<?php
/**
 * WPML Migration Rollback Service.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use Throwable;
use wpdb;

/**
 * Class WpmlMigrationRollbackService
 *
 * Provides transactional, non-destructive rollback capabilities for TFML groups created
 * by a specific migration run, guaranteeing zero modifications to WordPress Core entities,
 * zero mutations to WPML tables, and atomic cleanup of RetainedTranslationRegistry.
 */
class WpmlMigrationRollbackService {

	/**
	 * Database abstraction.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Checkpoint repository.
	 *
	 * @var MigrationCheckpointRepository
	 */
	private MigrationCheckpointRepository $checkpoint_repository;

	/**
	 * Lock manager.
	 *
	 * @var MigrationExecutionLock
	 */
	private MigrationExecutionLock $lock;

	/**
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

	/**
	 * Fault injection flag for manifest persistence testing.
	 *
	 * @var bool
	 */
	private bool $simulate_manifest_write_failure = false;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null                          $db                    Database instance.
	 * @param TranslationGroupRepository|null    $group_repository      Group repository.
	 * @param MigrationCheckpointRepository|null $checkpoint_repository Checkpoint repository.
	 * @param MigrationExecutionLock|null        $lock                  Lock manager.
	 * @param RetainedTranslationRegistry|null   $retained_registry     Retained translation registry.
	 */
	public function __construct(
		?wpdb $db = null,
		?TranslationGroupRepository $group_repository = null,
		?MigrationCheckpointRepository $checkpoint_repository = null,
		?MigrationExecutionLock $lock = null,
		?RetainedTranslationRegistry $retained_registry = null
	) {
		global $wpdb;

		$this->db                    = null !== $db ? $db : $wpdb;
		$this->group_repository      = null !== $group_repository ? $group_repository : new TranslationGroupRepository( $this->db );
		$this->checkpoint_repository = null !== $checkpoint_repository ? $checkpoint_repository : new MigrationCheckpointRepository( $this->db );
		$this->lock                  = null !== $lock ? $lock : new MigrationExecutionLock( $this->db );
		$this->retained_registry     = null !== $retained_registry ? $retained_registry : new RetainedTranslationRegistry( $this->db );
	}

	/**
	 * Option key prefix for migration execution manifests.
	 */
	public const OPTION_MANIFEST_PREFIX = 'tfml_migration_manifest_';

	/**
	 * Enables or disables simulated manifest write failure.
	 *
	 * @param bool $simulate Whether to simulate failure.
	 * @return void
	 */
	public function set_simulate_manifest_write_failure( bool $simulate ): void {
		$this->simulate_manifest_write_failure = $simulate;
	}

	/**
	 * Gets the persisted execution manifest for an execution token.
	 *
	 * @param string $execution_id Execution token.
	 * @return array<string, mixed>|null
	 */
	public function get_execution_manifest( string $execution_id ): ?array {
		if ( '' === trim( $execution_id ) ) {
			return null;
		}

		$key = self::OPTION_MANIFEST_PREFIX . $execution_id;

		if ( $this->has_real_db() ) {
			$table   = $this->get_options_table();
			$query   = $this->db->prepare(
				"SELECT `option_value` FROM `{$table}` WHERE `option_name` = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			);
			$raw_val = $this->db->get_var( $query );
			if ( null === $raw_val || false === $raw_val ) {
				return null;
			}

			$manifest = function_exists( 'maybe_unserialize' )
				? maybe_unserialize( $raw_val )
				: @unserialize( (string) $raw_val ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged

			return is_array( $manifest ) ? $manifest : null;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$manifest = get_option( $key, null );

		return is_array( $manifest ) ? $manifest : null;
	}

	/**
	 * Saves or registers an execution manifest and verifies persisted state.
	 *
	 * @param string               $execution_id Execution token.
	 * @param array<string, mixed> $data         Manifest data.
	 * @return bool
	 * @throws MigrationExecutionException If manifest write or verification fails.
	 */
	public function save_execution_manifest( string $execution_id, array $data ): bool {
		if ( '' === trim( $execution_id ) ) {
			return false;
		}

		if ( $this->simulate_manifest_write_failure ) {
			throw new MigrationExecutionException(
				sprintf( 'Fallo inducido al persistir el manifiesto de ejecución "%s".', $execution_id )
			);
		}

		$key = self::OPTION_MANIFEST_PREFIX . $execution_id;

		if ( $this->has_real_db() ) {
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
					sprintf( 'Error SQL al persistir el manifiesto "%s": %s', $key, $this->db->last_error )
				);
			}
		} elseif ( function_exists( 'update_option' ) ) {
			update_option( $key, $data, false );
		} else {
			return false;
		}

		$persisted = $this->get_execution_manifest( $execution_id );
		if ( ! is_array( $persisted ) || ( $persisted['execution_id'] ?? '' ) !== ( $data['execution_id'] ?? $execution_id ) ) {
			throw new MigrationExecutionException(
				sprintf( 'Fallo de verificación post-escritura del manifiesto "%s".', $execution_id )
			);
		}

		return true;
	}

	/**
	 * Deletes an execution manifest upon successful rollback.
	 *
	 * @param string $execution_id Execution token.
	 * @return bool
	 */
	public function delete_execution_manifest( string $execution_id ): bool {
		if ( '' === trim( $execution_id ) ) {
			return false;
		}

		$key = self::OPTION_MANIFEST_PREFIX . $execution_id;

		if ( $this->has_real_db() ) {
			$table = $this->get_options_table();
			$sql   = $this->db->prepare(
				"DELETE FROM `{$table}` WHERE `option_name` = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			);
			$this->db->query( $sql );
			$this->invalidate_cache( $key );
			return true;
		}

		if ( ! function_exists( 'delete_option' ) ) {
			return false;
		}

		return delete_option( $key );
	}

	/**
	 * Inspects potential rollback dependencies and blockers before executing deletion.
	 *
	 * Enforces strict execution ownership (preventing deletion of pre-existing or foreign groups)
	 * and detects whether groups have been edited by editorial users post-migration.
	 *
	 * @param array<int, int> $group_ids    List of group IDs to inspect.
	 * @param string          $execution_id Execution token to verify ownership against.
	 * @return array{
	 *     eligible_groups: array<int, int>,
	 *     blocked_groups: array<int, array{group_id: int, reason: string, code: string}>
	 * }
	 */
	public function inspect_dependencies( array $group_ids, string $execution_id = '' ): array {
		$eligible = array();
		$blocked  = array();

		$manifest  = '' !== $execution_id ? $this->get_execution_manifest( $execution_id ) : null;
		$owned_ids = null !== $manifest && isset( $manifest['imported_group_ids'] )
			? array_map( 'intval', (array) $manifest['imported_group_ids'] )
			: null;

		foreach ( $group_ids as $group_id ) {
			$group_id = (int) $group_id;

			// 1. Strict execution ownership verification.
			if ( null !== $owned_ids && ! in_array( $group_id, $owned_ids, true ) ) {
				$blocked[] = array(
					'group_id' => $group_id,
					'reason'   => sprintf( 'El grupo ID %d no fue creado por la ejecución "%s" (propiedad no demostrada; protegido contra eliminación).', $group_id, $execution_id ),
					'code'     => 'not_owned',
				);
				continue;
			}

			$group = $this->group_repository->find( $group_id );
			if ( null === $group ) {
				continue;
			}

			// 2. Check for post-migration content version increment.
			$has_editorial_edits = false;
			foreach ( $group->get_elements() as $element ) {
				if ( $element->get_current_content_version() > $element->get_source_version_at_translation() ) {
					$has_editorial_edits = true;
					break;
				}
			}

			if ( $has_editorial_edits ) {
				$blocked[] = array(
					'group_id' => $group_id,
					'reason'   => 'El grupo presenta ediciones editoriales posteriores a la migración (versión de contenido incrementada).',
					'code'     => 'editorial_edits',
				);
			} else {
				$eligible[] = $group_id;
			}
		}

		return array(
			'eligible_groups' => $eligible,
			'blocked_groups'  => $blocked,
		);
	}

	/**
	 * Reverts groups created during a specific migration execution.
	 *
	 * @param MigrationExecutionReport $report Migration report from the run to revert.
	 * @param bool                     $force  Whether to force rollback ignoring detected post-migration edits.
	 * @return array{
	 *     success: bool,
	 *     execution_id: string,
	 *     reverted_groups_count: int,
	 *     reverted_elements_count: int,
	 *     blocked_groups_count: int,
	 *     blocked_groups: array<int, array{group_id: int, reason: string, code?: string}>,
	 *     message: string
	 * }
	 * @throws MigrationExecutionException If transaction fails.
	 */
	public function rollback_execution( MigrationExecutionReport $report, bool $force = false ): array {
		$execution_id = $report->get_execution_id();
		$group_ids    = $report->get_imported_group_ids();

		// Auto-register manifest from execution report if not already persisted.
		if ( '' !== $execution_id && null === $this->get_execution_manifest( $execution_id ) ) {
			$this->save_execution_manifest(
				$execution_id,
				array(
					'execution_id'       => $execution_id,
					'source'             => $report->get_source(),
					'imported_group_ids' => $group_ids,
					'created_at'         => $report->get_started_at(),
				)
			);
		}

		$result = $this->rollback_groups( $group_ids, $execution_id, $force );

		// Reset checkpoint for source upon full execution rollback.
		if ( $result['success'] ) {
			$this->checkpoint_repository->delete( $report->get_source() );
			$this->lock->force_release( $report->get_source() );
			if ( '' !== $execution_id ) {
				$this->delete_execution_manifest( $execution_id );
			}
		}

		return $result;
	}

	/**
	 * Atomically reverts a list of TFML groups and their associated retained translations.
	 *
	 * @param array<int, int> $group_ids    List of group IDs.
	 * @param string          $execution_id Execution token or identifier.
	 * @param bool            $force        Force flag.
	 * @return array{
	 *     success: bool,
	 *     execution_id: string,
	 *     reverted_groups_count: int,
	 *     reverted_elements_count: int,
	 *     blocked_groups_count: int,
	 *     blocked_groups: array<int, array{group_id: int, reason: string, code?: string}>,
	 *     message: string
	 * }
	 * @throws MigrationExecutionException If database operation fails.
	 */
	public function rollback_groups( array $group_ids, string $execution_id = '', bool $force = false ): array {
		if ( empty( $group_ids ) ) {
			return array(
				'success'                 => true,
				'execution_id'            => $execution_id,
				'reverted_groups_count'   => 0,
				'reverted_elements_count' => 0,
				'blocked_groups_count'    => 0,
				'blocked_groups'          => array(),
				'message'                 => 'No se encontraron grupos para revertir.',
			);
		}

		$inspection = $this->inspect_dependencies( $group_ids, $execution_id );
		$eligible   = $inspection['eligible_groups'];
		$blocked    = array();

		foreach ( $inspection['blocked_groups'] as $item ) {
			// force = true ONLY overrides post-migration editorial edits of legitimately owned groups.
			// It NEVER overrides lack of ownership (pre-existing or foreign groups are strictly protected)!
			if ( $force && 'editorial_edits' === ( $item['code'] ?? '' ) ) {
				$eligible[] = (int) $item['group_id'];
			} else {
				$blocked[] = $item;
			}
		}

		if ( empty( $eligible ) && ! empty( $blocked ) ) {
			return array(
				'success'                 => false,
				'execution_id'            => $execution_id,
				'reverted_groups_count'   => 0,
				'reverted_elements_count' => 0,
				'blocked_groups_count'    => count( $blocked ),
				'blocked_groups'          => $blocked,
				'message'                 => 'Reversión abortada: ningún grupo es elegible para eliminación (bloqueados por falta de propiedad demostrada o ediciones posteriores).',
			);
		}

		$reverted_groups_count   = 0;
		$reverted_elements_count = 0;

		$this->group_repository->begin_transaction();

		try {
			// If groups were already reverted in a prior rollback run (idempotent re-execution),
			// ensure any residual retained entries for requested IDs are cleaned up.
			if ( empty( $eligible ) && empty( $blocked ) ) {
				foreach ( $group_ids as $gid ) {
					$this->retained_registry->remove_by_group_id( (int) $gid );
				}
			}

			foreach ( $eligible as $group_id ) {
				$group = $this->group_repository->find( (int) $group_id );
				if ( null === $group ) {
					$this->retained_registry->remove_by_group_id( (int) $group_id );
					continue;
				}

				$elements_count = count( $group->get_elements() );
				$deleted        = $this->group_repository->delete( (int) $group_id );
				if ( ! $deleted ) {
					throw new MigrationExecutionException(
						sprintf( 'No se pudo eliminar el grupo TFML ID %d durante el rollback.', (int) $group_id )
					);
				}

				++$reverted_groups_count;
				$reverted_elements_count += $elements_count;
				$this->retained_registry->remove_by_group_id( (int) $group_id );

				// Verify post-delete state inside transaction.
				if ( null !== $this->retained_registry->get_by_group_id( (int) $group_id ) ) {
					throw new MigrationExecutionException(
						sprintf( 'Inconsistencia en rollback: El registro retenido del grupo ID %d permanece activo tras su purga.', (int) $group_id )
					);
				}
			}

			$this->group_repository->commit_transaction();

			return array(
				'success'                 => true,
				'execution_id'            => $execution_id,
				'reverted_groups_count'   => $reverted_groups_count,
				'reverted_elements_count' => $reverted_elements_count,
				'blocked_groups_count'    => count( $blocked ),
				'blocked_groups'          => $blocked,
				'message'                 => sprintf(
					'Reversión ejecutada exitosamente: %d grupos y %d elementos relacionales eliminados de TFML (Cero modificaciones en Core ni WPML).',
					$reverted_groups_count,
					$reverted_elements_count
				),
			);
		} catch ( Throwable $e ) {
			$this->group_repository->rollback_transaction();
			$this->retained_registry->invalidate_option_cache();

			throw new MigrationExecutionException(
				sprintf( 'Fallo transaccional durante el rollback de la ejecución "%s": %s', $execution_id, $e->getMessage() ),
				0,
				$e
			);
		}
	}

	/**
	 * Determines whether a live MySQL connection is available on $this->db.
	 *
	 * @return bool
	 */
	private function has_real_db(): bool {
		return isset( $this->db->dbh ) && null !== $this->db->dbh;
	}

	/**
	 * Returns the options table name for the active database connection.
	 *
	 * @return string
	 */
	private function get_options_table(): string {
		return $this->db->prefix . 'options';
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
