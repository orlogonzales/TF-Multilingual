<?php
/**
 * Transactional Hardening and Retained Translation Recovery Unit Tests (Fase 3.31).
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use wpdb;

/**
 * Class TransactionalRetainedRecoveryTest
 *
 * Certifies the 15 mandatory induced-failure, concurrency, idempotency, promotion,
 * reconciliation, and rollback scenarios defined in Directive DT-TFML-061.
 */
class TransactionalRetainedRecoveryTest extends TestCase {

	/**
	 * Retained registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

	/**
	 * Set up test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->retained_registry = new RetainedTranslationRegistry();
		$this->retained_registry->purge_all();
		delete_option( 'tfml_migration_checkpoint_wpml' );
	}

	/**
	 * Tear down test.
	 */
	protected function tearDown(): void {
		$this->retained_registry->set_simulate_write_failure( false );
		$this->retained_registry->purge_all();
		delete_option( 'tfml_migration_checkpoint_wpml' );
		parent::tearDown();
	}

	/**
	 * Creates a partial plan for TRID 197 (ES:197, EN:8356 active; FR:8839 retained).
	 *
	 * @return MigrationPlan
	 */
	private function create_trid_197_partial_plan(): MigrationPlan {
		$master = new MigrationPlan(
			array(
				'source'            => 'wpml',
				'status'            => MigrationPlan::STATUS_NEEDS_CONFIGURATION,
				'missing_languages' => array( 'fr' ),
				'summary'           => array(
					'total_groups'            => 1,
					'importable_groups'       => 0,
					'blocked_language_groups' => 1,
					'skipped_groups'          => 0,
					'conflict_groups'         => 0,
					'orphan_groups'           => 0,
					'total_elements'          => 3,
					'importable_elements'     => 2,
					'pending_elements'        => 1,
				),
				'media_plan'        => array(),
				'planned_groups'    => array(
					array(
						'trid'                 => 197,
						'element_type'         => 'post',
						'subtype'              => 'page',
						'canonical_id'         => 197,
						'canonical_lang'       => 'es',
						'classification'       => MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE,
						'reasons'              => array( "Dependencia pendiente: el idioma 'fr' no está configurado en TFML." ),
						'translations'         => array(
							'es' => 197,
							'en' => 8356,
						),
						'pending_translations' => array(
							'fr' => 8839,
						),
					),
				),
			)
		);

		return $master->derive_partial_plan_with_retained_languages(
			array(
				array(
					'trid'                 => 197,
					'authorized_languages' => array( 'es', 'en' ),
				),
			),
			'dt-tfml-061-test'
		);
	}

	/**
	 * Scenario 1: Failure when creating tfml_groups leaves RetainedTranslationRegistry empty.
	 */
	public function test_scenario_01_failure_creating_group_leaves_retained_registry_empty(): void {
		$plan             = $this->create_trid_197_partial_plan();
		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		$group_repository->method( 'find_by_element' )->willReturn( null );
		$group_repository->expects( $this->once() )->method( 'begin_transaction' );
		$group_repository->expects( $this->once() )->method( 'rollback_transaction' );
		$group_repository->expects( $this->never() )->method( 'commit_transaction' );
		$group_repository->method( 'create_group' )->willThrowException( new RuntimeException( 'Fallo SQL simulado en tfml_groups' ) );

		$checkpoint_repo = new MigrationCheckpointRepository( $db );
		$lock            = $this->createMock( MigrationExecutionLock::class );
		$lock->method( 'verify_ownership' )->willReturn( true );
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );
		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repository,
			null,
			$checkpoint_repo,
			$lock,
			$validator,
			$schema,
			$this->retained_registry
		);
		$executor->set_authorized( true );

		try {
			$executor->execute_batch( $plan, 10, 'sc1-token', null, $plan->get_fingerprint() );
			$this->fail( 'Debió lanzar MigrationExecutionException.' );
		} catch ( MigrationExecutionException $e ) {
			$this->assertStringContainsString( 'Fallo SQL simulado en tfml_groups', $e->getMessage() );
		}

		$this->assertNull( $this->retained_registry->get( 'wpml', 197 ) );
		$this->assertEmpty( $this->retained_registry->get_all() );
	}

	/**
	 * Scenario 2: Failure inserting active tfml_group_elements rolls back group and leaves RetainedTranslationRegistry empty.
	 */
	public function test_scenario_02_failure_inserting_active_element_rolls_back_group_and_retained(): void {
		$plan             = $this->create_trid_197_partial_plan();
		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		$persisted_group = new TranslationGroup( 'post', 'page', 197, 42 );
		$group_repository->method( 'find_by_element' )->willReturn( null );
		$group_repository->method( 'create_group' )->willReturn( $persisted_group );
		$group_repository->method( 'add_translation' )->willThrowException( new RuntimeException( 'Fallo SQL al insertar elemento 8356' ) );
		$group_repository->expects( $this->once() )->method( 'begin_transaction' );
		$group_repository->expects( $this->once() )->method( 'rollback_transaction' );
		$group_repository->expects( $this->never() )->method( 'commit_transaction' );

		$checkpoint_repo = new MigrationCheckpointRepository( $db );
		$lock            = $this->createMock( MigrationExecutionLock::class );
		$lock->method( 'verify_ownership' )->willReturn( true );
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );
		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repository,
			null,
			$checkpoint_repo,
			$lock,
			$validator,
			$schema,
			$this->retained_registry
		);
		$executor->set_authorized( true );

		try {
			$executor->execute_batch( $plan, 10, 'sc2-token', null, $plan->get_fingerprint() );
			$this->fail( 'Debió lanzar MigrationExecutionException.' );
		} catch ( MigrationExecutionException $e ) {
			$this->assertStringContainsString( 'Fallo SQL al insertar elemento 8356', $e->getMessage() );
		}

		$this->assertNull( $this->retained_registry->get( 'wpml', 197 ) );
	}

	/**
	 * Scenario 3: Induced failure writing RetainedTranslationRegistry rolls back active group and elements.
	 */
	public function test_scenario_03_failure_writing_retained_registry_rolls_back_batch_transaction(): void {
		$plan             = $this->create_trid_197_partial_plan();
		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		$persisted_group = new TranslationGroup( 'post', 'page', 197, 42 );
		$group_repository->method( 'find_by_element' )->willReturn( null );
		$group_repository->method( 'create_group' )->willReturn( $persisted_group );
		$group_repository->method( 'add_translation' )->willReturn( TranslationElement::create( 'post', 8356, 'en' ) );
		$group_repository->expects( $this->once() )->method( 'begin_transaction' );
		$group_repository->expects( $this->once() )->method( 'rollback_transaction' );
		$group_repository->expects( $this->never() )->method( 'commit_transaction' );

		$this->retained_registry->set_simulate_write_failure( true );

		$checkpoint_repo = new MigrationCheckpointRepository( $db );
		$lock            = $this->createMock( MigrationExecutionLock::class );
		$lock->method( 'verify_ownership' )->willReturn( true );
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );
		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repository,
			null,
			$checkpoint_repo,
			$lock,
			$validator,
			$schema,
			$this->retained_registry
		);
		$executor->set_authorized( true );

		try {
			$executor->execute_batch( $plan, 10, 'sc3-token', null, $plan->get_fingerprint() );
			$this->fail( 'Debió lanzar MigrationExecutionException por fallo en RetainedTranslationRegistry.' );
		} catch ( MigrationExecutionException $e ) {
			$this->assertStringContainsString( 'RetainedTranslationRegistry', $e->getMessage() );
		}

		$this->retained_registry->set_simulate_write_failure( false );
		$this->assertNull( $this->retained_registry->get( 'wpml', 197 ) );
	}

	/**
	 * Scenario 4: Induced failure saving execution manifest rolls back the entire batch transaction.
	 */
	public function test_scenario_04_failure_saving_manifest_rolls_back_batch(): void {
		$plan             = $this->create_trid_197_partial_plan();
		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		$persisted_group = new TranslationGroup( 'post', 'page', 197, 42 );
		$group_repository->method( 'find_by_element' )->willReturn( null );
		$group_repository->method( 'create_group' )->willReturn( $persisted_group );
		$group_repository->method( 'add_translation' )->willReturn( TranslationElement::create( 'post', 8356, 'en' ) );
		$group_repository->expects( $this->once() )->method( 'begin_transaction' );
		$group_repository->expects( $this->once() )->method( 'rollback_transaction' );
		$group_repository->expects( $this->never() )->method( 'commit_transaction' );

		$checkpoint_repo = new MigrationCheckpointRepository( $db );
		$lock            = $this->createMock( MigrationExecutionLock::class );
		$lock->method( 'verify_ownership' )->willReturn( true );
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );
		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repository,
			null,
			$checkpoint_repo,
			$lock,
			$validator,
			$schema,
			$this->retained_registry
		);
		$executor->set_authorized( true );
		$executor->set_simulate_manifest_failure( true );

		try {
			$executor->execute_batch( $plan, 10, 'sc4-token', null, $plan->get_fingerprint() );
			$this->fail( 'Debió lanzar MigrationExecutionException por fallo en manifiesto.' );
		} catch ( MigrationExecutionException $e ) {
			$this->assertStringContainsString( 'manifiesto', $e->getMessage() );
		}
	}

	/**
	 * Scenario 5: Failure saving checkpoint inside transaction triggers rollback without desynchronization.
	 */
	public function test_scenario_05_failure_saving_checkpoint_rolls_back_batch(): void {
		$plan             = $this->create_trid_197_partial_plan();
		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		$persisted_group = new TranslationGroup( 'post', 'page', 197, 42 );
		$group_repository->method( 'find_by_element' )->willReturn( null );
		$group_repository->method( 'create_group' )->willReturn( $persisted_group );
		$group_repository->method( 'add_translation' )->willReturn( TranslationElement::create( 'post', 8356, 'en' ) );
		$group_repository->expects( $this->once() )->method( 'begin_transaction' );
		$group_repository->expects( $this->once() )->method( 'rollback_transaction' );
		$group_repository->expects( $this->never() )->method( 'commit_transaction' );

		$checkpoint_repo = new MigrationCheckpointRepository( $db );
		$checkpoint_repo->set_simulate_write_failure( true );

		$lock = $this->createMock( MigrationExecutionLock::class );
		$lock->method( 'verify_ownership' )->willReturn( true );
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );
		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repository,
			null,
			$checkpoint_repo,
			$lock,
			$validator,
			$schema,
			$this->retained_registry
		);
		$executor->set_authorized( true );

		try {
			$executor->execute_batch( $plan, 10, 'sc5-token', null, $plan->get_fingerprint() );
			$this->fail( 'Debió lanzar MigrationExecutionException por fallo en checkpoint.' );
		} catch ( MigrationExecutionException $e ) {
			$this->assertStringContainsString( 'checkpoint', $e->getMessage() );
		}
	}

	/**
	 * Scenario 6 & 7: Simulated interruption before commit followed by idempotent re-execution produces 0 duplicates and preserves timestamps.
	 */
	public function test_scenario_06_and_07_interruption_recovery_and_idempotent_reexecution(): void {
		$plan             = $this->create_trid_197_partial_plan();
		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		$persisted_group = new TranslationGroup( 'post', 'page', 197, 42 );
		$persisted_group->add_element( TranslationElement::create( 'post', 197, 'es' ), true );
		$persisted_group->add_element( TranslationElement::create( 'post', 8356, 'en' ), false );

		$imported_in_memory = false;
		$group_repository->method( 'find_by_element' )->willReturnCallback(
			function ( string $type, int $id ) use ( &$imported_in_memory, $persisted_group ) {
				if ( $imported_in_memory && ( 197 === $id || 8356 === $id ) ) {
					return $persisted_group;
				}
				return null;
			}
		);
		$group_repository->method( 'create_group' )->willReturnCallback(
			function () use ( &$imported_in_memory, $persisted_group ) {
				$imported_in_memory = true;
				return $persisted_group;
			}
		);
		$group_repository->method( 'add_translation' )->willReturn( TranslationElement::create( 'post', 8356, 'en' ) );
		$group_repository->method( 'rollback_transaction' )->willReturnCallback(
			function () use ( &$imported_in_memory ) {
				$imported_in_memory = false;
				$this->retained_registry->purge_all();
				return true;
			}
		);

		$checkpoint_repo = new MigrationCheckpointRepository( $db );
		$lock            = $this->createMock( MigrationExecutionLock::class );
		$lock->method( 'verify_ownership' )->willReturn( true );
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );
		$schema = $this->createMock( SchemaManager::class );
		$schema->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repository,
			null,
			$checkpoint_repo,
			$lock,
			$validator,
			$schema,
			$this->retained_registry
		);
		$executor->set_authorized( true );
		$executor->set_simulate_interrupt_before_commit( true );

		// Attempt 1: Interrupted before commit.
		try {
			$executor->execute_batch( $plan, 10, 'sc6-token', null, $plan->get_fingerprint() );
			$this->fail( 'Debió lanzar excepción por interrupción simulada antes de COMMIT.' );
		} catch ( MigrationExecutionException $e ) {
			$this->assertStringContainsString( 'Interrupción simulada', $e->getMessage() );
		}

		$this->assertNull( $this->retained_registry->get( 'wpml', 197 ) );

		// Attempt 2: Normal recovery execution after interruption.
		$executor->set_simulate_interrupt_before_commit( false );
		$checkpoint_repo->delete( 'wpml' );
		$res1 = $executor->execute_batch( $plan, 10, 'sc6-token-recovery', null, $plan->get_fingerprint() );

		$this->assertTrue( $res1['finished'] );
		$this->assertSame( 'imported_partial_retained', $res1['batch_results'][0]['status'] );

		$record1 = $this->retained_registry->get( 'wpml', 197 );
		$this->assertNotNull( $record1 );
		$this->assertTrue( $this->retained_registry->verify_record_integrity( $record1 ) );

		// Attempt 3 (Scenario 7): Idempotent re-execution of the exact same partial plan.
		$checkpoint_repo->delete( 'wpml' );
		$res2 = $executor->execute_batch( $plan, 10, 'sc7-token-idempotent', null, $plan->get_fingerprint() );

		$this->assertTrue( $res2['finished'] );
		$this->assertSame( 'idempotent_skipped', $res2['batch_results'][0]['status'] );

		$record2 = $this->retained_registry->get( 'wpml', 197 );
		$this->assertNotNull( $record2 );
		$this->assertSame( $record1['created_at'], $record2['created_at'] );
		$this->assertSame( $record1['fingerprint'], $record2['fingerprint'] );
		$this->assertCount( 1, $this->retained_registry->get_all() );
	}

	/**
	 * Scenario 8: Registering the same retained element in another group is rejected without altering the first record.
	 */
	public function test_scenario_08_duplicate_retained_element_in_second_group_is_rejected(): void {
		$first = $this->retained_registry->register(
			'wpml',
			197,
			42,
			'post',
			'page',
			array( 'fr' => 8839 ),
			'fp-197'
		);

		try {
			$this->retained_registry->register(
				'wpml',
				999,
				99,
				'post',
				'page',
				array( 'fr' => 8839 ),
				'fp-999'
			);
			$this->fail( 'Debió rechazar la duplicación del elemento retenido 8839.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( '8839', $e->getMessage() );
		}

		$preserved = $this->retained_registry->get( 'wpml', 197 );
		$this->assertSame( $first, $preserved );
		$this->assertNull( $this->retained_registry->get( 'wpml', 999 ) );
	}

	/**
	 * Scenario 9, 10, 11, 12: Promotion preconditions, collision rejection, atomic rollback on failure, and atomic success.
	 */
	public function test_scenarios_09_10_11_12_promotion_invariants_and_transactional_atomicity(): void {
		$this->retained_registry->register(
			'wpml',
			197,
			42,
			'post',
			'page',
			array( 'fr' => 8839 ),
			'fp-197'
		);

		$group = new TranslationGroup( 'post', 'page', 197, 42 );
		$group->add_element( TranslationElement::create( 'post', 197, 'es' ), true );
		$group->add_element( TranslationElement::create( 'post', 8356, 'en' ), false );

		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$group_repo->method( 'find' )->with( 42 )->willReturn( $group );

		$lang_inactive = $this->createMock( LanguageRegistry::class );
		$lang_inactive->method( 'is_active' )->willReturnCallback( fn( string $code ) => in_array( $code, array( 'es', 'en' ), true ) );

		$validator_ok = $this->createMock( WordPressElementValidator::class );
		$validator_ok->method( 'exists' )->willReturn( true );

		// Scenario 9: FR inactive -> rejected without losing retained record.
		try {
			$this->retained_registry->promote_retained_translation( 42, 'fr', $group_repo, $lang_inactive, $validator_ok );
			$this->fail( 'Debió rechazar promoción con idioma inactivo.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'continúa inactivo', $e->getMessage() );
		}
		$this->assertTrue( $this->retained_registry->has_retained_element( 'post', 8839 ) );

		// Scenario 10: Post 8839 deleted in WordPress -> rejected without corrupting retained record.
		$lang_active = $this->createMock( LanguageRegistry::class );
		$lang_active->method( 'is_active' )->willReturn( true );

		$validator_missing = $this->createMock( WordPressElementValidator::class );
		$validator_missing->method( 'validate' )->willThrowException(
			new InvalidArgumentException( 'El elemento post ID 8839 no existe en WordPress Core.' )
		);

		try {
			$this->retained_registry->promote_retained_translation( 42, 'fr', $group_repo, $lang_active, $validator_missing );
			$this->fail( 'Debió rechazar promoción de elemento inexistente en WordPress.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'no existe en WordPress Core', $e->getMessage() );
		}
		$this->assertTrue( $this->retained_registry->has_retained_element( 'post', 8839 ) );

		// Scenario 11: Element 8839 already assigned in tfml_group_elements -> rejected without corrupting state.
		$group_repo_collision = $this->createMock( TranslationGroupRepository::class );
		$group_repo_collision->method( 'find' )->with( 42 )->willReturn( $group );
		$group_repo_collision->method( 'find_by_element' )->with( 'post', 8839 )->willReturn( new TranslationGroup( 'post', 'page', 900, 99 ) );

		try {
			$this->retained_registry->promote_retained_translation( 42, 'fr', $group_repo_collision, $lang_active, $validator_ok );
			$this->fail( 'Debió rechazar promoción por colisión en tfml_group_elements.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'ya pertenece activamente al grupo TFML ID 99', $e->getMessage() );
		}
		$this->assertTrue( $this->retained_registry->has_retained_element( 'post', 8839 ) );

		// Scenario 12a: Induced write failure in RetainedTranslationRegistry during promotion rolls back transaction!
		$group_repo_tx_fail = $this->createMock( TranslationGroupRepository::class );
		$group_repo_tx_fail->method( 'find' )->with( 42 )->willReturn( $group );
		$group_repo_tx_fail->method( 'find_by_element' )->with( 'post', 8839 )->willReturn( null );
		$group_repo_tx_fail->method( 'add_translation' )->willReturn( TranslationElement::create( 'post', 8839, 'fr' ) );
		$group_repo_tx_fail->expects( $this->once() )->method( 'begin_transaction' );
		$group_repo_tx_fail->expects( $this->once() )->method( 'rollback_transaction' );
		$group_repo_tx_fail->expects( $this->never() )->method( 'commit_transaction' );

		$this->retained_registry->set_simulate_write_failure( true );
		try {
			$this->retained_registry->promote_retained_translation( 42, 'fr', $group_repo_tx_fail, $lang_active, $validator_ok );
			$this->fail( 'Debió revertir la promoción cuando falla la actualización del registro retenido.' );
		} catch ( MigrationExecutionException $e ) {
			$this->assertStringContainsString( 'RetainedTranslationRegistry', $e->getMessage() );
		}
		$this->retained_registry->set_simulate_write_failure( false );
		$this->assertTrue( $this->retained_registry->has_retained_element( 'post', 8839 ) );

		// Scenario 12b: Successful promotion with FR active commits atomically and removes retained record.
		$group_repo_ok = $this->createMock( TranslationGroupRepository::class );
		$group_repo_ok->method( 'find' )->with( 42 )->willReturn( $group );
		$group_repo_ok->method( 'find_by_element' )->with( 'post', 8839 )->willReturn( null );
		$group_repo_ok->expects( $this->once() )->method( 'begin_transaction' );
		$group_repo_ok->expects( $this->once() )->method( 'commit_transaction' );
		$group_repo_ok->expects( $this->never() )->method( 'rollback_transaction' );
		$group_repo_ok->expects( $this->once() )
			->method( 'add_translation' )
			->with( 42, 8839, 'fr', false )
			->willReturn( TranslationElement::create( 'post', 8839, 'fr' ) );

		$promoted = $this->retained_registry->promote_retained_translation( 42, 'fr', $group_repo_ok, $lang_active, $validator_ok );
		$this->assertSame( 8839, $promoted->get_element_id() );
		$this->assertSame( 'fr', $promoted->get_language_code() );
		$this->assertFalse( $this->retained_registry->has_retained_element( 'post', 8839 ) );
		$this->assertNull( $this->retained_registry->get_by_group_id( 42 ) );
	}

	/**
	 * Scenario 13: Reconciliation detects missing retained record or orphan retained record without active TFML group.
	 */
	public function test_scenario_13_reconcile_detects_missing_and_orphan_retained_records(): void {
		$plan             = $this->create_trid_197_partial_plan();
		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		$group = new TranslationGroup( 'post', 'page', 197, 42 );
		$group->add_element( TranslationElement::create( 'post', 197, 'es' ), true );
		$group->add_element( TranslationElement::create( 'post', 8356, 'en' ), false );

		$group_repository->method( 'find_by_element' )->willReturnCallback(
			fn( string $type, int $id ) => in_array( $id, array( 197, 8356 ), true ) ? $group : null
		);
		$group_repository->method( 'find' )->willReturnCallback(
			fn( int $gid ) => 42 === $gid ? $group : null
		);

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repository,
			null,
			null,
			null,
			null,
			null,
			$this->retained_registry
		);

		// Case A: Missing retained record for TRID 197.
		$recon_missing = $executor->reconcile( $plan );
		$this->assertFalse( $recon_missing['integrity_verified'] );
		$this->assertNotEmpty( $recon_missing['discrepancies'] );
		$this->assertStringContainsString( 'no posee registro en RetainedTranslationRegistry', $recon_missing['discrepancies'][0] );

		// Case B: Register valid TRID 197, plus an orphan retained record (TRID 888 pointing to non-existent group 999).
		$this->retained_registry->register( 'wpml', 197, 42, 'post', 'page', array( 'fr' => 8839 ), $plan->get_fingerprint() );
		$this->retained_registry->register( 'wpml', 888, 999, 'post', 'page', array( 'de' => 9999 ), 'fp-orphan' );

		$recon_orphan = $executor->reconcile( $plan );
		$this->assertFalse( $recon_orphan['integrity_verified'] );
		$this->assertNotEmpty( $recon_orphan['discrepancies'] );
		$this->assertStringContainsString( 'Registro retenido huérfano', $recon_orphan['discrepancies'][0] );
	}

	/**
	 * Scenario 14 & 15: Rollback cleans up active group and retained record; repeated rollback is idempotent and preserves other retained records.
	 */
	public function test_scenario_14_and_15_rollback_atomic_cleanup_and_idempotent_preservation_of_other_records(): void {
		$this->retained_registry->register( 'wpml', 197, 42, 'post', 'page', array( 'fr' => 8839 ), 'fp-197' );
		$this->retained_registry->register( 'wpml', 500, 77, 'post', 'page', array( 'it' => 5001 ), 'fp-500' );

		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		$group_42        = new TranslationGroup( 'post', 'page', 197, 42 );
		$group_42_active = true;

		$group_repository->method( 'find' )->willReturnCallback(
			function ( int $gid ) use ( &$group_42_active, $group_42 ) {
				if ( 42 === $gid && $group_42_active ) {
					return $group_42;
				}
				return null;
			}
		);
		$group_repository->method( 'delete' )->with( 42 )->willReturnCallback(
			function () use ( &$group_42_active ) {
				$group_42_active = false;
				return true;
			}
		);

		$rollback_service = new WpmlMigrationRollbackService(
			$db,
			$group_repository,
			new MigrationCheckpointRepository( $db ),
			new MigrationExecutionLock( $db ),
			$this->retained_registry
		);

		// Scenario 14: First rollback cleans up group 42 and its retained record (TRID 197), keeping group 77 (TRID 500) intact.
		$res1 = $rollback_service->rollback_groups( array( 42 ), 'exec-197', false );
		$this->assertTrue( $res1['success'] );
		$this->assertSame( 1, $res1['reverted_groups_count'] );
		$this->assertNull( $this->retained_registry->get_by_group_id( 42 ) );
		$this->assertNotNull( $this->retained_registry->get_by_group_id( 77 ) );

		// Scenario 15: Repeated rollback on already-reverted group 42 is idempotent and does not touch group 77 (TRID 500).
		$res2 = $rollback_service->rollback_groups( array( 42 ), 'exec-197', false );
		$this->assertTrue( $res2['success'] );
		$this->assertSame( 0, $res2['reverted_groups_count'] );
		$this->assertNotNull( $this->retained_registry->get_by_group_id( 77 ) );
		$this->assertTrue( $this->retained_registry->has_retained_element( 'post', 5001 ) );
	}
}
