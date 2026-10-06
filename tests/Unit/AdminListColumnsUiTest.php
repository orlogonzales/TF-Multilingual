<?php
/**
 * AdminListColumnsUi Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\AdminListColumnsUi;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;
use WP_Term;

/**
 * Class AdminListColumnsUiTest
 */
class AdminListColumnsUiTest extends TestCase {

	/**
	 * In-memory wpdb test double.
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
	 * Translation group repository.
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
	 * Admin list columns UI under test.
	 *
	 * @var AdminListColumnsUi
	 */
	private AdminListColumnsUi $columns_ui;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_options'] = array();
		$GLOBALS['wp_test_posts']   = array();
		$GLOBALS['wp_test_terms']   = array();
		$GLOBALS['wp_test_caps']    = array();

		$this->db = new TestableWpdb();
		$this->db->reset();

		$settings_repo           = new SettingsRepository();
		$this->language_registry = new LanguageRegistry( $settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$pt = Language::create( 'pt', 'pt_PT', 'Portuguese', 'Português', true, 30 );
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', false, 40 ); // Inactive

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

		$resolver                = new ContentTranslationResolver( $this->group_repository, $this->language_registry );
		$this->editorial_service = new TranslationEditorialService(
			$this->language_registry,
			$this->group_repository,
			$resolver,
			$validator,
			$this->db
		);

		$this->columns_ui = new AdminListColumnsUi(
			$this->language_registry,
			$this->editorial_service
		);
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		$this->db->reset();
		$GLOBALS['wp_test_options'] = array();
		$GLOBALS['wp_test_posts']   = array();
		$GLOBALS['wp_test_terms']   = array();
		$GLOBALS['wp_test_caps']    = array();
	}

	/**
	 * Tests add_posts_columns inserts 'tfml_languages' immediately before 'date'.
	 */
	public function test_add_posts_columns_inserts_before_date(): void {
		$initial = array(
			'cb'     => '<input type="checkbox" />',
			'title'  => 'Title',
			'author' => 'Author',
			'date'   => 'Date',
		);

		$columns = $this->columns_ui->add_posts_columns( $initial );

		$expected_keys = array( 'cb', 'title', 'author', AdminListColumnsUi::COLUMN_KEY, 'date' );
		$this->assertSame( $expected_keys, array_keys( $columns ) );
		$this->assertSame( 'Idiomas', $columns[ AdminListColumnsUi::COLUMN_KEY ] );
	}

	/**
	 * Tests add_terms_columns inserts 'tfml_languages' immediately before 'posts'.
	 */
	public function test_add_terms_columns_inserts_before_posts(): void {
		$initial = array(
			'cb'    => '<input type="checkbox" />',
			'name'  => 'Name',
			'slug'  => 'Slug',
			'posts' => 'Count',
		);

		$columns = $this->columns_ui->add_terms_columns( $initial );

		$expected_keys = array( 'cb', 'name', 'slug', AdminListColumnsUi::COLUMN_KEY, 'posts' );
		$this->assertSame( $expected_keys, array_keys( $columns ) );
		$this->assertSame( 'Idiomas', $columns[ AdminListColumnsUi::COLUMN_KEY ] );
	}

	/**
	 * Tests render_post_languages_html for an unmanaged post.
	 */
	public function test_render_post_languages_html_unmanaged(): void {
		$post            = new WP_Post();
		$post->ID        = 501;
		$post->post_type = 'post';

		$GLOBALS['wp_test_posts'][501] = $post;

		$html = $this->columns_ui->render_post_languages_html( $post );

		$this->assertStringContainsString( 'tfml-badge--unmanaged', $html );
		$this->assertStringContainsString( 'Sin idioma', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * Tests render_post_languages_html for a managed post with current, translated, and missing languages.
	 */
	public function test_render_post_languages_html_managed_complete(): void {
		$post_es            = new WP_Post();
		$post_es->ID        = 101;
		$post_es->post_type = 'post';

		$post_en            = new WP_Post();
		$post_en->ID        = 102;
		$post_en->post_type = 'post';

		$GLOBALS['wp_test_posts'][101] = $post_es;
		$GLOBALS['wp_test_posts'][102] = $post_en;

		// Create group with ES and EN.
		$group = $this->group_repository->create_group( 'post', 'post', 101, 'es', true );
		$this->group_repository->add_translation( $group->get_id(), 102, 'en' );

		$GLOBALS['wp_test_caps']['edit_post']  = true;
		$GLOBALS['wp_test_caps']['edit_posts'] = true;

		$html = $this->columns_ui->render_post_languages_html( $post_es );

		// 1. Current language badge.
		$this->assertStringContainsString( 'tfml-badge--current', $html );
		$this->assertStringContainsString( 'ES', $html );

		// 2. Existing translation link for EN.
		$this->assertStringContainsString( 'tfml-link--translated', $html );
		$this->assertStringContainsString( 'post.php?post=102&action=edit', $html );
		$this->assertStringContainsString( 'EN', $html );

		// 3. Missing translation action form for PT.
		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'name="action" value="tfml_create_post_translation"', $html );
		$this->assertStringContainsString( 'name="source_post_id" value="101"', $html );
		$this->assertStringContainsString( 'name="target_language" value="pt"', $html );
		$this->assertStringContainsString( 'tfml-link--add', $html );
		$this->assertStringContainsString( 'PT', $html );

		// 4. Inactive language FR is not rendered as an add button.
		$this->assertStringNotContainsString( 'name="target_language" value="fr"', $html );
	}

	/**
	 * Tests render_post_languages_html with an inactive translation renders inactive badge.
	 */
	public function test_render_post_languages_html_with_inactive_translation(): void {
		$post_es            = new WP_Post();
		$post_es->ID        = 201;
		$post_es->post_type = 'post';

		$post_fr            = new WP_Post();
		$post_fr->ID        = 202;
		$post_fr->post_type = 'post';

		$GLOBALS['wp_test_posts'][201] = $post_es;
		$GLOBALS['wp_test_posts'][202] = $post_fr;

		$group = $this->group_repository->create_group( 'post', 'post', 201, 'es', true );
		// Manually insert inactive translation element for FR into db.
		$this->db->insert(
			$this->db->prefix . 'tfml_group_elements',
			array(
				'group_id'      => $group->get_id(),
				'element_type'  => 'post',
				'element_id'    => 202,
				'language_code' => 'fr',
			)
		);

		$html = $this->columns_ui->render_post_languages_html( $post_es );

		$this->assertStringContainsString( 'tfml-badge--inactive', $html );
		$this->assertStringContainsString( 'FR', $html );
		$this->assertStringContainsString( 'inactivo', $html );
	}

	/**
	 * Tests that when user lacks creation permissions, missing language renders forbidden indicator.
	 */
	public function test_render_post_languages_html_when_user_lacks_create_permission(): void {
		$post            = new WP_Post();
		$post->ID        = 301;
		$post->post_type = 'post';

		$GLOBALS['wp_test_posts'][301] = $post;
		$group                         = $this->group_repository->create_group( 'post', 'post', 301, 'es', true );

		// Deny create permission.
		$GLOBALS['wp_test_caps']['edit_post']  = true;
		$GLOBALS['wp_test_caps']['edit_posts'] = false;

		$html = $this->columns_ui->render_post_languages_html( $post );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'tfml-missing--forbidden', $html );
		$this->assertStringContainsString( 'EN', $html );
	}

	/**
	 * Tests render_term_languages_html for a managed term.
	 */
	public function test_render_term_languages_html_managed(): void {
		$term           = new WP_Term();
		$term->term_id  = 701;
		$term->taxonomy = 'category';
		$term->name     = 'Destinos';

		$GLOBALS['wp_test_terms'][701] = $term;

		$group = $this->group_repository->create_group( 'term', 'category', 701, 'es', true );

		$GLOBALS['wp_test_caps']['manage_categories'] = true;
		$GLOBALS['wp_test_caps']['edit_terms']        = true;

		$html = $this->columns_ui->render_term_languages_html( $term );

		$this->assertStringContainsString( 'tfml-badge--current', $html );
		$this->assertStringContainsString( 'ES', $html );
		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'name="action" value="tfml_create_term_translation"', $html );
		$this->assertStringContainsString( 'name="source_term_id" value="701"', $html );
		$this->assertStringContainsString( 'name="target_language" value="en"', $html );
		$this->assertStringContainsString( 'name="target_term_name" value="Destinos (en)"', $html );
	}

	/**
	 * Tests prefetch_posts primes the editorial batch cache.
	 */
	public function test_prefetch_posts_primes_editorial_batch_cache(): void {
		$post1            = new WP_Post();
		$post1->ID        = 401;
		$post1->post_type = 'post';

		$post2            = new WP_Post();
		$post2->ID        = 402;
		$post2->post_type = 'post';

		$GLOBALS['wp_test_posts'][401] = $post1;
		$GLOBALS['wp_test_posts'][402] = $post2;

		$this->group_repository->create_group( 'post', 'post', 401, 'es', true );

		$posts = array( $post1, $post2 );
		$res   = $this->columns_ui->prefetch_posts( $posts );

		$this->assertSame( $posts, $res );

		// Subsequent call for 401 should return cached editorial data.
		$data1 = $this->editorial_service->get_editorial_data( 'post', 401, 'post' );
		$this->assertTrue( $data1['is_managed'] );
		$this->assertSame( 'es', $data1['current_language'] );

		$data2 = $this->editorial_service->get_editorial_data( 'post', 402, 'post' );
		$this->assertFalse( $data2['is_managed'] );
	}

	/**
	 * Tests render_term_languages_html for an unmanaged term.
	 */
	public function test_render_term_languages_html_unmanaged(): void {
		$term           = new WP_Term();
		$term->term_id  = 801;
		$term->taxonomy = 'category';
		$term->name     = 'Unmanaged Category';

		$html = $this->columns_ui->render_term_languages_html( $term );

		$this->assertStringContainsString( 'tfml-badge--unmanaged', $html );
		$this->assertStringContainsString( 'Sin idioma', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * Tests render_term_languages_html when user lacks edit_terms capability.
	 */
	public function test_render_term_languages_html_when_user_lacks_edit_terms(): void {
		$term           = new WP_Term();
		$term->term_id  = 901;
		$term->taxonomy = 'category';
		$term->name     = 'Protected Term';

		$GLOBALS['wp_test_terms'][901] = $term;
		$this->group_repository->create_group( 'term', 'category', 901, 'es', true );

		$GLOBALS['wp_test_caps']['manage_categories'] = false;
		$GLOBALS['wp_test_caps']['edit_terms']        = false;

		$html = $this->columns_ui->render_term_languages_html( $term );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'tfml-missing--forbidden', $html );
	}

	/**
	 * Tests build_languages_html when language registry is unconfigured.
	 */
	public function test_build_languages_html_unconfigured(): void {
		$html = $this->columns_ui->build_languages_html( 'post', 10, 'post', array( 'is_configured' => false ) );
		$this->assertSame( '<span class="tfml-unconfigured">—</span>', $html );
	}
}
