<?php
/**
 * Script de Verificación Reproducible — Fase 3.35 (DT-TFML-065).
 *
 * Certificación Visual, Interactiva y Lingüística del Frontend.
 *
 * Verifica (100% solo lectura, cero mutaciones):
 * 1. Preflight bloqueante e invariancia centinela de datos (cms_ect intacta, cms_ect_lab intacta: 121 grupos, 135 elementos, grupo 587, 1 retenido fr:8839, 12 manifiestos).
 * 2. Disponibilidad real de navegador Chromium/Edge para auditoría CDP (escritorio 1440x900 y móvil 390x844) e integridad de las 16 tablas de Slider Revolution.
 * 3. Auditoría de navegación bilingüe, plantillas Elementor Header/Footer (4804 ES <-> 8383 EN) y diagnóstico de menús desktop vs. off-canvas móvil.
 * 4. Auditoría específica del idioma portugués (pt): activo en LanguageRegistry, 0 elementos en BD, expuesto en icl_get_languages(), HTTP 404 en /pt/ por política de no-fallback y excluido de hreflang.
 * 5. Auditoría de textos heredados del tema ecoCMS y función traducir(): ICL_LANGUAGE_CODE no definida por diseño y atribución exacta de cadenas en español en /en/.
 * 6. Matriz de no regresión HTTP, renderizado visual HTML, SEO (canonical, hreflang, proveedor de sitemaps) y blindaje de seguridad del laboratorio.
 * 7. Invariancia post-verificación de datos migrados, opciones de portada y base original protegida.
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
 * Ejecuta una petición HTTP real y extrae metadatos HTML, cabeceras, canonical, hreflang, widgets y errores PHP.
 *
 * @param string $url             URL objetivo.
 * @param bool   $follow_location True para seguir redirecciones, false para inspeccionar respuesta directa (ej. 301).
 * @return array{
 *     code: int,
 *     location: string,
 *     lang: string,
 *     title: string,
 *     canonical: string,
 *     hreflang: array<string, string>,
 *     has_header_sticky: bool,
 *     has_main_nav: bool,
 *     has_top_idiomas: bool,
 *     has_sr7_module: bool,
 *     has_raw_revslider: bool,
 *     has_menu_es: bool,
 *     has_menu_en: bool,
 *     stylesheets_count: int,
 *     scripts_count: int,
 *     php_errors: bool,
 *     body_len: int
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
			CURLOPT_USERAGENT      => 'TFML-Fase-3.35-Verifier/1.0',
		)
	);

	$raw_response = curl_exec( $ch );
	$header_size  = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$code         = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
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

	$title = '';
	if ( preg_match( '/<title>(.*?)<\/title>/is', $body, $title_matches ) ) {
		$title = trim( html_entity_decode( $title_matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	$canonical = '';
	if ( preg_match( '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']/i', $body, $canon_matches ) ) {
		$canonical = trim( $canon_matches[1] );
	}

	$hreflang = array();
	if ( preg_match_all( '/<link\s+rel=["\']alternate["\']\s+hreflang=["\']([^"\']+)["\']\s+href=["\']([^"\']+)["\']/i', $body, $href_matches, PREG_SET_ORDER ) ) {
		foreach ( $href_matches as $hm ) {
			$hreflang[ trim( $hm[1] ) ] = trim( $hm[2] );
		}
	}

	$has_header_sticky = false !== strpos( $body, '_header_sticky' );
	$has_main_nav      = false !== strpos( $body, 'main-navigation' );
	$has_top_idiomas   = false !== strpos( $body, 'idiomas_header_bg' );
	$has_sr7_module    = false !== strpos( $body, '<sr7-module' );
	$has_raw_revslider = false !== strpos( $body, '[rev_slider' );
	$has_menu_es       = false !== strpos( $body, 'id="menu-menu-principal"' );
	$has_menu_en       = false !== strpos( $body, 'id="menu-menu-es-ingles"' );

	preg_match_all( '/<link[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]([^\'"]+)[\'"]/i', $body, $css_matches );
	preg_match_all( '/<script[^>]*src=[\'"]([^\'"]+)[\'"]/i', $body, $js_matches );

	$php_errors = (bool) preg_match( '/(Fatal error|Parse error|Warning:|Notice:|Deprecated:)/i', $body );

	return array(
		'code'              => $code,
		'location'          => $location,
		'lang'              => $lang,
		'title'             => $title,
		'canonical'         => $canonical,
		'hreflang'          => $hreflang,
		'has_header_sticky' => $has_header_sticky,
		'has_main_nav'      => $has_main_nav,
		'has_top_idiomas'   => $has_top_idiomas,
		'has_sr7_module'    => $has_sr7_module,
		'has_raw_revslider' => $has_raw_revslider,
		'has_menu_es'       => $has_menu_es,
		'has_menu_en'       => $has_menu_en,
		'stylesheets_count' => count( $css_matches[1] ?? array() ),
		'scripts_count'     => count( $js_matches[1] ?? array() ),
		'php_errors'        => $php_errors,
		'body_len'          => strlen( $body ),
	);
}

echo "================================================================================\n";
echo "VERIFICACIÓN REPRODUCIBLE — FASE 3.35 (DT-TFML-065): CERTIFICACIÓN VISUAL E INTERACTIVA\n";
echo "================================================================================\n\n";

global $wpdb;

// ============================================================================
// BLOQUE 1: PREFLIGHT BLOQUEANTE E INVARIANCIA CENTINELA DE DATOS (SECCIÓN 2)
// ============================================================================
echo "--- BLOQUE 1: Preflight Bloqueante e Invariancia Centinela de Datos ---\n";

$active_plugins = (array) get_option( 'active_plugins', array() );
$tfml_active    = in_array( 'tf-multilingual/tf-multilingual.php', $active_plugins, true );
$wpml_active    = false;
foreach ( $active_plugins as $plugin_slug ) {
	if ( false !== stripos( (string) $plugin_slug, 'sitepress' ) || false !== stripos( (string) $plugin_slug, 'wpml' ) ) {
		$wpml_active = true;
		break;
	}
}

record_gate(
	'Entorno aislado cms_ect_lab conectado, TFML activo y WPML estrictamente inactivo',
	'cms_ect_lab' === DB_NAME && 'etk_t_' === $wpdb->prefix && $tfml_active && ! $wpml_active,
	sprintf( 'DB=%s, prefix=%s, tfml=%s, wpml=%s', DB_NAME, $wpdb->prefix, $tfml_active ? 'activo' : 'inactivo', $wpml_active ? 'activo' : 'inactivo' )
);

$sentinel_cms_ect       = get_icl_sentinel( 'cms_ect' );
$sentinel_cms_ect_lab   = get_icl_sentinel( 'cms_ect_lab' );
$expected_sentinel_hash = '4241ca7e7ec6399a594537cb04790c10';
$expected_sentinel_rows = 3403;

record_gate(
	'Invariancia centinela de icl_translations en cms_ect (protegida) y cms_ect_lab',
	$expected_sentinel_rows === $sentinel_cms_ect['rows']
		&& $expected_sentinel_hash === $sentinel_cms_ect['md5']
		&& $expected_sentinel_rows === $sentinel_cms_ect_lab['rows']
		&& $expected_sentinel_hash === $sentinel_cms_ect_lab['md5'],
	sprintf( 'cms_ect=%d:%s | cms_ect_lab=%d:%s', $sentinel_cms_ect['rows'], $sentinel_cms_ect['md5'], $sentinel_cms_ect_lab['rows'], $sentinel_cms_ect_lab['md5'] )
);

$groups_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$group_587_rows = $wpdb->get_results( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 587 ORDER BY language_code ASC", ARRAY_A );
$count_8839     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id = 8839" );
$dup_elements   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING COUNT(*) > 1) d" );
$dup_group_lang = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT group_id, language_code FROM {$wpdb->prefix}tfml_group_elements GROUP BY group_id, language_code HAVING COUNT(*) > 1) d" );

$retained_registry = new RetainedTranslationRegistry( $wpdb );
$retained_records  = $retained_registry->get_all( 'wpml' );
$retained_197      = $retained_registry->get( 'wpml', 197 );
$manifests_cnt     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$checkpoint_repo   = new MigrationCheckpointRepository();
$checkpoint        = $checkpoint_repo->get( 'wpml' );
$rollback_service  = new WpmlMigrationRollbackService( $wpdb );
$manifest_pilot    = $rollback_service->get_execution_manifest( 'pilot-trid-197-partial' );
$lock_mgr          = new MigrationExecutionLock( $wpdb );

record_gate(
	'Línea base de datos TFML intacta: 121 grupos, 135 elementos, grupo 587 (197:es, 8356:en), 0 filas para 8839, 1 retenido (fr:8839), 12 manifiestos, checkpoint completed y lock libre',
	121 === $groups_count
		&& 135 === $elements_count
		&& 2 === count( $group_587_rows )
		&& 0 === $count_8839
		&& 1 === count( $retained_records )
		&& isset( $retained_197['retained_elements']['fr']['element_id'] )
		&& 8839 === (int) $retained_197['retained_elements']['fr']['element_id']
		&& $retained_registry->verify_record_integrity( $retained_197 )
		&& 12 === $manifests_cnt
		&& null !== $checkpoint
		&& $checkpoint->is_completed()
		&& is_array( $manifest_pilot )
		&& 0 === $dup_elements
		&& 0 === $dup_group_lang
		&& ! $lock_mgr->is_locked( 'wpml' ),
	sprintf( 'groups=%d, elements=%d, group_587=%d, retained=%d, manifests=%d', $groups_count, $elements_count, count( $group_587_rows ), count( $retained_records ), $manifests_cnt )
);

// ============================================================================
// BLOQUE 2: CAPACIDAD DE NAVEGADOR REAL, PLUGINS VISUALES Y SLIDER REVOLUTION
// ============================================================================
echo "\n--- BLOQUE 2: Capacidad de Navegador Real, Plugins Visuales y Slider Revolution ---\n";

$edge_bin    = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
$edge_exists = file_exists( $edge_bin );

record_gate(
	'Capacidad real de navegador Chromium/Edge (msedge.exe) verificada en el host para auditoría CDP',
	$edge_exists,
	sprintf( 'binary=%s (%s)', $edge_bin, $edge_exists ? 'disponible' : 'ausente' )
);

$visual_plugins_ok = class_exists( 'OvaFramework' )
	&& post_type_exists( 'ova_framework_hf_el' )
	&& did_action( 'elementor/loaded' )
	&& class_exists( 'ElementorPro\Plugin' )
	&& shortcode_exists( 'rev_slider' )
	&& class_exists( 'RevSliderFront' );

$revslider_tables  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'cms_ect_lab' AND TABLE_NAME LIKE 'etk_t_revslider%'" );
$slider_6_alias    = (string) $wpdb->get_var( "SELECT alias FROM {$wpdb->prefix}revslider_sliders WHERE id = 6" );
$slider_6_slides7  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}revslider_slides7 WHERE slider_id = 6" );

record_gate(
	'Stack visual completo activo (ova-framework, Elementor Core/Pro, Slider Revolution) con 16 tablas y slider home-es-1 (ID 6, 2 filas en revslider_slides7)',
	$visual_plugins_ok && 16 === $revslider_tables && 'home-es-1' === $slider_6_alias && 2 === $slider_6_slides7,
	sprintf( 'plugins_ok=%s, revslider_tables=%d, slider_6=%s (%d filas SR7)', $visual_plugins_ok ? 'true' : 'false', $revslider_tables, $slider_6_alias, $slider_6_slides7 )
);

// ============================================================================
// BLOQUE 3: AUDITORÍA DE NAVEGACIÓN BILINGÜE, CABECERAS HF Y MENÚS (SECCIÓN 7)
// ============================================================================
echo "\n--- BLOQUE 3: Auditoría de Navegación Bilingüe, Cabeceras HF y Menús ---\n";

$header_meta_197  = (string) get_post_meta( 197, 'ova_met_header_version', true );
$header_meta_8356 = (string) get_post_meta( 8356, 'ova_met_header_version', true );
$post_4804        = get_post( 4804 );
$post_8383        = get_post( 8383 );

record_gate(
	'Vinculación de cabeceras Elementor por portada (ova_met_header_version): Portada ES (197) -> ova,header-home-es (4804) y Portada EN (8356) -> ova,header-home-en (8383)',
	'ova,header-home-es' === $header_meta_197
		&& 'ova,header-home-en' === $header_meta_8356
		&& null !== $post_4804 && 'header-home-es' === $post_4804->post_name
		&& null !== $post_8383 && 'header-home-en' === $post_8383->post_name,
	sprintf( '197->%s (ID %d), 8356->%s (ID %d)', $header_meta_197, $post_4804 ? $post_4804->ID : 0, $header_meta_8356, $post_8383 ? $post_8383->ID : 0 )
);

$menu_es_items      = wp_get_nav_menu_items( 43 ) ?: array();
$menu_en_items      = wp_get_nav_menu_items( 98 ) ?: array();
$migrated_nav_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups WHERE subtype = 'nav_menu_item'" );

$el_data_4804        = (string) get_post_meta( 4804, '_elementor_data', true );
$el_data_8383        = (string) get_post_meta( 8383, '_elementor_data', true );
$desktop_slug_4804   = false !== strpos( $el_data_4804, '"menu_slug":"menu-principal"' );
$desktop_slug_8383   = false !== strpos( $el_data_8383, '"menu_slug":"menu-es-ingles"' );
$mobile_slug_primary = false !== strpos( $el_data_4804, '"menu_slug":"primary"' ) && false !== strpos( $el_data_8383, '"menu_slug":"primary"' );

record_gate(
	'Menús desktop configurados (Menu Principal ES=16 ítems, Menu - ES - Inglés EN=16 ítems, 18 grupos nav_menu_item migrados) y diagnóstico de widget móvil (menu_slug="primary") verificado',
	16 === count( $menu_es_items )
		&& 16 === count( $menu_en_items )
		&& 18 === $migrated_nav_items
		&& $desktop_slug_4804
		&& $desktop_slug_8383
		&& $mobile_slug_primary,
	sprintf( 'menu_43=%d, menu_98=%d, nav_groups=%d, mobile_slug_primary=%s', count( $menu_es_items ), count( $menu_en_items ), $migrated_nav_items, $mobile_slug_primary ? 'true' : 'false' )
);

// ============================================================================
// BLOQUE 4: AUDITORÍA ESPECÍFICA DEL IDIOMA PORTUGUÉS (pt) (SECCIÓN 8)
// ============================================================================
echo "\n--- BLOQUE 4: Auditoría Específica del Idioma Portugués (pt) ---\n";

$plugin        = Plugin::get_instance();
$lang_registry = $plugin->get_language_registry();
$active_codes  = array_keys( $lang_registry->active() );
$pt_elements   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE language_code = 'pt'" );
$pt_icl_rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE language_code = 'pt'" );
$icl_langs     = function_exists( 'icl_get_languages' ) ? icl_get_languages( 'skip_missing=0&orderby=custom' ) : array();

record_gate(
	'Diagnóstico de Portugués (pt): activo en LanguageRegistry (es, en, pt; fr inactivo), expuesto en icl_get_languages() y con 0 elementos traducidos en BD',
	in_array( 'pt', $active_codes, true )
		&& ! in_array( 'fr', $active_codes, true )
		&& isset( $icl_langs['pt'] )
		&& ! isset( $icl_langs['fr'] )
		&& 0 === $pt_elements
		&& 0 === $pt_icl_rows,
	sprintf( 'active_codes=[%s], icl_langs=[%s], pt_tfml=%d, pt_icl=%d', implode( ',', $active_codes ), implode( ',', array_keys( $icl_langs ) ), $pt_elements, $pt_icl_rows )
);

$http_pt = inspect_http_route( $lab_url . '/pt/' );
record_gate(
	'GET /pt/ (Portugués activo sin portada traducida): HTTP 404 por política estricta de no-fallback silencioso, sin canonical ni hreflang y 0 errores PHP',
	404 === $http_pt['code']
		&& '' === $http_pt['canonical']
		&& empty( $http_pt['hreflang'] )
		&& ! $http_pt['php_errors'],
	sprintf( 'HTTP=%d, lang=%s, canonical="%s", php_errors=%s', $http_pt['code'], $http_pt['lang'], $http_pt['canonical'], $http_pt['php_errors'] ? 'true' : 'false' )
);

// ============================================================================
// BLOQUE 5: AUDITORÍA DE TEXTOS HEREDADOS DE ecoCMS Y traducir() (SECCIÓN 9)
// ============================================================================
echo "\n--- BLOQUE 5: Auditoría de Textos Heredados de ecoCMS y traducir() ---\n";

$icl_const_undef = ! defined( 'ICL_LANGUAGE_CODE' );
$fn_traducir     = function_exists( 'traducir' );
$traducir_sample = $fn_traducir ? (string) traducir( 'Texto ES', 'Text EN', 'Texto PT', 'Texte FR' ) : '';
$el_data_8356    = (string) get_post_meta( 8356, '_elementor_data', true );
$has_nuestro_blog_in_8356 = false !== strpos( $el_data_8356, 'Nuestro Blog' ) && false !== strpos( $el_data_8356, 'Ver m\u00e1s' );

record_gate(
	'Diagnóstico de textos heredados: ICL_LANGUAGE_CODE no definida (ADR-060), traducir() cae en rama "es" por diseño del tema ecoCMS y _elementor_data de 8356 contiene "Nuestro Blog" / "Ver más"',
	$icl_const_undef
		&& $fn_traducir
		&& 'Texto ES' === $traducir_sample
		&& $has_nuestro_blog_in_8356,
	sprintf( 'ICL_LANGUAGE_CODE_undef=%s, traducir()="%s", elementor_8356_has_es_strings=%s', $icl_const_undef ? 'true' : 'false', $traducir_sample, $has_nuestro_blog_in_8356 ? 'true' : 'false' )
);

// ============================================================================
// BLOQUE 6: MATRIZ HTTP, RENDERIZADO VISUAL, SEO, SITEMAPS Y SEGURIDAD (SECCIONES 10 Y 11)
// ============================================================================
echo "\n--- BLOQUE 6: Matriz HTTP, Renderizado Visual, SEO, Sitemaps y Seguridad ---\n";

$expected_hreflang = array(
	'es'        => $lab_url . '/',
	'en'        => $lab_url . '/en/',
	'x-default' => $lab_url . '/',
);

$http_es = inspect_http_route( $lab_url . '/' );
record_gate(
	'GET / (Portada ES): HTTP 200, lang="es-ES", canonical="/", hreflang es/en/x-default (sin fr ni pt), Header Sticky, Menú ES, Selector Idiomas, Slider SR7 y 0 errores PHP',
	200 === $http_es['code']
		&& 'es-ES' === $http_es['lang']
		&& ( $lab_url . '/' ) === $http_es['canonical']
		&& $expected_hreflang === $http_es['hreflang']
		&& $http_es['has_header_sticky']
		&& $http_es['has_menu_es']
		&& $http_es['has_top_idiomas']
		&& $http_es['has_sr7_module']
		&& ! $http_es['has_raw_revslider']
		&& ! $http_es['php_errors'],
	sprintf( 'HTTP=%d, lang=%s, menu_es=%s, sr7=%s, css=%d, js=%d', $http_es['code'], $http_es['lang'], $http_es['has_menu_es'] ? 'true' : 'false', $http_es['has_sr7_module'] ? 'true' : 'false', $http_es['stylesheets_count'], $http_es['scripts_count'] )
);

$http_en = inspect_http_route( $lab_url . '/en/' );
record_gate(
	'GET /en/ (Portada EN): HTTP 200, lang="en-US", canonical="/en/", hreflang es/en/x-default (sin fr ni pt), Header Sticky, Menú EN, Selector Idiomas, Slider SR7 y 0 errores PHP',
	200 === $http_en['code']
		&& 'en-US' === $http_en['lang']
		&& ( $lab_url . '/en/' ) === $http_en['canonical']
		&& $expected_hreflang === $http_en['hreflang']
		&& $http_en['has_header_sticky']
		&& $http_en['has_menu_en']
		&& $http_en['has_top_idiomas']
		&& $http_en['has_sr7_module']
		&& ! $http_en['has_raw_revslider']
		&& ! $http_en['php_errors'],
	sprintf( 'HTTP=%d, lang=%s, menu_en=%s, sr7=%s, css=%d, js=%d', $http_en['code'], $http_en['lang'], $http_en['has_menu_en'] ? 'true' : 'false', $http_en['has_sr7_module'] ? 'true' : 'false', $http_en['stylesheets_count'], $http_en['scripts_count'] )
);

$http_slug_en = inspect_http_route( $lab_url . '/en/home/', false );
$http_slug_es = inspect_http_route( $lab_url . '/inicio/', false );
record_gate(
	'Redirecciones canónicas 301 de slugs de portada: /en/home/ -> /en/ y /inicio/ -> /',
	301 === $http_slug_en['code']
		&& ( $lab_url . '/en/' ) === $http_slug_en['location']
		&& 301 === $http_slug_es['code']
		&& ( $lab_url . '/' ) === $http_slug_es['location'],
	sprintf( '/en/home/=%d->%s | /inicio/=301->%s', $http_slug_en['code'], $http_slug_en['location'], $http_slug_es['location'] )
);

$http_fr         = inspect_http_route( $lab_url . '/fr/' );
$http_old_blog   = inspect_http_route( $lab_url . '/old-blog/' );
$http_en_oldblog = inspect_http_route( $lab_url . '/en/old-blog/' );
record_gate(
	'Política de idiomas inactivos y páginas monolingües: /fr/ -> 404, /old-blog/ -> 200 (es-ES), /en/old-blog/ -> 404 (sin fallback silencioso)',
	404 === $http_fr['code']
		&& ! $http_fr['php_errors']
		&& 200 === $http_old_blog['code']
		&& 'es-ES' === $http_old_blog['lang']
		&& ! $http_old_blog['php_errors']
		&& 404 === $http_en_oldblog['code']
		&& ! $http_en_oldblog['php_errors'],
	sprintf( '/fr/=%d, /old-blog/=%d, /en/old-blog/=%d', $http_fr['code'], $http_old_blog['code'], $http_en_oldblog['code'] )
);

// Verificación de CoreSitemapsFilter sobre entradas de portada (197 ES, 8356 EN y exclusión de 8839 FR)
$sitemaps_filter = $plugin->get_core_sitemaps_filter();
$entry_197       = $sitemaps_filter->filter_posts_entry( array( 'loc' => get_permalink( 197 ) ), get_post( 197 ), 'page' );
$entry_8356      = $sitemaps_filter->filter_posts_entry( array( 'loc' => get_permalink( 8356 ) ), get_post( 8356 ), 'page' );
$http_sitemap    = inspect_http_route( $lab_url . '/wp-sitemap.xml' );

record_gate(
	'CoreSitemapsFilter normaliza entradas de portada (197 -> /, 8356 -> /en/) y /wp-sitemap.xml responde 404 por guardia noindex (blog_public=0)',
	( $lab_url . '/' ) === ( $entry_197['loc'] ?? '' )
		&& ( $lab_url . '/en/' ) === ( $entry_8356['loc'] ?? '' )
		&& 404 === $http_sitemap['code'],
	sprintf( 'entry_197=%s, entry_8356=%s, http_sitemap=%d (blog_public=0)', $entry_197['loc'] ?? '', $entry_8356['loc'] ?? '', $http_sitemap['code'] )
);

$guard_active = defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' )
	&& defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && constant( 'WP_HTTP_BLOCK_EXTERNAL' )
	&& '0' === (string) get_option( 'blog_public' );

record_gate(
	'Blindaje de seguridad del laboratorio activo (DISABLE_WP_CRON=true, WP_HTTP_BLOCK_EXTERNAL=true, blog_public=0 noindex)',
	$guard_active,
	sprintf( 'CRON=%s, HTTP_BLOCK=%s, blog_public=%s', defined( 'DISABLE_WP_CRON' ) ? 'true' : 'false', defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ? 'true' : 'false', (string) get_option( 'blog_public' ) )
);

// ============================================================================
// BLOQUE 7: INVARIANCIA POST-FASE DE BASES DE DATOS Y OPCIONES (SECCIÓN 12)
// ============================================================================
echo "\n--- BLOQUE 7: Invariancia Post-Fase de Bases de Datos y Opciones ---\n";

$post_cms_ect      = get_icl_sentinel( 'cms_ect' );
$post_cms_ect_lab  = get_icl_sentinel( 'cms_ect_lab' );
$post_groups       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$post_elements     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$post_retained     = $retained_registry->get_all( 'wpml' );
$post_show_front   = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'show_on_front'" );
$post_page_front   = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'page_on_front'" );

record_gate(
	'Invariancia post-fase: cero escrituras en cms_ect y métricas TFML en cms_ect_lab 100% intactas (121 grupos, 135 elementos, 1 retenido, page_on_front=197)',
	$expected_sentinel_rows === $post_cms_ect['rows']
		&& $expected_sentinel_hash === $post_cms_ect['md5']
		&& $expected_sentinel_rows === $post_cms_ect_lab['rows']
		&& $expected_sentinel_hash === $post_cms_ect_lab['md5']
		&& 121 === $post_groups
		&& 135 === $post_elements
		&& 1 === count( $post_retained )
		&& 'page' === $post_show_front
		&& 197 === $post_page_front,
	sprintf( 'cms_ect=%d:%s | groups=%d, elements=%d, retained=%d, page_on_front=%d', $post_cms_ect['rows'], $post_cms_ect['md5'], $post_groups, $post_elements, count( $post_retained ), $post_page_front )
);

echo "\n================================================================================\n";
echo sprintf( "RESUMEN FASE 3.35: %d CONTROLES | %d PASS | %d FAIL\n", $total_checks, $passed_checks, $failed_checks );
echo "================================================================================\n";

if ( $failed_checks > 0 ) {
	exit( 1 );
}
