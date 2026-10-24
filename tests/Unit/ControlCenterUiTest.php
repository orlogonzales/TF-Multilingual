<?php
/**
 * Control Center UI Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\ControlCenterUi;
use TF\Multilingual\Diagnostic\DiagnosticService;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class ControlCenterUiTest
 */
class ControlCenterUiTest extends TestCase {

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
	 * Retained registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

	/**
	 * Diagnostic service.
	 *
	 * @var DiagnosticService
	 */
	private DiagnosticService $diagnostic_service;

	/**
	 * Control Center UI under test.
	 *
	 * @var ControlCenterUi
	 */
	private ControlCenterUi $ui;

	/**
	 * Sets up test fixtures before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db         = new TestableWpdb();
		$this->db->prefix = 'etk_';
		$GLOBALS['wpdb']  = $this->db;

		$GLOBALS['wp_test_caps']    = array( 'manage_options' => true );
		$GLOBALS['wp_test_options'] = array();
		$GLOBALS['wp_test_posts']   = array();

		$settings_repo           = new SettingsRepository();
		$this->language_registry = new LanguageRegistry( $settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 1 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 2 );
		$pt = Language::create( 'pt', 'pt_PT', 'Português', 'Português', true, 3 );

		$this->language_registry->add_language( $es, true );
		$this->language_registry->add_language( $en );
		$this->language_registry->add_language( $pt );

		$this->group_repository  = new TranslationGroupRepository( $this->db, $this->language_registry );
		$this->retained_registry = new RetainedTranslationRegistry( $this->db );

		$media_repo = new MediaTranslationRepository( $this->db );
		$str_repo   = new StringRepository( $this->db );
		$resolver   = new ContentTranslationResolver( $this->group_repository, $this->language_registry );
		$nav_repo   = new NavMenuLocationRepository( $settings_repo, $this->language_registry, $resolver );

		$this->diagnostic_service = new DiagnosticService(
			$this->language_registry,
			$settings_repo,
			$this->group_repository,
			$media_repo,
			$str_repo,
			$nav_repo,
			$this->db,
			$this->retained_registry
		);

		$this->ui = new ControlCenterUi(
			$this->language_registry,
			$this->group_repository,
			$this->retained_registry,
			$this->diagnostic_service
		);
	}

	/**
	 * Tears down test environment.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['wp_test_caps'],
			$GLOBALS['wp_test_options'],
			$GLOBALS['wp_test_posts'],
			$GLOBALS['admin_page_hooks']
		);
		parent::tearDown();
	}

	/**
	 * Tests menu registration executes cleanly.
	 */
	public function test_register_menu_page_executes(): void {
		$this->ui->register_menu_page();
		$this->assertTrue( true );
	}

	/**
	 * Tests render_page outputs Spatial UI Control Center dashboard.
	 */
	public function test_render_page_outputs_spatial_dashboard(): void {
		// Register a sample retained translation
		$this->retained_registry->register(
			'wpml',
			197,
			1,
			'post',
			'page',
			array(
				'fr' => array(
					'element_id' => 9250,
					'post_type'  => 'page',
				),
			)
		);

		ob_start();
		$this->ui->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'tfml-spatial-wrap', $output );
		$this->assertStringContainsString( 'Control Center', $output );
		$this->assertStringContainsString( 'Grupos activos', $output );
		$this->assertStringContainsString( 'Elementos vinculados', $output );
		$this->assertStringContainsString( 'Idiomas activos', $output );
		$this->assertStringContainsString( 'Traducciones FR retenidas', $output );
		$this->assertStringContainsString( 'Salud óptima', $output );
		$this->assertStringContainsString( 'Revisión rápida', $output );
		$this->assertStringContainsString( 'Historiales de migración', $output );
		$this->assertStringContainsString( 'Diagnóstico del sistema', $output );
		$this->assertStringContainsString( 'tfml-timeline', $output );
		$this->assertStringContainsString( 'tfml-lang-chips', $output );
		$this->assertStringContainsString( '¿Necesitas ayuda?', $output );
	}

	/**
	 * Tests render_page denies access when user lacks manage_options.
	 */
	public function test_render_page_denies_unauthorized_user(): void {
		$GLOBALS['wp_test_caps']['manage_options'] = false;

		$this->expectException( \RuntimeException::class );
		$this->ui->render_page();
	}
}
