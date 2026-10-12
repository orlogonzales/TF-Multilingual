<?php
/**
 * Partial Retained Migration Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use wpdb;

/**
 * Class PartialRetainedMigrationTest
 */
class PartialRetainedMigrationTest extends TestCase {

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
		$this->retained_registry->purge_all();
		delete_option( 'tfml_migration_checkpoint_wpml' );
		parent::tearDown();
	}

	/**
	 * Create a mock master plan with TRID 197 as BLOCKED_BY_LANGUAGE.
	 *
	 * @return MigrationPlan
	 */
	private function create_master_plan_with_trid_197(): MigrationPlan {
		return new MigrationPlan(
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
	}

	/**
	 * Test derive_partial_plan rejects BLOCKED_BY_LANGUAGE group without explicit authorization.
	 */
	public function test_derive_partial_plan_rejects_blocked_group_without_authorization(): void {
		$master_plan = $this->create_master_plan_with_trid_197();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/no es elegible para el plan parcial.*BLOCKED_BY_LANGUAGE/' );

		$master_plan->derive_partial_plan( array( 197 ), 'unauthorized-test' );
	}

	/**
	 * Test derive_partial_plan succeeds with explicit authorization for retained languages.
	 */
	public function test_derive_partial_plan_succeeds_with_explicit_authorization(): void {
		$master_plan = $this->create_master_plan_with_trid_197();

		$derived = $master_plan->derive_partial_plan(
			array( 197 ),
			'pilot-partial-197',
			array(
				197 => array(
					'authorized_languages' => array( 'es', 'en' ),
					'allow_retained'       => true,
				),
			)
		);

		$this->assertTrue( $derived->is_partial() );
		$this->assertSame( MigrationPlan::STATUS_READY, $derived->get_status() );
		$this->assertSame( 'pilot-partial-197', $derived->get_label() );
		$this->assertSame( $master_plan->get_fingerprint(), $derived->get_parent_fingerprint() );

		$groups = $derived->get_planned_groups();
		$this->assertCount( 1, $groups );

		$group = $groups[0];
		$this->assertSame( 197, $group['trid'] );
		$this->assertSame( MigrationPlan::CLASSIFICATION_PARTIAL_RETAINED, $group['classification'] );
		$this->assertSame(
			array(
				'es' => 197,
				'en' => 8356,
			),
			$group['translations']
		);
		$this->assertSame( array( 'fr' => 8839 ), $group['pending_translations'] );

		$summary = $derived->get_summary();
		$this->assertSame( 1, $summary['total_groups'] );
		$this->assertSame( 1, $summary['importable_groups'] );
		$this->assertSame( 1, $summary['partial_retained_groups'] );
		$this->assertSame( 0, $summary['blocked_language_groups'] );
		$this->assertSame( 2, $summary['total_elements'] );
		$this->assertSame( 1, $summary['pending_elements'] );
	}

	/**
	 * Test derive_partial_plan_with_retained_languages helper.
	 */
	public function test_derive_partial_plan_with_retained_languages_helper(): void {
		$master_plan = $this->create_master_plan_with_trid_197();

		$derived = $master_plan->derive_partial_plan_with_retained_languages(
			array(
				array(
					'trid'                 => 197,
					'authorized_languages' => array( 'es', 'en' ),
				),
			),
			'helper-test'
		);

		$this->assertSame( MigrationPlan::STATUS_READY, $derived->get_status() );
		$groups = $derived->get_planned_groups();
		$this->assertSame( MigrationPlan::CLASSIFICATION_PARTIAL_RETAINED, $groups[0]['classification'] );
	}

	/**
	 * Test execute_batch with PARTIAL_RETAINED imports active elements and registers retained elements.
	 */
	public function test_executor_processes_partial_retained_group(): void {
		$master_plan  = $this->create_master_plan_with_trid_197();
		$partial_plan = $master_plan->derive_partial_plan(
			array( 197 ),
			'test-exec',
			array( 197 => true )
		);

		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		// Return null on find_by_element (elements not yet in TFML):
		$group_repository->method( 'find_by_element' )->willReturn( null );

		// Return persisted group with ID 42:
		$persisted_group = new TranslationGroup( 'post', 'page', 197, 42 );

		$group_repository->expects( $this->once() )
			->method( 'create_group' )
			->with( 'post', 'page', 197, 'es', true )
			->willReturn( $persisted_group );

		$added_element = TranslationElement::create( 'post', 8356, 'en' );
		$group_repository->expects( $this->once() )
			->method( 'add_translation' )
			->with( 42, 8356, 'en', false )
			->willReturn( $added_element );

		$checkpoint_repo = $this->createMock( MigrationCheckpointRepository::class );
		$lock            = $this->createMock( MigrationExecutionLock::class );
		$lock->method( 'verify_ownership' )->willReturn( true );

		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->method( 'exists' )->willReturn( true );

		$schema_manager = $this->createMock( SchemaManager::class );
		$schema_manager->method( 'is_installed' )->willReturn( true );

		$executor = new WpmlMigrationExecutor(
			$db,
			$group_repository,
			null,
			$checkpoint_repo,
			$lock,
			$validator,
			$schema_manager,
			$this->retained_registry
		);
		$executor->set_authorized( true );

		$result = $executor->execute_batch( $partial_plan, 10, 'exec-123', null, $partial_plan->get_fingerprint() );

		$this->assertTrue( $result['finished'] );
		$batch_res = $result['batch_results'][0];
		$this->assertSame( 197, $batch_res['trid'] );
		$this->assertSame( 'imported_partial_retained', $batch_res['status'] );
		$this->assertSame( 42, $batch_res['group_id'] );
		$this->assertSame( array( 'fr' => 8839 ), $batch_res['retained_translations'] );

		// Verify retained translation was recorded in registry:
		$retained = $this->retained_registry->get( 'wpml', 197 );
		$this->assertNotNull( $retained );
		$this->assertSame( 42, $retained['group_id'] );
		$this->assertArrayHasKey( 'fr', $retained['retained_elements'] );
		$this->assertSame( 8839, $retained['retained_elements']['fr']['element_id'] );
	}

	/**
	 * Test reconciliation verifies active and retained translation consistency.
	 */
	public function test_reconcile_verifies_partial_retained_group(): void {
		$master_plan  = $this->create_master_plan_with_trid_197();
		$partial_plan = $master_plan->derive_partial_plan(
			array( 197 ),
			'test-reconcile',
			array( 197 => true )
		);

		// Pre-register retained translation:
		$this->retained_registry->register(
			'wpml',
			197,
			42,
			'post',
			'page',
			array( 'fr' => 8839 ),
			$partial_plan->get_fingerprint()
		);

		$db               = $this->createMock( wpdb::class );
		$db->prefix       = 'etk_t_';
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		// Active translation group with ES (197) and EN (8356):
		$el_es = TranslationElement::create( 'post', 197, 'es' );
		$el_en = TranslationElement::create( 'post', 8356, 'en' );

		$group = new TranslationGroup( 'post', 'page', 197, 42 );
		$group->add_element( $el_es, true );
		$group->add_element( $el_en, false );

		$group_repository->method( 'find_by_element' )->willReturnCallback(
			function ( string $type, int $id ) use ( $group ) {
				if ( 197 === $id || 8356 === $id ) {
					return $group;
				}
				// Crucial: 8839 (French) returns null because it is NOT active in TFML!
				return null;
			}
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

		$recon = $executor->reconcile( $partial_plan );

		$this->assertTrue( $recon['integrity_verified'] );
		$this->assertEmpty( $recon['discrepancies'] );
		$this->assertSame( 1, $recon['tfml_imported_groups'] );
		$this->assertSame( 2, $recon['tfml_imported_elements'] );
		$this->assertSame( 1, $recon['retained_pending_elements'] );
	}

	/**
	 * Test rollback cleans up retained translations from registry.
	 */
	public function test_rollback_cleans_up_retained_translations(): void {
		$this->retained_registry->register(
			'wpml',
			197,
			42,
			'post',
			'page',
			array( 'fr' => 8839 )
		);

		$this->assertNotNull( $this->retained_registry->get_by_group_id( 42 ) );

		$db               = $this->createMock( wpdb::class );
		$group_repository = $this->createMock( TranslationGroupRepository::class );

		$group = new TranslationGroup( 'post', 'page', 197, 42 );

		$group_repository->method( 'find' )->with( 42 )->willReturn( $group );
		$group_repository->expects( $this->once() )
			->method( 'delete' )
			->with( 42 )
			->willReturn( true );

		$checkpoint_repo = $this->createMock( MigrationCheckpointRepository::class );
		$lock            = $this->createMock( MigrationExecutionLock::class );

		$rollback_service = new WpmlMigrationRollbackService(
			$db,
			$group_repository,
			$checkpoint_repo,
			$lock,
			$this->retained_registry
		);

		$res = $rollback_service->rollback_groups( array( 42 ), 'exec-rollback-test', false );

		$this->assertTrue( $res['success'] );
		$this->assertSame( 1, $res['reverted_groups_count'] );

		// Verify retained translations for group 42 were deleted:
		$this->assertNull( $this->retained_registry->get_by_group_id( 42 ) );
		$this->assertFalse( $this->retained_registry->has_retained_element( 'post', 8839 ) );
	}
}
