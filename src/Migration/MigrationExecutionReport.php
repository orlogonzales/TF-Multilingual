<?php
/**
 * Migration Execution Report Value Object.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

/**
 * Class MigrationExecutionReport
 *
 * Immutable report summarizing the execution, reconciliation, and audit log of a migration run.
 */
class MigrationExecutionReport {

	/**
	 * Status constant: Completed successfully.
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Status constant: In progress (partial batches remaining).
	 */
	public const STATUS_IN_PROGRESS = 'in_progress';

	/**
	 * Status constant: Failed with errors.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Status constant: Cancelled / Aborted.
	 */
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Execution identifier (UUID or token).
	 *
	 * @var string
	 */
	private string $execution_id;

	/**
	 * Migration source identifier.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Execution status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Number of batches processed.
	 *
	 * @var int
	 */
	private int $batch_count;

	/**
	 * Total planned groups in the plan.
	 *
	 * @var int
	 */
	private int $total_planned_groups;

	/**
	 * Total groups processed so far.
	 *
	 * @var int
	 */
	private int $processed_groups;

	/**
	 * Number of groups successfully imported into TFML.
	 *
	 * @var int
	 */
	private int $imported_groups;

	/**
	 * Number of groups skipped (blocked by language, unsupported, orphan, or conflict).
	 *
	 * @var int
	 */
	private int $skipped_groups;

	/**
	 * Number of groups that failed during execution.
	 *
	 * @var int
	 */
	private int $failed_groups;

	/**
	 * Total individual elements imported into tfml_group_elements.
	 *
	 * @var int
	 */
	private int $imported_elements;

	/**
	 * Log of encountered errors.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $errors;

	/**
	 * Individual results per processed group.
	 *
	 * @var array<int, array{trid: int, status: string, group_id: ?int, message: string}>
	 */
	private array $group_results;

	/**
	 * Final reconciliation summary.
	 *
	 * @var array{
	 *     source_total_groups: int,
	 *     tfml_imported_groups: int,
	 *     tfml_imported_elements: int,
	 *     skipped_groups: int,
	 *     retained_pending_elements: int,
	 *     discrepancies: array<int, string>,
	 *     integrity_verified: bool
	 * }
	 */
	private array $reconciliation;

	/**
	 * Execution start timestamp (UTC).
	 *
	 * @var string
	 */
	private string $started_at;

	/**
	 * Execution finish timestamp (UTC).
	 *
	 * @var string|null
	 */
	private ?string $finished_at;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Initialization data.
	 */
	public function __construct( array $data ) {
		$this->execution_id         = (string) ( $data['execution_id'] ?? '' );
		$this->source               = (string) ( $data['source'] ?? 'wpml' );
		$this->status               = (string) ( $data['status'] ?? self::STATUS_IN_PROGRESS );
		$this->batch_count          = (int) ( $data['batch_count'] ?? 0 );
		$this->total_planned_groups = (int) ( $data['total_planned_groups'] ?? 0 );
		$this->processed_groups     = (int) ( $data['processed_groups'] ?? 0 );
		$this->imported_groups      = (int) ( $data['imported_groups'] ?? 0 );
		$this->skipped_groups       = (int) ( $data['skipped_groups'] ?? 0 );
		$this->failed_groups        = (int) ( $data['failed_groups'] ?? 0 );
		$this->imported_elements    = (int) ( $data['imported_elements'] ?? 0 );
		$this->errors               = (array) ( $data['errors'] ?? array() );
		$this->group_results        = (array) ( $data['group_results'] ?? array() );
		$this->reconciliation       = (array) ( $data['reconciliation'] ?? array() );
		$this->started_at           = (string) ( $data['started_at'] ?? gmdate( 'Y-m-d H:i:s' ) );
		$this->finished_at          = isset( $data['finished_at'] ) ? (string) $data['finished_at'] : null;
	}

	/**
	 * Gets execution ID.
	 *
	 * @return string
	 */
	public function get_execution_id(): string {
		return $this->execution_id;
	}

	/**
	 * Gets source identifier.
	 *
	 * @return string
	 */
	public function get_source(): string {
		return $this->source;
	}

	/**
	 * Gets execution status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Gets number of batches processed.
	 *
	 * @return int
	 */
	public function get_batch_count(): int {
		return $this->batch_count;
	}

	/**
	 * Gets total planned groups count.
	 *
	 * @return int
	 */
	public function get_total_planned_groups(): int {
		return $this->total_planned_groups;
	}

	/**
	 * Gets processed groups count.
	 *
	 * @return int
	 */
	public function get_processed_groups(): int {
		return $this->processed_groups;
	}

	/**
	 * Gets successfully imported groups count.
	 *
	 * @return int
	 */
	public function get_imported_groups(): int {
		return $this->imported_groups;
	}

	/**
	 * Gets skipped groups count.
	 *
	 * @return int
	 */
	public function get_skipped_groups(): int {
		return $this->skipped_groups;
	}

	/**
	 * Gets failed groups count.
	 *
	 * @return int
	 */
	public function get_failed_groups(): int {
		return $this->failed_groups;
	}

	/**
	 * Gets total imported individual elements count.
	 *
	 * @return int
	 */
	public function get_imported_elements(): int {
		return $this->imported_elements;
	}

	/**
	 * Gets errors list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Gets individual group results.
	 *
	 * @return array<int, array{trid: int, status: string, group_id: ?int, message: string}>
	 */
	public function get_group_results(): array {
		return $this->group_results;
	}

	/**
	 * Gets list of TFML group IDs created during this execution.
	 *
	 * @return array<int, int>
	 */
	public function get_imported_group_ids(): array {
		$ids = array();
		foreach ( $this->group_results as $res ) {
			if ( 'imported' === ( $res['status'] ?? '' ) && ! empty( $res['group_id'] ) ) {
				$ids[] = (int) $res['group_id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Gets reconciliation summary.
	 *
	 * @return array<string, mixed>
	 */
	public function get_reconciliation(): array {
		return $this->reconciliation;
	}

	/**
	 * Checks if execution is completed.
	 *
	 * @return bool
	 */
	public function is_completed(): bool {
		return self::STATUS_COMPLETED === $this->status;
	}

	/**
	 * Converts report to serializable array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'execution_id'         => $this->execution_id,
			'source'               => $this->source,
			'status'               => $this->status,
			'batch_count'          => $this->batch_count,
			'total_planned_groups' => $this->total_planned_groups,
			'processed_groups'     => $this->processed_groups,
			'imported_groups'      => $this->imported_groups,
			'skipped_groups'       => $this->skipped_groups,
			'failed_groups'        => $this->failed_groups,
			'imported_elements'    => $this->imported_elements,
			'errors'               => $this->errors,
			'group_results'        => $this->group_results,
			'reconciliation'       => $this->reconciliation,
			'started_at'           => $this->started_at,
			'finished_at'          => $this->finished_at,
		);
	}
}
