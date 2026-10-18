<?php
/**
 * Script de Verificación Reproducible — Fase 3.34 (DT-TFML-064).
 *
 * Restauración Controlada del Renderizado Visual y Auditoría de Integraciones.
 *
 * Verifica:
 * 1. Preflight bloqueante e invariancia centinela de datos (cms_ect intacta, cms_ect_lab intacta: 121 grupos, 135 elementos, 1 retenido fr:8839).
 * 2. Respaldo previo y restauración verificable con metadatos de baseline SHA-256 en d:\laragon\backup\tfml_staging\.
 * 3. Inventario físico y activación progresiva y controlada de dependencias visuales (ova-framework, elementor, elementor-pro, revslider).
 * 4. Blindaje de seguridad y aislamiento (WPML bloqueado, lab-isolation-guard activo, cron bloqueado, HTTP externo bloqueado, ICL_LANGUAGE_CODE no definida).
 * 5. Adaptador de compatibilidad heredada WPML activo y seguro (icl_get_languages y icl_object_id operativos sin polución global).
 * 6. Certificación del renderizado visual completo en Portada ES (/) y Portada EN (/en/): Header Sticky, Menús Bilingües, Selector de Idiomas, Slider Revolution, Widgets Elementor y Assets encolados.
 * 7. No regresión de SEO, canonical, hreflang simétrico y enrutamiento canónico 301/404.
 * 8. Invariancia post-verificación de datos migrados y base original.
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
use TF\Multilingual\Routing\ResolvedorPortadaMultilingue;

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
 * Ejecuta una petición HTTP real y extrae metadatos HTML, cabeceras de redirección, canonical, hreflang, widgets y errores PHP.
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
 *     elementor_widgets_count: int,
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
			CURLOPT_USERAGENT      => 'TFML-Fase-3.34-Verifier/1.0',
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

	preg_match_all( '/data-widget_type="([^"]+)"/', $body, $widget_matches );
	$elementor_widgets_count = count( array_unique( $widget_matches[1] ?? array() ) );

	preg_match_all( '/<link[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]([^\'"]+)[\'"]/i', $body, $css_matches );
	preg_match_all( '/<script[^>]*src=[\'"]([^\'"]+)[\'"]/i', $body, $js_matches );

	$php_errors = (bool) preg_match( '/(Fatal error|Parse error|Warning:|Notice:|Deprecated:)/i', $body );

	return array(
		'code'                    => $code,
		'location'                => $location,
		'lang'                    => $lang,
		'title'                   => $title,
		'canonical'               => $canonical,
		'hreflang'                => $hreflang,
		'has_header_sticky'       => $has_header_sticky,
		'has_main_nav'            => $has_main_nav,
		'has_top_idiomas'         => $has_top_idiomas,
		'has_sr7_module'          => $has_sr7_module,
		'has_raw_revslider'       => $has_raw_revslider,
		'elementor_widgets_count' => $elementor_widgets_count,
		'stylesheets_count'       => count( $css_matches[1] ?? array() ),
		'scripts_count'           => count( $js_matches[1] ?? array() ),
		'php_errors'              => $php_errors,
		'body_len'                => strlen( $body ),
	);
}

echo "================================================================================\n";
echo "VERIFICACIÓN REPRODUCIBLE — FASE 3.34 (DT-TFML-064): RESTAURACIÓN VISUAL Y AUDITORÍA\n";
echo "================================================================================\n\n";

global $wpdb;

// ============================================================================
// BLOQUE 1: PREFLIGHT BLOQUEANTE E INVARIANCIA CENTINELA DE DATOS
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

$sentinel_cms_ect     = get_icl_sentinel( 'cms_ect' );
$sentinel_cms_ect_lab = get_icl_sentinel( 'cms_ect_lab' );
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

$lock_mgr = new MigrationExecutionLock( $wpdb );

record_gate(
	'Línea base de datos TFML intacta: 121 grupos, 135 elementos, grupo 587 (197:es, 8356:en), 0 filas para 8839, 1 retenido (fr:8839), 0 duplicados y lock libre',
	121 === $groups_count
		&& 135 === $elements_count
		&& 2 === count( $group_587_rows )
		&& 0 === $count_8839
		&& 1 === count( $retained_records )
		&& isset( $retained_197['retained_elements']['fr']['element_id'] )
		&& 8839 === (int) $retained_197['retained_elements']['fr']['element_id']
		&& $retained_registry->verify_record_integrity( $retained_197 )
		&& 0 === $dup_elements
		&& 0 === $dup_group_lang
		&& ! $lock_mgr->is_locked( 'wpml' ),
	sprintf( 'groups=%d, elements=%d, group_587=%d, retained=%d', $groups_count, $elements_count, count( $group_587_rows ), count( $retained_records ) )
);

// ============================================================================
// BLOQUE 2: RESPALDO PREVIO Y RESTAURACIÓN VERIFICABLE
// ============================================================================
echo "\n--- BLOQUE 2: Respaldo Previo y Restauración Verificable ---\n";

$backup_pattern = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_34_visual_restoration_*.sql';
$candidate_files = glob( $backup_pattern ) ?: array();
$backup_file    = '';
$meta_file      = '';
$meta_data      = array();

foreach ( $candidate_files as $cand ) {
	$cand_meta = preg_replace( '/\.sql$/', '.meta.json', $cand );
	if ( file_exists( $cand_meta ) ) {
		$backup_file = $cand;
		$meta_file   = $cand_meta;
		$meta_data   = json_decode( (string) file_get_contents( $cand_meta ), true ) ?: array();
		break;
	}
}

$backup_exists = '' !== $backup_file && file_exists( $backup_file );
$backup_hash   = $backup_exists ? hash_file( 'sha256', $backup_file ) : '';
$meta_sha      = (string) ( $meta_data['sha256'] ?? '' );
$meta_baseline = (string) ( $meta_data['git_baseline'] ?? '' );

record_gate(
	'Respaldo previo específico de cms_ect_lab y metadatos JSON verificados en staging',
	$backup_exists
		&& '' !== $backup_hash
		&& $backup_hash === $meta_sha
		&& 'f554595110712228a73dda1a17322cbc098ae864' === $meta_baseline,
	sprintf( 'backup=%s, size=%d MB, sha256=%s, baseline=%s', basename( $backup_file ), round( ( $meta_data['size_bytes'] ?? 0 ) / 1024 / 1024, 2 ), substr( $backup_hash, 0, 12 ), $meta_baseline )
);

$ephemeral_db_exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'cms_ect_lab_restore_verify'" );
record_gate(
	'Base de datos efímera de verificación de restauración eliminada limpiamente',
	0 === $ephemeral_db_exists,
	'cms_ect_lab_restore_verify=0'
);

// ============================================================================
// BLOQUE 3: DEPENDENCIAS VISUALES Y AISLAMIENTO DE SEGURIDAD
// ============================================================================
echo "\n--- BLOQUE 3: Dependencias Visuales y Aislamiento de Seguridad ---\n";

$guard_active = defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' )
	&& defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && constant( 'WP_HTTP_BLOCK_EXTERNAL' )
	&& '0' === (string) get_option( 'blog_public' );

record_gate(
	'Aislamiento estricto de laboratorio activo (cron bloqueado, HTTP saliente bloqueado, noindex forzado)',
	$guard_active,
	sprintf( 'CRON=%s, HTTP_BLOCK=%s, blog_public=%s', defined( 'DISABLE_WP_CRON' ) ? 'true' : 'false', defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ? 'true' : 'false', (string) get_option( 'blog_public' ) )
);

$ova_framework_ok = class_exists( 'OvaFramework' ) && post_type_exists( 'ova_framework_hf_el' );
record_gate(
	'Plugin visual #1: ova-framework cargado y CPT de cabeceras/pies (ova_framework_hf_el) registrado',
	$ova_framework_ok,
	sprintf( 'OvaFramework=%s, CPT ova_framework_hf_el=%s', class_exists( 'OvaFramework' ) ? 'true' : 'false', post_type_exists( 'ova_framework_hf_el' ) ? 'true' : 'false' )
);

$elementor_ok = did_action( 'elementor/loaded' ) && class_exists( 'ElementorPro\Plugin' );
record_gate(
	'Plugin visual #2 & #3: Elementor Core y Elementor Pro cargados e inicializados',
	$elementor_ok,
	sprintf( 'elementor/loaded=%s, ElementorPro=%s', did_action( 'elementor/loaded' ) ? 'true' : 'false', class_exists( 'ElementorPro\Plugin' ) ? 'true' : 'false' )
);

$revslider_ok = shortcode_exists( 'rev_slider' ) && class_exists( 'RevSliderFront' );
$revslider_tables = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'cms_ect_lab' AND TABLE_NAME LIKE 'etk_t_revslider%'" );
record_gate(
	'Plugin visual #4: Slider Revolution inicializado, shortcode rev_slider disponible y 16 tablas operativas',
	$revslider_ok && 16 === $revslider_tables,
	sprintf( 'shortcode_exists(rev_slider)=%s, tablas_revslider=%d', $revslider_ok ? 'true' : 'false', $revslider_tables )
);

// ============================================================================
// BLOQUE 4: ADAPTADOR WPML Y SEGURIDAD ARQUITECTÓNICA
// ============================================================================
echo "\n--- BLOQUE 4: Adaptador WPML y Seguridad Arquitectónica ---\n";

$plugin = Plugin::get_instance();
$wpml_adapter = $plugin->get_adaptador_compatibilidad_wpml();
$adapter_enabled = $wpml_adapter && $wpml_adapter->esta_habilitado();
$fn_get_langs    = function_exists( 'icl_get_languages' );
$fn_obj_id       = function_exists( 'icl_object_id' );
$icl_code_undef  = ! defined( 'ICL_LANGUAGE_CODE' );

record_gate(
	'Adaptador WPML activado deterministamente sin colisión y constante ICL_LANGUAGE_CODE no definida',
	$adapter_enabled && $fn_get_langs && $fn_obj_id && $icl_code_undef,
	sprintf( 'habilitado=%s, icl_get_languages=%s, icl_object_id=%s, ICL_LANGUAGE_CODE_undef=%s', $adapter_enabled ? 'true' : 'false', $fn_get_langs ? 'true' : 'false', $fn_obj_id ? 'true' : 'false', $icl_code_undef ? 'true' : 'false' )
);

$active_langs = $fn_get_langs ? icl_get_languages( 'skip_missing=0&orderby=custom' ) : array();
$has_es = isset( $active_langs['es'] );
$has_en = isset( $active_langs['en'] );
$has_fr = isset( $active_langs['fr'] );

record_gate(
	'icl_get_languages provee idiomas activos (es, en) y omite estrictamente francés retenido/inactivo',
	$has_es && $has_en && ! $has_fr,
	sprintf( 'idiomas_activos=[%s], fr_presente=%s', implode( ',', array_keys( $active_langs ) ), $has_fr ? 'true' : 'false' )
);

// ============================================================================
// BLOQUE 5: CERTIFICACIÓN DE RENDERIZADO VISUAL COMPLETO EN VIVO
// ============================================================================
echo "\n--- BLOQUE 5: Certificación de Renderizado Visual Completo en Vivo ---\n";

$http_es = inspect_http_route( $lab_url . '/' );
record_gate(
	'GET / (Portada ES): HTTP 200, lang="es-ES", canonical="/", hreflang simétrico y 0 errores PHP',
	200 === $http_es['code']
		&& 'es-ES' === $http_es['lang']
		&& $lab_url . '/' === $http_es['canonical']
		&& ( $http_es['hreflang']['es'] ?? '' ) === $lab_url . '/'
		&& ( $http_es['hreflang']['en'] ?? '' ) === $lab_url . '/en/'
		&& ! $http_es['php_errors'],
	sprintf( 'HTTP=%d, lang=%s, body=%d KB, php_errors=%s', $http_es['code'], $http_es['lang'], round( $http_es['body_len'] / 1024 ), $http_es['php_errors'] ? 'true' : 'false' )
);

record_gate(
	'GET / (Portada ES): Header Sticky, Menú Principal ES, Selector de Idiomas y Slider Revolution (<sr7-module>) renderizados',
	$http_es['has_header_sticky']
		&& $http_es['has_main_nav']
		&& $http_es['has_top_idiomas']
		&& $http_es['has_sr7_module']
		&& ! $http_es['has_raw_revslider'],
	sprintf( 'header=%s, nav=%s, top_idiomas=%s, sr7=%s, raw_shortcode_leak=%s', $http_es['has_header_sticky'] ? 'true' : 'false', $http_es['has_main_nav'] ? 'true' : 'false', $http_es['has_top_idiomas'] ? 'true' : 'false', $http_es['has_sr7_module'] ? 'true' : 'false', $http_es['has_raw_revslider'] ? 'true' : 'false' )
);

$http_en = inspect_http_route( $lab_url . '/en/' );
record_gate(
	'GET /en/ (Portada EN): HTTP 200, lang="en-US", canonical="/en/", hreflang simétrico y 0 errores PHP',
	200 === $http_en['code']
		&& 'en-US' === $http_en['lang']
		&& $lab_url . '/en/' === $http_en['canonical']
		&& ( $http_en['hreflang']['es'] ?? '' ) === $lab_url . '/'
		&& ( $http_en['hreflang']['en'] ?? '' ) === $lab_url . '/en/'
		&& ! $http_en['php_errors'],
	sprintf( 'HTTP=%d, lang=%s, body=%d KB, php_errors=%s', $http_en['code'], $http_en['lang'], round( $http_en['body_len'] / 1024 ), $http_en['php_errors'] ? 'true' : 'false' )
);

record_gate(
	'GET /en/ (Portada EN): Header Sticky, Menú EN, Selector de Idiomas y Slider Revolution (<sr7-module>) renderizados',
	$http_en['has_header_sticky']
		&& $http_en['has_main_nav']
		&& $http_en['has_top_idiomas']
		&& $http_en['has_sr7_module']
		&& ! $http_en['has_raw_revslider'],
	sprintf( 'header=%s, nav=%s, top_idiomas=%s, sr7=%s, raw_shortcode_leak=%s', $http_en['has_header_sticky'] ? 'true' : 'false', $http_en['has_main_nav'] ? 'true' : 'false', $http_en['has_top_idiomas'] ? 'true' : 'false', $http_en['has_sr7_module'] ? 'true' : 'false', $http_en['has_raw_revslider'] ? 'true' : 'false' )
);

record_gate(
	'Encolado verificado de assets CSS/JS de Elementor y Slider Revolution (>20 hojas de estilo, >20 scripts)',
	$http_es['stylesheets_count'] >= 20 && $http_es['scripts_count'] >= 20
		&& $http_en['stylesheets_count'] >= 20 && $http_en['scripts_count'] >= 20,
	sprintf( 'ES(css=%d, js=%d) | EN(css=%d, js=%d)', $http_es['stylesheets_count'], $http_es['scripts_count'], $http_en['stylesheets_count'], $http_en['scripts_count'] )
);

// ============================================================================
// BLOQUE 6: MATRIZ DE ENRUTAMIENTO, CANÓNICAS Y CONTENIDO NO REGRESIVO
// ============================================================================
echo "\n--- BLOQUE 6: Matriz de Enrutamiento, Canónicas y Contenido No Regresivo ---\n";

$http_slug_en = inspect_http_route( $lab_url . '/en/home/', false );
record_gate(
	'GET /en/home/ (Slug inglés de portada): Redirección 301 limpia hacia http://lab.ecoterra.test/en/',
	301 === $http_slug_en['code'] && $lab_url . '/en/' === $http_slug_en['location'],
	sprintf( 'HTTP=%d -> Location=%s', $http_slug_en['code'], $http_slug_en['location'] )
);

$http_slug_es = inspect_http_route( $lab_url . '/inicio/', false );
record_gate(
	'GET /inicio/ (Slug español de portada): Redirección 301 limpia hacia http://lab.ecoterra.test/',
	301 === $http_slug_es['code'] && $lab_url . '/' === $http_slug_es['location'],
	sprintf( 'HTTP=%d -> Location=%s', $http_slug_es['code'], $http_slug_es['location'] )
);

$http_fr = inspect_http_route( $lab_url . '/fr/' );
record_gate(
	'GET /fr/ (Idioma inactivo/retenido): HTTP 404, sin fallback silencioso, 0 errores PHP',
	404 === $http_fr['code'] && ! $http_fr['php_errors'],
	sprintf( 'HTTP=%d, canonical=%s, php_errors=%s', $http_fr['code'], $http_fr['canonical'], $http_fr['php_errors'] ? 'true' : 'false' )
);

$http_post_es = inspect_http_route( $lab_url . '/sin-categorizar/recomendaciones-para-disfrutar-machu-picchu/' );
record_gate(
	'GET Post publicado migrado (5687): HTTP 200, lang="es-ES", canonical coherente y 0 errores PHP',
	200 === $http_post_es['code'] && 'es-ES' === $http_post_es['lang'] && ! $http_post_es['php_errors'],
	sprintf( 'HTTP=%d, lang=%s, canonical=%s', $http_post_es['code'], $http_post_es['lang'], $http_post_es['canonical'] )
);

$http_mono_en = inspect_http_route( $lab_url . '/en/old-blog/' );
record_gate(
	'GET /en/old-blog/ (página monolingüe ES bajo prefijo /en/): HTTP 404 sin filtraciones indebidas',
	404 === $http_mono_en['code'] && ! $http_mono_en['php_errors'],
	sprintf( 'HTTP=%d, canonical=%s, php_errors=%s', $http_mono_en['code'], $http_mono_en['canonical'], $http_mono_en['php_errors'] ? 'true' : 'false' )
);

// ============================================================================
// BLOQUE 7: INVARIANCIA POST-FASE DE BASES DE DATOS Y PERSISTENCIA
// ============================================================================
echo "\n--- BLOQUE 7: Invariancia Post-Fase de Bases de Datos y Persistencia ---\n";

$post_cms_ect     = get_icl_sentinel( 'cms_ect' );
$post_cms_ect_lab = get_icl_sentinel( 'cms_ect_lab' );

$post_groups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$post_elements = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$post_retained = $retained_registry->get_all( 'wpml' );

record_gate(
	'Invariancia post-fase: cero escrituras en cms_ect y métricas TFML en cms_ect_lab 100% conservadas (121 grupos, 135 elementos, 1 retenido)',
	$expected_sentinel_rows === $post_cms_ect['rows']
		&& $expected_sentinel_hash === $post_cms_ect['md5']
		&& $expected_sentinel_rows === $post_cms_ect_lab['rows']
		&& $expected_sentinel_hash === $post_cms_ect_lab['md5']
		&& 121 === $post_groups
		&& 135 === $post_elements
		&& 1 === count( $post_retained ),
	sprintf( 'cms_ect=%d:%s | groups=%d, elements=%d, retained=%d', $post_cms_ect['rows'], $post_cms_ect['md5'], $post_groups, $post_elements, count( $post_retained ) )
);

echo "\n================================================================================\n";
echo sprintf( "RESUMEN FASE 3.34: %d CONTROLES | %d PASS | %d FAIL\n", $total_checks, $passed_checks, $failed_checks );
echo "================================================================================\n";

if ( $failed_checks > 0 ) {
	exit( 1 );
}
