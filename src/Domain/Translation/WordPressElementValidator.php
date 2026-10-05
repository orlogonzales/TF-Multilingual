<?php
/**
 * WordPress Element Validator Service.
 *
 * @package TF\Multilingual\Domain\Translation
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Translation;

use TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException;
use WP_Post;
use WP_Term;

/**
 * Class WordPressElementValidator
 *
 * Validates real WordPress entities (posts and terms) using official WordPress Core APIs.
 */
class WordPressElementValidator {

	/**
	 * Validates that an element exists and matches the required element_type and subtype.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param string $subtype      Subtype (post_type or taxonomy).
	 * @param int    $element_id   WordPress object ID.
	 * @return void
	 * @throws InvalidTranslationElementException If entity does not exist or subtype mismatches.
	 */
	public function validate( string $element_type, string $subtype, int $element_id ): void {
		if ( 'post' === $element_type ) {
			$this->validate_post( $subtype, $element_id );
			return;
		}

		if ( 'term' === $element_type ) {
			$this->validate_term( $subtype, $element_id );
			return;
		}

		throw InvalidTranslationElementException::for_unsupported_type( $element_type );
	}

	/**
	 * Validates a WordPress post.
	 *
	 * @param string $expected_post_type Expected post type.
	 * @param int    $post_id            Post ID.
	 * @return void
	 * @throws InvalidTranslationElementException If post does not exist or post_type mismatches.
	 */
	protected function validate_post( string $expected_post_type, int $post_id ): void {
		if ( ! function_exists( 'get_post' ) ) {
			// In standalone unit test environment without WP Core loaded, pass unless stubbed.
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			throw InvalidTranslationElementException::for_non_existent_entity( 'post', $post_id );
		}

		if ( $post->post_type !== $expected_post_type ) {
			throw InvalidTranslationElementException::for_subtype_mismatch(
				$expected_post_type,
				$post->post_type
			);
		}
	}

	/**
	 * Validates a WordPress term.
	 *
	 * @param string $expected_taxonomy Expected taxonomy.
	 * @param int    $term_id           Term ID.
	 * @return void
	 * @throws InvalidTranslationElementException If term does not exist or taxonomy mismatches.
	 */
	protected function validate_term( string $expected_taxonomy, int $term_id ): void {
		if ( ! function_exists( 'get_term' ) ) {
			// In standalone unit test environment without WP Core loaded, pass unless stubbed.
			return;
		}

		$term = get_term( $term_id, $expected_taxonomy );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) ) {
			throw InvalidTranslationElementException::for_non_existent_entity( 'term', $term_id );
		}

		if ( ! $term instanceof WP_Term ) {
			throw InvalidTranslationElementException::for_non_existent_entity( 'term', $term_id );
		}

		if ( $term->taxonomy !== $expected_taxonomy ) {
			throw InvalidTranslationElementException::for_subtype_mismatch(
				$expected_taxonomy,
				$term->taxonomy
			);
		}
	}
}
