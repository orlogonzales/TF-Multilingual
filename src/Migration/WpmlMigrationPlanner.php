<?php
/**
 * WPML Migration Planner (Strictly Read-Only).
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use wpdb;

/**
 * Class WpmlMigrationPlanner
 *
 * Strictly read-only planner that generates a deterministic MigrationPlan from WPML.
 * Classifies groups into IMPORTABLE, BLOCKED_BY_LANGUAGE, SKIPPED, CONFLICT, and ORPHAN.
 */
class WpmlMigrationPlanner {

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
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository|null
	 */
	private ?TranslationGroupRepository $group_repository;

	/**
	 * Unsupported element types to skip intentionally.
	 *
	 * @var array<int, string>
	 */
	private const UNSUPPORTED_TYPES = array(
		'comment',
		'tax_translation_priority',
	);

	/**
	 * Constructor.
	 *
	 * @param wpdb                            $db                WordPress database instance.
	 * @param LanguageRegistry                $language_registry Language registry.
	 * @param TranslationGroupRepository|null $group_repository Optional translation group repository.
	 */
	public function __construct(
		wpdb $db,
		LanguageRegistry $language_registry,
		?TranslationGroupRepository $group_repository = null
	) {
		$this->db                = $db;
		$this->language_registry = $language_registry;
		$this->group_repository  = $group_repository;
	}

	/**
	 * Generates a complete deterministic migration plan from WPML data.
	 *
	 * @param int  $limit                 Maximum number of TRIDs to process (0 = all).
	 * @param int  $offset                Starting offset of TRIDs.
	 * @param bool $detect_tfml_conflicts Whether to query existing TFML elements to classify collisions (default: true).
	 * @return MigrationPlan
	 */
	public function generate_plan( int $limit = 0, int $offset = 0, bool $detect_tfml_conflicts = true ): MigrationPlan {
		$table = $this->db->prefix . 'icl_translations';

		// Verify table existence safely.
		$table_exists = $this->db->get_var(
			$this->db->prepare( 'SHOW TABLES LIKE %s', $table )
		) === $table;

		if ( ! $table_exists ) {
			return new MigrationPlan(
				array(
					'source'            => 'wpml',
					'status'            => MigrationPlan::STATUS_BLOCKED,
					'missing_languages' => array(),
					'summary'           => array(),
					'media_plan'        => array(),
					'planned_groups'    => array(),
				)
			);
		}

		// 1. Detect missing languages.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$source_languages  = $this->db->get_col( "SELECT DISTINCT language_code FROM `{$table}`" );
		$missing_languages = array();

		foreach ( $source_languages as $code ) {
			$code = (string) $code;
			if ( ! $this->language_registry->is_active( $code ) ) {
				$missing_languages[] = $code;
			}
		}

		// 2. Formulate Media Model B Refinado plan.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attachment_count = (int) $this->db->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE element_type = 'post_attachment'"
		);

		$media_plan = array(
			'total_attachment_rows' => $attachment_count,
			'strategy'              => 'MEDIA_MODEL_B_DEDUPLICATION',
			'description'           => 'En WPML las imágenes se triplicaban en wp_posts (3.033 adjuntos). En TFML se preservará 1 único attachment post por archivo físico, extrayendo los textos ALT, título y descripción de los adjuntos clonados hacia tfml_media_translations sin duplicar posts en WordPress.',
		);

		// 3. Query distinct TRIDs excluding post_attachment.
		$trid_query = "SELECT DISTINCT trid FROM `{$table}` WHERE element_type != 'post_attachment' ORDER BY trid ASC";
		if ( $limit > 0 ) {
			$trid_query .= $this->db->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$trids = $this->db->get_col( $trid_query );

		if ( empty( $trids ) ) {
			return new MigrationPlan(
				array(
					'source'            => 'wpml',
					'status'            => empty( $missing_languages ) ? MigrationPlan::STATUS_READY : MigrationPlan::STATUS_NEEDS_CONFIGURATION,
					'missing_languages' => $missing_languages,
					'summary'           => array(
						'total_groups'            => 0,
						'importable_groups'       => 0,
						'blocked_language_groups' => 0,
						'skipped_groups'          => 0,
						'conflict_groups'         => 0,
						'orphan_groups'           => 0,
						'total_elements'          => 0,
						'importable_elements'     => 0,
						'pending_elements'        => 0,
					),
					'media_plan'        => $media_plan,
					'planned_groups'    => array(),
				)
			);
		}

		// 4. Batch query all elements belonging to selected TRIDs.
		$placeholders = implode( ',', array_fill( 0, count( $trids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT translation_id, trid, element_type, element_id, language_code, source_language_code
				 FROM `{$table}`
				 WHERE trid IN ({$placeholders})
				 ORDER BY trid ASC, translation_id ASC",
				...$trids
			),
			ARRAY_A
		);

		// Group rows by TRID.
		$grouped_rows = array();
		foreach ( $rows as $row ) {
			$trid                    = (int) $row['trid'];
			$grouped_rows[ $trid ][] = $row;
		}

		// 5. Query pre-existing TFML elements to detect conflicts in O(1).
		$tfml_table        = $this->db->prefix . 'tfml_group_elements';
		$existing_tfml_ids = array();
		$has_tfml_table    = $this->db->get_var(
			$this->db->prepare( 'SHOW TABLES LIKE %s', $tfml_table )
		) === $tfml_table;

		if ( $detect_tfml_conflicts && $has_tfml_table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$tfml_rows = $this->db->get_col( "SELECT element_id FROM `{$tfml_table}`" );
			foreach ( $tfml_rows as $id ) {
				$existing_tfml_ids[ (int) $id ] = true;
			}
		}

		// 6. Process and classify each TRID deterministically.
		$planned_groups          = array();
		$importable_groups_count = 0;
		$blocked_lang_count      = 0;
		$skipped_groups_count    = 0;
		$conflict_groups_count   = 0;
		$orphan_groups_count     = 0;
		$total_elements_count    = 0;
		$importable_elements     = 0;
		$pending_elements        = 0;

		foreach ( $grouped_rows as $trid => $members ) {
			$raw_type = (string) $members[0]['element_type'];
			$parsed   = $this->parse_element_type( $raw_type );

			$canonical_id         = null;
			$canonical_lang       = null;
			$translations         = array();
			$pending_translations = array();
			$reasons              = array();
			$classification       = MigrationPlan::CLASSIFICATION_IMPORTABLE;

			// Check if unsupported type.
			if ( in_array( $raw_type, self::UNSUPPORTED_TYPES, true ) ) {
				$classification = MigrationPlan::CLASSIFICATION_SKIPPED;
				$reasons[]      = sprintf( "Tipo de elemento '%s' no soportado o excluido de la traducción.", $raw_type );
			}

			// Process members.
			foreach ( $members as $m ) {
				$elem_id  = (int) $m['element_id'];
				$lang     = (string) $m['language_code'];
				$src_lang = null !== $m['source_language_code'] ? (string) $m['source_language_code'] : null;

				++$total_elements_count;

				// Canonical detection: source_language_code is null or empty.
				if ( null === $src_lang || '' === $src_lang ) {
					$canonical_id   = $elem_id;
					$canonical_lang = $lang;
				}

				// Check WP Core existence (Orphan check).
				$exists_in_core = $this->check_element_exists_in_core( $elem_id, $parsed['element_type'] );
				if ( ! $exists_in_core && MigrationPlan::CLASSIFICATION_SKIPPED !== $classification ) {
					$classification = MigrationPlan::CLASSIFICATION_ORPHAN;
					$reasons[]      = sprintf( 'El elemento ID %d (%s) ya no existe en la base de datos de WordPress.', $elem_id, $lang );
				}

				// Check TFML conflict.
				if ( isset( $existing_tfml_ids[ $elem_id ] ) && MigrationPlan::CLASSIFICATION_SKIPPED !== $classification ) {
					$classification = MigrationPlan::CLASSIFICATION_CONFLICT;
					$reasons[]      = sprintf( 'El elemento ID %d ya pertenece a un grupo existente en TF Multilingual.', $elem_id );
				}

				// Language activation check: do not discard silently!
				if ( in_array( $lang, $missing_languages, true ) ) {
					$pending_translations[ $lang ] = $elem_id;
					++$pending_elements;
					if ( MigrationPlan::CLASSIFICATION_IMPORTABLE === $classification ) {
						$classification = MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE;
						$reasons[]      = sprintf( "Dependencia pendiente: el idioma '%s' no está configurado en TFML. Traducción retenida sin descarte silencioso.", $lang );
					}
				} else {
					$translations[ $lang ] = $elem_id;
					if ( MigrationPlan::CLASSIFICATION_IMPORTABLE === $classification ) {
						++$importable_elements;
					}
				}
			}

			// Fallback canonical if not set.
			if ( null === $canonical_id ) {
				if ( ! empty( $translations ) ) {
					$first_lang     = (string) array_key_first( $translations );
					$canonical_id   = $translations[ $first_lang ];
					$canonical_lang = $first_lang;
				} elseif ( ! empty( $pending_translations ) ) {
					$first_lang     = (string) array_key_first( $pending_translations );
					$canonical_id   = $pending_translations[ $first_lang ];
					$canonical_lang = $first_lang;
				}
			}

			if ( empty( $reasons ) ) {
				$reasons[] = 'Elegible para importación determinista directa.';
			}

			// Count metrics by classification.
			switch ( $classification ) {
				case MigrationPlan::CLASSIFICATION_IMPORTABLE:
					++$importable_groups_count;
					break;
				case MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE:
					++$blocked_lang_count;
					break;
				case MigrationPlan::CLASSIFICATION_SKIPPED:
					++$skipped_groups_count;
					break;
				case MigrationPlan::CLASSIFICATION_CONFLICT:
					++$conflict_groups_count;
					break;
				case MigrationPlan::CLASSIFICATION_ORPHAN:
					++$orphan_groups_count;
					break;
			}

			$planned_groups[] = array(
				'trid'                 => $trid,
				'element_type'         => $parsed['element_type'],
				'subtype'              => $parsed['subtype'],
				'canonical_id'         => $canonical_id,
				'canonical_lang'       => $canonical_lang,
				'classification'       => $classification,
				'reasons'              => $reasons,
				'translations'         => $translations,
				'pending_translations' => $pending_translations,
			);
		}

		$overall_status = MigrationPlan::STATUS_READY;
		if ( ! empty( $missing_languages ) || $blocked_lang_count > 0 ) {
			$overall_status = MigrationPlan::STATUS_NEEDS_CONFIGURATION;
		}

		$summary = array(
			'total_groups'            => count( $planned_groups ),
			'importable_groups'       => $importable_groups_count,
			'blocked_language_groups' => $blocked_lang_count,
			'skipped_groups'          => $skipped_groups_count,
			'conflict_groups'         => $conflict_groups_count,
			'orphan_groups'           => $orphan_groups_count,
			'total_elements'          => $total_elements_count,
			'importable_elements'     => $importable_elements,
			'pending_elements'        => $pending_elements,
		);

		return new MigrationPlan(
			array(
				'source'            => 'wpml',
				'status'            => $overall_status,
				'missing_languages' => $missing_languages,
				'summary'           => $summary,
				'media_plan'        => $media_plan,
				'planned_groups'    => $planned_groups,
			)
		);
	}

	/**
	 * Parse WPML element_type into TFML element_type and subtype.
	 *
	 * @param string $raw_type Raw WPML element_type string.
	 * @return array{element_type: string, subtype: string}
	 */
	public function parse_element_type( string $raw_type ): array {
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
	 * Check if an element exists in WordPress Core tables.
	 *
	 * @param int    $element_id   Object ID.
	 * @param string $element_type Element type ('post' or 'term').
	 * @return bool
	 */
	private function check_element_exists_in_core( int $element_id, string $element_type ): bool {
		if ( 'post' === $element_type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found = $this->db->get_var(
				$this->db->prepare( "SELECT ID FROM `{$this->db->posts}` WHERE ID = %d", $element_id )
			);
			return null !== $found && (int) $found === $element_id;
		}

		if ( 'term' === $element_type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found = $this->db->get_var(
				$this->db->prepare( "SELECT term_id FROM `{$this->db->terms}` WHERE term_id = %d", $element_id )
			);
			return null !== $found && (int) $found === $element_id;
		}

		return true;
	}
}
