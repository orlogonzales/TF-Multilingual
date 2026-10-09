<?php
/**
 * Canonical URL Manager.
 *
 * @package TF\Multilingual\Domain\Seo
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Seo;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;
use WP_Post;
use WP_Term;

/**
 * Class CanonicalUrlManager
 *
 * Manages localized canonical URLs for WordPress Core and third-party SEO plugins
 * (Yoast SEO, Rank Math), guaranteeing that each translation variant points strictly
 * to its own localized canonical URL and avoiding duplicate canonical tags.
 */
class CanonicalUrlManager {

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
	 * URL language resolver.
	 *
	 * @var UrlLanguageResolver
	 */
	private UrlLanguageResolver $url_resolver;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry           $language_registry Language registry.
	 * @param ContentTranslationResolver $content_resolver  Content translation resolver.
	 * @param LocalizedUrlGenerator      $url_generator     Localized URL generator.
	 * @param UrlLanguageResolver        $url_resolver      URL language resolver.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		ContentTranslationResolver $content_resolver,
		LocalizedUrlGenerator $url_generator,
		UrlLanguageResolver $url_resolver
	) {
		$this->language_registry = $language_registry;
		$this->content_resolver  = $content_resolver;
		$this->url_generator     = $url_generator;
		$this->url_resolver      = $url_resolver;
	}

	/**
	 * Registers hooks for WordPress Core and third-party SEO plugins.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'wp_get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );
		add_filter( 'wpseo_canonical', array( $this, 'filter_third_party_canonical' ), 10, 1 );
		add_filter( 'rank_math/canonical_url', array( $this, 'filter_third_party_canonical' ), 10, 1 );
	}

	/**
	 * Filters canonical URL for WordPress Core.
	 *
	 * @param string             $canonical Canonical URL.
	 * @param WP_Post|int|null   $post      Optional post object or post ID.
	 * @return string Localized canonical URL.
	 */
	public function filter_canonical_url( string $canonical, $post = null ): string {
		if ( '' === trim( $canonical ) ) {
			return $canonical;
		}

		if ( ! $this->language_registry->is_configured() ) {
			return $canonical;
		}

		// 1. If post context is explicitly provided.
		$post_id = 0;
		if ( $post instanceof WP_Post ) {
			$post_id = (int) $post->ID;
		} elseif ( is_numeric( $post ) && (int) $post > 0 ) {
			$post_id = (int) $post;
		}

		if ( $post_id > 0 ) {
			return $this->localize_post_canonical( $canonical, $post_id );
		}

		// 2. If singular context.
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$queried_post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
			if ( $queried_post_id > 0 ) {
				return $this->localize_post_canonical( $canonical, $queried_post_id );
			}
		}

		// 3. If taxonomy term context.
		if ( function_exists( 'is_category' ) && ( is_category() || is_tag() || is_tax() ) ) {
			$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
			if ( $term instanceof WP_Term ) {
				$term_lang = $this->content_resolver->language_of( 'term', (int) $term->term_id );
				if ( null === $term_lang ) {
					$default   = $this->language_registry->get_default();
					$term_lang = null !== $default ? $default->get_code() : null;
				}

				if ( null !== $term_lang && $this->language_registry->is_active( $term_lang ) ) {
					return $this->url_generator->localize_url( $canonical, $term_lang );
				}
			}
		}

		// 4. Front page / latest posts home.
		if ( function_exists( 'is_front_page' ) && ( is_front_page() || is_home() ) ) {
			$lang = $this->url_resolver->resolve_from_url( $canonical );
			if ( null !== $lang && $this->language_registry->is_active( $lang ) ) {
				return $this->url_generator->home_url( $lang );
			}

			$default = $this->language_registry->get_default();
			if ( null !== $default && $this->language_registry->is_active( $default->get_code() ) ) {
				return $this->url_generator->home_url( $default->get_code() );
			}
		}

		// 5. General fallback: resolve language from URL itself.
		$lang = $this->url_resolver->resolve_from_url( $canonical );
		if ( null !== $lang && $this->language_registry->is_active( $lang ) ) {
			return $this->url_generator->localize_url( $canonical, $lang );
		}

		return $canonical;
	}

	/**
	 * Filters canonical URL for third-party SEO plugins (Yoast, Rank Math).
	 *
	 * @param mixed $canonical Canonical URL string or boolean false.
	 * @return mixed Filtered canonical URL or original value.
	 */
	public function filter_third_party_canonical( $canonical ) {
		if ( ! is_string( $canonical ) || '' === trim( $canonical ) ) {
			return $canonical;
		}

		return $this->filter_canonical_url( $canonical, null );
	}

	/**
	 * Localizes canonical URL for a specific post ID.
	 *
	 * @param string $canonical Canonical URL.
	 * @param int    $post_id   Post ID.
	 * @return string Localized canonical URL.
	 */
	private function localize_post_canonical( string $canonical, int $post_id ): string {
		$lang_code = $this->content_resolver->language_of( 'post', $post_id );

		if ( null === $lang_code ) {
			$default   = $this->language_registry->get_default();
			$lang_code = null !== $default ? $default->get_code() : null;
		}

		if ( null === $lang_code || ! $this->language_registry->is_active( $lang_code ) ) {
			return $canonical;
		}

		return $this->url_generator->localize_url( $canonical, $lang_code );
	}
}
