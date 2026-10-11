<?php
/**
 * Migration Authorization Exception.
 *
 * @package TF\Multilingual\Migration\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration\Exceptions;

/**
 * Class MigrationAuthorizationException
 *
 * Thrown when an execution is attempted without proper environment authorization or administrative permissions.
 */
class MigrationAuthorizationException extends MigrationException {
}
