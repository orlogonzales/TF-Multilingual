<?php
/**
 * Migration Execution Lock Manager.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use wpdb;

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
	 * Shared database connection.
	 *
	 * @var wpdb|null
	 */
	private ?wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $db Optional shared database connection.
	 */
	public function __construct( ?wpdb $db = null ) {
		if ( null !== $db ) {
			$this->db = $db;
		} elseif ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ) {
			$this->db = $GLOBALS['wpdb'];
		} else {
			$this->db = null;
		}
	}

	/**
	 * Acquires an exclusive lock for a given migration source.
	 *
	 * Utilizes atomic INSERT IGNORE / add_option insertion to avoid race conditions.
	 * If an active lock exists, verifies ownership or expiration before allowing acquisition.
	 *
	 * @param string $source       Migration source identifier ('wpml', 'polylang').
	 * @param string $execution_id Unique execution UUID/token.
	 * @param int    $ttl          Time-to-live in seconds.
	 * @return bool True if lock was acquired, false if already locked by another process.
	 */
	public function acquire( string $source, string $execution_id, int $ttl = self::DEFAULT_TTL ): bool {
		$key       = self::OPTION_PREFIX . $source;
		$now       = time();
		$lock_data = array(
			'execution_id' => $execution_id,
			'source'       => $source,
			'acquired_at'  => gmdate( 'Y-m-d H:i:s' ),
			'expires_at'   => $now + $ttl,
		);

		// Fast path: Atomic insert when key does not exist.
		if ( $this->atomic_insert_lock( $key, $lock_data ) ) {
			return true;
		}

		$current = $this->read_lock( $key );

		if ( is_array( $current ) ) {
			$expires_at = (int) ( $current['expires_at'] ?? 0 );
			$owner_id   = (string) ( $current['execution_id'] ?? '' );

			// Re-acquiring by same owner succeeds.
			if ( $owner_id === $execution_id ) {
				return $this->write_lock( $key, $lock_data );
			}

			// If current lock is still active and owned by someone else, acquire fails.
			if ( $expires_at > $now ) {
				return false;
			}
		}

		// Lock expired: delete expired lock and atomically re-insert.
		$this->delete_lock( $key );
		if ( $this->atomic_insert_lock( $key, $lock_data ) ) {
			return true;
		}

		// Verify ownership in case of concurrent acquisition race after expiration.
		$after = $this->read_lock( $key );
		if ( is_array( $after ) && (string) ( $after['execution_id'] ?? '' ) !== $execution_id ) {
			return false;
		}

		return $this->write_lock( $key, $lock_data );
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
		$current = $this->read_lock( $key );
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

		return $this->write_lock( $key, $current );
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
		$current = $this->read_lock( $key );

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
		$current = $this->read_lock( $key );

		if ( ! is_array( $current ) ) {
			return true;
		}

		$owner_id = (string) ( $current['execution_id'] ?? '' );
		if ( $owner_id !== $execution_id && (int) ( $current['expires_at'] ?? 0 ) > time() ) {
			return false;
		}

		return $this->delete_lock( $key );
	}

	/**
	 * Checks if a migration source is currently locked by an active process.
	 *
	 * @param string $source Migration source identifier.
	 * @return bool
	 */
	public function is_locked( string $source ): bool {
		$key     = self::OPTION_PREFIX . $source;
		$current = $this->read_lock( $key );

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
		$current = $this->read_lock( $key );

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

		return $this->delete_lock( $key );
	}

	/**
	 * Performs an atomic insert of the lock row if it does not exist.
	 *
	 * @param string               $key       Option key.
	 * @param array<string, mixed> $lock_data Lock payload.
	 * @return bool True if inserted atomically by this call.
	 */
	private function atomic_insert_lock( string $key, array $lock_data ): bool {
		if ( $this->has_real_db() && null !== $this->db ) {
			$table      = $this->get_options_table();
			$serialized = function_exists( 'maybe_serialize' )
				? maybe_serialize( $lock_data )
				: serialize( $lock_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

			$sql = $this->db->prepare(
				"INSERT IGNORE INTO `{$table}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$serialized
			);

			$res = $this->db->query( $sql );
			$this->invalidate_cache( $key );

			return 1 === (int) $res;
		}

		if ( function_exists( 'add_option' ) ) {
			return add_option( $key, $lock_data, '', false );
		}

		return false;
	}

	/**
	 * Reads the lock payload directly from storage without stale cache interference.
	 *
	 * @param string $key Option key.
	 * @return array<string, mixed>|null
	 */
	private function read_lock( string $key ): ?array {
		if ( $this->has_real_db() && null !== $this->db ) {
			$table   = $this->get_options_table();
			$query   = $this->db->prepare(
				"SELECT `option_value` FROM `{$table}` WHERE `option_name` = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			);
			$raw_val = $this->db->get_var( $query );
			if ( null === $raw_val || false === $raw_val ) {
				return null;
			}

			$data = function_exists( 'maybe_unserialize' )
				? maybe_unserialize( $raw_val )
				: @unserialize( (string) $raw_val ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged

			return is_array( $data ) ? $data : null;
		}

		$current = get_option( $key, null );

		return is_array( $current ) ? $current : null;
	}

	/**
	 * Writes/updates the lock payload and verifies persistence.
	 *
	 * @param string               $key       Option key.
	 * @param array<string, mixed> $lock_data Lock payload.
	 * @return bool
	 */
	private function write_lock( string $key, array $lock_data ): bool {
		if ( $this->has_real_db() && null !== $this->db ) {
			$table      = $this->get_options_table();
			$serialized = function_exists( 'maybe_serialize' )
				? maybe_serialize( $lock_data )
				: serialize( $lock_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

			$sql = $this->db->prepare(
				"INSERT INTO `{$table}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`), `autoload` = 'no'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$serialized
			);

			$res = $this->db->query( $sql );
			$this->invalidate_cache( $key );

			if ( false === $res || '' !== $this->db->last_error ) {
				return false;
			}

			$persisted = $this->read_lock( $key );
			return is_array( $persisted ) && ( $persisted['execution_id'] ?? '' ) === ( $lock_data['execution_id'] ?? '' );
		}

		update_option( $key, $lock_data, false );
		$persisted = get_option( $key, null );

		return is_array( $persisted ) && ( $persisted['execution_id'] ?? '' ) === ( $lock_data['execution_id'] ?? '' );
	}

	/**
	 * Deletes the lock row from storage.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	private function delete_lock( string $key ): bool {
		if ( $this->has_real_db() && null !== $this->db ) {
			$table = $this->get_options_table();
			$sql   = $this->db->prepare(
				"DELETE FROM `{$table}` WHERE `option_name` = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			);
			$this->db->query( $sql );
			$this->invalidate_cache( $key );
			return true;
		}

		return delete_option( $key );
	}

	/**
	 * Determines whether a live MySQL connection is available on $this->db.
	 *
	 * @return bool
	 */
	private function has_real_db(): bool {
		return null !== $this->db && isset( $this->db->dbh ) && null !== $this->db->dbh;
	}

	/**
	 * Returns the options table name for the active database connection.
	 *
	 * @return string
	 */
	private function get_options_table(): string {
		$prefix = null !== $this->db ? $this->db->prefix : 'wp_';

		return $prefix . 'options';
	}

	/**
	 * Invalidates WordPress option caches for the given key.
	 *
	 * @param string $key Option key.
	 * @return void
	 */
	private function invalidate_cache( string $key ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
