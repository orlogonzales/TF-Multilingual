<?php
/**
 * Translation Editorial Service Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Editorial\Exceptions\EditorialConflictException;
use TF\Multilingual\Editorial\Exceptions\EditorialPermissionException;
use TF\Multilingual\Editorial\Exceptions\EditorialValidationException;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;
use WP_Term;

/**
 * Class TranslationEditorialServiceTest
 *
 * Tests the business logic and domain policies of TranslationEditorialService.
 */
class TranslationEditorialServiceTest extends TestCase {

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
	private LanguageRegistry $registry;

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
	 * Editorial service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $service;

	/**
	 * Custom field policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $cf_registry;

	/**
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db         = new TestableWpdb();
		$this->db->prefix = 'wp_';
		$GLOBALS['wpdb']  = $this->db;

		$GLOBALS['wp_test_options']    = array();
		$GLOBALS['wp_test_posts']      = array();
		$GLOBALS['wp_test_terms']      = array();
		$GLOBALS['wp_test_postmeta']   = array();
		$GLOBALS['wp_test_post_types'] = array( 'post', 'page', 'tour' );
		$GLOBALS['wp_test_taxonomies'] = array( 'category', 'post_tag', 'tour_type' );
		$GLOBALS['wp_test_caps']       = array();

		$settings_repo           = new SettingsRepository();
		$this->registry          = new LanguageRegistry( $settings_repo );
		$this->cf_registry       = new CustomFieldPolicyRegistry( $settings_repo );
		$this->retained_registry = new RetainedTranslationRegistry( $this->db );

		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', false, 30 ); // Inactive.

		$this->registry->add_language( $es, true );
		$this->registry->add_language( $en );
		$this->registry->add_language( $fr );

		$validator        = new WordPressElementValidator( $this->db );
		$this->group_repo = new TranslationGroupRepository( $this->db, $this->registry, $validator );
		$this->resolver   = new ContentTranslationResolver( $this->group_repo, $this->registry, $validator );

		$this->service = new TranslationEditorialService(
			$this->registry,
			$this->group_repo,
			$this->resolver,
			$validator,
			$this->db,
			$this->cf_registry,
			null,
			null,
			$this->retained_registry
		);
	}

	/**
	 * Clean up test environment after each test.
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_test_options']    = array();
		$GLOBALS['wp_test_posts']      = array();
		$GLOBALS['wp_test_terms']      = array();
		$GLOBALS['wp_test_postmeta']   = array();
		$GLOBALS['wp_test_post_types'] = array();
		$GLOBALS['wp_test_taxonomies'] = array();
		$GLOBALS['wp_test_caps']       = array();
		parent::tearDown();
	}

	/**
	 * Helper to register a test post in the in-memory array.
	 *
	 * @param int    $id        Post ID.
	 * @param string $post_type Post type.
	 * @param string $status    Post status.
	 * @param string $title     Post title.
	 * @return WP_Post
	 */
	private function create_test_post( int $id = 101, string $post_type = 'post', string $status = 'publish', string $title = 'Post Title' ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_status = $status;
		$post->post_title  = $title;

		$GLOBALS['wp_test_posts'][ $id ] = $post;
		return $post;
	}

	/**
	 * Helper to register a test term in the in-memory array.
	 *
	 * @param int    $id       Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $name     Term name.
	 * @return WP_Term
	 */
	private function create_test_term( int $id = 201, string $taxonomy = 'category', string $name = 'Term Name' ): WP_Term {
		$term           = new WP_Term();
		$term->term_id  = $id;
		$term->taxonomy = $taxonomy;
		$term->name     = $name;

		$GLOBALS['wp_test_terms'][ $id ] = $term;
		return $term;
	}

	/**
	 * Test supported vs disallowed post types.
	 */
	public function test_is_supported_post_type(): void {
		$this->assertTrue( $this->service->is_supported_post_type( 'post' ) );
		$this->assertTrue( $this->service->is_supported_post_type( 'page' ) );
		$this->assertTrue( $this->service->is_supported_post_type( 'tour' ) );

		// Disallowed post types
		$this->assertFalse( $this->service->is_supported_post_type( 'revision' ) );
		$this->assertFalse( $this->service->is_supported_post_type( 'attachment' ) );
		$this->assertFalse( $this->service->is_supported_post_type( 'nav_menu_item' ) );
		$this->assertFalse( $this->service->is_supported_post_type( 'unknown_cpt' ) );
	}

	/**
	 * Test supported vs disallowed taxonomies.
	 */
	public function test_is_supported_taxonomy(): void {
		$this->assertTrue( $this->service->is_supported_taxonomy( 'category' ) );
		$this->assertTrue( $this->service->is_supported_taxonomy( 'post_tag' ) );
		$this->assertTrue( $this->service->is_supported_taxonomy( 'tour_type' ) );

		// Disallowed taxonomies
		$this->assertFalse( $this->service->is_supported_taxonomy( 'post_format' ) );
		$this->assertFalse( $this->service->is_supported_taxonomy( 'nav_menu' ) );
		$this->assertFalse( $this->service->is_supported_taxonomy( 'unknown_tax' ) );
	}

	/**
	 * Test get_editorial_data when unconfigured.
	 */
	public function test_get_editorial_data_when_unconfigured(): void {
		$unconfigured_registry = new LanguageRegistry( new SettingsRepository() );
		$service               = new TranslationEditorialService( $unconfigured_registry );

		$data = $service->get_editorial_data( 'post', 101, 'post' );
		$this->assertFalse( $data['is_configured'] );
		$this->assertFalse( $data['is_managed'] );
	}

	/**
	 * Test get_editorial_data for an unmanaged post.
	 */
	public function test_get_editorial_data_for_unmanaged_post(): void {
		$this->create_test_post( 101, 'post' );
		$data = $this->service->get_editorial_data( 'post', 101, 'post' );

		$this->assertTrue( $data['is_configured'] );
		$this->assertFalse( $data['is_managed'] );
		$this->assertNull( $data['current_language'] );
		$this->assertArrayHasKey( 'es', $data['missing_languages'] );
		$this->assertArrayHasKey( 'en', $data['missing_languages'] );
		$this->assertArrayNotHasKey( 'fr', $data['missing_languages'] ); // Inactive language not listed as missing
	}

	/**
	 * Test assigning initial language to an unmanaged post creates a group.
	 */
	public function test_assign_initial_language_successfully(): void {
		$this->create_test_post( 101, 'post' );

		$group = $this->service->assign_initial_language( 'post', 101, 'post', 'es' );

		$this->assertInstanceOf( TranslationGroup::class, $group );
		$this->assertSame( 'post', $group->get_element_type() );
		$this->assertSame( 'post', $group->get_subtype() );
		$this->assertTrue( $group->has_translation( 'es' ) );

		// Verifying editorial data reflects managed state.
		$data = $this->service->get_editorial_data( 'post', 101, 'post' );
		$this->assertTrue( $data['is_managed'] );
		$this->assertSame( 'es', $data['current_language'] );
		$this->assertArrayHasKey( 'en', $data['missing_languages'] );
		$this->assertArrayNotHasKey( 'es', $data['missing_languages'] );
	}

	/**
	 * Test assigning initial language throws when post is already managed.
	 */
	public function test_assign_initial_language_throws_when_already_managed(): void {
		$this->create_test_post( 101, 'post' );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );

		$this->expectException( EditorialConflictException::class );
		$this->service->assign_initial_language( 'post', 101, 'post', 'en' );
	}

	/**
	 * Test assigning initial language throws when language is inactive.
	 */
	public function test_assign_initial_language_throws_for_inactive_language(): void {
		$this->create_test_post( 101, 'post' );

		$this->expectException( EditorialValidationException::class );
		$this->service->assign_initial_language( 'post', 101, 'post', 'fr' );
	}

	/**
	 * Test assigning initial language throws when user lacks edit_post capability.
	 */
	public function test_assign_initial_language_permission_denied(): void {
		$this->create_test_post( 101, 'post' );
		$GLOBALS['wp_test_caps']['edit_post'] = false;

		$this->expectException( EditorialPermissionException::class );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );
	}

	/**
	 * Test creating post translation creates a draft in the same group with zero cloning.
	 */
	public function test_create_post_translation_successfully_in_draft_status(): void {
		$source = $this->create_test_post( 101, 'post', 'publish', 'Original Title' );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );

		$new_id = $this->service->create_post_translation( 101, 'en' );

		$this->assertGreaterThan( 0, $new_id );
		$this->assertNotEquals( 101, $new_id );

		$new_post = $GLOBALS['wp_test_posts'][ $new_id ] ?? null;
		$this->assertInstanceOf( WP_Post::class, $new_post );
		$this->assertSame( 'draft', $new_post->post_status, 'New translation must strictly be draft' );
		$this->assertSame( 'post', $new_post->post_type );
		$this->assertSame( '', $new_post->post_title, 'Zero-cloning: title should not clone automatically' );

		// Verify both posts belong to the same group.
		$group_source = $this->resolver->get_group_for_element( 'post', 101 );
		$group_new    = $this->resolver->get_group_for_element( 'post', $new_id );

		$this->assertNotNull( $group_source );
		$this->assertSame( $group_source->get_id(), $group_new->get_id() );
		$this->assertTrue( $group_source->has_translation( 'en' ) );
	}

	/**
	 * Test creating post translation throws if source post is not managed.
	 */
	public function test_create_post_translation_throws_if_source_not_managed(): void {
		$this->create_test_post( 101, 'post' );

		$this->expectException( EditorialValidationException::class );
		$this->service->create_post_translation( 101, 'en' );
	}

	/**
	 * Test creating post translation throws if translation already exists (concurrency prevention).
	 */
	public function test_create_post_translation_throws_if_translation_already_exists(): void {
		$this->create_test_post( 101, 'post' );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );
		$this->service->create_post_translation( 101, 'en' );

		$this->expectException( EditorialConflictException::class );
		$this->service->create_post_translation( 101, 'en' );
	}

	/**
	 * Test creating post translation throws if target language is inactive.
	 */
	public function test_create_post_translation_throws_for_inactive_language(): void {
		$this->create_test_post( 101, 'post' );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );

		$this->expectException( EditorialValidationException::class );
		$this->service->create_post_translation( 101, 'fr' );
	}

	/**
	 * Test creating post translation throws for unsupported post type (e.g. revision).
	 */
	public function test_create_post_translation_throws_for_unsupported_post_type(): void {
		$this->create_test_post( 101, 'revision' );

		$this->expectException( EditorialValidationException::class );
		$this->service->create_post_translation( 101, 'en' );
	}

	/**
	 * Test creating post translation throws if user lacks create capability.
	 */
	public function test_create_post_translation_permission_denied(): void {
		$this->create_test_post( 101, 'post' );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );

		$GLOBALS['wp_test_caps']['edit_posts'] = false;

		$this->expectException( EditorialPermissionException::class );
		$this->service->create_post_translation( 101, 'en' );
	}

	/**
	 * Test creating term translation successfully.
	 */
	public function test_create_term_translation_successfully(): void {
		$this->create_test_term( 201, 'category', 'Destinos ES' );
		$this->service->assign_initial_language( 'term', 201, 'category', 'es' );

		$new_term_id = $this->service->create_term_translation( 201, 'category', 'en', 'Destinations EN' );

		$this->assertGreaterThan( 0, $new_term_id );
		$this->assertNotEquals( 201, $new_term_id );

		$new_term = $GLOBALS['wp_test_terms'][ $new_term_id ] ?? null;
		$this->assertInstanceOf( WP_Term::class, $new_term );
		$this->assertSame( 'Destinations EN', $new_term->name );

		// Verify both terms belong to the same group.
		$group_source = $this->resolver->get_group_for_element( 'term', 201 );
		$group_new    = $this->resolver->get_group_for_element( 'term', $new_term_id );

		$this->assertNotNull( $group_source );
		$this->assertSame( $group_source->get_id(), $group_new->get_id() );
		$this->assertSame( 'category', $group_source->get_subtype() );
		$this->assertTrue( $group_source->has_translation( 'en' ) );
	}

	/**
	 * Test creating term translation throws if term name is empty.
	 */
	public function test_create_term_translation_throws_for_empty_term_name(): void {
		$this->create_test_term( 201, 'category', 'Destinos ES' );
		$this->service->assign_initial_language( 'term', 201, 'category', 'es' );

		$this->expectException( EditorialValidationException::class );
		$this->service->create_term_translation( 201, 'category', 'en', '   ' );
	}

	/**
	 * Test creating term translation throws if taxonomy is mismatched.
	 */
	public function test_create_term_translation_throws_if_taxonomy_mismatched(): void {
		$this->create_test_term( 201, 'category', 'Destinos ES' );
		$this->service->assign_initial_language( 'term', 201, 'category', 'es' );

		$this->expectException( EditorialValidationException::class );
		$this->service->create_term_translation( 201, 'post_tag', 'en', 'Destinations EN' );
	}

	/**
	 * Test creating term translation throws if translation already exists.
	 */
	public function test_create_term_translation_throws_if_translation_already_exists(): void {
		$this->create_test_term( 201, 'category', 'Destinos ES' );
		$this->service->assign_initial_language( 'term', 201, 'category', 'es' );
		$this->service->create_term_translation( 201, 'category', 'en', 'Destinations EN' );

		$this->expectException( EditorialConflictException::class );
		$this->service->create_term_translation( 201, 'category', 'en', 'Destinations EN Duplicate' );
	}

	/**
	 * Test creating term translation throws if user lacks edit_terms capability.
	 */
	public function test_create_term_translation_permission_denied(): void {
		$this->create_test_term( 201, 'category', 'Destinos ES' );
		$this->service->assign_initial_language( 'term', 201, 'category', 'es' );

		$GLOBALS['wp_test_caps']['edit_terms'] = false;

		$this->expectException( EditorialPermissionException::class );
		$this->service->create_term_translation( 201, 'category', 'en', 'Destinations EN' );
	}

	/**
	 * Test get_editorial_data with an inactive language translation in group.
	 */
	public function test_get_editorial_data_with_inactive_language_translation(): void {
		$this->create_test_post( 101, 'post' );
		$this->create_test_post( 102, 'post' );

		// Temporarily activate fr to allow adding translation to group, then deactivate to test UI state.
		$this->registry->activate( 'fr' );
		$group = $this->group_repo->create_group( 'post', 'post', 101, 'es', true );
		$this->group_repo->add_translation( (int) $group->get_id(), 102, 'fr' );
		$this->registry->deactivate( 'fr' );

		$data = $this->service->get_editorial_data( 'post', 101, 'post' );

		$this->assertTrue( $data['is_managed'] );
		$this->assertSame( 'es', $data['current_language'] );
		$this->assertArrayHasKey( 'fr', $data['inactive_translations'] );
		$this->assertSame( 102, $data['inactive_translations']['fr']['element_id'] );
		$this->assertArrayHasKey( 'en', $data['missing_languages'] );
	}

	/**
	 * Test get_edit_url for posts and terms.
	 */
	public function test_get_edit_url(): void {
		$post_url = $this->service->get_edit_url( 'post', 101, 'post' );
		$this->assertStringContainsString( 'post.php?post=101', $post_url );

		$term_url = $this->service->get_edit_url( 'term', 201, 'category' );
		$this->assertStringContainsString( 'term.php?taxonomy=category', $term_url );
		$this->assertStringContainsString( 'tag_ID=201', $term_url );
	}

	/**
	 * Tests create_post_translation initializes only metadata configured with SHARE policy.
	 * TRANSLATE and IGNORE (and unconfigured) metadata remain uncopied (Zero Auto-Cloning).
	 */
	public function test_create_post_translation_initializes_only_shared_metadata(): void {
		$this->create_test_post( 101, 'post', 'publish', 'Original Post' );
		$this->group_repo->create_group( 'post', 'post', 101, 'es', true );

		// Configure policies.
		$this->cf_registry->set_policy( '_tour_price', CustomFieldPolicy::SHARE );
		$this->cf_registry->set_policy( '_tour_capacity', CustomFieldPolicy::SHARE );
		$this->cf_registry->set_policy( 'tour_notes', CustomFieldPolicy::TRANSLATE );
		$this->cf_registry->set_policy( '_edit_lock', CustomFieldPolicy::IGNORE );

		// Populate source post metadata.
		$GLOBALS['wp_test_postmeta'][101] = array(
			'_tour_price'        => 350,
			'_tour_capacity'     => 12,
			'tour_notes'         => 'Notas en español',
			'_edit_lock'         => '1600000000:1',
			'unconfigured_field' => 'should_not_copy',
		);

		// Create translation for 'en'.
		$en_id = $this->service->create_post_translation( 101, 'en' );

		// Verify SHARE fields were copied.
		$this->assertSame( 350, $GLOBALS['wp_test_postmeta'][ $en_id ]['_tour_price'] );
		$this->assertSame( 12, $GLOBALS['wp_test_postmeta'][ $en_id ]['_tour_capacity'] );

		// Verify TRANSLATE and IGNORE and unconfigured fields were NOT copied (Zero Cloning).
		$this->assertArrayNotHasKey( 'tour_notes', $GLOBALS['wp_test_postmeta'][ $en_id ] ?? array() );
		$this->assertArrayNotHasKey( '_edit_lock', $GLOBALS['wp_test_postmeta'][ $en_id ] ?? array() );
		$this->assertArrayNotHasKey( 'unconfigured_field', $GLOBALS['wp_test_postmeta'][ $en_id ] ?? array() );
	}

	/**
	 * Tests sync_post_version increments content version only when fingerprint changes.
	 */
	public function test_sync_post_version_increments_only_on_fingerprint_change(): void {
		$this->create_test_post( 101, 'post', 'publish', 'Canonical Post' );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );

		$element_before = $this->group_repo->find_element( 'post', 101 );
		$this->assertNotNull( $element_before );
		$this->assertSame( 1, $element_before->get_current_content_version() );

		// 1. Sync without modifying content -> version remains 1.
		$this->service->sync_post_version( 101 );
		$element_same = $this->group_repo->find_element( 'post', 101 );
		$this->assertSame( 1, $element_same->get_current_content_version() );

		// 2. Modify post title -> fingerprint changes.
		$GLOBALS['wp_test_posts'][101]->post_title = 'Canonical Post Updated';
		$this->service->sync_post_version( 101 );

		$element_after = $this->group_repo->find_element( 'post', 101 );
		$this->assertSame( 2, $element_after->get_current_content_version() );

		// 3. Another sync without change -> version remains 2.
		$this->service->sync_post_version( 101 );
		$element_unchanged = $this->group_repo->find_element( 'post', 101 );
		$this->assertSame( 2, $element_unchanged->get_current_content_version() );
	}

	/**
	 * Tests translation status transitions from UPDATED to REVIEW when source changes, and back to UPDATED.
	 */
	public function test_translation_status_transitions_and_review_resolution(): void {
		$this->create_test_post( 101, 'post', 'publish', 'Canonical Post ES' );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );

		$en_id = $this->service->create_post_translation( 101, 'en' );
		$this->create_test_post( $en_id, 'post', 'draft', 'Translation Post EN' );
		$this->service->sync_post_version( $en_id );

		// Initial: both are UPDATED.
		$data_initial = $this->service->get_editorial_data( 'post', 101, 'post' );
		$this->assertSame( 'updated', $data_initial['translations']['en']['status'] );

		// Modify source post -> source version becomes 2.
		$GLOBALS['wp_test_posts'][101]->post_title = 'Canonical Post ES Modified';
		$this->service->sync_post_version( 101 );

		// Translation must now resolve as REVIEW.
		$data_after_source_change = $this->service->get_editorial_data( 'post', 101, 'post' );
		$this->assertSame( 'review', $data_after_source_change['translations']['en']['status'] );

		// Update EN translation content -> sync EN version -> status transitions back to UPDATED.
		$GLOBALS['wp_test_posts'][ $en_id ]->post_title = 'Translation Post EN Updated';
		$this->service->sync_post_version( $en_id );

		$data_after_translation_update = $this->service->get_editorial_data( 'post', 101, 'post' );
		$this->assertSame( 'updated', $data_after_translation_update['translations']['en']['status'] );
	}

	/**
	 * Tests mark_translation_reviewed resolves REVIEW status without editing content.
	 */
	public function test_mark_translation_reviewed_resolves_review_status(): void {
		$this->create_test_post( 101, 'post', 'publish', 'Canonical Post ES' );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );

		$en_id = $this->service->create_post_translation( 101, 'en' );

		// Advance canonical source to version 2.
		$GLOBALS['wp_test_posts'][101]->post_title = 'Minor typo fixed in ES';
		$this->service->sync_post_version( 101 );

		$data_before = $this->service->get_editorial_data( 'post', 101, 'post' );
		$this->assertSame( 'review', $data_before['translations']['en']['status'] );

		// Mark translation as reviewed.
		$result = $this->service->mark_translation_reviewed( 'post', $en_id );
		$this->assertTrue( $result );

		$data_after = $this->service->get_editorial_data( 'post', 101, 'post' );
		$this->assertSame( 'updated', $data_after['translations']['en']['status'] );
	}

	/**
	 * Tests sync_term_version increments term content version only when name/slug/description changes.
	 */
	public function test_sync_term_version_increments_only_on_change(): void {
		$this->create_test_term( 201, 'category', 'Aventuras', 'aventuras' );
		$this->service->assign_initial_language( 'term', 201, 'category', 'es' );

		$element = $this->group_repo->find_element( 'term', 201 );
		$this->assertNotNull( $element );
		$this->assertSame( 1, $element->get_current_content_version() );

		// Sync without change -> unchanged.
		$this->service->sync_term_version( 201, 'category' );
		$element_same = $this->group_repo->find_element( 'term', 201 );
		$this->assertSame( 1, $element_same->get_current_content_version() );

		// Change term name -> version increments to 2.
		$GLOBALS['wp_test_terms'][201]->name = 'Aventuras Extremas';
		$this->service->sync_term_version( 201, 'category' );

		$element_after = $this->group_repo->find_element( 'term', 201 );
		$this->assertSame( 2, $element_after->get_current_content_version() );
	}

	/**
	 * Tests on_save_post does NOT increment version on autosaves, revisions, or unchanged content.
	 */
	public function test_on_save_post_ignores_autosaves_revisions_and_unchanged_posts(): void {
		$this->create_test_post( 101, 'post', 'publish', 'Original Canonical Post' );
		$this->service->assign_initial_language( 'post', 101, 'post', 'es' );

		$element_initial = $this->group_repo->find_element( 'post', 101 );
		$this->assertNotNull( $element_initial );
		$initial_version     = $element_initial->get_current_content_version();
		$initial_fingerprint = $element_initial->get_translatable_fingerprint();
		$this->assertSame( 1, $initial_version );
		$this->assertNotEmpty( $initial_fingerprint );

		// 1. Autosave arrives -> must be ignored.
		$GLOBALS['wp_test_post_autosaves'][999] = 101;
		$this->service->on_save_post( 999 );

		$element_after_autosave = $this->group_repo->find_element( 'post', 101 );
		$this->assertSame( $initial_version, $element_after_autosave->get_current_content_version() );
		$this->assertSame( $initial_fingerprint, $element_after_autosave->get_translatable_fingerprint() );

		// 2. Revision arrives -> must be ignored.
		$GLOBALS['wp_test_post_revisions'][888] = 101;
		$this->service->on_save_post( 888 );

		$element_after_revision = $this->group_repo->find_element( 'post', 101 );
		$this->assertSame( $initial_version, $element_after_revision->get_current_content_version() );
		$this->assertSame( $initial_fingerprint, $element_after_revision->get_translatable_fingerprint() );

		// 3. Normal save of post without content changes -> must not increment version.
		$this->service->on_save_post( 101, $GLOBALS['wp_test_posts'][101], true );

		$element_after_normal_save = $this->group_repo->find_element( 'post', 101 );
		$this->assertSame( $initial_version, $element_after_normal_save->get_current_content_version() );
		$this->assertSame( $initial_fingerprint, $element_after_normal_save->get_translatable_fingerprint() );

		// 4. Meaningful content change -> version increments to 2, fingerprint updates.
		$GLOBALS['wp_test_posts'][101]->post_title = 'Meaningfully Changed Title';
		$this->service->on_save_post( 101, $GLOBALS['wp_test_posts'][101], true );

		$element_after_edit = $this->group_repo->find_element( 'post', 101 );
		$this->assertSame( 2, $element_after_edit->get_current_content_version() );
		$this->assertNotSame( $initial_fingerprint, $element_after_edit->get_translatable_fingerprint() );
	}

	/**
	 * Tests successful linking of an existing post into a translation group.
	 */
	public function test_link_translation_success(): void {
		$this->create_test_post( 101, 'page', 'publish', 'Actividades ES' );
		$this->create_test_post( 102, 'page', 'publish', 'Activities EN' );

		$group = $this->service->link_translation( 'post', 101, 102, 'en', 'es' );

		$this->assertInstanceOf( \TF\Multilingual\Domain\Translation\TranslationGroup::class, $group );
		$this->assertTrue( $group->has_translation( 'es' ) );
		$this->assertTrue( $group->has_translation( 'en' ) );
		$this->assertSame( 101, $group->get_canonical_element_id() );

		$elem102 = $this->group_repo->find_element( 'post', 102 );
		$this->assertNotNull( $elem102 );
		$this->assertSame( 'en', $elem102->get_language_code() );
	}

	/**
	 * Tests link_translation rejects identical source and target IDs.
	 */
	public function test_link_translation_rejects_identical_ids(): void {
		$this->expectException( EditorialValidationException::class );
		$this->service->link_translation( 'post', 101, 101, 'en' );
	}

	/**
	 * Tests link_translation rejects inactive language.
	 */
	public function test_link_translation_rejects_inactive_language(): void {
		$this->expectException( EditorialValidationException::class );
		$this->service->link_translation( 'post', 101, 102, 'fr' );
	}

	/**
	 * Tests link_translation rejects subtype mismatch.
	 */
	public function test_link_translation_rejects_subtype_mismatch(): void {
		$this->create_test_post( 101, 'page', 'publish', 'Página' );
		$this->create_test_post( 102, 'post', 'publish', 'Entrada' );

		$this->expectException( EditorialValidationException::class );
		$this->service->link_translation( 'post', 101, 102, 'en' );
	}

	/**
	 * Tests link_translation rejects target already assigned to another group.
	 */
	public function test_link_translation_rejects_already_assigned_target(): void {
		$this->create_test_post( 101, 'page', 'publish', 'Page 1' );
		$this->create_test_post( 102, 'page', 'publish', 'Page 2' );

		// Assign 102 to its own group first.
		$this->service->assign_initial_language( 'post', 102, 'page', 'en' );

		$this->expectException( EditorialConflictException::class );
		$this->service->link_translation( 'post', 101, 102, 'en' );
	}

	/**
	 * Tests link_translation rejects language duplicate when group already contains that language.
	 */
	public function test_link_translation_rejects_duplicate_language_in_group(): void {
		$this->create_test_post( 101, 'page', 'publish', 'Page 1 ES' );
		$this->create_test_post( 102, 'page', 'publish', 'Page 2 EN' );
		$this->create_test_post( 103, 'page', 'publish', 'Page 3 EN Duplicate' );

		$this->service->link_translation( 'post', 101, 102, 'en' );

		$this->expectException( EditorialConflictException::class );
		$this->service->link_translation( 'post', 101, 103, 'en' );
	}

	/**
	 * Tests link_translation enforces user editing permissions.
	 */
	public function test_link_translation_enforces_permissions(): void {
		$this->create_test_post( 101, 'page', 'publish', 'Page 1' );
		$this->create_test_post( 102, 'page', 'publish', 'Page 2' );

		$GLOBALS['wp_test_caps']['edit_post:102'] = false;

		$this->expectException( EditorialPermissionException::class );
		$this->service->link_translation( 'post', 101, 102, 'en' );
	}

	/**
	 * Tests successful unlinking of an element from its translation group.
	 */
	public function test_unlink_translation_success(): void {
		$this->create_test_post( 101, 'page', 'publish', 'Actividades ES' );
		$this->create_test_post( 102, 'page', 'publish', 'Activities EN' );

		$this->service->link_translation( 'post', 101, 102, 'en', 'es' );

		$this->assertNotNull( $this->group_repo->find_element( 'post', 102 ) );

		$success = $this->service->unlink_translation( 'post', 102 );
		$this->assertTrue( $success );

		// 102 must be gone from group elements.
		$this->assertNull( $this->group_repo->find_element( 'post', 102 ) );

		// WordPress post entity in Core remains intact.
		$this->assertNotNull( $GLOBALS['wp_test_posts'][102] );
	}

	/**
	 * Tests unlinking canonical promotes remaining element as new canonical.
	 */
	public function test_unlink_canonical_promotes_replacement(): void {
		$this->create_test_post( 101, 'page', 'publish', 'Actividades ES' );
		$this->create_test_post( 102, 'page', 'publish', 'Activities EN' );

		$this->service->link_translation( 'post', 101, 102, 'en', 'es' );

		$group = $this->group_repo->find_by_element( 'post', 101 );
		$this->assertSame( 101, $group->get_canonical_element_id() );

		// Unlink canonical 101.
		$success = $this->service->unlink_translation( 'post', 101 );
		$this->assertTrue( $success );

		// Group reloaded from 102.
		$reloaded = $this->group_repo->find_by_element( 'post', 102 );
		$this->assertNotNull( $reloaded );
		$this->assertSame( 102, $reloaded->get_canonical_element_id() );
	}

	/**
	 * Tests unlink_translation enforces permissions.
	 */
	public function test_unlink_translation_enforces_permissions(): void {
		$this->create_test_post( 101, 'page', 'publish', 'Página' );
		$this->service->assign_initial_language( 'post', 101, 'page', 'es' );

		$GLOBALS['wp_test_caps']['edit_post:101'] = false;

		$this->expectException( EditorialPermissionException::class );
		$this->service->unlink_translation( 'post', 101 );
	}

	/**
	 * Tests unlink_translation with valid context source ID succeeds.
	 */
	public function test_unlink_translation_with_context_source_id_success(): void {
		$this->create_test_post( 201, 'page', 'publish', 'Nosotros ES' );
		$this->create_test_post( 202, 'page', 'publish', 'About Us EN' );

		$this->service->link_translation( 'post', 201, 202, 'en', 'es' );

		$success = $this->service->unlink_translation( 'post', 202, null, 201 );
		$this->assertTrue( $success );
		$this->assertNull( $this->group_repo->find_element( 'post', 202 ) );
		$this->assertNotNull( $this->group_repo->find_element( 'post', 201 ) );
	}

	/**
	 * Tests unlink_translation rejects cross-group tampering when source belongs to another group.
	 */
	public function test_unlink_translation_with_context_source_id_tampering_rejected(): void {
		$this->create_test_post( 201, 'page', 'publish', 'Group 1 ES' );
		$this->create_test_post( 202, 'page', 'publish', 'Group 1 EN' );
		$this->service->link_translation( 'post', 201, 202, 'en', 'es' );

		$this->create_test_post( 301, 'page', 'publish', 'Group 2 ES' );
		$this->create_test_post( 302, 'page', 'publish', 'Group 2 EN' );
		$this->service->link_translation( 'post', 301, 302, 'en', 'es' );

		// Tampering attempt: unlinking 302 (from group 2) under the context of 201 (from group 1).
		$this->expectException( EditorialConflictException::class );
		$this->service->unlink_translation( 'post', 302, null, 201 );
	}

	/**
	 * Tests unlink_translation rejects when user lacks permissions on the source context post.
	 */
	public function test_unlink_translation_with_context_source_id_unauthorized_rejected(): void {
		$this->create_test_post( 201, 'page', 'publish', 'Nosotros ES' );
		$this->create_test_post( 202, 'page', 'publish', 'About Us EN' );
		$this->service->link_translation( 'post', 201, 202, 'en', 'es' );

		$GLOBALS['wp_test_caps']['edit_post:202'] = true;
		$GLOBALS['wp_test_caps']['edit_post:201'] = false; // Denied on context source.

		$this->expectException( EditorialPermissionException::class );
		$this->service->unlink_translation( 'post', 202, null, 201 );
	}

	/**
	 * Tests unlinking the last remaining element in a group purges the empty group row,
	 * while the WordPress Core post remains 100% intact.
	 */
	public function test_unlink_last_remaining_element_purges_empty_group(): void {
		$this->create_test_post( 401, 'page', 'publish', 'Monolingual Post' );
		$this->service->assign_initial_language( 'post', 401, 'page', 'es' );

		$group = $this->group_repo->find_by_element( 'post', 401 );
		$this->assertNotNull( $group );
		$group_id = (int) $group->get_id();

		// Unlink the only element in the group.
		$success = $this->service->unlink_translation( 'post', 401 );
		$this->assertTrue( $success );

		// Element is removed from group repository.
		$this->assertNull( $this->group_repo->find_element( 'post', 401 ) );
		// Empty group row is purged.
		$this->assertNull( $this->group_repo->find( $group_id ) );
		// Core post remains 100% intact.
		$post = $GLOBALS['wp_test_posts'][401] ?? null;
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( 401, $post->ID );
	}

	/**
	 * Tests re-attempting to unlink an already unlinked element throws EditorialValidationException.
	 */
	public function test_unlink_retry_when_not_in_group_throws(): void {
		$this->create_test_post( 501, 'page', 'publish', 'Test Post' );
		$this->service->assign_initial_language( 'post', 501, 'page', 'es' );

		$this->service->unlink_translation( 'post', 501 );

		// Second attempt must throw validation exception because 501 is no longer managed.
		$this->expectException( EditorialValidationException::class );
		$this->service->unlink_translation( 'post', 501 );
	}

	/**
	 * Tests unlinking an active translation preserves retained French translations in registry.
	 */
	public function test_unlink_preserves_retained_translations(): void {
		$this->create_test_post( 601, 'page', 'publish', 'Post ES' );
		$this->create_test_post( 602, 'page', 'publish', 'Post EN' );
		$this->service->link_translation( 'post', 601, 602, 'en', 'es' );

		$group = $this->group_repo->find_by_element( 'post', 601 );
		$this->assertNotNull( $group );
		$group_id = (int) $group->get_id();

		// Simulate retained french post 8839 in registry for this group.
		$this->retained_registry->register(
			'wpml',
			9999,
			$group_id,
			'post',
			'page',
			array( 'fr' => 8839 )
		);
		$this->assertTrue( $this->retained_registry->has_retained_element( 'post', 8839 ) );

		// Unlink English translation 602.
		$success = $this->service->unlink_translation( 'post', 602 );
		$this->assertTrue( $success );

		// Retained French translation 8839 remains completely intact.
		$this->assertTrue( $this->retained_registry->has_retained_element( 'post', 8839 ) );
	}

	/**
	 * Tests database rollback occurs cleanly if an SQL error occurs during unlinking.
	 */
	public function test_unlink_rolls_back_on_database_error(): void {
		$this->create_test_post( 701, 'page', 'publish', 'Post ES' );
		$this->create_test_post( 702, 'page', 'publish', 'Post EN' );
		$this->service->link_translation( 'post', 701, 702, 'en', 'es' );

		// Simulate DB error when attempting to delete the translation row.
		$this->db->force_next_error = 'Simulated DB connection timeout';

		try {
			$this->service->unlink_translation( 'post', 702 );
			$this->fail( 'Expected exception was not thrown on DB error.' );
		} catch ( \Throwable $e ) {
			// Expected exception from repository.
			$this->assertStringContainsString( 'Failed to delete translation element', $e->getMessage() );
		}

		// Verify transaction rollback: both elements must still be present in group elements.
		$this->assertNotNull( $this->group_repo->find_element( 'post', 701 ) );
		$this->assertNotNull( $this->group_repo->find_element( 'post', 702 ) );
	}

	/**
	 * Tests unlinking the last active element from a group with retained translations preserves the group.
	 *
	 * Ensures that historical traceability, manifests, and RetainedTranslationRegistry references
	 * remain 100% consistent, allowing future promotion when the retained language is activated.
	 */
	public function test_unlink_last_active_element_preserves_group_when_retained_translations_exist(): void {
		$this->create_test_post( 801, 'page', 'publish', 'Single Active Post ES' );
		$this->create_test_post( 802, 'page', 'publish', 'Retained Post FR' );

		$group    = $this->service->assign_initial_language( 'post', 801, 'page', 'es' );
		$group_id = (int) $group->get_id();

		// Register retained French translation in this group.
		$this->retained_registry->register(
			'wpml',
			8888,
			$group_id,
			'post',
			'page',
			array( 'fr' => 802 )
		);

		$this->assertTrue( $this->retained_registry->has_retained_element( 'post', 802 ) );

		// Unlink the only active element (801).
		$success = $this->service->unlink_translation( 'post', 801 );
		$this->assertTrue( $success );

		// Active element 801 is no longer registered.
		$this->assertNull( $this->group_repo->find_element( 'post', 801 ) );

		// The group row in tfml_groups MUST STILL EXIST to preserve traceability for retained translations.
		$reloaded_group = $this->group_repo->find( $group_id );
		$this->assertNotNull( $reloaded_group );
		$this->assertSame( $group_id, (int) $reloaded_group->get_id() );
		$this->assertNull( $reloaded_group->get_canonical_element_id() );
		$this->assertEmpty( $reloaded_group->get_elements() );

		// Retained registry record is intact and still points to this group.
		$retained_rec = $this->retained_registry->get_by_group_id( $group_id );
		$this->assertNotNull( $retained_rec );
		$this->assertSame( 8888, (int) $retained_rec['trid'] );

		// Now simulate future activation of French language in LanguageRegistry.
		$this->registry->update_language( 'fr', array( 'active' => true ) );

		$validator = new \TF\Multilingual\Domain\Translation\WordPressElementValidator( $this->db );

		// Promote the retained French translation into the preserved group.
		$promoted_elem = $this->retained_registry->promote_retained_translation(
			$group_id,
			'fr',
			$this->group_repo,
			$this->registry,
			$validator
		);

		$this->assertSame( 802, $promoted_elem->get_element_id() );
		$this->assertSame( 'fr', $promoted_elem->get_language_code() );

		// Group now has French element and has restored canonical.
		$final_group = $this->group_repo->find( $group_id );
		$this->assertNotNull( $final_group );
		$this->assertSame( 802, $final_group->get_canonical_element_id() );
		$this->assertCount( 1, $final_group->get_elements() );
	}

	/**
	 * Tests cleanup_deleted_element removes a normal (non-canonical) element and keeps canonical intact.
	 */
	public function test_cleanup_deleted_element_normal_member(): void {
		$this->create_test_post( 1101, 'post', 'publish', 'Post ES' );
		$this->create_test_post( 1102, 'post', 'publish', 'Post EN' );

		$group    = $this->service->link_translation( 'post', 1101, 1102, 'en', 'es' );
		$group_id = (int) $group->get_id();

		$this->assertSame( 1101, $group->get_canonical_element_id() );
		$this->assertCount( 2, $group->get_elements() );

		// Simulate deletion of non-canonical element 1102.
		$this->service->cleanup_deleted_element( 'post', 1102 );

		$reloaded = $this->group_repo->find( $group_id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( 1101, $reloaded->get_canonical_element_id() );
		$this->assertCount( 1, $reloaded->get_elements() );
		$this->assertNull( $this->group_repo->find_element( 'post', 1102 ) );
		$this->assertNotNull( $this->group_repo->find_element( 'post', 1101 ) );
	}

	/**
	 * Tests cleanup_deleted_element reassigns canonical when canonical element is deleted.
	 */
	public function test_cleanup_deleted_element_canonical_reassigned(): void {
		$this->create_test_post( 1201, 'post', 'publish', 'Post ES' );
		$this->create_test_post( 1202, 'post', 'publish', 'Post EN' );

		$group    = $this->service->link_translation( 'post', 1201, 1202, 'en', 'es' );
		$group_id = (int) $group->get_id();

		$this->assertSame( 1201, $group->get_canonical_element_id() );

		// Simulate deletion of canonical element 1201.
		$this->service->cleanup_deleted_element( 'post', 1201 );

		$reloaded = $this->group_repo->find( $group_id );
		$this->assertNotNull( $reloaded );
		// Canonical must be reassigned to remaining element 1202.
		$this->assertSame( 1202, $reloaded->get_canonical_element_id() );
		$this->assertCount( 1, $reloaded->get_elements() );
		$this->assertNull( $this->group_repo->find_element( 'post', 1201 ) );
	}

	/**
	 * Tests cleanup_deleted_element purges group completely when the last member is deleted and no retained exist.
	 */
	public function test_cleanup_deleted_element_last_member_purges_group_without_retained(): void {
		$this->create_test_post( 1301, 'post', 'publish', 'Sole Post ES' );

		$group    = $this->service->assign_initial_language( 'post', 1301, 'post', 'es' );
		$group_id = (int) $group->get_id();

		// Cleanup sole member.
		$this->service->cleanup_deleted_element( 'post', 1301 );

		// Group should be completely purged.
		$this->assertNull( $this->group_repo->find( $group_id ) );
		$this->assertNull( $this->group_repo->find_element( 'post', 1301 ) );
	}

	/**
	 * Tests cleanup_deleted_element preserves group row when retained translations exist in RetainedTranslationRegistry.
	 */
	public function test_cleanup_deleted_element_last_member_preserves_group_with_retained(): void {
		$this->create_test_post( 1401, 'page', 'publish', 'Active Post ES' );
		$this->create_test_post( 1402, 'page', 'publish', 'Retained Post FR' );

		$group    = $this->service->assign_initial_language( 'post', 1401, 'page', 'es' );
		$group_id = (int) $group->get_id();

		// Register retained French translation.
		$this->retained_registry->register(
			'wpml',
			9999,
			$group_id,
			'post',
			'page',
			array( 'fr' => 1402 )
		);

		$this->assertTrue( $this->retained_registry->has_retained_element( 'post', 1402 ) );

		// Cleanup sole active member 1401.
		$this->service->cleanup_deleted_element( 'post', 1401 );

		// Group row MUST be preserved to maintain retained translation linkage.
		$preserved = $this->group_repo->find( $group_id );
		$this->assertNotNull( $preserved );
		$this->assertSame( $group_id, (int) $preserved->get_id() );
		$this->assertNull( $preserved->get_canonical_element_id() );
		$this->assertEmpty( $preserved->get_elements() );

		// Retained registry record remains intact.
		$retained_rec = $this->retained_registry->get_by_group_id( $group_id );
		$this->assertNotNull( $retained_rec );
		$this->assertSame( 9999, (int) $retained_rec['trid'] );
	}

	/**
	 * Tests on_deleted_post and on_delete_term hook callbacks invoke cleanup_deleted_element.
	 */
	public function test_lifecycle_hooks_invoke_cleanup(): void {
		$this->create_test_post( 1501, 'post', 'publish', 'Hook Post ES' );
		$this->create_test_post( 1502, 'post', 'publish', 'Hook Post EN' );

		$group    = $this->service->link_translation( 'post', 1501, 1502, 'en', 'es' );
		$group_id = (int) $group->get_id();

		$this->service->init_hooks();

		// Trigger deleted_post hook.
		do_action( 'deleted_post', 1502 );

		$reloaded = $this->group_repo->find( $group_id );
		$this->assertNotNull( $reloaded );
		$this->assertCount( 1, $reloaded->get_elements() );
		$this->assertNull( $this->group_repo->find_element( 'post', 1502 ) );

		// Test taxonomy term deletion.
		$this->create_test_term( 2501, 'category', 'Term ES' );
		$this->create_test_term( 2502, 'category', 'Term EN' );

		$term_group    = $this->service->link_translation( 'term', 2501, 2502, 'en', 'es' );
		$term_group_id = (int) $term_group->get_id();

		// Trigger delete_term hook.
		do_action( 'delete_term', 2502, 2502, 'category' );

		$reloaded_term = $this->group_repo->find( $term_group_id );
		$this->assertNotNull( $reloaded_term );
		$this->assertCount( 1, $reloaded_term->get_elements() );
		$this->assertNull( $this->group_repo->find_element( 'term', 2502 ) );

		$this->service->remove_hooks();
	}
}
