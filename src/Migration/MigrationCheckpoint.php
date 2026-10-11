<?php
/**
 * Migration Checkpoint Value Object.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

/**
 * Class MigrationCheckpoint
 *
 * Encapsulates the execution state and progress of a migration batch,
 * enabling safe interruption, resumption, idempotence and audit logging.
 */
class MigrationCheckpoint {

	/**
	 * Status constant: Pending execution.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Status constant: In progress.
	 */
	public const STATUS_IN_PROGRESS = 'in_progress';

	/**
	 * Status constant: Successfully completed.
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Status constant: Failed with errors.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Source system identifier.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Total items to process in the plan.
	 *
	 * @var int
	 */
	private int $total_items;

	/**
	 * Items processed so far.
	 *
	 * @var int
	 */
	private int $processed_items;

	/**
	 * Last processed source TRID or identifier.
	 *
	 * @var int
	 */
	private int $last_processed_trid;

	/**
	 * Batch size for processing steps.
	 *
	 * @var int
	 */
	private int $batch_size;

	/**
	 * Current execution status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Log of encountered errors during execution.
	 *
	 * @var array<int, array{item_id: int, message: string, timestamp: string}>
	 */
	private array $errors;

	/**
	 * Checkpoint creation timestamp.
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Checkpoint last updated timestamp.
	 *
	 * @var string
	 */
	private string $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Initialization data.
	 */
	public function __construct( array $data = array() ) {
		$this->source              = (string) ( $data['source'] ?? 'wpml' );
		$this->total_items         = (int) ( $data['total_items'] ?? 0 );
		$this->processed_items     = (int) ( $data['processed_items'] ?? 0 );
		$this->last_processed_trid = (int) ( $data['last_processed_trid'] ?? 0 );
		$this->batch_size          = (int) ( $data['batch_size'] ?? 50 );
		$this->status              = (string) ( $data['status'] ?? self::STATUS_PENDING );
		$this->errors              = (array) ( $data['errors'] ?? array() );
		$this->created_at          = (string) ( $data['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) );
		$this->updated_at          = (string) ( $data['updated_at'] ?? gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Get source name.
	 *
	 * @return string
	 */
	public function get_source(): string {
		return $this->source;
	}

	/**
	 * Get total items.
	 *
	 * @return int
	 */
	public function get_total_items(): int {
		return $this->total_items;
	}

	/**
	 * Get processed items count.
	 *
	 * @return int
	 */
	public function get_processed_items(): int {
		return $this->processed_items;
	}

	/**
	 * Get last processed TRID.
	 *
	 * @return int
	 */
	public function get_last_processed_trid(): int {
		return $this->last_processed_trid;
	}

	/**
	 * Get batch size.
	 *
	 * @return int
	 */
	public function get_batch_size(): int {
		return $this->batch_size;
	}

	/**
	 * Get status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Get error log.
	 *
	 * @return array<int, array{item_id: int, message: string, timestamp: string}>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Check if execution is completed.
	 *
	 * @return bool
	 */
	public function is_completed(): bool {
		return self::STATUS_COMPLETED === $this->status;
	}

	/**
	 * Advance checkpoint progress.
	 *
	 * @param int $items_count Number of items processed in this step.
	 * @param int $last_trid   Last TRID processed.
	 * @return self New immutable instance with updated progress.
	 */
	public function advance( int $items_count, int $last_trid ): self {
		$processed = $this->processed_items + $items_count;
		$status    = $processed >= $this->total_items ? self::STATUS_COMPLETED : self::STATUS_IN_PROGRESS;

		return new self(
			array(
				'source'              => $this->source,
				'total_items'         => $this->total_items,
				'processed_items'     => $processed,
				'last_processed_trid' => $last_trid,
				'batch_size'          => $this->batch_size,
				'status'              => $status,
				'errors'              => $this->errors,
				'created_at'          => $this->created_at,
				'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Record an error in the checkpoint.
	 *
	 * @param int    $item_id Failed item ID or TRID.
	 * @param string $message Error description.
	 * @return self New immutable instance with recorded error.
	 */
	public function record_error( int $item_id, string $message ): self {
		$errors   = $this->errors;
		$errors[] = array(
			'item_id'   => $item_id,
			'message'   => $message,
			'timestamp' => gmdate( 'Y-m-d H:i:s' ),
		);

		return new self(
			array(
				'source'              => $this->source,
				'total_items'         => $this->total_items,
				'processed_items'     => $this->processed_items,
				'last_processed_trid' => $this->last_processed_trid,
				'batch_size'          => $this->batch_size,
				'status'              => self::STATUS_FAILED,
				'errors'              => $errors,
				'created_at'          => $this->created_at,
				'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Convert checkpoint to serializable array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'source'              => $this->source,
			'total_items'         => $this->total_items,
			'processed_items'     => $this->processed_items,
			'last_processed_trid' => $this->last_processed_trid,
			'batch_size'          => $this->batch_size,
			'status'              => $this->status,
			'errors'              => $this->errors,
			'created_at'          => $this->created_at,
			'updated_at'          => $this->updated_at,
		);
	}

	/**
	 * Hydrate checkpoint from array.
	 *
	 * @param array<string, mixed> $data Stored array data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self( $data );
	}
}
