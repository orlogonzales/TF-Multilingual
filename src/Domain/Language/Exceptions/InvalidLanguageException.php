<?php
/**
 * Invalid Language Exception.
 *
 * @package TF\Multilingual\Domain\Language\Exceptions
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Language\Exceptions;

/**
 * Class InvalidLanguageException
 *
 * Thrown when a language code, locale or property violates domain validation rules.
 */
class InvalidLanguageException extends LanguageDomainException {
}
