<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.24.
 *
 * Directiva: DT-TFML-054
 * Alcance: Auditoría integral post-migración y diagnóstico de brechas funcionales:
 *          1. Congelamiento del baseline post-migración (120 grupos / 133 elementos, 100% universo importable).
 *          2. Diagnóstico de la ausencia del tema ecoCMS en laboratorio.
 *          3. Diagnóstico específico del TRID 197 (página de inicio bilingüe/trilingüe bloqueada por idioma francés).
 *          4. Auditoría técnica de los 129 grupos no importables (52 bloqueados, 24 omitidos, 53 huérfanos).
 *          5. Diagnóstico de integraciones (100% Elementor, 0% WPBakery).
 *          6. Comprobaciones HTTP no destructivas de enrutamiento perimetral.
 *          7. Invarianza absoluta de centinelas (Delta = 0).
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/ExternalPluginIsolationGuard.php';

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;

global $wpdb;
$original_wpdb = $wpdb;

$assertions_passed = 0;

/**
 * Assertion helper.
 *
 * @param bool   $condition Condition to evaluate.
 * @param string $message   Description.
 * @return void
 */
function assert_true( bool $condition, string $message ): void {
	global $assertions_passed;
	if ( ! $condition ) {
		echo " [FAIL] {$message}\n";
		exit( 1 );
	}
	++$assertions_passed;
	echo " [PASS] {$message}\n";
}

echo "====================================================================\n";
echo "  TF MULTILINGUAL — AUDITORÍA INTEGRAL POST-MIGRACIÓN (FASE 3.24)\n";
echo "  Directiva: DT-TFML-054 (Diagnóstico de Brechas Funcionales)\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: CENTINELA WPML EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "--- Pre-Flight: Centinela WPML en Base de Datos de Origen (cms_ect) ---\n";

$sentinel_table = 'etk_t_icl_translations';
$original_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$pre_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$pre_hash = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: 3403)\n";
echo "  MD5:  {$pre_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: Conteo WPML en cms_ect es exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: Checksum MD5 en cms_ect coincide exactamente' );

// Respaldo verificado fuera del webroot.
$backup_file = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
assert_true( file_exists( $backup_file ), 'Archivo de respaldo restaurable existe fuera del webroot' );
$backup_hash = hash_file( 'sha256', $backup_file );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_hash, 'Huella SHA-256 del respaldo certificada' );

// Aislamiento de plugins externos.
$isolation_guard = new TF\Multilingual\Scratch\ExternalPluginIsolationGuard();
$isolation_guard->isolate();

// Configurar conexión al entorno de laboratorio cms_ect_lab.
$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';
$lab_db  = 'cms_ect_lab';

$mysqli_lab = new mysqli( $db_host, $db_user, $db_pass, $lab_db );
assert_true( ! $mysqli_lab->connect_error, "Conexión MySQL directa a laboratorio '{$lab_db}' exitosa" );

$lab_wpdb = new wpdb( $db_user, $db_pass, $lab_db, $db_host );
$lab_wpdb->set_prefix( 'etk_t_' );
$lab_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$GLOBALS['wpdb'] = $lab_wpdb;

$plugin = Plugin::get_instance();
$plugin->init();
$registry = $plugin->get_language_registry();

// -------------------------------------------------------------------------
// 2. CONTROL 1: CONGELAMIENTO DEL BASELINE POST-MIGRACIÓN (120 GRUPOS / 133 ELEMENTOS)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Congelamiento del Baseline Post-Migración (120 Grupos / 133 Elementos) ---\n";

$groups_count   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 120 === $groups_count, "BD laboratorio conserva congelados exactamente 120 grupos en etk_t_tfml_groups ({$groups_count})" );
assert_true( 133 === $elements_count, "BD laboratorio conserva congelados exactamente 133 elementos en etk_t_tfml_group_elements ({$elements_count})" );

// Integridad referencial: Cero duplicados en tablas TFML:
$dup_g = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM (SELECT canonical_element_id FROM `etk_t_tfml_groups` GROUP BY element_type, canonical_element_id HAVING COUNT(*) > 1) as d' )->fetch_row()[0];
$dup_e = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM (SELECT element_type, element_id FROM `etk_t_tfml_group_elements` GROUP BY element_type, element_id HAVING COUNT(*) > 1) as d' )->fetch_row()[0];
assert_true( 0 === $dup_g, 'Congelamiento: Cero grupos duplicados en etk_t_tfml_groups' );
assert_true( 0 === $dup_e, 'Congelamiento: Cero elementos duplicados en etk_t_tfml_group_elements' );

// Tablas secundarias TFML permanecen limpias en 0:
$cnt_media  = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_media_translations`' )->fetch_row()[0];
$cnt_str    = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_strings`' )->fetch_row()[0];
$cnt_str_tr = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_string_translations`' )->fetch_row()[0];
assert_true( 0 === $cnt_media, 'Tabla media_translations conserva 0 registros' );
assert_true( 0 === $cnt_str, 'Tabla strings conserva 0 registros' );
assert_true( 0 === $cnt_str_tr, 'Tabla string_translations conserva 0 registros' );

// Checkpoint y locks oficiales:
$checkpoint_repo = new MigrationCheckpointRepository();
$lock_manager    = new MigrationExecutionLock();
$cp_current      = $checkpoint_repo->get( 'wpml' );
assert_true( null !== $cp_current && $cp_current->is_completed(), 'Checkpoint oficial de migración se encuentra en estado COMPLETED' );
assert_true( 18 === $cp_current->get_processed_items(), 'Checkpoint oficial registra 18 ítems procesados (Lote 10)' );
assert_true( 34840 === $cp_current->get_last_processed_trid(), 'Checkpoint oficial registra TRID 34840 como último procesado' );
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'Lock de migración liberado y disponible' );

// Centinela interno WPML en cms_ect_lab:
$lab_wpml_rows = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$lab_wpml_hash = (string) $lab_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);
assert_true( 3403 === $lab_wpml_rows, 'WPML en cms_ect_lab contiene exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $lab_wpml_hash, 'WPML en cms_ect_lab estrictamente intacto (MD5 4241ca7e...)' );
$GLOBALS['wpdb'] = $lab_wpdb;

// -------------------------------------------------------------------------
// 3. CONTROL 2: DIAGNÓSTICO DE LA AUSENCIA DEL TEMA ecoCMS
// -------------------------------------------------------------------------
echo "\n--- Control 2: Diagnóstico de la Ausencia del Tema ecoCMS ---\n";

$opt_template   = (string) $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'template'" )->fetch_row()[0];
$opt_stylesheet = (string) $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'stylesheet'" )->fetch_row()[0];
$opt_current_th = (string) $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'current_theme'" )->fetch_row()[0];

echo "Configuración de tema en BD laboratorio (etk_t_options):\n";
echo "  template:      {$opt_template} (Expected: ecoCMS)\n";
echo "  stylesheet:    {$opt_stylesheet} (Expected: ecoCMS)\n";
echo "  current_theme: {$opt_current_th} (Expected: ecoCMS)\n\n";

assert_true( 'ecoCMS' === $opt_template, "BD laboratorio tiene configurada la opción template = 'ecoCMS'" );
assert_true( 'ecoCMS' === $opt_stylesheet, "BD laboratorio tiene configurada la opción stylesheet = 'ecoCMS'" );
assert_true( 'ecoCMS' === $opt_current_th, "BD laboratorio tiene configurada la opción current_theme = 'ecoCMS'" );

// Comprobar ausencia física en laboratorio vs presencia en origen:
$lab_theme_dir    = 'D:/laragon/www/lab.ecoterra/wp-content/themes/ecoCMS';
$origin_theme_dir = 'D:/laragon/www/cms.ecoterra/wp-content/themes/ecoCMS';

assert_true( ! is_dir( $lab_theme_dir ), 'Laboratorio: Directorio del tema ecoCMS no existe físicamente en lab.ecoterra (causa raíz del estado BLOCKED)' );
assert_true( is_dir( $origin_theme_dir ), 'Origen: Directorio del tema ecoCMS existe físicamente en cms.ecoterra' );
assert_true( file_exists( $origin_theme_dir . '/style.css' ), 'Origen: Archivo style.css del tema ecoCMS disponible' );
assert_true( file_exists( $origin_theme_dir . '/functions.php' ), 'Origen: Archivo functions.php del tema ecoCMS disponible' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: DIAGNÓSTICO ESPECÍFICO DEL TRID 197 (PÁGINA PRINCIPAL)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Diagnóstico Específico del TRID 197 (Página Principal) ---\n";

$opt_show_on_front = (string) $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'show_on_front'" )->fetch_row()[0];
$opt_page_on_front = (int) $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'page_on_front'" )->fetch_row()[0];

assert_true( 'page' === $opt_show_on_front, "WordPress Core tiene configurado show_on_front = 'page'" );
assert_true( 197 === $opt_page_on_front, 'WordPress Core tiene configurada la página ID 197 como página de inicio (page_on_front)' );

// Consultar miembros del TRID 197 en la tabla icl_translations del laboratorio:
$res_197 = $mysqli_lab->query( 'SELECT element_id, language_code, source_language_code FROM `etk_t_icl_translations` WHERE trid = 197 ORDER BY translation_id ASC' );
$trid_197_members = array();
while ( $r = $res_197->fetch_assoc() ) {
	$trid_197_members[ $r['language_code'] ] = (int) $r['element_id'];
}

echo "Miembros del TRID 197 en WPML:\n";
foreach ( $trid_197_members as $lang => $pid ) {
	echo "  - Idioma {$lang}: Post ID {$pid}\n";
}

assert_true( isset( $trid_197_members['es'] ) && 197 === $trid_197_members['es'], 'TRID 197 contiene página canónica en español (ID 197)' );
assert_true( isset( $trid_197_members['en'] ) && 8356 === $trid_197_members['en'], 'TRID 197 contiene traducción en inglés (ID 8356)' );
assert_true( isset( $trid_197_members['fr'] ) && 8839 === $trid_197_members['fr'], 'TRID 197 contiene traducción en francés (ID 8839)' );

// Comprobar existencia física de los 3 posts en WordPress Core:
$validator = new WordPressElementValidator();
assert_true( $validator->exists( 'post', 'page', 197 ), 'Página 197 (Inicio, es) existe físicamente en Core' );
assert_true( $validator->exists( 'post', 'page', 8356 ), 'Página 8356 (Home, en) existe físicamente en Core' );
assert_true( $validator->exists( 'post', 'page', 8839 ), 'Página 8839 (Accueil, fr) existe físicamente en Core' );

// Comprobar que el TRID 197 NO está migrado a TFML (debido a regla BLOCKED_BY_LANGUAGE):
$res_tfml_197 = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements` WHERE element_type = "post" AND element_id IN (197, 8356, 8839)' )->fetch_row()[0];
assert_true( 0 === $res_tfml_197, 'TRID 197 no está migrado a etk_t_tfml_group_elements (congelado como BLOCKED_BY_LANGUAGE)' );

// Comprobar que ContentTranslationResolver devuelve null para la traducción de la home hacia inglés:
$group_repo = new TranslationGroupRepository( $lab_wpdb, $registry );
$resolver   = new ContentTranslationResolver( $group_repo, $registry );
$res_home   = $resolver->resolve_element_id( 'post', 197, 'en' );
assert_true( null === $res_home, 'Resolución de home 197 hacia EN devuelve null (causa raíz del 404 en /en/)' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: AUDITORÍA DE LOS 129 GRUPOS NO IMPORTABLES
// -------------------------------------------------------------------------
echo "\n--- Control 4: Auditoría de los 129 Grupos No Importables ---\n";

$planner      = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$global_plan  = $planner->generate_plan( 0, 0, false );
$plan_summary = $global_plan->get_summary();

echo "Resumen Global del Plan de Migración:\n";
echo "  Total grupos en WPML:      {$plan_summary['total_groups']} (Expected: 249)\n";
echo "  Grupos IMPORTABLE:         {$plan_summary['importable_groups']} (Expected: 120 — 100% Migrados)\n";
echo "  Grupos BLOCKED_BY_LANGUAGE:{$plan_summary['blocked_language_groups']} (Expected: 52)\n";
echo "  Grupos SKIPPED:            {$plan_summary['skipped_groups']} (Expected: 24)\n";
echo "  Grupos ORPHAN:             {$plan_summary['orphan_groups']} (Expected: 53)\n\n";

assert_true( 249 === $plan_summary['total_groups'], 'Plan global analiza exactamente 249 grupos en WPML' );
assert_true( 120 === $plan_summary['importable_groups'], 'Exactamente 120 grupos clasificados como IMPORTABLE (100% migrados en los 10 lotes)' );
assert_true( 52 === $plan_summary['blocked_language_groups'], 'Exactamente 52 grupos clasificados como BLOCKED_BY_LANGUAGE' );
assert_true( 24 === $plan_summary['skipped_groups'], 'Exactamente 24 grupos clasificados como SKIPPED' );
assert_true( 53 === $plan_summary['orphan_groups'], 'Exactamente 53 grupos clasificados como ORPHAN' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: DIAGNÓSTICO DE INTEGRACIONES (ELEMENTOR VS WPBAKERY)
// -------------------------------------------------------------------------
echo "\n--- Control 5: Diagnóstico de Integraciones (Elementor vs WPBakery) ---\n";

$cnt_wpb = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `etk_t_postmeta` WHERE meta_key LIKE '%vc_%' OR meta_key LIKE '%wpb%'" )->fetch_row()[0];
assert_true( 0 === $cnt_wpb, 'WPBakery: 0 registros de metadatos vc_ o wpb encontrados en etk_t_postmeta (WPBakery no aplica al sitio)' );

$cnt_elem = (int) $mysqli_lab->query( "SELECT COUNT(*) FROM `etk_t_postmeta` WHERE meta_key = '_elementor_data'" )->fetch_row()[0];
assert_true( $cnt_elem > 0, "Elementor: Confirmada presencia activa de Elementor en el sitio ({$cnt_elem} posts con _elementor_data)" );

// -------------------------------------------------------------------------
// 7. CONTROL 6: COMPROBACIONES HTTP NO DESTRUCTIVAS
// -------------------------------------------------------------------------
echo "\n--- Control 6: Comprobaciones HTTP No Destructivas ---\n";

/**
 * Perform lab HTTP GET.
 *
 * @param string $path URL path.
 * @return array Response data.
 */
function lab_http_get( string $path ): array {
	$url = 'http://lab.ecoterra.test' . $path;
	$ch  = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RESOLVE, array( 'lab.ecoterra.test:80:127.0.0.1' ) );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HEADER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	$response    = curl_exec( $ch );
	$status_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );

	return array(
		'status'  => $status_code,
		'headers' => is_string( $response ) ? substr( $response, 0, $header_size ) : '',
		'body'    => is_string( $response ) ? substr( $response, $header_size ) : '',
	);
}

$http_root = lab_http_get( '/' );
assert_true( 200 === $http_root['status'], 'HTTP: Raíz (/) responde HTTP 200 OK' );
assert_true( strpos( $http_root['headers'], 'noindex' ) !== false, 'HTTP: Cabecera X-Robots-Tag noindex activa en laboratorio' );

$http_en = lab_http_get( '/en/' );
assert_true( 404 === $http_en['status'], 'HTTP: Prefijo /en/ responde 404 Not Found (home inglés no migrado por bloqueo de idioma en TRID 197)' );
assert_true( 500 !== $http_en['status'], 'HTTP: Ausencia de error HTTP 500 en /en/' );

$http_fr = lab_http_get( '/fr/' );
assert_true( 404 === $http_fr['status'], 'HTTP: Prefijo /fr/ responde 404 Not Found (idioma inactivo, rechazo limpio sin aliasing a default)' );
assert_true( 500 !== $http_fr['status'], 'HTTP: Ausencia de error HTTP 500 en /fr/' );

// -------------------------------------------------------------------------
// 8. CONTROL 7: INVARIANZA FINAL Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 7: Invarianza Final y Centinela en Origen (cms_ect) ---\n";

$post_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "\nSentinel WPML Post-Flight (cms_ect):\n";
echo "  Rows: {$post_rows} (Expected: 3403)\n";
echo "  MD5:  {$post_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.24 AUDITORÍA POST-MIGRACIÓN Y BRECHAS: EXITOSA (PASS)\n";
echo "====================================================================\n";
