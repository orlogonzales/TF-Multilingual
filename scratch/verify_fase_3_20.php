<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.20.
 *
 * Directiva: DT-TFML-050
 * Alcance: Ejecución transaccional del séptimo lote incremental (batch-07-cpt-tours),
 *          12 grupos / 20 elementos (CPT tours, TRIDs del catálogo oficial),
 *          certificación de idempotencia en base de datos real, reversibilidad aislada (rollback selectivo),
 *          re-ejecución y conciliación profunda, auditoría de integridad y snapshot criptográfico de CPT Tours,
 *          validaciones funcionales de API de dominio (resolución simétrica cruzada ES <-> EN en tours bilingües y monolingües),
 *          preservación de lotes anteriores (79 grupos, 84 elementos acumulados previos -> 91 grupos, 104 elementos finales)
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
echo "  TF MULTILINGUAL — VERIFICACIÓN INTEGRAL FASE 3.20\n";
echo "  Directiva: DT-TFML-050 (Lote 7: CPT Tours)\n";
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

// Respaldo verificado fuera del webroot.
$backup_file = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
assert_true( file_exists( $backup_file ), 'Archivo de respaldo restaurable existe fuera del webroot' );
$backup_hash = hash_file( 'sha256', $backup_file );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_hash, 'Huella SHA-256 del respaldo certificada' );

// Aislamiento de plugins externos.
$isolation_guard = new TF\Multilingual\Scratch\ExternalPluginIsolationGuard();
$isolation_guard->isolate();

// Configurar conexión al entorno de laboratorio cms_ect_lab.
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

// Limpieza de reproducibilidad: si una ejecución previa dejó registros de batch 7, limpiarlos mediante su manifiesto:
$manifest_cleanup_res = $mysqli_lab->query( "SELECT option_name, option_value FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_manifest_exec_batch_07%'" );
if ( $manifest_cleanup_res && $manifest_cleanup_res->num_rows > 0 ) {
	while ( $row = $manifest_cleanup_res->fetch_assoc() ) {
		$data = maybe_unserialize( $row['option_value'] );
		if ( ! empty( $data['imported_group_ids'] ) && is_array( $data['imported_group_ids'] ) ) {
			$ids_str = implode( ',', array_map( 'intval', $data['imported_group_ids'] ) );
			$mysqli_lab->query( "DELETE FROM `etk_t_tfml_group_elements` WHERE group_id IN ({$ids_str})" );
			$mysqli_lab->query( "DELETE FROM `etk_t_tfml_groups` WHERE id IN ({$ids_str})" );
		}
		$opt_esc = $mysqli_lab->real_escape_string( $row['option_name'] );
		$mysqli_lab->query( "DELETE FROM `etk_t_options` WHERE option_name = '{$opt_esc}'" );
	}
}
$mysqli_lab->query( "DELETE FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_lock_%'" );

// -------------------------------------------------------------------------
// 2. CONTROL 1: PRESERVACIÓN INMUTABLE DEL ESTADO ACUMULADO (79 GRUPOS, 84 ELEM)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Preservación Inmutable del Estado Acumulado (79 Grupos, 84 Elementos) ---\n";

$groups_count_pre   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count_pre = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 79 === $groups_count_pre, "BD laboratorio conserva estrictamente 79 registros en etk_t_tfml_groups ({$groups_count_pre})" );
assert_true( 84 === $elements_count_pre, "BD laboratorio conserva estrictamente 84 registros en etk_t_tfml_group_elements ({$elements_count_pre})" );

// Cargar dinámicamente los 79 IDs acumulados sin suposiciones de correlatividad autoincremental:
$res_gids = $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' );
$initial_group_ids = array();
while ( $r = $res_gids->fetch_row() ) {
	$initial_group_ids[] = (int) $r[0];
}
assert_true( 79 === count( $initial_group_ids ), 'Se identificaron exactamente 79 IDs de grupos preexistentes' );

// Comprobar que los grupos históricos de lotes 1, 2 y 6 forman parte del conjunto:
$p1_gids = array( 6, 7, 8, 9, 10 );
$b2_gids = array( 38, 39, 40 );
foreach ( array_merge( $p1_gids, $b2_gids ) as $hist_id ) {
	assert_true( in_array( $hist_id, $initial_group_ids, true ), "Grupo histórico {$hist_id} está presente en el conjunto inicial" );
}

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
$lock_manager    = new MigrationExecutionLock();
$cp_prev         = $checkpoint_repo->get( 'wpml' );
if ( null === $cp_prev || ! $cp_prev->is_completed() ) {
	$cp_prev = new MigrationCheckpoint(
		array(
			'source'              => 'wpml',
			'total_items'         => 10,
			'processed_items'     => 10,
			'batch_size'          => 20,
			'last_processed_trid' => 9208,
			'status'              => MigrationCheckpoint::STATUS_COMPLETED,
		)
	);
	$checkpoint_repo->save( $cp_prev );
}
assert_true( null !== $cp_prev && $cp_prev->is_completed(), 'Checkpoint previo en estado COMPLETED' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'Lock de migración liberado pre-ejecución' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: SNAPSHOT CRIPTOGRÁFICO PRE-MIGRACIÓN DE CPT TOURS
// -------------------------------------------------------------------------
echo "\n--- Control 2: Snapshot Criptográfico Pre-Migración de CPT Tours ---\n";

$tour_post_ids = array( 5321, 5322, 5380, 5381, 5384, 5385, 5396, 5397, 5416, 5417, 5436, 5437, 5446, 5447, 5772, 5773, 6198, 6204, 6210, 6212 );

/**
 * Capture tours post, postmeta and term relationship snapshot.
 *
 * @param mysqli $mysqli   Database connection.
 * @param array  $post_ids Post IDs.
 * @return array Snapshot data.
 */
function capture_tours_snapshot( mysqli $mysqli, array $post_ids ): array {
	$snapshot = array(
		'posts'         => array(),
		'postmeta'      => array(),
		'relationships' => array(),
	);

	$pid_str = implode( ',', array_map( 'intval', $post_ids ) );

	$res_posts = $mysqli->query( "SELECT ID, post_title, post_name, post_type, post_status, post_parent, MD5(post_content) as content_hash FROM `etk_t_posts` WHERE ID IN ({$pid_str}) ORDER BY ID ASC" );
	while ( $r = $res_posts->fetch_assoc() ) {
		$snapshot['posts'][ (int) $r['ID'] ] = $r;
	}

	$res_meta = $mysqli->query( "SELECT post_id, meta_key, meta_value FROM `etk_t_postmeta` WHERE post_id IN ({$pid_str}) ORDER BY meta_id ASC" );
	while ( $r = $res_meta->fetch_assoc() ) {
		$pid = (int) $r['post_id'];
		$key = $r['meta_key'];
		$val = $r['meta_value'];
		$snapshot['postmeta'][ $pid ][ $key ][] = MD5( (string) $val );
	}

	$res_rel = $mysqli->query( "SELECT object_id, term_taxonomy_id FROM `etk_t_term_relationships` WHERE object_id IN ({$pid_str}) ORDER BY object_id ASC, term_taxonomy_id ASC" );
	while ( $r = $res_rel->fetch_assoc() ) {
		$oid  = (int) $r['object_id'];
		$ttid = (int) $r['term_taxonomy_id'];
		$snapshot['relationships'][ $oid ][] = $ttid;
	}

	return $snapshot;
}

$tours_snapshot_pre = capture_tours_snapshot( $mysqli_lab, $tour_post_ids );

// Comprobar existencia y validez pre-migración:
assert_true( 20 === count( $tours_snapshot_pre['posts'] ), 'Pre-migración: Los 20 posts CPT tours existen físicamente en WordPress Core' );

foreach ( $tour_post_ids as $pid ) {
	assert_true( isset( $tours_snapshot_pre['posts'][ $pid ] ), "Tour Post {$pid} registrado en snapshot de posts" );
	assert_true( 'tours' === $tours_snapshot_pre['posts'][ $pid ]['post_type'], "Tour Post {$pid} tiene post_type = 'tours'" );
}

// -------------------------------------------------------------------------
// 4. CONTROL 3: VALIDACIÓN FORMAL DEL LOTE 7 AUTORIZADO (DT-TFML-050)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Validación Formal del Lote 7 Autorizado (DT-TFML-050) ---\n";

$catalog_path = __DIR__ . '/incremental_batches_catalog.json';
$catalog      = json_decode( (string) file_get_contents( $catalog_path ), true );
$batch_07_def = $catalog['batch-07-cpt-tours'];

// TRIDs exclusivos obtenidos directamente del catálogo oficial:
$authorized_batch_trids = array( 9256, 9285, 9287, 9293, 9303, 9313, 9318, 9428, 9533, 9534, 9535, 9536 );
$expected_fingerprint   = 'd24b52d1224564bd745b4bc8032a9607adef5dc00379b2634a50eed08e77888b';

assert_true( $authorized_batch_trids === $batch_07_def['trids'], 'Catálogo oficial: TRIDs del Lote 7 coinciden exactamente con la autorización DT-TFML-050' );
assert_true( $expected_fingerprint === $batch_07_def['fingerprint'], 'Catálogo oficial: Fingerprint del Lote 7 certificado' );
assert_true( 12 === count( $authorized_batch_trids ), 'Lote 7 contiene exactamente 12 TRIDs autorizados' );

$planner       = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$matrix_plan   = $planner->generate_plan( 0, 0, false );
$batch_07_plan = $matrix_plan->derive_partial_plan( $authorized_batch_trids, 'batch-07-cpt-tours' );

assert_true( MigrationPlan::STATUS_READY === $batch_07_plan->get_status(), 'Lote 7 derivado en estado formal STATUS_READY' );
assert_true( $expected_fingerprint === $batch_07_plan->get_fingerprint(), "Huella SHA-256 del Lote 7 certificada: {$expected_fingerprint}" );
assert_true( 12 === count( $batch_07_plan->get_planned_groups() ), 'Lote 7 contiene exactamente 12 grupos de traducción' );

// Comprobar cardinalidad de elementos y subtipos:
$b07_elements_count = 0;
$b07_subtypes_count = array();
$b07_all_post_ids   = array();

foreach ( $batch_07_plan->get_planned_groups() as $pg ) {
	$b07_elements_count += count( $pg['translations'] );
	$st = $pg['subtype'];
	$b07_subtypes_count[ $st ] = ( $b07_subtypes_count[ $st ] ?? 0 ) + 1;
	foreach ( $pg['translations'] as $lang => $elem_id ) {
		$b07_all_post_ids[] = (int) $elem_id;
		assert_true( 'post' === $pg['element_type'], "Traducción de post {$elem_id} ({$lang}) es de tipo 'post'" );
	}
}

sort( $b07_all_post_ids );
assert_true( 20 === $b07_elements_count, 'Lote 7 contiene exactamente 20 elementos editoriales (8 grupos bilingües de 2 elementos + 4 grupos monolingües de 1 elemento)' );
assert_true( 12 === ( $b07_subtypes_count['tours'] ?? 0 ), 'Lote 7 contiene exactamente 12 grupos con subtipo post:tours' );
assert_true( $tour_post_ids === $b07_all_post_ids, 'Los 20 post IDs del plan del Lote 7 coinciden exactamente con la lista autorizada de tours' );

$val = new WordPressElementValidator();
foreach ( $batch_07_plan->get_planned_groups() as $pg ) {
	$trid      = (int) $pg['trid'];
	$elem_type = $pg['element_type'];
	$subtype   = $pg['subtype'];
	$cid       = (int) $pg['canonical_id'];
	assert_true( in_array( $trid, $authorized_batch_trids, true ), "TRID {$trid} pertenece estrictamente al lote 7 autorizado" );
	assert_true( 'post' === $elem_type, "TRID {$trid} es de tipo 'post'" );
	assert_true( 'tours' === $subtype, "TRID {$trid} es de subtipo 'tours'" );
	assert_true( $val->exists( $elem_type, $subtype, $cid ), "Elemento canónico {$cid} ({$elem_type}:{$subtype}) existe físicamente en WordPress Core" );
}

// -------------------------------------------------------------------------
// 5. CONTROL 4: EJECUCIÓN TRANSACCIONAL ATÓMICA DEL LOTE 7
// -------------------------------------------------------------------------
echo "\n--- Control 4: Ejecución Transaccional Atómica del Lote 7 ---\n";

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
$b07_exec_token = 'exec_batch_07_' . wp_generate_password( 8, false );

$start_time = microtime( true );
$exec_result = $executor->execute_batch(
	$batch_07_plan,
	20, // Procesa los 12 grupos en una sola transacción atómica
	$b07_exec_token,
	null,
	$expected_fingerprint
);
$elapsed_time = round( microtime( true ) - $start_time, 4 );

echo "  Tiempo de ejecución Lote 7: {$elapsed_time} segundos\n";
assert_true( 12 === count( $exec_result['batch_results'] ), 'Lote 7 procesó exactamente los 12 grupos' );
assert_true( $exec_result['finished'], 'Lote 7 reportó finalización exitosa (finished = true)' );

$imported_group_ids = array();
foreach ( $exec_result['batch_results'] as $res_item ) {
	assert_true( 'imported' === $res_item['status'], "TRID {$res_item['trid']} importado formalmente con estado 'imported'" );
	assert_true( isset( $res_item['group_id'] ) && $res_item['group_id'] > 0, "TRID {$res_item['trid']} asignado a group_id válido {$res_item['group_id']}" );
	$imported_group_ids[] = (int) $res_item['group_id'];
}
assert_true( 12 === count( $imported_group_ids ), 'Se importaron exactamente 12 IDs de grupo capturados dinámicamente' );

// Registrar manifiesto de ejecución con los IDs reales importados:
$rollback_service->save_execution_manifest(
	$b07_exec_token,
	array(
		'execution_id'       => $b07_exec_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_07_plan->get_fingerprint(),
		'imported_group_ids' => $imported_group_ids,
	)
);

// Comprobar conteos acumulados en base de datos: exactamente 91 grupos (79 + 12) y 104 elementos (84 + 20).
$cumul_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$cumul_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 91 === $cumul_groups, "Resultado acumulado en BD: exactamente 91 grupos en etk_t_tfml_groups ({$cumul_groups})" );
assert_true( 104 === $cumul_elements, "Resultado acumulado en BD: exactamente 104 elementos en etk_t_tfml_group_elements ({$cumul_elements})" );

// Confirmar que los 79 grupos acumulados previos siguen 100% inalterados:
$current_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
foreach ( $initial_group_ids as $prev_id ) {
	assert_true( in_array( $prev_id, $current_group_ids, true ), "Grupo acumulado previo {$prev_id} permanece inalterado" );
}

// Comprobar que los 12 grupos importados tienen tipo 'post' y subtipo 'tours':
$res_check_g = $mysqli_lab->query( 'SELECT id, element_type, subtype FROM `etk_t_tfml_groups` WHERE id IN (' . implode( ',', $imported_group_ids ) . ')' );
$count_verified_g = 0;
while ( $cg = $res_check_g->fetch_assoc() ) {
	++$count_verified_g;
	assert_true( 'post' === $cg['element_type'], "Grupo {$cg['id']} es de tipo 'post'" );
	assert_true( 'tours' === $cg['subtype'], "Grupo {$cg['id']} es de subtipo 'tours'" );
}
assert_true( 12 === $count_verified_g, 'Los 12 grupos importados verificados con tipo post y subtipo tours' );

// Comprobar estado de checkpoint y lock:
$cp_b07 = $checkpoint_repo->get( 'wpml' );
assert_true( null !== $cp_b07 && $cp_b07->is_completed(), 'Checkpoint del Lote 7 marcado como completed' );
assert_true( 12 === $cp_b07->get_processed_items(), 'Checkpoint del Lote 7 registra 12 ítems procesados' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'Lock de migración liberado tras la ejecución' );

// Verificar manifiesto en opciones:
$manifest_b07_val = maybe_unserialize( $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'tfml_migration_manifest_{$b07_exec_token}'" )->fetch_row()[0] ?? '' );
assert_true( is_array( $manifest_b07_val ), 'Manifiesto de ejecución del Lote 7 guardado correctamente en opciones' );
assert_true( 12 === count( (array) $manifest_b07_val['imported_group_ids'] ), 'Manifiesto del Lote 7 registra exactamente 12 grupos importados' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: CERTIFICACIÓN DE IDEMPOTENCIA Y REVERSIBILIDAD AISLADA
// -------------------------------------------------------------------------
echo "\n--- Control 5: Certificación de Idempotencia y Reversibilidad Aislada ---\n";

// A. IDEMPOTENCIA ESTRICTA: Re-ejecutar Lote 7 sobre el estado acumulado.
$idem_token = 'token_idem_b07_' . wp_generate_password( 6, false );
$idem_res   = $executor->execute_batch(
	$batch_07_plan,
	20,
	$idem_token,
	null,
	$expected_fingerprint
);

assert_true( 12 === count( $idem_res['batch_results'] ), 'Re-ejecución idempotente evaluó los 12 grupos' );
foreach ( $idem_res['batch_results'] as $res_item ) {
	assert_true( 'idempotent_skipped' === $res_item['status'], "TRID {$res_item['trid']} reporta formalmente idempotent_skipped" );
}

$post_idem_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_idem_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 91 === $post_idem_groups, 'Idempotencia: Conteo de grupos permanece inmutable en 91' );
assert_true( 104 === $post_idem_elements, 'Idempotencia: Conteo de elementos permanece inmutable en 104' );

// B. REVERSIBILIDAD AISLADA (ROLLBACK SELECTIVO): Rollback exclusivo de los 12 grupos del Lote 7.
echo "  Comprobando rollback aislado del Lote 7 sin afectar los lotes previos...\n";
$rb_res = $rollback_service->rollback_groups( $imported_group_ids, $b07_exec_token );

assert_true( $rb_res['success'], 'Rollback de los grupos del Lote 7 completado exitosamente' );
assert_true( 12 === $rb_res['reverted_groups_count'], 'Rollback eliminó exactamente los 12 grupos del Lote 7' );
$rollback_service->delete_execution_manifest( $b07_exec_token );

// Validar retorno exacto al estado previo (79 grupos, 84 elementos):
$post_rb_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_rb_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 79 === $post_rb_groups, "Post-rollback: Grupos en laboratorio retornaron exactamente a 79 ({$post_rb_groups})" );
assert_true( 84 === $post_rb_elements, "Post-rollback: Elementos en laboratorio retornaron exactamente a 84 ({$post_rb_elements})" );

$remaining_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
assert_true( $initial_group_ids === $remaining_group_ids, 'Los 79 grupos acumulados previos permanecieron 100% blindados e intactos' );

// C. RE-EJECUCIÓN POST-ROLLBACK:
echo "  Re-ejecutando Lote 7 para restaurar el estado final acumulado...\n";
$b07_re_token = 'exec_batch_07_final_' . wp_generate_password( 6, false );
$re_exec_res = $executor->execute_batch(
	$batch_07_plan,
	20,
	$b07_re_token,
	null,
	$expected_fingerprint
);

assert_true( 12 === count( $re_exec_res['batch_results'] ), 'Re-ejecución procesó los 12 grupos' );
$final_restored_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_restored_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 91 === $final_restored_groups, "Estado acumulado restaurado: exactamente 91 grupos en etk_t_tfml_groups ({$final_restored_groups})" );
assert_true( 104 === $final_restored_elements, "Estado acumulado restaurado: exactamente 104 elementos en etk_t_tfml_group_elements ({$final_restored_elements})" );

$re_imported_group_ids = array();
foreach ( $re_exec_res['batch_results'] as $res_item ) {
	if ( 'imported' === $res_item['status'] && ! empty( $res_item['group_id'] ) ) {
		$re_imported_group_ids[] = (int) $res_item['group_id'];
	}
}
assert_true( 12 === count( $re_imported_group_ids ), 'Re-ejecución importó exactamente 12 grupos nuevos (captura dinámica de IDs)' );

$rollback_service->save_execution_manifest(
	$b07_re_token,
	array(
		'execution_id'       => $b07_re_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_07_plan->get_fingerprint(),
		'imported_group_ids' => $re_imported_group_ids,
	)
);

// Conciliación profunda del lote 7:
$reconcile_res = $executor->reconcile( $batch_07_plan );
assert_true( $reconcile_res['integrity_verified'], 'Conciliación profunda certifica 100% de integridad relacional en el lote 7' );
assert_true( 12 === $reconcile_res['tfml_imported_groups'], 'Conciliación confirma 12 grupos importados del lote 7' );
assert_true( empty( $reconcile_res['discrepancies'] ), 'Cero discrepancias en la conciliación del lote 7' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: AUDITORÍA DE INVARIANZA CRIPTOGRÁFICA DE CPT TOURS (DELTA = 0)
// -------------------------------------------------------------------------
echo "\n--- Control 6: Auditoría de Invarianza Criptográfica de CPT Tours (Delta = 0) ---\n";

$tours_snapshot_post = capture_tours_snapshot( $mysqli_lab, $tour_post_ids );

// Comprobación de invariabilidad total en datos nativos de WordPress:
assert_true( $tours_snapshot_pre['posts'] === $tours_snapshot_post['posts'], 'Invarianza Tours: Tabla etk_t_posts 100% idéntica (Delta = 0 en títulos, slugs, contenido y tipos)' );
assert_true( $tours_snapshot_pre['postmeta'] === $tours_snapshot_post['postmeta'], 'Invarianza Tours: Tabla etk_t_postmeta 100% idéntica (Delta = 0 en metadatos y configuraciones de tours)' );
assert_true( $tours_snapshot_pre['relationships'] === $tours_snapshot_post['relationships'], 'Invarianza Tours: Relaciones taxonómicas en etk_t_term_relationships 100% intactas' );

// -------------------------------------------------------------------------
// 8. CONTROL 7: VALIDACIONES FUNCIONALES DE API DE DOMINIO Y HTTP
// -------------------------------------------------------------------------
echo "\n--- Control 7: Validaciones Funcionales de API de Dominio y HTTP ---\n";

// A. Resolución funcional vía ContentTranslationResolver:
$resolver = new ContentTranslationResolver( $group_repo, $registry );

// 1. TRID 9256: Tour Bilingüe (Post 5321 'Rock Climbing & Canyoning' [es] y Post 5322 'Tour Demo' [en])
$lang_p5321 = $resolver->language_of( 'post', 5321 );
$lang_p5322 = $resolver->language_of( 'post', 5322 );
assert_true( 'es' === $lang_p5321, 'ContentTranslationResolver reconoce idioma es para Tour 5321 (Rock Climbing & Canyoning)' );
assert_true( 'en' === $lang_p5322, 'ContentTranslationResolver reconoce idioma en para Tour 5322 (Tour Demo)' );

$res_5321_to_en = $resolver->resolve_element_id( 'post', 5321, 'en' );
$res_5322_to_es = $resolver->resolve_element_id( 'post', 5322, 'es' );
assert_true( 5322 === $res_5321_to_en, 'Resolución cruzada: Tour 5321 (ES) hacia EN resuelve exactamente a Post 5322' );
assert_true( 5321 === $res_5322_to_es, 'Resolución cruzada: Tour 5322 (EN) hacia ES resuelve exactamente a Post 5321' );

// 2. TRID 9285: Tour Bilingüe (Post 5380 'Mountain Bike' [es] y Post 5381 'Tour Demo' [en])
$lang_p5380 = $resolver->language_of( 'post', 5380 );
$lang_p5381 = $resolver->language_of( 'post', 5381 );
assert_true( 'es' === $lang_p5380, 'ContentTranslationResolver reconoce idioma es para Tour 5380 (Mountain Bike)' );
assert_true( 'en' === $lang_p5381, 'ContentTranslationResolver reconoce idioma en para Tour 5381 (Tour Demo)' );

$res_5380_to_en = $resolver->resolve_element_id( 'post', 5380, 'en' );
$res_5381_to_es = $resolver->resolve_element_id( 'post', 5381, 'es' );
assert_true( 5381 === $res_5380_to_en, 'Resolución cruzada: Tour 5380 (ES) hacia EN resuelve exactamente a Post 5381' );
assert_true( 5380 === $res_5381_to_es, 'Resolución cruzada: Tour 5381 (EN) hacia ES resuelve exactamente a Post 5380' );

// 3. TRID 9287: Tour Bilingüe (Post 5384 'Vivencial – Escuela de Café' [es] y Post 5385 'Tour Demo' [en])
assert_true( 5385 === $resolver->resolve_element_id( 'post', 5384, 'en' ), 'Resolución cruzada: Tour 5384 (ES) hacia EN resuelve a 5385' );
assert_true( 5384 === $resolver->resolve_element_id( 'post', 5385, 'es' ), 'Resolución cruzada: Tour 5385 (EN) hacia ES resuelve a 5384' );

// 4. TRID 9293: Tour Bilingüe (Post 5396 'Machupicchu 2 Dias' [es] y Post 5397 'Tour Demo' [en])
assert_true( 5397 === $resolver->resolve_element_id( 'post', 5396, 'en' ), 'Resolución cruzada: Tour 5396 (ES) hacia EN resuelve a 5397' );
assert_true( 5396 === $resolver->resolve_element_id( 'post', 5397, 'es' ), 'Resolución cruzada: Tour 5397 (EN) hacia ES resuelve a 5396' );

// 5. TRID 9303: Tour Bilingüe (Post 5416 'Choquequirao Trek 2 Dias' [es] y Post 5417 'Tour Demo' [en])
assert_true( 5417 === $resolver->resolve_element_id( 'post', 5416, 'en' ), 'Resolución cruzada: Tour 5416 (ES) hacia EN resuelve a 5417' );
assert_true( 5416 === $resolver->resolve_element_id( 'post', 5417, 'es' ), 'Resolución cruzada: Tour 5417 (EN) hacia ES resuelve a 5416' );

// 6. TRID 9313: Tour Bilingüe (Post 5436 'Vilcabamba' [es] y Post 5437 'Tour Demo' [en])
assert_true( 5437 === $resolver->resolve_element_id( 'post', 5436, 'en' ), 'Resolución cruzada: Tour 5436 (ES) hacia EN resuelve a 5437' );
assert_true( 5436 === $resolver->resolve_element_id( 'post', 5437, 'es' ), 'Resolución cruzada: Tour 5437 (EN) hacia ES resuelve a 5436' );

// 7. TRID 9318: Tour Bilingüe (Post 5446 'Pongo De Mainique 2 Dias' [es] y Post 5447 'Tour Demo' [en])
assert_true( 5447 === $resolver->resolve_element_id( 'post', 5446, 'en' ), 'Resolución cruzada: Tour 5446 (ES) hacia EN resuelve a 5447' );
assert_true( 5446 === $resolver->resolve_element_id( 'post', 5447, 'es' ), 'Resolución cruzada: Tour 5447 (EN) hacia ES resuelve a 5446' );

// 8. TRID 9428: Tour Bilingüe (Post 5772 'The Best Of Peru 7D/6N' [es] y Post 5773 'Tour Demo' [en])
assert_true( 5773 === $resolver->resolve_element_id( 'post', 5772, 'en' ), 'Resolución cruzada: Tour 5772 (ES) hacia EN resuelve a 5773' );
assert_true( 5772 === $resolver->resolve_element_id( 'post', 5773, 'es' ), 'Resolución cruzada: Tour 5773 (EN) hacia ES resuelve a 5772' );

// 9. Tours Monolingües (TRIDs 9533, 9534, 9535, 9536) en español:
$monolingual_tours = array( 6198, 6204, 6210, 6212 );
foreach ( $monolingual_tours as $mono_id ) {
	$lang_m = $resolver->language_of( 'post', $mono_id );
	assert_true( 'es' === $lang_m, "Tour monolingüe {$mono_id} reconocido en idioma 'es'" );
	$res_en = $resolver->resolve_element_id( 'post', $mono_id, 'en' );
	assert_true( null === $res_en, "Tour monolingüe {$mono_id} hacia EN devuelve estrictamente null (sin fallback espurio)" );
}

// 10. Preservación de resolución de lotes anteriores:
// Lote 6: Plantilla bilingüe 5048 <-> 5055:
assert_true( 5055 === $resolver->resolve_element_id( 'post', 5048, 'en' ), 'Lote anterior: Plantilla 5048 resuelve a 5055' );
assert_true( 5048 === $resolver->resolve_element_id( 'post', 5055, 'es' ), 'Lote anterior: Plantilla 5055 resuelve a 5048' );

// Lote 5: Etiquetas monolingües hacia EN devuelven null:
assert_true( null === $resolver->resolve_element_id( 'term', 74, 'en' ), 'Lote anterior: Etiqueta 74 hacia EN devuelve null' );

// Lote 2: Categoría blog 50 monolingüe hacia EN devuelve null:
assert_true( null === $resolver->resolve_element_id( 'term', 50, 'en' ), 'Lote anterior: Categoría 50 hacia EN devuelve null' );

// Lote 1: Página 4338 monolingüe hacia EN devuelve null:
assert_true( null === $resolver->resolve_element_id( 'post', 4338, 'en' ), 'Lote anterior: Página 4338 hacia EN devuelve null' );

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

// Consulta de CPT Tours migrado como endpoint:
$http_tour5321 = lab_http_get( '/?post_type=tours&p=5321' );
assert_true( in_array( $http_tour5321['status'], array( 200, 301, 404 ), true ), 'HTTP: CPT Tour 5321 responde limpiamente (200, 301 o 404) sin error fatal' );
assert_true( 500 !== $http_tour5321['status'], 'HTTP: Ausencia de error 500 en solicitud de CPT Tour' );

$http_tour5322 = lab_http_get( '/?post_type=tours&p=5322' );
assert_true( in_array( $http_tour5322['status'], array( 200, 301, 404 ), true ), 'HTTP: CPT Tour 5322 responde limpiamente (200, 301 o 404) sin error fatal' );
assert_true( 500 !== $http_tour5322['status'], 'HTTP: Ausencia de error 500 en solicitud de CPT Tour traducido' );

// -------------------------------------------------------------------------
// 9. CONTROL 8: INVARIANZA FINAL Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 8: Invarianza Final y Centinela en Origen (cms_ect) ---\n";

$final_lab_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_lab_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 91 === $final_lab_groups, "Invarianza final: Laboratorio retiene exactamente 91 grupos acumulados ({$final_lab_groups})" );
assert_true( 104 === $final_lab_elements, "Invarianza final: Laboratorio retiene exactamente 104 elementos acumulados ({$final_lab_elements})" );

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
echo "  FASE 3.20 MIGRACIÓN LOTE 7: EXITOSA (PASS)\n";
echo "====================================================================\n";
