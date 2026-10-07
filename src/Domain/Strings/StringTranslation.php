<?php
/**
 * String Translation Entity.
 *
 * @package TF\Multilingual\Domain\String
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Strings;

use TF\Multilingual\Domain\Strings\Exceptions\InvalidStringException;

/**
 * Class StringTranslation
 *
 * Represents the translation of a TranslatableString for a specific language.
 */
class StringTranslation {

	/**
	 * Unique row ID in tfml_string_translations.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Foreign key referencing tfml_strings.id.
	 *
	 * @var int
	 */
	private int $string_id;

	/**
	 * Target language code.
	 *
	 * @var string
	 */
	private string $language_code;

	/**
	 * Translated text.
	 *
	 * @var string|null
	 */
	private ?string $translated_value;

	/**
	 * Version of the source string at the time this translation was saved/reviewed.
	 *
	 * @var int
	 */
	private int $source_version_translated;

	/**
	 * Physical status in database ('up_to_date', 'needs_review').
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Timestamp when translation was updated.
	 *
	 * @var string
	 */
	private string $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int         $id                        Row ID.
	 * @param int         $string_id                 Reference to string.
	 * @param string      $language_code             Language code.
	 * @param string|null $translated_value          Translated value.
	 * @param int         $source_version_translated Source version at translation.
	 * @param string      $status                    Physical schema status.
	 * @param string      $updated_at                Updated timestamp.
	 * @throws InvalidStringException If properties are invalid.
	 */
	public function __construct(
		int $id,
		int $string_id,
		string $language_code,
		?string $translated_value,
		int $source_version_translated = 1,
		string $status = StringStatus::DB_UP_TO_DATE,
		string $updated_at = ''
	) {
		$language_code = trim( $language_code );

		if ( $string_id <= 0 ) {
			throw new InvalidStringException( 'String ID must be a positive integer.' );
		}

		if ( '' === $language_code ) {
			throw new InvalidStringException( 'Language code cannot be empty.' );
		}

		$this->id                        = $id;
		$this->string_id                 = $string_id;
		$this->language_code             = $language_code;
		$this->translated_value          = $translated_value;
		$this->source_version_translated = max( 1, $source_version_translated );
		$this->status                    = StringStatus::is_valid_db_status( $status )
			? $status
			: StringStatus::to_db_status( $status );
		$this->updated_at                = $updated_at;
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
	 * Gets referenced string ID.
	 *
	 * @return int
	 */
	public function get_string_id(): int {
		return $this->string_id;
	}

	/**
	 * Gets target language code.
	 *
	 * @return string
	 */
	public function get_language_code(): string {
		return $this->language_code;
	}

	/**
	 * Gets translated value.
	 *
	 * @return string|null
	 */
	public function get_translated_value(): ?string {
		return $this->translated_value;
	}

	/**
	 * Gets source version at translation time.
	 *
	 * @return int
	 */
	public function get_source_version_translated(): int {
		return $this->source_version_translated;
	}

	/**
	 * Gets physical DB status ('up_to_date', 'needs_review').
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Gets canonical domain status ('updated', 'review', 'untranslated').
	 *
	 * @return string
	 */
	public function get_domain_status(): string {
		return StringStatus::to_domain_status( $this->status );
	}

	/**
	 * Checks if translation is synchronized / updated.
	 *
	 * @return bool
	 */
	public function is_up_to_date(): bool {
		return StringStatus::DB_UP_TO_DATE === $this->status;
	}

	/**
	 * Checks if translation requires review.
	 *
	 * @return bool
	 */
	public function needs_review(): bool {
		return StringStatus::DB_NEEDS_REVIEW === $this->status;
	}

	/**
	 * Gets updated timestamp.
	 *
	 * @return string
	 */
	public function get_updated_at(): string {
		return $this->updated_at;
	}
}
