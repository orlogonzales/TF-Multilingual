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
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Query\QueryLanguageFilter;
use TF\Multilingual\Query\TermQueryLanguageFilter;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;
use WP_Query;
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
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

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

		$this->settings_repo     = new SettingsRepository();
		$this->lang_registry     = new LanguageRegistry( $this->settings_repo );
		$this->retained_registry = new RetainedTranslationRegistry( $this->wpdb );

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
			$this->url_generator,
			$this->retained_registry
		);
	}

	/**
	 * Helper to register a post stub.
	 *
	 * @param int    $id     Post ID.
	 * @param string $status Post status.
	 * @param string $type   Post type.
	 * @return WP_Post
	 */
	private function create_post( int $id, string $status = 'publish', string $type = 'post' ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_status = $status;
		$post->post_type   = $type;
		$post->post_title  = 'Post ' . $id;

		$GLOBALS['wp_test_posts'][ $id ] = $post;
		return $post;
	}

	/**
	 * Tests query args for posts and taxonomies use the exact suppression constants
	 * from QueryLanguageFilter and TermQueryLanguageFilter (DT-TFML-066).
	 */
	public function test_query_args_suppression_uses_canonical_constants(): void {
		$post_args = $this->filter->filter_posts_query_args( array( 'post_type' => 'post' ), 'post' );
		$this->assertArrayHasKey( QueryLanguageFilter::QUERY_VAR_SUPPRESS, $post_args );
		$this->assertTrue( $post_args[ QueryLanguageFilter::QUERY_VAR_SUPPRESS ] );
		$this->assertSame( 'tfml_suppress_language_filter', QueryLanguageFilter::QUERY_VAR_SUPPRESS );

		$tax_args = $this->filter->filter_taxonomies_query_args( array( 'taxonomy' => 'category' ), 'category' );
		$this->assertArrayHasKey( TermQueryLanguageFilter::QUERY_VAR_SUPPRESS, $tax_args );
		$this->assertTrue( $tax_args[ TermQueryLanguageFilter::QUERY_VAR_SUPPRESS ] );
		$this->assertSame( 'tfml_suppress_language_filter', TermQueryLanguageFilter::QUERY_VAR_SUPPRESS );
	}

	/**
	 * Tests post sitemap entry localizes loc URL to post language and includes both ES and EN front pages.
	 */
	public function test_posts_entry_localization_and_bilingual_pages_inclusion(): void {
		$GLOBALS['wp_test_options']['show_on_front'] = 'page';
		$GLOBALS['wp_test_options']['page_on_front'] = 197;

		$page_es = $this->create_post( 197, 'publish', 'page' );
		$page_en = $this->create_post( 8356, 'publish', 'page' );

		$group = $this->group_repo->create_group( 'post', 'page', 197, 'es' );
		$this->group_repo->add_translation( (int) $group->get_id(), 8356, 'en' );

		$entry_es = $this->filter->filter_posts_entry( array( 'loc' => 'http://example.com/' ), $page_es, 'page' );
		$this->assertSame( 'http://example.com/', $entry_es['loc'] );

		$entry_en = $this->filter->filter_posts_entry( array( 'loc' => 'http://example.com/home/' ), $page_en, 'page' );
		$this->assertSame( 'http://example.com/en/', $entry_en['loc'] );

		// Verify no duplicates when building a list of unique URLs.
		$urls = array( $entry_es['loc'], $entry_en['loc'] );
		$this->assertCount( 2, array_unique( $urls ) );
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
	 * Tests inactive language post and retained translation (e.g. French 8839) are excluded from query args and entry.
	 */
	public function test_inactive_and_retained_language_post_is_dropped(): void {
		$post_fr = $this->create_post( 301, 'publish' );
		$this->group_repo->create_group( 'post', 'post', 301, 'fr' );
		$this->lang_registry->deactivate( 'fr' );

		$entry = $this->filter->filter_posts_entry( array( 'loc' => 'http://example.com/bonjour/' ), $post_fr, 'post' );
		$this->assertEmpty( $entry );

		// Retained translation (e.g. French front page 8839 not in tfml_group_elements, stored in RetainedTranslationRegistry).
		$post_retained_fr = $this->create_post( 8839, 'publish', 'page' );
		$this->retained_registry->register( 'wpml', 197, 587, 'post', 'page', array( 'fr' => 8839 ) );

		$query_args = $this->filter->filter_posts_query_args( array( 'post_type' => 'page' ), 'page' );
		$this->assertArrayHasKey( 'post__not_in', $query_args );
		$this->assertContains( 8839, $query_args['post__not_in'] );

		$retained_entry = $this->filter->filter_posts_entry( array( 'loc' => 'http://example.com/accueil/' ), $post_retained_fr, 'page' );
		$this->assertEmpty( $retained_entry );
	}

	/**
	 * Tests sitemap suppression does not disable linguistic filtering on normal public queries.
	 */
	public function test_sitemap_suppression_does_not_leak_to_normal_public_queries(): void {
		$url_resolver  = new UrlLanguageResolver( $this->lang_registry, 'http://example.com' );
		$curr_resolver = new CurrentLanguageResolver( $url_resolver );
		$query_filter  = new QueryLanguageFilter( $this->lang_registry, $curr_resolver, $this->wpdb );

		// Sitemap query has QueryLanguageFilter::QUERY_VAR_SUPPRESS = true -> should NOT be filtered.
		$sitemap_args  = $this->filter->filter_posts_query_args( array( 'post_type' => 'page' ), 'page' );
		$sitemap_query = new WP_Query( $sitemap_args );
		$this->assertFalse( $query_filter->is_query_eligible( $sitemap_query ) );

		// Normal public main query does NOT have suppression var -> MUST still be filtered.
		$public_query                = new WP_Query( array( 'post_type' => 'page' ) );
		$public_query->is_main_query = true;
		$this->assertTrue( $query_filter->is_query_eligible( $public_query ) );
	}

	/**
	 * Tests taxonomy term sitemap entry localization and inactive/retained language exclusion.
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

		// Retained term.
		$term_retained                = new WP_Term();
		$term_retained->term_id       = 99;
		$GLOBALS['wp_test_terms'][99] = $term_retained;
		$this->retained_registry->register( 'wpml', 500, 600, 'term', 'category', array( 'fr' => 99 ) );

		$tax_args = $this->filter->filter_taxonomies_query_args( array( 'taxonomy' => 'category' ), 'category' );
		$this->assertArrayHasKey( 'exclude', $tax_args );
		$this->assertContains( 99, $tax_args['exclude'] );

		$entry_retained = $this->filter->filter_taxonomies_entry(
			array( 'loc' => 'http://example.com/category/retenu/' ),
			$term_retained,
			'category'
		);
		$this->assertEmpty( $entry_retained );
	}
}
