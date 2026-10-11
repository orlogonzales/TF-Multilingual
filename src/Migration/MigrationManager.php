<?php
/**
 * Migration Manager Service.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use wpdb;

/**
 * Class MigrationManager
 *
 * Coordinates migration source detection, read-only analysis, dry-run simulations,
 * and migration reporting without performing unauthorized or unsafe writes.
 */
class MigrationManager {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Migration source detector.
	 *
	 * @var MigrationSourceDetector
	 */
	private MigrationSourceDetector $detector;

	/**
	 * WPML source analyzer.
	 *
	 * @var WpmlSourceAnalyzer
	 */
	private WpmlSourceAnalyzer $wpml_analyzer;

	/**
	 * Polylang source analyzer.
	 *
	 * @var PolylangSourceAnalyzer
	 */
	private PolylangSourceAnalyzer $polylang_analyzer;

	/**
	 * WPML migration planner.
	 *
	 * @var WpmlMigrationPlanner
	 */
	private WpmlMigrationPlanner $wpml_planner;

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
	 * WPML migration executor.
	 *
	 * @var WpmlMigrationExecutor|null
	 */
	private ?WpmlMigrationExecutor $wpml_executor = null;

	/**
	 * Constructor.
	 *
	 * @param wpdb                               $db                    WordPress database instance.
	 * @param LanguageRegistry                  $language_registry     Language registry.
	 * @param MigrationCheckpointRepository|null $checkpoint_repository Checkpoint repository.
	 * @param MigrationExecutionLock|null        $lock                  Lock manager.
	 * @param WpmlMigrationExecutor|null         $wpml_executor         WPML executor.
	 */
	public function __construct(
		wpdb $db,
		LanguageRegistry $language_registry,
		?MigrationCheckpointRepository $checkpoint_repository = null,
		?MigrationExecutionLock $lock = null,
		?WpmlMigrationExecutor $wpml_executor = null
	) {
		$this->db                    = $db;
		$this->language_registry     = $language_registry;
		$this->detector              = new MigrationSourceDetector( $db );
		$this->wpml_analyzer         = new WpmlSourceAnalyzer( $db, $language_registry );
		$this->polylang_analyzer     = new PolylangSourceAnalyzer( $db, $language_registry );
		$this->wpml_planner          = new WpmlMigrationPlanner( $db, $language_registry );
		$this->checkpoint_repository = null !== $checkpoint_repository ? $checkpoint_repository : new MigrationCheckpointRepository();
		$this->lock                  = null !== $lock ? $lock : new MigrationExecutionLock();
		$this->wpml_executor         = $wpml_executor;
	}

	/**
	 * Get migration source detector.
	 *
	 * @return MigrationSourceDetector
	 */
	public function get_detector(): MigrationSourceDetector {
		return $this->detector;
	}

	/**
	 * Get WPML analyzer.
	 *
	 * @return WpmlSourceAnalyzer
	 */
	public function get_wpml_analyzer(): WpmlSourceAnalyzer {
		return $this->wpml_analyzer;
	}

	/**
	 * Get Polylang analyzer.
	 *
	 * @return PolylangSourceAnalyzer
	 */
	public function get_polylang_analyzer(): PolylangSourceAnalyzer {
		return $this->polylang_analyzer;
	}

	/**
	 * Analyze specified source ('wpml' or 'polylang').
	 *
	 * @param string $source Source key.
	 * @return MigrationInventoryReport
	 */
	public function analyze_source( string $source ): MigrationInventoryReport {
		if ( 'wpml' === $source ) {
			return $this->wpml_analyzer->analyze();
		}

		if ( 'polylang' === $source ) {
			return $this->polylang_analyzer->analyze();
		}

		return new MigrationInventoryReport(
			array(
				'source'      => $source,
				'source_name' => ucfirst( $source ),
				'status'      => 'blocked',
				'notes'       => array( "Fuente de migración desconocida: '{$source}'." ),
			)
		);
	}

	/**
	 * Get WPML migration planner.
	 *
	 * @return WpmlMigrationPlanner
	 */
	public function get_wpml_planner(): WpmlMigrationPlanner {
		return $this->wpml_planner;
	}

	/**
	 * Generate migration plan for specified source.
	 *
	 * @param string $source Source key ('wpml').
	 * @param int    $limit  Maximum groups limit.
	 * @param int    $offset Group offset.
	 * @return MigrationPlan
	 */
	public function generate_plan( string $source, int $limit = 0, int $offset = 0 ): MigrationPlan {
		if ( 'wpml' === $source ) {
			return $this->wpml_planner->generate_plan( $limit, $offset );
		}

		return new MigrationPlan(
			array(
				'source'            => $source,
				'status'            => MigrationPlan::STATUS_BLOCKED,
				'missing_languages' => array(),
				'summary'           => array(),
				'media_plan'        => array(),
				'planned_groups'    => array(),
			)
		);
	}

	/**
	 * Detect all available migration sources with summary counts.
	 *
	 * @return array<string, array{available: bool, name: string, details: array<string, mixed>}>
	 */
	public function get_available_sources(): array {
		return $this->detector->detect_sources();
	}

	/**
	 * Gets the checkpoint repository instance.
	 *
	 * @return MigrationCheckpointRepository
	 */
	public function get_checkpoint_repository(): MigrationCheckpointRepository {
		return $this->checkpoint_repository;
	}

	/**
	 * Gets the migration execution lock manager.
	 *
	 * @return MigrationExecutionLock
	 */
	public function get_execution_lock(): MigrationExecutionLock {
		return $this->lock;
	}

	/**
	 * Gets or creates the WPML migration executor.
	 *
	 * @return WpmlMigrationExecutor
	 */
	public function get_wpml_executor(): WpmlMigrationExecutor {
		if ( null === $this->wpml_executor ) {
			$this->wpml_executor = new WpmlMigrationExecutor(
				$this->db,
				null,
				$this->language_registry,
				$this->checkpoint_repository,
				$this->lock
			);
		}

		return $this->wpml_executor;
	}

	/**
	 * Executes a validated migration plan via the appropriate executor.
	 *
	 * @param MigrationPlan $plan         Validated migration plan.
	 * @param int           $batch_size   Batch size per iteration.
	 * @param string|null   $execution_id Unique execution token.
	 * @return MigrationExecutionReport
	 */
	public function execute_plan(
		MigrationPlan $plan,
		int $batch_size = 50,
		?string $execution_id = null
	): MigrationExecutionReport {
		if ( 'wpml' === $plan->get_source() ) {
			return $this->get_wpml_executor()->run( $plan, $batch_size, $execution_id );
		}

		return new MigrationExecutionReport(
			array(
				'execution_id'         => $execution_id ?? uniqid( 'exec_', true ),
				'source'               => $plan->get_source(),
				'status'               => MigrationExecutionReport::STATUS_FAILED,
				'errors'               => array( array( 'message' => sprintf( 'No existe ejecutor para la fuente "%s".', $plan->get_source() ) ) ),
				'total_planned_groups' => count( $plan->get_planned_groups() ),
			)
		);
	}
}
