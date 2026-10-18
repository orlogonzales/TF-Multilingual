<?php
/**
 * Core Sitemaps Filter.
 *
 * @package TF\Multilingual\Domain\Seo
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Seo;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Query\QueryLanguageFilter;
use TF\Multilingual\Query\TermQueryLanguageFilter;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use WP_Post;
use WP_Query;
use WP_Term;
use wpdb;

/**
 * Class CoreSitemapsFilter
 *
 * Adapts WordPress Core native XML Sitemaps (wp_sitemaps) for multilingual content (DT-TFML-068):
 * - Enforces an explicit, deterministic 6-category eligibility contract (A..F) without querying WPML tables (icl_*).
 * - Applies SQL-level eligibility constraints in posts_clauses and terms_clauses so pagination, max_num_pages,
 *   counts, and ordering are calculated exclusively over eligible records without generating empty sitemap pages.
 * - Excludes technical layout/template post types (ova_framework_hf_el, elementor_library, etc.) while preserving
 *   commercial CPTs (rooms, tours, product).
 * - Excludes inactive languages, retained translations (RetainedTranslationRegistry), and unaccredited/unmigrated
 *   content while supporting accredited monolingual content when authorized.
 * - Localizes and deduplicates canonical URLs across all active languages.
 */
class CoreSitemapsFilter {

	/**
	 * Category A: Accredited TFML content associated with an active language and valid public status.
	 */
	public const CATEGORY_TFML_ACCREDITED = 'tfml_accredited';

	/**
	 * Category B: Legitimately monolingual content whose default-language ownership is explicitly accredited.
	 */
	public const CATEGORY_ACCREDITED_MONOLINGUAL = 'accredited_monolingual';

	/**
	 * Category C: Unclassified content lacking TFML association and linguistic provenance (e.g. synthetic test posts).
	 */
	public const CATEGORY_UNCLASSIFIED = 'unclassified';

	/**
	 * Category D: Unmigrated historical translations lacking TFML relationship (treated conservatively as unclassified at runtime).
	 */
	public const CATEGORY_UNMIGRATED_HISTORICAL = 'unmigrated_historical';

	/**
	 * Category E: Retained translations in inactive languages registered in RetainedTranslationRegistry.
	 */
	public const CATEGORY_RETAINED_TRANSLATION = 'retained_translation';

	/**
	 * Category F: Technical templates, layout structures, and internal post types excluded from public sitemaps.
	 */
	public const CATEGORY_TECHNICAL_CONTENT = 'technical_content';

	/**
	 * Exclusion category for TFML elements assigned to an inactive language.
	 */
	public const CATEGORY_INACTIVE_LANGUAGE = 'inactive_language';

	/**
	 * Exclusion category for non-public (draft, private, trash, or invalid) entities.
	 */
	public const CATEGORY_NON_PUBLIC_STATUS = 'non_public_status';

	/**
	 * Exclusion category for duplicate canonical URLs within a sitemap list.
	 */
	public const CATEGORY_DUPLICATE_URL = 'duplicate_url';

	/**
	 * Default conservative policy: requires TFML accreditation in an active language or explicit monolingual accreditation.
	 */
	public const POLICY_CONSERVATIVE_ACCREDITED_ONLY = 'conservative_accredited_only';

	/**
	 * Authorized monolingual default policy: treats unmanaged content as accredited in the active default language.
	 */
	public const POLICY_MONOLINGUAL_DEFAULT = 'monolingual_default';

	/**
	 * Internal query variable marking a WP_Query or WP_Term_Query for sitemap SQL eligibility filtering.
	 */
	public const QUERY_VAR_SITEMAP_ELIGIBILITY = '_tfml_sitemap_eligibility';

	/**
	 * Internal query variable storing the sitemap post type.
	 */
	public const QUERY_VAR_SITEMAP_POST_TYPE = '_tfml_sitemap_post_type';

	/**
	 * Internal query variable storing the sitemap taxonomy.
	 */
	public const QUERY_VAR_SITEMAP_TAXONOMY = '_tfml_sitemap_taxonomy';

	/**
	 * Internal query variable marking that sitemap SQL clauses have been applied.
	 */
	public const QUERY_VAR_SITEMAP_CLAUSES_APPLIED = '_tfml_sitemap_clauses_applied';

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $content_resolver;

	/**
	 * Localized URL generator.
	 *
	 * @var LocalizedUrlGenerator
	 */
	private LocalizedUrlGenerator $url_generator;

	/**
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry|null
	 */
	private ?RetainedTranslationRegistry $retained_registry;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb|null
	 */
	private ?wpdb $db;

	/**
	 * Active sitemap eligibility policy.
	 *
	 * @var string
	 */
	private string $eligibility_policy = self::POLICY_CONSERVATIVE_ACCREDITED_ONLY;

	/**
	 * Explicitly accredited monolingual element IDs keyed by element type ('post' or 'term').
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $accredited_monolingual_ids = array(
		'post' => array(),
		'term' => array(),
	);

	/**
	 * Map of canonical URLs already emitted in the current post sitemap pass (URL => post_id).
	 *
	 * @var array<string, int>
	 */
	private array $seen_post_urls = array();

	/**
	 * Map of canonical URLs already emitted in the current taxonomy sitemap pass (URL => term_id).
	 *
	 * @var array<string, int>
	 */
	private array $seen_term_urls = array();

	/**
	 * Technical post types excluded from public XML sitemaps.
	 *
	 * @var array<int, string>
	 */
	private array $excluded_technical_post_types = array(
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
		'ova_framework_hf_el',
		'elementor_library',
		'elementor_font',
		'elementor_icons',
		'elementor_snippet',
		'e-floating-buttons',
		'e-landing-page',
		'wpcf7_contact_form',
	);

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry                 $language_registry Language registry.
	 * @param ContentTranslationResolver       $content_resolver  Content translation resolver.
	 * @param LocalizedUrlGenerator            $url_generator     Localized URL generator.
	 * @param RetainedTranslationRegistry|null $retained_registry Optional retained translation registry.
	 * @param wpdb|null                        $db                Optional wpdb instance.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		ContentTranslationResolver $content_resolver,
		LocalizedUrlGenerator $url_generator,
		?RetainedTranslationRegistry $retained_registry = null,
		?wpdb $db = null
	) {
		global $wpdb;
		$this->language_registry = $language_registry;
		$this->content_resolver  = $content_resolver;
		$this->url_generator     = $url_generator;
		$this->retained_registry = $retained_registry;
		$this->db                = null !== $db ? $db : ( $wpdb instanceof wpdb ? $wpdb : null );
	}

	/**
	 * Registers hooks for WordPress Core Sitemaps.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'wp_sitemaps_post_types', array( $this, 'filter_sitemaps_post_types' ), 10, 1 );
		add_filter( 'wp_sitemaps_posts_pre_max_num_pages', array( $this, 'filter_posts_pre_max_num_pages' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_pre_url_list', array( $this, 'filter_posts_pre_url_list' ), 10, 3 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_posts_query_args' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'filter_taxonomies_query_args' ), 10, 2 );
		add_filter( 'posts_clauses', array( $this, 'filter_sitemap_posts_clauses' ), 10, 2 );
		add_filter( 'terms_clauses', array( $this, 'filter_sitemap_terms_clauses' ), 10, 3 );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'filter_posts_entry' ), 10, 3 );
		add_filter( 'wp_sitemaps_taxonomies_entry', array( $this, 'filter_taxonomies_entry' ), 10, 4 );
	}

	/**
	 * Filters the list of post object subtypes available within the sitemap,
	 * excluding technical template/layout CPTs while preserving commercial CPTs (rooms, tours, product).
	 *
	 * @param array<string, mixed> $post_types Registered viewable post type objects keyed by name.
	 * @return array<string, mixed> Filtered post types.
	 */
	public function filter_sitemaps_post_types( array $post_types ): array {
		foreach ( array_keys( $post_types ) as $post_type ) {
			if ( $this->is_excluded_technical_post_type( (string) $post_type ) ) {
				unset( $post_types[ $post_type ] );
			}
		}

		return $post_types;
	}

	/**
	 * Short-circuits max_num_pages to 0 for excluded technical post types.
	 *
	 * @param int|null $max_num_pages Pre-filtered max pages or null.
	 * @param string   $post_type     Post type name.
	 * @return int|null Zero if technical post type, otherwise original value.
	 */
	public function filter_posts_pre_max_num_pages( ?int $max_num_pages, string $post_type ): ?int {
		if ( $this->is_excluded_technical_post_type( $post_type ) ) {
			return 0;
		}

		return $max_num_pages;
	}

	/**
	 * Short-circuits URL list to empty array for excluded technical post types.
	 *
	 * @param array<int, array<string, mixed>>|null $url_list  Pre-filtered URL list or null.
	 * @param string                                $post_type Post type name.
	 * @param int                                   $page_num  Page number.
	 * @return array<int, array<string, mixed>>|null Empty array if technical post type, otherwise original value.
	 */
	public function filter_posts_pre_url_list( ?array $url_list, string $post_type, int $page_num = 1 ): ?array {
		unset( $page_num );
		if ( $this->is_excluded_technical_post_type( $post_type ) ) {
			return array();
		}

		return $url_list;
	}

	/**
	 * Checks whether a post type is classified as technical content (Category F) excluded from sitemaps.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool True if excluded as technical content.
	 */
	public function is_excluded_technical_post_type( string $post_type ): bool {
		if ( '' === $post_type ) {
			return false;
		}

		$excluded = $this->excluded_technical_post_types;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'tfml_sitemaps_excluded_post_types', $excluded );
			if ( is_array( $filtered ) ) {
				$excluded = array_values( array_map( 'strval', $filtered ) );
			}
		}

		return in_array( $post_type, $excluded, true );
	}

	/**
	 * Suppresses single-request-language filtering for post sitemap queries while marking the query
	 * for SQL-level multilingual sitemap eligibility (DT-TFML-068) and excluding retained translations.
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string               $post_type Post type.
	 * @return array<string, mixed> Modified query arguments.
	 */
	public function filter_posts_query_args( array $args, string $post_type ): array {
		$this->seen_post_urls = array();

		$args[ QueryLanguageFilter::QUERY_VAR_SUPPRESS ] = true;
		$args[ self::QUERY_VAR_SITEMAP_ELIGIBILITY ]     = true;
		$args[ self::QUERY_VAR_SITEMAP_POST_TYPE ]       = $post_type;
		$args['post_status']                             = array( 'publish' );

		$retained_ids = $this->get_retained_element_ids( 'post', $post_type );
		if ( ! empty( $retained_ids ) ) {
			$existing_not_in      = isset( $args['post__not_in'] ) && is_array( $args['post__not_in'] )
				? array_map( 'intval', $args['post__not_in'] )
				: array();
			$args['post__not_in'] = array_values( array_unique( array_merge( $existing_not_in, $retained_ids ) ) );
		}

		return $args;
	}

	/**
	 * Suppresses single-request-language filtering for taxonomy sitemap queries while marking the query
	 * for SQL-level multilingual sitemap eligibility (DT-TFML-068) and excluding retained translations.
	 *
	 * @param array<string, mixed> $args     Query arguments.
	 * @param string               $taxonomy Taxonomy name.
	 * @return array<string, mixed> Modified query arguments.
	 */
	public function filter_taxonomies_query_args( array $args, string $taxonomy ): array {
		$this->seen_term_urls = array();

		$args[ TermQueryLanguageFilter::QUERY_VAR_SUPPRESS ] = true;
		$args[ self::QUERY_VAR_SITEMAP_ELIGIBILITY ]         = true;
		$args[ self::QUERY_VAR_SITEMAP_TAXONOMY ]            = $taxonomy;

		$retained_ids = $this->get_retained_element_ids( 'term', $taxonomy );
		if ( ! empty( $retained_ids ) ) {
			$existing_exclude = isset( $args['exclude'] ) && is_array( $args['exclude'] )
				? array_map( 'intval', $args['exclude'] )
				: array();
			$args['exclude']  = array_values( array_unique( array_merge( $existing_exclude, $retained_ids ) ) );
		}

		return $args;
	}

	/**
	 * Injects SQL JOIN and WHERE eligibility constraints for sitemap post queries (WP_Sitemaps_Posts),
	 * preserving exact pagination, found_posts, max_num_pages, and ordering.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param WP_Query              $query   WP_Query instance.
	 * @return array<string, string> Modified SQL clauses.
	 */
	public function filter_sitemap_posts_clauses( array $clauses, WP_Query $query ): array {
		if ( true !== $query->get( self::QUERY_VAR_SITEMAP_ELIGIBILITY ) ) {
			return $clauses;
		}

		if ( true === $query->get( self::QUERY_VAR_SITEMAP_CLAUSES_APPLIED ) ) {
			return $clauses;
		}

		if ( str_contains( $clauses['join'] ?? '', 'AS tfml_sge' ) ) {
			return $clauses;
		}

		if ( ! isset( $clauses['join'] ) ) {
			$clauses['join'] = '';
		}
		if ( ! isset( $clauses['where'] ) ) {
			$clauses['where'] = '';
		}

		$post_type = (string) $query->get( self::QUERY_VAR_SITEMAP_POST_TYPE );
		if ( '' === $post_type ) {
			$raw_pt    = $query->get( 'post_type' );
			$post_type = is_string( $raw_pt ) ? $raw_pt : '';
		}

		if ( $this->is_excluded_technical_post_type( $post_type ) || ! $this->language_registry->is_configured() ) {
			$clauses['where'] .= ' AND 1=0';
			$query->set( self::QUERY_VAR_SITEMAP_CLAUSES_APPLIED, true );
			return $clauses;
		}

		$active_codes = $this->get_active_language_codes();
		if ( empty( $active_codes ) ) {
			$clauses['where'] .= ' AND 1=0';
			$query->set( self::QUERY_VAR_SITEMAP_CLAUSES_APPLIED, true );
			return $clauses;
		}

		$db             = $this->get_db();
		$prefix         = null !== $db ? $db->prefix : 'wp_';
		$posts_table    = null !== $db && '' !== $db->posts ? $db->posts : $prefix . 'posts';
		$elements_table = $prefix . 'tfml_group_elements';

		$clauses['join'] .= " LEFT JOIN {$elements_table} AS tfml_sge ON (tfml_sge.element_id = {$posts_table}.ID AND tfml_sge.element_type = 'post')";

		$escaped_langs = array_map(
			static fn( string $code ): string => "'" . esc_sql( $code ) . "'",
			$active_codes
		);
		$langs_in_sql  = implode( ', ', $escaped_langs );

		$where_parts   = array();
		$where_parts[] = "{$posts_table}.post_status = 'publish'";

		$retained_ids = $this->get_retained_element_ids( 'post', $post_type );
		if ( ! empty( $retained_ids ) ) {
			$retained_in_sql = implode( ', ', array_map( 'intval', $retained_ids ) );
			$where_parts[]   = "{$posts_table}.ID NOT IN ({$retained_in_sql})";
		}

		if ( $this->is_monolingual_default_policy_active() ) {
			$where_parts[] = "(tfml_sge.language_code IN ({$langs_in_sql}) OR tfml_sge.id IS NULL)";
		} else {
			$accredited_ids = $this->get_accredited_monolingual_ids( 'post', $post_type );
			if ( ! empty( $accredited_ids ) && $this->is_default_language_active() ) {
				$accredited_in_sql = implode( ', ', array_map( 'intval', $accredited_ids ) );
				$where_parts[]     = "(tfml_sge.language_code IN ({$langs_in_sql}) OR (tfml_sge.id IS NULL AND {$posts_table}.ID IN ({$accredited_in_sql})))";
			} else {
				$where_parts[] = "tfml_sge.language_code IN ({$langs_in_sql})";
			}
		}

		$clauses['where'] .= ' AND ' . implode( ' AND ', $where_parts );
		$query->set( self::QUERY_VAR_SITEMAP_CLAUSES_APPLIED, true );

		return $clauses;
	}

	/**
	 * Injects SQL JOIN and WHERE eligibility constraints for sitemap term queries (WP_Sitemaps_Taxonomies),
	 * preserving exact wp_count_terms() and get_url_list() pagination.
	 *
	 * @param array<string, string> $clauses    SQL clauses.
	 * @param array<int, string>    $taxonomies Queried taxonomies.
	 * @param array<string, mixed>  $args       Term query arguments.
	 * @return array<string, string> Modified SQL clauses.
	 */
	public function filter_sitemap_terms_clauses( array $clauses, array $taxonomies, array $args ): array {
		if ( empty( $args[ self::QUERY_VAR_SITEMAP_ELIGIBILITY ] ) ) {
			return $clauses;
		}

		if ( str_contains( $clauses['join'] ?? '', 'AS tfml_ste' ) ) {
			return $clauses;
		}

		if ( ! isset( $clauses['join'] ) ) {
			$clauses['join'] = '';
		}
		if ( ! isset( $clauses['where'] ) ) {
			$clauses['where'] = '';
		}

		$taxonomy = (string) ( $args[ self::QUERY_VAR_SITEMAP_TAXONOMY ] ?? ( $taxonomies[0] ?? '' ) );

		if (
			! $this->language_registry->is_configured()
			|| in_array( 'nav_menu', $taxonomies, true )
			|| 'nav_menu' === $taxonomy
		) {
			$clauses['where'] .= ( '' !== trim( $clauses['where'] ) ? ' AND ' : '' ) . '1=0';
			return $clauses;
		}

		$active_codes = $this->get_active_language_codes();
		if ( empty( $active_codes ) ) {
			$clauses['where'] .= ( '' !== trim( $clauses['where'] ) ? ' AND ' : '' ) . '1=0';
			return $clauses;
		}

		$db             = $this->get_db();
		$prefix         = null !== $db ? $db->prefix : 'wp_';
		$elements_table = $prefix . 'tfml_group_elements';
		$groups_table   = $prefix . 'tfml_groups';

		$clauses['join'] .= " LEFT JOIN {$elements_table} AS tfml_ste ON (tfml_ste.element_id = t.term_id AND tfml_ste.element_type = 'term') LEFT JOIN {$groups_table} AS tfml_stg ON (tfml_stg.id = tfml_ste.group_id)";

		$escaped_langs    = array_map(
			static fn( string $code ): string => "'" . esc_sql( $code ) . "'",
			$active_codes
		);
		$langs_in_sql     = implode( ', ', $escaped_langs );
		$subtype_cond_sql = '' !== $taxonomy
			? " AND tfml_stg.subtype = '" . esc_sql( $taxonomy ) . "'"
			: '';

		$where_parts = array();

		$retained_ids = $this->get_retained_element_ids( 'term', $taxonomy );
		if ( ! empty( $retained_ids ) ) {
			$retained_in_sql = implode( ', ', array_map( 'intval', $retained_ids ) );
			$where_parts[]   = "t.term_id NOT IN ({$retained_in_sql})";
		}

		if ( $this->is_monolingual_default_policy_active() ) {
			$where_parts[] = "((tfml_ste.language_code IN ({$langs_in_sql}){$subtype_cond_sql}) OR tfml_ste.id IS NULL)";
		} else {
			$accredited_ids = $this->get_accredited_monolingual_ids( 'term', $taxonomy );
			if ( ! empty( $accredited_ids ) && $this->is_default_language_active() ) {
				$accredited_in_sql = implode( ', ', array_map( 'intval', $accredited_ids ) );
				$where_parts[]     = "((tfml_ste.language_code IN ({$langs_in_sql}){$subtype_cond_sql}) OR (tfml_ste.id IS NULL AND t.term_id IN ({$accredited_in_sql})))";
			} else {
				$where_parts[] = "(tfml_ste.language_code IN ({$langs_in_sql}){$subtype_cond_sql})";
			}
		}

		$where_sql = implode( ' AND ', $where_parts );
		if ( '' !== trim( $clauses['where'] ) ) {
			$clauses['where'] .= ' AND ' . $where_sql;
		} else {
			$clauses['where'] = $where_sql;
		}

		return $clauses;
	}

	/**
	 * Evaluates a post's sitemap eligibility deterministically according to the 6-category contract (DT-TFML-068).
	 *
	 * @param WP_Post|int|mixed $post      Post object or ID.
	 * @param string            $post_type Optional post type context.
	 * @return array{eligible: bool, category: string, language_code: ?string, reason: string}
	 */
	public function evaluate_post_eligibility( $post, string $post_type = '' ): array {
		$post_obj = $post instanceof WP_Post
			? $post
			: ( function_exists( 'get_post' ) ? get_post( $post ) : ( $GLOBALS['wp_test_posts'][ (int) $post ] ?? null ) );

		if ( ! $post_obj instanceof WP_Post || 'publish' !== $post_obj->post_status ) {
			return array(
				'eligible'      => false,
				'category'      => self::CATEGORY_NON_PUBLIC_STATUS,
				'language_code' => null,
				'reason'        => 'Post does not exist or post_status is not publish.',
			);
		}

		$effective_type = '' !== $post_type ? $post_type : (string) ( $post_obj->post_type ?? '' );
		if ( $this->is_excluded_technical_post_type( $effective_type ) ) {
			return array(
				'eligible'      => false,
				'category'      => self::CATEGORY_TECHNICAL_CONTENT,
				'language_code' => null,
				'reason'        => sprintf( 'Post type "%s" is classified as technical content excluded from public sitemaps.', $effective_type ),
			);
		}

		$post_id = (int) $post_obj->ID;
		if ( $this->is_retained_element( 'post', $post_id ) ) {
			return array(
				'eligible'      => false,
				'category'      => self::CATEGORY_RETAINED_TRANSLATION,
				'language_code' => null,
				'reason'        => 'Post is registered as a retained translation in an inactive language.',
			);
		}

		$lang_code = $this->content_resolver->language_of( 'post', $post_id );
		if ( null !== $lang_code ) {
			if ( ! $this->language_registry->is_active( $lang_code ) ) {
				return array(
					'eligible'      => false,
					'category'      => self::CATEGORY_INACTIVE_LANGUAGE,
					'language_code' => $lang_code,
					'reason'        => sprintf( 'Post is associated with inactive language "%s".', $lang_code ),
				);
			}

			return array(
				'eligible'      => true,
				'category'      => self::CATEGORY_TFML_ACCREDITED,
				'language_code' => $lang_code,
				'reason'        => sprintf( 'Post has accredited TFML association in active language "%s".', $lang_code ),
			);
		}

		if ( $this->is_accredited_monolingual_element( 'post', $post_id, $effective_type ) ) {
			$default      = $this->language_registry->get_default();
			$default_code = null !== $default ? $default->get_code() : null;
			if ( null !== $default_code && $this->language_registry->is_active( $default_code ) ) {
				return array(
					'eligible'      => true,
					'category'      => self::CATEGORY_ACCREDITED_MONOLINGUAL,
					'language_code' => $default_code,
					'reason'        => sprintf( 'Post is accredited as monolingual content in default language "%s".', $default_code ),
				);
			}
		}

		return array(
			'eligible'      => false,
			'category'      => self::CATEGORY_UNCLASSIFIED,
			'language_code' => null,
			'reason'        => 'Post lacks TFML language accreditation and monolingual provenance.',
		);
	}

	/**
	 * Evaluates a taxonomy term's sitemap eligibility deterministically according to the 6-category contract (DT-TFML-068).
	 *
	 * @param WP_Term|int|mixed $term     Term object or ID.
	 * @param string            $taxonomy Taxonomy name.
	 * @return array{eligible: bool, category: string, language_code: ?string, reason: string}
	 */
	public function evaluate_term_eligibility( $term, string $taxonomy ): array {
		$term_obj = $term instanceof WP_Term
			? $term
			: ( function_exists( 'get_term' ) ? get_term( $term, $taxonomy ) : ( $GLOBALS['wp_test_terms'][ (int) $term ] ?? null ) );

		if ( ! $term_obj instanceof WP_Term ) {
			return array(
				'eligible'      => false,
				'category'      => self::CATEGORY_NON_PUBLIC_STATUS,
				'language_code' => null,
				'reason'        => 'Term does not exist or is invalid.',
			);
		}

		if ( 'nav_menu' === $taxonomy ) {
			return array(
				'eligible'      => false,
				'category'      => self::CATEGORY_TECHNICAL_CONTENT,
				'language_code' => null,
				'reason'        => 'Navigation menu terms are structural and excluded from public sitemaps.',
			);
		}

		$term_id = (int) $term_obj->term_id;
		if ( $this->is_retained_element( 'term', $term_id ) ) {
			return array(
				'eligible'      => false,
				'category'      => self::CATEGORY_RETAINED_TRANSLATION,
				'language_code' => null,
				'reason'        => 'Term is registered as a retained translation in an inactive language.',
			);
		}

		$lang_code = $this->content_resolver->language_of( 'term', $term_id );
		if ( null !== $lang_code ) {
			if ( ! $this->language_registry->is_active( $lang_code ) ) {
				return array(
					'eligible'      => false,
					'category'      => self::CATEGORY_INACTIVE_LANGUAGE,
					'language_code' => $lang_code,
					'reason'        => sprintf( 'Term is associated with inactive language "%s".', $lang_code ),
				);
			}

			return array(
				'eligible'      => true,
				'category'      => self::CATEGORY_TFML_ACCREDITED,
				'language_code' => $lang_code,
				'reason'        => sprintf( 'Term has accredited TFML association in active language "%s".', $lang_code ),
			);
		}

		if ( $this->is_accredited_monolingual_element( 'term', $term_id, $taxonomy ) ) {
			$default      = $this->language_registry->get_default();
			$default_code = null !== $default ? $default->get_code() : null;
			if ( null !== $default_code && $this->language_registry->is_active( $default_code ) ) {
				return array(
					'eligible'      => true,
					'category'      => self::CATEGORY_ACCREDITED_MONOLINGUAL,
					'language_code' => $default_code,
					'reason'        => sprintf( 'Term is accredited as monolingual content in default language "%s".', $default_code ),
				);
			}
		}

		return array(
			'eligible'      => false,
			'category'      => self::CATEGORY_UNCLASSIFIED,
			'language_code' => null,
			'reason'        => 'Term lacks TFML language accreditation and monolingual provenance.',
		);
	}

	/**
	 * Localizes post sitemap entry and filters out ineligible, retained, inactive, unclassified, or duplicate entries.
	 *
	 * @param array<string, mixed> $entry     Sitemap entry array.
	 * @param WP_Post|int|mixed    $post      Post object or ID.
	 * @param string               $post_type Post type.
	 * @return array<string, mixed> Localized sitemap entry, or empty array if dropped.
	 */
	public function filter_posts_entry( array $entry, $post, string $post_type ): array {
		if ( empty( $entry ) ) {
			return array();
		}

		$post_obj = $post instanceof WP_Post
			? $post
			: ( function_exists( 'get_post' ) ? get_post( $post ) : ( $GLOBALS['wp_test_posts'][ (int) $post ] ?? null ) );

		if ( ! $post_obj instanceof WP_Post ) {
			return array();
		}

		$evaluation = $this->evaluate_post_eligibility( $post_obj, $post_type );
		if ( ! $evaluation['eligible'] || null === $evaluation['language_code'] ) {
			return array();
		}

		$post_id   = (int) $post_obj->ID;
		$lang_code = $evaluation['language_code'];

		if ( isset( $entry['loc'] ) && is_string( $entry['loc'] ) && '' !== $entry['loc'] ) {
			if ( $this->url_generator->is_front_page_for_language( $post_id, $lang_code ) ) {
				$entry['loc'] = $this->url_generator->home_url( $lang_code );
			} else {
				$entry['loc'] = $this->url_generator->localize_url( $entry['loc'], $lang_code );
			}
		}

		if ( empty( $entry['loc'] ) || ! is_string( $entry['loc'] ) ) {
			return array();
		}

		$loc = $entry['loc'];
		if ( isset( $this->seen_post_urls[ $loc ] ) && $this->seen_post_urls[ $loc ] !== $post_id ) {
			return array();
		}

		$this->seen_post_urls[ $loc ] = $post_id;

		return $entry;
	}

	/**
	 * Localizes taxonomy term sitemap entry and filters out ineligible, retained, inactive, unclassified, or duplicate entries.
	 *
	 * @param array<string, mixed> $entry    Sitemap entry array.
	 * @param WP_Term|int|mixed    $term     Term object or ID.
	 * @param string               $taxonomy Taxonomy name.
	 * @param WP_Term|null         $term_obj Optional WP_Term object passed as 4th argument in WP 6.0+.
	 * @return array<string, mixed> Localized sitemap entry, or empty array if dropped.
	 */
	public function filter_taxonomies_entry( array $entry, $term, string $taxonomy, $term_obj = null ): array {
		if ( empty( $entry ) ) {
			return array();
		}

		$resolved_term = $term_obj instanceof WP_Term
			? $term_obj
			: ( $term instanceof WP_Term
				? $term
				: ( function_exists( 'get_term' ) ? get_term( $term, $taxonomy ) : ( $GLOBALS['wp_test_terms'][ (int) $term ] ?? null ) ) );

		if ( ! $resolved_term instanceof WP_Term ) {
			return array();
		}

		$evaluation = $this->evaluate_term_eligibility( $resolved_term, $taxonomy );
		if ( ! $evaluation['eligible'] || null === $evaluation['language_code'] ) {
			return array();
		}

		$term_id   = (int) $resolved_term->term_id;
		$lang_code = $evaluation['language_code'];

		if ( isset( $entry['loc'] ) && is_string( $entry['loc'] ) && '' !== $entry['loc'] ) {
			$entry['loc'] = $this->url_generator->localize_url( $entry['loc'], $lang_code );
		}

		if ( empty( $entry['loc'] ) || ! is_string( $entry['loc'] ) ) {
			return array();
		}

		$loc = $entry['loc'];
		if ( isset( $this->seen_term_urls[ $loc ] ) && $this->seen_term_urls[ $loc ] !== $term_id ) {
			return array();
		}

		$this->seen_term_urls[ $loc ] = $term_id;

		return $entry;
	}

	/**
	 * Sets the sitemap eligibility policy.
	 *
	 * @param string $policy Policy constant (POLICY_CONSERVATIVE_ACCREDITED_ONLY or POLICY_MONOLINGUAL_DEFAULT).
	 * @return void
	 */
	public function set_eligibility_policy( string $policy ): void {
		if (
			self::POLICY_CONSERVATIVE_ACCREDITED_ONLY === $policy
			|| self::POLICY_MONOLINGUAL_DEFAULT === $policy
		) {
			$this->eligibility_policy = $policy;
		}
	}

	/**
	 * Gets the active sitemap eligibility policy.
	 *
	 * @return string
	 */
	public function get_eligibility_policy(): string {
		return $this->eligibility_policy;
	}

	/**
	 * Explicitly accredits an unmanaged element as legitimately monolingual in the default language (Category B).
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   Element ID.
	 * @return void
	 */
	public function accredit_monolingual_element( string $element_type, int $element_id ): void {
		if ( $element_id <= 0 || ! in_array( $element_type, array( 'post', 'term' ), true ) ) {
			return;
		}

		$this->accredited_monolingual_ids[ $element_type ][ $element_id ] = $element_id;
	}

	/**
	 * Clears explicitly accredited monolingual elements.
	 *
	 * @param string|null $element_type Optional element type ('post' or 'term'), or null for all.
	 * @return void
	 */
	public function clear_accredited_monolingual_elements( ?string $element_type = null ): void {
		if ( null === $element_type ) {
			$this->accredited_monolingual_ids = array(
				'post' => array(),
				'term' => array(),
			);
			return;
		}

		if ( isset( $this->accredited_monolingual_ids[ $element_type ] ) ) {
			$this->accredited_monolingual_ids[ $element_type ] = array();
		}
	}

	/**
	 * Resets the per-sitemap URL deduplication state.
	 *
	 * @return void
	 */
	public function reset_url_deduplication_state(): void {
		$this->seen_post_urls = array();
		$this->seen_term_urls = array();
	}

	/**
	 * Checks whether an unmanaged element is accredited as monolingual in the default language (Category B).
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   Element ID.
	 * @param string $subtype      Optional subtype (post_type or taxonomy).
	 * @return bool True if accredited monolingual.
	 */
	public function is_accredited_monolingual_element( string $element_type, int $element_id, string $subtype = '' ): bool {
		if ( $element_id <= 0 || ! $this->is_default_language_active() ) {
			return false;
		}

		if ( $this->is_monolingual_default_policy_active() ) {
			return true;
		}

		$accredited_ids = $this->get_accredited_monolingual_ids( $element_type, $subtype );
		if ( in_array( $element_id, $accredited_ids, true ) ) {
			return true;
		}

		if ( function_exists( 'apply_filters' ) ) {
			return (bool) apply_filters( 'tfml_is_accredited_monolingual_element', false, $element_type, $element_id, $subtype );
		}

		return false;
	}

	/**
	 * Returns all explicitly accredited monolingual IDs for a given element type and subtype.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param string $subtype      Optional subtype.
	 * @return array<int, int> List of accredited element IDs.
	 */
	public function get_accredited_monolingual_ids( string $element_type, string $subtype = '' ): array {
		$ids = isset( $this->accredited_monolingual_ids[ $element_type ] )
			? array_values( $this->accredited_monolingual_ids[ $element_type ] )
			: array();

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'tfml_sitemap_accredited_monolingual_ids', $ids, $element_type, $subtype );
			if ( is_array( $filtered ) ) {
				$ids = array_map( 'intval', $filtered );
			}
		}

		$ids = array_filter( $ids, static fn( int $id ): bool => $id > 0 );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Checks whether the default language is configured and active.
	 *
	 * @return bool
	 */
	private function is_default_language_active(): bool {
		$default = $this->language_registry->get_default();
		if ( null === $default ) {
			return false;
		}

		return $this->language_registry->is_active( $default->get_code() );
	}

	/**
	 * Checks whether the monolingual default policy is active.
	 *
	 * @return bool
	 */
	private function is_monolingual_default_policy_active(): bool {
		return self::POLICY_MONOLINGUAL_DEFAULT === $this->eligibility_policy && $this->is_default_language_active();
	}

	/**
	 * Returns all active language codes from the language registry.
	 *
	 * @return array<int, string>
	 */
	private function get_active_language_codes(): array {
		if ( ! $this->language_registry->is_configured() ) {
			return array();
		}

		$active = $this->language_registry->active();
		if ( empty( $active ) ) {
			return array();
		}

		$codes = array();
		foreach ( $active as $language ) {
			$codes[] = $language->get_code();
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Checks whether an element is currently recorded as a retained translation in an inactive language.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   Element ID.
	 * @return bool True if retained and not yet promoted.
	 */
	private function is_retained_element( string $element_type, int $element_id ): bool {
		if ( $element_id <= 0 ) {
			return false;
		}

		$registry = $this->get_retained_registry();
		if ( null === $registry ) {
			return false;
		}

		return $registry->has_retained_element( $element_type, $element_id );
	}

	/**
	 * Retrieves all retained element IDs for a given element type and optional subtype.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param string $subtype      Optional subtype (post_type or taxonomy).
	 * @return array<int, int> List of retained element IDs.
	 */
	private function get_retained_element_ids( string $element_type, string $subtype = '' ): array {
		$registry = $this->get_retained_registry();
		if ( null === $registry ) {
			return array();
		}

		$records = $registry->get_all();
		if ( empty( $records ) ) {
			return array();
		}

		$ids = array();
		foreach ( $records as $record ) {
			$rec_type    = (string) ( $record['element_type'] ?? '' );
			$rec_subtype = (string) ( $record['subtype'] ?? '' );

			if ( $rec_type !== $element_type ) {
				continue;
			}

			if ( '' !== $subtype && '' !== $rec_subtype && $rec_subtype !== $subtype ) {
				continue;
			}

			$retained_elements = (array) ( $record['retained_elements'] ?? array() );
			foreach ( $retained_elements as $lang_code => $item ) {
				$status = (string) ( $item['status'] ?? RetainedTranslationRegistry::STATUS_RETAINED_INACTIVE_LANGUAGE );
				if ( RetainedTranslationRegistry::STATUS_PROMOTED === $status ) {
					continue;
				}
				if ( $this->language_registry->is_active( (string) $lang_code ) ) {
					continue;
				}
				$elem_id = (int) ( $item['element_id'] ?? 0 );
				if ( $elem_id > 0 ) {
					$ids[] = $elem_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Lazily resolves the retained translation registry instance.
	 *
	 * @return RetainedTranslationRegistry|null
	 */
	private function get_retained_registry(): ?RetainedTranslationRegistry {
		if ( null === $this->retained_registry ) {
			$this->retained_registry = new RetainedTranslationRegistry( $this->get_db() );
		}

		return $this->retained_registry;
	}

	/**
	 * Lazily resolves the wpdb instance.
	 *
	 * @return wpdb|null
	 */
	private function get_db(): ?wpdb {
		if ( null === $this->db ) {
			global $wpdb;
			if ( $wpdb instanceof wpdb ) {
				$this->db = $wpdb;
			}
		}

		return $this->db;
	}
}
