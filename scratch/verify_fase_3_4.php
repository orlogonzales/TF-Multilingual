<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.4
 * (Planificador y Motor de Simulación Determinista de Migración WPML → TFML)
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
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.4 (PLANIFICADOR)\n";
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

// Initialize plugin.
$plugin            = Plugin::get_instance();
$plugin->init();
$migration_manager = $plugin->get_migration_manager();
$wpml_planner      = $migration_manager->get_wpml_planner();

echo "\n--- BLOQUE 1: GENERACION DETERMINISTA DEL PLAN DE MIGRACION ---\n";
$plan = $wpml_planner->generate_plan();

assert_test( $plan->get_source() === 'wpml', 'Plan: Fuente identificada como WPML' );
assert_test( $plan->get_status() === MigrationPlan::STATUS_NEEDS_CONFIGURATION, 'Plan: Estado NEEDS_CONFIGURATION debido a idioma fr pendiente' );
assert_test( in_array( 'fr', $plan->get_missing_languages(), true ), 'Plan: Dependencia lingüística fr detectada formalmente' );

$summary = $plan->get_summary();
echo "Resumen del plan generado:\n";
echo "  Total grupos analizados: {$summary['total_groups']}\n";
echo "  Grupos IMPORTABLE:       {$summary['importable_groups']}\n";
echo "  Grupos BLOCKED_BY_LANG:  {$summary['blocked_language_groups']}\n";
echo "  Grupos SKIPPED:          {$summary['skipped_groups']}\n";
echo "  Grupos ORPHAN:           {$summary['orphan_groups']}\n";
echo "  Grupos CONFLICT:         {$summary['conflict_groups']}\n";
echo "  Elementos pendientes fr: {$summary['pending_elements']}\n\n";

assert_test( $summary['total_groups'] === 249, 'Plan: Los 249 grupos editoriales de WPML fueron planificados' );
assert_test( $summary['importable_groups'] > 0, 'Plan: Se identificaron grupos IMPORTABLE (es + en)' );
assert_test( $summary['blocked_language_groups'] > 0, 'Plan: Se identificaron grupos bloqueados por dependencia de idioma fr' );
assert_test( $summary['pending_elements'] > 0, 'Plan: Elementos franceses retenidos sin descarte silencioso' );
assert_test( $summary['skipped_groups'] > 0, 'Plan: Elementos no soportados (comments, priority) clasificados como SKIPPED' );

echo "\n--- BLOQUE 2: ESTRATEGIA MEDIA MODEL B REFINADO ---\n";
$media_plan = $plan->get_media_plan();
assert_test( $media_plan['total_attachment_rows'] === 3033, 'Media Plan: 3033 adjuntos contabilizados para deduplicación' );
assert_test( $media_plan['strategy'] === 'MEDIA_MODEL_B_DEDUPLICATION', 'Media Plan: Estrategia fijada en MEDIA_MODEL_B_DEDUPLICATION' );

echo "\n--- BLOQUE 3: RETENCION LINGUISTICA DE TRADUCCIONES EN FRANCES ---\n";
$blocked_groups = $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE );
assert_test( ! empty( $blocked_groups ), 'Retención: Existen grupos con traducciones francesas preservadas' );

$sample_blocked = $blocked_groups[0];
assert_test( isset( $sample_blocked['pending_translations']['fr'] ), 'Retención: Traducción en fr asignada a pending_translations' );
assert_test( ! empty( $sample_blocked['translations'] ), 'Retención: Traducciones en idiomas activos (es/en) preservadas en translations' );
assert_test( ! empty( $sample_blocked['canonical_id'] ), 'Retención: Elemento canónico resuelto deterministamente' );

echo "\n--- BLOQUE 4: ARQUITECTURA DE CHECKPOINTS E IDEMPOTENCIA ---\n";
$checkpoint = new MigrationCheckpoint(
	array(
		'source'      => 'wpml',
		'total_items' => $summary['total_groups'],
		'batch_size'  => 50,
	)
);

assert_test( $checkpoint->get_total_items() === 249, 'Checkpoint: Total items inicializado a 249' );
assert_test( $checkpoint->get_processed_items() === 0, 'Checkpoint: Processed items inicia en 0' );
assert_test( $checkpoint->get_status() === MigrationCheckpoint::STATUS_PENDING, 'Checkpoint: Estado inicial PENDING' );

// Simulate processing first batch of 50 items.
$step1 = $checkpoint->advance( 50, 105 );
assert_test( $step1->get_processed_items() === 50, 'Checkpoint: Lote 1 avanza a 50 procesados' );
assert_test( $step1->get_last_processed_trid() === 105, 'Checkpoint: Registra TRID 105 como último procesado' );
assert_test( $step1->get_status() === MigrationCheckpoint::STATUS_IN_PROGRESS, 'Checkpoint: Estado conmuta a IN_PROGRESS' );

// Serialization & hydration for persistent storage.
$serialized = $step1->to_array();
$restored   = MigrationCheckpoint::from_array( $serialized );
assert_test( $restored->get_last_processed_trid() === 105, 'Checkpoint: Hidratación determinista desde array' );
assert_test( $restored->get_processed_items() === 50, 'Checkpoint: Mantiene progreso intacto tras serialización' );

echo "\n--- BLOQUE 5: COORDINACION EN MIGRATION MANAGER ---\n";
$managed_plan = $migration_manager->generate_plan( 'wpml', 10 );
assert_test( count( $managed_plan->get_planned_groups() ) === 10, 'MigrationManager: generate_plan respeta paginación por limit (10)' );

echo "\n--- BLOQUE 6: REGRESIONES INTEGRALES DEL CORE (SITE HEALTH DIAGNOSTIC) ---\n";
$diag       = $plugin->get_diagnostic_service();
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
echo "  RESUMEN DE VERIFICACION FASE 3.4\n";
echo "  Aserciones Exitosas: {$assertions_passed}\n";
echo "  Aserciones Fallidas: {$assertions_failed}\n";
echo "====================================================================\n";

if ( $assertions_failed > 0 ) {
	exit( 1 );
}

exit( 0 );
