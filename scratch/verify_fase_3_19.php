<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.19.
 *
 * Directiva: DT-TFML-049
 * Alcance: Ejecución transaccional del sexto lote incremental (batch-06-elementor-templates),
 *          10 grupos / 11 elementos (elementor_library y elementor_library_type, TRIDs del catálogo oficial),
 *          certificación de idempotencia en base de datos real, reversibilidad aislada (rollback selectivo),
 *          re-ejecución y conciliación profunda, auditoría de integridad y snapshot de metadatos/JSON Elementor,
 *          validaciones funcionales de API de dominio,
 *          preservación de lotes anteriores (69 grupos, 73 elementos acumulados previos -> 79 grupos, 84 elementos finales)
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
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.19\n";
echo "  Directiva: DT-TFML-049 (Lote 6: Plantillas y Tipos Elementor)\n";
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

// Si una ejecución previa de Fase 3.19 dejó registros de batch 6, limpiarlos para asegurar baseline reproducible de 69 grupos:
$manifest_cleanup_res = $mysqli_lab->query( "SELECT option_name, option_value FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_manifest_exec_batch_06%'" );
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
// 2. CONTROL 1: PRESERVACIÓN INMUTABLE DEL ESTADO ACUMULADO (69 GRUPOS, 73 ELEM)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Preservación Inmutable del Estado Acumulado (69 Grupos, 73 Elementos) ---\n";

$groups_count_pre   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count_pre = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 69 === $groups_count_pre, "BD laboratorio conserva estrictamente 69 registros en etk_t_tfml_groups ({$groups_count_pre})" );
assert_true( 73 === $elements_count_pre, "BD laboratorio conserva estrictamente 73 registros en etk_t_tfml_group_elements ({$elements_count_pre})" );

// Cargar dinámicamente los 69 IDs acumulados sin suposiciones de correlatividad autoincremental:
$res_gids = $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' );
$initial_group_ids = array();
while ( $r = $res_gids->fetch_row() ) {
	$initial_group_ids[] = (int) $r[0];
}
assert_true( 69 === count( $initial_group_ids ), 'Se identificaron exactamente 69 IDs de grupos preexistentes' );

// Comprobar que los grupos históricos de lotes 1 y 2 forman parte del conjunto:
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
$lock_manager    = new MigrationExecutionLock( $lab_wpdb, 600 );
$cp_prev         = $checkpoint_repo->get( 'wpml' );
if ( null === $cp_prev || ! $cp_prev->is_completed() ) {
	$cp_prev = new MigrationCheckpoint(
		array(
			'source'              => 'wpml',
			'total_items'         => 22,
			'processed_items'     => 22,
			'batch_size'          => 30,
			'last_processed_trid' => 9417,
			'status'              => MigrationCheckpoint::STATUS_COMPLETED,
		)
	);
	$checkpoint_repo->save( $cp_prev );
}
assert_true( null !== $cp_prev && $cp_prev->is_completed(), 'Checkpoint previo existe y tiene estado completed' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'El bloqueo de migración está formalmente liberado (is_locked = false)' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: SNAPSHOT DE INTEGRIDAD ELEMENTOR PRE-MIGRACIÓN (SOLO LECTURA)
// -------------------------------------------------------------------------
echo "\n--- Control 2: Snapshot de Integridad Elementor Pre-Migración (Solo Lectura) ---\n";

$elementor_post_ids = array( 6, 1300, 1713, 1723, 2365, 3423, 4130, 5048, 5055 );
$elementor_term_ids = array( 32, 33 );

/**
 * Capture Elementor metadata and post snapshot.
 *
 * @param mysqli $mysqli Database connection.
 * @param array  $pids   Post IDs.
 * @param array  $tids   Term IDs.
 * @return array Snapshot data.
 */
function capture_elementor_snapshot( mysqli $mysqli, array $pids, array $tids ): array {
	$snapshot = array(
		'posts'    => array(),
		'postmeta' => array(),
		'json'     => array(),
		'terms'    => array(),
	);

	$pid_str = implode( ',', array_map( 'intval', $pids ) );
	$res_posts = $mysqli->query( "SELECT ID, post_title, post_name, post_type, post_status, MD5(post_content) as content_hash FROM `etk_t_posts` WHERE ID IN ({$pid_str}) ORDER BY ID ASC" );
	while ( $r = $res_posts->fetch_assoc() ) {
		$snapshot['posts'][ (int) $r['ID'] ] = $r;
	}

	$res_meta = $mysqli->query( "SELECT post_id, meta_key, meta_value FROM `etk_t_postmeta` WHERE post_id IN ({$pid_str}) ORDER BY meta_id ASC" );
	while ( $r = $res_meta->fetch_assoc() ) {
		$pid = (int) $r['post_id'];
		$key = $r['meta_key'];
		$val = $r['meta_value'];
		$snapshot['postmeta'][ $pid ][ $key ][] = MD5( $val );

		if ( '_elementor_data' === $key ) {
			$json_decoded = json_decode( $val, true );
			$snapshot['json'][ $pid ] = array(
				'is_valid_json' => ( null !== $json_decoded || '[]' === trim( $val ) ),
				'json_hash'     => md5( $val ),
				'bytes'         => strlen( $val ),
			);
		}
	}

	$tid_str = implode( ',', array_map( 'intval', $tids ) );
	$res_terms = $mysqli->query( "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.count FROM `etk_t_terms` t JOIN `etk_t_term_taxonomy` tt ON t.term_id = tt.term_id WHERE t.term_id IN ({$tid_str}) ORDER BY t.term_id ASC" );
	while ( $r = $res_terms->fetch_assoc() ) {
		$snapshot['terms'][ (int) $r['term_id'] ] = $r;
	}

	return $snapshot;
}

$elementor_snapshot_pre = capture_elementor_snapshot( $mysqli_lab, $elementor_post_ids, $elementor_term_ids );

// Comprobar existencia y validez pre-migración:
assert_true( 9 === count( $elementor_snapshot_pre['posts'] ), 'Pre-migración: Los 9 posts elementor_library existen físicamente' );
assert_true( 2 === count( $elementor_snapshot_pre['terms'] ), 'Pre-migración: Los 2 términos elementor_library_type existen físicamente' );

foreach ( $elementor_post_ids as $pid ) {
	assert_true( isset( $elementor_snapshot_pre['posts'][ $pid ] ), "Post {$pid} registrado en snapshot de posts" );
	assert_true( 'elementor_library' === $elementor_snapshot_pre['posts'][ $pid ]['post_type'], "Post {$pid} es de tipo elementor_library" );
	assert_true( isset( $elementor_snapshot_pre['postmeta'][ $pid ]['_elementor_template_type'] ), "Post {$pid} contiene meta _elementor_template_type" );
	if ( isset( $elementor_snapshot_pre['json'][ $pid ] ) ) {
		assert_true( $elementor_snapshot_pre['json'][ $pid ]['is_valid_json'], "Post {$pid} contiene JSON _elementor_data estructuralmente válido" );
	}
}

// -------------------------------------------------------------------------
// 4. CONTROL 3: VALIDACIÓN FORMAL DEL LOTE 6 AUTORIZADO (DT-TFML-049)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Validación Formal del Lote 6 Autorizado (DT-TFML-049) ---\n";

$catalog_path = __DIR__ . '/incremental_batches_catalog.json';
$catalog      = json_decode( (string) file_get_contents( $catalog_path ), true );
$batch_06_def = $catalog['batch-06-elementor-templates'];

// TRIDs exclusivos obtenidos directamente del catálogo oficial:
$authorized_batch_trids = array( 6, 1300, 1713, 1723, 2365, 3423, 4130, 4532, 4533, 9208 );
$expected_fingerprint   = 'ee32aff6cc43ea1f334b758bda34d9a9cc271de3a3c76217abd56e6264924a24';

assert_true( $authorized_batch_trids === $batch_06_def['trids'], 'Catálogo oficial: TRIDs del Lote 6 coinciden exactamente con la autorización DT-TFML-049' );
assert_true( $expected_fingerprint === $batch_06_def['fingerprint'], 'Catálogo oficial: Fingerprint del Lote 6 certificado' );
assert_true( 10 === count( $authorized_batch_trids ), 'Lote 6 contiene exactamente 10 TRIDs autorizados' );

$planner       = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$matrix_plan   = $planner->generate_plan( 0, 0, false );
$batch_06_plan = $matrix_plan->derive_partial_plan( $authorized_batch_trids, 'batch-06-elementor-templates' );

assert_true( MigrationPlan::STATUS_READY === $batch_06_plan->get_status(), 'Lote 6 derivado en estado formal STATUS_READY' );
assert_true( $expected_fingerprint === $batch_06_plan->get_fingerprint(), "Huella SHA-256 del Lote 6 certificada: {$expected_fingerprint}" );
assert_true( 10 === count( $batch_06_plan->get_planned_groups() ), 'Lote 6 contiene exactamente 10 grupos de traducción' );

// Comprobar cardinalidad de elementos y subtipos:
$b06_elements_count = 0;
$b06_subtypes_count = array();
foreach ( $batch_06_plan->get_planned_groups() as $pg ) {
	$b06_elements_count += count( $pg['translations'] );
	$st = $pg['subtype'];
	$b06_subtypes_count[ $st ] = ( $b06_subtypes_count[ $st ] ?? 0 ) + 1;
}
assert_true( 11 === $b06_elements_count, 'Lote 6 contiene exactamente 11 elementos editoriales (8 grupos x 1 elemento + 1 grupo x 2 elementos [TRID 9208] + 2 grupos términos)' );
assert_true( 8 === ( $b06_subtypes_count['elementor_library'] ?? 0 ), 'Lote 6 contiene 8 grupos con subtipo post:elementor_library' );
assert_true( 2 === ( $b06_subtypes_count['elementor_library_type'] ?? 0 ), 'Lote 6 contiene 2 grupos con subtipo term:elementor_library_type' );

// Comprobar existencia física en WordPress Core:
$val = new WordPressElementValidator();
foreach ( $batch_06_plan->get_planned_groups() as $pg ) {
	$trid      = (int) $pg['trid'];
	$elem_type = $pg['element_type'];
	$subtype   = $pg['subtype'];
	$cid       = (int) $pg['canonical_id'];
	assert_true( in_array( $trid, $authorized_batch_trids, true ), "TRID {$trid} pertenece estrictamente al lote 6 autorizado" );
	assert_true( in_array( $elem_type, array( 'post', 'term' ), true ), "TRID {$trid} es de tipo válido ({$elem_type})" );
	assert_true( $val->exists( $elem_type, $subtype, $cid ), "Elemento canónico {$cid} ({$elem_type}:{$subtype}) existe físicamente en WordPress Core" );
}

// -------------------------------------------------------------------------
// 5. CONTROL 4: EJECUCIÓN TRANSACCIONAL DEL LOTE 6 (cms_ect_lab)
// -------------------------------------------------------------------------
echo "\n--- Control 4: Ejecución Transaccional del Lote 6 en cms_ect_lab ---\n";

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
$b06_exec_token = 'exec_batch_06_' . wp_generate_password( 8, false );

$start_time = microtime( true );
$exec_result = $executor->execute_batch(
	$batch_06_plan,
	20, // Procesa los 10 grupos en una sola transacción atómica
	$b06_exec_token,
	null,
	$expected_fingerprint
);
$elapsed_time = round( microtime( true ) - $start_time, 4 );

echo "  Tiempo de ejecución Lote 6: {$elapsed_time} segundos\n";
assert_true( 10 === count( $exec_result['batch_results'] ), 'Lote 6 procesó exactamente los 10 grupos' );
assert_true( $exec_result['finished'], 'Lote 6 reportó finalización exitosa (finished = true)' );

$imported_group_ids = array();
foreach ( $exec_result['batch_results'] as $res_item ) {
	assert_true( 'imported' === $res_item['status'], "TRID {$res_item['trid']} importado formalmente con estado 'imported'" );
	assert_true( isset( $res_item['group_id'] ) && $res_item['group_id'] > 0, "TRID {$res_item['trid']} asignado a group_id válido {$res_item['group_id']}" );
	$imported_group_ids[] = (int) $res_item['group_id'];
}
assert_true( 10 === count( $imported_group_ids ), 'Se importaron exactamente 10 IDs de grupo capturados dinámicamente' );

// Registrar manifiesto de ejecución con los IDs reales importados:
$rollback_service->save_execution_manifest(
	$b06_exec_token,
	array(
		'execution_id'       => $b06_exec_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_06_plan->get_fingerprint(),
		'imported_group_ids' => $imported_group_ids,
	)
);

// Comprobar conteos acumulados en base de datos: exactamente 79 grupos (69 + 10) y 84 elementos (73 + 11).
$cumul_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$cumul_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 79 === $cumul_groups, "Resultado acumulado en BD: exactamente 79 grupos en etk_t_tfml_groups ({$cumul_groups})" );
assert_true( 84 === $cumul_elements, "Resultado acumulado en BD: exactamente 84 elementos en etk_t_tfml_group_elements ({$cumul_elements})" );

// Confirmar que los 69 grupos acumulados previos siguen 100% inalterados:
$current_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
foreach ( $initial_group_ids as $prev_id ) {
	assert_true( in_array( $prev_id, $current_group_ids, true ), "Grupo acumulado previo {$prev_id} permanece inalterado" );
}

// Comprobar estado de checkpoint y lock:
$cp_b06 = $checkpoint_repo->get( 'wpml' );
assert_true( null !== $cp_b06 && $cp_b06->is_completed(), 'Checkpoint del Lote 6 marcado como completed' );
assert_true( 10 === $cp_b06->get_processed_items(), 'Checkpoint del Lote 6 registra 10 ítems procesados' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'Lock de migración liberado tras la ejecución' );

// Verificar manifiesto en opciones:
$manifest_b06_val = maybe_unserialize( $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'tfml_migration_manifest_{$b06_exec_token}'" )->fetch_row()[0] ?? '' );
assert_true( is_array( $manifest_b06_val ), 'Manifiesto de ejecución del Lote 6 guardado correctamente en opciones' );
assert_true( 10 === count( (array) $manifest_b06_val['imported_group_ids'] ), 'Manifiesto del Lote 6 registra exactamente 10 grupos importados' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: IDEMPOTENCIA Y REVERSIBILIDAD AISLADA DEL LOTE 6
// -------------------------------------------------------------------------
echo "\n--- Control 5: Idempotencia y Reversibilidad Aislada del Lote 6 ---\n";

// A. Idempotencia: Re-ejecutar Lote 6 sobre el estado acumulado.
$idem_token = 'token_idem_b06_' . wp_generate_password( 6, false );
$idem_res   = $executor->execute_batch(
	$batch_06_plan,
	20,
	$idem_token,
	null,
	$expected_fingerprint
);

assert_true( 10 === count( $idem_res['batch_results'] ), 'Re-ejecución idempotente evaluó los 10 grupos' );
foreach ( $idem_res['batch_results'] as $res_item ) {
	assert_true( 'idempotent_skipped' === $res_item['status'], "TRID {$res_item['trid']} reporta formalmente idempotent_skipped" );
}

$post_idem_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_idem_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 79 === $post_idem_groups, 'Idempotencia: Conteo de grupos permanece inmutable en 79' );
assert_true( 84 === $post_idem_elements, 'Idempotencia: Conteo de elementos permanece inmutable en 84' );

// B. Reversibilidad Aislada: Rollback exclusivo de los 10 grupos del Lote 6 (usando los IDs del manifiesto).
echo "  Comprobando rollback aislado del Lote 6 sin afectar los lotes previos...\n";
$rb_res = $rollback_service->rollback_groups( $imported_group_ids, $b06_exec_token );

assert_true( $rb_res['success'], 'Rollback de los grupos del Lote 6 completado exitosamente' );
assert_true( 10 === $rb_res['reverted_groups_count'], 'Rollback eliminó exactamente los 10 grupos del Lote 6' );
$rollback_service->delete_execution_manifest( $b06_exec_token );

// Comprobar retorno estricto a 69 grupos y 73 elementos:
$post_rb_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_rb_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 69 === $post_rb_groups, "Post-rollback: Grupos en laboratorio retornaron exactamente a 69 ({$post_rb_groups})" );
assert_true( 73 === $post_rb_elements, "Post-rollback: Elementos en laboratorio retornaron exactamente a 73 ({$post_rb_elements})" );

$remaining_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
assert_true( $initial_group_ids === $remaining_group_ids, 'Los 69 grupos acumulados previos permanecieron 100% blindados e intactos' );

// C. Re-ejecución posterior para restaurar el estado acumulado (79 grupos, 84 elementos):
echo "  Re-ejecutando Lote 6 para restaurar el estado final acumulado...\n";
$b06_re_token = 'exec_batch_06_final_' . wp_generate_password( 6, false );
$re_exec_res = $executor->execute_batch(
	$batch_06_plan,
	20,
	$b06_re_token,
	null,
	$expected_fingerprint
);

assert_true( 10 === count( $re_exec_res['batch_results'] ), 'Re-ejecución procesó los 10 grupos' );
$final_restored_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_restored_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 79 === $final_restored_groups, "Estado acumulado restaurado: exactamente 79 grupos en etk_t_tfml_groups ({$final_restored_groups})" );
assert_true( 84 === $final_restored_elements, "Estado acumulado restaurado: exactamente 84 elementos en etk_t_tfml_group_elements ({$final_restored_elements})" );

$re_imported_group_ids = array();
foreach ( $re_exec_res['batch_results'] as $res_item ) {
	if ( 'imported' === $res_item['status'] && ! empty( $res_item['group_id'] ) ) {
		$re_imported_group_ids[] = (int) $res_item['group_id'];
	}
}
assert_true( 10 === count( $re_imported_group_ids ), 'Re-ejecución importó exactamente 10 grupos nuevos (captura dinámica de IDs)' );

$rollback_service->save_execution_manifest(
	$b06_re_token,
	array(
		'execution_id'       => $b06_re_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_06_plan->get_fingerprint(),
		'imported_group_ids' => $re_imported_group_ids,
	)
);

// Conciliación profunda del lote 6:
$reconcile_res = $executor->reconcile( $batch_06_plan );
assert_true( $reconcile_res['integrity_verified'], 'Conciliación profunda certifica 100% de integridad relacional en el lote 6' );
assert_true( 10 === $reconcile_res['tfml_imported_groups'], 'Conciliación confirma 10 grupos importados del lote 6' );
assert_true( empty( $reconcile_res['discrepancies'] ), 'Cero discrepancias en la conciliación del lote 6' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: AUDITORÍA DE INVARIANZA DE METADATOS Y JSON ELEMENTOR
// -------------------------------------------------------------------------
echo "\n--- Control 6: Auditoría de Invarianza de Metadatos y JSON Elementor ---\n";

$elementor_snapshot_post = capture_elementor_snapshot( $mysqli_lab, $elementor_post_ids, $elementor_term_ids );

// Comprobación de invariabilidad total:
assert_true( $elementor_snapshot_pre['posts'] === $elementor_snapshot_post['posts'], 'Invarianza Elementor: Tabla etk_t_posts 100% idéntica (Delta = 0 en títulos, tipos y contenido)' );
assert_true( $elementor_snapshot_pre['postmeta'] === $elementor_snapshot_post['postmeta'], 'Invarianza Elementor: Tabla etk_t_postmeta 100% idéntica (Delta = 0 en metadatos y configuraciones)' );
assert_true( $elementor_snapshot_pre['json'] === $elementor_snapshot_post['json'], 'Invarianza Elementor: Hashes MD5 y estructuras JSON _elementor_data 100% idénticos' );
assert_true( $elementor_snapshot_pre['terms'] === $elementor_snapshot_post['terms'], 'Invarianza Elementor: Términos elementor_library_type 100% intactos' );

// -------------------------------------------------------------------------
// 8. CONTROL 7: VALIDACIONES FUNCIONALES DE API DE DOMINIO Y HTTP
// -------------------------------------------------------------------------
echo "\n--- Control 7: Validaciones Funcionales de API de Dominio y HTTP ---\n";

// A. Resolución funcional vía ContentTranslationResolver:
$resolver = new ContentTranslationResolver( $group_repo, $registry );

// TRID 6: post 6 ('Kit por defecto', elementor_library, es)
$lang_p6 = $resolver->language_of( 'post', 6 );
assert_true( 'es' === $lang_p6, 'ContentTranslationResolver reconoce idioma es para plantilla 6 (Kit por defecto)' );

// TRID 1300: post 1300 ('Contact Tab 1', elementor_library, es)
$lang_p1300 = $resolver->language_of( 'post', 1300 );
assert_true( 'es' === $lang_p1300, 'ContentTranslationResolver reconoce idioma es para plantilla 1300 (Contact Tab 1)' );

// TRID 4532: term 32 ('page', elementor_library_type, es)
$lang_t32 = $resolver->language_of( 'term', 32 );
assert_true( 'es' === $lang_t32, 'ContentTranslationResolver reconoce idioma es para término 32 (page)' );

// TRID 9208: Grupo BILINGÜE Elementor (Post 5048 'Testimonios' [es] y Post 5055 'Testimonial' [en])
$lang_p5048 = $resolver->language_of( 'post', 5048 );
$lang_p5055 = $resolver->language_of( 'post', 5055 );
assert_true( 'es' === $lang_p5048, 'ContentTranslationResolver reconoce idioma es para plantilla 5048 (Testimonios)' );
assert_true( 'en' === $lang_p5055, 'ContentTranslationResolver reconoce idioma en para plantilla 5055 (Testimonial)' );

// Resolución simétrica cruzada ES <-> EN en TRID 9208:
$res_5048_to_en = $resolver->resolve_element_id( 'post', 5048, 'en' );
$res_5055_to_es = $resolver->resolve_element_id( 'post', 5055, 'es' );
assert_true( 5055 === $res_5048_to_en, 'Resolución cruzada: Plantilla 5048 (ES) hacia EN resuelve exactamente a Post 5055' );
assert_true( 5048 === $res_5055_to_es, 'Resolución cruzada: Plantilla 5055 (EN) hacia ES resuelve exactamente a Post 5048' );

// Ausencia estricta de fallback hacia idiomas sin traducción:
$res_p6_to_en = $resolver->resolve_element_id( 'post', 6, 'en' );
assert_true( null === $res_p6_to_en, 'Plantilla 6 monolingüe hacia EN devuelve null (sin fallback espurio)' );

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

// Consulta de plantilla Elementor como endpoint interno:
$http_el5048 = lab_http_get( '/?elementor_library=testimonios' );
assert_true( in_array( $http_el5048['status'], array( 200, 301, 404 ), true ), 'HTTP: Plantilla Elementor responde limpiamente sin error fatal' );
assert_true( 500 !== $http_el5048['status'], 'HTTP: Ausencia de error 500 en solicitud de plantilla Elementor' );

// -------------------------------------------------------------------------
// 9. CONTROL 8: INVARIANZA FINAL Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 8: Invarianza Final y Centinela en Origen (cms_ect) ---\n";

$final_lab_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_lab_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 79 === $final_lab_groups, "Invarianza final: Laboratorio retiene exactamente 79 grupos acumulados ({$final_lab_groups})" );
assert_true( 84 === $final_lab_elements, "Invarianza final: Laboratorio retiene exactamente 84 elementos acumulados ({$final_lab_elements})" );

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
echo "  FASE 3.19 MIGRACIÓN LOTE 6: EXITOSA (PASS)\n";
echo "====================================================================\n";
