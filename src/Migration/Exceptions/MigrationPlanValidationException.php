<?php
/**
 * Migration Plan Validation Exception.
 *
 * @package TF\Multilingual\Migration\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration\Exceptions;

/**
 * Class MigrationPlanValidationException
 *
 * Thrown when a migration plan fails pre-flight verification or is detected to be stale/altered.
 */
class MigrationPlanValidationException extends MigrationException {

	/**
	 * List of validation errors.
	 *
	 * @var array<int, string>
	 */
	private array $validation_errors;

	/**
	 * Constructor.
	 *
	 * @param string             $message           Exception message.
	 * @param array<int, string> $validation_errors Detailed validation errors.
	 */
	public function __construct( string $message, array $validation_errors = array() ) {
		parent::__construct( $message );
		$this->validation_errors = $validation_errors;
	}

	/**
	 * Gets the validation errors list.
	 *
	 * @return array<int, string>
	 */
	public function get_validation_errors(): array {
		return $this->validation_errors;
	}
}
