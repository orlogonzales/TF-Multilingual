<?php
/**
 * Rewrite Rules Manager.
 *
 * @package TF\Multilingual\Routing
 */

declare( strict_types=1 );

namespace TF\Multilingual\Routing;

use TF\Multilingual\Domain\Language\LanguageRegistry;

/**
 * Class RewriteManager
 *
 * Injects multilingual URL rewrite rules into WordPress Core rewrite system.
 *
 * Sovereign Architectural Rules:
 * - Default language rewrite rules are UNCHANGED (no prefix).
 * - Secondary active languages are prefixed with /{lang}/.
 * - Rewrites prepend new rules at the top of the rules array.
 * - Shifted matches ($matches[1] -> $matches[2]) are correctly handled.
 * - System endpoints (wp-json, wp-admin, xmlrpc, sitemaps) are excluded.
 * - Rules are dynamically filtered via rewrite_rules_array; NO flush_rewrite_rules() on init!
 */
class RewriteManager {

	/**
	 * Query variable name used by TFML.
	 */
	public const QUERY_VAR = 'tfml_lang';

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver|null
	 */
	private ?CurrentLanguageResolver $current_language_resolver;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry             $language_registry         Language registry.
	 * @param CurrentLanguageResolver|null $current_language_resolver Optional current language resolver.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		?CurrentLanguageResolver $current_language_resolver = null
	) {
		$this->language_registry         = $language_registry;
		$this->current_language_resolver = $current_language_resolver;
	}

	/**
	 * Registers WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'rewrite_rules_array', array( $this, 'filter_rewrite_rules' ) );
		add_action( 'parse_request', array( $this, 'on_parse_request' ) );
	}

	/**
	 * Registers the internal query var with WordPress.
	 *
	 * @param array<string> $vars Existing query vars.
	 * @return array<string> Modified query vars.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Filters WordPress rewrite rules array to prepend multilingual secondary language rules.
	 *
	 * If NOT_CONFIGURED, returns rules without modification.
	 *
	 * @param array<string, string> $rules Existing rewrite rules.
	 * @return array<string, string> Multilingual rewrite rules.
	 */
	public function filter_rewrite_rules( array $rules ): array {
		if ( ! $this->language_registry->is_configured() ) {
			return $rules;
		}

		$secondary_languages = $this->get_secondary_active_languages();
		if ( empty( $secondary_languages ) ) {
			return $rules;
		}

		return $this->generate_multilingual_rules( $rules, $secondary_languages );
	}

	/**
	 * Generates prefixed rewrite rules for secondary languages.
	 *
	 * @param array<string, string> $existing_rules      Original rewrite rules.
	 * @param array<string>         $secondary_languages Array of canonical language codes.
	 * @return array<string, string>
	 */
	public function generate_multilingual_rules( array $existing_rules, array $secondary_languages ): array {
		$lang_regex = implode( '|', array_map( 'preg_quote', $secondary_languages ) );
		$new_rules  = array();

		// 1. Language homepage rule at the top.
		$new_rules[ "^({$lang_regex})/?$" ] = 'index.php?' . self::QUERY_VAR . '=$matches[1]';

		// 2. Prefixed rules for secondary languages.
		foreach ( $existing_rules as $pattern => $query ) {
			// Exclude system endpoints from being language-prefixed.
			if ( preg_match( '/^(wp-json|wp-sitemap|index\.php|\^wp-json|\^wp-sitemap|\^index\.php|\$)/', $pattern ) ) {
				continue;
			}

			// Clean leading ^ from pattern if present.
			$clean_pattern = ltrim( $pattern, '^' );

			// Prefix pattern.
			$prefixed_pattern = "^({$lang_regex})/{$clean_pattern}";

			// Shift matches in query ($matches[1] -> $matches[2], etc.).
			$shifted_query = preg_replace_callback(
				'/\$matches\[(\d+)\]/',
				function ( $m ) {
					return '$matches[' . ( (int) $m[1] + 1 ) . ']';
				},
				$query
			);

			if ( null === $shifted_query ) {
				$shifted_query = $query;
			}

			// Append query var.
			$separator      = ( false !== strpos( $shifted_query, '?' ) ) ? '&' : '?';
			$prefixed_query = $shifted_query . $separator . self::QUERY_VAR . '=$matches[1]';

			$new_rules[ $prefixed_pattern ] = $prefixed_query;
		}

		return array_merge( $new_rules, $existing_rules );
	}

	/**
	 * Handles WordPress parse_request action to sync CurrentLanguageResolver.
	 *
	 * @param \WP $wp WordPress environment object.
	 * @return void
	 */
	public function on_parse_request( $wp ): void {
		if ( null === $this->current_language_resolver ) {
			return;
		}

		if ( isset( $wp->query_vars[ self::QUERY_VAR ] ) && is_string( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			$lang_code = $wp->query_vars[ self::QUERY_VAR ];
			if ( $this->language_registry->has( $lang_code ) && $this->language_registry->is_active( $lang_code ) ) {
				$this->current_language_resolver->set_current_language( $lang_code );
			}
		}
	}

	/**
	 * Retrieves list of secondary active languages codes.
	 *
	 * @return array<string>
	 */
	public function get_secondary_active_languages(): array {
		if ( ! $this->language_registry->is_configured() ) {
			return array();
		}

		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : null;

		$secondary = array();
		foreach ( $this->language_registry->active() as $language ) {
			if ( $language->get_code() !== $default_code ) {
				$secondary[] = $language->get_code();
			}
		}

		return $secondary;
	}

	/**
	 * Flushes rewrite rules cleanly (only on structural configuration events, NEVER on init).
	 *
	 * @param bool $hard Whether to perform hard flush (updating .htaccess).
	 * @return void
	 */
	public function flush_rules( bool $hard = false ): void {
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( $hard );
		}
	}
}
