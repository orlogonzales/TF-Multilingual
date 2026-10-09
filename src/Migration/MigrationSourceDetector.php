<?php
/**
 * Migration Source Detector.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use wpdb;

/**
 * Class MigrationSourceDetector
 *
 * Strictly read-only detection of external multilingual systems (WPML, Polylang)
 * in the active WordPress database environment.
 */
class MigrationSourceDetector {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db WordPress database instance.
	 */
	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Detect all available migration sources in the current environment.
	 *
	 * @return array<string, array{available: bool, name: string, details: array<string, mixed>}>
	 */
	public function detect_sources(): array {
		return array(
			'wpml'     => $this->detect_wpml(),
			'polylang' => $this->detect_polylang(),
		);
	}

	/**
	 * Detect WPML installation and database tables (strictly read-only).
	 *
	 * @return array{available: bool, name: string, details: array<string, mixed>}
	 */
	public function detect_wpml(): array {
		$translations_table = $this->db->prefix . 'icl_translations';
		$languages_table    = $this->db->prefix . 'icl_languages';

		$tables_found = $this->db->get_col(
			$this->db->prepare(
				'SHOW TABLES LIKE %s',
				$this->db->esc_like( $this->db->prefix . 'icl_' ) . '%'
			)
		);

		$has_translations = in_array( $translations_table, $tables_found, true );
		$has_languages    = in_array( $languages_table, $tables_found, true );

		$total_translations = 0;
		$total_groups       = 0;

		if ( $has_translations ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_translations = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$translations_table}" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_groups = (int) $this->db->get_var( "SELECT COUNT(DISTINCT trid) FROM {$translations_table}" );
		}

		$is_available = $has_translations && $total_translations > 0;

		return array(
			'available' => $is_available,
			'name'      => 'WPML (WordPress Multilingual)',
			'details'   => array(
				'tables_count'       => count( $tables_found ),
				'has_translations'   => $has_translations,
				'has_languages'      => $has_languages,
				'total_translations' => $total_translations,
				'total_groups'       => $total_groups,
			),
		);
	}

	/**
	 * Detect Polylang installation and taxonomies (strictly read-only).
	 *
	 * @return array{available: bool, name: string, details: array<string, mixed>}
	 */
	public function detect_polylang(): array {
		$taxonomies = $this->db->get_col(
			"SELECT DISTINCT taxonomy FROM {$this->db->term_taxonomy} WHERE taxonomy IN ('language', 'term_language', 'post_translations', 'term_translations')"
		);

		$has_language_tax = in_array( 'language', $taxonomies, true ) || in_array( 'term_language', $taxonomies, true );
		$has_translations = in_array( 'post_translations', $taxonomies, true ) || in_array( 'term_translations', $taxonomies, true );

		$total_languages    = 0;
		$total_translations = 0;

		if ( $has_language_tax ) {
			$total_languages = (int) $this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$this->db->term_taxonomy} WHERE taxonomy IN (%s, %s)",
					'language',
					'term_language'
				)
			);
		}

		if ( $has_translations ) {
			$total_translations = (int) $this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$this->db->term_taxonomy} WHERE taxonomy IN (%s, %s)",
					'post_translations',
					'term_translations'
				)
			);
		}

		$is_available = $has_language_tax && $total_languages > 0;

		return array(
			'available' => $is_available,
			'name'      => 'Polylang',
			'details'   => array(
				'has_language_tax'   => $has_language_tax,
				'has_translations'   => $has_translations,
				'total_languages'    => $total_languages,
				'total_translations' => $total_translations,
			),
		);
	}
}
