<?php
/**
 * Translatable String Entity.
 *
 * @package TF\Multilingual\Domain\String
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Strings;

use TF\Multilingual\Domain\Strings\Exceptions\InvalidStringException;

/**
 * Class TranslatableString
 *
 * Represents an interface string registered with stable semantic identity (domain + string_key).
 */
class TranslatableString {

	/**
	 * Unique row ID in tfml_strings.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Logical domain (e.g. 'travel-flow', 'theme', 'default').
	 *
	 * @var string
	 */
	private string $domain;

	/**
	 * Stable semantic key (e.g. 'booking.button.confirm').
	 *
	 * @var string
	 */
	private string $string_key;

	/**
	 * Disambiguation context (e.g. 'verb', 'noun').
	 *
	 * @var string
	 */
	private string $context;

	/**
	 * Canonical source text in source language.
	 *
	 * @var string
	 */
	private string $original_value;

	/**
	 * Source language code (e.g. 'es').
	 *
	 * @var string
	 */
	private string $source_language;

	/**
	 * Logical version of the source text.
	 *
	 * @var int
	 */
	private int $string_version;

	/**
	 * Whether a registration conflict was detected.
	 *
	 * @var bool
	 */
	private bool $has_conflict;

	/**
	 * Timestamp when this string was last observed.
	 *
	 * @var string
	 */
	private string $last_seen_at;

	/**
	 * Timestamp when this string was created.
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Constructor.
	 *
	 * @param int    $id              Row ID.
	 * @param string $domain          String domain.
	 * @param string $string_key      Stable semantic key.
	 * @param string $context         Context.
	 * @param string $original_value  Original source value.
	 * @param string $source_language Source language code.
	 * @param int    $string_version  String content version.
	 * @param bool   $has_conflict    Conflict flag.
	 * @param string $last_seen_at    Last seen timestamp.
	 * @param string $created_at      Creation timestamp.
	 * @throws InvalidStringException If key or domain are invalid.
	 */
	public function __construct(
		int $id,
		string $domain,
		string $string_key,
		string $context,
		string $original_value,
		string $source_language = 'es',
		int $string_version = 1,
		bool $has_conflict = false,
		string $last_seen_at = '',
		string $created_at = ''
	) {
		$domain     = trim( $domain );
		$string_key = trim( $string_key );

		if ( '' === $domain ) {
			throw new InvalidStringException( 'String domain cannot be empty.' );
		}

		if ( '' === $string_key ) {
			throw new InvalidStringException( 'String key cannot be empty.' );
		}

		if ( $string_version < 1 ) {
			throw new InvalidStringException( 'String version must be at least 1.' );
		}

		$this->id              = $id;
		$this->domain          = $domain;
		$this->string_key      = $string_key;
		$this->context         = trim( $context );
		$this->original_value  = $original_value;
		$this->source_language = '' !== trim( $source_language ) ? trim( $source_language ) : 'es';
		$this->string_version  = $string_version;
		$this->has_conflict    = $has_conflict;
		$this->last_seen_at    = $last_seen_at;
		$this->created_at      = $created_at;
	}

	/**
	 * Gets row ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Gets domain.
	 *
	 * @return string
	 */
	public function get_domain(): string {
		return $this->domain;
	}

	/**
	 * Gets stable semantic key.
	 *
	 * @return string
	 */
	public function get_string_key(): string {
		return $this->string_key;
	}

	/**
	 * Gets context.
	 *
	 * @return string
	 */
	public function get_context(): string {
		return $this->context;
	}

	/**
	 * Gets original source text.
	 *
	 * @return string
	 */
	public function get_original_value(): string {
		return $this->original_value;
	}

	/**
	 * Gets source language.
	 *
	 * @return string
	 */
	public function get_source_language(): string {
		return $this->source_language;
	}

	/**
	 * Gets string version.
	 *
	 * @return int
	 */
	public function get_string_version(): int {
		return $this->string_version;
	}

	/**
	 * Checks if conflict is flagged.
	 *
	 * @return bool
	 */
	public function has_conflict(): bool {
		return $this->has_conflict;
	}

	/**
	 * Gets last seen timestamp.
	 *
	 * @return string
	 */
	public function get_last_seen_at(): string {
		return $this->last_seen_at;
	}

	/**
	 * Gets created timestamp.
	 *
	 * @return string
	 */
	public function get_created_at(): string {
		return $this->created_at;
	}
}
