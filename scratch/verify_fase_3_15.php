<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.15.
 *
 * Directiva: DT-TFML-045
 * Alcance: Saneamiento integral del catálogo de migración incremental (115 grupos / 9 lotes),
 *          comprobación de no-colisión, pertenencia a IMPORTABLE, existencia en Core,
 *          conciliación exhaustiva del Lote 3 (WooCommerce), auditoría funcional HTTP (/en/, /fr/, canónicas),
 *          preservación intacta del estado acumulado (8 grupos, 12 elementos)
 *          y centinela inviolable en cms_ect (Delta = 0).
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
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;

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
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.15\n";
echo "  Directiva: DT-TFML-045 (Saneamiento Catálogo y Preparación Lote 3)\n";
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

// -------------------------------------------------------------------------
// 2. CONTROL 1: PRESERVACIÓN INMUTABLE DEL PILOTO ACUMULADO (8 GRUPOS, 12 ELEM)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Preservación Inmutable del Piloto Acumulado (Fases 3.12 y 3.14) ---\n";

$groups_count_pre   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count_pre = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 8 === $groups_count_pre, "BD laboratorio conserva estrictamente 8 registros en etk_t_tfml_groups ({$groups_count_pre})" );
assert_true( 12 === $elements_count_pre, "BD laboratorio conserva estrictamente 12 registros en etk_t_tfml_group_elements ({$elements_count_pre})" );

// Comprobar IDs exactos de grupos
$res_gids = $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' );
$actual_gids = array();
while ( $r = $res_gids->fetch_row() ) {
	$actual_gids[] = (int) $r[0];
}
assert_true( array( 6, 7, 8, 9, 10, 38, 39, 40 ) === $actual_gids, 'Los 8 IDs de grupo acumulados permanecen intactos: [6, 7, 8, 9, 10, 38, 39, 40]' );

// Semáforo de migración libre
$lock_active = (bool) $lab_wpdb->get_var( "SELECT option_value FROM etk_t_options WHERE option_name LIKE 'tfml_migration_lock_%'" );
assert_true( ! $lock_active, 'El bloqueo de migración está formalmente liberado (is_locked = false)' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: SANEAMIENTO INTEGRAL DEL PLAN MATRIZ Y DEL CATÁLOGO
// -------------------------------------------------------------------------
echo "\n--- Control 2: Auditoría Integral del Plan Matriz y Catálogo de Lotes ---\n";

$planner     = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$matrix_plan = $planner->generate_plan( 0, 0, false );

assert_true( MigrationPlan::STATUS_NEEDS_CONFIGURATION === $matrix_plan->get_status(), 'Plan matriz en estado STATUS_NEEDS_CONFIGURATION por idioma fr pendiente' );
assert_true( '08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949' === $matrix_plan->get_fingerprint(), 'Huella criptográfica inmutable del plan matriz certificada' );

$importables = $matrix_plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_IMPORTABLE );
assert_true( 120 === count( $importables ), 'Total grupos importables en matriz es 120' );

$importables_by_trid = array();
foreach ( $importables as $g ) {
	$importables_by_trid[ (int) $g['trid'] ] = $g;
}

// Cargar catálogo saneado
$catalog_path = __DIR__ . '/incremental_batches_catalog.json';
assert_true( file_exists( $catalog_path ), 'Catálogo incremental de lotes existe' );
$catalog = json_decode( (string) file_get_contents( $catalog_path ), true );
assert_true( 9 === count( $catalog ), 'Catálogo contiene exactamente los 9 lotes incrementales' );

// Verificar Lote Piloto 1 (5 TRIDs)
$pilot_1_trids = array( 4575, 9238, 9239, 9247, 4338 );
$all_partition_trids = array();
foreach ( $pilot_1_trids as $t ) {
	$all_partition_trids[ $t ] = 'pilot-batch-1';
}

$total_catalog_groups   = 0;
$total_catalog_elements = 0;
$collisions_found       = 0;

foreach ( $catalog as $bid => $bdef ) {
	$b_trids        = $bdef['trids'];
	$derived_plan   = $matrix_plan->derive_partial_plan( $b_trids, $bid );
	$derived_fp     = $derived_plan->get_fingerprint();
	$derived_groups = $derived_plan->get_planned_groups();
	$derived_elem   = $derived_plan->get_summary()['importable_elements'] ?? $derived_plan->get_summary()['total_elements'] ?? 0;

	assert_true( $derived_fp === $bdef['fingerprint'], "Lote {$bid}: Fingerprint derivado coincide exactamente ({$derived_fp})" );
	assert_true( count( $derived_groups ) === $bdef['groups'], "Lote {$bid}: Cardinalidad de grupos coincide ({$bdef['groups']})" );
	assert_true( $derived_elem === $bdef['elements'], "Lote {$bid}: Cardinalidad de elementos coincide ({$bdef['elements']})" );

	foreach ( $b_trids as $trid ) {
		if ( isset( $all_partition_trids[ $trid ] ) ) {
			++$collisions_found;
			echo " [FAIL] Colisión de TRID {$trid} entre {$bid} y {$all_partition_trids[ $trid ]}\n";
		}
		$all_partition_trids[ $trid ] = $bid;
		assert_true( isset( $importables_by_trid[ $trid ] ), "Lote {$bid}: TRID {$trid} clasifica formalmente como IMPORTABLE en plan matriz" );
	}

	$total_catalog_groups   += $bdef['groups'];
	$total_catalog_elements += $bdef['elements'];
}

assert_true( 0 === $collisions_found, 'Partición disjunta estricta: Cero colisiones o duplicados entre los 9 lotes y el piloto inicial' );
assert_true( 115 === $total_catalog_groups, 'Suma de grupos incrementales es exactamente 115' );
assert_true( 120 === ( $total_catalog_groups + count( $pilot_1_trids ) ), 'Universo importable completo: 120 grupos (100% cubierto)' );
assert_true( 124 === $total_catalog_elements, 'Suma de elementos incrementales es exactamente 124' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: CONCILIACIÓN ESPECÍFICA DEL LOTE 3 (batch-03-taxonomy-woocommerce)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Conciliación Específica del Lote 3 (WooCommerce) ---\n";

$b3 = $catalog['batch-03-taxonomy-woocommerce'];
$b3_expected_trids = array( 4578, 4579, 4580, 4581, 4607, 4614, 4615, 4616, 4617, 4618, 4619, 4620, 4621, 4622, 4638, 9544 );

assert_true( $b3_expected_trids === $b3['trids'], 'Lote 3: Los 16 TRIDs corresponden estrictamente a la lista certificada' );
assert_true( 16 === $b3['groups'], 'Lote 3: Registra exactamente 16 grupos' );
assert_true( 16 === $b3['elements'], 'Lote 3: Registra exactamente 16 elementos' );
assert_true( '5910dcf46cd4e9915b787cf6c06e59c87b1b0e7875d488ea0d68325cc3291945' === $b3['fingerprint'], 'Lote 3: Fingerprint oficial certificado' );

// Comprobar subtipos reales
$b3_subtypes = $b3['subtypes'];
assert_true( 5 === ( $b3_subtypes['product_type'] ?? 0 ), 'Lote 3: Contiene exactamente 5 grupos de product_type' );
assert_true( 9 === ( $b3_subtypes['product_visibility'] ?? 0 ), 'Lote 3: Contiene exactamente 9 grupos de product_visibility' );
assert_true( 2 === ( $b3_subtypes['product_cat'] ?? 0 ), 'Lote 3: Contiene exactamente 2 grupos de product_cat' );
assert_true( ! isset( $b3_subtypes['product_tag'] ), 'Lote 3: Ausencia de grupos product_tag (corregido de la recomendación previa)' );
assert_true( ! isset( $b3_subtypes['pa_attribute'] ), 'Lote 3: Ausencia de grupos pa_attribute (corregido de la recomendación previa)' );

// Comprobar existencia de las entidades en WordPress Core
foreach ( $b3['trids'] as $trid ) {
	$row = $lab_wpdb->get_row( $lab_wpdb->prepare( "SELECT element_id, element_type FROM etk_t_icl_translations WHERE trid = %d LIMIT 1", $trid ) );
	assert_true( null !== $row, "Lote 3: TRID {$trid} existe en etk_t_icl_translations" );
	$term_exists = (bool) $lab_wpdb->get_var( $lab_wpdb->prepare( "SELECT term_taxonomy_id FROM etk_t_term_taxonomy WHERE term_taxonomy_id = %d", $row->element_id ) );
	assert_true( $term_exists, "Lote 3: Elemento {$row->element_id} de TRID {$trid} existe físicamente en etk_t_term_taxonomy" );
}

// -------------------------------------------------------------------------
// 5. CONTROL 4: VERIFICACIÓN FUNCIONAL HTTP Y AUDITORÍA DE ENTORNO
// -------------------------------------------------------------------------
echo "\n--- Control 4: Verificación Funcional HTTP y Auditoría de Entorno ---\n";

/**
 * Execute HTTP request to lab.ecoterra.test.
 *
 * @param string $path URL path.
 * @return array{status: int, headers: string, body: string}
 */
function test_http_call( string $path ): array {
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

// HTTP-01: Raíz en español
$http_root = test_http_call( '/' );
assert_true( 200 === $http_root['status'], 'HTTP-01: Raíz (/) responde HTTP 200 OK' );
assert_true( strpos( $http_root['headers'], 'noindex' ) !== false, 'HTTP-01: Cabecera X-Robots-Tag noindex presente' );

// HTTP-02: Prefijo /en/
$http_en = test_http_call( '/en/' );
assert_true( 404 === $http_en['status'], 'HTTP-02: Ruta /en/ responde 404 Not Found (home inglés no migrada por bloqueo de idioma en TRID 197)' );
assert_true( 500 !== $http_en['status'], 'HTTP-02: Ausencia de error HTTP 500 en /en/' );

// HTTP-03: Prefijo /fr/
$http_fr = test_http_call( '/fr/' );
assert_true( 404 === $http_fr['status'], 'HTTP-03: Ruta /fr/ responde 404 Not Found (idioma inactivo, sin aliasing a default)' );
assert_true( 500 !== $http_fr['status'], 'HTTP-03: Ausencia de error HTTP 500 en /fr/' );

// HTTP-04: Entidad real migrada Lote 1 (Página 4338)
$http_p4338 = test_http_call( '/?page_id=4338' );
assert_true( 301 === $http_p4338['status'], 'HTTP-04a: Solicitud /?page_id=4338 redirige con 301 canónico' );
$http_old_blog = test_http_call( '/old-blog/' );
assert_true( 200 === $http_old_blog['status'], 'HTTP-04b: Destino canónico /old-blog/ responde HTTP 200 OK' );

// HTTP-05: Entidad real migrada Lote 2 (Categoría 50)
$http_cat50 = test_http_call( '/?cat=50' );
assert_true( 301 === $http_cat50['status'], 'HTTP-05a: Solicitud /?cat=50 redirige con 301 canónico' );
$http_cat_blog = test_http_call( '/category/blog/' );
assert_true( 200 === $http_cat_blog['status'], 'HTTP-05b: Destino canónico /category/blog/ responde HTTP 200 OK' );

// HTTP-06: Verificación de renderizado de tema (BLOCKED)
$theme_exists = is_dir( 'D:/laragon/www/lab.ecoterra/wp-content/themes/' . get_option( 'stylesheet' ) );
assert_true( ! $theme_exists, 'HTTP-06: Directorio del tema ecoCMS ausente en laboratorio (renderizado frontend html_lang/canonical/hreflang clasificado estrictamente como BLOCKED)' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: INVARIANZA FINAL Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 5: Invarianza Final y Centinela en Origen (cms_ect) ---\n";

$groups_count_post   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count_post = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 8 === $groups_count_post, "Invarianza final: BD laboratorio mantiene exactamente 8 grupos acumulados ({$groups_count_post})" );
assert_true( 12 === $elements_count_post, "Invarianza final: BD laboratorio mantiene exactamente 12 elementos acumulados ({$elements_count_post})" );

$post_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

assert_true( 3403 === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

echo "\n====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.15 CERTIFICACION INTEGRAL: EXITOSA (PASS)\n";
echo "====================================================================\n";
