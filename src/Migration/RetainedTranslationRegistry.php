<?php
/**
 * Retained Translation Registry.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use InvalidArgumentException;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use Throwable;
use wpdb;

/**
 * Class RetainedTranslationRegistry
 *
 * Manages the transactional persistence, tracking, verification, and future promotion
 * of retained translations belonging to inactive languages from historical migration
 * sources (e.g. WPML).
 *
 * Guarantees zero active pollution of TFML core tables (tfml_group_elements) while
 * sharing the same InnoDB database connection ($wpdb) for atomic commit/rollback
 * alongside TFML groups and elements, verifying persisted state after every write,
 * and preventing stale reads from WP_Object_Cache upon transaction rollback.
 */
class RetainedTranslationRegistry {

	/**
	 * Option key for retained translations registry.
	 */
	public const OPTION_KEY = 'tfml_retained_translations';

	/**
	 * Canonical schema version.
	 */
	public const SCHEMA_VERSION = '1.0.0';

	/**
	 * Status constant for retained translation awaiting language activation.
	 */
	public const STATUS_RETAINED_INACTIVE_LANGUAGE = 'retained_inactive_language';

	/**
	 * Status constant for promoted translation now active in TFML.
	 */
	public const STATUS_PROMOTED = 'promoted';

	/**
	 * Shared WordPress database instance.
	 *
	 * @var wpdb|null
	 */
	private ?wpdb $db;

	/**
	 * Fault injection flag for testing write failures.
	 *
	 * @var bool
	 */
	private bool $simulate_write_failure = false;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $db Optional shared database connection.
	 */
	public function __construct( ?wpdb $db = null ) {
		if ( null !== $db ) {
			$this->db = $db;
		} elseif ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ) {
			$this->db = $GLOBALS['wpdb'];
		} else {
			$this->db = null;
		}
	}

	/**
	 * Enables or disables simulated write failures for transactional fault testing.
	 *
	 * @param bool $simulate Whether to simulate write failure.
	 * @return void
	 */
	public function set_simulate_write_failure( bool $simulate ): void {
		$this->simulate_write_failure = $simulate;
	}

	/**
	 * Registers a set of retained translations for a partially imported group.
	 *
	 * @param string             $source                Migration source identifier ('wpml', 'polylang').
	 * @param int                $trid                  Source translation group / TRID.
	 * @param int                $group_id              TFML group ID assigned to the active subset.
	 * @param string             $element_type          Element type ('post', 'term').
	 * @param string             $subtype               Subtype (post_type or taxonomy).
	 * @param array<string, int> $retained_translations Map of lang_code => element_id.
	 * @param string             $plan_fingerprint      Cryptographic fingerprint of the originating plan.
	 * @return array<string, mixed> Persisted record array.
	 * @throws InvalidArgumentException If data is empty or conflicts with existing records.
	 * @throws MigrationExecutionException If persistence or state verification fails.
	 */
	public function register(
		string $source,
		int $trid,
		int $group_id,
		string $element_type,
		string $subtype,
		array $retained_translations,
		string $plan_fingerprint = ''
	): array {
		if ( '' === trim( $source ) ) {
			throw new InvalidArgumentException( 'El origen de migración no puede estar vacío.' );
		}

		if ( $trid <= 0 ) {
			throw new InvalidArgumentException( 'El identificador TRID debe ser un entero positivo.' );
		}

		if ( $group_id <= 0 ) {
			throw new InvalidArgumentException( 'El group_id de TFML debe ser un entero positivo.' );
		}

		if ( empty( $retained_translations ) ) {
			throw new InvalidArgumentException( 'La lista de traducciones retenidas no puede estar vacía.' );
		}

		$normalized_retained = array();
		foreach ( $retained_translations as $lang => $elem_id ) {
			$norm_lang = Language::normalize_code( (string) $lang );
			Language::validate_code( $norm_lang );
			$eid = (int) $elem_id;
			if ( $eid <= 0 ) {
				throw new InvalidArgumentException( 'El ID del elemento retenido debe ser un entero positivo.' );
			}
			$normalized_retained[ $norm_lang ] = $eid;
		}
		ksort( $normalized_retained );

		$current_records = $this->load_all();
		$record_key      = $this->build_record_key( $source, $trid );

		// Check uniqueness of elements: no retained element can be duplicated in another group.
		foreach ( $normalized_retained as $lang => $elem_id ) {
			if ( $this->has_element_in_records( $current_records, $element_type, $elem_id, $record_key ) ) {
				throw new InvalidArgumentException(
					sprintf(
						'Conflicto de unicidad: El elemento %s ID %d ya se encuentra registrado como retenido en otro grupo.',
						$element_type,
						$elem_id
					)
				);
			}
		}

		// Idempotency and referential consistency check if record already exists for this TRID.
		if ( isset( $current_records[ $record_key ] ) && is_array( $current_records[ $record_key ] ) ) {
			$existing = $current_records[ $record_key ];
			if ( (int) ( $existing['group_id'] ?? 0 ) !== $group_id ) {
				throw new InvalidArgumentException(
					sprintf(
						'Conflicto de integridad referencial: El TRID %d ya está vinculado al grupo TFML ID %d (se intentó vincular al ID %d).',
						$trid,
						(int) ( $existing['group_id'] ?? 0 ),
						$group_id
					)
				);
			}

			if ( $this->is_semantically_identical( $existing, $source, $trid, $group_id, $element_type, $subtype, $normalized_retained, $plan_fingerprint ) ) {
				$this->persist_and_verify( $current_records, $trid );
				return $existing;
			}
		}

		$now               = gmdate( 'Y-m-d H:i:s' );
		$retained_elements = array();

		foreach ( $normalized_retained as $lang => $eid ) {
			$retained_elements[ $lang ] = array(
				'element_id'   => $eid,
				'element_type' => $element_type,
				'subtype'      => $subtype,
				'status'       => self::STATUS_RETAINED_INACTIVE_LANGUAGE,
				'source_trid'  => $trid,
				'recorded_at'  => $now,
			);
		}

		$record_payload = array(
			'schema_version'    => self::SCHEMA_VERSION,
			'source'            => strtolower( trim( $source ) ),
			'trid'              => $trid,
			'group_id'          => $group_id,
			'element_type'      => $element_type,
			'subtype'           => $subtype,
			'retained_elements' => $retained_elements,
			'plan_fingerprint'  => $plan_fingerprint,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$fingerprint                   = $this->compute_record_fingerprint( $record_payload );
		$record_payload['fingerprint'] = $fingerprint;

		$current_records[ $record_key ] = $record_payload;
		$this->persist_and_verify( $current_records, $trid );

		return $record_payload;
	}

	/**
	 * Verifies the cryptographic and structural integrity of a retained translation record.
	 *
	 * @param array<string, mixed> $record Record array to verify.
	 * @return bool True if structure and SHA-256 fingerprint are valid.
	 */
	public function verify_record_integrity( array $record ): bool {
		if (
			empty( $record['source'] ) ||
			empty( $record['trid'] ) ||
			empty( $record['group_id'] ) ||
			empty( $record['retained_elements'] ) ||
			empty( $record['fingerprint'] )
		) {
			return false;
		}

		$expected_fp = $this->compute_record_fingerprint( $record );

		return hash_equals( (string) $record['fingerprint'], $expected_fp );
	}

	/**
	 * Retrieves a retained group record by source and TRID.
	 *
	 * @param string $source Source identifier ('wpml').
	 * @param int    $trid   Source TRID.
	 * @return array<string, mixed>|null Record array or null if not found.
	 */
	public function get( string $source, int $trid ): ?array {
		$records    = $this->load_all();
		$record_key = $this->build_record_key( $source, $trid );

		return $records[ $record_key ] ?? null;
	}

	/**
	 * Retrieves a retained group record by TFML group ID.
	 *
	 * @param int $group_id TFML group ID.
	 * @return array<string, mixed>|null Record array or null if not found.
	 */
	public function get_by_group_id( int $group_id ): ?array {
		$records = $this->load_all();
		foreach ( $records as $rec ) {
			if ( (int) ( $rec['group_id'] ?? 0 ) === $group_id ) {
				return $rec;
			}
		}

		return null;
	}

	/**
	 * Retrieves all retained records, optionally filtered by source.
	 *
	 * @param string|null $source Optional source filter.
	 * @return array<string, array<string, mixed>> List of records.
	 */
	public function get_all( ?string $source = null ): array {
		$records = $this->load_all();
		if ( null === $source || '' === trim( $source ) ) {
			return $records;
		}

		return array_filter(
			$records,
			fn( array $r ) => ( $r['source'] ?? '' ) === $source
		);
	}

	/**
	 * Retrieves all retained translations waiting for a specific language code.
	 *
	 * @param string      $language_code Language code (e.g. 'fr').
	 * @param string|null $source        Optional source filter.
	 * @return array<int, array{
	 *     trid: int,
	 *     group_id: int,
	 *     source: string,
	 *     element_type: string,
	 *     subtype: string,
	 *     element_id: int,
	 *     status: string,
	 *     recorded_at: string
	 * }> List of pending items for the language.
	 */
	public function get_retained_for_language( string $language_code, ?string $source = null ): array {
		$lang_normalized = strtolower( trim( $language_code ) );
		$records         = $this->get_all( $source );
		$pending         = array();

		foreach ( $records as $rec ) {
			$retained_elements = (array) ( $rec['retained_elements'] ?? array() );
			if ( isset( $retained_elements[ $lang_normalized ] ) ) {
				$item      = (array) $retained_elements[ $lang_normalized ];
				$pending[] = array(
					'trid'         => (int) ( $rec['trid'] ?? 0 ),
					'group_id'     => (int) ( $rec['group_id'] ?? 0 ),
					'source'       => (string) ( $rec['source'] ?? 'wpml' ),
					'element_type' => (string) ( $rec['element_type'] ?? 'post' ),
					'subtype'      => (string) ( $rec['subtype'] ?? '' ),
					'element_id'   => (int) ( $item['element_id'] ?? 0 ),
					'status'       => (string) ( $item['status'] ?? self::STATUS_RETAINED_INACTIVE_LANGUAGE ),
					'recorded_at'  => (string) ( $item['recorded_at'] ?? '' ),
				);
			}
		}

		return $pending;
	}

	/**
	 * Checks if an element is currently recorded as retained.
	 *
	 * @param string $element_type Element type ('post', 'term').
	 * @param int    $element_id   Element ID.
	 * @return bool
	 */
	public function has_retained_element( string $element_type, int $element_id ): bool {
		$records = $this->load_all();

		return $this->has_element_in_records( $records, $element_type, $element_id );
	}

	/**
	 * Removes a retained record by source and TRID.
	 *
	 * @param string $source Source identifier ('wpml').
	 * @param int    $trid   Source TRID.
	 * @return bool True if record was found and removed.
	 * @throws MigrationExecutionException If persistence verification fails.
	 */
	public function remove( string $source, int $trid ): bool {
		$records    = $this->load_all();
		$record_key = $this->build_record_key( $source, $trid );

		if ( ! isset( $records[ $record_key ] ) ) {
			return false;
		}

		unset( $records[ $record_key ] );

		return $this->persist_and_verify( $records, $trid );
	}

	/**
	 * Removes all retained records associated with a given TFML group ID.
	 *
	 * Useful during rollback of imported groups.
	 *
	 * @param int $group_id TFML group ID.
	 * @return bool True if any record was removed.
	 * @throws MigrationExecutionException If persistence verification fails.
	 */
	public function remove_by_group_id( int $group_id ): bool {
		$records = $this->load_all();
		$changed = false;
		$trid    = 0;

		foreach ( $records as $key => $rec ) {
			if ( (int) ( $rec['group_id'] ?? 0 ) === $group_id ) {
				$trid = (int) ( $rec['trid'] ?? 0 );
				unset( $records[ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			return $this->persist_and_verify( $records, $trid );
		}

		if ( $this->simulate_write_failure ) {
			throw new MigrationExecutionException(
				sprintf( 'Fallo inducido de escritura al limpiar traducciones retenidas del grupo ID %d.', $group_id ),
				$trid
			);
		}

		return false;
	}

	/**
	 * Promotes a previously retained translation into an active translation within its TFML group.
	 *
	 * Executes atomically within a database transaction shared with TranslationGroupRepository:
	 * if updating the retained translation registry fails after inserting the active element,
	 * the entire operation is rolled back so no element can exist simultaneously as active and retained.
	 *
	 * @param int                        $group_id          TFML group ID.
	 * @param string                     $language_code     Language code to promote (must be active now).
	 * @param TranslationGroupRepository $group_repository  Translation group repository.
	 * @param LanguageRegistry           $language_registry Language registry.
	 * @param WordPressElementValidator  $element_validator Element validator.
	 * @return TranslationElement The newly active translation element.
	 * @throws InvalidArgumentException If promotion invariants fail.
	 * @throws MigrationExecutionException If transactional persistence fails.
	 */
	public function promote_retained_translation(
		int $group_id,
		string $language_code,
		TranslationGroupRepository $group_repository,
		LanguageRegistry $language_registry,
		WordPressElementValidator $element_validator
	): TranslationElement {
		$lang_normalized = Language::normalize_code( $language_code );
		Language::validate_code( $lang_normalized );

		$record = $this->get_by_group_id( $group_id );

		if ( null === $record ) {
			throw new InvalidArgumentException(
				sprintf( 'No existe registro de traducciones retenidas para el grupo TFML ID %d.', $group_id )
			);
		}

		$retained_elements = (array) ( $record['retained_elements'] ?? array() );
		if ( ! isset( $retained_elements[ $lang_normalized ] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'El grupo TFML ID %d no contiene una traducción retenida para el idioma "%s".',
					$group_id,
					$lang_normalized
				)
			);
		}

		// Invariant 1: Language MUST now be active in LanguageRegistry.
		if ( ! $language_registry->is_active( $lang_normalized ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'No se puede promover la traducción retenida: el idioma "%s" continúa inactivo en TFML.',
					$lang_normalized
				)
			);
		}

		$item         = (array) $retained_elements[ $lang_normalized ];
		$element_id   = (int) ( $item['element_id'] ?? 0 );
		$element_type = (string) ( $record['element_type'] ?? 'post' );
		$subtype      = (string) ( $record['subtype'] ?? '' );
		$trid         = (int) ( $record['trid'] ?? 0 );

		// Invariant 2: Element MUST exist in WordPress Core.
		$element_validator->validate( $element_type, $subtype, $element_id );

		// Invariant 3: Group MUST exist in TFML.
		$group = $group_repository->find( $group_id );
		if ( null === $group ) {
			throw new InvalidArgumentException(
				sprintf( 'El grupo TFML destino ID %d no existe en la base de datos.', $group_id )
			);
		}

		// Invariant 4: Element MUST NOT already belong to an active TFML group.
		$existing_active = $group_repository->find_by_element( $element_type, $element_id );
		if ( null !== $existing_active ) {
			throw new InvalidArgumentException(
				sprintf(
					'Conflicto de colisión: El elemento %s ID %d ya pertenece activamente al grupo TFML ID %d.',
					$element_type,
					$element_id,
					(int) $existing_active->get_id()
				)
			);
		}

		$group_repository->begin_transaction();

		try {
			// 1. Add active translation through standard domain repository.
			$element = $group_repository->add_translation( $group_id, $element_id, $lang_normalized, false );

			// 2. Remove the promoted translation from retained elements list.
			unset( $retained_elements[ $lang_normalized ] );

			$records    = $this->load_all();
			$record_key = $this->build_record_key( (string) $record['source'], $trid );

			if ( empty( $retained_elements ) ) {
				unset( $records[ $record_key ] );
			} else {
				$record['retained_elements'] = $retained_elements;
				$record['updated_at']        = gmdate( 'Y-m-d H:i:s' );
				$record['fingerprint']       = $this->compute_record_fingerprint( $record );
				$records[ $record_key ]      = $record;
			}

			// 3. Persist and verify state inside the same transaction.
			$this->persist_and_verify( $records, $trid );

			$group_repository->commit_transaction();

			return $element;
		} catch ( Throwable $e ) {
			$group_repository->rollback_transaction();
			$this->invalidate_option_cache();

			if ( $e instanceof InvalidArgumentException || $e instanceof MigrationExecutionException ) {
				throw $e;
			}

			throw new MigrationExecutionException(
				sprintf(
					'Fallo transaccional durante la promoción del elemento retenido ID %d (%s) en el grupo %d: %s',
					$element_id,
					$lang_normalized,
					$group_id,
					$e->getMessage()
				),
				$trid,
				$e
			);
		}
	}

	/**
	 * Purges all retained translations.
	 *
	 * @return bool
	 */
	public function purge_all(): bool {
		if ( $this->has_real_db() && null !== $this->db ) {
			$table = $this->get_options_table();
			$sql   = $this->db->prepare(
				"DELETE FROM `{$table}` WHERE `option_name` = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::OPTION_KEY
			);
			$this->db->query( $sql );
			$this->invalidate_option_cache();
			return true;
		}

		if ( function_exists( 'delete_option' ) ) {
			return delete_option( self::OPTION_KEY );
		}

		return false;
	}

	/**
	 * Checks whether the current record is semantically identical to the new registration request.
	 *
	 * @param array<string, mixed> $existing            Existing record.
	 * @param string               $source              Source identifier.
	 * @param int                  $trid                TRID.
	 * @param int                  $group_id            TFML group ID.
	 * @param string               $element_type        Element type.
	 * @param string               $subtype             Subtype.
	 * @param array<string, int>   $normalized_retained Normalized map of lang => element_id.
	 * @param string               $plan_fingerprint    Plan fingerprint.
	 * @return bool
	 */
	private function is_semantically_identical(
		array $existing,
		string $source,
		int $trid,
		int $group_id,
		string $element_type,
		string $subtype,
		array $normalized_retained,
		string $plan_fingerprint
	): bool {
		$existing_source  = (string) ( $existing['source'] ?? '' );
		$existing_trid    = (int) ( $existing['trid'] ?? 0 );
		$existing_group   = (int) ( $existing['group_id'] ?? 0 );
		$existing_type    = (string) ( $existing['element_type'] ?? '' );
		$existing_subtype = (string) ( $existing['subtype'] ?? '' );

		if (
			strtolower( trim( $source ) ) !== $existing_source ||
			$existing_trid !== $trid ||
			$existing_group !== $group_id ||
			$existing_type !== $element_type ||
			$existing_subtype !== $subtype
		) {
			return false;
		}

		if ( '' !== $plan_fingerprint && '' !== (string) ( $existing['plan_fingerprint'] ?? '' ) && $plan_fingerprint !== (string) $existing['plan_fingerprint'] ) {
			return false;
		}

		$existing_elements = (array) ( $existing['retained_elements'] ?? array() );
		if ( count( $existing_elements ) !== count( $normalized_retained ) ) {
			return false;
		}

		foreach ( $normalized_retained as $lang => $eid ) {
			if ( ! isset( $existing_elements[ $lang ] ) ) {
				return false;
			}
			$item = (array) $existing_elements[ $lang ];
			if ( (int) ( $item['element_id'] ?? 0 ) !== $eid ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Builds canonical composite record key.
	 *
	 * @param string $source Source identifier.
	 * @param int    $trid   Source TRID.
	 * @return string
	 */
	private function build_record_key( string $source, int $trid ): string {
		return sprintf( '%s:%d', strtolower( trim( $source ) ), $trid );
	}

	/**
	 * Computes a deterministic SHA-256 fingerprint for a record payload.
	 *
	 * @param array<string, mixed> $payload Record payload.
	 * @return string
	 */
	private function compute_record_fingerprint( array $payload ): string {
		$copy = $payload;
		unset( $copy['fingerprint'] );

		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $copy ) : json_encode( $copy );

		return hash( 'sha256', (string) $encoded );
	}

	/**
	 * Computes a deterministic SHA-256 fingerprint for an entire collection of records.
	 *
	 * @param array<string, array<string, mixed>> $records Collection of records.
	 * @return string
	 */
	private function compute_collection_fingerprint( array $records ): string {
		$copy = $records;
		ksort( $copy );
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $copy ) : json_encode( $copy );

		return hash( 'sha256', (string) $encoded );
	}

	/**
	 * Checks if an element ID is already recorded in the given records.
	 *
	 * @param array<string, array<string, mixed>> $records      All records.
	 * @param string                              $element_type Element type.
	 * @param int                                 $element_id   Element ID.
	 * @param string|null                         $ignore_key   Optional key to ignore during check.
	 * @return bool
	 */
	private function has_element_in_records( array $records, string $element_type, int $element_id, ?string $ignore_key = null ): bool {
		foreach ( $records as $key => $rec ) {
			if ( null !== $ignore_key && $key === $ignore_key ) {
				continue;
			}

			if ( ( $rec['element_type'] ?? '' ) !== $element_type ) {
				continue;
			}

			$retained_elements = (array) ( $rec['retained_elements'] ?? array() );
			foreach ( $retained_elements as $item ) {
				if ( (int) ( $item['element_id'] ?? 0 ) === $element_id ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Determines whether a live MySQL connection is available on $this->db.
	 *
	 * @return bool
	 */
	private function has_real_db(): bool {
		return null !== $this->db && isset( $this->db->dbh ) && null !== $this->db->dbh;
	}

	/**
	 * Returns the options table name for the active database connection.
	 *
	 * @return string
	 */
	private function get_options_table(): string {
		$prefix = null !== $this->db ? $this->db->prefix : 'wp_';

		return $prefix . 'options';
	}

	/**
	 * Invalidates WordPress option caches for the retained translation registry key.
	 *
	 * @return void
	 */
	public function invalidate_option_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_KEY, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * Loads all records directly from the transactional storage without stale cache interference.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function load_all(): array {
		if ( $this->has_real_db() && null !== $this->db ) {
			$table = $this->get_options_table();
			$query = $this->db->prepare(
				"SELECT `option_value` FROM `{$table}` WHERE `option_name` = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::OPTION_KEY
			);

			$raw_val = $this->db->get_var( $query );
			if ( null === $raw_val || false === $raw_val ) {
				return array();
			}

			$unserialized = function_exists( 'maybe_unserialize' )
				? maybe_unserialize( $raw_val )
				: @unserialize( (string) $raw_val ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged

			return is_array( $unserialized ) ? $unserialized : array();
		}

		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$raw = get_option( self::OPTION_KEY, array() );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persists all records and strictly verifies the persisted state to distinguish
	 * no-change idempotency from actual write failures.
	 *
	 * @param array<string, array<string, mixed>> $records Records to save.
	 * @param int                                 $trid    Associated TRID for error context.
	 * @return bool True when persistence and verification succeed.
	 * @throws MigrationExecutionException If write fails or persisted state diverges.
	 */
	private function persist_and_verify( array $records, int $trid = 0 ): bool {
		if ( $this->simulate_write_failure ) {
			throw new MigrationExecutionException(
				'Fallo de escritura en RetainedTranslationRegistry (persistencia de traducciones retenidas abortada).',
				$trid
			);
		}

		if ( $this->has_real_db() && null !== $this->db ) {
			$table      = $this->get_options_table();
			$serialized = function_exists( 'maybe_serialize' )
				? maybe_serialize( $records )
				: serialize( $records ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

			$sql = $this->db->prepare(
				"INSERT INTO `{$table}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`), `autoload` = 'no'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::OPTION_KEY,
				$serialized
			);

			$res = $this->db->query( $sql );
			$this->invalidate_option_cache();

			if ( false === $res || '' !== $this->db->last_error ) {
				throw new MigrationExecutionException(
					sprintf(
						'Error SQL al persistir traducciones retenidas en %s: %s',
						$table,
						$this->db->last_error
					),
					$trid
				);
			}
		} elseif ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $records, false );
		} else {
			throw new MigrationExecutionException(
				'No existe mecanismo de persistencia disponible para RetainedTranslationRegistry.',
				$trid
			);
		}

		// Verify persisted state regardless of whether update_option returned true or false (no-change).
		$persisted   = $this->load_all();
		$expected_fp = $this->compute_collection_fingerprint( $records );
		$actual_fp   = $this->compute_collection_fingerprint( $persisted );

		if ( ! hash_equals( $expected_fp, $actual_fp ) ) {
			throw new MigrationExecutionException(
				'Fallo de verificación post-escritura: El estado persistido en tfml_retained_translations no coincide con el estado esperado.',
				$trid
			);
		}

		return true;
	}
}
