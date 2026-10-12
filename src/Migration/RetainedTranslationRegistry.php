<?php
/**
 * Retained Translation Registry.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use InvalidArgumentException;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;

/**
 * Class RetainedTranslationRegistry
 *
 * Manages the persistence, tracking, and future promotion of retained translations
 * belonging to inactive languages from historical migration sources (e.g. WPML).
 *
 * Guarantees zero active pollution of TFML core tables (tfml_group_elements) while
 * preserving complete historical lineage, cryptographic fingerprints, and explicit
 * auditability for future language activations.
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

		$current_records = $this->load_all();
		$record_key      = $this->build_record_key( $source, $trid );

		// Check uniqueness of elements: no retained element can be duplicated in another group.
		foreach ( $retained_translations as $lang => $elem_id ) {
			$elem_id = (int) $elem_id;
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

		$now               = gmdate( 'Y-m-d H:i:s' );
		$retained_elements = array();

		foreach ( $retained_translations as $lang => $elem_id ) {
			$lang = strtolower( trim( (string) $lang ) );
			$eid  = (int) $elem_id;

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
			'source'            => $source,
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
		$this->save_all( $current_records );

		return $record_payload;
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
	 */
	public function remove( string $source, int $trid ): bool {
		$records    = $this->load_all();
		$record_key = $this->build_record_key( $source, $trid );

		if ( ! isset( $records[ $record_key ] ) ) {
			return false;
		}

		unset( $records[ $record_key ] );

		return $this->save_all( $records );
	}

	/**
	 * Removes all retained records associated with a given TFML group ID.
	 *
	 * Useful during rollback of imported groups.
	 *
	 * @param int $group_id TFML group ID.
	 * @return bool True if any record was removed.
	 */
	public function remove_by_group_id( int $group_id ): bool {
		$records = $this->load_all();
		$changed = false;

		foreach ( $records as $key => $rec ) {
			if ( (int) ( $rec['group_id'] ?? 0 ) === $group_id ) {
				unset( $records[ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			return $this->save_all( $records );
		}

		return false;
	}

	/**
	 * Promotes a previously retained translation into an active translation within its TFML group.
	 *
	 * Validates that the target language is now active, the element exists in WordPress Core,
	 * the group exists, adds the translation through TranslationGroupRepository, and updates
	 * or cleans up the retained record.
	 *
	 * @param int                        $group_id          TFML group ID.
	 * @param string                     $language_code     Language code to promote (must be active now).
	 * @param TranslationGroupRepository $group_repository  Translation group repository.
	 * @param LanguageRegistry           $language_registry Language registry.
	 * @param WordPressElementValidator  $element_validator Element validator.
	 * @return TranslationElement The newly active translation element.
	 * @throws InvalidArgumentException If promotion invariants fail.
	 */
	public function promote_retained_translation(
		int $group_id,
		string $language_code,
		TranslationGroupRepository $group_repository,
		LanguageRegistry $language_registry,
		WordPressElementValidator $element_validator
	): TranslationElement {
		$lang_normalized = strtolower( trim( $language_code ) );
		$record          = $this->get_by_group_id( $group_id );

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

		// Invariant 2: Element MUST exist in WordPress Core.
		$element_validator->validate( $element_type, $subtype, $element_id );

		// Invariant 3: Group MUST exist in TFML.
		$group = $group_repository->find( $group_id );
		if ( null === $group ) {
			throw new InvalidArgumentException(
				sprintf( 'El grupo TFML destino ID %d no existe en la base de datos.', $group_id )
			);
		}

		// Perform addition through standard domain repository:
		$element = $group_repository->add_translation( $group_id, $element_id, $lang_normalized, false );

		// Remove the promoted translation from retained elements list:
		unset( $retained_elements[ $lang_normalized ] );

		$records    = $this->load_all();
		$record_key = $this->build_record_key( (string) $record['source'], (int) $record['trid'] );

		if ( empty( $retained_elements ) ) {
			// All retained elements have been promoted: clean up record entirely.
			unset( $records[ $record_key ] );
		} else {
			// Other retained elements remain: update record.
			$record['retained_elements'] = $retained_elements;
			$record['updated_at']        = gmdate( 'Y-m-d H:i:s' );
			$record['fingerprint']       = $this->compute_record_fingerprint( $record );
			$records[ $record_key ]      = $record;
		}

		$this->save_all( $records );

		return $element;
	}

	/**
	 * Purges all retained translations.
	 *
	 * @return bool
	 */
	public function purge_all(): bool {
		if ( function_exists( 'delete_option' ) ) {
			return delete_option( self::OPTION_KEY );
		}

		return false;
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
	 * Loads all records from persistent storage.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function load_all(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$raw = get_option( self::OPTION_KEY, array() );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Saves all records to persistent storage.
	 *
	 * @param array<string, array<string, mixed>> $records Records to save.
	 * @return bool
	 */
	private function save_all( array $records ): bool {
		if ( ! function_exists( 'update_option' ) ) {
			return false;
		}

		return update_option( self::OPTION_KEY, $records, false );
	}
}
