<?php
/**
 * Core Sitemaps Filter Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Seo\CoreSitemapsFilter;
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
 * Class CoreSitemapsFilterTest
 */
class CoreSitemapsFilterTest extends TestCase {

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
	 * Filter under test.
	 *
	 * @var CoreSitemapsFilter
	 */
	private CoreSitemapsFilter $filter;

	/**
	 * Sets up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = new TestableWpdb();
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		$GLOBALS['wp_test_options'] = array();
		$GLOBALS['wp_test_posts']   = array();
		$GLOBALS['wp_test_terms']   = array();

		$this->settings_repo = new SettingsRepository();
		$this->lang_registry = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$fr = Language::create( 'fr', 'fr_FR', 'Français', 'Français', true, 30 );

		$this->lang_registry->add_language( $es, true );
		$this->lang_registry->add_language( $en );
		$this->lang_registry->add_language( $fr );

		$this->group_repo       = new TranslationGroupRepository( $this->wpdb, $this->lang_registry );
		$this->content_resolver = new ContentTranslationResolver( $this->group_repo, $this->lang_registry );
		$url_resolver           = new UrlLanguageResolver( $this->lang_registry, 'http://example.com' );
		$this->url_generator    = new LocalizedUrlGenerator(
			$this->lang_registry,
			$url_resolver,
			$this->content_resolver,
			'http://example.com'
		);

		$this->filter = new CoreSitemapsFilter(
			$this->lang_registry,
			$this->content_resolver,
			$this->url_generator
		);
	}

	/**
	 * Helper to register a post stub.
	 *
	 * @param int    $id     Post ID.
	 * @param string $status Post status.
	 * @return WP_Post
	 */
	private function create_post( int $id, string $status = 'publish' ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_status = $status;
		$post->post_title  = 'Post ' . $id;

		$GLOBALS['wp_test_posts'][ $id ] = $post;
		return $post;
	}

	/**
	 * Tests query args for posts and taxonomies have tfml_suppress_filters set.
	 */
	public function test_query_args_suppression(): void {
		$post_args = $this->filter->filter_posts_query_args( array( 'post_type' => 'post' ), 'post' );
		$this->assertTrue( $post_args['tfml_suppress_filters'] );

		$tax_args = $this->filter->filter_taxonomies_query_args( array( 'taxonomy' => 'category' ), 'category' );
		$this->assertTrue( $tax_args['tfml_suppress_filters'] );
	}

	/**
	 * Tests post sitemap entry localizes loc URL to post language.
	 */
	public function test_posts_entry_localization(): void {
		$post_es = $this->create_post( 101, 'publish' );
		$post_en = $this->create_post( 102, 'publish' );

		$group = $this->group_repo->create_group( 'post', 'post', 101, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 102, 'en' );

		$entry_es = $this->filter->filter_posts_entry( array( 'loc' => 'http://example.com/hola/' ), $post_es, 'post' );
		$this->assertSame( 'http://example.com/hola/', $entry_es['loc'] );

		$entry_en = $this->filter->filter_posts_entry( array( 'loc' => 'http://example.com/hello/' ), $post_en, 'post' );
		$this->assertSame( 'http://example.com/en/hello/', $entry_en['loc'] );
	}

	/**
	 * Tests draft post is excluded (returns empty array).
	 */
	public function test_draft_post_entry_is_dropped(): void {
		$post_draft = $this->create_post( 201, 'draft' );

		$this->group_repo->create_group( 'post', 'post', 201, 'en' );

		$entry = $this->filter->filter_posts_entry( array( 'loc' => 'http://example.com/en/draft/' ), $post_draft, 'post' );
		$this->assertEmpty( $entry );
	}

	/**
	 * Tests inactive language post is excluded (returns empty array).
	 */
	public function test_inactive_language_post_is_dropped(): void {
		$post_fr = $this->create_post( 301, 'publish' );

		$this->group_repo->create_group( 'post', 'post', 301, 'fr' );
		$this->lang_registry->deactivate( 'fr' );

		$entry = $this->filter->filter_posts_entry( array( 'loc' => 'http://example.com/bonjour/' ), $post_fr, 'post' );
		$this->assertEmpty( $entry );
	}

	/**
	 * Tests taxonomy term sitemap entry localization and inactive language exclusion.
	 */
	public function test_taxonomies_entry_localization(): void {
		$term_en                      = new WP_Term();
		$term_en->term_id             = 15;
		$GLOBALS['wp_test_terms'][15] = $term_en;

		$this->group_repo->create_group( 'term', 'category', 15, 'en' );

		$entry_en = $this->filter->filter_taxonomies_entry(
			array( 'loc' => 'http://example.com/category/adventure/' ),
			$term_en,
			'category'
		);
		$this->assertSame( 'http://example.com/en/category/adventure/', $entry_en['loc'] );

		// Inactive language term.
		$term_fr                      = new WP_Term();
		$term_fr->term_id             = 16;
		$GLOBALS['wp_test_terms'][16] = $term_fr;

		$this->group_repo->create_group( 'term', 'category', 16, 'fr' );
		$this->lang_registry->deactivate( 'fr' );

		$entry_fr = $this->filter->filter_taxonomies_entry(
			array( 'loc' => 'http://example.com/category/voyage/' ),
			$term_fr,
			'category'
		);
		$this->assertEmpty( $entry_fr );
	}
}
