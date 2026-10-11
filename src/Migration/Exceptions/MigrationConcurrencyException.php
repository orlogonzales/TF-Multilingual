<?php
/**
 * Migration Concurrency Exception.
 *
 * @package TF\Multilingual\Migration\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration\Exceptions;

/**
 * Class MigrationConcurrencyException
 *
 * Thrown when another migration process is currently running or holding the execution lock.
 */
class MigrationConcurrencyException extends MigrationException {

	/**
	 * Creates exception for a locked source.
	 *
	 * @param string               $source    Migration source.
	 * @param array<string, mixed> $lock_info Lock metadata.
	 * @return self
	 */
	public static function for_locked_source( string $source, array $lock_info = array() ): self {
		$execution_id = (string) ( $lock_info['execution_id'] ?? 'unknown' );
		$acquired_at  = (string) ( $lock_info['acquired_at'] ?? 'unknown' );

		return new self(
			sprintf(
				'La fuente de migración "%s" está bloqueada por otra ejecución concurrente activa (Execution ID: %s, Iniciada: %s).',
				$source,
				$execution_id,
				$acquired_at
			)
		);
	}
}
