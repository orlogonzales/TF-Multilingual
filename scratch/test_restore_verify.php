<?php
/**
 * Test Restore Verification for Phase 3.10
 *
 * Verifies that the SQL backup in d:\laragon\backup\tfml_staging\ can be restored
 * faithfully into an isolated temporary database without errors, confirming
 * disaster recovery readiness and data integrity.
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';

$backup_file = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
$temp_db     = 'cms_ect_restore_verify';

echo "====================================================================\n";
echo "  TF MULTILINGUAL — FASE 3.10: PRUEBA DE RESTAURACION VERIFICABLE\n";
echo "====================================================================\n\n";

if ( ! file_exists( $backup_file ) ) {
	die( "FATAL: Archivo de respaldo no encontrado en {$backup_file}\n" );
}

$file_sha256 = hash_file( 'sha256', $backup_file );
echo "Respaldo: {$backup_file}\n";
echo "SHA-256:  {$file_sha256}\n";
echo "Tamaño:   " . round( filesize( $backup_file ) / 1024 / 1024, 2 ) . " MB\n\n";

$mysqli = new mysqli( $db_host, $db_user, $db_pass );
if ( $mysqli->connect_error ) {
	die( "FATAL: Error conectando a MySQL: " . $mysqli->connect_error . "\n" );
}

// Sentinel check on cms_ect.
$mysqli->select_db( 'cms_ect' );
$mysqli->query( 'SET SESSION group_concat_max_len = 10000000' );
$pre_rows = (int) $mysqli->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$pre_hash = (string) $mysqli->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

echo "Sentinel Origen (cms_ect):\n";
echo "  Rows: {$pre_rows}, MD5: {$pre_hash}\n\n";

if ( 3403 !== $pre_rows || '4241ca7e7ec6399a594537cb04790c10' !== $pre_hash ) {
	die( "FATAL: Centinela origen alterado.\n" );
}

// 1. Create clean temporary DB.
echo "1. Creando base de datos temporal aislada {$temp_db}...\n";
$mysqli->query( "DROP DATABASE IF EXISTS `{$temp_db}`" );
if ( ! $mysqli->query( "CREATE DATABASE `{$temp_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
	die( "FATAL: No se pudo crear la base de datos temporal: " . $mysqli->error . "\n" );
}

$mysqli->select_db( $temp_db );
$mysqli->query( 'SET FOREIGN_KEY_CHECKS = 0' );
$mysqli->query( "SET sql_mode = ''" );

// 2. Stream restore.
echo "2. Restaurando snapshot en {$temp_db}...\n";
$start_time = microtime( true );

$fh = fopen( $backup_file, 'r' );
if ( ! $fh ) {
	die( "FATAL: No se pudo abrir {$backup_file}\n" );
}

$query_buffer = '';
$statements_executed = 0;
$errors = array();

while ( ! feof( $fh ) ) {
	$line = fgets( $fh );
	if ( false === $line ) {
		break;
	}

	$trimmed = trim( $line );
	if ( '' === $trimmed || str_starts_with( $trimmed, '--' ) || str_starts_with( $trimmed, '/*' ) ) {
		continue;
	}

	$query_buffer .= $line;

	if ( str_ends_with( $trimmed, ';' ) ) {
		if ( ! $mysqli->query( $query_buffer ) ) {
			$errors[] = substr( $mysqli->error, 0, 100 ) . ' in: ' . substr( $query_buffer, 0, 50 );
		} else {
			++$statements_executed;
		}
		$query_buffer = '';
	}
}
fclose( $fh );

$elapsed_time = round( microtime( true ) - $start_time, 2 );
echo "   Sentencias ejecutadas: {$statements_executed}\n";
echo "   Errores: " . count( $errors ) . "\n";
echo "   Tiempo transcurrido: {$elapsed_time} segundos\n\n";

if ( count( $errors ) > 0 ) {
	echo "FATAL: Hubo errores en la restauración:\n";
	foreach ( $errors as $err ) {
		echo "  - {$err}\n";
	}
	exit( 1 );
}

// 3. Verify integrity of restored temporary database.
echo "3. Verificando integridad de la restauración en {$temp_db}...\n";
$res_tables = $mysqli->query( "SHOW TABLES LIKE 'etk_t_%'" );
$table_count = $res_tables->num_rows;
echo "   Tablas restauradas: {$table_count}\n";

$restored_rows = (int) $mysqli->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$mysqli->query( 'SET SESSION group_concat_max_len = 10000000' );
$restored_hash = (string) $mysqli->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

$restored_posts = (int) $mysqli->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0];
$restored_terms = (int) $mysqli->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0];

echo "   etk_t_icl_translations: {$restored_rows} filas, MD5: {$restored_hash}\n";
echo "   etk_t_posts:            {$restored_posts} filas\n";
echo "   etk_t_terms:            {$restored_terms} filas\n\n";

if ( 3403 !== $restored_rows || '4241ca7e7ec6399a594537cb04790c10' !== $restored_hash ) {
	die( "FATAL: Checksum de WPML no coincide tras la restauración.\n" );
}
if ( 4021 !== $restored_posts || 82 !== $restored_terms ) {
	die( "FATAL: Conteo de contenido Core no coincide tras la restauración.\n" );
}

echo " [PASS] Restauración verificada al 100% sin discrepancias.\n\n";

// 4. Cleanup temporary database.
echo "4. Limpiando base de datos temporal {$temp_db}...\n";
$mysqli->query( "DROP DATABASE `{$temp_db}`" );
echo "   Base de datos temporal eliminada correctamente.\n\n";

// 5. Post-flight check on cms_ect.
$mysqli->select_db( 'cms_ect' );
$post_rows = (int) $mysqli->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$post_hash = (string) $mysqli->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];

if ( $pre_rows !== $post_rows || $pre_hash !== $post_hash ) {
	die( "FATAL: Origen cms_ect sufrió alteraciones.\n" );
}

echo "Sentinel Post-Flight cms_ect: Inalterado (Delta = 0).\n";
echo "====================================================================\n";
echo "  PRUEBA DE RESTAURACION CERTIFICADA EXITOSAMENTE\n";
echo "====================================================================\n";

$mysqli->close();
