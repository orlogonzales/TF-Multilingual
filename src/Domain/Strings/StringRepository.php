<?php
/**
 * String Repository.
 *
 * @package TF\Multilingual\Domain\String
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Strings;

use TF\Multilingual\Domain\Strings\Exceptions\InvalidStringException;
use TF\Multilingual\Domain\Strings\Exceptions\StringNotFoundException;
use wpdb;

/**
 * Class StringRepository
 *
 * Sovereign repository managing persistence, registration, versioning and preloading
 * of translatable strings across tfml_strings and tfml_string_translations tables.
 */
class StringRepository {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Table name for strings.
	 *
	 * @var string
	 */
	private string $table_strings;

	/**
	 * Table name for string translations.
	 *
	 * @var string
	 */
	private string $table_translations;

	/**
	 * In-memory cache for translatable strings keyed by "$domain:$string_key".
	 *
	 * @var array<string, TranslatableString>
	 */
	private array $strings_cache = array();

	/**
	 * In-memory cache for translatable strings keyed by ID.
	 *
	 * @var array<int, TranslatableString>
	 */
	private array $strings_by_id_cache = array();

	/**
	 * In-memory preloaded translations map: [domain][string_key][language_code] => StringTranslation.
	 *
	 * @var array<string, array<string, array<string, StringTranslation>>>
	 */
	private array $domain_preloaded_translations = array();

	/**
	 * Set of domain names that have been completely preloaded in this request.
	 *
	 * @var array<string, bool>
	 */
	private array $preloaded_domains = array();

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $db Optional database instance.
	 */
	public function __construct( ?wpdb $db = null ) {
		if ( null !== $db ) {
			$this->db = $db;
		} else {
			global $wpdb;
			$this->db = $wpdb;
		}

		$this->table_strings      = $this->db->prefix . 'tfml_strings';
		$this->table_translations = $this->db->prefix . 'tfml_string_translations';
	}

	/**
	 * Registers an interface string with stable semantic identity (domain + key).
	 *
	 * - Idempotent if same domain, key, and source text.
	 * - Increments string_version: N -> N+1 if source text changes, and demotes
	 *   existing translations to 'needs_review'.
	 * - Flags has_conflict = 1 if conflicting context is registered for the same key.
	 *
	 * @param string $domain          String domain.
	 * @param string $key             Stable semantic key.
	 * @param string $original_value  Original source text.
	 * @param string $context         Optional context.
	 * @param string $source_language Source language code.
	 * @return TranslatableString
	 * @throws InvalidStringException If key or domain are invalid.
	 */
	public function register(
		string $domain,
		string $key,
		string $original_value,
		string $context = '',
		string $source_language = 'es'
	): TranslatableString {
		$domain          = trim( $domain );
		$key             = trim( $key );
		$context         = trim( $context );
		$source_language = '' !== trim( $source_language ) ? trim( $source_language ) : 'es';

		if ( '' === $domain ) {
			throw new InvalidStringException( 'String domain cannot be empty.' );
		}

		if ( '' === $key ) {
			throw new InvalidStringException( 'String key cannot be empty.' );
		}

		$now      = current_time( 'mysql', 1 );
		$existing = $this->find_by_domain_and_key( $domain, $key );

		if ( null === $existing ) {
			// Atomic insert.
			$inserted = $this->db->insert(
				$this->table_strings,
				array(
					'domain'          => $domain,
					'string_key'      => $key,
					'context'         => $context,
					'original_value'  => $original_value,
					'source_language' => $source_language,
					'string_version'  => 1,
					'has_conflict'    => 0,
					'last_seen_at'    => $now,
					'created_at'      => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);

			if ( false === $inserted ) {
				// Concurrency race: another process inserted it concurrently.
				$existing = $this->find_by_domain_and_key( $domain, $key, false );
				if ( null === $existing ) {
					throw new InvalidStringException(
						sprintf( 'Failed to register string "%s" in domain "%s": %s', $key, $domain, $this->db->last_error )
					);
				}
			} else {
				$new_id = (int) $this->db->insert_id;
				$entity = new TranslatableString(
					$new_id,
					$domain,
					$key,
					$context,
					$original_value,
					$source_language,
					1,
					false,
					$now,
					$now
				);
				$this->cache_string( $entity );
				return $entity;
			}
		}

		// Existing string found. Check if source text changed.
		if ( $existing->get_original_value() !== $original_value ) {
			$new_version  = $existing->get_string_version() + 1;
			$has_conflict = $existing->has_conflict();

			if ( '' !== $context && '' !== $existing->get_context() && $context !== $existing->get_context() ) {
				$has_conflict = true;
			}

			$this->db->update(
				$this->table_strings,
				array(
					'original_value'  => $original_value,
					'string_version'  => $new_version,
					'context'         => '' !== $context ? $context : $existing->get_context(),
					'has_conflict'    => $has_conflict ? 1 : 0,
					'last_seen_at'    => $now,
					'source_language' => $source_language,
				),
				array( 'id' => $existing->get_id() ),
				array( '%s', '%d', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);

			// Demote all existing translations for this string to 'needs_review'.
			$this->db->query(
				$this->db->prepare(
					"UPDATE {$this->table_translations} SET status = %s WHERE string_id = %d AND source_version_translated < %d",
					StringStatus::DB_NEEDS_REVIEW,
					$existing->get_id(),
					$new_version
				)
			);

			$updated_entity = new TranslatableString(
				$existing->get_id(),
				$domain,
				$key,
				'' !== $context ? $context : $existing->get_context(),
				$original_value,
				$source_language,
				$new_version,
				$has_conflict,
				$now,
				$existing->get_created_at()
			);

			$this->cache_string( $updated_entity );
			$this->invalidate_domain_preload( $domain );

			return $updated_entity;
		}

		// Source text is unchanged: check context conflict.
		$has_conflict = $existing->has_conflict();
		if ( '' !== $context && '' !== $existing->get_context() && $context !== $existing->get_context() ) {
			if ( ! $has_conflict ) {
				$has_conflict = true;
				$this->db->update(
					$this->table_strings,
					array( 'has_conflict' => 1 ),
					array( 'id' => $existing->get_id() ),
					array( '%d' ),
					array( '%d' )
				);

				$existing = new TranslatableString(
					$existing->get_id(),
					$existing->get_domain(),
					$existing->get_string_key(),
					$existing->get_context(),
					$existing->get_original_value(),
					$existing->get_source_language(),
					$existing->get_string_version(),
					true,
					$existing->get_last_seen_at(),
					$existing->get_created_at()
				);
				$this->cache_string( $existing );
			}
		}

		return $existing;
	}

	/**
	 * Finds a translatable string by domain and key.
	 *
	 * @param string $domain    String domain.
	 * @param string $key       Semantic key.
	 * @param bool   $use_cache Whether to use memory cache.
	 * @return TranslatableString|null
	 */
	public function find_by_domain_and_key( string $domain, string $key, bool $use_cache = true ): ?TranslatableString {
		$cache_key = $domain . ':' . $key;
		if ( $use_cache && isset( $this->strings_cache[ $cache_key ] ) ) {
			return $this->strings_cache[ $cache_key ];
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_strings} WHERE domain = %s AND string_key = %s LIMIT 1",
				$domain,
				$key
			)
		);

		if ( ! $row ) {
			return null;
		}

		$entity = $this->map_row_to_string( $row );
		$this->cache_string( $entity );

		return $entity;
	}

	/**
	 * Finds a translatable string by ID.
	 *
	 * @param int  $id        String ID.
	 * @param bool $use_cache Whether to use memory cache.
	 * @return TranslatableString|null
	 */
	public function find_by_id( int $id, bool $use_cache = true ): ?TranslatableString {
		if ( $use_cache && isset( $this->strings_by_id_cache[ $id ] ) ) {
			return $this->strings_by_id_cache[ $id ];
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_strings} WHERE id = %d LIMIT 1",
				$id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$entity = $this->map_row_to_string( $row );
		$this->cache_string( $entity );

		return $entity;
	}

	/**
	 * Saves or updates a translation for a string.
	 *
	 * @param int         $string_id        Referenced string ID.
	 * @param string      $language_code    Target language code.
	 * @param string|null $translated_value Translated value.
	 * @param string|null $status           Optional status ('up_to_date', 'needs_review' or domain status).
	 * @return StringTranslation
	 * @throws StringNotFoundException If string does not exist.
	 */
	public function save_translation(
		int $string_id,
		string $language_code,
		?string $translated_value,
		?string $status = null
	): StringTranslation {
		$string = $this->find_by_id( $string_id );
		if ( null === $string ) {
			throw StringNotFoundException::for_id( $string_id );
		}

		$language_code = trim( $language_code );
		$now           = current_time( 'mysql', 1 );
		$source_ver    = $string->get_string_version();

		$db_status = StringStatus::DB_UP_TO_DATE;
		if ( null !== $status ) {
			$db_status = StringStatus::is_valid_db_status( $status )
				? $status
				: StringStatus::to_db_status( $status );
		}

		// Atomic upsert into tfml_string_translations.
		$sql = "INSERT INTO {$this->table_translations}
			(string_id, language_code, translated_value, source_version_translated, status, updated_at)
			VALUES (%d, %s, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
			translated_value = VALUES(translated_value),
			source_version_translated = VALUES(source_version_translated),
			status = VALUES(status),
			updated_at = VALUES(updated_at)";

		$this->db->query(
			$this->db->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$string_id,
				$language_code,
				$translated_value,
				$source_ver,
				$db_status,
				$now
			)
		);

		// Reload translation record.
		$translation = $this->get_translation( $string_id, $language_code, false );
		if ( null === $translation ) {
			throw new InvalidStringException(
				sprintf( 'Failed to retrieve saved translation for string ID %d and language "%s".', $string_id, $language_code )
			);
		}

		// Update in-memory domain cache if preloaded.
		$domain = $string->get_domain();
		$key    = $string->get_string_key();
		if ( isset( $this->domain_preloaded_translations[ $domain ][ $key ] ) ) {
			$this->domain_preloaded_translations[ $domain ][ $key ][ $language_code ] = $translation;
		}

		return $translation;
	}

	/**
	 * Gets a translation for a string and language.
	 *
	 * @param int    $string_id     String ID.
	 * @param string $language_code Language code.
	 * @param bool   $use_cache     Whether to check preloaded memory cache first.
	 * @return StringTranslation|null
	 */
	public function get_translation( int $string_id, string $language_code, bool $use_cache = true ): ?StringTranslation {
		$language_code = trim( $language_code );

		if ( $use_cache ) {
			$string = $this->find_by_id( $string_id );
			if ( null !== $string ) {
				$domain = $string->get_domain();
				$key    = $string->get_string_key();
				if ( isset( $this->domain_preloaded_translations[ $domain ][ $key ][ $language_code ] ) ) {
					return $this->domain_preloaded_translations[ $domain ][ $key ][ $language_code ];
				}
			}
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table_translations} WHERE string_id = %d AND language_code = %s LIMIT 1",
				$string_id,
				$language_code
			)
		);

		if ( ! $row ) {
			return null;
		}

		return $this->map_row_to_translation( $row );
	}

	/**
	 * Gets all translations for a given string.
	 *
	 * @param int $string_id String ID.
	 * @return array<string, StringTranslation> Keyed by language_code.
	 */
	public function get_translations( int $string_id ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_translations} WHERE string_id = %d",
				$string_id
			)
		);

		$translations = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$translations[ $row->language_code ] = $this->map_row_to_translation( $row );
			}
		}

		return $translations;
	}

	/**
	 * Preloads all strings and translations for a domain into in-memory cache.
	 *
	 * Executes in strictly O(1) database queries (1 query for strings, 1 query for translations)
	 * ensuring Zero N+1 queries during frontend rendering.
	 *
	 * @param string      $domain        String domain.
	 * @param string|null $language_code Optional language filter.
	 * @return void
	 */
	public function preload_domain( string $domain, ?string $language_code = null ): void {
		$domain = trim( $domain );
		if ( '' === $domain ) {
			return;
		}

		if ( isset( $this->preloaded_domains[ $domain ] ) && null === $language_code ) {
			return; // Domain already loaded.
		}

		// Query 1: Fetch all strings in this domain.
		$string_rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table_strings} WHERE domain = %s",
				$domain
			)
		);

		if ( empty( $string_rows ) ) {
			$this->preloaded_domains[ $domain ] = true;
			return;
		}

		$string_ids = array();
		foreach ( $string_rows as $row ) {
			$entity = $this->map_row_to_string( $row );
			$this->cache_string( $entity );
			$string_ids[] = (int) $row->id;
		}

		if ( ! isset( $this->domain_preloaded_translations[ $domain ] ) ) {
			$this->domain_preloaded_translations[ $domain ] = array();
		}

		// Query 2: Fetch all translations for these strings.
		$sql = "SELECT t.*, s.string_key, s.domain 
			FROM {$this->table_translations} t 
			INNER JOIN {$this->table_strings} s ON t.string_id = s.id 
			WHERE s.domain = %s";

		$params = array( $domain );

		if ( null !== $language_code && '' !== trim( $language_code ) ) {
			$sql     .= ' AND t.language_code = %s';
			$params[] = trim( $language_code );
		}

		$translation_rows = $this->db->get_results(
			$this->db->prepare( $sql, ...$params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( ! empty( $translation_rows ) ) {
			foreach ( $translation_rows as $t_row ) {
				$trans_entity = $this->map_row_to_translation( $t_row );
				$s_key        = (string) $t_row->string_key;
				$lang         = (string) $t_row->language_code;

				if ( ! isset( $this->domain_preloaded_translations[ $domain ][ $s_key ] ) ) {
					$this->domain_preloaded_translations[ $domain ][ $s_key ] = array();
				}
				$this->domain_preloaded_translations[ $domain ][ $s_key ][ $lang ] = $trans_entity;
			}
		}

		$this->preloaded_domains[ $domain ] = true;
	}

	/**
	 * Retrieves a preloaded translation directly from in-memory cache.
	 *
	 * @param string $domain        Domain name.
	 * @param string $key           String key.
	 * @param string $language_code Language code.
	 * @return StringTranslation|null
	 */
	public function get_preloaded_translation( string $domain, string $key, string $language_code ): ?StringTranslation {
		return $this->domain_preloaded_translations[ $domain ][ $key ][ $language_code ] ?? null;
	}

	/**
	 * Checks if a domain has been preloaded in memory.
	 *
	 * @param string $domain Domain name.
	 * @return bool
	 */
	public function is_domain_preloaded( string $domain ): bool {
		return ! empty( $this->preloaded_domains[ $domain ] );
	}

	/**
	 * Finds all translatable strings matching criteria with pagination.
	 *
	 * @param array<string, mixed> $args Query arguments (domain, context, search, limit, offset, orderby, order).
	 * @return array<int, TranslatableString>
	 */
	public function find_all( array $args = array() ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['domain'] ) ) {
			$where[]  = 'domain = %s';
			$params[] = trim( (string) $args['domain'] );
		}

		if ( isset( $args['context'] ) && '' !== $args['context'] ) {
			$where[]  = 'context = %s';
			$params[] = trim( (string) $args['context'] );
		}

		if ( isset( $args['has_conflict'] ) ) {
			$where[]  = 'has_conflict = %d';
			$params[] = $args['has_conflict'] ? 1 : 0;
		}

		if ( ! empty( $args['search'] ) ) {
			$search_like = '%' . $this->db->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[]     = '(string_key LIKE %s OR original_value LIKE %s)';
			$params[]    = $search_like;
			$params[]    = $search_like;
		}

		$where_clause = implode( ' AND ', $where );

		$orderby = 'id';
		if ( ! empty( $args['orderby'] ) && in_array( $args['orderby'], array( 'id', 'domain', 'string_key', 'created_at', 'last_seen_at' ), true ) ) {
			$orderby = $args['orderby'];
		}

		$order = 'DESC';
		if ( ! empty( $args['order'] ) && in_array( strtoupper( (string) $args['order'] ), array( 'ASC', 'DESC' ), true ) ) {
			$order = strtoupper( (string) $args['order'] );
		}

		$limit_clause = '';
		if ( ! empty( $args['limit'] ) ) {
			$limit        = max( 1, (int) $args['limit'] );
			$offset       = max( 0, (int) ( $args['offset'] ?? 0 ) );
			$limit_clause = sprintf( 'LIMIT %d OFFSET %d', $limit, $offset );
		}

		$sql = "SELECT * FROM {$this->table_strings} WHERE {$where_clause} ORDER BY {$orderby} {$order} {$limit_clause}";

		$rows = ! empty( $params )
			? $this->db->get_results( $this->db->prepare( $sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $this->db->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$results = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$results[] = $this->map_row_to_string( $row );
			}
		}

		return $results;
	}

	/**
	 * Counts all translatable strings matching criteria.
	 *
	 * @param array<string, mixed> $args Query arguments (domain, context, search).
	 * @return int
	 */
	public function count_all( array $args = array() ): int {
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['domain'] ) ) {
			$where[]  = 'domain = %s';
			$params[] = trim( (string) $args['domain'] );
		}

		if ( isset( $args['context'] ) && '' !== $args['context'] ) {
			$where[]  = 'context = %s';
			$params[] = trim( (string) $args['context'] );
		}

		if ( isset( $args['has_conflict'] ) ) {
			$where[]  = 'has_conflict = %d';
			$params[] = $args['has_conflict'] ? 1 : 0;
		}

		if ( ! empty( $args['search'] ) ) {
			$search_like = '%' . $this->db->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[]     = '(string_key LIKE %s OR original_value LIKE %s)';
			$params[]    = $search_like;
			$params[]    = $search_like;
		}

		$where_clause = implode( ' AND ', $where );
		$sql          = "SELECT COUNT(*) FROM {$this->table_strings} WHERE {$where_clause}";

		return ! empty( $params )
			? (int) $this->db->get_var( $this->db->prepare( $sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Deletes a translatable string and all its translations.
	 *
	 * @param int $string_id String ID.
	 * @return bool
	 */
	public function delete_string( int $string_id ): bool {
		$string = $this->find_by_id( $string_id );
		if ( null === $string ) {
			return false;
		}

		// Delete translations.
		$this->db->delete(
			$this->table_translations,
			array( 'string_id' => $string_id ),
			array( '%d' )
		);

		// Delete string.
		$this->db->delete(
			$this->table_strings,
			array( 'id' => $string_id ),
			array( '%d' )
		);

		$cache_key = $string->get_domain() . ':' . $string->get_string_key();
		unset( $this->strings_cache[ $cache_key ], $this->strings_by_id_cache[ $string_id ] );
		$this->invalidate_domain_preload( $string->get_domain() );

		return true;
	}

	/**
	 * Updates the last_seen_at timestamp for a batch of string IDs.
	 *
	 * @param array<int> $string_ids Array of string IDs.
	 * @return void
	 */
	public function touch_last_seen( array $string_ids ): void {
		$clean_ids = array_filter( array_map( 'intval', $string_ids ) );
		if ( empty( $clean_ids ) ) {
			return;
		}

		$now          = current_time( 'mysql', 1 );
		$placeholders = implode( ',', array_fill( 0, count( $clean_ids ), '%d' ) );

		$this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table_strings} SET last_seen_at = %s WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				...$clean_ids
			)
		);
	}

	/**
	 * Clears in-memory caches.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->strings_cache                 = array();
		$this->strings_by_id_cache           = array();
		$this->domain_preloaded_translations = array();
		$this->preloaded_domains             = array();
	}

	/**
	 * Caches a string entity in memory.
	 *
	 * @param TranslatableString $translatable_string Entity to cache.
	 * @return void
	 */
	private function cache_string( TranslatableString $translatable_string ): void {
		$this->strings_cache[ $translatable_string->get_domain() . ':' . $translatable_string->get_string_key() ] = $translatable_string;
		$this->strings_by_id_cache[ $translatable_string->get_id() ] = $translatable_string;
	}

	/**
	 * Invalidates preloaded translations for a domain.
	 *
	 * @param string $domain Domain name.
	 * @return void
	 */
	private function invalidate_domain_preload( string $domain ): void {
		unset( $this->domain_preloaded_translations[ $domain ], $this->preloaded_domains[ $domain ] );
	}

	/**
	 * Maps a raw database row to a TranslatableString entity.
	 *
	 * @param object $row Database row.
	 * @return TranslatableString
	 */
	private function map_row_to_string( object $row ): TranslatableString {
		return new TranslatableString(
			(int) $row->id,
			(string) $row->domain,
			(string) $row->string_key,
			(string) ( $row->context ?? '' ),
			(string) $row->original_value,
			(string) ( $row->source_language ?? 'es' ),
			(int) ( $row->string_version ?? 1 ),
			! empty( $row->has_conflict ),
			(string) ( $row->last_seen_at ?? '' ),
			(string) ( $row->created_at ?? '' )
		);
	}

	/**
	 * Maps a raw database row to a StringTranslation entity.
	 *
	 * @param object $row Database row.
	 * @return StringTranslation
	 */
	private function map_row_to_translation( object $row ): StringTranslation {
		return new StringTranslation(
			(int) $row->id,
			(int) $row->string_id,
			(string) $row->language_code,
			isset( $row->translated_value ) ? (string) $row->translated_value : null,
			(int) ( $row->source_version_translated ?? 1 ),
			(string) ( $row->status ?? StringStatus::DB_UP_TO_DATE ),
			(string) ( $row->updated_at ?? '' )
		);
	}
}
