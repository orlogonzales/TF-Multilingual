<?php
/**
 * URL Language Resolver.
 *
 * @package TF\Multilingual\Routing
 */

declare( strict_types=1 );

namespace TF\Multilingual\Routing;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;

/**
 * Class UrlLanguageResolver
 *
 * Authoritative resolver for extracting language identity from URLs.
 *
 * Sovereign Architectural Principles:
 * - The URL is the primary and sovereign linguistic authority.
 * - Default language is ALWAYS unprefixed (e.g. /tours/).
 * - Active secondary languages are ALWAYS prefixed (e.g. /en/tours/).
 * - Registered but inactive languages (e.g. /fr/tours/ when fr is inactive) MUST NEVER
 *   degrade or fall back to default language. Returns STATUS_INACTIVE (and null in resolve_from_url).
 * - Unknown slugs (e.g. /hotel/, /blog/ where slug is not a registered TFML language code)
 *   are NOT errors and resolve normally to default language.
 * - Inconsistent/inactive default degrades safely to STATUS_DEFAULT_INACTIVE without guessing.
 * - Excluded system endpoints (wp-admin, wp-json, etc.) resolve to STATUS_EXCLUDED.
 * - NOT_CONFIGURED state leaves all paths untouched and returns null in resolve_from_url().
 */
class UrlLanguageResolver {

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Subdirectory path if WordPress is installed in a subdirectory (e.g. 'cms').
	 *
	 * @var string
	 */
	private string $home_path;

	/**
	 * Effective home URL.
	 *
	 * @var string
	 */
	private string $home_url;

	/**
	 * System endpoints excluded from multilingual prefix resolution.
	 *
	 * @var array<string>
	 */
	private array $excluded_prefixes = array(
		'wp-admin',
		'wp-login.php',
		'wp-json',
		'xmlrpc.php',
		'wp-cron.php',
		'wp-content',
		'wp-includes',
		'wp-comments-post.php',
	);

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $language_registry Language registry.
	 * @param string|null      $home_url          Optional home URL to determine subdirectory path.
	 */
	public function __construct( LanguageRegistry $language_registry, ?string $home_url = null ) {
		$this->language_registry = $language_registry;

		$effective_home_url = null !== $home_url ? $home_url : ( function_exists( 'home_url' ) ? home_url() : '' );
		$this->home_url     = $effective_home_url;
		$parsed_path        = parse_url( $effective_home_url, PHP_URL_PATH );
		$this->home_path    = is_string( $parsed_path ) ? trim( $parsed_path, '/' ) : '';
	}

	/**
	 * Returns effective home URL.
	 *
	 * @return string
	 */
	public function get_home_url(): string {
		return $this->home_url;
	}

	/**
	 * Returns home subdirectory path.
	 *
	 * @return string
	 */
	public function get_home_path(): string {
		return $this->home_path;
	}

	/**
	 * Resolves the URL into a typed UrlLanguageResolution.
	 *
	 * Distinguishes between:
	 * - Unprefixed path -> default language (if active).
	 * - Active secondary prefix -> active language code.
	 * - Registered but inactive prefix -> INACTIVE status (no fallback).
	 * - Unregistered slug -> default language (not hijacked).
	 * - Inactive default language -> DEFAULT_INACTIVE status (safe failure).
	 * - Excluded system path -> EXCLUDED status.
	 * - Not configured -> NOT_CONFIGURED status.
	 *
	 * @param string $url_or_path Request URL or path.
	 * @return UrlLanguageResolution
	 */
	public function resolve( string $url_or_path ): UrlLanguageResolution {
		$path = $this->normalize_path( $url_or_path );

		if ( empty( $this->language_registry->all() ) || null === $this->language_registry->get_default() ) {
			return UrlLanguageResolution::not_configured( $path );
		}

		if ( $this->is_excluded( $path ) ) {
			return UrlLanguageResolution::excluded( $path );
		}

		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : null;

		$is_default_valid = null !== $default_code && $this->language_registry->is_active( $default_code );

		if ( '' === $path ) {
			if ( ! $is_default_valid ) {
				return UrlLanguageResolution::default_inactive( $path );
			}
			return UrlLanguageResolution::active( (string) $default_code, $path, false );
		}

		$segments  = explode( '/', $path );
		$candidate = Language::normalize_code( $segments[0] );

		// Check if first segment matches the default language code.
		// Default language is strictly unprefixed.
		if ( $candidate === $default_code ) {
			if ( ! $is_default_valid ) {
				return UrlLanguageResolution::default_inactive( $path );
			}
			return UrlLanguageResolution::active( (string) $default_code, $path, true );
		}

		// Check if first segment is a registered TFML language code.
		if ( $this->language_registry->has( $candidate ) ) {
			if ( $this->language_registry->is_active( $candidate ) ) {
				return UrlLanguageResolution::active( $candidate, $path, true );
			}

			// Registered TFML language, but INACTIVE: strictly do NOT fall back to default!
			return UrlLanguageResolution::inactive( $candidate, $path );
		}

		// First segment is NOT a registered TFML language code (e.g. /tours/, /hotel/, /blog/).
		// Treat as regular WordPress path in default language.
		if ( ! $is_default_valid ) {
			return UrlLanguageResolution::default_inactive( $path );
		}

		return UrlLanguageResolution::active( (string) $default_code, $path, false );
	}

	/**
	 * Resolves active language code from URL string.
	 *
	 * Returns null if:
	 * - TFML is not configured.
	 * - URL matches an excluded route (admin, rest, login).
	 * - URL contains a registered but INACTIVE language prefix.
	 * - Configured default language is inactive or missing.
	 *
	 * @param string $url_or_path Request URL or path.
	 * @return string|null Resolved canonical active language code, or null.
	 */
	public function resolve_from_url( string $url_or_path ): ?string {
		$resolution = $this->resolve( $url_or_path );

		if ( $resolution->is_valid() ) {
			return $resolution->get_language_code();
		}

		return null;
	}

	/**
	 * Extracts language prefix segment from path if and only if it represents an active secondary language.
	 *
	 * Returns null for:
	 * - Unprefixed URLs.
	 * - URLs matching the default language.
	 * - URLs matching registered but inactive languages.
	 * - URLs with unregistered slugs.
	 *
	 * @param string $url_or_path URL or path.
	 * @return string|null Active secondary language code or null.
	 */
	public function extract_prefix( string $url_or_path ): ?string {
		$resolution = $this->resolve( $url_or_path );

		if ( ! $resolution->is_active() || ! $resolution->has_explicit_prefix() ) {
			return null;
		}

		$code         = $resolution->get_language_code();
		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : null;

		if ( $code === $default_code ) {
			return null;
		}

		return $code;
	}

	/**
	 * Strips language prefix from path if present and registered in TFML.
	 *
	 * Used by LocalizedUrlGenerator to sanitize paths before re-prefixing.
	 *
	 * @param string $path Path to strip prefix from.
	 * @return string Clean path without language prefix.
	 */
	public function strip_prefix( string $path ): string {
		$had_trailing = str_ends_with( $path, '/' );
		$clean        = trim( $path, '/' );
		if ( '' === $clean ) {
			return '';
		}

		$segments  = explode( '/', $clean );
		$candidate = Language::normalize_code( $segments[0] );

		if ( $this->language_registry->has( $candidate ) ) {
			array_shift( $segments );
			$stripped = implode( '/', $segments );
			return $had_trailing && '' !== $stripped ? $stripped . '/' : $stripped;
		}

		return $had_trailing ? $clean . '/' : $clean;
	}


	/**
	 * Removes language prefix from path if present.
	 *
	 * @param string $path   Relative path.
	 * @param string $prefix Language prefix to remove.
	 * @return string Stripped path.
	 */
	public function remove_prefix( string $path, string $prefix ): string {
		$had_trailing = str_ends_with( $path, '/' );
		$path         = trim( $path, '/' );
		$prefix       = trim( $prefix, '/' );

		if ( $path === $prefix ) {
			return '';
		}

		if ( str_starts_with( $path, $prefix . '/' ) ) {
			$remaining = substr( $path, strlen( $prefix ) + 1 );
			return $had_trailing ? $remaining . '/' : $remaining;
		}

		return $path;
	}

	/**
	 * Checks if a path starts with an excluded system prefix.
	 *
	 * @param string $path Clean relative path.
	 * @return bool True if excluded, false otherwise.
	 */
	public function is_excluded( string $path ): bool {
		$trimmed = ltrim( $path, '/' );

		foreach ( $this->excluded_prefixes as $excluded ) {
			if ( $trimmed === $excluded || str_starts_with( $trimmed, $excluded . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes a URL or path string into a relative clean path string.
	 *
	 * Strips protocol, host, port, query string, and subdirectory home path.
	 *
	 * @param string $url_or_path URL or path.
	 * @return string Normalized path without leading/trailing slashes.
	 */
	public function normalize_path( string $url_or_path ): string {
		$path = parse_url( $url_or_path, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = $url_or_path;
		}

		// Remove query string or fragments if parse_url didn't catch them.
		list( $path ) = explode( '?', $path );
		list( $path ) = explode( '#', $path );

		$trimmed = trim( $path, '/' );

		// Strip home path if WordPress is installed in a subdirectory.
		if ( '' !== $this->home_path && ( $trimmed === $this->home_path || str_starts_with( $trimmed, $this->home_path . '/' ) ) ) {
			$trimmed = trim( substr( $trimmed, strlen( $this->home_path ) ), '/' );
		}

		return $trimmed;
	}
}
