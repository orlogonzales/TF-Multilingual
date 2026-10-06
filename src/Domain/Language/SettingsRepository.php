<?php
/**
 * Settings Repository for Options API persistence.
 *
 * @package TF\Multilingual\Domain\Language
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Language;

/**
 * Class SettingsRepository
 *
 * Encapsulates read, write and corruption-safe hydration for tfml_settings in Options API.
 */
class SettingsRepository {

	/**
	 * Option key stored in wp_options.
	 */
	public const OPTION_NAME = 'tfml_settings';

	/**
	 * Canonical empty settings payload.
	 */
	public const EMPTY_SETTINGS = array(
		'default_language' => null,
		'languages'        => array(),
		'custom_fields'    => array(
			'policies' => array(),
		),
	);

	/**
	 * Loads settings payload from wp_options with robust corruption recovery.
	 *
	 * If the option is non-existent, malformed or corrupted, returns the safe default
	 * empty structure without raising fatals or warnings.
	 *
	 * @return array{default_language: ?string, languages: array<string, array<string, mixed>>, custom_fields: array{policies: array<string, string>}}
	 */
	public function load(): array {
		$raw = get_option( self::OPTION_NAME, null );

		return $this->sanitize_payload( $raw );
	}

	/**
	 * Persists settings payload into wp_options.
	 *
	 * Utiliza la política de autoload seleccionada para que WordPress pueda cargar esta
	 * configuración frecuente junto con sus opciones autoloaded, evitando normalmente
	 * una consulta individual posterior para esta opción dentro del request.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return bool True if persisted or unchanged.
	 */
	public function save( array $settings ): bool {
		$sanitized = $this->sanitize_payload( $settings );

		// Pass true to ensure autoload='on' in modern WordPress.
		return update_option( self::OPTION_NAME, $sanitized, true );
	}

	/**
	 * Deletes settings option completely (primarily for testing and controlled purge).
	 *
	 * @return bool True on success.
	 */
	public function delete(): bool {
		return delete_option( self::OPTION_NAME );
	}

	/**
	 * Retrieves raw option value directly for diagnostics and test verifications.
	 *
	 * @return mixed
	 */
	public function get_raw(): mixed {
		return get_option( self::OPTION_NAME, null );
	}

	/**
	 * Sanitizes raw settings payload into predictable canonical structure.
	 *
	 * @param mixed $raw Raw input.
	 * @return array{default_language: ?string, languages: array<string, array<string, mixed>>, custom_fields: array{policies: array<string, string>}}
	 */
	public function sanitize_payload( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return self::EMPTY_SETTINGS;
		}

		$languages = array();
		if ( isset( $raw['languages'] ) && is_array( $raw['languages'] ) ) {
			foreach ( $raw['languages'] as $key => $lang_data ) {
				if ( is_array( $lang_data ) && is_string( $key ) && '' !== trim( $key ) ) {
					$languages[ Language::normalize_code( $key ) ] = $lang_data;
				}
			}
		}

		$default = null;
		if ( isset( $raw['default_language'] ) && is_string( $raw['default_language'] ) ) {
			$trimmed = Language::normalize_code( $raw['default_language'] );
			if ( '' !== $trimmed ) {
				$default = $trimmed;
			}
		}

		$custom_fields = array(
			'policies' => array(),
		);
		if ( isset( $raw['custom_fields'] ) && is_array( $raw['custom_fields'] ) ) {
			if ( isset( $raw['custom_fields']['policies'] ) && is_array( $raw['custom_fields']['policies'] ) ) {
				foreach ( $raw['custom_fields']['policies'] as $meta_key => $policy ) {
					if ( is_string( $meta_key ) && '' !== trim( $meta_key ) && is_string( $policy ) ) {
						$trimmed_policy = strtolower( trim( $policy ) );
						if ( in_array( $trimmed_policy, array( 'translate', 'share', 'ignore' ), true ) ) {
							$custom_fields['policies'][ trim( $meta_key ) ] = $trimmed_policy;
						}
					}
				}
			}
		}

		return array(
			'default_language' => $default,
			'languages'        => $languages,
			'custom_fields'    => $custom_fields,
		);
	}
}
