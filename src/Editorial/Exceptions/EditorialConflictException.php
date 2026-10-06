<?php
/**
 * Editorial Conflict Exception.
 *
 * @package TF\Multilingual\Editorial\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Editorial\Exceptions;

/**
 * Class EditorialConflictException
 *
 * Thrown when an editorial operation encounters a concurrency or uniqueness conflict.
 */
class EditorialConflictException extends EditorialException {

	/**
	 * Creates exception when a translation already exists in the target language.
	 *
	 * @param string $language_code Target language code.
	 * @return self
	 */
	public static function translation_already_exists( string $language_code ): self {
		return new self( sprintf( 'A translation for language "%s" already exists in this translation group.', $language_code ) );
	}

	/**
	 * Creates exception when an object is already managed in a translation group.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @return self
	 */
	public static function already_managed( string $element_type, int $element_id ): self {
		return new self( sprintf( '%s #%d is already assigned to a translation group.', ucfirst( $element_type ), $element_id ) );
	}
}
