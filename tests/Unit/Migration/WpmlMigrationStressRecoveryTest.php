<?php
/**
 * WPML Migration Stress, Concurrency and Recovery Unit Tests.
 *
 * Directiva: DT-TFML-043 (Fase 3.13)
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\Exceptions\TranslationConflictException;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationExecutionReport;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use wpdb;

/**
 * Class WpmlMigrationStressRecoveryTest
 */
class WpmlMigrationStressRecoveryTest extends TestCase {

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
	private function create_active_registry(): LanguageRegistry {
		$registry = new LanguageRegistry();
		$registry->add_language( new Language( 'es', 'es_ES', 'Español', 'Español', true ), true );
		$registry->add_language( new Language( 'en', 'en_US', 'English', 'English', true ) );
		return $registry;
	}

	/**
	 * 1. Stress Scenario: Interruption before COMMIT (Transaction Rollback).
	 */
	public function test_stress_interruption_before_commit_rolls_back_transaction(): void {
		$db       = $this->createMock( wpdb::class );
		$registry = $this->create_active_registry();

		$rolled_back = false;
		$group_repo  = new class( $db, $registry, $rolled_back ) extends TranslationGroupRepository {
			private bool $rb_ref;
			public function __construct( $db, $registry, &$rb ) {
				parent::__construct( $db, $registry );
				$this->rb_ref = &$rb;
			}
			public function begin_transaction(): void {
				// No-op for mock.
			}
			public function rollback_transaction(): void {
				$this->rb_ref = true;
			}
			public function find_by_element( string $type, int $id ): ?TranslationGroup {
				return null;
			}
			public function create_group(
				string $element_type,
				string $subtype,
				?int $initial_element_id = null,
				?string $initial_language_code = null,
				bool $as_canonical = true,
				string $initial_fingerprint = ''
			): TranslationGroup {
				throw new RuntimeException( 'Simulated crash before COMMIT' );
			}
		};

		$checkpoint_repo = $this->createMock( MigrationCheckpointRepository::class );
		$checkpoint_repo->method( 'get' )->willReturn( null );
		$saved_cp = null;
		$checkpoint_repo->method( 'save' )->willReturnCallback(
			function ( MigrationCheckpoint $cp ) use ( &$saved_cp ) {
				$saved_cp = $cp;
				return true;
			}
		);

		$lock              = new MigrationExecutionLock();
		$element_validator = $this->createMock( WordPressElementValidator::class );
		$element_validator->method( 'exists' )->willReturn( true );
		$schema_manager = $this->createMock( SchemaManager::class );
		$schema_manager->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repo,
			$registry,
			$checkpoint_repo,
			$lock,
			$element_validator,
			$schema_manager
		);
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 101,
						'element_type'   => 'post',
						'subtype'        => 'page',
						'canonical_id'   => 1001,
						'canonical_lang' => 'es',
						'translations'   => array( 'es' => 1001 ),
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
					),
				),
			)
		);

		$this->expectException( MigrationExecutionException::class );
		$this->expectExceptionMessage( 'Simulated crash before COMMIT' );

		try {
			$executor->execute_batch( $plan, 5 );
		} finally {
			$this->assertTrue( $rolled_back, 'Database transaction must be rolled back on pre-commit crash' );
			$this->assertNotNull( $saved_cp );
			$this->assertSame( MigrationCheckpoint::STATUS_FAILED, $saved_cp->get_status() );
			$this->assertFalse( $lock->is_locked( 'wpml' ), 'Lock must be released on failure' );
		}
	}

	/**
	 * 2. Stress Scenario: Interruption after COMMIT (Checkpoint Resumption).
	 */
	public function test_stress_interruption_after_commit_resumes_without_duplicate(): void {
		$db       = $this->createMock( wpdb::class );
		$registry = $this->create_active_registry();

		$persisted_groups = array();
		$group_repo       = new class( $db, $registry, $persisted_groups ) extends TranslationGroupRepository {
			private array $store;
			public function __construct( $db, $registry, &$store ) {
				parent::__construct( $db, $registry );
				$this->store = &$store;
			}
			public function begin_transaction(): void {}
			public function commit_transaction(): void {}
			public function rollback_transaction(): void {}
			public function find_by_element( string $type, int $id ): ?TranslationGroup {
				return $this->store[ $id ] ?? null;
			}
			public function create_group(
				string $element_type,
				string $subtype,
				?int $initial_element_id = null,
				?string $initial_language_code = null,
				bool $as_canonical = true,
				string $initial_fingerprint = ''
			): TranslationGroup {
				$g = TranslationGroup::create( $element_type, $subtype );
				$e = TranslationElement::create( $element_type, (int) $initial_element_id, (string) $initial_language_code );
				$g->add_element( $e, $as_canonical );
				$this->store[ $initial_element_id ] = $g;
				return $g;
			}
		};

		$checkpoint_repo = new MigrationCheckpointRepository();
		$lock            = new MigrationExecutionLock();
		$validator       = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );
		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repo,
			$registry,
			$checkpoint_repo,
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
						'trid'           => 201,
						'element_type'   => 'post',
						'subtype'        => 'page',
						'canonical_id'   => 2001,
						'canonical_lang' => 'es',
						'translations'   => array( 'es' => 2001 ),
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
					),
					array(
						'trid'           => 202,
						'element_type'   => 'post',
						'subtype'        => 'page',
						'canonical_id'   => 2002,
						'canonical_lang' => 'es',
						'translations'   => array( 'es' => 2002 ),
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
					),
				),
			)
		);

		// Batch 1: Process only 1 item with explicit token.
		$res1 = $executor->execute_batch( $plan, 1, 'token_resumption' );
		$this->assertCount( 1, $res1['batch_results'] );
		$this->assertFalse( $res1['finished'] );

		// Simulate interruption and resumption with the same worker token:
		$res2 = $executor->execute_batch( $plan, 1, 'token_resumption' );
		$this->assertCount( 1, $res2['batch_results'] );
		$this->assertTrue( $res2['finished'] );

		$this->assertCount( 2, $persisted_groups );
	}

	/**
	 * 3. Stress Scenario: Lock loss, expiry and recovery.
	 */
	public function test_stress_lock_loss_expiry_and_fencing_token_recovery(): void {
		$lock = new MigrationExecutionLock();

		// Acquire initial lock.
		$token_a = 'token_worker_a';
		$this->assertTrue( $lock->acquire( 'wpml', $token_a, 2 ) ); // 2 seconds TTL

		// Worker B cannot acquire active lock.
		$token_b = 'token_worker_b';
		$this->assertFalse( $lock->acquire( 'wpml', $token_b, 10 ) );

		// Worker B cannot renew with mismatched fencing token.
		$this->assertFalse( $lock->renew( 'wpml', $token_b ) );

		// Fencing ownership check.
		$this->assertTrue( $lock->verify_ownership( 'wpml', $token_a ) );
		$this->assertFalse( $lock->verify_ownership( 'wpml', $token_b ) );

		// Simulate lock expiry in storage.
		$expired_data = array(
			'token'      => $token_a,
			'source'     => 'wpml',
			'expires_at' => time() - 10,
		);
		update_option( 'tfml_migration_lock_wpml', $expired_data );

		// Expired lock: verify_ownership returns false.
		$this->assertFalse( $lock->verify_ownership( 'wpml', $token_a ) );

		// Worker B can recover expired lock.
		$this->assertTrue( $lock->acquire( 'wpml', $token_b, 60 ) );
		$this->assertTrue( $lock->verify_ownership( 'wpml', $token_b ) );
	}

	/**
	 * 4. Stress Scenario: Concurrent execution rejection.
	 */
	public function test_stress_concurrent_execution_rejection(): void {
		$db              = $this->createMock( wpdb::class );
		$registry        = $this->create_active_registry();
		$group_repo      = $this->createMock( TranslationGroupRepository::class );
		$checkpoint_repo = $this->createMock( MigrationCheckpointRepository::class );
		$lock            = new MigrationExecutionLock();
		$validator       = $this->createMock( WordPressElementValidator::class );
		$schema          = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		// Worker 1 holds the lock.
		$lock->acquire( 'wpml', 'worker_1_active_token', 60 );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repo,
			$registry,
			$checkpoint_repo,
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
						'trid'           => 301,
						'element_type'   => 'post',
						'subtype'        => 'page',
						'canonical_id'   => 3001,
						'canonical_lang' => 'es',
						'translations'   => array( 'es' => 3001 ),
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
					),
				),
			)
		);

		// Worker 2 attempts execution: Must throw MigrationConcurrencyException.
		$this->expectException( MigrationConcurrencyException::class );
		$executor->execute_batch( $plan, 5, 'worker_2_token' );
	}

	/**
	 * 5. Stress Scenario: Tampered or Inconsistent Manifest Rejection in Rollback.
	 */
	public function test_stress_inconsistent_manifest_rejection(): void {
		$db               = $this->createMock( wpdb::class );
		$group_repo       = $this->createMock( TranslationGroupRepository::class );
		$checkpoint_repo  = $this->createMock( MigrationCheckpointRepository::class );
		$rollback_service = new WpmlMigrationRollbackService( $db, $group_repo, $checkpoint_repo );

		// Test 1: Tampered manifest in storage where imported_group_ids was stripped:
		$rollback_service->save_execution_manifest(
			'exec_tampered_test',
			array(
				'execution_id'       => 'exec_tampered_test',
				'imported_group_ids' => array(), // Tampered / stripped!
			)
		);
		$res_corrupt = $rollback_service->rollback_groups( array( 100 ), 'exec_tampered_test', true );
		$this->assertFalse( $res_corrupt['success'] );
		$this->assertSame( 'not_owned', $res_corrupt['blocked_groups'][0]['code'] );
		$rollback_service->delete_execution_manifest( 'exec_tampered_test' );

		// Test 2: Manifest with foreign groups (group 999 not owned):
		$rollback_service->save_execution_manifest(
			'exec_foreign_test',
			array(
				'execution_id'       => 'exec_foreign_test',
				'source'             => 'wpml',
				'imported_group_ids' => array( 100 ),
			)
		);

		// Try rolling back group 999 (not in manifest):
		$res_groups = $rollback_service->rollback_groups( array( 999 ), 'exec_foreign_test', true );
		$this->assertFalse( $res_groups['success'] );
		$this->assertSame( 'not_owned', $res_groups['blocked_groups'][0]['code'] );

		$rollback_service->delete_execution_manifest( 'exec_foreign_test' );
	}

	/**
	 * 6. Stress Scenario: Idempotency with Duplicate Execution.
	 */
	public function test_stress_idempotency_with_duplicate_execution(): void {
		$db       = $this->createMock( wpdb::class );
		$registry = $this->create_active_registry();

		$existing_group = TranslationGroup::create( 'post', 'page' );
		$elem           = TranslationElement::create( 'post', 4001, 'es' );
		$existing_group->add_element( $elem, true );

		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$group_repo->method( 'find_by_element' )->willReturn( $existing_group );

		$checkpoint_repo   = new MigrationCheckpointRepository();
		$lock              = new MigrationExecutionLock();
		$element_validator = $this->createMock( WordPressElementValidator::class );
		$element_validator->method( 'exists' )->willReturn( true );
		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repo,
			$registry,
			$checkpoint_repo,
			$lock,
			$element_validator,
			$schema
		);
		$executor->set_authorized( true );

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_READY,
				'planned_groups' => array(
					array(
						'trid'           => 401,
						'element_type'   => 'post',
						'subtype'        => 'page',
						'canonical_id'   => 4001,
						'canonical_lang' => 'es',
						'translations'   => array( 'es' => 4001 ),
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
					),
				),
			)
		);

		// Execute batch on existing data:
		$res = $executor->execute_batch( $plan, 5 );
		$this->assertCount( 1, $res['batch_results'] );
		$this->assertSame( 'idempotent_skipped', $res['batch_results'][0]['status'] );
	}
}
