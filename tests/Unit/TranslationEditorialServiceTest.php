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

		$settings_repo     = new SettingsRepository();
		$this->registry    = new LanguageRegistry( $settings_repo );
		$this->cf_registry = new CustomFieldPolicyRegistry( $settings_repo );

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
			$this->cf_registry
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
}
