<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.11.
 *
 * Directiva: DT-TFML-041
 * Alcance: Preparación de ejecución piloto transaccional (Dry-Run, Gobernanza de Estado,
 *          Plan Piloto Derivado, Trazabilidad Criptográfica, Checkpoints y Rollback).
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/ExternalPluginIsolationGuard.php';

use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;

global $wpdb;

$assertions_passed = 0;

/**
 * Custom assertion helper.
 *
 * @param bool   $condition Condition to assert.
 * @param string $message   Assertion description.
 * @return void
 */
function assert_true( bool $condition, string $message ): void {
	global $assertions_passed;
	if ( ! $condition ) {
		echo " [FAIL] {$message}\n";
		exit( 1 );
	}
	echo " [PASS] {$message}\n";
	++$assertions_passed;
}

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.11 (PILOTO PREP)\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: SENTINEL WPML CHECK (cms_ect)
// -------------------------------------------------------------------------
$sentinel_table = $wpdb->prefix . 'icl_translations';
$wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

$pre_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$pre_hash = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: 3403)\n";
echo "  MD5:  {$pre_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: WPML row count is exactly 3403' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: WPML checksum matches' );

// Isolate 3rd party plugins to prevent interference in testing environment.
$isolation_guard = new TF\Multilingual\Scratch\ExternalPluginIsolationGuard();
$isolation_guard->isolate();

// Setup DB connections to isolated lab.
$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';
$lab_db  = 'cms_ect_lab';

$mysqli_lab = new mysqli( $db_host, $db_user, $db_pass, $lab_db );
assert_true( ! $mysqli_lab->connect_error, "Conexión independiente MySQL a {$lab_db} exitosa" );

$lab_wpdb = new wpdb( $db_user, $db_pass, $lab_db, $db_host );
$lab_wpdb->set_prefix( 'etk_t_' );

$plugin = TF\Multilingual\Core\Plugin::get_instance();
$plugin->init();
$registry = $plugin->get_language_registry();

// -------------------------------------------------------------------------
// 2. CONTROL 1: SEGURIDAD, AISLAMIENTO Y RESPALDO VERIFICADO FUERA DE DOCROOT
// -------------------------------------------------------------------------
echo "\n--- Control 1: Seguridad, Aislamiento y Respaldo Verificado Fuera de DocumentRoot ---\n";

$backup_secure_path = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
$doc_root           = 'd:/laragon/www';
$lab_backups_dir    = 'd:/laragon/www/lab.ecoterra/backups';

assert_true( file_exists( $backup_secure_path ), 'Archivo de respaldo SQL existe en d:/laragon/backup/tfml_staging/' );
assert_true( strpos( realpath( $backup_secure_path ), realpath( $doc_root ) ) === false, 'Respaldo está almacenado estrictamente fuera de DocumentRoot (d:/laragon/www)' );

$backup_sha256 = hash_file( 'sha256', $backup_secure_path );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_sha256, 'Integridad criptográfica SHA-256 del respaldo certificada' );

assert_true( ! file_exists( $lab_backups_dir . '/preflight_backup_cms_ect_snapshot.sql' ), 'Archivo SQL eliminado de lab.ecoterra/backups/ (0% riesgo de exposición HTTP)' );
assert_true( file_exists( $lab_backups_dir . '/.htaccess' ), 'Archivo .htaccess de bloqueo HTTP presente en lab.ecoterra/backups/' );
assert_true( file_exists( $lab_backups_dir . '/index.php' ), 'Archivo index.php de protección 403 presente en lab.ecoterra/backups/' );
assert_true( file_exists( $lab_backups_dir . '/web.config' ), 'Archivo web.config para IIS presente en lab.ecoterra/backups/' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: GOBERNANZA DE ESTADO DEL PLAN MATRIZ (NEEDS_CONFIGURATION)
// -------------------------------------------------------------------------
echo "\n--- Control 2: Gobernanza de Estado del Plan Matriz y Bloqueo de Ejecución Directa ---\n";

$planner   = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$full_plan = $planner->generate_plan();

assert_true( MigrationPlan::STATUS_NEEDS_CONFIGURATION === $full_plan->get_status(), 'Plan matriz permanece en STATUS_NEEDS_CONFIGURATION por idioma fr pendiente' );
assert_true( array( 'fr' ) === $full_plan->get_missing_languages(), 'Plan matriz detecta formalmente el idioma francés "fr" como faltante' );
assert_true( '08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949' === $full_plan->get_fingerprint(), 'Huella digital SHA-256 del plan matriz inmutable e idéntica a Fase 3.4/3.10' );

// Verify executor strictly blocks execution of full plan in STATUS_NEEDS_CONFIGURATION.
$executor = new WpmlMigrationExecutor( $lab_wpdb, null, $registry );
$executor->set_authorized( true );

$full_execution_blocked = false;
try {
	$executor->execute_batch( $full_plan );
} catch ( MigrationPlanValidationException $e ) {
	$full_execution_blocked = true;
	assert_true( strpos( $e->getMessage(), 'NEEDS_CONFIGURATION' ) !== false, 'Mensaje de excepción detalla rechazo por estado NEEDS_CONFIGURATION' );
}
assert_true( $full_execution_blocked, 'WpmlMigrationExecutor::execute_batch() rechaza categóricamente ejecutar plan matriz incompleto' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: CONSTRUCCIÓN Y LINAJE DEL PLAN PILOTO DERIVADO (DT-TFML-041)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Construcción y Linaje Criptográfico del Plan Piloto Derivado ---\n";

// Rejection test 1: Empty list.
$empty_rejected = false;
try {
	$full_plan->derive_partial_plan( array() );
} catch ( InvalidArgumentException $e ) {
	$empty_rejected = true;
}
assert_true( $empty_rejected, 'derive_partial_plan rechaza lista vacía de TRIDs' );

// Rejection test 2: Non-importable group (e.g. TRID blocked by French or orphan).
$non_importable_rejected = false;
try {
	// Find a blocked group TRID from parent plan.
	$blocked_groups = $full_plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE );
	$blocked_trid   = (int) $blocked_groups[0]['trid'];
	$full_plan->derive_partial_plan( array( $blocked_trid ) );
} catch ( InvalidArgumentException $e ) {
	$non_importable_rejected = true;
}
assert_true( $non_importable_rejected, 'derive_partial_plan rechaza incluir grupos no importables (clasificación BLOCKED_BY_LANGUAGE)' );

// Select representative pilot batch (5 groups: 2 terms in tours_cat, 2 posts in tours, 1 page in es).
$pilot_trids = array( 4575, 9238, 9239, 9247, 4338 );
$pilot_plan  = $full_plan->derive_partial_plan( $pilot_trids, 'pilot-batch-1' );

assert_true( $pilot_plan->is_partial(), 'Plan piloto identificado formalmente como parcial (is_partial = true)' );
assert_true( 'pilot-batch-1' === $pilot_plan->get_label(), 'Etiqueta del lote piloto registrada formalmente como "pilot-batch-1"' );
assert_true( MigrationPlan::STATUS_READY === $pilot_plan->get_status(), 'Plan piloto derivado adquiere estado formal STATUS_READY' );
assert_true( empty( $pilot_plan->get_missing_languages() ), 'Plan piloto contiene 0 dependencias de idiomas faltantes' );
assert_true( $full_plan->get_fingerprint() === $pilot_plan->get_parent_fingerprint(), 'Linaje criptográfico verificado: parent_fingerprint coincide exactamente con el plan matriz' );

$pilot_fp = $pilot_plan->get_fingerprint();
assert_true( '30742abbc3af9267c826aef6c24875756e829971df65993553ec75d2aa55f711' === $pilot_fp, 'Huella digital SHA-256 del plan piloto calculada deterministamente (30742abbc...)' );
assert_true( 5 === count( $pilot_plan->get_planned_groups() ), 'Plan piloto contiene exactamente 5 grupos de traducción' );
assert_true( 9 === (int) $pilot_plan->get_summary()['total_elements'], 'Plan piloto contiene exactamente 9 elementos de traducción' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: CORRESPONDENCIA PREVIA DE ELEMENTOS EN CORE Y WPML
// -------------------------------------------------------------------------
echo "\n--- Control 4: Verificación Previa de Correspondencia en WordPress Core y WPML ---\n";

$groups_map = array();
foreach ( $pilot_plan->get_planned_groups() as $g ) {
	$groups_map[ (int) $g['trid'] ] = $g;
}

// 1. Term TRID 4575 (tours_cat) -> 41 (es), 47 (en).
$g_4575 = $groups_map[4575];
assert_true( 'term' === $g_4575['element_type'] && 'tours_cat' === $g_4575['subtype'], 'TRID 4575 corresponde a término tours_cat' );
assert_true( 41 === $g_4575['translations']['es'] && 47 === $g_4575['translations']['en'], 'TRID 4575 mapea exactamente a términos 41 (es) y 47 (en)' );

$term_41_exists = (bool) $mysqli_lab->query( "SELECT 1 FROM `etk_t_term_taxonomy` WHERE term_id = 41 AND taxonomy = 'tours_cat'" )->fetch_row();
$term_47_exists = (bool) $mysqli_lab->query( "SELECT 1 FROM `etk_t_term_taxonomy` WHERE term_id = 47 AND taxonomy = 'tours_cat'" )->fetch_row();
assert_true( $term_41_exists && $term_47_exists, 'Términos Core 41 y 47 existen físicamente en etk_t_term_taxonomy' );

// 2. Term TRID 9238 (tours_cat) -> 46 (es), 48 (en).
$g_9238 = $groups_map[9238];
assert_true( 'term' === $g_9238['element_type'] && 'tours_cat' === $g_9238['subtype'], 'TRID 9238 corresponde a término tours_cat' );
assert_true( 46 === $g_9238['translations']['es'] && 48 === $g_9238['translations']['en'], 'TRID 9238 mapea exactamente a términos 46 (es) y 48 (en)' );

$term_46_exists = (bool) $mysqli_lab->query( "SELECT 1 FROM `etk_t_term_taxonomy` WHERE term_id = 46 AND taxonomy = 'tours_cat'" )->fetch_row();
$term_48_exists = (bool) $mysqli_lab->query( "SELECT 1 FROM `etk_t_term_taxonomy` WHERE term_id = 48 AND taxonomy = 'tours_cat'" )->fetch_row();
assert_true( $term_46_exists && $term_48_exists, 'Términos Core 46 y 48 existen físicamente en etk_t_term_taxonomy' );

// 3. Post TRID 9239 (tours) -> 5286 (es), 5287 (en).
$g_9239 = $groups_map[9239];
assert_true( 'post' === $g_9239['element_type'] && 'tours' === $g_9239['subtype'], 'TRID 9239 corresponde a CPT tours' );
assert_true( 5286 === $g_9239['translations']['es'] && 5287 === $g_9239['translations']['en'], 'TRID 9239 mapea exactamente a posts 5286 (es) y 5287 (en)' );

$post_5286 = $mysqli_lab->query( "SELECT post_type, post_status FROM `etk_t_posts` WHERE ID = 5286" )->fetch_assoc();
$post_5287 = $mysqli_lab->query( "SELECT post_type, post_status FROM `etk_t_posts` WHERE ID = 5287" )->fetch_assoc();
assert_true( 'tours' === $post_5286['post_type'] && 'trash' !== $post_5286['post_status'], 'Post 5286 activo y no en papelera' );
assert_true( 'tours' === $post_5287['post_type'] && 'trash' !== $post_5287['post_status'], 'Post 5287 activo y no en papelera' );

// 4. Post TRID 9247 (tours) -> 5303 (es), 5304 (en).
$g_9247 = $groups_map[9247];
assert_true( 'post' === $g_9247['element_type'] && 'tours' === $g_9247['subtype'], 'TRID 9247 corresponde a CPT tours' );
assert_true( 5303 === $g_9247['translations']['es'] && 5304 === $g_9247['translations']['en'], 'TRID 9247 mapea exactamente a posts 5303 (es) y 5304 (en)' );

$post_5303 = $mysqli_lab->query( "SELECT post_type, post_status FROM `etk_t_posts` WHERE ID = 5303" )->fetch_assoc();
$post_5304 = $mysqli_lab->query( "SELECT post_type, post_status FROM `etk_t_posts` WHERE ID = 5304" )->fetch_assoc();
assert_true( 'tours' === $post_5303['post_type'] && 'trash' !== $post_5303['post_status'], 'Post 5303 activo y no en papelera' );
assert_true( 'tours' === $post_5304['post_type'] && 'trash' !== $post_5304['post_status'], 'Post 5304 activo y no en papelera' );

// 5. Post TRID 4338 (page) -> 4338 (es).
$g_4338 = $groups_map[4338];
assert_true( 'post' === $g_4338['element_type'] && 'page' === $g_4338['subtype'], 'TRID 4338 corresponde a post_type page' );
assert_true( 4338 === $g_4338['translations']['es'], 'TRID 4338 mapea a página canónica 4338' );

$post_4338 = $mysqli_lab->query( "SELECT post_type, post_status FROM `etk_t_posts` WHERE ID = 4338" )->fetch_assoc();
assert_true( 'page' === $post_4338['post_type'] && 'trash' !== $post_4338['post_status'], 'Post 4338 activo y no en papelera' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: VALIDACIÓN FORMAL DRY-RUN Y CERO ESCRITURAS EN cms_ect_lab
// -------------------------------------------------------------------------
echo "\n--- Control 5: Validación Formal Dry-Run del Plan Piloto y Cero Escrituras ---\n";

$validation = $executor->validate_plan( $pilot_plan, $pilot_fp );
assert_true( $validation['valid'], 'Validación formal de plan piloto en ejecutor resulta 100% VÁLIDA sin errores' );
assert_true( empty( $validation['errors'] ), 'Lista de errores de validación del plan piloto estrictamente vacía' );

// Strict read-only verification: check that TFML tables in cms_ect_lab have 0 rows.
$tfml_tables = array(
	'etk_t_tfml_groups',
	'etk_t_tfml_group_elements',
	'etk_t_tfml_media_translations',
	'etk_t_tfml_strings',
	'etk_t_tfml_string_translations',
);

foreach ( $tfml_tables as $table ) {
	$count = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `{$table}`" )->fetch_row()[0];
	assert_true( 0 === $count, "Tabla TFML '{$table}' contiene exactamente 0 registros (CERO escrituras, migración retenida)" );
}

$lab_posts_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0];
$lab_terms_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0];
assert_true( 4021 === $lab_posts_count, 'Posts en cms_ect_lab intactos (4021 = 4021)' );
assert_true( 82 === $lab_terms_count, 'Terms en cms_ect_lab intactos (82 = 82)' );

$mysqli_lab->query( 'SET SESSION group_concat_max_len = 10000000' );
$wpml_rows_lab = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$wpml_hash_lab = (string) $mysqli_lab->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

assert_true( 3403 === $wpml_rows_lab, 'Filas en etk_t_icl_translations de laboratorio intactas (3403)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $wpml_hash_lab, 'Checksum de WPML en laboratorio strictly intacto' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: CHECKPOINTS, MANIFIESTOS Y CAPACIDAD DE ROLLBACK
// -------------------------------------------------------------------------
echo "\n--- Control 6: Checkpoints, Manifiestos de Titularidad y Capacidad de Rollback ---\n";

// 1. Checkpoint hydration and serialization test for pilot batch.
$pilot_checkpoint = new MigrationCheckpoint(
	array(
		'source'          => 'wpml',
		'total_items'     => 5,
		'processed_items' => 0,
		'batch_size'      => 5,
		'status'          => MigrationCheckpoint::STATUS_PENDING,
	)
);
assert_true( 5 === $pilot_checkpoint->get_total_items(), 'Checkpoint configurado para exactamente 5 ítems' );
assert_true( MigrationCheckpoint::STATUS_PENDING === $pilot_checkpoint->get_status(), 'Estado inicial de checkpoint es PENDING' );

// 2. Rollback ownership test: Unmanifested / foreign groups are strictly blocked.
$rollback_service = $plugin->get_migration_manager()->get_wpml_rollback_service();
$dummy_exec_id    = 'exec_pilot_prep_' . wp_generate_password( 6, false );
$foreign_test_gid = 888777;

$rollback_service->save_execution_manifest(
	$dummy_exec_id,
	array(
		'execution_id'       => $dummy_exec_id,
		'source'             => 'wpml',
		'imported_group_ids' => array( 112233 ),
	)
);

$rollback_test = $rollback_service->rollback_groups( array( $foreign_test_gid ), $dummy_exec_id, true );
assert_true( ! $rollback_test['success'], 'Rollback bloquea incondicionalmente grupos ajenos al manifiesto' );
assert_true( 1 === $rollback_test['blocked_groups_count'], 'Exactamente 1 grupo bloqueado por falta de titularidad' );
assert_true( 'not_owned' === $rollback_test['blocked_groups'][0]['code'], 'Código de bloqueo clasificado formalmente como not_owned' );

$rollback_service->delete_execution_manifest( $dummy_exec_id );

$mysqli_lab->close();

// -------------------------------------------------------------------------
// 8. POST-FLIGHT: SENTINEL WPML CHECK (cms_ect) (DELTA = 0)
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
echo "  FASE 3.11 COMPLETADA Y CERTIFICADA EXITOSAMENTE EN LABORATORIO REAL\n";
echo "====================================================================\n";
