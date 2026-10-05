<?php
/**
 * Slug Collision Detector.
 *
 * @package TF\Multilingual\Routing
 */

declare( strict_types=1 );

namespace TF\Multilingual\Routing;

use wpdb;

/**
 * Class SlugCollisionDetector
 *
 * Detects whether WordPress already utilizes a root slug identical to a language code.
 *
 * Principle: Do not silently overwrite existing host content routes.
 */
class SlugCollisionDetector {

	/**
	 * WordPress database object.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $db Optional wpdb instance.
	 */
	public function __construct( ?wpdb $db = null ) {
		global $wpdb;
		$this->db = null !== $db ? $db : $wpdb;
	}

	/**
	 * Detects whether a specific language code collides with an existing root WordPress entity.
	 *
	 * @param string $language_code Language code to check.
	 * @return array<string, mixed>|null Collision details array or null if clean.
	 */
	public function detect_collision_for_code( string $language_code ): ?array {
		$code = strtolower( trim( $language_code ) );
		if ( '' === $code ) {
			return null;
		}

		// 1. Check for root pages or posts with matching post_name.
		$post_row = $this->db->get_row(
			$this->db->prepare(
				"SELECT ID, post_title, post_type, post_name, post_status FROM {$this->db->posts} WHERE post_name = %s AND post_status NOT IN ('trash', 'auto-draft') LIMIT 1",
				$code
			),
			ARRAY_A
		);

		if ( is_array( $post_row ) ) {
			return array(
				'type'        => 'post',
				'entity_id'   => (int) $post_row['ID'],
				'title'       => (string) $post_row['post_title'],
				'subtype'     => (string) $post_row['post_type'],
				'slug'        => (string) $post_row['post_name'],
				'post_status' => (string) $post_row['post_status'],
			);
		}

		// 2. Check for root taxonomy terms with matching slug.
		$term_row = $this->db->get_row(
			$this->db->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.taxonomy FROM {$this->db->terms} t JOIN {$this->db->term_taxonomy} tt ON t.term_id = tt.term_id WHERE t.slug = %s LIMIT 1",
				$code
			),
			ARRAY_A
		);

		if ( is_array( $term_row ) ) {
			return array(
				'type'      => 'term',
				'entity_id' => (int) $term_row['term_id'],
				'title'     => (string) $term_row['name'],
				'subtype'   => (string) $term_row['taxonomy'],
				'slug'      => (string) $term_row['slug'],
			);
		}

		return null;
	}

	/**
	 * Checks multiple language codes for collisions.
	 *
	 * @param array<string> $language_codes Language codes.
	 * @return array<string, array<string, mixed>> Map of language_code => collision info.
	 */
	public function detect_collisions( array $language_codes ): array {
		$collisions = array();
		foreach ( $language_codes as $code ) {
			$collision = $this->detect_collision_for_code( $code );
			if ( null !== $collision ) {
				$collisions[ $code ] = $collision;
			}
		}

		return $collisions;
	}

	/**
	 * Checks if a language code collies with existing content.
	 *
	 * @param string $language_code Language code.
	 * @return bool True if collision exists.
	 */
	public function has_collision( string $language_code ): bool {
		return null !== $this->detect_collision_for_code( $language_code );
	}
}
