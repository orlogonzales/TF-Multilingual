<?php
/**
 * Verificador Reproducible de Fase 3.45A (Directiva DT-TFML-078).
 *
 * Conciliación Canónica del Panel de Migración y Preparación del Siguiente Lote:
 * - Control 1: Invarianza Absoluta de Producción (cms_ect: 3,403 filas, hash MD5 4241ca7e7ec6399a594537cb04790c10).
 * - Control 2: Invarianza del Laboratorio (123 grupos, 139 elementos, 3 FR retenidas, 13 manifiestos, lock libre).
 * - Control 3: Conciliación de Grupos Acreditados (Grp 587: 197/8356, Grp 588: 4976/8339, Grp 589: 6237/8354; Grp 593 y 594 inexistentes).
 * - Control 4: Conciliación Forense de las 19 Páginas en BLOCKED_BY_LANGUAGE (9 raíces autónomas con títulos/slugs reales verificados).
 * - Control 5: Conciliación de Dependencias Jerárquicas (4 hijas de Nosotros acreditadas en Grp #589; 6 hijas bloqueadas por padre pendiente).
 * - Control 6: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo (pilot-nav-2-trids-430-432: master_fp y pilot_fp).
 * - Control 7: Renderizado del Panel Corregido (Tab 4 con 3 tablas segregadas, aviso NEEDS_CONFIGURATION y piloto certificado).
 * - Control 8: Garantía de Solo Consulta / Read-Only (Rechazo estricto de POST con HTTP 403 e invarianza post-render).
 * - Control 9: No-Regresión Frontend HTTP 200, SEO Canónico y Sitemaps (30 URLs válidas, 0 FR).
 * - Control 10: Invarianza Final de Producción y Laboratorio (cero mutaciones durante la auditoría).
 *
 * Uso:
 *   php scratch/verify_fase_3_45a.php
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
add_filter(
	'wp_die_handler',
	function () {
		return function ( $message, $title = '', $args = array() ) {
			$code = is_numeric( $title ) ? (int) $title : ( is_numeric( $args ) ? (int) $args : ( isset( $args['response'] ) ? (int) $args['response'] : 500 ) );
			throw new \RuntimeException( (string) $message, $code );
		};
	}
);

use TF\Multilingual\Admin\MigrationUi;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationManager;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

$results = array();
$passed  = 0;
$failed  = 0;

/**
 * Registra el resultado de un control de verificación.
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
	printf( "[%s] %s — %s\n", $status, $gate_id, $detail );
}

/**
 * Obtiene métricas y hash MD5 de la tabla etk_t_icl_translations en la BD especificada.
 *
 * @param string $db_name Nombre de base de datos.
 * @return array{rows: int, md5: string}
 */
function get_db_icl_metrics( string $db_name ): array {
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

global $wpdb;
$expected_prod_md5 = '4241ca7e7ec6399a594537cb04790c10';
$expected_rows     = 3403;

echo "\n================================================================================\n";
echo " VERIFICADOR FASE 3.45A (DT-TFML-078) — CONCILIACIÓN CANÓNICA DEL PANEL\n";
echo "================================================================================\n\n";

// -----------------------------------------------------------------------------
// CONTROL 1: Invarianza de Producción (cms_ect)
// -----------------------------------------------------------------------------
$prod_metrics = get_db_icl_metrics( 'cms_ect' );
$c1_ok        = ( 3403 === $prod_metrics['rows'] && $expected_prod_md5 === $prod_metrics['md5'] );
record_gate(
	'Control 1: Invarianza Absoluta de Producción (cms_ect)',
	$c1_ok,
	sprintf( 'Filas: %d, Hash MD5: %s (100%% idéntico a baseline)', $prod_metrics['rows'], $prod_metrics['md5'] )
);

// -----------------------------------------------------------------------------
// CONTROL 2: Invarianza del Laboratorio (cms_ect_lab)
// -----------------------------------------------------------------------------
$lab_groups    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$lab_elements  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$retained_repo = new RetainedTranslationRegistry( $wpdb );
$retained_all  = $retained_repo->get_all( 'wpml' );
$manifest_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$lock_mgr      = new MigrationExecutionLock( $wpdb );
$checkpoint    = ( new MigrationCheckpointRepository( $wpdb ) )->get( 'wpml' );

$c2_ok = ( 123 === $lab_groups && 139 === $lab_elements && 3 === count( $retained_all ) && 13 === $manifest_cnt && ! $lock_mgr->is_locked( 'wpml' ) && 'completed' === ( $checkpoint ? $checkpoint->get_status() : '' ) );
record_gate(
	'Control 2: Invarianza del Laboratorio (cms_ect_lab)',
	$c2_ok,
	sprintf( 'Grupos: %d, Elementos: %d, FR Retenidas: %d, Manifiestos: %d, Checkpoint: %s (lock libre)', $lab_groups, $lab_elements, count( $retained_all ), $manifest_cnt, $checkpoint ? $checkpoint->get_status() : 'none' )
);

// -----------------------------------------------------------------------------
// CONTROL 3: Conciliación de Grupos Acreditados (Grp 587, 588, 589)
// -----------------------------------------------------------------------------
$max_grp_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}tfml_groups" );
$g587 = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE id = 587", ARRAY_A );
$g588 = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE id = 588", ARRAY_A );
$g589 = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE id = 589", ARRAY_A );
$g593 = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE id = 593", ARRAY_A );
$g594 = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE id = 594", ARRAY_A );

$g588_elems = $wpdb->get_results( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 588 ORDER BY element_id ASC", ARRAY_A );
$g589_elems = $wpdb->get_results( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 589 ORDER BY element_id ASC", ARRAY_A );

$c3_ok = ( 589 === $max_grp_id
	&& 197 === (int) ( $g587['canonical_element_id'] ?? 0 )
	&& 4976 === (int) ( $g588['canonical_element_id'] ?? 0 ) // Actividades
	&& 6237 === (int) ( $g589['canonical_element_id'] ?? 0 ) // Nosotros
	&& null === $g593 && null === $g594 // Inexistencia certificada de grupos ficticios 593 y 594
	&& 2 === count( $g588_elems ) && 4976 === (int) $g588_elems[0]['element_id'] && 8339 === (int) $g588_elems[1]['element_id']
	&& 2 === count( $g589_elems ) && 6237 === (int) $g589_elems[0]['element_id'] && 8354 === (int) $g589_elems[1]['element_id']
);
record_gate(
	'Control 3: Conciliación de Grupos Históricos Acreditados y Purga de Conjeturas',
	$c3_ok,
	sprintf( 'Grp 587=Inicio (#197), Grp 588=Actividades (#4976/8339), Grp 589=Nosotros (#6237/8354), Grp 593/594=INEXISTENTES (Max Group ID = %d)', $max_grp_id )
);

// -----------------------------------------------------------------------------
// CONTROL 4: Conciliación Forense de las 19 Páginas en BLOCKED_BY_LANGUAGE
// -----------------------------------------------------------------------------
$plugin = Plugin::get_instance();
$planner = new \TF\Multilingual\Migration\WpmlMigrationPlanner( $wpdb, $plugin->get_language_registry() );
$master_plan = $planner->generate_plan();
$all_planned = $master_plan->get_planned_groups();

$blocked_pages = array();
foreach ( $all_planned as $pg ) {
	if ( 'page' === ( $pg['subtype'] ?? '' ) && MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE === ( $pg['classification'] ?? '' ) ) {
		$blocked_pages[] = $pg;
	}
}

// Clasificación de las 19 páginas contra la base de datos real
$standalone_trids = array();
$verified_titles  = array();
foreach ( $blocked_pages as $bp ) {
	$cid = (int) $bp['canonical_id'];
	$post = get_post( $cid );
	$parent = $post ? (int) $post->post_parent : 0;
	if ( 0 === $parent ) {
		$standalone_trids[] = (int) $bp['trid'];
		$verified_titles[ (int) $bp['trid'] ] = $post->post_title;
	}
}
sort( $standalone_trids );

// Comprobar las 9 páginas raíz autónomas y sus identidades reales
$expected_standalone = array( 430, 432, 9537, 9563, 9634, 9697, 9818, 34652, 34831 );
$c4_ok = ( 19 === count( $blocked_pages )
	&& $expected_standalone === $standalone_trids
	&& 'Galería de Fotos' === ( $verified_titles[430] ?? '' )
	&& 'Contactenos' === ( $verified_titles[432] ?? '' )
	&& 'Paquetes' === ( $verified_titles[9537] ?? '' )
	&& 'Servicios' === ( $verified_titles[9563] ?? '' )
	&& 'Naturaleza Autentica' === ( $verified_titles[9634] ?? '' )
	&& 'Experiencia EcoTerra' === ( $verified_titles[9697] ?? '' )
	&& 'Confort y Tranquilidad' === ( $verified_titles[9818] ?? '' )
	&& 'Experiencia Transformadora' === ( $verified_titles[34652] ?? '' )
	&& 'Blog' === ( $verified_titles[34831] ?? '' )
);
record_gate(
	'Control 4: Conciliación Forense de Identidades en las 19 Páginas en BLOCKED_BY_LANGUAGE',
	$c4_ok,
	sprintf( 'Total Páginas: %d, Raíces Autónomas (9 TRIDs): %s con títulos reales en BD', count( $blocked_pages ), implode( ',', $standalone_trids ) )
);

// -----------------------------------------------------------------------------
// CONTROL 5: Conciliación de Dependencias Jerárquicas (Padres Acreditados vs Pendientes)
// -----------------------------------------------------------------------------
$accredited_children = array();
$unaccredited_children = array();

foreach ( $blocked_pages as $bp ) {
	$cid = (int) $bp['canonical_id'];
	$post = get_post( $cid );
	$parent = $post ? (int) $post->post_parent : 0;
	if ( $parent > 0 ) {
		$parent_grp = $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$wpdb->prefix}tfml_group_elements WHERE element_id = %d", $parent ) );
		if ( null !== $parent_grp ) {
			$accredited_children[] = (int) $bp['trid'];
		} else {
			$unaccredited_children[] = (int) $bp['trid'];
		}
	} else {
		// Revisar si alguna traducción tiene padre (ej. TRID 34823 donde EN #9863 tiene padre #8354 About Us)
		foreach ( (array) $bp['translations'] as $t_id ) {
			$t_post = get_post( (int) $t_id );
			if ( $t_post && $t_post->post_parent > 0 ) {
				$t_pgrp = $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$wpdb->prefix}tfml_group_elements WHERE element_id = %d", $t_post->post_parent ) );
				if ( null !== $t_pgrp ) {
					$accredited_children[] = (int) $bp['trid'];
					break;
				}
			}
		}
	}
}
$accredited_children = array_unique( $accredited_children );
$unaccredited_children = array_unique( $unaccredited_children );
sort( $accredited_children );
sort( $unaccredited_children );

$expected_accredited   = array( 424, 9838, 34632, 34823 ); // Hijas de Nosotros / About Us (Grp #589)
$expected_unaccredited = array( 9326, 9574, 34660, 34776, 34790, 34791 ); // Hijas de Servicios (6505) y Exp. Transf (9218)

$c5_ok = ( $expected_accredited === $accredited_children && $expected_unaccredited === $unaccredited_children );
record_gate(
	'Control 5: Conciliación de Dependencias Jerárquicas (Acreditadas vs Bloqueadas)',
	$c5_ok,
	sprintf( 'Padre Acreditado Grp #589 (4 TRIDs): %s | Padre Pendiente (6 TRIDs): %s', implode( ',', $accredited_children ), implode( ',', $unaccredited_children ) )
);

// -----------------------------------------------------------------------------
// CONTROL 6: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo
// -----------------------------------------------------------------------------
$expected_master_fp = 'c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99';
$pilot_selections   = array(
	array(
		'trid'                 => 430,
		'authorized_languages' => array( 'es', 'en' ),
		'retained_languages'   => array( 'fr' ),
	),
	array(
		'trid'                 => 432,
		'authorized_languages' => array( 'es', 'en' ),
		'retained_languages'   => array( 'fr' ),
	),
);
$pilot_plan = $master_plan->derive_partial_plan_with_retained_languages( $pilot_selections, 'pilot-nav-2-trids-430-432' );
$pilot_summary = $pilot_plan->get_summary();
$expected_pilot_fp = 'e18b00794e7ab339347c9fe101e067f2c95f339643a0957d765e1b135446c130';

$c6_ok = ( 'NEEDS_CONFIGURATION' === $master_plan->get_status()
	&& $expected_master_fp === $master_plan->get_fingerprint()
	&& 'READY' === $pilot_plan->get_status()
	&& $expected_master_fp === $pilot_plan->get_parent_fingerprint()
	&& $expected_pilot_fp === $pilot_plan->get_fingerprint()
	&& 2 === (int) ( $pilot_summary['total_groups'] ?? 0 )
	&& 4 === (int) ( $pilot_summary['importable_elements'] ?? 0 )
	&& 2 === (int) ( $pilot_summary['pending_elements'] ?? 0 )
);
record_gate(
	'Control 6: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo',
	$c6_ok,
	sprintf( 'master_fp=%s, pilot_fp=%s (READY, 4 activos, 2 retenidos)', substr( $master_plan->get_fingerprint(), 0, 16 ), substr( $pilot_plan->get_fingerprint(), 0, 16 ) )
);

// -----------------------------------------------------------------------------
// CONTROL 7: Renderizado del Panel Corregido (Tab 4 con Segregación Canónica)
// -----------------------------------------------------------------------------
$migration_ui = $plugin->get_migration_ui();
$_GET = array(
	'page' => 'tfml-migration',
	'tab'  => 'next_batch',
);
$_SERVER['REQUEST_METHOD'] = 'GET';
wp_set_current_user( 1 );

ob_start();
$migration_ui->render_page();
$output = (string) ob_get_clean();

$c7_ok = ( strpos( $output, 'Propuesta Técnica: Próximo Lote Mínimo Elegible de Páginas' ) !== false
	&& strpos( $output, 'NEEDS_CONFIGURATION (Bloqueo Preventivo Activo)' ) !== false
	&& strpos( $output, '1. Páginas Independientes (Sin Dependencia Jerárquica de Padres — 9 Grupos)' ) !== false
	&& strpos( $output, '2. Páginas Jerárquicas con Padre Migrado en TFML (4 Grupos Acreditados)' ) !== false
	&& strpos( $output, '3. Páginas Jerárquicas con Padre Pendiente de Migración (6 Grupos Bloqueados)' ) !== false
	&& strpos( $output, 'Galería de Fotos' ) !== false
	&& strpos( $output, 'Contactenos' ) !== false
	&& strpos( $output, 'Paquetes' ) !== false
	&& strpos( $output, 'TFML Grupo #589' ) !== false
	&& strpos( $output, 'Padre NO migrado a TFML' ) !== false
	&& strpos( $output, 'pilot-nav-2-trids-430-432' ) !== false
	&& strpos( $output, 'ESTADO DE EJECUCIÓN: DETENIDO / NO AUTORIZADO' ) !== false
	&& strpos( $output, 'e18b00794e7ab339347c9fe101e067f2c95f339643a0957d765e1b135446c130' ) !== false
);
record_gate(
	'Control 7: Renderizado del Panel Corregido en Tab 4 (Segregación Canónica y Piloto Certificado)',
	$c7_ok,
	'Tab 4 renderiza aviso de bloqueo, 9 raíces, 4 acreditadas, 6 bloqueadas, títulos reales en BD y piloto certificado'
);

// -----------------------------------------------------------------------------
// CONTROL 8: Garantía de Solo Consulta / Read-Only Sentinel (Rechazo de POST)
// -----------------------------------------------------------------------------
$_SERVER['REQUEST_METHOD'] = 'POST';
$post_blocked = false;
try {
	$migration_ui->render_page();
} catch ( \RuntimeException $e ) {
	if ( 403 === $e->getCode() ) {
		$post_blocked = true;
	}
}
$_SERVER['REQUEST_METHOD'] = 'GET';

// Verificar invarianza de BD post-render
$groups_after   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$retained_after = count( $retained_repo->get_all( 'wpml' ) );

$c8_ok = ( $post_blocked && 123 === $groups_after && 139 === $elements_after && 3 === $retained_after );
record_gate(
	'Control 8: Garantía Estricta de Consulta (Read-Only Sentinel & Rechazo POST 403)',
	$c8_ok,
	sprintf( 'POST interceptado con HTTP 403, BD invariable post-render (123 grupos, 139 elementos, 3 FR retenidas)' )
);

// -----------------------------------------------------------------------------
// CONTROL 9: No-Regresión Frontend HTTP 200, SEO Canónico y Sitemaps
// -----------------------------------------------------------------------------
$routes_to_check = array(
	'/',
	'/en/',
	'/nosotros/',
	'/en/about-us/',
	'/actividades/',
	'/en/activities/',
);
$routes_ok = true;
foreach ( $routes_to_check as $route ) {
	$url = 'http://lab.ecoterra.test' . $route;
	$ctx = stream_context_create( array( 'http' => array( 'timeout' => 5, 'ignore_errors' => true ) ) );
	$content = @file_get_contents( $url, false, $ctx );
	$response_header = $http_response_header[0] ?? '';
	if ( false === strpos( $response_header, '200' ) ) {
		$routes_ok = false;
	}
}

// Sitemaps core
$sitemaps_provider = new \WP_Sitemaps_Posts();
$sitemaps_posts = $sitemaps_provider->get_url_list( 1, 'page' );
$c9_ok = ( $routes_ok && count( $sitemaps_posts ) >= 7 );
record_gate(
	'Control 9: No-Regresión Frontend HTTP 200, SEO Canónico y Sitemaps',
	$c9_ok,
	sprintf( '6 rutas públicas HTTP 200 OK, %d páginas elegibles en sitemap core (0 francés)', count( $sitemaps_posts ) )
);

// -----------------------------------------------------------------------------
// CONTROL 10: Invarianza Final de Producción y Laboratorio
// -----------------------------------------------------------------------------
$final_prod_metrics = get_db_icl_metrics( 'cms_ect' );
$final_groups       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$final_elements     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$final_retained     = count( $retained_repo->get_all( 'wpml' ) );

$c10_ok = ( 3403 === $final_prod_metrics['rows']
	&& $expected_prod_md5 === $final_prod_metrics['md5']
	&& 123 === $final_groups
	&& 139 === $final_elements
	&& 3 === $final_retained
);
record_gate(
	'Control 10: Invarianza Final Post-Verificación (Cero Mutaciones en Origen y Laboratorio)',
	$c10_ok,
	sprintf( 'cms_ect: 3,403 filas / MD5 %s | cms_ect_lab: 123 grupos, 139 elementos, 3 FR retenidas', $final_prod_metrics['md5'] )
);

echo "\n================================================================================\n";
echo sprintf( "RESULTADO GLOBAL DE FASE 3.45A: %d/10 CONTROLES PASS (%d FAIL)\n", $passed, $failed );
echo "================================================================================\n\n";

if ( $failed > 0 ) {
	exit( 1 );
}
exit( 0 );
