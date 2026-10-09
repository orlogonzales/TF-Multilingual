<?php
/**
 * Migration Manager Service.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use wpdb;

/**
 * Class MigrationManager
 *
 * Coordinates migration source detection, read-only analysis, dry-run simulations,
 * and migration reporting without performing unauthorized or unsafe writes.
 */
class MigrationManager {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Migration source detector.
	 *
	 * @var MigrationSourceDetector
	 */
	private MigrationSourceDetector $detector;

	/**
	 * WPML source analyzer.
	 *
	 * @var WpmlSourceAnalyzer
	 */
	private WpmlSourceAnalyzer $wpml_analyzer;

	/**
	 * Polylang source analyzer.
	 *
	 * @var PolylangSourceAnalyzer
	 */
	private PolylangSourceAnalyzer $polylang_analyzer;

	/**
	 * Constructor.
	 *
	 * @param wpdb             $db                WordPress database instance.
	 * @param LanguageRegistry $language_registry Language registry.
	 */
	public function __construct( wpdb $db, LanguageRegistry $language_registry ) {
		$this->db                = $db;
		$this->detector          = new MigrationSourceDetector( $db );
		$this->wpml_analyzer     = new WpmlSourceAnalyzer( $db, $language_registry );
		$this->polylang_analyzer = new PolylangSourceAnalyzer( $db, $language_registry );
	}

	/**
	 * Get migration source detector.
	 *
	 * @return MigrationSourceDetector
	 */
	public function get_detector(): MigrationSourceDetector {
		return $this->detector;
	}

	/**
	 * Get WPML analyzer.
	 *
	 * @return WpmlSourceAnalyzer
	 */
	public function get_wpml_analyzer(): WpmlSourceAnalyzer {
		return $this->wpml_analyzer;
	}

	/**
	 * Get Polylang analyzer.
	 *
	 * @return PolylangSourceAnalyzer
	 */
	public function get_polylang_analyzer(): PolylangSourceAnalyzer {
		return $this->polylang_analyzer;
	}

	/**
	 * Analyze specified source ('wpml' or 'polylang').
	 *
	 * @param string $source Source key.
	 * @return MigrationInventoryReport
	 */
	public function analyze_source( string $source ): MigrationInventoryReport {
		if ( 'wpml' === $source ) {
			return $this->wpml_analyzer->analyze();
		}

		if ( 'polylang' === $source ) {
			return $this->polylang_analyzer->analyze();
		}

		return new MigrationInventoryReport(
			array(
				'source'      => $source,
				'source_name' => ucfirst( $source ),
				'status'      => 'blocked',
				'notes'       => array( "Fuente de migración desconocida: '{$source}'." ),
			)
		);
	}

	/**
	 * Detect all available migration sources with summary counts.
	 *
	 * @return array<string, array{available: bool, name: string, details: array<string, mixed>}>
	 */
	public function get_available_sources(): array {
		return $this->detector->detect_sources();
	}
}
