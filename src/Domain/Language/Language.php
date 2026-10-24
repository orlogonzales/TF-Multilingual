<?php
/**
 * Language Domain Entity / Value Object.
 *
 * @package TF\Multilingual\Domain\Language
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Language;

use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;

/**
 * Class Language
 *
 * Represents an immutable language configured within TF Multilingual.
 */
final class Language {

	/**
	 * Canonical language code (e.g. 'es', 'en', 'pt-br', 'zh-cn').
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * WordPress locale string (e.g. 'es_ES', 'en_US', 'pt_BR').
	 *
	 * @var string
	 */
	private string $locale;

	/**
	 * International name in English / admin context (e.g. 'Spanish').
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Native name / endonym (e.g. 'Español').
	 *
	 * @var string
	 */
	private string $native_name;

	/**
	 * Whether the language is active for frontend availability.
	 *
	 * @var bool
	 */
	private bool $active;

	/**
	 * Sort order priority (ascending).
	 *
	 * @var int
	 */
	private int $order;

	/**
	 * Visual flag identifier (e.g. 'es', 'us', 'gb', 'pt', 'br', 'fr').
	 *
	 * @var string|null
	 */
	private ?string $flag_code;

	/**
	 * Private constructor. Enforces validation and canonical normalization.
	 *
	 * @param string      $code        Language code.
	 * @param string      $locale      WordPress locale.
	 * @param string      $name        International name.
	 * @param string      $native_name Native endonym.
	 * @param bool        $active      Active flag.
	 * @param int         $order       Sort order.
	 * @param string|null $flag_code   Optional visual flag identifier.
	 *
	 * @throws InvalidLanguageException If validation fails.
	 */
	public function __construct(
		string $code,
		string $locale,
		string $name,
		string $native_name,
		bool $active = true,
		int $order = 10,
		?string $flag_code = null
	) {
		$normalized_code   = self::normalize_code( $code );
		$normalized_locale = self::normalize_locale( $locale );
		$trimmed_name      = trim( $name );
		$trimmed_native    = trim( $native_name );

		self::validate_code( $normalized_code );
		self::validate_locale( $normalized_locale );

		if ( '' === $trimmed_name ) {
			throw new InvalidLanguageException( 'Language name cannot be empty.' );
		}

		if ( '' === $trimmed_native ) {
			throw new InvalidLanguageException( 'Language native name cannot be empty.' );
		}

		if ( $order < 0 ) {
			throw new InvalidLanguageException( 'Language sort order cannot be negative.' );
		}

		$this->code        = $normalized_code;
		$this->locale      = $normalized_locale;
		$this->name        = $trimmed_name;
		$this->native_name = $trimmed_native;
		$this->active      = $active;
		$this->order       = $order;
		$this->flag_code   = ( null !== $flag_code && '' !== trim( $flag_code ) )
			? strtolower( trim( $flag_code ) )
			: null;
	}

	/**
	 * Named factory constructor.
	 *
	 * @param string      $code        Language code.
	 * @param string      $locale      WordPress locale.
	 * @param string      $name        International name.
	 * @param string      $native_name Native endonym.
	 * @param bool        $active      Active flag.
	 * @param int         $order       Sort order.
	 * @param string|null $flag_code   Optional visual flag identifier.
	 * @return self
	 */
	public static function create(
		string $code,
		string $locale,
		string $name,
		string $native_name,
		bool $active = true,
		int $order = 10,
		?string $flag_code = null
	): self {
		return new self( $code, $locale, $name, $native_name, $active, $order, $flag_code );
	}

	/**
	 * Hydrates a Language instance from an array representation.
	 *
	 * @param array<string, mixed> $data Raw data array.
	 * @param string|null          $fallback_code Optional fallback code if missing in array keys.
	 * @return self
	 * @throws InvalidLanguageException If mandatory keys are missing or malformed.
	 */
	public static function from_array( array $data, ?string $fallback_code = null ): self {
		$code = $data['code'] ?? $fallback_code;
		if ( ! is_string( $code ) || '' === trim( $code ) ) {
			throw new InvalidLanguageException( 'Missing or invalid language code in array data.' );
		}

		$locale = $data['locale'] ?? '';
		if ( ! is_string( $locale ) || '' === trim( $locale ) ) {
			throw new InvalidLanguageException( sprintf( 'Missing locale for language "%s".', $code ) );
		}

		$name        = isset( $data['name'] ) && is_string( $data['name'] ) ? $data['name'] : '';
		$native_name = isset( $data['native_name'] ) && is_string( $data['native_name'] ) ? $data['native_name'] : $name;
		$active      = isset( $data['active'] ) ? (bool) $data['active'] : true;
		$order       = isset( $data['order'] ) && is_numeric( $data['order'] ) ? (int) $data['order'] : 10;
		$flag_code   = isset( $data['flag_code'] ) && is_string( $data['flag_code'] ) && '' !== trim( $data['flag_code'] )
			? trim( $data['flag_code'] )
			: null;

		return new self( $code, $locale, $name, $native_name, $active, $order, $flag_code );
	}

	/**
	 * Normalizes a language code into canonical form (lowercase, hyphens instead of underscores).
	 *
	 * @param string $code Raw language code.
	 * @return string Canonical normalized code.
	 */
	public static function normalize_code( string $code ): string {
		$cleaned = strtolower( trim( $code ) );
		$cleaned = str_replace( '_', '-', $cleaned );

		return $cleaned;
	}

	/**
	 * Normalizes a WordPress locale string into canonical form.
	 *
	 * @param string $locale Raw locale string.
	 * @return string Canonical normalized locale.
	 */
	public static function normalize_locale( string $locale ): string {
		$trimmed = trim( $locale );
		if ( '' === $trimmed ) {
			return '';
		}

		// If formatted as ll-cc, transform to ll_CC.
		if ( preg_match( '/^([a-z]{2,3})-([a-z0-9]{2,8})$/i', $trimmed, $matches ) ) {
			return strtolower( $matches[1] ) . '_' . strtoupper( $matches[2] );
		}

		return $trimmed;
	}

	/**
	 * Validates a normalized language code according to TFML conventions.
	 *
	 * Format: 2-3 lowercase alpha chars, optionally followed by hyphen and 2-8 alpha/numeric chars.
	 * Examples: 'es', 'en', 'pt-br', 'zh-cn', 'es-419'.
	 *
	 * @param string $code Normalized language code.
	 * @return void
	 * @throws InvalidLanguageException If code is invalid.
	 */
	public static function validate_code( string $code ): void {
		if ( '' === $code ) {
			throw new InvalidLanguageException( 'Language code cannot be empty.' );
		}

		if ( strlen( $code ) > 15 ) {
			throw new InvalidLanguageException(
				sprintf( 'Language code "%s" exceeds maximum length of 15 characters.', $code )
			);
		}

		if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $code ) ) {
			throw new InvalidLanguageException(
				sprintf( 'Language code "%s" does not match required format (e.g. "es", "en", "pt-br").', $code )
			);
		}
	}

	/**
	 * Validates a normalized WordPress locale.
	 *
	 * @param string $locale Normalized locale.
	 * @return void
	 * @throws InvalidLanguageException If locale is invalid.
	 */
	public static function validate_locale( string $locale ): void {
		if ( '' === $locale ) {
			throw new InvalidLanguageException( 'Locale cannot be empty.' );
		}

		if ( strlen( $locale ) > 25 ) {
			throw new InvalidLanguageException(
				sprintf( 'Locale "%s" exceeds maximum length of 25 characters.', $locale )
			);
		}

		if ( ! preg_match( '/^[a-z]{2,3}(?:_[a-zA-Z0-9]{2,8})*(?:@[a-zA-Z0-9]+)?$/', $locale ) ) {
			throw new InvalidLanguageException(
				sprintf( 'Locale "%s" does not match valid WordPress locale format (e.g. "es_ES", "en_US").', $locale )
			);
		}
	}

	/**
	 * Gets canonical language code.
	 *
	 * @return string
	 */
	public function get_code(): string {
		return $this->code;
	}

	/**
	 * Gets WordPress locale.
	 *
	 * @return string
	 */
	public function get_locale(): string {
		return $this->locale;
	}

	/**
	 * Gets international / administrative name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Gets native name / endonym.
	 *
	 * @return string
	 */
	public function get_native_name(): string {
		return $this->native_name;
	}

	/**
	 * Checks if language is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * Gets sort order priority.
	 *
	 * @return int
	 */
	public function get_order(): int {
		return $this->order;
	}

	/**
	 * Gets visual flag identifier, or null if relying on default mapping.
	 *
	 * @return string|null
	 */
	public function get_flag_code(): ?string {
		return $this->flag_code;
	}

	/**
	 * Returns a new instance with altered active state (immutable evolution).
	 *
	 * @param bool $active New active state.
	 * @return self
	 */
	public function with_active( bool $active ): self {
		if ( $this->active === $active ) {
			return $this;
		}

		return new self(
			$this->code,
			$this->locale,
			$this->name,
			$this->native_name,
			$active,
			$this->order,
			$this->flag_code
		);
	}

	/**
	 * Returns a new instance with altered sort order (immutable evolution).
	 *
	 * @param int $order New sort order.
	 * @return self
	 */
	public function with_order( int $order ): self {
		if ( $this->order === $order ) {
			return $this;
		}

		return new self(
			$this->code,
			$this->locale,
			$this->name,
			$this->native_name,
			$this->active,
			$order,
			$this->flag_code
		);
	}

	/**
	 * Returns a new instance with altered flag code (immutable evolution).
	 *
	 * @param string|null $flag_code New visual flag identifier or null to reset.
	 * @return self
	 */
	public function with_flag_code( ?string $flag_code ): self {
		$normalized = ( null !== $flag_code && '' !== trim( $flag_code ) )
			? strtolower( trim( $flag_code ) )
			: null;

		if ( $this->flag_code === $normalized ) {
			return $this;
		}

		return new self(
			$this->code,
			$this->locale,
			$this->name,
			$this->native_name,
			$this->active,
			$this->order,
			$normalized
		);
	}

	/**
	 * Returns a new instance with updated metadata (immutable evolution).
	 *
	 * @param string      $name        New international name.
	 * @param string      $native_name New native endonym.
	 * @param string|null $locale      Optional new locale.
	 * @param string|null $flag_code   Optional new flag code.
	 * @return self
	 */
	public function with_details( string $name, string $native_name, ?string $locale = null, ?string $flag_code = null ): self {
		return new self(
			$this->code,
			null !== $locale ? $locale : $this->locale,
			$name,
			$native_name,
			$this->active,
			$this->order,
			null !== $flag_code ? $flag_code : $this->flag_code
		);
	}

	/**
	 * Serializes to associative array representation for persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = array(
			'code'        => $this->code,
			'locale'      => $this->locale,
			'name'        => $this->name,
			'native_name' => $this->native_name,
			'active'      => $this->active,
			'order'       => $this->order,
		);

		if ( null !== $this->flag_code && '' !== $this->flag_code ) {
			$data['flag_code'] = $this->flag_code;
		}

		return $data;
	}
}
