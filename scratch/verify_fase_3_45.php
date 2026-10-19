<?php
/**
 * Verificador Reproducible de Fase 3.45 (Directiva DT-TFML-077).
 *
 * Panel Administrativo de Migración WPML:
 * - Control 1: Invarianza de Producción — cms_ect intocada (3,403 filas, hash MD5 inmutable).
 * - Control 2: Conexión Efectiva — WordPress conectado exclusivamente a cms_ect_lab.
 * - Control 3: Invarianza Laboratorio — 123 grupos, 139 elementos y 3 traducciones FR retenidas preservadas.
 * - Control 4: Centinela WPML Laboratorio — 3,403 filas en etk_t_icl_translations intactas.
 * - Control 5: Integridad de 13 Manifiestos de Ejecución y Checkpoint Activo (status=completed, TRID 9545).
 * - Control 6: Registro y Jerarquía de Menús — Idiomas (9) -> Traducciones (10) -> Cadenas (11) -> Migración WPML (12) -> Diagnóstico (13).
 * - Control 7: Plan Determinista y Huella Criptográfica SHA-256 (c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99).
 * - Control 8: Renderizado del Panel Administrativo MigrationUi (Pestañas Plan, Historial, Custodia FR, Próximo Lote).
 * - Control 9: Garantía de Solo Consulta / Read-Only — Rechazo estricto de mutaciones e invarianza de BD post-render.
 * - Control 10: No-Regresión Frontend HTTP 200, SEO Canónico y Sitemaps en Laboratorio.
 *
 * Uso:
 *   php scratch/verify_fase_3_45.php
 *
 * @package TF\Multilingual
 */

declare(strict_types=1);

if ( 'cli' !== php_sapi_name() ) {
	exit( "Ejecución permitida únicamente desde PHP CLI.\n" );
}

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

$lab_root = 'D:/laragon/www/lab.ecoterra';
if ( ! file_exists( $lab_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "ERROR: No se encontró wp-load.php en {$lab_root}\n" );
	exit( 1 );
}

require_once $lab_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}
ini_set( 'display_errors', '1' );
add_filter(
	'wp_die_handler',
	function () {
		return function ( $message, $title = '', $args = array() ) {
			$code = is_numeric( $title ) ? (int) $title : ( is_numeric( $args ) ? (int) $args : ( isset( $args['response'] ) ? (int) $args['response'] : 500 ) );
			throw new \RuntimeException( (string) $message, $code );
		};
	}
);

use TF\Multilingual\Admin\DiagnosticUi;
use TF\Multilingual\Admin\LanguageEditorialUi;
use TF\Multilingual\Admin\MigrationUi;
use TF\Multilingual\Admin\StringEditorialUi;
use TF\Multilingual\Admin\TranslationsOverviewUi;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationManager;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

$results = array();
$passed  = 0;
$failed  = 0;

/**
 * Registra el resultado de un control de verificación.
 *
 * @param string $gate_id Identificador del control.
 * @param bool   $ok      Resultado booleano.
 * @param string $detail  Evidencia verificable.
 */
function record_gate( string $gate_id, bool $ok, string $detail ): void {
	global $results, $passed, $failed;
	$status = $ok ? 'PASS' : 'FAIL';
	if ( $ok ) {
		++$passed;
	} else {
		++$failed;
	}
	$results[] = array(
		'gate'   => $gate_id,
		'status' => $status,
		'detail' => $detail,
	);
	echo sprintf( "[%s] %s\n       %s\n", $status, $gate_id, $detail );
}

/**
 * Obtiene métricas del centinela de traducciones en la BD indicada.
 *
 * @param string $db_name Nombre de la base de datos.
 * @return array{rows: int, md5: string}
 */
function get_icl_sentinel( string $db_name ): array {
	$mysqli = new mysqli( '127.0.0.1', 'root', '', $db_name, 3306 );
	if ( $mysqli->connect_errno ) {
		return array(
			'rows' => -1,
			'md5'  => 'connect_error',
		);
	}
	$mysqli->set_charset( 'utf8mb4' );
	$mysqli->query( 'SET SESSION group_concat_max_len = 10485760' );
	$res = $mysqli->query(
		"SELECT COUNT(*) AS cnt, MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code,'')) ORDER BY translation_id ASC SEPARATOR '|')) AS hash_val FROM etk_t_icl_translations"
	);
	$row = $res ? $res->fetch_assoc() : array();
	$mysqli->close();

	return array(
		'rows' => isset( $row['cnt'] ) ? (int) $row['cnt'] : 0,
		'md5'  => isset( $row['hash_val'] ) ? (string) $row['hash_val'] : '',
	);
}

/**
 * Realiza una petición HTTP local e inspecciona encabezados y canonicals.
 *
 * @param string $url URL a consultar.
 * @return array{code: int, canonicals: array<string>}
 */
function fetch_and_inspect_http( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'TFML-Fase345-Verifier/1.0' );
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$canonicals = array();
	if ( is_string( $body ) && '' !== $body ) {
		if ( preg_match_all( '/<link[^>]+rel=[\'"]canonical[\'"][^>]+href=[\'"]([^\'"]+)[\'"]/i', $body, $matches ) ) {
			$canonicals = array_merge( $canonicals, $matches[1] );
		}
	}

	return array(
		'code'       => $code,
		'canonicals' => $canonicals,
	);
}

echo "========================================================================\n";
echo "TF MULTILINGUAL — VERIFICADOR FASE 3.45 (DT-TFML-077)\n";
echo "Panel Administrativo de Migración WPML (Modo Consulta y Auditoría)\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// CONTROL 1: Invarianza de Producción — cms_ect
// -------------------------------------------------------------------------
$expected_prod_md5 = '4241ca7e7ec6399a594537cb04790c10';
$prod_sentinel     = get_icl_sentinel( 'cms_ect' );
$prod_ok           = ( 3403 === $prod_sentinel['rows'] && $expected_prod_md5 === $prod_sentinel['md5'] );

record_gate(
	'1. Invarianza de Producción — cms_ect intocada (3,403 filas, hash inmutable)',
	$prod_ok,
	sprintf( 'rows=%d/3403 | md5=%s (esperado: %s)', $prod_sentinel['rows'], $prod_sentinel['md5'], $expected_prod_md5 )
);

// -------------------------------------------------------------------------
// CONTROL 2: Conexión Efectiva — cms_ect_lab
// -------------------------------------------------------------------------
global $wpdb;
$active_db   = (string) $wpdb->get_var( 'SELECT DATABASE()' );
$constant_db = defined( 'DB_NAME' ) ? DB_NAME : '';
$property_db = (string) $wpdb->dbname;
$conn_ok     = ( 'cms_ect_lab' === $active_db && 'cms_ect_lab' === $constant_db && 'cms_ect_lab' === $property_db );

record_gate(
	'2. Conexión Efectiva — WordPress conectado exclusivamente a cms_ect_lab',
	$conn_ok,
	sprintf( 'DB_NAME=%s | wpdb->dbname=%s | SELECT DATABASE()=%s', $constant_db, $property_db, $active_db )
);

// -------------------------------------------------------------------------
// CONTROL 3: Invarianza de Datos en Laboratorio (123 grupos, 139 elementos, 3 FR retenidas)
// -------------------------------------------------------------------------
$table_groups   = $wpdb->prefix . 'tfml_groups';
$table_elements = $wpdb->prefix . 'tfml_group_elements';

$count_groups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_groups}" );
$count_elements = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_elements}" );

$retained_registry = new RetainedTranslationRegistry( $wpdb );
$retained_fr       = $retained_registry->get_retained_for_language( 'fr' );
$retained_count    = count( $retained_fr );

$expected_fr_ids = array( 8839, 8955, 8873 );
$all_retained_ok = true;
foreach ( $expected_fr_ids as $fid ) {
	if ( ! $retained_registry->has_retained_element( 'post', $fid ) ) {
		$all_retained_ok = false;
	}
}

$lab_data_ok = ( 123 === $count_groups && 139 === $count_elements && 3 === $retained_count && $all_retained_ok );

record_gate(
	'3. Invarianza Laboratorio — 123 grupos, 139 elementos y 3 traducciones FR retenidas preservadas',
	$lab_data_ok,
	sprintf( 'groups=%d/123 | elements=%d/139 | retained_fr=%d/3 (ids [8839,8955,8873] ok=%s)', $count_groups, $count_elements, $retained_count, $all_retained_ok ? 'true' : 'false' )
);

// -------------------------------------------------------------------------
// CONTROL 4: Centinela WPML en Laboratorio Intacto (3,403 filas)
// -------------------------------------------------------------------------
$lab_sentinel = get_icl_sentinel( 'cms_ect_lab' );
$lab_sent_ok  = ( 3403 === $lab_sentinel['rows'] && $expected_prod_md5 === $lab_sentinel['md5'] );

record_gate(
	'4. Centinela WPML Laboratorio — 3,403 filas en etk_t_icl_translations intactas',
	$lab_sent_ok,
	sprintf( 'rows=%d/3403 | md5=%s', $lab_sentinel['rows'], $lab_sentinel['md5'] )
);

// -------------------------------------------------------------------------
// CONTROL 5: Integridad de los 13 Manifiestos de Ejecución y Checkpoint Activo
// -------------------------------------------------------------------------
$rollback_service = new WpmlMigrationRollbackService( $wpdb );
$all_manifests    = $rollback_service->get_all_execution_manifests();
$manifest_count   = count( $all_manifests );

$total_imported_via_manifests = 0;
foreach ( $all_manifests as $m ) {
	$imported                      = (array) ( $m['imported_group_ids'] ?? ( $m['imported_groups'] ?? array() ) );
	$total_imported_via_manifests += count( $imported );
}

$checkpoint_repo   = new MigrationCheckpointRepository( $wpdb );
$active_checkpoint = $checkpoint_repo->get( 'wpml' );

$chk_ok = (
	null !== $active_checkpoint &&
	'wpml' === $active_checkpoint->get_source() &&
	'completed' === $active_checkpoint->get_status() &&
	9545 === $active_checkpoint->get_last_processed_trid() &&
	2 === $active_checkpoint->get_processed_items() &&
	2 === $active_checkpoint->get_total_items()
);

$manifests_ok = ( 13 === $manifest_count && 123 === $total_imported_via_manifests && $chk_ok );

record_gate(
	'5. Manifiestos y Checkpoint — 13 manifiestos inmutables (123 grupos importados) y checkpoint #9545 completed',
	$manifests_ok,
	sprintf(
		'manifests=%d/13 | total_imported_groups=%d/123 | checkpoint_status=%s | last_trid=%d/9545',
		$manifest_count,
		$total_imported_via_manifests,
		$active_checkpoint ? $active_checkpoint->get_status() : 'null',
		$active_checkpoint ? $active_checkpoint->get_last_processed_trid() : 0
	)
);

// -------------------------------------------------------------------------
// CONTROL 6: Registro y Jerarquía de Menú de WordPress
// -------------------------------------------------------------------------
$plugin = Plugin::get_instance();
$plugin->init();

$migration_ui = $plugin->get_migration_ui();
$menu_registered_ok = ( null !== $migration_ui && 'manage_options' === $migration_ui->get_capability() );

// Verificar prioridades de ganchos admin_menu
global $wp_filter;
$admin_menu_callbacks = isset( $wp_filter['admin_menu'] ) ? $wp_filter['admin_menu']->callbacks : array();

$has_p9  = isset( $admin_menu_callbacks[9] );  // LanguageEditorialUi
$has_p10 = isset( $admin_menu_callbacks[10] ); // TranslationsOverviewUi
$has_p11 = isset( $admin_menu_callbacks[11] ); // StringEditorialUi
$has_p12 = isset( $admin_menu_callbacks[12] ); // MigrationUi
$has_p13 = isset( $admin_menu_callbacks[13] ); // DiagnosticUi

$menu_order_ok = ( $menu_registered_ok && $has_p9 && $has_p10 && $has_p11 && $has_p12 && $has_p13 );

record_gate(
	'6. Registro y Jerarquía de Menús — Idiomas (9) -> Traducciones (10) -> Cadenas (11) -> Migración WPML (12) -> Diagnóstico (13)',
	$menu_order_ok,
	sprintf(
		'migration_ui_exists=%s | cap=%s | priorities: [p9:%s, p10:%s, p11:%s, p12:%s, p13:%s]',
		null !== $migration_ui ? 'true' : 'false',
		$migration_ui ? $migration_ui->get_capability() : 'none',
		$has_p9 ? 'OK' : 'MISSING',
		$has_p10 ? 'OK' : 'MISSING',
		$has_p11 ? 'OK' : 'MISSING',
		$has_p12 ? 'OK' : 'MISSING',
		$has_p13 ? 'OK' : 'MISSING'
	)
);

// -------------------------------------------------------------------------
// CONTROL 7: Plan Determinista y Huella Criptográfica SHA-256
// -------------------------------------------------------------------------
$migration_mgr = $plugin->get_migration_manager();
$planner       = $migration_mgr->get_wpml_planner();
$plan          = $planner->generate_plan( 0, 0, true );

$plan_fingerprint = $plan->get_fingerprint();
$expected_fp      = 'c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99';
$plan_summary     = $plan->get_summary();
$blocked_groups   = (int) ( $plan_summary['blocked_language_groups'] ?? ( $plan_summary['blocked_by_language_groups'] ?? 0 ) );

$fp_ok = (
	$expected_fp === $plan_fingerprint &&
	249 === (int) ( $plan_summary['total_groups'] ?? 0 ) &&
	129 === (int) ( $plan_summary['conflict_groups'] ?? 0 ) &&
	48 === $blocked_groups &&
	48 === (int) ( $plan_summary['orphan_groups'] ?? 0 ) &&
	24 === (int) ( $plan_summary['skipped_groups'] ?? 0 )
);

record_gate(
	'7. Plan Determinista y Huella Criptográfica — SHA-256 intacta en 249 TRIDs (129 conflicto, 48 bloq. idioma, 48 huérfanos, 24 omitidos)',
	$fp_ok,
	sprintf(
		'fingerprint=%s (esperado: %s) | total_trids=%d | conflicts=%d | blocked_lang=%d | orphans=%d | skipped=%d',
		$plan_fingerprint,
		$expected_fp,
		$plan_summary['total_groups'] ?? 0,
		$plan_summary['conflict_groups'] ?? 0,
		$blocked_groups,
		$plan_summary['orphan_groups'] ?? 0,
		$plan_summary['skipped_groups'] ?? 0
	)
);

// -------------------------------------------------------------------------
// CONTROL 8: Renderizado del Panel Administrativo MigrationUi (4 Pestañas)
// -------------------------------------------------------------------------
// Simular contexto administrativo con permisos manage_options
$user_id = 1;
wp_set_current_user( $user_id );

// 8.1 Pestaña Plan
$_GET = array(
	'page' => 'tfml-migration',
	'tab'  => 'plan',
);
ob_start();
$migration_ui->render_page();
$html_plan = (string) ob_get_clean();

$plan_tab_ok = (
	false !== strpos( $html_plan, 'Migración WPML' ) &&
	false !== strpos( $html_plan, 'Modo Consulta y Auditoría' ) &&
	false !== strpos( $html_plan, '3,403' ) &&
	false !== strpos( $html_plan, $expected_fp ) &&
	false !== strpos( $html_plan, 'MEDIA_MODEL_B_DEDUPLICATION' ) &&
	false !== strpos( $html_plan, 'BLOQ. IDIOMA (FR)' )
);

// 8.2 Pestaña Historial
$_GET = array(
	'page' => 'tfml-migration',
	'tab'  => 'history',
);
ob_start();
$migration_ui->render_page();
$html_history = (string) ob_get_clean();

$history_tab_ok = (
	false !== strpos( $html_history, 'Punto de Control (Checkpoint) Activo' ) &&
	false !== strpos( $html_history, 'Historial de Manifiestos de Ejecución' ) &&
	false !== strpos( $html_history, '#9545' ) &&
	false !== strpos( $html_history, '13 Lotes Certificados' )
);

// 8.3 Pestaña Custodia FR
$_GET = array(
	'page' => 'tfml-migration',
	'tab'  => 'retained',
);
ob_start();
$migration_ui->render_page();
$html_retained = (string) ob_get_clean();

$retained_tab_ok = (
	false !== strpos( $html_retained, 'Traducciones Retenidas en Custodia (Idioma Inactivo FR)' ) &&
	false !== strpos( $html_retained, 'Modelo de Cero Polución' ) &&
	false !== strpos( $html_retained, '#197' ) &&
	false !== strpos( $html_retained, '#8839' ) &&
	false !== strpos( $html_retained, '#9201' ) &&
	false !== strpos( $html_retained, '#8955' ) &&
	false !== strpos( $html_retained, '#9545' ) &&
	false !== strpos( $html_retained, '#8873' )
);

// 8.4 Pestaña Próximo Lote
$_GET = array(
	'page' => 'tfml-migration',
	'tab'  => 'next_batch',
);
ob_start();
$migration_ui->render_page();
$html_next = (string) ob_get_clean();

$next_tab_ok = (
	false !== strpos( $html_next, 'Propuesta Técnica: Próximo Lote Mínimo Elegible de Páginas' ) &&
	false !== strpos( $html_next, '1. Páginas Independientes' ) &&
	false !== strpos( $html_next, '2. Páginas Jerárquicas con Padre Migrado' ) &&
	false !== strpos( $html_next, '#6237 Nosotros (TFML Grp #588)' ) &&
	false !== strpos( $html_next, '#6505 Servicios (TFML Grp #593)' ) &&
	false !== strpos( $html_next, 'Precondiciones Mandatorias' )
);

$all_render_ok = ( $plan_tab_ok && $history_tab_ok && $retained_tab_ok && $next_tab_ok );

record_gate(
	'8. Renderizado del Panel Administrativo — 4 pestañas operativas (Plan, Historial, Custodia FR, Próximo Lote)',
	$all_render_ok,
	sprintf(
		'plan_tab=%s | history_tab=%s | retained_tab=%s | next_batch_tab=%s',
		$plan_tab_ok ? 'PASS' : 'FAIL',
		$history_tab_ok ? 'PASS' : 'FAIL',
		$retained_tab_ok ? 'PASS' : 'FAIL',
		$next_tab_ok ? 'PASS' : 'FAIL'
	)
);

// -------------------------------------------------------------------------
// CONTROL 9: Garantía de Solo Consulta / Read-Only
// -------------------------------------------------------------------------
// 9.1 Rechazo de llamadas POST
$_SERVER['REQUEST_METHOD'] = 'POST';
$post_blocked              = false;
try {
	ob_start();
	$migration_ui->render_page();
	ob_end_clean();
} catch ( \RuntimeException $e ) {
	$post_blocked = ( 403 === $e->getCode() );
}
$_SERVER['REQUEST_METHOD'] = 'GET';

// 9.2 Verificación de invarianza de base de datos post-renderizado
$post_groups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_groups}" );
$post_elements = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_elements}" );
$post_retained = count( $retained_registry->get_retained_for_language( 'fr' ) );

$readonly_guaranteed = (
	$post_blocked &&
	123 === $post_groups &&
	139 === $post_elements &&
	3 === $post_retained
);

record_gate(
	'9. Garantía de Solo Consulta — POST rechazado con 403 y base de datos de laboratorio 100% inalterada tras render',
	$readonly_guaranteed,
	sprintf(
		'post_blocked_403=%s | groups=%d/123 | elements=%d/139 | retained_fr=%d/3',
		$post_blocked ? 'true' : 'false',
		$post_groups,
		$post_elements,
		$post_retained
	)
);

// -------------------------------------------------------------------------
// CONTROL 10: No-Regresión Frontend HTTP 200, SEO Canónico y Sitemaps
// -------------------------------------------------------------------------
$urls_to_test = array(
	'http://lab.ecoterra.test/nosotros/'       => 'http://lab.ecoterra.test/nosotros/',
	'http://lab.ecoterra.test/en/about-us/'    => 'http://lab.ecoterra.test/en/about-us/',
	'http://lab.ecoterra.test/actividades/'    => 'http://lab.ecoterra.test/actividades/',
	'http://lab.ecoterra.test/en/activities/' => 'http://lab.ecoterra.test/en/activities/',
);

$http_all_ok = true;
$http_errors = array();

foreach ( $urls_to_test as $req_url => $expected_canonical ) {
	$resp = fetch_and_inspect_http( $req_url );
	if ( 200 !== $resp['code'] ) {
		$http_all_ok   = false;
		$http_errors[] = sprintf( '%s retornó HTTP %d', $req_url, $resp['code'] );
	}
	if ( ! in_array( $expected_canonical, $resp['canonicals'], true ) ) {
		$http_all_ok   = false;
		$http_errors[] = sprintf( '%s canonical esperado %s no encontrado', $req_url, $expected_canonical );
	}
}

// Comprobación de Sitemaps (30 entradas sin francés, blog_public = 0)
$provider_posts = new WP_Sitemaps_Posts();
$provider_tax   = new WP_Sitemaps_Taxonomies();

$sitemap_pages = $provider_posts->get_url_list( 1, 'page' );
$sitemap_posts = $provider_posts->get_url_list( 1, 'post' );
$sitemap_cats  = $provider_tax->get_url_list( 1, 'category' );
$sitemap_tags  = $provider_tax->get_url_list( 1, 'post_tag' );

$count_pages = count( $sitemap_pages );
$count_posts = count( $sitemap_posts );
$count_cats  = count( $sitemap_cats );
$count_tags  = count( $sitemap_tags );
$total_urls  = $count_pages + $count_posts + $count_cats + $count_tags;

$blog_public_opt = (int) get_option( 'blog_public' );

$sitemaps_ok = ( 7 === $count_pages && 3 === $count_posts && 1 === $count_cats && 19 === $count_tags && 30 === $total_urls && 0 === $blog_public_opt );

$no_regression_ok = ( $http_all_ok && $sitemaps_ok );

record_gate(
	'10. No-Regresión Frontend, SEO Canónico y Sitemaps — HTTP 200, canonicals íntegros y 30 entradas en sitemaps sin francés retenido',
	$no_regression_ok,
	sprintf(
		'http_urls_tested=%d | all_200_and_canonical=%s | sitemaps=[page:%d/7, post:%d/3, cat:%d/1, tag:%d/19, total:%d/30, blog_public:%d] | errors: [%s]',
		count( $urls_to_test ),
		$http_all_ok ? 'true' : 'false',
		$count_pages,
		$count_posts,
		$count_cats,
		$count_tags,
		$total_urls,
		$blog_public_opt,
		empty( $http_errors ) ? 'ninguno' : implode( '; ', $http_errors )
	)
);

echo "\n========================================================================\n";
echo sprintf( "RESULTADO GLOBAL DE FASE 3.45: %d/10 CONTROLES PASS (%d fallos)\n", $passed, $failed );
echo "========================================================================\n";

if ( 10 === $passed && 0 === $failed ) {
	echo "\nFASE 3.45 CUMPLIDA SATISFACTORIAMENTE — DIRECTIVA DT-TFML-077 VERIFICADA.\n";
	exit( 0 );
} else {
	echo "\nERROR: FASE 3.45 CONSERVA CONTROLES EN ESTADO FALLIDO.\n";
	exit( 1 );
}
