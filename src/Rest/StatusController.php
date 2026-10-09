<?php
/**
 * REST API Status Controller.
 *
 * @package TF\Multilingual\Rest
 */

declare( strict_types=1 );

namespace TF\Multilingual\Rest;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\TranslationEditorialService;
use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Term;

/**
 * Class StatusController
 *
 * Exposes REST endpoints for querying logical translation statuses and marking translations as reviewed.
 */
class StatusController extends WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'tf-multilingual/v1';

	/**
	 * Route resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'status';

	/**
	 * Item schema cache.
	 *
	 * @var array<string, mixed>|null
	 */
	protected $schema = null;

	/**
	 * Language registry domain service.
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
	 * Translation status resolver domain service.
	 *
	 * @var TranslationStatusResolver
	 */
	private TranslationStatusResolver $status_resolver;

	/**
	 * Translation editorial application service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $editorial_service;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry            $language_registry Language registry service.
	 * @param TranslationGroupRepository   $group_repository  Translation group repository.
	 * @param TranslationStatusResolver   $status_resolver   Translation status resolver.
	 * @param TranslationEditorialService $editorial_service Editorial application service.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		TranslationGroupRepository $group_repository,
		TranslationStatusResolver $status_resolver,
		TranslationEditorialService $editorial_service
	) {
		$this->language_registry = $language_registry;
		$this->group_repository  = $group_repository;
		$this->status_resolver   = $status_resolver;
		$this->editorial_service = $editorial_service;
	}

	/**
	 * Registers REST API routes for status operations.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Single element status route: GET /tf-multilingual/v1/status/(?P<element_type>post|term)/(?P<id>[\d]+).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<element_type>post|term)/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'element_type' => array(
							'description'       => 'Element type.',
							'type'              => 'string',
							'enum'              => array( 'post', 'term' ),
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'id'           => array(
							'description'       => 'Unique identifier of the WordPress object.',
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Mark reviewed endpoint: POST /tf-multilingual/v1/status/reviewed.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reviewed',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mark_reviewed' ),
					'permission_callback' => array( $this, 'mark_reviewed_permissions_check' ),
					'args'                => array(
						'element_type' => array(
							'description'       => 'Element type.',
							'type'              => 'string',
							'enum'              => array( 'post', 'term' ),
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'element_id'   => array(
							'description'       => 'Element ID to mark as reviewed.',
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * Checks permissions for retrieving status of a specific element.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ): bool|WP_Error {
		$element_type = (string) $request->get_param( 'element_type' );
		$id           = (int) $request->get_param( 'id' );

		if ( 'post' === $element_type ) {
			$post = $this->get_post_entity( $id );
			if ( null === $post ) {
				return new WP_Error(
					'rest_post_invalid_id',
					__( 'Invalid post ID.', 'tf-multilingual' ),
					array( 'status' => 404 )
				);
			}

			if ( 'publish' === $post->post_status ) {
				return true;
			}

			if ( $this->can_user_read_post( $id ) ) {
				return true;
			}

			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view status for this post.', 'tf-multilingual' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( 'term' === $element_type ) {
			$term = $this->get_term_entity( $id );
			if ( null === $term ) {
				return new WP_Error(
					'rest_term_invalid_id',
					__( 'Invalid term ID.', 'tf-multilingual' ),
					array( 'status' => 404 )
				);
			}

			if ( $this->is_taxonomy_public( $term->taxonomy ) ) {
				return true;
			}

			if ( $this->can_user_edit_element( 'term', $id ) ) {
				return true;
			}

			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view status for this term.', 'tf-multilingual' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return new WP_Error(
			'rest_invalid_type',
			__( 'Invalid element type.', 'tf-multilingual' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Retrieves status details for a specific element.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ): WP_REST_Response {
		$element_type = (string) $request->get_param( 'element_type' );
		$id           = (int) ( $request->get_param( 'id' ) ?? $request->get_param( 'element_id' ) );

		$group = $this->group_repository->find_by_element( $element_type, $id );

		if ( null === $group ) {
			return rest_ensure_response(
				array(
					'element_type'         => $element_type,
					'element_id'           => $id,
					'language_code'        => null,
					'status'               => TranslationStatus::UNTRANSLATED,
					'needs_review'         => false,
					'current_version'      => 0,
					'source_version'       => 0,
					'fingerprint'          => '',
					'canonical_element_id' => null,
					'canonical_version'    => null,
				)
			);
		}

		$element = null;
		foreach ( $group->get_elements() as $elem ) {
			if ( $elem->get_element_id() === $id ) {
				$element = $elem;
				break;
			}
		}

		if ( null === $element ) {
			return rest_ensure_response(
				array(
					'element_type'         => $element_type,
					'element_id'           => $id,
					'language_code'        => null,
					'status'               => TranslationStatus::UNTRANSLATED,
					'needs_review'         => false,
					'current_version'      => 0,
					'source_version'       => 0,
					'fingerprint'          => '',
					'canonical_element_id' => $group->get_canonical_element_id(),
					'canonical_version'    => $group->get_canonical_element()?->get_current_content_version(),
				)
			);
		}

		$status            = $this->status_resolver->resolve_element_status( $element, $group );
		$canonical_element = $group->get_canonical_element();

		$data = array(
			'element_type'         => $element_type,
			'element_id'           => $id,
			'language_code'        => $element->get_language_code(),
			'status'               => $status,
			'needs_review'         => ( TranslationStatus::REVIEW === $status ),
			'current_version'      => $element->get_current_content_version(),
			'source_version'       => $element->get_source_version_at_translation(),
			'fingerprint'          => $element->get_translatable_fingerprint(),
			'canonical_element_id' => $group->get_canonical_element_id(),
			'canonical_version'    => null !== $canonical_element ? $canonical_element->get_current_content_version() : null,
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Checks permissions for marking a translation as reviewed.
	 *
	 * Anti-IDOR enforcement: User MUST have edit capability for the specific element.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function mark_reviewed_permissions_check( $request ): bool|WP_Error {
		$element_type = (string) $request->get_param( 'element_type' );
		$element_id   = (int) $request->get_param( 'element_id' );

		if ( $element_id <= 0 ) {
			return new WP_Error(
				'rest_invalid_id',
				__( 'Element ID must be a positive integer.', 'tf-multilingual' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->can_user_edit_element( $element_type, $element_id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to mark this translation as reviewed.', 'tf-multilingual' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Marks a translation element as reviewed against canonical source.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_reviewed( $request ): WP_REST_Response|WP_Error {
		$element_type = (string) $request->get_param( 'element_type' );
		$element_id   = (int) $request->get_param( 'element_id' );

		try {
			$marked = $this->editorial_service->mark_translation_reviewed( $element_type, $element_id );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'rest_mark_reviewed_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		// Return fresh status for the element.
		$request->set_param( 'id', $element_id );
		return $this->get_item( $request );
	}

	/**
	 * Retrieves the schema for status resources.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'translation_status',
			'type'       => 'object',
			'properties' => array(
				'element_type'         => array(
					'description' => 'Element type (post or term).',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'element_id'           => array(
					'description' => 'WordPress object ID.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'language_code'        => array(
					'description' => 'Canonical language code.',
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'status'               => array(
					'description' => 'Logical status (updated, review, untranslated).',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'needs_review'         => array(
					'description' => 'Whether translation needs editorial review.',
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'current_version'      => array(
					'description' => 'Current content version.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'source_version'       => array(
					'description' => 'Source version at last translation/review.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'fingerprint'          => array(
					'description' => 'SHA-256 fingerprint of translatable fields.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'canonical_element_id' => array(
					'description' => 'ID of canonical source element.',
					'type'        => array( 'integer', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'canonical_version'    => array(
					'description' => 'Current version of canonical element.',
					'type'        => array( 'integer', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Resolves a WP_Post safely in both live and mock testing environments.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post|null
	 */
	protected function get_post_entity( int $id ): ?WP_Post {
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $id );
			return $post instanceof WP_Post ? $post : null;
		}

		return $GLOBALS['wp_test_posts'][ $id ] ?? null;
	}

	/**
	 * Resolves a WP_Term safely in both live and mock testing environments.
	 *
	 * @param int $id Term ID.
	 * @return WP_Term|null
	 */
	protected function get_term_entity( int $id ): ?WP_Term {
		if ( function_exists( 'get_term' ) ) {
			$term = get_term( $id );
			return $term instanceof WP_Term ? $term : null;
		}

		return $GLOBALS['wp_test_terms'][ $id ] ?? null;
	}

	/**
	 * Checks whether a taxonomy is public.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	protected function is_taxonomy_public( string $taxonomy ): bool {
		if ( function_exists( 'get_taxonomy' ) ) {
			$tax = get_taxonomy( $taxonomy );
			return $tax ? (bool) $tax->public : true;
		}

		return true;
	}

	/**
	 * Checks whether the current user can read a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function can_user_read_post( int $post_id ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return true;
		}

		return current_user_can( 'read_post', $post_id ) || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Checks whether the current user has edit capability for an element.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   Object ID.
	 * @return bool
	 */
	protected function can_user_edit_element( string $element_type, int $element_id ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return true;
		}

		$cap = ( 'post' === $element_type ) ? 'edit_post' : 'edit_term';

		return current_user_can( $cap, $element_id );
	}
}
