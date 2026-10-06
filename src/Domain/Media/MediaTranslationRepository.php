<?php
/**
 * Media Translation Repository.
 *
 * @package TF\Multilingual\Domain\Media
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Media;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use wpdb;

/**
 * Class MediaTranslationRepository
 *
 * Manages physical persistence of multilingual media metadata in tfml_media_translations.
 * Isolates all database operations and provides efficient batch loading to prevent N+1 queries.
 */
class MediaTranslationRepository {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Sovereign language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Table name with prefix.
	 *
	 * @var string
	 */
	private string $table_name;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null             $db                Database instance.
	 * @param LanguageRegistry|null $language_registry Language registry.
	 */
	public function __construct(
		?wpdb $db = null,
		?LanguageRegistry $language_registry = null
	) {
		global $wpdb;

		$this->db                = null !== $db ? $db : $wpdb;
		$this->language_registry = null !== $language_registry ? $language_registry : new LanguageRegistry();
		$this->table_name        = $this->db->prefix . 'tfml_media_translations';
	}

	/**
	 * Retrieves the full table name.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		return $this->table_name;
	}

	/**
	 * Finds a specific media translation by attachment ID and language code.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $language_code Canonical language code.
	 * @return MediaTranslation|null The translation entity or null if not found.
	 */
	public function find( int $attachment_id, string $language_code ): ?MediaTranslation {
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$normalized_lang = Language::normalize_code( $language_code );
		if ( '' === $normalized_lang ) {
			return null;
		}

		$query = $this->db->prepare(
			"SELECT * FROM {$this->table_name} WHERE attachment_id = %d AND language_code = %s LIMIT 1",
			$attachment_id,
			$normalized_lang
		);

		$row = $this->db->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) || empty( $row ) ) {
			return null;
		}

		return MediaTranslation::from_row( $row );
	}

	/**
	 * Retrieves all saved translations for a single attachment, keyed by language code.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string, MediaTranslation> Map of language_code => MediaTranslation.
	 */
	public function find_by_attachment( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return array();
		}

		$query = $this->db->prepare(
			"SELECT * FROM {$this->table_name} WHERE attachment_id = %d ORDER BY language_code ASC",
			$attachment_id
		);

		$rows = $this->db->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$translations = array();
		foreach ( $rows as $row ) {
			$entity                                       = MediaTranslation::from_row( $row );
			$translations[ $entity->get_language_code() ] = $entity;
		}

		return $translations;
	}

	/**
	 * Batch retrieves translations for multiple attachments in a single database query (Zero N+1).
	 *
	 * @param array<int> $attachment_ids Array of attachment IDs.
	 * @return array<int, array<string, MediaTranslation>> Nested map of [attachment_id => [lang => MediaTranslation]].
	 */
	public function find_by_attachments( array $attachment_ids ): array {
		$valid_ids = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ), fn( $id ) => $id > 0 ) ) );
		if ( empty( $valid_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $valid_ids ), '%d' ) );
		$query        = $this->db->prepare(
			"SELECT * FROM {$this->table_name} WHERE attachment_id IN ($placeholders) ORDER BY attachment_id ASC, language_code ASC",
			...$valid_ids
		);

		$rows = $this->db->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array();
		foreach ( $valid_ids as $id ) {
			$result[ $id ] = array();
		}

		foreach ( $rows as $row ) {
			$entity                     = MediaTranslation::from_row( $row );
			$att_id                     = $entity->get_attachment_id();
			$lang                       = $entity->get_language_code();
			$result[ $att_id ][ $lang ] = $entity;
		}

		return $result;
	}

	/**
	 * Checks if a translation exists for an attachment and language.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language_code Language code.
	 * @return bool True if record exists.
	 */
	public function exists( int $attachment_id, string $language_code ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$normalized_lang = Language::normalize_code( $language_code );
		if ( '' === $normalized_lang ) {
			return false;
		}

		$query = $this->db->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE attachment_id = %d AND language_code = %s",
			$attachment_id,
			$normalized_lang
		);

		return ( (int) $this->db->get_var( $query ) ) > 0;
	}

	/**
	 * Saves (inserts or updates) a media translation record.
	 *
	 * Uses atomic REPLACE / INSERT ON DUPLICATE KEY UPDATE semantics on uq_attachment_language.
	 *
	 * @param MediaTranslation $translation Translation entity to save.
	 * @return MediaTranslation The saved entity with refreshed ID and timestamp.
	 */
	public function save( MediaTranslation $translation ): MediaTranslation {
		$now  = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'attachment_id' => $translation->get_attachment_id(),
			'language_code' => $translation->get_language_code(),
			'alt_text'      => $translation->get_alt_text(),
			'title'         => $translation->get_title(),
			'caption'       => $translation->get_caption(),
			'description'   => $translation->get_description(),
			'updated_at'    => $now,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

		// Check if record already exists to determine insert vs update
		$existing = $this->find( $translation->get_attachment_id(), $translation->get_language_code() );
		if ( null !== $existing && null !== $existing->get_id() ) {
			$this->db->update(
				$this->table_name,
				$data,
				array( 'id' => $existing->get_id() ),
				$formats,
				array( '%d' )
			);
			$record_id = $existing->get_id();
		} else {
			$this->db->insert(
				$this->table_name,
				$data,
				$formats
			);
			$record_id = (int) $this->db->insert_id;
		}

		return MediaTranslation::create(
			$translation->get_attachment_id(),
			$translation->get_language_code(),
			$translation->get_alt_text(),
			$translation->get_title(),
			$translation->get_caption(),
			$translation->get_description(),
			$record_id > 0 ? $record_id : null,
			$now
		);
	}

	/**
	 * Deletes a specific media translation by attachment ID and language code.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language_code Language code.
	 * @return bool True if a row was deleted.
	 */
	public function delete( int $attachment_id, string $language_code ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$normalized_lang = Language::normalize_code( $language_code );
		if ( '' === $normalized_lang ) {
			return false;
		}

		$affected = $this->db->delete(
			$this->table_name,
			array(
				'attachment_id' => $attachment_id,
				'language_code' => $normalized_lang,
			),
			array( '%d', '%s' )
		);

		return false !== $affected && $affected > 0;
	}

	/**
	 * Deletes all translations associated with a given attachment ID.
	 *
	 * Called during permanent deletion of an attachment in WordPress Core.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Number of deleted translation rows.
	 */
	public function delete_all_for_attachment( int $attachment_id ): int {
		if ( $attachment_id <= 0 ) {
			return 0;
		}

		$affected = $this->db->delete(
			$this->table_name,
			array( 'attachment_id' => $attachment_id ),
			array( '%d' )
		);

		return false !== $affected ? (int) $affected : 0;
	}
}
