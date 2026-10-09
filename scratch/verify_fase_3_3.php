<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.3
 * (Subsistema de Migración: Detección, Análisis Read-Only e Inventario Diagnóstico de WPML)
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

// Bootstrap WordPress.
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/ExternalPluginIsolationGuard.php';

if ( ! defined( 'TFML_PLUGIN_FILE' ) ) {
	define( 'TFML_PLUGIN_FILE', dirname( __DIR__ ) . '/tf-multilingual.php' );
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

set_exception_handler(
	function( Throwable $e ) {
		echo "\nFATAL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
		exit( 1 );
	}
);

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Migration\MigrationManager;
use TF\Multilingual\Migration\MigrationSourceDetector;
use TF\Multilingual\Migration\WpmlSourceAnalyzer;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.3 (MIGRACION READ-ONLY)\n";
echo "====================================================================\n\n";

$assertions_passed = 0;
$assertions_failed = 0;

function assert_test( bool $condition, string $message ): void {
	global $assertions_passed, $assertions_failed;
	if ( $condition ) {
		echo " [PASS] {$message}\n";
		$assertions_passed++;
	} else {
		echo " [FAIL] {$message}\n";
		$assertions_failed++;
	}
}

// --- WPML SENTINEL PRE-FLIGHT ---
$wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$sentinel_table = $wpdb->prefix . 'icl_translations';
$expected_rows  = 3403;
$expected_md5   = '4241ca7e7ec6399a594537cb04790c10';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pre_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pre_md5  = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Pre-Flight:\n";
echo "  Rows: {$pre_rows} (Expected: {$expected_rows})\n";
echo "  MD5:  {$pre_md5} (Expected: {$expected_md5})\n\n";

assert_test( $pre_rows === $expected_rows, 'Sentinel pre-flight: WPML row count is exactly 3403' );
assert_test( $pre_md5 === $expected_md5, 'Sentinel pre-flight: WPML checksum matches' );

// Activate isolation guard.
$guard = new ExternalPluginIsolationGuard();
$isolated_count = $guard->isolate();
echo "External Plugin Isolation Guard active: isolated {$isolated_count} third-party hooks.\n";

// Instantiate Plugin & MigrationManager.
$plugin = Plugin::get_instance();
$plugin->init();
$migration_manager = $plugin->get_migration_manager();
$detector          = $migration_manager->get_detector();
$wpml_analyzer     = $migration_manager->get_wpml_analyzer();

echo "\n--- BLOQUE 1: DETECCION DE FUENTES EN EL ENTORNO REAL ---\n";
$sources = $migration_manager->get_available_sources();

assert_test( isset( $sources['wpml'] ), 'Detector: Fuente WPML reconocida en catálogo' );
assert_test( true === $sources['wpml']['available'], 'Detector: WPML detectado como DISPONIBLE en la base de datos' );
assert_test( 3403 === $sources['wpml']['details']['total_translations'], 'Detector: 3403 registros de traducción detectados en WPML' );
assert_test( 1541 === $sources['wpml']['details']['total_groups'], 'Detector: 1541 grupos (trids) detectados en WPML' );
assert_test( 22 === $sources['wpml']['details']['tables_count'], 'Detector: 22 tablas de WPML identificadas' );
assert_test( isset( $sources['polylang'] ), 'Detector: Fuente Polylang reconocida en catálogo' );
assert_test( false === $sources['polylang']['available'], 'Detector: Polylang correctamente detectado como ausente' );

echo "\n--- BLOQUE 2: ANALISIS DE SOLO LECTURA DE WPML (INVENTARIO DIAGNOSTICO) ---\n";
$report = $wpml_analyzer->analyze();

assert_test( $report->get_source() === 'wpml', 'Reporte: Fuente identificada como WPML' );
assert_test( $report->get_total_elements() === 3403, 'Reporte: Total de elementos analizados coincide exactamente (3403)' );
assert_test( $report->get_total_groups() === 1541, 'Reporte: Total de trids analizados coincide (1541)' );
assert_test( $report->get_content_groups() === 249, 'Reporte: Grupos de contenido editorial (excluyendo attachments) es 249' );
assert_test( $report->get_attachment_elements() === 3033, 'Reporte: 3033 attachments detectados para gobernanza Media Model B' );

// Languages verification.
$languages = $report->get_languages();
$codes = array_column( $languages, 'code' );
assert_test( in_array( 'es', $codes, true ), 'Reporte: Idioma es detectado en WPML' );
assert_test( in_array( 'en', $codes, true ), 'Reporte: Idioma en detectado en WPML' );
assert_test( in_array( 'fr', $codes, true ), 'Reporte: Idioma fr detectado en WPML' );

// Warning status verification: fr is present in WPML but not yet configured in TFML.
assert_test( $report->get_status() === 'warning', 'Reporte: Estado warning generado por idioma fr pendiente de configurar en TFML' );
assert_test( in_array( 'fr', $report->get_missing_languages(), true ), 'Reporte: fr explícitamente reportado en missing_languages' );

// Breakdown verification.
$types = $report->get_elements_by_type();
assert_test( ( $types['post_page'] ?? 0 ) === 89, 'Reporte: 89 páginas detectadas en WPML' );
assert_test( ( $types['post_tours'] ?? 0 ) === 44, 'Reporte: 44 tours detectados en WPML' );
assert_test( ( $types['post_rooms'] ?? 0 ) === 33, 'Reporte: 33 rooms detectados en WPML' );

echo "\n--- BLOQUE 3: SIMULACION DE MIGRACION DRY-RUN (CERO ESCRITURAS) ---\n";
$sim = $wpml_analyzer->simulate_migration( 20 );

assert_test( $sim['simulated_groups'] === 20, 'Simulación: 20 grupos simulados correctamente' );
assert_test( $sim['simulated_elements'] > 20, 'Simulación: Elementos simulados mayores a conteo de grupos' );

// Check sample simulated group.
$first_group = $sim['groups'][0] ?? null;
assert_test( null !== $first_group, 'Simulación: Estructura de primer grupo simulado obtenida' );
if ( null !== $first_group ) {
	assert_test( $first_group['trid'] > 0, "Simulación: Grupo trid {$first_group['trid']} válido" );
	assert_test( ! empty( $first_group['element_type'] ), "Simulación: Tipo de elemento '{$first_group['element_type']}' asignado" );
	assert_test( ! empty( $first_group['canonical_id'] ), "Simulación: Elemento canónico {$first_group['canonical_id']} resuelto deterministamente" );
	assert_test( ! empty( $first_group['translations'] ), 'Simulación: Diccionario de traducciones por idioma estructurado' );
}

echo "\n--- BLOQUE 4: COORDINACION EN MIGRATION MANAGER ---\n";
$coord_report = $migration_manager->analyze_source( 'wpml' );
assert_test( $coord_report->get_source() === 'wpml', 'MigrationManager: Resuelve y entrega reporte de WPML' );
assert_test( $coord_report->get_total_elements() === 3403, 'MigrationManager: Entrega conteo idéntico de elementos' );

// Unknown source handling.
$unknown_report = $migration_manager->analyze_source( 'non_existent_plugin' );
assert_test( $unknown_report->get_status() === 'blocked', 'MigrationManager: Fuente desconocida tratada de forma segura como blocked' );

echo "\n--- BLOQUE 5: REGRESIONES INTEGRALES DEL CORE (SITE HEALTH DIAGNOSTIC) ---\n";
$diag = $plugin->get_diagnostic_service();
$tables_rep = $diag->get_tables_report();
$lang_rep   = $diag->get_languages_report();
$rel_rep    = $diag->get_relations_integrity_report();
$mod_rep    = $diag->get_modules_report();

assert_test( 'good' === $tables_rep['status'], 'Diagnóstico Core: Tablas maestras en estado good' );
assert_test( 'good' === $lang_rep['status'], 'Diagnóstico Core: Registro de idiomas en estado good' );
assert_test( 0 === $rel_rep['orphaned_canonical_count'], 'Diagnóstico Core: Cero canonicals huérfanos' );
assert_test( 'good' === $mod_rep['status'], 'Diagnóstico Core: Módulos funcionales en estado good' );

// Restore isolation guard.
$guard->restore();
echo "External Plugin Isolation Guard restored.\n";

// --- SENTINEL POST-FLIGHT VERIFICATION ---
echo "\n--- SENTINEL POST-FLIGHT VERIFICATION ---\n";
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$post_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$post_md5  = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);
$delta     = $post_rows - $expected_rows;

echo "Sentinel WPML Post-Flight:\n";
echo "  Rows: {$post_rows} (Delta: {$delta})\n";
echo "  MD5:  {$post_md5}\n\n";

assert_test( $post_rows === $expected_rows, 'Invarianza WPML: Total de registros inalterado (3403)' );
assert_test( $post_md5 === $expected_md5, 'Invarianza WPML: Checksum criptográfico inalterado (4241ca7e7ec6399a594537cb04790c10)' );

echo "\n====================================================================\n";
echo "  RESUMEN DE VERIFICACION FASE 3.3\n";
echo "  Aserciones Exitosas: {$assertions_passed}\n";
echo "  Aserciones Fallidas: {$assertions_failed}\n";
echo "====================================================================\n";

if ( $assertions_failed > 0 ) {
	exit( 1 );
}

exit( 0 );
