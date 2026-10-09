<?php
/**
 * Polylang Source Analyzer (Strictly Read-Only).
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use wpdb;

/**
 * Class PolylangSourceAnalyzer
 *
 * Strictly read-only analysis and inventory diagnostic of Polylang structures
 * (language taxonomy, term_translations, post_translations). Guarantees zero write operations.
 */
class PolylangSourceAnalyzer {

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
	 * Check if Polylang source data is present in database.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$taxonomies = $this->db->get_col(
			"SELECT DISTINCT taxonomy FROM {$this->db->term_taxonomy} WHERE taxonomy IN ('language', 'term_language')"
		);

		return ! empty( $taxonomies );
	}

	/**
	 * Execute comprehensive, strictly read-only inventory analysis for Polylang.
	 *
	 * @return MigrationInventoryReport
	 */
	public function analyze(): MigrationInventoryReport {
		if ( ! $this->is_available() ) {
			return new MigrationInventoryReport(
				array(
					'source'      => 'polylang',
					'source_name' => 'Polylang',
					'status'      => 'blocked',
					'notes'       => array( 'Polylang taxonomies not found in database.' ),
				)
			);
		}

		// 1. Languages in Polylang (stored as terms in 'language' or 'term_language' taxonomy).
		$lang_terms = $this->db->get_results(
			"SELECT t.slug as code, tt.description
			 FROM {$this->db->terms} t
			 INNER JOIN {$this->db->term_taxonomy} tt ON t.term_id = tt.term_id
			 WHERE tt.taxonomy IN ('language', 'term_language')",
			ARRAY_A
		);

		$detected_languages = array();
		$missing_languages  = array();

		foreach ( $lang_terms as $row ) {
			$code        = (string) $row['code'];
			$tfml_has    = $this->language_registry->has( $code );
			$tfml_active = false;
			if ( $tfml_has ) {
				$lang_obj    = $this->language_registry->get( $code );
				$tfml_active = null !== $lang_obj && $lang_obj->is_active();
			}

			if ( ! $tfml_active ) {
				$missing_languages[] = $code;
			}

			$detected_languages[] = array(
				'code'               => $code,
				'active_in_source'   => true,
				'registered_in_tfml' => $tfml_has,
				'active_in_tfml'     => $tfml_active,
			);
		}

		// 2. Count post and term translations from 'post_translations' and 'term_translations'.
		$translation_terms = $this->db->get_results(
			"SELECT tt.taxonomy, tt.description
			 FROM {$this->db->term_taxonomy} tt
			 WHERE tt.taxonomy IN ('post_translations', 'term_translations')",
			ARRAY_A
		);

		$total_groups     = count( $translation_terms );
		$total_elements   = 0;
		$elements_by_type = array(
			'post' => 0,
			'term' => 0,
		);

		foreach ( $translation_terms as $t_row ) {
			$raw_desc = (string) $t_row['description'];
			$data     = function_exists( 'maybe_unserialize' ) ? \maybe_unserialize( $raw_desc ) : @unserialize( $raw_desc );
			if ( is_array( $data ) ) {
				$count           = count( $data );
				$total_elements += $count;
				if ( 'post_translations' === $t_row['taxonomy'] ) {
					$elements_by_type['post'] += $count;
				} else {
					$elements_by_type['term'] += $count;
				}
			}
		}

		$status  = 'ready';
		$notes   = array();
		$notes[] = sprintf( 'Encontrados %d grupos de traducción en Polylang con %d elementos totales.', $total_groups, $total_elements );

		if ( ! empty( $missing_languages ) ) {
			$status  = 'warning';
			$notes[] = sprintf( 'ADVERTENCIA: Idiomas de Polylang no activos en TFML: %s.', implode( ', ', $missing_languages ) );
		}

		return new MigrationInventoryReport(
			array(
				'source'              => 'polylang',
				'source_name'         => 'Polylang',
				'status'              => $status,
				'languages'           => $detected_languages,
				'missing_languages'   => $missing_languages,
				'elements_by_type'    => $elements_by_type,
				'total_elements'      => $total_elements,
				'total_groups'        => $total_groups,
				'content_groups'      => $total_groups,
				'attachment_elements' => 0,
				'conflicts'           => array(),
				'orphans'             => array(),
				'notes'               => $notes,
			)
		);
	}
}
