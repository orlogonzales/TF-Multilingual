<?php
/**
 * Translations Overview UI Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\TranslationsOverviewUi;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class TranslationsOverviewUiTest
 */
class TranslationsOverviewUiTest extends TestCase {

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
	 * Editorial service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $editorial_service;

	/**
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

	/**
	 * UI under test.
	 *
	 * @var TranslationsOverviewUi
	 */
	private TranslationsOverviewUi $ui;

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

		$content_resolver        = new ContentTranslationResolver( $this->group_repository, $this->language_registry, $validator );
		$this->retained_registry = new RetainedTranslationRegistry( $this->db );

		$this->editorial_service = new TranslationEditorialService(
			$this->language_registry,
			$this->group_repository,
			$content_resolver,
			$validator,
			$this->db,
			null,
			null,
			null,
			$this->retained_registry
		);

		$this->ui = new TranslationsOverviewUi(
			$this->group_repository,
			$this->language_registry,
			$this->editorial_service,
			$this->retained_registry
		);

		$_GET  = array();
		$_POST = array();
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		$_GET                       = array();
		$_POST                      = array();
		$GLOBALS['wp_test_options'] = array();
		parent::tearDown();
	}

	/**
	 * Tests get_capability returns edit_posts.
	 */
	public function test_get_capability(): void {
		$this->assertSame( 'edit_posts', $this->ui->get_capability() );
	}

	/**
	 * Tests init_hooks registers admin_menu action.
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
	 * Tests render_page outputs complete overview HTML.
	 */
	public function test_render_page_outputs_html(): void {
		// Seed mock post.
		$mock_post                     = new \stdClass();
		$mock_post->ID                 = 101;
		$mock_post->post_title         = 'Excursión Machu Picchu';
		$mock_post->post_status        = 'publish';
		$GLOBALS['wp_test_posts'][101] = $mock_post;

		// Seed a translation group.
		$group = $this->group_repository->create_group( 'post', 'tours', 101, 'es', true );
		$this->group_repository->add_translation( $group->get_id(), 102, 'en' );

		// Seed a retained translation.
		$this->retained_registry->register(
			'wpml',
			1001,
			$group->get_id(),
			'post',
			'tours',
			array( 'fr' => 8839 )
		);

		ob_start();
		$this->ui->render_page();
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'Centro de Traducciones', $output );
		$this->assertStringContainsString( 'tfml-kpi-card', $output );
		$this->assertStringContainsString( 'Grupos Registrados', $output );
		$this->assertStringContainsString( 'Elementos Vinculados', $output );
		$this->assertStringContainsString( 'Idiomas Activos', $output );
		$this->assertStringContainsString( 'Traducciones Retenidas', $output );
		$this->assertStringContainsString( 'wp-list-table', $output );
		$this->assertStringContainsString( 'Traducciones Históricas Retenidas en Custodia', $output );
		$this->assertStringContainsString( '8839', $output );
	}

	/**
	 * Tests render_page with search and filters.
	 */
	public function test_render_page_with_filters(): void {
		$_GET['s']       = 'Tour';
		$_GET['subtype'] = 'tours';
		$_GET['status']  = 'incomplete';
		$_GET['lang']    = 'pt';
		$_GET['paged']   = '1';

		$group = $this->group_repository->create_group( 'post', 'tours', 101, 'es', true );
		$this->group_repository->add_translation( $group->get_id(), 102, 'en' );

		ob_start();
		$this->ui->render_page();
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'Centro de Traducciones', $output );
	}

	/**
	 * Tests get_element_display_title helper.
	 */
	public function test_get_element_display_title(): void {
		$mock_post                     = new \stdClass();
		$mock_post->ID                 = 201;
		$mock_post->post_title         = 'Página de Inicio';
		$GLOBALS['wp_test_posts'][201] = $mock_post;

		$title = $this->ui->get_element_display_title( 'post', 201, 'page' );
		$this->assertSame( 'Página de Inicio', $title );

		$fallback = $this->ui->get_element_display_title( 'post', 9999, 'page' );
		$this->assertStringContainsString( '9999', $fallback );
	}

	/**
	 * Tests get_post_status_label helper.
	 */
	public function test_get_post_status_label(): void {
		$mock_post                     = new \stdClass();
		$mock_post->ID                 = 301;
		$mock_post->post_status        = 'publish';
		$GLOBALS['wp_test_posts'][301] = $mock_post;

		$label = $this->ui->get_post_status_label( 301 );
		$this->assertSame( 'Publicado', $label );
	}
}
