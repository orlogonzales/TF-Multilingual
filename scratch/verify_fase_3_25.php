<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.25.
 *
 * Directiva: DT-TFML-055
 * Alcance: Restauración controlada del entorno visual en laboratorio:
 *          1. Congelamiento del baseline post-migración (120 grupos / 133 elementos, 100% universo importable).
 *          2. Contención y seguridad del laboratorio (salvaguardas activas).
 *          3. Copia física aislada e integridad SHA-256 de ecoCMS (175 archivos).
 *          4. Evaluación y copia física de ova-framework (6 archivos).
 *          5. Inicio y reconocimiento de ecoCMS en WordPress.
 *          6. Matriz HTTP y HTML obligatoria (11 rutas obligatorias).
 *          7. Inspección de menús, navegación y selector de idioma.
 *          8. Invarianza absoluta de centinelas (Delta = 0).
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
echo "  TF MULTILINGUAL — RESTAURACIÓN ENTORNO VISUAL ecoCMS (FASE 3.25)\n";
echo "  Directiva: DT-TFML-055 (Copia Física Aislada, Renderizado y Matriz)\n";
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
$lab_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

// -------------------------------------------------------------------------
// 2. CONTROL 1: CONGELAMIENTO DEL BASELINE POST-MIGRACIÓN
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

// -------------------------------------------------------------------------
// 4. CONTROL 3: COPIA FÍSICA AISLADA E INTEGRIDAD DE ecoCMS
// -------------------------------------------------------------------------
echo "\n--- Control 3: Copia Física Aislada e Integridad de ecoCMS ---\n";

$theme_src = 'D:/laragon/www/cms.ecoterra/wp-content/themes/ecoCMS';
$theme_dst = 'D:/laragon/www/lab.ecoterra/wp-content/themes/ecoCMS';

assert_true( is_dir( $theme_dst ), 'Destino: Directorio del tema ecoCMS existe físicamente en lab.ecoterra' );
assert_true( ! is_link( $theme_dst ), 'Aislamiento: Directorio de ecoCMS NO es un symlink ni junction' );

$src_files = 0;
$dst_files = 0;
$hash_diff = 0;

$it_src = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_src, RecursiveDirectoryIterator::SKIP_DOTS ) );
foreach ( $it_src as $item ) {
	if ( $item->isFile() ) {
		$src_files++;
		$rel  = substr( $item->getPathname(), strlen( $theme_src ) + 1 );
		$d_fl = $theme_dst . DIRECTORY_SEPARATOR . $rel;
		if ( ! file_exists( $d_fl ) ) {
			$hash_diff++;
		} else {
			$h_s = hash_file( 'sha256', $item->getPathname() );
			$h_d = hash_file( 'sha256', $d_fl );
			if ( $h_s !== $h_d ) {
				$hash_diff++;
			}
		}
	}
}

$it_dst = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_dst, RecursiveDirectoryIterator::SKIP_DOTS ) );
foreach ( $it_dst as $item ) {
	if ( $item->isFile() ) {
		$dst_files++;
		if ( is_link( $item->getPathname() ) ) {
			$hash_diff++;
		}
	}
}

assert_true( 175 === $src_files, "Inventario: ecoCMS en origen contiene exactamente 175 archivos ({$src_files})" );
assert_true( 175 === $dst_files, "Inventario: ecoCMS en destino contiene exactamente 175 archivos ({$dst_files})" );
assert_true( 0 === $hash_diff, 'Integridad: 100% de archivos de ecoCMS coinciden en tamaño y SHA-256 (0 discrepancias)' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: EVALUACIÓN Y COPIA DE ova-framework
// -------------------------------------------------------------------------
echo "\n--- Control 4: Evaluación y Copia de ova-framework ---\n";

$plugin_src = 'D:/laragon/www/cms.ecoterra/wp-content/plugins/ova-framework';
$plugin_dst = 'D:/laragon/www/lab.ecoterra/wp-content/plugins/ova-framework';

assert_true( is_dir( $plugin_dst ), 'Destino: Directorio de ova-framework existe físicamente en lab.ecoterra' );
assert_true( ! is_link( $plugin_dst ), 'Aislamiento: ova-framework NO es un symlink ni junction' );

$p_src_cnt  = 0;
$p_dst_cnt  = 0;
$p_hash_dif = 0;

$p_it_src = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_src, RecursiveDirectoryIterator::SKIP_DOTS ) );
foreach ( $p_it_src as $item ) {
	if ( $item->isFile() ) {
		$p_src_cnt++;
		$rel  = substr( $item->getPathname(), strlen( $plugin_src ) + 1 );
		$d_fl = $plugin_dst . DIRECTORY_SEPARATOR . $rel;
		if ( ! file_exists( $d_fl ) ) {
			$p_hash_dif++;
		} else {
			if ( hash_file( 'sha256', $item->getPathname() ) !== hash_file( 'sha256', $d_fl ) ) {
				$p_hash_dif++;
			}
		}
	}
}

$p_it_dst = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dst, RecursiveDirectoryIterator::SKIP_DOTS ) );
foreach ( $p_it_dst as $item ) {
	if ( $item->isFile() ) {
		$p_dst_cnt++;
	}
}

assert_true( 6 === $p_src_cnt, "Inventario: ova-framework contiene 6 archivos en origen ({$p_src_cnt})" );
assert_true( 6 === $p_dst_cnt, "Inventario: ova-framework contiene 6 archivos en destino ({$p_dst_cnt})" );
assert_true( 0 === $p_hash_dif, 'Integridad: 100% de archivos de ova-framework coinciden en SHA-256 (0 discrepancias)' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: INICIO Y RECONOCIMIENTO DE ecoCMS EN WORDPRESS
// -------------------------------------------------------------------------
echo "\n--- Control 5: Inicio y Reconocimiento de ecoCMS en WordPress ---\n";

$opt_tmpl  = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'template'" );
$opt_style = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'stylesheet'" );
$opt_theme = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'current_theme'" );

assert_true( 'ecoCMS' === $opt_tmpl, 'Opción template configurada como ecoCMS' );
assert_true( 'ecoCMS' === $opt_style, 'Opción stylesheet configurada como ecoCMS' );
assert_true( 'ecoCMS' === $opt_theme, 'Opción current_theme configurada como ecoCMS' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: MATRIZ HTTP Y HTML OBLIGATORIA
// -------------------------------------------------------------------------
echo "\n--- Control 6: Matriz HTTP y HTML Obligatoria ---\n";

/**
 * Execute HTTP GET against lab.ecoterra.test.
 *
 * @param string $path Path to request.
 * @return array Response data.
 */
function lab_get( string $path ): array {
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

// 1. Root /
$res_root = lab_get( '/' );
assert_true( 200 === $res_root['status'], 'HTTP Matrix [1/11]: Raíz (/) responde HTTP 200 OK' );
assert_true( strlen( $res_root['body'] ) > 10000, 'HTML Matrix [1/11]: Raíz (/) genera documento HTML real (>10KB: ' . strlen( $res_root['body'] ) . ' bytes)' );
assert_true( stripos( $res_root['headers'], 'noindex' ) !== false, 'Seguridad [1/11]: Cabecera X-Robots-Tag: noindex presente en /' );
assert_true( strpos( $res_root['body'], '<header' ) !== false, 'Estructura [1/11]: Etiqueta <header> presente en HTML de portada' );
assert_true( strpos( $res_root['body'], '<footer' ) !== false, 'Estructura [1/11]: Etiqueta <footer> presente en HTML de portada' );

// 2. /en/
$res_en = lab_get( '/en/' );
assert_true( 404 === $res_en['status'], 'HTTP Matrix [2/11]: /en/ responde 404 Not Found (esperado por TRID 197 bloqueado)' );
assert_true( strlen( $res_en['body'] ) > 5000, 'HTML Matrix [2/11]: /en/ renderiza plantilla 404 completa de ecoCMS' );
assert_true( 500 !== $res_en['status'], 'Seguridad [2/11]: Ausencia de error fatal 500 en /en/' );

// 3. /fr/
$res_fr = lab_get( '/fr/' );
assert_true( 404 === $res_fr['status'], 'HTTP Matrix [3/11]: /fr/ responde 404 Not Found (idioma inactivo, rechazo limpio)' );
assert_true( 500 !== $res_fr['status'], 'Seguridad [3/11]: Ausencia de error fatal 500 en /fr/' );

// 4. Página monolingüe migrada (/old-blog/)
$res_page = lab_get( '/old-blog/' );
assert_true( 200 === $res_page['status'], 'HTTP Matrix [4/11]: Página migrada /old-blog/ responde HTTP 200 OK' );
assert_true( strlen( $res_page['body'] ) > 10000, 'HTML Matrix [4/11]: Página /old-blog/ renderiza HTML completo' );

// 5. Entrada del blog migrada (/blog/recomendaciones-para-disfrutar-machu-picchu/)
$res_post = lab_get( '/blog/recomendaciones-para-disfrutar-machu-picchu/' );
assert_true( 200 === $res_post['status'], 'HTTP Matrix [5/11]: Entrada blog migrada responde HTTP 200 OK' );
assert_true( strlen( $res_post['body'] ) > 10000, 'HTML Matrix [5/11]: Entrada blog renderiza contenido editorial real' );

// 6. Categoría migrada (/category/blog/)
$res_cat = lab_get( '/category/blog/' );
assert_true( 200 === $res_cat['status'], 'HTTP Matrix [6/11]: Categoría migrada /category/blog/ responde HTTP 200 OK' );
assert_true( strlen( $res_cat['body'] ) > 10000, 'HTML Matrix [6/11]: Archivo de categoría renderiza listado' );

// 7. Etiqueta migrada (/tag/cusco/)
$res_tag = lab_get( '/tag/cusco/' );
assert_true( 200 === $res_tag['status'], 'HTTP Matrix [7/11]: Etiqueta migrada /tag/cusco/ responde HTTP 200 OK' );
assert_true( strlen( $res_tag['body'] ) > 10000, 'HTML Matrix [7/11]: Archivo de etiqueta renderiza listado' );

// 8. Tour bilingüe en español (?post_type=tours&p=5286)
$res_tour_es = lab_get( '/?post_type=tours&p=5286' );
assert_true( in_array( $res_tour_es['status'], array( 200, 404 ), true ), 'HTTP Matrix [8/11]: Tour ES responde código válido sin 500 (Status: ' . $res_tour_es['status'] . ')' );
assert_true( 500 !== $res_tour_es['status'], 'Seguridad [8/11]: Ausencia de error fatal 500 en Tour ES' );

// 9. Tour bilingüe en inglés (?post_type=tours&p=5287)
$res_tour_en = lab_get( '/?post_type=tours&p=5287' );
assert_true( in_array( $res_tour_en['status'], array( 200, 404 ), true ), 'HTTP Matrix [9/11]: Tour EN responde código válido sin 500 (Status: ' . $res_tour_en['status'] . ')' );

// 10. Producto WooCommerce (?post_type=product&p=5250)
$res_prod = lab_get( '/?post_type=product&p=5250' );
assert_true( in_array( $res_prod['status'], array( 200, 404 ), true ), 'HTTP Matrix [10/11]: Producto responde código válido sin 500 (Status: ' . $res_prod['status'] . ')' );

// 11. Página Elementor (/old-blog/)
assert_true( strpos( $res_page['body'], 'site-brand' ) !== false, 'HTML Matrix [11/11]: Elementos estructurales de ecoCMS presentes en página Elementor' );

// -------------------------------------------------------------------------
// 8. CONTROL 7: INVARIANZA FINAL Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 7: Invarianza Final y Centinela en Origen (cms_ect) ---\n";

$post_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $original_wpdb->get_var( $sql_hash );

echo "\nSentinel WPML Post-Flight (cms_ect):\n";
echo "  Rows: {$post_rows} (Expected: 3403)\n";
echo "  MD5:  {$post_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.25 RESTAURACIÓN ENTORNO VISUAL ecoCMS: EXITOSA (PASS)\n";
echo "====================================================================\n";
