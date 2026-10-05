<?php
/**
 * Current Language Resolver.
 *
 * @package TF\Multilingual\Routing
 */

declare( strict_types=1 );

namespace TF\Multilingual\Routing;

use TF\Multilingual\Domain\Language\Language;

/**
 * Class CurrentLanguageResolver
 *
 * Exposes the authoritative current request language across TFML.
 *
 * Sovereign Source: URL via UrlLanguageResolver. Never inferred from get_locale() or browser headers.
 */
class CurrentLanguageResolver {

	/**
	 * URL language resolver.
	 *
	 * @var UrlLanguageResolver
	 */
	private UrlLanguageResolver $url_resolver;

	/**
	 * Explicitly set or cached current language code.
	 *
	 * @var string|null
	 */
	private ?string $current_language = null;

	/**
	 * Cached resolution object for the current request.
	 *
	 * @var UrlLanguageResolution|null
	 */
	private ?UrlLanguageResolution $current_resolution = null;

	/**
	 * Constructor.
	 *
	 * @param UrlLanguageResolver $url_resolver URL language resolver.
	 */
	public function __construct( UrlLanguageResolver $url_resolver ) {
		$this->url_resolver = $url_resolver;
	}

	/**
	 * Returns the authoritative current language code for the request.
	 *
	 * @return string|null Resolved canonical language code, or null if unconfigured/excluded.
	 */
	public function get_current_language(): ?string {
		if ( null !== $this->current_language ) {
			return $this->current_language;
		}

		$resolution             = $this->get_current_language_resolution();
		$this->current_language = $resolution->is_valid() ? $resolution->get_language_code() : null;

		return $this->current_language;
	}

	/**
	 * Returns the full resolution object for the current request.
	 *
	 * @return UrlLanguageResolution
	 */
	public function get_current_language_resolution(): UrlLanguageResolution {
		if ( null !== $this->current_resolution ) {
			return $this->current_resolution;
		}

		$uri                      = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$this->current_resolution = $this->url_resolver->resolve( $uri );

		return $this->current_resolution;
	}

	/**
	 * Manually sets the current language (e.g. during testing, CLI, or admin simulation).
	 *
	 * @param string|null $language_code Language code.
	 * @return void
	 */
	public function set_current_language( ?string $language_code ): void {
		$this->current_language   = null !== $language_code ? Language::normalize_code( $language_code ) : null;
		$this->current_resolution = null;
	}

	/**
	 * Resets cached state.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->current_language   = null;
		$this->current_resolution = null;
	}
}
