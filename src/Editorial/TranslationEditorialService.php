<?php
/**
 * Translation Editorial Service.
 *
 * @package TF\Multilingual\Editorial
 */

declare( strict_types=1 );

namespace TF\Multilingual\Editorial;

use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Domain\Versioning\TranslatableFingerprint;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\Exceptions\EditorialConflictException;
use TF\Multilingual\Editorial\Exceptions\EditorialPermissionException;
use TF\Multilingual\Editorial\Exceptions\EditorialValidationException;
use WP_Post;
use WP_Term;
use wpdb;

/**
 * Class TranslationEditorialService
 *
 * Application service orchestrating editorial translation workflows in WordPress Admin.
 * Handles language assignment, translation creation, and editorial state aggregation
 * while strictly enforcing Core capabilities, non-cloning policies, and domain invariants.
 */
class TranslationEditorialService {

	/**
	 * Disallowed post types for multilingual translation.
	 */
	public const DISALLOWED_POST_TYPES = array(
		'revision',
		'attachment',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
	);

	/**
	 * Disallowed taxonomies for multilingual translation.
	 */
	public const DISALLOWED_TAXONOMIES = array(
		'nav_menu',
		'link_category',
		'post_format',
		'wp_theme',
		'wp_template_part_area',
	);

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
	 * Content translation resolver service.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $translation_resolver;

	/**
	 * WordPress element validator service.
	 *
	 * @var WordPressElementValidator
	 */
	private WordPressElementValidator $element_validator;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * In-memory runtime cache for aggregated editorial data.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $editorial_cache = array();

	/**
	 * Custom field policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $custom_field_policy_registry;

	/**
	 * Translation status resolver domain service.
	 *
	 * @var TranslationStatusResolver
	 */
	private TranslationStatusResolver $status_resolver;

	/**
	 * Translatable content fingerprint service.
	 *
	 * @var TranslatableFingerprint
	 */
	private TranslatableFingerprint $fingerprint_service;

	/**
	 * Sync lock recursion guard.
	 *
	 * @var array<int>
	 */
	private array $syncing_elements = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry|null           $language_registry             Language registry.
	 * @param TranslationGroupRepository|null $group_repository              Translation group repository.
	 * @param ContentTranslationResolver|null $translation_resolver          Content translation resolver.
	 * @param WordPressElementValidator|null  $element_validator             Element validator.
	 * @param wpdb|null                       $db                            WordPress database instance.
	 * @param CustomFieldPolicyRegistry|null  $custom_field_policy_registry  Custom field policy registry.
	 * @param TranslationStatusResolver|null  $status_resolver               Translation status resolver.
	 * @param TranslatableFingerprint|null    $fingerprint_service           Fingerprint service.
	 */
	public function __construct(
		?LanguageRegistry $language_registry = null,
		?TranslationGroupRepository $group_repository = null,
		?ContentTranslationResolver $translation_resolver = null,
		?WordPressElementValidator $element_validator = null,
		?wpdb $db = null,
		?CustomFieldPolicyRegistry $custom_field_policy_registry = null,
		?TranslationStatusResolver $status_resolver = null,
		?TranslatableFingerprint $fingerprint_service = null
	) {
		global $wpdb;

		$this->language_registry            = null !== $language_registry ? $language_registry : new LanguageRegistry();
		$this->group_repository             = null !== $group_repository ? $group_repository : new TranslationGroupRepository();
		$this->translation_resolver         = null !== $translation_resolver ? $translation_resolver : new ContentTranslationResolver( $this->group_repository, $this->language_registry );
		$this->element_validator            = null !== $element_validator ? $element_validator : new WordPressElementValidator();
		$this->db                           = null !== $db ? $db : $wpdb;
		$this->custom_field_policy_registry = null !== $custom_field_policy_registry ? $custom_field_policy_registry : new CustomFieldPolicyRegistry();
		$this->status_resolver              = null !== $status_resolver ? $status_resolver : new TranslationStatusResolver( $this->group_repository, $this->language_registry );
		$this->fingerprint_service          = null !== $fingerprint_service ? $fingerprint_service : new TranslatableFingerprint( $this->custom_field_policy_registry );
	}

	/**
	 * Gets the translation status resolver.
	 *
	 * @return TranslationStatusResolver
	 */
	public function get_status_resolver(): TranslationStatusResolver {
		return $this->status_resolver;
	}

	/**
	 * Gets the translatable fingerprint service.
	 *
	 * @return TranslatableFingerprint
	 */
	public function get_fingerprint_service(): TranslatableFingerprint {
		return $this->fingerprint_service;
	}

	/**
	 * Checks whether a post type is eligible for translation.
	 *
	 * @param string $post_type Post type name.
	 * @return bool True if eligible.
	 */
	public function is_supported_post_type( string $post_type ): bool {
		$normalized = strtolower( trim( $post_type ) );
		if ( '' === $normalized || in_array( $normalized, self::DISALLOWED_POST_TYPES, true ) ) {
			return false;
		}

		if ( function_exists( 'post_type_exists' ) ) {
			return post_type_exists( $normalized );
		}

		if ( isset( $GLOBALS['wp_test_post_types'] ) && is_array( $GLOBALS['wp_test_post_types'] ) ) {
			return in_array( $normalized, $GLOBALS['wp_test_post_types'], true );
		}

		return in_array( $normalized, array( 'post', 'page', 'tour' ), true );
	}

	/**
	 * Checks whether a taxonomy is eligible for translation.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool True if eligible.
	 */
	public function is_supported_taxonomy( string $taxonomy ): bool {
		$normalized = strtolower( trim( $taxonomy ) );
		if ( '' === $normalized || in_array( $normalized, self::DISALLOWED_TAXONOMIES, true ) ) {
			return false;
		}

		if ( function_exists( 'taxonomy_exists' ) ) {
			return taxonomy_exists( $normalized );
		}

		if ( isset( $GLOBALS['wp_test_taxonomies'] ) && is_array( $GLOBALS['wp_test_taxonomies'] ) ) {
			return in_array( $normalized, $GLOBALS['wp_test_taxonomies'], true );
		}

		return in_array( $normalized, array( 'category', 'post_tag', 'tour_type' ), true );
	}

	/**
	 * Retrieves aggregated editorial translation data for an entity.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @param string $subtype      Subtype (post_type or taxonomy).
	 * @return array<string, mixed> Structured editorial data.
	 */
	public function get_editorial_data( string $element_type, int $element_id, string $subtype ): array {
		$cache_key = "{$element_type}:{$element_id}:{$subtype}";
		if ( array_key_exists( $cache_key, $this->editorial_cache ) ) {
			return $this->editorial_cache[ $cache_key ];
		}

		if ( ! $this->language_registry->is_configured() ) {
			$unconfigured                        = array(
				'is_configured'         => false,
				'is_managed'            => false,
				'current_language'      => null,
				'current_language_name' => null,
				'group_id'              => null,
				'active_languages'      => array(),
				'translations'          => array(),
				'missing_languages'     => array(),
				'inactive_translations' => array(),
			);
			$this->editorial_cache[ $cache_key ] = $unconfigured;
			return $unconfigured;
		}

		$active_langs = $this->language_registry->active();
		$group        = ( $element_id > 0 ) ? $this->translation_resolver->get_group_for_element( $element_type, $element_id ) : null;

		$is_managed            = ( null !== $group && $group->get_subtype() === $subtype );
		$current_language      = null;
		$current_language_name = null;
		$translations          = array();
		$missing_languages     = array();
		$inactive_translations = array();

		if ( $is_managed && null !== $group ) {
			$group_elements = $group->get_elements();

			foreach ( $group_elements as $code => $element ) {
				$lang_obj  = $this->language_registry->get( $code );
				$lang_name = null !== $lang_obj ? $lang_obj->get_name() : $code;
				$is_active = null !== $lang_obj && $lang_obj->is_active();
				$edit_url  = $this->get_edit_url( $element_type, $element->get_element_id(), $subtype );

				if ( $element->get_element_id() === $element_id ) {
					$current_language      = $code;
					$current_language_name = $lang_name;
				}

				$status = $this->status_resolver->resolve_element_status( $element, $group );

				$trans_info = array(
					'element_id'      => $element->get_element_id(),
					'language_code'   => $code,
					'language_name'   => $lang_name,
					'is_current'      => ( $element->get_element_id() === $element_id ),
					'is_active'       => $is_active,
					'edit_url'        => $edit_url,
					'status'          => $status,
					'source_version'  => $element->get_source_version_at_translation(),
					'current_version' => $element->get_current_content_version(),
					'is_source'       => ( $element->get_element_id() === $group->get_canonical_element_id() ),
				);

				if ( $is_active ) {
					$translations[ $code ] = $trans_info;
				} else {
					$inactive_translations[ $code ] = $trans_info;
				}
			}

			// Determine missing active languages.
			foreach ( $active_langs as $code => $lang ) {
				if ( ! isset( $translations[ $code ] ) ) {
					$missing_languages[ $code ] = array(
						'language_code' => $code,
						'language_name' => $lang->get_name(),
						'status'        => TranslationStatus::UNTRANSLATED,
					);
				}
			}
		} else {
			// Unmanaged object: all active languages are missing from the object's perspective.
			foreach ( $active_langs as $code => $lang ) {
				$missing_languages[ $code ] = array(
					'language_code' => $code,
					'language_name' => $lang->get_name(),
					'status'        => TranslationStatus::UNTRANSLATED,
				);
			}
		}

		$data = array(
			'is_configured'         => true,
			'is_managed'            => $is_managed,
			'current_language'      => $current_language,
			'current_language_name' => $current_language_name,
			'group_id'              => null !== $group ? $group->get_id() : null,
			'active_languages'      => $active_langs,
			'translations'          => $translations,
			'missing_languages'     => $missing_languages,
			'inactive_translations' => $inactive_translations,
		);

		$this->editorial_cache[ $cache_key ] = $data;

		return $data;
	}

	/**
	 * Retrieves editorial data for multiple elements in batch.
	 *
	 * Pre-warms both the database resolver cache and the editorial data cache in O(1) queries.
	 *
	 * @param string     $element_type Element type ('post' or 'term').
	 * @param array<int> $element_ids  Array of WordPress object IDs.
	 * @param string     $subtype      Subtype (post_type or taxonomy).
	 * @return array<int, array<string, mixed>> Map of element_id => editorial data array.
	 */
	public function get_editorial_data_for_elements( string $element_type, array $element_ids, string $subtype ): array {
		$valid_ids = array_values( array_unique( array_filter( array_map( 'intval', $element_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $valid_ids ) ) {
			return array();
		}

		if ( ! $this->language_registry->is_configured() ) {
			$unconfigured = array();
			foreach ( $valid_ids as $id ) {
				$unconfigured[ $id ] = $this->get_editorial_data( $element_type, $id, $subtype );
			}
			return $unconfigured;
		}

		// Find which IDs are not yet in editorial cache.
		$uncached_ids = array();
		foreach ( $valid_ids as $id ) {
			$key = "{$element_type}:{$id}:{$subtype}";
			if ( ! array_key_exists( $key, $this->editorial_cache ) ) {
				$uncached_ids[] = $id;
			}
		}

		if ( ! empty( $uncached_ids ) ) {
			// Batch fetch groups for uncached elements.
			$groups_by_element = $this->group_repository->find_by_elements( $element_type, $uncached_ids );

			// Prime the resolver cache with groups found.
			foreach ( $groups_by_element as $el_id => $group ) {
				$this->translation_resolver->prime_cache( $group );
			}

			// For elements not having a group, prime resolver cache as empty.
			foreach ( $uncached_ids as $el_id ) {
				if ( ! isset( $groups_by_element[ $el_id ] ) ) {
					$this->translation_resolver->prime_empty( $element_type, $el_id );
				}
			}

			// Populate editorial cache for each uncached element.
			foreach ( $uncached_ids as $el_id ) {
				$this->get_editorial_data( $element_type, $el_id, $subtype );
			}
		}

		// Collect results.
		$result = array();
		foreach ( $valid_ids as $id ) {
			$key           = "{$element_type}:{$id}:{$subtype}";
			$result[ $id ] = $this->editorial_cache[ $key ];
		}

		return $result;
	}

	/**
	 * Clears the in-memory editorial data cache.
	 *
	 * @return void
	 */
	public function clear_editorial_cache(): void {
		$this->editorial_cache = array();
	}

	/**
	 * Explicitly assigns an initial language to an unmanaged object and creates its TranslationGroup.
	 *
	 * @param string $element_type  Element type ('post' or 'term').
	 * @param int    $element_id    WordPress object ID.
	 * @param string $subtype       Subtype (post_type or taxonomy).
	 * @param string $language_code Target canonical language code.
	 * @return TranslationGroup The newly created translation group.
	 * @throws EditorialValidationException If validation fails.
	 * @throws EditorialPermissionException If user lacks capabilities.
	 * @throws EditorialConflictException If element is already managed.
	 */
	public function assign_initial_language( string $element_type, int $element_id, string $subtype, string $language_code ): TranslationGroup {
		if ( ! $this->language_registry->is_configured() ) {
			throw EditorialValidationException::not_configured();
		}

		$normalized_type = strtolower( trim( $element_type ) );
		if ( ! in_array( $normalized_type, array( 'post', 'term' ), true ) ) {
			throw new EditorialValidationException( sprintf( 'Invalid element type "%s".', $element_type ) );
		}

		if ( $element_id <= 0 ) {
			throw new EditorialValidationException( 'Invalid element ID.' );
		}

		// Validate user permission.
		if ( 'post' === $normalized_type ) {
			if ( ! $this->is_supported_post_type( $subtype ) ) {
				throw EditorialValidationException::unsupported_post_type( $subtype );
			}
			if ( ! $this->user_can( 'edit_post', $element_id ) ) {
				throw EditorialPermissionException::cannot_edit_post( $element_id );
			}
		} else {
			if ( ! $this->is_supported_taxonomy( $subtype ) ) {
				throw EditorialValidationException::invalid_taxonomy( $subtype );
			}
			$tax_obj = function_exists( 'get_taxonomy' ) ? get_taxonomy( $subtype ) : null;
			$cap     = ( $tax_obj && isset( $tax_obj->cap->edit_terms ) ) ? $tax_obj->cap->edit_terms : 'edit_terms';
			if ( ! $this->user_can( $cap ) ) {
				throw EditorialPermissionException::cannot_edit_terms( $subtype );
			}
		}

		// Validate language code is active.
		$canonical_lang = Language::normalize_code( $language_code );
		if ( ! $this->language_registry->is_active( $canonical_lang ) ) {
			throw EditorialValidationException::inactive_or_invalid_language( $canonical_lang );
		}

		// Ensure object exists in WordPress.
		if ( 'post' === $normalized_type ) {
			$post = $this->find_post( $element_id );
			if ( ! $post instanceof WP_Post || ! $this->element_validator->exists( $normalized_type, $subtype, $element_id ) ) {
				throw EditorialValidationException::invalid_post( $element_id );
			}
		} else {
			$term = $this->find_term( $element_id, $subtype );
			if ( ! $term instanceof WP_Term || ! $this->element_validator->exists( $normalized_type, $subtype, $element_id ) ) {
				throw EditorialValidationException::invalid_term( $element_id );
			}
		}

		// Check if already managed.
		$existing_group = $this->translation_resolver->get_group_for_element( $normalized_type, $element_id );
		if ( null !== $existing_group ) {
			throw EditorialConflictException::already_managed( $normalized_type, $element_id );
		}

		// Calculate initial fingerprint for canonical element.
		$initial_fingerprint = ( 'post' === $normalized_type )
			? $this->fingerprint_service->calculate_post_fingerprint( $element_id )
			: $this->fingerprint_service->calculate_term_fingerprint( $element_id, $subtype );

		// Create group with canonical designation.
		$group = $this->group_repository->create_group(
			$normalized_type,
			$subtype,
			$element_id,
			$canonical_lang,
			true,
			$initial_fingerprint
		);

		$this->translation_resolver->flush_cache();
		$this->clear_editorial_cache();

		return $group;
	}

	/**
	 * Creates a new translation post in the target language and links it to the source group.
	 *
	 * Enforces:
	 * - Strict DRAFT status.
	 * - Zero cloning of postmeta, taxonomies, or attachments.
	 * - Core capability checks for editing source and creating target post type.
	 * - Concurrency conflict prevention.
	 *
	 * @param int    $source_post_id       Source post ID.
	 * @param string $target_language_code Target active language code.
	 * @return int The ID of the newly created translation post.
	 * @throws EditorialValidationException If validation fails.
	 * @throws EditorialPermissionException If permissions are lacking.
	 * @throws EditorialConflictException If translation already exists.
	 */
	public function create_post_translation( int $source_post_id, string $target_language_code ): int {
		if ( ! $this->language_registry->is_configured() ) {
			throw EditorialValidationException::not_configured();
		}

		if ( $source_post_id <= 0 ) {
			throw EditorialValidationException::invalid_post( $source_post_id );
		}

		// Validate user permission on source post.
		if ( ! $this->user_can( 'edit_post', $source_post_id ) ) {
			throw EditorialPermissionException::cannot_edit_post( $source_post_id );
		}

		$source_post = $this->find_post( $source_post_id );
		if ( ! $source_post instanceof WP_Post ) {
			throw EditorialValidationException::invalid_post( $source_post_id );
		}

		// Disallow internal and unsupported post types.
		if ( ! $this->is_supported_post_type( $source_post->post_type ) ) {
			throw EditorialValidationException::unsupported_post_type( $source_post->post_type );
		}

		// Validate user permission to create posts of this type.
		$post_type_obj = function_exists( 'get_post_type_object' ) ? get_post_type_object( $source_post->post_type ) : null;
		$create_cap    = ( $post_type_obj && isset( $post_type_obj->cap->create_posts ) ) ? $post_type_obj->cap->create_posts : 'edit_posts';
		if ( ! $this->user_can( $create_cap ) ) {
			throw EditorialPermissionException::cannot_create_post( $source_post->post_type );
		}

		// Validate target language is active.
		$canonical_target = Language::normalize_code( $target_language_code );
		if ( ! $this->language_registry->is_active( $canonical_target ) ) {
			throw EditorialValidationException::inactive_or_invalid_language( $canonical_target );
		}

		// Retrieve source group.
		$group = $this->translation_resolver->get_group_for_element( 'post', $source_post_id );
		if ( null === $group ) {
			throw EditorialValidationException::source_not_managed();
		}

		// Check for existing translation in target language (concurrency / duplicate check).
		if ( $group->has_translation( $canonical_target ) ) {
			throw EditorialConflictException::translation_already_exists( $canonical_target );
		}

		// Sovereign Rule: Insert new translation post strictly as 'draft'.
		// Zero cloning: do NOT copy post_content, post_excerpt, postmeta, terms, or thumbnail.
		$new_post_args = array(
			'post_type'    => $source_post->post_type,
			'post_status'  => 'draft',
			'post_title'   => '',
			'post_content' => '',
			'post_author'  => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 1,
		);

		$new_post_id = $this->insert_post( $new_post_args );

		// Canonical element's version as source version reference.
		$canonical_element = $group->get_canonical_element();
		$source_version    = null !== $canonical_element ? $canonical_element->get_current_content_version() : 1;

		// Initialize metadata configured with SHARE policy (Zero cloning: TRANSLATE/IGNORE remain uncopied).
		$this->initialize_shared_meta( $source_post_id, $new_post_id );

		// Initial fingerprint of the new translation post.
		$initial_fingerprint = $this->fingerprint_service->calculate_post_fingerprint( $new_post_id );

		// Add new translation post to the existing group.
		$group_id = (int) $group->get_id();
		$this->group_repository->add_translation(
			$group_id,
			$new_post_id,
			$canonical_target,
			false,
			$source_version,
			1,
			$initial_fingerprint
		);

		$this->translation_resolver->flush_cache();
		$this->clear_editorial_cache();

		return $new_post_id;
	}

	/**
	 * Creates a new translation term in the target language and links it to the source group.
	 *
	 * Enforces:
	 * - Taxonomy existence and capability validation.
	 * - Subtype isolation (matches source taxonomy).
	 * - Concurrency conflict prevention.
	 * - Zero automatic hierarchy copying.
	 *
	 * @param int    $source_term_id       Source term ID.
	 * @param string $taxonomy             Taxonomy name.
	 * @param string $target_language_code Target active language code.
	 * @param string $target_term_name     Name for the new translated term.
	 * @return int The ID of the newly created translation term.
	 * @throws EditorialValidationException If validation fails.
	 * @throws EditorialPermissionException If permissions are lacking.
	 * @throws EditorialConflictException If translation already exists.
	 */
	public function create_term_translation(
		int $source_term_id,
		string $taxonomy,
		string $target_language_code,
		string $target_term_name
	): int {
		if ( ! $this->language_registry->is_configured() ) {
			throw EditorialValidationException::not_configured();
		}

		if ( $source_term_id <= 0 ) {
			throw EditorialValidationException::invalid_term( $source_term_id );
		}

		if ( ! $this->is_supported_taxonomy( $taxonomy ) ) {
			throw EditorialValidationException::invalid_taxonomy( $taxonomy );
		}

		// Validate user permission for taxonomy terms.
		$tax_obj  = function_exists( 'get_taxonomy' ) ? get_taxonomy( $taxonomy ) : null;
		$edit_cap = ( $tax_obj && isset( $tax_obj->cap->edit_terms ) ) ? $tax_obj->cap->edit_terms : 'edit_terms';
		if ( ! $this->user_can( $edit_cap ) ) {
			throw EditorialPermissionException::cannot_edit_terms( $taxonomy );
		}

		$source_term = $this->find_term( $source_term_id, $taxonomy );
		if ( ! $source_term instanceof WP_Term ) {
			throw EditorialValidationException::invalid_term( $source_term_id );
		}

		$name_trimmed = trim( $target_term_name );
		if ( '' === $name_trimmed ) {
			throw new EditorialValidationException( 'Term name cannot be empty.' );
		}

		// Validate target language is active.
		$canonical_target = Language::normalize_code( $target_language_code );
		if ( ! $this->language_registry->is_active( $canonical_target ) ) {
			throw EditorialValidationException::inactive_or_invalid_language( $canonical_target );
		}

		// Retrieve source group.
		$group = $this->translation_resolver->get_group_for_element( 'term', $source_term_id );
		if ( null === $group ) {
			throw EditorialValidationException::source_not_managed();
		}

		if ( $group->get_subtype() !== $taxonomy ) {
			throw EditorialValidationException::subtype_mismatch();
		}

		// Check for existing translation in target language.
		if ( $group->has_translation( $canonical_target ) ) {
			throw EditorialConflictException::translation_already_exists( $canonical_target );
		}

		// Insert term via native WordPress API (slug is left to WordPress Core, no parent copied).
		$new_term_id = $this->insert_term( $name_trimmed, $taxonomy );

		$canonical_element   = $group->get_canonical_element();
		$source_version      = null !== $canonical_element ? $canonical_element->get_current_content_version() : 1;
		$initial_fingerprint = $this->fingerprint_service->calculate_term_fingerprint( $new_term_id, $taxonomy );

		// Add new translation term to the existing group.
		$group_id = (int) $group->get_id();
		$this->group_repository->add_translation(
			$group_id,
			$new_term_id,
			$canonical_target,
			false,
			$source_version,
			1,
			$initial_fingerprint
		);

		$this->translation_resolver->flush_cache();
		$this->clear_editorial_cache();

		return $new_term_id;
	}

	/**
	 * Generates the native WordPress admin edit URL for an element.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @param string $subtype      Subtype (post_type or taxonomy).
	 * @return string Admin edit URL.
	 */
	public function get_edit_url( string $element_type, int $element_id, string $subtype = '' ): string {
		if ( 'post' === $element_type ) {
			return 'post.php?post=' . $element_id . '&action=edit';
		}

		if ( 'term' === $element_type && '' !== $subtype ) {
			return 'term.php?taxonomy=' . rawurlencode( $subtype ) . '&tag_ID=' . $element_id;
		}

		return '';
	}

	/**
	 * Checks whether the current user has the specified capability.
	 *
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Optional additional arguments.
	 * @return bool
	 */
	protected function user_can( string $capability, ...$args ): bool {
		if ( isset( $GLOBALS['wp_test_caps'][ $capability ] ) ) {
			return (bool) $GLOBALS['wp_test_caps'][ $capability ];
		}
		if ( function_exists( 'current_user_can' ) ) {
			return current_user_can( $capability, ...$args );
		}
		return true;
	}

	/**
	 * Finds a post by ID.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|null
	 */
	protected function find_post( int $post_id ): ?WP_Post {
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );
			return $post instanceof WP_Post ? $post : null;
		}
		return $GLOBALS['wp_test_posts'][ $post_id ] ?? null;
	}

	/**
	 * Finds a term by ID and taxonomy.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return WP_Term|null
	 */
	protected function find_term( int $term_id, string $taxonomy ): ?WP_Term {
		if ( function_exists( 'get_term' ) ) {
			$term = get_term( $term_id, $taxonomy );
			return $term instanceof WP_Term ? $term : null;
		}
		return $GLOBALS['wp_test_terms'][ $term_id ] ?? null;
	}

	/**
	 * Inserts a new post.
	 *
	 * @param array<string, mixed> $postarr Post data.
	 * @return int Post ID.
	 * @throws EditorialValidationException If post creation fails.
	 */
	protected function insert_post( array $postarr ): int {
		if ( function_exists( 'wp_insert_post' ) ) {
			$filter_callback = '__return_false';
			if ( function_exists( 'add_filter' ) ) {
				add_filter( 'wp_insert_post_empty_content', $filter_callback, 10, 0 );
			}

			try {
				$result = wp_insert_post( $postarr, true );
			} finally {
				if ( function_exists( 'remove_filter' ) ) {
					remove_filter( 'wp_insert_post_empty_content', $filter_callback, 10 );
				}
			}

			if ( is_wp_error( $result ) ) {
				throw new EditorialValidationException( $result->get_error_message() );
			}
			return (int) $result;
		}

		$existing_ids = isset( $GLOBALS['wp_test_posts'] ) && is_array( $GLOBALS['wp_test_posts'] ) && count( $GLOBALS['wp_test_posts'] ) > 0
			? array_keys( $GLOBALS['wp_test_posts'] )
			: array( 500 );
		$new_id       = max( $existing_ids ) + 1;

		$post               = new WP_Post();
		$post->ID           = $new_id;
		$post->post_type    = (string) ( $postarr['post_type'] ?? 'post' );
		$post->post_status  = (string) ( $postarr['post_status'] ?? 'draft' );
		$post->post_title   = (string) ( $postarr['post_title'] ?? '' );
		$post->post_content = (string) ( $postarr['post_content'] ?? '' );

		$GLOBALS['wp_test_posts'][ $new_id ] = $post;
		return $new_id;
	}

	/**
	 * Inserts a new term.
	 *
	 * @param string $term_name Term name.
	 * @param string $taxonomy  Taxonomy name.
	 * @return int Term ID.
	 * @throws EditorialValidationException If term creation fails.
	 */
	protected function insert_term( string $term_name, string $taxonomy ): int {
		if ( function_exists( 'wp_insert_term' ) ) {
			$result = wp_insert_term( $term_name, $taxonomy );
			if ( is_wp_error( $result ) ) {
				throw new EditorialValidationException( $result->get_error_message() );
			}
			return (int) $result['term_id'];
		}

		$existing_ids = isset( $GLOBALS['wp_test_terms'] ) && is_array( $GLOBALS['wp_test_terms'] ) && count( $GLOBALS['wp_test_terms'] ) > 0
			? array_keys( $GLOBALS['wp_test_terms'] )
			: array( 800 );
		$new_id       = max( $existing_ids ) + 1;

		$term           = new WP_Term();
		$term->term_id  = $new_id;
		$term->taxonomy = $taxonomy;
		$term->name     = $term_name;
		$term->slug     = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '-', $term_name ) );

		$GLOBALS['wp_test_terms'][ $new_id ] = $term;
		return $new_id;
	}

	/**
	 * Copies metadata configured with SHARE policy from source post to new post.
	 *
	 * Zero cloning: only explicitly SHARE keys are copied. All other keys remain uncopied.
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $target_post_id Target post ID.
	 * @return void
	 */
	protected function initialize_shared_meta( int $source_post_id, int $target_post_id ): void {
		$policies = $this->custom_field_policy_registry->get_all_policies();
		foreach ( $policies as $meta_key => $policy ) {
			if ( CustomFieldPolicy::SHARE !== $policy ) {
				continue;
			}

			if ( $this->post_meta_exists( $source_post_id, $meta_key ) ) {
				$val = $this->get_post_meta_value( $source_post_id, $meta_key );
				$this->update_post_meta_value( $target_post_id, $meta_key, $val );
			}

			// If this is an ACF field, also copy its paired reference key if present.
			$ref_key = '_' . $meta_key;
			if ( ! str_starts_with( $meta_key, '_' ) && $this->post_meta_exists( $source_post_id, $ref_key ) ) {
				$ref_val = $this->get_post_meta_value( $source_post_id, $ref_key );
				$this->update_post_meta_value( $target_post_id, $ref_key, $ref_val );
			}
		}
	}

	/**
	 * Checks if post meta exists.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	protected function post_meta_exists( int $post_id, string $meta_key ): bool {
		if ( function_exists( 'metadata_exists' ) ) {
			return metadata_exists( 'post', $post_id, $meta_key );
		}

		return isset( $GLOBALS['wp_test_postmeta'][ $post_id ][ $meta_key ] );
	}

	/**
	 * Gets post meta value.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	protected function get_post_meta_value( int $post_id, string $meta_key ): mixed {
		if ( function_exists( 'get_post_meta' ) ) {
			return get_post_meta( $post_id, $meta_key, true );
		}

		return $GLOBALS['wp_test_postmeta'][ $post_id ][ $meta_key ] ?? null;
	}

	/**
	 * Updates post meta value.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $val      Meta value.
	 * @return bool
	 */
	protected function update_post_meta_value( int $post_id, string $meta_key, mixed $val ): bool {
		if ( function_exists( 'update_post_meta' ) ) {
			$slashed = function_exists( 'wp_slash' ) ? wp_slash( $val ) : $val;
			return (bool) update_post_meta( $post_id, $meta_key, $slashed );
		}

		if ( ! isset( $GLOBALS['wp_test_postmeta'] ) || ! is_array( $GLOBALS['wp_test_postmeta'] ) ) {
			$GLOBALS['wp_test_postmeta'] = array();
		}
		$GLOBALS['wp_test_postmeta'][ $post_id ][ $meta_key ] = $val;
		return true;
	}

	/**
	 * Synchronizes content version and fingerprint for a managed post.
	 *
	 * Increments version ONLY if translatable fingerprint has changed.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function sync_post_version( int $post_id ): void {
		if ( $post_id <= 0 || in_array( $post_id, $this->syncing_elements, true ) ) {
			return;
		}

		$this->syncing_elements[] = $post_id;

		try {
			$element = $this->group_repository->find_element( 'post', $post_id );
			if ( null === $element ) {
				return;
			}

			$group = $this->translation_resolver->get_group_for_element( 'post', $post_id );
			if ( null === $group ) {
				return;
			}

			$new_fingerprint = $this->fingerprint_service->calculate_post_fingerprint( $post_id );
			$old_fingerprint = $element->get_translatable_fingerprint();

			// If fingerprint is unchanged and not initial empty, exit early.
			if ( '' !== $old_fingerprint && $new_fingerprint === $old_fingerprint ) {
				return;
			}

			$is_canonical = ( $element->get_element_id() === $group->get_canonical_element_id() );
			$new_version  = ( '' === $old_fingerprint )
				? $element->get_current_content_version()
				: $element->get_current_content_version() + 1;

			$canonical_element = $group->get_canonical_element();
			$source_version    = $is_canonical
				? $new_version
				: ( null !== $canonical_element ? $canonical_element->get_current_content_version() : $element->get_source_version_at_translation() );

			$updated_element = $element
				->with_current_content_version( $new_version )
				->with_source_version_at_translation( $source_version )
				->with_translatable_fingerprint( $new_fingerprint );

			$this->group_repository->update_element( $updated_element );
			$this->translation_resolver->flush_cache();
			$this->clear_editorial_cache();
			$this->status_resolver->clear_cache();
		} finally {
			$key = array_search( $post_id, $this->syncing_elements, true );
			if ( false !== $key ) {
				unset( $this->syncing_elements[ $key ] );
				$this->syncing_elements = array_values( $this->syncing_elements );
			}
		}
	}

	/**
	 * Synchronizes content version and fingerprint for a managed term.
	 *
	 * Increments version ONLY if translatable fingerprint has changed.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public function sync_term_version( int $term_id, string $taxonomy ): void {
		if ( $term_id <= 0 || in_array( $term_id, $this->syncing_elements, true ) ) {
			return;
		}

		$this->syncing_elements[] = $term_id;

		try {
			$element = $this->group_repository->find_element( 'term', $term_id );
			if ( null === $element ) {
				return;
			}

			$group = $this->translation_resolver->get_group_for_element( 'term', $term_id );
			if ( null === $group ) {
				return;
			}

			$new_fingerprint = $this->fingerprint_service->calculate_term_fingerprint( $term_id, $taxonomy );
			$old_fingerprint = $element->get_translatable_fingerprint();

			if ( '' !== $old_fingerprint && $new_fingerprint === $old_fingerprint ) {
				return;
			}

			$is_canonical = ( $element->get_element_id() === $group->get_canonical_element_id() );
			$new_version  = ( '' === $old_fingerprint )
				? $element->get_current_content_version()
				: $element->get_current_content_version() + 1;

			$canonical_element = $group->get_canonical_element();
			$source_version    = $is_canonical
				? $new_version
				: ( null !== $canonical_element ? $canonical_element->get_current_content_version() : $element->get_source_version_at_translation() );

			$updated_element = $element
				->with_current_content_version( $new_version )
				->with_source_version_at_translation( $source_version )
				->with_translatable_fingerprint( $new_fingerprint );

			$this->group_repository->update_element( $updated_element );
			$this->translation_resolver->flush_cache();
			$this->clear_editorial_cache();
			$this->status_resolver->clear_cache();
		} finally {
			$key = array_search( $term_id, $this->syncing_elements, true );
			if ( false !== $key ) {
				unset( $this->syncing_elements[ $key ] );
				$this->syncing_elements = array_values( $this->syncing_elements );
			}
		}
	}

	/**
	 * Explicitly marks a translation as reviewed against current canonical version.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @return bool True on success, false if element not found or not managed.
	 */
	public function mark_translation_reviewed( string $element_type, int $element_id ): bool {
		$element = $this->group_repository->find_element( $element_type, $element_id );
		if ( null === $element ) {
			return false;
		}

		$group = $this->translation_resolver->get_group_for_element( $element_type, $element_id );
		if ( null === $group ) {
			return false;
		}

		$canonical_element = $group->get_canonical_element();
		if ( null === $canonical_element ) {
			return false;
		}

		$canonical_version = $canonical_element->get_current_content_version();
		if ( $element->get_source_version_at_translation() >= $canonical_version ) {
			return true;
		}

		$updated_element = $element->with_source_version_at_translation( $canonical_version );
		$success         = $this->group_repository->update_element( $updated_element );

		if ( $success ) {
			$this->translation_resolver->flush_cache();
			$this->clear_editorial_cache();
			$this->status_resolver->clear_cache();
		}

		return $success;
	}

	/**
	 * Registers core lifecycle action hooks for automatic version tracking.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'save_post', array( $this, 'on_save_post' ), 50, 3 );
		add_action( 'added_post_meta', array( $this, 'on_meta_added' ), 50, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_updated' ), 50, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_deleted' ), 50, 3 );
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 50, 3 );
	}

	/**
	 * Removes core lifecycle action hooks.
	 *
	 * @return void
	 */
	public function remove_hooks(): void {
		if ( ! function_exists( 'remove_action' ) ) {
			return;
		}

		remove_action( 'save_post', array( $this, 'on_save_post' ), 50 );
		remove_action( 'added_post_meta', array( $this, 'on_meta_added' ), 50 );
		remove_action( 'updated_post_meta', array( $this, 'on_meta_updated' ), 50 );
		remove_action( 'deleted_post_meta', array( $this, 'on_meta_deleted' ), 50 );
		remove_action( 'edited_term', array( $this, 'on_edited_term' ), 50 );
	}

	/**
	 * Callback for save_post hook.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 * @param bool         $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public function on_save_post( int $post_id, ?WP_Post $post = null, bool $update = false ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post_obj = null !== $post ? $post : $this->find_post( $post_id );
		if ( ! $post_obj instanceof WP_Post ) {
			return;
		}

		if ( 'auto-draft' === $post_obj->post_status ) {
			return;
		}

		if ( ! $this->is_supported_post_type( $post_obj->post_type ) ) {
			return;
		}

		$this->sync_post_version( $post_id );
	}

	/**
	 * Callback for added_post_meta hook.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public function on_meta_added( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		if ( CustomFieldPolicy::TRANSLATE !== $this->custom_field_policy_registry->get_policy( $meta_key ) ) {
			return;
		}

		$this->sync_post_version( $object_id );
	}

	/**
	 * Callback for updated_post_meta hook.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public function on_meta_updated( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		if ( CustomFieldPolicy::TRANSLATE !== $this->custom_field_policy_registry->get_policy( $meta_key ) ) {
			return;
		}

		$this->sync_post_version( $object_id );
	}

	/**
	 * Callback for deleted_post_meta hook.
	 *
	 * @param mixed  $meta_ids  Array of deleted meta IDs or single ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public function on_meta_deleted( mixed $meta_ids, int $object_id, string $meta_key ): void {
		if ( CustomFieldPolicy::TRANSLATE !== $this->custom_field_policy_registry->get_policy( $meta_key ) ) {
			return;
		}

		$this->sync_post_version( $object_id );
	}

	/**
	 * Callback for edited_term hook.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public function on_edited_term( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! $this->is_supported_taxonomy( $taxonomy ) ) {
			return;
		}

		$this->sync_term_version( $term_id, $taxonomy );
	}
}
