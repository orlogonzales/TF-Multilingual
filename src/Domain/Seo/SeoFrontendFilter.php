<?php
/**
 * SEO Frontend Filter.
 *
 * @package TF\Multilingual\Domain\Seo
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Seo;

/**
 * Class SeoFrontendFilter
 *
 * Coordinates frontend SEO integrations for TF Multilingual:
 * - Emits multilingual hreflang link tags into <head>.
 * - Coordinates canonical URL management across core and third-party SEO plugins.
 * - Coordinates core XML sitemaps filtering.
 */
class SeoFrontendFilter {

	/**
	 * Hreflang generator.
	 *
	 * @var HreflangGenerator
	 */
	private HreflangGenerator $hreflang_generator;

	/**
	 * Canonical URL manager.
	 *
	 * @var CanonicalUrlManager
	 */
	private CanonicalUrlManager $canonical_manager;

	/**
	 * Core sitemaps filter.
	 *
	 * @var CoreSitemapsFilter
	 */
	private CoreSitemapsFilter $sitemaps_filter;

	/**
	 * Constructor.
	 *
	 * @param HreflangGenerator   $hreflang_generator Hreflang generator.
	 * @param CanonicalUrlManager $canonical_manager  Canonical URL manager.
	 * @param CoreSitemapsFilter  $sitemaps_filter    Core sitemaps filter.
	 */
	public function __construct(
		HreflangGenerator $hreflang_generator,
		CanonicalUrlManager $canonical_manager,
		CoreSitemapsFilter $sitemaps_filter
	) {
		$this->hreflang_generator = $hreflang_generator;
		$this->canonical_manager  = $canonical_manager;
		$this->sitemaps_filter    = $sitemaps_filter;
	}

	/**
	 * Registers SEO frontend and sitemap hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_head', array( $this, 'render_hreflang_tags' ), 2 );
		}

		$this->canonical_manager->init_hooks();
		$this->sitemaps_filter->init_hooks();
	}

	/**
	 * Renders alternate hreflang link tags into <head>.
	 *
	 * @return void
	 */
	public function render_hreflang_tags(): void {
		$variants = $this->hreflang_generator->get_hreflang_variants();

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters alternate hreflang variants prior to HTML output.
			 *
			 * @param array<string, string> $variants Map of hreflang code => localized URL.
			 */
			$variants = apply_filters( 'tfml_hreflang_variants', $variants );
		}

		if ( empty( $variants ) || ! is_array( $variants ) ) {
			return;
		}

		foreach ( $variants as $hreflang => $url ) {
			if ( ! is_string( $hreflang ) || ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$safe_hreflang = function_exists( 'esc_attr' ) ? esc_attr( $hreflang ) : htmlspecialchars( $hreflang, ENT_QUOTES, 'UTF-8' );
			$safe_url      = function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );

			printf(
				"<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n",
				$safe_hreflang,
				$safe_url
			);
		}
	}

	/**
	 * Gets the hreflang generator.
	 *
	 * @return HreflangGenerator
	 */
	public function get_hreflang_generator(): HreflangGenerator {
		return $this->hreflang_generator;
	}

	/**
	 * Gets the canonical URL manager.
	 *
	 * @return CanonicalUrlManager
	 */
	public function get_canonical_manager(): CanonicalUrlManager {
		return $this->canonical_manager;
	}

	/**
	 * Gets the core sitemaps filter.
	 *
	 * @return CoreSitemapsFilter
	 */
	public function get_sitemaps_filter(): CoreSitemapsFilter {
		return $this->sitemaps_filter;
	}
}
