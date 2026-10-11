<?php
/**
 * Preflight Discovery for Phase 3.9
 *
 * Inspects real prefixes, tables, engines, and limits on cms_ect (strictly read-only).
 */

declare( strict_types=1 );

$source_db = 'cms_ect';
$lab_db    = 'cms_ect_lab';

$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';

$mysqli = new mysqli( $db_host, $db_user, $db_pass, $source_db );
if ( $mysqli->connect_error ) {
	die( "Error de conexión: " . $mysqli->connect_error . "\n" );
}

echo "=== PREFLIGHT DISCOVERY (cms_ect) ===\n";

// 1. Get tables in cms_ect.
$res = $mysqli->query( "SHOW TABLES LIKE 'etk_t_%'" );
$etk_tables = array();
while ( $row = $res->fetch_row() ) {
	$etk_tables[] = $row[0];
}

echo "Total tablas con prefijo etk_t_: " . count( $etk_tables ) . "\n";

// 2. Identify WPML tables.
$res_wpml = $mysqli->query( "SHOW TABLES LIKE 'etk_t_icl_%'" );
$wpml_tables = array();
while ( $row = $res_wpml->fetch_row() ) {
	$wpml_tables[] = $row[0];
}

echo "Total tablas WPML (etk_t_icl_*): " . count( $wpml_tables ) . "\n";
foreach ( $wpml_tables as $t ) {
	echo "  - {$t}\n";
}

// 3. Check icl_translations row count and MD5.
$res_count = $mysqli->query( "SELECT COUNT(*) FROM `etk_t_icl_translations`" );
$row_count = (int) $res_count->fetch_row()[0];
$mysqli->query( "SET SESSION group_concat_max_len = 10000000" );
$res_hash = $mysqli->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
);
$hash = $res_hash->fetch_row()[0];

echo "\nSentinel etk_t_icl_translations:\n";
echo "  Rows: {$row_count} (Esperado: 3403)\n";
echo "  MD5:  {$hash} (Esperado: 4241ca7e7ec6399a594537cb04790c10)\n";

// 4. Core tables existence and counts.
$core_tables = array(
	'etk_t_posts',
	'etk_t_postmeta',
	'etk_t_terms',
	'etk_t_term_taxonomy',
	'etk_t_term_relationships',
	'etk_t_options',
);

echo "\nTablas Core:\n";
foreach ( $core_tables as $ct ) {
	$c_res = $mysqli->query( "SELECT COUNT(*) FROM `{$ct}`" );
	$cnt   = $c_res ? (int) $c_res->fetch_row()[0] : -1;
	echo "  {$ct}: {$cnt} registros\n";
}

// 5. MySQL limits.
$packet = $mysqli->query( "SHOW VARIABLES LIKE 'max_allowed_packet'" )->fetch_assoc()['Value'];
$engines = $mysqli->query( "SELECT ENGINE, COUNT(*) FROM information_schema.tables WHERE table_schema = '{$source_db}' GROUP BY ENGINE" );
echo "\nLímites de MySQL y Motores:\n";
echo "  max_allowed_packet: " . ( (int) $packet / ( 1024 * 1024 ) ) . " MB\n";
while ( $e_row = $engines->fetch_row() ) {
	echo "  Motor {$e_row[0]}: {$e_row[1]} tablas\n";
}

$mysqli->close();
