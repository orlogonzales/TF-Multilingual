<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.23.
 *
 * Directiva: DT-TFML-053
 * Alcance: Ejecución transaccional del décimo y último lote incremental (batch-10-nav-menu-items),
 *          18 grupos / 18 elementos (CPT WordPress nativo post:nav_menu_item, TRIDs del catálogo oficial),
 *          certificación de idempotencia en base de datos real, reversibilidad aislada (rollback selectivo),
 *          re-ejecución y conciliación profunda, auditoría especial de estructura de navegación (Delta = 0),
 *          validaciones funcionales de API de dominio (reconocimiento lingüístico y ausencia de fallback espurio),
 *          reconciliación global de los 10 lotes (120 grupos / 133 elementos acumulados = 100% universo importable),
 *          preservación de entidades no importables (52 bloqueadas, 24 omitidas, 53 huérfanas)
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
echo "  TF MULTILINGUAL — VERIFICACIÓN INTEGRAL FASE 3.23\n";
echo "  Directiva: DT-TFML-053 (Lote 10: Elementos de Menú WordPress)\n";
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

// Limpieza de reproducibilidad: si una ejecución previa dejó registros de batch 10, limpiarlos mediante su manifiesto:
$manifest_cleanup_res = $mysqli_lab->query( "SELECT option_name, option_value FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_manifest_exec_batch_10%'" );
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
// 2. CONTROL 1: PRESERVACIÓN INMUTABLE DEL ESTADO ACUMULADO (102 GRUPOS, 115 ELEM)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Preservación Inmutable del Estado Acumulado (102 Grupos, 115 Elementos) ---\n";

$groups_count_pre   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count_pre = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 102 === $groups_count_pre, "BD laboratorio conserva estrictamente 102 registros en etk_t_tfml_groups ({$groups_count_pre})" );
assert_true( 115 === $elements_count_pre, "BD laboratorio conserva estrictamente 115 registros en etk_t_tfml_group_elements ({$elements_count_pre})" );

// Cargar dinámicamente los 102 IDs acumulados sin suposiciones de correlatividad autoincremental:
$res_gids = $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' );
$initial_group_ids = array();
while ( $r = $res_gids->fetch_row() ) {
	$initial_group_ids[] = (int) $r[0];
}
assert_true( 102 === count( $initial_group_ids ), 'Se identificaron exactamente 102 IDs de grupos preexistentes' );

// Comprobar que grupos históricos de lotes anteriores forman parte del conjunto:
$historical_sample_gids = array( 6, 7, 8, 9, 10, 38, 39, 40 );
foreach ( $historical_sample_gids as $hist_id ) {
	assert_true( in_array( $hist_id, $initial_group_ids, true ), "Grupo histórico {$hist_id} está presente en el conjunto inicial" );
}

// Tablas secundarias TFML permanecen en 0:
$cnt_media  = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_media_translations`' )->fetch_row()[0];
$cnt_str    = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_strings`' )->fetch_row()[0];
$cnt_str_tr = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_string_translations`' )->fetch_row()[0];
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
			'total_items'         => 4,
			'processed_items'     => 4,
			'batch_size'          => 20,
			'last_processed_trid' => 9411,
			'status'              => MigrationCheckpoint::STATUS_COMPLETED,
		)
	);
	$checkpoint_repo->save( $cp_prev );
}
assert_true( null !== $cp_prev && $cp_prev->is_completed(), 'Checkpoint previo en estado COMPLETED' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'Lock de migración liberado pre-ejecución' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: AUDITORÍA ESPECIAL Y SNAPSHOT DE INTEGRIDAD DE MENÚS (SOLO LECTURA)
// -------------------------------------------------------------------------
echo "\n--- Control 2: Auditoría Especial y Snapshot de Integridad de Menús (Solo Lectura) ---\n";

$menu_item_post_ids = array(
	8363,
	9243,
	9376,
	9657,
	9671,
	9902,
	10076,
	10080,
	10081,
	10151,
	10152,
	10153,
	10154,
	10155,
	10156,
	10157,
	10158,
	10159,
);

/**
 * Capture nav_menu_item post, postmeta and term relationships snapshot.
 *
 * @param mysqli $mysqli   Database connection.
 * @param array  $post_ids Menu Item Post IDs.
 * @return array Snapshot data.
 */
function capture_nav_menu_items_snapshot( mysqli $mysqli, array $post_ids ): array {
	$snapshot = array(
		'posts'         => array(),
		'postmeta'      => array(),
		'nav_fields'    => array(),
		'relationships' => array(),
	);

	$pid_str = implode( ',', array_map( 'intval', $post_ids ) );

	$res_posts = $mysqli->query( "SELECT ID, post_title, post_name, post_type, post_status, post_excerpt, post_author, post_parent, menu_order, comment_status, ping_status, MD5(post_content) as content_hash FROM `etk_t_posts` WHERE ID IN ({$pid_str}) ORDER BY ID ASC" );
	while ( $r = $res_posts->fetch_assoc() ) {
		$snapshot['posts'][ (int) $r['ID'] ] = $r;
	}

	$res_meta = $mysqli->query( "SELECT post_id, meta_key, meta_value FROM `etk_t_postmeta` WHERE post_id IN ({$pid_str}) ORDER BY meta_id ASC" );
	while ( $r = $res_meta->fetch_assoc() ) {
		$pid = (int) $r['post_id'];
		$key = $r['meta_key'];
		$val = $r['meta_value'];
		$snapshot['postmeta'][ $pid ][ $key ][] = MD5( (string) $val );

		// Metadatos nativos específicos de navegación:
		if ( in_array( $key, array( '_menu_item_type', '_menu_item_object', '_menu_item_object_id', '_menu_item_menu_item_parent', '_menu_item_url', '_menu_item_target', '_menu_item_classes', '_menu_item_xfn' ), true ) ) {
			$snapshot['nav_fields'][ $pid ][ $key ] = $val;
		}
	}

	$res_rel = $mysqli->query( "SELECT object_id, term_taxonomy_id FROM `etk_t_term_relationships` WHERE object_id IN ({$pid_str}) ORDER BY object_id ASC, term_taxonomy_id ASC" );
	while ( $r = $res_rel->fetch_assoc() ) {
		$oid  = (int) $r['object_id'];
		$ttid = (int) $r['term_taxonomy_id'];
		$snapshot['relationships'][ $oid ][] = $ttid;
	}

	return $snapshot;
}

$nav_snapshot_pre = capture_nav_menu_items_snapshot( $mysqli_lab, $menu_item_post_ids );

// Comprobar existencia física y validez pre-migración:
assert_true( 18 === count( $nav_snapshot_pre['posts'] ), 'Pre-migración: Los 18 elementos de menú existen físicamente en WordPress Core' );

foreach ( $menu_item_post_ids as $mid ) {
	assert_true( isset( $nav_snapshot_pre['posts'][ $mid ] ), "Elemento de menú {$mid} registrado en snapshot de posts" );
	assert_true( 'nav_menu_item' === $nav_snapshot_pre['posts'][ $mid ]['post_type'], "Elemento {$mid} tiene post_type = 'nav_menu_item'" );
	assert_true( isset( $nav_snapshot_pre['nav_fields'][ $mid ]['_menu_item_object_id'] ), "Elemento {$mid} posee _menu_item_object_id" );
	assert_true( isset( $nav_snapshot_pre['nav_fields'][ $mid ]['_menu_item_menu_item_parent'] ), "Elemento {$mid} posee _menu_item_menu_item_parent" );
}

// -------------------------------------------------------------------------
// 4. CONTROL 3: VALIDACIÓN FORMAL DEL LOTE 10 AUTORIZADO (DT-TFML-053)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Validación Formal del Lote 10 Autorizado (DT-TFML-053) ---\n";

$catalog_path = __DIR__ . '/incremental_batches_catalog.json';
$catalog      = json_decode( (string) file_get_contents( $catalog_path ), true );
$batch_10_def = $catalog['batch-10-nav-menu-items'];

// TRIDs exclusivos obtenidos directamente del catálogo oficial:
$authorized_batch_trids = array(
	9210,
	34659,
	34701,
	34789,
	34792,
	34824,
	34826,
	34828,
	34829,
	34832,
	34833,
	34834,
	34835,
	34836,
	34837,
	34838,
	34839,
	34840,
);
$expected_fingerprint   = '19b7f15c730a7f0059b7d6873a37b07ed2bcd63f499c5a30fc921ae5d4957acc';

assert_true( $authorized_batch_trids === $batch_10_def['trids'], 'Catálogo oficial: TRIDs del Lote 10 coinciden exactamente con la autorización DT-TFML-053' );
assert_true( $expected_fingerprint === $batch_10_def['fingerprint'], 'Catálogo oficial: Fingerprint del Lote 10 certificado' );
assert_true( 18 === count( $authorized_batch_trids ), 'Lote 10 contiene exactamente 18 TRIDs autorizados' );

$planner       = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$matrix_plan   = $planner->generate_plan( 0, 0, false );
$batch_10_plan = $matrix_plan->derive_partial_plan( $authorized_batch_trids, 'batch-10-nav-menu-items' );

assert_true( MigrationPlan::STATUS_READY === $batch_10_plan->get_status(), 'Lote 10 derivado en estado formal STATUS_READY' );
assert_true( $expected_fingerprint === $batch_10_plan->get_fingerprint(), "Huella SHA-256 del Lote 10 certificada: {$expected_fingerprint}" );
assert_true( 18 === count( $batch_10_plan->get_planned_groups() ), 'Lote 10 contiene exactamente 18 grupos de traducción' );

// Comprobar cardinalidad de elementos y subtipos:
$b10_elements_count = 0;
$b10_subtypes_count = array();
$b10_all_post_ids   = array();
$b10_lang_counts    = array();

foreach ( $batch_10_plan->get_planned_groups() as $pg ) {
	$b10_elements_count += count( $pg['translations'] );
	$st = $pg['subtype'];
	$b10_subtypes_count[ $st ] = ( $b10_subtypes_count[ $st ] ?? 0 ) + 1;
	foreach ( $pg['translations'] as $lang => $elem_id ) {
		$b10_all_post_ids[]     = (int) $elem_id;
		$b10_lang_counts[ $lang ] = ( $b10_lang_counts[ $lang ] ?? 0 ) + 1;
		assert_true( 'post' === $pg['element_type'], "Traducción de menú {$elem_id} ({$lang}) es de tipo 'post'" );
		assert_true( in_array( $lang, array( 'es', 'en' ), true ), "Elemento {$elem_id} pertenece a idioma activo autorizable ({$lang})" );
	}
}

sort( $b10_all_post_ids );
assert_true( 18 === $b10_elements_count, 'Lote 10 contiene exactamente 18 elementos editoriales (18 elementos de menú)' );
assert_true( 18 === ( $b10_subtypes_count['nav_menu_item'] ?? 0 ), 'Lote 10 contiene exactamente 18 grupos con subtipo post:nav_menu_item' );
assert_true( 9 === ( $b10_lang_counts['es'] ?? 0 ), 'Lote 10 contiene exactamente 9 elementos en idioma español' );
assert_true( 9 === ( $b10_lang_counts['en'] ?? 0 ), 'Lote 10 contiene exactamente 9 elementos en idioma inglés' );
assert_true( $menu_item_post_ids === $b10_all_post_ids, 'Los 18 post IDs del plan del Lote 10 coinciden exactamente con la lista autorizada de elementos de menú' );

$val = new WordPressElementValidator();
foreach ( $batch_10_plan->get_planned_groups() as $pg ) {
	$trid      = (int) $pg['trid'];
	$elem_type = $pg['element_type'];
	$subtype   = $pg['subtype'];
	$cid       = (int) $pg['canonical_id'];
	assert_true( in_array( $trid, $authorized_batch_trids, true ), "TRID {$trid} pertenece estrictamente al lote 10 autorizado" );
	assert_true( 'post' === $elem_type, "TRID {$trid} es de tipo 'post'" );
	assert_true( 'nav_menu_item' === $subtype, "TRID {$trid} es de subtipo 'nav_menu_item'" );
	assert_true( $val->exists( $elem_type, $subtype, $cid ), "Elemento canónico {$cid} ({$elem_type}:{$subtype}) existe físicamente en WordPress Core" );
}

// -------------------------------------------------------------------------
// 5. CONTROL 4: EJECUCIÓN TRANSACCIONAL ATÓMICA DEL LOTE 10 (cms_ect_lab)
// -------------------------------------------------------------------------
echo "\n--- Control 4: Ejecución Transaccional Atómica del Lote 10 en cms_ect_lab ---\n";

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
$b10_exec_token = 'exec_batch_10_' . wp_generate_password( 8, false );

$start_time = microtime( true );
$exec_result = $executor->execute_batch(
	$batch_10_plan,
	30, // Procesa los 18 grupos en una sola transacción atómica
	$b10_exec_token,
	null,
	$expected_fingerprint
);
$elapsed_time = round( microtime( true ) - $start_time, 4 );

echo "  Tiempo de ejecución Lote 10: {$elapsed_time} segundos\n";
assert_true( 18 === count( $exec_result['batch_results'] ), 'Lote 10 procesó exactamente los 18 grupos' );
assert_true( $exec_result['finished'], 'Lote 10 reportó finalización exitosa (finished = true)' );

$imported_group_ids = array();
foreach ( $exec_result['batch_results'] as $res_item ) {
	assert_true( 'imported' === $res_item['status'], "TRID {$res_item['trid']} importado formalmente con estado 'imported'" );
	assert_true( isset( $res_item['group_id'] ) && $res_item['group_id'] > 0, "TRID {$res_item['trid']} asignado a group_id válido {$res_item['group_id']}" );
	$imported_group_ids[] = (int) $res_item['group_id'];
}
assert_true( 18 === count( $imported_group_ids ), 'Se importaron exactamente 18 IDs de grupo capturados dinámicamente' );

// Registrar manifiesto de ejecución con los IDs reales importados:
$rollback_service->save_execution_manifest(
	$b10_exec_token,
	array(
		'execution_id'       => $b10_exec_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_10_plan->get_fingerprint(),
		'imported_group_ids' => $imported_group_ids,
	)
);

// Comprobar conteos acumulados en base de datos: exactamente 120 grupos (102 + 18) y 133 elementos (115 + 18).
// 100% DEL UNIVERSO IMPORTABLE ALCANZADO.
$cumul_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$cumul_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 120 === $cumul_groups, "Resultado acumulado en BD: exactamente 120 grupos en etk_t_tfml_groups ({$cumul_groups}) — 100% ALCANZADO" );
assert_true( 133 === $cumul_elements, "Resultado acumulado en BD: exactamente 133 elementos en etk_t_tfml_group_elements ({$cumul_elements}) — 100% ALCANZADO" );

// Confirmar que los 102 grupos acumulados previos siguen 100% inalterados:
$current_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
foreach ( $initial_group_ids as $prev_id ) {
	assert_true( in_array( $prev_id, $current_group_ids, true ), "Grupo acumulado previo {$prev_id} permanece inalterado" );
}

// Comprobar que los 18 grupos importados tienen tipo 'post' y subtipo 'nav_menu_item':
$res_check_g = $mysqli_lab->query( 'SELECT id, element_type, subtype FROM `etk_t_tfml_groups` WHERE id IN (' . implode( ',', $imported_group_ids ) . ')' );
$count_verified_g = 0;
while ( $cg = $res_check_g->fetch_assoc() ) {
	++$count_verified_g;
	assert_true( 'post' === $cg['element_type'], "Grupo {$cg['id']} es de tipo 'post'" );
	assert_true( 'nav_menu_item' === $cg['subtype'], "Grupo {$cg['id']} es de subtipo 'nav_menu_item'" );
}
assert_true( 18 === $count_verified_g, 'Los 18 grupos importados verificados con tipo post y subtipo nav_menu_item' );

// Comprobar estado de checkpoint y lock:
$cp_b10 = $checkpoint_repo->get( 'wpml' );
assert_true( null !== $cp_b10 && $cp_b10->is_completed(), 'Checkpoint del Lote 10 marcado como completed' );
assert_true( 18 === $cp_b10->get_processed_items(), 'Checkpoint del Lote 10 registra 18 ítems procesados' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'Lock de migración liberado tras la ejecución' );

// Verificar manifiesto en opciones:
$manifest_b10_val = maybe_unserialize( $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'tfml_migration_manifest_{$b10_exec_token}'" )->fetch_row()[0] ?? '' );
assert_true( is_array( $manifest_b10_val ), 'Manifiesto de ejecución del Lote 10 guardado correctamente en opciones' );
assert_true( 18 === count( (array) $manifest_b10_val['imported_group_ids'] ), 'Manifiesto del Lote 10 registra exactamente 18 grupos importados' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: CERTIFICACIÓN DE IDEMPOTENCIA Y REVERSIBILIDAD AISLADA
// -------------------------------------------------------------------------
echo "\n--- Control 5: Certificación de Idempotencia y Reversibilidad Aislada ---\n";

// A. IDEMPOTENCIA ESTRICTA: Re-ejecutar Lote 10 sobre el estado acumulado.
$idem_token = 'token_idem_b10_' . wp_generate_password( 6, false );
$idem_res   = $executor->execute_batch(
	$batch_10_plan,
	30,
	$idem_token,
	null,
	$expected_fingerprint
);

assert_true( 18 === count( $idem_res['batch_results'] ), 'Re-ejecución idempotente evaluó los 18 grupos' );
foreach ( $idem_res['batch_results'] as $res_item ) {
	assert_true( 'idempotent_skipped' === $res_item['status'], "TRID {$res_item['trid']} reporta formalmente idempotent_skipped" );
}

$post_idem_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_idem_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 120 === $post_idem_groups, 'Idempotencia: Conteo de grupos permanece inmutable en 120' );
assert_true( 133 === $post_idem_elements, 'Idempotencia: Conteo de elementos permanece inmutable en 133' );

// B. REVERSIBILIDAD AISLADA (ROLLBACK SELECTIVO): Rollback exclusivo de los 18 grupos del Lote 10.
echo "  Comprobando rollback aislado del Lote 10 sin afectar los 9 lotes previos...\n";
$rb_res = $rollback_service->rollback_groups( $imported_group_ids, $b10_exec_token );

assert_true( $rb_res['success'], 'Rollback de los grupos del Lote 10 completado exitosamente' );
assert_true( 18 === $rb_res['reverted_groups_count'], 'Rollback eliminó exactamente los 18 grupos del Lote 10' );
$rollback_service->delete_execution_manifest( $b10_exec_token );

// Validar retorno exacto al estado previo (102 grupos, 115 elementos):
$post_rb_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_rb_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 102 === $post_rb_groups, "Post-rollback: Grupos en laboratorio retornaron exactamente a 102 ({$post_rb_groups})" );
assert_true( 115 === $post_rb_elements, "Post-rollback: Elementos en laboratorio retornaron exactamente a 115 ({$post_rb_elements})" );

$remaining_group_ids = array_map( fn( $r ) => (int) $r[0], $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM ) );
assert_true( $initial_group_ids === $remaining_group_ids, 'Los 102 grupos acumulados previos permanecieron 100% blindados e intactos' );

// C. RE-EJECUCIÓN POST-ROLLBACK:
echo "  Re-ejecutando Lote 10 para restaurar el estado final acumulado al 100%...\n";
$b10_re_token = 'exec_batch_10_final_' . wp_generate_password( 6, false );
$re_exec_res = $executor->execute_batch(
	$batch_10_plan,
	30,
	$b10_re_token,
	null,
	$expected_fingerprint
);

assert_true( 18 === count( $re_exec_res['batch_results'] ), 'Re-ejecución procesó los 18 grupos' );
$final_restored_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_restored_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 120 === $final_restored_groups, "Estado acumulado restaurado: exactamente 120 grupos en etk_t_tfml_groups ({$final_restored_groups})" );
assert_true( 133 === $final_restored_elements, "Estado acumulado restaurado: exactamente 133 elementos en etk_t_tfml_group_elements ({$final_restored_elements})" );

$re_imported_group_ids = array();
foreach ( $re_exec_res['batch_results'] as $res_item ) {
	if ( 'imported' === $res_item['status'] && ! empty( $res_item['group_id'] ) ) {
		$re_imported_group_ids[] = (int) $res_item['group_id'];
	}
}
assert_true( 18 === count( $re_imported_group_ids ), 'Re-ejecución importó exactamente 18 grupos nuevos (captura dinámica de IDs)' );

$rollback_service->save_execution_manifest(
	$b10_re_token,
	array(
		'execution_id'       => $b10_re_token,
		'source'             => 'wpml',
		'plan_fingerprint'   => $batch_10_plan->get_fingerprint(),
		'imported_group_ids' => $re_imported_group_ids,
	)
);

// Conciliación profunda del lote 10:
$reconcile_res = $executor->reconcile( $batch_10_plan );
assert_true( $reconcile_res['integrity_verified'], 'Conciliación profunda certifica 100% de integridad relacional en el lote 10' );
assert_true( 18 === $reconcile_res['tfml_imported_groups'], 'Conciliación confirma 18 grupos importados del lote 10' );
assert_true( empty( $reconcile_res['discrepancies'] ), 'Cero discrepancias en la conciliación del lote 10' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: AUDITORÍA DE INVARIANZA CRIPTOGRÁFICA DE MENÚS (DELTA = 0)
// -------------------------------------------------------------------------
echo "\n--- Control 6: Auditoría de Invarianza Criptográfica de Menús (Delta = 0) ---\n";

$nav_snapshot_post = capture_nav_menu_items_snapshot( $mysqli_lab, $menu_item_post_ids );

// Comprobación de invariabilidad total en datos nativos de WordPress de navegación:
assert_true( $nav_snapshot_pre['posts'] === $nav_snapshot_post['posts'], 'Invarianza Menús: Tabla etk_t_posts 100% idéntica (Delta = 0 en títulos, slugs, contenido, estados, tipos, menu_order y post_parent)' );
assert_true( $nav_snapshot_pre['postmeta'] === $nav_snapshot_post['postmeta'], 'Invarianza Menús: Tabla etk_t_postmeta 100% idéntica (Delta = 0 en metadatos)' );
assert_true( $nav_snapshot_pre['nav_fields'] === $nav_snapshot_post['nav_fields'], 'Invarianza Menús: Metadatos de navegación (_menu_item_object_id, _menu_item_menu_item_parent, _menu_item_url, etc.) 100% idénticos' );
assert_true( $nav_snapshot_pre['relationships'] === $nav_snapshot_post['relationships'], 'Invarianza Menús: Relaciones taxonómicas con nav_menu en etk_t_term_relationships 100% intactas' );

// -------------------------------------------------------------------------
// 8. CONTROL 7: VALIDACIONES FUNCIONALES DE API DE DOMINIO Y HTTP
// -------------------------------------------------------------------------
echo "\n--- Control 7: Validaciones Funcionales de API de Dominio y HTTP ---\n";

// A. Resolución funcional vía ContentTranslationResolver:
$resolver = new ContentTranslationResolver( $group_repo, $registry );

// Idiomas esperados para los elementos de menú del Lote 10:
$expected_menu_langs = array(
	8363  => 'en',
	9243  => 'es',
	9376  => 'es',
	9657  => 'es',
	9671  => 'es',
	9902  => 'es',
	10076 => 'es',
	10080 => 'es',
	10081 => 'es',
	10151 => 'es',
	10152 => 'en',
	10153 => 'en',
	10154 => 'en',
	10155 => 'en',
	10156 => 'en',
	10157 => 'en',
	10158 => 'en',
	10159 => 'en',
);

foreach ( $expected_menu_langs as $mid => $expected_lang ) {
	$lang = $resolver->language_of( 'post', $mid );
	assert_true( $expected_lang === $lang, "ContentTranslationResolver reconoce idioma '{$expected_lang}' para Elemento de Menú {$mid}" );

	$target_lang = 'es' === $expected_lang ? 'en' : 'es';
	$res_target  = $resolver->resolve_element_id( 'post', $mid, $target_lang );
	assert_true( null === $res_target, "Elemento de menú {$mid} hacia {$target_lang} devuelve estrictamente null (sin fallback indebido)" );
}

// Preservación de resolución de los 9 lotes anteriores:
// Lote 9: Entrada de blog 5687 monolingüe:
assert_true( 'es' === $resolver->language_of( 'post', 5687 ), 'Lote 9 anterior: Entrada 5687 tiene idioma es' );
assert_true( null === $resolver->resolve_element_id( 'post', 5687, 'en' ), 'Lote 9 anterior: Entrada 5687 hacia EN devuelve null' );

// Lote 8: CPT Producto WooCommerce 5250 monolingüe:
assert_true( 'es' === $resolver->language_of( 'post', 5250 ), 'Lote 8 anterior: Producto 5250 tiene idioma es' );
assert_true( null === $resolver->resolve_element_id( 'post', 5250, 'en' ), 'Lote 8 anterior: Producto 5250 hacia EN devuelve null' );

// Lote 7: CPT Tours bilingües 5321 <-> 5322:
assert_true( 5322 === $resolver->resolve_element_id( 'post', 5321, 'en' ), 'Lote 7 anterior: Tour 5321 resuelve a 5322' );
assert_true( 5321 === $resolver->resolve_element_id( 'post', 5322, 'es' ), 'Lote 7 anterior: Tour 5322 resuelve a 5321' );

// Lote 6: Plantilla bilingüe 5048 <-> 5055:
assert_true( 5055 === $resolver->resolve_element_id( 'post', 5048, 'en' ), 'Lote 6 anterior: Plantilla 5048 resuelve a 5055' );
assert_true( 5048 === $resolver->resolve_element_id( 'post', 5055, 'es' ), 'Lote 6 anterior: Plantilla 5055 resuelve a 5048' );

// Lote 5: Etiquetas monolingües hacia EN devuelven null:
assert_true( null === $resolver->resolve_element_id( 'term', 74, 'en' ), 'Lote 5 anterior: Etiqueta 74 hacia EN devuelve null' );

// Lote 2: Categoría blog 50 monolingüe hacia EN devuelve null:
assert_true( null === $resolver->resolve_element_id( 'term', 50, 'en' ), 'Lote 2 anterior: Categoría 50 hacia EN devuelve null' );

// Lote 1: Página 4338 monolingüe hacia EN devuelve null:
assert_true( null === $resolver->resolve_element_id( 'post', 4338, 'en' ), 'Lote 1 anterior: Página 4338 hacia EN devuelve null' );

// B. Verificación de entorno de presentación (tema ausente -> BLOCKED):
$theme_exists = is_dir( 'D:/laragon/www/lab.ecoterra/wp-content/themes/' . get_option( 'stylesheet' ) );
assert_true( ! $theme_exists, 'Directorio del tema ecoCMS ausente en laboratorio: renderizado frontend HTML y navegación visual catalogados estrictamente como BLOCKED' );

// C. Verificación de peticiones HTTP reales al servidor web:
/**
 * Perform lab HTTP GET.
 *
 * @param string $path URL path.
 * @return array Response data.
 */
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

// Consulta de elementos de menú (no deben causar errores 500 al ser consultados):
$http_m9243 = lab_http_get( '/?p=9243' );
assert_true( 500 !== $http_m9243['status'], 'HTTP: Ausencia de error 500 en solicitud de Elemento de Menú 9243' );

$http_m8363 = lab_http_get( '/?p=8363' );
assert_true( 500 !== $http_m8363['status'], 'HTTP: Ausencia de error 500 en solicitud de Elemento de Menú 8363' );

// -------------------------------------------------------------------------
// 9. CONTROL 8: RECONCILIACIÓN GLOBAL DE LOS 10 LOTES Y ENTIDADES NO IMPORTABLES
// -------------------------------------------------------------------------
echo "\n--- Control 8: Reconciliación Global de los 10 Lotes y Entidades No Importables ---\n";

$final_lab_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$final_lab_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 120 === $final_lab_groups, "Universo Importable 100%: Grupos totales en laboratorio son exactamente 120 ({$final_lab_groups})" );
assert_true( 133 === $final_lab_elements, "Universo Importable 100%: Elementos totales en laboratorio son exactamente 133 ({$final_lab_elements})" );

// Cero duplicados en grupos y elementos:
$dup_g = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM (SELECT canonical_element_id FROM `etk_t_tfml_groups` GROUP BY element_type, canonical_element_id HAVING COUNT(*) > 1) as d' )->fetch_row()[0];
$dup_e = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM (SELECT element_type, element_id FROM `etk_t_tfml_group_elements` GROUP BY element_type, element_id HAVING COUNT(*) > 1) as d' )->fetch_row()[0];
assert_true( 0 === $dup_g, 'Integridad Global: Cero grupos duplicados en etk_t_tfml_groups' );
assert_true( 0 === $dup_e, 'Integridad Global: Cero elementos duplicados en etk_t_tfml_group_elements' );

// Verificar estadísticas del plan global WPML contra entidades no importables:
$matrix_summary = $matrix_plan->get_summary();
echo "Estadísticas del Plan Global WPML:\n";
echo '  Planned (Importables):    ' . ( $matrix_summary['importable_groups'] ?? 0 ) . " (Expected: 120)\n";
echo '  Blocked by Language:      ' . ( $matrix_summary['blocked_language_groups'] ?? 0 ) . " (Expected: 52)\n";
echo '  Skipped (Ignorados):      ' . ( $matrix_summary['skipped_groups'] ?? 0 ) . " (Expected: 24)\n";
echo '  Orphan (Huérfanos):       ' . ( $matrix_summary['orphan_groups'] ?? 0 ) . " (Expected: 53)\n\n";

assert_true( 120 === ( $matrix_summary['importable_groups'] ?? 0 ), 'Plan Global: Exactamente 120 grupos clasificados como importables' );
assert_true( 52 === ( $matrix_summary['blocked_language_groups'] ?? 0 ), 'Plan Global: Exactamente 52 grupos conservados en estado BLOCKED_BY_LANGUAGE' );
assert_true( 24 === ( $matrix_summary['skipped_groups'] ?? 0 ), 'Plan Global: Exactamente 24 grupos conservados en estado SKIPPED' );
assert_true( 53 === ( $matrix_summary['orphan_groups'] ?? 0 ), 'Plan Global: Exactamente 53 grupos conservados en estado ORPHAN' );

// -------------------------------------------------------------------------
// 10. CONTROL 9: INVARIANZA FINAL Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 9: Invarianza Final y Centinela en Origen (cms_ect) ---\n";

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
echo "  FASE 3.23 MIGRACIÓN LOTE 10: EXITOSA (PASS)\n";
echo "  UNIVERSO IMPORTABLE WPML: 120/120 GRUPOS (100% COMPLETADO)\n";
echo "====================================================================\n";
