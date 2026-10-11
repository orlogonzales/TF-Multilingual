<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.9
 * (Preflight, aislamiento físico, respaldo verificable y clonación controlada)
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

// Bootstrap WordPress on cms.ecoterra (source host).
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/ExternalPluginIsolationGuard.php';

if ( ! defined( 'TFML_PLUGIN_FILE' ) ) {
	define( 'TFML_PLUGIN_FILE', dirname( __DIR__ ) . '/tf-multilingual.php' );
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

set_exception_handler(
	function( Throwable $e ) {
		echo "\nFATAL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
		exit( 1 );
	}
);

use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.9 (PREFLIGHT LAB)\n";
echo "====================================================================\n\n";

$assertions_passed = 0;

function assert_true( bool $condition, string $message ): void {
	global $assertions_passed;
	if ( ! $condition ) {
		echo " [FAIL] {$message}\n";
		exit( 1 );
	}
	echo " [PASS] {$message}\n";
	++$assertions_passed;
}

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: SENTINEL WPML CHECK (cms_ect)
// -------------------------------------------------------------------------
$wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$sentinel_table = $wpdb->prefix . 'icl_translations';
$expected_rows  = 3403;
$expected_md5   = '4241ca7e7ec6399a594537cb04790c10';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pre_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pre_hash = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: {$expected_rows})\n";
echo "  MD5:  {$pre_hash} (Expected: {$expected_md5})\n\n";

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: WPML row count is exactly 3403' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: WPML checksum matches' );

// Activate isolation guard.
$isolation_guard = new ExternalPluginIsolationGuard();
$isolated_count  = $isolation_guard->isolate();
echo "External Plugin Isolation Guard active: isolated {$isolated_count} third-party hooks.\n\n";

$lab_dir = 'd:/laragon/www/lab.ecoterra';
$lab_db  = 'cms_ect_lab';

// -------------------------------------------------------------------------
// 2. CONTROL 1: VERIFICACION DE AISLAMIENTO FISICO DEL CODIGO (SIN JUNCTION)
// -------------------------------------------------------------------------
echo "--- Control 1: Verificación de Aislamiento Físico del Código (No Junction) ---\n";

$lab_plugin_dir = $lab_dir . '/wp-content/plugins/tf-multilingual';

assert_true( is_dir( $lab_plugin_dir ), 'Directorio del plugin en laboratorio existe' );
assert_true( file_exists( $lab_plugin_dir . '/tf-multilingual.php' ), 'Archivo principal tf-multilingual.php existe en laboratorio' );
assert_true( ! is_link( $lab_plugin_dir ), 'Directorio del plugin en laboratorio NO es un enlace simbólico' );

// Check via PowerShell that LinkType is null/empty (guaranteeing physical independence).
$ps_check_junction = shell_exec( 'powershell -NoProfile -Command "(Get-Item \'' . str_replace( '/', '\\', $lab_plugin_dir ) . '\').LinkType"' );
$link_type         = trim( (string) $ps_check_junction );
assert_true( empty( $link_type ), 'Directorio es un directorio físico estándar NTFS (LinkType vacío, sin junction)' );

// Check git checkout in lab.
$lab_git_head = trim( (string) shell_exec( 'git -C "' . str_replace( '/', '\\', $lab_plugin_dir ) . '" rev-parse HEAD' ) );
$expected_sha = 'af46d65a226568e6653549ed4f1eea0956189a44';
assert_true( $lab_git_head === $expected_sha, "Laboratorio tiene checkout independiente fijado en baseline {$expected_sha}" );

// Check host repo status remains clean and intact.
$host_dir      = dirname( __DIR__ );
$host_git_head = trim( (string) shell_exec( 'git -C "' . str_replace( '/', '\\', $host_dir ) . '" rev-parse HEAD' ) );
assert_true( 40 === strlen( $host_git_head ) && ctype_xdigit( $host_git_head ), "Repositorio anfitrión permanece en commit SHA válido ({$host_git_head})" );

// -------------------------------------------------------------------------
// 3. CONTROL 2: VERIFICACION DE RESPALDO Y SALVAGUARDAS ESTRUCTURALES
// -------------------------------------------------------------------------
echo "\n--- Control 2: Verificación de Respaldo y Salvaguardas Estructurales ---\n";

$backup_file = file_exists( 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql' )
	? 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql'
	: $lab_dir . '/backups/preflight_backup_cms_ect_snapshot.sql';
assert_true( file_exists( $backup_file ), 'Archivo de respaldo SQL existe en ruta segura' );

$backup_size = (int) filesize( $backup_file );
assert_true( $backup_size > 15000000, "Tamaño del respaldo es consistente ({$backup_size} bytes, > 15 MB)" );

// Verify backup is outside the git repository.
assert_true( strpos( realpath( $backup_file ), realpath( $host_dir ) ) === false, 'Respaldo está almacenado estrictamente fuera del repositorio Git del plugin' );

// Verify backup contents header.
$backup_handle = fopen( $backup_file, 'r' );
$header_sample = fread( $backup_handle, 1024 );
fclose( $backup_handle );
assert_true( strpos( $header_sample, 'TF Multilingual' ) !== false, 'Encabezado del archivo de respaldo identificado como TF Multilingual Backup' );
assert_true( strpos( $header_sample, 'cms_ect' ) !== false, 'Origen del respaldo documentado formalmente como cms_ect' );

// Verify HTTP access protection for backups directory.
$backup_protect_dir = $lab_dir . '/backups';
assert_true( file_exists( $backup_protect_dir . '/.htaccess' ), 'Archivo .htaccess de bloqueo HTTP existe en backups/' );
$htaccess_content = (string) file_get_contents( $backup_protect_dir . '/.htaccess' );
assert_true( strpos( $htaccess_content, 'Require all denied' ) !== false || strpos( $htaccess_content, 'Deny from all' ) !== false, 'Reglas de denegación de acceso web configuradas en .htaccess' );

assert_true( file_exists( $backup_protect_dir . '/index.php' ), 'Archivo index.php de protección 403 existe en backups/' );
$index_php_content = (string) file_get_contents( $backup_protect_dir . '/index.php' );
assert_true( strpos( $index_php_content, '403' ) !== false, 'index.php de backups/ configurado para responder HTTP 403 Forbidden' );

assert_true( file_exists( $backup_protect_dir . '/web.config' ), 'Archivo web.config para IIS existe en backups/' );
$web_config_content = (string) file_get_contents( $backup_protect_dir . '/web.config' );
assert_true( strpos( $web_config_content, '<deny users="*"' ) !== false, 'web.config de backups/ deniega acceso a todos los usuarios' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: PREFIJOS DINAMICOS Y TABLAS RESTAURADAS EN cms_ect_lab
// -------------------------------------------------------------------------
echo "\n--- Control 3: Prefijos Dinámicos y Tablas Restauradas en {$lab_db} ---\n";

$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';

$mysqli_lab = new mysqli( $db_host, $db_user, $db_pass, $lab_db );
assert_true( ! $mysqli_lab->connect_error, "Conexión independiente con MySQL a {$lab_db} exitosa" );

// Verify real table prefix in wp-config.php of lab.
$lab_config = (string) file_get_contents( $lab_dir . '/wp-config.php' );
assert_true( strpos( $lab_config, "\$table_prefix = 'etk_t_';" ) !== false, 'wp-config.php del laboratorio utiliza el prefijo real dinámico etk_t_' );

// Verify 22 WPML tables exist in cms_ect_lab.
$wpml_tables = array(
	'etk_t_icl_background_task',
	'etk_t_icl_content_status',
	'etk_t_icl_core_status',
	'etk_t_icl_flags',
	'etk_t_icl_languages',
	'etk_t_icl_languages_translations',
	'etk_t_icl_links_post_to_post',
	'etk_t_icl_links_post_to_term',
	'etk_t_icl_locale_map',
	'etk_t_icl_message_status',
	'etk_t_icl_node',
	'etk_t_icl_reminders',
	'etk_t_icl_string_positions',
	'etk_t_icl_string_status',
	'etk_t_icl_string_translations',
	'etk_t_icl_strings',
	'etk_t_icl_translate',
	'etk_t_icl_translate_job',
	'etk_t_icl_translation_batches',
	'etk_t_icl_translation_downloads',
	'etk_t_icl_translation_status',
	'etk_t_icl_translations',
);

foreach ( $wpml_tables as $table ) {
	$res = $mysqli_lab->query( "SHOW TABLES LIKE '{$table}'" );
	assert_true( $res && 1 === $res->num_rows, "Tabla WPML '{$table}' restaurada en {$lab_db}" );
}

// Verify WPML sentinel in cms_ect_lab matches exactly.
$mysqli_lab->query( 'SET SESSION group_concat_max_len = 10000000' );
$lab_rows = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$lab_hash = (string) $mysqli_lab->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

assert_true( $expected_rows === $lab_rows, "Filas WPML en laboratorio coinciden exactamente ({$lab_rows})" );
assert_true( $expected_md5 === $lab_hash, "Checksum WPML en laboratorio coincide exactamente ({$lab_hash})" );

// Verify core content tables in cms_ect_lab.
$lab_posts = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0];
$lab_terms = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0];
assert_true( 4021 === $lab_posts, "Posts restaurados en laboratorio coinciden ({$lab_posts} = 4021)" );
assert_true( 82 === $lab_terms, "Terms restaurados en laboratorio coinciden ({$lab_terms} = 82)" );

// -------------------------------------------------------------------------
// 5. CONTROL 4: ANONIMIZACION Y BLINDAJE DE PRIVACIDAD EN cms_ect_lab
// -------------------------------------------------------------------------
echo "\n--- Control 4: Anonimización y Blindaje de Privacidad en {$lab_db} ---\n";

// Verify users count in cms_ect_lab.
$users_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_users`' )->fetch_row()[0];
assert_true( 1 === $users_count, 'Tabla de usuarios en laboratorio contiene exactamente 1 usuario administrador sintético' );

$user_row = $mysqli_lab->query( 'SELECT `user_login`, `user_email` FROM `etk_t_users` LIMIT 1' )->fetch_assoc();
assert_true( 'admin_lab' === $user_row['user_login'], 'Usuario único de laboratorio es admin_lab' );
assert_true( 'admin@lab.ecoterra.test' === $user_row['user_email'], 'Correo de usuario único de laboratorio es admin@lab.ecoterra.test' );

// Verify no external customer emails exist in etk_t_users.
$ext_emails = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `etk_t_users` WHERE `user_email` NOT LIKE '%@lab.ecoterra.test'" )->fetch_row()[0];
assert_true( 0 === $ext_emails, 'Cero correos productivos de clientes en etk_t_users' );

// Verify payment gateway credentials and sensitive secrets are purged.
$sensitive_options = (int) $mysqli_lab->query(
	"SELECT COUNT(*) FROM `etk_t_options` WHERE `option_name` LIKE 'stripe_%' OR `option_name` LIKE '%api_key%' OR `option_name` LIKE '%secret%' OR `option_name` LIKE '%token%' AND `option_name` NOT LIKE '%active_plugins%'"
)->fetch_row()[0];
assert_true( 0 === $sensitive_options, 'Cero secretos de pago, API keys o tokens residuales en etk_t_options' );

// Verify customer billing metadata in postmeta is completely anonymized.
$billing_first_names = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `etk_t_postmeta` WHERE `meta_key` = '_billing_first_name' AND `meta_value` != 'Lab'" )->fetch_row()[0];
assert_true( 0 === $billing_first_names, 'Metadatos de nombres en pedidos de clientes (_billing_first_name) anonimizados a "Lab"' );

$billing_emails = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `etk_t_postmeta` WHERE `meta_key` = '_billing_email' AND `meta_value` != 'customer@lab.ecoterra.test'" )->fetch_row()[0];
assert_true( 0 === $billing_emails, 'Metadatos de correos de clientes (_billing_email) anonimizados a customer@lab.ecoterra.test' );

// Verify zero un-anonymized emails across ALL postmeta.
$res_unauth = $mysqli_lab->query( "SELECT COUNT(*) FROM `etk_t_postmeta` WHERE `meta_value` LIKE '%@%' AND `meta_value` NOT LIKE '%@lab.ecoterra.test%'" );
$unauth_postmeta_emails = $res_unauth ? (int) $res_unauth->fetch_row()[0] : 0;
assert_true( 0 === $unauth_postmeta_emails, 'Cero correos electrónicos productivos o personales en la totalidad de etk_t_postmeta' );

// Verify zero un-anonymized emails in posts content and excerpt.
$unauth_post_emails = (int) $mysqli_lab->query(
	"SELECT COUNT(*) FROM `etk_t_posts` WHERE (`post_content` LIKE '%@%' AND `post_content` NOT LIKE '%@lab.ecoterra.test%') OR (`post_excerpt` LIKE '%@%' AND `post_excerpt` NOT LIKE '%@lab.ecoterra.test%')"
)->fetch_row()[0];
assert_true( 0 === $unauth_post_emails, 'Cero correos electrónicos productivos en etk_t_posts (contenido y extracto anonimizados)' );

// Verify WPML strings and string translations have zero personal emails.
$wpml_string_emails = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `etk_t_icl_strings` WHERE `value` LIKE '%@%' AND `value` NOT LIKE '%@lab.ecoterra.test%'" )->fetch_row()[0];
assert_true( 0 === $wpml_string_emails, 'Cero correos electrónicos en tabla WPML etk_t_icl_strings' );

$wpml_str_trans_emails = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `etk_t_icl_string_translations` WHERE `value` LIKE '%@%' AND `value` NOT LIKE '%@lab.ecoterra.test%'" )->fetch_row()[0];
assert_true( 0 === $wpml_str_trans_emails, 'Cero correos electrónicos en tabla WPML etk_t_icl_string_translations' );

// Verify comments table is completely excluded from lab.
$comments_table_exists = (int) $mysqli_lab->query( "SHOW TABLES LIKE 'etk_t_comments'" )->num_rows;
assert_true( 0 === $comments_table_exists, 'Tabla etk_t_comments NO fue copiada al laboratorio (Aislamiento de comentarios y datos de usuarios)' );

// Verify isolation parameters in options.
$siteurl_res = $mysqli_lab->query( "SELECT `option_value` FROM `etk_t_options` WHERE `option_name` = 'siteurl'" );
$siteurl_val = $siteurl_res ? $siteurl_res->fetch_row()[0] : '';
assert_true( 'http://lab.ecoterra.test' === $siteurl_val, 'siteurl en cms_ect_lab apunta a http://lab.ecoterra.test' );

$blog_public_res = $mysqli_lab->query( "SELECT `option_value` FROM `etk_t_options` WHERE `option_name` = 'blog_public'" );
$blog_public_val = $blog_public_res ? $blog_public_res->fetch_row()[0] : '';
assert_true( '0' === $blog_public_val, 'blog_public en cms_ect_lab es 0 (bloqueo frente a motores de búsqueda)' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: FRONTERA DE FASE Y BLOQUEO DE MIGRACION (MIGRACION NO EJECUTADA)
// -------------------------------------------------------------------------
echo "\n--- Control 5: Frontera de Fase y Bloqueo de Migración (Migración NO Ejecutada) ---\n";

$tfml_tables = array(
	'etk_t_tfml_groups',
	'etk_t_tfml_group_elements',
	'etk_t_tfml_media_translations',
	'etk_t_tfml_strings',
	'etk_t_tfml_string_translations',
);

foreach ( $tfml_tables as $table ) {
	$res = $mysqli_lab->query( "SHOW TABLES LIKE '{$table}'" );
	assert_true( $res && 1 === $res->num_rows, "Tabla TFML '{$table}' existe y está inicializada con prefijo etk_t_" );

	$row_count = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `{$table}`" )->fetch_row()[0];
	assert_true( 0 === $row_count, "Tabla TFML '{$table}' tiene exactamente 0 filas (migración estrictamente NO ejecutada)" );
}

$mysqli_lab->close();

// -------------------------------------------------------------------------
// 7. POST-FLIGHT: SENTINEL WPML CHECK (cms_ect) (DELTA = 0)
// -------------------------------------------------------------------------
echo "\n--- Sentinel WPML Post-Flight (cms_ect) ---\n";

$post_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "  Rows: {$post_rows} (Expected: {$pre_rows})\n";
echo "  MD5:  {$post_hash} (Expected: {$pre_hash})\n\n";

assert_true( $pre_rows === $post_rows, 'Sentinel post-flight: WPML row count intact en cms_ect (Delta = 0)' );
assert_true( $pre_hash === $post_hash, 'Sentinel post-flight: WPML checksum strictly intact en cms_ect (Delta = 0)' );

echo "====================================================================\n";
echo "  RESULTADO: {$assertions_passed}/{$assertions_passed} VERIFICACIONES PASS\n";
echo "  FASE 3.9 COMPLETADA Y CERTIFICADA EXITOSAMENTE EN LABORATORIO REAL\n";
echo "====================================================================\n";
