<?php
/**
 * Translation Group Not Found Exception.
 *
 * @package TF\Multilingual\Domain\Translation\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Translation\Exceptions;

/**
 * Class TranslationGroupNotFoundException
 */
class TranslationGroupNotFoundException extends TranslationDomainException {

	/**
	 * Factory for group ID lookup failure.
	 *
	 * @param int $group_id Group ID.
	 * @return self
	 */
	public static function for_id( int $group_id ): self {
		return new self(
			sprintf( 'Translation group with ID %d was not found.', $group_id )
		);
	}

	/**
	 * Factory for element lookup failure.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   Element ID.
	 * @return self
	 */
	public static function for_element( string $element_type, int $element_id ): self {
		return new self(
			sprintf(
				'No translation group found for element "%s" with ID %d.',
				$element_type,
				$element_id
			)
		);
	}
}
