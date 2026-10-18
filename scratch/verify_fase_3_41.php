<?php
/**
 * Verificador Reproducible de Fase 3.41 (Directiva DT-TFML-071).
 *
 * Corrección Canonical (`CanonicalUrlManager` -> filtro nativo `get_canonical_url`),
 * Verificación en Solo Lectura del Acceso Administrativo al Laboratorio (`wp-login.php` y `/punko`)
 * e Inventario Real del Panel TF Multilingual, con CERO escrituras en base de datos.
 *
 * Uso:
 *   php scratch/verify_fase_3_41.php
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
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\RetainedTranslationRegistry;

$results = array();
$passed  = 0;
$failed  = 0;

/**
 * Registra el resultado de un control de verificacion.
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
 * Calcula el conteo y hash MD5 de etk_t_icl_translations en la BD indicada.
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
		'rows' => (int) ( $row['cnt'] ?? 0 ),
		'md5'  => (string) ( $row['hash_val'] ?? '' ),
	);
}

/**
 * Calcula un digest SHA-256 del estado actual de tablas TFML, opciones de migración y tablas de usuarios (sin leer user_pass).
 *
 * @return array<string,mixed>
 */
function snapshot_lab_state(): array {
	global $wpdb;

	$groups    = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_groups ORDER BY id ASC", ARRAY_A );
	$elements  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements ORDER BY id ASC", ARRAY_A );
	$retained  = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'tfml_retained_translations'" );
	$manifests = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%' ORDER BY option_name ASC", ARRAY_A );
	$cp        = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'tfml_migration_checkpoint_wpml'" );
	$lock      = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'tfml_migration_lock_wpml'" );
	$users     = $wpdb->get_results( "SELECT ID, user_login, user_nicename, user_email, user_registered, user_status, display_name FROM {$wpdb->users} ORDER BY ID ASC", ARRAY_A );
	$usermeta  = $wpdb->get_results( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} ORDER BY umeta_id ASC", ARRAY_A );

	$ret_reg   = new RetainedTranslationRegistry( $wpdb );
	$ret_count = count( $ret_reg->get_all( 'wpml' ) );

	$cp_arr    = '' !== $cp ? maybe_unserialize( $cp ) : array();
	$cp_status = is_array( $cp_arr ) && isset( $cp_arr['status'] ) ? (string) $cp_arr['status'] : 'none';

	$digest = hash(
		'sha256',
		(string) wp_json_encode(
			array(
				'groups'    => $groups,
				'elements'  => $elements,
				'retained'  => $retained,
				'manifests' => $manifests,
				'cp'        => $cp,
				'lock'      => $lock,
				'users'     => $users,
				'usermeta'  => $usermeta,
			)
		)
	);

	return array(
		'groups_count'    => is_array( $groups ) ? count( $groups ) : 0,
		'elements_count'  => is_array( $elements ) ? count( $elements ) : 0,
		'retained_count'  => $ret_count,
		'manifests_count' => is_array( $manifests ) ? count( $manifests ) : 0,
		'checkpoint'      => $cp_status,
		'users'           => is_array( $users ) ? $users : array(),
		'usermeta'        => is_array( $usermeta ) ? $usermeta : array(),
		'digest'          => $digest,
	);
}

/**
 * Ejecuta una petición HTTP local vía cURL sin seguir redirecciones y analiza cabeceras y HTML.
 *
 * @param string        $url     URL objetivo.
 * @param array<string> $headers Cabeceras adicionales (ej. 'Cookie: ...').
 * @return array<string,mixed>
 */
function fetch_and_inspect_http( string $url, array $headers = array() ): array {
	$ch = curl_init( $url );
	$opts = array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_TIMEOUT        => 15,
	);
	if ( ! empty( $headers ) ) {
		$opts[ CURLOPT_HTTPHEADER ] = $headers;
	}
	curl_setopt_array( $ch, $opts );
	$resp     = (string) curl_exec( $ch );
	$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$hdr_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$raw_hdr  = substr( $resp, 0, $hdr_size );
	$body     = substr( $resp, $hdr_size );
	curl_close( $ch );

	$location = '';
	if ( preg_match( '/^Location:\s*(.+)$/im', $raw_hdr, $lm ) ) {
		$location = trim( $lm[1] );
	}

	$set_cookies = array();
	if ( preg_match_all( '/^Set-Cookie:\s*(.+)$/im', $raw_hdr, $cm ) ) {
		foreach ( $cm[1] as $cval ) {
			$set_cookies[] = trim( $cval );
		}
	}
	$set_cookie = implode( '; ', $set_cookies );

	$lang = '';
	if ( preg_match( '/<html[^>]*\blang=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$lang = $m[1];
	}

	$body_class = '';
	if ( preg_match( '/<body[^>]*\bclass=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$body_class = $m[1];
	}

	$canonical_count = 0;
	$canonical_href  = '';
	if ( preg_match_all( '/<link[^>]*\brel=["\']canonical["\'][^>]*>/i', $body, $canon_tags ) ) {
		$canonical_count = count( $canon_tags[0] );
		if ( $canonical_count > 0 && preg_match( '/\bhref=["\']([^"\']+)["\']/i', $canon_tags[0][0], $hm ) ) {
			$canonical_href = $hm[1];
		}
	}

	$hreflang = array();
	if ( preg_match_all( '/<link[^>]*\brel=["\']alternate["\'][^>]*\bhreflang=["\']([^"\']+)["\'][^>]*\bhref=["\']([^"\']+)["\']/i', $body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$hreflang[ $match[1] ] = $match[2];
		}
	}

	$php_errors = ( false !== stripos( $body, 'Fatal error' ) || false !== stripos( $body, 'Parse error' ) || false !== stripos( $body, 'Uncaught Error' ) );

	return array(
		'code'            => $code,
		'location'        => $location,
		'set_cookie'      => $set_cookie,
		'lang'            => $lang,
		'body_class'      => $body_class,
		'canonical_count' => $canonical_count,
		'canonical_href'  => $canonical_href,
		'hreflang'        => $hreflang,
		'php_errors'      => $php_errors,
		'body'            => $body,
	);
}

echo "========================================================================\n";
echo " VERIFICACIÓN FASE 3.41 (DIRECTIVA DT-TFML-071)\n";
echo " Corrección Canonical + Acceso Admin Lab + Inventario Panel TFML\n";
echo "========================================================================\n\n";

global $wpdb;

// -------------------------------------------------------------------------
// CONTROL 1: Preflight e Invarianza de Entrada
// -------------------------------------------------------------------------
$prod_sentinel_pre = get_icl_sentinel( 'cms_ect' );
$lab_sentinel_pre  = get_icl_sentinel( 'cms_ect_lab' );
$lab_state_pre     = snapshot_lab_state();

$retained_registry = new RetainedTranslationRegistry( $wpdb );
$retained_all      = $retained_registry->get_all( 'wpml' );
$retained_ids      = array();
foreach ( $retained_all as $record ) {
	if ( isset( $record['retained_elements'] ) && is_array( $record['retained_elements'] ) ) {
		foreach ( $record['retained_elements'] as $re ) {
			$retained_ids[] = (int) ( $re['element_id'] ?? 0 );
		}
	}
}
sort( $retained_ids );

$lock_mgr     = new MigrationExecutionLock( $wpdb );
$schema_ver   = (string) get_option( SchemaManager::OPTION_SCHEMA_VERSION, '' );
$preflight_ok = 'cms_ect_lab' === $wpdb->dbname
	&& '1.0.0' === $schema_ver
	&& ! $lock_mgr->is_locked( 'wpml' )
	&& 3403 === $prod_sentinel_pre['rows']
	&& '4241ca7e7ec6399a594537cb04790c10' === $prod_sentinel_pre['md5']
	&& 3403 === $lab_sentinel_pre['rows']
	&& '4241ca7e7ec6399a594537cb04790c10' === $lab_sentinel_pre['md5']
	&& 123 === $lab_state_pre['groups_count']
	&& 139 === $lab_state_pre['elements_count']
	&& 3 === $lab_state_pre['retained_count']
	&& array( 8839, 8873, 8955 ) === $retained_ids
	&& 13 === $lab_state_pre['manifests_count']
	&& 'completed' === $lab_state_pre['checkpoint'];

record_gate(
	'1. Preflight e Invarianza de Entrada (123 grupos, 139 elementos activos, 3 retenidos fr [8839, 8873, 8955], 13 manifiestos y centinelas WPML intactos)',
	$preflight_ok,
	sprintf(
		'DB=%s | groups=%d | elements=%d | retained=%d (%s) | manifests=%d | checkpoint=%s | cms_ect=%d (%s) | cms_ect_lab=%d (%s) | digest_pre=%s',
		$wpdb->dbname,
		$lab_state_pre['groups_count'],
		$lab_state_pre['elements_count'],
		$lab_state_pre['retained_count'],
		implode( ',', $retained_ids ),
		$lab_state_pre['manifests_count'],
		$lab_state_pre['checkpoint'],
		$prod_sentinel_pre['rows'],
		$prod_sentinel_pre['md5'],
		$lab_sentinel_pre['rows'],
		$lab_sentinel_pre['md5'],
		$lab_state_pre['digest']
	)
);

// -------------------------------------------------------------------------
// CONTROL 2: Registro del filtro nativo get_canonical_url en CanonicalUrlManager
// -------------------------------------------------------------------------
$plugin        = Plugin::get_instance();
$canonical_mgr = $plugin->get_canonical_url_manager();

$prio_core   = has_filter( 'get_canonical_url', array( $canonical_mgr, 'filter_canonical_url' ) );
$prio_legacy = has_filter( 'wp_get_canonical_url', array( $canonical_mgr, 'filter_canonical_url' ) );
$prio_yoast  = has_filter( 'wpseo_canonical', array( $canonical_mgr, 'filter_third_party_canonical' ) );
$prio_rm     = has_filter( 'rank_math/canonical_url', array( $canonical_mgr, 'filter_third_party_canonical' ) );

$wp_core_canon_6237 = (string) wp_get_canonical_url( 6237 );
$wp_core_canon_8354 = (string) wp_get_canonical_url( 8354 );
$wp_core_canon_4976 = (string) wp_get_canonical_url( 4976 );
$wp_core_canon_8339 = (string) wp_get_canonical_url( 8339 );
$wp_core_canon_197  = (string) wp_get_canonical_url( 197 );
$wp_core_canon_8356 = (string) wp_get_canonical_url( 8356 );

$canonical_hooks_ok = 10 === $prio_core
	&& 10 === $prio_legacy
	&& 10 === $prio_yoast
	&& 10 === $prio_rm
	&& 'http://lab.ecoterra.test/nosotros/' === $wp_core_canon_6237
	&& 'http://lab.ecoterra.test/en/about-us/' === $wp_core_canon_8354
	&& 'http://lab.ecoterra.test/actividades/' === $wp_core_canon_4976
	&& 'http://lab.ecoterra.test/en/activities/' === $wp_core_canon_8339
	&& 'http://lab.ecoterra.test/' === $wp_core_canon_197
	&& 'http://lab.ecoterra.test/en/' === $wp_core_canon_8356;

record_gate(
	'2. Parte A — CanonicalUrlManager::init_hooks() registra get_canonical_url (prio 10) y wp_get_canonical_url() de WordPress Core devuelve URLs localizadas exactas',
	$canonical_hooks_ok,
	sprintf(
		'get_canonical_url=%s | wp_get_canonical_url=%s | wpseo_canonical=%s | rank_math=%s | 6237=%s | 8354=%s | 4976=%s | 8339=%s | 197=%s | 8356=%s',
		var_export( $prio_core, true ),
		var_export( $prio_legacy, true ),
		var_export( $prio_yoast, true ),
		var_export( $prio_rm, true ),
		$wp_core_canon_6237,
		$wp_core_canon_8354,
		$wp_core_canon_4976,
		$wp_core_canon_8339,
		$wp_core_canon_197,
		$wp_core_canon_8356
	)
);

// -------------------------------------------------------------------------
// CONTROL 3: Emisión HTML real de <link rel="canonical"> en /en/about-us/ y /en/activities/
// -------------------------------------------------------------------------
$r_nosotros    = fetch_and_inspect_http( 'http://lab.ecoterra.test/nosotros/' );
$r_about_en    = fetch_and_inspect_http( 'http://lab.ecoterra.test/en/about-us/' );
$r_actividades = fetch_and_inspect_http( 'http://lab.ecoterra.test/actividades/' );
$r_activ_en    = fetch_and_inspect_http( 'http://lab.ecoterra.test/en/activities/' );
$r_home_es     = fetch_and_inspect_http( 'http://lab.ecoterra.test/' );
$r_home_en     = fetch_and_inspect_http( 'http://lab.ecoterra.test/en/' );
$r_fr          = fetch_and_inspect_http( 'http://lab.ecoterra.test/fr/' );
$r_pt          = fetch_and_inspect_http( 'http://lab.ecoterra.test/pt/' );
$r_sitemap_pub = fetch_and_inspect_http( 'http://lab.ecoterra.test/wp-sitemap.xml' );

$en_internal_canon_ok = 200 === $r_about_en['code']
	&& 1 === $r_about_en['canonical_count']
	&& 'http://lab.ecoterra.test/en/about-us/' === $r_about_en['canonical_href']
	&& 'en-US' === $r_about_en['lang']
	&& false !== strpos( $r_about_en['body_class'], 'page-id-8354' )
	&& ! $r_about_en['php_errors']
	&& 200 === $r_activ_en['code']
	&& 1 === $r_activ_en['canonical_count']
	&& 'http://lab.ecoterra.test/en/activities/' === $r_activ_en['canonical_href']
	&& 'en-US' === $r_activ_en['lang']
	&& false !== strpos( $r_activ_en['body_class'], 'page-id-8339' )
	&& ! $r_activ_en['php_errors'];

record_gate(
	'3. Parte A — HTML real en /en/about-us/ y /en/activities/ emite exactamente 1 <link rel="canonical"> con prefijo /en/ (sin duplicación)',
	$en_internal_canon_ok,
	sprintf(
		'/en/about-us/=[HTTP %d, count=%d, href=%s, lang=%s] | /en/activities/=[HTTP %d, count=%d, href=%s, lang=%s]',
		$r_about_en['code'],
		$r_about_en['canonical_count'],
		$r_about_en['canonical_href'],
		$r_about_en['lang'],
		$r_activ_en['code'],
		$r_activ_en['canonical_count'],
		$r_activ_en['canonical_href'],
		$r_activ_en['lang']
	)
);

// -------------------------------------------------------------------------
// CONTROL 4: No-regresión de canonical en /nosotros/, /actividades/, / y /en/
// -------------------------------------------------------------------------
$es_and_home_canon_ok = 200 === $r_nosotros['code']
	&& 1 === $r_nosotros['canonical_count']
	&& 'http://lab.ecoterra.test/nosotros/' === $r_nosotros['canonical_href']
	&& 'es-ES' === $r_nosotros['lang']
	&& ! $r_nosotros['php_errors']
	&& 200 === $r_actividades['code']
	&& 1 === $r_actividades['canonical_count']
	&& 'http://lab.ecoterra.test/actividades/' === $r_actividades['canonical_href']
	&& 'es-ES' === $r_actividades['lang']
	&& ! $r_actividades['php_errors']
	&& 200 === $r_home_es['code']
	&& 1 === $r_home_es['canonical_count']
	&& 'http://lab.ecoterra.test/' === $r_home_es['canonical_href']
	&& 'es-ES' === $r_home_es['lang']
	&& ! $r_home_es['php_errors']
	&& 200 === $r_home_en['code']
	&& 1 === $r_home_en['canonical_count']
	&& 'http://lab.ecoterra.test/en/' === $r_home_en['canonical_href']
	&& 'en-US' === $r_home_en['lang']
	&& ! $r_home_en['php_errors']
	&& 404 === $r_fr['code']
	&& 404 === $r_pt['code']
	&& 404 === $r_sitemap_pub['code'];

record_gate(
	'4. Parte A — No-regresión de <link rel="canonical"> único en /nosotros/, /actividades/, / y /en/, y bloqueo 404 en /fr/, /pt/ y /wp-sitemap.xml',
	$es_and_home_canon_ok,
	sprintf(
		'/nosotros/=[%d, count=%d, %s] | /actividades/=[%d, count=%d, %s] | /=[%d, count=%d, %s] | /en/=[%d, count=%d, %s] | /fr/=%d | /pt/=%d | /wp-sitemap.xml=%d',
		$r_nosotros['code'],
		$r_nosotros['canonical_count'],
		$r_nosotros['canonical_href'],
		$r_actividades['code'],
		$r_actividades['canonical_count'],
		$r_actividades['canonical_href'],
		$r_home_es['code'],
		$r_home_es['canonical_count'],
		$r_home_es['canonical_href'],
		$r_home_en['code'],
		$r_home_en['canonical_count'],
		$r_home_en['canonical_href'],
		$r_fr['code'],
		$r_pt['code'],
		$r_sitemap_pub['code']
	)
);

// -------------------------------------------------------------------------
// CONTROL 5: No-regresión de hreflang en las 6 páginas bilingües
// -------------------------------------------------------------------------
$expected_hl_nosotros    = array(
	'es'        => 'http://lab.ecoterra.test/nosotros/',
	'en'        => 'http://lab.ecoterra.test/en/about-us/',
	'x-default' => 'http://lab.ecoterra.test/nosotros/',
);
$expected_hl_actividades = array(
	'es'        => 'http://lab.ecoterra.test/actividades/',
	'en'        => 'http://lab.ecoterra.test/en/activities/',
	'x-default' => 'http://lab.ecoterra.test/actividades/',
);
$expected_hl_home        = array(
	'es'        => 'http://lab.ecoterra.test/',
	'en'        => 'http://lab.ecoterra.test/en/',
	'x-default' => 'http://lab.ecoterra.test/',
);

$hreflang_ok = $expected_hl_nosotros === $r_nosotros['hreflang']
	&& $expected_hl_nosotros === $r_about_en['hreflang']
	&& $expected_hl_actividades === $r_actividades['hreflang']
	&& $expected_hl_actividades === $r_activ_en['hreflang']
	&& $expected_hl_home === $r_home_es['hreflang']
	&& $expected_hl_home === $r_home_en['hreflang'];

record_gate(
	'5. Parte A — No-regresión de hreflang (es, en, x-default; 0 francés ni portugués) en /, /en/, /nosotros/, /en/about-us/, /actividades/ y /en/activities/',
	$hreflang_ok,
	sprintf(
		'hl_home=%s | hl_nosotros=%s | hl_actividades=%s',
		wp_json_encode( $r_home_en['hreflang'] ),
		wp_json_encode( $r_about_en['hreflang'] ),
		wp_json_encode( $r_activ_en['hreflang'] )
	)
);

// -------------------------------------------------------------------------
// CONTROL 6: No-regresión de menús de escritorio
// -------------------------------------------------------------------------
$es_html_has_nav = false !== strpos( $r_home_es['body'], 'href="http://lab.ecoterra.test/nosotros/"' )
	&& false !== strpos( $r_home_es['body'], 'href="http://lab.ecoterra.test/actividades/"' )
	&& false !== strpos( $r_nosotros['body'], 'href="http://lab.ecoterra.test/nosotros/"' )
	&& false !== strpos( $r_nosotros['body'], 'href="http://lab.ecoterra.test/actividades/"' )
	&& false !== strpos( $r_actividades['body'], 'href="http://lab.ecoterra.test/nosotros/"' )
	&& false !== strpos( $r_actividades['body'], 'href="http://lab.ecoterra.test/actividades/"' );

$en_html_has_nav = false !== strpos( $r_home_en['body'], 'href="http://lab.ecoterra.test/en/about-us/"' )
	&& false !== strpos( $r_home_en['body'], 'href="http://lab.ecoterra.test/en/activities/"' )
	&& false !== strpos( $r_about_en['body'], 'href="http://lab.ecoterra.test/en/about-us/"' )
	&& false !== strpos( $r_about_en['body'], 'href="http://lab.ecoterra.test/en/activities/"' )
	&& false !== strpos( $r_activ_en['body'], 'href="http://lab.ecoterra.test/en/about-us/"' )
	&& false !== strpos( $r_activ_en['body'], 'href="http://lab.ecoterra.test/en/activities/"' );

$migrated_en_urls    = array();
$unmigrated_safe_cnt = 0;
$premature_en_cnt    = 0;

if ( preg_match( '/<ul[^>]*id=["\']menu-menu-es-ingles["\'][^>]*>(.*?)<\/nav>/is', $r_about_en['body'], $m_nav ) ) {
	if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $m_nav[1], $a_nav, PREG_SET_ORDER ) ) {
		foreach ( $a_nav as $a_item ) {
			$href = trim( $a_item[1] );
			$text = trim( wp_strip_all_tags( $a_item[2] ) );
			if ( in_array( $text, array( 'Home', 'About Us', 'Activities' ), true ) ) {
				$migrated_en_urls[ $text ] = $href;
			} elseif ( '#' !== $href ) {
				if ( 0 === strpos( $href, 'http://lab.ecoterra.test/en/' ) ) {
					++$premature_en_cnt;
				} else {
					++$unmigrated_safe_cnt;
				}
			}
		}
	}
}

$fr_in_en_html = ( false !== strpos( $r_home_en['body'], '/nous/' ) || false !== strpos( $r_home_en['body'], '/activites/' ) || false !== strpos( $r_home_en['body'], '/fr/' ) );

$menus_ok = $es_html_has_nav
	&& $en_html_has_nav
	&& 'http://lab.ecoterra.test/en/' === ( $migrated_en_urls['Home'] ?? '' )
	&& 'http://lab.ecoterra.test/en/about-us/' === ( $migrated_en_urls['About Us'] ?? '' )
	&& 'http://lab.ecoterra.test/en/activities/' === ( $migrated_en_urls['Activities'] ?? '' )
	&& 12 === $unmigrated_safe_cnt
	&& 0 === $premature_en_cnt
	&& ! $fr_in_en_html;

record_gate(
	'6. Parte A — No-regresión de menús de navegación (Nosotros/About Us y Actividades/Activities localizados, 12/12 no migradas protegidas)',
	$menus_ok,
	sprintf(
		'about_en=%s | activities_en=%s | unmigrated_safe=%d/12 | premature_en_404=%d | fr_exposed=%s',
		$migrated_en_urls['About Us'] ?? 'N/A',
		$migrated_en_urls['Activities'] ?? 'N/A',
		$unmigrated_safe_cnt,
		$premature_en_cnt,
		$fr_in_en_html ? 'true' : 'false'
	)
);

// -------------------------------------------------------------------------
// CONTROL 7: No-regresión de sitemaps (30 entradas elegibles, 0 francés, 0 duplicados)
// -------------------------------------------------------------------------
$posts_provider = new WP_Sitemaps_Posts();
$tax_provider   = new WP_Sitemaps_Taxonomies();

$page_entries     = $posts_provider->get_url_list( 1, 'page' );
$post_entries     = $posts_provider->get_url_list( 1, 'post' );
$rooms_entries    = $posts_provider->get_url_list( 1, 'rooms' );
$hf_entries       = $posts_provider->get_url_list( 1, 'ova_framework_hf_el' );
$cat_entries      = $tax_provider->get_url_list( 1, 'category' );
$post_tag_entries = $tax_provider->get_url_list( 1, 'post_tag' );

$page_locs = array_map( static fn( array $e ): string => (string) $e['loc'], $page_entries );
sort( $page_locs );

$all_locs = array_merge(
	$page_locs,
	array_map( static fn( array $e ): string => (string) $e['loc'], $post_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $rooms_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $hf_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $cat_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $post_tag_entries )
);
$unique_locs = array_unique( $all_locs );

$fr_locs = array_filter(
	$all_locs,
	static function ( string $loc ): bool {
		return false !== strpos( $loc, '/fr/' )
			|| false !== strpos( $loc, '/nous/' )
			|| false !== strpos( $loc, '/activites/' )
			|| false !== strpos( $loc, '/accueil/' );
	}
);

$sitemaps_ok = 7 === count( $page_entries )
	&& 3 === count( $post_entries )
	&& 0 === count( $rooms_entries )
	&& 0 === count( $hf_entries )
	&& 1 === count( $cat_entries )
	&& 19 === count( $post_tag_entries )
	&& 30 === count( $all_locs )
	&& 30 === count( $unique_locs )
	&& 0 === count( $fr_locs )
	&& in_array( 'http://lab.ecoterra.test/nosotros/', $page_locs, true )
	&& in_array( 'http://lab.ecoterra.test/en/about-us/', $page_locs, true )
	&& in_array( 'http://lab.ecoterra.test/actividades/', $page_locs, true )
	&& in_array( 'http://lab.ecoterra.test/en/activities/', $page_locs, true )
	&& '0' === (string) get_option( 'blog_public' );

record_gate(
	'7. Parte A/D — No-regresión de sitemaps (30 entradas: page=7, post=3, rooms=0, hf=0, category=1, post_tag=19; 0 francés; 0 duplicados; blog_public=0)',
	$sitemaps_ok,
	sprintf(
		'page=%d | post=%d | rooms=%d | hf=%d | category=%d | post_tag=%d | total=%d | unique=%d | fr=%d | blog_public=%s',
		count( $page_entries ),
		count( $post_entries ),
		count( $rooms_entries ),
		count( $hf_entries ),
		count( $cat_entries ),
		count( $post_tag_entries ),
		count( $all_locs ),
		count( $unique_locs ),
		count( $fr_locs ),
		(string) get_option( 'blog_public' )
	)
);

// -------------------------------------------------------------------------
// CONTROL 8: Parte B — Verificación en solo lectura de wp-login.php, /punko y /wp-admin/
// -------------------------------------------------------------------------
$r_login_direct = fetch_and_inspect_http( 'http://lab.ecoterra.test/wp-login.php' );
$r_punko        = fetch_and_inspect_http( 'http://lab.ecoterra.test/punko' );
$expected_token = wp_hash( 'ecoterra_login_gate' );
$r_login_cookie = fetch_and_inspect_http(
	'http://lab.ecoterra.test/wp-login.php',
	array(
		'Cookie: wpa_login_token=' . $expected_token,
	)
);
$r_wp_admin     = fetch_and_inspect_http( 'http://lab.ecoterra.test/wp-admin/' );

$has_loginform = false !== strpos( $r_login_cookie['body'], 'id="loginform"' )
	&& false !== strpos( $r_login_cookie['body'], 'name="log"' )
	&& false !== strpos( $r_login_cookie['body'], 'name="pwd"' )
	&& false !== strpos( $r_login_cookie['body'], 'name="wp-submit"' );

$login_gateway_ok = 302 === $r_login_direct['code']
	&& 'http://lab.ecoterra.test' === rtrim( $r_login_direct['location'], '/' )
	&& 302 === $r_punko['code']
	&& 'http://lab.ecoterra.test/wp-login.php' === $r_punko['location']
	&& false !== strpos( $r_punko['set_cookie'], 'wpa_login_token=' . $expected_token )
	&& 200 === $r_login_cookie['code']
	&& $has_loginform
	&& ! $r_login_cookie['php_errors']
	&& 302 === $r_wp_admin['code']
	&& false !== strpos( $r_wp_admin['location'], 'wp-login.php' );

record_gate(
	'8. Parte B — Diagnóstico en solo lectura de wp-login.php: redirección sin token (302 -> /), pasarela /punko (Set-Cookie wpa_login_token + 302 -> wp-login.php), formulario #loginform (200 OK) y /wp-admin/ (302 -> wp-login.php)',
	$login_gateway_ok,
	sprintf(
		'direct_login=[%d -> %s] | /punko=[%d -> %s, cookie_ok=%s] | login_with_cookie=[%d, loginform=%s] | /wp-admin/=[%d -> %s]',
		$r_login_direct['code'],
		$r_login_direct['location'],
		$r_punko['code'],
		$r_punko['location'],
		false !== strpos( $r_punko['set_cookie'], 'wpa_login_token=' ) ? 'true' : 'false',
		$r_login_cookie['code'],
		$has_loginform ? 'true' : 'false',
		$r_wp_admin['code'],
		$r_wp_admin['location']
	)
);

// -------------------------------------------------------------------------
// CONTROL 9: Parte B — Verificación en solo lectura de cuenta admin_lab y aislamiento cms_ect vs cms_ect_lab
// -------------------------------------------------------------------------
$lab_users = $lab_state_pre['users'];
$mysqli_p  = new mysqli( '127.0.0.1', 'root', '', 'cms_ect', 3306 );
$prod_u    = array();
if ( ! $mysqli_p->connect_errno ) {
	$prod_u_r = $mysqli_p->query( 'SELECT ID, user_login FROM `etk_t_users` ORDER BY ID ASC' );
	if ( $prod_u_r ) {
		while ( $row = $prod_u_r->fetch_assoc() ) {
			$prod_u[] = $row['user_login'];
		}
		$prod_u_r->free();
	}
	$mysqli_p->close();
}

$admin_user_obj = get_user_by( 'id', 1 );
$is_lab_admin   = ( $admin_user_obj instanceof WP_User )
	&& 'admin_lab' === $admin_user_obj->user_login
	&& 'admin@lab.ecoterra.test' === $admin_user_obj->user_email
	&& in_array( 'administrator', (array) $admin_user_obj->roles, true )
	&& user_can( $admin_user_obj, 'manage_options' );

$users_isolation_ok = 1 === count( $lab_users )
	&& 1 === (int) $lab_users[0]['ID']
	&& 'admin_lab' === $lab_users[0]['user_login']
	&& 2 === count( $lab_state_pre['usermeta'] )
	&& $is_lab_admin
	&& 3 === count( $prod_u )
	&& ! in_array( 'admin_lab', $prod_u, true );

record_gate(
	'9. Parte B — Verificación en solo lectura de cuenta administrativa de laboratorio (admin_lab, ID=1, administrator) y aislamiento con cms_ect (0 lecturas de user_pass, 0 escrituras)',
	$users_isolation_ok,
	sprintf(
		'cms_ect_lab.users=%d (login=%s, email=%s, role=administrator, manage_options=%s, usermeta=%d) | cms_ect.users=%d (admin_lab_in_prod=%s)',
		count( $lab_users ),
		$lab_users[0]['user_login'] ?? 'N/A',
		$lab_users[0]['user_email'] ?? 'N/A',
		$is_lab_admin ? 'true' : 'false',
		count( $lab_state_pre['usermeta'] ),
		count( $prod_u ),
		in_array( 'admin_lab', $prod_u, true ) ? 'true' : 'false'
	)
);

// -------------------------------------------------------------------------
// CONTROL 10: Parte C — Inventario técnico en solo lectura del panel TF Multilingual, REST API y capturas PNG
// -------------------------------------------------------------------------
$rest_server = rest_get_server();
$all_routes  = array_keys( $rest_server->get_routes() );
$tfml_routes = array_values(
	array_filter(
		$all_routes,
		static function ( string $r ): bool {
			return 0 === strpos( $r, '/tf-multilingual/v1' );
		}
	)
);
sort( $tfml_routes );

$expected_rest_routes = array(
	'/tf-multilingual/v1',
	'/tf-multilingual/v1/languages',
	'/tf-multilingual/v1/status/(?P<element_type>post|term)/(?P<id>[\d]+)',
	'/tf-multilingual/v1/status/reviewed',
	'/tf-multilingual/v1/translations',
	'/tf-multilingual/v1/translations/(?P<element_type>post|term)/(?P<id>[\d]+)',
	'/tf-multilingual/v1/translations/link',
	'/tf-multilingual/v1/translations/unlink',
);

$artifact_dir = 'C:/Users/orl55/.gemini/antigravity/brain/c5cacd00-3d71-4e8d-827d-fb7d5eeb9dfd';
$screenshots  = array(
	'fase_3_41_wp_login_punko.png',
	'fase_3_41_admin_diagnostic.png',
	'fase_3_41_admin_strings.png',
	'fase_3_41_admin_navigation.png',
	'fase_3_41_admin_custom_fields.png',
	'fase_3_41_admin_editorial.png',
);
$screenshots_ok = true;
foreach ( $screenshots as $shot ) {
	$path = $artifact_dir . '/' . $shot;
	if ( ! is_file( $path ) || filesize( $path ) < 10000 ) {
		$screenshots_ok = false;
	}
}

$inventory_ok = $expected_rest_routes === $tfml_routes
	&& null !== $plugin->get_diagnostic_ui()
	&& null !== $plugin->get_string_editorial_ui()
	&& null !== $plugin->get_nav_menu_editorial_ui()
	&& null !== $plugin->get_custom_fields_settings_ui()
	&& null !== $plugin->get_admin_list_columns_ui()
	&& null !== $plugin->get_post_editorial_ui()
	&& null !== $plugin->get_term_editorial_ui()
	&& null !== $plugin->get_media_editorial_ui()
	&& $screenshots_ok;

record_gate(
	'10. Parte C — Inventario técnico en solo lectura de pantallas Admin de TF Multilingual, 8 rutas REST (/tf-multilingual/v1/*) y 6 capturas PNG reales',
	$inventory_ok,
	sprintf(
		'admin_uis=8/8 (DiagnosticUi, StringEditorialUi, NavMenuEditorialUi, CustomFieldsSettingsUi, AdminListColumnsUi, PostEditorialUi, TermEditorialUi, MediaEditorialUi) | rest_routes=%d | screenshots=%d/6',
		count( $tfml_routes ),
		$screenshots_ok ? 6 : 0
	)
);

// -------------------------------------------------------------------------
// CONTROL 11: Reconciliación Post-Verificación
// -------------------------------------------------------------------------
$mig_manager  = $plugin->get_migration_manager();
$planner      = $mig_manager->get_wpml_planner();
$executor     = $mig_manager->get_wpml_executor();
$plan_no_conf = $planner->generate_plan( 0, 0, false );

$importable_trids = array_map(
	static fn( array $g ): int => (int) $g['trid'],
	$plan_no_conf->get_groups_by_classification( \TF\Multilingual\Migration\MigrationPlan::CLASSIFICATION_IMPORTABLE )
);
$selections_123   = $importable_trids;
$selections_123[] = array(
	'trid'                 => 197,
	'authorized_languages' => array( 'es', 'en' ),
	'retained_languages'   => array( 'fr' ),
);
$selections_123[] = array(
	'trid'                 => 9201,
	'authorized_languages' => array( 'es', 'en' ),
	'retained_languages'   => array( 'fr' ),
);
$selections_123[] = array(
	'trid'                 => 9545,
	'authorized_languages' => array( 'es', 'en' ),
	'retained_languages'   => array( 'fr' ),
);
$plan_123  = $plan_no_conf->derive_partial_plan_with_retained_languages( $selections_123, 'reconcile-123-groups' );
$reconcile = $executor->reconcile( $plan_123 );

$reconcile_ok = true === ( $reconcile['integrity_verified'] ?? false )
	&& 123 === (int) ( $reconcile['tfml_imported_groups'] ?? 0 )
	&& 139 === (int) ( $reconcile['tfml_imported_elements'] ?? 0 )
	&& 3 === (int) ( $reconcile['retained_pending_elements'] ?? 0 )
	&& empty( $reconcile['discrepancies'] );

record_gate(
	'11. Parte D — Reconciliación íntegra (WpmlMigrationExecutor::reconcile() consistente: 123 grupos, 139 elementos activos, 3 retenidos franceses, 0 discrepancias)',
	$reconcile_ok,
	sprintf(
		'integrity_verified=%s | groups=%d | elements=%d | retained=%d | discrepancies=%d',
		( $reconcile['integrity_verified'] ?? false ) ? 'true' : 'false',
		(int) ( $reconcile['tfml_imported_groups'] ?? 0 ),
		(int) ( $reconcile['tfml_imported_elements'] ?? 0 ),
		(int) ( $reconcile['retained_pending_elements'] ?? 0 ),
		count( $reconcile['discrepancies'] ?? array() )
	)
);

// -------------------------------------------------------------------------
// CONTROL 12: Invarianza Criptográfica Post-Verificación (Cero Escrituras)
// -------------------------------------------------------------------------
$prod_sentinel_post = get_icl_sentinel( 'cms_ect' );
$lab_sentinel_post  = get_icl_sentinel( 'cms_ect_lab' );
$lab_state_post     = snapshot_lab_state();

$invariance_ok = $lab_state_pre['digest'] === $lab_state_post['digest']
	&& 123 === $lab_state_post['groups_count']
	&& 139 === $lab_state_post['elements_count']
	&& 3 === $lab_state_post['retained_count']
	&& 1 === count( $lab_state_post['users'] )
	&& 2 === count( $lab_state_post['usermeta'] )
	&& 3403 === $prod_sentinel_post['rows']
	&& '4241ca7e7ec6399a594537cb04790c10' === $prod_sentinel_post['md5']
	&& 3403 === $lab_sentinel_post['rows']
	&& '4241ca7e7ec6399a594537cb04790c10' === $lab_sentinel_post['md5'];

record_gate(
	'12. Parte D — Invarianza criptográfica post-verificación (CERO escrituras en cms_ect y cms_ect_lab; grupos, elementos, retenidos, usuarios y WPML intactos)',
	$invariance_ok,
	sprintf(
		'digest_equal=%s (%s) | groups=%d | elements=%d | retained=%d | users=%d | usermeta=%d | cms_ect=%d (%s) | cms_ect_lab=%d (%s)',
		$lab_state_pre['digest'] === $lab_state_post['digest'] ? 'true' : 'false',
		$lab_state_post['digest'],
		$lab_state_post['groups_count'],
		$lab_state_post['elements_count'],
		$lab_state_post['retained_count'],
		count( $lab_state_post['users'] ),
		count( $lab_state_post['usermeta'] ),
		$prod_sentinel_post['rows'],
		$prod_sentinel_post['md5'],
		$lab_sentinel_post['rows'],
		$lab_sentinel_post['md5']
	)
);

echo "\n========================================================================\n";
echo sprintf( " RESUMEN FASE 3.41: %d PASS / %d FAIL (Total: %d controles)\n", $passed, $failed, $passed + $failed );
echo "========================================================================\n";

exit( $failed > 0 ? 1 : 0 );
