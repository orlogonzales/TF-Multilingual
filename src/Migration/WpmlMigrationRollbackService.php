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
 * by a specific migration run, guaranteeing zero modifications to WordPress Core entities
 * and zero mutations to WPML tables.
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
	 * Constructor.
	 *
	 * @param wpdb|null                          $db                    Database instance.
	 * @param TranslationGroupRepository|null    $group_repository      Group repository.
	 * @param MigrationCheckpointRepository|null $checkpoint_repository Checkpoint repository.
	 * @param MigrationExecutionLock|null        $lock                  Lock manager.
	 */
	public function __construct(
		?wpdb $db = null,
		?TranslationGroupRepository $group_repository = null,
		?MigrationCheckpointRepository $checkpoint_repository = null,
		?MigrationExecutionLock $lock = null
	) {
		global $wpdb;

		$this->db                    = null !== $db ? $db : $wpdb;
		$this->group_repository      = null !== $group_repository ? $group_repository : new TranslationGroupRepository( $this->db );
		$this->checkpoint_repository = null !== $checkpoint_repository ? $checkpoint_repository : new MigrationCheckpointRepository();
		$this->lock                  = null !== $lock ? $lock : new MigrationExecutionLock();
	}

	/**
	 * Inspects potential rollback dependencies and blockers before executing deletion.
	 *
	 * Detects whether groups have been edited by editorial users post-migration.
	 *
	 * @param array<int, int> $group_ids List of group IDs to inspect.
	 * @return array{
	 *     eligible_groups: array<int, int>,
	 *     blocked_groups: array<int, array{group_id: int, reason: string}>
	 * }
	 */
	public function inspect_dependencies( array $group_ids ): array {
		$eligible = array();
		$blocked  = array();

		foreach ( $group_ids as $group_id ) {
			$group = $this->group_repository->find( (int) $group_id );
			if ( null === $group ) {
				continue;
			}

			// Check for post-migration content version increment.
			$has_editorial_edits = false;
			foreach ( $group->get_elements() as $element ) {
				if ( $element->get_current_content_version() > $element->get_source_version_at_translation() ) {
					$has_editorial_edits = true;
					break;
				}
			}

			if ( $has_editorial_edits ) {
				$blocked[] = array(
					'group_id' => (int) $group_id,
					'reason'   => 'El grupo presenta ediciones editoriales posteriores a la migración (versión de contenido incrementada).',
				);
			} else {
				$eligible[] = (int) $group_id;
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
	 *     blocked_groups: array<int, array{group_id: int, reason: string}>,
	 *     message: string
	 * }
	 * @throws MigrationExecutionException If transaction fails.
	 */
	public function rollback_execution( MigrationExecutionReport $report, bool $force = false ): array {
		$group_ids = $report->get_imported_group_ids();

		$result = $this->rollback_groups( $group_ids, $report->get_execution_id(), $force );

		// Reset checkpoint for source upon full execution rollback.
		if ( $result['success'] ) {
			$this->checkpoint_repository->delete( $report->get_source() );
			$this->lock->force_release( $report->get_source() );
		}

		return $result;
	}

	/**
	 * Atomically reverts a list of TFML groups.
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
	 *     blocked_groups: array<int, array{group_id: int, reason: string}>,
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

		$inspection = $this->inspect_dependencies( $group_ids );
		$eligible   = $force ? $group_ids : $inspection['eligible_groups'];
		$blocked    = $force ? array() : $inspection['blocked_groups'];

		if ( empty( $eligible ) && ! empty( $blocked ) ) {
			return array(
				'success'                 => false,
				'execution_id'            => $execution_id,
				'reverted_groups_count'   => 0,
				'reverted_elements_count' => 0,
				'blocked_groups_count'    => count( $blocked ),
				'blocked_groups'          => $blocked,
				'message'                 => 'Reversión abortada: todos los grupos planificados presentan dependencias o ediciones posteriores.',
			);
		}

		$reverted_groups_count   = 0;
		$reverted_elements_count = 0;

		$this->group_repository->begin_transaction();

		try {
			foreach ( $eligible as $group_id ) {
				$group = $this->group_repository->find( (int) $group_id );
				if ( null === $group ) {
					continue;
				}

				$elements_count = count( $group->get_elements() );
				$deleted        = $this->group_repository->delete( (int) $group_id );
				if ( $deleted ) {
					++$reverted_groups_count;
					$reverted_elements_count += $elements_count;
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

			throw new MigrationExecutionException(
				sprintf( 'Fallo transaccional durante el rollback de la ejecución "%s": %s', $execution_id, $e->getMessage() ),
				0,
				$e
			);
		}
	}
}
