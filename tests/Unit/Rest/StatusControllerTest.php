<?php
/**
 * REST Status Controller Unit Tests.
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
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Rest\StatusController;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class StatusControllerTest
 */
class StatusControllerTest extends TestCase {

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
	 * Status resolver.
	 *
	 * @var TranslationStatusResolver
	 */
	private TranslationStatusResolver $status_resolver;

	/**
	 * Editorial service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $editorial_service;

	/**
	 * Status controller under test.
	 *
	 * @var StatusController
	 */
	private StatusController $controller;

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

		$this->controller = new StatusController(
			$this->language_registry,
			$this->group_repository,
			$this->status_resolver,
			$this->editorial_service
		);

		$GLOBALS['wp_test_posts'] = array();
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

		$this->assertArrayHasKey( '/status/(?P<element_type>post|term)/(?P<id>[\\d]+)', $routes );
		$this->assertArrayHasKey( '/status/reviewed', $routes );
	}

	/**
	 * Tests get_item_permissions_check on draft post without capabilities returns 401/403.
	 */
	public function test_get_item_permissions_check_draft_post(): void {
		$post                          = new WP_Post();
		$post->ID                      = 101;
		$post->post_status             = 'draft';
		$GLOBALS['wp_test_posts'][101] = $post;

		$GLOBALS['wp_test_caps']['read_post'] = false;
		$GLOBALS['wp_test_caps']['edit_post'] = false;

		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/status/post/101' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 101 );

		$result = $this->controller->get_item_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Tests get_item for an unassigned element returns untranslated status.
	 */
	public function test_get_item_unassigned_returns_untranslated(): void {
		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/status/post/101' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 101 );

		$response = $this->controller->get_item( $request );
		$data     = $response->get_data();

		$this->assertSame( 'post', $data['element_type'] );
		$this->assertSame( 101, $data['element_id'] );
		$this->assertSame( TranslationStatus::UNTRANSLATED, $data['status'] );
		$this->assertFalse( $data['needs_review'] );
		$this->assertSame( 0, $data['current_version'] );
	}

	/**
	 * Tests get_item for an assigned translation needing review.
	 */
	public function test_get_item_assigned_needing_review(): void {
		$group_id                      = 50;
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

		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/status/post/102' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'id', 102 );

		$response = $this->controller->get_item( $request );
		$data     = $response->get_data();

		$this->assertSame( 'post', $data['element_type'] );
		$this->assertSame( 102, $data['element_id'] );
		$this->assertSame( 'en', $data['language_code'] );
		$this->assertSame( TranslationStatus::REVIEW, $data['status'] );
		$this->assertTrue( $data['needs_review'] );
		$this->assertSame( 1, $data['current_version'] );
		$this->assertSame( 1, $data['source_version'] );
		$this->assertSame( 101, $data['canonical_element_id'] );
		$this->assertSame( 2, $data['canonical_version'] );
	}

	/**
	 * Tests mark_reviewed_permissions_check enforcement.
	 */
	public function test_mark_reviewed_permissions_check(): void {
		$request = new WP_REST_Request( 'POST', '/tf-multilingual/v1/status/reviewed' );
		$request->set_param( 'element_type', 'post' );
		$request->set_param( 'element_id', 102 );

		$GLOBALS['wp_test_caps']['edit_post:102'] = false;
		$result                                   = $this->controller->mark_reviewed_permissions_check( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );

		$GLOBALS['wp_test_caps']['edit_post:102'] = true;
		$this->assertTrue( $this->controller->mark_reviewed_permissions_check( $request ) );
	}

	/**
	 * Tests schema retrieval.
	 */
	public function test_get_item_schema(): void {
		$schema = $this->controller->get_item_schema();
		$this->assertSame( 'translation_status', $schema['title'] );
		$this->assertArrayHasKey( 'status', $schema['properties'] );
		$this->assertArrayHasKey( 'needs_review', $schema['properties'] );
		$this->assertArrayHasKey( 'current_version', $schema['properties'] );
		$this->assertArrayHasKey( 'source_version', $schema['properties'] );
	}
}
