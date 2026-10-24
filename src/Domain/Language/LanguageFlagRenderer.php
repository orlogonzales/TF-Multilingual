<?php
/**
 * Language Flag Renderer Component.
 *
 * @package TF\Multilingual\Domain\Language
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Language;

/**
 * Class LanguageFlagRenderer
 *
 * Centralized service for resolving and rendering high-resolution,
 * sovereign vectorized national flags conforming to the Spatial UI design system.
 */
class LanguageFlagRenderer {

	/**
	 * Default mapping from language code/prefix to national flag file key.
	 *
	 * - Spanish ('es') => Spain ('es')
	 * - English ('en', 'en-us') => United States ('us')
	 * - British English ('en-gb') => United Kingdom ('gb')
	 * - Portuguese ('pt', 'pt-pt') => Portugal ('pt')
	 * - Brazilian Portuguese ('pt-br') => Brazil ('br')
	 * - French ('fr') => France ('fr')
	 */
	public const DEFAULT_FLAG_MAP = array(
		'es'    => 'es',
		'en'    => 'us',
		'en-us' => 'us',
		'en-gb' => 'gb',
		'pt'    => 'pt',
		'pt-pt' => 'pt',
		'pt-br' => 'br',
		'fr'    => 'fr',
		'gb'    => 'gb',
		'us'    => 'us',
		'br'    => 'br',
	);

	/**
	 * Human-readable flag labels for accessibility alt text and form selectors.
	 */
	public const FLAG_LABELS = array(
		'es' => 'España',
		'us' => 'Estados Unidos',
		'gb' => 'Reino Unido',
		'pt' => 'Portugal',
		'br' => 'Brasil',
		'fr' => 'Francia',
	);

	/**
	 * Returns the list of available national flags with descriptive labels for UI selects.
	 *
	 * @return array<string, string> Key: flag identifier, Value: Human label.
	 */
	public static function get_available_flags(): array {
		return array(
			'es' => __( 'España (ES)', 'tf-multilingual' ),
			'us' => __( 'Estados Unidos (US)', 'tf-multilingual' ),
			'gb' => __( 'Reino Unido (GB)', 'tf-multilingual' ),
			'pt' => __( 'Portugal (PT)', 'tf-multilingual' ),
			'br' => __( 'Brasil (BR)', 'tf-multilingual' ),
			'fr' => __( 'Francia (FR)', 'tf-multilingual' ),
		);
	}

	/**
	 * Resolves the visual flag identifier for a given language code and optional custom flag.
	 *
	 * @param string      $lang_code   Canonical language code (e.g. 'es', 'en', 'pt').
	 * @param string|null $custom_flag Optional custom flag override (e.g. 'gb', 'br').
	 * @return string Resolved flag identifier.
	 */
	public static function resolve_flag_code( string $lang_code, ?string $custom_flag = null ): string {
		if ( null !== $custom_flag && '' !== trim( $custom_flag ) ) {
			$cleaned_custom = strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', trim( $custom_flag ) ) );
			if ( '' !== $cleaned_custom ) {
				return $cleaned_custom;
			}
		}

		$cleaned_lang = strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', trim( $lang_code ) ) );

		if ( isset( self::DEFAULT_FLAG_MAP[ $cleaned_lang ] ) ) {
			return self::DEFAULT_FLAG_MAP[ $cleaned_lang ];
		}

		return $cleaned_lang;
	}

	/**
	 * Resolves the verified web URL of a flag asset, or empty string if not found.
	 *
	 * @param string      $flag_or_lang_code Flag identifier or language code.
	 * @param string|null $custom_flag       Optional custom override.
	 * @return string Safe relative/absolute URL or empty string.
	 */
	public static function get_flag_url( string $flag_or_lang_code, ?string $custom_flag = null ): string {
		$flag_key    = self::resolve_flag_code( $flag_or_lang_code, $custom_flag );
		$plugin_root = dirname( __DIR__, 3 );
		$flags_dir   = $plugin_root . '/assets/images/flags';
		$flag_base   = function_exists( 'plugins_url' )
			? plugins_url( 'assets/images/flags/', $plugin_root . '/tf-multilingual.php' )
			: '/wp-content/plugins/tf-multilingual/assets/images/flags/';

		// Priority 1: Check $flag_key with .svg then .png.
		foreach ( array( 'svg', 'png' ) as $ext ) {
			$path = $flags_dir . '/' . $flag_key . '.' . $ext;
			if ( is_file( $path ) && is_readable( $path ) ) {
				return rtrim( $flag_base, '/' ) . '/' . $flag_key . '.' . $ext;
			}
		}

		// Priority 2: Fallback to language code directly if different from resolved key.
		$lang_clean = strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', trim( $flag_or_lang_code ) ) );
		if ( $lang_clean !== $flag_key ) {
			foreach ( array( 'svg', 'png' ) as $ext ) {
				$path = $flags_dir . '/' . $lang_clean . '.' . $ext;
				if ( is_file( $path ) && is_readable( $path ) ) {
					return rtrim( $flag_base, '/' ) . '/' . $lang_clean . '.' . $ext;
				}
			}
		}

		return '';
	}

	/**
	 * Renders accessible HTML for a language flag or fallback badge.
	 *
	 * @param string      $lang_code   Language code (e.g. 'es', 'en', 'pt').
	 * @param string      $label       Descriptive label for alt/title (e.g. 'Español', 'English').
	 * @param int         $width       Display width in pixels (default 28).
	 * @param int         $height      Display height in pixels (default 20).
	 * @param string|null $custom_flag Optional custom flag code.
	 * @param string      $extra_class Optional extra CSS classes.
	 * @return string HTML <img> tag or fallback <span> pill.
	 */
	public static function render(
		string $lang_code,
		string $label = '',
		int $width = 28,
		int $height = 20,
		?string $custom_flag = null,
		string $extra_class = ''
	): string {
		$flag_key = self::resolve_flag_code( $lang_code, $custom_flag );
		$url      = self::get_flag_url( $lang_code, $custom_flag );

		// Accessible title: "Bandera de España" or "Bandera de España (Español)".
		$country_name = self::FLAG_LABELS[ $flag_key ] ?? '';
		if ( '' !== $country_name ) {
			/* translators: 1: Country name, 2: Optional language label */
			$accessible_title = sprintf( __( 'Bandera de %s', 'tf-multilingual' ), $country_name );
			if ( '' !== $label && false === stripos( $label, $country_name ) ) {
				$accessible_title .= ' (' . $label . ')';
			}
		} elseif ( '' !== $label ) {
			/* translators: %s: Language label */
			$accessible_title = sprintf( __( 'Bandera de %s', 'tf-multilingual' ), $label );
		} else {
			/* translators: %s: Language code */
			$accessible_title = sprintf( __( 'Bandera: %s', 'tf-multilingual' ), strtoupper( $lang_code ) );
		}

		if ( '' !== $url ) {
			$class_attr = 'tfml-flag tfml-flag-' . esc_attr( $flag_key );
			if ( '' !== $extra_class ) {
				$class_attr .= ' ' . esc_attr( $extra_class );
			}

			return sprintf(
				'<img src="%s" alt="%s" title="%s" class="%s" width="%d" height="%d" style="width:%dpx;height:%dpx;object-fit:cover;border-radius:3px;box-shadow:0 1px 2px rgba(0,0,0,0.12);border:1px solid rgba(0,0,0,0.08);vertical-align:middle;display:inline-block;" />',
				esc_url( $url ),
				esc_attr( $accessible_title ),
				esc_attr( $accessible_title ),
				$class_attr,
				$width,
				$height,
				$width,
				$height
			);
		}

		// Graceful fallback pill.
		$clean_code = strtoupper( (string) preg_replace( '/[^a-z0-9_-]/i', '', trim( $lang_code ) ) );
		if ( '' === $clean_code ) {
			$clean_code = '?';
		}

		return sprintf(
			'<span class="tfml-flag-fallback tfml-flag-fallback-%s" title="%s" style="display:inline-block;padding:1px 5px;font-size:10px;font-weight:700;line-height:16px;background:#e9ecef;color:#495057;border-radius:3px;border:1px solid #ced4da;letter-spacing:0.5px;text-transform:uppercase;vertical-align:middle;">%s</span>',
			esc_attr( strtolower( $clean_code ) ),
			esc_attr( $accessible_title ),
			esc_html( $clean_code )
		);
	}
}
