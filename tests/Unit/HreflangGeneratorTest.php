<?php
/**
 * Hreflang Generator Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Seo\HreflangGenerator;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;
use WP_Term;

/**
 * Class HreflangGeneratorTest
 */
class HreflangGeneratorTest extends TestCase {

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
	 * Generator under test.
	 *
	 * @var HreflangGenerator
	 */
	private HreflangGenerator $generator;

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
		$GLOBALS['wp_test_permalinks']        = array();
		$GLOBALS['wp_test_terms']             = array();
		$GLOBALS['wp_test_term_links']        = array();
		$GLOBALS['wp_test_is_singular']       = false;
		$GLOBALS['wp_test_is_category']       = false;
		$GLOBALS['wp_test_is_tag']            = false;
		$GLOBALS['wp_test_is_tax']            = false;
		$GLOBALS['wp_test_is_front_page']     = false;
		$GLOBALS['wp_test_is_home']           = false;
		$GLOBALS['wp_test_is_feed']           = false;
		$GLOBALS['wp_test_is_trackback']      = false;
		$GLOBALS['wp_test_is_404']            = false;
		$GLOBALS['wp_test_is_search']         = false;
		$GLOBALS['wp_test_is_preview']        = false;
		$GLOBALS['wp_test_queried_object']    = null;
		$GLOBALS['wp_test_queried_object_id'] = 0;

		$this->settings_repo = new SettingsRepository();
		$this->lang_registry = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$pt = Language::create( 'pt', 'pt_PT', 'Português', 'Português', true, 30 );
		$fr = Language::create( 'fr', 'fr_FR', 'Français', 'Français', true, 40 );

		$this->lang_registry->add_language( $es, true );
		$this->lang_registry->add_language( $en );
		$this->lang_registry->add_language( $pt );
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

		$this->generator = new HreflangGenerator(
			$this->lang_registry,
			$this->group_repo,
			$this->content_resolver,
			$this->url_generator
		);
	}

	/**
	 * Helper to register a post stub.
	 *
	 * @param int    $id     Post ID.
	 * @param string $status Post status.
	 * @param string $url    Permalink URL.
	 * @return WP_Post
	 */
	private function create_post( int $id, string $status = 'publish', string $url = '' ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_status = $status;
		$post->post_title  = 'Post ' . $id;

		$GLOBALS['wp_test_posts'][ $id ]      = $post;
		$GLOBALS['wp_test_permalinks'][ $id ] = '' !== $url ? $url : ( 'http://example.com/post-' . $id . '/' );

		return $post;
	}

	/**
	 * Tests published post with default ES and secondary EN produces es, en, and x-default.
	 */
	public function test_post_with_es_and_en_produces_variants_and_x_default(): void {
		$post_es = $this->create_post( 101, 'publish', 'http://example.com/hola/' );
		$post_en = $this->create_post( 102, 'publish', 'http://example.com/hello/' );

		$group = $this->group_repo->create_group( 'post', 'post', 101, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 102, 'en' );

		$variants = $this->generator->get_post_hreflang_variants( 101 );

		$this->assertArrayHasKey( 'es', $variants );
		$this->assertArrayHasKey( 'en', $variants );
		$this->assertArrayHasKey( 'x-default', $variants );

		$this->assertSame( 'http://example.com/hola/', $variants['es'] );
		$this->assertSame( 'http://example.com/en/hello/', $variants['en'] );
		$this->assertSame( 'http://example.com/hola/', $variants['x-default'] );
	}

	/**
	 * Tests draft secondary translation is excluded from hreflang.
	 */
	public function test_draft_translation_is_excluded(): void {
		$this->create_post( 101, 'publish', 'http://example.com/hola/' );
		$this->create_post( 102, 'draft', 'http://example.com/draft-en/' );

		$group = $this->group_repo->create_group( 'post', 'post', 101, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 102, 'en' );

		$variants = $this->generator->get_post_hreflang_variants( 101 );

		$this->assertArrayHasKey( 'es', $variants );
		$this->assertArrayNotHasKey( 'en', $variants );
		$this->assertSame( 'http://example.com/hola/', $variants['x-default'] );
	}

	/**
	 * Tests x-default is STRICTLY absent when default language translation does not exist.
	 */
	public function test_x_default_is_strictly_absent_when_default_language_missing(): void {
		$this->create_post( 201, 'publish', 'http://example.com/en/welcome/' );
		$this->create_post( 202, 'publish', 'http://example.com/pt/bem-vindo/' );

		// Group contains only EN and PT, NO ES (default).
		$group = $this->group_repo->create_group( 'post', 'post', 201, 'en' );
		$this->group_repo->add_translation( $group->get_id(), 202, 'pt' );

		$variants = $this->generator->get_post_hreflang_variants( 201 );

		$this->assertArrayHasKey( 'en', $variants );
		$this->assertArrayHasKey( 'pt', $variants );
		$this->assertArrayNotHasKey( 'x-default', $variants );
	}

	/**
	 * Tests published post in REVIEW status is included in hreflang.
	 */
	public function test_published_post_in_review_status_is_included(): void {
		$this->create_post( 301, 'publish', 'http://example.com/guia/' );
		$this->create_post( 302, 'publish', 'http://example.com/guide/' );

		$group = $this->group_repo->create_group( 'post', 'post', 301, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 302, 'en' );

		$variants = $this->generator->get_post_hreflang_variants( 301 );

		$this->assertArrayHasKey( 'es', $variants );
		$this->assertArrayHasKey( 'en', $variants );
		$this->assertSame( 'http://example.com/en/guide/', $variants['en'] );
	}

	/**
	 * Tests inactive language translation is excluded from hreflang.
	 */
	public function test_inactive_language_translation_is_excluded(): void {
		$this->create_post( 401, 'publish', 'http://example.com/hola/' );
		$this->create_post( 402, 'publish', 'http://example.com/bonjour/' );

		$group = $this->group_repo->create_group( 'post', 'post', 401, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 402, 'fr' );

		// Deactivate fr after translation was formed.
		$this->lang_registry->deactivate( 'fr' );

		$variants = $this->generator->get_post_hreflang_variants( 401 );

		$this->assertArrayHasKey( 'es', $variants );
		$this->assertArrayNotHasKey( 'fr', $variants );
	}

	/**
	 * Tests single unassigned post produces its own language and x-default if default.
	 */
	public function test_unassigned_post_produces_only_self(): void {
		$this->create_post( 501, 'publish', 'http://example.com/solo/' );

		$variants = $this->generator->get_post_hreflang_variants( 501 );

		$this->assertCount( 2, $variants );
		$this->assertSame( 'http://example.com/solo/', $variants['es'] );
		$this->assertSame( 'http://example.com/solo/', $variants['x-default'] );
	}

	/**
	 * Tests taxonomy term hreflang variants.
	 */
	public function test_taxonomy_term_hreflang_variants(): void {
		$term_es          = new WP_Term();
		$term_es->term_id = 11;
		$term_es->slug    = 'aventura';
		$term_es->name    = 'Aventura';

		$term_en          = new WP_Term();
		$term_en->term_id = 12;
		$term_en->slug    = 'adventure';
		$term_en->name    = 'Adventure';

		$GLOBALS['wp_test_terms'][11]      = $term_es;
		$GLOBALS['wp_test_terms'][12]      = $term_en;
		$GLOBALS['wp_test_term_links'][11] = 'http://example.com/category/aventura/';
		$GLOBALS['wp_test_term_links'][12] = 'http://example.com/category/adventure/';

		$group = $this->group_repo->create_group( 'term', 'category', 11, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 12, 'en' );

		$variants = $this->generator->get_term_hreflang_variants( 11, 'category' );

		$this->assertArrayHasKey( 'es', $variants );
		$this->assertArrayHasKey( 'en', $variants );
		$this->assertArrayHasKey( 'x-default', $variants );
		$this->assertSame( 'http://example.com/category/aventura/', $variants['es'] );
		$this->assertSame( 'http://example.com/en/category/adventure/', $variants['en'] );
		$this->assertSame( 'http://example.com/category/aventura/', $variants['x-default'] );
	}

	/**
	 * Tests front page hreflang variants generation.
	 */
	public function test_front_page_hreflang_variants(): void {
		$this->lang_registry->deactivate( 'fr' );

		$variants = $this->generator->get_front_page_hreflang_variants();

		$this->assertArrayHasKey( 'es', $variants );
		$this->assertArrayHasKey( 'en', $variants );
		$this->assertArrayHasKey( 'pt', $variants );
		$this->assertArrayNotHasKey( 'fr', $variants ); // fr is inactive.
		$this->assertArrayHasKey( 'x-default', $variants );
		$this->assertSame( 'http://example.com/', $variants['es'] );
		$this->assertSame( 'http://example.com/en/', $variants['en'] );
		$this->assertSame( 'http://example.com/pt/', $variants['pt'] );
		$this->assertSame( 'http://example.com/', $variants['x-default'] );
	}

	/**
	 * Tests ineligible contexts return empty variants.
	 */
	public function test_ineligible_contexts_return_empty(): void {
		$GLOBALS['wp_test_is_404'] = true;
		$this->assertFalse( $this->generator->is_eligible_context() );
		$this->assertEmpty( $this->generator->get_hreflang_variants() );

		$GLOBALS['wp_test_is_404']    = false;
		$GLOBALS['wp_test_is_search'] = true;
		$this->assertFalse( $this->generator->is_eligible_context() );
		$this->assertEmpty( $this->generator->get_hreflang_variants() );

		$GLOBALS['wp_test_is_search'] = false;
		$GLOBALS['wp_test_is_feed']   = true;
		$this->assertFalse( $this->generator->is_eligible_context() );
		$this->assertEmpty( $this->generator->get_hreflang_variants() );
	}
}
