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
use WP_Term;

/**
 * Class CoreSitemapsFilter
 *
 * Adapts WordPress Core native XML Sitemaps (wp_sitemaps) for multilingual content:
 * - Ensures sitemap queries retrieve entries across all languages without being restricted to current request language.
 * - Localizes entry URLs according to each post or term's language.
 * - Drops draft, non-published, retained, or inactive language entries from the sitemap.
 */
class CoreSitemapsFilter {

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
	 * Constructor.
	 *
	 * @param LanguageRegistry                 $language_registry Language registry.
	 * @param ContentTranslationResolver       $content_resolver  Content translation resolver.
	 * @param LocalizedUrlGenerator            $url_generator     Localized URL generator.
	 * @param RetainedTranslationRegistry|null $retained_registry Optional retained translation registry.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		ContentTranslationResolver $content_resolver,
		LocalizedUrlGenerator $url_generator,
		?RetainedTranslationRegistry $retained_registry = null
	) {
		$this->language_registry = $language_registry;
		$this->content_resolver  = $content_resolver;
		$this->url_generator     = $url_generator;
		$this->retained_registry = $retained_registry;
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

		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_posts_query_args' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'filter_taxonomies_query_args' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'filter_posts_entry' ), 10, 3 );
		add_filter( 'wp_sitemaps_taxonomies_entry', array( $this, 'filter_taxonomies_entry' ), 10, 3 );
	}

	/**
	 * Suppresses TFML query language filtering for post sitemap queries so that all active multilingual variants are indexed,
	 * while excluding retained translations belonging to inactive languages.
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string               $post_type Post type.
	 * @return array<string, mixed> Modified query arguments.
	 */
	public function filter_posts_query_args( array $args, string $post_type ): array {
		$args[ QueryLanguageFilter::QUERY_VAR_SUPPRESS ] = true;

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
	 * Suppresses TFML query language filtering for taxonomy sitemap queries so that all active multilingual terms are indexed,
	 * while excluding retained translations belonging to inactive languages.
	 *
	 * @param array<string, mixed> $args     Query arguments.
	 * @param string               $taxonomy Taxonomy name.
	 * @return array<string, mixed> Modified query arguments.
	 */
	public function filter_taxonomies_query_args( array $args, string $taxonomy ): array {
		$args[ TermQueryLanguageFilter::QUERY_VAR_SUPPRESS ] = true;

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
	 * Localizes post sitemap entry and filters out drafts, retained translations, or inactive languages.
	 *
	 * @param array<string, mixed> $entry     Sitemap entry array.
	 * @param WP_Post              $post      Post object.
	 * @param string               $post_type Post type.
	 * @return array<string, mixed> Localized sitemap entry, or empty array if dropped.
	 */
	public function filter_posts_entry( array $entry, $post, string $post_type ): array {
		if ( empty( $entry ) ) {
			return array();
		}

		$post_obj = $post instanceof WP_Post ? $post : ( function_exists( 'get_post' ) ? get_post( $post ) : ( $GLOBALS['wp_test_posts'][ (int) $post ] ?? null ) );
		if ( ! $post_obj instanceof WP_Post || 'publish' !== $post_obj->post_status ) {
			return array();
		}

		$post_id = (int) $post_obj->ID;
		if ( $this->is_retained_element( 'post', $post_id ) ) {
			return array();
		}

		$lang_code = $this->content_resolver->language_of( 'post', $post_id );
		if ( null === $lang_code ) {
			$default   = $this->language_registry->get_default();
			$lang_code = null !== $default ? $default->get_code() : null;
		}

		if ( null === $lang_code || ! $this->language_registry->is_active( $lang_code ) ) {
			return array();
		}

		if ( isset( $entry['loc'] ) && is_string( $entry['loc'] ) && '' !== $entry['loc'] ) {
			if ( $this->url_generator->is_front_page_for_language( $post_id, $lang_code ) ) {
				$entry['loc'] = $this->url_generator->home_url( $lang_code );
			} else {
				$entry['loc'] = $this->url_generator->localize_url( $entry['loc'], $lang_code );
			}
		}

		return $entry;
	}

	/**
	 * Localizes taxonomy term sitemap entry and filters out retained translations or inactive languages.
	 *
	 * @param array<string, mixed> $entry    Sitemap entry array.
	 * @param WP_Term              $term     Term object.
	 * @param string               $taxonomy Taxonomy name.
	 * @return array<string, mixed> Localized sitemap entry, or empty array if dropped.
	 */
	public function filter_taxonomies_entry( array $entry, $term, string $taxonomy ): array {
		if ( empty( $entry ) ) {
			return array();
		}

		$term_obj = $term instanceof WP_Term ? $term : ( function_exists( 'get_term' ) ? get_term( $term, $taxonomy ) : ( $GLOBALS['wp_test_terms'][ (int) $term ] ?? null ) );
		if ( ! $term_obj instanceof WP_Term ) {
			return array();
		}

		$term_id = (int) $term_obj->term_id;
		if ( $this->is_retained_element( 'term', $term_id ) ) {
			return array();
		}

		$lang_code = $this->content_resolver->language_of( 'term', $term_id );
		if ( null === $lang_code ) {
			$default   = $this->language_registry->get_default();
			$lang_code = null !== $default ? $default->get_code() : null;
		}

		if ( null === $lang_code || ! $this->language_registry->is_active( $lang_code ) ) {
			return array();
		}

		if ( isset( $entry['loc'] ) && is_string( $entry['loc'] ) && '' !== $entry['loc'] ) {
			$entry['loc'] = $this->url_generator->localize_url( $entry['loc'], $lang_code );
		}

		return $entry;
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
			$this->retained_registry = new RetainedTranslationRegistry();
		}

		return $this->retained_registry;
	}
}
