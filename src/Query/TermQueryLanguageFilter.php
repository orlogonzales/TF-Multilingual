<?php
/**
 * Term Query Language Filter.
 *
 * @package TF\Multilingual\Query
 */

declare( strict_types=1 );

namespace TF\Multilingual\Query;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use wpdb;

/**
 * Class TermQueryLanguageFilter
 *
 * Applies multilingual SQL constraints to WordPress term queries (WP_Term_Query).
 *
 * Sovereign Architectural Rules (Fase 1.7):
 * - WordPress continues executing its own taxonomy queries (WP_Term_Query, get_terms).
 *   TFML only adds linguistic constraints.
 * - Hook terms_clauses injects SQL JOIN and WHERE clauses.
 * - Identity: element_type = 'term', element_id = term_id, group subtype = taxonomy.
 * - Taxonomy is mandatory: tfml_groups.subtype must match tt.taxonomy.
 * - Policy: Progressive Adoption / Default-Language Ownership:
 *   - Managed terms (has row in tfml_group_elements) are strictly filtered by language_code
 *     and matched against taxonomy subtype.
 *   - Unmanaged terms (tfml_group_elements.id IS NULL) belong exclusively to the default language.
 *   - Default language requests show managed default + unmanaged.
 *   - Secondary language requests show strictly managed secondary (STRICT NO FALLBACK).
 * - No DISTINCT added (uq_element UNIQUE index guarantees zero duplicates).
 * - Opt-out supported via 'tfml_suppress_language_filter' => true or native 'suppress_filter' => true.
 * - Context exclusions: wp-admin, REST API, WP-CLI, WP-Cron, AJAX.
 * - Idempotency: guarantees a query never has clauses appended twice.
 */
class TermQueryLanguageFilter {

	/**
	 * Internal query variable for opting out of language filtering.
	 */
	public const QUERY_VAR_SUPPRESS = 'tfml_suppress_language_filter';

	/**
	 * Default-language ownership policy (Fase 1.7 Official Policy).
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
	 * Registers WordPress filter hook.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_filter( 'terms_clauses', array( $this, 'filter_terms_clauses' ), 10, 3 );
	}

	/**
	 * Checks if a term query is eligible for multilingual filtering.
	 *
	 * @param array<string, mixed> $args       Term query arguments.
	 * @param array<string>        $taxonomies List of queried taxonomies.
	 * @return bool True if eligible.
	 */
	public function is_query_eligible( array $args, array $taxonomies = array() ): bool {
		// 1. Registry must be configured.
		if ( ! $this->language_registry->is_configured() ) {
			return false;
		}

		// 2. Explicit TFML opt-out check.
		if ( ! empty( $args[ self::QUERY_VAR_SUPPRESS ] ) ) {
			return false;
		}

		// 3. WordPress native suppress_filter check (singular in WP_Term_Query).
		if ( ! empty( $args['suppress_filter'] ) ) {
			return false;
		}

		// 4. Defensive plural suppress_filters check.
		if ( ! empty( $args['suppress_filters'] ) ) {
			return false;
		}

		// 5. Excluded execution contexts.
		if ( $this->is_excluded_context() ) {
			return false;
		}

		// 6. Direct identity lookups (e.g. get_term_by() or term_exists() with 'get' => 'all' and slug/name/term_taxonomy_id or single include).
		if ( 'all' === ( $args['get'] ?? '' ) ) {
			if ( ! empty( $args['slug'] ) || ! empty( $args['name'] ) || ! empty( $args['term_taxonomy_id'] ) ) {
				return false;
			}
			if ( ! empty( $args['include'] ) && 1 === count( (array) $args['include'] ) && 1 === (int) ( $args['number'] ?? 0 ) ) {
				return false;
			}
		}

		// 7. Structural taxonomies exclusion (e.g. nav_menu).
		if ( in_array( 'nav_menu', $taxonomies, true ) || ( isset( $args['taxonomy'] ) && 'nav_menu' === $args['taxonomy'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Filters WordPress term SQL clauses to append multilingual constraints.
	 *
	 * @param array<string, string> $clauses    Associative array of SQL clauses.
	 * @param array<string>         $taxonomies List of queried taxonomies.
	 * @param array<string, mixed>  $args       Term query arguments.
	 * @return array<string, string> Modified SQL clauses.
	 */
	public function filter_terms_clauses( array $clauses, array $taxonomies, array $args ): array {
		if ( ! $this->is_query_eligible( $args, $taxonomies ) ) {
			return $clauses;
		}

		// Idempotency: prevent double application on the same query.
		$table_name = $this->db->prefix . 'tfml_group_elements';
		if ( str_contains( $clauses['join'] ?? '', $table_name ) ) {
			return $clauses;
		}

		$current_lang = $this->current_language_resolver->get_current_language();
		if ( null === $current_lang || ! $this->language_registry->is_active( $current_lang ) ) {
			return $clauses;
		}

		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : null;
		$is_default   = ( $current_lang === $default_code );

		$join_clause  = $this->build_join_clause();
		$where_clause = $this->build_where_clause( $current_lang, $is_default );

		if ( ! isset( $clauses['join'] ) ) {
			$clauses['join'] = '';
		}
		if ( ! isset( $clauses['where'] ) ) {
			$clauses['where'] = '';
		}

		$clauses['join'] .= ' ' . $join_clause;

		if ( '' !== trim( $clauses['where'] ) ) {
			$clauses['where'] .= ' AND ' . $where_clause;
		} else {
			$clauses['where'] = $where_clause;
		}

		return $clauses;
	}

	/**
	 * Builds the SQL JOIN clause for terms.
	 *
	 * @return string
	 */
	public function build_join_clause(): string {
		$table_elements = $this->db->prefix . 'tfml_group_elements';
		$table_groups   = $this->db->prefix . 'tfml_groups';

		return "LEFT JOIN {$table_elements} AS tfml_ge ON (tfml_ge.element_id = t.term_id AND tfml_ge.element_type = 'term') LEFT JOIN {$table_groups} AS tfml_g ON (tfml_g.id = tfml_ge.group_id)";
	}

	/**
	 * Builds the SQL WHERE condition based on language, taxonomy subtype, and policy.
	 *
	 * @param string $language_code Canonical target language code.
	 * @param bool   $is_default    Whether the target language is the default language.
	 * @return string
	 */
	public function build_where_clause( string $language_code, bool $is_default ): string {
		$code_escaped = esc_sql( $language_code );

		if ( $is_default && self::POLICY_DEFAULT_LANGUAGE_OWNERSHIP === $this->unmanaged_policy ) {
			// Progressive Adoption: Default includes managed in default language for matching taxonomy + unmanaged.
			return "((tfml_ge.language_code = '{$code_escaped}' AND tfml_g.subtype = tt.taxonomy) OR tfml_ge.id IS NULL)";
		}

		// Secondary languages or Strict policy: strictly managed in target language with matching taxonomy (NO FALLBACK).
		return "(tfml_ge.language_code = '{$code_escaped}' AND tfml_g.subtype = tt.taxonomy)";
	}

	/**
	 * Checks if the current execution context is excluded from term filtering.
	 *
	 * @return bool True if context is excluded.
	 */
	public function is_excluded_context(): bool {
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

		// AJAX requests.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}

		// Core Sitemaps requests.
		if ( ( function_exists( 'is_sitemap' ) && is_sitemap() ) || ( function_exists( 'get_query_var' ) && ! empty( get_query_var( 'sitemap' ) ) ) ) {
			return true;
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
