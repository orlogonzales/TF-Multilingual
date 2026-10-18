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
use TF\Multilingual\Migration\RetainedTranslationRegistry;
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
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

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
		$GLOBALS['wp_test_post_status']          = array();
		$GLOBALS['wp_test_permalinks']           = array();
		$GLOBALS['wp_test_term_links']           = array();

		$this->settings_repo = new SettingsRepository();
		$this->lang_registry = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->lang_registry->add_language( $es, true );
		$this->lang_registry->add_language( $en );

		$this->group_repo        = new TranslationGroupRepository( $this->wpdb, $this->lang_registry );
		$this->resolver          = new ContentTranslationResolver( $this->group_repo, $this->lang_registry );
		$this->retained_registry = new RetainedTranslationRegistry( $this->wpdb );
		$this->location_repo     = new NavMenuLocationRepository(
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
			$this->url_generator,
			$this->retained_registry
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
			$GLOBALS['wp_test_post_status'],
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
	 * Tests wp_nav_menu_objects localizes post, term, and custom URLs in secondary language
	 * when WordPress Core get_permalink() and get_term_link() return unprefixed native URLs.
	 */
	public function test_filter_wp_nav_menu_objects_localizes_items(): void {
		$this->current_language = 'en';

		// 1. Post item: ES post 101 -> EN post 102 (WordPress get_permalink returns unprefixed URL)
		$post_group = $this->group_repo->create_group( 'post', 'page', 101, 'es' );
		$this->group_repo->add_translation( $post_group->get_id(), 102, 'en' );
		$GLOBALS['wp_test_permalinks'][102] = 'http://example.com/about-us/';

		// 2. Term item: ES category 201 -> EN category 202 (WordPress get_term_link returns unprefixed URL)
		$term_group = $this->group_repo->create_group( 'term', 'category', 201, 'es' );
		$this->group_repo->add_translation( $term_group->get_id(), 202, 'en' );
		$GLOBALS['wp_test_term_links'][202] = 'http://example.com/category/tours/';

		// Create menu items
		$item_post = $this->create_menu_item( 1, 'About', 'post_type', 'page', 101, 'http://example.com/nosotros/' );
		$item_term = $this->create_menu_item( 2, 'Tours', 'taxonomy', 'category', 201, 'http://example.com/categoria/tours/' );
		$item_cust = $this->create_menu_item( 3, 'Contact', 'custom', '', 0, 'http://example.com/contacto/?source=menu#form' );
		$item_ext  = $this->create_menu_item( 4, 'External', 'custom', '', 0, 'https://partner-portal.org/login' );

		$items = array( $item_post, $item_term, $item_cust, $item_ext );

		$processed = $this->filter->filter_wp_nav_menu_objects( $items, new stdClass() );

		// Verify Post item translated and prefixed with /en/
		$this->assertSame( '102', $processed[0]->object_id );
		$this->assertSame( 'http://example.com/en/about-us/', $processed[0]->url );

		// Verify Term item translated and prefixed with /en/
		$this->assertSame( '202', $processed[1]->object_id );
		$this->assertSame( 'http://example.com/en/category/tours/', $processed[1]->url );

		// Verify Internal Custom URL localized with query and fragment preserved, no double prefix
		$this->assertSame( 'http://example.com/en/contacto/?source=menu#form', $processed[2]->url );

		// Verify External URL untouched
		$this->assertSame( 'https://partner-portal.org/login', $processed[3]->url );
	}

	/**
	 * Tests Case 2 & Case 11: post_type and taxonomy items pointing directly to English IDs
	 * registered as 'en' in TFML receive the /en/ prefix in 'en' context.
	 */
	public function test_direct_english_post_and_term_menu_items_are_prefixed_in_english_context(): void {
		$this->current_language = 'en';

		$post_group = $this->group_repo->create_group( 'post', 'page', 101, 'es' );
		$this->group_repo->add_translation( $post_group->get_id(), 102, 'en' );
		$GLOBALS['wp_test_permalinks'][102] = 'http://example.com/about-us/';

		$term_group = $this->group_repo->create_group( 'term', 'category', 201, 'es' );
		$this->group_repo->add_translation( $term_group->get_id(), 202, 'en' );
		$GLOBALS['wp_test_term_links'][202] = 'http://example.com/category/tours/';

		// Menu items in English menu pointing directly to English IDs 102 and 202
		$item_post = $this->create_menu_item( 10, 'About Us', 'post_type', 'page', 102, 'http://example.com/about-us/' );
		$item_term = $this->create_menu_item( 11, 'Tours', 'taxonomy', 'category', 202, 'http://example.com/category/tours/' );

		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item_post, $item_term ), new stdClass() );

		$this->assertSame( '102', $processed[0]->object_id );
		$this->assertSame( 'http://example.com/en/about-us/', $processed[0]->url );
		$this->assertSame( '202', $processed[1]->object_id );
		$this->assertSame( 'http://example.com/en/category/tours/', $processed[1]->url );
	}

	/**
	 * Tests Cases 3, 4 & 5: Static front page normalization in both 'en' and 'es' contexts
	 * whether menu item points to ES front page (197) or EN front page (8356).
	 */
	public function test_static_front_page_normalization_in_es_and_en_contexts(): void {
		$GLOBALS['wp_test_options']['show_on_front'] = 'page';
		$GLOBALS['wp_test_options']['page_on_front'] = 197;

		$front_group = $this->group_repo->create_group( 'post', 'page', 197, 'es' );
		$this->group_repo->add_translation( $front_group->get_id(), 8356, 'en' );
		$GLOBALS['wp_test_permalinks'][197]  = 'http://example.com/inicio/';
		$GLOBALS['wp_test_permalinks'][8356] = 'http://example.com/home/';

		// Case 3 & 4: In 'en' context, item pointing to 197 or 8356 normalizes to http://example.com/en/
		$this->current_language = 'en';
		$item_es_front          = $this->create_menu_item( 20, 'Home (from 197)', 'post_type', 'page', 197, 'http://example.com/inicio/' );
		$item_en_front          = $this->create_menu_item( 21, 'Home (from 8356)', 'post_type', 'page', 8356, 'http://example.com/home/' );

		$processed_en = $this->filter->filter_wp_nav_menu_objects( array( $item_es_front, $item_en_front ), new stdClass() );
		$this->assertSame( '8356', $processed_en[0]->object_id );
		$this->assertSame( 'http://example.com/en/', $processed_en[0]->url );
		$this->assertSame( '8356', $processed_en[1]->object_id );
		$this->assertSame( 'http://example.com/en/', $processed_en[1]->url );

		// Case 5: In 'es' context, item pointing to 197 normalizes to http://example.com/
		$this->current_language = 'es';
		$item_es_in_es          = $this->create_menu_item( 22, 'Inicio', 'post_type', 'page', 197, 'http://example.com/inicio/' );
		$processed_es           = $this->filter->filter_wp_nav_menu_objects( array( $item_es_in_es ), new stdClass() );
		$this->assertSame( '197', $processed_es[0]->object_id );
		$this->assertSame( 'http://example.com/', $processed_es[0]->url );
	}

	/**
	 * Tests Case 6: Hierarchical child page permalink is localized with /en/ and preserves parent slug.
	 */
	public function test_hierarchical_page_permalink_is_localized(): void {
		$this->current_language = 'en';

		$child_group = $this->group_repo->create_group( 'post', 'page', 424, 'es' );
		$this->group_repo->add_translation( $child_group->get_id(), 8352, 'en' );
		$GLOBALS['wp_test_permalinks'][8352] = 'http://example.com/about-us/who-are-we/';

		$item      = $this->create_menu_item( 30, 'Who Are We?', 'post_type', 'page', 8352, 'http://example.com/about-us/who-are-we/' );
		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item ), new stdClass() );

		$this->assertSame( '8352', $processed[0]->object_id );
		$this->assertSame( 'http://example.com/en/about-us/who-are-we/', $processed[0]->url );
	}

	/**
	 * Tests wp_nav_menu_objects does not double prefix already localized URLs (Case 13).
	 */
	public function test_filter_wp_nav_menu_objects_avoids_double_prefix(): void {
		$this->current_language = 'en';

		$item      = $this->create_menu_item( 5, 'Already EN', 'custom', '', 0, 'http://example.com/en/tours/' );
		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item ), new stdClass() );

		$this->assertSame( 'http://example.com/en/tours/', $processed[0]->url );
	}

	/**
	 * Tests Case 7: wp_nav_menu_objects leaves unmigrated post items untouched (never invents /en/... 404 URLs).
	 */
	public function test_filter_wp_nav_menu_objects_leaves_unmanaged_post_untouched(): void {
		$this->current_language = 'en';

		$item      = $this->create_menu_item( 6, 'Activities', 'post_type', 'page', 8339, 'http://example.com/activities/' );
		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item ), new stdClass() );

		$this->assertSame( '8339', $processed[0]->object_id );
		$this->assertSame( 'http://example.com/activities/', $processed[0]->url );
	}

	/**
	 * Tests Case 8: Post translation in 'draft' or 'trash' status is never exposed in menu links.
	 */
	public function test_non_published_post_translations_are_not_exposed(): void {
		$this->current_language = 'en';

		$group_draft = $this->group_repo->create_group( 'post', 'page', 301, 'es' );
		$this->group_repo->add_translation( $group_draft->get_id(), 302, 'en' );
		$GLOBALS['wp_test_post_status'][302] = 'draft';
		$GLOBALS['wp_test_permalinks'][302]  = 'http://example.com/draft-page/';

		$group_trash = $this->group_repo->create_group( 'post', 'page', 303, 'es' );
		$this->group_repo->add_translation( $group_trash->get_id(), 304, 'en' );
		$GLOBALS['wp_test_post_status'][304] = 'trash';
		$GLOBALS['wp_test_permalinks'][304]  = 'http://example.com/trashed-page/';

		$item_draft = $this->create_menu_item( 40, 'Draft Target', 'post_type', 'page', 301, 'http://example.com/borrador/' );
		$item_trash = $this->create_menu_item( 41, 'Trash Target', 'post_type', 'page', 303, 'http://example.com/papelera/' );

		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item_draft, $item_trash ), new stdClass() );

		$this->assertSame( '301', $processed[0]->object_id );
		$this->assertSame( 'http://example.com/borrador/', $processed[0]->url );
		$this->assertSame( '303', $processed[1]->object_id );
		$this->assertSame( 'http://example.com/papelera/', $processed[1]->url );
	}

	/**
	 * Tests Case 9: Translation registered in RetainedTranslationRegistry is never exposed.
	 */
	public function test_retained_translation_elements_are_never_exposed(): void {
		$this->current_language = 'en';

		$group = $this->group_repo->create_group( 'post', 'page', 197, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 8839, 'en' );
		$GLOBALS['wp_test_permalinks'][8839] = 'http://example.com/accueil/';

		$this->retained_registry->register(
			'wpml',
			197,
			$group->get_id(),
			'post',
			'page',
			array( 'fr' => 8839 ),
			'pilot-trid-197-partial'
		);

		$item      = $this->create_menu_item( 50, 'Retained Target', 'post_type', 'page', 197, 'http://example.com/inicio/' );
		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item ), new stdClass() );

		$this->assertSame( '197', $processed[0]->object_id );
		$this->assertSame( 'http://example.com/inicio/', $processed[0]->url );
	}

	/**
	 * Tests Case 15: Menu item in Spanish context pointing to a translated post keeps unprefixed Spanish URL (never /es/).
	 */
	public function test_spanish_menu_item_keeps_unprefixed_spanish_url(): void {
		$this->current_language = 'es';

		$group = $this->group_repo->create_group( 'post', 'page', 101, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 102, 'en' );
		$GLOBALS['wp_test_permalinks'][101] = 'http://example.com/nosotros/';
		$GLOBALS['wp_test_permalinks'][102] = 'http://example.com/about-us/';

		$item_es   = $this->create_menu_item( 60, 'Nosotros', 'post_type', 'page', 101, 'http://example.com/nosotros/' );
		$item_x    = $this->create_menu_item( 61, 'Cross EN->ES', 'post_type', 'page', 102, 'http://example.com/about-us/' );
		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item_es, $item_x ), new stdClass() );

		$this->assertSame( '101', $processed[0]->object_id );
		$this->assertSame( 'http://example.com/nosotros/', $processed[0]->url );
		$this->assertStringNotContainsString( '/es/', $processed[0]->url );

		$this->assertSame( '101', $processed[1]->object_id );
		$this->assertSame( 'http://example.com/nosotros/', $processed[1]->url );
		$this->assertStringNotContainsString( '/es/', $processed[1]->url );
	}

	/**
	 * Tests Case 16: Query string and fragment on post_type and taxonomy menu items are preserved.
	 */
	public function test_query_string_and_fragment_are_preserved_on_post_and_taxonomy_items(): void {
		$this->current_language = 'en';

		$post_group = $this->group_repo->create_group( 'post', 'page', 101, 'es' );
		$this->group_repo->add_translation( $post_group->get_id(), 102, 'en' );
		$GLOBALS['wp_test_permalinks'][102] = 'http://example.com/about-us/';

		$term_group = $this->group_repo->create_group( 'term', 'category', 201, 'es' );
		$this->group_repo->add_translation( $term_group->get_id(), 202, 'en' );
		$GLOBALS['wp_test_term_links'][202] = 'http://example.com/category/tours/';

		$item_post = $this->create_menu_item( 70, 'About Team', 'post_type', 'page', 101, 'http://example.com/nosotros/?ref=nav#team' );
		$item_term = $this->create_menu_item( 71, 'Tours Top', 'taxonomy', 'category', 201, 'http://example.com/categoria/tours/?view=grid#featured' );

		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item_post, $item_term ), new stdClass() );

		$this->assertSame( 'http://example.com/en/about-us/?ref=nav#team', $processed[0]->url );
		$this->assertSame( 'http://example.com/en/category/tours/?view=grid#featured', $processed[1]->url );
	}

	/**
	 * Tests Case 17: Administrative and system links (/wp-admin/, /wp-login.php, /wp-json/) are never prefixed.
	 */
	public function test_administrative_and_system_links_are_never_prefixed(): void {
		$this->current_language = 'en';

		$item_admin = $this->create_menu_item( 80, 'Admin', 'custom', '', 0, 'http://example.com/wp-admin/edit.php' );
		$item_login = $this->create_menu_item( 81, 'Login', 'custom', '', 0, 'http://example.com/wp-login.php?redirect_to=home' );
		$item_rest  = $this->create_menu_item( 82, 'REST', 'custom', '', 0, 'http://example.com/wp-json/tfml/v1/languages' );
		$item_rel   = $this->create_menu_item( 83, 'Rel Admin', 'custom', '', 0, '/wp-admin/' );

		$processed = $this->filter->filter_wp_nav_menu_objects(
			array( $item_admin, $item_login, $item_rest, $item_rel ),
			new stdClass()
		);

		$this->assertSame( 'http://example.com/wp-admin/edit.php', $processed[0]->url );
		$this->assertSame( 'http://example.com/wp-login.php?redirect_to=home', $processed[1]->url );
		$this->assertSame( 'http://example.com/wp-json/tfml/v1/languages', $processed[2]->url );
		$this->assertSame( '/wp-admin/', $processed[3]->url );
	}

	/**
	 * Tests Case 18: Inactive language ('fr') does not swap menu locations, menu args, or prefix menu items with /fr/.
	 */
	public function test_inactive_language_fr_does_not_activate_menus_or_prefixes(): void {
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', false, 30 );
		$this->lang_registry->add_language( $fr );
		$this->current_language = 'fr';

		$this->location_repo->set_menu_for_location( 'primary', 'en', 98 );
		$locations = array( 'primary' => 43 );
		$this->assertSame( array( 'primary' => 43 ), $this->filter->filter_nav_menu_locations( $locations ) );

		$args = array(
			'menu'           => 43,
			'theme_location' => '',
		);
		$this->assertSame( 43, $this->filter->filter_wp_nav_menu_args( $args )['menu'] );

		$item_post = $this->create_menu_item( 90, 'Nosotros', 'post_type', 'page', 101, 'http://example.com/nosotros/' );
		$item_cust = $this->create_menu_item( 91, 'Contacto', 'custom', '', 0, 'http://example.com/contacto/' );
		$processed = $this->filter->filter_wp_nav_menu_objects( array( $item_post, $item_cust ), new stdClass() );

		$this->assertSame( 'http://example.com/nosotros/', $processed[0]->url );
		$this->assertSame( 'http://example.com/contacto/', $processed[1]->url );
		$this->assertStringNotContainsString( '/fr/', $processed[0]->url );
		$this->assertStringNotContainsString( '/fr/', $processed[1]->url );
	}
}
