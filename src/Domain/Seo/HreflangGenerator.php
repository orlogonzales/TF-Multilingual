<?php
/**
 * Hreflang Generator.
 *
 * @package TF\Multilingual\Domain\Seo
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Seo;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use WP_Post;
use WP_Term;

/**
 * Class HreflangGenerator
 *
 * Discovers and builds alternate hreflang link tags for published, indexable
 * multilingual variants in compliance with search engine specifications.
 *
 * Core Invariants:
 * 1. Only published, indexable content in active languages is included.
 * 2. REVIEW status is an editorial freshness state, not publication state: published
 *    content requiring review remains indexable and is included.
 * 3. x-default points strictly to the default language variant only when a valid
 *    published variant in that default language exists.
 */
class HreflangGenerator {

	/**
	 * Language registry.
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
	 * @param TranslationGroupRepository $group_repository  Translation group repository.
	 * @param ContentTranslationResolver $content_resolver  Content translation resolver.
	 * @param LocalizedUrlGenerator      $url_generator     Localized URL generator.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		TranslationGroupRepository $group_repository,
		ContentTranslationResolver $content_resolver,
		LocalizedUrlGenerator $url_generator
	) {
		$this->language_registry = $language_registry;
		$this->group_repository  = $group_repository;
		$this->content_resolver  = $content_resolver;
		$this->url_generator     = $url_generator;
	}

	/**
	 * Discovers all alternate hreflang variants for the current request.
	 *
	 * @return array<string, string> Map of hreflang code => full canonical URL.
	 */
	public function get_hreflang_variants(): array {
		if ( ! $this->is_eligible_context() ) {
			return array();
		}

		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
			if ( $post_id > 0 ) {
				return $this->get_post_hreflang_variants( $post_id );
			}
		}

		if ( function_exists( 'is_category' ) && ( is_category() || is_tag() || is_tax() ) ) {
			$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
			if ( $term instanceof WP_Term ) {
				return $this->get_term_hreflang_variants( (int) $term->term_id, $term->taxonomy );
			}
		}

		if ( function_exists( 'is_front_page' ) && ( is_front_page() || is_home() ) ) {
			return $this->get_front_page_hreflang_variants();
		}

		return array();
	}

	/**
	 * Discovers alternate hreflang variants for a specific post.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array<string, string> Map of hreflang code => localized URL.
	 */
	public function get_post_hreflang_variants( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : ( $GLOBALS['wp_test_posts'][ $post_id ] ?? null );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return array();
		}

		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : 'es';

		$variants = array();
		$group    = $this->group_repository->find_by_element( 'post', $post_id );

		if ( null === $group ) {
			// Single unassigned post.
			$lang_code = $this->content_resolver->language_of( 'post', $post_id );
			if ( null === $lang_code ) {
				$lang_code = $default_code;
			}

			if ( $this->language_registry->is_active( $lang_code ) ) {
				$permalink = function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : '';
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$url                    = $this->url_generator->localize_url( $permalink, $lang_code );
					$variants[ $lang_code ] = $url;
					if ( $lang_code === $default_code ) {
						$variants['x-default'] = $url;
					}
				}
			}

			return $variants;
		}

		// Member of translation group: inspect all siblings.
		foreach ( $group->get_elements() as $elem ) {
			$lang_code = $elem->get_language_code();
			if ( ! $this->language_registry->is_active( $lang_code ) ) {
				continue;
			}

			$sibling_id   = $elem->get_element_id();
			$sibling_post = function_exists( 'get_post' ) ? get_post( $sibling_id ) : ( $GLOBALS['wp_test_posts'][ $sibling_id ] ?? null );

			// Strict publication invariant: only published siblings are indexed.
			if ( ! $sibling_post instanceof WP_Post || 'publish' !== $sibling_post->post_status ) {
				continue;
			}

			$permalink = function_exists( 'get_permalink' ) ? get_permalink( $sibling_id ) : '';
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$variants[ $lang_code ] = $this->url_generator->localize_url( $permalink, $lang_code );
			}
		}

		// x-default strictly points to default language variant if valid and published.
		if ( isset( $variants[ $default_code ] ) ) {
			$variants['x-default'] = $variants[ $default_code ];
		}

		return $variants;
	}

	/**
	 * Discovers alternate hreflang variants for a taxonomy term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<string, string> Map of hreflang code => localized URL.
	 */
	public function get_term_hreflang_variants( int $term_id, string $taxonomy ): array {
		if ( $term_id <= 0 || '' === $taxonomy ) {
			return array();
		}

		$term = function_exists( 'get_term' ) ? get_term( $term_id, $taxonomy ) : ( $GLOBALS['wp_test_terms'][ $term_id ] ?? null );
		if ( ! $term instanceof WP_Term ) {
			return array();
		}

		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : 'es';

		$variants = array();
		$group    = $this->group_repository->find_by_element( 'term', $term_id );

		if ( null === $group ) {
			$lang_code = $this->content_resolver->language_of( 'term', $term_id );
			if ( null === $lang_code ) {
				$lang_code = $default_code;
			}

			if ( $this->language_registry->is_active( $lang_code ) ) {
				$term_link = function_exists( 'get_term_link' ) ? get_term_link( $term, $taxonomy ) : '';
				if ( is_string( $term_link ) && '' !== $term_link ) {
					$url                    = $this->url_generator->localize_url( $term_link, $lang_code );
					$variants[ $lang_code ] = $url;
					if ( $lang_code === $default_code ) {
						$variants['x-default'] = $url;
					}
				}
			}

			return $variants;
		}

		foreach ( $group->get_elements() as $elem ) {
			$lang_code = $elem->get_language_code();
			if ( ! $this->language_registry->is_active( $lang_code ) ) {
				continue;
			}

			$sibling_id   = $elem->get_element_id();
			$sibling_term = function_exists( 'get_term' ) ? get_term( $sibling_id, $taxonomy ) : ( $GLOBALS['wp_test_terms'][ $sibling_id ] ?? null );
			if ( ! $sibling_term instanceof WP_Term ) {
				continue;
			}

			$term_link = function_exists( 'get_term_link' ) ? get_term_link( $sibling_term, $taxonomy ) : '';
			if ( is_string( $term_link ) && '' !== $term_link ) {
				$variants[ $lang_code ] = $this->url_generator->localize_url( $term_link, $lang_code );
			}
		}

		if ( isset( $variants[ $default_code ] ) ) {
			$variants['x-default'] = $variants[ $default_code ];
		}

		return $variants;
	}

	/**
	 * Discovers alternate hreflang variants for front page / latest posts home.
	 *
	 * @return array<string, string> Map of hreflang code => localized URL.
	 */
	public function get_front_page_hreflang_variants(): array {
		$variants     = array();
		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : 'es';

		foreach ( $this->language_registry->active() as $lang ) {
			$code              = $lang->get_code();
			$variants[ $code ] = $this->url_generator->home_url( $code );
		}

		if ( isset( $variants[ $default_code ] ) ) {
			$variants['x-default'] = $variants[ $default_code ];
		}

		return $variants;
	}

	/**
	 * Verifies whether the current context is eligible for hreflang output.
	 *
	 * Excludes 404, search results, admin, feeds, trackbacks, and preview requests.
	 *
	 * @return bool True if eligible.
	 */
	public function is_eligible_context(): bool {
		if ( ! $this->language_registry->is_configured() ) {
			return false;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}

		if ( function_exists( 'is_trackback' ) && is_trackback() ) {
			return false;
		}

		if ( function_exists( 'is_404' ) && is_404() ) {
			return false;
		}

		if ( function_exists( 'is_search' ) && is_search() ) {
			return false;
		}

		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return false;
		}

		return true;
	}
}
