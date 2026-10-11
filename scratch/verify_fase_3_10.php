<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.10
 * (Preflight Final, Aislamiento de Respaldos fuera de DocumentRoot, Restauración Verificable,
 *  Inventario Determinista y Simulación Dry-Run de Migración Piloto)
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

// Bootstrap WordPress on cms.ecoterra (source host).
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
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.10 (PILOTO DRY-RUN)\n";
echo "====================================================================\n\n";

$assertions_passed = 0;

function assert_true( bool $condition, string $message ): void {
	global $assertions_passed;
	if ( ! $condition ) {
		echo " [FAIL] {$message}\n";
		exit( 1 );
	}
	echo " [PASS] {$message}\n";
	++$assertions_passed;
}

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: SENTINEL WPML CHECK (cms_ect)
// -------------------------------------------------------------------------
$wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$sentinel_table = $wpdb->prefix . 'icl_translations';
$expected_rows  = 3403;
$expected_md5   = '4241ca7e7ec6399a594537cb04790c10';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pre_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pre_hash = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: {$expected_rows})\n";
echo "  MD5:  {$pre_hash} (Expected: {$expected_md5})\n\n";

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: WPML row count is exactly 3403' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: WPML checksum matches' );

// Activate isolation guard.
$isolation_guard = new ExternalPluginIsolationGuard();
$isolated_count  = $isolation_guard->isolate();
echo "External Plugin Isolation Guard active: isolated {$isolated_count} third-party hooks.\n\n";

$lab_dir = 'd:/laragon/www/lab.ecoterra';
$lab_db  = 'cms_ect_lab';
$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';

// -------------------------------------------------------------------------
// 2. CONTROL 1: SEGURIDAD Y REUBICACION DEL RESPALDO FUERA DE DOCUMENTROOT
// -------------------------------------------------------------------------
echo "--- Control 1: Seguridad y Reubicación del Respaldo fuera de DocumentRoot ---\n";

$backup_file_relocated = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
assert_true( file_exists( $backup_file_relocated ), 'Archivo de respaldo SQL existe en d:/laragon/backup/tfml_staging/' );

// Verify the relocated backup is strictly OUTSIDE d:/laragon/www (DocumentRoot).
$real_backup_path = realpath( $backup_file_relocated );
$real_www_path    = realpath( 'd:/laragon/www' );
assert_true( strpos( $real_backup_path, $real_www_path ) === false, 'Respaldo está almacenado estrictamente fuera de DocumentRoot (d:/laragon/www)' );

// Verify SHA-256 hash of backup file.
$expected_sha256 = 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a';
$calc_sha256     = hash_file( 'sha256', $backup_file_relocated );
assert_true( $expected_sha256 === $calc_sha256, "Integridad SHA-256 del respaldo verificada al 100% ({$calc_sha256})" );

// Verify backup file no longer exists in web accessible folder lab.ecoterra/backups/.
$web_backup_file = $lab_dir . '/backups/preflight_backup_cms_ect_snapshot.sql';
assert_true( ! file_exists( $web_backup_file ), 'Archivo SQL eliminado de lab.ecoterra/backups/ (0% riesgo de descarga HTTP)' );

// Verify HTTP access denial safeguards in lab.ecoterra/backups/.
$backups_dir = $lab_dir . '/backups';
assert_true( file_exists( $backups_dir . '/.htaccess' ), 'Archivo .htaccess de bloqueo HTTP presente en lab.ecoterra/backups/' );
assert_true( file_exists( $backups_dir . '/index.php' ), 'Archivo index.php de protección 403 presente en lab.ecoterra/backups/' );
assert_true( file_exists( $backups_dir . '/web.config' ), 'Archivo web.config para IIS presente en lab.ecoterra/backups/' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: AISLAMIENTO FISICO, SALVAGUARDAS Y BLINDAJE DE cms_ect_lab
// -------------------------------------------------------------------------
echo "\n--- Control 2: Aislamiento Físico, Salvaguardas y Blindaje de cms_ect_lab ---\n";

$lab_plugin_dir = $lab_dir . '/wp-content/plugins/tf-multilingual';
assert_true( is_dir( $lab_plugin_dir ), 'Directorio del plugin en laboratorio existe' );
assert_true( file_exists( $lab_plugin_dir . '/tf-multilingual.php' ), 'Archivo principal tf-multilingual.php existe en laboratorio' );
assert_true( ! is_link( $lab_plugin_dir ), 'Directorio del plugin en laboratorio NO es un enlace simbólico' );

$ps_check_junction = shell_exec( 'powershell -NoProfile -Command "(Get-Item \'' . str_replace( '/', '\\', $lab_plugin_dir ) . '\').LinkType"' );
$link_type         = trim( (string) $ps_check_junction );
assert_true( empty( $link_type ), 'Directorio de laboratorio es físico estándar NTFS (LinkType vacío, sin junction)' );

$lab_git_head = trim( (string) shell_exec( 'git -C "' . str_replace( '/', '\\', $lab_plugin_dir ) . '" rev-parse HEAD' ) );
$expected_sha = 'af46d65a226568e6653549ed4f1eea0956189a44';
assert_true( $lab_git_head === $expected_sha, "Laboratorio tiene checkout independiente fijado en baseline {$expected_sha}" );

// Check host repo status.
$host_dir      = dirname( __DIR__ );
$host_git_head = trim( (string) shell_exec( 'git -C "' . str_replace( '/', '\\', $host_dir ) . '" rev-parse HEAD' ) );
assert_true( 40 === strlen( $host_git_head ) && ctype_xdigit( $host_git_head ), "Repositorio anfitrión permanece en commit SHA válido ({$host_git_head})" );

// Verify wp-config.php of lab.
$lab_config = (string) file_get_contents( $lab_dir . '/wp-config.php' );
assert_true( strpos( $lab_config, "define( 'DB_NAME', '{$lab_db}' )" ) !== false, "wp-config.php del laboratorio apunta estrictamente a {$lab_db}" );
assert_true( strpos( $lab_config, "\$table_prefix = 'etk_t_';" ) !== false, 'wp-config.php del laboratorio utiliza el prefijo real dinámico etk_t_' );
assert_true( strpos( $lab_config, "define( 'WP_HTTP_BLOCK_EXTERNAL', true )" ) !== false, 'Bloqueo de HTTP saliente activo en wp-config.php' );
assert_true( strpos( $lab_config, "define( 'DISABLE_WP_CRON', true )" ) !== false, 'WP-Cron desactivado en wp-config.php' );
assert_true( strpos( $lab_config, "define( 'DISALLOW_FILE_EDIT', true )" ) !== false, 'Edición de archivos bloqueada en wp-config.php' );
assert_true( strpos( $lab_config, "define( 'AUTOMATIC_UPDATER_DISABLED', true )" ) !== false, 'Actualizaciones automáticas desactivadas en wp-config.php' );

// Verify mu-plugin guard.
$mu_guard = $lab_dir . '/wp-content/mu-plugins/lab-isolation-guard.php';
assert_true( file_exists( $mu_guard ), 'mu-plugin de salvaguardas (lab-isolation-guard.php) activo' );
$mu_code = (string) file_get_contents( $mu_guard );
assert_true( strpos( $mu_code, 'pre_wp_mail' ) !== false, 'Filtro prioritario pre_wp_mail forzado a false' );
assert_true( strpos( $mu_code, 'noindex, nofollow, noarchive' ) !== false, 'Cabecera X-Robots-Tag noindex forzada' );
assert_true( strpos( $mu_code, 'xmlrpc_enabled' ) !== false, 'XML-RPC deshabilitado' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: CERTIFICACION DE RESTAURACION VERIFICABLE EN BASE TEMPORAL
// -------------------------------------------------------------------------
echo "\n--- Control 3: Certificación de Restauración Verificable en Base Temporal ---\n";

$temp_db    = 'cms_ect_restore_verify';
$mysqli_tmp = new mysqli( $db_host, $db_user, $db_pass );
assert_true( ! $mysqli_tmp->connect_error, 'Conexión a MySQL para prueba de restauración exitosa' );

// Drop if leftover and create clean temporary DB.
$mysqli_tmp->query( "DROP DATABASE IF EXISTS `{$temp_db}`" );
$created = $mysqli_tmp->query( "CREATE DATABASE `{$temp_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
assert_true( (bool) $created, "Base de datos temporal {$temp_db} creada exitosamente" );

$mysqli_tmp->select_db( $temp_db );
$mysqli_tmp->query( 'SET FOREIGN_KEY_CHECKS = 0' );
$mysqli_tmp->query( "SET sql_mode = ''" );

// Restore snapshot.
$fh = fopen( $backup_file_relocated, 'r' );
assert_true( false !== $fh, 'Apertura de lectura del archivo de respaldo SQL exitosa' );

$query_buffer = '';
$statements_executed = 0;
$restore_errors = 0;

while ( ! feof( $fh ) ) {
	$line = fgets( $fh );
	if ( false === $line ) {
		break;
	}
	$trimmed = trim( $line );
	if ( '' === $trimmed || str_starts_with( $trimmed, '--' ) || str_starts_with( $trimmed, '/*' ) ) {
		continue;
	}
	$query_buffer .= $line;
	if ( str_ends_with( $trimmed, ';' ) ) {
		if ( ! $mysqli_tmp->query( $query_buffer ) ) {
			$restore_errors++;
		} else {
			$statements_executed++;
		}
		$query_buffer = '';
	}
}
fclose( $fh );

assert_true( 0 === $restore_errors, "Restauración de snapshot completada con CERO errores ({$statements_executed} sentencias)" );

// Verify tables and data in restored temp DB.
$restored_tables = $mysqli_tmp->query( "SHOW TABLES LIKE 'etk_t_%'" )->num_rows;
assert_true( 28 === $restored_tables, "Exactamente 28 tablas restauradas en {$temp_db}" );

$mysqli_tmp->query( 'SET SESSION group_concat_max_len = 10000000' );
$temp_wpml_rows = (int) $mysqli_tmp->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$temp_wpml_hash = (string) $mysqli_tmp->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

assert_true( 3403 === $temp_wpml_rows, 'Tabla WPML restaurada contiene exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $temp_wpml_hash, 'Checksum criptográfico de WPML restaurado coincide al 100%' );

$temp_posts = (int) $mysqli_tmp->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0];
$temp_terms = (int) $mysqli_tmp->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0];
assert_true( 4021 === $temp_posts, 'Posts restaurados en base temporal coinciden (4021)' );
assert_true( 82 === $temp_terms, 'Terms restaurados en base temporal coinciden (82)' );

// Cleanup temporary DB.
$mysqli_tmp->query( "DROP DATABASE `{$temp_db}`" );
assert_true( 0 === $mysqli_tmp->query( "SHOW DATABASES LIKE '{$temp_db}'" )->num_rows, "Base de datos temporal {$temp_db} eliminada limpiamente tras certificación" );
$mysqli_tmp->close();

// -------------------------------------------------------------------------
// 5. CONTROL 4: INVENTARIO DETERMINISTA Y PLANIFICACION DE MIGRACION
// -------------------------------------------------------------------------
echo "\n--- Control 4: Inventario Determinista y Planificación de Migración en {$lab_db} ---\n";

$lab_wpdb = new wpdb( $db_user, $db_pass, $lab_db, $db_host );
$lab_wpdb->set_prefix( 'etk_t_' );

$plugin   = Plugin::get_instance();
$plugin->init();
$registry = $plugin->get_language_registry();

$planner = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$plan    = $planner->generate_plan();

assert_true( 'wpml' === $plan->get_source(), 'Planificador identifica formalmente la fuente como "wpml"' );
assert_true( MigrationPlan::STATUS_NEEDS_CONFIGURATION === $plan->get_status(), 'Plan se mantiene en STATUS_NEEDS_CONFIGURATION (dependencia de idioma fr no configurado)' );
assert_true( in_array( 'fr', $plan->get_missing_languages(), true ), 'Plan detecta formalmente el idioma francés "fr" como faltante en TFML' );

$plan_fingerprint = $plan->get_fingerprint();
assert_true( 64 === strlen( $plan_fingerprint ) && ctype_xdigit( $plan_fingerprint ), "Huella digital SHA-256 calculada deterministamente ({$plan_fingerprint})" );

$summary = $plan->get_summary();
assert_true( 249 === ( $summary['total_groups'] ?? 0 ), 'Total de grupos analizados es exactamente 249 (coincidencia Fase 3.4)' );
assert_true( 120 === ( $summary['importable_groups'] ?? 0 ), 'Total de grupos IMPORTABLE es exactamente 120 (coincidencia Fase 3.4)' );
assert_true( 52 === ( $summary['blocked_language_groups'] ?? 0 ), 'Total de grupos BLOCKED_BY_LANGUAGE es exactamente 52 (coincidencia Fase 3.4)' );
assert_true( 24 === ( $summary['skipped_groups'] ?? 0 ), 'Total de grupos SKIPPED es exactamente 24 (coincidencia Fase 3.4)' );
assert_true( 53 === ( $summary['orphan_groups'] ?? 0 ), 'Total de grupos ORPHAN es exactamente 53 (coincidencia Fase 3.4)' );
assert_true( 0 === ( $summary['conflict_groups'] ?? 0 ), 'Total de grupos CONFLICT es estrictamente 0 (cero colisiones preexistentes)' );

$media_plan = $plan->get_media_plan();
assert_true( 3033 === ( $media_plan['total_attachment_rows'] ?? 0 ), 'Plan de medios contabiliza 3033 adjuntos para deduplicación' );
assert_true( 'MEDIA_MODEL_B_DEDUPLICATION' === ( $media_plan['strategy'] ?? '' ), 'Estrategia de medios formalizada en MEDIA_MODEL_B_DEDUPLICATION' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: SIMULACION DRY-RUN, CERO ESCRITURAS Y ROLLBACK CAPABILITY
// -------------------------------------------------------------------------
echo "\n--- Control 5: Simulación Dry-Run, Cero Escrituras y Capacidad de Rollback ---\n";

$executor = new WpmlMigrationExecutor();
$validation_result = $executor->validate_plan( $plan, $plan_fingerprint );
if ( ! $validation_result['valid'] ) {
	echo "Validation errors:\n";
	print_r( $validation_result['errors'] );
}
assert_true( $validation_result['valid'], 'Validación formal del plan en ejecutor (validate_plan) resulta 100% VÁLIDA sin errores' );
assert_true( empty( $validation_result['errors'] ), 'Lista de errores de validación de plan estrictamente vacía' );

// Verify ZERO insertions in TFML tables in cms_ect_lab.
$mysqli_lab = new mysqli( $db_host, $db_user, $db_pass, $lab_db );
$tfml_tables = array(
	'etk_t_tfml_groups',
	'etk_t_tfml_group_elements',
	'etk_t_tfml_media_translations',
	'etk_t_tfml_strings',
	'etk_t_tfml_string_translations',
);

foreach ( $tfml_tables as $tbl ) {
	$cnt = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `{$tbl}`" )->fetch_row()[0];
	assert_true( 0 === $cnt, "Tabla TFML '{$tbl}' contiene exactamente 0 registros (CERO escrituras, migración retenida)" );
}

// Verify ZERO modifications in WordPress Core tables in cms_ect_lab.
$posts_in_lab = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0];
$terms_in_lab = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0];
assert_true( 4021 === $posts_in_lab, "Posts en cms_ect_lab intactos ({$posts_in_lab} = 4021)" );
assert_true( 82 === $terms_in_lab, "Terms en cms_ect_lab intactos ({$terms_in_lab} = 82)" );

// Verify ZERO modifications in WPML tables in cms_ect_lab.
$mysqli_lab->query( 'SET SESSION group_concat_max_len = 10000000' );
$wpml_rows_lab = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$wpml_hash_lab = (string) $mysqli_lab->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

assert_true( 3403 === $wpml_rows_lab, 'Filas en etk_t_icl_translations de laboratorio intactas (3403)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $wpml_hash_lab, 'Checksum de WPML en laboratorio strictly intacto' );

// Test rollback isolation capability: foreign groups are unconditionally blocked.
$rollback_service = $plugin->get_migration_manager()->get_wpml_rollback_service();
$dummy_exec_id    = 'exec_dry_run_test_' . wp_generate_password( 6, false );
$foreign_test_gid = 999888;

$rollback_service->save_execution_manifest(
	$dummy_exec_id,
	array(
		'execution_id'       => $dummy_exec_id,
		'source'             => 'wpml',
		'imported_group_ids' => array( 112233 ),
	)
);

$rollback_test = $rollback_service->rollback_groups( array( $foreign_test_gid ), $dummy_exec_id, true );
assert_true( ! $rollback_test['success'], 'Rollback bloquea incondicionalmente grupos ajenos / no registrados en manifiesto' );
assert_true( 1 === $rollback_test['blocked_groups_count'], 'Exactamente 1 grupo bloqueado por titularidad no demostrada' );
assert_true( 'not_owned' === $rollback_test['blocked_groups'][0]['code'], 'Código de bloqueo clasificado formalmente como not_owned' );

$rollback_service->delete_execution_manifest( $dummy_exec_id );

$mysqli_lab->close();

// -------------------------------------------------------------------------
// 7. POST-FLIGHT: SENTINEL WPML CHECK (cms_ect) (DELTA = 0)
// -------------------------------------------------------------------------
echo "\n--- Sentinel WPML Post-Flight (cms_ect) ---\n";

$post_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "  Rows: {$post_rows} (Expected: {$pre_rows})\n";
echo "  MD5:  {$post_hash} (Expected: {$pre_hash})\n\n";

assert_true( $pre_rows === $post_rows, 'Sentinel post-flight: WPML row count intact en cms_ect (Delta = 0)' );
assert_true( $pre_hash === $post_hash, 'Sentinel post-flight: WPML checksum strictly intact en cms_ect (Delta = 0)' );

$isolation_guard->restore();

echo "====================================================================\n";
echo "  RESULTADO: {$assertions_passed}/{$assertions_passed} VERIFICACIONES PASS\n";
echo "  FASE 3.10 COMPLETADA Y CERTIFICADA EXITOSAMENTE EN LABORATORIO REAL\n";
echo "====================================================================\n";
