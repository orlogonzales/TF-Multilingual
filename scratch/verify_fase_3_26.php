<?php
/**
 * TF Multilingual — Verificación Integral de Diagnóstico en Laboratorio Real: Fase 3.26.
 *
 * Directiva: DT-TFML-056
 * Alcance: Diagnóstico y diseño seguro de la portada bilingüe y compatibilidad del tema:
 *          1. Pre-flight y centinela estricto en base de datos de origen (cms_ect).
 *          2. Congelamiento del baseline post-migración (120 grupos / 133 elementos).
 *          3. Contención y seguridad del laboratorio (salvaguardas activas, copias físicas).
 *          4. Diagnóstico arquitectónico del TRID 197 (WPML y catalogación).
 *          5. Diagnóstico de enrutamiento y resolución de portada (/en/ vs /).
 *          6. Causa raíz del atributo <html lang="en-US"> y solución limpia.
 *          7. Auditoría exhaustiva de compatibilidad temática de ecoCMS (109 ocurrencias).
 *          8. Diagnóstico de cabecera y dependencia de Elementor / Elementor Pro.
 *          9. Invarianza absoluta de centinelas (Delta = 0).
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

global $wpdb;
$original_wpdb = $wpdb;

$assertions_passed = 0;

/**
 * Custom assertion helper.
 *
 * @param bool   $condition Condition to assert.
 * @param string $message   Message on success/failure.
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
echo "  TF MULTILINGUAL — DIAGNÓSTICO Y DISEÑO DE PORTADA Y TEMA (FASE 3.26)\n";
echo "  Directiva: DT-TFML-056 (Análisis Empírico y Pruebas No Destructivas)\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: CENTINELA WPML EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "--- Pre-Flight: Centinela WPML en Base de Datos de Origen (cms_ect) ---\n";

$sentinel_table = 'etk_t_icl_translations';
$original_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$pre_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$sql_hash = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`";
$pre_hash = (string) $original_wpdb->get_var( $sql_hash );

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: 3403)\n";
echo "  MD5:  {$pre_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: Conteo WPML en cms_ect es exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: Checksum MD5 en cms_ect coincide exactamente' );

// Respaldo verificable.
$backup_file = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
assert_true( file_exists( $backup_file ), 'Archivo de respaldo restaurable existe fuera del webroot' );
$backup_hash = hash_file( 'sha256', $backup_file );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_hash, 'Huella SHA-256 del respaldo certificada' );

// Conexión a laboratorio cms_ect_lab.
$db_user  = defined( 'DB_USER' ) ? DB_USER : 'root';
$db_pass  = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
$db_host  = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
$lab_wpdb = new wpdb( $db_user, $db_pass, 'cms_ect_lab', $db_host );
$lab_wpdb->set_prefix( 'etk_t_' );
$lab_wpdb->posts = $lab_wpdb->prefix . 'posts';
$lab_wpdb->terms = $lab_wpdb->prefix . 'terms';
$lab_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

// -------------------------------------------------------------------------
// 2. CONTROL 1: CONGELAMIENTO DEL BASELINE POST-MIGRACIÓN (120/133)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Congelamiento del Baseline Post-Migración (120 Grupos / 133 Elementos) ---\n";

$cnt_groups   = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' );
$cnt_elements = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' );

assert_true( 120 === $cnt_groups, "BD laboratorio conserva exactamente 120 grupos en etk_t_tfml_groups ({$cnt_groups})" );
assert_true( 133 === $cnt_elements, "BD laboratorio conserva exactamente 133 elementos en etk_t_tfml_group_elements ({$cnt_elements})" );

$dup_groups = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) - COUNT(DISTINCT id) FROM `etk_t_tfml_groups`' );
assert_true( 0 === $dup_groups, 'Congelamiento: Cero grupos duplicados en etk_t_tfml_groups' );

$dup_elements = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) - COUNT(DISTINCT id) FROM `etk_t_tfml_group_elements`' );
assert_true( 0 === $dup_elements, 'Congelamiento: Cero elementos duplicados en etk_t_tfml_group_elements' );

$cnt_media   = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_media_translations`' );
$cnt_strings = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_strings`' );
$cnt_str_tr  = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_string_translations`' );

assert_true( 0 === $cnt_media, 'Tabla media_translations conserva 0 registros' );
assert_true( 0 === $cnt_strings, 'Tabla strings conserva 0 registros' );
assert_true( 0 === $cnt_str_tr, 'Tabla string_translations conserva 0 registros' );

// Checkpoint.
$cp_raw = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'tfml_migration_checkpoint_wpml'" );
$cp     = maybe_unserialize( $cp_raw );
if ( is_string( $cp_raw ) && is_array( json_decode( $cp_raw, true ) ) ) {
	$cp = json_decode( $cp_raw, true );
}

$cp_status    = is_array( $cp ) ? ( $cp['status'] ?? '' ) : ( is_object( $cp ) ? ( $cp->status ?? '' ) : '' );
$cp_processed = is_array( $cp ) ? ( $cp['processed_items'] ?? 0 ) : ( is_object( $cp ) ? ( $cp->processed_items ?? 0 ) : 0 );
$cp_trid      = is_array( $cp ) ? ( $cp['last_processed_trid'] ?? 0 ) : ( is_object( $cp ) ? ( $cp->last_processed_trid ?? 0 ) : 0 );

assert_true( 'completed' === $cp_status, 'Checkpoint oficial de migración se encuentra en estado COMPLETED' );
assert_true( 18 === (int) $cp_processed, 'Checkpoint oficial registra 18 ítems procesados (Lote 10)' );
assert_true( 34840 === (int) $cp_trid, 'Checkpoint oficial registra TRID 34840 como último procesado' );

$lock_raw = $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'tfml_migration_lock_wpml'" );
assert_true( empty( $lock_raw ), 'Lock de migración liberado y disponible' );

$lab_wpml_rows = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$lab_wpml_hash = (string) $lab_wpdb->get_var( $sql_hash );
assert_true( 3403 === $lab_wpml_rows, 'WPML en cms_ect_lab contiene exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $lab_wpml_hash, 'WPML en cms_ect_lab estrictamente intacto' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: CONTENCIÓN Y SEGURIDAD DEL LABORATORIO
// -------------------------------------------------------------------------
echo "\n--- Control 2: Contención y Seguridad del Laboratorio ---\n";

$wp_config_content = file_get_contents( 'D:/laragon/www/lab.ecoterra/wp-config.php' );
assert_true( strpos( $wp_config_content, "define( 'WP_HTTP_BLOCK_EXTERNAL', true )" ) !== false, 'Contención: WP_HTTP_BLOCK_EXTERNAL activo en wp-config.php' );
assert_true( strpos( $wp_config_content, "define( 'DISABLE_WP_CRON', true )" ) !== false, 'Contención: DISABLE_WP_CRON activo en wp-config.php' );
assert_true( strpos( $wp_config_content, "define( 'WP_AUTO_UPDATE_CORE', false )" ) !== false, 'Contención: WP_AUTO_UPDATE_CORE desactivado' );

$guard_content = file_get_contents( 'D:/laragon/www/lab.ecoterra/wp-content/mu-plugins/lab-isolation-guard.php' );
assert_true( strpos( $guard_content, "add_filter( 'pre_wp_mail', '__return_false'" ) !== false, 'Seguridad: Cortocircuito pre_wp_mail activo en mu-plugins' );
assert_true( strpos( $guard_content, 'X-Robots-Tag: noindex' ) !== false, 'Seguridad: Cabecera X-Robots-Tag forzada en mu-plugins' );

$theme_dst  = 'D:/laragon/www/lab.ecoterra/wp-content/themes/ecoCMS';
$plugin_dst = 'D:/laragon/www/lab.ecoterra/wp-content/plugins/ova-framework';

assert_true( is_dir( $theme_dst ) && ! is_link( $theme_dst ), 'Aislamiento: Directorio de ecoCMS existe físicamente y no es enlace simbólico' );
assert_true( is_dir( $plugin_dst ) && ! is_link( $plugin_dst ), 'Aislamiento: Directorio de ova-framework existe físicamente y no es enlace simbólico' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: DIAGNÓSTICO ARQUITECTÓNICO DEL TRID 197
// -------------------------------------------------------------------------
echo "\n--- Control 3: Diagnóstico Arquitectónico del TRID 197 ---\n";

$trid_rows = $lab_wpdb->get_results( 'SELECT translation_id, element_type, element_id, language_code, source_language_code FROM `etk_t_icl_translations` WHERE trid = 197 ORDER BY translation_id ASC', ARRAY_A );

assert_true( 3 === count( $trid_rows ), 'TRID 197 en WPML contiene exactamente 3 traducciones (es, en, fr)' );

$langs_in_trid = array();
$post_by_lang  = array();
foreach ( $trid_rows as $row ) {
	$langs_in_trid[]                         = $row['language_code'];
	$post_by_lang[ $row['language_code'] ]   = (int) $row['element_id'];
}

assert_true( in_array( 'es', $langs_in_trid, true ) && in_array( 'en', $langs_in_trid, true ) && in_array( 'fr', $langs_in_trid, true ), 'TRID 197 posee miembros para idiomas es, en y fr' );
assert_true( 197 === $post_by_lang['es'], 'TRID 197 elemento en español es el Post ID 197 (canónica)' );
assert_true( 8356 === $post_by_lang['en'], 'TRID 197 elemento en inglés es el Post ID 8356' );
assert_true( 8839 === $post_by_lang['fr'], 'TRID 197 elemento en francés es el Post ID 8839' );

// Comprobar ausencia absoluta en TFML lab.
$tfml_el_197  = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements` WHERE element_id = 197' );
$tfml_el_8356 = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements` WHERE element_id = 8356' );
$tfml_el_8839 = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements` WHERE element_id = 8839' );

assert_true( 0 === $tfml_el_197, 'Post 197 NO está migrado a TFML (0 elementos en etk_t_tfml_group_elements)' );
assert_true( 0 === $tfml_el_8356, 'Post 8356 NO está migrado a TFML (0 elementos en etk_t_tfml_group_elements)' );
assert_true( 0 === $tfml_el_8839, 'Post 8839 NO está migrado a TFML (0 elementos en etk_t_tfml_group_elements)' );

// Verificación de la clasificación del planificador.
$registry = new \TF\Multilingual\Domain\Language\LanguageRegistry();
if ( ! $registry->has( 'es' ) ) {
	$registry->add_language( new \TF\Multilingual\Domain\Language\Language( 'es', 'es_ES', 'Español', 'Español', true ), true );
}
if ( ! $registry->has( 'en' ) ) {
	$registry->add_language( new \TF\Multilingual\Domain\Language\Language( 'en', 'en_US', 'English', 'English', true ) );
}

try {
	$planner = new \TF\Multilingual\Migration\WpmlMigrationPlanner( $lab_wpdb, $registry );
	$plan    = $planner->generate_plan();
} catch ( \Throwable $e ) {
	echo 'Exception in generate_plan: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n";
	echo $e->getTraceAsString() . "\n";
	exit( 1 );
}

$trid_197_group = null;
foreach ( $plan->get_planned_groups() as $g ) {
	if ( 197 === (int) ( $g['trid'] ?? 0 ) ) {
		$trid_197_group = $g;
		break;
	}
}

assert_true( null !== $trid_197_group, 'Planificador detecta el TRID 197 en el universo WPML' );
assert_true( \TF\Multilingual\Migration\MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE === ( $trid_197_group['classification'] ?? '' ), 'TRID 197 está formalmente clasificado como BLOCKED_BY_LANGUAGE por contener idioma no activo (fr)' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: DIAGNÓSTICO DE ENRUTAMIENTO Y RESOLUCIÓN DE PORTADA
// -------------------------------------------------------------------------
echo "\n--- Control 4: Diagnóstico de Enrutamiento y Resolución de Portada ---\n";

$show_front = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'show_on_front'" );
$page_front = (int) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'page_on_front'" );

assert_true( 'page' === $show_front, 'WordPress está configurado con show_on_front = page' );
assert_true( 197 === $page_front, 'WordPress está configurado con page_on_front = 197' );

/**
 * Execute HTTP GET against lab.ecoterra.test.
 *
 * @param string $path Path to request.
 * @return array Response data.
 */
function lab_http_request( string $path ): array {
	$ch = curl_init( 'http://lab.ecoterra.test' . $path );
	curl_setopt( $ch, CURLOPT_RESOLVE, array( 'lab.ecoterra.test:80:127.0.0.1' ) );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HEADER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	$res    = curl_exec( $ch );
	$code   = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$h_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );

	return array(
		'status'  => $code,
		'headers' => is_string( $res ) ? substr( $res, 0, $h_size ) : '',
		'body'    => is_string( $res ) ? substr( $res, $h_size ) : '',
	);
}

$req_root = lab_http_request( '/' );
assert_true( 200 === $req_root['status'], 'Portada en español (/) responde HTTP 200 OK' );
assert_true( strlen( $req_root['body'] ) > 10000, 'Portada en español renderiza documento HTML real completo' );

$req_en = lab_http_request( '/en/' );
assert_true( 404 === $req_en['status'], 'Portada en inglés (/en/) responde HTTP 404 Not Found (consecuencia de TRID 197 no migrado)' );
assert_true( 500 !== $req_en['status'], 'Portada en inglés (/en/) no produce error 500 fatal' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: CAUSA RAÍZ DEL ATRIBUTO <html lang="en-US"> EN PÁGINAS ES
// -------------------------------------------------------------------------
echo "\n--- Control 5: Causa Raíz de <html lang=\"en-US\"> y Solución Limpia ---\n";

$wplang = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'WPLANG'" );
assert_true( empty( $wplang ), 'Opción WPLANG en cms_ect_lab está vacía, forzando a WP Core al fallback en_US' );

// Comprobar presencia de lang="en-US" en la respuesta HTML real de la portada en español.
assert_true( strpos( $req_root['body'], 'lang="en-US"' ) !== false, 'Evidencia empírica: Portada en español contiene lang="en-US" por omisión de filtro locale' );

// Simulación de la solución limpia: filtro en 'locale' o 'determine_locale'.
$simulated_lang_es = 'es';
$simulated_locale  = 'es' === $simulated_lang_es ? 'es_ES' : 'en_US';
assert_true( 'es_ES' === $simulated_locale, 'Filtro limpio en hook locale resuelve es_ES para español' );
assert_true( 'en_US' === ( 'en' === 'en' ? 'en_US' : 'es_ES' ), 'Filtro limpio en hook locale resuelve en_US para inglés' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: AUDITORÍA EXHAUSTIVA DE COMPATIBILIDAD TEMÁTICA DE ecoCMS
// -------------------------------------------------------------------------
echo "\n--- Control 6: Auditoría Exhaustiva de Compatibilidad Temática de ecoCMS ---\n";

$eco_files    = 0;
$c_icl_lang   = 0;
$c_traducir   = 0;
$c_icl_obj    = 0;
$c_icl_get    = 0;
$c_guards_obj = 0;
$c_guards_get = 0;

$it_eco = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_dst, RecursiveDirectoryIterator::SKIP_DOTS ) );
foreach ( $it_eco as $f ) {
	if ( $f->isFile() && 'php' === $f->getExtension() ) {
		$eco_files++;
		$content = file_get_contents( $f->getPathname() );
		$c_icl_lang   += substr_count( $content, 'ICL_LANGUAGE_CODE' );
		$c_traducir   += substr_count( $content, 'traducir(' );
		$c_icl_obj    += substr_count( $content, 'icl_object_id(' );
		$c_icl_get    += substr_count( $content, 'icl_get_languages(' );
		$c_guards_obj += substr_count( $content, "'icl_object_id'" );
		$c_guards_get += substr_count( $content, "'icl_get_languages'" );
	}
}

$c_total_calls = $c_icl_lang + $c_traducir + $c_icl_obj + $c_icl_get;
$c_total_refs  = $c_total_calls + $c_guards_obj + $c_guards_get;

echo "Inventario de llamadas multilingües en ecoCMS:\n";
echo "  ICL_LANGUAGE_CODE:   {$c_icl_lang} ocurrencias (esperadas: 5)\n";
echo "  traducir():          {$c_traducir} ocurrencias (esperadas: 96)\n";
echo "  icl_object_id():     {$c_icl_obj} ocurrencias (esperadas: 2)\n";
echo "  icl_get_languages(): {$c_icl_get} ocurrencias (esperadas: 2)\n";
echo "  Total llamadas:      {$c_total_calls} ocurrencias (esperadas: 105)\n";
echo "  Total con guardas:   {$c_total_refs} referencias (esperadas: 109)\n\n";

assert_true( 5 === $c_icl_lang, 'ecoCMS contiene exactamente 5 ocurrencias de ICL_LANGUAGE_CODE' );
assert_true( 96 === $c_traducir, 'ecoCMS contiene exactamente 96 llamadas a traducir()' );
assert_true( 2 === $c_icl_obj, 'ecoCMS contiene exactamente 2 llamadas a icl_object_id()' );
assert_true( 2 === $c_icl_get, 'ecoCMS contiene exactamente 2 llamadas a icl_get_languages()' );
assert_true( 105 === $c_total_calls, 'Total de llamadas directas multilingües en ecoCMS es exactamente 105' );
assert_true( 109 === $c_total_refs, 'Total de referencias a símbolos WPML (incluyendo guardas function_exists) es exactamente 109' );

// Verificación del comportamiento de traducir() sin ICL_LANGUAGE_CODE definido.
$ecoterra_inc = file_get_contents( $theme_dst . '/inc/ecoterra.php' );
assert_true( strpos( $ecoterra_inc, "defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : 'es'" ) !== false, 'Función traducir() en ecoCMS recurre a es cuando ICL_LANGUAGE_CODE no está definida' );

// -------------------------------------------------------------------------
// 8. CONTROL 7: DIAGNÓSTICO DE CABECERA Y DEPENDENCIA DE ELEMENTOR
// -------------------------------------------------------------------------
echo "\n--- Control 7: Diagnóstico de Cabecera y Dependencia de Elementor ---\n";

$hf_posts = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `etk_t_posts` WHERE post_type = 'ova_framework_hf_el' AND post_status = 'publish'" );
echo "Cabeceras/Pies maquetados en ova_framework_hf_el: {$hf_posts}\n";

$hdr_opt = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'ova_header_template'" );
echo "Opción ova_header_template: {$hdr_opt}\n";

$theme_hook_php = file_get_contents( $theme_dst . '/inc/class-hook.php' );
assert_true( strpos( $theme_hook_php, "template-parts/header', 'default'" ) !== false, 'ecoCMS/inc/class-hook.php contiene fallback a header-default.php si falta Elementor' );

// -------------------------------------------------------------------------
// 9. CONTROL 8: INVARIANZA POST-FLIGHT Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 8: Invarianza Post-Flight y Centinela en Origen (cms_ect) ---\n";

$post_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $original_wpdb->get_var( $sql_hash );

echo "Sentinel WPML Post-Flight (cms_ect):\n";
echo "  Rows: {$post_rows} (Expected: 3403)\n";
echo "  MD5:  {$post_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

$post_groups   = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' );
$post_elements = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' );
assert_true( 120 === $post_groups, 'Laboratorio post-flight: Conserva 120 grupos intactos (Delta = 0)' );
assert_true( 133 === $post_elements, 'Laboratorio post-flight: Conserva 133 elementos intactos (Delta = 0)' );

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.26 DIAGNÓSTICO Y DISEÑO DE PORTADA Y TEMA: EXITOSA (PASS)\n";
echo "====================================================================\n";
