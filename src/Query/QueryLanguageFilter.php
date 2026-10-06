<?php
/**
 * Query Language Filter.
 *
 * @package TF\Multilingual\Query
 */

declare( strict_types=1 );

namespace TF\Multilingual\Query;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use WP_Query;
use wpdb;

/**
 * Class QueryLanguageFilter
 *
 * Applies multilingual SQL constraints to WordPress queries.
 *
 * Sovereign Architectural Rules (Fase 1.6):
 * - WordPress continues executing its own queries. TFML only adds linguistic constraints.
 * - Hook pre_get_posts qualifies the query and marks eligibility.
 * - Hook posts_clauses injects the SQL JOIN and WHERE clauses.
 * - Never filter via the_posts or posts_results (preserves pagination, found_posts, max_num_pages).
 * - Policy: Progressive Adoption / Default-Language Ownership:
 *   - Managed content (has row in tfml_group_elements) is strictly filtered by language_code.
 *   - Unmanaged content (tfml_group_elements.id IS NULL) belongs exclusively to the default language.
 *   - Default language requests show managed default + unmanaged.
 *   - Secondary language requests show strictly managed secondary (STRICT NO FALLBACK).
 * - No DISTINCT added (uq_element UNIQUE index guarantees zero duplicates).
 * - Opt-out supported via 'tfml_suppress_language_filter' => true or 'suppress_filters' => true.
 * - Context exclusions: wp-admin, REST API, WP-CLI, WP-Cron, AJAX, ?preview=true.
 * - Post type exclusions: attachment, revision, nav_menu_item, and internal types.
 * - Idempotency: guarantees a query never has clauses appended twice.
 */
class QueryLanguageFilter {

	/**
	 * Internal query variable for opting out of language filtering.
	 */
	public const QUERY_VAR_SUPPRESS = 'tfml_suppress_language_filter';

	/**
	 * Internal query variable marking applied clauses.
	 */
	public const QUERY_VAR_APPLIED = '_tfml_clauses_applied';

	/**
	 * Internal query variable storing target language code.
	 */
	public const QUERY_VAR_TARGET_LANG = '_tfml_target_language';

	/**
	 * Default-language ownership policy (Fase 1.6 Official Policy).
	 */
	public const POLICY_DEFAULT_LANGUAGE_OWNERSHIP = 'default_language';

	/**
	 * Strict filtering policy (Future phase readiness).
	 */
	public const POLICY_STRICT = 'strict';

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_language_resolver;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Active unmanaged content policy.
	 *
	 * @var string
	 */
	private string $unmanaged_policy = self::POLICY_DEFAULT_LANGUAGE_OWNERSHIP;

	/**
	 * Internal post types excluded from language filtering.
	 *
	 * @var array<string>
	 */
	private array $excluded_post_types = array(
		'attachment',
		'revision',
		'nav_menu_item',
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
	 * Constructor.
	 *
	 * @param LanguageRegistry        $language_registry         Language registry.
	 * @param CurrentLanguageResolver $current_language_resolver Current language resolver.
	 * @param wpdb|null               $db                        Optional wpdb instance.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		CurrentLanguageResolver $current_language_resolver,
		?wpdb $db = null
	) {
		global $wpdb;
		$this->language_registry         = $language_registry;
		$this->current_language_resolver = $current_language_resolver;
		$this->db                        = null !== $db ? $db : $wpdb;
	}

	/**
	 * Registers WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( 'pre_get_posts', array( $this, 'on_pre_get_posts' ), 10, 1 );
		add_filter( 'posts_clauses', array( $this, 'filter_posts_clauses' ), 10, 2 );
	}

	/**
	 * Evaluates query eligibility during pre_get_posts.
	 *
	 * @param WP_Query $query WordPress query instance.
	 * @return void
	 */
	public function on_pre_get_posts( WP_Query $query ): void {
		if ( ! $this->is_query_eligible( $query ) ) {
			return;
		}

		$current_lang = $this->current_language_resolver->get_current_language();
		if ( null !== $current_lang && $this->language_registry->is_active( $current_lang ) ) {
			$query->set( self::QUERY_VAR_TARGET_LANG, $current_lang );
		}
	}

	/**
	 * Checks if a query is eligible for multilingual filtering.
	 *
	 * @param WP_Query $query Query instance.
	 * @return bool True if eligible.
	 */
	public function is_query_eligible( WP_Query $query ): bool {
		// 1. Registry must be configured.
		if ( ! $this->language_registry->is_configured() ) {
			return false;
		}

		// 2. Explicit TFML opt-out check.
		if ( true === $query->get( self::QUERY_VAR_SUPPRESS ) ) {
			return false;
		}

		// 3. WordPress native suppress_filters check.
		if ( true === $query->get( 'suppress_filters' ) ) {
			return false;
		}

		// 4. Excluded execution contexts.
		if ( $this->is_excluded_context( $query ) ) {
			return false;
		}

		// 5. Excluded post types.
		if ( $this->is_excluded_post_type( $query ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Filters WordPress SQL clauses to append multilingual constraints.
	 *
	 * @param array<string, string> $clauses Associative array of SQL clauses.
	 * @param WP_Query              $query   WordPress query instance.
	 * @return array<string, string> Modified SQL clauses.
	 */
	public function filter_posts_clauses( array $clauses, WP_Query $query ): array {
		// Check target language qualification.
		$target_lang = $query->get( self::QUERY_VAR_TARGET_LANG );
		if ( ! is_string( $target_lang ) || '' === $target_lang ) {
			return $clauses;
		}

		// Idempotency: prevent double application on the same query instance.
		if ( true === $query->get( self::QUERY_VAR_APPLIED ) ) {
			return $clauses;
		}

		// Check if JOIN is already present.
		$table_name = $this->db->prefix . 'tfml_group_elements';
		if ( str_contains( $clauses['join'] ?? '', $table_name ) ) {
			return $clauses;
		}

		// Verify target language is still registered and active.
		if ( ! $this->language_registry->is_active( $target_lang ) ) {
			return $clauses;
		}

		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : null;
		$is_default   = ( $target_lang === $default_code );

		// Build SQL Clauses.
		$join_clause  = $this->build_join_clause();
		$where_clause = $this->build_where_clause( $target_lang, $is_default );

		if ( ! isset( $clauses['join'] ) ) {
			$clauses['join'] = '';
		}
		if ( ! isset( $clauses['where'] ) ) {
			$clauses['where'] = '';
		}

		$clauses['join']  .= ' ' . $join_clause;
		$clauses['where'] .= ' ' . $where_clause;

		$query->set( self::QUERY_VAR_APPLIED, true );

		return $clauses;
	}

	/**
	 * Builds the SQL JOIN clause.
	 *
	 * @return string
	 */
	public function build_join_clause(): string {
		$table = $this->db->prefix . 'tfml_group_elements';
		$posts = $this->db->posts;

		return "LEFT JOIN {$table} AS tfml_ge ON (tfml_ge.element_id = {$posts}.ID AND tfml_ge.element_type = 'post')";
	}

	/**
	 * Builds the SQL WHERE condition based on language and policy.
	 *
	 * @param string $language_code Canonical target language code.
	 * @param bool   $is_default    Whether the target language is the default language.
	 * @return string
	 */
	public function build_where_clause( string $language_code, bool $is_default ): string {
		$code_escaped = esc_sql( $language_code );

		if ( $is_default && self::POLICY_DEFAULT_LANGUAGE_OWNERSHIP === $this->unmanaged_policy ) {
			// Progressive Adoption: Default language includes managed default + unmanaged historical content.
			return "AND (tfml_ge.language_code = '{$code_escaped}' OR tfml_ge.id IS NULL)";
		}

		// Secondary languages or Strict policy: strictly managed content in target language (NO FALLBACK).
		return "AND tfml_ge.language_code = '{$code_escaped}'";
	}

	/**
	 * Checks if the current execution context is excluded from language filtering.
	 *
	 * @param WP_Query $query Query instance.
	 * @return bool True if context is excluded.
	 */
	public function is_excluded_context( WP_Query $query ): bool {
		// Admin lists and screens.
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return true;
		}

		// REST API.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return true;
		}

		// WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		// WP-Cron.
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		// AJAX requests (conservative: excluded in 1.6).
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}

		// Editorial Preview (?preview=true).
		if ( $query->is_preview() ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if query explicitly targets excluded internal post types.
	 *
	 * @param WP_Query $query Query instance.
	 * @return bool True if post type is excluded.
	 */
	public function is_excluded_post_type( WP_Query $query ): bool {
		$post_type = $query->get( 'post_type' );

		if ( is_string( $post_type ) && in_array( $post_type, $this->excluded_post_types, true ) ) {
			return true;
		}

		if ( is_array( $post_type ) && ! empty( $post_type ) ) {
			// If all requested post types are internal excluded types, exclude query.
			$all_excluded = true;
			foreach ( $post_type as $type ) {
				if ( ! in_array( $type, $this->excluded_post_types, true ) ) {
					$all_excluded = false;
					break;
				}
			}
			if ( $all_excluded ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sets the unmanaged content policy (internal/testing use).
	 *
	 * @param string $policy Policy constant.
	 * @return void
	 */
	public function set_unmanaged_policy( string $policy ): void {
		if ( self::POLICY_DEFAULT_LANGUAGE_OWNERSHIP === $policy || self::POLICY_STRICT === $policy ) {
			$this->unmanaged_policy = $policy;
		}
	}

	/**
	 * Gets the current unmanaged content policy.
	 *
	 * @return string
	 */
	public function get_unmanaged_policy(): string {
		return $this->unmanaged_policy;
	}
}
