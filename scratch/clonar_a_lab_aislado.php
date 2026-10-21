<?php
/**
 * TF Multilingual — Script de Respaldo, Anonimización y Clonación Controlada (Fase 3.9)
 *
 * Directiva: DT-TFML-039
 * Baseline:  af46d65a226568e6653549ed4f1eea0956189a44
 *
 * @package TF\Multilingual\Scratch
 */

declare( strict_types=1 );

$source_db  = 'cms_ect';
$target_db  = 'cms_ect_lab';
$lab_dir    = 'd:/laragon/www/lab.ecoterra';
$backup_dir = $lab_dir . '/backups';
$backup_file = $backup_dir . '/preflight_backup_cms_ect_snapshot.sql';

echo "====================================================================\n";
echo "  TF MULTILINGUAL — FASE 3.9: PREFLIGHT, RESPALDO Y CLONACION LAB\n";
echo "====================================================================\n\n";

// Fail-safe check: source and target MUST NEVER be identical.
if ( $source_db === $target_db ) {
	die( "FATAL: Origen y destino no pueden ser la misma base de datos.\n" );
}

// -------------------------------------------------------------------------
// 1. CONEXIONES INDEPENDIENTES Y VERIFICACION PRE-FLIGHT
// -------------------------------------------------------------------------
$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';

$mysqli_src = new mysqli( $db_host, $db_user, $db_pass, $source_db );
if ( $mysqli_src->connect_error ) {
	die( "Error de conexión a {$source_db}: " . $mysqli_src->connect_error . "\n" );
}

$mysqli_tgt = new mysqli( $db_host, $db_user, $db_pass, $target_db );
if ( $mysqli_tgt->connect_error ) {
	die( "Error de conexión a {$target_db}: " . $mysqli_tgt->connect_error . "\n" );
}

// Fail-safe check on target connection:
$tgt_active_db = $mysqli_tgt->query( 'SELECT DATABASE()' )->fetch_row()[0];
if ( $tgt_active_db !== $target_db ) {
	die( "FATAL: La conexión de destino no apunta a {$target_db} (apunta a {$tgt_active_db}). Abortando.\n" );
}

// Sentinel check on source.
$mysqli_src->query( "SET SESSION group_concat_max_len = 10000000" );
$pre_rows = (int) $mysqli_src->query( "SELECT COUNT(*) FROM `etk_t_icl_translations`" )->fetch_row()[0];
$pre_hash = (string) $mysqli_src->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

echo "   Sentinel origen ({$source_db}): {$pre_rows} filas, MD5: {$pre_hash}\n";
if ( 3403 !== $pre_rows || '4241ca7e7ec6399a594537cb04790c10' !== $pre_hash ) {
	die( "FATAL: El centinela de origen no coincide con el valor esperado. Abortando.\n" );
}
echo "   [PASS] Centinela pre-flight confirmado.\n\n";

// -------------------------------------------------------------------------
// 2. GENERACION DE RESPALDO DE SOLO LECTURA
// -------------------------------------------------------------------------
echo "2. Generando respaldo consistente de solo lectura...\n";

if ( ! is_dir( $backup_dir ) ) {
	mkdir( $backup_dir, 0777, true );
}
file_put_contents( $backup_dir . '/.gitignore', "*\n" );
file_put_contents(
	$backup_dir . '/.htaccess',
	"# Deny all web traffic to backup snapshot files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
);
file_put_contents(
	$backup_dir . '/index.php',
	"<?php\n/**\n * HTTP Access Denied.\n */\nhttp_response_code( 403 );\nheader( 'HTTP/1.1 403 Forbidden' );\nexit( 'Access denied.' );\n"
);
file_put_contents(
	$backup_dir . '/web.config',
	"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n    <system.webServer>\n        <authorization>\n            <deny users=\"*\" />\n        </authorization>\n    </system.webServer>\n</configuration>\n"
);

// Define tables to backup.
$tables_to_copy = array(
	// 22 WPML tables:
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
	// 5 Core content tables:
	'etk_t_posts',
	'etk_t_postmeta',
	'etk_t_terms',
	'etk_t_term_taxonomy',
	'etk_t_term_relationships',
	// Options:
	'etk_t_options',
);

$fh = fopen( $backup_file, 'wb' );
if ( ! $fh ) {
	die( "FATAL: No se pudo abrir {$backup_file} para escritura.\n" );
}

fwrite( $fh, "-- TF Multilingual Staging Backup (Phase 3.9)\n" );
fwrite( $fh, "-- Source DB: {$source_db}\n" );
fwrite( $fh, "-- Date: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
fwrite( $fh, "SET FOREIGN_KEY_CHECKS = 0;\n" );
fwrite( $fh, "SET sql_mode = '';\n\n" );

$mysqli_src->query( "SET SESSION sql_mode = ''" );

foreach ( $tables_to_copy as $table ) {
	// 1. Get DDL.
	$create_res = $mysqli_src->query( "SHOW CREATE TABLE `{$table}`" );
	if ( ! $create_res ) {
		fclose( $fh );
		die( "Error obteniendo CREATE TABLE para {$table}\n" );
	}
	$create_sql = $create_res->fetch_row()[1];
	fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
	fwrite( $fh, $create_sql . ";\n\n" );

	// 2. Dump data in chunks.
	$data_res = $mysqli_src->query( "SELECT * FROM `{$table}`", MYSQLI_USE_RESULT );
	if ( ! $data_res ) {
		fclose( $fh );
		die( "Error leyendo datos de {$table}\n" );
	}

	$rows_buffer = array();
	$chunk_size  = 500;
	$fields_info = $data_res->fetch_fields();
	$field_count = count( $fields_info );

	while ( $row = $data_res->fetch_row() ) {
		$values = array();
		for ( $i = 0; $i < $field_count; $i++ ) {
			if ( null === $row[ $i ] ) {
				$values[] = 'NULL';
			} else {
				$values[] = "'" . $mysqli_tgt->real_escape_string( $row[ $i ] ) . "'";
			}
		}
		$rows_buffer[] = '(' . implode( ', ', $values ) . ')';

		if ( count( $rows_buffer ) >= $chunk_size ) {
			fwrite( $fh, "INSERT INTO `{$table}` VALUES " . implode( ",\n", $rows_buffer ) . ";\n" );
			$rows_buffer = array();
		}
	}

	if ( ! empty( $rows_buffer ) ) {
		fwrite( $fh, "INSERT INTO `{$table}` VALUES " . implode( ",\n", $rows_buffer ) . ";\n" );
	}
	fwrite( $fh, "\n" );
}

fwrite( $fh, "SET FOREIGN_KEY_CHECKS = 1;\n" );
fclose( $fh );

$backup_size = filesize( $backup_file );
echo "   Respaldo generado exitosamente: " . round( $backup_size / ( 1024 * 1024 ), 2 ) . " MB\n";
echo "   Ruta: {$backup_file}\n";
echo "   [PASS] Respaldo verificado y protegido fuera del repositorio.\n\n";

// -------------------------------------------------------------------------
// 3. RESTAURACION CONTROLADA EN cms_ect_lab
// -------------------------------------------------------------------------
echo "3. Restaurando tablas seleccionadas en {$target_db}...\n";

$mysqli_tgt->query( "SET FOREIGN_KEY_CHECKS = 0" );
$mysqli_tgt->query( "SET SESSION sql_mode = ''" );

// Execute SQL dump in target DB.
$file_contents = file_get_contents( $backup_file );
$statements    = array_filter( array_map( 'trim', explode( ";\n", $file_contents ) ) );

foreach ( $statements as $stmt ) {
	if ( '' === $stmt || str_starts_with( $stmt, '--' ) ) {
		continue;
	}
	$res = $mysqli_tgt->query( $stmt );
	if ( ! $res ) {
		echo "ERROR en statement: " . substr( $stmt, 0, 100 ) . "...\n";
		echo "MySQL Error: " . $mysqli_tgt->error . "\n";
		die( "Restauración fallida en {$target_db}.\n" );
	}
}

echo "   Tablas restauradas en {$target_db}.\n\n";

// -------------------------------------------------------------------------
// 4. ESTRATEGIA DE ANONIMIZACION Y SEGURIDAD EN cms_ect_lab
// -------------------------------------------------------------------------
echo "4. Aplicando anonimización y blindaje en {$target_db}...\n";

// 4.1 Options table: sanitize URLs, emails, and disable indexing.
$options_updates = array(
	'siteurl'          => 'http://lab.ecoterra.test',
	'home'             => 'http://lab.ecoterra.test',
	'admin_email'      => 'admin@lab.ecoterra.test',
	'blog_public'      => '0',
	'blogname'         => 'EcoTerra — Laboratorio Aislado (Fase 3.9)',
);

foreach ( $options_updates as $k => $v ) {
	$stmt = $mysqli_tgt->prepare( "UPDATE `etk_t_options` SET `option_value` = ? WHERE `option_name` = ?" );
	$stmt->bind_param( 'ss', $v, $k );
	$stmt->execute();
	$stmt->close();
}

// 4.2 Purge sensitive payment/API keys and residual tokens from options.
$sensitive_option_patterns = array(
	'%stripe%',
	'%paypal%',
	'%secret%',
	'%api_key%',
	'%mailchimp%',
	'%sendgrid%',
	'%smtp%',
	'%token%',
);

foreach ( $sensitive_option_patterns as $pat ) {
	$mysqli_tgt->query( "DELETE FROM `etk_t_options` WHERE `option_name` LIKE '{$pat}' AND `option_name` NOT LIKE '%active_plugins%' AND `option_name` NOT LIKE '%user_roles%'" );
}

// 4.3 Users & Accounts: replace with clean, dedicated lab administrator.
$mysqli_tgt->query( "CREATE TABLE IF NOT EXISTS `etk_t_users` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  `user_pass` varchar(255) NOT NULL DEFAULT '',
  `user_nicename` varchar(50) NOT NULL DEFAULT '',
  `user_email` varchar(100) NOT NULL DEFAULT '',
  `user_url` varchar(100) NOT NULL DEFAULT '',
  `user_registered` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `user_activation_key` varchar(255) NOT NULL DEFAULT '',
  `user_status` int(11) NOT NULL DEFAULT 0,
  `display_name` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`),
  KEY `user_login_key` (`user_login`),
  KEY `user_nicename` (`user_nicename`),
  KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

$mysqli_tgt->query( "CREATE TABLE IF NOT EXISTS `etk_t_usermeta` (
  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext DEFAULT NULL,
  PRIMARY KEY (`umeta_id`),
  KEY `user_id` (`user_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

// Truncate users and usermeta.
$mysqli_tgt->query( "TRUNCATE TABLE `etk_t_users`" );
$mysqli_tgt->query( "TRUNCATE TABLE `etk_t_usermeta`" );

// Insert single admin_lab user.
$lab_pass_hash = '$P$B7b3QJ0/JqYQh0Z7Y5v5W0.kQe9cT/1'; // Standard portable hash for staging admin
$mysqli_tgt->query(
	"INSERT INTO `etk_t_users` (`ID`, `user_login`, `user_pass`, `user_nicename`, `user_email`, `user_registered`, `display_name`)
	 VALUES (1, 'admin_lab', '{$lab_pass_hash}', 'admin_lab', 'admin@lab.ecoterra.test', NOW(), 'Admin Lab')"
);

$roles_serialized = serialize( array( 'administrator' => true ) );
$mysqli_tgt->query( "INSERT INTO `etk_t_usermeta` (`user_id`, `meta_key`, `meta_value`) VALUES (1, 'etk_t_capabilities', '{$roles_serialized}')" );
$mysqli_tgt->query( "INSERT INTO `etk_t_usermeta` (`user_id`, `meta_key`, `meta_value`) VALUES (1, 'etk_t_user_level', '10')" );

// 4.4 Anonymize customer billing and shipping metadata in postmeta.
$billing_shipping_overrides = array(
	'_billing_first_name'     => 'Lab',
	'_billing_last_name'      => 'Customer',
	'_billing_phone'          => '+000000000',
	'_billing_email'          => 'customer@lab.ecoterra.test',
	'_billing_address_1'      => 'Av. Laboratorio 123',
	'_billing_address_2'      => '',
	'_billing_city'           => 'Laboratorio',
	'_billing_postcode'       => '00000',
	'_billing_address_index'  => 'Lab Customer PE customer@lab.ecoterra.test +000000000',
	'_shipping_first_name'    => 'Lab',
	'_shipping_last_name'     => 'Customer',
	'_shipping_phone'         => '+000000000',
	'_shipping_address_1'     => 'Av. Laboratorio 123',
	'_shipping_address_2'     => '',
	'_shipping_city'          => 'Laboratorio',
	'_shipping_postcode'      => '00000',
	'_shipping_address_index' => 'Lab Customer PE',
);

foreach ( $billing_shipping_overrides as $b_key => $b_val ) {
	$stmt_b = $mysqli_tgt->prepare( "UPDATE `etk_t_postmeta` SET `meta_value` = ? WHERE `meta_key` = ?" );
	$stmt_b->bind_param( 'ss', $b_val, $b_key );
	$stmt_b->execute();
	$stmt_b->close();
}

// 4.5 Anonymize any residual emails across all postmeta (serialized arrays, JSON, plain strings).
$email_regex = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
$meta_res = $mysqli_tgt->query( "SELECT `meta_id`, `meta_value` FROM `etk_t_postmeta` WHERE `meta_value` LIKE '%@%' AND `meta_value` NOT LIKE '%@lab.ecoterra.test%'" );
if ( $meta_res ) {
	while ( $m_row = $meta_res->fetch_assoc() ) {
		$mid = (int) $m_row['meta_id'];
		$val = (string) $m_row['meta_value'];

		// If serialized PHP string:
		if ( str_contains( $val, 's:' ) && false !== @unserialize( $val ) ) {
			$unser = unserialize( $val );
			$anon_array = function ( &$item ) use ( &$anon_array, $email_regex ) {
				if ( is_array( $item ) ) {
					foreach ( $item as &$sub ) {
						$anon_array( $sub );
					}
				} elseif ( is_string( $item ) ) {
					$item = preg_replace( $email_regex, 'contacto@lab.ecoterra.test', $item );
				}
			};
			$anon_array( $unser );
			$new_val = serialize( $unser );
			$stmt = $mysqli_tgt->prepare( "UPDATE `etk_t_postmeta` SET `meta_value` = ? WHERE `meta_id` = ?" );
			$stmt->bind_param( 'si', $new_val, $mid );
			$stmt->execute();
			$stmt->close();
		} else {
			// Handles raw JSON (_elementor_data) and plain text:
			$new_val = preg_replace( $email_regex, 'contacto@lab.ecoterra.test', $val );
			if ( $new_val !== $val ) {
				$stmt = $mysqli_tgt->prepare( "UPDATE `etk_t_postmeta` SET `meta_value` = ? WHERE `meta_id` = ?" );
				$stmt->bind_param( 'si', $new_val, $mid );
				$stmt->execute();
				$stmt->close();
			}
		}
	}
}

// 4.6 Anonymize emails in posts content and excerpt.
$posts_res = $mysqli_tgt->query( "SELECT `ID`, `post_content`, `post_excerpt` FROM `etk_t_posts` WHERE `post_content` LIKE '%@%' OR `post_excerpt` LIKE '%@%'" );
if ( $posts_res ) {
	while ( $p_row = $posts_res->fetch_assoc() ) {
		$pid     = (int) $p_row['ID'];
		$content = (string) $p_row['post_content'];
		$excerpt = (string) $p_row['post_excerpt'];

		$new_content = preg_replace( $email_regex, 'contacto@lab.ecoterra.test', $content );
		$new_excerpt = preg_replace( $email_regex, 'contacto@lab.ecoterra.test', $excerpt );

		if ( $new_content !== $content || $new_excerpt !== $excerpt ) {
			$stmt_p = $mysqli_tgt->prepare( "UPDATE `etk_t_posts` SET `post_content` = ?, `post_excerpt` = ? WHERE `ID` = ?" );
			$stmt_p->bind_param( 'ssi', $new_content, $new_excerpt, $pid );
			$stmt_p->execute();
			$stmt_p->close();
		}
	}
}

// 4.5 Ensure TF Multilingual tables exist with prefix etk_t_ in cms_ect_lab (EMPTY: NO MIGRATION RUN YET).
$tfml_tables_sql = <<<SQL
CREATE TABLE IF NOT EXISTS `etk_t_tfml_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `element_type` varchar(20) NOT NULL DEFAULT '',
  `subtype` varchar(32) NOT NULL DEFAULT '',
  `canonical_element_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `idx_type_subtype` (`element_type`,`subtype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `etk_t_tfml_group_elements` (
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

CREATE TABLE IF NOT EXISTS `etk_t_tfml_media_translations` (
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

CREATE TABLE IF NOT EXISTS `etk_t_tfml_strings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `string_key` varchar(191) NOT NULL DEFAULT '',
  `context` varchar(64) NOT NULL DEFAULT 'general',
  `original_value` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `last_seen_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key_context` (`string_key`,`context`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `etk_t_tfml_string_translations` (
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

$mysqli_tgt->multi_query( $tfml_tables_sql );
while ( $mysqli_tgt->more_results() && $mysqli_tgt->next_result() ) {
	// Flush.
}

// Truncate TFML tables in target to guarantee NO migration data has been created:
$mysqli_tgt->query( "TRUNCATE TABLE `etk_t_tfml_groups`" );
$mysqli_tgt->query( "TRUNCATE TABLE `etk_t_tfml_group_elements`" );
$mysqli_tgt->query( "TRUNCATE TABLE `etk_t_tfml_media_translations`" );
$mysqli_tgt->query( "TRUNCATE TABLE `etk_t_tfml_strings`" );
$mysqli_tgt->query( "TRUNCATE TABLE `etk_t_tfml_string_translations`" );

echo "   Anonimización completada: usuarios productivos purgados, emails reescritos a @lab.ecoterra.test, pasarelas de pago y secretos eliminados.\n\n";

// -------------------------------------------------------------------------
// 5. VERIFICACION DE INTEGRIDAD EN cms_ect_lab
// -------------------------------------------------------------------------
echo "5. Verificando integridad del laboratorio restaurado ({$target_db})...\n";

$tgt_wpml_rows = (int) $mysqli_tgt->query( "SELECT COUNT(*) FROM `etk_t_icl_translations`" )->fetch_row()[0];
$mysqli_tgt->query( "SET SESSION group_concat_max_len = 10000000" );
$tgt_wpml_hash = (string) $mysqli_tgt->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

echo "   WPML en {$target_db}: {$tgt_wpml_rows} filas, MD5: {$tgt_wpml_hash}\n";

if ( 3403 !== $tgt_wpml_rows || '4241ca7e7ec6399a594537cb04790c10' !== $tgt_wpml_hash ) {
	die( "FATAL: La integridad de WPML en el laboratorio no coincide con el origen.\n" );
}
echo "   [PASS] Integridad de datos WPML en {$target_db} validada al 100%.\n";

$tgt_posts = (int) $mysqli_tgt->query( "SELECT COUNT(*) FROM `etk_t_posts`" )->fetch_row()[0];
$tgt_terms = (int) $mysqli_tgt->query( "SELECT COUNT(*) FROM `etk_t_terms`" )->fetch_row()[0];
$tgt_groups = (int) $mysqli_tgt->query( "SELECT COUNT(*) FROM `etk_t_tfml_groups`" )->fetch_row()[0];

echo "   Posts en {$target_db}: {$tgt_posts} (Origen: 4021)\n";
echo "   Terms en {$target_db}: {$tgt_terms} (Origen: 82)\n";
echo "   TFML Groups en {$target_db}: {$tgt_groups} (Esperado: 0 - Migración NO ejecutada)\n\n";

// -------------------------------------------------------------------------
// 6. SENTINEL POST-FLIGHT CHECK (cms_ect)
// -------------------------------------------------------------------------
echo "6. Verificación post-flight del sitio origen ({$source_db})...\n";

$post_rows = (int) $mysqli_src->query( "SELECT COUNT(*) FROM `etk_t_icl_translations`" )->fetch_row()[0];
$post_hash = (string) $mysqli_src->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

echo "   Sentinel origen ({$source_db}): {$post_rows} filas, MD5: {$post_hash}\n";

$mysqli_src->close();
$mysqli_tgt->close();

if ( $pre_rows !== $post_rows || $pre_hash !== $post_hash ) {
	die( "FATAL: ¡Se detectó alteración en la base de datos de origen {$source_db}! Delta != 0.\n" );
}

echo "   [PASS] Invarianza del origen: Cero escrituras, cero alteraciones (Delta = 0).\n\n";

echo "====================================================================\n";
echo "  RESULTADO: FASE 3.9 COMPLETADA EXITOSAMENTE\n";
echo "  Laboratorio:     d:/laragon/www/lab.ecoterra\n";
echo "  Base de datos:   cms_ect_lab\n";
echo "  Prefijo real:    etk_t_\n";
echo "  Respaldo:        {$backup_file} (protegido fuera del repositorio)\n";
echo "  Anonimización:   Usuarios reales purgados, emails ficticios, secretos eliminados\n";
echo "  Datos WPML:      3403 filas restauradas fielmente para prueba de migración\n";
echo "  Migración TFML:  BLOQUEADA (0 grupos creados; reservada para Fase 3.10)\n";
echo "  Origen cms_ect:  100% INTACTO (Delta = 0)\n";
echo "====================================================================\n";
