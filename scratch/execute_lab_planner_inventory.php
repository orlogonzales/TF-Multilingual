<?php
/**
 * WPML Planner & Deterministic Inventory on cms_ect_lab (Strictly Read-Only).
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

// Bootstrap WordPress from cms.ecoterra to load classes.
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/ExternalPluginIsolationGuard.php';

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\MigrationPlan;

$guard = new TF\Multilingual\Scratch\ExternalPluginIsolationGuard();
$guard->isolate();

echo "====================================================================\n";
echo "  TF MULTILINGUAL — FASE 3.10: INVENTARIO Y PLANIFICACION PILOTO\n";
echo "====================================================================\n\n";

$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';
$lab_db  = 'cms_ect_lab';

// Instantiate independent wpdb pointing strictly to cms_ect_lab.
$lab_wpdb = new wpdb( $db_user, $db_pass, $lab_db, $db_host );
$lab_wpdb->set_prefix( 'etk_t_' );

echo "Conectado a laboratorio: {$lab_db} (Prefijo: {$lab_wpdb->prefix})\n";

// Verify WPML table in cms_ect_lab.
$lab_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$sentinel_rows = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}icl_translations`" );
$sentinel_hash = (string) $lab_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$lab_wpdb->prefix}icl_translations`"
);

echo "Sentinel WPML en {$lab_db}:\n";
echo "  Filas: {$sentinel_rows} (Esperado: 3403)\n";
echo "  MD5:   {$sentinel_hash} (Esperado: 4241ca7e7ec6399a594537cb04790c10)\n\n";

if ( 3403 !== $sentinel_rows || '4241ca7e7ec6399a594537cb04790c10' !== $sentinel_hash ) {
	die( "FATAL: Centinela WPML en cms_ect_lab no coincide.\n" );
}

// Initialize plugin to get calibrated LanguageRegistry.
$plugin   = TF\Multilingual\Core\Plugin::get_instance();
$plugin->init();
$registry = $plugin->get_language_registry();

echo "Configuración de idiomas TFML:\n";
echo "  Default: " . ( $registry->get_default() ? $registry->get_default()->get_code() : 'none' ) . "\n";
$active_codes = array_keys( $registry->active() );
echo "  Activos: " . implode( ', ', $active_codes ) . "\n\n";

// Instantiate planner pointing to cms_ect_lab.
$planner = new WpmlMigrationPlanner( $lab_wpdb, $registry );

// Generate plan.
$start_time = microtime( true );
$plan = $planner->generate_plan();
$elapsed = round( microtime( true ) - $start_time, 4 );

echo "Plan generado en {$elapsed} segundos.\n\n";

$summary = $plan->get_summary();
$fingerprint = $plan->get_fingerprint();

echo "--- RESUMEN DEL PLAN DE MIGRACION (cms_ect_lab) ---\n";
echo "Status:                 " . $plan->get_status() . "\n";
echo "Source:                 " . $plan->get_source() . "\n";
echo "Plan Fingerprint:       " . $fingerprint . "\n";
echo "Total grupos analizados: " . ( $summary['total_groups'] ?? 0 ) . "\n";
echo "  - IMPORTABLE:          " . ( $summary['importable_groups'] ?? 0 ) . "\n";
echo "  - BLOCKED_BY_LANGUAGE: " . ( $summary['blocked_language_groups'] ?? 0 ) . "\n";
echo "  - SKIPPED:             " . ( $summary['skipped_groups'] ?? 0 ) . "\n";
echo "  - ORPHAN:              " . ( $summary['orphan_groups'] ?? 0 ) . "\n";
echo "  - CONFLICT:            " . ( $summary['conflict_groups'] ?? 0 ) . "\n";
echo "Total elementos:        " . ( $summary['total_elements'] ?? 0 ) . "\n";
echo "  - Elementos importables: " . ( $summary['importable_elements'] ?? 0 ) . "\n";
echo "  - Elementos pendientes:  " . ( $summary['pending_elements'] ?? 0 ) . "\n\n";

$media_plan = $plan->get_media_plan();
echo "--- PLAN DE MEDIOS (MEDIA MODEL B REFINADO) ---\n";
echo "Total adjuntos en WPML:  " . ( $media_plan['total_attachment_rows'] ?? 0 ) . "\n";
echo "Estrategia:              " . ( $media_plan['strategy'] ?? '' ) . "\n";
echo "Descripción:             " . ( $media_plan['description'] ?? '' ) . "\n\n";

// Breakdown of taxonomies and terms.
echo "--- DETALLE POR SUBTIPO Y TAXONOMIAS ---\n";
$subtype_breakdown = array();
$term_taxonomies = array();
foreach ( $plan->get_planned_groups() as $grp ) {
	$st = $grp['subtype'];
	$et = $grp['element_type'];
	$cl = $grp['classification'];
	$key = "{$et}:{$st}";
	if ( ! isset( $subtype_breakdown[ $key ][ $cl ] ) ) {
		$subtype_breakdown[ $key ][ $cl ] = 0;
	}
	$subtype_breakdown[ $key ][ $cl ]++;
}

foreach ( $subtype_breakdown as $st_key => $class_counts ) {
	echo "  - {$st_key}: " . json_encode( $class_counts ) . "\n";
}

// Compare with historical Phase 3.4.
echo "\n--- COMPARACION HISTORICA: FASE 3.4 vs FASE 3.10 ---\n";
$f34_total      = 249;
$f34_importable = 120;
$f34_blocked    = 52;
$f34_skipped    = 24;
$f34_orphan     = 53;
$f34_conflict   = 0;
$f34_media      = 3033;

$diff_total      = ( $summary['total_groups'] ?? 0 ) - $f34_total;
$diff_importable = ( $summary['importable_groups'] ?? 0 ) - $f34_importable;
$diff_blocked    = ( $summary['blocked_language_groups'] ?? 0 ) - $f34_blocked;
$diff_skipped    = ( $summary['skipped_groups'] ?? 0 ) - $f34_skipped;
$diff_orphan     = ( $summary['orphan_groups'] ?? 0 ) - $f34_orphan;
$diff_conflict   = ( $summary['conflict_groups'] ?? 0 ) - $f34_conflict;
$diff_media      = ( $media_plan['total_attachment_rows'] ?? 0 ) - $f34_media;

echo "  Total Grupos:       " . ( $summary['total_groups'] ?? 0 ) . " vs {$f34_total} (Delta: {$diff_total})\n";
echo "  IMPORTABLE:         " . ( $summary['importable_groups'] ?? 0 ) . " vs {$f34_importable} (Delta: {$diff_importable})\n";
echo "  BLOCKED_BY_LANG:    " . ( $summary['blocked_language_groups'] ?? 0 ) . " vs {$f34_blocked} (Delta: {$diff_blocked})\n";
echo "  SKIPPED:            " . ( $summary['skipped_groups'] ?? 0 ) . " vs {$f34_skipped} (Delta: {$diff_skipped})\n";
echo "  ORPHAN:             " . ( $summary['orphan_groups'] ?? 0 ) . " vs {$f34_orphan} (Delta: {$diff_orphan})\n";
echo "  CONFLICT:           " . ( $summary['conflict_groups'] ?? 0 ) . " vs {$f34_conflict} (Delta: {$diff_conflict})\n";
echo "  Media Adjuntos:     " . ( $media_plan['total_attachment_rows'] ?? 0 ) . " vs {$f34_media} (Delta: {$diff_media})\n\n";

if ( 0 === $diff_total && 0 === $diff_importable && 0 === $diff_blocked && 0 === $diff_skipped && 0 === $diff_orphan && 0 === $diff_conflict && 0 === $diff_media ) {
	echo " [PASS] Coincidencia matemática 100% determinista con la Fase 3.4.\n";
} else {
	echo " [WARN] Existen diferencias frente a Fase 3.4 que requieren investigación.\n";
}

$guard->restore();
