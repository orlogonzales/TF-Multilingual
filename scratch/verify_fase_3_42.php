<?php
/**
 * Verificador Reproducible de Fase 3.42 (Directiva DT-TFML-073).
 *
 * Coherencia del Panel Administrativo & Pantalla Funcional de Idiomas:
 * - Menú TF Multilingual unificado y sin submenús duplicados
 * - Pantalla completa "Idiomas" (catálogo, conmutadores activo/inactivo, predeterminado, adición de idiomas)
 * - Invariantes de seguridad (idioma predeterminado soberano bloqueado contra desactivación/eliminación)
 * - Módulo de diagnóstico con métricas de navegación desglosadas (2 ubicaciones tema, 1 grupo menú, 0 mapeos manuales)
 * - Divulgación transparente de traducciones retenidas (3 franceses en registro)
 * - No-regresión HTTP, SEO canónico y sitemaps en laboratorio
 * - CERO escrituras en la base de datos de producción cms_ect
 *
 * Uso:
 *   php scratch/verify_fase_3_42.php
 *
 * @package TF\Multilingual
 */

declare(strict_types=1);

if ( 'cli' !== php_sapi_name() ) {
	exit( "Ejecución permitida únicamente desde PHP CLI.\n" );
}

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

$lab_root = 'D:/laragon/www/lab.ecoterra';
if ( ! file_exists( $lab_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "ERROR: No se encontró wp-load.php en {$lab_root}\n" );
	exit( 1 );
}

require_once $lab_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

use TF\Multilingual\Admin\DiagnosticUi;
use TF\Multilingual\Admin\LanguageEditorialUi;
use TF\Multilingual\Admin\StringEditorialUi;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Diagnostic\DiagnosticService;
use TF\Multilingual\Domain\Language\Exceptions\DefaultLanguageException;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\RetainedTranslationRegistry;

$results = array();
$passed  = 0;
$failed  = 0;

/**
 * Registra el resultado de un control de verificacion.
 *
 * @param string $gate_id Identificador del control.
 * @param bool   $ok      Resultado booleano.
 * @param string $detail  Evidencia verificable.
 */
function record_gate( string $gate_id, bool $ok, string $detail ): void {
	global $results, $passed, $failed;
	$status = $ok ? 'PASS' : 'FAIL';
	if ( $ok ) {
		++$passed;
	} else {
		++$failed;
	}
	$results[] = array(
		'gate'   => $gate_id,
		'status' => $status,
		'detail' => $detail,
	);
	echo sprintf( "[%s] %s\n       %s\n", $status, $gate_id, $detail );
}

/**
 * Calcula el conteo y hash MD5 de etk_t_icl_translations en la BD indicada.
 *
 * @param string $db_name Nombre de la base de datos.
 * @return array{rows: int, md5: string}
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
 * Realiza una petición HTTP local con cURL y analiza cabeceras y cuerpo.
 *
 * @param string        $url     URL de destino.
 * @param array<string> $headers Cabeceras opcionales.
 * @return array<string, mixed>
 */
function fetch_and_inspect_http( string $url, array $headers = array() ): array {
	$ch = curl_init( $url );
	$opts = array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_TIMEOUT        => 15,
	);
	if ( ! empty( $headers ) ) {
		$opts[ CURLOPT_HTTPHEADER ] = $headers;
	}
	curl_setopt_array( $ch, $opts );

	$raw      = (string) curl_exec( $ch );
	$info     = curl_getinfo( $ch );
	$err      = curl_error( $ch );
	$hdr_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );

	if ( false === $raw ) {
		return array(
			'code'       => 0,
			'error'      => $err,
			'canonicals' => array(),
			'hreflangs'  => array(),
			'body'       => '',
		);
	}

	$header_block = substr( (string) $raw, 0, (int) $hdr_size );
	$body         = substr( (string) $raw, (int) $hdr_size );

	$canonicals = array();
	if ( preg_match_all( '/<link\s+[^>]*rel=[\'"]canonical[\'"][^>]*href=[\'"]([^\'"]+)[\'"]/i', $body, $cm1 ) ) {
		$canonicals = array_merge( $canonicals, $cm1[1] );
	}
	if ( preg_match_all( '/<link\s+[^>]*href=[\'"]([^\'"]+)[\'"][^>]*rel=[\'"]canonical[\'"]/i', $body, $cm2 ) ) {
		$canonicals = array_merge( $canonicals, $cm2[1] );
	}
	$canonicals = array_values( array_unique( $canonicals ) );

	$hreflangs = array();
	if ( preg_match_all( '/<link\s+[^>]*rel=[\'"]alternate[\'"][^>]*hreflang=[\'"]([^\'"]+)[\'"][^>]*href=[\'"]([^\'"]+)[\'"]/i', $body, $hm ) ) {
		foreach ( $hm[1] as $idx => $hl ) {
			$hreflangs[ $hl ] = $hm[2][ $idx ];
		}
	}

	return array(
		'code'       => (int) $info['http_code'],
		'error'      => '',
		'canonicals' => $canonicals,
		'hreflangs'  => $hreflangs,
		'body'       => $body,
	);
}

echo "========================================================================\n";
echo "TF MULTILINGUAL — VERIFICADOR REPRODUCIBLE FASE 3.42 (DT-TFML-073)\n";
echo "Coherencia de Panel WP-Admin, Pantalla Idiomas & Diagnóstico Enriquecido\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// CONTROL 1: Centinela de producción cms_ect (CERO escrituras, MD5 inmutable)
// -------------------------------------------------------------------------
$prod_sentinel = get_icl_sentinel( 'cms_ect' );
$expected_prod_md5 = '4241ca7e7ec6399a594537cb04790c10';
$prod_ok = ( 3403 === $prod_sentinel['rows'] && $expected_prod_md5 === $prod_sentinel['md5'] );

record_gate(
	'1. Producción cms_ect — Centinela inmutable (3.403 filas, MD5 intacto, 0 escrituras)',
	$prod_ok,
	sprintf( 'rows=%d/3403 | md5=%s (esperado=%s)', $prod_sentinel['rows'], $prod_sentinel['md5'], $expected_prod_md5 )
);

// -------------------------------------------------------------------------
// CONTROL 2: Conexión efectiva exclusiva a cms_ect_lab
// -------------------------------------------------------------------------
global $wpdb;
$active_db_res = $wpdb->get_var( 'SELECT DATABASE()' );
$db_conn_ok = ( 'cms_ect_lab' === DB_NAME && 'cms_ect_lab' === $wpdb->dbname && 'cms_ect_lab' === $active_db_res );

record_gate(
	'2. Conexión efectiva exclusiva a cms_ect_lab (DB_NAME, $wpdb->dbname, SELECT DATABASE())',
	$db_conn_ok,
	sprintf( 'DB_NAME=%s | wpdb->dbname=%s | SELECT DATABASE()=%s', DB_NAME, $wpdb->dbname, (string) $active_db_res )
);

// -------------------------------------------------------------------------
// CONTROL 3: Invarianza de datos en cms_ect_lab (123 grupos, 139 elementos, 3 franceses retenidos)
// -------------------------------------------------------------------------
$groups_tbl   = $wpdb->prefix . SchemaManager::TABLES['groups'];
$elements_tbl = $wpdb->prefix . SchemaManager::TABLES['group_elements'];

$total_groups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$groups_tbl}`" );
$total_elements = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$elements_tbl}`" );

$retained_repo = new RetainedTranslationRegistry( $wpdb );
$retained_all  = $retained_repo->get_all();
$retained_fr_count = 0;
$retained_fr_ids   = array();
foreach ( $retained_all as $rec ) {
	$elems = (array) ( $rec['retained_elements'] ?? array() );
	if ( isset( $elems['fr'] ) ) {
		++$retained_fr_count;
		$retained_fr_ids[] = (int) ( $elems['fr']['element_id'] ?? 0 );
	}
}
sort( $retained_fr_ids );

$lab_data_ok = ( 123 === $total_groups && 139 === $total_elements && 3 === $retained_fr_count && array( 8839, 8873, 8955 ) === $retained_fr_ids );

record_gate(
	'3. Laboratorio cms_ect_lab — Invarianza de datos (123 grupos, 139 elementos, 3 franceses retenidos: 8839, 8873, 8955)',
	$lab_data_ok,
	sprintf( 'groups=%d/123 | elements=%d/139 | retained_fr=%d/3 (ids: %s)', $total_groups, $total_elements, $retained_fr_count, implode( ',', $retained_fr_ids ) )
);

// -------------------------------------------------------------------------
// CONTROL 4: Centinela WPML en cms_ect_lab (3.403 filas intactas)
// -------------------------------------------------------------------------
$lab_sentinel = get_icl_sentinel( 'cms_ect_lab' );
$lab_icl_ok = ( 3403 === $lab_sentinel['rows'] && $expected_prod_md5 === $lab_sentinel['md5'] );

record_gate(
	'4. Laboratorio cms_ect_lab — Centinela WPML intacto (3.403 filas etk_t_icl_translations)',
	$lab_icl_ok,
	sprintf( 'rows=%d/3403 | md5=%s', $lab_sentinel['rows'], $lab_sentinel['md5'] )
);

// -------------------------------------------------------------------------
// CONTROL 5: Estructura y jerarquía de menús administrativos de TF Multilingual
// -------------------------------------------------------------------------
// Simular registro admin_menu
set_current_screen( 'dashboard' );
wp_set_current_user( 1 ); // admin_lab

global $menu, $submenu;
$menu    = array();
$submenu = array();

// Inicializar componentes UI
$plugin = Plugin::get_instance();
$languages_ui = $plugin->get_language_editorial_ui();
$strings_ui   = $plugin->get_string_editorial_ui();
$diagnostic_ui = new DiagnosticUi( new DiagnosticService(
	$plugin->get_language_registry(),
	new TF\Multilingual\Domain\Language\SettingsRepository(),
	new TF\Multilingual\Domain\Translation\TranslationGroupRepository(),
	$plugin->get_media_repository(),
	$plugin->get_string_repository(),
	$plugin->get_nav_menu_location_repository(),
	$wpdb,
	$retained_repo
) );

$languages_ui->register_menu_page();
$strings_ui->register_menu_page();
$diagnostic_ui->register_menu_page();

$parent_slug = 'tf-multilingual';
$tfml_submenus = $submenu[ $parent_slug ] ?? array();

$sub_titles = array_map( static fn( array $item ): string => (string) $item[0], $tfml_submenus );
$sub_slugs  = array_map( static fn( array $item ): string => (string) $item[2], $tfml_submenus );

$menu_hierarchy_ok = ( 3 === count( $tfml_submenus ) )
	&& isset( $sub_titles[0] ) && 'Idiomas' === $sub_titles[0] && 'tf-multilingual' === $sub_slugs[0]
	&& isset( $sub_titles[1] ) && 'Cadenas de texto' === $sub_titles[1] && 'tfml-strings' === $sub_slugs[1]
	&& isset( $sub_titles[2] ) && 'Diagnóstico' === $sub_titles[2] && 'tfml-diagnostic' === $sub_slugs[2];

record_gate(
	'5. Jerarquía de Menú WP-Admin — 0 submenús duplicados (1: Idiomas, 2: Cadenas de texto, 3: Diagnóstico)',
	$menu_hierarchy_ok,
	sprintf(
		'submenus_count=%d | items: [%s] | slugs: [%s]',
		count( $tfml_submenus ),
		implode( ' | ', $sub_titles ),
		implode( ' | ', $sub_slugs )
	)
);

// -------------------------------------------------------------------------
// CONTROL 6: Pantalla "Idiomas" — Operatividad e Invariantes de Seguridad
// -------------------------------------------------------------------------
$lang_reg = $plugin->get_language_registry();
$lang_reg->ensure_loaded();
$all_cfg_langs = $lang_reg->all();
$default_lang  = $lang_reg->get_default();

$cap_check = ( 'manage_options' === $languages_ui->get_capability() );
$has_es    = isset( $all_cfg_langs['es'] ) && $all_cfg_langs['es']->is_active();
$has_en    = isset( $all_cfg_langs['en'] ) && $all_cfg_langs['en']->is_active();
$has_pt    = isset( $all_cfg_langs['pt'] ) && $all_cfg_langs['pt']->is_active();
$default_is_es = ( null !== $default_lang && 'es' === $default_lang->get_code() );

// Test de invariante: Intento de desactivar idioma predeterminado
$deactivate_blocked = false;
try {
	$lang_reg->deactivate( 'es' );
} catch ( DefaultLanguageException $e ) {
	$deactivate_blocked = true;
}

// Test de invariante: Intento de eliminar idioma predeterminado
$delete_blocked = false;
try {
	$lang_reg->remove_language( 'es' );
} catch ( DefaultLanguageException $e ) {
	$delete_blocked = true;
}

$languages_screen_ok = $cap_check && $has_es && $has_en && $has_pt && $default_is_es && $deactivate_blocked && $delete_blocked;

record_gate(
	'6. Pantalla "Idiomas" — Operatividad funcional e invariantes de seguridad (manage_options, ES defecto, bloqueo desactivar/eliminar defecto)',
	$languages_screen_ok,
	sprintf(
		'cap=%s | total=%d | default=%s | deactivate_default_blocked=%s | delete_default_blocked=%s',
		$languages_ui->get_capability(),
		count( $all_cfg_langs ),
		$default_lang ? $default_lang->get_code() : 'null',
		$deactivate_blocked ? 'true' : 'false',
		$delete_blocked ? 'true' : 'false'
	)
);

// -------------------------------------------------------------------------
// CONTROL 7: Diagnóstico Enriquecido — Métricas de Menús y Traducciones Retenidas
// -------------------------------------------------------------------------
$diag_svc    = new DiagnosticService(
	$lang_reg,
	new TF\Multilingual\Domain\Language\SettingsRepository(),
	new TF\Multilingual\Domain\Translation\TranslationGroupRepository(),
	$plugin->get_media_repository(),
	$plugin->get_string_repository(),
	$plugin->get_nav_menu_location_repository(),
	$wpdb,
	$retained_repo
);

$diag_full = $diag_svc->run_full_diagnostic();
$menus_sec = $diag_full['sections']['modules']['menus'] ?? array();
$langs_sec = $diag_full['sections']['languages'] ?? array();

$theme_locs  = (int) ( $menus_sec['theme_locations_count'] ?? 0 );
$menu_groups = (int) ( $menus_sec['menu_groups_count'] ?? 0 );
$locs_mapped = (int) ( $menus_sec['locations_mapped'] ?? 0 );
$retained_tot = (int) ( $langs_sec['retained_translations']['total'] ?? 0 );
$retained_by_lang = (array) ( $langs_sec['retained_translations']['by_lang'] ?? array() );

ob_start();
$diagnostic_ui->render_page();
$diag_html = ob_get_clean();

$has_clarified_menus_text = ( false !== strpos( $diag_html, 'ubicaciones de tema detectadas' ) );
$has_retained_text        = ( false !== strpos( $diag_html, 'Traducciones Retenidas (Idiomas Inactivos)' ) );

$diagnostic_enhanced_ok = ( 2 === $theme_locs )
	&& ( 18 === $menu_groups )
	&& ( 0 === $locs_mapped )
	&& ( 3 === $retained_tot )
	&& ( 3 === ( $retained_by_lang['fr'] ?? 0 ) )
	&& $has_clarified_menus_text
	&& $has_retained_text;

record_gate(
	'7. Diagnóstico Enriquecido — 3 métricas de navegación (2 tema, 18 grupos de menús, 0 mapeos) y divulgación de 3 traducciones retenidas FR',
	$diagnostic_enhanced_ok,
	sprintf(
		'theme_locs=%d/2 | menu_groups=%d/18 | manual_maps=%d/0 | retained_tot=%d/3 (fr: %d) | html_clarified=%s',
		$theme_locs,
		$menu_groups,
		$locs_mapped,
		$retained_tot,
		$retained_by_lang['fr'] ?? 0,
		( $has_clarified_menus_text && $has_retained_text ) ? 'true' : 'false'
	)
);

// -------------------------------------------------------------------------
// CONTROL 8: No-regresión HTTP y SEO Canónico en Frontend
// -------------------------------------------------------------------------
$endpoints = array(
	'es_nosotros'   => 'http://lab.ecoterra.test/nosotros/',
	'en_about'      => 'http://lab.ecoterra.test/en/about-us/',
	'es_actividad'  => 'http://lab.ecoterra.test/actividades/',
	'en_activities' => 'http://lab.ecoterra.test/en/activities/',
);

$http_results = array();
$http_all_ok  = true;
foreach ( $endpoints as $key => $url ) {
	$res = fetch_and_inspect_http( $url );
	$canonical_ok = ( 1 === count( $res['canonicals'] ) && $url === $res['canonicals'][0] );
	$status_ok    = ( 200 === $res['code'] );
	$ok = $status_ok && $canonical_ok;
	if ( ! $ok ) {
		$http_all_ok = false;
	}
	$http_results[ $key ] = array(
		'code'       => $res['code'],
		'canonical'  => $res['canonicals'][0] ?? 'N/A',
		'canon_count'=> count( $res['canonicals'] ),
	);
}

record_gate(
	'8. Frontend HTTP & Canonical — 4/4 páginas HTTP 200 con canonical único y correcto',
	$http_all_ok,
	sprintf(
		'nosotros=[%d, %s] | about-us=[%d, %s] | actividades=[%d, %s] | activities=[%d, %s]',
		$http_results['es_nosotros']['code'],
		$http_results['es_nosotros']['canonical'],
		$http_results['en_about']['code'],
		$http_results['en_about']['canonical'],
		$http_results['es_actividad']['code'],
		$http_results['es_actividad']['canonical'],
		$http_results['en_activities']['code'],
		$http_results['en_activities']['canonical']
	)
);

// -------------------------------------------------------------------------
// CONTROL 9: No-regresión XML Sitemaps (30 entradas, 0 francés, blog_public=0)
// -------------------------------------------------------------------------
$posts_prov = new WP_Sitemaps_Posts();
$tax_prov   = new WP_Sitemaps_Taxonomies();

$page_list = $posts_prov->get_url_list( 1, 'page' );
$post_list = $posts_prov->get_url_list( 1, 'post' );
$cat_list  = $tax_prov->get_url_list( 1, 'category' );
$tag_list  = $tax_prov->get_url_list( 1, 'post_tag' );

$total_sitemap_entries = count( $page_list ) + count( $post_list ) + count( $cat_list ) + count( $tag_list );
$blog_public_val       = (string) get_option( 'blog_public' );

$sitemap_ok = ( 7 === count( $page_list ) && 3 === count( $post_list ) && 1 === count( $cat_list ) && 19 === count( $tag_list ) && 30 === $total_sitemap_entries && '0' === $blog_public_val );

record_gate(
	'9. XML Sitemaps — 30 entradas sin francés retenido y blog_public=0',
	$sitemap_ok,
	sprintf( 'page=%d/7 | post=%d/3 | category=%d/1 | post_tag=%d/19 | total=%d/30 | blog_public=%s', count( $page_list ), count( $post_list ), count( $cat_list ), count( $tag_list ), $total_sitemap_entries, $blog_public_val )
);

// -------------------------------------------------------------------------
// CONTROL 10: Renderizado HTML de la nueva pantalla "Idiomas"
// -------------------------------------------------------------------------
ob_start();
$languages_ui->render_page();
$lang_html = ob_get_clean();

$has_table      = ( false !== strpos( $lang_html, 'tfml-languages-table' ) );
$has_add_form   = ( false !== strpos( $lang_html, 'Añadir nuevo idioma' ) );
$has_matrix     = ( false !== strpos( $lang_html, 'Matriz de Módulos' ) );
$has_pred_badge = ( false !== strpos( $lang_html, 'Predeterminado' ) );
$has_fr_notice  = ( false !== strpos( $lang_html, 'Traducciones Retenidas Detectadas' ) );

$ui_render_ok = $has_table && $has_add_form && $has_matrix && $has_pred_badge && $has_fr_notice;

record_gate(
	'10. Renderizado HTML "Idiomas" — Tabla de idiomas, formulario de adición, matriz modular y aviso de retenidos',
	$ui_render_ok,
	sprintf(
		'table=%s | add_form=%s | matrix=%s | default_badge=%s | fr_notice=%s',
		$has_table ? 'true' : 'false',
		$has_add_form ? 'true' : 'false',
		$has_matrix ? 'true' : 'false',
		$has_pred_badge ? 'true' : 'false',
		$has_fr_notice ? 'true' : 'false'
	)
);

echo "\n------------------------------------------------------------------------\n";
echo sprintf( "RESUMEN FINAL: %d/10 CONTROLES PASS (%d FAIL)\n", $passed, $failed );
echo "------------------------------------------------------------------------\n";

if ( $failed > 0 ) {
	exit( 1 );
}

exit( 0 );
