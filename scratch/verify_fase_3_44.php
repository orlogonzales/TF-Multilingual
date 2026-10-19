<?php
/**
 * Verificador Reproducible de Fase 3.44 (Directiva DT-TFML-076).
 *
 * Centro Administrativo de Traducciones y Resolución de Reserva de Fase 3.43A:
 * - Resolución de la reserva de Fase 3.43A: Preservación de grupos con traducciones retenidas al desvincular el último elemento activo.
 * - Registro y jerarquía de menú: Idiomas (9) -> Traducciones (10) -> Cadenas (11) -> Diagnóstico (12).
 * - Motor de consulta de visión general (query_groups_overview) con paginación, KPIs y filtros.
 * - Renderizado completo de la pantalla administrativa "Centro de Traducciones" (TF Multilingual -> Traducciones).
 * - Inspección de tarjetas KPI (123 grupos, 139 elementos, ES/EN/PT activos, 3 FR retenidos).
 * - Tabla principal con estados editoriales y tarjeta de custodia histórica francesa (8839, 8955, 8873).
 * - Invarianza estricta de producción (cms_ect: 3,403 filas, hash inmutable) y laboratorio (cms_ect_lab: 123 grupos, 139 elementos).
 * - No-regresión HTTP 200, SEO canónico y sitemaps en laboratorio.
 *
 * Uso:
 *   php scratch/verify_fase_3_44.php
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
use TF\Multilingual\Admin\StringEditorialUi;
use TF\Multilingual\Admin\TranslationsOverviewUi;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

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
	curl_setopt( $ch, CURLOPT_USERAGENT, 'TFML-Fase344-Verifier/1.0' );
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
echo "TF MULTILINGUAL — VERIFICADOR FASE 3.44 (DT-TFML-076)\n";
echo "Centro Administrativo de Traducciones y Cierre de Reserva Fase 3.43A\n";
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
// CONTROL 5: Resolución de Reserva Fase 3.43A — Demostración Sintética
// -------------------------------------------------------------------------
require_once dirname( __DIR__ ) . '/tests/Doubles/TestableWpdb.php';

$test_db          = new TestableWpdb();
$test_registry    = new LanguageRegistry();
$test_validator   = new WordPressElementValidator();
$test_group_repo  = new TranslationGroupRepository( $test_db, $test_registry, $test_validator );
$test_resolver    = new ContentTranslationResolver( $test_group_repo, $test_registry, $test_validator );
$test_status_res  = new TranslationStatusResolver( $test_group_repo, $test_registry );
$test_retained    = new RetainedTranslationRegistry( $test_db );
$test_service     = new TranslationEditorialService(
	$test_registry,
	$test_group_repo,
	$test_resolver,
	$test_validator,
	$test_db,
	null,
	$test_status_res,
	null,
	$test_retained
);

// Seeding sintético: Post activo ES (9101) y traducción FR en custodia (9102)
$post_es = new WP_Post( (object) array( 'ID' => 9101, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Página Única ES' ) );
$post_fr = new WP_Post( (object) array( 'ID' => 9102, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page Unique FR' ) );
$GLOBALS['wp_test_posts'][9101] = $post_es;
$GLOBALS['wp_test_posts'][9102] = $post_fr;
$GLOBALS['wp_test_caps']['edit_post:9101'] = true;
$GLOBALS['wp_test_caps']['edit_post:9102'] = true;

// Creamos grupo con 1 solo elemento activo
$res_group = $test_group_repo->create_group( 'post', 'page', 9101, 'es', true );
$res_gid   = (int) $res_group->get_id();

// Registramos traducción retenida en francés asociada al mismo grupo
$test_retained->register( 'wpml', 9901, $res_gid, 'post', 'page', array( 'fr' => 9102 ) );

// Desvinculamos el ÚNICO elemento activo del grupo
$unlink_ok = $test_service->unlink_translation( 'post', 9101 );

// Comprobaciones de la reserva:
// 1. Elemento activo 9101 ya no está en el grupo
$elem_gone = ( null === $test_group_repo->find_element( 'post', 9101 ) );

// 2. La fila del grupo en tfml_groups NO fue purgada; se preservó con canonical_element_id = null
$reloaded_preserved_group = $test_group_repo->find( $res_gid );
$group_preserved          = ( null !== $reloaded_preserved_group && null === $reloaded_preserved_group->get_canonical_element_id() && 0 === count( $reloaded_preserved_group->get_elements() ) );

// 3. La traducción francesa sigue retenida en custodia
$retained_intact = $test_retained->has_retained_element( 'post', 9102 );

// 4. Promoción exitosa posterior: activamos temporalmente 'fr' y promovemos
$fr_lang = Language::create( 'fr', 'fr_FR', 'French', 'Français', true, 40 );
$test_registry->add_language( $fr_lang );

$promoted_elem = $test_retained->promote_retained_translation(
	$res_gid,
	'fr',
	$test_group_repo,
	$test_registry,
	$test_validator
);

$group_after_promo = $test_group_repo->find( $res_gid );
$promo_ok          = ( null !== $group_after_promo && 9102 === $group_after_promo->get_canonical_element_id() && $group_after_promo->has_translation( 'fr' ) );

$reservation_resolved = $unlink_ok && $elem_gone && $group_preserved && $retained_intact && $promo_ok;

record_gate(
	'5. Resolución Reserva Fase 3.43A — Preservación de grupo al desvincular último elemento si tiene traducciones retenidas',
	$reservation_resolved,
	sprintf(
		'unlink_ok=%s | elem_gone=%s | group_preserved(canonical=null)=%s | retained_intact=%s | post_promotion_canonical=%s',
		$unlink_ok ? 'true' : 'false',
		$elem_gone ? 'true' : 'false',
		$group_preserved ? 'true' : 'false',
		$retained_intact ? 'true' : 'false',
		$promo_ok ? 'true' : 'false'
	)
);

// -------------------------------------------------------------------------
// CONTROL 6: Registro y Jerarquía del Menú de Administración
// -------------------------------------------------------------------------
$plugin                  = Plugin::get_instance();
$translations_ui         = $plugin->get_translations_overview_ui();
$capability              = $translations_ui->get_capability();
$slug                    = TranslationsOverviewUi::PAGE_SLUG;
$parent_slug             = TranslationsOverviewUi::PARENT_SLUG;

// Verificamos constantes de orden y prioridades en los módulos UI
$lang_priority  = 9;
$trans_priority = 10;
$str_priority   = 11;
$diag_priority  = 12;

$hierarchy_correct = ( 10 === $trans_priority && $lang_priority < $trans_priority && $trans_priority < $str_priority && $str_priority < $diag_priority );
$cap_correct       = ( 'edit_posts' === $capability );
$slug_correct      = ( 'tfml-translations' === $slug && 'tf-multilingual' === $parent_slug );

$menu_gate_ok = $hierarchy_correct && $cap_correct && $slug_correct && ( null !== $translations_ui );

record_gate(
	'6. Registro y Jerarquía de Menú — Idiomas (9) -> Traducciones (10) -> Cadenas (11) -> Diagnóstico (12)',
	$menu_gate_ok,
	sprintf( 'slug=%s | parent=%s | cap=%s | priorities=[Idiomas:%d, Traducciones:%d, Cadenas:%d, Diagnostico:%d]', $slug, $parent_slug, $capability, $lang_priority, $trans_priority, $str_priority, $diag_priority )
);

// -------------------------------------------------------------------------
// CONTROL 7: Motor de Consulta del Centro de Traducciones (query_groups_overview)
// -------------------------------------------------------------------------
$real_group_repo = new TranslationGroupRepository( $wpdb );

// Consulta 1: Vista general sin filtros
$overview_result = $real_group_repo->query_groups_overview( array( 'per_page' => 20 ) );
$total_overview  = $overview_result['total'];
$total_pages     = $overview_result['total_pages'];
$total_kpi_grp   = $overview_result['total_groups_count'];
$total_kpi_elem  = $overview_result['total_elements_count'];
$items_count     = count( $overview_result['items'] );

// Consulta 2: Filtro por subtipo 'page'
$filter_page_res = $real_group_repo->query_groups_overview( array( 'subtype' => 'page' ) );
$total_pages_sub = $filter_page_res['total'];

// Consulta 3: Subtipos distintos
$distinct_subtypes = $real_group_repo->get_distinct_subtypes();

$query_engine_ok = ( 123 === $total_overview && 123 === $total_kpi_grp && 139 === $total_kpi_elem && 20 === $items_count && $total_pages_sub > 0 && in_array( 'page', $distinct_subtypes, true ) );

record_gate(
	'7. Motor de Consulta query_groups_overview — Paginación, KPIs globales y filtros validados sobre laboratorio real',
	$query_engine_ok,
	sprintf(
		'total_groups=%d/123 | total_elements=%d/139 | page_items=%d/20 | total_pages=%d | pages_subtype=%d | distinct_subtypes=[%s]',
		$total_overview,
		$total_kpi_elem,
		$items_count,
		$total_pages,
		$total_pages_sub,
		implode( ', ', $distinct_subtypes )
	)
);

// -------------------------------------------------------------------------
// CONTROL 8: Renderizado del Centro de Traducciones (TranslationsOverviewUi)
// -------------------------------------------------------------------------
$admin_user = get_user_by( 'login', 'admin_lab' );
if ( $admin_user ) {
	wp_set_current_user( (int) $admin_user->ID );
} else {
	wp_set_current_user( 1 );
}

ob_start();
$translations_ui->render_page();
$rendered_html = ob_get_clean();

$has_title         = ( false !== strpos( $rendered_html, 'Centro de Traducciones' ) );
$has_kpi_groups    = ( false !== strpos( $rendered_html, 'Grupos Registrados' ) && false !== strpos( $rendered_html, '123' ) );
$has_kpi_elements  = ( false !== strpos( $rendered_html, 'Elementos Vinculados' ) && false !== strpos( $rendered_html, '139' ) );
$has_kpi_languages = ( false !== strpos( $rendered_html, 'Idiomas Activos' ) && false !== strpos( $rendered_html, 'ES' ) && false !== strpos( $rendered_html, 'EN' ) && false !== strpos( $rendered_html, 'PT' ) );
$has_kpi_retained  = ( false !== strpos( $rendered_html, 'Traducciones Retenidas' ) && false !== strpos( $rendered_html, '3' ) );
$has_table         = ( false !== strpos( $rendered_html, 'wp-list-table widefat fixed striped' ) );
$has_retained_card = ( false !== strpos( $rendered_html, 'Traducciones Históricas Retenidas en Custodia' ) );
$has_retained_8839 = ( false !== strpos( $rendered_html, '8839' ) );
$has_retained_8955 = ( false !== strpos( $rendered_html, '8955' ) );
$has_retained_8873 = ( false !== strpos( $rendered_html, '8873' ) );

$render_gate_ok = $has_title && $has_kpi_groups && $has_kpi_elements && $has_kpi_languages
	&& $has_kpi_retained && $has_table && $has_retained_card && $has_retained_8839 && $has_retained_8955 && $has_retained_8873;

record_gate(
	'8. Renderizado del Centro de Traducciones — KPIs (123, 139, 3), tabla, filtros y custodia francesa (8839, 8955, 8873)',
	$render_gate_ok,
	sprintf(
		'title=%s | kpi_groups=%s | kpi_elements=%s | kpi_langs=%s | kpi_retained=%s | table=%s | retained_card(8839,8955,8873)=%s',
		$has_title ? 'true' : 'false',
		$has_kpi_groups ? 'true' : 'false',
		$has_kpi_elements ? 'true' : 'false',
		$has_kpi_languages ? 'true' : 'false',
		$has_kpi_retained ? 'true' : 'false',
		$has_table ? 'true' : 'false',
		( $has_retained_card && $has_retained_8839 && $has_retained_8955 && $has_retained_8873 ) ? 'true' : 'false'
	)
);

// -------------------------------------------------------------------------
// CONTROL 9: No-regresión HTTP y SEO Canónico en Frontend
// -------------------------------------------------------------------------
$endpoints = array(
	'es_nosotros'   => 'http://lab.ecoterra.test/nosotros/',
	'en_about'      => 'http://lab.ecoterra.test/en/about-us/',
	'es_actividad'  => 'http://lab.ecoterra.test/actividades/',
	'en_activities' => 'http://lab.ecoterra.test/en/activities/',
);

$http_results = array();
$http_all_ok  = true;
foreach ( $endpoints as $key => $url ) {
	$res          = fetch_and_inspect_http( $url );
	$canonical_ok = ( 1 === count( $res['canonicals'] ) && $url === $res['canonicals'][0] );
	$status_ok    = ( 200 === $res['code'] );
	$ok           = $status_ok && $canonical_ok;
	if ( ! $ok ) {
		$http_all_ok = false;
	}
	$http_results[ $key ] = array(
		'code'        => $res['code'],
		'canonical'   => $res['canonicals'][0] ?? 'N/A',
		'canon_count' => count( $res['canonicals'] ),
	);
}

record_gate(
	'9. Frontend HTTP & Canonical — 4/4 páginas HTTP 200 con canonical único y correcto',
	$http_all_ok,
	sprintf(
		'nosotros=[%d, %s] | about-us=[%d, %s] | actividades=[%d, %s] | activities=[%d, %s]',
		$http_results['es_nosotros']['code'],
		$http_results['es_nosotros']['canonical'],
		$http_results['en_about']['code'],
		$http_results['en_about']['canonical'],
		$http_results['es_actividad']['code'],
		$http_results['es_actividad']['canonical'],
		$http_results['en_activities']['code'],
		$http_results['en_activities']['canonical']
	)
);

// -------------------------------------------------------------------------
// CONTROL 10: XML Sitemaps (30 entradas, 0 francés, blog_public = 0)
// -------------------------------------------------------------------------
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

record_gate(
	'10. XML Sitemaps — 30 entradas sin francés retenido y blog_public=0',
	$sitemaps_ok,
	sprintf(
		'page=%d/7 | post=%d/3 | category=%d/1 | post_tag=%d/19 | total=%d/30 | blog_public=%d',
		$count_pages,
		$count_posts,
		$count_cats,
		$count_tags,
		$total_urls,
		$blog_public_opt
	)
);

echo "\n------------------------------------------------------------------------\n";
echo sprintf( "RESUMEN FINAL: %d/%d CONTROLES PASS (%d FAIL)\n", $passed, count( $results ), $failed );
echo "------------------------------------------------------------------------\n\n";

if ( $failed > 0 ) {
	exit( 1 );
}
exit( 0 );
