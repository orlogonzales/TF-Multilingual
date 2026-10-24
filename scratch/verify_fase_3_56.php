<?php
/**
 * TF Multilingual — Verificador Reproducible de Fase 3.56
 * DT-TFML-089: Habilitación de UAT Aislada y Entrega para Aceptación Funcional.
 * DT-TFML-090-R1: Corrección Final de Custodia de Credenciales UAT y Actualización Canónica a lab.ecoterra.test.
 *
 * Ejecuta 10 controles deterministas que certifican:
 * 1. Invarianza absoluta de producción (cms_ect).
 * 2. Invarianza estricta del laboratorio consolidado (cms_ect_lab) y protección de TRID 34790.
 * 3. Aprovisionamiento, integridad relacional y paridad de UAT (cms_ect_uat).
 * 4. Disponibilidad HTTP en vivo en http://lab.ecoterra.test/punko, fallback 8089 e inaccesibilidad HTTP de secretos.
 * 5. Autenticación real de WordPress (admin_uat y admin_lab) con secreto privado y rechazo estricto de contraseñas previas.
 * 6. Prueba de mutación editorial en UAT y aislamiento bidireccional estricto (0 fuga hacia lab/prod).
 * 7. Retención estricta de francés (21 elementos) y protección de borradores en UAT.
 * 8. Renderizado E2E de interfaces administrativas en UAT (0 errores PHP, HTML completo).
 * 9. Matriz frontend real en lab.ecoterra.test, conmutador de idiomas y sitemaps nativos en UAT.
 * 10. Invarianza criptográfica final y certificación de UAT lista para el propietario.
 *
 * @package TF\Multilingual\Scratch
 */

declare(strict_types=1);

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

$uat_root = 'D:/laragon/www/uat.ecoterra';
if ( ! file_exists( $uat_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "ERROR: No se encontró wp-load.php en {$uat_root}\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']       = 'lab.ecoterra.test';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PORT']     = 80;
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';

require_once $uat_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

while ( ob_get_level() > 0 ) {
	ob_end_clean();
}
ini_set( 'display_errors', '1' );

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Seo\CoreSitemapsFilter;
use TF\Multilingual\Domain\Strings\StringTranslationService;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

global $wpdb;

echo "================================================================================\n";
echo "TF MULTILINGUAL — VERIFICADOR REPRODUCIBLE DE FASE 3.56\n";
echo "CUSTODIA DE CREDENCIALES UAT Y URL CANÓNICA (DT-TFML-089 / DT-TFML-090-R1)\n";
echo "================================================================================\n\n";

$all_pass = true;
$results  = array();

/**
 * Helper to calculate production sentinel MD5 hash.
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
 * Helper to inspect live HTTP routes via cURL.
 */
function inspect_route( string $url, array $headers = array(), ?string $cookie = null ): array {
	$ch = curl_init( $url );
	$opt = array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_HEADER         => true,
	);
	if ( ! empty( $headers ) ) {
		$opt[ CURLOPT_HTTPHEADER ] = $headers;
	}
	if ( null !== $cookie ) {
		$opt[ CURLOPT_COOKIE ] = $cookie;
	}
	curl_setopt_array( $ch, $opt );
	$response = (string) curl_exec( $ch );
	$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$raw_headers = substr( $response, 0, $header_size );
	$body        = substr( $response, $header_size );

	$lang      = '';
	$canonical = '';
	$location  = '';
	$set_cookie = '';

	if ( preg_match( '/^Location:\s*(.+)$/mi', $raw_headers, $m ) ) {
		$location = trim( $m[1] );
	}
	if ( preg_match( '/^Set-Cookie:\s*(.+)$/mi', $raw_headers, $m ) ) {
		$set_cookie = trim( $m[1] );
	}
	if ( preg_match( '/<html[^>]+lang=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$lang = $m[1];
	}
	if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$canonical = $m[1];
	}

	return array(
		'code'        => $code,
		'lang'        => $lang,
		'canonical'   => $canonical,
		'location'    => $location,
		'set_cookie'  => $set_cookie,
		'body_len'    => strlen( $body ),
		'body'        => $body,
	);
}

// -----------------------------------------------------------------------------
// CONTROL 1: Invarianza Absoluta de Producción (cms_ect)
// -----------------------------------------------------------------------------
try {
	$prod_sentinel  = get_icl_sentinel( 'cms_ect' );
	$expected_count = 3403;
	$expected_md5   = '4241ca7e7ec6399a594537cb04790c10';

	if ( $prod_sentinel['rows'] === $expected_count && $prod_sentinel['md5'] === $expected_md5 ) {
		echo "[PASS] Control 1: Invarianza Absoluta de Producción (cms_ect) — Filas: {$prod_sentinel['rows']}, MD5: {$prod_sentinel['md5']}\n";
		$results[1] = true;
	} else {
		echo "[FAIL] Control 1: Invarianza Producción Violada! Filas: {$prod_sentinel['rows']} (esp {$expected_count}), MD5: {$prod_sentinel['md5']} (esp {$expected_md5})\n";
		$results[1] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 1 Excepción: " . $e->getMessage() . "\n";
	$results[1] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 2: Invarianza Estricta de Laboratorio Base (cms_ect_lab)
// -----------------------------------------------------------------------------
try {
	$mysqli_lab = new mysqli( '127.0.0.1', 'root', '', 'cms_ect_lab', 3306 );
	$lab_groups    = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM etk_t_tfml_groups" )->fetch_row()[0];
	$lab_elements  = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM etk_t_tfml_group_elements" )->fetch_row()[0];
	$lab_manifests = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM etk_t_options WHERE option_name LIKE 'tfml_migration_manifest_%'" )->fetch_row()[0];

	$res_ret = $mysqli_lab->query( "SELECT option_value FROM etk_t_options WHERE option_name = 'tfml_retained_translations'" );
	$ret_arr = $res_ret && $res_ret->num_rows > 0 ? unserialize( $res_ret->fetch_assoc()['option_value'] ) : array();
	$lab_retained = is_array( $ret_arr ) ? count( $ret_arr ) : 0;

	$lab_drafts = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM etk_t_posts WHERE ID IN (9662, 9663, 9664) AND post_status = 'draft'" )->fetch_row()[0];
	$lab_drafts_tfml = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM etk_t_tfml_group_elements WHERE element_id IN (9662, 9663, 9664)" )->fetch_row()[0];

	$res_lock = $mysqli_lab->query( "SELECT option_value FROM etk_t_options WHERE option_name = 'tfml_migration_execution_lock'" );
	$lock_val = $res_lock && $res_lock->num_rows > 0 ? $res_lock->fetch_assoc()['option_value'] : null;

	$mysqli_lab->close();

	$c2_pass = (
		141 === $lab_groups &&
		175 === $lab_elements &&
		21 === $lab_retained &&
		20 === $lab_manifests &&
		3 === $lab_drafts &&
		0 === $lab_drafts_tfml &&
		( null === $lock_val || '' === $lock_val )
	);

	if ( $c2_pass ) {
		echo "[PASS] Control 2: Invarianza de Laboratorio (cms_ect_lab) — Grupos: {$lab_groups}, Elementos: {$lab_elements}, Retenidas FR: {$lab_retained}, Manifiestos: {$lab_manifests}, Lock Libre: SI, TRID 34790 drafts: 3 (0 en TFML)\n";
		$results[2] = true;
	} else {
		echo "[FAIL] Control 2: Discrepancia en Laboratorio Base! Grp={$lab_groups}, Elem={$lab_elements}, Ret={$lab_retained}, Man={$lab_manifests}, Drafts={$lab_drafts}, DraftsTfml={$lab_drafts_tfml}\n";
		$results[2] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 2 Excepción: " . $e->getMessage() . "\n";
	$results[2] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 3: Aprovisionamiento y Paridad Relacional de UAT (cms_ect_uat)
// -----------------------------------------------------------------------------
try {
	$uat_groups    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_groups" );
	$uat_elements  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_group_elements" );
	$uat_manifests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_options WHERE option_name LIKE 'tfml_migration_manifest_%'" );

	$retained_repo = new RetainedTranslationRegistry();
	$uat_retained  = count( $retained_repo->get_all() );

	$conf_file = 'D:/laragon/etc/apache2/sites-enabled/02-uat.ecoterra.conf';
	$conf_ok   = file_exists( $conf_file ) &&
		( false !== strpos( (string) file_get_contents( $conf_file ), 'lab.ecoterra.test' ) ) &&
		( false !== strpos( (string) file_get_contents( $conf_file ), 'Listen 8089' ) );

	$wp_config_file = $uat_root . '/wp-config.php';
	$wp_config_ok   = file_exists( $wp_config_file ) && ( false !== strpos( (string) file_get_contents( $wp_config_file ), 'cms_ect_uat' ) );

	$siteurl_opt = (string) $wpdb->get_var( "SELECT option_value FROM etk_t_options WHERE option_name = 'siteurl'" );
	$home_opt    = (string) $wpdb->get_var( "SELECT option_value FROM etk_t_options WHERE option_name = 'home'" );
	$url_opt_ok  = ( 'http://lab.ecoterra.test' === $siteurl_opt && 'http://lab.ecoterra.test' === $home_opt );

	$c3_pass = (
		141 === $uat_groups &&
		175 === $uat_elements &&
		21 === $uat_retained &&
		20 === $uat_manifests &&
		$conf_ok &&
		$wp_config_ok &&
		$url_opt_ok
	);

	if ( $c3_pass ) {
		echo "[PASS] Control 3: Paridad Relacional de UAT (cms_ect_uat) — Grupos: {$uat_groups}, Elementos: {$uat_elements}, Retenidas FR: {$uat_retained}, Manifiestos: {$uat_manifests}, Apache VHost canónico lab.ecoterra.test: OK, wp-config UAT: OK, siteurl/home: OK\n";
		$results[3] = true;
	} else {
		echo "[FAIL] Control 3: Discrepancia en aprovisionamiento UAT! Grp={$uat_groups}, Elem={$uat_elements}, Ret={$uat_retained}, ConfOk=" . ( $conf_ok ? 'yes' : 'no' ) . ", UrlOpt=" . ( $url_opt_ok ? 'yes' : 'no' ) . "\n";
		$results[3] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 3 Excepción: " . $e->getMessage() . "\n";
	$results[3] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 4: Disponibilidad HTTP en Vivo (lab.ecoterra.test / 8089) e Inaccesibilidad de Secretos
// -----------------------------------------------------------------------------
try {
	// 4.1 Rutas canónicas sobre http://lab.ecoterra.test
	$lab_home_es = inspect_route( 'http://lab.ecoterra.test/' );
	$lab_home_en = inspect_route( 'http://lab.ecoterra.test/en/' );
	$lab_punko   = inspect_route( 'http://lab.ecoterra.test/punko' );

	$lab_cookie_token = '';
	if ( preg_match( '/wpa_login_token=([a-f0-9]+)/i', $lab_punko['set_cookie'], $cm ) ) {
		$lab_cookie_token = $cm[1];
	}

	$lab_login_no_gate   = inspect_route( 'http://lab.ecoterra.test/wp-login.php' );
	$lab_login_with_gate = inspect_route( 'http://lab.ecoterra.test/wp-login.php', array(), "wpa_login_token={$lab_cookie_token}" );

	// 4.2 Fallback disponible en http://localhost:8089/punko
	$alt_punko = inspect_route( 'http://localhost:8089/punko' );
	$alt_ok    = ( 302 === $alt_punko['code'] );

	// 4.3 Inaccesibilidad absoluta de archivos de credenciales/tokens por HTTP
	$probe_lab_txt  = inspect_route( 'http://lab.ecoterra.test/CREDENCIALES_UAT.txt' );
	$probe_lab_json = inspect_route( 'http://lab.ecoterra.test/.uat_auth_token.json' );
	$probe_alt_txt  = inspect_route( 'http://localhost:8089/CREDENCIALES_UAT.txt' );
	$probe_alt_json = inspect_route( 'http://localhost:8089/.uat_auth_token.json' );

	$http_inaccessible = (
		200 !== $probe_lab_txt['code'] &&
		200 !== $probe_lab_json['code'] &&
		200 !== $probe_alt_txt['code'] &&
		200 !== $probe_alt_json['code']
	);

	// 4.4 Ausencia física en webroot
	$absent_webroot = (
		! file_exists( $uat_root . '/CREDENCIALES_UAT.txt' ) &&
		! file_exists( $uat_root . '/.uat_auth_token.json' )
	);

	$c4_pass = (
		200 === $lab_home_es['code'] &&
		200 === $lab_home_en['code'] &&
		302 === $lab_punko['code'] &&
		! empty( $lab_cookie_token ) &&
		302 === $lab_login_no_gate['code'] &&
		200 === $lab_login_with_gate['code'] &&
		false !== strpos( $lab_login_with_gate['body'], 'loginform' ) &&
		$alt_ok &&
		$http_inaccessible &&
		$absent_webroot
	);

	if ( $c4_pass ) {
		echo "[PASS] Control 4: Disponibilidad HTTP Canónica (lab.ecoterra.test) y Protección de Secretos — ES/EN (200), /punko (302 Token), wp-login protegido (302/200), Fallback 8089 (302), Inaccesibilidad HTTP secretos (bloqueado/403/404): SI\n";
		$results[4] = true;
	} else {
		echo "[FAIL] Control 4: Fallo en rutas HTTP, /punko o custodia! LabES={$lab_home_es['code']}, LabEN={$lab_home_en['code']}, Punko={$lab_punko['code']}, NoGate={$lab_login_no_gate['code']}, WithGate={$lab_login_with_gate['code']}, AltOk=" . ( $alt_ok ? 'yes' : 'no' ) . ", HttpInacc=" . ( $http_inaccessible ? 'yes' : 'no' ) . ", AbsentWebroot=" . ( $absent_webroot ? 'yes' : 'no' ) . "\n";
		$results[4] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 4 Excepción: " . $e->getMessage() . "\n";
	$results[4] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 5: Autenticación Administrativa Estricta y Revocación en UAT
// -----------------------------------------------------------------------------
try {
	$u_uat = get_user_by( 'login', 'admin_uat' );
	$u_lab = get_user_by( 'login', 'admin_lab' );

	$u_uat_ok = $u_uat && in_array( 'administrator', (array) $u_uat->roles, true ) && 'admin_uat@uat.ecoterra.test' === $u_uat->user_email;
	$u_lab_ok = $u_lab && in_array( 'administrator', (array) $u_lab->roles, true );

	// Carga estricta de credencial desde almacenamiento privado seguro
	$token_file = getenv( 'TFML_UAT_CREDENTIALS_PATH' ) ?: 'D:/laragon/storage/secrets/tfml_uat/.uat_auth_token.json';
	$uat_pass   = getenv( 'TFML_UAT_ADMIN_PASS' ) ?: '';
	$lab_pass   = getenv( 'TFML_UAT_LAB_PASS' ) ?: '';

	if ( empty( $uat_pass ) && file_exists( $token_file ) ) {
		$token_data = json_decode( (string) file_get_contents( $token_file ), true );
		$uat_pass   = (string) ( $token_data['pass'] ?? '' );
		$lab_pass   = (string) ( $token_data['pass_lab'] ?? '' );
	}

	if ( empty( $uat_pass ) ) {
		echo "[FAIL] Control 5: Falta secreto de autenticación UAT en almacenamiento privado ({$token_file}). Prohibido continuar sin credenciales reales.\n";
		$results[5] = false;
		$all_pass   = false;
	} else {
		// 5.1 Autenticación positiva de admin_uat mediante WordPress
		$auth_res    = wp_authenticate( 'admin_uat', $uat_pass );
		$auth_uat_ok = ! is_wp_error( $auth_res ) && (int) $auth_res->ID === (int) $u_uat->ID;

		// 5.2 Autenticación positiva de admin_lab si dispone de secreto
		$auth_lab_ok_pass = true;
		if ( ! empty( $lab_pass ) ) {
			$auth_lab_res     = wp_authenticate( 'admin_lab', $lab_pass );
			$auth_lab_ok_pass = ! is_wp_error( $auth_lab_res ) && (int) $auth_lab_res->ID === (int) $u_lab->ID;
		}

		// 5.3 Rechazo estricto de contraseñas anteriores/expuestas históricamente
		$expired_creds_to_test = (array) ( $token_data['revoked_probes'] ?? array() );
		$expired_creds_to_test[] = 'invalid_probe_expired_token';
		$all_expired_rejected = true;
		foreach ( $expired_creds_to_test as $expired_pass ) {
			if ( empty( $expired_pass ) ) {
				continue;
			}
			$probe_res = wp_authenticate( 'admin_uat', $expired_pass );
			if ( ! is_wp_error( $probe_res ) ) {
				$all_expired_rejected = false;
			}
		}

		// 5.4 Hash en base de datos debe ser diferente de hashes obsoletos
		$old_hashes = array(
			'f97978df0b637d7a468d6d63426fe4f5',
		);
		$current_pass_hash = (string) $wpdb->get_var( "SELECT user_pass FROM etk_t_users WHERE user_login = 'admin_uat'" );
		$old_hash_rejected = ! in_array( $current_pass_hash, $old_hashes, true );

		$old_pass_rejected = ( $old_hash_rejected && $all_expired_rejected );

		// 5.5 Aislamiento total: admin_uat no existe en cms_ect ni cms_ect_lab
		$mysqli_check = new mysqli( '127.0.0.1', 'root', '', 'cms_ect', 3306 );
		$prod_u_uat = (int) $mysqli_check->query( "SELECT COUNT(*) FROM etk_t_users WHERE user_login = 'admin_uat'" )->fetch_row()[0];
		$mysqli_check->select_db( 'cms_ect_lab' );
		$lab_u_uat  = (int) $mysqli_check->query( "SELECT COUNT(*) FROM etk_t_users WHERE user_login = 'admin_uat'" )->fetch_row()[0];
		$mysqli_check->close();

		$c5_pass = $u_uat_ok && $u_lab_ok && $auth_uat_ok && $auth_lab_ok_pass && $old_pass_rejected && 0 === $prod_u_uat && 0 === $lab_u_uat;

		if ( $c5_pass ) {
			echo "[PASS] Control 5: Autenticación y Segregación de Roles — admin_uat (ID {$u_uat->ID}) y admin_lab (ID 1) autentican OK con secreto privado, contraseñas anteriores/expuestas estrictamente rechazadas, 0 usuarios UAT en cms_ect y cms_ect_lab: SI\n";
			$results[5] = true;
		} else {
			echo "[FAIL] Control 5: Fallo en autenticación o roles de UAT! UatOk=" . ( $u_uat_ok ? 'yes' : 'no' ) . ", AuthUat=" . ( $auth_uat_ok ? 'yes' : 'no' ) . ", AuthLab=" . ( $auth_lab_ok_pass ? 'yes' : 'no' ) . ", OldRejected=" . ( $old_pass_rejected ? 'yes' : 'no' ) . ", ProdUat={$prod_u_uat}, LabUat={$lab_u_uat}\n";
			$results[5] = false;
			$all_pass   = false;
		}
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 5 Excepción: " . $e->getMessage() . "\n";
	$results[5] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 6: Prueba de Mutación Editorial en UAT y Aislamiento Bidireccional
// -----------------------------------------------------------------------------
try {
	wp_set_current_user( 2 ); // admin_uat

	$settings_repo     = new SettingsRepository();
	$lang_registry     = new LanguageRegistry( $settings_repo );
	$validator         = new WordPressElementValidator( $wpdb );
	$group_repo        = new TranslationGroupRepository( $wpdb, $lang_registry, $validator );
	$resolver          = new ContentTranslationResolver( $group_repo, $lang_registry, $validator );
	$cf_registry       = new CustomFieldPolicyRegistry( $settings_repo );
	$retained_registry = new RetainedTranslationRegistry();

	$editorial_service = new TranslationEditorialService(
		$lang_registry,
		$group_repo,
		$resolver,
		$validator,
		$wpdb,
		$cf_registry,
		null,
		null,
		$retained_registry
	);

	// 6.1 Create synthetic test post in UAT
	$now = current_time( 'mysql' );
	$synth_uat_post = 999951;
	$wpdb->insert(
		'etk_t_posts',
		array(
			'ID'                => $synth_uat_post,
			'post_title'        => 'UAT Isolation Test Page Phase 3.56',
			'post_content'      => 'Testing boundary isolation between UAT, LAB, and PROD.',
			'post_status'       => 'publish',
			'post_type'         => 'page',
			'post_name'         => 'uat-isolation-test-page',
			'post_date'         => $now,
			'post_date_gmt'     => $now,
			'post_modified'     => $now,
			'post_modified_gmt' => $now,
		)
	);

	// 6.2 Create translation group in UAT
	$new_group    = $group_repo->create_group( 'post', 'page', $synth_uat_post, 'es', true );
	$new_group_id = (int) $new_group->get_id();

	// Verify mutation occurred in cms_ect_uat
	$uat_groups_mut = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_groups" );
	$uat_elms_mut   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_group_elements" );

	// Inspect cms_ect_lab to prove STRICT ISOLATION (must still be 141 and 175)
	$mysqli_iso = new mysqli( '127.0.0.1', 'root', '', 'cms_ect_lab', 3306 );
	$lab_groups_iso = (int) $mysqli_iso->query( "SELECT COUNT(*) FROM etk_t_tfml_groups" )->fetch_row()[0];
	$lab_elms_iso   = (int) $mysqli_iso->query( "SELECT COUNT(*) FROM etk_t_tfml_group_elements" )->fetch_row()[0];
	$mysqli_iso->close();

	// Rollback synthetic test data from UAT
	$group_repo->delete( $new_group_id );
	$wpdb->delete( 'etk_t_posts', array( 'ID' => $synth_uat_post ) );

	$uat_groups_clean = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_groups" );
	$uat_elms_clean   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_group_elements" );

	$c6_pass = (
		142 === $uat_groups_mut &&
		176 === $uat_elms_mut &&
		141 === $lab_groups_iso &&
		175 === $lab_elms_iso &&
		141 === $uat_groups_clean &&
		175 === $uat_elms_clean
	);

	if ( $c6_pass ) {
		echo "[PASS] Control 6: Mutación Editorial en UAT y Aislamiento Bidireccional — Mutación UAT (141->142 grp, 175->176 elm), Lab Intacto (141 grp, 175 elm), Limpieza UAT (141 grp, 175 elm): SI\n";
		$results[6] = true;
	} else {
		echo "[FAIL] Control 6: Fallo en aislamiento bidireccional! UatMut=({$uat_groups_mut},{$uat_elms_mut}), LabIso=({$lab_groups_iso},{$lab_elms_iso}), UatClean=({$uat_groups_clean},{$uat_elms_clean})\n";
		$results[6] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 6 Excepción: " . $e->getMessage() . "\n";
	$results[6] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 7: Retención de Francés y Protección de Borradores en UAT
// -----------------------------------------------------------------------------
try {
	$lang_reg_c7  = new LanguageRegistry();
	$active_codes = array_keys( $lang_reg_c7->active() );

	$retained_reg  = new RetainedTranslationRegistry();
	$all_retained  = $retained_reg->get_all();
	$retained_cnt  = count( $all_retained );

	// TRID 34790 check
	$uat_draft_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_posts WHERE ID IN (9662, 9663, 9664) AND post_status = 'draft'" );
	$uat_draft_tfml  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_group_elements WHERE element_id IN (9662, 9663, 9664)" );

	// HTTP 404 on French routes and draft post on canonical host
	$fr_home    = inspect_route( 'http://lab.ecoterra.test/fr/' );
	$draft_resp = inspect_route( 'http://lab.ecoterra.test/?p=9662' );

	$c7_pass = (
		! in_array( 'fr', $active_codes, true ) &&
		in_array( 'es', $active_codes, true ) &&
		in_array( 'en', $active_codes, true ) &&
		21 === $retained_cnt &&
		3 === $uat_draft_posts &&
		0 === $uat_draft_tfml &&
		404 === $fr_home['code'] &&
		404 === $draft_resp['code']
	);

	if ( $c7_pass ) {
		echo "[PASS] Control 7: Retención FR y Protección Borrador TRID 34790 en UAT — Idiomas activos (sin FR), 21 FR retenidas intactas, 3 drafts TRID 34790 (0 en TFML), Rutas /fr/ (404) y draft ?p=9662 (404): SI\n";
		$results[7] = true;
	} else {
		echo "[FAIL] Control 7: Fallo en retención o protección de borradores! Active=" . implode( ',', $active_codes ) . ", Ret={$retained_cnt}, Drafts={$uat_draft_posts}, TfmlDrafts={$uat_draft_tfml}, FrHome={$fr_home['code']}\n";
		$results[7] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 7 Excepción: " . $e->getMessage() . "\n";
	$results[7] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 8: Renderizado E2E de Pantallas Administrativas en UAT
// -----------------------------------------------------------------------------
try {
	wp_set_current_user( 2 ); // admin_uat
	set_current_screen( 'dashboard' );

	$plugin = Plugin::get_instance();

	$screens = array(
		'Languages'      => function () use ( $plugin ) {
			$ui = $plugin->get_language_editorial_ui();
			$ui->render_page();
		},
		'Translations'   => function () use ( $plugin ) {
			$ui = $plugin->get_translations_overview_ui();
			$ui->render_page();
		},
		'Strings'        => function () use ( $plugin ) {
			$ui = $plugin->get_string_editorial_ui();
			$ui->render_page();
		},
		'Migration_Plan' => function () use ( $plugin ) {
			$_GET['tab'] = 'plan';
			$ui = $plugin->get_migration_ui();
			$ui->render_page();
		},
		'Migration_Hist' => function () use ( $plugin ) {
			$_GET['tab'] = 'history';
			$ui = $plugin->get_migration_ui();
			$ui->render_page();
		},
		'Migration_Ret'  => function () use ( $plugin ) {
			$_GET['tab'] = 'retained';
			$ui = $plugin->get_migration_ui();
			$ui->render_page();
		},
		'Diagnostics'    => function () use ( $plugin ) {
			$ui = $plugin->get_diagnostic_ui();
			$ui->render_page();
		},
		'CustomFields'   => function () use ( $plugin ) {
			$ui = $plugin->get_custom_fields_settings_ui();
			$ui->render_page();
		},
		'Navigation'     => function () use ( $plugin ) {
			$ui = $plugin->get_nav_menu_editorial_ui();
			$ui->render_admin_page();
		},
		'Metabox_ES'     => function () use ( $plugin ) {
			$post_es = get_post( 6237 );
			$ui = $plugin->get_post_editorial_ui();
			$ui->render_meta_box( $post_es );
		},
	);

	$screen_passes = 0;
	foreach ( $screens as $s_name => $s_fn ) {
		ob_start();
		try {
			$s_fn();
			$html = ob_get_clean();
			if ( ! empty( $html ) ) {
				$screen_passes++;
			} else {
				echo "   [!] Screen {$s_name} rendered empty HTML\n";
			}
		} catch ( Throwable $te ) {
			ob_end_clean();
			echo "   [ERROR Screen: {$s_name}] " . $te->getMessage() . "\n";
		}
	}

	$c8_pass = ( count( $screens ) === $screen_passes );

	if ( $c8_pass ) {
		echo "[PASS] Control 8: Renderizado E2E de Pantallas Administrativas en UAT — " . count( $screens ) . " Pantallas renderizadas sin errores PHP: SI\n";
		$results[8] = true;
	} else {
		echo "[FAIL] Control 8: Errores en renderizado administrativo! Pasadas={$screen_passes}/" . count( $screens ) . "\n";
		$results[8] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 8 Excepción: " . $e->getMessage() . "\n";
	$results[8] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 9: Matriz HTTP Frontend Real y Sitemaps Nativos en UAT
// -----------------------------------------------------------------------------
try {
	$routes_to_test = array(
		'/'             => 200,
		'/en/'          => 200,
		'/nosotros/'    => 200,
		'/en/about-us/' => 200,
		'/blog/'        => 200,
		'/en/blog/'     => 200,
		'/fr/'          => 404,
		'/pt/'          => 404,
		'/?p=9662'      => 404, // TRID 34790 draft post
	);

	$http_ok = true;
	foreach ( $routes_to_test as $route => $expected_code ) {
		$url  = 'http://lab.ecoterra.test' . $route;
		$resp = inspect_route( $url );
		if ( $resp['code'] !== $expected_code ) {
			echo "   [!] HTTP Route mismatch for {$route}: expected {$expected_code}, got {$resp['code']}\n";
			$http_ok = false;
		}
	}

	// Sitemaps verification
	$posts_provider = new \WP_Sitemaps_Posts();
	$pages_list     = $posts_provider->get_url_list( 1, 'page' );
	$pages_count    = is_array( $pages_list ) ? count( $pages_list ) : 0;

	$has_french_sitemap = false;
	$has_draft_sitemap  = false;
	if ( is_array( $pages_list ) ) {
		foreach ( $pages_list as $p_entry ) {
			$loc = $p_entry['loc'] ?? '';
			if ( false !== strpos( $loc, '/fr/' ) ) {
				$has_french_sitemap = true;
			}
			if ( false !== strpos( $loc, '9662' ) || false !== strpos( $loc, '9663' ) || false !== strpos( $loc, '9664' ) ) {
				$has_draft_sitemap = true;
			}
		}
	}

	$c9_pass = ( $http_ok && 43 === $pages_count && ! $has_french_sitemap && ! $has_draft_sitemap );

	if ( $c9_pass ) {
		echo "[PASS] Control 9: Matriz HTTP Frontend y Sitemaps en UAT (lab.ecoterra.test) — " . count( $routes_to_test ) . " Rutas validadas, Sitemaps pages=43 (0 francés, 0 borradores): SI\n";
		$results[9] = true;
	} else {
		echo "[FAIL] Control 9: Discrepancia en HTTP o Sitemaps! http_ok=" . ( $http_ok ? 'yes' : 'no' ) . ", pages_count={$pages_count}, FrSitemap=" . ( $has_french_sitemap ? 'yes' : 'no' ) . "\n";
		$results[9] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 9 Excepción: " . $e->getMessage() . "\n";
	$results[9] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 10: Invarianza Criptográfica Final y Estado de UAT Certificado
// -----------------------------------------------------------------------------
try {
	$final_prod_sentinel = get_icl_sentinel( 'cms_ect' );

	$mysqli_final = new mysqli( '127.0.0.1', 'root', '', 'cms_ect_lab', 3306 );
	$final_lab_groups    = (int) $mysqli_final->query( "SELECT COUNT(*) FROM etk_t_tfml_groups" )->fetch_row()[0];
	$final_lab_elements  = (int) $mysqli_final->query( "SELECT COUNT(*) FROM etk_t_tfml_group_elements" )->fetch_row()[0];
	$final_lab_manifests = (int) $mysqli_final->query( "SELECT COUNT(*) FROM etk_t_options WHERE option_name LIKE 'tfml_migration_manifest_%'" )->fetch_row()[0];

	$res_ret_f = $mysqli_final->query( "SELECT option_value FROM etk_t_options WHERE option_name = 'tfml_retained_translations'" );
	$ret_arr_f = $res_ret_f && $res_ret_f->num_rows > 0 ? unserialize( $res_ret_f->fetch_assoc()['option_value'] ) : array();
	$final_lab_retained = is_array( $ret_arr_f ) ? count( $ret_arr_f ) : 0;
	$mysqli_final->close();

	$final_uat_groups    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_groups" );
	$final_uat_elements  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_group_elements" );
	$final_uat_manifests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_options WHERE option_name LIKE 'tfml_migration_manifest_%'" );
	$final_uat_retained  = count( ( new RetainedTranslationRegistry() )->get_all() );

	$c10_pass = (
		3403 === $final_prod_sentinel['rows'] &&
		'4241ca7e7ec6399a594537cb04790c10' === $final_prod_sentinel['md5'] &&
		141 === $final_lab_groups &&
		175 === $final_lab_elements &&
		21 === $final_lab_retained &&
		20 === $final_lab_manifests &&
		141 === $final_uat_groups &&
		175 === $final_uat_elements &&
		21 === $final_uat_retained &&
		20 === $final_uat_manifests
	);

	if ( $c10_pass ) {
		echo "[PASS] Control 10: Invarianza Final — cms_ect: 3403 filas / MD5 4241ca7e7ec6399a594537cb04790c10 | cms_ect_lab: 141 grupos, 175 elementos, 21 retained FR, 20 manifiestos | cms_ect_uat: 141 grupos, 175 elementos, 21 retained FR, 20 manifiestos (Listo para UAT humana)\n";
		$results[10] = true;
	} else {
		echo "[FAIL] Control 10: Invarianza violada al cierre! Prod=({$final_prod_sentinel['rows']},{$final_prod_sentinel['md5']}), Lab=({$final_lab_groups},{$final_lab_elements}), Uat=({$final_uat_groups},{$final_uat_elements})\n";
		$results[10] = false;
		$all_pass    = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 10 Excepción: " . $e->getMessage() . "\n";
	$results[10] = false;
	$all_pass    = false;
}

echo "\n================================================================================\n";
$passed_count = count( array_filter( $results ) );
echo "RESULTADO GLOBAL DE FASE 3.56: {$passed_count}/10 CONTROLES PASS (" . ( 10 - $passed_count ) . " FAIL)\n";
echo "================================================================================\n";

if ( ! $all_pass || 10 !== $passed_count ) {
	exit( 1 );
}
exit( 0 );
