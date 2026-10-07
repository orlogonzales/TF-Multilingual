<?php
/**
 * Navigation Menu Editorial UI Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\NavMenuEditorialUi;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class NavMenuEditorialUiTest
 */
class NavMenuEditorialUiTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $wpdb;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings_repo;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $lang_registry;

	/**
	 * Location repository.
	 *
	 * @var NavMenuLocationRepository
	 */
	private NavMenuLocationRepository $location_repo;

	/**
	 * Editorial UI under test.
	 *
	 * @var NavMenuEditorialUi
	 */
	private NavMenuEditorialUi $ui;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = new TestableWpdb();
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		$GLOBALS['wp_test_options']              = array();
		$GLOBALS['wp_test_nav_menu_locations']   = array( 'primary' => 43 );
		$GLOBALS['wp_test_registered_nav_menus'] = array( 'primary' => 'Primary Menu' );
		$GLOBALS['wp_test_nav_menus']            = array(
			43 => (object) array(
				'term_id' => 43,
				'name'    => 'Menu Principal',
			),
			98 => (object) array(
				'term_id' => 98,
				'name'    => 'Main Menu EN',
			),
		);

		$this->settings_repo = new SettingsRepository();
		$this->lang_registry = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->lang_registry->add_language( $es, true );
		$this->lang_registry->add_language( $en );

		$group_repo          = new TranslationGroupRepository( $this->wpdb, $this->lang_registry );
		$resolver            = new ContentTranslationResolver( $group_repo, $this->lang_registry );
		$this->location_repo = new NavMenuLocationRepository(
			$this->settings_repo,
			$this->lang_registry,
			$resolver
		);

		$this->ui = new NavMenuEditorialUi(
			$this->location_repo,
			$this->lang_registry,
			$resolver,
			$group_repo
		);
	}

	/**
	 * Tears down test environment.
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = new \wpdb();
		unset(
			$GLOBALS['wp_test_options'],
			$GLOBALS['wp_test_nav_menu_locations'],
			$GLOBALS['wp_test_registered_nav_menus'],
			$GLOBALS['wp_test_nav_menus']
		);
		parent::tearDown();
	}

	/**
	 * Tests handle_form_submission saves secondary language location mapping.
	 */
	public function test_handle_form_submission_saves_locations(): void {
		$_GET['page']                            = NavMenuEditorialUi::PAGE_SLUG;
		$_SERVER['REQUEST_METHOD']               = 'POST';
		$_POST[ NavMenuEditorialUi::NONCE_NAME ] = 'valid_nonce';
		$_POST['tfml_nav_locations']             = array(
			'primary' => array(
				'en' => 98,
			),
		);

		// Current user has capability
		$GLOBALS['wp_test_current_user_can'] = true;

		$this->ui->handle_form_submission();

		$saved_menu = $this->location_repo->get_menu_for_location( 'primary', 'en' );
		$this->assertSame( 98, $saved_menu );
	}

	/**
	 * Tests render_admin_page outputs HTML table with registered locations and languages.
	 */
	public function test_render_admin_page_outputs_html(): void {
		$GLOBALS['wp_test_current_user_can'] = true;

		ob_start();
		$this->ui->render_admin_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Navegación y Menús Multilingües', $html );
		$this->assertStringContainsString( 'Primary Menu', $html );
		$this->assertStringContainsString( 'primary', $html );
		$this->assertStringContainsString( 'Español', $html );
		$this->assertStringContainsString( 'English', $html );
		$this->assertStringContainsString( 'Main Menu EN', $html );
	}
}
