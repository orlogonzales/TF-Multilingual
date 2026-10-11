<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.12.
 *
 * Directivas: DT-TFML-042 y ADENDA DT-TFML-042-A
 * Alcance: Ejecución piloto transaccional WPML -> TFML en laboratorio aislado cms_ect_lab
 *          (Lote pilot-batch-1: 5 grupos, 9 elementos).
 *          Verificación de Preflight, Validación Criptográfica, Pruebas Negativas de Gobernanza,
 *          Ejecución Transaccional, Idempotencia, Rollback Completo, Recuperación / Re-ejecución
 *          y Centinela Post-Flight Inviolable en cms_ect (Delta = 0).
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

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationExecutionReport;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Migration\Exceptions\MigrationAuthorizationException;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;

global $wpdb;
$original_wpdb = $wpdb;

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
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.12 (EJECUCION PILOTO)\n";
echo "  Directiva: DT-TFML-042 | Adenda: DT-TFML-042-A\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: SENTINEL WPML CHECK (cms_ect)
// -------------------------------------------------------------------------
echo "--- Pre-Flight: Centinela WPML en Base de Datos de Origen (cms_ect) ---\n";
$sentinel_table = $original_wpdb->prefix . 'icl_translations';
$original_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

$pre_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$pre_hash = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: 3403)\n";
echo "  MD5:  {$pre_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: Conteo WPML en cms_ect es exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: Checksum MD5 en cms_ect coincide exactamente' );

// Isolate external plugins to prevent unwanted side-effects.
$isolation_guard = new TF\Multilingual\Scratch\ExternalPluginIsolationGuard();
$isolation_guard->isolate();

// Setup DB connections to isolated lab.
$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';
$lab_db  = 'cms_ect_lab';

$mysqli_lab = new mysqli( $db_host, $db_user, $db_pass, $lab_db );
assert_true( ! $mysqli_lab->connect_error, "Conexión MySQL directa a laboratorio '{$lab_db}' exitosa" );

$lab_wpdb = new wpdb( $db_user, $db_pass, $lab_db, $db_host );
$lab_wpdb->set_prefix( 'etk_t_' );

$plugin = Plugin::get_instance();
$plugin->init();
$registry = $plugin->get_language_registry();

// -------------------------------------------------------------------------
// 2. CONTROL 1: PREFLIGHT OBLIGATORIO Y VERIFICACIÓN DE AISLAMIENTO FÍSICO
// -------------------------------------------------------------------------
echo "\n--- Control 1: Preflight Obligatorio y Verificación de Aislamiento Físico ---\n";

// A. Confirm database active name and prefix.
$active_db_res = $mysqli_lab->query( 'SELECT DATABASE()' )->fetch_row()[0];
assert_true( 'cms_ect_lab' === $active_db_res, 'Base de datos activa del laboratorio es estrictamente "cms_ect_lab"' );
assert_true( 'etk_t_' === $lab_wpdb->prefix, 'Prefijo de tablas de laboratorio es exactamente "etk_t_"' );

// B. Confirm backup existence outside DocumentRoot and cryptographic SHA-256 fingerprint.
$backup_secure_path = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
$doc_root           = 'd:/laragon/www';
assert_true( file_exists( $backup_secure_path ), 'Archivo de respaldo existe en almacenamiento seguro' );
assert_true( strpos( realpath( $backup_secure_path ), realpath( $doc_root ) ) === false, 'Respaldo almacenado estrictamente fuera de DocumentRoot' );

$backup_sha256 = hash_file( 'sha256', $backup_secure_path );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_sha256, 'Integridad criptográfica SHA-256 del respaldo certificada' );

// C. Confirm initial clean state: all 5 TFML tables in cms_ect_lab have strictly 0 rows.
$tfml_tables = array(
	'etk_t_tfml_groups',
	'etk_t_tfml_group_elements',
	'etk_t_tfml_media_translations',
	'etk_t_tfml_strings',
	'etk_t_tfml_string_translations',
);

// Reset any test leftovers in lab TFML tables and migration options.
foreach ( $tfml_tables as $table ) {
	$mysqli_lab->query( "TRUNCATE TABLE `{$table}`" );
}
$mysqli_lab->query( "DELETE FROM `etk_t_options` WHERE `option_name` LIKE 'tfml_migration_%'" );

foreach ( $tfml_tables as $table ) {
	$count = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `{$table}`" )->fetch_row()[0];
	assert_true( 0 === $count, "Estado inicial: Tabla TFML '{$table}' contiene exactamente 0 registros" );
}

// -------------------------------------------------------------------------
// 3. CONTROL 2: CONSTRUCCIÓN, LINAJE Y HUELLA DEL LOTE PILOTO (pilot-batch-1)
// -------------------------------------------------------------------------
echo "\n--- Control 2: Linaje Criptográfico y Aprobación del Lote Piloto ---\n";

$planner   = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$full_plan = $planner->generate_plan();

assert_true( MigrationPlan::STATUS_NEEDS_CONFIGURATION === $full_plan->get_status(), 'Plan matriz en estado STATUS_NEEDS_CONFIGURATION por idioma fr pendiente' );
$parent_fp = $full_plan->get_fingerprint();
assert_true( '08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949' === $parent_fp, 'Huella digital SHA-256 inmutable del plan matriz certificada' );

// Authorized pilot TRIDs: 4575, 9238, 9239, 9247, 4338.
$pilot_trids = array( 4575, 9238, 9239, 9247, 4338 );
$pilot_plan  = $full_plan->derive_partial_plan( $pilot_trids, 'pilot-batch-1' );

assert_true( $pilot_plan->is_partial(), 'Plan piloto identificado formalmente como parcial (is_partial = true)' );
assert_true( 'pilot-batch-1' === $pilot_plan->get_label(), 'Etiqueta del lote registrada formalmente como "pilot-batch-1"' );
assert_true( MigrationPlan::STATUS_READY === $pilot_plan->get_status(), 'Plan piloto derivado en estado formal STATUS_READY' );
assert_true( empty( $pilot_plan->get_missing_languages() ), 'Plan piloto sin dependencias de idiomas faltantes (0 faltantes)' );
assert_true( $parent_fp === $pilot_plan->get_parent_fingerprint(), 'Linaje criptográfico verificado con el plan matriz' );

$pilot_fp               = $pilot_plan->get_fingerprint();
$authorized_fingerprint = '30742abbc3af9267c826aef6c24875756e829971df65993553ec75d2aa55f711';
assert_true( $authorized_fingerprint === $pilot_fp, "Huella digital del plan piloto coincide exactamente con la autorizada ({$authorized_fingerprint})" );
assert_true( 5 === count( $pilot_plan->get_planned_groups() ), 'Plan piloto contiene exactamente 5 grupos de traducción' );
assert_true( 9 === (int) $pilot_plan->get_summary()['total_elements'], 'Plan piloto contiene exactamente 9 elementos de traducción' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: PRUEBAS NEGATIVAS Y GATES DE GOBERNANZA (ADENDA DT-TFML-042-A)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Pruebas Negativas y Criterios Explícitos de Fallo ---\n";

$group_repo        = new TranslationGroupRepository( $lab_wpdb, $registry );
$checkpoint_repo   = new MigrationCheckpointRepository();
$lock_manager      = new MigrationExecutionLock();
$element_validator = new WordPressElementValidator();
$schema_manager    = new SchemaManager( $lab_wpdb );

$executor = new WpmlMigrationExecutor(
	$lab_wpdb,
	$group_repo,
	$registry,
	$checkpoint_repo,
	$lock_manager,
	$element_validator,
	$schema_manager
);

// N1: Unauthorized executor rejection.
$unauth_blocked = false;
try {
	$executor->execute_batch( $pilot_plan );
} catch ( MigrationAuthorizationException $e ) {
	$unauth_blocked = true;
}
assert_true( $unauth_blocked, 'N1: Ejecutor no autorizado bloquea la ejecución con MigrationAuthorizationException' );

// Authorize executor for lab testing.
$executor->set_authorized( true );

// N2: Tampered / Altered plan fingerprint rejection.
$tampered_fp      = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
$tampered_blocked = false;
try {
	$executor->execute_batch( $pilot_plan, 5, 'test_token', null, $tampered_fp );
} catch ( MigrationPlanValidationException $e ) {
	$tampered_blocked = true;
	$err_text         = implode( ' ', $e->get_validation_errors() );
	assert_true( strpos( $err_text, 'no coincide con la esperada' ) !== false, 'N2: Detalle de validación registra rechazo por discrepancia de huella' );
}
assert_true( $tampered_blocked, 'N2: Ejecutor rechaza incondicionalmente plan con huella manipulada/alterada' );

// N3: Unready parent plan rejection (STATUS_NEEDS_CONFIGURATION).
$unready_blocked = false;
try {
	$executor->execute_batch( $full_plan );
} catch ( MigrationPlanValidationException $e ) {
	$unready_blocked = true;
	assert_true( strpos( $e->getMessage(), 'NEEDS_CONFIGURATION' ) !== false, 'N3: Mensaje de error detalla rechazo por estado NEEDS_CONFIGURATION' );
}
assert_true( $unready_blocked, 'N3: Ejecutor rechaza incondicionalmente plan matriz en STATUS_NEEDS_CONFIGURATION' );

// N4: Unauthorized TRID / Language-blocked group rejection in derive_partial_plan.
$blocked_lang_rejected = false;
try {
	$blocked_groups = $full_plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE );
	$blocked_trid   = (int) $blocked_groups[0]['trid'];
	$full_plan->derive_partial_plan( array( $blocked_trid ) );
} catch ( InvalidArgumentException $e ) {
	$blocked_lang_rejected = true;
}
assert_true( $blocked_lang_rejected, 'N4: derive_partial_plan rechaza grupos bloqueados por idiomas no configurados (fr)' );

// N5: Rollback ownership test: Unowned / foreign group rejection.
$rollback_service = new WpmlMigrationRollbackService(
	$lab_wpdb,
	$group_repo,
	$checkpoint_repo,
	$lock_manager
);

$dummy_exec_id = 'exec_neg_test_' . wp_generate_password( 6, false );
$rollback_service->save_execution_manifest(
	$dummy_exec_id,
	array(
		'execution_id'       => $dummy_exec_id,
		'source'             => 'wpml',
		'imported_group_ids' => array( 999111 ),
	)
);
$foreign_test = $rollback_service->rollback_groups( array( 777333 ), $dummy_exec_id, true );
assert_true( ! $foreign_test['success'], 'N5: Rollback rechaza incondicionalmente grupos que no pertenecen al manifiesto' );
assert_true( 'not_owned' === $foreign_test['blocked_groups'][0]['code'], 'N5: Código de rechazo es "not_owned"' );
$rollback_service->delete_execution_manifest( $dummy_exec_id );

// -------------------------------------------------------------------------
// 5. CONTROL 4: EJECUCIÓN PILOTO TRANSACCIONAL (RUN 1: 5 GRUPOS, 9 ELEMENTOS)
// -------------------------------------------------------------------------
echo "\n--- Control 4: Ejecución Piloto Transaccional Autorizada (Lote pilot-batch-1) ---\n";

// Set global $wpdb to $lab_wpdb to guarantee all WordPress options/transients write strictly to cms_ect_lab.
$GLOBALS['wpdb'] = $lab_wpdb;

$execution_id = 'pilot_run_' . wp_generate_password( 8, false );
$report_run1  = $executor->run( $pilot_plan, 5, $execution_id, $pilot_fp );

assert_true( MigrationExecutionReport::STATUS_COMPLETED === $report_run1->get_status(), 'Ejecución 1 completada formalmente con estado STATUS_COMPLETED' );
assert_true( 5 === $report_run1->get_imported_groups(), 'Ejecución 1 reporta exactamente 5 grupos importados' );
assert_true( 9 === $report_run1->get_imported_elements(), 'Ejecución 1 reporta exactamente 9 elementos importados' );
assert_true( 0 === $report_run1->get_failed_groups(), 'Ejecución 1 reporta 0 grupos fallidos' );
assert_true( 0 === $report_run1->get_skipped_groups(), 'Ejecución 1 reporta 0 grupos omitidos' );
assert_true( empty( $report_run1->get_errors() ), 'Ejecución 1 reporta 0 errores en el checkpoint' );

$reconciliation = $report_run1->get_reconciliation();
assert_true( ! empty( $reconciliation ) && true === $reconciliation['integrity_verified'], 'Conciliación post-ejecución verifica integridad al 100%' );
assert_true( empty( $reconciliation['discrepancies'] ), 'Lista de discrepancias de conciliación estrictamente vacía' );

// Direct DB Inspections on cms_ect_lab:
$groups_count   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 5 === $groups_count, 'BD laboratorio: Exactamente 5 registros en etk_t_tfml_groups' );
assert_true( 9 === $elements_count, 'BD laboratorio: Exactamente 9 registros en etk_t_tfml_group_elements' );

// Detailed verification of the 5 migrated groups:
$migrated_groups = $mysqli_lab->query(
	'SELECT id, element_type, subtype, canonical_element_id FROM `etk_t_tfml_groups` ORDER BY id ASC'
)->fetch_all( MYSQLI_ASSOC );

assert_true( 5 === count( $migrated_groups ), 'Recuperados los 5 grupos de traducción migrados de la base de datos' );

// Map groups by canonical_element_id.
$groups_by_canonical = array();
foreach ( $migrated_groups as $mg ) {
	$groups_by_canonical[ (int) $mg['canonical_element_id'] ] = $mg;
}

// 1. TRID 4575 -> tours_cat, canonical 41 (es), translation 47 (en).
assert_true( isset( $groups_by_canonical[41] ), 'Grupo TRID 4575 registrado con canónico 41' );
$g41     = $groups_by_canonical[41];
$dom_g41 = $group_repo->find( (int) $g41['id'] );
assert_true( 'term' === $g41['element_type'] && 'tours_cat' === $g41['subtype'], 'TRID 4575: tipo term, subtipo tours_cat' );
assert_true( 'es' === $dom_g41->get_canonical_element()->get_language_code(), 'TRID 4575: idioma canónico "es"' );

$elements_g41 = $mysqli_lab->query( "SELECT element_id, language_code, source_version_at_translation FROM `etk_t_tfml_group_elements` WHERE group_id = {$g41['id']} ORDER BY element_id ASC" )->fetch_all( MYSQLI_ASSOC );
assert_true( 2 === count( $elements_g41 ), 'TRID 4575 contiene exactamente 2 elementos (es/en)' );
assert_true( 41 === (int) $elements_g41[0]['element_id'] && 'es' === $elements_g41[0]['language_code'], 'TRID 4575: elemento 41 es idioma "es"' );
assert_true( 47 === (int) $elements_g41[1]['element_id'] && 'en' === $elements_g41[1]['language_code'], 'TRID 4575: elemento 47 es idioma "en"' );

// 2. TRID 9238 -> tours_cat, canonical 46 (es), translation 48 (en).
assert_true( isset( $groups_by_canonical[46] ), 'Grupo TRID 9238 registrado con canónico 46' );
$g46     = $groups_by_canonical[46];
$dom_g46 = $group_repo->find( (int) $g46['id'] );
assert_true( 'term' === $g46['element_type'] && 'tours_cat' === $g46['subtype'], 'TRID 9238: tipo term, subtipo tours_cat' );
assert_true( 'es' === $dom_g46->get_canonical_element()->get_language_code(), 'TRID 9238: idioma canónico "es"' );

$elements_g46 = $mysqli_lab->query( "SELECT element_id, language_code FROM `etk_t_tfml_group_elements` WHERE group_id = {$g46['id']} ORDER BY element_id ASC" )->fetch_all( MYSQLI_ASSOC );
assert_true( 2 === count( $elements_g46 ), 'TRID 9238 contiene exactamente 2 elementos (es/en)' );
assert_true( 46 === (int) $elements_g46[0]['element_id'] && 'es' === $elements_g46[0]['language_code'], 'TRID 9238: elemento 46 es idioma "es"' );
assert_true( 48 === (int) $elements_g46[1]['element_id'] && 'en' === $elements_g46[1]['language_code'], 'TRID 9238: elemento 48 es idioma "en"' );

// 3. TRID 9239 -> tours, canonical 5286 (es), translation 5287 (en).
assert_true( isset( $groups_by_canonical[5286] ), 'Grupo TRID 9239 registrado con canónico 5286' );
$g5286     = $groups_by_canonical[5286];
$dom_g5286 = $group_repo->find( (int) $g5286['id'] );
assert_true( 'post' === $g5286['element_type'] && 'tours' === $g5286['subtype'], 'TRID 9239: tipo post, subtipo tours' );
assert_true( 'es' === $dom_g5286->get_canonical_element()->get_language_code(), 'TRID 9239: idioma canónico "es"' );

$elements_g5286 = $mysqli_lab->query( "SELECT element_id, language_code FROM `etk_t_tfml_group_elements` WHERE group_id = {$g5286['id']} ORDER BY element_id ASC" )->fetch_all( MYSQLI_ASSOC );
assert_true( 2 === count( $elements_g5286 ), 'TRID 9239 contiene exactamente 2 elementos (es/en)' );
assert_true( 5286 === (int) $elements_g5286[0]['element_id'] && 'es' === $elements_g5286[0]['language_code'], 'TRID 9239: elemento 5286 es idioma "es"' );
assert_true( 5287 === (int) $elements_g5286[1]['element_id'] && 'en' === $elements_g5286[1]['language_code'], 'TRID 9239: elemento 5287 es idioma "en"' );

// 4. TRID 9247 -> tours, canonical 5303 (es), translation 5304 (en).
assert_true( isset( $groups_by_canonical[5303] ), 'Grupo TRID 9247 registrado con canónico 5303' );
$g5303     = $groups_by_canonical[5303];
$dom_g5303 = $group_repo->find( (int) $g5303['id'] );
assert_true( 'post' === $g5303['element_type'] && 'tours' === $g5303['subtype'], 'TRID 9247: tipo post, subtipo tours' );
assert_true( 'es' === $dom_g5303->get_canonical_element()->get_language_code(), 'TRID 9247: idioma canónico "es"' );

$elements_g5303 = $mysqli_lab->query( "SELECT element_id, language_code FROM `etk_t_tfml_group_elements` WHERE group_id = {$g5303['id']} ORDER BY element_id ASC" )->fetch_all( MYSQLI_ASSOC );
assert_true( 2 === count( $elements_g5303 ), 'TRID 9247 contiene exactamente 2 elementos (es/en)' );
assert_true( 5303 === (int) $elements_g5303[0]['element_id'] && 'es' === $elements_g5303[0]['language_code'], 'TRID 9247: elemento 5303 es idioma "es"' );
assert_true( 5304 === (int) $elements_g5303[1]['element_id'] && 'en' === $elements_g5303[1]['language_code'], 'TRID 9247: elemento 5304 es idioma "en"' );

// 5. TRID 4338 -> page, canonical 4338 (es), 1 element.
assert_true( isset( $groups_by_canonical[4338] ), 'Grupo TRID 4338 registrado con canónico 4338' );
$g4338     = $groups_by_canonical[4338];
$dom_g4338 = $group_repo->find( (int) $g4338['id'] );
assert_true( 'post' === $g4338['element_type'] && 'page' === $g4338['subtype'], 'TRID 4338: tipo post, subtipo page' );
assert_true( 'es' === $dom_g4338->get_canonical_element()->get_language_code(), 'TRID 4338: idioma canónico "es"' );

$elements_g4338 = $mysqli_lab->query( "SELECT element_id, language_code FROM `etk_t_tfml_group_elements` WHERE group_id = {$g4338['id']}" )->fetch_all( MYSQLI_ASSOC );
assert_true( 1 === count( $elements_g4338 ), 'TRID 4338 contiene exactamente 1 elemento canónico' );
assert_true( 4338 === (int) $elements_g4338[0]['element_id'] && 'es' === $elements_g4338[0]['language_code'], 'TRID 4338: elemento 4338 es idioma "es"' );

// Verify WPML and Core tables in cms_ect_lab are completely unmutated.
$lab_posts_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0];
$lab_terms_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0];
assert_true( 4021 === $lab_posts_count, 'Posts en cms_ect_lab intactos tras importación (4021)' );
assert_true( 82 === $lab_terms_count, 'Terms en cms_ect_lab intactos tras importación (82)' );

$mysqli_lab->query( 'SET SESSION group_concat_max_len = 10000000' );
$wpml_rows_lab = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$wpml_hash_lab = (string) $mysqli_lab->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];
assert_true( 3403 === $wpml_rows_lab, 'Filas en etk_t_icl_translations de laboratorio intactas tras importación (3403)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $wpml_hash_lab, 'Checksum de WPML en laboratorio strictly intacto tras importación' );

// Verify manifest in options.
$persisted_manifest = $rollback_service->get_execution_manifest( $execution_id );
assert_true( null !== $persisted_manifest, 'Manifiesto de ejecución persistido correctamente en opciones de laboratorio' );
assert_true( 5 === count( (array) $persisted_manifest['imported_group_ids'] ), 'Manifiesto registra exactamente los 5 IDs de grupo importados' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: VERIFICACIÓN DE IDEMPOTENCIA (RUN 2: 0 DUPLICADOS)
// -------------------------------------------------------------------------
echo "\n--- Control 5: Verificación de Idempotencia Estricta (Re-ejecución) ---\n";

$execution_id_2 = 'pilot_run_idemp_' . wp_generate_password( 8, false );
$report_run2    = $executor->run( $pilot_plan, 5, $execution_id_2, $pilot_fp );

assert_true( MigrationExecutionReport::STATUS_COMPLETED === $report_run2->get_status(), 'Re-ejecución completada con estado STATUS_COMPLETED' );
assert_true( 0 === $report_run2->get_imported_groups(), 'Re-ejecución: 0 grupos nuevos creados (CERO duplicados)' );
assert_true( 5 === $report_run2->get_skipped_groups(), 'Re-ejecución: Exactamente 5 grupos detectados y omitidos por idempotencia' );
assert_true( 0 === $report_run2->get_failed_groups(), 'Re-ejecución: 0 grupos fallidos' );

// Check that each group result in Run 2 is 'idempotent_skipped'.
foreach ( $report_run2->get_group_results() as $gres ) {
	assert_true( 'idempotent_skipped' === $gres['status'], "TRID {$gres['trid']} reporta formalmente 'idempotent_skipped'" );
}

// Direct DB count verification: counts must remain exactly 5 and 9.
$groups_count_after_run2   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count_after_run2 = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 5 === $groups_count_after_run2, 'Idempotencia en BD: El número de grupos permanece exactamente en 5' );
assert_true( 9 === $elements_count_after_run2, 'Idempotencia en BD: El número de elementos permanece exactamente en 9' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: REVERSIÓN TOTAL TRANSACCIONAL (ROLLBACK)
// -------------------------------------------------------------------------
echo "\n--- Control 6: Reversión Total Transaccional (Rollback Service) ---\n";

$rollback_result = $rollback_service->rollback_execution( $report_run1, false );

assert_true( $rollback_result['success'], 'Rollback transaccional ejecutado con éxito total' );
assert_true( 5 === $rollback_result['reverted_groups_count'], 'Rollback revirtió exactamente los 5 grupos de traducción creados' );
assert_true( 9 === $rollback_result['reverted_elements_count'], 'Rollback revirtió exactamente los 9 elementos de traducción' );
assert_true( 0 === $rollback_result['blocked_groups_count'], 'Rollback reporta 0 grupos bloqueados' );

// Direct DB check: all TFML tables must be returned to 0 rows.
foreach ( $tfml_tables as $table ) {
	$count = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `{$table}`" )->fetch_row()[0];
	assert_true( 0 === $count, "Post-Rollback: Tabla TFML '{$table}' restaurada exactamente a 0 registros" );
}

// Direct Core & WPML check post-rollback.
$lab_posts_post_rollback = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0];
$lab_terms_post_rollback = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0];
assert_true( 4021 === $lab_posts_post_rollback, 'Posts en cms_ect_lab intactos tras rollback (4021)' );
assert_true( 82 === $lab_terms_post_rollback, 'Terms en cms_ect_lab intactos tras rollback (82)' );

$wpml_rows_post_rollback = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$wpml_hash_post_rollback = (string) $mysqli_lab->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];
assert_true( 3403 === $wpml_rows_post_rollback, 'WPML en laboratorio intacto tras rollback (3403 filas)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $wpml_hash_post_rollback, 'WPML checksum en laboratorio intacto tras rollback' );

// Manifest cleanup verification.
assert_true( null === $rollback_service->get_execution_manifest( $execution_id ), 'Manifiesto de ejecución eliminado de opciones tras rollback exitoso' );

// -------------------------------------------------------------------------
// 8. CONTROL 7: RECUPERACIÓN Y RE-EJECUCIÓN FINAL (RECOVERY / RE-EXECUTION)
// -------------------------------------------------------------------------
echo "\n--- Control 7: Capacidad de Recuperación y Re-ejecución Post-Rollback ---\n";

$execution_id_final = 'pilot_final_' . wp_generate_password( 8, false );
$report_final       = $executor->run( $pilot_plan, 5, $execution_id_final, $pilot_fp );

assert_true( MigrationExecutionReport::STATUS_COMPLETED === $report_final->get_status(), 'Re-ejecución final completada con estado STATUS_COMPLETED' );
assert_true( 5 === $report_final->get_imported_groups(), 'Re-ejecución final: 5 grupos importados fielmente' );
assert_true( 9 === $report_final->get_imported_elements(), 'Re-ejecución final: 9 elementos importados fielmente' );
assert_true( 0 === $report_final->get_failed_groups(), 'Re-ejecución final: 0 grupos fallidos' );

$final_groups_count   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_elements_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 5 === $final_groups_count, 'Estado final en laboratorio: exactamente 5 grupos en etk_t_tfml_groups' );
assert_true( 9 === $final_elements_count, 'Estado final en laboratorio: exactamente 9 elementos en etk_t_tfml_group_elements' );

// Restore original $GLOBALS['wpdb'].
$GLOBALS['wpdb'] = $original_wpdb;
$mysqli_lab->close();

// -------------------------------------------------------------------------
// 9. POST-FLIGHT: SENTINEL WPML CHECK (cms_ect) (DELTA = 0)
// -------------------------------------------------------------------------
echo "\n--- Sentinel WPML Post-Flight: Comprobación Inviolable en Origen (cms_ect) ---\n";

$post_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "  Rows: {$post_rows} (Expected: {$pre_rows})\n";
echo "  MD5:  {$post_hash} (Expected: {$pre_hash})\n\n";

assert_true( $pre_rows === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( $pre_hash === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

$isolation_guard->restore();

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.12 CERTIFICADA EXITOSAMENTE (MATRIZ: PASS)\n";
echo "====================================================================\n";
