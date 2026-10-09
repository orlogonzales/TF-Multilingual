<?php
/**
 * REST API Translations Controller.
 *
 * @package TF\Multilingual\Rest
 */

declare( strict_types=1 );

namespace TF\Multilingual\Rest;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
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
 * Class TranslationsController
 *
 * Exposes REST endpoints for querying, linking, and unlinking multilingual translations.
 */
class TranslationsController extends WP_REST_Controller {

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
	protected $rest_base = 'translations';

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
	 * Content translation resolver domain service.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $translation_resolver;

	/**
	 * Translation editorial application service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $editorial_service;

	/**
	 * Translation status resolver domain service.
	 *
	 * @var TranslationStatusResolver
	 */
	private TranslationStatusResolver $status_resolver;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry            $language_registry    Language registry service.
	 * @param TranslationGroupRepository   $group_repository     Translation group repository.
	 * @param ContentTranslationResolver  $translation_resolver Content translation resolver.
	 * @param TranslationEditorialService $editorial_service    Editorial application service.
	 * @param TranslationStatusResolver   $status_resolver       Translation status resolver.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		TranslationGroupRepository $group_repository,
		ContentTranslationResolver $translation_resolver,
		TranslationEditorialService $editorial_service,
		TranslationStatusResolver $status_resolver
	) {
		$this->language_registry    = $language_registry;
		$this->group_repository     = $group_repository;
		$this->translation_resolver = $translation_resolver;
		$this->editorial_service    = $editorial_service;
		$this->status_resolver      = $status_resolver;
	}

	/**
	 * Registers REST API routes for translations.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Collection route: GET /tf-multilingual/v1/translations.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'element_type' => array(
							'description'       => 'Element type to list.',
							'type'              => 'string',
							'enum'              => array( 'post', 'term' ),
							'default'           => 'post',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'subtype'      => array(
							'description'       => 'Optional subtype (post type or taxonomy).',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'page'         => array(
							'description'       => 'Current page of the collection.',
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page'     => array(
							'description'       => 'Maximum number of items to return in result set.',
							'type'              => 'integer',
							'default'           => 10,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Single element route: GET /tf-multilingual/v1/translations/(?P<element_type>post|term)/(?P<id>[\d]+).
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

		// Link endpoint: POST /tf-multilingual/v1/translations/link.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/link',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'link_translation' ),
					'permission_callback' => array( $this, 'link_permissions_check' ),
					'args'                => array(
						'element_type'    => array(
							'description'       => 'Element type.',
							'type'              => 'string',
							'enum'              => array( 'post', 'term' ),
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'source_id'       => array(
							'description'       => 'Source element ID.',
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'target_id'       => array(
							'description'       => 'Target element ID to link into group.',
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'target_language' => array(
							'description'       => 'Target language code for the linked element.',
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'source_language' => array(
							'description'       => 'Optional source language code if source is unassigned.',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Unlink endpoint: POST /tf-multilingual/v1/translations/unlink.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/unlink',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'unlink_translation' ),
					'permission_callback' => array( $this, 'unlink_permissions_check' ),
					'args'                => array(
						'element_type'     => array(
							'description'       => 'Element type.',
							'type'              => 'string',
							'enum'              => array( 'post', 'term' ),
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'element_id'       => array(
							'description'       => 'Element ID to unlink from its translation group.',
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'new_canonical_id' => array(
							'description'       => 'Replacement canonical element ID if unlinking canonical.',
							'type'              => 'integer',
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
	 * Checks permissions for retrieving collection of translation groups.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function get_items_permissions_check( $request ): bool {
		return true;
	}

	/**
	 * Retrieves paginated list of translation groups.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$element_type = (string) $request->get_param( 'element_type' );
		$subtype      = (string) ( $request->get_param( 'subtype' ) ?? '' );
		$page         = (int) ( $request->get_param( 'page' ) ?? 1 );
		$per_page     = (int) ( $request->get_param( 'per_page' ) ?? 10 );

		$paginated = $this->group_repository->paginate_groups(
			$element_type,
			'' !== $subtype ? $subtype : null,
			$page,
			$per_page
		);

		$data = array();
		foreach ( $paginated['items'] as $group ) {
			$canonical_id = $group->get_canonical_element_id() ?? 0;
			$data[]       = $this->format_group_data( $group, $canonical_id );
		}

		$response = rest_ensure_response( $data );
		$response->header( 'X-WP-Total', (string) $paginated['total'] );
		$response->header( 'X-WP-TotalPages', (string) $paginated['total_pages'] );

		return $response;
	}

	/**
	 * Checks permissions for retrieving translation details of a specific element.
	 *
	 * Enforces anti-IDOR: private/draft posts require read/edit capabilities.
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
				__( 'Sorry, you are not allowed to view translations for this post.', 'tf-multilingual' ),
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
				__( 'Sorry, you are not allowed to view translations for this term.', 'tf-multilingual' ),
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
	 * Retrieves translation details for a specific WordPress object.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ): WP_REST_Response {
		$element_type = (string) $request->get_param( 'element_type' );
		$id           = (int) $request->get_param( 'id' );

		$group = $this->group_repository->find_by_element( $element_type, $id );

		if ( null === $group ) {
			$subtype = 'post';
			if ( 'post' === $element_type ) {
				$post = $this->get_post_entity( $id );
				if ( null !== $post ) {
					$subtype = $post->post_type;
				}
			} elseif ( 'term' === $element_type ) {
				$term = $this->get_term_entity( $id );
				if ( null !== $term ) {
					$subtype = $term->taxonomy;
				}
			}

			$all_active = array_keys( $this->language_registry->active() );
			$unassigned = array(
				'group_id'               => null,
				'element_type'           => $element_type,
				'element_id'             => $id,
				'subtype'                => $subtype,
				'language_code'          => null,
				'is_canonical'           => false,
				'canonical_element_id'   => null,
				'canonical_language'     => null,
				'translations'           => (object) array(),
				'untranslated_languages' => $all_active,
			);

			return rest_ensure_response( $unassigned );
		}

		$data = $this->format_group_data( $group, $id );

		return rest_ensure_response( $data );
	}

	/**
	 * Checks permissions for linking translations.
	 *
	 * Anti-IDOR enforcement: User MUST have edit permissions for BOTH source and target elements.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function link_permissions_check( $request ): bool|WP_Error {
		$element_type = (string) $request->get_param( 'element_type' );
		$source_id    = (int) $request->get_param( 'source_id' );
		$target_id    = (int) $request->get_param( 'target_id' );

		if ( $source_id <= 0 || $target_id <= 0 ) {
			return new WP_Error(
				'rest_invalid_ids',
				__( 'Source and target IDs must be positive integers.', 'tf-multilingual' ),
				array( 'status' => 400 )
			);
		}

		$can_edit_source = $this->can_user_edit_element( $element_type, $source_id );
		$can_edit_target = $this->can_user_edit_element( $element_type, $target_id );

		if ( ! $can_edit_source || ! $can_edit_target ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to link these translation elements.', 'tf-multilingual' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Links two existing WordPress elements into a translation group.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function link_translation( $request ): WP_REST_Response|WP_Error {
		$element_type    = (string) $request->get_param( 'element_type' );
		$source_id       = (int) $request->get_param( 'source_id' );
		$target_id       = (int) $request->get_param( 'target_id' );
		$target_language = Language::normalize_code( (string) $request->get_param( 'target_language' ) );
		$source_language = (string) ( $request->get_param( 'source_language' ) ?? '' );

		if ( $source_id === $target_id ) {
			return new WP_Error(
				'rest_link_identical',
				__( 'Source and target elements cannot be identical.', 'tf-multilingual' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->language_registry->is_active( $target_language ) ) {
			return new WP_Error(
				'rest_invalid_language',
				__( 'Target language is not registered or not active.', 'tf-multilingual' ),
				array( 'status' => 400 )
			);
		}

		// Ensure elements exist and share subtype.
		$source_subtype = '';
		$target_subtype = '';

		if ( 'post' === $element_type ) {
			$source_post = $this->get_post_entity( $source_id );
			$target_post = $this->get_post_entity( $target_id );

			if ( null === $source_post || null === $target_post ) {
				return new WP_Error(
					'rest_post_not_found',
					__( 'One or both posts do not exist.', 'tf-multilingual' ),
					array( 'status' => 404 )
				);
			}

			$source_subtype = $source_post->post_type;
			$target_subtype = $target_post->post_type;
		} elseif ( 'term' === $element_type ) {
			$source_term = $this->get_term_entity( $source_id );
			$target_term = $this->get_term_entity( $target_id );

			if ( null === $source_term || null === $target_term ) {
				return new WP_Error(
					'rest_term_not_found',
					__( 'One or both terms do not exist.', 'tf-multilingual' ),
					array( 'status' => 404 )
				);
			}

			$source_subtype = $source_term->taxonomy;
			$target_subtype = $target_term->taxonomy;
		}

		if ( $source_subtype !== $target_subtype ) {
			return new WP_Error(
				'rest_subtype_mismatch',
				__( 'Source and target elements must belong to the exact same subtype.', 'tf-multilingual' ),
				array( 'status' => 400 )
			);
		}

		// Validate target is not already assigned to any translation group.
		$target_existing_group = $this->group_repository->find_by_element( $element_type, $target_id );
		if ( null !== $target_existing_group ) {
			return new WP_Error(
				'rest_target_already_linked',
				__( 'Target element is already assigned to a translation group.', 'tf-multilingual' ),
				array( 'status' => 409 )
			);
		}

		// Find or assign source group.
		$source_group = $this->group_repository->find_by_element( $element_type, $source_id );
		if ( null === $source_group ) {
			$initial_lang = '' !== $source_language ? Language::normalize_code( $source_language ) : ( $this->language_registry->get_default()?->get_code() ?? 'es' );
			try {
				$source_group = $this->editorial_service->assign_initial_language(
					$element_type,
					$source_id,
					$source_subtype,
					$initial_lang
				);
			} catch ( \Throwable $e ) {
				return new WP_Error(
					'rest_group_creation_failed',
					$e->getMessage(),
					array( 'status' => 500 )
				);
			}
		}

		if ( $source_group->has_translation( $target_language ) ) {
			return new WP_Error(
				'rest_language_duplicate',
				__( 'The translation group already contains a translation for this language.', 'tf-multilingual' ),
				array( 'status' => 409 )
			);
		}

		$canonical_element = $source_group->get_canonical_element();
		$source_version    = null !== $canonical_element ? $canonical_element->get_current_content_version() : 1;

		$fp_service  = $this->editorial_service->get_fingerprint_service();
		$fingerprint = '';
		if ( 'post' === $element_type ) {
			$fingerprint = $fp_service->calculate_post_fingerprint( $target_id );
		} else {
			$fingerprint = $fp_service->calculate_term_fingerprint( $target_id, $target_subtype );
		}

		try {
			$this->group_repository->add_translation(
				(int) $source_group->get_id(),
				$target_id,
				$target_language,
				false,
				$source_version,
				1,
				$fingerprint
			);

			$this->translation_resolver->flush_cache();
			$this->editorial_service->clear_editorial_cache();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'rest_link_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		// Reload fresh group.
		$updated_group = $this->group_repository->find( (int) $source_group->get_id() );
		$data          = null !== $updated_group ? $this->format_group_data( $updated_group, $source_id ) : array();

		return rest_ensure_response( $data );
	}

	/**
	 * Checks permissions for unlinking a translation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function unlink_permissions_check( $request ): bool|WP_Error {
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
				__( 'Sorry, you are not allowed to unlink this translation element.', 'tf-multilingual' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Unlinks an element from its translation group.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unlink_translation( $request ): WP_REST_Response|WP_Error {
		$element_type     = (string) $request->get_param( 'element_type' );
		$element_id       = (int) $request->get_param( 'element_id' );
		$new_canonical_id = $request->get_param( 'new_canonical_id' ) ? (int) $request->get_param( 'new_canonical_id' ) : null;

		$group = $this->group_repository->find_by_element( $element_type, $element_id );
		if ( null === $group ) {
			return new WP_Error(
				'rest_not_in_group',
				__( 'Element is not assigned to any translation group.', 'tf-multilingual' ),
				array( 'status' => 404 )
			);
		}

		// Locate element language in group.
		$element_lang = null;
		foreach ( $group->get_elements() as $elem ) {
			if ( $elem->get_element_id() === $element_id ) {
				$element_lang = $elem->get_language_code();
				break;
			}
		}

		if ( null === $element_lang ) {
			return new WP_Error(
				'rest_element_not_found_in_group',
				__( 'Element not found in translation group.', 'tf-multilingual' ),
				array( 'status' => 404 )
			);
		}

		try {
			$success = $this->group_repository->remove_translation(
				(int) $group->get_id(),
				$element_lang,
				$new_canonical_id
			);

			$this->translation_resolver->flush_cache();
			$this->editorial_service->clear_editorial_cache();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'rest_unlink_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'     => $success,
				'unlinked_id' => $element_id,
				'group_id'    => (int) $group->get_id(),
			)
		);
	}

	/**
	 * Formats a TranslationGroup entity into a standardized REST response array.
	 *
	 * @param TranslationGroup $group        Translation group entity.
	 * @param int              $reference_id ID of reference element for context.
	 * @return array<string, mixed>
	 */
	public function format_group_data( TranslationGroup $group, int $reference_id ): array {
		$canonical_element = $group->get_canonical_element();
		$canonical_lang    = null !== $canonical_element ? $canonical_element->get_language_code() : null;
		$canonical_id      = $group->get_canonical_element_id();
		$elements          = $group->get_elements();
		$element_type      = $group->get_element_type();
		$subtype           = $group->get_subtype();

		$ref_lang = null;
		foreach ( $elements as $elem ) {
			if ( $elem->get_element_id() === $reference_id ) {
				$ref_lang = $elem->get_language_code();
				break;
			}
		}

		$translations = array();
		foreach ( $elements as $elem ) {
			$elem_id   = $elem->get_element_id();
			$elem_lang = $elem->get_language_code();
			$is_canon  = ( $elem_id === $canonical_id );
			$status    = $this->status_resolver->resolve_element_status( $elem, $group );

			$can_edit = $this->can_user_edit_element( $element_type, $elem_id );
			$edit_url = $can_edit ? admin_url( $this->editorial_service->get_edit_url( $element_type, $elem_id, $subtype ) ) : '';

			$translations[ $elem_lang ] = array(
				'element_id'      => $elem_id,
				'language_code'   => $elem_lang,
				'is_canonical'    => $is_canon,
				'status'          => $status,
				'source_version'  => $elem->get_source_version_at_translation(),
				'current_version' => $elem->get_current_content_version(),
				'fingerprint'     => $elem->get_translatable_fingerprint(),
				'permalink'       => $this->get_element_permalink( $element_type, $elem_id ),
				'edit_url'        => $edit_url,
			);
		}

		$all_active   = array_keys( $this->language_registry->active() );
		$untranslated = array_values( array_diff( $all_active, array_keys( $translations ) ) );

		return array(
			'group_id'               => (int) $group->get_id(),
			'element_type'           => $element_type,
			'element_id'             => $reference_id,
			'subtype'                => $subtype,
			'language_code'          => $ref_lang,
			'is_canonical'           => ( $reference_id === $canonical_id ),
			'canonical_element_id'   => $canonical_id,
			'canonical_language'     => $canonical_lang,
			'translations'           => $translations,
			'untranslated_languages' => $untranslated,
		);
	}

	/**
	 * Retrieves the schema for translation group resources.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'translation_group',
			'type'       => 'object',
			'properties' => array(
				'group_id'               => array(
					'description' => 'Unique ID of the translation group.',
					'type'        => array( 'integer', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'element_type'           => array(
					'description' => 'Element type (post or term).',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'element_id'             => array(
					'description' => 'WordPress object ID.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'subtype'                => array(
					'description' => 'Post type or taxonomy.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'language_code'          => array(
					'description' => 'Assigned language code of the requested element.',
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'is_canonical'           => array(
					'description' => 'Whether requested element is canonical source.',
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'canonical_element_id'   => array(
					'description' => 'ID of the canonical element in group.',
					'type'        => array( 'integer', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'canonical_language'     => array(
					'description' => 'Language code of the canonical element.',
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'translations'           => array(
					'description' => 'Member translations indexed by language code.',
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'untranslated_languages' => array(
					'description' => 'Active language codes with no translation in this group.',
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
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

	/**
	 * Computes permalink for a WordPress object.
	 *
	 * @param string $element_type Element type.
	 * @param int    $element_id   Object ID.
	 * @return string
	 */
	protected function get_element_permalink( string $element_type, int $element_id ): string {
		if ( 'post' === $element_type ) {
			$link = function_exists( 'get_permalink' ) ? get_permalink( $element_id ) : '';
			return is_string( $link ) ? $link : '';
		}

		if ( 'term' === $element_type ) {
			$link = function_exists( 'get_term_link' ) ? get_term_link( $element_id ) : '';
			return is_string( $link ) ? $link : '';
		}

		return '';
	}
}
