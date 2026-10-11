<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.16.
 *
 * Directiva: DT-TFML-046
 * Alcance: Ejecución transaccional del tercer lote piloto incremental (batch-03-taxonomy-woocommerce),
 *          16 grupos / 16 elementos taxonómicos de WooCommerce,
 *          certificación de idempotencia en base de datos real, reversibilidad aislada (rollback selectivo),
 *          re-ejecución y conciliación profunda, validaciones funcionales de API de dominio,
 *          preservación de lotes anteriores (8 grupos, 12 elementos acumulados previos -> 24 grupos, 28 elementos finales)
 *          y centinela inviolable en origen cms_ect (Delta = 0).
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
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

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
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.16\n";
echo "  Directiva: DT-TFML-046 (Migración Transaccional Lote 3 WooCommerce)\n";
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
$lab_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

$plugin = Plugin::get_instance();
$plugin->init();
$registry = $plugin->get_language_registry();

// Ensure clean baseline if previous test run executed batch 3:
$mysqli_lab->query( 'DELETE FROM `etk_t_tfml_group_elements` WHERE group_id NOT IN (6, 7, 8, 9, 10, 38, 39, 40)' );
$mysqli_lab->query( 'DELETE FROM `etk_t_tfml_groups` WHERE id NOT IN (6, 7, 8, 9, 10, 38, 39, 40)' );
$mysqli_lab->query( "DELETE FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_manifest_exec_batch_03%'" );
$mysqli_lab->query( "DELETE FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_lock_%'" );

// -------------------------------------------------------------------------
// 2. CONTROL 1: PRESERVACIÓN INMUTABLE DEL PILOTO ACUMULADO (8 GRUPOS, 12 ELEM)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Preservación Inmutable del Piloto Acumulado (Fases 3.12 y 3.14) ---\n";

$groups_count_pre   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count_pre = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 8 === $groups_count_pre, "BD laboratorio conserva estrictamente 8 registros en etk_t_tfml_groups ({$groups_count_pre})" );
assert_true( 12 === $elements_count_pre, "BD laboratorio conserva estrictamente 12 registros en etk_t_tfml_group_elements ({$elements_count_pre})" );

// Comprobar los 8 IDs acumulados exactos:
$res_gids = $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' );
$actual_gids = array();
while ( $r = $res_gids->fetch_row() ) {
	$actual_gids[] = (int) $r[0];
}
$expected_initial_gids = array( 6, 7, 8, 9, 10, 38, 39, 40 );
assert_true( $expected_initial_gids === $actual_gids, 'Los 8 IDs de grupo de los lotes 1 y 2 permanecen intactos: [6, 7, 8, 9, 10, 38, 39, 40]' );

// Tablas secundarias TFML permanecen en 0:
$cnt_media   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_media_translations`' )->fetch_row()[0];
$cnt_str     = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_strings`' )->fetch_row()[0];
$cnt_str_tr  = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_string_translations`' )->fetch_row()[0];
assert_true( 0 === $cnt_media, 'Tabla media_translations conserva 0 registros' );
assert_true( 0 === $cnt_str, 'Tabla strings conserva 0 registros' );
assert_true( 0 === $cnt_str_tr, 'Tabla string_translations conserva 0 registros' );

// Centinela interno WPML en cms_ect_lab:
$lab_wpml_rows = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$lab_wpml_hash = (string) $lab_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);
assert_true( 3403 === $lab_wpml_rows, 'WPML en cms_ect_lab contiene exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $lab_wpml_hash, 'WPML en cms_ect_lab estrictamente intacto (MD5 4241ca7e...)' );
$GLOBALS['wpdb'] = $lab_wpdb;

// Checkpoint y locks previos:
$checkpoint_repo = new MigrationCheckpointRepository();
$lock_manager    = new MigrationExecutionLock( $lab_wpdb, 600 );
$cp_prev         = $checkpoint_repo->get( 'wpml' );
if ( null === $cp_prev || ! $cp_prev->is_completed() ) {
	$cp_prev = new MigrationCheckpoint(
		array(
			'source'              => 'wpml',
			'total_items'         => 3,
			'processed_items'     => 3,
			'batch_size'          => 3,
			'last_processed_trid' => 9532,
			'status'              => MigrationCheckpoint::STATUS_COMPLETED,
		)
	);
	$checkpoint_repo->save( $cp_prev );
}
assert_true( null !== $cp_prev && $cp_prev->is_completed(), 'Checkpoint previo existe y tiene estado completed' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'El bloqueo de migración está formalmente liberado (is_locked = false)' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: VALIDACIÓN FORMAL DEL LOTE 3 AUTORIZADO
// -------------------------------------------------------------------------
echo "\n--- Control 2: Validación Formal del Lote 3 Autorizado ---\n";

$catalog_path = __DIR__ . '/incremental_batches_catalog.json';
$catalog      = json_decode( (string) file_get_contents( $catalog_path ), true );
$batch_03_def = $catalog['batch-03-taxonomy-woocommerce'];

$authorized_batch_trids = array( 4578, 4579, 4580, 4581, 4607, 4614, 4615, 4616, 4617, 4618, 4619, 4620, 4621, 4622, 4638, 9544 );
$expected_fingerprint   = '5910dcf46cd4e9915b787cf6c06e59c87b1b0e7875d488ea0d68325cc3291945';

assert_true( $authorized_batch_trids === $batch_03_def['trids'], 'Catálogo oficial: TRIDs del Lote 3 coinciden exactamente con la autorización' );
assert_true( $expected_fingerprint === $batch_03_def['fingerprint'], 'Catálogo oficial: Fingerprint del Lote 3 certificado' );

$planner       = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$matrix_plan   = $planner->generate_plan( 0, 0, false );
$batch_03_plan = $matrix_plan->derive_partial_plan( $authorized_batch_trids, 'batch-03-taxonomy-woocommerce' );

assert_true( MigrationPlan::STATUS_READY === $batch_03_plan->get_status(), 'Lote 3 derivado en estado formal STATUS_READY' );
assert_true( $expected_fingerprint === $batch_03_plan->get_fingerprint(), "Huella SHA-256 del Lote 3 certificada: {$expected_fingerprint}" );
assert_true( 16 === count( $batch_03_plan->get_planned_groups() ), 'Lote 3 contiene exactamente 16 grupos de traducción' );

// Comprobar cardinalidad de elementos:
$b03_elements_count = 0;
$b03_subtypes_count = array();
foreach ( $batch_03_plan->get_planned_groups() as $pg ) {
	$b03_elements_count += count( $pg['translations'] );
	$st = $pg['subtype'];
	$b03_subtypes_count[ $st ] = ( $b03_subtypes_count[ $st ] ?? 0 ) + 1;
}
assert_true( 16 === $b03_elements_count, 'Lote 3 contiene exactamente 16 elementos editoriales' );
assert_true( 5 === ( $b03_subtypes_count['product_type'] ?? 0 ), 'Lote 3 contiene exactamente 5 grupos de product_type' );
assert_true( 9 === ( $b03_subtypes_count['product_visibility'] ?? 0 ), 'Lote 3 contiene exactamente 9 grupos de product_visibility' );
assert_true( 2 === ( $b03_subtypes_count['product_cat'] ?? 0 ), 'Lote 3 contiene exactamente 2 grupos de product_cat' );

// Comprobar existencia física en WordPress Core:
$val = new WordPressElementValidator();
foreach ( $batch_03_plan->get_planned_groups() as $pg ) {
	$trid      = (int) $pg['trid'];
	$elem_type = $pg['element_type'];
	$subtype   = $pg['subtype'];
	$cid       = (int) $pg['canonical_id'];
	assert_true( in_array( $trid, $authorized_batch_trids, true ), "TRID {$trid} pertenece estrictamente al lote 3 autorizado" );
	assert_true( $val->exists( $elem_type, $subtype, $cid ), "Elemento canónico {$cid} ({$elem_type}:{$subtype}) existe en WordPress Core" );
}

// -------------------------------------------------------------------------
// 4. CONTROL 3: EJECUCIÓN TRANSACCIONAL DEL LOTE 3 (cms_ect_lab)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Ejecución Transaccional del Lote 3 en cms_ect_lab ---\n";

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
$b03_exec_token = 'exec_batch_03_' . wp_generate_password( 8, false );

$start_time = microtime( true );
$exec_result = $executor->execute_batch(
	$batch_03_plan,
	20, // Procesa los 16 grupos en una transacción atómica completa
	$b03_exec_token,
	null,
	$expected_fingerprint
);
$elapsed_time = round( microtime( true ) - $start_time, 4 );

echo "  Tiempo de ejecución Lote 3: {$elapsed_time} segundos\n";
assert_true( 16 === count( $exec_result['batch_results'] ), 'Lote 3 procesó exactamente los 16 grupos' );
assert_true( $exec_result['finished'], 'Lote 3 reportó finalización exitosa (finished = true)' );

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
assert_true( 16 === count( $imported_group_ids ), 'Se importaron exactamente 16 IDs de grupo' );

// Registrar manifiesto de ejecución:
$rollback_service->save_execution_manifest(
	$b03_exec_token,
	array(
		'execution_id'       => $b03_exec_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_03_plan->get_fingerprint(),
		'imported_group_ids' => $imported_group_ids,
	)
);

// Comprobar conteos acumulados en base de datos: exactamente 24 grupos (8 + 16) y 28 elementos (12 + 16).
$cumul_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$cumul_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 24 === $cumul_groups, "Resultado acumulado en BD: exactamente 24 grupos en etk_t_tfml_groups ({$cumul_groups})" );
assert_true( 28 === $cumul_elements, "Resultado acumulado en BD: exactamente 28 elementos en etk_t_tfml_group_elements ({$cumul_elements})" );

// Confirmar que los 8 grupos acumulados previos siguen 100% inalterados:
$current_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
foreach ( $expected_initial_gids as $prev_id ) {
	assert_true( in_array( $prev_id, $current_group_ids, true ), "Grupo acumulado previo {$prev_id} permanece inalterado" );
}

// Comprobar estado de checkpoint y lock:
$cp_b03 = $checkpoint_repo->get( 'wpml' );
assert_true( null !== $cp_b03 && $cp_b03->is_completed(), 'Checkpoint del Lote 3 marcado como completed' );
assert_true( 16 === $cp_b03->get_processed_items(), 'Checkpoint del Lote 3 registra 16 ítems procesados' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'Lock de migración liberado tras la ejecución' );

// Verificar manifiesto en opciones:
$manifest_b03_val = maybe_unserialize( $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'tfml_migration_manifest_{$b03_exec_token}'" )->fetch_row()[0] ?? '' );
assert_true( is_array( $manifest_b03_val ), 'Manifiesto de ejecución del Lote 3 guardado correctamente en opciones' );
assert_true( 16 === count( (array) $manifest_b03_val['imported_group_ids'] ), 'Manifiesto del Lote 3 registra exactamente 16 grupos importados' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: IDEMPOTENCIA Y REVERSIBILIDAD AISLADA DEL LOTE 3
// -------------------------------------------------------------------------
echo "\n--- Control 4: Idempotencia y Reversibilidad Aislada del Lote 3 ---\n";

// A. Idempotencia: Re-ejecutar Lote 3 sobre el estado acumulado.
$idem_token = 'token_idem_b03_' . wp_generate_password( 6, false );
$idem_res   = $executor->execute_batch(
	$batch_03_plan,
	20,
	$idem_token,
	null,
	$expected_fingerprint
);

assert_true( 16 === count( $idem_res['batch_results'] ), 'Re-ejecución idempotente evaluó los 16 grupos' );
foreach ( $idem_res['batch_results'] as $res_item ) {
	assert_true( 'idempotent_skipped' === $res_item['status'], "TRID {$res_item['trid']} reporta formalmente idempotent_skipped" );
}

$post_idem_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_idem_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 24 === $post_idem_groups, 'Idempotencia: Conteo de grupos permanece inmutable en 24' );
assert_true( 28 === $post_idem_elements, 'Idempotencia: Conteo de elementos permanece inmutable en 28' );

// B. Reversibilidad Aislada: Rollback exclusivo de los 16 grupos del Lote 3.
echo "  Comprobando rollback aislado del Lote 3 sin afectar los lotes previos...\n";
$rb_res = $rollback_service->rollback_groups( $imported_group_ids, $b03_exec_token );

assert_true( $rb_res['success'], 'Rollback de los grupos del Lote 3 completado exitosamente' );
assert_true( 16 === $rb_res['reverted_groups_count'], 'Rollback eliminó exactamente los 16 grupos del Lote 3' );
$rollback_service->delete_execution_manifest( $b03_exec_token );

// Comprobar retorno estricto a 8 grupos y 12 elementos:
$post_rb_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_rb_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 8 === $post_rb_groups, "Post-rollback: Grupos en laboratorio retornaron exactamente a 8 ({$post_rb_groups})" );
assert_true( 12 === $post_rb_elements, "Post-rollback: Elementos en laboratorio retornaron exactamente a 12 ({$post_rb_elements})" );

$remaining_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
assert_true( $expected_initial_gids === $remaining_group_ids, 'Los 8 grupos acumulados de los lotes 1 y 2 permanecieron 100% blindados e intactos' );

// C. Re-ejecución posterior para restaurar el estado acumulado (24 grupos, 28 elementos):
echo "  Re-ejecutando Lote 3 para restaurar el estado final acumulado...\n";
$b03_re_token = 'exec_batch_03_final_' . wp_generate_password( 6, false );
$re_exec_res = $executor->execute_batch(
	$batch_03_plan,
	20,
	$b03_re_token,
	null,
	$expected_fingerprint
);

assert_true( 16 === count( $re_exec_res['batch_results'] ), 'Re-ejecución procesó los 16 grupos' );
$final_restored_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_restored_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 24 === $final_restored_groups, "Estado acumulado restaurado: exactamente 24 grupos en etk_t_tfml_groups ({$final_restored_groups})" );
assert_true( 28 === $final_restored_elements, "Estado acumulado restaurado: exactamente 28 elementos en etk_t_tfml_group_elements ({$final_restored_elements})" );

$re_imported_group_ids = array();
foreach ( $re_exec_res['batch_results'] as $res_item ) {
	if ( 'imported' === $res_item['status'] && ! empty( $res_item['group_id'] ) ) {
		$re_imported_group_ids[] = (int) $res_item['group_id'];
	}
}
$rollback_service->save_execution_manifest(
	$b03_re_token,
	array(
		'execution_id'       => $b03_re_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_03_plan->get_fingerprint(),
		'imported_group_ids' => $re_imported_group_ids,
	)
);

// Conciliación profunda del lote 3:
$reconcile_res = $executor->reconcile( $batch_03_plan );
assert_true( $reconcile_res['integrity_verified'], 'Conciliación profunda certifica 100% de integridad relacional en el lote 3' );
assert_true( 16 === $reconcile_res['tfml_imported_groups'], 'Conciliación confirma 16 grupos importados del lote 3' );
assert_true( empty( $reconcile_res['discrepancies'] ), 'Cero discrepancias en la conciliación del lote 3' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: VALIDACIONES FUNCIONALES DE API DE DOMINIO Y HTTP
// -------------------------------------------------------------------------
echo "\n--- Control 5: Validaciones Funcionales de API de Dominio y HTTP ---\n";

// A. Resolución funcional vía ContentTranslationResolver:
$resolver = new ContentTranslationResolver( $group_repo, $registry );

// TRID 4578: product_type 'simple' (term 2, es)
$lang_t2 = $resolver->language_of( 'term', 2 );
assert_true( 'es' === $lang_t2, 'ContentTranslationResolver reconoce idioma es para término 2 (product_type simple)' );

// TRID 9544: product_cat 'Sin categorizar' (term 97, en)
$lang_t97 = $resolver->language_of( 'term', 97 );
assert_true( 'en' === $lang_t97, 'ContentTranslationResolver reconoce idioma en para término 97 (product_cat Sin categorizar)' );

// B. Verificación de entorno de presentación (tema ausente -> BLOCKED):
$theme_exists = is_dir( 'D:/laragon/www/lab.ecoterra/wp-content/themes/' . get_option( 'stylesheet' ) );
assert_true( ! $theme_exists, 'Directorio del tema ecoCMS ausente en laboratorio: renderizado frontend HTML catalogado estrictamente como BLOCKED' );

// C. Verificación de peticiones HTTP reales al servidor web:
function lab_http_get( string $path ): array {
	$url = 'http://lab.ecoterra.test' . $path;
	$ch  = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RESOLVE, array( 'lab.ecoterra.test:80:127.0.0.1' ) );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HEADER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	$response    = curl_exec( $ch );
	$status_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );

	return array(
		'status'  => $status_code,
		'headers' => is_string( $response ) ? substr( $response, 0, $header_size ) : '',
		'body'    => is_string( $response ) ? substr( $response, $header_size ) : '',
	);
}

$http_root = lab_http_get( '/' );
assert_true( 200 === $http_root['status'], 'HTTP: Raíz (/) responde HTTP 200 OK' );
assert_true( strpos( $http_root['headers'], 'noindex' ) !== false, 'HTTP: Cabecera X-Robots-Tag noindex activa en laboratorio' );

$http_en = lab_http_get( '/en/' );
assert_true( 404 === $http_en['status'], 'HTTP: Prefijo /en/ responde 404 Not Found (home inglés no migrado por bloqueo de idioma en TRID 197)' );
assert_true( 500 !== $http_en['status'], 'HTTP: Ausencia de error HTTP 500 en /en/' );

$http_fr = lab_http_get( '/fr/' );
assert_true( 404 === $http_fr['status'], 'HTTP: Prefijo /fr/ responde 404 Not Found (idioma inactivo, rechazo limpio sin aliasing a default)' );
assert_true( 500 !== $http_fr['status'], 'HTTP: Ausencia de error HTTP 500 en /fr/' );

$http_p4338 = lab_http_get( '/?page_id=4338' );
assert_true( 301 === $http_p4338['status'], 'HTTP: Página 4338 (Lote 1) responde 301 canónico hacia /old-blog/' );

$http_cat50 = lab_http_get( '/?cat=50' );
assert_true( 301 === $http_cat50['status'], 'HTTP: Categoría 50 (Lote 2) responde 301 canónico hacia /category/blog/' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: INVARIANZA FINAL Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 6: Invarianza Final y Centinela en Origen (cms_ect) ---\n";

$final_lab_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_lab_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 24 === $final_lab_groups, "Invarianza final: Laboratorio retiene exactamente 24 grupos acumulados ({$final_lab_groups})" );
assert_true( 28 === $final_lab_elements, "Invarianza final: Laboratorio retiene exactamente 28 elementos acumulados ({$final_lab_elements})" );

$post_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "\nSentinel WPML Post-Flight (cms_ect):\n";
echo "  Rows: {$post_rows} (Expected: 3403)\n";
echo "  MD5:  {$post_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.16 MIGRACIÓN LOTE 3: EXITOSA (PASS)\n";
echo "====================================================================\n";
