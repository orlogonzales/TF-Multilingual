<?php
/**
 * Localized URL Generator.
 *
 * @package TF\Multilingual\Routing
 */

declare( strict_types=1 );

namespace TF\Multilingual\Routing;

use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;

/**
 * Class LocalizedUrlGenerator
 *
 * Generates multilingual URLs for home, posts, terms and arbitrary WordPress URLs.
 *
 * Sovereign Architectural Principles:
 * - WordPress continues to resolve WordPress. TFML adds only the linguistic dimension.
 * - Posts / Pages / CPT URLs are obtained from get_permalink().
 * - Term URLs are obtained from get_term_link().
 * - No parallel custom permalink generation (no post_type + slug reconstruction).
 * - External URLs are preserved unmodified (never converted to internal).
 * - Query strings, URL fragments, custom ports, and subdirectory paths are strictly preserved.
 * - Default language URLs are unprefixed.
 * - Secondary language URLs are prefixed with /{language_code}/.
 * - Double prefixes are strictly prevented.
 */
class LocalizedUrlGenerator {

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * URL language resolver.
	 *
	 * @var UrlLanguageResolver
	 */
	private UrlLanguageResolver $url_resolver;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $content_resolver;

	/**
	 * Optional home URL override.
	 *
	 * @var string|null
	 */
	private ?string $home_url;

	/**
	 * Reentrancy guard to prevent recursive link resolution loops.
	 *
	 * @var bool
	 */
	private bool $is_resolving_link = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry           $language_registry Language registry.
	 * @param UrlLanguageResolver        $url_resolver     URL language resolver.
	 * @param ContentTranslationResolver $content_resolver Content translation resolver.
	 * @param string|null                $home_url         Optional home URL override.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		UrlLanguageResolver $url_resolver,
		ContentTranslationResolver $content_resolver,
		?string $home_url = null
	) {
		$this->language_registry = $language_registry;
		$this->url_resolver      = $url_resolver;
		$this->content_resolver  = $content_resolver;
		$this->home_url          = $home_url;
	}

	/**
	 * Generates the localized home URL for a given language code.
	 *
	 * Obtains base home URL from WordPress Core native home_url() and applies language localization.
	 *
	 * @param string $language_code Target canonical language code.
	 * @param string $path          Optional path relative to home.
	 * @return string Localized home URL.
	 * @throws LanguageNotFoundException If language is unregistered.
	 * @throws InvalidLanguageException If language is inactive.
	 */
	public function home_url( string $language_code, string $path = '' ): string {
		$code = $this->validate_target_language( $language_code );

		$resolver_home = $this->url_resolver->get_home_url();
		$base_home_url = ! empty( $this->home_url )
			? $this->home_url
			: ( ! empty( $resolver_home )
				? $resolver_home
				: ( function_exists( 'home_url' ) ? (string) home_url( $path ) : 'http://localhost/' . ltrim( $path, '/' ) ) );

		if ( '' !== $path && '/' !== $path && ! str_ends_with( $base_home_url, '/' . ltrim( $path, '/' ) ) ) {
			$base_home_url = rtrim( $base_home_url, '/' ) . '/' . ltrim( $path, '/' );
		}

		return $this->localize_url( $base_home_url, $code );
	}

	/**
	 * Localizes an arbitrary WordPress URL to the target language.
	 *
	 * Rules:
	 * - External URLs (host differing from WordPress site host) are returned unmodified.
	 * - Existing recognized TFML language prefixes are cleanly stripped.
	 * - If target is default: URL remains unprefixed.
	 * - If target is secondary: /{language_code}/ is inserted after any site subdirectory.
	 * - Query strings (?foo=bar), fragments (#section), and ports (:8080) are preserved.
	 *
	 * @param string $url             Source URL.
	 * @param string $target_language Target canonical language code.
	 * @return string Localized URL.
	 * @throws LanguageNotFoundException If target language is unregistered.
	 * @throws InvalidLanguageException If target language is inactive.
	 */
	public function localize_url( string $url, string $target_language ): string {
		$code = $this->validate_target_language( $target_language );

		if ( '' === trim( $url ) ) {
			return $url;
		}

		$parsed = parse_url( $url );
		if ( false === $parsed ) {
			return $url;
		}

		// Determine site home base and home path.
		$resolver_home = $this->url_resolver->get_home_url();
		$site_home_url = ! empty( $this->home_url )
			? $this->home_url
			: ( ! empty( $resolver_home )
				? $resolver_home
				: ( function_exists( 'home_url' ) ? (string) home_url() : 'http://localhost' ) );

		$site_parsed = parse_url( $site_home_url );
		$site_host   = $site_parsed['host'] ?? '';
		$site_path   = isset( $site_parsed['path'] ) ? trim( $site_parsed['path'], '/' ) : '';

		// External URL Policy: if host is provided and differs from site host, do not modify.
		$url_host = $parsed['host'] ?? '';
		if ( '' !== $url_host && '' !== $site_host && 0 !== strcasecmp( $url_host, $site_host ) ) {
			return $url;
		}

		$scheme   = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : ( str_starts_with( $url, '//' ) ? '//' : '' );
		$host     = $url_host;
		$port     = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$user     = $parsed['user'] ?? '';
		$pass     = isset( $parsed['pass'] ) ? ':' . $parsed['pass'] : '';
		$auth     = '' !== $user ? $user . $pass . '@' : '';
		$query    = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';
		$raw_path = $parsed['path'] ?? '/';

		$had_trailing_slash = str_ends_with( $raw_path, '/' );

		// Strip site subdirectory prefix from path if present.
		$relative_path = trim( $raw_path, '/' );
		if ( '' !== $site_path && ( $relative_path === $site_path || str_starts_with( $relative_path, $site_path . '/' ) ) ) {
			$relative_path = trim( substr( $relative_path, strlen( $site_path ) ), '/' );
		}

		// Strip any recognized TFML language prefix from the relative path.
		$clean_path = $this->url_resolver->strip_prefix( $relative_path );
		$clean_path = trim( $clean_path, '/' );

		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : null;

		// Assemble localized path segments.
		$path_segments = array();
		if ( '' !== $site_path ) {
			$path_segments[] = $site_path;
		}

		if ( $code !== $default_code ) {
			$path_segments[] = $code;
		}

		if ( '' !== $clean_path ) {
			$path_segments[] = $clean_path;
		}

		$new_path = '/' . implode( '/', $path_segments );

		// Trailing slash preservation.
		if ( '' === $clean_path && empty( $path_segments ) ) {
			$new_path = '/';
		} elseif ( $had_trailing_slash || '' === $clean_path ) {
			$new_path = rtrim( $new_path, '/' ) . '/';
		} else {
			$new_path = $this->apply_trailing_slash( $new_path, $clean_path );
		}

		$base = ( '' !== $scheme || '' !== $host ) ? $scheme . $auth . $host . $port : '';

		return $base . $new_path . $query . $fragment;
	}

	/**
	 * Generates the localized URL for a translated post.
	 *
	 * Obtains native permalink from WordPress Core get_permalink() and localizes it.
	 * Returns null if no translation exists in target language (untranslated, no fallback).
	 *
	 * @param int    $post_id         Source WordPress post ID.
	 * @param string $target_language Target canonical language code.
	 * @return string|null Localized permalink or null if untranslated / invalid.
	 */
	public function get_post_translation_url( int $post_id, string $target_language ): ?string {
		$code = $this->validate_target_language( $target_language );

		if ( $this->is_resolving_link ) {
			return null;
		}

		$this->is_resolving_link = true;
		try {
			$translated_element = $this->content_resolver->resolve( 'post', $post_id, $code );
			if ( null === $translated_element ) {
				return null;
			}

			$translated_post_id = $translated_element->get_element_id();
			$permalink          = function_exists( 'get_permalink' ) ? get_permalink( $translated_post_id ) : false;

			if ( ! is_string( $permalink ) || '' === $permalink ) {
				return null;
			}

			return $this->localize_url( $permalink, $code );
		} finally {
			$this->is_resolving_link = false;
		}
	}

	/**
	 * Generates the localized URL for a translated term.
	 *
	 * Obtains native term link from WordPress Core get_term_link() and localizes it.
	 * Returns null if no translation exists in target language (untranslated, no fallback).
	 *
	 * @param int    $term_id         Source WordPress term ID.
	 * @param string $target_language Target canonical language code.
	 * @param string $taxonomy        Optional taxonomy name.
	 * @return string|null Localized term link or null if untranslated / invalid.
	 */
	public function get_term_translation_url( int $term_id, string $target_language, string $taxonomy = '' ): ?string {
		$code = $this->validate_target_language( $target_language );

		if ( $this->is_resolving_link ) {
			return null;
		}

		$this->is_resolving_link = true;
		try {
			$translated_element = $this->content_resolver->resolve( 'term', $term_id, $code );
			if ( null === $translated_element ) {
				return null;
			}

			$translated_term_id = $translated_element->get_element_id();
			$term_link          = function_exists( 'get_term_link' ) ? get_term_link( $translated_term_id, $taxonomy ) : false;

			if ( is_wp_error( $term_link ) || ! is_string( $term_link ) || '' === $term_link ) {
				return null;
			}

			return $this->localize_url( $term_link, $code );
		} finally {
			$this->is_resolving_link = false;
		}
	}

	/**
	 * Validates target language code: must exist and be active.
	 *
	 * @param string $language_code Target language code.
	 * @return string Normalized canonical code.
	 * @throws LanguageNotFoundException If language is unregistered.
	 * @throws InvalidLanguageException If language is inactive.
	 */
	private function validate_target_language( string $language_code ): string {
		$code = Language::normalize_code( $language_code );

		if ( ! $this->language_registry->has( $code ) ) {
			throw LanguageNotFoundException::for_code( $code );
		}

		if ( ! $this->language_registry->is_active( $code ) ) {
			throw InvalidLanguageException::for_inactive_language( $code );
		}

		return $code;
	}

	/**
	 * Applies WordPress Core trailing slash convention if available, or maintains original ending.
	 *
	 * @param string $path       New URL path.
	 * @param string $clean_path Clean path segment.
	 * @return string Path with appropriate trailing slash.
	 */
	private function apply_trailing_slash( string $path, string $clean_path ): string {
		if ( function_exists( 'user_trailingslashit' ) ) {
			return user_trailingslashit( $path );
		}

		// Don't add trailing slash to filenames with extensions.
		if ( false !== strpos( basename( $clean_path ), '.' ) ) {
			return $path;
		}

		return rtrim( $path, '/' ) . '/';
	}
}
