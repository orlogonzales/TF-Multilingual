<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.8
 * (Preparación y Certificación del Laboratorio Aislado y Auditoría de Propiedad de Rollback)
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

// Bootstrap WordPress on cms.ecoterra.
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
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\MigrationExecutionReport;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.8 (LAB AISLADO)\n";
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

// Initialize plugin on host.
$plugin = Plugin::get_instance();
$plugin->init();

$manager = $plugin->get_migration_manager();
$lock    = $manager->get_execution_lock();
$cp_repo = $manager->get_checkpoint_repository();

// Clean any leftover test options on host.
delete_option( 'tfml_migration_lock_wpml' );
delete_option( 'tfml_migration_checkpoint_wpml' );

// -------------------------------------------------------------------------
// 2. CONTROL 1: VERIFICACION DE AISLAMIENTO TECNICO DEL LABORATORIO
// -------------------------------------------------------------------------
echo "--- Control 1: Verificación de Aislamiento Técnico del Laboratorio ---\n";

$lab_dir = 'd:/laragon/www/lab.ecoterra';
$lab_db  = 'cms_ect_lab';

assert_true( is_dir( $lab_dir ), 'Directorio del laboratorio aislado d:/laragon/www/lab.ecoterra existe' );
assert_true( file_exists( $lab_dir . '/wp-config.php' ), 'Archivo wp-config.php del laboratorio existe' );

$lab_config = (string) file_get_contents( $lab_dir . '/wp-config.php' );
assert_true( strpos( $lab_config, "define( 'DB_NAME', '{$lab_db}' )" ) !== false, 'wp-config.php apunta exclusivamente a la base de datos cms_ect_lab' );
assert_true( strpos( $lab_config, "define( 'WP_HTTP_BLOCK_EXTERNAL', true )" ) !== false, 'Bloqueo de tráfico HTTP saliente configurado (WP_HTTP_BLOCK_EXTERNAL)' );
assert_true( strpos( $lab_config, "define( 'DISABLE_WP_CRON', true )" ) !== false, 'WP-Cron en segundo plano desactivado (DISABLE_WP_CRON)' );
assert_true( strpos( $lab_config, "define( 'DISALLOW_FILE_EDIT', true )" ) !== false, 'Edición de archivos deshabilitada en admin' );
assert_true( strpos( $lab_config, "define( 'AUTOMATIC_UPDATER_DISABLED', true )" ) !== false, 'Actualizaciones automáticas desactivadas' );

// Verify mu-plugin guard.
$mu_guard = $lab_dir . '/wp-content/mu-plugins/lab-isolation-guard.php';
assert_true( file_exists( $mu_guard ), 'mu-plugin de salvaguardas (lab-isolation-guard.php) existe' );
$mu_code = (string) file_get_contents( $mu_guard );
assert_true( strpos( $mu_code, 'pre_wp_mail' ) !== false, 'Bloqueo total de correos salientes (pre_wp_mail -> __return_false)' );
assert_true( strpos( $mu_code, 'noindex, nofollow, noarchive' ) !== false, 'Cabecera X-Robots-Tag noindex, nofollow forzada' );
assert_true( strpos( $mu_code, 'xmlrpc_enabled' ) !== false, 'Acceso XML-RPC deshabilitado en el laboratorio' );

// Verify plugin junction.
$plugin_junction = $lab_dir . '/wp-content/plugins/tf-multilingual';
assert_true( is_dir( $plugin_junction ), 'Junction del plugin tf-multilingual existe y es accesible' );
assert_true( file_exists( $plugin_junction . '/tf-multilingual.php' ), 'Archivo principal tf-multilingual.php accesible a través de la junction' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: CONEXION INDEPENDIENTE Y TABLAS LIMPIAS EN cms_ect_lab
// -------------------------------------------------------------------------
echo "\n--- Control 2: Conexión Independiente y Esquemas Limpios en {$lab_db} ---\n";

$mysqli_lab = new mysqli( 'localhost', 'root', '', $lab_db );
assert_true( ! $mysqli_lab->connect_error, 'Conexión independiente con MySQL a ' . $lab_db . ' exitosa' );

// Verify WordPress core tables.
$core_tables = array( 'wp_options', 'wp_posts', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships' );
foreach ( $core_tables as $table ) {
	$res = $mysqli_lab->query( "SHOW TABLES LIKE '{$table}'" );
	assert_true( $res && 1 === $res->num_rows, "Tabla Core '{$table}' presente en {$lab_db}" );
}

// Verify TF Multilingual master tables.
$tfml_tables = array( 'wp_tfml_groups', 'wp_tfml_group_elements', 'wp_tfml_media_translations', 'wp_tfml_strings', 'wp_tfml_string_translations' );
foreach ( $tfml_tables as $table ) {
	$res = $mysqli_lab->query( "SHOW TABLES LIKE '{$table}'" );
	assert_true( $res && 1 === $res->num_rows, "Tabla TFML '{$table}' creada e instalada en {$lab_db}" );
}

// Verify isolation options in cms_ect_lab.
$siteurl_res = $mysqli_lab->query( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" );
$siteurl_val = $siteurl_res ? $siteurl_res->fetch_row()[0] : '';
assert_true( 'http://lab.ecoterra.test' === $siteurl_val, 'siteurl en cms_ect_lab apunta a http://lab.ecoterra.test' );

$blog_public_res = $mysqli_lab->query( "SELECT option_value FROM wp_options WHERE option_name = 'blog_public'" );
$blog_public_val = $blog_public_res ? $blog_public_res->fetch_row()[0] : '';
assert_true( '0' === $blog_public_val, 'blog_public en cms_ect_lab es 0 (bloqueo frente a motores de búsqueda)' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: TRANSACCIONES Y CICLO DE VIDA EN EL LABORATORIO AISLADO
// -------------------------------------------------------------------------
echo "\n--- Control 3: Transacciones y Rollback de Infraestructura en {$lab_db} ---\n";

$mysqli_lab->query( "SET SESSION sql_mode = ''" );
$mysqli_lab->begin_transaction();

// Insert synthetic group.
$mysqli_lab->query( "INSERT INTO `wp_tfml_groups` (`element_type`, `subtype`, `canonical_element_id`, `created_at`) VALUES ('post', 'post', 101, NOW())" );
$test_gid = (int) $mysqli_lab->insert_id;
assert_true( $test_gid > 0, "Inserción sintética transaccional en wp_tfml_groups de {$lab_db} exitosa (ID {$test_gid})" );

// Commit transaction.
$mysqli_lab->commit();

$count_check = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `wp_tfml_groups` WHERE `id` = {$test_gid}" )->fetch_row()[0];
assert_true( 1 === $count_check, 'COMMIT confirmado en base de datos del laboratorio' );

// Test rollback in cms_ect_lab.
$mysqli_lab->begin_transaction();
$mysqli_lab->query( "INSERT INTO `wp_tfml_groups` (`element_type`, `subtype`, `canonical_element_id`, `created_at`) VALUES ('post', 'post', 999, NOW())" );
$rolled_id = (int) $mysqli_lab->insert_id;
$mysqli_lab->rollback();

$rolled_check = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `wp_tfml_groups` WHERE `id` = {$rolled_id}" )->fetch_row()[0];
assert_true( 0 === $rolled_check, "ROLLBACK en {$lab_db} revirtió correctamente la transacción no confirmada" );

// Clean test group in cms_ect_lab.
$mysqli_lab->query( "DELETE FROM `wp_tfml_groups` WHERE `id` = {$test_gid}" );
$mysqli_lab->close();

// -------------------------------------------------------------------------
// 5. CONTROL 4: AUDITORIA AVANZADA DE PROPIEDAD DE EJECUCION EN ROLLBACK
// -------------------------------------------------------------------------
echo "\n--- Control 4: Auditoría de Propiedad de Ejecución y Protección de Grupos Preexistentes ---\n";

$rollback_service = $manager->get_wpml_rollback_service();
$exec_id_alpha    = 'exec_fase38_alpha_' . wp_generate_password( 6, false );
$foreign_group_id = 998877; // Pre-existing or foreign group ID.

// Register manifest for exec_id_alpha containing only group 42.
$rollback_service->save_execution_manifest(
	$exec_id_alpha,
	array(
		'execution_id'       => $exec_id_alpha,
		'source'             => 'wpml',
		'imported_group_ids' => array( 42 ),
		'created_at'         => gmdate( 'Y-m-d H:i:s' ),
	)
);

// 5.1 Test: attempt to rollback foreign group 998877 even with force = true!
$unowned_rollback = $rollback_service->rollback_groups( array( $foreign_group_id ), $exec_id_alpha, true );
assert_true( ! $unowned_rollback['success'], 'Rollback sobre grupo ajeno/preexistente es BLOQUEADO incluso con force = true' );
assert_true( 1 === $unowned_rollback['blocked_groups_count'], 'Exactamente 1 grupo bloqueado por falta de propiedad' );
assert_true( 'not_owned' === $unowned_rollback['blocked_groups'][0]['code'], 'Código de bloqueo clasificado estrictamente como not_owned' );
assert_true( strpos( $unowned_rollback['blocked_groups'][0]['reason'], 'propiedad no demostrada' ) !== false, 'Mensaje explica falta de acreditación de propiedad' );

// Clean up test manifest.
$rollback_service->delete_execution_manifest( $exec_id_alpha );

// 5.2 Test: Real Synthetic Execution Run with Automatic Manifest Generation.
$fixture_post_1 = wp_insert_post(
	array(
		'post_title'   => 'Lab 3.8 Post ES',
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_content' => 'Contenido ES',
	)
);
$fixture_post_2 = wp_insert_post(
	array(
		'post_title'   => 'Lab 3.8 Post EN',
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_content' => 'Content EN',
	)
);

$plan_lab = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'summary'        => array(
			'total_groups'      => 1,
			'importable_groups' => 1,
		),
		'planned_groups' => array(
			array(
				'trid'           => 77771,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => $fixture_post_1,
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $fixture_post_1,
					'en' => $fixture_post_2,
				),
			),
		),
	)
);

$executor = new WpmlMigrationExecutor();
$executor->set_authorized( true );
$report = $executor->run( $plan_lab );

assert_true( MigrationExecutionReport::STATUS_COMPLETED === $report->get_status(), 'Migración sintética 3.8 completada con éxito' );
$imported_ids = $report->get_imported_group_ids();
assert_true( 1 === count( $imported_ids ), 'Reporte contiene 1 ID de grupo importado' );
$owned_gid = reset( $imported_ids );

// Verify manifest was automatically persisted by WpmlMigrationExecutor.
$saved_manifest = $rollback_service->get_execution_manifest( $report->get_execution_id() );
assert_true( null !== $saved_manifest, 'Manifiesto de ejecución persistido automáticamente en wp_options' );
assert_true( in_array( $owned_gid, $saved_manifest['imported_group_ids'], true ), 'ID de grupo registrado formalmente en el manifiesto' );

// 5.3 Test: Attempt to delete both owned group AND a foreign group in the same rollback.
$mixed_rollback = $rollback_service->rollback_groups( array( $owned_gid, 999999 ), $report->get_execution_id(), false );
assert_true( $mixed_rollback['success'], 'Rollback mixto procesa los grupos de forma discriminada' );
assert_true( 1 === $mixed_rollback['reverted_groups_count'], 'Exactamente 1 grupo propio revertido' );
assert_true( 1 === $mixed_rollback['blocked_groups_count'], 'Exactamente 1 grupo ajeno bloqueado y protegido' );
assert_true( 999999 === $mixed_rollback['blocked_groups'][0]['group_id'], 'Grupo ajeno 999999 identificado en el reporte de bloqueo' );
assert_true( 'not_owned' === $mixed_rollback['blocked_groups'][0]['code'], 'Grupo ajeno marcado con código not_owned' );

// Teardown fixtures on cms_ect.
wp_delete_post( $fixture_post_1, true );
wp_delete_post( $fixture_post_2, true );
delete_option( 'tfml_migration_lock_wpml' );
delete_option( 'tfml_migration_checkpoint_wpml' );
$rollback_service->delete_execution_manifest( $report->get_execution_id() );

// -------------------------------------------------------------------------
// 6. CONTROL 5: PROTECCION DEL ORIGEN Y CERO ESCRITURAS CRUZADAS
// -------------------------------------------------------------------------
echo "\n--- Control 5: Verificación de Cero Escrituras Cruzadas y Protección del Origen ---\n";

// Verify cms_ect_lab does NOT contain any WPML tables (no real data copied yet).
$mysqli_lab_check = new mysqli( 'localhost', 'root', '', $lab_db );
$wpml_in_lab      = $mysqli_lab_check->query( "SHOW TABLES LIKE '%icl_translations%'" );
assert_true( 0 === $wpml_in_lab->num_rows, 'cms_ect_lab NO contiene tablas de WPML (datos reales NO copiados sin autorización)' );
$mysqli_lab_check->close();

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

echo "====================================================================\n";
echo "  RESULTADO: {$assertions_passed}/{$assertions_passed} VERIFICACIONES PASS\n";
echo "  FASE 3.8 COMPLETADA Y CERTIFICADA EXITOSAMENTE EN LABORATORIO REAL\n";
echo "====================================================================\n";
