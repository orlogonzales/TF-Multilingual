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
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

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
		$this->checkpoint_repository = null !== $checkpoint_repository ? $checkpoint_repository : new MigrationCheckpointRepository();
		$this->lock                  = null !== $lock ? $lock : new MigrationExecutionLock();
		$this->retained_registry     = null !== $retained_registry ? $retained_registry : new RetainedTranslationRegistry();
	}

	/**
	 * Option key prefix for migration execution manifests.
	 */
	public const OPTION_MANIFEST_PREFIX = 'tfml_migration_manifest_';

	/**
	 * Gets the persisted execution manifest for an execution token.
	 *
	 * @param string $execution_id Execution token.
	 * @return array<string, mixed>|null
	 */
	public function get_execution_manifest( string $execution_id ): ?array {
		if ( '' === trim( $execution_id ) || ! function_exists( 'get_option' ) ) {
			return null;
		}

		$manifest = get_option( self::OPTION_MANIFEST_PREFIX . $execution_id, null );

		return is_array( $manifest ) ? $manifest : null;
	}

	/**
	 * Saves or registers an execution manifest.
	 *
	 * @param string               $execution_id Execution token.
	 * @param array<string, mixed> $data         Manifest data.
	 * @return bool
	 */
	public function save_execution_manifest( string $execution_id, array $data ): bool {
		if ( '' === trim( $execution_id ) || ! function_exists( 'update_option' ) ) {
			return false;
		}

		return update_option( self::OPTION_MANIFEST_PREFIX . $execution_id, $data, false );
	}

	/**
	 * Deletes an execution manifest upon successful rollback.
	 *
	 * @param string $execution_id Execution token.
	 * @return bool
	 */
	public function delete_execution_manifest( string $execution_id ): bool {
		if ( '' === trim( $execution_id ) || ! function_exists( 'delete_option' ) ) {
			return false;
		}

		return delete_option( self::OPTION_MANIFEST_PREFIX . $execution_id );
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
					$this->retained_registry->remove_by_group_id( (int) $group_id );
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
