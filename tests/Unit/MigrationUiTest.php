<?php
/**
 * Migration UI Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\MigrationUi;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationManager;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class MigrationUiTest
 */
class MigrationUiTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

	/**
	 * Migration manager.
	 *
	 * @var MigrationManager
	 */
	private MigrationManager $migration_manager;

	/**
	 * UI under test.
	 *
	 * @var MigrationUi
	 */
	private MigrationUi $ui;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db                   = new TestableWpdb();
		$GLOBALS['wpdb']            = $this->db;
		$GLOBALS['wp_test_options'] = array();

		$settings_repo           = new SettingsRepository();
		$this->language_registry = new LanguageRegistry( $settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$pt = Language::create( 'pt', 'pt_PT', 'Portuguese', 'Português', true, 30 );
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', false, 40 );

		$this->language_registry->add_language( $es, true );
		$this->language_registry->add_language( $en );
		$this->language_registry->add_language( $pt );
		$this->language_registry->add_language( $fr );
		$this->language_registry->persist();

		$validator              = new WordPressElementValidator();
		$this->group_repository = new TranslationGroupRepository(
			$this->db,
			$this->language_registry,
			$validator
		);

		$this->retained_registry = new RetainedTranslationRegistry( $this->db );
		$this->migration_manager = new MigrationManager(
			$this->db,
			$this->language_registry
		);

		$this->ui = new MigrationUi(
			$this->migration_manager,
			$this->language_registry,
			$this->group_repository,
			$this->retained_registry,
			$this->db
		);

		$_GET                      = array();
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$GLOBALS['wp_test_caps']   = array( 'manage_options' => true );
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		$_GET                       = array();
		$_POST                      = array();
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$GLOBALS['wp_test_options'] = array();
		$GLOBALS['wp_test_caps']    = array();
		Plugin::reset_instance();
		parent::tearDown();
	}

	/**
	 * Tests get_capability returns manage_options.
	 */
	public function test_get_capability_returns_manage_options(): void {
		$this->assertSame( 'manage_options', $this->ui->get_capability() );
	}

	/**
	 * Tests init_hooks registers admin_menu action without errors.
	 */
	public function test_init_hooks(): void {
		$this->ui->init_hooks();
		$this->assertTrue( true );
	}

	/**
	 * Tests register_menu_page executes without error.
	 */
	public function test_register_menu_page(): void {
		$this->ui->register_menu_page();
		$this->assertTrue( true );
	}

	/**
	 * Tests render_page enforces manage_options capability.
	 */
	public function test_render_page_denies_unauthorized_user(): void {
		$GLOBALS['wp_test_caps'] = array( 'manage_options' => false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 403 );
		$this->ui->render_page();
	}

	/**
	 * Tests render_page rejects POST attempts (strictly read-only).
	 */
	public function test_render_page_rejects_post_request(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 403 );
		$this->ui->render_page();
	}

	/**
	 * Tests render_page outputs default Tab 1 (Plan) with security banner and KPIs.
	 */
	public function test_render_page_outputs_plan_tab(): void {
		$_GET = array(
			'page' => 'tfml-migration',
			'tab'  => 'plan',
		);

		ob_start();
		$this->ui->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Migración WPML', $output );
		$this->assertStringContainsString( 'Modo Consulta y Auditoría', $output );
		$this->assertStringContainsString( 'Registros WPML', $output );
		$this->assertStringContainsString( 'Grupos en TFML', $output );
		$this->assertStringContainsString( 'En Custodia (FR)', $output );
		$this->assertStringContainsString( 'Huella Criptográfica SHA-256 del Plan', $output );
		$this->assertStringContainsString( 'MEDIA_MODEL_B_DEDUPLICATION', $output );
		$this->assertStringContainsString( 'Buscar por TRID, ID o título...', $output );
	}

	/**
	 * Tests render_page outputs Tab 2 (History) with checkpoint and manifests.
	 */
	public function test_render_page_outputs_history_tab(): void {
		$_GET = array(
			'page' => 'tfml-migration',
			'tab'  => 'history',
		);

		// Seed a mock checkpoint.
		$checkpoint_repo = $this->migration_manager->get_checkpoint_repository();
		$checkpoint      = new MigrationCheckpoint(
			array(
				'source'              => 'wpml',
				'status'              => 'completed',
				'last_processed_trid' => 9545,
				'processed_items'     => 2,
				'total_items'         => 2,
			)
		);
		$checkpoint_repo->save( $checkpoint );

		// Seed mock manifest in options.
		$GLOBALS['wp_test_options']['tfml_migration_manifest_test_token_1'] = array(
			'execution_id'          => 'test_token_1',
			'source'                => 'wpml',
			'plan_fingerprint'      => 'c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99',
			'imported_groups'       => array( 1, 2, 3 ),
			'retained_translations' => array(),
			'started_at'            => '2026-10-18T10:00:00+00:00',
			'finished_at'           => '2026-10-18T10:01:00+00:00',
			'status'                => 'completed',
		);

		ob_start();
		$this->ui->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Punto de Control (Checkpoint) Activo', $output );
		$this->assertStringContainsString( 'Historial de Manifiestos de Ejecución', $output );
		$this->assertStringContainsString( 'Historial de Lotes (1)', $output );
		$this->assertStringContainsString( '#9545', $output );
		$this->assertStringContainsString( 'test_token_1', $output );
		$this->assertStringContainsString( 'c82cb04b1cd6c79e...', $output );
	}

	/**
	 * Tests render_page outputs Tab 3 (Retained translations in custody).
	 */
	public function test_render_page_outputs_retained_tab(): void {
		$_GET = array(
			'page' => 'tfml-migration',
			'tab'  => 'retained',
		);

		// Seed retained French record.
		$this->retained_registry->register(
			'wpml',
			197,
			587,
			'post',
			'page',
			array( 'fr' => 8839 )
		);

		ob_start();
		$this->ui->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Traducciones Retenidas en Custodia (Idioma Inactivo FR)', $output );
		$this->assertStringContainsString( 'Modelo de Cero Polución', $output );
		$this->assertStringContainsString( 'En custodia — idioma inactivo', $output );
		$this->assertStringContainsString( 'retained_inactive_language', $output );
		$this->assertStringContainsString( '#197', $output );
		$this->assertStringContainsString( 'Grupo #587', $output );
		$this->assertStringContainsString( '#8839', $output );
		$this->assertStringContainsString( 'FR', $output );
	}

	/**
	 * Tests render_page outputs Tab 4 (Technical Proposal for next batch).
	 */
	public function test_render_page_outputs_next_batch_tab(): void {
		$_GET = array(
			'page' => 'tfml-migration',
			'tab'  => 'next_batch',
		);

		ob_start();
		$this->ui->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Propuesta Técnica: Próximo Lote Mínimo Elegible de Páginas', $output );
		$this->assertStringContainsString( '1. Páginas Independientes', $output );
		$this->assertStringContainsString( '2. Páginas Jerárquicas con Padre Migrado', $output );
		$this->assertStringContainsString( 'Precondiciones Mandatorias para la Futura Ejecución', $output );
	}

	/**
	 * Tests Plugin integrates MigrationUi and exposes getter.
	 */
	public function test_plugin_integrates_migration_ui(): void {
		$plugin = Plugin::get_instance();
		$plugin->init();
		$this->assertNotNull( $plugin );
		$this->assertInstanceOf( MigrationUi::class, $plugin->get_migration_ui() );
	}
}
