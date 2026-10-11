<?php
/**
 * TF Multilingual — Análisis y Planificación Incremental (Fase 3.13).
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/ExternalPluginIsolationGuard.php';

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;

$isolation_guard = new TF\Multilingual\Scratch\ExternalPluginIsolationGuard();
$isolation_guard->isolate();

$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';
$lab_db  = 'cms_ect_lab';

$lab_wpdb = new wpdb( $db_user, $db_pass, $lab_db, $db_host );
$lab_wpdb->set_prefix( 'etk_t_' );

$plugin = Plugin::get_instance();
$plugin->init();
$registry = $plugin->get_language_registry();

$planner     = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$matrix_plan = $planner->generate_plan( 0, 0, false );

$pilot_trids = array( 4575, 9238, 9239, 9247, 4338 );
$importables = $matrix_plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_IMPORTABLE );

$remaining = array();
foreach ( $importables as $g ) {
	$trid = (int) $g['trid'];
	if ( ! in_array( $trid, $pilot_trids, true ) ) {
		$remaining[ $trid ] = $g;
	}
}

// Separate by category:
$cat_trids = array();
$woo_tax_trids = array();
$tag_trids = array();
$elem_trids = array();
$tour_trids = array();
$prod_trids = array();
$post_form_trids = array();
$menu_trids = array();

foreach ( $remaining as $trid => $g ) {
	$type = $g['element_type'];
	$sub  = $g['subtype'];

	if ( 'term' === $type && in_array( $sub, array( 'tours_cat', 'category' ), true ) ) {
		$cat_trids[] = $trid;
	} elseif ( 'term' === $type && in_array( $sub, array( 'product_cat', 'product_type', 'product_visibility' ), true ) ) {
		$woo_tax_trids[] = $trid;
	} elseif ( 'term' === $type && 'post_tag' === $sub ) {
		$tag_trids[] = $trid;
	} elseif ( in_array( $sub, array( 'elementor_library', 'elementor_library_type' ), true ) ) {
		$elem_trids[] = $trid;
	} elseif ( 'post' === $type && 'tours' === $sub ) {
		$tour_trids[] = $trid;
	} elseif ( 'post' === $type && 'product' === $sub ) {
		$prod_trids[] = $trid;
	} elseif ( 'post' === $type && in_array( $sub, array( 'post', 'wpcf7_contact_form' ), true ) ) {
		$post_form_trids[] = $trid;
	} elseif ( 'post' === $type && 'nav_menu_item' === $sub ) {
		$menu_trids[] = $trid;
	}
}

sort( $cat_trids );
sort( $woo_tax_trids );
sort( $tag_trids );
sort( $elem_trids );
sort( $tour_trids );
sort( $prod_trids );
sort( $post_form_trids );
sort( $menu_trids );

// Split tags in 2 batches:
$tag_batch_1 = array_slice( $tag_trids, 0, 23 );
$tag_batch_2 = array_slice( $tag_trids, 23 );

$batches = array(
	'batch-02-taxonomy-categories'  => array(
		'label'       => 'Taxonomías: Categorías (tours_cat, category)',
		'trids'       => $cat_trids,
		'complexity'  => 'Baja',
	),
	'batch-03-taxonomy-woocommerce' => array(
		'label'       => 'Taxonomías: WooCommerce (product_cat, type, visibility)',
		'trids'       => $woo_tax_trids,
		'complexity'  => 'Baja',
	),
	'batch-04-taxonomy-tags-1'      => array(
		'label'       => 'Taxonomías: Etiquetas (post_tag) - Parte 1',
		'trids'       => $tag_batch_1,
		'complexity'  => 'Baja',
	),
	'batch-05-taxonomy-tags-2'      => array(
		'label'       => 'Taxonomías: Etiquetas (post_tag) - Parte 2',
		'trids'       => $tag_batch_2,
		'complexity'  => 'Baja',
	),
	'batch-06-elementor-templates'  => array(
		'label'       => 'Plantillas y Tipos Elementor (library, library_type)',
		'trids'       => $elem_trids,
		'complexity'  => 'Media',
	),
	'batch-07-cpt-tours'            => array(
		'label'       => 'CPT Tours: Publicaciones restantes',
		'trids'       => $tour_trids,
		'complexity'  => 'Media-Alta',
	),
	'batch-08-cpt-products'         => array(
		'label'       => 'CPT WooCommerce: Productos',
		'trids'       => $prod_trids,
		'complexity'  => 'Media',
	),
	'batch-09-core-posts-forms'     => array(
		'label'       => 'Core Posts y Formularios (post, wpcf7_contact_form)',
		'trids'       => $post_form_trids,
		'complexity'  => 'Baja-Media',
	),
	'batch-10-nav-menu-items'       => array(
		'label'       => 'Estructura de Menú (nav_menu_item)',
		'trids'       => $menu_trids,
		'complexity'  => 'Alta (dependencias jerárquicas)',
	),
);

echo "====================================================================\n";
echo "  TF MULTILINGUAL — PLANIFICACIÓN INCREMENTAL (115 GRUPOS / 9 LOTES)\n";
echo "====================================================================\n\n";

$total_batched_groups = 0;
$total_batched_elements = 0;

$catalog = array();

foreach ( $batches as $batch_id => $data ) {
	$derived = $matrix_plan->derive_partial_plan( $data['trids'], $batch_id );
	$count_groups   = count( $derived->get_planned_groups() );
	$count_elements = 0;
	foreach ( $derived->get_planned_groups() as $g ) {
		$count_elements += count( $g['translations'] );
	}
	$fp = $derived->get_fingerprint();

	$total_batched_groups += $count_groups;
	$total_batched_elements += $count_elements;

	$catalog[ $batch_id ] = array(
		'id'          => $batch_id,
		'label'       => $data['label'],
		'groups'      => $count_groups,
		'elements'    => $count_elements,
		'complexity'  => $data['complexity'],
		'fingerprint' => $fp,
		'trids'       => $data['trids'],
	);

	echo sprintf(
		"[%s]\n  Descripción:  %s\n  Grupos:       %d | Elementos: %d | Complejidad: %s\n  Fingerprint:  %s\n  TRIDs:        %s\n\n",
		$batch_id,
		$data['label'],
		$count_groups,
		$count_elements,
		$data['complexity'],
		$fp,
		implode( ', ', $data['trids'] )
	);
}

echo "--------------------------------------------------------------------\n";
echo "Total Lotes Propuestos:    " . count( $batches ) . "\n";
echo "Total Grupos en Lotes:     {$total_batched_groups} (Esperado: 115)\n";
echo "Total Elementos en Lotes:  {$total_batched_elements} (Esperado: 124)\n";
echo "Piloto Inicial (Preserv):  5 grupos / 9 elementos\n";
echo "Total General Importables: " . ( $total_batched_groups + 5 ) . " grupos / " . ( $total_batched_elements + 9 ) . " elementos\n";
echo "====================================================================\n";

file_put_contents( __DIR__ . '/incremental_batches_catalog.json', json_encode( $catalog, JSON_PRETTY_PRINT ) );
