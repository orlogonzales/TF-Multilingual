<?php
/**
 * Translation Group Repository.
 *
 * @package TF\Multilingual\Domain\Translation
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Translation;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException;
use TF\Multilingual\Domain\Translation\Exceptions\TranslationConflictException;
use TF\Multilingual\Domain\Translation\Exceptions\TranslationDomainException;
use TF\Multilingual\Domain\Translation\Exceptions\TranslationGroupNotFoundException;
use wpdb;

/**
 * Class TranslationGroupRepository
 *
 * Sovereign repository for persisting and querying Translation Groups and Translation Elements.
 */
class TranslationGroupRepository {

	/**
	 * WordPress database abstraction.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Language registry domain service.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Element validator service.
	 *
	 * @var WordPressElementValidator
	 */
	private WordPressElementValidator $element_validator;

	/**
	 * Table name for translation groups.
	 *
	 * @var string
	 */
	private string $table_groups;

	/**
	 * Table name for translation group elements.
	 *
	 * @var string
	 */
	private string $table_group_elements;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null                      $db                 WordPress database object.
	 * @param LanguageRegistry|null          $language_registry Language registry.
	 * @param WordPressElementValidator|null $element_validator Element validator.
	 */
	public function __construct(
		?wpdb $db = null,
		?LanguageRegistry $language_registry = null,
		?WordPressElementValidator $element_validator = null
	) {
		global $wpdb;

		$this->db                   = null !== $db ? $db : $wpdb;
		$this->language_registry    = null !== $language_registry ? $language_registry : new LanguageRegistry();
		$this->element_validator    = null !== $element_validator ? $element_validator : new WordPressElementValidator();
		$this->table_groups         = $this->db->prefix . 'tfml_groups';
		$this->table_group_elements = $this->db->prefix . 'tfml_group_elements';
	}

	/**
	 * Creates and persists a translation group and its initial element atomically.
	 *
	 * @param string      $element_type          Element type ('post' or 'term').
	 * @param string      $subtype               Subtype (post_type or taxonomy).
	 * @param int|null    $initial_element_id    Optional initial element ID to attach.
	 * @param string|null $initial_language_code Optional language code for initial element.
	 * @param bool        $as_canonical          Whether initial element is canonical.
	 * @return TranslationGroup The created group.
	 * @throws InvalidTranslationElementException If element or language is invalid.
	 * @throws TranslationConflictException If element is already assigned to a group.
	 * @throws TranslationDomainException If database insertion fails.
	 */
	public function create_group(
		string $element_type,
		string $subtype,
		?int $initial_element_id = null,
		?string $initial_language_code = null,
		bool $as_canonical = true
	): TranslationGroup {
		$group = TranslationGroup::create( $element_type, $subtype );

		if ( null !== $initial_element_id && null !== $initial_language_code ) {
			$canonical_lang = Language::normalize_code( $initial_language_code );
			$this->validate_language_is_active( $canonical_lang );
			$this->element_validator->validate( $element_type, $subtype, $initial_element_id );
			$this->assert_element_not_in_any_group( $element_type, $initial_element_id );

			$element = TranslationElement::create(
				$element_type,
				$initial_element_id,
				$canonical_lang
			);
			$group->add_element( $element, $as_canonical );
		}

		return $this->create( $group );
	}

	/**
	 * Persists a new TranslationGroup into the database.
	 *
	 * Compound persistence of group and elements is wrapped in an InnoDB transaction.
	 *
	 * @param TranslationGroup $group Domain translation group.
	 * @return TranslationGroup Persisted translation group with database IDs.
	 * @throws TranslationConflictException If unique constraints are violated.
	 * @throws TranslationDomainException If insertion fails.
	 */
	public function create( TranslationGroup $group ): TranslationGroup {
		$this->begin_transaction();

		try {
			$created_at = $this->get_current_utc_timestamp();

			$result = $this->db->insert(
				$this->table_groups,
				array(
					'element_type'         => $group->get_element_type(),
					'subtype'              => $group->get_subtype(),
					'canonical_element_id' => $group->get_canonical_element_id(),
					'created_at'           => $created_at,
				),
				array( '%s', '%s', '%d', '%s' )
			);

			if ( false === $result ) {
				$this->handle_db_error( 'Failed to insert translation group.' );
			}

			$group_id        = (int) $this->db->insert_id;
			$persisted_group = $group->with_id( $group_id );

			// Persist any pre-attached elements.
			foreach ( $group->get_elements() as $element ) {
				$element_with_group = $element->with_group_id( $group_id );
				$inserted_element   = $this->insert_element_row( $element_with_group );
				// Update in group clone.
				$persisted_group->remove_element( $element->get_language_code(), $element->get_element_id() );
				$persisted_group->add_element(
					$inserted_element,
					$inserted_element->get_element_id() === $persisted_group->get_canonical_element_id()
				);
			}

			$this->commit_transaction();

			return $persisted_group;
		} catch ( TranslationDomainException $e ) {
			$this->rollback_transaction();
			throw $e;
		}
	}

	/**
	 * Finds a TranslationGroup by its primary ID.
	 *
	 * @param int $group_id Group ID.
	 * @return TranslationGroup|null The group entity or null if not found.
	 */
	public function find( int $group_id ): ?TranslationGroup {
		if ( $group_id <= 0 ) {
			return null;
		}

		$query_group = $this->db->prepare(
			"SELECT * FROM `{$this->table_groups}` WHERE `id` = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$group_id
		);

		$group_row = $this->db->get_row( $query_group, ARRAY_A );
		if ( ! is_array( $group_row ) ) {
			return null;
		}

		$group = TranslationGroup::from_row( $group_row );

		$query_elements = $this->db->prepare(
			"SELECT * FROM `{$this->table_group_elements}` WHERE `group_id` = %d ORDER BY `id` ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$group_id
		);

		$element_rows = $this->db->get_results( $query_elements, ARRAY_A );
		if ( is_array( $element_rows ) ) {
			foreach ( $element_rows as $row ) {
				$element = TranslationElement::from_row( $row );
				// Re-attach element without triggering canonical auto-assignment.
				$is_canonical = ( $element->get_element_id() === $group->get_canonical_element_id() );
				$group->add_element( $element, $is_canonical );
			}
		}

		return $group;
	}

	/**
	 * Finds a TranslationGroup by ID or throws TranslationGroupNotFoundException.
	 *
	 * @param int $group_id Group ID.
	 * @return TranslationGroup
	 * @throws TranslationGroupNotFoundException If group does not exist.
	 */
	public function find_or_fail( int $group_id ): TranslationGroup {
		$group = $this->find( $group_id );
		if ( null === $group ) {
			throw TranslationGroupNotFoundException::for_id( $group_id );
		}

		return $group;
	}

	/**
	 * Finds a TranslationGroup containing a given WordPress element.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @return TranslationGroup|null The translation group or null if unassigned.
	 */
	public function find_by_element( string $element_type, int $element_id ): ?TranslationGroup {
		$query = $this->db->prepare(
			"SELECT `group_id` FROM `{$this->table_group_elements}` WHERE `element_type` = %s AND `element_id` = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$element_type,
			$element_id
		);

		$group_id = $this->db->get_var( $query );
		if ( null === $group_id || false === $group_id ) {
			return null;
		}

		return $this->find( (int) $group_id );
	}

	/**
	 * Finds TranslationGroups for multiple WordPress elements in batch.
	 *
	 * Guarantees O(1) query complexity (at most 3 SQL queries) regardless of element count,
	 * completely eliminating N+1 query patterns in admin list tables.
	 *
	 * @param string     $element_type Element type ('post' or 'term').
	 * @param array<int> $element_ids  Array of WordPress object IDs.
	 * @return array<int, TranslationGroup> Map of element_id => TranslationGroup. Unassigned elements are omitted.
	 */
	public function find_by_elements( string $element_type, array $element_ids ): array {
		$valid_ids = array_values( array_unique( array_filter( array_map( 'intval', $element_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $valid_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $valid_ids ), '%d' ) );
		$query        = $this->db->prepare(
			"SELECT `element_id`, `group_id` FROM `{$this->table_group_elements}` WHERE `element_type` = %s AND `element_id` IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			array_merge( array( $element_type ), $valid_ids )
		);

		$pairs = $this->db->get_results( $query, ARRAY_A );
		if ( empty( $pairs ) || ! is_array( $pairs ) ) {
			return array();
		}

		$group_ids = array_values( array_unique( array_map( 'intval', array_column( $pairs, 'group_id' ) ) ) );
		if ( empty( $group_ids ) ) {
			return array();
		}

		$group_placeholders = implode( ', ', array_fill( 0, count( $group_ids ), '%d' ) );
		$groups_query       = $this->db->prepare(
			"SELECT * FROM `{$this->table_groups}` WHERE `id` IN ($group_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$group_ids
		);

		$group_rows = $this->db->get_results( $groups_query, ARRAY_A );
		if ( empty( $group_rows ) || ! is_array( $group_rows ) ) {
			return array();
		}

		$groups_by_id = array();
		foreach ( $group_rows as $row ) {
			$group                            = TranslationGroup::from_row( $row );
			$groups_by_id[ (int) $row['id'] ] = $group;
		}

		$elements_query = $this->db->prepare(
			"SELECT * FROM `{$this->table_group_elements}` WHERE `group_id` IN ($group_placeholders) ORDER BY `id` ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$group_ids
		);

		$element_rows = $this->db->get_results( $elements_query, ARRAY_A );
		if ( is_array( $element_rows ) ) {
			foreach ( $element_rows as $row ) {
				$element = TranslationElement::from_row( $row );
				$g_id    = (int) $row['group_id'];
				if ( isset( $groups_by_id[ $g_id ] ) ) {
					$is_canonical = ( $element->get_element_id() === $groups_by_id[ $g_id ]->get_canonical_element_id() );
					$groups_by_id[ $g_id ]->add_element( $element, $is_canonical );
				}
			}
		}

		$result = array();
		foreach ( $pairs as $pair ) {
			$el_id = (int) $pair['element_id'];
			$g_id  = (int) $pair['group_id'];
			if ( isset( $groups_by_id[ $g_id ] ) ) {
				$result[ $el_id ] = $groups_by_id[ $g_id ];
			}
		}

		return $result;
	}

	/**
	 * Adds a translation element to an existing translation group.
	 *
	 * @param int    $group_id      Group ID.
	 * @param int    $element_id    WordPress object ID.
	 * @param string $language_code Canonical language code.
	 * @param bool   $is_canonical  Whether to set as canonical element.
	 * @return TranslationElement The persisted element.
	 * @throws TranslationGroupNotFoundException If group does not exist.
	 * @throws InvalidTranslationElementException If element or language is invalid.
	 * @throws TranslationConflictException If language or element already assigned.
	 */
	public function add_translation(
		int $group_id,
		int $element_id,
		string $language_code,
		bool $is_canonical = false
	): TranslationElement {
		$group          = $this->find_or_fail( $group_id );
		$canonical_lang = Language::normalize_code( $language_code );

		// 1. Language registry validation.
		$this->validate_language_is_active( $canonical_lang );

		// 2. Homogeneity & existence validation.
		$this->element_validator->validate(
			$group->get_element_type(),
			$group->get_subtype(),
			$element_id
		);

		// 3. Domain invariant: element must not be in any other group.
		$this->assert_element_not_in_any_group( $group->get_element_type(), $element_id );

		// 4. Domain invariant: group must not already have a translation for this language.
		if ( $group->has_translation( $canonical_lang ) ) {
			throw TranslationConflictException::for_duplicate_language( $group_id, $canonical_lang );
		}

		$this->begin_transaction();

		try {
			$element = TranslationElement::create(
				$group->get_element_type(),
				$element_id,
				$canonical_lang,
				$group_id
			);

			$persisted_element = $this->insert_element_row( $element );

			if ( $is_canonical || null === $group->get_canonical_element_id() ) {
				$this->db->update(
					$this->table_groups,
					array( 'canonical_element_id' => $element_id ),
					array( 'id' => $group_id ),
					array( '%d' ),
					array( '%d' )
				);
			}

			$this->commit_transaction();

			return $persisted_element;
		} catch ( TranslationDomainException $e ) {
			$this->rollback_transaction();
			throw $e;
		}
	}

	/**
	 * Retrieves an individual translation element from a group by language code.
	 *
	 * Returns null if untranslated (SIN TRADUCIR). Never returns placeholder or canonical fallback.
	 *
	 * @param int    $group_id      Group ID.
	 * @param string $language_code Language code.
	 * @return TranslationElement|null The translation element or null if untranslated.
	 */
	public function get_translation( int $group_id, string $language_code ): ?TranslationElement {
		$canonical = Language::normalize_code( $language_code );

		$query = $this->db->prepare(
			"SELECT * FROM `{$this->table_group_elements}` WHERE `group_id` = %d AND `language_code` = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$group_id,
			$canonical
		);

		$row = $this->db->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		return TranslationElement::from_row( $row );
	}

	/**
	 * Removes a translation relation from a group.
	 *
	 * Removing a translation removes ONLY the relation row from tfml_group_elements.
	 * Core WordPress objects (posts and terms) are 100% preserved.
	 *
	 * If the removed element was canonical:
	 * - If other elements remain, $new_canonical_element_id is strictly required.
	 * - If no other elements remain, the empty group is automatically purged.
	 *
	 * @param int      $group_id                 Group ID.
	 * @param string   $language_code            Language code to remove.
	 * @param int|null $new_canonical_element_id Required if removing canonical and others remain.
	 * @return bool True on success, false if translation was not present.
	 * @throws TranslationGroupNotFoundException If group does not exist.
	 * @throws InvalidTranslationElementException If new canonical is missing or invalid.
	 */
	public function remove_translation(
		int $group_id,
		string $language_code,
		?int $new_canonical_element_id = null
	): bool {
		$group          = $this->find_or_fail( $group_id );
		$canonical_lang = Language::normalize_code( $language_code );

		$target_element = $group->get_translation( $canonical_lang );
		if ( null === $target_element ) {
			return false;
		}

		$is_canonical    = ( $group->get_canonical_element_id() === $target_element->get_element_id() );
		$remaining_count = count( $group->get_elements() ) - 1;

		if ( $is_canonical && $remaining_count > 0 ) {
			if ( null === $new_canonical_element_id ) {
				throw new InvalidTranslationElementException(
					'Cannot remove the canonical element without explicitly designating a new canonical element for the remaining translations.'
				);
			}

			// Validate that proposed new canonical belongs to group and is not the one being deleted.
			if ( $new_canonical_element_id === $target_element->get_element_id() ) {
				throw new InvalidTranslationElementException(
					'The new canonical element cannot be the element currently being removed.'
				);
			}

			$valid_replacement = false;
			foreach ( $group->get_elements() as $elem ) {
				if ( $elem->get_element_id() === $new_canonical_element_id ) {
					$valid_replacement = true;
					break;
				}
			}

			if ( ! $valid_replacement ) {
				throw InvalidTranslationElementException::for_invalid_canonical( $new_canonical_element_id );
			}
		}

		$this->begin_transaction();

		try {
			// 1. Delete relation row.
			$this->db->delete(
				$this->table_group_elements,
				array(
					'group_id'      => $group_id,
					'language_code' => $canonical_lang,
				),
				array( '%d', '%s' )
			);

			// 2. Handle group lifecycle.
			if ( 0 === $remaining_count ) {
				// Empty group policy: purge orphaned group row automatically.
				$this->db->delete(
					$this->table_groups,
					array( 'id' => $group_id ),
					array( '%d' )
				);
			} elseif ( $is_canonical && null !== $new_canonical_element_id ) {
				// Update canonical element pointer.
				$this->db->update(
					$this->table_groups,
					array( 'canonical_element_id' => $new_canonical_element_id ),
					array( 'id' => $group_id ),
					array( '%d' ),
					array( '%d' )
				);
			}

			$this->commit_transaction();

			return true;
		} catch ( TranslationDomainException $e ) {
			$this->rollback_transaction();
			throw $e;
		}
	}

	/**
	 * Sets the canonical element ID for a group explicitly.
	 *
	 * @param int $group_id   Group ID.
	 * @param int $element_id WordPress object ID.
	 * @return bool True on success.
	 * @throws TranslationGroupNotFoundException If group does not exist.
	 * @throws InvalidTranslationElementException If element does not belong to group.
	 */
	public function set_canonical( int $group_id, int $element_id ): bool {
		$group = $this->find_or_fail( $group_id );

		// Throws InvalidTranslationElementException if element does not belong to group.
		$group->set_canonical_element_id( $element_id );

		$updated = $this->db->update(
			$this->table_groups,
			array( 'canonical_element_id' => $element_id ),
			array( 'id' => $group_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Deletes a TranslationGroup and all its element relations.
	 *
	 * Core WordPress objects (posts and terms) are 100% preserved.
	 *
	 * @param int $group_id Group ID.
	 * @return bool True on success.
	 * @throws TranslationGroupNotFoundException If group does not exist.
	 */
	public function delete( int $group_id ): bool {
		$group = $this->find_or_fail( $group_id );

		$this->begin_transaction();

		try {
			// Delete all member relations.
			$this->db->delete(
				$this->table_group_elements,
				array( 'group_id' => $group_id ),
				array( '%d' )
			);

			// Delete group header.
			$this->db->delete(
				$this->table_groups,
				array( 'id' => $group_id ),
				array( '%d' )
			);

			$this->commit_transaction();

			return true;
		} catch ( TranslationDomainException $e ) {
			$this->rollback_transaction();
			throw $e;
		}
	}

	/**
	 * Validates that an element is not already registered in another translation group.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @return void
	 * @throws TranslationConflictException If element belongs to another group.
	 */
	protected function assert_element_not_in_any_group( string $element_type, int $element_id ): void {
		$query = $this->db->prepare(
			"SELECT `group_id` FROM `{$this->table_group_elements}` WHERE `element_type` = %s AND `element_id` = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$element_type,
			$element_id
		);

		$existing_group_id = $this->db->get_var( $query );
		if ( null !== $existing_group_id && false !== $existing_group_id ) {
			throw TranslationConflictException::for_element_already_assigned(
				$element_type,
				$element_id,
				(int) $existing_group_id
			);
		}
	}

	/**
	 * Validates that a language is registered and active in the LanguageRegistry.
	 *
	 * @param string $language_code Language code.
	 * @return void
	 * @throws InvalidTranslationElementException If language is inactive or not found.
	 */
	protected function validate_language_is_active( string $language_code ): void {
		if ( ! $this->language_registry->is_active( $language_code ) ) {
			throw InvalidTranslationElementException::for_inactive_language( $language_code );
		}
	}

	/**
	 * Inserts a single element row into tfml_group_elements.
	 *
	 * @param TranslationElement $element Translation element.
	 * @return TranslationElement Persisted element with database ID.
	 * @throws TranslationConflictException If unique constraint is violated.
	 * @throws TranslationDomainException If insertion fails.
	 */
	protected function insert_element_row( TranslationElement $element ): TranslationElement {
		$updated_at = $element->get_updated_at() ?? $this->get_current_utc_timestamp();

		$result = $this->db->insert(
			$this->table_group_elements,
			array(
				'group_id'                      => $element->get_group_id(),
				'element_type'                  => $element->get_element_type(),
				'element_id'                    => $element->get_element_id(),
				'language_code'                 => $element->get_language_code(),
				'source_version_at_translation' => $element->get_source_version_at_translation(),
				'current_content_version'       => $element->get_current_content_version(),
				'translatable_fingerprint'      => $element->get_translatable_fingerprint(),
				'updated_at'                    => $updated_at,
			),
			array( '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			$this->handle_db_error(
				sprintf(
					'Failed to insert translation element "%s" (%d) into group %d.',
					$element->get_element_type(),
					$element->get_element_id(),
					$element->get_group_id() ?? 0
				)
			);
		}

		$element_id = (int) $this->db->insert_id;

		return $element->with_id( $element_id )->with_updated_at( $updated_at );
	}

	/**
	 * Translates MySQL database errors into controlled domain exceptions.
	 *
	 * @param string $fallback_message Fallback error message.
	 * @return never
	 * @throws TranslationConflictException If error is a duplicate key constraint collision.
	 * @throws TranslationDomainException If error is a general database error.
	 */
	protected function handle_db_error( string $fallback_message ): never {
		$error = $this->db->last_error;

		if ( is_string( $error ) && ( str_contains( $error, 'Duplicate entry' ) || str_contains( $error, '1062' ) ) ) {
			throw TranslationConflictException::for_concurrent_collision( $error );
		}

		throw new TranslationDomainException(
			! empty( $error ) ? sprintf( '%s DB Error: %s', $fallback_message, $error ) : $fallback_message
		);
	}

	/**
	 * Gets current timestamp in UTC (Y-m-d H:i:s).
	 *
	 * Decision: Database timestamps are stored in UTC for normalization across site timezones.
	 *
	 * @return string
	 */
	protected function get_current_utc_timestamp(): string {
		if ( function_exists( 'current_time' ) ) {
			return (string) current_time( 'mysql', true );
		}

		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Starts a database transaction if supported.
	 *
	 * @return void
	 */
	protected function begin_transaction(): void {
		$this->db->query( 'START TRANSACTION' );
	}

	/**
	 * Commits an active database transaction.
	 *
	 * @return void
	 */
	protected function commit_transaction(): void {
		$this->db->query( 'COMMIT' );
	}

	/**
	 * Rolls back an active database transaction.
	 *
	 * @return void
	 */
	protected function rollback_transaction(): void {
		$this->db->query( 'ROLLBACK' );
	}
}
