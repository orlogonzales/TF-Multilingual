<?php
/**
 * Editorial Validation Exception.
 *
 * @package TF\Multilingual\Editorial\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Editorial\Exceptions;

/**
 * Class EditorialValidationException
 *
 * Thrown when an editorial operation fails semantic or structural validation.
 */
class EditorialValidationException extends EditorialException {

	/**
	 * Creates exception for an invalid or non-existent post.
	 *
	 * @param int $post_id Post ID.
	 * @return self
	 */
	public static function invalid_post( int $post_id ): self {
		return new self( sprintf( 'Post #%d does not exist or is invalid.', $post_id ) );
	}

	/**
	 * Creates exception for an invalid or non-existent term.
	 *
	 * @param int $term_id Term ID.
	 * @return self
	 */
	public static function invalid_term( int $term_id ): self {
		return new self( sprintf( 'Term #%d does not exist or is invalid.', $term_id ) );
	}

	/**
	 * Creates exception for an invalid or non-existent taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return self
	 */
	public static function invalid_taxonomy( string $taxonomy ): self {
		return new self( sprintf( 'Taxonomy "%s" is not registered.', $taxonomy ) );
	}

	/**
	 * Creates exception for unsupported post types (e.g. revision, attachment).
	 *
	 * @param string $post_type Post type.
	 * @return self
	 */
	public static function unsupported_post_type( string $post_type ): self {
		return new self( sprintf( 'Post type "%s" is not supported for multilingual translations.', $post_type ) );
	}

	/**
	 * Creates exception when attempting to create a translation for an unmanaged source object.
	 *
	 * @return self
	 */
	public static function source_not_managed(): self {
		return new self( 'The source object is not managed by TF Multilingual. Assign an initial language first.' );
	}

	/**
	 * Creates exception for inactive or invalid language codes.
	 *
	 * @param string $language_code Language code.
	 * @return self
	 */
	public static function inactive_or_invalid_language( string $language_code ): self {
		return new self( sprintf( 'Language "%s" is either inactive or not registered in the system.', $language_code ) );
	}

	/**
	 * Creates exception when group subtype does not match requested taxonomy.
	 *
	 * @return self
	 */
	public static function subtype_mismatch(): self {
		return new self( 'Group subtype does not match the requested taxonomy.' );
	}

	/**
	 * Creates exception when system is not configured.
	 *
	 * @return self
	 */
	public static function not_configured(): self {
		return new self( 'TF Multilingual is not configured. Cannot perform editorial translation operations.' );
	}
}
