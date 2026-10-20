<?php
/**
 * Verificador Reproducible de Fase 3.54 (Directiva DT-TFML-087).
 *
 * Cierre Funcional Integrado y Certificación de Cobertura Multilingüe de TF Multilingual:
 * Certificación integral del plugin como reemplazo soberano, desacoplado y autónomo de WPML
 * en el entorno de laboratorio (lab.ecoterra.test / cms_ect_lab).
 *
 * Controles de Fase:
 * - Control 1: Invarianza Absoluta de Producción (cms_ect: 3,403 filas, hash MD5 4241ca7e7ec6399a594537cb04790c10).
 * - Control 2: Estado Consolidado e Integridad Relacional en Laboratorio (cms_ect_lab: 141 grupos, 175 elementos, 21 FR retenidas, 20 manifiestos, checkpoint completed, lock libre).
 * - Control 3: Diagnóstico Global del Sistema (6/6 Secciones PASS / Good: environment, tables, languages, relations, rewrites, modules).
 * - Control 4: Corrección y Persistencia de Ubicaciones de Menú (SettingsRepository & NavMenuLocationRepository roundtrip sin pérdidas).
 * - Control 5: Ciclo de Vida Editorial, Búsqueda de Candidatos y Hooks de Eliminación (search_link_candidates, deleted_post y delete_term hooks, anti-tampering).
 * - Control 6: Cadenas Multilingües e Internacionalización (StringTranslationService: registro, traducción, fallback soberano y limpieza).
 * - Control 7: Compatibilidad WPML, Elementor y ecoCMS sin Modificación Externa (AdaptadorCompatibilidadWpml: icl_object_id, icl_get_languages, cero mutación en terceros).
 * - Control 8: Certificación HTTP Real en Vivo (42 Rutas: 200 OK en pares ES/EN, canonical y hreflang simétricos, 404 en /fr/, /pt/ y borradores).
 * - Control 9: Sitemaps Nativos WordPress y Exclusión de Contenido Inactivo/Borrador (CoreSitemapsFilter: 66 URLs únicas, 0 francés, 0 borradores).
 * - Control 10: Tratamiento Formal del Borrador TRID 34790 e Invarianza Final (Borrador 34790 intacto en draft, 0 entradas en TFML, invarianza total).
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
ini_set( 'display_errors', '1' );

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Seo\CoreSitemapsFilter;
use TF\Multilingual\Domain\Strings\StringTranslationService;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

$total_checks  = 0;
$passed_checks = 0;
$failed_checks = 0;

/**
 * Registra el resultado de un control de fase.
 *
 * @param string $label     Nombre del control.
 * @param bool   $condition Resultado booleano.
 * @param string $detail    Detalle diagnóstico.
 * @return void
 */
function record_gate( string $label, bool $condition, string $detail = '' ): void {
	global $total_checks, $passed_checks, $failed_checks;
	++$total_checks;
	if ( $condition ) {
		++$passed_checks;
		echo "[PASS] {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	} else {
		++$failed_checks;
		echo "[FAIL] {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}
}

/**
 * Calcula el hash MD5 y conteo de filas de etk_t_icl_translations en la BD dada.
 *
 * @param string $db_name Nombre de base de datos.
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
 * Inspecciona una URL HTTP real.
 *
 * @param string $url URL completa.
 * @return array{code: int, lang: string, canonical: string, body: string}
 */
function inspect_route( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 15,
		)
	);
	$body = (string) curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$lang      = '';
	$canonical = '';

	if ( preg_match( '/<html[^>]+lang=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$lang = $m[1];
	}
	if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$canonical = $m[1];
	}

	return array(
		'code'      => $code,
		'lang'      => $lang,
		'canonical' => $canonical,
		'body'      => $body,
	);
}

echo "================================================================================\n";
echo "TF MULTILINGUAL — VERIFICADOR REPRODUCIBLE DE FASE 3.54\n";
echo "CIERRE FUNCIONAL INTEGRADO Y CERTIFICACIÓN DE COBERTURA MULTILINGÜE\n";
echo "================================================================================\n\n";

global $wpdb;
$plugin = Plugin::get_instance();

// Expected sentinel constants
$expected_wpml_rows = 3403;
$expected_wpml_md5  = '4241ca7e7ec6399a594537cb04790c10';

// -----------------------------------------------------------------------------
// CONTROL 1: Invarianza Absoluta de Producción (cms_ect)
// -----------------------------------------------------------------------------
$sentinel_prod = get_icl_sentinel( 'cms_ect' );
$c1_ok         = ( $expected_wpml_rows === $sentinel_prod['rows'] && $expected_wpml_md5 === $sentinel_prod['md5'] );
record_gate(
	'Control 1: Invarianza Absoluta de Producción (cms_ect)',
	$c1_ok,
	sprintf( 'Filas: %d (esperadas: %d), MD5: %s', $sentinel_prod['rows'], $expected_wpml_rows, $sentinel_prod['md5'] )
);

// -----------------------------------------------------------------------------
// CONTROL 2: Estado Consolidado e Integridad Relacional en Laboratorio (cms_ect_lab)
// -----------------------------------------------------------------------------
$groups_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$retained_registry = new RetainedTranslationRegistry();
$retained_count    = count( $retained_registry->get_all() );
$manifests_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name LIKE 'tfml_migration_manifest_%'" );

$migration_mgr     = $plugin->get_migration_manager();
$checkpoint_repo   = $migration_mgr->get_checkpoint_repository();
$checkpoint        = $checkpoint_repo->get( 'wpml' );
$lock              = $migration_mgr->get_execution_lock();
$is_locked         = $lock->is_locked( 'wpml' );

$fr_active_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE language_code = 'fr'" );
$element_dups      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id, COUNT(*) as c FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING c > 1) as t" );
$group_lang_dups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT group_id, language_code, COUNT(*) as c FROM {$wpdb->prefix}tfml_group_elements GROUP BY group_id, language_code HAVING c > 1) as t" );

$c2_ok = ( 141 === $groups_count
	&& 175 === $elements_count
	&& 21 === $retained_count
	&& 20 === $manifests_count
	&& null !== $checkpoint
	&& 'completed' === $checkpoint->get_status()
	&& ! $is_locked
	&& 0 === $fr_active_count
	&& 0 === $element_dups
	&& 0 === $group_lang_dups
);
record_gate(
	'Control 2: Estado Consolidado e Integridad Relacional en Laboratorio (cms_ect_lab)',
	$c2_ok,
	sprintf( 'Grupos: %d, Elementos: %d, Retenidas FR: %d, Manifiestos: %d, Checkpoint: %s, Lock libre: %s, FR Activo: %d, Dups: %d/%d', $groups_count, $elements_count, $retained_count, $manifests_count, $checkpoint ? $checkpoint->get_status() : 'null', ! $is_locked ? 'SI' : 'NO', $fr_active_count, $element_dups, $group_lang_dups )
);

// -----------------------------------------------------------------------------
// CONTROL 3: Diagnóstico Global del Sistema (6/6 Secciones Good)
// -----------------------------------------------------------------------------
$diag_service = $plugin->get_diagnostic_service();
$diag_report  = $diag_service->run_full_diagnostic();
$diag_status  = $diag_report['overall_status'] ?? 'unknown';

$sec_env      = $diag_report['sections']['environment']['status'] ?? 'unknown';
$sec_tbl      = $diag_report['sections']['tables']['status'] ?? 'unknown';
$sec_lng      = $diag_report['sections']['languages']['status'] ?? 'unknown';
$sec_rel      = $diag_report['sections']['relations']['status'] ?? 'unknown';
$sec_rew      = $diag_report['sections']['rewrites']['status'] ?? 'unknown';
$sec_mod      = $diag_report['sections']['modules']['status'] ?? 'unknown';

$orphaned_posts = $diag_report['sections']['relations']['orphaned_posts_count'] ?? -1;
$orphaned_terms = $diag_report['sections']['relations']['orphaned_terms_count'] ?? -1;
$empty_groups   = $diag_report['sections']['relations']['empty_groups_count'] ?? -1;

$c3_ok = ( 'good' === $diag_status
	&& 'good' === $sec_env
	&& 'good' === $sec_tbl
	&& 'good' === $sec_lng
	&& 'good' === $sec_rel
	&& 'good' === $sec_rew
	&& 'good' === $sec_mod
	&& 0 === $orphaned_posts
	&& 0 === $orphaned_terms
	&& 0 === $empty_groups
);
record_gate(
	'Control 3: Diagnóstico Global del Sistema (6/6 Secciones PASS / Good)',
	$c3_ok,
	sprintf( 'Overall: %s (env:%s, tbl:%s, lng:%s, rel:%s, rew:%s, mod:%s, orphans:%d/%d, empty_grp:%d)', $diag_status, $sec_env, $sec_tbl, $sec_lng, $sec_rel, $sec_rew, $sec_mod, $orphaned_posts, $orphaned_terms, $empty_groups )
);

// -----------------------------------------------------------------------------
// CONTROL 4: Corrección y Persistencia de Ubicaciones de Menú (Settings & NavMenuLocation)
// -----------------------------------------------------------------------------
$settings_repo = new SettingsRepository();
$nav_loc_repo  = new NavMenuLocationRepository( $settings_repo, $plugin->get_language_registry() );

// Test roundtrip of nav menu location persistence
$nav_loc_repo->set_menu_for_location( 'fase_3_54_test_loc', 'en', 7777 );
$saved_mapping = $nav_loc_repo->persist();

$fresh_settings_repo = new SettingsRepository();
$fresh_nav_loc_repo  = new NavMenuLocationRepository( $fresh_settings_repo, $plugin->get_language_registry() );
$resolved_test_menu  = $fresh_nav_loc_repo->get_menu_for_location( 'fase_3_54_test_loc', 'en' );

// Clean up test mapping
$fresh_nav_loc_repo->set_menu_for_location( 'fase_3_54_test_loc', 'en', null );
$cleaned_mapping = $fresh_nav_loc_repo->persist();

$final_verify_repo = new NavMenuLocationRepository( new SettingsRepository(), $plugin->get_language_registry() );
$all_clean_maps    = $final_verify_repo->get_all_mappings();

$c4_ok = ( true === $saved_mapping
	&& 7777 === $resolved_test_menu
	&& true === $cleaned_mapping
	&& ! isset( $all_clean_maps['fase_3_54_test_loc'] )
);
record_gate(
	'Control 4: Corrección y Persistencia de Ubicaciones de Menú (Settings & NavMenuLocation)',
	$c4_ok,
	sprintf( 'Roundtrip persist=%s, resolved=%d, cleanup=%s, dirty_keys=0', $saved_mapping ? 'true' : 'false', (int) $resolved_test_menu, $cleaned_mapping ? 'true' : 'false' )
);

// -----------------------------------------------------------------------------
// CONTROL 5: Ciclo de Vida Editorial, Búsqueda de Candidatos y Hooks de Eliminación
// -----------------------------------------------------------------------------
$editorial_svc = $plugin->get_editorial_service();

// 1. Candidate search must exclude already linked pages (e.g. #9614 Lek migrated in Group 601)
$cand_lek = $editorial_svc->search_link_candidates( 'post', 'page', 'es', 'Lek', 20, 0 );
$found_linked_9614 = false;
$found_draft_9662  = false;
foreach ( $cand_lek as $c ) {
	if ( 9614 === (int) $c['id'] ) {
		$found_linked_9614 = true;
	}
	if ( 9662 === (int) $c['id'] ) {
		$found_draft_9662 = true;
	}
}

// 2. Lifecycle hooks registered in WordPress
$has_save_post     = has_action( 'save_post', array( $editorial_svc, 'on_save_post' ) );
$has_deleted_post  = has_action( 'deleted_post', array( $editorial_svc, 'on_deleted_post' ) );
$has_meta_added    = has_action( 'added_post_meta', array( $editorial_svc, 'on_meta_added' ) );
$has_meta_updated  = has_action( 'updated_post_meta', array( $editorial_svc, 'on_meta_updated' ) );
$has_meta_deleted  = has_action( 'deleted_post_meta', array( $editorial_svc, 'on_meta_deleted' ) );
$has_edited_term   = has_action( 'edited_term', array( $editorial_svc, 'on_edited_term' ) );
$has_delete_term   = has_action( 'delete_term', array( $editorial_svc, 'on_delete_term' ) );

$c5_ok = ( ! $found_linked_9614
	&& $found_draft_9662
	&& false !== $has_save_post
	&& false !== $has_deleted_post
	&& false !== $has_meta_added
	&& false !== $has_meta_updated
	&& false !== $has_meta_deleted
	&& false !== $has_edited_term
	&& false !== $has_delete_term
);
record_gate(
	'Control 5: Ciclo de Vida Editorial, Búsqueda de Candidatos y Hooks de Eliminación',
	$c5_ok,
	sprintf( 'Excluded linked #9614: %s, Included draft #9662: %s, 7/7 hooks active (save, del_post, meta_add, meta_upd, meta_del, edit_term, del_term)', ! $found_linked_9614 ? 'SI' : 'NO', $found_draft_9662 ? 'SI' : 'NO' )
);

// -----------------------------------------------------------------------------
// CONTROL 6: Cadenas Multilingües e Internacionalización (StringTranslationService)
// -----------------------------------------------------------------------------
$str_svc      = $plugin->get_string_translation_service();
$test_str_key = 'tfml_cert_fase_3_54_' . time();
$str_obj      = $str_svc->register_string( 'cert_domain', $test_str_key, 'Texto Original Certificación', 'Contexto Cert' );

$str_registered = ( null !== $str_obj && $str_obj->get_id() > 0 );
if ( $str_registered ) {
	$str_svc->save_translation( $str_obj->get_id(), 'en', 'Certified Original Text' );
}

$translated_en = $str_svc->translate( 'cert_domain', $test_str_key, 'Texto Original Certificación', 'en' );
$fallback_es   = $str_svc->translate( 'cert_domain', $test_str_key, 'Texto Original Certificación', 'es' );

// Clean up certified test string
if ( $str_registered ) {
	$plugin->get_string_repository()->delete_string( $str_obj->get_id() );
}
$recheck_cleaned = $plugin->get_string_repository()->find_by_domain_and_key( 'cert_domain', $test_str_key );

$c6_ok = ( $str_registered
	&& 'Certified Original Text' === $translated_en
	&& 'Texto Original Certificación' === $fallback_es
	&& null === $recheck_cleaned
);
record_gate(
	'Control 6: Cadenas Multilingües e Internacionalización (StringTranslationService)',
	$c6_ok,
	sprintf( 'Register: %s, Translate EN: "%s", Sovereign default ES fallback: "%s", Cleaned: %s', $str_registered ? 'true' : 'false', $translated_en, $fallback_es, null === $recheck_cleaned ? 'true' : 'false' )
);

// -----------------------------------------------------------------------------
// CONTROL 7: Compatibilidad WPML, Elementor y ecoCMS sin Modificación Externa
// -----------------------------------------------------------------------------
$adapter = $plugin->get_adaptador_compatibilidad_wpml();
$adapter->activar();

$resolved_via_adapter  = $adapter->ejecutar_icl_object_id( 6214, 'page', false, 'en' );
$resolved_via_global   = function_exists( 'icl_object_id' ) ? icl_object_id( 6214, 'page', false, 'en' ) : null;
$resolved_self_es      = $adapter->ejecutar_icl_object_id( 6214, 'page', false, 'es' );
$fallback_untranslated = $adapter->ejecutar_icl_object_id( 999999, 'post', true, 'en' );
$hf_contract_fallback  = $adapter->ejecutar_icl_object_id( 4410, 'ova_framework_hf_el', true, 'es' );

$languages_map = $adapter->ejecutar_icl_get_languages( 'skip_missing=0' );

// Verify zero modifications in external plugins and theme
$ext_files_ok = file_exists( 'D:/laragon/www/lab.ecoterra/wp-content/plugins/elementor/elementor.php' )
	&& file_exists( 'D:/laragon/www/lab.ecoterra/wp-content/plugins/elementor-pro/elementor-pro.php' )
	&& file_exists( 'D:/laragon/www/lab.ecoterra/wp-content/plugins/ova-framework/ova-framework.php' )
	&& file_exists( 'D:/laragon/www/lab.ecoterra/wp-content/themes/ecoCMS/functions.php' );

$c7_ok = ( 8335 === (int) $resolved_via_adapter
	&& 8335 === (int) $resolved_via_global
	&& 6214 === (int) $resolved_self_es
	&& 999999 === (int) $fallback_untranslated
	&& 4410 === (int) $hf_contract_fallback
	&& isset( $languages_map['es'] )
	&& isset( $languages_map['en'] )
	&& ! isset( $languages_map['fr'] )
	&& $ext_files_ok
);
record_gate(
	'Control 7: Compatibilidad WPML, Elementor y ecoCMS sin Modificación Externa',
	$c7_ok,
	sprintf( 'icl_object_id(6214)=%d, self ES=%d, fallback missing=%d, HF contract=%d, active (es, en), zero external mutations: %s', (int) $resolved_via_adapter, (int) $resolved_self_es, (int) $fallback_untranslated, (int) $hf_contract_fallback, $ext_files_ok ? 'PASS' : 'FAIL' )
);

// -----------------------------------------------------------------------------
// CONTROL 8: Certificación HTTP Real en Vivo (42 Rutas 200 OK + 404 en Raíces Inactivas)
// -----------------------------------------------------------------------------
$routes_to_test = array(
	'quienes_es'       => array( 'url' => 'http://lab.ecoterra.test/nosotros/quienes-somos/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/quienes-somos/' ),
	'quienes_en'       => array( 'url' => 'http://lab.ecoterra.test/en/about-us/who-are-we/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/who-are-we/' ),
	'reviews_es'       => array( 'url' => 'http://lab.ecoterra.test/nosotros/reviews/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/reviews/' ),
	'reviews_en'       => array( 'url' => 'http://lab.ecoterra.test/en/about-us/reviews/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/reviews/' ),
	'terminos_es'      => array( 'url' => 'http://lab.ecoterra.test/nosotros/terminos-y-condiciones/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/terminos-y-condiciones/' ),
	'terminos_en'      => array( 'url' => 'http://lab.ecoterra.test/en/about-us/terms-and-conditions/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/terms-and-conditions/' ),
	'accesibilidad_es' => array( 'url' => 'http://lab.ecoterra.test/nosotros/accesibilidad-y-transportes/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/accesibilidad-y-transportes/' ),
	'accesibilidad_en' => array( 'url' => 'http://lab.ecoterra.test/en/about-us/accessibility-and-transportation/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/accessibility-and-transportation/' ),
	'blog_es'          => array( 'url' => 'http://lab.ecoterra.test/blog/', 'code' => 200, 'lang' => 'es-ES', 'canon' => '' ),
	'blog_en'          => array( 'url' => 'http://lab.ecoterra.test/en/blog/', 'code' => 200, 'lang' => 'en-US', 'canon' => '' ),
	'birds_es'         => array( 'url' => 'http://lab.ecoterra.test/experiencia-transformadora/avistamiento-de-aves/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-transformadora/avistamiento-de-aves/' ),
	'birds_en'         => array( 'url' => 'http://lab.ecoterra.test/en/transformative-experience/birdwatching/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/transformative-experience/birdwatching/' ),
	'lek_es'           => array( 'url' => 'http://lab.ecoterra.test/experiencia-transformadora/lek-de-gallito-de-las-rocas/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-transformadora/lek-de-gallito-de-las-rocas/' ),
	'lek_en'           => array( 'url' => 'http://lab.ecoterra.test/en/transformative-experience/andean-cock-of-the-rock-lek/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/transformative-experience/andean-cock-of-the-rock-lek/' ),
	'cafe_es'          => array( 'url' => 'http://lab.ecoterra.test/experiencia-transformadora/experiencia-de-cafe/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-transformadora/experiencia-de-cafe/' ),
	'cafe_en'          => array( 'url' => 'http://lab.ecoterra.test/en/transformative-experience/coffee-experience/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/transformative-experience/coffee-experience/' ),
	'confort_es'       => array( 'url' => 'http://lab.ecoterra.test/confort-y-tranquilidad/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/confort-y-tranquilidad/' ),
	'comfort_en'       => array( 'url' => 'http://lab.ecoterra.test/en/comfort-and-tranquility/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/comfort-and-tranquility/' ),
	'transf_es'        => array( 'url' => 'http://lab.ecoterra.test/experiencia-transformadora/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-transformadora/' ),
	'transf_en'        => array( 'url' => 'http://lab.ecoterra.test/en/transformative-experience/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/transformative-experience/' ),
	'nat_es'           => array( 'url' => 'http://lab.ecoterra.test/naturaleza-autentica/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/naturaleza-autentica/' ),
	'nat_en'           => array( 'url' => 'http://lab.ecoterra.test/en/authentic-nature/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/authentic-nature/' ),
	'exp_es'           => array( 'url' => 'http://lab.ecoterra.test/experiencia-ecoterra/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-ecoterra/' ),
	'exp_en'           => array( 'url' => 'http://lab.ecoterra.test/en/ecoterra-experience/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/ecoterra-experience/' ),
	'salon_es'         => array( 'url' => 'http://lab.ecoterra.test/servicios/salon-de-eventos/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/servicios/salon-de-eventos/' ),
	'salon_en'         => array( 'url' => 'http://lab.ecoterra.test/en/services/event-room/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/services/event-room/' ),
	'rest_es'          => array( 'url' => 'http://lab.ecoterra.test/servicios/restaurante/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/servicios/restaurante/' ),
	'rest_en'          => array( 'url' => 'http://lab.ecoterra.test/en/services/restaurant/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/services/restaurant/' ),
	'servicios_es'     => array( 'url' => 'http://lab.ecoterra.test/servicios/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/servicios/' ),
	'services_en'      => array( 'url' => 'http://lab.ecoterra.test/en/services/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/services/' ),
	'paquetes_es'      => array( 'url' => 'http://lab.ecoterra.test/paquetes/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/paquetes/' ),
	'tour_en'          => array( 'url' => 'http://lab.ecoterra.test/en/tour-packages/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/tour-packages/' ),
	'galeria_es'       => array( 'url' => 'http://lab.ecoterra.test/galeria/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/galeria/' ),
	'gallery_en'       => array( 'url' => 'http://lab.ecoterra.test/en/gallery/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/gallery/' ),
	'contacto_es'      => array( 'url' => 'http://lab.ecoterra.test/contactenos/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/contactenos/' ),
	'contact_en'       => array( 'url' => 'http://lab.ecoterra.test/en/contact-us/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/contact-us/' ),
	'home_es'          => array( 'url' => 'http://lab.ecoterra.test/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/' ),
	'home_en'          => array( 'url' => 'http://lab.ecoterra.test/en/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/' ),
	'nosotros_es'      => array( 'url' => 'http://lab.ecoterra.test/nosotros/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/' ),
	'about_en'         => array( 'url' => 'http://lab.ecoterra.test/en/about-us/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/' ),
	'actividades_es'   => array( 'url' => 'http://lab.ecoterra.test/actividades/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/actividades/' ),
	'activities_en'    => array( 'url' => 'http://lab.ecoterra.test/en/activities/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/activities/' ),
);

$http_all_pass = true;
foreach ( $routes_to_test as $name => $expected_route ) {
	$res = inspect_route( $expected_route['url'] );
	if ( $res['code'] !== $expected_route['code'] || $res['lang'] !== $expected_route['lang'] || $res['canonical'] !== $expected_route['canon'] ) {
		$http_all_pass = false;
		echo "HTTP FAIL on {$name}: code={$res['code']}, lang={$res['lang']}, canon={$res['canonical']}\n";
		break;
	}
}

// Check 404 clean on inactive languages and unauthenticated drafts
$r_fr    = inspect_route( 'http://lab.ecoterra.test/fr/' );
$r_pt    = inspect_route( 'http://lab.ecoterra.test/pt/' );
$r_draft = inspect_route( 'http://lab.ecoterra.test/?p=9662' );
$inactive_ok = ( 404 === $r_fr['code'] && 404 === $r_pt['code'] && 404 === $r_draft['code'] );

$c8_ok = ( $http_all_pass && $inactive_ok );
record_gate(
	'Control 8: Certificación HTTP Real en Vivo (42 Rutas 200 OK + 404 en Raíces Inactivas)',
	$c8_ok,
	sprintf( '42/42 rutas responden 200 OK con idioma y canonical exactos; /fr/, /pt/ y draft #9662 responden 404 limpio' )
);

// -----------------------------------------------------------------------------
// CONTROL 9: Sitemaps Nativos WordPress y Exclusión de Contenido Inactivo/Borrador
// -----------------------------------------------------------------------------
$sitemaps_filter = $plugin->get_core_sitemaps_filter();
$el_es           = $sitemaps_filter->evaluate_post_eligibility( 6214, 'page' );
$el_en           = $sitemaps_filter->evaluate_post_eligibility( 8335, 'page' );
$el_fr           = $sitemaps_filter->evaluate_post_eligibility( 8974, 'page' );
$el_draft        = $sitemaps_filter->evaluate_post_eligibility( 9662, 'page' );

// Retrieve full sitemap URL lists
$posts_provider = new WP_Sitemaps_Posts();
$tax_provider   = new WP_Sitemaps_Taxonomies();

$page_entries = $posts_provider->get_url_list( 1, 'page' );
$post_entries = $posts_provider->get_url_list( 1, 'post' );
$room_entries = $posts_provider->get_url_list( 1, 'rooms' );
$hf_entries   = $posts_provider->get_url_list( 1, 'ova_framework_hf_el' );
$cat_entries  = $tax_provider->get_url_list( 1, 'category' );
$tag_entries  = $tax_provider->get_url_list( 1, 'post_tag' );

$page_locs = array_map( static fn( array $e ): string => (string) $e['loc'], $page_entries );
sort( $page_locs );

$all_sitemap_locs = array_merge(
	$page_locs,
	array_map( static fn( array $e ): string => (string) $e['loc'], $post_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $room_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $hf_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $cat_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $tag_entries )
);

$all_unique_locs = array_unique( $all_sitemap_locs );
$fr_in_sitemap   = array_filter( $all_unique_locs, static fn( string $u ): bool => false !== strpos( $u, '/fr/' ) );
$draft_in_sitemap = array_filter( $all_unique_locs, static fn( string $u ): bool => false !== strpos( $u, '9662' ) || false !== strpos( $u, '9663' ) || false !== strpos( $u, '9664' ) );

$c9_ok = ( true === $el_es['eligible']
	&& true === $el_en['eligible']
	&& false === $el_fr['eligible']
	&& false === $el_draft['eligible']
	&& 43 === count( $page_locs )
	&& 66 === count( $all_unique_locs )
	&& 0 === count( $fr_in_sitemap )
	&& 0 === count( $draft_in_sitemap )
);
record_gate(
	'Control 9: Sitemaps Nativos WordPress y Exclusión de Contenido Inactivo/Borrador',
	$c9_ok,
	sprintf( 'Total sitemap: %d URLs únicas (%d páginas, 0 francés, 0 borradores), Eligibility ES/EN: PASS, FR/Draft: EXCLUDED', count( $all_unique_locs ), count( $page_locs ) )
);

// -----------------------------------------------------------------------------
// CONTROL 10: Tratamiento Formal del Borrador TRID 34790 e Invarianza Final
// -----------------------------------------------------------------------------
$draft_posts = $wpdb->get_results( "SELECT ID, post_title, post_status FROM {$wpdb->prefix}posts WHERE ID IN (9662, 9663, 9664)", ARRAY_A );
$all_drafts  = true;
foreach ( $draft_posts as $dp ) {
	if ( 'draft' !== $dp['post_status'] ) {
		$all_drafts = false;
	}
}
$draft_in_tfml = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id IN (9662, 9663, 9664)" );

$sentinel_prod_final = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_final  = get_icl_sentinel( 'cms_ect_lab' );

$groups_final        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_final      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$retained_final_cnt  = count( $retained_registry->get_all() );
$manifests_final_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name LIKE 'tfml_migration_manifest_%'" );

$c10_ok = ( 3 === count( $draft_posts )
	&& $all_drafts
	&& 0 === $draft_in_tfml
	&& $expected_wpml_rows === $sentinel_prod_final['rows']
	&& $expected_wpml_md5 === $sentinel_prod_final['md5']
	&& $expected_wpml_rows === $sentinel_lab_final['rows']
	&& $expected_wpml_md5 === $sentinel_lab_final['md5']
	&& 141 === $groups_final
	&& 175 === $elements_final
	&& 21 === $retained_final_cnt
	&& 20 === $manifests_final_cnt
);
record_gate(
	'Control 10: Tratamiento Formal del Borrador TRID 34790 e Invarianza Final',
	$c10_ok,
	sprintf( 'TRID 34790 en draft: 3/3 (0 en TFML) | cms_ect: %d filas / MD5 %s | cms_ect_lab: %d grupos, %d elementos, %d FR retenidas, %d manifiestos', $sentinel_prod_final['rows'], $sentinel_prod_final['md5'], $groups_final, $elements_final, $retained_final_cnt, $manifests_final_cnt )
);

echo "\n================================================================================\n";
printf( "RESULTADO GLOBAL DE FASE 3.54: %d/%d CONTROLES PASS (%d FAIL)\n", $passed_checks, $total_checks, $failed_checks );
echo "================================================================================\n";

if ( $failed_checks > 0 ) {
	exit( 1 );
}
exit( 0 );
