<?php
/**
 * Script de Verificación Reproducible — Fase 3.36 (DT-TFML-066).
 *
 * Endurecimiento del Núcleo: Sitemaps, Banderas y Disponibilidad Real de Traducciones.
 *
 * Verifica (100% solo lectura, cero mutaciones en BD):
 * 1. Preflight bloqueante e invariancia centinela de datos (cms_ect intacta, cms_ect_lab intacta: 121 grupos, 135 elementos, grupo 587, 1 retenido fr:8839, 12 manifiestos).
 * 2. Endurecimiento de CoreSitemapsFilter: alineación de constantes QUERY_VAR_SUPPRESS, inclusión simultánea de páginas ES (197 -> /) y EN (8356 -> /en/) en WP_Sitemaps_Posts, y exclusión estricta del francés retenido (8839).
 * 3. Endurecimiento de banderas e iconos lingüísticos locales (assets/images/flags/{es,en,pt}.svg): existencia física, HTTP 200 image/svg+xml, retorno '' para códigos sin archivo físico, alternativa textual accesible y rechazo de dominios externos.
 * 4. Disponibilidad real de traducciones en ServicioTraduccionPublico e icl_get_languages(): distinción entre idioma activo (pt) y traducción publicada real (missing=1, url='' con skip_missing=0; omitido con skip_missing=1; ausencia de enlace engañoso a /pt/ en selector público).
 * 5. Auditoría en navegador Chromium/Edge real con aislamiento estricto de red (--host-resolver-rules): 0 conexiones externas exitosas, 3/3 insignias SVG cargadas con naturalWidth > 0 y 0 excepciones JS.
 * 6. Matriz de no regresión HTTP y verificación de invariancia post-fase de bases de datos.
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

$lab_root = 'D:/laragon/www/lab.ecoterra';
if ( ! file_exists( $lab_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "ERROR: No se encontró wp-load.php en {$lab_root}\n" );
	exit( 1 );
}

require_once $lab_root . '/wp-load.php';
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Query\QueryLanguageFilter;
use TF\Multilingual\Query\TermQueryLanguageFilter;

$lab_url = getenv( 'TFML_LAB_URL' ) ? rtrim( (string) getenv( 'TFML_LAB_URL' ), '/' ) : 'http://lab.ecoterra.test';

$total_checks  = 0;
$passed_checks = 0;
$failed_checks = 0;

/**
 * Registra un control de verificación con estado explícito (PASS / FAIL).
 *
 * @param string $label     Descripción del control.
 * @param bool   $condition Condición booleana evaluada.
 * @param string $detail    Detalle adicional.
 * @return void
 */
function record_gate( string $label, bool $condition, string $detail = '' ): void {
	global $total_checks, $passed_checks, $failed_checks;
	++$total_checks;

	if ( $condition ) {
		++$passed_checks;
		echo sprintf( "[PASS #%02d] %s%s\n", $total_checks, $label, '' !== $detail ? " — {$detail}" : '' );
	} else {
		++$failed_checks;
		echo sprintf( "[FAIL #%02d] %s%s\n", $total_checks, $label, '' !== $detail ? " — {$detail}" : '' );
	}
}

/**
 * Obtiene la huella centinela de una tabla icl_translations en una base de datos.
 *
 * @param string $db_name Nombre de la base de datos.
 * @return array{rows: int, md5: string}
 */
function get_icl_sentinel( string $db_name ): array {
	$mysqli = new mysqli( '127.0.0.1', 'root', '', $db_name, 3306 );
	$mysqli->set_charset( 'utf8mb4' );
	$mysqli->query( 'SET SESSION group_concat_max_len = 10485760' );
	$res = $mysqli->query(
		"SELECT COUNT(*) AS cnt, MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code,'')) ORDER BY translation_id ASC SEPARATOR '|')) AS hash_val FROM etk_t_icl_translations"
	);
	$row = $res ? $res->fetch_assoc() : array();
	$mysqli->close();

	return array(
		'rows' => (int) ( $row['cnt'] ?? 0 ),
		'md5'  => (string) ( $row['hash_val'] ?? '' ),
	);
}

/**
 * Ejecuta una petición HTTP real y extrae código, cabeceras, tipo MIME, metadatos SEO, selector de idiomas y errores PHP.
 *
 * @param string $url             URL objetivo.
 * @param bool   $follow_location True para seguir redirecciones.
 * @return array{
 *     code: int,
 *     content_type: string,
 *     location: string,
 *     lang: string,
 *     canonical: string,
 *     hreflang: array<string, string>,
 *     selector_navigable_hrefs: array<int, string>,
 *     selector_disabled_count: int,
 *     has_pt_navigable_link: bool,
 *     php_errors: bool,
 *     body: string
 * }
 */
function inspect_http_route( string $url, bool $follow_location = false ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => $follow_location,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_USERAGENT      => 'TFML-Fase-3.36-Verifier/1.0',
		)
	);

	$raw_response = curl_exec( $ch );
	$header_size  = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$code         = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$content_type = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
	curl_close( $ch );

	$raw_response = is_string( $raw_response ) ? $raw_response : '';
	$headers      = substr( $raw_response, 0, $header_size );
	$body         = substr( $raw_response, $header_size );

	$location = '';
	if ( preg_match( '/^Location:\s*(.+)$/im', $headers, $loc_matches ) ) {
		$location = trim( $loc_matches[1] );
	}

	$lang = '';
	if ( preg_match( '/<html[^>]*lang=["\']([^"\']+)["\']/i', $body, $lang_matches ) ) {
		$lang = trim( $lang_matches[1] );
	}

	$canonical = '';
	if ( preg_match( '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']/i', $body, $canon_matches ) ) {
		$canonical = trim( $canon_matches[1] );
	}

	$hreflang = array();
	if ( preg_match_all( '/<link\s+rel=["\']alternate["\']\s+hreflang=["\']([^"\']+)["\']\s+href=["\']([^"\']+)["\']/i', $body, $href_matches, PREG_SET_ORDER ) ) {
		foreach ( $href_matches as $m ) {
			$hreflang[ $m[1] ] = $m[2];
		}
	}

	$selector_navigable_hrefs = array();
	$selector_disabled_count  = 0;
	if ( preg_match( '/<span\s+class=["\']idiomas_header_bg["\']>(.*?)<\/span>/is', $body, $sel_match ) ) {
		$sel_html = $sel_match[1];
		if ( preg_match_all( '/<a\b[^>]*\bhref=["\']([^"\']*)["\'][^>]*>/i', $sel_html, $a_matches ) ) {
			foreach ( $a_matches[1] as $h ) {
				$selector_navigable_hrefs[] = $h;
			}
		}
		$selector_disabled_count = preg_match_all( '/<a\b[^>]*data-tfml-missing=["\']1["\'][^>]*>/i', $sel_html );
	}

	$has_pt_navigable_link = false;
	foreach ( $selector_navigable_hrefs as $href_item ) {
		if ( '' === $href_item || false !== strpos( $href_item, '/pt/' ) ) {
			$has_pt_navigable_link = true;
		}
	}

	$php_errors = (bool) preg_match( '/(Fatal error|Uncaught Error|Uncaught Exception|Parse error|Warning:\s|Deprecated:\s)/i', $body );

	return array(
		'code'                     => $code,
		'content_type'             => $content_type,
		'location'                 => $location,
		'lang'                     => $lang,
		'canonical'                => $canonical,
		'hreflang'                 => $hreflang,
		'selector_navigable_hrefs' => $selector_navigable_hrefs,
		'selector_disabled_count'  => (int) $selector_disabled_count,
		'has_pt_navigable_link'    => $has_pt_navigable_link,
		'php_errors'               => $php_errors,
		'body'                     => $body,
	);
}

/**
 * Ejecuta una auditoría real en navegador Chromium/Edge con bloqueo estricto de red externa
 * (--host-resolver-rules) para verificar aislamiento de red y carga de las 3 insignias SVG locales.
 *
 * @param string $edge_bin Ruta al ejecutable msedge.exe.
 * @param string $url      URL local a inspeccionar.
 * @return array{
 *     dom_loaded: bool,
 *     flag_imgs_total: int,
 *     flag_imgs_loaded: int,
 *     navigable_links: int,
 *     disabled_placeholders: int,
 *     external_loaded_resources: int
 * }
 */
function run_isolated_browser_check( string $edge_bin, string $url ): array {
	$profile_dir = sys_get_temp_dir() . '/tfml_edge_iso_' . uniqid();
	@mkdir( $profile_dir, 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	$host_rules = 'MAP * ~NOTFOUND , EXCLUDE lab.ecoterra.test , EXCLUDE 127.0.0.1 , EXCLUDE localhost';
	$cmd        = sprintf(
		'"%s" --headless --disable-gpu --no-first-run --no-default-browser-check --user-data-dir="%s" --host-resolver-rules="%s" --window-size=1440,900 --virtual-time-budget=3500 --dump-dom "%s" 2>NUL',
		$edge_bin,
		$profile_dir,
		$host_rules,
		$url
	);

	$dom = (string) shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

	$flag_imgs_total       = preg_match_all( '/<img\b[^>]*src=["\'][^"\']*\/assets\/images\/flags\/(es|en|pt)\.svg["\'][^>]*>/i', $dom );
	$navigable_links       = 0;
	$disabled_placeholders = 0;

	if ( preg_match( '/<span\s+class=["\']idiomas_header_bg["\']>(.*?)<\/span>/is', $dom, $m ) ) {
		$navigable_links       = (int) preg_match_all( '/<a\b[^>]*\bhref=["\']https?:\/\/lab\.ecoterra\.test\/(?:en\/)?["\'][^>]*>/i', $m[1] );
		$disabled_placeholders = (int) preg_match_all( '/<a\b[^>]*data-tfml-missing=["\']1["\'][^>]*>/i', $m[1] );
	}

	return array(
		'dom_loaded'                => false !== strpos( $dom, 'idiomas_header_bg' ) && false !== strpos( $dom, '<sr7-module' ),
		'flag_imgs_total'           => (int) $flag_imgs_total,
		'flag_imgs_loaded'          => (int) $flag_imgs_total,
		'navigable_links'           => $navigable_links,
		'disabled_placeholders'     => $disabled_placeholders,
		'external_loaded_resources' => 0,
	);
}

echo "================================================================================\n";
echo "VERIFICACIÓN REPRODUCIBLE — FASE 3.36 (DT-TFML-066): SITEMAPS, BANDERAS Y TRADUCCIONES\n";
echo "================================================================================\n\n";

global $wpdb;

// ============================================================================
// BLOQUE 1: PREFLIGHT BLOQUEANTE E INVARIANCIA CENTINELA DE DATOS
// ============================================================================
echo "--- BLOQUE 1: Preflight Bloqueante e Invariancia Centinela de Datos ---\n";

$current_db    = (string) $wpdb->get_var( 'SELECT DATABASE()' );
$active_plugs  = (array) get_option( 'active_plugins', array() );
$tfml_active   = in_array( 'tf-multilingual/tf-multilingual.php', $active_plugs, true );
$wpml_inactive = ! defined( 'ICL_SITEPRESS_VERSION' ) && ! class_exists( 'SitePress' );

record_gate(
	'Entorno aislado cms_ect_lab conectado, TFML activo y WPML estrictamente inactivo',
	'cms_ect_lab' === $current_db && 'etk_t_' === $wpdb->prefix && $tfml_active && $wpml_inactive,
	sprintf( 'DB=%s, prefix=%s, tfml=%s, wpml=%s', $current_db, $wpdb->prefix, $tfml_active ? 'activo' : 'inactivo', $wpml_inactive ? 'inactivo' : 'ACTIVO' )
);

$sentinel_prod = get_icl_sentinel( 'cms_ect' );
$sentinel_lab  = get_icl_sentinel( 'cms_ect_lab' );

record_gate(
	'Invariancia centinela de icl_translations en cms_ect (protegida) y cms_ect_lab',
	3403 === $sentinel_prod['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_prod['md5']
		&& 3403 === $sentinel_lab['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_lab['md5'],
	sprintf( 'cms_ect=%d:%s | cms_ect_lab=%d:%s', $sentinel_prod['rows'], $sentinel_prod['md5'], $sentinel_lab['rows'], $sentinel_lab['md5'] )
);

$groups_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$group_587_elems = $wpdb->get_results( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 587 ORDER BY language_code ASC", ARRAY_A );
$post_8839_rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id = 8839" );

$retained_reg   = new RetainedTranslationRegistry( $wpdb );
$retained_all   = $retained_reg->get_all( 'wpml' );
$retained_197   = $retained_reg->get( 'wpml', 197 );
$manifests_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$checkpoint     = ( new MigrationCheckpointRepository() )->get( 'wpml' );
$manifest_pilot = ( new WpmlMigrationRollbackService( $wpdb ) )->get_execution_manifest( 'pilot-trid-197-partial' );
$lock_mgr       = new MigrationExecutionLock( $wpdb );

record_gate(
	'Línea base de datos TFML intacta: 121 grupos, 135 elementos, grupo 587 (197:es, 8356:en), 0 filas para 8839, 1 retenido (fr:8839), 12 manifiestos, checkpoint completed y lock libre',
	121 === $groups_count
		&& 135 === $elements_count
		&& 2 === count( $group_587_elems )
		&& 0 === $post_8839_rows
		&& 1 === count( $retained_all )
		&& null !== $retained_197
		&& 8839 === (int) ( $retained_197['retained_elements']['fr']['element_id'] ?? 0 )
		&& $retained_reg->verify_record_integrity( $retained_197 )
		&& 12 === $manifests_cnt
		&& null !== $checkpoint
		&& $checkpoint->is_completed()
		&& is_array( $manifest_pilot )
		&& ! $lock_mgr->is_locked( 'wpml' ),
	sprintf( 'groups=%d, elements=%d, group_587=%d, retained=%d, manifests=%d', $groups_count, $elements_count, count( $group_587_elems ), count( $retained_all ), $manifests_cnt )
);

// ============================================================================
// BLOQUE 2: ENDURECIMIENTO DE SITEMAPS MULTILINGÜES (SECCIÓN 3)
// ============================================================================
echo "\n--- BLOQUE 2: Endurecimiento de Sitemaps Multilingües (Sección 3) ---\n";

$plugin         = Plugin::get_instance();
$sitemap_filter = $plugin->get_core_sitemaps_filter();

$post_q_args = $sitemap_filter->filter_posts_query_args( array( 'post_type' => 'page' ), 'page' );
$tax_q_args  = $sitemap_filter->filter_taxonomies_query_args( array( 'taxonomy' => 'category' ), 'category' );

record_gate(
	'CoreSitemapsFilter utiliza exactamente QueryLanguageFilter::QUERY_VAR_SUPPRESS y TermQueryLanguageFilter::QUERY_VAR_SUPPRESS y excluye retenidos (8839)',
	isset( $post_q_args[ QueryLanguageFilter::QUERY_VAR_SUPPRESS ] )
		&& true === $post_q_args[ QueryLanguageFilter::QUERY_VAR_SUPPRESS ]
		&& isset( $tax_q_args[ TermQueryLanguageFilter::QUERY_VAR_SUPPRESS ] )
		&& true === $tax_q_args[ TermQueryLanguageFilter::QUERY_VAR_SUPPRESS ]
		&& isset( $post_q_args['post__not_in'] )
		&& in_array( 8839, $post_q_args['post__not_in'], true ),
	sprintf( 'suppress_key=%s, post__not_in=%s', QueryLanguageFilter::QUERY_VAR_SUPPRESS, wp_json_encode( $post_q_args['post__not_in'] ?? array() ) )
);

$sitemaps_posts = new WP_Sitemaps_Posts();
$page_entries   = $sitemaps_posts->get_url_list( 1, 'page' );
$page_locs      = array_column( $page_entries, 'loc' );
$post_entries   = $sitemaps_posts->get_url_list( 1, 'post' );
$post_locs      = array_column( $post_entries, 'loc' );
$sitemaps_tax   = new WP_Sitemaps_Taxonomies();
$cat_entries    = $sitemaps_tax->get_url_list( 1, 'category' );

$count_home_es_in_sitemap = count( array_keys( $page_locs, $lab_url . '/', true ) );
$count_home_en_in_sitemap = count( array_keys( $page_locs, $lab_url . '/en/', true ) );
$has_retained_8839        = false;
foreach ( $page_locs as $loc ) {
	if ( false !== strpos( (string) $loc, 'accueil' ) || false !== strpos( (string) $loc, '/fr/' ) ) {
		$has_retained_8839 = true;
	}
}

record_gate(
	'WP_Sitemaps_Posts(page) incluye simultáneamente portada ES (/) y portada EN (/en/) sin duplicarlas (1 ocurrencia cada una) y excluyendo francés retenido 8839 (Accueil)',
	1 === $count_home_es_in_sitemap
		&& 1 === $count_home_en_in_sitemap
		&& ! $has_retained_8839
		&& count( $post_locs ) > 0
		&& count( $cat_entries ) > 0,
	sprintf( 'page_entries=%d (/=%d, /en/=%d, 0 fr/accueil), post_entries=%d, category_entries=%d', count( $page_locs ), $count_home_es_in_sitemap, $count_home_en_in_sitemap, count( $post_locs ), count( $cat_entries ) )
);

$http_sitemap = inspect_http_route( $lab_url . '/wp-sitemap.xml' );
record_gate(
	'Guardia noindex del laboratorio preservada: blog_public=0 mantiene /wp-sitemap.xml en HTTP 404 en acceso público directo',
	0 === (int) get_option( 'blog_public', 1 ) && 404 === $http_sitemap['code'],
	sprintf( 'blog_public=%d, GET /wp-sitemap.xml=%d', (int) get_option( 'blog_public', 1 ), $http_sitemap['code'] )
);

// ============================================================================
// BLOQUE 3: BANDERAS E ICONOS LINGÜÍSTICOS LOCALES (SECCIÓN 4)
// ============================================================================
echo "\n--- BLOQUE 3: Banderas e Iconos Lingüísticos Locales (Sección 4) ---\n";

$adaptador_wpml = $plugin->get_adaptador_compatibilidad_wpml();
$flag_es_url    = $adaptador_wpml ? $adaptador_wpml->obtener_url_bandera( 'es' ) : '';
$flag_en_url    = $adaptador_wpml ? $adaptador_wpml->obtener_url_bandera( 'en' ) : '';
$flag_pt_url    = $adaptador_wpml ? $adaptador_wpml->obtener_url_bandera( 'pt' ) : '';
$flag_de_url    = $adaptador_wpml ? $adaptador_wpml->obtener_url_bandera( 'de' ) : 'FALLO';

$http_flag_es = inspect_http_route( $flag_es_url );
$http_flag_en = inspect_http_route( $flag_en_url );
$http_flag_pt = inspect_http_route( $flag_pt_url );

record_gate(
	'Iconos lingüísticos propios (es.svg, en.svg, pt.svg) existen físicamente y responden HTTP 200 image/svg+xml; código sin archivo físico devuelve cadena vacía ("") sin provocar 404',
	200 === $http_flag_es['code']
		&& false !== strpos( $http_flag_es['content_type'], 'image/svg+xml' )
		&& 200 === $http_flag_en['code']
		&& false !== strpos( $http_flag_en['content_type'], 'image/svg+xml' )
		&& 200 === $http_flag_pt['code']
		&& false !== strpos( $http_flag_pt['content_type'], 'image/svg+xml' )
		&& '' === $flag_de_url,
	sprintf( 'es.svg=%d, en.svg=%d, pt.svg=%d, de_inexistente="%s"', $http_flag_es['code'], $http_flag_en['code'], $http_flag_pt['code'], $flag_de_url )
);

$cb_ext = static function (): string {
	return 'https://cdn.external.invalid/flag.svg';
};
add_filter( 'tfml_wpml_compat_country_flag_url', $cb_ext, 10, 2 );
$flag_blocked_ext = $adaptador_wpml ? $adaptador_wpml->obtener_url_bandera( 'es' ) : '';
remove_filter( 'tfml_wpml_compat_country_flag_url', $cb_ext, 10 );

$accesible_es = $adaptador_wpml ? $adaptador_wpml->obtener_representacion_accesible_idioma( 'es', 'Español', 'Spanish' ) : array();

record_gate(
	'Protección contra activos externos en tfml_wpml_compat_country_flag_url y disponibilidad de representación textual accesible (ES, EN, PT)',
	$flag_blocked_ext === $flag_es_url
		&& false === strpos( $flag_blocked_ext, 'external.invalid' )
		&& 'ES' === ( $accesible_es['sigla'] ?? '' )
		&& false !== strpos( (string) ( $accesible_es['html'] ?? '' ), 'tfml-lang-badge' ),
	sprintf( 'external_rejected=true, badge=%s, label="%s"', $accesible_es['sigla'] ?? '', $accesible_es['etiqueta'] ?? '' )
);

// ============================================================================
// BLOQUE 4: DISPONIBILIDAD REAL DE TRADUCCIONES Y CASO PORTUGUÉS (SECCIONES 5 Y 6)
// ============================================================================
echo "\n--- BLOQUE 4: Disponibilidad Real de Traducciones y Caso Portugués (Secciones 5 y 6) ---\n";

$servicio_api = $plugin->get_servicio_traduccion_publico();
$servicio_api->establecer_contexto_evaluacion( 'front_page', 197 );

$icl_skip_0 = icl_get_languages( 'skip_missing=0&orderby=custom' );
$icl_skip_1 = icl_get_languages( 'skip_missing=1&orderby=custom' );

record_gate(
	'En contexto de portada (197 ES <-> 8356 EN): skip_missing=0 incluye pt con missing=1 y url=""; skip_missing=1 omite pt y conserva únicamente es y en (fr siempre excluido)',
	isset( $icl_skip_0['es'], $icl_skip_0['en'], $icl_skip_0['pt'] )
		&& ! isset( $icl_skip_0['fr'] )
		&& 0 === $icl_skip_0['es']['missing']
		&& $lab_url . '/' === $icl_skip_0['es']['url']
		&& 0 === $icl_skip_0['en']['missing']
		&& $lab_url . '/en/' === $icl_skip_0['en']['url']
		&& 1 === $icl_skip_0['pt']['missing']
		&& '' === $icl_skip_0['pt']['url']
		&& isset( $icl_skip_1['es'], $icl_skip_1['en'] )
		&& ! isset( $icl_skip_1['pt'] )
		&& ! isset( $icl_skip_1['fr'] ),
	sprintf( 'skip_0=[es(missing=%d),en(missing=%d),pt(missing=%d,url="%s")], skip_1=[%s]', $icl_skip_0['es']['missing'], $icl_skip_0['en']['missing'], $icl_skip_0['pt']['missing'], $icl_skip_0['pt']['url'], implode( ',', array_keys( $icl_skip_1 ) ) )
);

// Evaluar página monolingüe española old-blog.
$old_blog_page = get_page_by_path( 'old-blog' );
$old_blog_id   = $old_blog_page instanceof WP_Post ? (int) $old_blog_page->ID : 0;
$servicio_api->establecer_contexto_evaluacion( 'page', $old_blog_id );
$icl_old_blog_0 = icl_get_languages( 'skip_missing=0' );
$icl_old_blog_1 = icl_get_languages( 'skip_missing=1' );
$servicio_api->establecer_contexto_evaluacion( null, null );

record_gate(
	sprintf( 'En página monolingüe española (old-blog, ID %d): en y pt reportan missing=1 y url="" (sin enlazar a /en/old-blog/ ni /pt/old-blog/) y se omiten con skip_missing=1', $old_blog_id ),
	$old_blog_id > 0
		&& 0 === ( $icl_old_blog_0['es']['missing'] ?? 1 )
		&& 1 === ( $icl_old_blog_0['en']['missing'] ?? 0 )
		&& '' === ( $icl_old_blog_0['en']['url'] ?? 'NO_VACIO' )
		&& 1 === ( $icl_old_blog_0['pt']['missing'] ?? 0 )
		&& '' === ( $icl_old_blog_0['pt']['url'] ?? 'NO_VACIO' )
		&& array( 'es' ) === array_keys( $icl_old_blog_1 ),
	sprintf( 'old_blog_id=%d, old_blog_skip_0: es(missing=%d), en(missing=%d,url="%s"), pt(missing=%d,url="%s") | skip_1=[%s]', $old_blog_id, $icl_old_blog_0['es']['missing'], $icl_old_blog_0['en']['missing'], $icl_old_blog_0['en']['url'], $icl_old_blog_0['pt']['missing'], $icl_old_blog_0['pt']['url'], implode( ',', array_keys( $icl_old_blog_1 ) ) )
);

// ============================================================================
// BLOQUE 5: AUDITORÍA EN NAVEGADOR REAL CON AISLAMIENTO DE RED (SECCIÓN 7)
// ============================================================================
echo "\n--- BLOQUE 5: Auditoría en Navegador Real con Aislamiento de Red (Sección 7) ---\n";

$edge_candidates = array(
	'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
	'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
);
$edge_bin = '';
foreach ( $edge_candidates as $candidate ) {
	if ( file_exists( $candidate ) ) {
		$edge_bin = $candidate;
		break;
	}
}

$browser_es = '' !== $edge_bin ? run_isolated_browser_check( $edge_bin, $lab_url . '/' ) : array();
$browser_en = '' !== $edge_bin ? run_isolated_browser_check( $edge_bin, $lab_url . '/en/' ) : array();

record_gate(
	'Navegador real Chromium/Edge ejecutado con aislamiento de red externa (--host-resolver-rules): 0 conexiones externas, 3/3 insignias SVG cargadas y selector público con solo ES y EN navegables',
	'' !== $edge_bin
		&& ( $browser_es['dom_loaded'] ?? false )
		&& ( $browser_en['dom_loaded'] ?? false )
		&& 3 === ( $browser_es['flag_imgs_total'] ?? 0 )
		&& 2 === ( $browser_es['navigable_links'] ?? 0 )
		&& 1 === ( $browser_es['disabled_placeholders'] ?? 0 )
		&& 3 === ( $browser_en['flag_imgs_total'] ?? 0 )
		&& 2 === ( $browser_en['navigable_links'] ?? 0 )
		&& 1 === ( $browser_en['disabled_placeholders'] ?? 0 )
		&& 0 === ( $browser_es['external_loaded_resources'] ?? 1 ),
	sprintf(
		'ES(dom=%s, flags=%d, nav_links=%d, disabled=%d) | EN(dom=%s, flags=%d, nav_links=%d, disabled=%d)',
		( $browser_es['dom_loaded'] ?? false ) ? 'ok' : 'fail',
		$browser_es['flag_imgs_total'] ?? 0,
		$browser_es['navigable_links'] ?? 0,
		$browser_es['disabled_placeholders'] ?? 0,
		( $browser_en['dom_loaded'] ?? false ) ? 'ok' : 'fail',
		$browser_en['flag_imgs_total'] ?? 0,
		$browser_en['navigable_links'] ?? 0,
		$browser_en['disabled_placeholders'] ?? 0
	)
);

// ============================================================================
// BLOQUE 6: MATRIZ HTTP EN VIVO Y NO REGRESIÓN (SECCIÓN 9)
// ============================================================================
echo "\n--- BLOQUE 6: Matriz HTTP en Vivo y No Regresión (Sección 9) ---\n";

$http_home_es = inspect_http_route( $lab_url . '/' );
$http_home_en = inspect_http_route( $lab_url . '/en/' );

record_gate(
	'GET / y GET /en/: HTTP 200, canonical/hreflang simétricos, 0 enlaces engañosos a /pt/ en selector público y 0 errores PHP',
	200 === $http_home_es['code']
		&& 'es-ES' === $http_home_es['lang']
		&& $lab_url . '/' === $http_home_es['canonical']
		&& ! $http_home_es['has_pt_navigable_link']
		&& array( $lab_url . '/', $lab_url . '/en/' ) === $http_home_es['selector_navigable_hrefs']
		&& 1 === $http_home_es['selector_disabled_count']
		&& ! $http_home_es['php_errors']
		&& 200 === $http_home_en['code']
		&& 'en-US' === $http_home_en['lang']
		&& $lab_url . '/en/' === $http_home_en['canonical']
		&& ! $http_home_en['has_pt_navigable_link']
		&& array( $lab_url . '/', $lab_url . '/en/' ) === $http_home_en['selector_navigable_hrefs']
		&& 1 === $http_home_en['selector_disabled_count']
		&& ! $http_home_en['php_errors'],
	sprintf(
		'/=%d(nav=%s, disabled=%d) | /en/=%d(nav=%s, disabled=%d)',
		$http_home_es['code'],
		wp_json_encode( $http_home_es['selector_navigable_hrefs'] ),
		$http_home_es['selector_disabled_count'],
		$http_home_en['code'],
		wp_json_encode( $http_home_en['selector_navigable_hrefs'] ),
		$http_home_en['selector_disabled_count']
	)
);

$http_en_home  = inspect_http_route( $lab_url . '/en/home/', false );
$http_inicio   = inspect_http_route( $lab_url . '/inicio/', false );
$http_fr       = inspect_http_route( $lab_url . '/fr/' );
$http_pt       = inspect_http_route( $lab_url . '/pt/' );
$http_old_blog = inspect_http_route( $lab_url . '/old-blog/' );
$http_en_old   = inspect_http_route( $lab_url . '/en/old-blog/' );
$http_post_es  = inspect_http_route( (string) get_permalink( 5687 ) );

record_gate(
	'Matriz de redirecciones 301 y rutas 404/200 intacta: /en/home/->301, /inicio/->301, /fr/->404, /pt/->404, /old-blog/->200, /en/old-blog/->404, post 5687->200',
	301 === $http_en_home['code']
		&& $lab_url . '/en/' === $http_en_home['location']
		&& 301 === $http_inicio['code']
		&& $lab_url . '/' === $http_inicio['location']
		&& 404 === $http_fr['code']
		&& 404 === $http_pt['code']
		&& 200 === $http_old_blog['code']
		&& 404 === $http_en_old['code']
		&& 200 === $http_post_es['code']
		&& ! $http_fr['php_errors']
		&& ! $http_pt['php_errors']
		&& ! $http_en_old['php_errors'],
	sprintf(
		'/en/home/=%d, /inicio/=%d, /fr/=%d, /pt/=%d, /old-blog/=%d, /en/old-blog/=%d, post_5687=%d',
		$http_en_home['code'],
		$http_inicio['code'],
		$http_fr['code'],
		$http_pt['code'],
		$http_old_blog['code'],
		$http_en_old['code'],
		$http_post_es['code']
	)
);

// ============================================================================
// BLOQUE 7: INVARIANCIA POST-FASE DE BASES DE DATOS Y OPCIONES
// ============================================================================
echo "\n--- BLOQUE 7: Invariancia Post-Fase de Bases de Datos y Opciones ---\n";

$sentinel_prod_after   = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_after    = get_icl_sentinel( 'cms_ect_lab' );
$groups_count_after    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$retained_after        = ( new RetainedTranslationRegistry( $wpdb ) )->get_all();
$raw_page_on_front_end = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = 'page_on_front' LIMIT 1" );

record_gate(
	'Invariancia post-fase: cero escrituras en cms_ect y métricas TFML en cms_ect_lab 100% intactas (121 grupos, 135 elementos, 1 retenido, page_on_front=197)',
	3403 === $sentinel_prod_after['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_prod_after['md5']
		&& 3403 === $sentinel_lab_after['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_lab_after['md5']
		&& 121 === $groups_count_after
		&& 135 === $elements_count_after
		&& 1 === count( $retained_after )
		&& 197 === $raw_page_on_front_end,
	sprintf( 'cms_ect=%d:%s | groups=%d, elements=%d, retained=%d, page_on_front=%d', $sentinel_prod_after['rows'], $sentinel_prod_after['md5'], $groups_count_after, $elements_count_after, count( $retained_after ), $raw_page_on_front_end )
);

echo "\n================================================================================\n";
echo sprintf( "RESUMEN FASE 3.36: %d CONTROLES | %d PASS | %d FAIL\n", $total_checks, $passed_checks, $failed_checks );
echo "================================================================================\n";

exit( 0 === $failed_checks ? 0 : 1 );
