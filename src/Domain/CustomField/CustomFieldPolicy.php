<?php
/**
 * Custom Field Policy Value Object / Enumeration.
 *
 * @package TF\Multilingual\Domain\CustomField
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\CustomField;

use InvalidArgumentException;

/**
 * Class CustomFieldPolicy
 *
 * Defines valid policies for custom field synchronization across language siblings.
 */
final class CustomFieldPolicy {

	/**
	 * Meta field values are independently translated per post (no cross-post synchronization).
	 */
	public const TRANSLATE = 'translate';

	/**
	 * Meta field values are automatically synchronized across all language siblings in the group.
	 */
	public const SHARE = 'share';

	/**
	 * Meta field is ignored by TF Multilingual (no sync, no automatic copying).
	 */
	public const IGNORE = 'ignore';

	/**
	 * Returns all valid policy values.
	 *
	 * @return array<string>
	 */
	public static function all(): array {
		return array(
			self::TRANSLATE,
			self::SHARE,
			self::IGNORE,
		);
	}

	/**
	 * Checks if a given policy string is valid.
	 *
	 * @param string $policy Policy string to check.
	 * @return bool True if valid.
	 */
	public static function is_valid( string $policy ): bool {
		$normalized = strtolower( trim( $policy ) );

		return in_array( $normalized, self::all(), true );
	}

	/**
	 * Normalizes a policy string or throws exception if invalid.
	 *
	 * @param string $policy Policy string.
	 * @return string Normalized lowercase policy.
	 * @throws InvalidArgumentException If policy is unknown.
	 */
	public static function normalize( string $policy ): string {
		$normalized = strtolower( trim( $policy ) );
		if ( ! in_array( $normalized, self::all(), true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid custom field policy "%s". Allowed policies: %s.', $policy, implode( ', ', self::all() ) )
			);
		}

		return $normalized;
	}

	/**
	 * Returns the strict default policy for unconfigured or unknown keys.
	 *
	 * @return string
	 */
	public static function default(): string {
		return self::IGNORE;
	}
}
