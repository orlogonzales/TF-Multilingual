<?php
/**
 * Translatable Fingerprint Domain Service.
 *
 * @package TF\Multilingual\Domain\Versioning
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Versioning;

use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use WP_Post;
use WP_Term;

/**
 * Class TranslatableFingerprint
 *
 * Generates deterministic cryptographic fingerprints representing the translatable
 * editorial content of WordPress objects (posts and terms).
 */
class TranslatableFingerprint {

	/**
	 * Custom field policy registry.
	 *
	 * @var CustomFieldPolicyRegistry|null
	 */
	private ?CustomFieldPolicyRegistry $policy_registry;

	/**
	 * Constructor.
	 *
	 * @param CustomFieldPolicyRegistry|null $policy_registry Policy registry.
	 */
	public function __construct( ?CustomFieldPolicyRegistry $policy_registry = null ) {
		$this->policy_registry = $policy_registry;
	}

	/**
	 * Instance calculation for post fingerprint.
	 *
	 * @param int|WP_Post $post Post ID or WP_Post instance.
	 * @return string SHA-256 fingerprint.
	 */
	public function calculate_post_fingerprint( int|WP_Post $post ): string {
		return self::compute_for_post( $post, $this->policy_registry );
	}

	/**
	 * Instance calculation for term fingerprint.
	 *
	 * @param int|WP_Term $term     Term ID or WP_Term instance.
	 * @param string      $taxonomy Taxonomy slug.
	 * @return string SHA-256 fingerprint.
	 */
	public function calculate_term_fingerprint( int|WP_Term $term, string $taxonomy = '' ): string {
		return self::compute_for_term( $term, $taxonomy );
	}

	/**
	 * Computes fingerprint for a post.
	 *
	 * Evaluates:
	 * - post_title
	 * - post_content
	 * - post_excerpt
	 * - Custom fields explicitly registered with TRANSLATE policy.
	 *
	 * Excludes:
	 * - SHARE fields (synchronized across siblings, do not create revision delta).
	 * - IGNORE fields and unknown postmeta.
	 * - Technical metadata (_edit_lock, etc.).
	 * - Taxonomies and Media (independent lifecycles).
	 *
	 * @param int|WP_Post                     $post            Post ID or WP_Post instance.
	 * @param CustomFieldPolicyRegistry|null $policy_registry Custom field policy registry.
	 * @return string SHA-256 hexadecimal fingerprint (64 chars).
	 */
	public static function compute_for_post( int|WP_Post $post, ?CustomFieldPolicyRegistry $policy_registry = null ): string {
		$post_obj = null;
		if ( $post instanceof WP_Post ) {
			$post_obj = $post;
		} elseif ( function_exists( 'get_post' ) ) {
			$post_obj = get_post( $post );
		} elseif ( isset( $GLOBALS['wp_test_posts'][ $post ] ) ) {
			$post_obj = $GLOBALS['wp_test_posts'][ $post ];
		}

		if ( ! $post_obj instanceof WP_Post ) {
			return '';
		}

		$payload = array(
			'post_title'   => self::normalize_string( $post_obj->post_title ?? '' ),
			'post_content' => self::normalize_string( $post_obj->post_content ?? '' ),
			'post_excerpt' => self::normalize_string( $post_obj->post_excerpt ?? '' ),
		);

		// Include custom fields with TRANSLATE policy if registry provided.
		if ( null !== $policy_registry ) {
			$translate_meta = self::collect_translate_meta( (int) $post_obj->ID, $policy_registry );
			if ( ! empty( $translate_meta ) ) {
				$payload['meta'] = $translate_meta;
			}
		}

		return self::hash_payload( $payload );
	}

	/**
	 * Computes fingerprint for a taxonomy term.
	 *
	 * Evaluates:
	 * - name
	 * - slug
	 * - description
	 *
	 * @param int|WP_Term $term     Term ID or WP_Term instance.
	 * @param string      $taxonomy Taxonomy slug if ID passed.
	 * @return string SHA-256 hexadecimal fingerprint (64 chars).
	 */
	public static function compute_for_term( int|WP_Term $term, string $taxonomy = '' ): string {
		$term_obj = null;
		if ( $term instanceof WP_Term ) {
			$term_obj = $term;
		} elseif ( function_exists( 'get_term' ) ) {
			$fetched = get_term( $term, $taxonomy );
			if ( $fetched instanceof WP_Term ) {
				$term_obj = $fetched;
			}
		} elseif ( isset( $GLOBALS['wp_test_terms'][ $term ] ) ) {
			$term_obj = $GLOBALS['wp_test_terms'][ $term ];
		}

		if ( ! $term_obj instanceof WP_Term ) {
			return '';
		}

		$payload = array(
			'name'        => self::normalize_string( $term_obj->name ?? '' ),
			'slug'        => self::normalize_string( $term_obj->slug ?? '' ),
			'description' => self::normalize_string( $term_obj->description ?? '' ),
		);

		return self::hash_payload( $payload );
	}

	/**
	 * Collects and normalizes metadata registered strictly as TRANSLATE.
	 *
	 * @param int                        $post_id         Post ID.
	 * @param CustomFieldPolicyRegistry $policy_registry Policy registry.
	 * @return array<string, mixed> Key-sorted array of translated metadata.
	 */
	public static function collect_translate_meta( int $post_id, CustomFieldPolicyRegistry $policy_registry ): array {
		$all_meta = array();
		if ( function_exists( 'get_post_meta' ) ) {
			$meta = get_post_meta( $post_id );
			if ( is_array( $meta ) ) {
				$all_meta = $meta;
			}
		} elseif ( isset( $GLOBALS['wp_test_postmeta'][ $post_id ] ) && is_array( $GLOBALS['wp_test_postmeta'][ $post_id ] ) ) {
			foreach ( $GLOBALS['wp_test_postmeta'][ $post_id ] as $k => $v ) {
				$all_meta[ $k ] = array( $v );
			}
		}

		if ( empty( $all_meta ) ) {
			return array();
		}

		$result = array();
		foreach ( $all_meta as $key => $values ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			// Check policy: strictly TRANSLATE only.
			if ( CustomFieldPolicy::TRANSLATE !== $policy_registry->get_policy( $key ) ) {
				continue;
			}

			// Single or multiple meta values.
			if ( is_array( $values ) && 1 === count( $values ) ) {
				$raw = $values[0];
				$val = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
			} else {
				$val = is_array( $values )
					? array_map( static fn( $v ) => function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $v ) : $v, $values )
					: $values;
			}

			$result[ $key ] = self::normalize_value( $val );
		}

		ksort( $result );

		return $result;
	}

	/**
	 * Hashes a structured payload into a deterministic string.
	 *
	 * @param array<string, mixed> $payload Normalized data payload.
	 * @return string SHA-256 hash.
	 */
	public static function hash_payload( array $payload ): string {
		$canonical_json = self::canonical_serialize( $payload );

		return hash( 'sha256', $canonical_json );
	}

	/**
	 * Serializes arbitrary values deterministically preserving exact types and key ordering.
	 *
	 * Differentiates:
	 * - '' vs '0' vs 0 vs false vs null vs []
	 *
	 * @param mixed $value Value to serialize.
	 * @return string Deterministic serialization.
	 */
	public static function canonical_serialize( mixed $value ): string {
		$normalized = self::normalize_value( $value );

		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false !== $json ) {
			return $json;
		}

		return serialize( $normalized ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Normalizes strings by unifying line breaks and trimming surrounding whitespace.
	 *
	 * @param string $str Input string.
	 * @return string Normalized string.
	 */
	public static function normalize_string( string $str ): string {
		// Unify Windows/Mac line endings to \n.
		$unified = str_replace( array( "\r\n", "\r" ), "\n", $str );

		return trim( $unified );
	}

	/**
	 * Recursively normalizes complex values (sorting array keys, normalizing strings).
	 *
	 * Preserves distinct types (int, float, bool, string, array, null).
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed Normalized value.
	 */
	public static function normalize_value( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return self::normalize_string( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = array();
			foreach ( $value as $k => $v ) {
				$normalized[ $k ] = self::normalize_value( $v );
			}
			ksort( $normalized );
			return $normalized;
		}

		return $value;
	}
}
