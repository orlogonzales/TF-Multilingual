<?php
/**
 * Canonical URL Manager Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Seo\CanonicalUrlManager;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;
use WP_Term;

/**
 * Class CanonicalUrlManagerTest
 */
class CanonicalUrlManagerTest extends TestCase {

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
	 * Content resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $content_resolver;

	/**
	 * URL generator.
	 *
	 * @var LocalizedUrlGenerator
	 */
	private LocalizedUrlGenerator $url_generator;

	/**
	 * URL language resolver.
	 *
	 * @var UrlLanguageResolver
	 */
	private UrlLanguageResolver $url_resolver;

	/**
	 * Manager under test.
	 *
	 * @var CanonicalUrlManager
	 */
	private CanonicalUrlManager $manager;

	/**
	 * Sets up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = new TestableWpdb();
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		$GLOBALS['wp_test_options']           = array();
		$GLOBALS['wp_test_posts']             = array();
		$GLOBALS['wp_test_terms']             = array();
		$GLOBALS['wp_test_is_singular']       = false;
		$GLOBALS['wp_test_is_category']       = false;
		$GLOBALS['wp_test_is_tag']            = false;
		$GLOBALS['wp_test_is_tax']            = false;
		$GLOBALS['wp_test_is_front_page']     = false;
		$GLOBALS['wp_test_is_home']           = false;
		$GLOBALS['wp_test_queried_object']    = null;
		$GLOBALS['wp_test_queried_object_id'] = 0;

		$this->settings_repo = new SettingsRepository();
		$this->lang_registry = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$pt = Language::create( 'pt', 'pt_PT', 'Português', 'Português', true, 30 );

		$this->lang_registry->add_language( $es, true );
		$this->lang_registry->add_language( $en );
		$this->lang_registry->add_language( $pt );

		$this->group_repo       = new TranslationGroupRepository( $this->wpdb, $this->lang_registry );
		$this->content_resolver = new ContentTranslationResolver( $this->group_repo, $this->lang_registry );
		$this->url_resolver     = new UrlLanguageResolver( $this->lang_registry, 'http://example.com' );
		$this->url_generator    = new LocalizedUrlGenerator(
			$this->lang_registry,
			$this->url_resolver,
			$this->content_resolver,
			'http://example.com'
		);

		$this->manager = new CanonicalUrlManager(
			$this->lang_registry,
			$this->content_resolver,
			$this->url_generator,
			$this->url_resolver
		);
	}

	/**
	 * Helper to register a post stub.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post
	 */
	private function create_post( int $id ): WP_Post {
		$post             = new WP_Post();
		$post->ID         = $id;
		$post->post_title = 'Post ' . $id;

		$GLOBALS['wp_test_posts'][ $id ] = $post;
		return $post;
	}

	/**
	 * Tests core wp_get_canonical_url localizes canonical per translation language.
	 */
	public function test_filter_canonical_url_with_post_object(): void {
		$post_es = $this->create_post( 101 );
		$post_en = $this->create_post( 102 );

		$group = $this->group_repo->create_group( 'post', 'post', 101, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 102, 'en' );

		// ES post: default language, stays unprefixed.
		$canonical_es = $this->manager->filter_canonical_url( 'http://example.com/hola/', $post_es );
		$this->assertSame( 'http://example.com/hola/', $canonical_es );

		// EN post: secondary language, receives /en/ prefix.
		$canonical_en = $this->manager->filter_canonical_url( 'http://example.com/hello/', $post_en );
		$this->assertSame( 'http://example.com/en/hello/', $canonical_en );

		// Passing post ID directly.
		$canonical_en_id = $this->manager->filter_canonical_url( 'http://example.com/hello/', 102 );
		$this->assertSame( 'http://example.com/en/hello/', $canonical_en_id );
	}

	/**
	 * Tests queried object resolution in singular context.
	 */
	public function test_filter_canonical_in_singular_queried_context(): void {
		$this->create_post( 201 );

		$this->group_repo->create_group( 'post', 'post', 201, 'pt' );

		$GLOBALS['wp_test_is_singular']       = true;
		$GLOBALS['wp_test_queried_object_id'] = 201;

		$canonical = $this->manager->filter_canonical_url( 'http://example.com/bem-vindo/', null );
		$this->assertSame( 'http://example.com/pt/bem-vindo/', $canonical );
	}

	/**
	 * Tests third party hooks (Yoast SEO, Rank Math).
	 */
	public function test_third_party_canonical_filters(): void {
		$this->create_post( 301 );

		$this->group_repo->create_group( 'post', 'post', 301, 'en' );

		$GLOBALS['wp_test_is_singular']       = true;
		$GLOBALS['wp_test_queried_object_id'] = 301;

		// Simulated Yoast filter.
		$yoast = $this->manager->filter_third_party_canonical( 'http://example.com/custom-yoast/' );
		$this->assertSame( 'http://example.com/en/custom-yoast/', $yoast );

		// False / empty canonical passes through unchanged.
		$this->assertFalse( $this->manager->filter_third_party_canonical( false ) );
		$this->assertSame( '', $this->manager->filter_third_party_canonical( '' ) );
	}

	/**
	 * Tests term canonical URL localization.
	 */
	public function test_term_canonical_url_localization(): void {
		$term          = new WP_Term();
		$term->term_id = 55;
		$term->slug    = 'adventure';

		$GLOBALS['wp_test_terms'][55]         = $term;
		$GLOBALS['wp_test_is_category']       = true;
		$GLOBALS['wp_test_queried_object']    = $term;
		$GLOBALS['wp_test_queried_object_id'] = 55;

		$this->group_repo->create_group( 'term', 'category', 55, 'en' );

		$canonical = $this->manager->filter_canonical_url( 'http://example.com/category/adventure/', null );
		$this->assertSame( 'http://example.com/en/category/adventure/', $canonical );
	}

	/**
	 * Tests unconfigured or empty state returns unchanged.
	 */
	public function test_unconfigured_registry_leaves_canonical_untouched(): void {
		$empty_registry = new LanguageRegistry( new SettingsRepository() );
		$manager        = new CanonicalUrlManager(
			$empty_registry,
			$this->content_resolver,
			$this->url_generator,
			$this->url_resolver
		);

		$raw = 'http://example.com/unconfigured/';
		$this->assertSame( $raw, $manager->filter_canonical_url( $raw, 101 ) );
	}
}
