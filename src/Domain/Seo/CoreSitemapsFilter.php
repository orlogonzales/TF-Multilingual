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
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use WP_Post;
use WP_Term;

/**
 * Class CoreSitemapsFilter
 *
 * Adapts WordPress Core native XML Sitemaps (wp_sitemaps) for multilingual content:
 * - Ensures sitemap queries retrieve entries across all languages without being restricted to current request language.
 * - Localizes entry URLs according to each post or term's language.
 * - Drops draft, non-published, or inactive language entries from the sitemap.
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
	 * Constructor.
	 *
	 * @param LanguageRegistry           $language_registry Language registry.
	 * @param ContentTranslationResolver $content_resolver  Content translation resolver.
	 * @param LocalizedUrlGenerator      $url_generator     Localized URL generator.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		ContentTranslationResolver $content_resolver,
		LocalizedUrlGenerator $url_generator
	) {
		$this->language_registry = $language_registry;
		$this->content_resolver  = $content_resolver;
		$this->url_generator     = $url_generator;
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
	 * Suppresses TFML query language filtering for post sitemap queries so that all multilingual variants are indexed.
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string               $post_type Post type.
	 * @return array<string, mixed> Modified query arguments.
	 */
	public function filter_posts_query_args( array $args, string $post_type ): array {
		$args['tfml_suppress_filters'] = true;
		return $args;
	}

	/**
	 * Suppresses TFML query language filtering for taxonomy sitemap queries so that all multilingual terms are indexed.
	 *
	 * @param array<string, mixed> $args     Query arguments.
	 * @param string               $taxonomy Taxonomy name.
	 * @return array<string, mixed> Modified query arguments.
	 */
	public function filter_taxonomies_query_args( array $args, string $taxonomy ): array {
		$args['tfml_suppress_filters'] = true;
		return $args;
	}

	/**
	 * Localizes post sitemap entry and filters out drafts or inactive languages.
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

		$lang_code = $this->content_resolver->language_of( 'post', (int) $post_obj->ID );
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
	 * Localizes taxonomy term sitemap entry and filters out inactive languages.
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

		$lang_code = $this->content_resolver->language_of( 'term', (int) $term_obj->term_id );
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
}
