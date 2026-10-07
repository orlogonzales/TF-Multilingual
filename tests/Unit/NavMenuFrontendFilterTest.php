<?php
/**
 * Navigation Menu Frontend Filter Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Navigation\NavMenuFrontendFilter;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;
use WP_Term;

/**
 * Class NavMenuFrontendFilterTest
 */
class NavMenuFrontendFilterTest extends TestCase {

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
	 * Group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repo;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $resolver;

	/**
	 * URL generator.
	 *
	 * @var LocalizedUrlGenerator
	 */
	private LocalizedUrlGenerator $url_generator;

	/**
	 * Location repository.
	 *
	 * @var NavMenuLocationRepository
	 */
	private NavMenuLocationRepository $location_repo;

	/**
	 * Frontend filter under test.
	 *
	 * @var NavMenuFrontendFilter
	 */
	private NavMenuFrontendFilter $filter;

	/**
	 * Simulated current language.
	 *
	 * @var string
	 */
	private string $current_language = 'es';

	/**
	 * Sets up test environment.
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
		$GLOBALS['wp_test_posts']                = array();
		$GLOBALS['wp_test_permalinks']           = array();
		$GLOBALS['wp_test_term_links']           = array();

		$this->settings_repo = new SettingsRepository();
		$this->lang_registry = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->lang_registry->add_language( $es, true );
		$this->lang_registry->add_language( $en );

		$this->group_repo    = new TranslationGroupRepository( $this->wpdb, $this->lang_registry );
		$this->resolver      = new ContentTranslationResolver( $this->group_repo, $this->lang_registry );
		$this->location_repo = new NavMenuLocationRepository(
			$this->settings_repo,
			$this->lang_registry,
			$this->resolver
		);

		$url_resolver        = new UrlLanguageResolver( $this->lang_registry, 'http://example.com' );
		$this->url_generator = new LocalizedUrlGenerator(
			$this->lang_registry,
			$url_resolver,
			$this->resolver,
			'http://example.com'
		);

		// CurrentLanguageResolver test double
		$current_lang_resolver = $this->createMock( CurrentLanguageResolver::class );
		$current_lang_resolver->method( 'get_current_language' )
			->willReturnCallback( fn(): string => $this->current_language );

		$this->filter = new NavMenuFrontendFilter(
			$this->location_repo,
			$current_lang_resolver,
			$this->lang_registry,
			$this->resolver,
			$this->url_generator
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
			$GLOBALS['wp_test_terms'],
			$GLOBALS['wp_test_posts'],
			$GLOBALS['wp_test_permalinks'],
			$GLOBALS['wp_test_term_links']
		);
		parent::tearDown();
	}

	/**
	 * Helper to create a nav_menu_item post object.
	 */
	private function create_menu_item( int $id, string $title, string $type, string $object_type, int $object_id, string $url ): WP_Post {
		$post            = new WP_Post();
		$post->ID        = $id;
		$post->post_type = 'nav_menu_item';
		$post->title     = $title;
		$post->type      = $type;
		$post->object    = $object_type;
		$post->object_id = (string) $object_id;
		$post->url       = $url;

		$GLOBALS['wp_test_posts'][ $id ] = $post;
		return $post;
	}

	/**
	 * Tests theme_mod_nav_menu_locations returns native locations untouched in default language.
	 */
	public function test_filter_nav_menu_locations_untouched_in_default_language(): void {
		$this->current_language = 'es';
		$locations              = array( 'primary' => 43 );

		$filtered = $this->filter->filter_nav_menu_locations( $locations );
		$this->assertSame( array( 'primary' => 43 ), $filtered );
	}

	/**
	 * Tests theme_mod_nav_menu_locations swaps location to translated menu in secondary language.
	 */
	public function test_filter_nav_menu_locations_swaps_menu_in_secondary_language(): void {
		$this->current_language = 'en';
		$this->location_repo->set_menu_for_location( 'primary', 'en', 98 );

		$locations = array( 'primary' => 43 );
		$filtered  = $this->filter->filter_nav_menu_locations( $locations );

		$this->assertSame( array( 'primary' => 98 ), $filtered );
	}

	/**
	 * Tests wp_nav_menu_args swaps menu when called directly without theme_location.
	 */
	public function test_filter_wp_nav_menu_args_translates_direct_menu_call(): void {
		$this->current_language = 'en';

		// Group linking menu 43 (es) to 98 (en)
		$group = $this->group_repo->create_group( 'term', 'nav_menu', 43, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 98, 'en' );

		$args = array(
			'menu'           => 43,
			'theme_location' => '',
		);

		$filtered = $this->filter->filter_wp_nav_menu_args( $args );
		$this->assertSame( 98, $filtered['menu'] );
	}

	/**
	 * Tests wp_nav_menu_objects localizes post, term, and custom URLs in secondary language.
	 */
	public function test_filter_wp_nav_menu_objects_localizes_items(): void {
		$this->current_language = 'en';

		// 1. Post item: ES post 101 -> EN post 102
		$post_group = $this->group_repo->create_group( 'post', 'page', 101, 'es' );
		$this->group_repo->add_translation( $post_group->get_id(), 102, 'en' );
		$GLOBALS['wp_test_permalinks'][102] = 'http://example.com/en/about-us/';

		// 2. Term item: ES category 201 -> EN category 202
		$term_group = $this->group_repo->create_group( 'term', 'category', 201, 'es' );
		$this->group_repo->add_translation( $term_group->get_id(), 202, 'en' );
		$GLOBALS['wp_test_term_links'][202] = 'http://example.com/en/category/tours/';

		// Create menu items
		$item_post = $this->create_menu_item( 1, 'About', 'post_type', 'page', 101, 'http://example.com/nosotros/' );
		$item_term = $this->create_menu_item( 2, 'Tours', 'taxonomy', 'category', 201, 'http://example.com/categoria/tours/' );
		$item_cust = $this->create_menu_item( 3, 'Contact', 'custom', '', 0, 'http://example.com/contacto/?source=menu#form' );
		$item_ext  = $this->create_menu_item( 4, 'External', 'custom', '', 0, 'https://partner-portal.org/login' );

		$items = array( $item_post, $item_term, $item_cust, $item_ext );

		$processed = $this->filter->filter_wp_nav_menu_objects( $items, new stdClass() );

		// Verify Post item translated
		$this->assertSame( '102', $processed[0]->object_id );
		$this->assertSame( 'http://example.com/en/about-us/', $processed[0]->url );

		// Verify Term item translated
		$this->assertSame( '202', $processed[1]->object_id );
		$this->assertSame( 'http://example.com/en/category/tours/', $processed[1]->url );

		// Verify Internal Custom URL localized with query and fragment preserved, no double prefix
		$this->assertSame( 'http://example.com/en/contacto/?source=menu#form', $processed[2]->url );

		// Verify External URL untouched
		$this->assertSame( 'https://partner-portal.org/login', $processed[3]->url );
	}

	/**
	 * Tests wp_nav_menu_objects does not double prefix already localized URLs.
	 */
	public function test_filter_wp_nav_menu_objects_avoids_double_prefix(): void {
		$this->current_language = 'en';

		$item      = $this->create_menu_item( 5, 'Already EN', 'custom', '', 0, 'http://example.com/en/tours/' );
		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item ), new stdClass() );

		$this->assertSame( 'http://example.com/en/tours/', $processed[0]->url );
	}

	/**
	 * Tests wp_nav_menu_objects leaves unmanaged post items untouched.
	 */
	public function test_filter_wp_nav_menu_objects_leaves_unmanaged_post_untouched(): void {
		$this->current_language = 'en';

		$item      = $this->create_menu_item( 6, 'Unmanaged Page', 'post_type', 'page', 999, 'http://example.com/unmanaged/' );
		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item ), new stdClass() );

		$this->assertSame( '999', $processed[0]->object_id );
		$this->assertSame( 'http://example.com/unmanaged/', $processed[0]->url );
	}
}
