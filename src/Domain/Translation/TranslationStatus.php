<?php
/**
 * Translation Status Domain Value Object / Constants.
 *
 * @package TF\Multilingual\Domain\Translation
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Translation;

/**
 * Class TranslationStatus
 *
 * Defines canonical lifecycle states for multilingual translations.
 */
final class TranslationStatus {

	/**
	 * Object does not have a translation in the requested language.
	 */
	public const UNTRANSLATED = 'untranslated';

	/**
	 * Translation exists and is synchronized with the source content version.
	 */
	public const UPDATED = 'updated';

	/**
	 * Translation exists but source content has changed since translation was created/updated.
	 */
	public const REVIEW = 'review';

	/**
	 * Returns all valid status values.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::UNTRANSLATED,
			self::UPDATED,
			self::REVIEW,
		);
	}

	/**
	 * Checks if a given status string is a valid domain status.
	 *
	 * @param string $status Status string.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Gets localized, human-readable label for a status.
	 *
	 * @param string $status Status string.
	 * @return string
	 */
	public static function get_label( string $status ): string {
		switch ( $status ) {
			case self::UPDATED:
				return __( 'Actualizado', 'tf-multilingual' );
			case self::REVIEW:
				return __( 'Requiere revisión', 'tf-multilingual' );
			case self::UNTRANSLATED:
				return __( 'Sin traducción', 'tf-multilingual' );
			default:
				return $status;
		}
	}
}
