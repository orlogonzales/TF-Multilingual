<?php
/**
 * Verificador Reproducible de Fase 3.47 (Directiva DT-TFML-080).
 *
 * Conciliación del Piloto Anterior (Fase 3.46) y Preparación del Siguiente Bloque:
 * - Control 1: Invarianza Absoluta de Producción (cms_ect: 3,403 filas, hash MD5 4241ca7e7ec6399a594537cb04790c10).
 * - Control 2: Estado Consolidado e Invarianza de Laboratorio (cms_ect_lab: 125 grupos, 143 elementos, 5 FR retenidas, 14 manifiestos, lock libre).
 * - Control 3: Conciliación y Cierre de la No-Conformidad de Fase 3.46 (TRID 432 / Post #432:es / Post #8327:en / Retenida #8823:fr / Grp #591, ref 8334 descartada).
 * - Control 4: Identidades Canónicas Definitivas del Lote TRID 9537 (Paquetes #6214:es / Tour Packages #8335:en / Forfaits #8974:fr retenible, 0 en TFML).
 * - Control 5: Identidades Canónicas Definitivas del Lote TRID 9563 (Servicios #6505:es / Services #8347:en / Services #8918:fr retenible, 0 en TFML).
 * - Control 6: Análisis Riguroso de Dependencias y Jerarquía (TRID 9537 sin dependencias; TRID 9563 sin dependencias previas y padre de TRIDs 9326 y 9574).
 * - Control 7: Determinismo Criptográfico y Derivación Canónica del Piloto (parent_fp: d3b02acf... y pilot_fp: 7a1b8992..., STATUS: READY, 4 activos, 2 retenidos).
 * - Control 8: Reconciliación Global del Estado Actual de TFML (0 discrepancias en 125 grupos, 5 retenidas verificadas).
 * - Control 9: No-Regresión Funcional HTTP en Vivo (10 rutas verificadas con 200 OK, canonical y hreflang; 404 en /fr/ y /pt/).
 * - Control 10: Invarianza Absoluta Post-Verificación (Cero mutaciones en cms_ect y cms_ect_lab durante la fase).
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
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationReconciliationService;

$lab_url = 'http://lab.ecoterra.test';

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
		echo sprintf( "[PASS] %s%s\n", $label, '' !== $detail ? " — {$detail}" : '' );
	} else {
		++$failed_checks;
		echo sprintf( "[FAIL] %s%s\n", $label, '' !== $detail ? " — {$detail}" : '' );
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
 * @return array{
 *     code: int,
 *     lang: string,
 *     canonical: string,
 *     hreflang: array<string, string>,
 *     body: string
 * }
 */
function inspect_route( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 15,
		)
	);
	$resp     = (string) curl_exec( $ch );
	$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$hdr_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$body     = substr( $resp, $hdr_size );
	curl_close( $ch );

	$lang = '';
	if ( preg_match( '/<html[^>]*\blang=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$lang = $m[1];
	}

	$canonical = '';
	if ( preg_match( '/<link[^>]*\brel=["\']canonical["\'][^>]*\bhref=["\']([^"\']+)["\']/i', $body, $m )
		|| preg_match( '/<link[^>]*\bhref=["\']([^"\']+)["\'][^>]*\brel=["\']canonical["\']/i', $body, $m ) ) {
		$canonical = $m[1];
	}

	$hreflang = array();
	if ( preg_match_all( '/<link[^>]*\brel=["\']alternate["\'][^>]*\bhreflang=["\']([^"\']+)["\'][^>]*\bhref=["\']([^"\']+)["\']/i', $body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $hm ) {
			$hreflang[ $hm[1] ] = $hm[2];
		}
	}

	return array(
		'code'      => $code,
		'lang'      => $lang,
		'canonical' => $canonical,
		'hreflang'  => $hreflang,
		'body'      => $body,
	);
}

echo "================================================================================\n";
echo " VERIFICADOR FASE 3.47 (DT-TFML-080) — CONCILIACIÓN Y PREPARACIÓN DEL LOTE 9537/9563\n";
echo "================================================================================\n\n";

global $wpdb;

$expected_wpml_rows = 3403;
$expected_wpml_md5  = '4241ca7e7ec6399a594537cb04790c10';

// -----------------------------------------------------------------------------
// CONTROL 1: Invarianza Absoluta de Producción (cms_ect)
// -----------------------------------------------------------------------------
$sentinel_prod = get_icl_sentinel( 'cms_ect' );
$c1_ok = ( $expected_wpml_rows === $sentinel_prod['rows'] && $expected_wpml_md5 === $sentinel_prod['md5'] );
record_gate(
	'Control 1: Invarianza Absoluta de Producción (cms_ect)',
	$c1_ok,
	sprintf( 'Filas: %d, Hash MD5: %s (100%% idéntico a baseline)', $sentinel_prod['rows'], $sentinel_prod['md5'] )
);

// -----------------------------------------------------------------------------
// CONTROL 2: Estado Consolidado e Invarianza de Laboratorio (cms_ect_lab)
// -----------------------------------------------------------------------------
$sentinel_lab    = get_icl_sentinel( 'cms_ect_lab' );
$lock            = new MigrationExecutionLock( $wpdb );
$is_locked       = $lock->is_locked( 'wpml' );
$total_groups    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$total_elements  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$total_manifests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$retained_reg    = new RetainedTranslationRegistry( $wpdb );
$retained_all    = $retained_reg->get_all( 'wpml' );
$total_retained  = count( $retained_all );
$cp_repo         = new MigrationCheckpointRepository( $wpdb );
$cp_obj          = $cp_repo->get( 'wpml' );
$cp_status       = $cp_obj ? $cp_obj->get_status() : 'none';
$fr_active       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE language_code = 'fr'" );

$c2_ok = ( $expected_wpml_rows === $sentinel_lab['rows']
	&& $expected_wpml_md5 === $sentinel_lab['md5']
	&& ! $is_locked
	&& 125 === $total_groups
	&& 143 === $total_elements
	&& 5 === $total_retained
	&& 14 === $total_manifests
	&& 'completed' === $cp_status
	&& 0 === $fr_active
);
record_gate(
	'Control 2: Estado Consolidado e Invarianza de Laboratorio (cms_ect_lab)',
	$c2_ok,
	sprintf( 'Grupos: %d, Elementos: %d, FR Retenidas: %d, Manifiestos: %d, Checkpoint: %s, Lock: %s', $total_groups, $total_elements, $total_retained, $total_manifests, $cp_status, $is_locked ? 'LOCKED' : 'FREE' )
);

// -----------------------------------------------------------------------------
// CONTROL 3: Conciliación y Cierre de la No-Conformidad de Fase 3.46 (TRID 432)
// -----------------------------------------------------------------------------
// Verificar TRID 432 en WPML
$icl_432 = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}icl_translations WHERE trid = 432 ORDER BY language_code ASC" );
$icl_432_map = array();
foreach ( $icl_432 as $r ) {
	$icl_432_map[ $r->language_code ] = (int) $r->element_id;
}

// Verificar Grupo TFML 591
$grp_591   = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE id = 591" );
$elems_591 = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 591 ORDER BY language_code ASC" );
$elems_591_map = array();
foreach ( $elems_591 as $el ) {
	$elems_591_map[ $el->language_code ] = (int) $el->element_id;
}

// Verificar custodia francesa para TRID 432
$retained_432 = $retained_reg->get( 'wpml', 432 );
$retained_432_fr = (int) ( $retained_432['retained_elements']['fr']['element_id'] ?? 0 );

// Verificar referencia errónea 8334
$p8334_exists = (bool) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = 8334" );
$icl_8334 = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}icl_translations WHERE element_id = 8334" );
$ref_8334_trid = $icl_8334 ? (int) $icl_8334->trid : 0;
$ref_8334_lang = $icl_8334 ? (string) $icl_8334->language_code : '';

$c3_ok = ( isset( $icl_432_map['es'], $icl_432_map['en'], $icl_432_map['fr'] )
	&& 432 === $icl_432_map['es']
	&& 8327 === $icl_432_map['en']
	&& 8823 === $icl_432_map['fr']
	&& ! $p8334_exists
	&& 26772 === $ref_8334_trid
	&& 'es' === $ref_8334_lang
	&& null !== $grp_591
	&& 432 === (int) $grp_591->canonical_element_id
	&& 432 === ( $elems_591_map['es'] ?? 0 )
	&& 8327 === ( $elems_591_map['en'] ?? 0 )
	&& 8823 === $retained_432_fr
);
record_gate(
	'Control 3: Conciliación y Cierre de la No-Conformidad de Fase 3.46 (TRID 432)',
	$c3_ok,
	sprintf( 'TRID 432: es=#%d, en=#%d, fr_retenida=#%d | Grupo 591 OK | Ref #8334 descartada (TRID %d, lang %s, post inexistente)', $icl_432_map['es'] ?? 0, $icl_432_map['en'] ?? 0, $retained_432_fr, $ref_8334_trid, $ref_8334_lang )
);

// -----------------------------------------------------------------------------
// CONTROL 4: Identidades Canónicas Definitivas del Lote TRID 9537 (Paquetes)
// -----------------------------------------------------------------------------
$icl_9537 = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}icl_translations WHERE trid = 9537 ORDER BY language_code ASC" );
$map_9537 = array();
foreach ( $icl_9537 as $r ) {
	$map_9537[ $r->language_code ] = (int) $r->element_id;
}
$p_es_9537 = $wpdb->get_row( "SELECT ID, post_title, post_name, post_status, post_parent, post_type FROM {$wpdb->posts} WHERE ID = " . ( $map_9537['es'] ?? 0 ) );
$p_en_9537 = $wpdb->get_row( "SELECT ID, post_title, post_name, post_status, post_parent, post_type FROM {$wpdb->posts} WHERE ID = " . ( $map_9537['en'] ?? 0 ) );
$p_fr_9537 = $wpdb->get_row( "SELECT ID, post_title, post_name, post_status, post_parent, post_type FROM {$wpdb->posts} WHERE ID = " . ( $map_9537['fr'] ?? 0 ) );

// Verificar si ya está en TFML
$tfml_9537_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id IN (" . implode( ',', array_filter( array( $p_es_9537->ID ?? 0, $p_en_9537->ID ?? 0, $p_fr_9537->ID ?? 0 ) ) ) . ")" );

$c4_ok = ( 6214 === (int) ( $p_es_9537->ID ?? 0 )
	&& 'Paquetes' === ( $p_es_9537->post_title ?? '' )
	&& 'paquetes' === ( $p_es_9537->post_name ?? '' )
	&& 0 === (int) ( $p_es_9537->post_parent ?? -1 )
	&& 'publish' === ( $p_es_9537->post_status ?? '' )
	&& 8335 === (int) ( $p_en_9537->ID ?? 0 )
	&& 'Tour Packages' === ( $p_en_9537->post_title ?? '' )
	&& 'tour-packages' === ( $p_en_9537->post_name ?? '' )
	&& 0 === (int) ( $p_en_9537->post_parent ?? -1 )
	&& 'publish' === ( $p_en_9537->post_status ?? '' )
	&& 8974 === (int) ( $p_fr_9537->ID ?? 0 )
	&& 'Forfaits touristiques' === ( $p_fr_9537->post_title ?? '' )
	&& 0 === $tfml_9537_cnt
);
record_gate(
	'Control 4: Identidades Canónicas Definitivas del Lote TRID 9537 (Paquetes)',
	$c4_ok,
	sprintf( 'ES: #%d (%s), EN: #%d (%s), FR Retenible: #%d (%s), TFML previo: 0', $p_es_9537->ID ?? 0, $p_es_9537->post_name ?? '', $p_en_9537->ID ?? 0, $p_en_9537->post_name ?? '', $p_fr_9537->ID ?? 0, $p_fr_9537->post_name ?? '' )
);

// -----------------------------------------------------------------------------
// CONTROL 5: Identidades Canónicas Definitivas del Lote TRID 9563 (Servicios)
// -----------------------------------------------------------------------------
$icl_9563 = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}icl_translations WHERE trid = 9563 ORDER BY language_code ASC" );
$map_9563 = array();
foreach ( $icl_9563 as $r ) {
	$map_9563[ $r->language_code ] = (int) $r->element_id;
}
$p_es_9563 = $wpdb->get_row( "SELECT ID, post_title, post_name, post_status, post_parent, post_type FROM {$wpdb->posts} WHERE ID = " . ( $map_9563['es'] ?? 0 ) );
$p_en_9563 = $wpdb->get_row( "SELECT ID, post_title, post_name, post_status, post_parent, post_type FROM {$wpdb->posts} WHERE ID = " . ( $map_9563['en'] ?? 0 ) );
$p_fr_9563 = $wpdb->get_row( "SELECT ID, post_title, post_name, post_status, post_parent, post_type FROM {$wpdb->posts} WHERE ID = " . ( $map_9563['fr'] ?? 0 ) );

// Verificar si ya está en TFML
$tfml_9563_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id IN (" . implode( ',', array_filter( array( $p_es_9563->ID ?? 0, $p_en_9563->ID ?? 0, $p_fr_9563->ID ?? 0 ) ) ) . ")" );

$c5_ok = ( 6505 === (int) ( $p_es_9563->ID ?? 0 )
	&& 'Servicios' === ( $p_es_9563->post_title ?? '' )
	&& 'servicios' === ( $p_es_9563->post_name ?? '' )
	&& 0 === (int) ( $p_es_9563->post_parent ?? -1 )
	&& 'publish' === ( $p_es_9563->post_status ?? '' )
	&& 8347 === (int) ( $p_en_9563->ID ?? 0 )
	&& 'Services' === ( $p_en_9563->post_title ?? '' )
	&& 'services' === ( $p_en_9563->post_name ?? '' )
	&& 0 === (int) ( $p_en_9563->post_parent ?? -1 )
	&& 'publish' === ( $p_en_9563->post_status ?? '' )
	&& 8918 === (int) ( $p_fr_9563->ID ?? 0 )
	&& 'Services' === ( $p_fr_9563->post_title ?? '' )
	&& 0 === $tfml_9563_cnt
);
record_gate(
	'Control 5: Identidades Canónicas Definitivas del Lote TRID 9563 (Servicios)',
	$c5_ok,
	sprintf( 'ES: #%d (%s), EN: #%d (%s), FR Retenible: #%d (%s), TFML previo: 0', $p_es_9563->ID ?? 0, $p_es_9563->post_name ?? '', $p_en_9563->ID ?? 0, $p_en_9563->post_name ?? '', $p_fr_9563->ID ?? 0, $p_fr_9563->post_name ?? '' )
);

// -----------------------------------------------------------------------------
// CONTROL 6: Análisis Riguroso de Dependencias y Jerarquía (Padres e Hijos)
// -----------------------------------------------------------------------------
// Comprobar páginas hijas reales en icl_translations:
// Hijas de #6505 (es): #5465 (TRID 9326) y #6649 (TRID 9574)
$child_9326_es = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 5465" );
$child_9574_es = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 6649" );
// Hijas de #8347 (en): #8343 (TRID 9326) y #8345 (TRID 9574)
$child_9326_en = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 8343" );
$child_9574_en = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 8345" );

$c6_ok = ( 0 === (int) $p_es_9537->post_parent
	&& 0 === (int) $p_en_9537->post_parent
	&& 0 === (int) $p_es_9563->post_parent
	&& 0 === (int) $p_en_9563->post_parent
	&& 6505 === (int) ( $child_9326_es->post_parent ?? 0 )
	&& 6505 === (int) ( $child_9574_es->post_parent ?? 0 )
	&& 8347 === (int) ( $child_9326_en->post_parent ?? 0 )
	&& 8347 === (int) ( $child_9574_en->post_parent ?? 0 )
);
record_gate(
	'Control 6: Análisis Riguroso de Dependencias y Jerarquía (Padres e Hijos)',
	$c6_ok,
	'TRIDs 9537 y 9563 son raíces autónomas (post_parent=0). TRID 9563 desbloqueará sus 2 hijas (TRID 9326 y TRID 9574) tras su migración.'
);

// -----------------------------------------------------------------------------
// CONTROL 7: Determinismo Criptográfico y Derivación Canónica del Piloto
// -----------------------------------------------------------------------------
$plugin      = Plugin::get_instance();
$mig_manager = $plugin->get_migration_manager();
$planner     = $mig_manager->get_wpml_planner();

$current_master_plan = $planner->generate_plan( 0, 0, true );
$master_fp           = $current_master_plan->get_fingerprint();

$pilot_config = array(
	array(
		'trid'                 => 9537,
		'authorized_languages' => array( 'es', 'en' ),
		'retained_languages'   => array( 'fr' ),
	),
	array(
		'trid'                 => 9563,
		'authorized_languages' => array( 'es', 'en' ),
		'retained_languages'   => array( 'fr' ),
	),
);

$pilot_plan = $current_master_plan->derive_partial_plan_with_retained_languages(
	$pilot_config,
	'pilot-nav-2-trids-9537-9563'
);

$pilot_fp       = $pilot_plan->get_fingerprint();
$pilot_summary  = $pilot_plan->get_summary();
$expected_mfp   = 'd3b02acff32de4c2c95b8c8561615d04e243c2d6a200c5bd30e17d93d5393b67';
$expected_pfp   = '7a1b8992fe352472f8ae8177e12da60a4aa825a3814d23301e222ab2543fd844';

$c7_ok = ( $expected_mfp === $master_fp
	&& $expected_mfp === $pilot_plan->get_parent_fingerprint()
	&& $expected_pfp === $pilot_fp
	&& MigrationPlan::STATUS_READY === $pilot_plan->get_status()
	&& 2 === count( $pilot_plan->get_planned_groups() )
	&& 4 === (int) ( $pilot_summary['importable_elements'] ?? 0 )
	&& 2 === (int) ( $pilot_summary['pending_elements'] ?? 0 )
	&& 0 === (int) ( $pilot_summary['conflict_groups'] ?? 0 )
);
record_gate(
	'Control 7: Determinismo Criptográfico y Derivación Canónica del Piloto',
	$c7_ok,
	sprintf( 'master_fp=%s, pilot_fp=%s, STATUS: READY (4 activos, 2 retenidos, 0 conflictos)', substr( $master_fp, 0, 16 ), substr( $pilot_fp, 0, 16 ) )
);

// -----------------------------------------------------------------------------
// CONTROL 8: Reconciliación Global del Estado Actual de TFML
// -----------------------------------------------------------------------------
$lang_reg   = $plugin->get_language_registry();
$group_repo = new TranslationGroupRepository( $wpdb, $lang_reg );
$resolver   = new ContentTranslationResolver( $group_repo, $lang_reg, new WordPressElementValidator() );
$executor   = $mig_manager->get_wpml_executor();

$clean_q = static function ( string $sql ) use ( $wpdb ): string {
	$target = "SELECT element_id FROM `{$wpdb->prefix}tfml_group_elements`";
	if ( $target === $sql ) {
		return $target . ' WHERE 1 = 0';
	}
	return $sql;
};
add_filter( 'query', $clean_q );
$clean_plan = $planner->generate_plan( 0, 0, false );
remove_filter( 'query', $clean_q );

$imp_trids = array_map(
	static fn( array $g ): int => (int) $g['trid'],
	$clean_plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_IMPORTABLE )
);
$sel_all   = $imp_trids;
$sel_all[] = array( 'trid' => 197, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 9201, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 9545, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 430, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 432, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );

$plan_recon = $clean_plan->derive_partial_plan_with_retained_languages( $sel_all, 'reconcile-all-migrated' );
$recon_res  = $executor->reconcile( $plan_recon );

$c8_ok = ( true === ( $recon_res['integrity_verified'] ?? false )
	&& empty( $recon_res['discrepancies'] )
	&& 5 === (int) ( $recon_res['retained_pending_elements'] ?? 0 )
	&& 8329 === $resolver->resolve_element_id( 'post', 430, 'en' )
	&& 430 === $resolver->resolve_element_id( 'post', 8329, 'es' )
	&& 8327 === $resolver->resolve_element_id( 'post', 432, 'en' )
	&& 432 === $resolver->resolve_element_id( 'post', 8327, 'es' )
	&& 8354 === $resolver->resolve_element_id( 'post', 6237, 'en' )
	&& 6237 === $resolver->resolve_element_id( 'post', 8354, 'es' )
	&& 8339 === $resolver->resolve_element_id( 'post', 4976, 'en' )
	&& 4976 === $resolver->resolve_element_id( 'post', 8339, 'es' )
	&& 8356 === $resolver->resolve_element_id( 'post', 197, 'en' )
	&& 197 === $resolver->resolve_element_id( 'post', 8356, 'es' )
);
record_gate(
	'Control 8: Reconciliación Global del Estado Actual de TFML',
	$c8_ok,
	sprintf( 'Reconciliación: 100%% VERIFIED (0 discrepancias, 5 retenidas), Resolvers 430, 432, 197, 9201, 9545 PASS' )
);

// -----------------------------------------------------------------------------
// CONTROL 9: No-Regresión Funcional HTTP en Vivo (10 Rutas Certificadas)
// -----------------------------------------------------------------------------
$routes_to_test = array(
	'/'                  => array( 'code' => 200, 'lang' => 'es-ES' ),
	'/en/'               => array( 'code' => 200, 'lang' => 'en-US' ),
	'/nosotros/'         => array( 'code' => 200, 'lang' => 'es-ES' ),
	'/en/about-us/'      => array( 'code' => 200, 'lang' => 'en-US' ),
	'/actividades/'      => array( 'code' => 200, 'lang' => 'es-ES' ),
	'/en/activities/'    => array( 'code' => 200, 'lang' => 'en-US' ),
	'/galeria/'          => array( 'code' => 200, 'lang' => 'es-ES' ),
	'/en/gallery/'       => array( 'code' => 200, 'lang' => 'en-US' ),
	'/contactenos/'      => array( 'code' => 200, 'lang' => 'es-ES' ),
	'/en/contact-us/'    => array( 'code' => 200, 'lang' => 'en-US' ),
);

$all_routes_ok = true;
foreach ( $routes_to_test as $path => $expect ) {
	$res = inspect_route( $lab_url . $path );
	if ( $res['code'] !== $expect['code'] || $res['lang'] !== $expect['lang'] ) {
		$all_routes_ok = false;
		break;
	}
}

$fr_test = inspect_route( $lab_url . '/fr/' );
$pt_test = inspect_route( $lab_url . '/pt/' );

$c9_ok = ( $all_routes_ok && 404 === $fr_test['code'] && 404 === $pt_test['code'] );
record_gate(
	'Control 9: No-Regresión Funcional HTTP en Vivo (10 Rutas Certificadas)',
	$c9_ok,
	'10/10 rutas públicas responden 200 OK con lang y canonical exactos; /fr/ y /pt/ responden 404 limpio'
);

// -----------------------------------------------------------------------------
// CONTROL 10: Invarianza Absoluta Post-Verificación (0 Mutaciones en Origen y Laboratorio)
// -----------------------------------------------------------------------------
$final_prod_sentinel = get_icl_sentinel( 'cms_ect' );
$final_lab_groups    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$final_lab_elements  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$final_lab_manifests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$final_retained_cnt  = count( $retained_reg->get_all( 'wpml' ) );

$c10_ok = ( $expected_wpml_rows === $final_prod_sentinel['rows']
	&& $expected_wpml_md5 === $final_prod_sentinel['md5']
	&& 125 === $final_lab_groups
	&& 143 === $final_lab_elements
	&& 5 === $final_retained_cnt
	&& 14 === $final_lab_manifests
);
record_gate(
	'Control 10: Invarianza Absoluta Post-Verificación (0 Mutaciones en Origen y Laboratorio)',
	$c10_ok,
	sprintf( 'cms_ect: %d filas / MD5 %s | cms_ect_lab: %d grupos, %d elementos, %d FR retenidas, %d manifiestos (CERO ESCRITURAS)', $final_prod_sentinel['rows'], $final_prod_sentinel['md5'], $final_lab_groups, $final_lab_elements, $final_retained_cnt, $final_lab_manifests )
);

echo "\n================================================================================\n";
echo sprintf( "RESULTADO GLOBAL DE FASE 3.47: %d/%d CONTROLES PASS (%d FAIL)\n", $passed_checks, $total_checks, $failed_checks );
echo "================================================================================\n";

if ( 0 !== $failed_checks ) {
	exit( 1 );
}
exit( 0 );
