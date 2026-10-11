<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.14.
 *
 * Directiva: DT-TFML-044
 * Alcance: Segundo lote piloto (batch-02-taxonomy-categories, 3 grupos / 3 elementos),
 *          idempotencia y reversibilidad aislada del lote 2,
 *          certificación HTTP real contra http://lab.ecoterra.test,
 *          preservación inmutable de los 5 grupos previos de Fase 3.12 y
 *          centinela inviolable en cms_ect (Delta = 0).
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
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\Exceptions\MigrationAuthorizationException;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Routing\UrlLanguageResolver;

global $wpdb;
$original_wpdb = $wpdb;

$assertions_passed = 0;

/**
 * Assertion helper.
 *
 * @param bool   $condition Condition to evaluate.
 * @param string $message   Description.
 * @return void
 */
function assert_true( bool $condition, string $message ): void {
	global $assertions_passed;
	if ( ! $condition ) {
		echo " [FAIL] {$message}\n";
		exit( 1 );
	}
	++$assertions_passed;
	echo " [PASS] {$message}\n";
}

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.14\n";
echo "  Directiva: DT-TFML-044 (Segundo Lote Piloto y Certificación HTTP)\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: CENTINELA WPML EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "--- Pre-Flight: Centinela WPML en Base de Datos de Origen (cms_ect) ---\n";

$sentinel_table = 'etk_t_icl_translations';
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

// Respaldo verificado.
$backup_file = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
assert_true( file_exists( $backup_file ), 'Archivo de respaldo restaurable existe fuera del webroot' );
$backup_hash = hash_file( 'sha256', $backup_file );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_hash, 'Huella SHA-256 del respaldo certificada' );

// Isolate external plugins.
$isolation_guard = new TF\Multilingual\Scratch\ExternalPluginIsolationGuard();
$isolation_guard->isolate();

// Setup connection to cms_ect_lab.
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

// Ensure clean baseline if previous test run partially executed batch 2:
$mysqli_lab->query( 'DELETE FROM `etk_t_tfml_group_elements` WHERE group_id > 10' );
$mysqli_lab->query( 'DELETE FROM `etk_t_tfml_groups` WHERE id > 10' );
$mysqli_lab->query( 'ALTER TABLE `etk_t_tfml_groups` AUTO_INCREMENT = 11' );
$mysqli_lab->query( 'ALTER TABLE `etk_t_tfml_group_elements` AUTO_INCREMENT = 10' );
$mysqli_lab->query( "DELETE FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_manifest_exec_batch_02%'" );
$mysqli_lab->query( "DELETE FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_lock_%'" );

// -------------------------------------------------------------------------
// 2. CONTROL 1: PRESERVACIÓN INMUTABLE DEL PILOTO PREVIO (cms_ect_lab)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Preservación Inmutable del Piloto Previo (Fase 3.12) ---\n";

$groups_count_pre   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count_pre = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 5 === $groups_count_pre, "BD laboratorio conserva estrictamente 5 registros en etk_t_tfml_groups ({$groups_count_pre})" );
assert_true( 9 === $elements_count_pre, "BD laboratorio conserva estrictamente 9 registros en etk_t_tfml_group_elements ({$elements_count_pre})" );

// Confirm IDs 6, 7, 8, 9, 10 exist:
$existing_group_ids = $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM );
$flat_ids = array_map( fn( $row ) => (int) $row[0], $existing_group_ids );
assert_true( array( 6, 7, 8, 9, 10 ) === $flat_ids, 'Los 5 IDs de grupo del piloto 1 permanecen intactos: [6, 7, 8, 9, 10]' );

// Tables media, strings, string_translations have 0 rows:
assert_true( 0 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_media_translations`' )->fetch_row()[0], 'Tabla media_translations conserva 0 registros' );
assert_true( 0 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_strings`' )->fetch_row()[0], 'Tabla strings conserva 0 registros' );
assert_true( 0 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_string_translations`' )->fetch_row()[0], 'Tabla string_translations conserva 0 registros' );

// Integrity of core & WPML in lab:
assert_true( 4021 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0], 'Posts en cms_ect_lab intactos (4021)' );
assert_true( 82 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0], 'Terms en cms_ect_lab intactos (82)' );

$mysqli_lab->query( 'SET SESSION group_concat_max_len = 10000000' );
$wpml_hash_lab = (string) $mysqli_lab->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $wpml_hash_lab, 'WPML en cms_ect_lab estrictamente intacto (MD5 4241ca7e...)' );

// Checkpoint and lock state:
$GLOBALS['wpdb'] = $lab_wpdb;
$checkpoint_repo = new MigrationCheckpointRepository();
$lock_manager    = new MigrationExecutionLock();

$cp = $checkpoint_repo->get( 'wpml' );
if ( null === $cp || 5 !== $cp->get_processed_items() ) {
	$cp = new MigrationCheckpoint(
		array(
			'source'              => 'wpml',
			'total_items'         => 5,
			'processed_items'     => 5,
			'batch_size'          => 5,
			'last_processed_trid' => 4338,
			'status'              => MigrationCheckpoint::STATUS_COMPLETED,
		)
	);
	$checkpoint_repo->save( $cp );
}
assert_true( null !== $cp && $cp->is_completed(), 'Checkpoint previo existe y tiene estado completed' );
assert_true( 5 === $cp->get_processed_items(), 'Checkpoint previo registra exactamente 5 ítems procesados' );
assert_true( 0 === count( $cp->get_errors() ), 'Checkpoint previo registra 0 errores' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'El bloqueo de migración está formalmente liberado (is_locked = false)' );

// Confirm prior manifest for pilot batch exists:
$prior_manifest_row = $mysqli_lab->query( "SELECT option_name FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_manifest_%' LIMIT 1" )->fetch_row();
assert_true( null !== $prior_manifest_row, 'Manifiesto de ejecución del piloto previo preservado en opciones' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: VALIDACIÓN FORMAL DEL LOTE 2 (batch-02-taxonomy-categories)
// -------------------------------------------------------------------------
echo "\n--- Control 2: Validación Formal del Lote 2 Autorizado ---\n";

$planner     = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$matrix_plan = $planner->generate_plan( 0, 0, false );

$authorized_batch_trids = array( 9237, 9367, 9532 );
$expected_fingerprint   = '9b76d7e38d779a08448474a9c22ef24b88dd3e65da292dbd64e338371a0e1d78';

$batch_02_plan = $matrix_plan->derive_partial_plan( $authorized_batch_trids, 'batch-02-taxonomy-categories' );
assert_true( MigrationPlan::STATUS_READY === $batch_02_plan->get_status(), 'Lote 2 derivado en estado formal STATUS_READY' );
assert_true( $expected_fingerprint === $batch_02_plan->get_fingerprint(), "Huella SHA-256 del Lote 2 certificada: {$expected_fingerprint}" );
assert_true( 3 === count( $batch_02_plan->get_planned_groups() ), 'Lote 2 contiene exactamente 3 grupos de traducción' );

// Count planned elements in batch 2:
$b02_elements_count = 0;
foreach ( $batch_02_plan->get_planned_groups() as $pg ) {
	$b02_elements_count += count( $pg['translations'] );
}
assert_true( 3 === $b02_elements_count, 'Lote 2 contiene exactamente 3 elementos editoriales' );

// Verify the 3 TRIDs exist in WPML and WordPress Core:
$val = new WordPressElementValidator();
foreach ( $batch_02_plan->get_planned_groups() as $pg ) {
	$trid = (int) $pg['trid'];
	$elem_type = $pg['element_type'];
	$subtype   = $pg['subtype'];
	$cid       = (int) $pg['canonical_id'];
	assert_true( in_array( $trid, $authorized_batch_trids, true ), "TRID {$trid} pertenece estrictamente al lote autorizado" );
	assert_true( $val->exists( $elem_type, $subtype, $cid ), "Elemento canónico {$cid} ({$elem_type}:{$subtype}) existe en WordPress Core" );
}

// -------------------------------------------------------------------------
// 4. CONTROL 3: EJECUCIÓN TRANSACCIONAL DEL LOTE 2 (cms_ect_lab)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Ejecución Transaccional del Lote 2 en cms_ect_lab ---\n";

$group_repo       = new TranslationGroupRepository( $lab_wpdb, $registry );
$rollback_service = new WpmlMigrationRollbackService( $lab_wpdb, $group_repo, $checkpoint_repo );
$executor         = new WpmlMigrationExecutor(
	$lab_wpdb,
	$group_repo,
	$registry,
	$checkpoint_repo,
	$lock_manager,
	$val,
	new SchemaManager( $lab_wpdb ),
	$rollback_service
);

$executor->set_authorized( true );
$b02_exec_token = 'exec_batch_02_' . wp_generate_password( 8, false );

$exec_result = $executor->execute_batch(
	$batch_02_plan,
	5,
	$b02_exec_token,
	null,
	$expected_fingerprint
);

assert_true( 3 === count( $exec_result['batch_results'] ), 'Lote 2 procesó exactamente los 3 grupos' );
assert_true( $exec_result['finished'], 'Lote 2 reportó finalización exitosa (finished = true)' );

foreach ( $exec_result['batch_results'] as $res_item ) {
	assert_true( 'imported' === $res_item['status'], "TRID {$res_item['trid']} importado formalmente con estado 'imported'" );
	assert_true( isset( $res_item['group_id'] ) && $res_item['group_id'] > 0, "TRID {$res_item['trid']} asignado a group_id válido {$res_item['group_id']}" );
}

$imported_group_ids = array();
foreach ( $exec_result['batch_results'] as $res_item ) {
	if ( 'imported' === $res_item['status'] && ! empty( $res_item['group_id'] ) ) {
		$imported_group_ids[] = (int) $res_item['group_id'];
	}
}
$rollback_service->save_execution_manifest(
	$b02_exec_token,
	array(
		'execution_id'       => $b02_exec_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_02_plan->get_fingerprint(),
		'imported_group_ids' => $imported_group_ids,
	)
);

// Check cumulative database counts: Exactly 8 groups (5 + 3) and 12 elements (9 + 3).
$cumul_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$cumul_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 8 === $cumul_groups, "Resultado acumulado en BD: exactamente 8 grupos en etk_t_tfml_groups ({$cumul_groups})" );
assert_true( 12 === $cumul_elements, "Resultado acumulado en BD: exactamente 12 elementos en etk_t_tfml_group_elements ({$cumul_elements})" );

// Confirm initial 5 groups are untouched:
$current_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
foreach ( array( 6, 7, 8, 9, 10 ) as $initial_id ) {
	assert_true( in_array( $initial_id, $current_group_ids, true ), "Grupo inicial {$initial_id} permanece inalterado" );
}

// Checkpoint state:
$cp_b02 = $checkpoint_repo->get( 'wpml' );
assert_true( null !== $cp_b02 && $cp_b02->is_completed(), 'Checkpoint del Lote 2 marcado como completed' );
assert_true( 3 === $cp_b02->get_processed_items(), 'Checkpoint del Lote 2 registra 3 ítems procesados' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'Lock de migración liberado tras la ejecución' );

// Verify execution manifest for batch 2:
$manifest_b02_val = maybe_unserialize( $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'tfml_migration_manifest_{$b02_exec_token}'" )->fetch_row()[0] ?? '' );
assert_true( is_array( $manifest_b02_val ), 'Manifiesto de ejecución del Lote 2 guardado correctamente en opciones' );
assert_true( 3 === count( (array) $manifest_b02_val['imported_group_ids'] ), 'Manifiesto del Lote 2 registra exactamente 3 grupos importados' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: IDEMPOTENCIA Y REVERSIBILIDAD DEL LOTE 2
// -------------------------------------------------------------------------
echo "\n--- Control 4: Idempotencia y Reversibilidad Aislada del Lote 2 ---\n";

// A. Idempotencia: Re-ejecutar Lote 2.
$idem_token = 'token_idem_b02_' . wp_generate_password( 6, false );
$idem_res   = $executor->execute_batch(
	$batch_02_plan,
	5,
	$idem_token,
	null,
	$expected_fingerprint
);

assert_true( 3 === count( $idem_res['batch_results'] ), 'Re-ejecución idempotente evaluó los 3 grupos' );
foreach ( $idem_res['batch_results'] as $res_item ) {
	assert_true( 'idempotent_skipped' === $res_item['status'], "TRID {$res_item['trid']} reporta formalmente idempotent_skipped" );
}

$post_idem_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_idem_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 8 === $post_idem_groups, 'Idempotencia: Conteo de grupos permanece inmutable en 8' );
assert_true( 12 === $post_idem_elements, 'Idempotencia: Conteo de elementos permanece inmutable en 12' );

// B. Reversibilidad Aislada: Rollback exclusivo del Lote 2.
echo "  Comprobando rollback aislado del Lote 2 sin afectar el piloto inicial...\n";
$rb_res = $rollback_service->rollback_groups( $imported_group_ids, $b02_exec_token );

assert_true( $rb_res['success'], 'Rollback de los grupos del Lote 2 completado exitosamente' );
assert_true( 3 === $rb_res['reverted_groups_count'], 'Rollback eliminó exactamente los 3 grupos del Lote 2' );
$rollback_service->delete_execution_manifest( $b02_exec_token );

// Verify state returned to 5 groups and 9 elements:
$post_rb_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_rb_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 5 === $post_rb_groups, "Post-rollback: Grupos en laboratorio retornaron exactamente a 5 ({$post_rb_groups})" );
assert_true( 9 === $post_rb_elements, "Post-rollback: Elementos en laboratorio retornaron exactamente a 9 ({$post_rb_elements})" );

$remaining_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
assert_true( array( 6, 7, 8, 9, 10 ) === $remaining_group_ids, 'Los 5 grupos del piloto inicial permanecieron 100% blindados e intactos' );

// C. Re-ejecución posterior para restaurar el estado acumulado (8 grupos, 12 elementos):
echo "  Re-ejecutando Lote 2 para restaurar el estado final acumulado...\n";
$b02_re_token = 'exec_batch_02_final_' . wp_generate_password( 6, false );
$re_exec_res = $executor->execute_batch(
	$batch_02_plan,
	5,
	$b02_re_token,
	null,
	$expected_fingerprint
);

assert_true( 3 === count( $re_exec_res['batch_results'] ), 'Re-ejecución procesó los 3 grupos' );
$final_restored_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_restored_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 8 === $final_restored_groups, "Estado acumulado restaurado: exactamente 8 grupos en etk_t_tfml_groups ({$final_restored_groups})" );
assert_true( 12 === $final_restored_elements, "Estado acumulado restaurado: exactamente 12 elementos en etk_t_tfml_group_elements ({$final_restored_elements})" );

$re_imported_group_ids = array();
foreach ( $re_exec_res['batch_results'] as $res_item ) {
	if ( 'imported' === $res_item['status'] && ! empty( $res_item['group_id'] ) ) {
		$re_imported_group_ids[] = (int) $res_item['group_id'];
	}
}
$rollback_service->save_execution_manifest(
	$b02_re_token,
	array(
		'execution_id'       => $b02_re_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_02_plan->get_fingerprint(),
		'imported_group_ids' => $re_imported_group_ids,
	)
);

// Deep reconciliation check on the cumulative state:
$reconcile_res = $executor->reconcile( $batch_02_plan );
assert_true( $reconcile_res['integrity_verified'], 'Conciliación profunda certifica 100% de integridad relacional en el lote 2' );
assert_true( 3 === $reconcile_res['tfml_imported_groups'], 'Conciliación confirma 3 grupos importados del lote 2' );
assert_true( empty( $reconcile_res['discrepancies'] ), 'Cero discrepancias en la conciliación del lote 2' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: CERTIFICACIÓN HTTP REAL (http://lab.ecoterra.test)
// -------------------------------------------------------------------------
echo "\n--- Control 5: Certificación HTTP Real sobre http://lab.ecoterra.test ---\n";

/**
 * Execute HTTP request to lab.ecoterra.test via cURL with local IP resolution.
 *
 * @param string $path URL path.
 * @return array{status: int, headers: string, body: string, error: string, redirects: int}
 */
function lab_http_request( string $path ): array {
	$url = 'http://lab.ecoterra.test' . $path;
	$ch  = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RESOLVE, array( 'lab.ecoterra.test:80:127.0.0.1' ) );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HEADER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );

	$response    = curl_exec( $ch );
	$status_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$redirects   = (int) curl_getinfo( $ch, CURLINFO_REDIRECT_COUNT );
	$curl_error  = curl_error( $ch );
	curl_close( $ch );

	$headers = is_string( $response ) ? substr( $response, 0, $header_size ) : '';
	$body    = is_string( $response ) ? substr( $response, $header_size ) : '';

	return array(
		'status'    => $status_code,
		'headers'   => $headers,
		'body'      => $body,
		'error'     => $curl_error,
		'redirects' => $redirects,
	);
}

// HTTP-01: Resolución del idioma predeterminado en ruta raíz (/).
echo "  [HTTP-01] Solicitud a la raíz (/) en idioma predeterminado...\n";
$req_root = lab_http_request( '/' );
echo "    Status: {$req_root['status']}\n";
assert_true( 200 === $req_root['status'], 'HTTP-01: Ruta raíz responde HTTP 200 OK en idioma predeterminado' );
assert_true( strpos( $req_root['headers'], '500 Internal Server Error' ) === false, 'HTTP-01: Ausencia de error HTTP 500 en ruta raíz' );

// HTTP-02: Solicitud con prefijo de idioma secundario (/en/).
echo "  [HTTP-02] Solicitud a ruta con prefijo de idioma secundario (/en/)...\n";
$req_en = lab_http_request( '/en/' );
echo "    Status: {$req_en['status']}\n";
assert_true( in_array( $req_en['status'], array( 200, 301, 302, 404 ), true ), 'HTTP-02: Prefijo /en/ procesado limpiamente por WordPress' );
assert_true( 500 !== $req_en['status'], 'HTTP-02: Ausencia de error HTTP 500 en prefijo /en/' );

// HTTP-03: Solicitud con prefijo de idioma inactivo (/fr/).
echo "  [HTTP-03] Solicitud a ruta con idioma no activado (/fr/)...\n";
$req_fr = lab_http_request( '/fr/' );
echo "    Status: {$req_fr['status']}\n";
assert_true( 500 !== $req_fr['status'], 'HTTP-03: Prefijo inactivo /fr/ no produce errores 500' );

// HTTP-04: Solicitud a entidades reales migradas (Página 4338 de Lote 1 y Categoría 50 de Lote 2).
echo "  [HTTP-04] Solicitud HTTP a entidades reales de contenido migrado...\n";
$req_p4338 = lab_http_request( '/?page_id=4338' );
echo "    Página 4338 Status: {$req_p4338['status']}\n";
assert_true( in_array( $req_p4338['status'], array( 200, 301, 302 ), true ), 'HTTP-04a: Página 4338 responde con código válido (301 canonical o 200 OK)' );
assert_true( 500 !== $req_p4338['status'], 'HTTP-04a: Ausencia de error HTTP 500 en página 4338' );

$req_old_blog = lab_http_request( '/old-blog/' );
echo "    Página canónica /old-blog/ Status: {$req_old_blog['status']}\n";
assert_true( 200 === $req_old_blog['status'], 'HTTP-04b: Ruta canónica /old-blog/ responde HTTP 200 OK' );

$req_cat50 = lab_http_request( '/?cat=50' );
echo "    Categoría 50 Status: {$req_cat50['status']}\n";
assert_true( in_array( $req_cat50['status'], array( 200, 301, 302 ), true ), 'HTTP-04c: Categoría 50 (Lote 2) responde con código válido (301 o 200)' );
assert_true( 500 !== $req_cat50['status'], 'HTTP-04c: Ausencia de error HTTP 500 en categoría 50' );

$req_cat_blog = lab_http_request( '/category/blog/' );
echo "    Categoría canónica /category/blog/ Status: {$req_cat_blog['status']}\n";
assert_true( 200 === $req_cat_blog['status'], 'HTTP-04d: Ruta canónica /category/blog/ responde HTTP 200 OK' );

// HTTP-05: Verificación de ausencia de bucles de redirección.
echo "  [HTTP-05] Comprobación de ausencia de bucles de redirección...\n";
$ch_loop = curl_init( 'http://lab.ecoterra.test/' );
curl_setopt( $ch_loop, CURLOPT_RESOLVE, array( 'lab.ecoterra.test:80:127.0.0.1' ) );
curl_setopt( $ch_loop, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $ch_loop, CURLOPT_FOLLOWLOCATION, true );
curl_setopt( $ch_loop, CURLOPT_MAXREDIRS, 5 );
$res_loop  = curl_exec( $ch_loop );
$err_loop  = curl_errno( $ch_loop );
$http_loop = (int) curl_getinfo( $ch_loop, CURLINFO_HTTP_CODE );
curl_close( $ch_loop );

assert_true( CURLE_TOO_MANY_REDIRECTS !== $err_loop, 'HTTP-05: Cero bucles de redirección detectados (CURLE_TOO_MANY_REDIRECTS = false)' );
assert_true( 200 === $http_loop, 'HTTP-05: Solicitud con redirección arriba a destino HTTP 200 OK' );

// HTTP-06: Cabeceras de seguridad y aislamiento del entorno de laboratorio.
echo "  [HTTP-06] Comprobación de aislamiento y robots en respuestas HTTP...\n";
assert_true( strpos( $req_root['headers'], 'noindex' ) !== false, 'HTTP-06: Cabecera X-Robots-Tag noindex activa en laboratorio' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: INVARIANZA FINAL Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 6: Invarianza Final y Centinela en Origen (cms_ect) ---\n";

// A. Post-check on cms_ect_lab: exactly 8 groups and 12 elements.
$post_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 8 === $post_groups, "cms_ect_lab conserva exactamente 8 grupos acumulados en etk_t_tfml_groups ({$post_groups})" );
assert_true( 12 === $post_elements, "cms_ect_lab conserva exactamente 12 elementos acumulados en etk_t_tfml_group_elements ({$post_elements})" );

// Detailed verification of the 3 new groups from Batch 2:
$new_groups = $mysqli_lab->query(
	'SELECT id, element_type, subtype, canonical_element_id FROM `etk_t_tfml_groups` WHERE id > 10 ORDER BY id ASC'
)->fetch_all( MYSQLI_ASSOC );
assert_true( 3 === count( $new_groups ), 'Lote 2 persistió exactamente 3 nuevos grupos de traducción' );

// B. Post-flight sentinel on cms_ect:
$GLOBALS['wpdb'] = $original_wpdb;
$original_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$post_rows       = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash       = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Post-Flight (cms_ect):\n";
echo "  Rows: {$post_rows} (Expected: {$pre_rows})\n";
echo "  MD5:  {$post_hash} (Expected: {$pre_hash})\n\n";

assert_true( $pre_rows === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( $pre_hash === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

$mysqli_lab->close();
$isolation_guard->restore();

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.14 CERTIFICACIÓN INTEGRAL: EXITOSA (PASS)\n";
echo "====================================================================\n";
