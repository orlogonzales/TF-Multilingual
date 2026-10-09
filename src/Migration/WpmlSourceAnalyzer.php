<?php
/**
 * WPML Source Analyzer (Strictly Read-Only).
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use wpdb;

/**
 * Class WpmlSourceAnalyzer
 *
 * Strictly read-only analysis and inventory diagnostic of WPML translation structures
 * (icl_translations, icl_languages, icl_strings). Guarantees zero write operations.
 */
class WpmlSourceAnalyzer {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Constructor.
	 *
	 * @param wpdb             $db                WordPress database instance.
	 * @param LanguageRegistry $language_registry Language registry.
	 */
	public function __construct( wpdb $db, LanguageRegistry $language_registry ) {
		$this->db                = $db;
		$this->language_registry = $language_registry;
	}

	/**
	 * Check if WPML source data is present in database.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$table = $this->db->prefix . 'icl_translations';
		$found = $this->db->get_var(
			$this->db->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		if ( $found !== $table ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table}" );
		return $count > 0;
	}

	/**
	 * Execute comprehensive, strictly read-only inventory analysis.
	 *
	 * @return MigrationInventoryReport
	 */
	public function analyze(): MigrationInventoryReport {
		if ( ! $this->is_available() ) {
			return new MigrationInventoryReport(
				array(
					'source'      => 'wpml',
					'source_name' => 'WPML (WordPress Multilingual)',
					'status'      => 'blocked',
					'notes'       => array( 'WPML translations table not found or empty.' ),
				)
			);
		}

		$translations_table = $this->db->prefix . 'icl_translations';
		$languages_table    = $this->db->prefix . 'icl_languages';

		// 1. Read detected languages in WPML.
		$detected_languages = $this->analyze_languages( $languages_table, $translations_table );
		$missing_languages  = array();

		foreach ( $detected_languages as $lang_info ) {
			if ( ! $lang_info['active_in_tfml'] ) {
				$missing_languages[] = $lang_info['code'];
			}
		}

		// 2. Count elements by element_type.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$type_rows = $this->db->get_results(
			"SELECT element_type, COUNT(*) as c FROM {$translations_table} GROUP BY element_type ORDER BY c DESC",
			ARRAY_A
		);

		$elements_by_type    = array();
		$total_elements      = 0;
		$attachment_elements = 0;

		foreach ( $type_rows as $row ) {
			$type                      = (string) $row['element_type'];
			$count                     = (int) $row['c'];
			$elements_by_type[ $type ] = $count;
			$total_elements           += $count;

			if ( 'post_attachment' === $type ) {
				$attachment_elements = $count;
			}
		}

		// 3. Groups count (distinct trid).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_groups = (int) $this->db->get_var( "SELECT COUNT(DISTINCT trid) FROM {$translations_table}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$content_groups = (int) $this->db->get_var(
			"SELECT COUNT(DISTINCT trid) FROM {$translations_table} WHERE element_type != 'post_attachment'"
		);

		// 4. Conflicts check (elements already in TFML groups).
		$conflicts = $this->detect_conflicts( $translations_table );

		// 5. Orphans check (elements pointing to non-existent posts or terms).
		$orphans = $this->detect_orphans( $translations_table );

		// 6. Build diagnostic notes and status.
		$notes   = array();
		$notes[] = sprintf( 'Encontrados %d registros de traducción en WPML distribuidos en %d grupos (trids).', $total_elements, $total_groups );
		$notes[] = sprintf( 'Grupos de contenido editorial (excluyendo attachments clonados): %d.', $content_groups );
		$notes[] = sprintf( 'Archivos adjuntos (post_attachment) en WPML: %d (En TFML se gestionan vía Media Model B Refinado sin duplicar posts).', $attachment_elements );

		$status = 'ready';

		if ( ! empty( $missing_languages ) ) {
			$status  = 'warning';
			$notes[] = sprintf( 'ADVERTENCIA: Los siguientes idiomas de WPML no están activos en TFML: %s. Deben configurarse antes de migrar.', implode( ', ', $missing_languages ) );
		}

		if ( ! empty( $conflicts ) ) {
			$status  = 'warning';
			$notes[] = sprintf( 'ADVERTENCIA: %d elementos ya pertenecen a grupos de TFML. La migración debe omitirlos o fusionarlos sin sobrescribir.', count( $conflicts ) );
		}

		if ( ! empty( $orphans ) ) {
			$notes[] = sprintf( 'AVISO: %d registros en WPML son huérfanos (el post o término original ya no existe en WordPress).', count( $orphans ) );
		}

		return new MigrationInventoryReport(
			array(
				'source'              => 'wpml',
				'source_name'         => 'WPML (WordPress Multilingual)',
				'status'              => $status,
				'languages'           => $detected_languages,
				'missing_languages'   => $missing_languages,
				'elements_by_type'    => $elements_by_type,
				'total_elements'      => $total_elements,
				'total_groups'        => $total_groups,
				'content_groups'      => $content_groups,
				'attachment_elements' => $attachment_elements,
				'conflicts'           => $conflicts,
				'orphans'             => $orphans,
				'notes'               => $notes,
			)
		);
	}

	/**
	 * Simulate migration for a batch of groups (Dry-Run mode without any database write).
	 *
	 * @param int $limit Maximum number of groups to simulate.
	 * @return array{
	 *     simulated_groups: int,
	 *     simulated_elements: int,
	 *     groups: array<int, array{
	 *         trid: int,
	 *         element_type: string,
	 *         subtype: string,
	 *         canonical_id: ?int,
	 *         canonical_lang: ?string,
	 *         translations: array<string, int>
	 *     }>
	 * }
	 */
	public function simulate_migration( int $limit = 50 ): array {
		if ( ! $this->is_available() ) {
			return array(
				'simulated_groups'   => 0,
				'simulated_elements' => 0,
				'groups'             => array(),
			);
		}

		$translations_table = $this->db->prefix . 'icl_translations';

		// Fetch sample distinct trids for non-attachment content.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$trids = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT trid FROM {$translations_table} WHERE element_type != 'post_attachment' ORDER BY trid ASC LIMIT %d",
				$limit
			)
		);

		if ( empty( $trids ) ) {
			return array(
				'simulated_groups'   => 0,
				'simulated_elements' => 0,
				'groups'             => array(),
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $trids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT translation_id, trid, element_type, element_id, language_code, source_language_code
				 FROM {$translations_table}
				 WHERE trid IN ({$placeholders})
				 ORDER BY trid ASC, translation_id ASC",
				...$trids
			),
			ARRAY_A
		);

		$groups_map         = array();
		$simulated_elements = 0;

		foreach ( $rows as $row ) {
			$trid     = (int) $row['trid'];
			$raw_type = (string) $row['element_type'];
			$elem_id  = (int) $row['element_id'];
			$lang     = (string) $row['language_code'];
			$src_lang = null !== $row['source_language_code'] ? (string) $row['source_language_code'] : null;

			if ( ! isset( $groups_map[ $trid ] ) ) {
				$parsed              = $this->parse_wpml_element_type( $raw_type );
				$groups_map[ $trid ] = array(
					'trid'           => $trid,
					'element_type'   => $parsed['element_type'],
					'subtype'        => $parsed['subtype'],
					'canonical_id'   => null,
					'canonical_lang' => null,
					'translations'   => array(),
				);
			}

			$groups_map[ $trid ]['translations'][ $lang ] = $elem_id;
			++$simulated_elements;

			// Canonical element: where source_language_code IS NULL or empty.
			if ( null === $src_lang || '' === $src_lang ) {
				$groups_map[ $trid ]['canonical_id']   = $elem_id;
				$groups_map[ $trid ]['canonical_lang'] = $lang;
			}
		}

		// Fallback: if no row had source_language_code NULL, pick the first element as canonical.
		foreach ( $groups_map as &$group ) {
			if ( null === $group['canonical_id'] && ! empty( $group['translations'] ) ) {
				$first_lang              = (string) array_key_first( $group['translations'] );
				$group['canonical_id']   = $group['translations'][ $first_lang ];
				$group['canonical_lang'] = $first_lang;
			}
		}
		unset( $group );

		return array(
			'simulated_groups'   => count( $groups_map ),
			'simulated_elements' => $simulated_elements,
			'groups'             => array_values( $groups_map ),
		);
	}

	/**
	 * Parse WPML raw element_type into TFML element_type and subtype.
	 *
	 * @param string $raw_type Raw WPML element_type (e.g. 'post_page', 'tax_category').
	 * @return array{element_type: string, subtype: string}
	 */
	public function parse_wpml_element_type( string $raw_type ): array {
		if ( str_starts_with( $raw_type, 'post_' ) ) {
			return array(
				'element_type' => 'post',
				'subtype'      => substr( $raw_type, 5 ),
			);
		}

		if ( str_starts_with( $raw_type, 'tax_' ) ) {
			return array(
				'element_type' => 'term',
				'subtype'      => substr( $raw_type, 4 ),
			);
		}

		return array(
			'element_type' => 'other',
			'subtype'      => $raw_type,
		);
	}

	/**
	 * Analyze languages in WPML.
	 *
	 * @param string $languages_table    Table name for icl_languages.
	 * @param string $translations_table Table name for icl_translations.
	 * @return array<int, array{code: string, active_in_source: bool, registered_in_tfml: bool, active_in_tfml: bool}>
	 */
	private function analyze_languages( string $languages_table, string $translations_table ): array {
		$languages_present = array();

		// Check if icl_languages exists.
		$has_lang_table = $this->db->get_var(
			$this->db->prepare( 'SHOW TABLES LIKE %s', $languages_table )
		) === $languages_table;

		if ( $has_lang_table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $this->db->get_results(
				"SELECT code, active FROM {$languages_table} WHERE active = 1",
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$languages_present[ (string) $r['code'] ] = 1 === (int) $r['active'];
			}
		}

		// Also fetch any languages used in icl_translations.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$used_langs = $this->db->get_col( "SELECT DISTINCT language_code FROM {$translations_table}" );
		foreach ( $used_langs as $code ) {
			if ( ! isset( $languages_present[ $code ] ) ) {
				$languages_present[ $code ] = true;
			}
		}

		$result = array();
		foreach ( $languages_present as $code => $is_active_source ) {
			$tfml_has    = $this->language_registry->has( $code );
			$tfml_active = false;
			if ( $tfml_has ) {
				$lang_obj    = $this->language_registry->get( $code );
				$tfml_active = null !== $lang_obj && $lang_obj->is_active();
			}

			$result[] = array(
				'code'               => $code,
				'active_in_source'   => $is_active_source,
				'registered_in_tfml' => $tfml_has,
				'active_in_tfml'     => $tfml_active,
			);
		}

		return $result;
	}

	/**
	 * Detect conflicts: elements in WPML already registered in TFML.
	 *
	 * @param string $translations_table Table name.
	 * @return array<int, array{element_id: int, element_type: string, language: string}>
	 */
	private function detect_conflicts( string $translations_table ): array {
		$tfml_elements_table = $this->db->prefix . 'tfml_group_elements';

		$has_tfml = $this->db->get_var(
			$this->db->prepare( 'SHOW TABLES LIKE %s', $tfml_elements_table )
		) === $tfml_elements_table;

		if ( ! $has_tfml ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conflict_rows = $this->db->get_results(
			"SELECT w.element_id, w.element_type, w.language_code
			 FROM {$translations_table} w
			 INNER JOIN {$tfml_elements_table} t ON w.element_id = t.element_id
			 WHERE w.element_type != 'post_attachment'
			 LIMIT 100",
			ARRAY_A
		);

		$conflicts = array();
		foreach ( $conflict_rows as $row ) {
			$conflicts[] = array(
				'element_id'   => (int) $row['element_id'],
				'element_type' => (string) $row['element_type'],
				'language'     => (string) $row['language_code'],
			);
		}

		return $conflicts;
	}

	/**
	 * Detect orphan elements in WPML where post or term was deleted from WordPress.
	 *
	 * @param string $translations_table Table name.
	 * @return array<int, array{element_id: int, element_type: string, language: string}>
	 */
	private function detect_orphans( string $translations_table ): array {
		// Detect orphan posts.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_posts = $this->db->get_results(
			"SELECT w.element_id, w.element_type, w.language_code
			 FROM {$translations_table} w
			 LEFT JOIN {$this->db->posts} p ON w.element_id = p.ID
			 WHERE w.element_type LIKE 'post_%' AND w.element_type != 'post_attachment' AND p.ID IS NULL
			 LIMIT 50",
			ARRAY_A
		);

		// Detect orphan terms.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_terms = $this->db->get_results(
			"SELECT w.element_id, w.element_type, w.language_code
			 FROM {$translations_table} w
			 LEFT JOIN {$this->db->terms} t ON w.element_id = t.term_id
			 WHERE w.element_type LIKE 'tax_%' AND t.term_id IS NULL
			 LIMIT 50",
			ARRAY_A
		);

		$orphans = array();
		foreach ( array_merge( $orphan_posts, $orphan_terms ) as $row ) {
			$orphans[] = array(
				'element_id'   => (int) $row['element_id'],
				'element_type' => (string) $row['element_type'],
				'language'     => (string) $row['language_code'],
			);
		}

		return $orphans;
	}
}
