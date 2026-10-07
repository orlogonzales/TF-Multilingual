<?php
/**
 * Navigation Menu Location Repository Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Term;

/**
 * Class NavMenuLocationRepositoryTest
 */
class NavMenuLocationRepositoryTest extends TestCase {

	/**
	 * Testable wpdb instance.
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
	 * Group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repo;

	/**
	 * Translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $resolver;

	/**
	 * Location repository under test.
	 *
	 * @var NavMenuLocationRepository
	 */
	private NavMenuLocationRepository $location_repo;

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
		$GLOBALS['wp_test_terms']                = array();

		$this->settings_repo = new SettingsRepository();
		$this->lang_registry = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$pt = Language::create( 'pt', 'pt_PT', 'Português', 'Português', true, 30 );

		$this->lang_registry->add_language( $es, true );
		$this->lang_registry->add_language( $en );
		$this->lang_registry->add_language( $pt );

		$this->group_repo    = new TranslationGroupRepository( $this->wpdb, $this->lang_registry );
		$this->resolver      = new ContentTranslationResolver( $this->group_repo, $this->lang_registry );
		$this->location_repo = new NavMenuLocationRepository(
			$this->settings_repo,
			$this->lang_registry,
			$this->resolver,
			true
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
			$GLOBALS['wp_test_terms']
		);
		parent::tearDown();
	}

	/**
	 * Helper to create a test nav_menu term.
	 */
	private function create_menu_term( int $id, string $name, string $slug ): WP_Term {
		$term           = new WP_Term();
		$term->term_id  = $id;
		$term->name     = $name;
		$term->slug     = $slug;
		$term->taxonomy = 'nav_menu';

		$GLOBALS['wp_test_terms'][ $id ] = $term;
		return $term;
	}

	/**
	 * Tests default language resolves to native WordPress location mapping.
	 */
	public function test_default_language_resolves_native_location(): void {
		$menu_id = $this->location_repo->get_menu_for_location( 'primary', 'es' );
		$this->assertSame( 43, $menu_id );
	}

	/**
	 * Tests secondary language resolves explicit mapping from settings.
	 */
	public function test_secondary_language_resolves_explicit_setting(): void {
		$this->location_repo->set_menu_for_location( 'primary', 'en', 98 );
		$this->location_repo->persist();

		$resolved = $this->location_repo->get_menu_for_location( 'primary', 'en' );
		$this->assertSame( 98, $resolved );
	}

	/**
	 * Tests secondary language resolves via TranslationGroup when no explicit mapping exists.
	 */
	public function test_secondary_language_resolves_via_translation_group(): void {
		$this->create_menu_term( 43, 'Menu Principal ES', 'menu-es' );
		$this->create_menu_term( 98, 'Main Menu EN', 'menu-en' );

		// Create group linking ES 43 to EN 98 under subtype 'nav_menu'
		$group = $this->group_repo->create_group( 'term', 'nav_menu', 43, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 98, 'en' );

		// No explicit setting in settings_repo
		$resolved = $this->location_repo->get_menu_for_location( 'primary', 'en' );
		$this->assertSame( 98, $resolved, 'Should resolve EN translation term 98 from TranslationGroup' );
	}

	/**
	 * Tests missing translation falls back to default language menu when fallback policy is enabled.
	 */
	public function test_missing_translation_falls_back_to_default_menu(): void {
		$this->location_repo->set_fallback_to_default( true );

		// PT is not configured and has no translation group
		$resolved = $this->location_repo->get_menu_for_location( 'primary', 'pt' );
		$this->assertSame( 43, $resolved, 'Must fallback to canonical menu 43' );
	}

	/**
	 * Tests missing translation returns null when fallback policy is disabled.
	 */
	public function test_missing_translation_returns_null_when_fallback_disabled(): void {
		$this->location_repo->set_fallback_to_default( false );

		$resolved = $this->location_repo->get_menu_for_location( 'primary', 'pt' );
		$this->assertNull( $resolved, 'Must return null when fallback is disabled' );
	}

	/**
	 * Tests invalid inputs return null.
	 */
	public function test_invalid_inputs_return_null(): void {
		$this->assertNull( $this->location_repo->get_menu_for_location( '', 'en' ) );
		$this->assertNull( $this->location_repo->get_menu_for_location( 'primary', '' ) );
	}

	/**
	 * Tests removing location mapping.
	 */
	public function test_remove_location_mapping(): void {
		$this->location_repo->set_menu_for_location( 'primary', 'en', 98 );
		$this->assertSame( 98, $this->location_repo->get_menu_for_location( 'primary', 'en' ) );

		$this->location_repo->set_menu_for_location( 'primary', 'en', null );
		// Without explicit setting, and no group, falls back to default 43
		$this->assertSame( 43, $this->location_repo->get_menu_for_location( 'primary', 'en' ) );
	}
}
