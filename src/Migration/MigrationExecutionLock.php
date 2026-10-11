<?php
/**
 * Migration Execution Lock Manager.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

/**
 * Class MigrationExecutionLock
 *
 * Provides concurrency control for migration operations, preventing overlapping
 * executions from mutating the database simultaneously.
 */
class MigrationExecutionLock {

	/**
	 * Default time-to-live in seconds (5 minutes).
	 */
	public const DEFAULT_TTL = 300;

	/**
	 * Option key prefix for migration locks.
	 */
	private const OPTION_PREFIX = 'tfml_migration_lock_';

	/**
	 * Acquires an exclusive lock for a given migration source.
	 *
	 * @param string $source       Migration source identifier ('wpml', 'polylang').
	 * @param string $execution_id Unique execution UUID/token.
	 * @param int    $ttl          Time-to-live in seconds.
	 * @return bool True if lock was acquired, false if already locked by another process.
	 */
	public function acquire( string $source, string $execution_id, int $ttl = self::DEFAULT_TTL ): bool {
		$key     = self::OPTION_PREFIX . $source;
		$current = get_option( $key, null );
		$now     = time();

		if ( is_array( $current ) ) {

			$expires_at = (int) ( $current['expires_at'] ?? 0 );
			$owner_id   = (string) ( $current['execution_id'] ?? '' );

			// If current lock is still valid and owned by someone else, acquire fails.
			if ( $expires_at > $now && $owner_id !== $execution_id ) {
				return false;
			}
		}

		$lock_data = array(
			'execution_id' => $execution_id,
			'source'       => $source,
			'acquired_at'  => gmdate( 'Y-m-d H:i:s' ),
			'expires_at'   => $now + $ttl,
		);

		return update_option( $key, $lock_data, false );
	}

	/**
	 * Renews an existing lock for a given migration source if owned by execution ID.
	 *
	 * @param string $source       Migration source identifier.
	 * @param string $execution_id Unique execution token that holds the lock.
	 * @param int    $ttl          Time-to-live extension in seconds.
	 * @return bool True if lock was renewed, false if expired or owned by another process.
	 */
	public function renew( string $source, string $execution_id, int $ttl = self::DEFAULT_TTL ): bool {
		$key     = self::OPTION_PREFIX . $source;
		$current = get_option( $key, null );
		$now     = time();

		if ( ! is_array( $current ) ) {
			return false;
		}

		$owner_id   = (string) ( $current['execution_id'] ?? '' );
		$expires_at = (int) ( $current['expires_at'] ?? 0 );

		// Fencing: cannot renew if ownership was lost or expired.
		if ( $owner_id !== $execution_id || $expires_at <= $now ) {
			return false;
		}

		$current['expires_at'] = $now + $ttl;
		$current['renewed_at'] = gmdate( 'Y-m-d H:i:s' );

		return update_option( $key, $current, false );
	}

	/**
	 * Verifies that the lock is currently valid and owned by the specified execution ID.
	 *
	 * Acts as a fencing token verification to prevent stale processes from writing after losing a lock.
	 *
	 * @param string $source       Migration source identifier.
	 * @param string $execution_id Unique execution token.
	 * @return bool True if lock is active and owned by execution ID.
	 */
	public function verify_ownership( string $source, string $execution_id ): bool {
		$key     = self::OPTION_PREFIX . $source;
		$current = get_option( $key, null );

		if ( ! is_array( $current ) ) {
			return false;
		}

		$owner_id   = (string) ( $current['execution_id'] ?? '' );
		$expires_at = (int) ( $current['expires_at'] ?? 0 );

		return $owner_id === $execution_id && $expires_at > time();
	}


	/**
	 * Releases an acquired lock if owned by the given execution ID.
	 *
	 * @param string $source       Migration source identifier.
	 * @param string $execution_id Unique execution token that holds the lock.
	 * @return bool True if lock was released, false if not owned by execution ID.
	 */
	public function release( string $source, string $execution_id ): bool {
		$key     = self::OPTION_PREFIX . $source;
		$current = get_option( $key, null );

		if ( ! is_array( $current ) ) {
			return true;
		}

		$owner_id = (string) ( $current['execution_id'] ?? '' );
		if ( $owner_id !== $execution_id && (int) ( $current['expires_at'] ?? 0 ) > time() ) {
			return false;
		}

		return delete_option( $key );
	}

	/**
	 * Checks if a migration source is currently locked by an active process.
	 *
	 * @param string $source Migration source identifier.
	 * @return bool
	 */
	public function is_locked( string $source ): bool {
		$key     = self::OPTION_PREFIX . $source;
		$current = get_option( $key, null );

		if ( ! is_array( $current ) ) {
			return false;
		}

		$expires_at = (int) ( $current['expires_at'] ?? 0 );

		return $expires_at > time();
	}

	/**
	 * Gets detailed lock information if active.
	 *
	 * @param string $source Migration source identifier.
	 * @return array<string, mixed>|null Lock metadata or null if not locked.
	 */
	public function get_lock_info( string $source ): ?array {
		if ( ! $this->is_locked( $source ) ) {
			return null;
		}

		$key     = self::OPTION_PREFIX . $source;
		$current = get_option( $key, null );

		return is_array( $current ) ? $current : null;
	}

	/**
	 * Unconditionally forces the release of a lock (administrative reset).
	 *
	 * @param string $source Migration source identifier.
	 * @return bool
	 */
	public function force_release( string $source ): bool {
		$key = self::OPTION_PREFIX . $source;

		return delete_option( $key );
	}
}
