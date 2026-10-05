<?php
/**
 * Invalid Language Exception.
 *
 * @package TF\Multilingual\Domain\Language\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Language\Exceptions;

/**
 * Class InvalidLanguageException
 *
 * Thrown when a language code, locale or property violates domain validation rules.
 */
class InvalidLanguageException extends LanguageDomainException {

	/**
	 * Creates an exception for an inactive language code.
	 *
	 * @param string $code Inactive language code.
	 * @return self
	 */
	public static function for_inactive_language( string $code ): self {
		return new self(
			sprintf( 'Language with code "%s" is registered but currently inactive.', $code )
		);
	}
}
