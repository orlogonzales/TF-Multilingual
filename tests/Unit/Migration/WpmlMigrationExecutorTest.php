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
		$call_count = 0;
		$group_repo->method( 'find_by_element' )
			->willReturnCallback(
				function () use ( &$call_count, $persisted_group ) {
					++$call_count;
					// During second pass, return existing group.
					return $call_count > 2 ? $persisted_group : null;
				}
			);

		$group_repo->method( 'create_group' )->willReturn( $persisted_group );
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
}
