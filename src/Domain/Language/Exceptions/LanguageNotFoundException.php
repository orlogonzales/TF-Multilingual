<?php
/**
 * Language Not Found Exception.
 *
 * @package TF\Multilingual\Domain\Language\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Language\Exceptions;

/**
 * Class LanguageNotFoundException
 *
 * Thrown when attempting to query or mutate an unregistered language code.
 */
class LanguageNotFoundException extends LanguageDomainException {

	/**
	 * Creates an exception for an unrecognized language code.
	 *
	 * @param string $code Requested language code.
	 * @return self
	 */
	public static function for_code( string $code ): self {
		return new self(
			sprintf( 'Language with code "%s" is not registered in the system.', $code )
		);
	}
}
