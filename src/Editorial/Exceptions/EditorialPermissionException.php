<?php
/**
 * Editorial Permission Exception.
 *
 * @package TF\Multilingual\Editorial\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Editorial\Exceptions;

/**
 * Class EditorialPermissionException
 *
 * Thrown when the current user lacks required capabilities for an editorial operation.
 */
class EditorialPermissionException extends EditorialException {

	/**
	 * Creates exception for insufficient permissions to edit a post.
	 *
	 * @param int $post_id Post ID.
	 * @return self
	 */
	public static function cannot_edit_post( int $post_id ): self {
		return new self( sprintf( 'User lacks permission to edit post #%d.', $post_id ) );
	}

	/**
	 * Creates exception for insufficient permissions to create a post of a given type.
	 *
	 * @param string $post_type Post type.
	 * @return self
	 */
	public static function cannot_create_post( string $post_type ): self {
		return new self( sprintf( 'User lacks permission to create posts of type "%s".', $post_type ) );
	}

	/**
	 * Creates exception for insufficient permissions to edit terms in a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return self
	 */
	public static function cannot_edit_terms( string $taxonomy ): self {
		return new self( sprintf( 'User lacks permission to manage terms in taxonomy "%s".', $taxonomy ) );
	}
}
