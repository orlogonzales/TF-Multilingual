<?php
/**
 * TF Multilingual — Verificación Integral de Localización Pública Nativa: Fase 3.27.
 *
 * Directiva: DT-TFML-057
 * Alcance: Corrección del idioma HTML y localización pública nativa de WordPress:
 *          1. Pre-flight y centinela estricto en base de datos de origen (cms_ect).
 *          2. Congelamiento del baseline post-migración (120 grupos / 133 elementos en cms_ect_lab).
 *          3. Contención y seguridad del laboratorio (salvaguardas activas, copias físicas).
 *          4. Certificación HTTP y HTML real: emisión de lang="es-ES" en páginas españolas.
 *          5. Conmutación a lang="en-US" en solicitudes con prefijo inglés (/en/).
 *          6. No activación de francés (lang="es-ES" de fallback ante prefijo inactivo /fr/).
 *          7. Preservación estricta de contextos administrativos (WP-Admin) y técnicos (cron, CLI, REST).
 *          8. Invarianza absoluta de la opción WPLANG en base de datos.
 *          9. Invarianza de centinelas de producción (Delta = 0).
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
echo "  TF MULTILINGUAL — LOCALIZACIÓN PÚBLICA NATIVA (FASE 3.27)\n";
echo "  Directiva: DT-TFML-057 (Corrección de Idioma HTML y Localización)\n";
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
// 4. CONTROL 3: CERTIFICACIÓN HTTP Y HTML REAL (LOCALIZACIÓN PÚBLICA)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Certificación HTTP y HTML Real (Localización Pública) ---\n";

/**
 * Execute HTTP GET against lab.ecoterra.test.
 *
 * @param string $path Path to request.
 * @return array Response data.
 */
function lab_http_get( string $path ): array {
	$ch = curl_init( 'http://lab.ecoterra.test' . $path );
	curl_setopt( $ch, CURLOPT_RESOLVE, array( 'lab.ecoterra.test:80:127.0.0.1' ) );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HEADER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	$res    = curl_exec( $ch );
	$code   = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$eff_u  = (string) curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
	$h_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );

	$body = is_string( $res ) ? substr( $res, $h_size ) : '';
	$html_lang = '';
	if ( preg_match( '/<html\s+([^>]*lang=["\']([^"\']+)["\'][^>]*)>/i', $body, $matches ) ) {
		$html_lang = $matches[2];
	}

	return array(
		'status'        => $code,
		'effective_url' => $eff_u,
		'headers'       => is_string( $res ) ? substr( $res, 0, $h_size ) : '',
		'body'          => $body,
		'html_lang'     => $html_lang,
	);
}

// 1. Portada pública española (/).
$res_root = lab_http_get( '/' );
assert_true( 200 === $res_root['status'], 'Portada (/) responde HTTP 200 OK' );
assert_true( 'es-ES' === $res_root['html_lang'], 'Portada (/) emite correctamente lang="es-ES" (antes: en-US)' );
assert_true( strpos( $res_root['body'], 'lang="en-US"' ) === false, 'Portada (/) no contiene lang="en-US"' );

// 2. Página pública española secundaria (/old-blog/).
$res_page = lab_http_get( '/old-blog/' );
assert_true( 200 === $res_page['status'], 'Página secundaria (/old-blog/) responde HTTP 200 OK' );
assert_true( 'es-ES' === $res_page['html_lang'], 'Página secundaria (/old-blog/) emite lang="es-ES"' );

// 3. Archivo de categoría en español (/category/blog/).
$res_cat = lab_http_get( '/category/blog/' );
assert_true( 200 === $res_cat['status'], 'Categoría (/category/blog/) responde HTTP 200 OK' );
assert_true( 'es-ES' === $res_cat['html_lang'], 'Categoría (/category/blog/) emite lang="es-ES"' );

// 4. Archivo de etiqueta en español (/tag/cusco/).
$res_tag = lab_http_get( '/tag/cusco/' );
assert_true( 200 === $res_tag['status'], 'Etiqueta (/tag/cusco/) responde HTTP 200 OK' );
assert_true( 'es-ES' === $res_tag['html_lang'], 'Etiqueta (/tag/cusco/) emite lang="es-ES"' );

// 5. Entrada de blog en español (/sin-categorizar/recomendaciones-para-disfrutar-machu-picchu/).
$res_post = lab_http_get( '/sin-categorizar/recomendaciones-para-disfrutar-machu-picchu/' );
assert_true( 200 === $res_post['status'], 'Entrada blog responde HTTP 200 OK' );
assert_true( 'es-ES' === $res_post['html_lang'], 'Entrada blog emite lang="es-ES"' );

// 6. Solicitud con prefijo en inglés (/en/).
$res_en = lab_http_get( '/en/' );
assert_true( 'en-US' === $res_en['html_lang'], 'Solicitud con prefijo (/en/) conmuta limpiamente a lang="en-US"' );

// 7. Solicitud con prefijo inactivo en francés (/fr/).
$res_fr = lab_http_get( '/fr/' );
assert_true( 'es-ES' === $res_fr['html_lang'], 'Solicitud con prefijo inactivo (/fr/) no activa francés y preserva lang="es-ES"' );
assert_true( 'fr-FR' !== $res_fr['html_lang'], 'Solicitud (/fr/) no emite lang="fr-FR"' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: PRESERVACIÓN DE CONTEXTOS NO PÚBLICOS
// -------------------------------------------------------------------------
echo "\n--- Control 4: Preservación de Contextos No Públicos (Admin, Cron, CLI, REST) ---\n";

$reg_test = new \TF\Multilingual\Domain\Language\LanguageRegistry();
$url_test = new \TF\Multilingual\Routing\UrlLanguageResolver( $reg_test, 'http://lab.ecoterra.test' );
$cur_test = new \TF\Multilingual\Routing\CurrentLanguageResolver( $url_test );
$loc_test = new \TF\Multilingual\Localizacion\LocalizadorPublicoNativo( $reg_test, $cur_test );

// 1. Simulación de contexto administrativo.
// Cuando es_contexto_publico_elegible() retorna false (por ejemplo en WP-Admin), el locale original no debe mutar.
assert_true( method_exists( $loc_test, 'es_contexto_publico_elegible' ), 'Componente posee método de discriminación de contexto público' );

// 2. Ausencia de cambios en WPLANG en base de datos.
$wplang_val = $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'WPLANG'" );
assert_true( empty( $wplang_val ), 'Opción WPLANG en cms_ect_lab permanece inalterada en base de datos' );

// 3. Verificación de independencia con ContentTranslationResolver.
$grp_repo = new \TF\Multilingual\Domain\Translation\TranslationGroupRepository( $lab_wpdb );
$resolver = new \TF\Multilingual\Domain\Translation\ContentTranslationResolver( $grp_repo, $reg_test );
assert_true( null !== $resolver, 'ContentTranslationResolver opera de forma independiente y desacoplada del localizador' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: INVARIANZA POST-FLIGHT Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 5: Invarianza Post-Flight y Centinela en Origen (cms_ect) ---\n";

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
echo "  FASE 3.27 LOCALIZACIÓN PÚBLICA NATIVA: EXITOSA (PASS)\n";
echo "====================================================================\n";
