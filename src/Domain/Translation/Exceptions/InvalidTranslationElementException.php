<?php
/**
 * Invalid Translation Element Exception.
 *
 * @package TF\Multilingual\Domain\Translation\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Translation\Exceptions;

/**
 * Class InvalidTranslationElementException
 */
class InvalidTranslationElementException extends TranslationDomainException {

	/**
	 * Factory for unsupported element type.
	 *
	 * @param string $type Element type.
	 * @return self
	 */
	public static function for_unsupported_type( string $type ): self {
		return new self(
			sprintf( 'Unsupported element type "%s". Only "post" and "term" are authorized.', $type )
		);
	}

	/**
	 * Factory for subtype mismatch in group homogeneity.
	 *
	 * @param string $expected Expected subtype.
	 * @param string $actual   Actual subtype.
	 * @return self
	 */
	public static function for_subtype_mismatch( string $expected, string $actual ): self {
		return new self(
			sprintf(
				'Homogeneity violation: Expected subtype "%s", but received element with subtype "%s".',
				$expected,
				$actual
			)
		);
	}

	/**
	 * Factory for non-existent WordPress entity.
	 *
	 * @param string $type Element type ('post' or 'term').
	 * @param int    $id   Element ID.
	 * @return self
	 */
	public static function for_non_existent_entity( string $type, int $id ): self {
		return new self(
			sprintf( 'WordPress %s with ID %d does not exist or could not be loaded.', $type, $id )
		);
	}

	/**
	 * Factory for inactive or unconfigured language.
	 *
	 * @param string $code Language code.
	 * @return self
	 */
	public static function for_inactive_language( string $code ): self {
		return new self(
			sprintf( 'Language "%s" is not registered or is not active in the language registry.', $code )
		);
	}

	/**
	 * Factory for invalid canonical element designation.
	 *
	 * @param int $element_id Element ID.
	 * @return self
	 */
	public static function for_invalid_canonical( int $element_id ): self {
		return new self(
			sprintf(
				'Cannot set element %d as canonical because it does not belong to this translation group.',
				$element_id
			)
		);
	}
}
