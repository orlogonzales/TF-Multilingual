<?php
/**
 * Translation Editorial Service.
 *
 * @package TF\Multilingual\Editorial
 */

declare( strict_types=1 );

namespace TF\Multilingual\Editorial;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
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
	 * Constructor.
	 *
	 * @param LanguageRegistry|null           $language_registry    Language registry.
	 * @param TranslationGroupRepository|null $group_repository     Translation group repository.
	 * @param ContentTranslationResolver|null $translation_resolver Content translation resolver.
	 * @param WordPressElementValidator|null  $element_validator    Element validator.
	 * @param wpdb|null                       $db                   WordPress database instance.
	 */
	public function __construct(
		?LanguageRegistry $language_registry = null,
		?TranslationGroupRepository $group_repository = null,
		?ContentTranslationResolver $translation_resolver = null,
		?WordPressElementValidator $element_validator = null,
		?wpdb $db = null
	) {
		global $wpdb;

		$this->language_registry    = null !== $language_registry ? $language_registry : new LanguageRegistry();
		$this->group_repository     = null !== $group_repository ? $group_repository : new TranslationGroupRepository();
		$this->translation_resolver = null !== $translation_resolver ? $translation_resolver : new ContentTranslationResolver( $this->group_repository, $this->language_registry );
		$this->element_validator    = null !== $element_validator ? $element_validator : new WordPressElementValidator();
		$this->db                   = null !== $db ? $db : $wpdb;
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
		if ( ! $this->language_registry->is_configured() ) {
			return array(
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

				$trans_info = array(
					'element_id'    => $element->get_element_id(),
					'language_code' => $code,
					'language_name' => $lang_name,
					'is_current'    => ( $element->get_element_id() === $element_id ),
					'is_active'     => $is_active,
					'edit_url'      => $edit_url,
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
					);
				}
			}
		} else {
			// Unmanaged object: all active languages are missing from the object's perspective.
			foreach ( $active_langs as $code => $lang ) {
				$missing_languages[ $code ] = array(
					'language_code' => $code,
					'language_name' => $lang->get_name(),
				);
			}
		}

		return array(
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

		// Create group with canonical designation.
		$group = $this->group_repository->create_group(
			$normalized_type,
			$subtype,
			$element_id,
			$canonical_lang,
			true
		);

		$this->translation_resolver->flush_cache();

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

		// Add new translation post to the existing group.
		$group_id = (int) $group->get_id();
		$this->group_repository->add_translation( $group_id, $new_post_id, $canonical_target );

		$this->translation_resolver->flush_cache();

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

		// Add new translation term to the existing group.
		$group_id = (int) $group->get_id();
		$this->group_repository->add_translation( $group_id, $new_term_id, $canonical_target );

		$this->translation_resolver->flush_cache();

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
			if ( function_exists( 'get_edit_post_link' ) ) {
				$url = get_edit_post_link( $element_id, 'raw' );
				return null !== $url ? $url : '';
			}
			return 'post.php?post=' . $element_id . '&action=edit';
		}

		if ( 'term' === $element_type && '' !== $subtype ) {
			if ( function_exists( 'get_edit_term_link' ) ) {
				$url = get_edit_term_link( $element_id, $subtype );
				return is_string( $url ) ? $url : '';
			}
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
}
