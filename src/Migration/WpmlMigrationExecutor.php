<?php
/**
 * WPML Migration Transactional Executor.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\Exceptions\MigrationAuthorizationException;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationException;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;
use Throwable;
use wpdb;

/**
 * Class WpmlMigrationExecutor
 *
 * Transactional, idempotent, and recoverable execution engine for WPML migration plans.
 * Processes planned groups in atomic batches with checkpoint tracking, strict read-only
 * protection of source tables, and zero unvetted writes on live sites.
 */
class WpmlMigrationExecutor {

	/**
	 * WordPress database abstraction.
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
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Checkpoint repository.
	 *
	 * @var MigrationCheckpointRepository
	 */
	private MigrationCheckpointRepository $checkpoint_repository;

	/**
	 * Concurrency lock manager.
	 *
	 * @var MigrationExecutionLock
	 */
	private MigrationExecutionLock $lock;

	/**
	 * Element validator.
	 *
	 * @var WordPressElementValidator
	 */
	private WordPressElementValidator $element_validator;

	/**
	 * Schema manager.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Explicit authorization flag.
	 *
	 * @var bool
	 */
	private bool $is_authorized = false;

	/**
	 * Cached check for existence of WPML icl_translations table.
	 *
	 * @var bool|null
	 */
	private ?bool $has_icl_table = null;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null                          $db                    Database instance.
	 * @param TranslationGroupRepository|null    $group_repository      Group repository.
	 * @param LanguageRegistry|null              $language_registry     Language registry.
	 * @param MigrationCheckpointRepository|null $checkpoint_repository Checkpoint repository.
	 * @param MigrationExecutionLock|null        $lock                  Lock manager.
	 * @param WordPressElementValidator|null     $element_validator     Element validator.
	 * @param SchemaManager|null                 $schema_manager        Schema manager.
	 */
	public function __construct(
		?wpdb $db = null,
		?TranslationGroupRepository $group_repository = null,
		?LanguageRegistry $language_registry = null,
		?MigrationCheckpointRepository $checkpoint_repository = null,
		?MigrationExecutionLock $lock = null,
		?WordPressElementValidator $element_validator = null,
		?SchemaManager $schema_manager = null
	) {
		global $wpdb;

		$this->db                    = null !== $db ? $db : $wpdb;
		$this->language_registry     = null !== $language_registry ? $language_registry : new LanguageRegistry();
		$this->group_repository      = null !== $group_repository ? $group_repository : new TranslationGroupRepository( $this->db, $this->language_registry );
		$this->checkpoint_repository = null !== $checkpoint_repository ? $checkpoint_repository : new MigrationCheckpointRepository();
		$this->lock                  = null !== $lock ? $lock : new MigrationExecutionLock();
		$this->element_validator     = null !== $element_validator ? $element_validator : new WordPressElementValidator();
		$this->schema_manager        = null !== $schema_manager ? $schema_manager : new SchemaManager( $this->db );
	}

	/**
	 * Checks if WPML icl_translations table exists in current database.
	 *
	 * @return bool
	 */
	private function has_icl_translations_table(): bool {
		if ( null !== $this->has_icl_table ) {
			return $this->has_icl_table;
		}

		$table = $this->db->prefix . 'icl_translations';
		$found = $this->db->get_var(
			$this->db->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		$this->has_icl_table = ( $found === $table );
		return $this->has_icl_table;
	}

	/**
	 * Checks if execution is authorized in the current environment.
	 *
	 * @return bool
	 */
	public function is_authorized(): bool {
		if ( defined( 'TFML_MIGRATION_EXECUTION_AUTHORIZED' ) && true === constant( 'TFML_MIGRATION_EXECUTION_AUTHORIZED' ) ) {
			return true;
		}

		return $this->is_authorized;
	}

	/**
	 * Sets explicit environment authorization for testing or controlled migration.
	 *
	 * @param bool $authorized Authorization status.
	 * @return void
	 */
	public function set_authorized( bool $authorized ): void {
		$this->is_authorized = $authorized;
	}

	/**
	 * Asserts that the executor is authorized to perform database writes.
	 *
	 * @return void
	 * @throws MigrationAuthorizationException If unauthorized.
	 */
	private function assert_authorized(): void {
		if ( ! $this->is_authorized() ) {
			throw new MigrationAuthorizationException(
				'Ejecución no autorizada. Por directiva DT-TFML-035, el ejecutor transaccional requiere autorización explícita antes de realizar escrituras.'
			);
		}
	}

	/**
	 * Validates plan integrity, freshness, and compatibility prior to execution.
	 *
	 * Performs strict validation against:
	 * 1. Plan fingerprint match (if expected fingerprint provided).
	 * 2. Source and DB schema compatibility.
	 * 3. Element existence in WordPress Core.
	 * 4. Post status (rejects trashed posts).
	 * 5. WPML source divergence in icl_translations (TRID shift, language shift, or deletion).
	 * 6. Overlapping relational collisions introduced in TFML after dry-run planning.
	 *
	 * @param MigrationPlan $plan                 Migration plan.
	 * @param string|null   $expected_fingerprint Optional expected SHA-256 fingerprint.
	 * @return array{valid: bool, errors: array<int, string>}
	 */
	public function validate_plan( MigrationPlan $plan, ?string $expected_fingerprint = null ): array {
		$errors = array();

		// Check plan fingerprint if an expected fingerprint is provided.
		if ( null !== $expected_fingerprint && ! $plan->verify_fingerprint( $expected_fingerprint ) ) {
			$errors[] = sprintf(
				'Huella digital del plan inválida: La huella calculada ("%s") no coincide con la esperada ("%s").',
				$plan->get_fingerprint(),
				$expected_fingerprint
			);
		}

		// Check source compatibility.
		if ( 'wpml' !== $plan->get_source() ) {
			$errors[] = sprintf( 'Fuente del plan incompatible: "%s" (se esperaba "wpml").', $plan->get_source() );
		}

		// Check schema compatibility.
		if ( ! $this->schema_manager->is_installed() ) {
			$errors[] = 'El esquema de base de datos de TF Multilingual no está instalado o se encuentra desactualizado.';
		}

		// Check plan consistency.
		$groups = $plan->get_planned_groups();
		if ( empty( $groups ) ) {
			$errors[] = 'El plan de migración no contiene grupos planificados para procesar.';
		}

		$has_icl   = $this->has_icl_translations_table();
		$icl_table = $this->db->prefix . 'icl_translations';

		// Freshness check: Verify elements, trash status, WPML source divergence, and TFML collisions.
		foreach ( $groups as $group ) {
			if ( MigrationPlan::CLASSIFICATION_IMPORTABLE !== ( $group['classification'] ?? '' ) ) {
				continue;
			}

			$trid         = (int) ( $group['trid'] ?? 0 );
			$element_type = (string) ( $group['element_type'] ?? 'post' );
			$subtype      = (string) ( $group['subtype'] ?? '' );
			$translations = (array) ( $group['translations'] ?? array() );

			$assigned_groups = array();
			$found_count     = 0;

			foreach ( $translations as $lang => $element_id ) {
				$element_id = (int) $element_id;

				// 1. Core entity existence check.
				if ( ! $this->element_validator->exists( $element_type, $subtype, $element_id ) ) {
					$errors[] = sprintf(
						'Alteración de datos detectada: El elemento "%s" (%s) ID %d del TRID %d ya no existe en WordPress Core (plan obsoleto o alterado).',
						$element_type,
						$subtype,
						$element_id,
						$trid
					);
					if ( count( $errors ) >= 5 ) {
						break 2;
					}
					continue;
				}

				// 2. Trash post status check.
				if ( 'post' === $element_type && function_exists( 'get_post_status' ) ) {
					$post_status = get_post_status( $element_id );
					if ( 'trash' === $post_status ) {
						$errors[] = sprintf(
							'Alteración de datos detectada: El post ID %d del TRID %d se encuentra en la papelera (trash).',
							$element_id,
							$trid
						);
						if ( count( $errors ) >= 5 ) {
							break 2;
						}
					}
				}

				// 3. WPML source divergence check (if icl_translations exists).
				if ( $has_icl ) {
					$wpml_element_type = ( 'post' === $element_type ) ? 'post_' . $subtype : ( 'term' === $element_type ? 'tax_' . $subtype : $subtype );
					$row               = $this->db->get_row(
						$this->db->prepare(
							"SELECT trid, language_code FROM {$icl_table} WHERE element_id = %d AND element_type = %s LIMIT 1",
							$element_id,
							$wpml_element_type
						)
					);

					if ( null !== $row ) {
						$actual_trid = isset( $row->trid ) ? (int) $row->trid : 0;
						$actual_lang = isset( $row->language_code ) ? (string) $row->language_code : '';

						if ( $actual_trid !== $trid || $actual_lang !== (string) $lang ) {
							$errors[] = sprintf(
								'Divergencia en fuente WPML detectada: El elemento ID %d cambió en icl_translations (TRID actual %d vs plan %d, idioma actual "%s" vs plan "%s").',
								$element_id,
								$actual_trid,
								$trid,
								$actual_lang,
								$lang
							);
						}
					}

					if ( count( $errors ) >= 5 ) {
						break 2;
					}
				}

				// 4. Track pre-existing assignments in TFML.
				$existing = $this->group_repository->find_by_element( $element_type, $element_id );
				if ( null !== $existing ) {
					$assigned_groups[ $existing->get_id() ] = $existing;
					++$found_count;
				}
			}

			// Check for partial or conflicting assignments in TFML (not 100% idempotent replay).
			if ( 0 < $found_count && ( count( $translations ) !== $found_count || 1 !== count( $assigned_groups ) ) ) {
				$errors[] = sprintf(
					'Colisión relacional sobrevenida detectada: Los elementos del TRID %d colisionan con asignaciones previas en TFML (%s).',
					$trid,
					implode( ', ', array_keys( $assigned_groups ) )
				);
				if ( count( $errors ) >= 5 ) {
					break;
				}
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Executes a single atomic batch of the migration plan.
	 *
	 * @param MigrationPlan            $plan                 Validated migration plan.
	 * @param int                      $batch_size           Batch size.
	 * @param string|null              $execution_id         Optional unique execution token.
	 * @param MigrationCheckpoint|null $checkpoint           Optional custom checkpoint.
	 * @param string|null              $expected_fingerprint Optional expected plan fingerprint.
	 * @return array{
	 *     checkpoint: MigrationCheckpoint,
	 *     batch_results: array<int, array<string, mixed>>,
	 *     finished: bool
	 * }
	 * @throws MigrationAuthorizationException If unauthorized.
	 * @throws MigrationConcurrencyException If locked by another execution or fencing token expires.
	 * @throws MigrationPlanValidationException If plan is invalid or stale.
	 * @throws MigrationExecutionException If transaction fails.
	 */
	public function execute_batch(
		MigrationPlan $plan,
		int $batch_size = 50,
		?string $execution_id = null,
		?MigrationCheckpoint $checkpoint = null,
		?string $expected_fingerprint = null
	): array {
		$this->assert_authorized();

		$token = null !== $execution_id ? $execution_id : ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'exec_', true ) );

		// Concurrency and fencing control:
		if ( $this->lock->verify_ownership( 'wpml', $token ) ) {
			// Lock is valid and owned by current execution; renew TTL.
			$this->lock->renew( 'wpml', $token );
		} elseif ( ! $this->lock->acquire( 'wpml', $token ) ) {
			throw MigrationConcurrencyException::for_locked_source( 'wpml', (array) $this->lock->get_lock_info( 'wpml' ) );
		}

		$validation = $this->validate_plan( $plan, $expected_fingerprint );
		if ( ! $validation['valid'] ) {
			$this->lock->release( 'wpml', $token );
			throw new MigrationPlanValidationException(
				'El plan de migración no superó la verificación previa de integridad y vigencia.',
				$validation['errors']
			);
		}

		$all_groups = $plan->get_planned_groups();
		if ( null === $checkpoint ) {
			$checkpoint = $this->checkpoint_repository->get( 'wpml' );
			if ( null === $checkpoint ) {
				$checkpoint = new MigrationCheckpoint(
					array(
						'source'      => 'wpml',
						'total_items' => count( $all_groups ),
						'batch_size'  => $batch_size,
						'status'      => MigrationCheckpoint::STATUS_PENDING,
					)
				);
			}
		}

		$last_processed_trid = $checkpoint->get_last_processed_trid();

		// Find slice of groups to process.
		$slice = array();
		foreach ( $all_groups as $g ) {
			$trid = (int) ( $g['trid'] ?? 0 );
			if ( $trid > $last_processed_trid ) {
				$slice[] = $g;
				if ( count( $slice ) >= $batch_size ) {
					break;
				}
			}
		}

		// If no groups left, mark as completed.
		if ( empty( $slice ) ) {
			$completed_cp = new MigrationCheckpoint(
				array(
					'source'              => 'wpml',
					'total_items'         => count( $all_groups ),
					'processed_items'     => count( $all_groups ),
					'last_processed_trid' => $last_processed_trid,
					'batch_size'          => $batch_size,
					'status'              => MigrationCheckpoint::STATUS_COMPLETED,
					'errors'              => $checkpoint->get_errors(),
				)
			);
			$this->checkpoint_repository->save( $completed_cp );
			$this->lock->release( 'wpml', $token );

			return array(
				'checkpoint'    => $completed_cp,
				'batch_results' => array(),
				'finished'      => true,
			);
		}

		$batch_results = array();
		$current_trid  = 0;

		// Begin transaction.
		$this->group_repository->begin_transaction();

		try {
			foreach ( $slice as $group ) {
				$current_trid   = (int) ( $group['trid'] ?? 0 );
				$classification = (string) ( $group['classification'] ?? '' );
				$element_type   = (string) ( $group['element_type'] ?? 'post' );
				$subtype        = (string) ( $group['subtype'] ?? '' );
				$canonical_id   = isset( $group['canonical_id'] ) ? (int) $group['canonical_id'] : null;
				$canonical_lang = isset( $group['canonical_lang'] ) ? (string) $group['canonical_lang'] : null;
				$translations   = (array) ( $group['translations'] ?? array() );

				// Enforce Media Model B Refinado: Reject any standalone post_attachment group.
				if ( 'attachment' === $subtype || 'post_attachment' === $subtype ) {
					$batch_results[] = array(
						'trid'     => $current_trid,
						'status'   => 'skipped_media_model_b',
						'group_id' => null,
						'message'  => 'Adjunto excluido de grupos editoriales bajo Media Model B Refinado.',
					);
					continue;
				}

				switch ( $classification ) {
					case MigrationPlan::CLASSIFICATION_IMPORTABLE:
						$import_result   = $this->import_group(
							$current_trid,
							$element_type,
							$subtype,
							$canonical_id,
							$canonical_lang,
							$translations
						);
						$batch_results[] = $import_result;
						break;

					case MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE:
						$pending_langs   = array_keys( (array) ( $group['pending_translations'] ?? array() ) );
						$batch_results[] = array(
							'trid'     => $current_trid,
							'status'   => 'skipped_blocked_language',
							'group_id' => null,
							'message'  => sprintf(
								'Grupo retenido: idioma no configurado en TFML (%s).',
								implode( ', ', $pending_langs )
							),
						);
						break;

					case MigrationPlan::CLASSIFICATION_SKIPPED:
						$reasons         = (array) ( $group['reasons'] ?? array() );
						$batch_results[] = array(
							'trid'     => $current_trid,
							'status'   => 'skipped_unsupported',
							'group_id' => null,
							'message'  => ! empty( $reasons ) ? implode( '; ', $reasons ) : 'Tipo no soportado o excluido.',
						);
						break;

					case MigrationPlan::CLASSIFICATION_ORPHAN:
						$batch_results[] = array(
							'trid'     => $current_trid,
							'status'   => 'skipped_orphan',
							'group_id' => null,
							'message'  => 'Elementos huérfanos: eliminados previamente en WordPress Core.',
						);
						break;

					case MigrationPlan::CLASSIFICATION_CONFLICT:
						$batch_results[] = array(
							'trid'     => $current_trid,
							'status'   => 'skipped_conflict',
							'group_id' => null,
							'message'  => 'Colisión relacional preexistente detectada en la planificación.',
						);
						break;

					default:
						$batch_results[] = array(
							'trid'     => $current_trid,
							'status'   => 'skipped_unknown',
							'group_id' => null,
							'message'  => 'Clasificación desconocida.',
						);
						break;
				}
			}

			// Advance checkpoint inside transaction.
			$new_checkpoint = $checkpoint->advance( count( $slice ), $current_trid );

			// Fencing token verification right before COMMIT: ensure lock was not expired or stolen.
			if ( ! $this->lock->verify_ownership( 'wpml', $token ) ) {
				throw new MigrationConcurrencyException(
					sprintf( 'Fencing token inválido o expirado para la ejecución "%s". La transacción ha sido abortada para evitar escrituras en conflicto.', $token )
				);
			}

			// Commit transaction.
			$this->group_repository->commit_transaction();

			// Persist checkpoint outside database transaction.
			$this->checkpoint_repository->save( $new_checkpoint );

			$is_finished = $new_checkpoint->is_completed();
			if ( $is_finished ) {
				$this->lock->release( 'wpml', $token );
			}

			return array(
				'checkpoint'    => $new_checkpoint,
				'batch_results' => $batch_results,
				'finished'      => $is_finished,
			);
		} catch ( Throwable $e ) {
			// Rollback transaction.
			$this->group_repository->rollback_transaction();

			$failed_checkpoint = $checkpoint->record_error( $current_trid, $e->getMessage() );
			$this->checkpoint_repository->save( $failed_checkpoint );

			$this->lock->release( 'wpml', $token );

			if ( $e instanceof MigrationException ) {
				throw $e;
			}

			throw new MigrationExecutionException(
				sprintf( 'Error fatal en la ejecución del lote (TRID %d): %s', $current_trid, $e->getMessage() ),
				$current_trid,
				$e
			);
		}
	}

	/**
	 * Imports a single group idempotently.
	 *
	 * @param int                $trid           Source TRID.
	 * @param string             $element_type   Element type ('post', 'term').
	 * @param string             $subtype        Subtype (post_type or taxonomy).
	 * @param int|null           $canonical_id   Canonical element ID.
	 * @param string|null        $canonical_lang Canonical language code.
	 * @param array<string, int> $translations   Translations map (lang => element_id).
	 * @return array{trid: int, status: string, group_id: ?int, message: string}
	 * @throws MigrationExecutionException If element mapping is in conflict across multiple groups.
	 */
	private function import_group(
		int $trid,
		string $element_type,
		string $subtype,
		?int $canonical_id,
		?string $canonical_lang,
		array $translations
	): array {
		if ( empty( $translations ) || null === $canonical_id || null === $canonical_lang ) {
			return array(
				'trid'     => $trid,
				'status'   => 'skipped_incomplete',
				'group_id' => null,
				'message'  => 'Grupo incompleto o sin canónico definido.',
			);
		}

		// Idempotency check: Inspect if elements are already registered in TFML.
		$existing_groups = array();
		$found_count     = 0;
		foreach ( $translations as $lang => $element_id ) {
			$element_id = (int) $element_id;
			$existing   = $this->group_repository->find_by_element( $element_type, $element_id );
			if ( null !== $existing ) {
				$existing_groups[ $existing->get_id() ] = $existing;
				++$found_count;
			}
		}

		// Case 1: All elements of this group are already part of the exact same TFML group (100% idempotent).
		if ( count( $translations ) === $found_count && 1 === count( $existing_groups ) ) {
			$existing_group_id = (int) array_key_first( $existing_groups );

			return array(
				'trid'     => $trid,
				'status'   => 'idempotent_skipped',
				'group_id' => $existing_group_id,
				'message'  => 'Grupo ya importado previamente (idempotencia garantizada).',
			);
		}

		// Case 2: One or more elements are already assigned to existing groups -> relational conflict!
		if ( $found_count > 0 ) {
			throw new MigrationExecutionException(
				sprintf(
					'Colisión de asignación: Uno o más elementos del TRID %d ya pertenecen a grupos preexistentes en TFML (%s).',
					$trid,
					implode( ', ', array_keys( $existing_groups ) )
				),
				$trid
			);
		}

		// Case 3: Elements not yet imported -> Create new TranslationGroup in TFML.

		$persisted = $this->group_repository->create_group(
			$element_type,
			$subtype,
			$canonical_id,
			$canonical_lang,
			true
		);

		// Add all remaining translations.
		foreach ( $translations as $lang => $element_id ) {
			$element_id = (int) $element_id;
			if ( $element_id === $canonical_id ) {
				continue;
			}

			$this->group_repository->add_translation(
				(int) $persisted->get_id(),
				$element_id,
				$lang,
				false
			);
		}

		return array(
			'trid'     => $trid,
			'status'   => 'imported',
			'group_id' => $persisted->get_id(),
			'message'  => sprintf( 'Grupo importado exitosamente con %d elementos.', count( $translations ) ),
		);
	}


	/**
	 * Runs the migration plan completely in iterative batches.
	 *
	 * @param MigrationPlan $plan                 Validated migration plan.
	 * @param int           $batch_size           Batch size per iteration.
	 * @param string|null   $execution_id         Optional unique execution token.
	 * @param string|null   $expected_fingerprint Optional expected plan fingerprint.
	 * @return MigrationExecutionReport Final reconciliation report.
	 * @throws MigrationConcurrencyException If lock renewal fails or is hijacked.
	 */
	public function run(
		MigrationPlan $plan,
		int $batch_size = 50,
		?string $execution_id = null,
		?string $expected_fingerprint = null
	): MigrationExecutionReport {
		$this->assert_authorized();

		$token      = null !== $execution_id ? $execution_id : ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'exec_', true ) );
		$started_at = gmdate( 'Y-m-d H:i:s' );

		// Initial lock acquisition.
		if ( ! $this->lock->acquire( 'wpml', $token ) ) {
			throw MigrationConcurrencyException::for_locked_source( 'wpml', (array) $this->lock->get_lock_info( 'wpml' ) );
		}

		$all_groups           = $plan->get_planned_groups();
		$total_planned_groups = count( $all_groups );
		$batch_count          = 0;
		$all_results          = array();
		$checkpoint           = null;

		$imported_groups   = 0;
		$skipped_groups    = 0;
		$failed_groups     = 0;
		$imported_elements = 0;

		$is_finished = false;

		while ( ! $is_finished ) {
			// Renew lock before processing each batch.
			if ( ! $this->lock->renew( 'wpml', $token ) && ! $this->lock->acquire( 'wpml', $token ) ) {
				throw MigrationConcurrencyException::for_locked_source( 'wpml', (array) $this->lock->get_lock_info( 'wpml' ) );
			}

			$batch_response = $this->execute_batch( $plan, $batch_size, $token, $checkpoint, $expected_fingerprint );
			$checkpoint     = $batch_response['checkpoint'];
			$batch_results  = $batch_response['batch_results'];
			$is_finished    = $batch_response['finished'];
			++$batch_count;

			foreach ( $batch_results as $res ) {
				$all_results[] = $res;
				$status        = (string) ( $res['status'] ?? '' );
				if ( 'imported' === $status ) {
					++$imported_groups;
				} elseif ( 'idempotent_skipped' === $status ) {
					++$skipped_groups;
				} elseif ( str_starts_with( $status, 'skipped' ) ) {
					++$skipped_groups;
				} elseif ( 'failed' === $status ) {
					++$failed_groups;
				}
			}

			if ( empty( $batch_results ) && ! $is_finished ) {
				break;
			}
		}

		$reconciliation = $this->reconcile( $plan );

		// Count imported elements from reconciliation.
		$imported_elements = (int) ( $reconciliation['tfml_imported_elements'] ?? 0 );

		// Extract imported group IDs and register execution manifest for ownership tracking.
		$imported_group_ids = array();
		foreach ( $all_results as $res ) {
			if ( 'imported' === ( $res['status'] ?? '' ) && ! empty( $res['group_id'] ) ) {
				$imported_group_ids[] = (int) $res['group_id'];
			}
		}
		$imported_group_ids = array_values( array_unique( $imported_group_ids ) );

		if ( function_exists( 'update_option' ) ) {
			update_option(
				WpmlMigrationRollbackService::OPTION_MANIFEST_PREFIX . $token,
				array(
					'execution_id'       => $token,
					'source'             => 'wpml',
					'plan_fingerprint'   => $plan->get_fingerprint(),
					'imported_group_ids' => $imported_group_ids,
					'started_at'         => $started_at,
					'finished_at'        => gmdate( 'Y-m-d H:i:s' ),
				),
				false
			);
		}

		return new MigrationExecutionReport(
			array(
				'execution_id'         => $token,
				'source'               => 'wpml',
				'status'               => $is_finished ? MigrationExecutionReport::STATUS_COMPLETED : MigrationExecutionReport::STATUS_IN_PROGRESS,
				'batch_count'          => $batch_count,
				'total_planned_groups' => $total_planned_groups,
				'processed_groups'     => count( $all_results ),
				'imported_groups'      => $imported_groups,
				'skipped_groups'       => $skipped_groups,
				'failed_groups'        => $failed_groups,
				'imported_elements'    => $imported_elements,
				'errors'               => $checkpoint ? $checkpoint->get_errors() : array(),
				'group_results'        => $all_results,
				'reconciliation'       => $reconciliation,
				'started_at'           => $started_at,
				'finished_at'          => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Deeply reconciles the migration plan against actual database records in TFML tables.
	 *
	 * Verifies correspondence of groups, individual elements, canonical mapping, language codes,
	 * subtype matching, and checks for zero orphan or fragmented assignments.
	 *
	 * @param MigrationPlan $plan Plan to reconcile against.
	 * @return array{
	 *     source_total_groups: int,
	 *     tfml_imported_groups: int,
	 *     tfml_imported_elements: int,
	 *     skipped_groups: int,
	 *     retained_pending_elements: int,
	 *     discrepancies: array<int, string>,
	 *     integrity_verified: bool
	 * }
	 */
	public function reconcile( MigrationPlan $plan ): array {
		$groups = $plan->get_planned_groups();

		$imported_groups_count   = 0;
		$imported_elements_count = 0;
		$skipped_count           = 0;
		$discrepancies           = array();

		foreach ( $groups as $g ) {
			$classification = (string) ( $g['classification'] ?? '' );
			$element_type   = (string) ( $g['element_type'] ?? 'post' );
			$subtype        = (string) ( $g['subtype'] ?? '' );
			$canonical_id   = isset( $g['canonical_id'] ) ? (int) $g['canonical_id'] : null;
			$canonical_lang = isset( $g['canonical_lang'] ) ? (string) $g['canonical_lang'] : null;
			$translations   = (array) ( $g['translations'] ?? array() );
			$trid           = (int) ( $g['trid'] ?? 0 );

			if ( MigrationPlan::CLASSIFICATION_IMPORTABLE === $classification ) {
				$found_groups = array();
				foreach ( $translations as $lang => $eid ) {
					$element_id = (int) $eid;
					$found      = $this->group_repository->find_by_element( $element_type, $element_id );
					if ( null !== $found ) {
						$found_groups[ $found->get_id() ] = $found;
					} else {
						$discrepancies[] = sprintf( 'TRID %d: Elemento ID %d (%s) planificado no fue encontrado en TFML.', $trid, $element_id, $lang );
					}
				}

				if ( 1 === count( $found_groups ) ) {
					/** @var TranslationGroup $group */
					$group = reset( $found_groups );

					// Verify canonical element ID.
					$group_canonical = $group->get_canonical_element();
					if ( null !== $canonical_id && ( null === $group_canonical || $group_canonical->get_element_id() !== $canonical_id ) ) {
						$discrepancies[] = sprintf(
							'TRID %d: El canónico en TFML (%s) no coincide con el plan (%d).',
							$trid,
							null !== $group_canonical ? (string) $group_canonical->get_element_id() : 'ninguno',
							$canonical_id
						);
					}

					// Verify canonical language code.
					if ( null !== $canonical_lang && ( null === $group_canonical || $group_canonical->get_language_code() !== $canonical_lang ) ) {
						$discrepancies[] = sprintf(
							'TRID %d: El idioma canónico en TFML (%s) no coincide con el plan (%s).',
							$trid,
							null !== $group_canonical ? $group_canonical->get_language_code() : 'ninguno',
							$canonical_lang
						);
					}

					// Verify subtype.
					if ( $group->get_subtype() !== $subtype ) {
						$discrepancies[] = sprintf(
							'TRID %d: Subtipo en TFML ("%s") no coincide con el plan ("%s").',
							$trid,
							$group->get_subtype(),
							$subtype
						);
					}

					// Verify translation count and language codes.
					$group_elements = $group->get_elements();
					if ( count( $group_elements ) !== count( $translations ) ) {
						$discrepancies[] = sprintf(
							'TRID %d: El grupo TFML ID %d contiene %d elementos, pero el plan definía %d.',
							$trid,
							(int) $group->get_id(),
							count( $group_elements ),
							count( $translations )
						);
					}

					foreach ( $translations as $plan_lang => $plan_eid ) {
						$el = $group->get_translation( (string) $plan_lang );
						if ( null === $el || $el->get_element_id() !== (int) $plan_eid ) {
							$discrepancies[] = sprintf(
								'TRID %d: Asignación de idioma "%s" discrepante en grupo TFML ID %d (ID actual %s vs plan %d).',
								$trid,
								$plan_lang,
								(int) $group->get_id(),
								null !== $el ? (string) $el->get_element_id() : 'ninguno',
								(int) $plan_eid
							);
						}
					}

					++$imported_groups_count;
					$imported_elements_count += count( $translations );
				} else {
					if ( empty( $found_groups ) ) {
						$discrepancies[] = sprintf( 'TRID %d: Grupo planificado como IMPORTABLE no fue encontrado en TFML.', $trid );
					} else {
						$discrepancies[] = sprintf(
							'TRID %d: Elementos fragmentados en múltiples grupos TFML (%s).',
							$trid,
							implode( ', ', array_keys( $found_groups ) )
						);
					}
				}
			} else {
				++$skipped_count;
			}
		}

		$summary             = $plan->get_summary();
		$expected_importable = isset( $summary['importable_groups'] )
			? (int) $summary['importable_groups']
			: count( $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_IMPORTABLE ) );
		$integrity_verified  = empty( $discrepancies ) && ( $imported_groups_count === $expected_importable );

		return array(
			'source_total_groups'       => count( $groups ),
			'tfml_imported_groups'      => $imported_groups_count,
			'tfml_imported_elements'    => $imported_elements_count,
			'skipped_groups'            => $skipped_count,
			'retained_pending_elements' => (int) ( $summary['pending_elements'] ?? 0 ),
			'discrepancies'             => $discrepancies,
			'integrity_verified'        => $integrity_verified,
		);
	}
}
