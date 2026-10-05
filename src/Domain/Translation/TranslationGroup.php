<?php
/**
 * Translation Group Domain Entity.
 *
 * @package TF\Multilingual\Domain\Translation
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Translation;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException;
use TF\Multilingual\Domain\Translation\Exceptions\TranslationConflictException;

/**
 * Class TranslationGroup
 *
 * Represents an overarching cluster of multilingual translations for a single logical entity.
 */
final class TranslationGroup {

	/**
	 * Primary key in tfml_groups table.
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * Element type ('post' or 'term').
	 *
	 * @var string
	 */
	private string $element_type;

	/**
	 * Subtype (post_type for posts, taxonomy for terms).
	 *
	 * @var string
	 */
	private string $subtype;

	/**
	 * ID of the sovereign canonical element (null or matching an element_id in group).
	 *
	 * @var int|null
	 */
	private ?int $canonical_element_id;

	/**
	 * Creation timestamp (UTC).
	 *
	 * @var string|null
	 */
	private ?string $created_at;

	/**
	 * Associated translation elements indexed by canonical language code.
	 *
	 * @var array<string, TranslationElement>
	 */
	private array $elements = array();

	/**
	 * Constructor.
	 *
	 * @param string      $element_type          Element type ('post' or 'term').
	 * @param string      $subtype               Subtype (post_type or taxonomy).
	 * @param int|null    $canonical_element_id  Canonical element ID.
	 * @param int|null    $id                    Group ID.
	 * @param string|null $created_at            Created at timestamp.
	 *
	 * @throws InvalidTranslationElementException If element_type or subtype is invalid.
	 */
	public function __construct(
		string $element_type,
		string $subtype,
		?int $canonical_element_id = null,
		?int $id = null,
		?string $created_at = null
	) {
		$normalized_type = strtolower( trim( $element_type ) );
		if ( ! in_array( $normalized_type, array( 'post', 'term' ), true ) ) {
			throw InvalidTranslationElementException::for_unsupported_type( $element_type );
		}

		$trimmed_subtype = trim( $subtype );
		if ( '' === $trimmed_subtype ) {
			throw new InvalidTranslationElementException( 'Subtype cannot be empty.' );
		}

		$this->element_type         = $normalized_type;
		$this->subtype              = $trimmed_subtype;
		$this->canonical_element_id = $canonical_element_id;
		$this->id                   = $id;
		$this->created_at           = $created_at;
	}

	/**
	 * Named factory constructor.
	 *
	 * @param string   $element_type         Element type ('post' or 'term').
	 * @param string   $subtype              Subtype (post_type or taxonomy).
	 * @param int|null $canonical_element_id Canonical element ID.
	 * @param int|null $id                   Group ID.
	 * @param string|null $created_at        Created at timestamp.
	 * @return self
	 */
	public static function create(
		string $element_type,
		string $subtype,
		?int $canonical_element_id = null,
		?int $id = null,
		?string $created_at = null
	): self {
		return new self( $element_type, $subtype, $canonical_element_id, $id, $created_at );
	}

	/**
	 * Hydrates a TranslationGroup instance from a database row.
	 *
	 * @param array<string, mixed>|object $row Raw database row.
	 * @return self
	 */
	public static function from_row( array|object $row ): self {
		$data = (array) $row;

		return new self(
			(string) ( $data['element_type'] ?? '' ),
			(string) ( $data['subtype'] ?? '' ),
			isset( $data['canonical_element_id'] ) && null !== $data['canonical_element_id']
				? (int) $data['canonical_element_id']
				: null,
			isset( $data['id'] ) ? (int) $data['id'] : null,
			isset( $data['created_at'] ) && is_string( $data['created_at'] ) ? $data['created_at'] : null
		);
	}

	/**
	 * Gets group primary key ID.
	 *
	 * @return int|null
	 */
	public function get_id(): ?int {
		return $this->id;
	}

	/**
	 * Gets element type.
	 *
	 * @return string
	 */
	public function get_element_type(): string {
		return $this->element_type;
	}

	/**
	 * Gets subtype.
	 *
	 * @return string
	 */
	public function get_subtype(): string {
		return $this->subtype;
	}

	/**
	 * Gets canonical element ID.
	 *
	 * @return int|null
	 */
	public function get_canonical_element_id(): ?int {
		return $this->canonical_element_id;
	}

	/**
	 * Gets creation timestamp (UTC).
	 *
	 * @return string|null
	 */
	public function get_created_at(): ?string {
		return $this->created_at;
	}

	/**
	 * Gets all attached translation elements indexed by canonical language code.
	 *
	 * @return array<string, TranslationElement>
	 */
	public function get_elements(): array {
		return $this->elements;
	}

	/**
	 * Checks whether this group contains no translation elements.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return empty( $this->elements );
	}

	/**
	 * Checks whether a translation exists for a specific language code.
	 *
	 * @param string $language_code Language code.
	 * @return bool
	 */
	public function has_translation( string $language_code ): bool {
		$canonical = Language::normalize_code( $language_code );

		return isset( $this->elements[ $canonical ] );
	}

	/**
	 * Retrieves the translation element for a specific language code.
	 *
	 * Returns null if untranslated (SIN TRADUCIR). Never returns fallback or placeholder.
	 *
	 * @param string $language_code Language code.
	 * @return TranslationElement|null
	 */
	public function get_translation( string $language_code ): ?TranslationElement {
		$canonical = Language::normalize_code( $language_code );

		return $this->elements[ $canonical ] ?? null;
	}

	/**
	 * Retrieves the canonical TranslationElement instance if defined.
	 *
	 * @return TranslationElement|null
	 */
	public function get_canonical_element(): ?TranslationElement {
		if ( null === $this->canonical_element_id ) {
			return null;
		}

		foreach ( $this->elements as $element ) {
			if ( $element->get_element_id() === $this->canonical_element_id ) {
				return $element;
			}
		}

		return null;
	}

	/**
	 * Gets list of all element IDs in the group.
	 *
	 * @return array<int>
	 */
	public function get_element_ids(): array {
		$ids = array();
		foreach ( $this->elements as $element ) {
			$ids[] = $element->get_element_id();
		}

		return $ids;
	}

	/**
	 * Adds a translation element to the group while enforcing domain invariants.
	 *
	 * @param TranslationElement $element      Translation element.
	 * @param bool               $as_canonical Whether to designate as canonical element.
	 * @return void
	 * @throws InvalidTranslationElementException If element type differs from group.
	 * @throws TranslationConflictException If language is already present.
	 */
	public function add_element( TranslationElement $element, bool $as_canonical = false ): void {
		if ( $element->get_element_type() !== $this->element_type ) {
			throw new InvalidTranslationElementException(
				sprintf(
					'Cannot add element of type "%s" to a group of type "%s".',
					$element->get_element_type(),
					$this->element_type
				)
			);
		}

		$lang = $element->get_language_code();
		if ( isset( $this->elements[ $lang ] ) ) {
			throw TranslationConflictException::for_duplicate_language(
				$this->id ?? 0,
				$lang
			);
		}

		// Attach group ID if known.
		if ( null !== $this->id && $element->get_group_id() !== $this->id ) {
			$element = $element->with_group_id( $this->id );
		}

		$this->elements[ $lang ] = $element;

		if ( $as_canonical ) {
			$this->canonical_element_id = $element->get_element_id();
		} elseif ( null === $this->canonical_element_id && 1 === count( $this->elements ) ) {
			// If no canonical element was set and this is the first element, set it.
			$this->canonical_element_id = $element->get_element_id();
		}
	}

	/**
	 * Removes a translation element by language code while enforcing canonical protection.
	 *
	 * @param string   $language_code           Language code to remove.
	 * @param int|null $new_canonical_element_id Required if removing canonical and others remain.
	 * @return TranslationElement|null The removed element, or null if not found.
	 * @throws InvalidTranslationElementException If new canonical is invalid or missing.
	 */
	public function remove_element(
		string $language_code,
		?int $new_canonical_element_id = null
	): ?TranslationElement {
		$canonical = Language::normalize_code( $language_code );
		if ( ! isset( $this->elements[ $canonical ] ) ) {
			return null;
		}

		$target = $this->elements[ $canonical ];

		$is_canonical = ( $this->canonical_element_id === $target->get_element_id() );

		// Remove from memory.
		unset( $this->elements[ $canonical ] );

		if ( $is_canonical ) {
			if ( empty( $this->elements ) ) {
				$this->canonical_element_id = null;
			} elseif ( null !== $new_canonical_element_id ) {
				$this->set_canonical_element_id( $new_canonical_element_id );
			} else {
				// Re-insert to keep state consistent before throwing.
				$this->elements[ $canonical ] = $target;
				throw new InvalidTranslationElementException(
					'Cannot remove the canonical element without explicitly designating a new canonical element for the remaining translations.'
				);
			}
		}

		return $target;
	}

	/**
	 * Sets canonical element explicitly.
	 *
	 * @param int $element_id WordPress object ID of an element in this group.
	 * @return void
	 * @throws InvalidTranslationElementException If element does not belong to this group.
	 */
	public function set_canonical_element_id( int $element_id ): void {
		$found = false;
		foreach ( $this->elements as $element ) {
			if ( $element->get_element_id() === $element_id ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			throw InvalidTranslationElementException::for_invalid_canonical( $element_id );
		}

		$this->canonical_element_id = $element_id;
	}

	/**
	 * Returns clone with assigned database ID.
	 *
	 * @param int $id Database ID.
	 * @return self
	 */
	public function with_id( int $id ): self {
		$clone = new self(
			$this->element_type,
			$this->subtype,
			$this->canonical_element_id,
			$id,
			$this->created_at
		);

		foreach ( $this->elements as $lang => $elem ) {
			$clone->elements[ $lang ] = $elem->with_group_id( $id );
		}

		return $clone;
	}
}
