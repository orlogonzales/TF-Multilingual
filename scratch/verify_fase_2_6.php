<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 2.6 (SEO Multilingüe)
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

// Bootstrap WordPress.
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/ExternalPluginIsolationGuard.php';

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

set_exception_handler(
	function( Throwable $e ) {
		echo "\nFATAL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
		exit( 1 );
	}
);

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Seo\CanonicalUrlManager;
use TF\Multilingual\Domain\Seo\CoreSitemapsFilter;
use TF\Multilingual\Domain\Seo\HreflangGenerator;
use TF\Multilingual\Domain\Seo\SeoFrontendFilter;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'WordPress environment not found.' );
}

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 2.6 (SEO & SITEMAPS)\n";
echo "====================================================================\n\n";

$assertions_passed = 0;
$assertions_failed = 0;

function assert_true( bool $condition, string $message ): void {
	global $assertions_passed, $assertions_failed;
	if ( $condition ) {
		echo " [PASS] $message\n";
		$assertions_passed++;
	} else {
		echo " [FAIL] $message\n";
		$assertions_failed++;
	}
}

// 0. Sentinel Pre-Flight Check
$wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$table_icl = $wpdb->prefix . 'icl_translations';
$pre_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_icl}`" );
$pre_md5   = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$table_icl}`"
);

echo "Sentinel WPML Pre-Flight:\n";
echo "  Rows: $pre_count (Expected: 3403)\n";
echo "  MD5:  $pre_md5 (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_count, 'WPML pre-count is exactly 3403' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_md5, 'WPML pre-checksum matches 4241ca7e7ec6399a594537cb04790c10' );

// Isolate external plugin callbacks during fixture creation
$guard = new ExternalPluginIsolationGuard();
$isolated_count = $guard->isolate();
echo "External Plugin Isolation Guard active: isolated $isolated_count callbacks.\n\n";

$created_posts  = array();
$created_terms  = array();
$created_groups = array();

try {
	// Initialize Subsystems
	$settings_repo = new SettingsRepository();
	$lang_registry = new LanguageRegistry( $settings_repo );

	// Configure Languages: es (default), en, pt
	if ( ! $lang_registry->has( 'es' ) ) {
		$lang_registry->add_language( Language::create( 'es', 'es_ES', 'Español', 'Spanish', true, 1 ), true );
	}
	if ( ! $lang_registry->has( 'en' ) ) {
		$lang_registry->add_language( Language::create( 'en', 'en_US', 'English', 'English', true, 2 ) );
	}
	if ( ! $lang_registry->has( 'pt' ) ) {
		$lang_registry->add_language( Language::create( 'pt', 'pt_PT', 'Português', 'Portuguese', true, 3 ) );
	}
	$lang_registry->persist();

	$group_repo         = new TranslationGroupRepository( $wpdb, $lang_registry );
	$resolver           = new ContentTranslationResolver( $group_repo, $lang_registry );
	$url_resolver       = new UrlLanguageResolver( $lang_registry );
	$url_generator      = new LocalizedUrlGenerator( $lang_registry, $url_resolver, $resolver );
	$hreflang_generator = new HreflangGenerator( $lang_registry, $group_repo, $resolver, $url_generator );
	$canonical_manager  = new CanonicalUrlManager( $lang_registry, $resolver, $url_generator, $url_resolver );
	$sitemaps_filter    = new CoreSitemapsFilter( $lang_registry, $resolver, $url_generator );
	$seo_frontend       = new SeoFrontendFilter( $hreflang_generator, $canonical_manager, $sitemaps_filter );

	echo "--- SECCION 1: Hreflang para Post Singular Publicado (ES, EN, PT + x-default) ---\n";

	$post_es = wp_insert_post(
		array(
			'post_title'   => 'Tour Selva Tropical ES Test',
			'post_name'    => 'tour-selva-tropical',
			'post_content' => 'Contenido selva tropical en español.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_es;

	$post_en = wp_insert_post(
		array(
			'post_title'   => 'Rainforest Tour EN Test',
			'post_name'    => 'rainforest-tour',
			'post_content' => 'Rainforest tour content in English.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_en;

	$post_pt = wp_insert_post(
		array(
			'post_title'   => 'Tour Floresta Tropical PT Test',
			'post_name'    => 'tour-floresta-tropical',
			'post_content' => 'Conteúdo floresta tropical em português.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_pt;

	$group_1 = $group_repo->create_group( 'post', 'post', $post_es, 'es' );
	$created_groups[] = $group_1->get_id();
	$group_repo->add_translation( $group_1->get_id(), $post_en, 'en' );
	$group_repo->add_translation( $group_1->get_id(), $post_pt, 'pt' );

	$variants_es = $hreflang_generator->get_post_hreflang_variants( $post_es );

	assert_true( isset( $variants_es['es'] ), 'Variante ES presente en hreflang' );
	assert_true( isset( $variants_es['en'] ), 'Variante EN presente en hreflang' );
	assert_true( isset( $variants_es['pt'] ), 'Variante PT presente en hreflang' );
	assert_true( isset( $variants_es['x-default'] ), 'Variante x-default presente en hreflang' );

	// ES is default language, URL must not have /es/ prefix
	assert_true( ! str_contains( $variants_es['es'], '/es/' ), 'Variante por defecto ES no tiene prefijo /es/' );
	assert_true( str_contains( $variants_es['en'], '/en/' ), 'Variante secundaria EN tiene prefijo /en/' );
	assert_true( str_contains( $variants_es['pt'], '/pt/' ), 'Variante secundaria PT tiene prefijo /pt/' );
	assert_true( $variants_es['x-default'] === $variants_es['es'], 'x-default apunta exactamente a la variante ES por defecto' );

	$variants_en = $hreflang_generator->get_post_hreflang_variants( $post_en );
	assert_true( $variants_en === $variants_es, 'Variantes generadas desde post EN son idénticas a las generadas desde ES' );

	echo "\n--- SECCION 2: Hreflang con Variante en Borrador (Invariante de Publicación) ---\n";

	$post_es_draft = wp_insert_post(
		array(
			'post_title'   => 'Aventura Cascada ES Test',
			'post_name'    => 'aventura-cascada',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_es_draft;

	$post_en_draft = wp_insert_post(
		array(
			'post_title'   => 'Waterfall Adventure EN Draft Test',
			'post_name'    => 'waterfall-adventure',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_en_draft;

	$group_draft = $group_repo->create_group( 'post', 'post', $post_es_draft, 'es' );
	$created_groups[] = $group_draft->get_id();
	$group_repo->add_translation( $group_draft->get_id(), $post_en_draft, 'en' );

	$variants_draft = $hreflang_generator->get_post_hreflang_variants( $post_es_draft );

	assert_true( isset( $variants_draft['es'] ), 'Post ES publicado está en hreflang' );
	assert_true( ! isset( $variants_draft['en'] ), 'Post EN en draft está estrictamente EXCLUIDO de hreflang' );
	assert_true( isset( $variants_draft['x-default'] ), 'x-default sigue presente apuntando al post publicado ES' );
	assert_true( count( $variants_draft ) === 2, 'Solo se indexan variantes publicadas (es y x-default)' );

	echo "\n--- SECCION 3: Invariante x-default cuando falta Variante por Defecto ---\n";

	$post_en_nodefault = wp_insert_post(
		array(
			'post_title'   => 'Volcano Expedition EN Test',
			'post_name'    => 'volcano-expedition',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_en_nodefault;

	$post_pt_nodefault = wp_insert_post(
		array(
			'post_title'   => 'Expedicao Vulcao PT Test',
			'post_name'    => 'expedicao-vulcao',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_pt_nodefault;

	$group_nodefault = $group_repo->create_group( 'post', 'post', $post_en_nodefault, 'en' );
	$created_groups[] = $group_nodefault->get_id();
	$group_repo->add_translation( $group_nodefault->get_id(), $post_pt_nodefault, 'pt' );

	$variants_nodefault = $hreflang_generator->get_post_hreflang_variants( $post_en_nodefault );

	assert_true( isset( $variants_nodefault['en'] ), 'Variante EN presente' );
	assert_true( isset( $variants_nodefault['pt'] ), 'Variante PT presente' );
	assert_true( ! isset( $variants_nodefault['es'] ), 'Variante ES no existe' );
	assert_true( ! isset( $variants_nodefault['x-default'] ), 'x-default está estrictamente AUSENTE cuando no existe variante en idioma por defecto' );

	echo "\n--- SECCION 4: Estado REVIEW Editorial vs Publicación ---\n";

	// Mark EN translation as needing review by lagging source_version_at_translation
	$group_fresh = $group_repo->find_by_element( 'post', $post_es );
	$elem_en = $group_fresh->get_translation( 'en' );
	$elem_en_review = $elem_en->with_source_version_at_translation( 0 );
	$group_repo->update_element( $elem_en_review );

	$variants_review = $hreflang_generator->get_post_hreflang_variants( $post_es );
	assert_true( isset( $variants_review['en'] ), 'Variante EN con estado editorial REVIEW permanece indexable en hreflang' );
	assert_true( str_contains( $variants_review['en'], '/en/' ), 'Variante EN mantiene URL canónica localizada mientras requiere revisión' );

	echo "\n--- SECCION 5: Hreflang para Términos de Taxonomía ---\n";

	$term_es = wp_insert_term( 'Ecoturismo ES Test', 'category', array( 'slug' => 'ecoturismo-test' ) );
	$term_es_id = (int) $term_es['term_id'];
	$created_terms[] = array( 'id' => $term_es_id, 'taxonomy' => 'category' );

	$term_en = wp_insert_term( 'Ecotourism EN Test', 'category', array( 'slug' => 'ecotourism-test' ) );
	$term_en_id = (int) $term_en['term_id'];
	$created_terms[] = array( 'id' => $term_en_id, 'taxonomy' => 'category' );

	$group_term = $group_repo->create_group( 'term', 'category', $term_es_id, 'es' );
	$created_groups[] = $group_term->get_id();
	$group_repo->add_translation( $group_term->get_id(), $term_en_id, 'en' );

	$variants_term = $hreflang_generator->get_term_hreflang_variants( $term_es_id, 'category' );

	assert_true( isset( $variants_term['es'] ), 'Variante término ES presente' );
	assert_true( isset( $variants_term['en'] ), 'Variante término EN presente' );
	assert_true( isset( $variants_term['x-default'] ), 'Variante término x-default presente' );
	assert_true( str_contains( $variants_term['en'], '/en/' ), 'Variante término EN tiene prefijo /en/' );

	echo "\n--- SECCION 6: Hreflang en Portada (Front Page / Home) ---\n";

	$variants_home = $hreflang_generator->get_front_page_hreflang_variants();

	assert_true( isset( $variants_home['es'] ), 'Portada ES presente' );
	assert_true( isset( $variants_home['en'] ), 'Portada EN presente' );
	assert_true( isset( $variants_home['pt'] ), 'Portada PT presente' );
	assert_true( isset( $variants_home['x-default'] ), 'Portada x-default presente' );

	echo "\n--- SECCION 7: Elegibilidad de Contexto SEO ---\n";

	assert_true( $hreflang_generator->is_eligible_context(), 'Contexto estándar es elegible' );

	echo "\n--- SECCION 8: Gestión de URL Canónica (CanonicalUrlManager) ---\n";

	$raw_canonical_es = get_permalink( $post_es );
	$canonical_filtered_es = $canonical_manager->filter_canonical_url( $raw_canonical_es, $post_es );
	assert_true( ! str_contains( $canonical_filtered_es, '/es/' ), 'Canonical para post ES permanece sin prefijo' );

	$raw_canonical_en = get_permalink( $post_en );
	$canonical_filtered_en = $canonical_manager->filter_canonical_url( $raw_canonical_en, $post_en );
	assert_true( str_contains( $canonical_filtered_en, '/en/' ), 'Canonical para post EN recibe prefijo /en/' );

	$raw_canonical_pt = get_permalink( $post_pt );
	$canonical_filtered_pt = $canonical_manager->filter_canonical_url( $raw_canonical_pt, $post_pt );
	assert_true( str_contains( $canonical_filtered_pt, '/pt/' ), 'Canonical para post PT recibe prefijo /pt/' );

	// Third party hooks simulation (Yoast / Rank Math)
	$third_party_en = $canonical_manager->filter_third_party_canonical( $raw_canonical_en );
	assert_true( is_string( $third_party_en ), 'Filtro de canonical de terceros retorna string válido' );

	$false_canonical = $canonical_manager->filter_third_party_canonical( false );
	assert_true( false === $false_canonical, 'Filtro de terceros respeta boolean false si plugin desactiva canonical' );

	echo "\n--- SECCION 9: Integración de Core XML Sitemaps (CoreSitemapsFilter) ---\n";

	$post_query_args = $sitemaps_filter->filter_posts_query_args( array( 'post_type' => 'post' ), 'post' );
	assert_true( isset( $post_query_args['tfml_suppress_filters'] ) && true === $post_query_args['tfml_suppress_filters'], 'wp_sitemaps_posts_query_args desactiva filtro restrictivo de idioma' );

	$tax_query_args = $sitemaps_filter->filter_taxonomies_query_args( array( 'taxonomy' => 'category' ), 'category' );
	assert_true( isset( $tax_query_args['tfml_suppress_filters'] ) && true === $tax_query_args['tfml_suppress_filters'], 'wp_sitemaps_taxonomies_query_args desactiva filtro restrictivo de idioma' );

	// Post entry localization
	$entry_raw_es = array( 'loc' => get_permalink( $post_es ) );
	$entry_filtered_es = $sitemaps_filter->filter_posts_entry( $entry_raw_es, get_post( $post_es ), 'post' );
	assert_true( isset( $entry_filtered_es['loc'] ) && ! str_contains( $entry_filtered_es['loc'], '/es/' ), 'Entrada de sitemap para post ES está localizada sin prefijo' );

	$entry_raw_en = array( 'loc' => get_permalink( $post_en ) );
	$entry_filtered_en = $sitemaps_filter->filter_posts_entry( $entry_raw_en, get_post( $post_en ), 'post' );
	assert_true( isset( $entry_filtered_en['loc'] ) && str_contains( $entry_filtered_en['loc'], '/en/' ), 'Entrada de sitemap para post EN está localizada con prefijo /en/' );

	// Draft post dropped from sitemap
	$entry_raw_draft = array( 'loc' => get_permalink( $post_en_draft ) );
	$entry_filtered_draft = $sitemaps_filter->filter_posts_entry( $entry_raw_draft, get_post( $post_en_draft ), 'post' );
	assert_true( empty( $entry_filtered_draft ), 'Entrada de sitemap para post en draft es descartada (array vacío)' );

	// Taxonomy entry localization
	$term_entry_raw_es = array( 'loc' => get_term_link( $term_es_id, 'category' ) );
	$term_entry_filtered_es = $sitemaps_filter->filter_taxonomies_entry( $term_entry_raw_es, get_term( $term_es_id, 'category' ), 'category' );
	assert_true( isset( $term_entry_filtered_es['loc'] ) && ! str_contains( $term_entry_filtered_es['loc'], '/es/' ), 'Entrada de sitemap para término ES está localizada sin prefijo' );

	$term_entry_raw_en = array( 'loc' => get_term_link( $term_en_id, 'category' ) );
	$term_entry_filtered_en = $sitemaps_filter->filter_taxonomies_entry( $term_entry_raw_en, get_term( $term_en_id, 'category' ), 'category' );
	assert_true( isset( $term_entry_filtered_en['loc'] ) && str_contains( $term_entry_filtered_en['loc'], '/en/' ), 'Entrada de sitemap para término EN está localizada con prefijo /en/' );

	echo "\n--- SECCION 10: Renderizado HTML de Etiquetas Hreflang en wp_head ---\n";

	// Hook into tfml_hreflang_variants to simulate post variants during frontend render
	$hook_callback = static function () use ( $variants_es ) {
		return $variants_es;
	};
	add_filter( 'tfml_hreflang_variants', $hook_callback );

	ob_start();
	$seo_frontend->render_hreflang_tags();
	$html_head = ob_get_clean();

	remove_filter( 'tfml_hreflang_variants', $hook_callback );

	assert_true( str_contains( $html_head, '<link rel="alternate" hreflang="es"' ), 'HTML contiene etiqueta alternate hreflang="es"' );
	assert_true( str_contains( $html_head, '<link rel="alternate" hreflang="en"' ), 'HTML contiene etiqueta alternate hreflang="en"' );
	assert_true( str_contains( $html_head, '<link rel="alternate" hreflang="pt"' ), 'HTML contiene etiqueta alternate hreflang="pt"' );
	assert_true( str_contains( $html_head, '<link rel="alternate" hreflang="x-default"' ), 'HTML contiene etiqueta alternate hreflang="x-default"' );

} finally {
	// Restore external callbacks
	$restored_count = $guard->restore();
	echo "\nExternal Plugin Isolation Guard restored $restored_count callbacks.\n";

	// Cleanup created fixtures
	echo "Cleaning up test fixtures...\n";
	foreach ( $created_posts as $pid ) {
		wp_delete_post( $pid, true );
	}
	foreach ( $created_terms as $tinfo ) {
		wp_delete_term( $tinfo['id'], $tinfo['taxonomy'] );
	}
	foreach ( $created_groups as $gid ) {
		$wpdb->delete( $wpdb->prefix . 'tfml_group_elements', array( 'group_id' => $gid ) );
		$wpdb->delete( $wpdb->prefix . 'tfml_groups', array( 'id' => $gid ) );
	}

	// Post-flight Sentinel Check
	$wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
	$post_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_icl}`" );
	$post_md5   = (string) $wpdb->get_var(
		"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$table_icl}`"
	);

	echo "\nSentinel WPML Post-Flight:\n";
	echo "  Rows: $post_count (Expected: 3403)\n";
	echo "  MD5:  $post_md5 (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

	assert_true( 3403 === $post_count, 'WPML post-count is exactly 3403 (Zero rows written to WPML)' );
	assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_md5, 'WPML post-checksum matches 4241ca7e7ec6399a594537cb04790c10 (Zero bit drift)' );
}

echo "====================================================================\n";
echo "  RESULTADOS TOTALES: $assertions_passed PASSED, $assertions_failed FAILED\n";
echo "====================================================================\n";

if ( $assertions_failed > 0 ) {
	exit( 1 );
}

exit( 0 );
