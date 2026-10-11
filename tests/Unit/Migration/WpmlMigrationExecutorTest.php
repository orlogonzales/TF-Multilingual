<?php
/**
 * WPML Migration Executor Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;

use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\Exceptions\MigrationAuthorizationException;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationExecutionReport;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use wpdb;

/**
 * Class WpmlMigrationExecutorTest
 */
class WpmlMigrationExecutorTest extends TestCase {

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( 'tfml_migration_lock_wpml' );
		delete_option( 'tfml_migration_checkpoint_wpml' );
		TranslationGroupRepository::reset_transaction_depth();
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		delete_option( 'tfml_migration_lock_wpml' );
		delete_option( 'tfml_migration_checkpoint_wpml' );
		TranslationGroupRepository::reset_transaction_depth();
		parent::tearDown();
	}

	/**
	 * Creates an active language registry.
	 *
	 * @return LanguageRegistry
	 */
	private function create_test_registry(): LanguageRegistry {
		$registry = new LanguageRegistry();
		$registry->add_language( new Language( 'es', 'es_ES', 'Español', 'Español', true ), true );
		$registry->add_language( new Language( 'en', 'en_US', 'English', 'English', true ) );

		return $registry;
	}

	/**
	 * Creates a mock database for executor tests.
	 *
	 * @return wpdb
	 */
	private function create_mock_db(): wpdb {
		$db         = $this->createMock( wpdb::class );
		$db->prefix = 'wp_';
		$db->posts  = 'wp_posts';
		$db->terms  = 'wp_terms';

		$db->method( 'prepare' )->willReturnCallback( fn( $q, ...$args ) => $q );
		$db->method( 'query' )->willReturn( true );

		return $db;
	}

	/**
	 * Test 1: Execution rejected if unauthorized.
	 */
	public function test_unauthorized_execution_rejected(): void {
		$db       = $this->create_mock_db();
		$registry = $this->create_test_registry();
		$executor = new WpmlMigrationExecutor( $db, null, $registry );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(),
			)
		);

		$this->assertFalse( $executor->is_authorized() );
		$this->expectException( MigrationAuthorizationException::class );

		$executor->execute_batch( $plan );
	}

	/**
	 * Test 2: Validation detects stale plan or altered data.
	 */
	public function test_stale_plan_altered_data_detected(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );

		// Simulate post 500 does NOT exist in Core anymore (stale/altered data).
		$validator->method( 'exists' )->willReturnCallback(
			fn( $type, $sub, $id ) => 500 !== $id
		);

		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			null,
			$registry,
			null,
			null,
			$validator,
			$schema
		);
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'post',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array(
							'es' => 500, // Stale!
							'en' => 501,
						),
					),
				),
			)
		);

		$this->expectException( MigrationPlanValidationException::class );
		$executor->execute_batch( $plan );
	}

	/**
	 * Test 3: Concurrency control rejects simultaneous execution.
	 */
	public function test_concurrent_execution_rejected(): void {
		$db       = $this->create_mock_db();
		$registry = $this->create_test_registry();
		$lock     = new MigrationExecutionLock();

		// Process A acquires the lock.
		$lock->acquire( 'wpml', 'process_A', 300 );

		$executor = new WpmlMigrationExecutor(
			$db,
			null,
			$registry,
			null,
			$lock
		);
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 1,
						'element_type'   => 'post',
						'subtype'        => 'page',
						'classification' => MigrationPlan::CLASSIFICATION_SKIPPED,
					),
				),
			)
		);

		// Process B tries to execute while locked by process_A.
		$this->expectException( MigrationConcurrencyException::class );
		$executor->execute_batch( $plan, 50, 'process_B' );
	}

	/**
	 * Test 4: Batch execution and idempotency on duplicate run.
	 */
	public function test_batch_execution_and_idempotency(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$schema     = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$persisted_group = TranslationGroup::create( 'post', 'post' )->with_id( 42 );

		// First pass: find_by_element returns null (elements not yet imported).
		// Second pass: find_by_element returns persisted_group (already imported).
		$is_imported = false;
		$group_repo->method( 'find_by_element' )
			->willReturnCallback(
				function () use ( &$is_imported, $persisted_group ) {
					return $is_imported ? $persisted_group : null;
				}
			);

		$group_repo->method( 'create_group' )
			->willReturnCallback(
				function () use ( &$is_imported, $persisted_group ) {
					$is_imported = true;
					return $persisted_group;
				}
			);
		$group_repo->method( 'add_translation' )->willReturn( TranslationElement::create( 'post', 101, 'en', 42 ) );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repo,
			$registry,
			null,
			null,
			$validator,
			$schema
		);

		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'post',
						'canonical_id'   => 100,
						'canonical_lang' => 'es',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array(
							'es' => 100,
							'en' => 101,
						),
					),
				),
			)
		);

		// First run: group is imported.
		$res1 = $executor->execute_batch( $plan, 50, 'token_idem' );
		$this->assertCount( 1, $res1['batch_results'] );
		$this->assertSame( 'imported', $res1['batch_results'][0]['status'] );
		$this->assertSame( 42, $res1['batch_results'][0]['group_id'] );

		// Second duplicate run with new initial checkpoint: group is skipped idempotently!
		$fresh_checkpoint = new MigrationCheckpoint(
			array(
				'source'      => 'wpml',
				'total_items' => 1,
			)
		);
		$res2             = $executor->execute_batch( $plan, 50, 'token_idem', $fresh_checkpoint );
		$this->assertCount( 1, $res2['batch_results'] );
		$this->assertSame( 'idempotent_skipped', $res2['batch_results'][0]['status'] );
		$this->assertSame( 42, $res2['batch_results'][0]['group_id'] );

		// Third run without explicit checkpoint: completed checkpoint in repo is automatically renewed.
		$res3 = $executor->execute_batch( $plan, 50, 'token_idem' );
		$this->assertCount( 1, $res3['batch_results'] );
		$this->assertSame( 'idempotent_skipped', $res3['batch_results'][0]['status'] );
	}


	/**
	 * Test 5: Transaction rollback upon interruption or failure.
	 */
	public function test_interruption_during_transaction_triggers_rollback(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$schema     = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$group_repo->method( 'find_by_element' )->willReturn( null );

		// Simulate catastrophic failure during repository create.
		$group_repo->expects( $this->once() )->method( 'begin_transaction' );
		$group_repo->expects( $this->once() )->method( 'rollback_transaction' );
		$group_repo->method( 'create_group' )->willThrowException( new \RuntimeException( 'Simulated DB crash' ) );

		$checkpoint_repo = new MigrationCheckpointRepository();

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repo,
			$registry,
			$checkpoint_repo,
			null,
			$validator,
			$schema
		);

		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'post',
						'canonical_id'   => 100,
						'canonical_lang' => 'es',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array(
							'es' => 100,
							'en' => 101,
						),
					),
				),
			)
		);

		try {

			$executor->execute_batch( $plan, 50, 'token_fail' );
			$this->fail( 'Expected MigrationExecutionException was not thrown.' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( 'Simulated DB crash', $e->getMessage() );
		}

		// Verify checkpoint was updated with failure state.
		$failed_cp = $checkpoint_repo->get( 'wpml' );
		$this->assertNotNull( $failed_cp );
		$this->assertSame( MigrationCheckpoint::STATUS_FAILED, $failed_cp->get_status() );
		$this->assertNotEmpty( $failed_cp->get_errors() );
	}

	/**
	 * Test 6: Resumption from existing checkpoint.
	 */
	public function test_resumption_from_existing_checkpoint(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$checkpoint_repo = new MigrationCheckpointRepository();
		// Save checkpoint indicating TRID 10 was already processed.
		$initial_cp = new MigrationCheckpoint(
			array(
				'source'              => 'wpml',
				'total_items'         => 2,
				'processed_items'     => 1,
				'last_processed_trid' => 10,
				'status'              => MigrationCheckpoint::STATUS_IN_PROGRESS,
			)
		);
		$checkpoint_repo->save( $initial_cp );

		$executor = new WpmlMigrationExecutor(
			$db,
			null,
			$registry,
			$checkpoint_repo,
			null,
			$validator,
			$schema
		);
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 10, // Should be skipped (already processed).
						'element_type'   => 'post',
						'subtype'        => 'post',
						'classification' => MigrationPlan::CLASSIFICATION_SKIPPED,
					),
					array(
						'trid'           => 20, // Should be processed.
						'element_type'   => 'post',
						'subtype'        => 'post',
						'classification' => MigrationPlan::CLASSIFICATION_SKIPPED,
					),
				),
			)
		);

		$res = $executor->execute_batch( $plan, 50, 'token_resume' );
		$this->assertCount( 1, $res['batch_results'] );
		$this->assertSame( 20, $res['batch_results'][0]['trid'] );
		$this->assertTrue( $res['finished'] );
	}

	/**
	 * Test 7: Classification status handling.
	 */
	public function test_classification_status_handling(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			null,
			$registry,
			null,
			null,
			$validator,
			$schema
		);
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'                 => 1,
						'classification'       => MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE,
						'pending_translations' => array( 'fr' => 106 ),
					),
					array(
						'trid'           => 2,
						'classification' => MigrationPlan::CLASSIFICATION_SKIPPED,
						'reasons'        => array( 'Tipo excluido' ),
					),
					array(
						'trid'           => 3,
						'classification' => MigrationPlan::CLASSIFICATION_ORPHAN,
					),
					array(
						'trid'           => 4,
						'classification' => MigrationPlan::CLASSIFICATION_CONFLICT,
					),
				),
			)
		);

		$report = $executor->run( $plan, 50, 'token_run' );
		$this->assertInstanceOf( MigrationExecutionReport::class, $report );
		$this->assertTrue( $report->is_completed() );
		$this->assertSame( 4, $report->get_skipped_groups() );
		$this->assertSame( 0, $report->get_imported_groups() );
	}

	/**
	 * Test 8: Fencing token verification rejects writing if lock was expired or hijacked before COMMIT.
	 */
	public function test_fencing_token_rejects_expired_or_stolen_lock_before_commit(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$schema     = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		// Simulate lock manager where initial verify succeeds, but right before commit verify_ownership fails (expired/stolen).
		$lock = $this->createMock( MigrationExecutionLock::class );
		$lock->method( 'acquire' )->willReturn( true );
		$lock_calls = 0;
		$lock->method( 'verify_ownership' )->willReturnCallback(
			function () use ( &$lock_calls ) {
				++$lock_calls;
				// First check during execute_batch passes; second check before COMMIT fails (lock lost).
				return $lock_calls < 2;
			}
		);

		$group_repo->expects( $this->once() )->method( 'begin_transaction' );
		$group_repo->expects( $this->once() )->method( 'rollback_transaction' );
		$group_repo->expects( $this->never() )->method( 'commit_transaction' );

		$persisted_group = TranslationGroup::create( 'post', 'post' )->with_id( 42 );
		$group_repo->method( 'create_group' )->willReturn( $persisted_group );
		$group_repo->method( 'add_translation' )->willReturn( TranslationElement::create( 'post', 101, 'en', 42 ) );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repo,
			$registry,
			null,
			$lock,
			$validator,
			$schema
		);
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'post',
						'canonical_id'   => 100,
						'canonical_lang' => 'es',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array(
							'es' => 100,
							'en' => 101,
						),
					),
				),
			)
		);

		$this->expectException( MigrationConcurrencyException::class );
		$this->expectExceptionMessageMatches( '/Fencing token inválido o expirado/' );

		$executor->execute_batch( $plan, 50, 'token_stolen' );
	}

	/**
	 * Test 9: validate_plan rejects posts moved to trash since planning.
	 */
	public function test_validate_plan_rejects_trashed_post(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor( $db, null, $registry, null, null, $validator, $schema );
		$executor->set_authorized( true );

		// Set post 100 as trashed.
		$GLOBALS['wp_test_post_status'][100] = 'trash';

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'post',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array(
							'es' => 100,
							'en' => 101,
						),
					),
				),
			)
		);

		$validation = $executor->validate_plan( $plan );
		$this->assertFalse( $validation['valid'] );
		$this->assertStringContainsString( 'se encuentra en la papelera (trash)', $validation['errors'][0] );

		unset( $GLOBALS['wp_test_post_status'][100] );
	}

	/**
	 * Test 10: validate_plan rejects plan if WPML icl_translations shifted TRID or language.
	 */
	public function test_validate_plan_rejects_wpml_source_divergence(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		// Table exists in DB.
		$db->method( 'get_var' )->willReturn( 'wp_icl_translations' );

		// Return row showing TRID in WPML shifted from 10 to 999!
		$shifted_row = (object) array(
			'trid'          => 999,
			'language_code' => 'es',
		);
		$db->method( 'get_row' )->willReturn( $shifted_row );

		$executor = new WpmlMigrationExecutor( $db, null, $registry, null, null, $validator, $schema );
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'post',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array( 'es' => 100 ),
					),
				),
			)
		);

		$validation = $executor->validate_plan( $plan );
		$this->assertFalse( $validation['valid'] );
		$this->assertStringContainsString( 'Divergencia en fuente WPML detectada', $validation['errors'][0] );
	}

	/**
	 * Test 11: validate_plan rejects plan if fingerprint mismatch is detected.
	 */
	public function test_validate_plan_rejects_fingerprint_mismatch(): void {
		$db       = $this->create_mock_db();
		$registry = $this->create_test_registry();
		$schema   = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor( $db, null, $registry, null, null, null, $schema );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 1,
						'classification' => 'SKIPPED',
					),
				),
			)
		);

		$validation = $executor->validate_plan( $plan, 'tampered_sha256_hash_12345' );
		$this->assertFalse( $validation['valid'] );
		$this->assertStringContainsString( 'Huella digital del plan inválida', $validation['errors'][0] );
	}

	/**
	 * Test 12: validate_plan rejects overlapping collisions introduced post-planning.
	 */
	public function test_validate_plan_rejects_overlapping_collision_post_planning(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$schema     = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		// Simulate element 100 already assigned to TFML group 5, but element 101 has no group (partial assignment conflict).
		$group5 = TranslationGroup::create( 'post', 'post' )->with_id( 5 );
		$group_repo->method( 'find_by_element' )->willReturnCallback(
			fn( $type, $id ) => 100 === $id ? $group5 : null
		);

		$executor = new WpmlMigrationExecutor( $db, $group_repo, $registry, null, null, $validator, $schema );
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'post',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array(
							'es' => 100,
							'en' => 101,
						),
					),
				),
			)
		);

		$validation = $executor->validate_plan( $plan );
		$this->assertFalse( $validation['valid'] );
		$this->assertStringContainsString( 'Colisión relacional sobrevenida detectada', $validation['errors'][0] );
	}

	/**
	 * Test 13: Deep reconciliation verifies canonical ID, language codes, subtype and translations.
	 */
	public function test_deep_reconciliation_verifies_canonical_and_translations(): void {
		$db       = $this->create_mock_db();
		$registry = $this->create_test_registry();

		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$schema     = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		// Create a fully matched TFML group.
		$canonical_el  = TranslationElement::create( 'post', 100, 'es', 42 );
		$trans_el      = TranslationElement::create( 'post', 101, 'en', 42 );
		$matched_group = TranslationGroup::create( 'post', 'page', 100, 42 );
		$matched_group->add_element( $canonical_el, true );
		$matched_group->add_element( $trans_el, false );

		$group_repo->method( 'find_by_element' )->willReturn( $matched_group );

		$executor = new WpmlMigrationExecutor( $db, $group_repo, $registry, null, null, null, $schema );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'summary'        => array(
					'importable_groups' => 1,
					'pending_elements'  => 0,
				),
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'page',
						'canonical_id'   => 100,
						'canonical_lang' => 'es',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array(
							'es' => 100,
							'en' => 101,
						),
					),
				),
			)
		);

		$reconcile_ok = $executor->reconcile( $plan );
		$this->assertTrue( $reconcile_ok['integrity_verified'] );
		$this->assertSame( 1, $reconcile_ok['tfml_imported_groups'] );
		$this->assertSame( 2, $reconcile_ok['tfml_imported_elements'] );
		$this->assertEmpty( $reconcile_ok['discrepancies'] );

		// Now simulate mismatched canonical ID in plan (expected 999 instead of 100).
		$mismatched_plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'summary'        => array(
					'importable_groups' => 1,
					'pending_elements'  => 0,
				),
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'page',
						'canonical_id'   => 999, // Mismatched!
						'canonical_lang' => 'es',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array(
							'es' => 100,
							'en' => 101,
						),
					),
				),
			)
		);

		$reconcile_bad = $executor->reconcile( $mismatched_plan );
		$this->assertFalse( $reconcile_bad['integrity_verified'] );
		$this->assertNotEmpty( $reconcile_bad['discrepancies'] );
		$this->assertStringContainsString( 'no coincide con el plan', $reconcile_bad['discrepancies'][0] );
	}

	/**
	 * Test 14: Post-COMMIT crash resumption executes cleanly without duplicates or omissions.
	 */
	public function test_post_commit_interruption_resumes_cleanly_without_duplicates(): void {
		$db        = $this->create_mock_db();
		$registry  = $this->create_test_registry();
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$schema     = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		// Simulate scenario: Batch 1 committed group 55 in MySQL, but checkpoint was NOT updated (simulated power outage).
		$already_imported_group = TranslationGroup::create( 'post', 'post' )->with_id( 55 );
		$group_repo->method( 'find_by_element' )->willReturn( $already_imported_group );

		// create_group must NEVER be called upon resume!
		$group_repo->expects( $this->never() )->method( 'create_group' );

		$checkpoint_repo = new MigrationCheckpointRepository();
		// Initial checkpoint stuck at TRID 0.
		$stuck_checkpoint = new MigrationCheckpoint(
			array(
				'source'              => 'wpml',
				'total_items'         => 1,
				'processed_items'     => 0,
				'last_processed_trid' => 0,
				'status'              => MigrationCheckpoint::STATUS_PENDING,
			)
		);
		$checkpoint_repo->save( $stuck_checkpoint );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repo,
			$registry,
			$checkpoint_repo,
			null,
			$validator,
			$schema
		);
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 10,
						'element_type'   => 'post',
						'subtype'        => 'post',
						'canonical_id'   => 100,
						'canonical_lang' => 'es',
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array(
							'es' => 100,
							'en' => 101,
						),
					),
				),
			)
		);

		// Resume execution.
		$res = $executor->execute_batch( $plan, 50, 'token_resume_after_crash' );

		// Batch succeeds with idempotent_skipped and advances checkpoint to 10.
		$this->assertTrue( $res['finished'] );
		$this->assertSame( 'idempotent_skipped', $res['batch_results'][0]['status'] );
		$this->assertSame( 55, $res['batch_results'][0]['group_id'] );

		$saved_cp = $checkpoint_repo->get( 'wpml' );
		$this->assertNotNull( $saved_cp );
		$this->assertSame( 10, $saved_cp->get_last_processed_trid() );
		$this->assertSame( MigrationCheckpoint::STATUS_COMPLETED, $saved_cp->get_status() );
	}

	/**
	 * Test: Execution rejects plan with STATUS_NEEDS_CONFIGURATION.
	 */
	public function test_execution_rejects_unready_plan_status(): void {
		$db       = $this->create_mock_db();
		$registry = $this->create_test_registry();
		$executor = new WpmlMigrationExecutor( $db, null, $registry );
		$executor->set_authorized( true );

		$unready_plan = new MigrationPlan(
			array(
				'source'            => 'wpml',
				'status'            => MigrationPlan::STATUS_NEEDS_CONFIGURATION,
				'missing_languages' => array( 'fr' ),
				'planned_groups'    => array(),
			)
		);

		$this->expectException( MigrationPlanValidationException::class );
		$this->expectExceptionMessage( 'NEEDS_CONFIGURATION' );

		$executor->execute_batch( $unready_plan );
	}

	/**
	 * Test: Derived partial plan with non-importable group fails validation.
	 */
	public function test_partial_plan_with_invalid_group_fails_validation(): void {
		$db       = $this->create_mock_db();
		$registry = $this->create_test_registry();
		$schema   = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			null,
			$registry,
			null,
			null,
			null,
			$schema
		);

		$bad_partial = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'is_partial'     => true,
				'planned_groups' => array(
					array(
						'trid'           => 99,
						'element_type'   => 'post',
						'subtype'        => 'post',
						'classification' => MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE,
					),
				),
			)
		);

		$res = $executor->validate_plan( $bad_partial );
		$this->assertFalse( $res['valid'] );
		$this->assertStringContainsString( 'no elegible', $res['errors'][0] );
	}
}
