<?php
/**
 * Migration Checkpoint Repository.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use wpdb;

/**
 * Class MigrationCheckpointRepository
 *
 * Persists and retrieves migration checkpoints to enable reliable recovery,
 * resumption from last known good state, and auditability across shared InnoDB transactions.
 */
class MigrationCheckpointRepository {

	/**
	 * Option key prefix for checkpoints.
	 */
	private const OPTION_PREFIX = 'tfml_migration_checkpoint_';

	/**
	 * Shared database connection.
	 *
	 * @var wpdb|null
	 */
	private ?wpdb $db;

	/**
	 * Fault injection flag for testing write failures.
	 *
	 * @var bool
	 */
	private bool $simulate_write_failure = false;

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
	 * Enables or disables simulated write failures for transactional fault testing.
	 *
	 * @param bool $simulate Whether to simulate write failure.
	 * @return void
	 */
	public function set_simulate_write_failure( bool $simulate ): void {
		$this->simulate_write_failure = $simulate;
	}

	/**
	 * Saves a checkpoint into persistent storage and verifies the persisted state.
	 *
	 * @param MigrationCheckpoint $checkpoint Checkpoint to persist.
	 * @return bool True on success.
	 * @throws MigrationExecutionException If write fails or persisted state diverges.
	 */
	public function save( MigrationCheckpoint $checkpoint ): bool {
		if ( $this->simulate_write_failure ) {
			throw new MigrationExecutionException(
				sprintf( 'Fallo inducido de escritura al actualizar el checkpoint de migración (%s).', $checkpoint->get_source() ),
				$checkpoint->get_last_processed_trid()
			);
		}

		$key  = self::OPTION_PREFIX . $checkpoint->get_source();
		$data = $checkpoint->to_array();

		if ( $this->has_real_db() && null !== $this->db ) {
			$table      = $this->get_options_table();
			$serialized = function_exists( 'maybe_serialize' )
				? maybe_serialize( $data )
				: serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

			$sql = $this->db->prepare(
				"INSERT INTO `{$table}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`), `autoload` = 'no'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key,
				$serialized
			);

			$res = $this->db->query( $sql );
			$this->invalidate_cache( $key );

			if ( false === $res || '' !== $this->db->last_error ) {
				throw new MigrationExecutionException(
					sprintf(
						'Error SQL al persistir el checkpoint "%s" en %s: %s',
						$key,
						$table,
						$this->db->last_error
					),
					$checkpoint->get_last_processed_trid()
				);
			}
		} elseif ( function_exists( 'update_option' ) ) {
			update_option( $key, $data, false );
		} else {
			return false;
		}

		// Verify persisted state to distinguish no-change from write failure.
		$persisted = $this->get( $checkpoint->get_source() );
		if (
			null === $persisted ||
			$persisted->get_status() !== $checkpoint->get_status() ||
			$persisted->get_processed_items() !== $checkpoint->get_processed_items() ||
			$persisted->get_last_processed_trid() !== $checkpoint->get_last_processed_trid()
		) {
			throw new MigrationExecutionException(
				sprintf( 'Fallo de verificación post-escritura: El checkpoint persistido para "%s" no coincide con el estado esperado.', $checkpoint->get_source() ),
				$checkpoint->get_last_processed_trid()
			);
		}

		return true;
	}

	/**
	 * Retrieves the active checkpoint for a given migration source.
	 *
	 * @param string $source Migration source identifier ('wpml', 'polylang').
	 * @return MigrationCheckpoint|null Hydrated checkpoint or null if none exists.
	 */
	public function get( string $source ): ?MigrationCheckpoint {
		$key = self::OPTION_PREFIX . $source;

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

			if ( ! is_array( $data ) ) {
				return null;
			}

			return MigrationCheckpoint::from_array( $data );
		}

		$data = get_option( $key, null );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return MigrationCheckpoint::from_array( $data );
	}

	/**
	 * Checks if a checkpoint exists for a given migration source.
	 *
	 * @param string $source Migration source identifier.
	 * @return bool
	 */
	public function has_checkpoint( string $source ): bool {
		return null !== $this->get( $source );
	}

	/**
	 * Deletes a checkpoint for a given migration source.
	 *
	 * @param string $source Migration source identifier.
	 * @return bool True on success.
	 */
	public function delete( string $source ): bool {
		$key = self::OPTION_PREFIX . $source;

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
