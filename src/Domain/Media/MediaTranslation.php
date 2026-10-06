<?php
/**
 * Media Translation Entity / Value Object.
 *
 * @package TF\Multilingual\Domain\Media
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Media;

use InvalidArgumentException;
use TF\Multilingual\Domain\Language\Language;

/**
 * Class MediaTranslation
 *
 * Represents localized editorial metadata for a WordPress media attachment in a specific language.
 * Adheres strictly to the single-attachment model: multiple languages share one physical attachment.
 */
class MediaTranslation {

	/**
	 * Database record ID (null if not persisted).
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * WordPress attachment post ID.
	 *
	 * @var int
	 */
	private int $attachment_id;

	/**
	 * Canonical language code.
	 *
	 * @var string
	 */
	private string $language_code;

	/**
	 * Alternative text (alt attribute).
	 *
	 * @var string
	 */
	private string $alt_text;

	/**
	 * Localized media title.
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * Localized media caption (excerpt).
	 *
	 * @var string
	 */
	private string $caption;

	/**
	 * Localized media description (content).
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * Last update timestamp.
	 *
	 * @var string|null
	 */
	private ?string $updated_at;

	/**
	 * Flag indicating whether this instance represents a native Core fallback.
	 *
	 * @var bool
	 */
	private bool $is_fallback;

	/**
	 * Constructor.
	 *
	 * @param int         $attachment_id Attachment post ID.
	 * @param string      $language_code Canonical language code.
	 * @param string      $alt_text      Alternative text.
	 * @param string      $title         Title.
	 * @param string      $caption       Caption.
	 * @param string      $description   Description.
	 * @param int|null    $id            Database ID.
	 * @param string|null $updated_at    Update timestamp.
	 * @param bool        $is_fallback   Core fallback flag.
	 * @throws InvalidArgumentException If attachment ID or language code is invalid.
	 */
	public function __construct(
		int $attachment_id,
		string $language_code,
		string $alt_text = '',
		string $title = '',
		string $caption = '',
		string $description = '',
		?int $id = null,
		?string $updated_at = null,
		bool $is_fallback = false
	) {
		if ( $attachment_id <= 0 ) {
			throw new InvalidArgumentException( 'Attachment ID must be a positive integer.' );
		}

		$normalized_lang = Language::normalize_code( $language_code );
		if ( '' === $normalized_lang ) {
			throw new InvalidArgumentException( 'Language code cannot be empty.' );
		}

		$this->attachment_id = $attachment_id;
		$this->language_code = $normalized_lang;
		$this->alt_text      = $alt_text;
		$this->title         = $title;
		$this->caption       = $caption;
		$this->description   = $description;
		$this->id            = $id;
		$this->updated_at    = $updated_at;
		$this->is_fallback   = $is_fallback;
	}

	/**
	 * Factory method for a new or persisted TFML media translation.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string      $language_code Language code.
	 * @param string      $alt_text      Alt text.
	 * @param string      $title         Title.
	 * @param string      $caption       Caption.
	 * @param string      $description   Description.
	 * @param int|null    $id            Database ID.
	 * @param string|null $updated_at    Update timestamp.
	 * @return self
	 */
	public static function create(
		int $attachment_id,
		string $language_code,
		string $alt_text = '',
		string $title = '',
		string $caption = '',
		string $description = '',
		?int $id = null,
		?string $updated_at = null
	): self {
		return new self(
			$attachment_id,
			$language_code,
			$alt_text,
			$title,
			$caption,
			$description,
			$id,
			$updated_at,
			false
		);
	}

	/**
	 * Factory method for a Core fallback representation when no TFML variant exists.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language_code Requested language code.
	 * @param string $alt_text      Native Core alt.
	 * @param string $title         Native Core title.
	 * @param string $caption       Native Core caption.
	 * @param string $description   Native Core description.
	 * @return self
	 */
	public static function create_fallback(
		int $attachment_id,
		string $language_code,
		string $alt_text = '',
		string $title = '',
		string $caption = '',
		string $description = ''
	): self {
		return new self(
			$attachment_id,
			$language_code,
			$alt_text,
			$title,
			$caption,
			$description,
			null,
			null,
			true
		);
	}

	/**
	 * Creates an instance from a database row array.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['attachment_id'] ?? 0 ),
			(string) ( $row['language_code'] ?? '' ),
			(string) ( $row['alt_text'] ?? '' ),
			(string) ( $row['title'] ?? '' ),
			(string) ( $row['caption'] ?? '' ),
			(string) ( $row['description'] ?? '' ),
			isset( $row['id'] ) ? (int) $row['id'] : null,
			isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null,
			false
		);
	}

	/**
	 * Gets database record ID.
	 *
	 * @return int|null
	 */
	public function get_id(): ?int {
		return $this->id;
	}

	/**
	 * Gets attachment post ID.
	 *
	 * @return int
	 */
	public function get_attachment_id(): int {
		return $this->attachment_id;
	}

	/**
	 * Gets language code.
	 *
	 * @return string
	 */
	public function get_language_code(): string {
		return $this->language_code;
	}

	/**
	 * Gets alternative text.
	 *
	 * @return string
	 */
	public function get_alt_text(): string {
		return $this->alt_text;
	}

	/**
	 * Gets title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * Gets caption.
	 *
	 * @return string
	 */
	public function get_caption(): string {
		return $this->caption;
	}

	/**
	 * Gets description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return $this->description;
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
	 * Returns true if this translation is a Core fallback.
	 *
	 * @return bool
	 */
	public function is_fallback(): bool {
		return $this->is_fallback;
	}

	/**
	 * Returns array representation suitable for database insertion or serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'attachment_id' => $this->attachment_id,
			'language_code' => $this->language_code,
			'alt_text'      => $this->alt_text,
			'title'         => $this->title,
			'caption'       => $this->caption,
			'description'   => $this->description,
			'updated_at'    => $this->updated_at,
		);
	}
}
