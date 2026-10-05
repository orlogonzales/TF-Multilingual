<?php
/**
 * Translation Conflict Exception.
 *
 * @package TF\Multilingual\Domain\Translation\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Translation\Exceptions;

/**
 * Class TranslationConflictException
 */
class TranslationConflictException extends TranslationDomainException {

	/**
	 * Factory for duplicate language in group.
	 *
	 * @param int    $group_id      Group ID.
	 * @param string $language_code Language code.
	 * @return self
	 */
	public static function for_duplicate_language( int $group_id, string $language_code ): self {
		return new self(
			sprintf(
				'Translation group %d already contains a translation for language "%s".',
				$group_id,
				$language_code
			)
		);
	}

	/**
	 * Factory for element already assigned to another group.
	 *
	 * @param string $element_type      Element type ('post' or 'term').
	 * @param int    $element_id        Element ID.
	 * @param int    $existing_group_id Existing group ID.
	 * @return self
	 */
	public static function for_element_already_assigned(
		string $element_type,
		int $element_id,
		int $existing_group_id
	): self {
		return new self(
			sprintf(
				'Element "%s" with ID %d is already assigned to translation group %d.',
				$element_type,
				$element_id,
				$existing_group_id
			)
		);
	}

	/**
	 * Factory for database unique constraint collision under concurrency.
	 *
	 * @param string $detail Details of the collision.
	 * @return self
	 */
	public static function for_concurrent_collision( string $detail ): self {
		return new self(
			sprintf( 'Database conflict detected under concurrency: %s', $detail )
		);
	}
}
