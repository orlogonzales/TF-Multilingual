<?php
/**
 * Script de Verificación Determinista — Fase 3.39 (DT-TFML-069).
 *
 * Localización Segura de Enlaces en NavMenuFrontendFilter, Auditoría de
 * Jerarquías WordPress (post_parent) y Preparación en Memoria del Piloto
 * de Navegación Bilingüe (2 TRIDs).
 *
 * Garantiza CERO mutaciones en `cms_ect` (producción) y `cms_ect_lab` (laboratorio).
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	exit( 'Ejecución permitida únicamente desde CLI.' );
}

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

$lab_root = 'D:/laragon/www/lab.ecoterra';
if ( ! file_exists( $lab_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "[FAIL] No se encontró wp-load.php en {$lab_root}\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'lab.ecoterra.test';
$_SERVER['SERVER_NAME'] = 'lab.ecoterra.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS']       = 'off';

require_once $lab_root . '/wp-load.php';
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}
ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Navigation\NavMenuFrontendFilter;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;

global $wpdb;

$gates  = array();
$failed = 0;

/**
 * Registra el resultado de una compuerta de verificación.
 *
 * @param string $name   Nombre del control.
 * @param bool   $passed Estado booleano.
 * @param string $detail Evidencia resumida.
 * @return void
 */
function record_gate( string $name, bool $passed, string $detail ): void {
	global $gates, $failed;
	$status  = $passed ? 'PASS' : 'FAIL';
	$gates[] = array(
		'name'   => $name,
		'status' => $status,
		'detail' => $detail,
	);
	if ( ! $passed ) {
		++$failed;
	}
	echo sprintf( "[%s] %s — %s\n", $status, $name, $detail );
}

/**
 * Obtiene la huella centinela de una tabla icl_translations en una base de datos.
 *
 * @param string $db_name Nombre de la base de datos.
 * @return array{count: int, md5: string}
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
		'count' => (int) ( $row['cnt'] ?? 0 ),
		'md5'   => (string) ( $row['hash_val'] ?? '' ),
	);
}

/**
 * Calcula un hash determinista de las tablas TFML y opciones críticas en el laboratorio.
 *
 * @return string
 */
function get_lab_state_digest(): string {
	global $wpdb;
	$groups   = $wpdb->get_results( "SELECT id, element_type, subtype, canonical_element_id FROM {$wpdb->prefix}tfml_groups ORDER BY id ASC", ARRAY_A );
	$elements = $wpdb->get_results( "SELECT id, group_id, element_type, element_id, language_code FROM {$wpdb->prefix}tfml_group_elements ORDER BY id ASC", ARRAY_A );
	$retained = get_option( 'tfml_retained_translations', array() );
	$settings = get_option( 'tfml_settings', array() );
	$front    = get_option( 'page_on_front', 0 );
	$show     = get_option( 'show_on_front', '' );

	return hash(
		'sha256',
		wp_json_encode(
			array(
				'groups'   => $groups,
				'elements' => $elements,
				'retained' => $retained,
				'settings' => $settings,
				'front'    => $front,
				'show'     => $show,
			)
		)
	);
}

echo "============================================================================\n";
echo " VERIFICADOR AUTOMATIZADO FASE 3.39 (DT-TFML-069)\n";
echo " Localización Segura de Enlaces y Preparación del Piloto Bilingüe (2 TRIDs)\n";
echo "============================================================================\n\n";

// ============================================================================
// BLOQUE 1: PREFLIGHT DE AISLAMIENTO E INVARIANCIA DE BASE DE DATOS (SECCIÓN 2)
// ============================================================================
echo "--- BLOQUE 1: Preflight de Aislamiento e Invariancia de Base de Datos (Sección 2) ---\n";

$expected_full_md5 = '4241ca7e7ec6399a594537cb04790c10';

$db_name       = (string) $wpdb->get_var( 'SELECT DATABASE()' );
$sentinel_prod = get_icl_sentinel( 'cms_ect' );
$sentinel_lab  = get_icl_sentinel( 'cms_ect_lab' );
$pre_digest    = get_lab_state_digest();

record_gate(
	'1. Aislamiento de entorno y centinela WPML (cms_ect y cms_ect_lab intactos: 3,403 filas / MD5 4241ca7e7ec6399a594537cb04790c10)',
	'cms_ect_lab' === $db_name
		&& 'etk_t_' === $wpdb->prefix
		&& 3403 === $sentinel_prod['count']
		&& $expected_full_md5 === $sentinel_prod['md5']
		&& 3403 === $sentinel_lab['count']
		&& $expected_full_md5 === $sentinel_lab['md5'],
	sprintf(
		'db=%s, prod=%d/%s, lab=%d/%s',
		$db_name,
		$sentinel_prod['count'],
		$sentinel_prod['md5'],
		$sentinel_lab['count'],
		$sentinel_lab['md5']
	)
);

$groups_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$group_587_rows = $wpdb->get_results( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 587 ORDER BY language_code ASC", ARRAY_A );
$post_8839_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_type = 'post' AND element_id = 8839" );

$retained_reg   = new RetainedTranslationRegistry( $wpdb );
$retained_all   = $retained_reg->get_all( 'wpml' );
$retained_197   = $retained_reg->get( 'wpml', 197 );
$manifests_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$checkpoint     = ( new MigrationCheckpointRepository() )->get( 'wpml' );
$rollback_svc   = new WpmlMigrationRollbackService( $wpdb );
$manifest_pilot = $rollback_svc->get_execution_manifest( 'pilot-trid-197-partial' );
$lock_mgr       = new MigrationExecutionLock( $wpdb );

$plugin          = Plugin::get_instance();
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
	'2. Estado TFML de entrada intacto (121 grupos, 135 elementos, grupo 587 [197:es, 8356:en], 1 retenido [fr:8839], 12 manifiestos, 0 discrepancias)',
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
	sprintf(
		'groups=%d, elements=%d, retained=%d (fr:%d), manifests=%d, reconcile_ok=%s',
		$groups_count,
		$elements_count,
		count( $retained_all ),
		(int) ( $retained_197['retained_elements']['fr']['element_id'] ?? 0 ),
		$manifests_cnt,
		( $reconcile_197['integrity_verified'] ?? false ) ? 'true' : 'false'
	)
);

// ============================================================================
// BLOQUE 2: VERIFICACIÓN DEL CONTRATO DE LOCALIZACIÓN EN NavMenuFrontendFilter (SECCIÓN 4)
// ============================================================================
echo "\n--- BLOQUE 2: Verificación del Contrato de Localización en NavMenuFrontendFilter (Sección 4) ---\n";

$lang_registry  = $plugin->get_language_registry();
$group_repo     = new TranslationGroupRepository( $wpdb, $lang_registry );
$trans_resolver = new ContentTranslationResolver( $group_repo, $lang_registry );
$url_resolver   = new UrlLanguageResolver( $lang_registry, home_url() );
$url_generator  = new LocalizedUrlGenerator(
	$lang_registry,
	$url_resolver,
	$trans_resolver,
	home_url()
);
$loc_repo       = $plugin->get_nav_menu_location_repository();

$simulated_lang = 'en';
$lang_stub      = new class( $url_resolver, $simulated_lang ) extends CurrentLanguageResolver {
	public string $forced_lang;
	public function __construct( UrlLanguageResolver $r, string $lang ) {
		parent::__construct( $r );
		$this->forced_lang = $lang;
	}
	public function get_current_language(): ?string {
		return $this->forced_lang;
	}
};

$contract_resolver = new class( $group_repo, $lang_registry ) extends ContentTranslationResolver {
	/** @var array<string, array<int, array{group_id:int, es:int, en:int}>> */
	private array $virtual_map = array(
		'post' => array(
			4976 => array( 'group_id' => 9001, 'es' => 4976, 'en' => 8339 ),
			8339 => array( 'group_id' => 9001, 'es' => 4976, 'en' => 8339 ),
		),
		'term' => array(
			50 => array( 'group_id' => 9010, 'es' => 50, 'en' => 1 ),
			1  => array( 'group_id' => 9010, 'es' => 50, 'en' => 1 ),
		),
	);

	public function resolve( string $element_type, int $element_id, string $target_language ): ?TranslationElement {
		if ( isset( $this->virtual_map[ $element_type ][ $element_id ][ $target_language ] ) ) {
			return new TranslationElement(
				$element_type,
				$this->virtual_map[ $element_type ][ $element_id ][ $target_language ],
				$target_language,
				$this->virtual_map[ $element_type ][ $element_id ]['group_id'],
				$this->virtual_map[ $element_type ][ $element_id ]['group_id']
			);
		}
		return parent::resolve( $element_type, $element_id, $target_language );
	}
};

$contract_filter = new NavMenuFrontendFilter(
	$loc_repo,
	$lang_stub,
	$lang_registry,
	$contract_resolver,
	$url_generator,
	$retained_reg
);

$menu_filter = new NavMenuFrontendFilter(
	$loc_repo,
	$lang_stub,
	$lang_registry,
	$trans_resolver,
	$url_generator,
	$retained_reg
);

$tax_subtype     = 'category';
$tax_es_id       = 50;
$tax_en_id       = 1;
$tax_es_native   = (string) get_term_link( $tax_es_id, $tax_subtype );
$tax_en_native   = (string) get_term_link( $tax_en_id, $tax_subtype );
$tax_en_expected = $url_generator->localize_url( $tax_en_native, 'en' );

$post_item_es            = new WP_Post( (object) array( 'ID' => 99000 ) );
$post_item_es->post_type = 'nav_menu_item';
$post_item_es->type      = 'post_type';
$post_item_es->object    = 'page';
$post_item_es->object_id = '4976';
$post_item_es->url       = 'http://lab.ecoterra.test/actividades/?ref=nav#top';

$term_item_es            = new WP_Post( (object) array( 'ID' => 99001 ) );
$term_item_es->post_type = 'nav_menu_item';
$term_item_es->type      = 'taxonomy';
$term_item_es->object    = $tax_subtype;
$term_item_es->object_id = (string) $tax_es_id;
$term_item_es->url       = $tax_es_native;

$term_item_en            = new WP_Post( (object) array( 'ID' => 99002 ) );
$term_item_en->post_type = 'nav_menu_item';
$term_item_en->type      = 'taxonomy';
$term_item_en->object    = $tax_subtype;
$term_item_en->object_id = (string) $tax_en_id;
$term_item_en->url       = $tax_en_native . '?view=grid#top';

// Caso de enlace administrativo y enlace externo
$admin_item            = new WP_Post( (object) array( 'ID' => 99003 ) );
$admin_item->post_type = 'nav_menu_item';
$admin_item->type      = 'custom';
$admin_item->object    = 'custom';
$admin_item->object_id = '0';
$admin_item->url       = 'http://lab.ecoterra.test/wp-admin/nav-menus.php';

$ext_item            = new WP_Post( (object) array( 'ID' => 99004 ) );
$ext_item->post_type = 'nav_menu_item';
$ext_item->type      = 'custom';
$ext_item->object    = 'custom';
$ext_item->object_id = '0';
$ext_item->url       = 'https://www.instagram.com/ecoterralodge/';

// Caso de elemento retenido francés (8839)
$retained_item            = new WP_Post( (object) array( 'ID' => 99005 ) );
$retained_item->post_type = 'nav_menu_item';
$retained_item->type      = 'post_type';
$retained_item->object    = 'page';
$retained_item->object_id = '8839';
$retained_item->url       = 'http://lab.ecoterra.test/home-2/';

$processed_contract = $contract_filter->filter_wp_nav_menu_objects(
	array( $post_item_es, $term_item_es, $term_item_en, $admin_item, $ext_item, $retained_item ),
	new stdClass()
);

record_gate(
	'3. Corrección de NavMenuFrontendFilter::localize_menu_item() con localize_url(), preservación de query/fragmento y exclusión de wp-admin/externos/retenidos',
	'8339' === (string) $processed_contract[0]->object_id
		&& 'http://lab.ecoterra.test/en/activities/?ref=nav#top' === $processed_contract[0]->url
		&& (string) $tax_en_id === (string) $processed_contract[1]->object_id
		&& $tax_en_expected === $processed_contract[1]->url
		&& (string) $tax_en_id === (string) $processed_contract[2]->object_id
		&& $tax_en_expected . '?view=grid#top' === $processed_contract[2]->url
		&& 'http://lab.ecoterra.test/wp-admin/nav-menus.php' === $processed_contract[3]->url
		&& 'https://www.instagram.com/ecoterralodge/' === $processed_contract[4]->url
		&& '8839' === (string) $processed_contract[5]->object_id
		&& 'http://lab.ecoterra.test/home-2/' === $processed_contract[5]->url,
	sprintf(
		'post_es->en=%s, term_es(%d)->en(%d)=%s, term_en_q=%s, admin=%s, retained=%s',
		$processed_contract[0]->url,
		$tax_es_id,
		$tax_en_id,
		$processed_contract[1]->url,
		$processed_contract[2]->url,
		$processed_contract[3]->url,
		$processed_contract[5]->url
	)
);

// ============================================================================
// BLOQUE 3: SEGURIDAD DE PÁGINAS NO MIGRADAS EN menu-principal (43) Y menu-es-ingles (98) (SECCIÓN 5)
// ============================================================================
echo "\n--- BLOQUE 3: Seguridad de Páginas No Migradas en menu-principal (43) y menu-es-ingles (98) (Sección 5) ---\n";

$lang_stub->forced_lang = 'es';
$items_es_raw           = wp_get_nav_menu_items( 43, array( 'tfml_suppress_language_filter' => true ) );
$items_es_cloned        = array_map( static fn( $it ) => clone $it, is_array( $items_es_raw ) ? $items_es_raw : array() );
$items_es_filtered      = $menu_filter->filter_wp_nav_menu_objects( $items_es_cloned, new stdClass() );

$es_front_ok     = false;
$es_no_es_prefix = true;
foreach ( $items_es_filtered as $idx => $it ) {
	if ( 197 === (int) $items_es_raw[ $idx ]->object_id ) {
		$es_front_ok = ( '197' === (string) $it->object_id && 'http://lab.ecoterra.test/' === $it->url );
	}
	if ( str_contains( (string) $it->url, '/es/' ) ) {
		$es_no_es_prefix = false;
	}
}

$lang_stub->forced_lang = 'en';
$items_en_raw           = wp_get_nav_menu_items( 98, array( 'tfml_suppress_language_filter' => true ) );
$items_en_cloned        = array_map( static fn( $it ) => clone $it, is_array( $items_en_raw ) ? $items_en_raw : array() );
$items_en_filtered      = $menu_filter->filter_wp_nav_menu_objects( $items_en_cloned, new stdClass() );

$en_home_ok             = false;
$unmigrated_untouched   = 0;
$premature_en_prefixed  = 0;

foreach ( $items_en_filtered as $idx => $it ) {
	$orig_obj_id = (int) $items_en_raw[ $idx ]->object_id;
	$orig_url    = (string) $items_en_raw[ $idx ]->url;
	if ( 197 === $orig_obj_id || 8356 === $orig_obj_id ) {
		// #01 Home (object_id 197/8356 -> 8356 -> /en/)
		$en_home_ok = ( '8356' === (string) $it->object_id && 'http://lab.ecoterra.test/en/' === $it->url );
	} elseif ( 'post_type' === $it->type ) {
		if ( null === $trans_resolver->resolve( 'post', (int) $it->object_id, 'en' ) ) {
			if ( $it->url === $orig_url && ! str_contains( (string) $it->url, '/en/' ) ) {
				++$unmigrated_untouched;
			} else {
				++$premature_en_prefixed;
			}
		}
	}
}

record_gate(
	'4. Seguridad de páginas no migradas en menu-principal (ID 43, 16 ítems) y menu-es-ingles (ID 98, 16 ítems): Home resuelve a / y /en/, y los 14 ítems post_type no migrados en menú 98 no emiten /en/... 404',
	16 === count( $items_es_filtered )
		&& $es_front_ok
		&& $es_no_es_prefix
		&& 16 === count( $items_en_filtered )
		&& $en_home_ok
		&& 14 === $unmigrated_untouched
		&& 0 === $premature_en_prefixed,
	sprintf(
		'menu43=%d (home_es=%s, no_es_prefix=%s), menu98=%d (home_en=%s, unmigrated_safe=%d/14, premature_en=%d)',
		count( $items_es_filtered ),
		$es_front_ok ? 'true' : 'false',
		$es_no_es_prefix ? 'true' : 'false',
		count( $items_en_filtered ),
		$en_home_ok ? 'true' : 'false',
		$unmigrated_untouched,
		$premature_en_prefixed
	)
);

// ============================================================================
// BLOQUE 4: AUDITORÍA DE JERARQUÍAS WORDPRESS (post_parent) (SECCIÓN 6)
// ============================================================================
echo "\n--- BLOQUE 4: Auditoría de Jerarquías WordPress (post_parent) (Sección 6) ---\n";

$expected_hierarchy = array(
	// Nosotros / About Us (TRID 9545) + 3 subpáginas
	array(
		'trid'          => 9545,
		'label'         => 'Nosotros / About Us',
		'es_id'         => 6237,
		'es_slug'       => 'nosotros',
		'es_parent'     => 0,
		'es_permalink'  => 'http://lab.ecoterra.test/nosotros/',
		'en_id'         => 8354,
		'en_slug'       => 'about-us',
		'en_parent'     => 0,
		'en_permalink'  => 'http://lab.ecoterra.test/about-us/',
		'en_localized'  => 'http://lab.ecoterra.test/en/about-us/',
	),
	array(
		'trid'          => 424,
		'label'         => '¿Quienes Somos? / Who Are We?',
		'es_id'         => 424,
		'es_slug'       => 'quienes-somos',
		'es_parent'     => 6237,
		'es_permalink'  => 'http://lab.ecoterra.test/nosotros/quienes-somos/',
		'en_id'         => 8352,
		'en_slug'       => 'who-are-we',
		'en_parent'     => 8354,
		'en_permalink'  => 'http://lab.ecoterra.test/about-us/who-are-we/',
		'en_localized'  => 'http://lab.ecoterra.test/en/about-us/who-are-we/',
	),
	array(
		'trid'          => 9838,
		'label'         => 'Reviews / Reviews',
		'es_id'         => 8316,
		'es_slug'       => 'reviews',
		'es_parent'     => 6237,
		'es_permalink'  => 'http://lab.ecoterra.test/nosotros/reviews/',
		'en_id'         => 8321,
		'en_slug'       => 'reviews',
		'en_parent'     => 8354,
		'en_permalink'  => 'http://lab.ecoterra.test/about-us/reviews/',
		'en_localized'  => 'http://lab.ecoterra.test/en/about-us/reviews/',
	),
	array(
		'trid'          => 34823,
		'label'         => 'Términos y Condiciones / Terms and Conditions',
		'es_id'         => 9864,
		'es_slug'       => 'terminos-y-condiciones',
		'es_parent'     => 6237,
		'es_permalink'  => 'http://lab.ecoterra.test/nosotros/terminos-y-condiciones/',
		'en_id'         => 9863,
		'en_slug'       => 'terms-and-conditions',
		'en_parent'     => 8354,
		'en_permalink'  => 'http://lab.ecoterra.test/about-us/terms-and-conditions/',
		'en_localized'  => 'http://lab.ecoterra.test/en/about-us/terms-and-conditions/',
	),
	// Actividades / Activities (TRID 9201) y Galería de Fotos / Photo Gallery (TRID 430)
	array(
		'trid'          => 9201,
		'label'         => 'Actividades / Activities',
		'es_id'         => 4976,
		'es_slug'       => 'actividades',
		'es_parent'     => 0,
		'es_permalink'  => 'http://lab.ecoterra.test/actividades/',
		'en_id'         => 8339,
		'en_slug'       => 'activities',
		'en_parent'     => 0,
		'en_permalink'  => 'http://lab.ecoterra.test/activities/',
		'en_localized'  => 'http://lab.ecoterra.test/en/activities/',
	),
	array(
		'trid'          => 430,
		'label'         => 'Galería de Fotos / Photo Gallery',
		'es_id'         => 430,
		'es_slug'       => 'galeria',
		'es_parent'     => 0,
		'es_permalink'  => 'http://lab.ecoterra.test/galeria/',
		'en_id'         => 8329,
		'en_slug'       => 'gallery',
		'en_parent'     => 0,
		'en_permalink'  => 'http://lab.ecoterra.test/gallery/',
		'en_localized'  => 'http://lab.ecoterra.test/en/gallery/',
	),
	// Experiencia Transformadora / Transformative Experience (TRID 34652) + 3 subpáginas
	array(
		'trid'          => 34652,
		'label'         => 'Experiencia Transformadora / Transformative Experience',
		'es_id'         => 9218,
		'es_slug'       => 'experiencia-transformadora',
		'es_parent'     => 0,
		'es_permalink'  => 'http://lab.ecoterra.test/experiencia-transformadora/',
		'en_id'         => 9219,
		'en_slug'       => 'transformative-experience',
		'en_parent'     => 0,
		'en_permalink'  => 'http://lab.ecoterra.test/transformative-experience/',
		'en_localized'  => 'http://lab.ecoterra.test/en/transformative-experience/',
	),
	array(
		'trid'          => 34660,
		'label'         => 'Avistamiento de Aves / Birdwatching',
		'es_id'         => 9248,
		'es_slug'       => 'avistamiento-de-aves',
		'es_parent'     => 9218,
		'es_permalink'  => 'http://lab.ecoterra.test/experiencia-transformadora/avistamiento-de-aves/',
		'en_id'         => 9249,
		'en_slug'       => 'birdwatching',
		'en_parent'     => 9219,
		'en_permalink'  => 'http://lab.ecoterra.test/transformative-experience/birdwatching/',
		'en_localized'  => 'http://lab.ecoterra.test/en/transformative-experience/birdwatching/',
	),
	array(
		'trid'          => 34776,
		'label'         => 'Lek de Gallito de las Rocas / Andean Cock of the Rock Lek',
		'es_id'         => 9614,
		'es_slug'       => 'lek-de-gallito-de-las-rocas',
		'es_parent'     => 9218,
		'es_permalink'  => 'http://lab.ecoterra.test/experiencia-transformadora/lek-de-gallito-de-las-rocas/',
		'en_id'         => 9615,
		'en_slug'       => 'andean-cock-of-the-rock-lek',
		'en_parent'     => 9219,
		'en_permalink'  => 'http://lab.ecoterra.test/transformative-experience/andean-cock-of-the-rock-lek/',
		'en_localized'  => 'http://lab.ecoterra.test/en/transformative-experience/andean-cock-of-the-rock-lek/',
	),
	array(
		'trid'          => 34791,
		'label'         => 'Experiencia De Café / Coffee Experience',
		'es_id'         => 9666,
		'es_slug'       => 'experiencia-de-cafe',
		'es_parent'     => 9218,
		'es_permalink'  => 'http://lab.ecoterra.test/experiencia-transformadora/experiencia-de-cafe/',
		'en_id'         => 9667,
		'en_slug'       => 'coffee-experience',
		'en_parent'     => 9219,
		'en_permalink'  => 'http://lab.ecoterra.test/transformative-experience/coffee-experience/',
		'en_localized'  => 'http://lab.ecoterra.test/en/transformative-experience/coffee-experience/',
	),
);

$hierarchy_ok   = true;
$verified_count = 0;

foreach ( $expected_hierarchy as $row ) {
	$p_es = get_post( $row['es_id'] );
	$p_en = get_post( $row['en_id'] );

	if ( ! $p_es instanceof WP_Post || ! $p_en instanceof WP_Post ) {
		$hierarchy_ok = false;
		break;
	}

	$perm_es = (string) get_permalink( $row['es_id'] );
	$perm_en = (string) get_permalink( $row['en_id'] );
	$loc_en  = $url_generator->localize_url( $perm_en, 'en' );

	if ( 'publish' !== $p_es->post_status
		|| 'publish' !== $p_en->post_status
		|| $row['es_slug'] !== $p_es->post_name
		|| $row['en_slug'] !== $p_en->post_name
		|| $row['es_parent'] !== (int) $p_es->post_parent
		|| $row['en_parent'] !== (int) $p_en->post_parent
		|| $row['es_permalink'] !== $perm_es
		|| $row['en_permalink'] !== $perm_en
		|| $row['en_localized'] !== $loc_en
	) {
		echo sprintf(
			"  [MISMATCH TRID %d] es(%d: slug=%s, parent=%d, perm=%s) | en(%d: slug=%s, parent=%d, perm=%s, loc=%s)\n",
			$row['trid'],
			$row['es_id'],
			$p_es->post_name,
			(int) $p_es->post_parent,
			$perm_es,
			$row['en_id'],
			$p_en->post_name,
			(int) $p_en->post_parent,
			$perm_en,
			$loc_en
		);
		$hierarchy_ok = false;
	} else {
		++$verified_count;
	}
}

record_gate(
	'5. Auditoría de jerarquías WordPress (post_parent): las 10 parejas ES/EN (TRIDs 9545, 424, 9838, 34823, 9201, 430, 34652, 34660, 34776, 34791) tienen post_parent simétrico y permalinks coherentes',
	$hierarchy_ok && 10 === $verified_count,
	sprintf( 'verified_pairs=%d/10, parent_6237_es<->8354_en=OK, parent_9218_es<->9219_en=OK', $verified_count )
);

// ============================================================================
// BLOQUE 5: DERIVACIÓN EN MEMORIA DEL PILOTO DE 2 TRIDs Y SIMULACIÓN DE MENÚ (SECCIONES 7 Y 8)
// ============================================================================
echo "\n--- BLOQUE 5: Derivación en Memoria del Piloto de 2 TRIDs y Simulación de Menú (Secciones 7 y 8) ---\n";

$pilot_9201_430 = $plan_no_conf->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 9201,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 430,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
	),
	'pilot-nav-2-trids-9201-430'
);

$pilot_9545_9201 = $plan_no_conf->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 9545,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 9201,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
	),
	'pilot-nav-2-trids-9545-9201'
);

$fp_9201_430  = $pilot_9201_430->get_fingerprint();
$fp_9545_9201 = $pilot_9545_9201->get_fingerprint();
$sum_a        = $pilot_9201_430->get_summary();
$sum_b        = $pilot_9545_9201->get_summary();

// Verificar ausencia de colisiones previas en tfml_groups y tfml_group_elements
$pilot_post_ids    = array( 4976, 8339, 8955, 430, 8329, 8989, 6237, 8354, 8873 );
$existing_el_cnt   = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_type = 'post' AND element_id IN (" . implode( ',', $pilot_post_ids ) . ')'
);

record_gate(
	'6. Derivación determinista en memoria (sin ejecutar WpmlMigrationExecutor) del piloto de 2 TRIDs (Opción A [9201,430] y Opción B [9545,9201]): READY, 2 grupos, 4 elementos activos (es,en), 2 retenidos (fr), 0 colisiones',
	MigrationPlan::STATUS_READY === $pilot_9201_430->get_status()
		&& 2 === (int) $sum_a['total_groups']
		&& 4 === (int) $sum_a['importable_elements']
		&& 2 === (int) $sum_a['pending_elements']
		&& 0 === (int) ( $sum_a['conflicted_groups'] ?? 0 )
		&& MigrationPlan::STATUS_READY === $pilot_9545_9201->get_status()
		&& 2 === (int) $sum_b['total_groups']
		&& 4 === (int) $sum_b['importable_elements']
		&& 2 === (int) $sum_b['pending_elements']
		&& 0 === (int) ( $sum_b['conflicted_groups'] ?? 0 )
		&& 0 === $existing_el_cnt
		&& 64 === strlen( $fp_9201_430 )
		&& 64 === strlen( $fp_9545_9201 ),
	sprintf(
		'fp_9201_430=%s, fp_9545_9201=%s, existing_collisions=%d',
		$fp_9201_430,
		$fp_9545_9201,
		$existing_el_cnt
	)
);

// Simulación en memoria de NavMenuFrontendFilter con los grupos del piloto disponibles (sin escribir en DB)
$sim_resolver = new class( $group_repo, $lang_registry ) extends ContentTranslationResolver {
	/** @var array<int, array{group_id:int, es:int, en:int}> */
	private array $virtual_map = array(
		4976 => array( 'group_id' => 9001, 'es' => 4976, 'en' => 8339 ),
		8339 => array( 'group_id' => 9001, 'es' => 4976, 'en' => 8339 ),
		430  => array( 'group_id' => 9002, 'es' => 430, 'en' => 8329 ),
		8329 => array( 'group_id' => 9002, 'es' => 430, 'en' => 8329 ),
		6237 => array( 'group_id' => 9003, 'es' => 6237, 'en' => 8354 ),
		8354 => array( 'group_id' => 9003, 'es' => 6237, 'en' => 8354 ),
	);

	public function resolve( string $element_type, int $element_id, string $target_language ): ?TranslationElement {
		if ( 'post' === $element_type && isset( $this->virtual_map[ $element_id ][ $target_language ] ) ) {
			return new TranslationElement(
				'post',
				$this->virtual_map[ $element_id ][ $target_language ],
				$target_language,
				$this->virtual_map[ $element_id ]['group_id'],
				$this->virtual_map[ $element_id ]['group_id']
			);
		}
		return parent::resolve( $element_type, $element_id, $target_language );
	}
};

$sim_filter = new NavMenuFrontendFilter(
	$loc_repo,
	$lang_stub,
	$lang_registry,
	$sim_resolver,
	$url_generator,
	$retained_reg
);

$lang_stub->forced_lang = 'en';
$sim_items_en           = array_map( static fn( $it ) => clone $it, is_array( $items_en_raw ) ? $items_en_raw : array() );
$sim_filtered           = $sim_filter->filter_wp_nav_menu_objects( $sim_items_en, new stdClass() );

$sim_urls_by_target_id = array();
foreach ( $sim_filtered as $it ) {
	$sim_urls_by_target_id[ (int) $it->object_id ] = (string) $it->url;
}

record_gate(
	'7. Proyección en memoria de menu-es-ingles (ID 98) tras corrección de NavMenuFrontendFilter: Home(8356)->/en/, About Us(8354)->/en/about-us/, Photo Gallery(8329)->/en/gallery/, Activities(8339)->/en/activities/',
	'http://lab.ecoterra.test/en/' === ( $sim_urls_by_target_id[8356] ?? '' )
		&& 'http://lab.ecoterra.test/en/about-us/' === ( $sim_urls_by_target_id[8354] ?? '' )
		&& 'http://lab.ecoterra.test/en/gallery/' === ( $sim_urls_by_target_id[8329] ?? '' )
		&& 'http://lab.ecoterra.test/en/activities/' === ( $sim_urls_by_target_id[8339] ?? '' ),
	sprintf(
		'home=%s, about=%s, gallery=%s, activities=%s',
		$sim_urls_by_target_id[8356] ?? 'N/A',
		$sim_urls_by_target_id[8354] ?? 'N/A',
		$sim_urls_by_target_id[8329] ?? 'N/A',
		$sim_urls_by_target_id[8339] ?? 'N/A'
	)
);

// ============================================================================
// BLOQUE 6: MATRIZ HTTP OBLIGATORIA Y SITEMAPS DE FASE 3.38 (SECCIONES 10 Y 11)
// ============================================================================
echo "\n--- BLOQUE 6: Matriz HTTP Obligatoria y Sitemaps de Fase 3.38 (Secciones 10 y 11) ---\n";

$http_checks = array(
	'/'              => 200,
	'/en/'           => 200,
	'/fr/'           => 404,
	'/pt/'           => 404,
	'/nosotros/'     => 200,
	'/about-us/'     => 200,
	'/en/about-us/'  => 404,
	'/actividades/'  => 200,
	'/activities/'   => 200,
	'/en/activities/'=> 404,
	'/wp-sitemap.xml'=> 404, // blog_public=0 en laboratorio
);

$http_all_ok  = true;
$http_summary = array();

foreach ( $http_checks as $route => $expected_code ) {
	$resp           = wp_remote_get(
		'http://lab.ecoterra.test' . $route,
		array(
			'timeout'     => 10,
			'redirection' => 0,
		)
	);
	$code           = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
	$http_summary[] = sprintf( '%s=%d', $route, $code );
	if ( $code !== $expected_code ) {
		$http_all_ok = false;
	}
}

record_gate(
	'8. Matriz HTTP obligatoria (Sección 10): /, /en/, /nosotros/, /about-us/, /actividades/ y /activities/ -> 200; /fr/, /pt/, /en/about-us/, /en/activities/ y /wp-sitemap.xml (blog_public=0) -> 404',
	$http_all_ok,
	implode( ', ', $http_summary )
);

// Verificar proveedores de sitemaps de Fase 3.38 (26 entradas: page=3, post=3, rooms=0, hf=0, category=1, post_tag=19) y HTML de / y /en/
$html_es = (string) wp_remote_retrieve_body( wp_remote_get( 'http://lab.ecoterra.test/', array( 'timeout' => 10 ) ) );
$html_en = (string) wp_remote_retrieve_body( wp_remote_get( 'http://lab.ecoterra.test/en/', array( 'timeout' => 10 ) ) );

$posts_provider    = new WP_Sitemaps_Posts();
$tax_provider      = new WP_Sitemaps_Taxonomies();
$post_page_entries = $posts_provider->get_url_list( 1, 'page' );
$post_post_entries = $posts_provider->get_url_list( 1, 'post' );
$post_room_entries = $posts_provider->get_url_list( 1, 'rooms' );
$post_hf_entries   = $posts_provider->get_url_list( 1, 'ova_framework_hf_el' );
$tax_cat_entries   = $tax_provider->get_url_list( 1, 'category' );
$tax_tag_entries   = $tax_provider->get_url_list( 1, 'post_tag' );

$page_locs  = array_column( $post_page_entries, 'loc' );
$total_urls = count( $post_page_entries ) + count( $post_post_entries ) + count( $post_room_entries ) + count( $post_hf_entries ) + count( $tax_cat_entries ) + count( $tax_tag_entries );

record_gate(
	'9. Integridad de sitemaps de Fase 3.38 intacta: 26 entradas totales (page=3 [/, /old-blog/, /en/], post=3, rooms=0, hf=0, category=1, post_tag=19), 0 URLs francesas y 0 plantillas técnicas',
	26 === $total_urls
		&& 3 === count( $post_page_entries )
		&& in_array( 'http://lab.ecoterra.test/', $page_locs, true )
		&& in_array( 'http://lab.ecoterra.test/old-blog/', $page_locs, true )
		&& in_array( 'http://lab.ecoterra.test/en/', $page_locs, true )
		&& 3 === count( $post_post_entries )
		&& 0 === count( $post_room_entries )
		&& 0 === count( $post_hf_entries )
		&& 1 === count( $tax_cat_entries )
		&& 19 === count( $tax_tag_entries )
		&& str_contains( $html_es, 'lang="es-ES"' )
		&& str_contains( $html_en, 'lang="en-US"' )
		&& str_contains( $html_en, 'href="http://lab.ecoterra.test/en/"' ),
	sprintf(
		'total_urls=%d (page=%d, post=%d, rooms=%d, hf=%d, category=%d, post_tag=%d)',
		$total_urls,
		count( $post_page_entries ),
		count( $post_post_entries ),
		count( $post_room_entries ),
		count( $post_hf_entries ),
		count( $tax_cat_entries ),
		count( $tax_tag_entries )
	)
);

// ============================================================================
// BLOQUE 7: INVARIANCIA POST-FASE DE BASES DE DATOS (SECCIONES 12 Y 15)
// ============================================================================
echo "\n--- BLOQUE 7: Invariancia Post-Fase de Bases de Datos (Secciones 12 y 15) ---\n";

$sentinel_prod_post = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_post  = get_icl_sentinel( 'cms_ect_lab' );
$post_digest        = get_lab_state_digest();
$post_groups_cnt    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$post_elements_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );

record_gate(
	'10. Confirmación de cero escrituras en base de datos post-verificación (digest SHA-256 idéntico, 121 grupos, 135 elementos, MD5 WPML 4241ca7e7ec6399a594537cb04790c10)',
	$pre_digest === $post_digest
		&& 121 === $post_groups_cnt
		&& 135 === $post_elements_cnt
		&& 3403 === $sentinel_prod_post['count']
		&& $expected_full_md5 === $sentinel_prod_post['md5']
		&& 3403 === $sentinel_lab_post['count']
		&& $expected_full_md5 === $sentinel_lab_post['md5'],
	sprintf(
		'digest_match=%s, groups=%d, elements=%d, prod_md5=%s, lab_md5=%s',
		$pre_digest === $post_digest ? 'true' : 'false',
		$post_groups_cnt,
		$post_elements_cnt,
		$sentinel_prod_post['md5'],
		$sentinel_lab_post['md5']
	)
);

echo "\n============================================================================\n";
echo sprintf( " RESUMEN FASE 3.39: %d/%d CONTROLES PASS (%d FAIL)\n", count( $gates ) - $failed, count( $gates ), $failed );
echo "============================================================================\n";

exit( $failed > 0 ? 1 : 0 );
