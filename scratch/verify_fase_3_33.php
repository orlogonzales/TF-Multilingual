<?php
/**
 * Script de Verificación Reproducible — Fase 3.33 (DT-TFML-063).
 *
 * Resolución Nativa de Portada Multilingüe, Enrutamiento y SEO Canónico.
 *
 * Verifica:
 * 1. Preflight bloqueante e invariancia de datos (121 grupos, 135 elementos activos, grupo 587, 1 retenido fr:8839).
 * 2. Revisión del checkpoint global (tfml_migration_checkpoint_wpml) y opciones nativas de portada (show_on_front, page_on_front).
 * 3. Resolución nativa de portada multilingüe (ResolvedorPortadaMultilingue), normalización de permalinks, canonical y hreflang.
 * 4. Matriz HTTP obligatoria en vivo sobre http://lab.ecoterra.test/ (/, /en/, /en/home/, /inicio/, /fr/, /old-blog/,
 *    /category/blog/, /tag/cusco/, post migrado y par de tours bilingües ES/EN).
 * 5. Invariancia post-fase de opciones persistidas en wp_options, de cms_ect_lab y de la BD protegida cms_ect.
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
 * Ejecuta una petición HTTP real y extrae metadatos HTML, cabeceras de redirección, canonical, hreflang y errores PHP.
 *
 * @param string $url             URL objetivo.
 * @param bool   $follow_location True para seguir redirecciones, false para inspeccionar respuesta directa (ej. 301).
 * @return array{code: int, location: string, lang: string, title: string, canonical: string, hreflang: array<string, string>, php_errors: bool, body_len: int}
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
			CURLOPT_USERAGENT      => 'TFML-Fase-3.33-Verifier/1.0',
		)
	);
	$raw      = (string) curl_exec( $ch );
	$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$hdr_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );

	$headers = substr( $raw, 0, $hdr_size );
	$body    = substr( $raw, $hdr_size );

	$location = '';
	if ( preg_match( '/^Location:\s*(.+)$/mi', $headers, $m_loc ) ) {
		$location = trim( $m_loc[1] );
	}

	$lang = '';
	if ( preg_match( '/<html[^>]*\blang=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$lang = $m[1];
	}

	$title = '';
	if ( preg_match( '/<title>([^<]*)<\/title>/i', $body, $m_title ) ) {
		$title = trim( html_entity_decode( $m_title[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	$canonical = '';
	if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $body, $m_can ) ) {
		$canonical = $m_can[1];
	} elseif ( preg_match( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i', $body, $m_can2 ) ) {
		$canonical = $m_can2[1];
	}

	$hreflang = array();
	if ( preg_match_all( '/<link[^>]+rel=["\']alternate["\'][^>]*>/i', $body, $links ) ) {
		foreach ( $links[0] as $tag ) {
			if ( preg_match( '/hreflang=["\']([^"\']+)["\']/i', $tag, $mh ) && preg_match( '/href=["\']([^"\']+)["\']/i', $tag, $mu ) ) {
				$hreflang[ $mh[1] ] = $mu[1];
			}
		}
	}

	$php_errors = (bool) preg_match( '/(Fatal error|Parse error|Warning:|Notice:|Deprecated:)/i', $body );

	return array(
		'code'       => $code,
		'location'   => $location,
		'lang'       => $lang,
		'title'      => $title,
		'canonical'  => $canonical,
		'hreflang'   => $hreflang,
		'php_errors' => $php_errors,
		'body_len'   => strlen( $body ),
	);
}

echo "================================================================================\n";
echo "VERIFICACIÓN REPRODUCIBLE — FASE 3.33 (DT-TFML-063): PORTADA MULTILINGÜE Y SEO\n";
echo "================================================================================\n\n";

global $wpdb;

// ============================================================================
// BLOQUE 1: PREFLIGHT BLOQUEANTE E INVARIANCIA DE DATOS (SECCIÓN 2)
// ============================================================================
echo "--- BLOQUE 1: Preflight Bloqueante e Invariancia de Datos ---\n";

$active_plugins = (array) get_option( 'active_plugins', array() );
$tfml_active    = in_array( 'tf-multilingual/tf-multilingual.php', $active_plugins, true );
$wpml_active    = false;
foreach ( $active_plugins as $plugin_slug ) {
	if ( false !== stripos( (string) $plugin_slug, 'sitepress' ) || false !== stripos( (string) $plugin_slug, 'wpml' ) ) {
		$wpml_active = true;
	}
}

record_gate(
	'Entorno aislado cms_ect_lab conectado, TFML activo y WPML inactivo',
	'cms_ect_lab' === DB_NAME && 'etk_t_' === $wpdb->prefix && $tfml_active && ! $wpml_active,
	sprintf( 'DB=%s, prefix=%s, tfml=%s, wpml=%s', DB_NAME, $wpdb->prefix, $tfml_active ? 'activo' : 'inactivo', $wpml_active ? 'activo' : 'inactivo' )
);

$sentinel_orig = get_icl_sentinel( 'cms_ect' );
$sentinel_lab  = get_icl_sentinel( 'cms_ect_lab' );
record_gate(
	'Invariancia centinela de icl_translations en cms_ect (protegida) y cms_ect_lab',
	3403 === $sentinel_orig['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_orig['md5']
		&& 3403 === $sentinel_lab['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_lab['md5'],
	sprintf( 'cms_ect=%d:%s | cms_ect_lab=%d:%s', $sentinel_orig['rows'], $sentinel_orig['md5'], $sentinel_lab['rows'], $sentinel_lab['md5'] )
);

$groups_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$group_587_rows = $wpdb->get_results( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 587 ORDER BY language_code ASC", ARRAY_A );
$count_8839     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id = 8839" );
$dup_elements   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING COUNT(*) > 1) d" );
$dup_group_lang = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT group_id, language_code FROM {$wpdb->prefix}tfml_group_elements GROUP BY group_id, language_code HAVING COUNT(*) > 1) d" );

$retained_reg  = new RetainedTranslationRegistry( $wpdb );
$retained_197  = $retained_reg->get( 'wpml', 197 );
$all_retained  = $retained_reg->get_all();
$manifests_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$lock_service  = new MigrationExecutionLock( $wpdb );
$backup_file   = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_32_pilot_trid197_20261013_233600.sql';

record_gate(
	'Línea base de datos Fase 3.32 intacta: 121 grupos, 135 elementos activos, grupo 587 (197:es, 8356:en), 0 filas para 8839, 1 retenido (fr:8839), 0 duplicados, 12 manifiestos y lock libre',
	121 === $groups_count
		&& 135 === $elements_count
		&& 2 === count( $group_587_rows )
		&& 8356 === (int) ( $group_587_rows[0]['element_id'] ?? 0 )
		&& 'en' === ( $group_587_rows[0]['language_code'] ?? '' )
		&& 197 === (int) ( $group_587_rows[1]['element_id'] ?? 0 )
		&& 'es' === ( $group_587_rows[1]['language_code'] ?? '' )
		&& 0 === $count_8839
		&& 1 === count( $all_retained )
		&& isset( $retained_197['retained_elements']['fr'] )
		&& 8839 === (int) ( $retained_197['retained_elements']['fr']['element_id'] ?? 0 )
		&& 0 === $dup_elements
		&& 0 === $dup_group_lang
		&& 12 === $manifests_cnt
		&& ! $lock_service->is_locked( 'wpml' )
		&& file_exists( $backup_file ),
	sprintf( 'groups=%d, elements=%d, group_587=%d, retained=%d, manifests=%d', $groups_count, $elements_count, count( $group_587_rows ), count( $all_retained ), $manifests_cnt )
);

// ============================================================================
// BLOQUE 2: REVISIÓN DE CHECKPOINT GLOBAL Y OPCIONES DE PORTADA (SECCIONES 3 Y 4)
// ============================================================================
echo "\n--- BLOQUE 2: Checkpoint Global y Opciones Nativas de Portada ---\n";

$checkpoint_repo  = new MigrationCheckpointRepository();
$checkpoint       = $checkpoint_repo->get( 'wpml' );
$rollback_service = new WpmlMigrationRollbackService( $wpdb );
$manifest_pilot   = $rollback_service->get_execution_manifest( 'pilot-trid-197-partial' );

record_gate(
	'Checkpoint global (tfml_migration_checkpoint_wpml) en estado completed sin pérdida de los 12 manifiestos históricos',
	null !== $checkpoint
		&& $checkpoint->is_completed()
		&& is_array( $manifest_pilot )
		&& array( 587 ) === ( $manifest_pilot['imported_group_ids'] ?? array() )
		&& 12 === $manifests_cnt,
	sprintf( 'checkpoint_status=%s, manifests_preserved=%d, pilot_group_ids=[587]', $checkpoint ? $checkpoint->get_status() : 'null', $manifests_cnt )
);

$raw_show_on_front  = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'show_on_front'" );
$raw_page_on_front  = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'page_on_front'" );
$raw_page_for_posts = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'page_for_posts'" );

record_gate(
	'Opciones nativas de portada en wp_options: show_on_front=page, page_on_front=197, page_for_posts=10109',
	'page' === $raw_show_on_front && 197 === $raw_page_on_front && 10109 === $raw_page_for_posts,
	sprintf( 'show_on_front=%s, page_on_front=%d, page_for_posts=%d', $raw_show_on_front, $raw_page_on_front, $raw_page_for_posts )
);

// ============================================================================
// BLOQUE 3: RESOLUCIÓN NATIVA DE PORTADA Y NORMALIZACIÓN DE URLS (SECCIONES 5 A 9)
// ============================================================================
echo "\n--- BLOQUE 3: Resolución Nativa de Portada, Permalinks, Canonical y Hreflang ---\n";

$plugin             = Plugin::get_instance();
$resolvedor_portada = $plugin->get_resolvedor_portada_multilingue();
$resolvedor_idioma  = $plugin->get_current_language_resolver();
$gestor_canonico    = $plugin->get_canonical_url_manager();
$generador_hreflang = $plugin->get_hreflang_generator();
$api_publica        = $plugin->get_servicio_traduccion_publico();

record_gate(
	'ResolvedorPortadaMultilingue instanciado y registrado en Plugin',
	$resolvedor_portada instanceof ResolvedorPortadaMultilingue
		&& false !== has_filter( 'option_page_on_front', array( $resolvedor_portada, 'filtrar_opcion_page_on_front' ) )
		&& false !== has_filter( 'page_link', array( $resolvedor_portada, 'filtrar_enlace_pagina' ) )
		&& false !== has_filter( 'redirect_canonical', array( $resolvedor_portada, 'filtrar_redirect_canonical' ) )
		&& false !== has_action( 'parse_query', array( $resolvedor_portada, 'normalizar_consulta_principal_portada' ) ),
	'Ganchos option_page_on_front, page_link, redirect_canonical y parse_query activos'
);

record_gate(
	'Resolución de ID de portada por idioma: base=197, es=197, en=8356, fr=null (inactivo/retenido), xx=null',
	197 === $resolvedor_portada->obtener_id_portada_base()
		&& 197 === $resolvedor_portada->obtener_id_portada_para_idioma( 'es' )
		&& 8356 === $resolvedor_portada->obtener_id_portada_para_idioma( 'en' )
		&& null === $resolvedor_portada->obtener_id_portada_para_idioma( 'fr' )
		&& null === $resolvedor_portada->obtener_id_portada_para_idioma( 'xx' )
		&& $resolvedor_portada->es_portada_de_idioma( 197, 'es' )
		&& $resolvedor_portada->es_portada_de_idioma( 8356, 'en' )
		&& ! $resolvedor_portada->es_portada_de_idioma( 8839, 'fr' ),
	'es->197, en->8356, fr->null, xx->null'
);

// Verificar filtrado de option_page_on_front en contexto público vs contexto no público (wp_doing_cron).
add_filter( 'wp_doing_cron', '__return_true' );
$resolvedor_idioma->set_current_language( 'en' );
$page_on_front_no_publico = (int) get_option( 'page_on_front' );
remove_filter( 'wp_doing_cron', '__return_true' );

$resolvedor_idioma->set_current_language( 'es' );
$page_on_front_publico_es = (int) get_option( 'page_on_front' );
$resolvedor_idioma->set_current_language( 'en' );
$page_on_front_publico_en = (int) get_option( 'page_on_front' );
$resolvedor_idioma->set_current_language( 'es' );

record_gate(
	'Filtrado contextual de option_page_on_front: contexto no público protegido=197, público ES=197, público EN=8356',
	197 === $page_on_front_no_publico
		&& 197 === $page_on_front_publico_es
		&& 8356 === $page_on_front_publico_en,
	sprintf( 'non_public=%d, public_es=%d, public_en=%d', $page_on_front_no_publico, $page_on_front_publico_es, $page_on_front_publico_en )
);

$permalink_197  = (string) get_permalink( 197 );
$permalink_8356 = (string) get_permalink( 8356 );
record_gate(
	'Normalización de permalinks (page_link): get_permalink(197) => / y get_permalink(8356) => /en/',
	( $lab_url . '/' ) === $permalink_197 && ( $lab_url . '/en/' ) === $permalink_8356,
	sprintf( '197=%s | 8356=%s', $permalink_197, $permalink_8356 )
);

$canonical_197  = $gestor_canonico->filter_canonical_url( $permalink_197, 197 );
$canonical_8356 = $gestor_canonico->filter_canonical_url( $permalink_8356, 8356 );
$hreflang_197   = $generador_hreflang->get_post_hreflang_variants( 197 );
$hreflang_8356  = $generador_hreflang->get_post_hreflang_variants( 8356 );
$hreflang_front = $generador_hreflang->get_front_page_hreflang_variants();

$expected_hreflang = array(
	'es'        => $lab_url . '/',
	'en'        => $lab_url . '/en/',
	'x-default' => $lab_url . '/',
);

record_gate(
	'CanonicalUrlManager y HreflangGenerator producen URLs de raíz canónicas y simétricas para 197 y 8356',
	( $lab_url . '/' ) === $canonical_197
		&& ( $lab_url . '/en/' ) === $canonical_8356
		&& $expected_hreflang === $hreflang_197
		&& $expected_hreflang === $hreflang_8356
		&& $expected_hreflang === $hreflang_front
		&& ! isset( $hreflang_197['fr'] ),
	sprintf( 'canonical_es=%s, canonical_en=%s, hreflang=%s', $canonical_197, $canonical_8356, json_encode( $hreflang_197, JSON_UNESCAPED_SLASHES ) )
);

// ============================================================================
// BLOQUE 4: MATRIZ HTTP OBLIGATORIA EN VIVO SOBRE lab.ecoterra.test (SECCIÓN 11)
// ============================================================================
echo "\n--- BLOQUE 4: Matriz HTTP Obligatoria en Vivo sobre {$lab_url} ---\n";

// 1. Portada principal: GET /
$http_root = inspect_http_route( $lab_url . '/' );
record_gate(
	'HTTP Matriz #1 — GET / (Portada ES): HTTP 200, lang="es-ES", canonical="/", hreflang es/en/x-default, 0 errores PHP',
	200 === $http_root['code']
		&& 'es-ES' === $http_root['lang']
		&& ( $lab_url . '/' ) === $http_root['canonical']
		&& $expected_hreflang === $http_root['hreflang']
		&& ! $http_root['php_errors'],
	sprintf( 'HTTP %d, lang="%s", title="%s", canonical="%s"', $http_root['code'], $http_root['lang'], $http_root['title'], $http_root['canonical'] )
);

// 2. Portada secundaria: GET /en/
$http_en = inspect_http_route( $lab_url . '/en/' );
record_gate(
	'HTTP Matriz #2 — GET /en/ (Portada EN): HTTP 200, lang="en-US", canonical="/en/", hreflang es/en/x-default, 0 errores PHP',
	200 === $http_en['code']
		&& 'en-US' === $http_en['lang']
		&& ( $lab_url . '/en/' ) === $http_en['canonical']
		&& $expected_hreflang === $http_en['hreflang']
		&& ! $http_en['php_errors'],
	sprintf( 'HTTP %d, lang="%s", title="%s", canonical="%s"', $http_en['code'], $http_en['lang'], $http_en['title'], $http_en['canonical'] )
);

// 3. Slug directo de portada inglesa: GET /en/home/ -> 301 a /en/
$http_en_home = inspect_http_route( $lab_url . '/en/home/', false );
record_gate(
	'HTTP Matriz #3 — GET /en/home/ (Slug portada EN): HTTP 301 redirige canónicamente a http://lab.ecoterra.test/en/',
	301 === $http_en_home['code']
		&& ( $lab_url . '/en/' ) === $http_en_home['location']
		&& ! $http_en_home['php_errors'],
	sprintf( 'HTTP %d -> Location: %s', $http_en_home['code'], $http_en_home['location'] )
);

// 4. Slug directo de portada española: GET /inicio/ -> 301 a /
$http_es_inicio = inspect_http_route( $lab_url . '/inicio/', false );
record_gate(
	'HTTP Matriz #4 — GET /inicio/ (Slug portada ES): HTTP 301 redirige canónicamente a http://lab.ecoterra.test/',
	301 === $http_es_inicio['code']
		&& ( $lab_url . '/' ) === $http_es_inicio['location']
		&& ! $http_es_inicio['php_errors'],
	sprintf( 'HTTP %d -> Location: %s', $http_es_inicio['code'], $http_es_inicio['location'] )
);

// 5. Idioma inactivo y retenido: GET /fr/ -> 404
$http_fr = inspect_http_route( $lab_url . '/fr/' );
record_gate(
	'HTTP Matriz #5 — GET /fr/ (Idioma inactivo/retenido): HTTP 404, lang="es-ES", sin canonical ni hreflang, 0 errores PHP',
	404 === $http_fr['code']
		&& 'es-ES' === $http_fr['lang']
		&& '' === $http_fr['canonical']
		&& empty( $http_fr['hreflang'] )
		&& ! $http_fr['php_errors'],
	sprintf( 'HTTP %d, lang="%s", canonical="%s", hreflang_count=%d', $http_fr['code'], $http_fr['lang'], $http_fr['canonical'], count( $http_fr['hreflang'] ) )
);

// 6. Archivo de blog / página secundaria: GET /old-blog/
$http_old_blog = inspect_http_route( $lab_url . '/old-blog/' );
record_gate(
	'HTTP Matriz #6 — GET /old-blog/: HTTP 200, lang="es-ES", canonical="/old-blog/", 0 errores PHP',
	200 === $http_old_blog['code']
		&& 'es-ES' === $http_old_blog['lang']
		&& ( $lab_url . '/old-blog/' ) === $http_old_blog['canonical']
		&& ! $http_old_blog['php_errors'],
	sprintf( 'HTTP %d, lang="%s", canonical="%s"', $http_old_blog['code'], $http_old_blog['lang'], $http_old_blog['canonical'] )
);

// 7. Categoría: GET /category/blog/
$http_cat_blog = inspect_http_route( $lab_url . '/category/blog/' );
record_gate(
	'HTTP Matriz #7 — GET /category/blog/: HTTP 200, lang="es-ES", 0 errores PHP',
	200 === $http_cat_blog['code']
		&& 'es-ES' === $http_cat_blog['lang']
		&& ! $http_cat_blog['php_errors'],
	sprintf( 'HTTP %d, lang="%s", title="%s"', $http_cat_blog['code'], $http_cat_blog['lang'], $http_cat_blog['title'] )
);

// 8. Etiqueta: GET /tag/cusco/
$http_tag_cusco = inspect_http_route( $lab_url . '/tag/cusco/' );
record_gate(
	'HTTP Matriz #8 — GET /tag/cusco/: HTTP 200, lang="es-ES", 0 errores PHP',
	200 === $http_tag_cusco['code']
		&& 'es-ES' === $http_tag_cusco['lang']
		&& ! $http_tag_cusco['php_errors'],
	sprintf( 'HTTP %d, lang="%s", title="%s"', $http_tag_cusco['code'], $http_tag_cusco['lang'], $http_tag_cusco['title'] )
);

// 9. Post migrado real en cms_ect_lab
$migrated_post_id = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->prefix}tfml_groups g
	 JOIN {$wpdb->prefix}tfml_group_elements ge ON ge.group_id = g.id
	 JOIN {$wpdb->posts} p ON p.ID = ge.element_id
	 WHERE g.element_type = 'post' AND g.subtype = 'post' AND ge.language_code = 'es' AND p.post_status = 'publish'
	 ORDER BY g.id ASC LIMIT 1"
);
$migrated_post_url = $migrated_post_id > 0 ? (string) get_permalink( $migrated_post_id ) : '';
$http_post         = '' !== $migrated_post_url ? inspect_http_route( $migrated_post_url ) : array( 'code' => 0, 'lang' => '', 'canonical' => '', 'php_errors' => true );

record_gate(
	sprintf( 'HTTP Matriz #9 — Post migrado (ID %d: %s): HTTP 200, lang="es-ES", canonical coherente, 0 errores PHP', $migrated_post_id, $migrated_post_url ),
	200 === $http_post['code']
		&& 'es-ES' === $http_post['lang']
		&& $migrated_post_url === $http_post['canonical']
		&& ! $http_post['php_errors'],
	sprintf( 'HTTP %d, lang="%s", canonical="%s"', $http_post['code'], $http_post['lang'], $http_post['canonical'] )
);

// 10. Segundo post/página migrado público en cms_ect_lab (distinto de 197, 8356, 4338 y 5687)
$second_post_id = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->prefix}tfml_groups g
	 JOIN {$wpdb->prefix}tfml_group_elements ge ON ge.group_id = g.id
	 JOIN {$wpdb->posts} p ON p.ID = ge.element_id
	 WHERE g.element_type = 'post' AND g.subtype IN ('post', 'page')
	   AND ge.language_code = 'es' AND p.post_status = 'publish'
	   AND p.ID NOT IN (197, 8356, 4338, 5687, 10109)
	 ORDER BY g.id ASC LIMIT 1"
);
$second_post_url = $second_post_id > 0 ? (string) get_permalink( $second_post_id ) : '';
$http_second     = '' !== $second_post_url ? inspect_http_route( $second_post_url ) : array( 'code' => 0, 'lang' => '', 'canonical' => '', 'hreflang' => array(), 'php_errors' => true );

record_gate(
	sprintf( 'HTTP Matriz #10 — Segunda entidad migrada publicada (ID %d: %s): HTTP 200, lang="es-ES", canonical coherente, 0 errores PHP', $second_post_id, $second_post_url ),
	200 === $http_second['code']
		&& 'es-ES' === $http_second['lang']
		&& $second_post_url === $http_second['canonical']
		&& ! $http_second['php_errors'],
	sprintf( 'HTTP %d, lang="%s", canonical="%s"', $http_second['code'], $http_second['lang'], $http_second['canonical'] )
);

// 11. Par de tours bilingües migrados (Lote 7: 5321 ES <-> 5322 EN): resolución simétrica y comportamiento HTTP limpio
// Nota: En cms_ect_lab solo tf-multilingual está activo (ova-framework inactivo), por lo que post_type_exists('tours') es false.
$tours_cpt_registered = post_type_exists( 'tours' );
$tour_5321_to_en      = $api_publica->resolver_traduccion_elemento( 'tours', 5321, 'en' );
$tour_5322_to_es      = $api_publica->resolver_traduccion_elemento( 'tours', 5322, 'es' );
$http_tour_5321       = inspect_http_route( $lab_url . '/?p=5321' );
$http_tour_5322       = inspect_http_route( $lab_url . '/en/?p=5322' );

record_gate(
	'HTTP Matriz #11 — Par de Tours bilingües (5321 ES <-> 5322 EN): resolución cruzada exacta y 0 errores PHP (CPT tours pendiente de activación de ova-framework)',
	false === $tours_cpt_registered
		&& 5322 === $tour_5321_to_en
		&& 5321 === $tour_5322_to_es
		&& ! $http_tour_5321['php_errors']
		&& ! $http_tour_5322['php_errors']
		&& 'es-ES' === $http_tour_5321['lang']
		&& 'en-US' === $http_tour_5322['lang'],
	sprintf( '5321->en=%d, 5322->es=%d, cpt_registered=%s, http_5321=%d(%s), http_5322=%d(%s)', (int) $tour_5321_to_en, (int) $tour_5322_to_es, $tours_cpt_registered ? 'true' : 'false', $http_tour_5321['code'], $http_tour_5321['lang'], $http_tour_5322['code'], $http_tour_5322['lang'] )
);

// 12. Política de no-fallback en rutas secundarias sin traducción al inglés: GET /en/old-blog/ -> 404 con lang="en-US"
$http_en_old_blog = inspect_http_route( $lab_url . '/en/old-blog/' );
record_gate(
	'HTTP Matriz #12 — GET /en/old-blog/ (página monolingüe ES solicitada bajo /en/): HTTP 404 sin fallback silencioso, lang="en-US", sin canonical ni hreflang, 0 errores PHP',
	404 === $http_en_old_blog['code']
		&& 'en-US' === $http_en_old_blog['lang']
		&& '' === $http_en_old_blog['canonical']
		&& empty( $http_en_old_blog['hreflang'] )
		&& ! $http_en_old_blog['php_errors'],
	sprintf( 'HTTP %d, lang="%s", canonical="%s", hreflang_count=%d', $http_en_old_blog['code'], $http_en_old_blog['lang'], $http_en_old_blog['canonical'], count( $http_en_old_blog['hreflang'] ) )
);

// ============================================================================
// BLOQUE 5: INVARIANCIA POST-FASE DE OPCIONES Y BASES DE DATOS
// ============================================================================
echo "\n--- BLOQUE 5: Invariancia Post-Fase de Opciones Persistidas y Bases de Datos ---\n";

$post_show_on_front  = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'show_on_front'" );
$post_page_on_front  = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'page_on_front'" );
$post_groups_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$post_elements_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$post_retained_all   = $retained_reg->get_all();
$sentinel_orig_post  = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_post   = get_icl_sentinel( 'cms_ect_lab' );

record_gate(
	'Invariancia post-fase: page_on_front=197 y show_on_front=page sin mutar en BD; 121 grupos, 135 elementos, 1 retenido y cms_ect intacta',
	'page' === $post_show_on_front
		&& 197 === $post_page_on_front
		&& 121 === $post_groups_count
		&& 135 === $post_elements_count
		&& 1 === count( $post_retained_all )
		&& 3403 === $sentinel_orig_post['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_orig_post['md5']
		&& 3403 === $sentinel_lab_post['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_lab_post['md5'],
	sprintf( 'page_on_front=%d, show_on_front=%s, groups=%d, elements=%d, retained=%d', $post_page_on_front, $post_show_on_front, $post_groups_count, $post_elements_count, count( $post_retained_all ) )
);

echo "\n================================================================================\n";
echo sprintf(
	"RESUMEN FASE 3.33: %d CONTROLES | %d PASS | %d FAIL\n",
	$total_checks,
	$passed_checks,
	$failed_checks
);
echo "================================================================================\n";

exit( $failed_checks > 0 ? 1 : 0 );
