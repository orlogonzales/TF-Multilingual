<?php
/**
 * Duplicate Language Exception.
 *
 * @package TF\Multilingual\Domain\Language\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Language\Exceptions;

/**
 * Class DuplicateLanguageException
 *
 * Thrown when attempting to register a language with a code that already exists.
 */
class DuplicateLanguageException extends LanguageDomainException {

	/**
	 * Creates an exception for an already registered language code.
	 *
	 * @param string $code Duplicated language code.
	 * @return self
	 */
	public static function for_code( string $code ): self {
		return new self(
			sprintf( 'Language with code "%s" is already registered.', $code )
		);
	}
}
