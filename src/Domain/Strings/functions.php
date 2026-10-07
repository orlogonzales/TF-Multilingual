<?php
/**
 * Global Multilingual String Helpers.
 *
 * @package TF\Multilingual\Domain\Strings
 */

declare( strict_types=1 );

use TF\Multilingual\Core\Plugin;

if ( ! function_exists( 'tfml_translate' ) ) {
	/**
	 * Programmatically translates an interface string for an optional explicit language.
	 *
	 * @param string      $domain        String domain (e.g. 'travel-flow').
	 * @param string      $key           Semantic key (e.g. 'booking.button.confirm').
	 * @param string      $default_value Default source text.
	 * @param string|null $language_code Optional explicit language code.
	 * @param string      $context       Optional context.
	 * @return string Unescaped translated string or default source text.
	 */
	function tfml_translate(
		string $domain,
		string $key,
		string $default_value = '',
		?string $language_code = null,
		string $context = ''
	): string {
		$plugin = Plugin::get_instance();
		if ( null === $plugin || ! method_exists( $plugin, 'get_string_translation_service' ) ) {
			return $default_value;
		}

		$service = $plugin->get_string_translation_service();
		if ( null === $service ) {
			return $default_value;
		}

		return $service->translate( $domain, $key, $default_value, $language_code, $context );
	}
}

if ( ! function_exists( 'tfml__' ) ) {
	/**
	 * Retrieves the translated string for the current request language.
	 *
	 * Returns unescaped translation. Separate translation from escaping.
	 *
	 * @param string $domain        String domain.
	 * @param string $key           Semantic key.
	 * @param string $default_value Default source text.
	 * @param string $context       Optional context.
	 * @return string Unescaped translated text.
	 */
	function tfml__( string $domain, string $key, string $default_value = '', string $context = '' ): string {
		return tfml_translate( $domain, $key, $default_value, null, $context );
	}
}

if ( ! function_exists( 'tfml_e' ) ) {
	/**
	 * Displays the translated string for the current request language, safely escaped for HTML.
	 *
	 * @param string $domain        String domain.
	 * @param string $key           Semantic key.
	 * @param string $default_value Default source text.
	 * @param string $context       Optional context.
	 * @return void
	 */
	function tfml_e( string $domain, string $key, string $default_value = '', string $context = '' ): void {
		$translated = tfml__( $domain, $key, $default_value, $context );
		if ( function_exists( 'esc_html' ) ) {
			echo esc_html( $translated );
		} else {
			echo htmlspecialchars( $translated, ENT_QUOTES, 'UTF-8' );
		}
	}
}

if ( ! function_exists( 'tfml_x' ) ) {
	/**
	 * Translates string with disambiguating context for the current request language.
	 *
	 * @param string $domain        String domain.
	 * @param string $key           Semantic key.
	 * @param string $context       Disambiguation context (e.g. 'verb', 'noun').
	 * @param string $default_value Default source text.
	 * @return string Unescaped translated text.
	 */
	function tfml_x( string $domain, string $key, string $context, string $default_value = '' ): string {
		return tfml__( $domain, $key, $default_value, $context );
	}
}

if ( ! function_exists( 'tfml_esc_html__' ) ) {
	/**
	 * Translates string and escapes it for HTML output.
	 *
	 * @param string $domain        String domain.
	 * @param string $key           Semantic key.
	 * @param string $default_value Default source text.
	 * @param string $context       Optional context.
	 * @return string Escaped HTML translated text.
	 */
	function tfml_esc_html__( string $domain, string $key, string $default_value = '', string $context = '' ): string {
		$translated = tfml__( $domain, $key, $default_value, $context );
		return function_exists( 'esc_html' ) ? esc_html( $translated ) : htmlspecialchars( $translated, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'tfml_esc_attr__' ) ) {
	/**
	 * Translates string and escapes it for HTML attribute output.
	 *
	 * @param string $domain        String domain.
	 * @param string $key           Semantic key.
	 * @param string $default_value Default source text.
	 * @param string $context       Optional context.
	 * @return string Escaped attribute translated text.
	 */
	function tfml_esc_attr__( string $domain, string $key, string $default_value = '', string $context = '' ): string {
		$translated = tfml__( $domain, $key, $default_value, $context );
		return function_exists( 'esc_attr' ) ? esc_attr( $translated ) : htmlspecialchars( $translated, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'tfml_n' ) ) {
	/**
	 * Translates string with singular/plural selection.
	 *
	 * Evaluates count and selects the appropriate sub-key (.single or .plural),
	 * providing a forward-compatible contract for plural engines.
	 *
	 * @param string $domain  String domain.
	 * @param string $key     Base semantic key.
	 * @param string $single  Single default text.
	 * @param string $plural  Plural default text.
	 * @param int    $number  Number of items.
	 * @param string $context Optional context.
	 * @return string Unescaped translated text.
	 */
	function tfml_n(
		string $domain,
		string $key,
		string $single,
		string $plural,
		int $number,
		string $context = ''
	): string {
		$is_single = 1 === $number;
		$sub_key   = $is_single ? $key . '.single' : $key . '.plural';
		$default   = $is_single ? $single : $plural;

		return tfml__( $domain, $sub_key, $default, $context );
	}
}
