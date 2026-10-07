<?php
/**
 * String Not Found Exception.
 *
 * @package TF\Multilingual\Domain\String\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Strings\Exceptions;

/**
 * Class StringNotFoundException
 *
 * Thrown when a translatable string is not found by ID or domain+key.
 */
class StringNotFoundException extends StringDomainException {

	/**
	 * Creates an exception for an unfound string by ID.
	 *
	 * @param int $id String ID.
	 * @return self
	 */
	public static function for_id( int $id ): self {
		return new self(
			sprintf( 'Translatable string with ID %d was not found.', $id )
		);
	}

	/**
	 * Creates an exception for an unfound string by domain and key.
	 *
	 * @param string $domain String domain.
	 * @param string $key    String key.
	 * @return self
	 */
	public static function for_domain_and_key( string $domain, string $key ): self {
		return new self(
			sprintf( 'Translatable string for domain "%s" and key "%s" was not found.', $domain, $key )
		);
	}
}
