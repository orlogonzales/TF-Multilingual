<?php
/**
 * Script reproducible de verificación — Fase 3.38 (Directiva DT-TFML-068).
 *
 * Certifica de forma no destructiva (0 escrituras en BD):
 * 1. Preflight bloqueante e invariancia centinela en cms_ect y cms_ect_lab (Sección 2).
 * 2. Contrato explícito de elegibilidad en 6 categorías (A..F) e independencia absoluta de WPML (Secciones 3 y 4).
 * 3. Corrección de consultas SQL de sitemaps (posts_clauses y terms_clauses), paginación y conteos (Sección 5).
 * 4. Exclusión estricta de idiomas inactivos (16 páginas FR, 11 habitaciones FR, 2 cabeceras FR) y retenidos (8839) (Sección 6).
 * 5. Exclusión de traducciones inglesas no migradas (21 páginas EN, 11 habitaciones EN, 1 categoría EN) y 24 posts sintéticos (Secciones 7 y 10).
 * 6. Exclusión de CPTs técnicos (ova_framework_hf_el, elementor_library) y preservación de CPTs comerciales (rooms, tours, product) (Sección 8).
 * 7. Matriz pre/post completa de los 6 proveedores (page, post, rooms, ova_framework_hf_el, category, post_tag), URLs canónicas sin duplicados y no regresión HTTP/BD (Secciones 9, 11, 12, 13 y 14).
 *
 * Uso: php scratch/verify_fase_3_38.php
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

namespace TF\Multilingual\Scratch;

use mysqli;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Seo\CoreSitemapsFilter;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use WP_Post;
use WP_Query;
use WP_Sitemaps_Index;
use WP_Sitemaps_Posts;
use WP_Sitemaps_Registry;
use WP_Sitemaps_Taxonomies;
use WP_Term;
use WP_Term_Query;

// Cargar entorno aislado de laboratorio (cms_ect_lab).
$lab_wp_load = 'D:/laragon/www/lab.ecoterra/wp-load.php';
if ( ! file_exists( $lab_wp_load ) ) {
	fwrite( STDERR, "[FAIL] No se encontró wp-load.php en el laboratorio: {$lab_wp_load}\n" );
	exit( 1 );
}

require_once $lab_wp_load;

$total_checks  = 0;
$passed_checks = 0;
$failed_checks = 0;
$lab_url       = 'http://lab.ecoterra.test';

/**
 * Registra el resultado de un control de verificación.
 *
 * @param string $label     Descripción del control.
 * @param bool   $condition Condición evaluada.
 * @param string $details   Detalles adicionales.
 * @return void
 */
function record_gate( string $label, bool $condition, string $details = '' ): void {
	global $total_checks, $passed_checks, $failed_checks;
	++$total_checks;

	if ( $condition ) {
		++$passed_checks;
		printf( "[PASS #%02d] %s%s\n", $total_checks, $label, '' !== $details ? " — {$details}" : '' );
	} else {
		++$failed_checks;
		printf( "[FAIL #%02d] %s%s\n", $total_checks, $label, '' !== $details ? " — {$details}" : '' );
	}
}

/**
 * Calcula el conteo y hash MD5 determinista de etk_t_icl_translations en una base de datos dada.
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

/**
 * Ejecuta una petición HTTP GET ligera contra el laboratorio local.
 *
 * @param string $url URL a consultar.
 * @return array{code: int, lang: string, canonical: string, noindex: bool, php_errors: bool}
 */
function inspect_http( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_USERAGENT      => 'TFML-Fase-3.38-Verifier/1.0',
		)
	);
	$raw          = curl_exec( $ch );
	$header_size  = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$code         = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$raw     = is_string( $raw ) ? $raw : '';
	$headers = substr( $raw, 0, $header_size );
	$body    = substr( $raw, $header_size );

	$lang = '';
	if ( preg_match( '/<html[^>]*lang=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$lang = trim( $m[1] );
	}

	$canonical = '';
	if ( preg_match( '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']/i', $body, $cm ) ) {
		$canonical = trim( $cm[1] );
	}

	$noindex    = ( false !== stripos( $headers, 'noindex' ) || false !== stripos( $body, 'noindex' ) );
	$php_errors = (bool) preg_match( '/(Fatal error|Uncaught Error|Uncaught Exception|Parse error|Warning:\s|Deprecated:\s)/i', $body );

	return array(
		'code'       => $code,
		'lang'       => $lang,
		'canonical'  => $canonical,
		'noindex'    => $noindex,
		'php_errors' => $php_errors,
	);
}

echo "================================================================================\n";
echo "VERIFICACIÓN REPRODUCIBLE — FASE 3.38 (DT-TFML-068): INTEGRIDAD Y ELEGIBILIDAD DE SITEMAPS\n";
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
$cron_disabled = defined( 'DISABLE_WP_CRON' ) && true === constant( 'DISABLE_WP_CRON' );
$http_blocked  = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && true === constant( 'WP_HTTP_BLOCK_EXTERNAL' );

$plugin         = Plugin::get_instance();
$lang_reg       = $plugin->get_language_registry();
$sitemap_filter = $plugin->get_core_sitemaps_filter();
$active_codes   = array_values(
	array_map(
		static fn( $lang ): string => $lang->get_code(),
		$lang_reg->active()
	)
);

record_gate(
	'Entorno aislado cms_ect_lab conectado, TFML activo, WPML inactivo, idiomas activos [es,en,pt], fr inactivo y protecciones de aislamiento activas',
	'cms_ect_lab' === $db_name
		&& 'etk_t_' === $table_prefix
		&& $tfml_active
		&& $wpml_inactive
		&& array( 'es', 'en', 'pt' ) === $active_codes
		&& ! $lang_reg->is_active( 'fr' )
		&& '0' === $blog_public
		&& $cron_disabled
		&& $http_blocked,
	sprintf( 'DB=%s, langs=[%s], fr_active=false, blog_public=%s, cron_off=%s, http_block=%s', $db_name, implode( ',', $active_codes ), $blog_public, $cron_disabled ? 'true' : 'false', $http_blocked ? 'true' : 'false' )
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
$manifest_pilot = ( new WpmlMigrationRollbackService( $wpdb ) )->get_execution_manifest( 'pilot-trid-197-partial' );
$lock_mgr       = new MigrationExecutionLock( $wpdb );

record_gate(
	'Línea base TFML intacta: 121 grupos, 135 elementos, grupo 587 (197:es, 8356:en), 0 filas TFML para 8839, 1 retenido (fr:8839), 12 manifiestos y lock libre',
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
		&& ! $lock_mgr->is_locked( 'wpml' ),
	sprintf( 'groups=%d, elements=%d, retained=%d, manifests=%d', $groups_count, $elements_count, count( $retained_all ), $manifests_cnt )
);

// ============================================================================
// BLOQUE 2: CONTRATO DE ELEGIBILIDAD (CATEGORÍAS A..F) E INDEPENDENCIA DE WPML (SECCIONES 3 Y 4)
// ============================================================================
echo "\n--- BLOQUE 2: Contrato de Elegibilidad (Categorías A..F) e Independencia de WPML (Secciones 3 y 4) ---\n";

$eval_197  = $sitemap_filter->evaluate_post_eligibility( get_post( 197 ), 'page' );
$eval_8356 = $sitemap_filter->evaluate_post_eligibility( get_post( 8356 ), 'page' );
$eval_4338 = $sitemap_filter->evaluate_post_eligibility( get_post( 4338 ), 'page' );
$eval_5687 = $sitemap_filter->evaluate_post_eligibility( get_post( 5687 ), 'post' );
$eval_t50  = $sitemap_filter->evaluate_term_eligibility( get_term( 50, 'category' ), 'category' );
$eval_t58  = $sitemap_filter->evaluate_term_eligibility( get_term( 58, 'post_tag' ), 'post_tag' );

$eval_8839_ret = $sitemap_filter->evaluate_post_eligibility( get_post( 8839 ), 'page' );
$eval_4804_hf  = $sitemap_filter->evaluate_post_eligibility( get_post( 4804 ), 'ova_framework_hf_el' );
$eval_8327_en  = $sitemap_filter->evaluate_post_eligibility( get_post( 8327 ), 'page' );
$eval_8823_fr  = $sitemap_filter->evaluate_post_eligibility( get_post( 8823 ), 'page' );
$eval_9220_dr  = $sitemap_filter->evaluate_post_eligibility( get_post( 9220 ), 'page' );

// Probar Categoría B (Contenido monolingüe acreditado) en memoria y limpiar inmediatamente.
$sitemap_filter->accredit_monolingual_element( 'post', 9864 );
$eval_9864_mono = $sitemap_filter->evaluate_post_eligibility( get_post( 9864 ), 'page' );
$sitemap_filter->clear_accredited_monolingual_elements();
$eval_9864_restored = $sitemap_filter->evaluate_post_eligibility( get_post( 9864 ), 'page' );

// Verificar las 24 entradas sintéticas (15576..15666).
$synthetic_ids = $wpdb->get_col(
	"SELECT p.ID FROM {$wpdb->posts} p
	 LEFT JOIN {$wpdb->prefix}icl_translations icl ON (icl.element_id = p.ID AND icl.element_type = 'post_post')
	 LEFT JOIN {$wpdb->prefix}tfml_group_elements ge ON (ge.element_id = p.ID AND ge.element_type = 'post')
	 WHERE p.post_type = 'post' AND p.post_status = 'publish' AND icl.translation_id IS NULL AND ge.id IS NULL
	 ORDER BY p.ID ASC"
);
$synthetic_all_unclassified = ( 24 === count( $synthetic_ids ) );
foreach ( $synthetic_ids as $sid ) {
	$ev = $sitemap_filter->evaluate_post_eligibility( get_post( (int) $sid ), 'post' );
	if ( $ev['eligible'] || CoreSitemapsFilter::CATEGORY_UNCLASSIFIED !== $ev['category'] ) {
		$synthetic_all_unclassified = false;
	}
}

record_gate(
	'Contrato de elegibilidad determinista (Categorías A..F): acredita TFML activos (A), acredita monolingües autorizados (B), excluye sintéticos no clasificados (C) y WPML no migrados (D), excluye retenidos (E), excluye CPTs técnicos (F) y borradores',
	true === $eval_197['eligible'] && CoreSitemapsFilter::CATEGORY_TFML_ACCREDITED === $eval_197['category']
		&& true === $eval_8356['eligible'] && CoreSitemapsFilter::CATEGORY_TFML_ACCREDITED === $eval_8356['category']
		&& true === $eval_4338['eligible'] && CoreSitemapsFilter::CATEGORY_TFML_ACCREDITED === $eval_4338['category']
		&& true === $eval_5687['eligible'] && CoreSitemapsFilter::CATEGORY_TFML_ACCREDITED === $eval_5687['category']
		&& true === $eval_t50['eligible'] && CoreSitemapsFilter::CATEGORY_TFML_ACCREDITED === $eval_t50['category']
		&& true === $eval_t58['eligible'] && CoreSitemapsFilter::CATEGORY_TFML_ACCREDITED === $eval_t58['category']
		&& true === $eval_9864_mono['eligible'] && CoreSitemapsFilter::CATEGORY_ACCREDITED_MONOLINGUAL === $eval_9864_mono['category']
		&& false === $eval_9864_restored['eligible'] && CoreSitemapsFilter::CATEGORY_UNCLASSIFIED === $eval_9864_restored['category']
		&& $synthetic_all_unclassified
		&& false === $eval_8327_en['eligible'] && CoreSitemapsFilter::CATEGORY_UNCLASSIFIED === $eval_8327_en['category']
		&& false === $eval_8823_fr['eligible'] && CoreSitemapsFilter::CATEGORY_UNCLASSIFIED === $eval_8823_fr['category']
		&& false === $eval_8839_ret['eligible'] && CoreSitemapsFilter::CATEGORY_RETAINED_TRANSLATION === $eval_8839_ret['category']
		&& false === $eval_4804_hf['eligible'] && CoreSitemapsFilter::CATEGORY_TECHNICAL_CONTENT === $eval_4804_hf['category']
		&& false === $eval_9220_dr['eligible'] && CoreSitemapsFilter::CATEGORY_NON_PUBLIC_STATUS === $eval_9220_dr['category'],
	sprintf(
		'A=[197:%s,8356:%s,5687:%s,t50:%s,t58:%s], B_mono=%s, C_synth=%d/24, D_unmig=[8327:%s,8823:%s], E_ret=8839:%s, F_tech=4804:%s, draft=9220:%s',
		$eval_197['category'],
		$eval_8356['category'],
		$eval_5687['category'],
		$eval_t50['category'],
		$eval_t58['category'],
		$eval_9864_mono['category'],
		count( $synthetic_ids ),
		$eval_8327_en['category'],
		$eval_8823_fr['category'],
		$eval_8839_ret['category'],
		$eval_4804_hf['category'],
		$eval_9220_dr['category']
	)
);

// Auditar que durante la ejecución completa de los proveedores de sitemaps se realicen 0 consultas a icl_*.
$icl_queries_detected = array();
$query_spy            = static function ( string $sql ) use ( &$icl_queries_detected ): string {
	if ( false !== stripos( $sql, 'icl_' ) ) {
		$icl_queries_detected[] = $sql;
	}
	return $sql;
};
add_filter( 'query', $query_spy, 1 );

$posts_provider = new WP_Sitemaps_Posts();
$tax_provider   = new WP_Sitemaps_Taxonomies();

$post_page_entries = $posts_provider->get_url_list( 1, 'page' );
$post_post_entries = $posts_provider->get_url_list( 1, 'post' );
$post_room_entries = $posts_provider->get_url_list( 1, 'rooms' );
$post_hf_entries   = $posts_provider->get_url_list( 1, 'ova_framework_hf_el' );
$tax_cat_entries   = $tax_provider->get_url_list( 1, 'category' );
$tax_tag_entries   = $tax_provider->get_url_list( 1, 'post_tag' );

$max_pages_page  = $posts_provider->get_max_num_pages( 'page' );
$max_pages_post  = $posts_provider->get_max_num_pages( 'post' );
$max_pages_rooms = $posts_provider->get_max_num_pages( 'rooms' );
$max_pages_hf    = $posts_provider->get_max_num_pages( 'ova_framework_hf_el' );
$max_pages_cat   = $tax_provider->get_max_num_pages( 'category' );
$max_pages_tag   = $tax_provider->get_max_num_pages( 'post_tag' );

remove_filter( 'query', $query_spy, 1 );

record_gate(
	'Independencia absoluta de WPML: 0 consultas SQL a tablas icl_* durante toda la generación de sitemaps e índice',
	0 === count( $icl_queries_detected ),
	sprintf( 'icl_queries_count=%d', count( $icl_queries_detected ) )
);

// ============================================================================
// BLOQUE 3: EXCLUSIÓN DE IDIOMAS INACTIVOS, TRADUCCIONES PENDIENTES Y TIPOS TÉCNICOS (SECCIONES 6, 7 Y 8)
// ============================================================================
echo "\n--- BLOQUE 3: Exclusión de Idiomas Inactivos, Traducciones Pendientes y Tipos Técnicos (Secciones 6, 7 y 8) ---\n";

$all_emitted_locs = array_merge(
	array_column( $post_page_entries, 'loc' ),
	array_column( $post_post_entries, 'loc' ),
	array_column( $post_room_entries, 'loc' ),
	array_column( $post_hf_entries, 'loc' ),
	array_column( $tax_cat_entries, 'loc' ),
	array_column( $tax_tag_entries, 'loc' )
);

// Verificar las 16 páginas FR publicadas + 8839 retenida + 11 habitaciones FR + 2 cabeceras FR.
$fr_published_ids = $wpdb->get_col(
	"SELECT p.ID FROM {$wpdb->prefix}icl_translations icl
	 JOIN {$wpdb->posts} p ON p.ID = icl.element_id
	 WHERE icl.language_code = 'fr' AND icl.element_type IN ('post_page', 'post_rooms', 'post_ova_framework_hf_el')
	   AND p.post_status = 'publish'
	 ORDER BY p.ID ASC"
);
$fr_leaked = array();
foreach ( $fr_published_ids as $fid ) {
	$fpost = get_post( (int) $fid );
	$fperm = get_permalink( $fpost );
	if ( in_array( $fperm, $all_emitted_locs, true ) || in_array( $lab_url . '/fr/', $all_emitted_locs, true ) ) {
		$fr_leaked[] = (int) $fid;
	}
	$feval = $sitemap_filter->evaluate_post_eligibility( $fpost, (string) $fpost->post_type );
	if ( $feval['eligible'] ) {
		$fr_leaked[] = (int) $fid;
	}
}

record_gate(
	'Exclusión total de idiomas inactivos y retenidos: 0/16 páginas FR publicadas, 0/1 retenida (8839), 0/11 habitaciones FR y 0/2 cabeceras FR en sitemaps',
	30 === count( $fr_published_ids ) && 0 === count( $fr_leaked ),
	sprintf( 'fr_published_checked=%d (16_page + 1_retained_8839 + 11_rooms + 2_hf), leaked=%d', count( $fr_published_ids ), count( $fr_leaked ) )
);

// Verificar las 21 páginas EN no migradas + 11 habitaciones EN no migradas + term 1 (Uncategorized EN).
$en_unmigrated_posts = $wpdb->get_col(
	"SELECT p.ID FROM {$wpdb->prefix}icl_translations icl
	 JOIN {$wpdb->posts} p ON p.ID = icl.element_id
	 LEFT JOIN {$wpdb->prefix}tfml_group_elements ge ON (ge.element_id = p.ID AND ge.element_type = 'post')
	 WHERE icl.language_code = 'en' AND icl.element_type IN ('post_page', 'post_rooms')
	   AND p.post_status = 'publish' AND ge.id IS NULL
	 ORDER BY p.ID ASC"
);
$en_unmigrated_leaked = array();
foreach ( $en_unmigrated_posts as $eid ) {
	$epost     = get_post( (int) $eid );
	$raw_perm  = (string) get_permalink( $epost );
	$fake_en   = str_replace( $lab_url . '/', $lab_url . '/en/', $raw_perm );
	if ( in_array( $raw_perm, $all_emitted_locs, true ) || in_array( $fake_en, $all_emitted_locs, true ) ) {
		$en_unmigrated_leaked[] = (int) $eid;
	}
}
$term_1_link   = (string) get_term_link( 1, 'category' );
$term_1_leaked = in_array( $term_1_link, $all_emitted_locs, true );

record_gate(
	'Exclusión de contenido inglés pendiente de migración: 0/21 páginas EN no migradas, 0/11 habitaciones EN no migradas y 0/1 categoría EN no migrada expuestas sin prefijo o con URL /en/ inexistente',
	32 === count( $en_unmigrated_posts )
		&& 0 === count( $en_unmigrated_leaked )
		&& ! $term_1_leaked,
	sprintf( 'en_unmigrated_checked=%d (21_page + 11_rooms), leaked=%d, term_1_leaked=%s', count( $en_unmigrated_posts ), count( $en_unmigrated_leaked ), $term_1_leaked ? 'true' : 'false' )
);

$registered_subtypes = array_keys( $posts_provider->get_object_subtypes() );
$filtered_sample_pts = array_keys(
	$sitemap_filter->filter_sitemaps_post_types(
		array(
			'page'                => (object) array( 'name' => 'page' ),
			'post'                => (object) array( 'name' => 'post' ),
			'rooms'               => (object) array( 'name' => 'rooms' ),
			'tours'               => (object) array( 'name' => 'tours' ),
			'product'             => (object) array( 'name' => 'product' ),
			'ova_framework_hf_el' => (object) array( 'name' => 'ova_framework_hf_el' ),
			'elementor_library'   => (object) array( 'name' => 'elementor_library' ),
			'e-floating-buttons'  => (object) array( 'name' => 'e-floating-buttons' ),
		)
	)
);
record_gate(
	'Exclusión nativa de tipos técnicos (ova_framework_hf_el, elementor_library, e-floating-buttons) en wp_sitemaps_post_types preservando CPTs comerciales (rooms, tours, product)',
	! in_array( 'ova_framework_hf_el', $registered_subtypes, true )
		&& ! in_array( 'elementor_library', $registered_subtypes, true )
		&& ! in_array( 'e-floating-buttons', $registered_subtypes, true )
		&& array( 'post', 'page', 'rooms' ) === $registered_subtypes
		&& array( 'page', 'post', 'rooms', 'tours', 'product' ) === $filtered_sample_pts
		&& 0 === $max_pages_hf
		&& 0 === count( $post_hf_entries ),
	sprintf( 'lab_subtypes=[%s], contract_preserved=[%s], hf_pages=%d, hf_urls=%d', implode( ',', $registered_subtypes ), implode( ',', $filtered_sample_pts ), $max_pages_hf, count( $post_hf_entries ) )
);

// ============================================================================
// BLOQUE 4: MATRIZ PRE/POST DE LOS 6 PROVEEDORES, URLS CANÓNICAS Y PAGINACIÓN (SECCIONES 5, 9, 10 Y 12)
// ============================================================================
echo "\n--- BLOQUE 4: Matriz Pre/Post de Proveedores, URLs Canónicas y Paginación (Secciones 5, 9, 10 y 12) ---\n";

$expected_page_locs = array(
	$lab_url . '/',
	$lab_url . '/old-blog/',
	$lab_url . '/en/',
);
$actual_page_locs   = array_column( $post_page_entries, 'loc' );
$actual_post_locs   = array_column( $post_post_entries, 'loc' );
$actual_cat_locs    = array_column( $tax_cat_entries, 'loc' );
$actual_tag_locs    = array_column( $tax_tag_entries, 'loc' );

record_gate(
	'Matriz post-3.38 exacta por proveedor: page=3 (/, /old-blog/, /en/), post=3 (0 sintéticos), rooms=0 (max_pages=0), ova_framework_hf_el=0 (max_pages=0), category=1 (term 50 Blog), post_tag=19',
	3 === count( $post_page_entries )
		&& $expected_page_locs === $actual_page_locs
		&& 3 === count( $post_post_entries )
		&& 0 === count( $post_room_entries )
		&& 0 === count( $post_hf_entries )
		&& 1 === count( $tax_cat_entries )
		&& 19 === count( $tax_tag_entries )
		&& 1 === $max_pages_page
		&& 1 === $max_pages_post
		&& 0 === $max_pages_rooms
		&& 0 === $max_pages_hf
		&& 1 === $max_pages_cat
		&& 1 === $max_pages_tag,
	sprintf(
		'page=%d(p=%d), post=%d(p=%d), rooms=%d(p=%d), hf=%d(p=%d), cat=%d(p=%d), tag=%d(p=%d)',
		count( $post_page_entries ),
		$max_pages_page,
		count( $post_post_entries ),
		$max_pages_post,
		count( $post_room_entries ),
		$max_pages_rooms,
		count( $post_hf_entries ),
		$max_pages_hf,
		count( $tax_cat_entries ),
		$max_pages_cat,
		count( $tax_tag_entries ),
		$max_pages_tag
	)
);

// Verificar 0 duplicados en todos los proveedores y HTTP 200 en las URLs de page, post y category.
$unique_all_locs = array_unique( $all_emitted_locs );
$zero_duplicates = ( count( $all_emitted_locs ) === count( $unique_all_locs ) );

$urls_to_check_http = array_merge( $actual_page_locs, $actual_post_locs, $actual_cat_locs );
$all_http_200       = true;
foreach ( $urls_to_check_http as $u ) {
	$res = inspect_http( (string) $u );
	if ( 200 !== $res['code'] || $res['php_errors'] ) {
		$all_http_200 = false;
	}
}

// Prueba de paginación con elementos excluidos (forzando max_urls = 2 temporalmente).
$limit_two_cb = static fn(): int => 2;
add_filter( 'wp_sitemaps_max_urls', $limit_two_cb, 99 );

$paginated_max_pages = $posts_provider->get_max_num_pages( 'page' );
$paginated_page_1    = $posts_provider->get_url_list( 1, 'page' );
$paginated_page_2    = $posts_provider->get_url_list( 2, 'page' );
$paginated_page_3    = $posts_provider->get_url_list( 3, 'page' );

$paginated_tax_max   = $tax_provider->get_max_num_pages( 'post_tag' );
$paginated_tax_p1    = $tax_provider->get_url_list( 1, 'post_tag' );
$paginated_tax_p10   = $tax_provider->get_url_list( 10, 'post_tag' );

remove_filter( 'wp_sitemaps_max_urls', $limit_two_cb, 99 );

record_gate(
	'Cero URLs duplicadas en todos los proveedores, HTTP 200 OK en todas las entradas de page/post/category y paginación SQL exacta sin páginas vacías (max_urls=2 -> page: 2 págs [2+1], post_tag: 10 págs [2..+1])',
	$zero_duplicates
		&& $all_http_200
		&& 2 === $paginated_max_pages
		&& 2 === count( $paginated_page_1 )
		&& 1 === count( $paginated_page_2 )
		&& 0 === count( $paginated_page_3 )
		&& 10 === $paginated_tax_max
		&& 2 === count( $paginated_tax_p1 )
		&& 1 === count( $paginated_tax_p10 ),
	sprintf(
		'total_locs=%d, unique=%d, http_200=%s, page_p1=%d, page_p2=%d, tag_max_p=%d, tag_p10=%d',
		count( $all_emitted_locs ),
		count( $unique_all_locs ),
		$all_http_200 ? 'true' : 'false',
		count( $paginated_page_1 ),
		count( $paginated_page_2 ),
		$paginated_tax_max,
		count( $paginated_tax_p10 )
	)
);

// ============================================================================
// BLOQUE 5: NO REGRESIÓN DEL FILTRADO PÚBLICO Y PROTECCIÓN DEL LABORATORIO (SECCIONES 11, 13 Y 14)
// ============================================================================
echo "\n--- BLOQUE 5: No Regresión del Filtrado Público y Protección del Laboratorio (Secciones 11, 13 y 14) ---\n";

$http_home_es = inspect_http( $lab_url . '/' );
$http_home_en = inspect_http( $lab_url . '/en/' );
$http_fr      = inspect_http( $lab_url . '/fr/' );
$http_pt      = inspect_http( $lab_url . '/pt/' );
$http_sitemap = inspect_http( $lab_url . '/wp-sitemap.xml' );

$public_q                = new WP_Query( array( 'post_type' => 'page' ) );
$public_q->is_main_query = true;
$public_filter_eligible  = $plugin->get_query_filter()->is_query_eligible( $public_q );

record_gate(
	'No regresión de rutas públicas (GET /=200 es-ES, GET /en/=200 en-US, GET /fr/=404, GET /pt/=404), QueryLanguageFilter activo en consultas ordinarias y /wp-sitemap.xml protegido (404 con blog_public=0)',
	200 === $http_home_es['code'] && 'es-ES' === $http_home_es['lang'] && ! $http_home_es['php_errors']
		&& 200 === $http_home_en['code'] && 'en-US' === $http_home_en['lang'] && ! $http_home_en['php_errors']
		&& 404 === $http_fr['code']
		&& 404 === $http_pt['code']
		&& 404 === $http_sitemap['code']
		&& $public_filter_eligible,
	sprintf( '/=%d(%s), /en/=%d(%s), /fr/=%d, /pt/=%d, /wp-sitemap.xml=%d, query_filter=%s', $http_home_es['code'], $http_home_es['lang'], $http_home_en['code'], $http_home_en['lang'], $http_fr['code'], $http_pt['code'], $http_sitemap['code'], $public_filter_eligible ? 'true' : 'false' )
);

$sentinel_prod_post = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_post  = get_icl_sentinel( 'cms_ect_lab' );
$groups_post        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_post      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$retained_post      = count( ( new RetainedTranslationRegistry( $wpdb ) )->get_all( 'wpml' ) );
$manifests_post     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$front_post         = (int) get_raw_option_or_default( 'page_on_front' );
$blog_pub_post      = (string) get_option( 'blog_public' );

/**
 * Lee directamente el valor crudo de una opción en wp_options sin filtros de runtime.
 *
 * @param string $option_name Nombre de la opción.
 * @return string
 */
function get_raw_option_or_default( string $option_name ): string {
	global $wpdb;
	return (string) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$option_name
		)
	);
}

record_gate(
	'Invariancia post-fase: cero escrituras en cms_ect y cms_ect_lab (121 grupos, 135 elementos, 1 retenido, 12 manifiestos, page_on_front=197, blog_public=0)',
	3403 === $sentinel_prod_post['rows']
		&& $expected_md5 === $sentinel_prod_post['md5']
		&& 3403 === $sentinel_lab_post['rows']
		&& $expected_md5 === $sentinel_lab_post['md5']
		&& 121 === $groups_post
		&& 135 === $elements_post
		&& 1 === $retained_post
		&& 12 === $manifests_post
		&& 197 === $front_post
		&& '0' === $blog_pub_post,
	sprintf(
		'cms_ect=%d:%s | groups=%d, elements=%d, retained=%d, manifests=%d, page_on_front=%d, blog_public=%s',
		$sentinel_prod_post['rows'],
		$sentinel_prod_post['md5'],
		$groups_post,
		$elements_post,
		$retained_post,
		$manifests_post,
		$front_post,
		$blog_pub_post
	)
);

echo "\n================================================================================\n";
printf( "RESUMEN FASE 3.38: %d CONTROLES | %d PASS | %d FAIL\n", $total_checks, $passed_checks, $failed_checks );
echo "================================================================================\n";

exit( $failed_checks > 0 ? 1 : 0 );
