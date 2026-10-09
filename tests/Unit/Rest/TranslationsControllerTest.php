<?php
/**
 * REST Translations Controller Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Rest
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Rest\TranslationsController;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

/**
 * Class TranslationsControllerTest
 */
class TranslationsControllerTest extends TestCase {

	/**
	 * Testable database double.
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
	 * Group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $translation_resolver;

	/**
	 * Editorial service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $editorial_service;

	/**
	 * Status resolver.
	 *
	 * @var TranslationStatusResolver
	 */
	private TranslationStatusResolver $status_resolver;

	/**
	 * Translations controller under test.
	 *
	 * @var TranslationsController
	 */
	private TranslationsController $controller;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db        = new TestableWpdb();
		$GLOBALS['wpdb'] = $this->db;

		$repo = $this->createMock( SettingsRepository::class );
		$repo->method( 'load' )->willReturn(
			array(
				'languages'        => array(
					'es' => array(
						'code'        => 'es',
						'locale'      => 'es_ES',
						'name'        => 'Spanish',
						'native_name' => 'Español',
						'active'      => true,
						'order'       => 10,
					),
					'en' => array(
						'code'        => 'en',
						'locale'      => 'en_US',
						'name'        => 'English',
						'native_name' => 'English',
						'active'      => true,
						'order'       => 20,
					),
					'pt' => array(
						'code'        => 'pt',
						'locale'      => 'pt_PT',
						'name'        => 'Portuguese',
						'native_name' => 'Português',
						'active'      => true,
						'order'       => 30,
					),
				),
				'default_language' => 'es',
			)
		);

		$this->language_registry    = new LanguageRegistry( $repo );
		$this->group_repository     = new TranslationGroupRepository( $this->db, $this->language_registry );
		$this->translation_resolver = new ContentTranslationResolver( $this->group_repository, $this->language_registry );
		$this->status_resolver      = new TranslationStatusResolver( $this->group_repository, $this->language_registry );
		$this->editorial_service    = new TranslationEditorialService(
			$this->language_registry,
			$this->group_repository,
			$this->translation_resolver,
			null,
			$this->db,
			new CustomFieldPolicyRegistry(),
			$this->status_resolver
		);

		$this->controller = new TranslationsController(
			$this->language_registry,
			$this->group_repository,
			$this->translation_resolver,
			$this->editorial_service,
			$this->status_resolver
		);

		$post1              = new WP_Post();
		$post1->ID          = 101;
		$post1->post_type   = 'post';
		$post1->post_status = 'publish';

		$post2              = new WP_Post();
		$post2->ID          = 102;
		$post2->post_type   = 'post';
		$post2->post_status = 'publish';

		$GLOBALS['wp_test_posts'] = array(
			101 => $post1,
			102 => $post2,
		);
		$GLOBALS['wp_test_terms'] = array();
		$GLOBALS['wp_test_caps']  = array();
	}

	/**
	 * Tears down globals after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_test_posts'], $GLOBALS['wp_test_terms'], $GLOBALS['wp_test_caps'] );
		parent::tearDown();
	}

	/**
	 * Tests route registration in WordPress REST server.
	 */
	public function test_register_routes(): void {
		$GLOBALS['wp_rest_routes'] = array();
		$this->controller->register_routes();

		$this->assertArrayHasKey( 'tf-multilingual/v1', $GLOBALS['wp_rest_routes'] );
		$routes = $GLOBALS['wp_rest_routes']['tf-multilingual/v1'];

		$this->assertArrayHasKey( '/translations', $routes );
		$this->assertArrayHasKey( '/translations/(?P<element_type>post|term)/(?P<id>[\\d]+)', $routes );
		$this->assertArrayHasKey( '/translations/link', $routes );
		$this->assertArrayHasKey( '/translations/unlink', $routes );
	}

	/**
	 * Tests get_item_permissions_check on non-existent post returns 404 error.
	 */
	public function test_get_item_permissions_check_non_existent_post(): void {
		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/999' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 999 );

		$result = $this->controller->get_item_permissions_check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Tests get_item_permissions_check on public published post returns true.
	 */
	public function test_get_item_permissions_check_published_post(): void {
		$post                          = new WP_Post();
		$post->ID                      = 101;
		$post->post_status             = 'publish';
		$post->post_type               = 'post';
		$GLOBALS['wp_test_posts'][101] = $post;

		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/101' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 101 );

		$this->assertTrue( $this->controller->get_item_permissions_check( $request ) );
	}

	/**
	 * Tests get_item_permissions_check on draft post without capabilities returns 401/403.
	 */
	public function test_get_item_permissions_check_draft_post_unauthorized(): void {
		$post                          = new WP_Post();
		$post->ID                      = 102;
		$post->post_status             = 'draft';
		$post->post_type               = 'post';
		$GLOBALS['wp_test_posts'][102] = $post;

		$GLOBALS['wp_test_caps']['read_post'] = false;
		$GLOBALS['wp_test_caps']['edit_post'] = false;

		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/102' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 102 );

		$result = $this->controller->get_item_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Tests get_item for an unassigned post returns structured empty representation.
	 */
	public function test_get_item_unassigned_post(): void {
		$post                          = new WP_Post();
		$post->ID                      = 101;
		$post->post_status             = 'publish';
		$post->post_type               = 'post';
		$GLOBALS['wp_test_posts'][101] = $post;

		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/101' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 101 );

		$response = $this->controller->get_item( $request );
		$data     = $response->get_data();

		$this->assertNull( $data['group_id'] );
		$this->assertSame( 'post', $data['element_type'] );
		$this->assertSame( 101, $data['element_id'] );
		$this->assertSame( 'post', $data['subtype'] );
		$this->assertNull( $data['language_code'] );
		$this->assertCount( 3, $data['untranslated_languages'] );
	}

	/**
	 * Tests get_item for an assigned translation group returns full payload.
	 */
	public function test_get_item_assigned_group(): void {
		$group_id                      = 42;
		$this->db->groups[ $group_id ] = array(
			'id'                   => $group_id,
			'element_type'         => 'post',
			'subtype'              => 'post',
			'canonical_element_id' => 101,
			'created_at'           => '2026-10-09 10:00:00',
		);
		$this->db->group_elements[1]   = array(
			'id'                            => 1,
			'group_id'                      => $group_id,
			'element_type'                  => 'post',
			'element_id'                    => 101,
			'language_code'                 => 'es',
			'source_version_at_translation' => 1,
			'current_content_version'       => 2,
			'translatable_fingerprint'      => 'hash-es',
		);
		$this->db->group_elements[2]   = array(
			'id'                            => 2,
			'group_id'                      => $group_id,
			'element_type'                  => 'post',
			'element_id'                    => 102,
			'language_code'                 => 'en',
			'source_version_at_translation' => 1,
			'current_content_version'       => 1,
			'translatable_fingerprint'      => 'hash-en',
		);

		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/101' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 101 );

		$response = $this->controller->get_item( $request );
		$data     = $response->get_data();

		$this->assertSame( 42, $data['group_id'] );
		$this->assertSame( 'post', $data['element_type'] );
		$this->assertSame( 101, $data['element_id'] );
		$this->assertSame( 'es', $data['language_code'] );
		$this->assertTrue( $data['is_canonical'] );
		$this->assertSame( 101, $data['canonical_element_id'] );
		$this->assertSame( 'es', $data['canonical_language'] );

		// Translations map.
		$this->assertArrayHasKey( 'es', $data['translations'] );
		$this->assertArrayHasKey( 'en', $data['translations'] );
		$this->assertSame( TranslationStatus::UPDATED, $data['translations']['es']['status'] );
		$this->assertSame( TranslationStatus::REVIEW, $data['translations']['en']['status'] );

		// Untranslated list.
		$this->assertSame( array( 'pt' ), $data['untranslated_languages'] );
	}

	/**
	 * Tests link_permissions_check anti-IDOR enforcement.
	 */
	public function test_link_permissions_check_enforces_anti_idor(): void {
		$request = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/link' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'source_id', 101 );
		$request->set_param( 'target_id', 102 );
		$request->set_param( 'target_language', 'en' );

		// Can edit source, but cannot edit target.
		$GLOBALS['wp_test_caps']['edit_post:101'] = true;
		$GLOBALS['wp_test_caps']['edit_post:102'] = false;

		$result = $this->controller->link_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
	}

	/**
	 * Tests link_translation rejects identical source and target IDs.
	 */
	public function test_link_translation_rejects_identical_elements(): void {
		$request = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/link' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'source_id', 101 );
		$request->set_param( 'target_id', 101 );
		$request->set_param( 'target_language', 'en' );

		$result = $this->controller->link_translation( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_link_identical', $result->get_error_code() );
	}

	/**
	 * Tests link_translation rejects inactive language.
	 */
	public function test_link_translation_rejects_inactive_language(): void {
		$request = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/link' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'source_id', 101 );
		$request->set_param( 'target_id', 102 );
		$request->set_param( 'target_language', 'fr' ); // Not registered.

		$result = $this->controller->link_translation( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_language', $result->get_error_code() );
	}

	/**
	 * Tests link_translation rejects subtype mismatch between source and target.
	 */
	public function test_link_translation_rejects_subtype_mismatch(): void {
		$post1            = new WP_Post();
		$post1->ID        = 101;
		$post1->post_type = 'post';

		$post2            = new WP_Post();
		$post2->ID        = 102;
		$post2->post_type = 'page';

		$GLOBALS['wp_test_posts'][101] = $post1;
		$GLOBALS['wp_test_posts'][102] = $post2;

		$request = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/link' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'source_id', 101 );
		$request->set_param( 'target_id', 102 );
		$request->set_param( 'target_language', 'en' );

		$result = $this->controller->link_translation( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_subtype_mismatch', $result->get_error_code() );
	}

	/**
	 * Tests unlink_permissions_check enforcement.
	 */
	public function test_unlink_permissions_check(): void {
		$request = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/unlink' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'element_id', 101 );

		$GLOBALS['wp_test_caps']['edit_post:101'] = false;
		$result                                   = $this->controller->unlink_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );

		$GLOBALS['wp_test_caps']['edit_post:101'] = true;
		$this->assertTrue( $this->controller->unlink_permissions_check( $request ) );
	}

	/**
	 * Tests unlink_translation when element is not in group returns 404.
	 */
	public function test_unlink_translation_element_not_in_group(): void {
		$request = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/unlink' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'element_id', 101 );

		$result = $this->controller->unlink_translation( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_in_group', $result->get_error_code() );
	}

	/**
	 * Tests schema retrieval.
	 */
	public function test_get_item_schema(): void {
		$schema = $this->controller->get_item_schema();
		$this->assertSame( 'translation_group', $schema['title'] );
		$this->assertArrayHasKey( 'group_id', $schema['properties'] );
		$this->assertArrayHasKey( 'translations', $schema['properties'] );
		$this->assertArrayHasKey( 'canonical_element_id', $schema['properties'] );
	}

	/**
	 * Tests get_item does not expose private or draft sibling translations to unauthorized users.
	 */
	public function test_get_item_hides_private_sibling_from_unauthorized_users(): void {
		$post1              = new WP_Post();
		$post1->ID          = 201;
		$post1->post_type   = 'page';
		$post1->post_status = 'publish';

		$post2              = new WP_Post();
		$post2->ID          = 202;
		$post2->post_type   = 'page';
		$post2->post_status = 'draft';

		$GLOBALS['wp_test_posts'][201] = $post1;
		$GLOBALS['wp_test_posts'][202] = $post2;

		$this->db->groups[55]          = array(
			'id'                   => 55,
			'element_type'         => 'post',
			'subtype'              => 'page',
			'canonical_element_id' => 201,
			'created_at'           => '2026-10-09 10:00:00',
		);
		$this->db->group_elements[201] = array(
			'id'                            => 1,
			'group_id'                      => 55,
			'element_type'                  => 'post',
			'element_id'                    => 201,
			'language_code'                 => 'es',
			'source_version_at_translation' => 1,
			'current_content_version'       => 1,
			'translatable_fingerprint'      => 'fp1',
		);
		$this->db->group_elements[202] = array(
			'id'                            => 2,
			'group_id'                      => 55,
			'element_type'                  => 'post',
			'element_id'                    => 202,
			'language_code'                 => 'en',
			'source_version_at_translation' => 1,
			'current_content_version'       => 1,
			'translatable_fingerprint'      => 'fp2',
		);

		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/201' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 201 );

		// Unauthorized: cannot read draft post 202.
		$GLOBALS['wp_test_caps']['read_post:202'] = false;
		$GLOBALS['wp_test_caps']['edit_post:202'] = false;

		$response = $this->controller->get_item( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'es', $data['translations'] );
		$this->assertArrayNotHasKey( 'en', $data['translations'], 'Private sibling post MUST NOT be exposed to unauthorized users' );
		$this->assertContains( 'en', $data['untranslated_languages'], 'Unpublished sibling language must appear as untranslated for unauthorized users' );

		// Authorized editor can see both.
		$GLOBALS['wp_test_caps']['read_post:202'] = true;
		$response2                                = $this->controller->get_item( $request );
		$data2                                    = $response2->get_data();

		$this->assertArrayHasKey( 'en', $data2['translations'], 'Authorized editor MUST be able to see draft sibling post' );
		$this->assertSame( 202, $data2['translations']['en']['element_id'] );
	}

	/**
	 * Tests get_item masks canonical element if it is private/draft and user lacks read permission.
	 */
	public function test_get_item_masks_canonical_when_private(): void {
		$post1              = new WP_Post();
		$post1->ID          = 301;
		$post1->post_type   = 'page';
		$post1->post_status = 'draft';

		$post2              = new WP_Post();
		$post2->ID          = 302;
		$post2->post_type   = 'page';
		$post2->post_status = 'publish';

		$GLOBALS['wp_test_posts'][301] = $post1;
		$GLOBALS['wp_test_posts'][302] = $post2;

		$this->db->groups[77]          = array(
			'id'                   => 77,
			'element_type'         => 'post',
			'subtype'              => 'page',
			'canonical_element_id' => 301,
			'created_at'           => '2026-10-09 10:00:00',
		);
		$this->db->group_elements[301] = array(
			'id'                            => 1,
			'group_id'                      => 77,
			'element_type'                  => 'post',
			'element_id'                    => 301,
			'language_code'                 => 'es',
			'source_version_at_translation' => 1,
			'current_content_version'       => 1,
			'translatable_fingerprint'      => 'fp1',
		);
		$this->db->group_elements[302] = array(
			'id'                            => 2,
			'group_id'                      => 77,
			'element_type'                  => 'post',
			'element_id'                    => 302,
			'language_code'                 => 'en',
			'source_version_at_translation' => 1,
			'current_content_version'       => 1,
			'translatable_fingerprint'      => 'fp2',
		);

		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/302' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 302 );

		// Unauthorized on post 301.
		$GLOBALS['wp_test_caps']['read_post:301'] = false;
		$GLOBALS['wp_test_caps']['edit_post:301'] = false;

		$response = $this->controller->get_item( $request );
		$data     = $response->get_data();

		$this->assertNull( $data['canonical_element_id'], 'Private canonical ID must be nullified for unauthorized users' );
		$this->assertNull( $data['canonical_language'], 'Private canonical language must be nullified for unauthorized users' );
		$this->assertFalse( $data['is_canonical'] );
	}
}
