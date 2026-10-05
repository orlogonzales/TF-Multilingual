<?php
/**
 * Translation Element Entity / Value Object.
 *
 * @package TF\Multilingual\Domain\Translation
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Translation;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException;

/**
 * Class TranslationElement
 *
 * Represents an individual WordPress object (post or term) associated with a translation group.
 */
final class TranslationElement {

	/**
	 * Primary key in tfml_group_elements table.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Foreign key pointing to tfml_groups.id.
	 *
	 * @var int|null
	 */
	private ?int $group_id;

	/**
	 * Element type ('post' or 'term').
	 *
	 * @var string
	 */
	private string $element_type;

	/**
	 * WordPress object ID (post_id or term_id).
	 *
	 * @var int
	 */
	private int $element_id;

	/**
	 * Canonical language code.
	 *
	 * @var string
	 */
	private string $language_code;

	/**
	 * Version of source content when translation was established/updated.
	 *
	 * @var int
	 */
	private int $source_version_at_translation;

	/**
	 * Current version of this element's translated content.
	 *
	 * @var int
	 */
	private int $current_content_version;

	/**
	 * Content fingerprint for change detection.
	 *
	 * @var string
	 */
	private string $translatable_fingerprint;

	/**
	 * Timestamp of last relation update (UTC).
	 *
	 * @var string|null
	 */
	private ?string $updated_at;

	/**
	 * Constructor.
	 *
	 * @param string      $element_type                  Element type ('post' or 'term').
	 * @param int         $element_id                    WordPress object ID.
	 * @param string      $language_code                 Language code.
	 * @param int|null    $group_id                      Group ID.
	 * @param int|null    $id                            Element relation ID.
	 * @param int         $source_version_at_translation Source version.
	 * @param int         $current_content_version       Content version.
	 * @param string      $translatable_fingerprint      Fingerprint.
	 * @param string|null $updated_at                    Updated at UTC timestamp.
	 *
	 * @throws InvalidTranslationElementException If parameters are invalid.
	 */
	public function __construct(
		string $element_type,
		int $element_id,
		string $language_code,
		?int $group_id = null,
		?int $id = null,
		int $source_version_at_translation = 1,
		int $current_content_version = 1,
		string $translatable_fingerprint = '',
		?string $updated_at = null
	) {
		$normalized_type = strtolower( trim( $element_type ) );
		if ( ! in_array( $normalized_type, array( 'post', 'term' ), true ) ) {
			throw InvalidTranslationElementException::for_unsupported_type( $element_type );
		}

		if ( $element_id <= 0 ) {
			throw new InvalidTranslationElementException(
				sprintf( 'Element ID must be a positive integer, received %d.', $element_id )
			);
		}

		$normalized_lang = Language::normalize_code( $language_code );
		if ( '' === $normalized_lang ) {
			throw new InvalidTranslationElementException( 'Language code cannot be empty.' );
		}

		$this->element_type                  = $normalized_type;
		$this->element_id                    = $element_id;
		$this->language_code                 = $normalized_lang;
		$this->group_id                      = $group_id;
		$this->id                            = $id;
		$this->source_version_at_translation = max( 1, $source_version_at_translation );
		$this->current_content_version       = max( 1, $current_content_version );
		$this->translatable_fingerprint      = $translatable_fingerprint;
		$this->updated_at                    = $updated_at;
	}

	/**
	 * Named factory constructor for creating a new unpersisted element.
	 *
	 * @param string      $element_type                  Element type ('post' or 'term').
	 * @param int         $element_id                    WordPress object ID.
	 * @param string      $language_code                 Language code.
	 * @param int|null    $group_id                      Optional group ID.
	 * @param int         $source_version_at_translation Source version.
	 * @param int         $current_content_version       Current content version.
	 * @param string      $translatable_fingerprint      Fingerprint.
	 * @param string|null $updated_at                    Updated at.
	 * @return self
	 */
	public static function create(
		string $element_type,
		int $element_id,
		string $language_code,
		?int $group_id = null,
		int $source_version_at_translation = 1,
		int $current_content_version = 1,
		string $translatable_fingerprint = '',
		?string $updated_at = null
	): self {
		return new self(
			$element_type,
			$element_id,
			$language_code,
			$group_id,
			null,
			$source_version_at_translation,
			$current_content_version,
			$translatable_fingerprint,
			$updated_at
		);
	}

	/**
	 * Hydrates a TranslationElement instance from a database row.
	 *
	 * @param array<string, mixed>|object $row Raw database row.
	 * @return self
	 */
	public static function from_row( array|object $row ): self {
		$data = (array) $row;

		return new self(
			(string) ( $data['element_type'] ?? '' ),
			(int) ( $data['element_id'] ?? 0 ),
			(string) ( $data['language_code'] ?? '' ),
			isset( $data['group_id'] ) ? (int) $data['group_id'] : null,
			isset( $data['id'] ) ? (int) $data['id'] : null,
			isset( $data['source_version_at_translation'] ) ? (int) $data['source_version_at_translation'] : 1,
			isset( $data['current_content_version'] ) ? (int) $data['current_content_version'] : 1,
			(string) ( $data['translatable_fingerprint'] ?? '' ),
			isset( $data['updated_at'] ) && is_string( $data['updated_at'] ) ? $data['updated_at'] : null
		);
	}

	/**
	 * Gets row ID.
	 *
	 * @return int|null
	 */
	public function get_id(): ?int {
		return $this->id;
	}

	/**
	 * Gets translation group ID.
	 *
	 * @return int|null
	 */
	public function get_group_id(): ?int {
		return $this->group_id;
	}

	/**
	 * Gets element type ('post' or 'term').
	 *
	 * @return string
	 */
	public function get_element_type(): string {
		return $this->element_type;
	}

	/**
	 * Gets WordPress object ID.
	 *
	 * @return int
	 */
	public function get_element_id(): int {
		return $this->element_id;
	}

	/**
	 * Gets canonical language code.
	 *
	 * @return string
	 */
	public function get_language_code(): string {
		return $this->language_code;
	}

	/**
	 * Gets source version at translation.
	 *
	 * @return int
	 */
	public function get_source_version_at_translation(): int {
		return $this->source_version_at_translation;
	}

	/**
	 * Gets current content version.
	 *
	 * @return int
	 */
	public function get_current_content_version(): int {
		return $this->current_content_version;
	}

	/**
	 * Gets translatable fingerprint.
	 *
	 * @return string
	 */
	public function get_translatable_fingerprint(): string {
		return $this->translatable_fingerprint;
	}

	/**
	 * Gets updated at timestamp.
	 *
	 * @return string|null
	 */
	public function get_updated_at(): ?string {
		return $this->updated_at;
	}

	/**
	 * Returns clone with assigned group ID.
	 *
	 * @param int $group_id Group ID.
	 * @return self
	 */
	public function with_group_id( int $group_id ): self {
		return new self(
			$this->element_type,
			$this->element_id,
			$this->language_code,
			$group_id,
			$this->id,
			$this->source_version_at_translation,
			$this->current_content_version,
			$this->translatable_fingerprint,
			$this->updated_at
		);
	}

	/**
	 * Returns clone with assigned database ID.
	 *
	 * @param int $id Primary key ID.
	 * @return self
	 */
	public function with_id( int $id ): self {
		return new self(
			$this->element_type,
			$this->element_id,
			$this->language_code,
			$this->group_id,
			$id,
			$this->source_version_at_translation,
			$this->current_content_version,
			$this->translatable_fingerprint,
			$this->updated_at
		);
	}

	/**
	 * Returns clone with updated versions and fingerprint.
	 *
	 * @param int    $source_version Source version.
	 * @param int    $content_version Content version.
	 * @param string $fingerprint    Fingerprint.
	 * @param string $updated_at     Updated at timestamp.
	 * @return self
	 */
	public function with_versions(
		int $source_version,
		int $content_version,
		string $fingerprint = '',
		?string $updated_at = null
	): self {
		return new self(
			$this->element_type,
			$this->element_id,
			$this->language_code,
			$this->group_id,
			$this->id,
			$source_version,
			$content_version,
			$fingerprint,
			null !== $updated_at ? $updated_at : $this->updated_at
		);
	}

	/**
	 * Returns clone with updated timestamp.
	 *
	 * @param string $updated_at Updated at timestamp.
	 * @return self
	 */
	public function with_updated_at( string $updated_at ): self {
		return new self(
			$this->element_type,
			$this->element_id,
			$this->language_code,
			$this->group_id,
			$this->id,
			$this->source_version_at_translation,
			$this->current_content_version,
			$this->translatable_fingerprint,
			$updated_at
		);
	}

	/**
	 * Serializes to array for persistence or debugging.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                            => $this->id,
			'group_id'                      => $this->group_id,
			'element_type'                  => $this->element_type,
			'element_id'                    => $this->element_id,
			'language_code'                 => $this->language_code,
			'source_version_at_translation' => $this->source_version_at_translation,
			'current_content_version'       => $this->current_content_version,
			'translatable_fingerprint'      => $this->translatable_fingerprint,
			'updated_at'                    => $this->updated_at,
		);
	}
}
