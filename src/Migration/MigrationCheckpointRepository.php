<?php
/**
 * Migration Checkpoint Repository.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

/**
 * Class MigrationCheckpointRepository
 *
 * Persists and retrieves migration checkpoints to enable reliable recovery,
 * resumption from last known good state, and auditability.
 */
class MigrationCheckpointRepository {

	/**
	 * Option key prefix for checkpoints.
	 */
	private const OPTION_PREFIX = 'tfml_migration_checkpoint_';

	/**
	 * Saves a checkpoint into persistent storage.
	 *
	 * @param MigrationCheckpoint $checkpoint Checkpoint to persist.
	 * @return bool True on success.
	 */
	public function save( MigrationCheckpoint $checkpoint ): bool {
		$key  = self::OPTION_PREFIX . $checkpoint->get_source();
		$data = $checkpoint->to_array();

		return update_option( $key, $data, false );
	}

	/**
	 * Retrieves the active checkpoint for a given migration source.
	 *
	 * @param string $source Migration source identifier ('wpml', 'polylang').
	 * @return MigrationCheckpoint|null Hydrated checkpoint or null if none exists.
	 */
	public function get( string $source ): ?MigrationCheckpoint {
		$key  = self::OPTION_PREFIX . $source;
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
		$key = self::OPTION_PREFIX . $source;

		return false !== get_option( $key, false );
	}

	/**
	 * Deletes a checkpoint for a given migration source.
	 *
	 * @param string $source Migration source identifier.
	 * @return bool True on success.
	 */
	public function delete( string $source ): bool {
		$key = self::OPTION_PREFIX . $source;

		return delete_option( $key );
	}
}
