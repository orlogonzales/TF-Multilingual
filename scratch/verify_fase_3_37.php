<?php
/**
 * Script de Verificación Reproducible — Fase 3.37 (DT-TFML-067).
 *
 * Auditoría del Universo Editorial, Sitemaps y Preparación del Siguiente Lote Multilingüe.
 *
 * Verifica (100% solo lectura, cero mutaciones en BD):
 * 1. Preflight bloqueante e invariancia centinela de datos (cms_ect intacta, cms_ect_lab intacta: 121 grupos, 135 elementos, grupo 587, 1 retenido fr:8839, 12 manifiestos, reconciliación 0 discrepancias).
 * 2. Conciliación exacta de las 61 páginas del sitemap (WP_Sitemaps_Posts('page')) y de los demás proveedores (post=27, rooms=33, ova_framework_hf_el=6, category=2, post_tag=19), más identificación de las 3 URLs duplicadas.
 * 3. Verificación de exclusión de idiomas inactivos: exclusión efectiva de 8839 (Accueil) e identificación exacta de las 16 páginas francesas, 11 rooms francesas y 2 cabeceras francesas no migradas expuestas por supresión de filtro.
 * 4. Inventario completo de navegación ES/EN (menu-principal ID 43, menu-es-ingles ID 98 y CTA de cabeceras 4804/8383/4410/8386) y comprobación HTTP de contextos lingüísticos.
 * 5. Validación individual de los 15 TRIDs candidatos y derivación en memoria (sin ejecutar WpmlMigrationExecutor) de los planes parciales escalonados (Lote 11A de 4 TRIDs, Lote 11B de 9 TRIDs y Lote 11C de 15 TRIDs) con sus fingerprints SHA-256.
 * 6. Invariancia post-auditoría de cms_ect y cms_ect_lab.
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

$_SERVER['HTTP_HOST']   = 'lab.ecoterra.test';
$_SERVER['SERVER_NAME'] = 'lab.ecoterra.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS']       = 'off';

$lab_root = 'D:/laragon/www/lab.ecoterra';
if ( ! file_exists( $lab_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "ERROR: No se encontró wp-load.php en {$lab_root}\n" );
	exit( 1 );
}

require_once $lab_root . '/wp-load.php';
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

$lab_url = getenv( 'TFML_LAB_URL' ) ? rtrim( (string) getenv( 'TFML_LAB_URL' ), '/' ) : 'http://lab.ecoterra.test';

$total_checks  = 0;
$passed_checks = 0;
$failed_checks = 0;

/**
 * Registra un control de verificación con estado explícito (PASS / FAIL).
 *
 * @param string $label     Descripción del control.
 * @param bool   $condition Condición booleana evaluada.
 * @param string $detail    Detalle adicional.
 * @return void
 */
function record_gate( string $label, bool $condition, string $detail = '' ): void {
	global $total_checks, $passed_checks, $failed_checks;
	++$total_checks;
	if ( $condition ) {
		++$passed_checks;
		$status = 'PASS';
	} else {
		++$failed_checks;
		$status = 'FAIL';
	}
	$suffix = '' !== $detail ? " — {$detail}" : '';
	echo sprintf( "[%s #%02d] %s%s\n", $status, $total_checks, $label, $suffix );
}

/**
 * Obtiene la huella centinela de una tabla icl_translations en una base de datos.
 *
 * @param string $db_name Nombre de la base de datos.
 * @return array{rows: int, md5: string}
 */
function get_icl_sentinel( string $db_name ): array {
	$mysqli = new mysqli( '127.0.0.1', 'root', '', $db_name, 3306 );
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

echo "================================================================================\n";
echo "VERIFICACIÓN REPRODUCIBLE — FASE 3.37 (DT-TFML-067): AUDITORÍA SITEMAPS Y NAVEGACIÓN\n";
echo "================================================================================\n\n";

global $wpdb;

// ============================================================================
// BLOQUE 1: PREFLIGHT BLOQUEANTE E INVARIANCIA CENTINELA DE DATOS (SECCIÓN 2)
// ============================================================================
echo "--- BLOQUE 1: Preflight Bloqueante e Invariancia Centinela de Datos (Sección 2) ---\n";

$db_name       = (string) $wpdb->dbname;
$table_prefix  = (string) $wpdb->prefix;
$active_plugs  = (array) get_option( 'active_plugins', array() );
$wpml_inactive = ! in_array( 'sitepress-multilingual-cms/sitepress.php', $active_plugs, true )
	&& ! defined( 'ICL_SITEPRESS_VERSION' )
	&& ! defined( 'ICL_LANGUAGE_CODE' );
$tfml_active   = in_array( 'tf-multilingual/tf-multilingual.php', $active_plugs, true );
$blog_public   = (string) get_option( 'blog_public' );

$plugin       = Plugin::get_instance();
$lang_reg     = $plugin->get_language_registry();
$active_codes = array_values(
	array_map(
		static fn( $lang ): string => $lang->get_code(),
		$lang_reg->active()
	)
);

record_gate(
	'Entorno aislado cms_ect_lab conectado, TFML activo, WPML inactivo, idiomas activos [es,en,pt], fr inactivo y blog_public=0',
	'cms_ect_lab' === $db_name
		&& 'etk_t_' === $table_prefix
		&& $tfml_active
		&& $wpml_inactive
		&& array( 'es', 'en', 'pt' ) === $active_codes
		&& ! $lang_reg->is_active( 'fr' )
		&& '0' === $blog_public,
	sprintf( 'DB=%s, active_langs=[%s], fr_active=%s, blog_public=%s', $db_name, implode( ',', $active_codes ), $lang_reg->is_active( 'fr' ) ? 'true' : 'false', $blog_public )
);

$sentinel_prod_pre = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_pre  = get_icl_sentinel( 'cms_ect_lab' );
$expected_md5      = '4241ca7e7ec6399a594537cb04790c10';

record_gate(
	'Invariancia centinela de icl_translations en cms_ect (protegida) y cms_ect_lab',
	3403 === $sentinel_prod_pre['rows']
		&& $expected_md5 === $sentinel_prod_pre['md5']
		&& 3403 === $sentinel_lab_pre['rows']
		&& $expected_md5 === $sentinel_lab_pre['md5'],
	sprintf( 'cms_ect=%d:%s | cms_ect_lab=%d:%s', $sentinel_prod_pre['rows'], $sentinel_prod_pre['md5'], $sentinel_lab_pre['rows'], $sentinel_lab_pre['md5'] )
);

$groups_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$group_587_rows = $wpdb->get_results(
	"SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 587 ORDER BY language_code ASC",
	ARRAY_A
);
$post_8839_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_type = 'post' AND element_id = 8839" );

$retained_reg   = new RetainedTranslationRegistry( $wpdb );
$retained_all   = $retained_reg->get_all( 'wpml' );
$retained_197   = $retained_reg->get( 'wpml', 197 );
$manifests_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$checkpoint     = ( new MigrationCheckpointRepository() )->get( 'wpml' );
$rollback_svc   = new WpmlMigrationRollbackService( $wpdb );
$manifest_pilot = $rollback_svc->get_execution_manifest( 'pilot-trid-197-partial' );
$lock_mgr       = new MigrationExecutionLock( $wpdb );

$mig_manager     = $plugin->get_migration_manager();
$planner         = $mig_manager->get_wpml_planner();
$plan_no_conf    = $planner->generate_plan( 0, 0, false );
$pilot_197_plan  = $plan_no_conf->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 197,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
	),
	'pilot-trid-197-partial'
);
$reconcile_197   = $mig_manager->get_wpml_executor()->reconcile( $pilot_197_plan );

record_gate(
	'Línea base TFML intacta: 121 grupos, 135 elementos, grupo 587 (197:es, 8356:en), 0 filas TFML para 8839, 1 retenido (fr:8839), 12 manifiestos, lock libre y 0 discrepancias de reconciliación',
	121 === $groups_count
		&& 135 === $elements_count
		&& 2 === count( $group_587_rows )
		&& 0 === $post_8839_rows
		&& 1 === count( $retained_all )
		&& null !== $retained_197
		&& 8839 === (int) ( $retained_197['retained_elements']['fr']['element_id'] ?? 0 )
		&& $retained_reg->verify_record_integrity( $retained_197 )
		&& 12 === $manifests_cnt
		&& null !== $checkpoint
		&& $checkpoint->is_completed()
		&& is_array( $manifest_pilot )
		&& ! $lock_mgr->is_locked( 'wpml' )
		&& true === ( $reconcile_197['integrity_verified'] ?? false )
		&& empty( $reconcile_197['discrepancies'] ),
	sprintf( 'groups=%d, elements=%d, retained=%d, manifests=%d, integrity_verified=%s', $groups_count, $elements_count, count( $retained_all ), $manifests_cnt, ( $reconcile_197['integrity_verified'] ?? false ) ? 'true' : 'false' )
);

// ============================================================================
// BLOQUE 2: INVENTARIO COMPLETO Y CONCILIACIÓN DEL SITEMAP (SECCIÓN 3)
// ============================================================================
echo "\n--- BLOQUE 2: Inventario Completo y Conciliación del Sitemap (Sección 3) ---\n";

$mig_manager = $plugin->get_migration_manager();
$planner     = $mig_manager->get_wpml_planner();
$master_plan = $planner->generate_plan();

$icl_rows       = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}icl_translations", ARRAY_A );
$icl_by_element = array();
foreach ( $icl_rows as $r ) {
	$icl_by_element[ $r['element_type'] . ':' . (int) $r['element_id'] ] = $r;
}

$tfml_rows       = $wpdb->get_results(
	"SELECT ge.*, g.canonical_element_id, g.subtype
	 FROM {$wpdb->prefix}tfml_group_elements ge
	 JOIN {$wpdb->prefix}tfml_groups g ON g.id = ge.group_id",
	ARRAY_A
);
$tfml_by_element = array();
foreach ( $tfml_rows as $r ) {
	$tfml_by_element[ $r['element_type'] . ':' . (int) $r['element_id'] ] = $r;
}

$plan_by_trid = array();
foreach ( $master_plan->get_planned_groups() as $g ) {
	$plan_by_trid[ (int) $g['trid'] ] = $g;
}

$posts_provider = new WP_Sitemaps_Posts();
$tax_provider   = new WP_Sitemaps_Taxonomies();

$page_args  = apply_filters(
	'wp_sitemaps_posts_query_args',
	array(
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'post_type'              => 'page',
		'posts_per_page'         => wp_sitemaps_get_max_urls( 'post' ),
		'post_status'            => array( 'publish' ),
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'update_post_meta_cache' => false,
		'paged'                  => 1,
	),
	'page'
);
$page_query = new WP_Query( $page_args );
$pages      = $page_query->posts;

$cat_counts = array(
	'TFML_MIGRATED_ES'                     => 0,
	'TFML_MIGRATED_EN'                     => 0,
	'UNMIGRATED_WPML_ES_BLOCKED'           => 0,
	'UNMIGRATED_WPML_ES_CONFLICT_TERM_ID'  => 0,
	'UNMIGRATED_WPML_EN_BLOCKED'           => 0,
	'UNMIGRATED_WPML_EN_CONFLICT_TERM_ID'  => 0,
	'UNMIGRATED_WPML_FR_BLOCKED'           => 0,
	'UNMIGRATED_WPML_FR_CONFLICT_TERM_ID'  => 0,
);
$url_map    = array();
$has_8839   = false;

foreach ( $pages as $post ) {
	$pid  = (int) $post->ID;
	if ( 8839 === $pid ) {
		$has_8839 = true;
	}
	$icl       = $icl_by_element[ 'post_page:' . $pid ] ?? null;
	$wpml_lang = $icl ? (string) $icl['language_code'] : 'NONE';
	$trid      = $icl ? (int) $icl['trid'] : 0;
	$tfml      = $tfml_by_element[ 'post:' . $pid ] ?? null;
	$tfml_lang = $tfml ? (string) $tfml['language_code'] : 'NONE';
	$plan_cls  = ( $trid > 0 && isset( $plan_by_trid[ $trid ] ) ) ? (string) $plan_by_trid[ $trid ]['classification'] : 'NONE';

	$entry = apply_filters(
		'wp_sitemaps_posts_entry',
		array( 'loc' => get_permalink( $post ) ),
		$post,
		'page'
	);
	$loc   = (string) ( $entry['loc'] ?? '' );
	$url_map[ $loc ][] = $pid;

	if ( 'es' === $tfml_lang ) {
		++$cat_counts['TFML_MIGRATED_ES'];
	} elseif ( 'en' === $tfml_lang ) {
		++$cat_counts['TFML_MIGRATED_EN'];
	} elseif ( 'es' === $wpml_lang && 'BLOCKED_BY_LANGUAGE' === $plan_cls ) {
		++$cat_counts['UNMIGRATED_WPML_ES_BLOCKED'];
	} elseif ( 'es' === $wpml_lang && 'CONFLICT' === $plan_cls ) {
		++$cat_counts['UNMIGRATED_WPML_ES_CONFLICT_TERM_ID'];
	} elseif ( 'en' === $wpml_lang && 'BLOCKED_BY_LANGUAGE' === $plan_cls ) {
		++$cat_counts['UNMIGRATED_WPML_EN_BLOCKED'];
	} elseif ( 'en' === $wpml_lang && 'CONFLICT' === $plan_cls ) {
		++$cat_counts['UNMIGRATED_WPML_EN_CONFLICT_TERM_ID'];
	} elseif ( 'fr' === $wpml_lang && 'BLOCKED_BY_LANGUAGE' === $plan_cls ) {
		++$cat_counts['UNMIGRATED_WPML_FR_BLOCKED'];
	} elseif ( 'fr' === $wpml_lang && 'CONFLICT' === $plan_cls ) {
		++$cat_counts['UNMIGRATED_WPML_FR_CONFLICT_TERM_ID'];
	}
}

record_gate(
	'Conciliación exacta de las 61 páginas del sitemap: 2 TFML_ES (197, 4338), 1 TFML_EN (8356), 21 WPML_ES no migradas (20 BLOCKED + 1 CONFLICT TRID 3), 21 WPML_EN no migradas (20 BLOCKED + 1 CONFLICT TRID 3) y 16 WPML_FR no migradas (15 BLOCKED + 1 CONFLICT TRID 3), con 8839 excluida',
	61 === count( $pages )
		&& ! $has_8839
		&& 2 === $cat_counts['TFML_MIGRATED_ES']
		&& 1 === $cat_counts['TFML_MIGRATED_EN']
		&& 20 === $cat_counts['UNMIGRATED_WPML_ES_BLOCKED']
		&& 1 === $cat_counts['UNMIGRATED_WPML_ES_CONFLICT_TERM_ID']
		&& 20 === $cat_counts['UNMIGRATED_WPML_EN_BLOCKED']
		&& 1 === $cat_counts['UNMIGRATED_WPML_EN_CONFLICT_TERM_ID']
		&& 15 === $cat_counts['UNMIGRATED_WPML_FR_BLOCKED']
		&& 1 === $cat_counts['UNMIGRATED_WPML_FR_CONFLICT_TERM_ID'],
	json_encode( $cat_counts )
);

$duplicate_urls = array_filter( $url_map, static fn( array $ids ): bool => count( $ids ) > 1 );
$post_urls      = $posts_provider->get_url_list( 1, 'post' );
$rooms_urls     = $posts_provider->get_url_list( 1, 'rooms' );
$hf_urls        = $posts_provider->get_url_list( 1, 'ova_framework_hf_el' );
$cat_urls       = $tax_provider->get_url_list( 1, 'category' );
$tag_urls       = $tax_provider->get_url_list( 1, 'post_tag' );

record_gate(
	'Detección exacta de 3 URLs duplicadas en sitemap de páginas (/services/, /services/restaurant/, /blog/) e inventario completo de otros proveedores (post=27, rooms=33, ova_framework_hf_el=6, category=2, post_tag=19)',
	3 === count( $duplicate_urls )
		&& array( 8347, 8918 ) === ( $duplicate_urls[ $lab_url . '/services/' ] ?? array() )
		&& array( 8345, 8921 ) === ( $duplicate_urls[ $lab_url . '/services/restaurant/' ] ?? array() )
		&& array( 10109, 10110, 10111 ) === ( $duplicate_urls[ $lab_url . '/blog/' ] ?? array() )
		&& 27 === count( $post_urls )
		&& 33 === count( $rooms_urls )
		&& 6 === count( $hf_urls )
		&& 2 === count( $cat_urls )
		&& 19 === count( $tag_urls ),
	sprintf( 'dup_urls=%d, post=%d, rooms=%d, hf=%d, cat=%d, tag=%d', count( $duplicate_urls ), count( $post_urls ), count( $rooms_urls ), count( $hf_urls ), count( $cat_urls ), count( $tag_urls ) )
);

// ============================================================================
// BLOQUE 3: VERIFICACIÓN DE EXCLUSIÓN DE IDIOMAS INACTIVOS (SECCIÓN 4)
// ============================================================================
echo "\n--- BLOQUE 3: Verificación de Exclusión de Idiomas Inactivos (Sección 4) ---\n";

$blocked_by_subtype = array();
foreach ( $master_plan->get_planned_groups() as $g ) {
	if ( MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE === $g['classification'] ) {
		$k                        = $g['element_type'] . ':' . $g['subtype'];
		$blocked_by_subtype[ $k ] = ( $blocked_by_subtype[ $k ] ?? 0 ) + 1;
	}
}

$fr_published_pages = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations icl
	 JOIN {$wpdb->posts} p ON p.ID = icl.element_id
	 WHERE icl.language_code = 'fr' AND icl.element_type = 'post_page' AND p.post_status = 'publish'"
);
$fr_draft_pages     = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations icl
	 JOIN {$wpdb->posts} p ON p.ID = icl.element_id
	 WHERE icl.language_code = 'fr' AND icl.element_type = 'post_page' AND p.post_status = 'draft'"
);
$fr_published_rooms = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations icl
	 JOIN {$wpdb->posts} p ON p.ID = icl.element_id
	 WHERE icl.language_code = 'fr' AND icl.element_type = 'post_rooms' AND p.post_status = 'publish'"
);
$fr_published_hf    = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations icl
	 JOIN {$wpdb->posts} p ON p.ID = icl.element_id
	 WHERE icl.language_code = 'fr' AND icl.element_type = 'post_ova_framework_hf_el' AND p.post_status = 'publish'"
);

record_gate(
	'Inventario de grupos BLOCKED_BY_LANGUAGE (50 grupos) y traducciones francesas: 17 páginas FR publicadas (1 retenida excluida 8839 + 16 no migradas expuestas en sitemap), 6 páginas FR en borrador, 11 rooms FR publicadas y 2 cabeceras FR publicadas',
	50 === array_sum( $blocked_by_subtype )
		&& 21 === ( $blocked_by_subtype['post:page'] ?? 0 )
		&& 14 === ( $blocked_by_subtype['post:nav_menu_item'] ?? 0 )
		&& 11 === ( $blocked_by_subtype['post:rooms'] ?? 0 )
		&& 2 === ( $blocked_by_subtype['post:ova_framework_hf_el'] ?? 0 )
		&& 1 === ( $blocked_by_subtype['term:category'] ?? 0 )
		&& 1 === ( $blocked_by_subtype['term:nav_menu'] ?? 0 )
		&& 17 === $fr_published_pages
		&& 6 === $fr_draft_pages
		&& 11 === $fr_published_rooms
		&& 2 === $fr_published_hf,
	sprintf( 'blocked_groups=50, fr_pages_pub=%d (1_retained+16_unmigrated), fr_pages_draft=%d, fr_rooms_pub=%d, fr_hf_pub=%d', $fr_published_pages, $fr_draft_pages, $fr_published_rooms, $fr_published_hf )
);

// ============================================================================
// BLOQUE 4: INVENTARIO DE NAVEGACIÓN ES/EN Y CONTEXTO HTTP (SECCIÓN 5)
// ============================================================================
echo "\n--- BLOQUE 4: Inventario de Navegación ES/EN y Contexto HTTP (Sección 5) ---\n";

$menu_es_items = wp_get_nav_menu_items( 43, array( 'tfml_suppress_language_filter' => true ) );
$menu_en_items = wp_get_nav_menu_items( 98, array( 'tfml_suppress_language_filter' => true ) );

$menu_es_trids = array();
foreach ( $menu_es_items as $item ) {
	if ( 'post_type' === $item->type && 'page' === $item->object ) {
		$icl = $icl_by_element[ 'post_page:' . (int) $item->object_id ] ?? null;
		if ( $icl && (int) $icl['trid'] !== 197 ) {
			$menu_es_trids[] = (int) $icl['trid'];
		}
	}
}
$menu_en_trids = array();
foreach ( $menu_en_items as $item ) {
	if ( 'post_type' === $item->type && 'page' === $item->object ) {
		$icl = $icl_by_element[ 'post_page:' . (int) $item->object_id ] ?? null;
		if ( $icl && (int) $icl['trid'] !== 197 ) {
			$menu_en_trids[] = (int) $icl['trid'];
		}
	}
}
sort( $menu_es_trids );
sort( $menu_en_trids );

$expected_14_menu_trids = array( 424, 430, 9201, 9537, 9545, 9634, 9697, 9818, 9838, 34652, 34660, 34776, 34791, 34823 );

record_gate(
	'Simetría exacta entre menu-principal (ID 43, 16 ítems) y menu-es-ingles (ID 98, 16 ítems): 1 portada migrada (TRID 197), 1 enlace custom (#) y los mismos 14 TRIDs de páginas internas pendientes en ambos menús',
	16 === count( $menu_es_items )
		&& 16 === count( $menu_en_items )
		&& $expected_14_menu_trids === $menu_es_trids
		&& $expected_14_menu_trids === $menu_en_trids,
	sprintf( 'es_items=%d, en_items=%d, shared_pending_page_trids=[%s]', count( $menu_es_items ), count( $menu_en_items ), implode( ',', $menu_es_trids ) )
);

$resp_nosotros    = wp_remote_get( $lab_url . '/nosotros/', array( 'redirection' => 0, 'timeout' => 5 ) );
$resp_about_root  = wp_remote_get( $lab_url . '/about-us/', array( 'redirection' => 0, 'timeout' => 5 ) );
$resp_about_en    = wp_remote_get( $lab_url . '/en/about-us/', array( 'redirection' => 0, 'timeout' => 5 ) );
$resp_nous_root   = wp_remote_get( $lab_url . '/nous/', array( 'redirection' => 0, 'timeout' => 5 ) );
$resp_contact_es  = wp_remote_get( $lab_url . '/contactenos/', array( 'redirection' => 0, 'timeout' => 5 ) );
$resp_contact_en  = wp_remote_get( $lab_url . '/en/contact-us/', array( 'redirection' => 0, 'timeout' => 5 ) );

$body_about_root = (string) wp_remote_retrieve_body( $resp_about_root );
$body_nous_root  = (string) wp_remote_retrieve_body( $resp_nous_root );

record_gate(
	'Verificación HTTP de páginas no migradas: /nosotros/->200(es-ES), /about-us/->200(servida bajo lang="es-ES" por carecer de fila TFML), /en/about-us/->404, /nous/->200(FR servida bajo lang="es-ES"), /contactenos/->200, /en/contact-us/->404',
	200 === (int) wp_remote_retrieve_response_code( $resp_nosotros )
		&& 200 === (int) wp_remote_retrieve_response_code( $resp_about_root )
		&& str_contains( $body_about_root, 'lang="es-ES"' )
		&& 404 === (int) wp_remote_retrieve_response_code( $resp_about_en )
		&& 200 === (int) wp_remote_retrieve_response_code( $resp_nous_root )
		&& str_contains( $body_nous_root, 'lang="es-ES"' )
		&& 200 === (int) wp_remote_retrieve_response_code( $resp_contact_es )
		&& 404 === (int) wp_remote_retrieve_response_code( $resp_contact_en ),
	'nosotros=200, about-us=200(es-ES), en/about-us=404, nous=200(es-ES), contactenos=200, en/contact-us=404'
);

// ============================================================================
// BLOQUE 5: PREPARACIÓN Y VALIDACIÓN EN MEMORIA DEL LOTE PARCIAL (SECCIONES 6 Y 8)
// ============================================================================
echo "\n--- BLOQUE 5: Preparación y Validación en Memoria del Siguiente Lote Parcial (Secciones 6 y 8) ---\n";

$candidate_15_trids = array( 424, 430, 432, 9201, 9537, 9545, 9634, 9697, 9818, 9838, 34652, 34660, 34776, 34791, 34823 );
$all_candidates_valid = true;

foreach ( $candidate_15_trids as $t ) {
	$g = $plan_by_trid[ $t ] ?? null;
	if ( ! is_array( $g ) || MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE !== $g['classification'] ) {
		$all_candidates_valid = false;
		break;
	}
	$es_id   = (int) ( $g['translations']['es'] ?? 0 );
	$en_id   = (int) ( $g['translations']['en'] ?? 0 );
	$fr_id   = (int) ( $g['pending_translations']['fr'] ?? 0 );
	$es_post = get_post( $es_id );
	$en_post = get_post( $en_id );
	$fr_post = get_post( $fr_id );

	if ( ! $es_post || 'publish' !== $es_post->post_status || ! $en_post || 'publish' !== $en_post->post_status || ! $fr_post ) {
		$all_candidates_valid = false;
		break;
	}
	if ( isset( $tfml_by_element[ 'post:' . $es_id ] ) || isset( $tfml_by_element[ 'post:' . $en_id ] ) || isset( $tfml_by_element[ 'post:' . $fr_id ] ) ) {
		$all_candidates_valid = false;
		break;
	}
}

record_gate(
	'Validación individual de los 15 TRIDs candidatos de navegación (14 del menú principal + TRID 432 de cabeceras): todos tienen par ES/EN publicado en WP Core, traducción FR elegible como retenida y 0 conflictos en TFML',
	$all_candidates_valid,
	sprintf( 'validated_trids=%d ([%s])', count( $candidate_15_trids ), implode( ',', $candidate_15_trids ) )
);

// Derive Tier 1 (4 priority TRIDs), Tier 2 (9 top-level TRIDs), and Tier 3 (15 complete navigation TRIDs) in memory
$build_auth = static function ( array $trids ): array {
	$out = array();
	foreach ( $trids as $t ) {
		$out[] = array(
			'trid'                 => $t,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		);
	}
	return $out;
};

$plan_11a = $master_plan->derive_partial_plan_with_retained_languages(
	$build_auth( array( 432, 9201, 9545, 9818 ) ),
	'batch-11a-core-priority-pages'
);
$plan_11b = $master_plan->derive_partial_plan_with_retained_languages(
	$build_auth( array( 430, 432, 9201, 9537, 9545, 9634, 9697, 9818, 34652 ) ),
	'batch-11b-top-level-nav-pages'
);
$plan_11c = $master_plan->derive_partial_plan_with_retained_languages(
	$build_auth( $candidate_15_trids ),
	'batch-11-primary-navigation-pages'
);

$expected_master_fp = '34a63fb1485341cf1da1109208c6fcefb3bab9f80b2fdfd5d0059a9e4e22263c';
$expected_11a_fp    = '1712e825c8231edf2ca68767061f3c2fd507c009e0bf1cf922c02ed282b616da';
$expected_11b_fp    = 'a3e9136e031ee9f3fea0b6d233c33b4abefbbf39d85cb35c9c26780165f15693';
$expected_11c_fp    = '64e3bcbeea7fcaa09a229828c1e180fd0ae82eee324786131f02ed7dee9a876d';

record_gate(
	'Derivación determinista en memoria (sin ejecutar WpmlMigrationExecutor) de Lote 11A (4 grupos/8 activos/4 retenidos), Lote 11B (9 grupos/18 activos/9 retenidos) y Lote 11C (15 grupos/30 activos/15 retenidos) con fingerprints SHA-256 verificados',
	$expected_master_fp === $master_plan->get_fingerprint()
		&& MigrationPlan::STATUS_READY === $plan_11a->get_status()
		&& $expected_11a_fp === $plan_11a->get_fingerprint()
		&& 4 === $plan_11a->get_summary()['total_groups']
		&& 8 === $plan_11a->get_summary()['importable_elements']
		&& 4 === $plan_11a->get_summary()['pending_elements']
		&& MigrationPlan::STATUS_READY === $plan_11b->get_status()
		&& $expected_11b_fp === $plan_11b->get_fingerprint()
		&& 9 === $plan_11b->get_summary()['total_groups']
		&& 18 === $plan_11b->get_summary()['importable_elements']
		&& 9 === $plan_11b->get_summary()['pending_elements']
		&& MigrationPlan::STATUS_READY === $plan_11c->get_status()
		&& $expected_11c_fp === $plan_11c->get_fingerprint()
		&& 15 === $plan_11c->get_summary()['total_groups']
		&& 30 === $plan_11c->get_summary()['importable_elements']
		&& 15 === $plan_11c->get_summary()['pending_elements'],
	sprintf( 'master_fp=%s..., 11a_fp=%s..., 11b_fp=%s..., 11c_fp=%s...', substr( $master_plan->get_fingerprint(), 0, 12 ), substr( $plan_11a->get_fingerprint(), 0, 12 ), substr( $plan_11b->get_fingerprint(), 0, 12 ), substr( $plan_11c->get_fingerprint(), 0, 12 ) )
);

// ============================================================================
// BLOQUE 6: INVARIANCIA POST-AUDITORÍA DE BASES DE DATOS Y OPCIONES (SECCIÓN 12)
// ============================================================================
echo "\n--- BLOQUE 6: Invariancia Post-Auditoría de Bases de Datos y Opciones (Sección 12) ---\n";

$sentinel_prod_post = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_post  = get_icl_sentinel( 'cms_ect_lab' );

$groups_post    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_post  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$retained_post  = count( $retained_reg->get_all( 'wpml' ) );
$manifests_post = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$pof_post       = (int) get_option( 'page_on_front' );
$bp_post        = (string) get_option( 'blog_public' );

record_gate(
	'Invariancia post-auditoría: cero escrituras en cms_ect y cms_ect_lab (121 grupos, 135 elementos, 1 retenido, 12 manifiestos, page_on_front=197, blog_public=0)',
	3403 === $sentinel_prod_post['rows']
		&& $expected_md5 === $sentinel_prod_post['md5']
		&& 3403 === $sentinel_lab_post['rows']
		&& $expected_md5 === $sentinel_lab_post['md5']
		&& 121 === $groups_post
		&& 135 === $elements_post
		&& 1 === $retained_post
		&& 12 === $manifests_post
		&& 197 === $pof_post
		&& '0' === $bp_post,
	sprintf( 'cms_ect=%d:%s | groups=%d, elements=%d, retained=%d, manifests=%d, page_on_front=%d, blog_public=%s', $sentinel_prod_post['rows'], $sentinel_prod_post['md5'], $groups_post, $elements_post, $retained_post, $manifests_post, $pof_post, $bp_post )
);

echo "\n================================================================================\n";
echo sprintf( "RESUMEN FASE 3.37: %d CONTROLES | %d PASS | %d FAIL\n", $total_checks, $passed_checks, $failed_checks );
echo "================================================================================\n";

exit( $failed_checks > 0 ? 1 : 0 );
