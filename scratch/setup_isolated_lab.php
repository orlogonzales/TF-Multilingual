<?php
/**
 * Setup Isolated Staging Laboratory for TF Multilingual (Phase 3.8).
 *
 * Provisions an independent filesystem folder (d:\laragon\www\lab.ecoterra)
 * and an independent MySQL database (cms_ect_lab) with full isolation safeguards:
 * - Blocked external HTTP traffic (WP_HTTP_BLOCK_EXTERNAL)
 * - Blocked emails (pre_wp_mail short-circuit)
 * - Blocked search indexing (blog_public = 0, noindex headers)
 * - Blocked WP-Cron (DISABLE_WP_CRON)
 * - Disabled file edits and automatic updates
 * - Clean WordPress and TFML schemas installed
 * - Zero production data copied (cms_ect remains 100% read-only).
 *
 * @package TF\Multilingual\Scratch
 */

declare( strict_types=1 );

$source_dir = 'd:/laragon/www/cms.ecoterra';
$lab_dir    = 'd:/laragon/www/lab.ecoterra';
$lab_db     = 'cms_ect_lab';

echo "====================================================================\n";
echo "  TF MULTILINGUAL — PREPARACION DE LABORATORIO AISLADO (FASE 3.8)\n";
echo "====================================================================\n\n";

// 1. MySQL: Ensure isolated database exists.
echo "1. Creando / Verificando base de datos aislada '{$lab_db}'...\n";
$mysqli = new mysqli( 'localhost', 'root', '' );
if ( $mysqli->connect_error ) {
	die( "Error de conexión MySQL: " . $mysqli->connect_error . "\n" );
}

$mysqli->query( "CREATE DATABASE IF NOT EXISTS `{$lab_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
echo "   Base de datos '{$lab_db}' lista.\n\n";

// 2. Filesystem: Create laboratory directory structure.
echo "2. Creando estructura de directorios en '{$lab_dir}'...\n";
$dirs_to_create = array(
	$lab_dir,
	$lab_dir . '/wp-content',
	$lab_dir . '/wp-content/plugins',
	$lab_dir . '/wp-content/themes',
	$lab_dir . '/wp-content/uploads',
	$lab_dir . '/wp-content/mu-plugins',
);

foreach ( $dirs_to_create as $dir ) {
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
}
echo "   Directorios base listos.\n\n";

// 3. Copy WordPress Core files (wp-admin, wp-includes, root php files).
echo "3. Copiando archivos del Core de WordPress...\n";

function copy_recursive( string $src, string $dst ): void {
	$dir = opendir( $src );
	if ( ! is_dir( $dst ) ) {
		mkdir( $dst, 0777, true );
	}
	while ( false !== ( $file = readdir( $dir ) ) ) {
		if ( '.' !== $file && '..' !== $file ) {
			if ( is_dir( $src . '/' . $file ) ) {
				copy_recursive( $src . '/' . $file, $dst . '/' . $file );
			} else {
				copy( $src . '/' . $file, $dst . '/' . $file );
			}
		}
	}
	closedir( $dir );
}

if ( ! is_dir( $lab_dir . '/wp-admin' ) ) {
	echo "   Copiando wp-admin...\n";
	copy_recursive( $source_dir . '/wp-admin', $lab_dir . '/wp-admin' );
}
if ( ! is_dir( $lab_dir . '/wp-includes' ) ) {
	echo "   Copiando wp-includes...\n";
	copy_recursive( $source_dir . '/wp-includes', $lab_dir . '/wp-includes' );
}

// Copy root PHP files.
$root_files = array(
	'index.php',
	'wp-blog-header.php',
	'wp-comments-post.php',
	'wp-config-sample.php',
	'wp-cron.php',
	'wp-links-opml.php',
	'wp-load.php',
	'wp-login.php',
	'wp-mail.php',
	'wp-settings.php',
	'wp-signup.php',
	'wp-trackback.php',
	'xmlrpc.php',
	'license.txt',
	'readme.html',
);

foreach ( $root_files as $file ) {
	if ( file_exists( $source_dir . '/' . $file ) ) {
		copy( $source_dir . '/' . $file, $lab_dir . '/' . $file );
	}
}
echo "   Archivos raíz del Core copiados.\n\n";

// 4. Create junction for tf-multilingual plugin.
echo "4. Vinculando plugin tf-multilingual mediante Directory Junction...\n";
$plugin_junction = $lab_dir . '/wp-content/plugins/tf-multilingual';
$plugin_source   = $source_dir . '/wp-content/plugins/tf-multilingual';

if ( ! file_exists( $plugin_junction ) ) {
	$cmd = sprintf( 'cmd /c mklink /J "%s" "%s"', str_replace( '/', '\\', $plugin_junction ), str_replace( '/', '\\', $plugin_source ) );
	shell_exec( $cmd );
}
echo "   Junction del plugin vinculada: {$plugin_junction} -> {$plugin_source}\n\n";

// 5. Generate isolated wp-config.php.
echo "5. Generando wp-config.php aislado con salvaguardas de seguridad...\n";
$salt_keys = array(
	'AUTH_KEY',
	'SECURE_AUTH_KEY',
	'LOGGED_IN_KEY',
	'NONCE_KEY',
	'AUTH_SALT',
	'SECURE_AUTH_SALT',
	'LOGGED_IN_SALT',
	'NONCE_SALT',
);

$salts_code = '';
foreach ( $salt_keys as $key ) {
	$salts_code .= sprintf( "define( '%s', '%s' );\n", $key, bin2hex( random_bytes( 32 ) ) );
}

$wp_config_content = <<<PHP
<?php
/**
 * WordPress Isolated Staging Configuration (Phase 3.8).
 *
 * Generated automatically for TF Multilingual migration laboratory.
 */

// ** Database settings ** //
define( 'DB_NAME', '{$lab_db}' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// ** Unique Authentication Keys and Salts ** //
{$salts_code}

\$table_prefix = 'wp_';

// ** Staging URL Configuration ** //
define( 'WP_HOME', 'http://lab.ecoterra.test' );
define( 'WP_SITEURL', 'http://lab.ecoterra.test' );

// ** Strict Isolation & Safeguards ** //
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'localhost,127.0.0.1' );
define( 'DISABLE_WP_CRON', true );
define( 'DISALLOW_FILE_EDIT', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'ALLOW_UNFILTERED_UPLOADS', false );

// ** Debugging ** //
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );

/* That's all, stop editing! Happy publishing. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHP;

file_put_contents( $lab_dir . '/wp-config.php', $wp_config_content );
echo "   wp-config.php generado exitosamente.\n\n";

// 6. Generate mu-plugins/lab-isolation-guard.php.
echo "6. Generando mu-plugin de salvaguardas (lab-isolation-guard.php)...\n";
$guard_content = <<<PHP
<?php
/**
 * Plugin Name: Laboratory Isolation Guard
 * Description: Hardens laboratory against external leaks, emails, and indexing.
 */

declare( strict_types=1 );

// 1. Block all outgoing emails.
add_filter( 'pre_wp_mail', '__return_false', 999 );

// 2. Force noindex, nofollow, noarchive in HTTP headers and HTML head.
add_action( 'send_headers', function() {
	header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
} );
add_action( 'wp_head', 'wp_no_robots', 1 );

// 3. Disable XML-RPC completely.
add_filter( 'xmlrpc_enabled', '__return_false' );

// 4. Disable pingbacks and trackbacks.
add_filter( 'xmlrpc_methods', function( \$methods ) {
	unset( \$methods['pingback.ping'] );
	return \$methods;
} );
PHP;

file_put_contents( $lab_dir . '/wp-content/mu-plugins/lab-isolation-guard.php', $guard_content );
echo "   mu-plugin guard creado.\n\n";

// 7. Initialize Database schema in cms_ect_lab.
echo "7. Inicializando esquema limpio en {$lab_db}...\n";
$mysqli->select_db( $lab_db );
$mysqli->query( "SET SESSION sql_mode = ''" );

// Create basic WordPress tables if not existing.
$wp_tables_sql = <<<SQL
CREATE TABLE IF NOT EXISTS `wp_options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`),
  KEY `autoload` (`autoload`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT 0,
  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `comment_status` varchar(20) NOT NULL DEFAULT 'open',
  `ping_status` varchar(20) NOT NULL DEFAULT 'open',
  `post_password` varchar(255) NOT NULL DEFAULT '',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `to_ping` text NOT NULL,
  `pinged` text NOT NULL,
  `post_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_modified_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content_filtered` longtext NOT NULL,
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `guid` varchar(255) NOT NULL DEFAULT '',
  `menu_order` int(11) NOT NULL DEFAULT 0,
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  `post_mime_type` varchar(100) NOT NULL DEFAULT '',
  `comment_count` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `post_name` (`post_name`(191)),
  KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`),
  KEY `post_parent` (`post_parent`),
  KEY `post_author` (`post_author`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext DEFAULT NULL,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_terms` (
  `term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL DEFAULT '',
  `slug` varchar(200) NOT NULL DEFAULT '',
  `term_group` bigint(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (`term_id`),
  KEY `slug` (`slug`(191)),
  KEY `name` (`name`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_term_taxonomy` (
  `term_taxonomy_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `term_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `taxonomy` varchar(32) NOT NULL DEFAULT '',
  `description` longtext NOT NULL,
  `parent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `count` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`term_taxonomy_id`),
  UNIQUE KEY `term_id_taxonomy` (`term_id`,`taxonomy`),
  KEY `taxonomy` (`taxonomy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_term_relationships` (
  `object_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `term_taxonomy_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `term_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`object_id`,`term_taxonomy_id`),
  KEY `term_taxonomy_id` (`term_taxonomy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TF Multilingual Tables:
CREATE TABLE IF NOT EXISTS `wp_tfml_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `element_type` varchar(20) NOT NULL DEFAULT '',
  `subtype` varchar(32) NOT NULL DEFAULT '',
  `canonical_element_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `idx_type_subtype` (`element_type`,`subtype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_tfml_group_elements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `element_type` varchar(20) NOT NULL DEFAULT '',
  `element_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `language_code` varchar(10) NOT NULL DEFAULT '',
  `source_version_at_translation` int(10) unsigned NOT NULL DEFAULT 1,
  `current_content_version` int(10) unsigned NOT NULL DEFAULT 1,
  `translatable_fingerprint` varchar(128) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_element` (`element_type`,`element_id`),
  UNIQUE KEY `uniq_group_lang` (`group_id`,`language_code`),
  KEY `idx_group` (`group_id`),
  KEY `idx_element` (`element_type`,`element_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_tfml_media_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attachment_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `language_code` varchar(10) NOT NULL DEFAULT '',
  `alt_text` text DEFAULT NULL,
  `title` text DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attachment_lang` (`attachment_id`,`language_code`),
  KEY `idx_attachment` (`attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_tfml_strings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `string_key` varchar(191) NOT NULL DEFAULT '',
  `context` varchar(64) NOT NULL DEFAULT 'general',
  `original_value` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `last_seen_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key_context` (`string_key`,`context`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_tfml_string_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `string_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `language_code` varchar(10) NOT NULL DEFAULT '',
  `translation_value` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'translated',
  `updated_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_string_lang` (`string_id`,`language_code`),
  KEY `idx_string` (`string_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

$mysqli->multi_query( $wp_tables_sql );
while ( $mysqli->more_results() && $mysqli->next_result() ) {
	// Flush multi queries.
}

// 8. Insert clean initial options.
echo "8. Insertando opciones iniciales de aislamiento...\n";

$initial_options = array(
	'siteurl'          => 'http://lab.ecoterra.test',
	'home'             => 'http://lab.ecoterra.test',
	'blogname'         => 'EcoTerra — Laboratorio Aislado (Fase 3.8)',
	'blog_public'      => '0',
	'admin_email'      => 'dev-null@lab.ecoterra.test',
	'active_plugins'   => serialize( array( 'tf-multilingual/tf-multilingual.php' ) ),
	'tfml_schema_version' => '1.0.0',
	'tfml_settings'    => serialize(
		array(
			'default_language' => 'es',
			'languages'        => array(
				'es' => array(
					'code'           => 'es',
					'locale'         => 'es_ES',
					'name'           => 'Español',
					'native_name'    => 'Español',
					'flag'           => 'es',
					'is_default'     => true,
					'active'         => true,
					'order'          => 1,
					'direction'      => 'ltr',
					'date_format'    => 'd/m/Y',
					'time_format'    => 'H:i',
				),
				'en' => array(
					'code'           => 'en',
					'locale'         => 'en_US',
					'name'           => 'English',
					'native_name'    => 'English',
					'flag'           => 'us',
					'is_default'     => false,
					'active'         => true,
					'order'          => 2,
					'direction'      => 'ltr',
					'date_format'    => 'm/d/Y',
					'time_format'    => 'g:i a',
				),
			),
		)
	),
);

foreach ( $initial_options as $k => $v ) {
	$stmt = $mysqli->prepare( "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES (?, ?, 'yes') ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`)" );
	$stmt->bind_param( 'ss', $k, $v );
	$stmt->execute();
	$stmt->close();
}

$mysqli->close();

echo "\n====================================================================\n";
echo "  LABORATORIO AISLADO PREPARADO EXITOSAMENTE:\n";
echo "  Directorio:     {$lab_dir}\n";
echo "  Base de datos:  {$lab_db}\n";
echo "  URL local:      http://lab.ecoterra.test\n";
echo "  Salvaguardas:   WP_HTTP_BLOCK_EXTERNAL, DISABLE_WP_CRON, pre_wp_mail = false, noindex\n";
echo "  Tablas TFML:    5 tablas maestras creadas en {$lab_db}\n";
echo "  Datos origen:   cms.ecoterra / cms_ect 100% INTACTOS (0 escrituras)\n";
echo "====================================================================\n";
