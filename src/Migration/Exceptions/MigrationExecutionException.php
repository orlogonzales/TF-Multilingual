<?php
/**
 * Migration Execution Exception.
 *
 * @package TF\Multilingual\Migration\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration\Exceptions;

use Throwable;

/**
 * Class MigrationExecutionException
 *
 * Thrown when an error occurs during transactional batch execution.
 */
class MigrationExecutionException extends MigrationException {

	/**
	 * TRID of the group being processed when error occurred.
	 *
	 * @var int
	 */
	private int $trid;

	/**
	 * Constructor.
	 *
	 * @param string          $message  Exception message.
	 * @param int             $trid     Source TRID.
	 * @param Throwable|null  $previous Previous exception.
	 */
	public function __construct( string $message, int $trid = 0, ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->trid = $trid;
	}

	/**
	 * Gets the failing TRID.
	 *
	 * @return int
	 */
	public function get_trid(): int {
		return $this->trid;
	}
}
