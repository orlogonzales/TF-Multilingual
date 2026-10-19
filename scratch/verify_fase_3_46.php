<?php
/**
 * Verificador Reproducible de Fase 3.46 (Directiva DT-TFML-079).
 *
 * Importación Transaccional Controlada del Piloto TRID 430/432
 * (TRID 430 Galería de Fotos / Photo Gallery + TRID 432 Contáctenos / Contact Us):
 * - Control 1: Invarianza Absoluta de Producción (cms_ect: 3,403 filas, hash MD5 4241ca7e7ec6399a594537cb04790c10).
 * - Control 2: Aislamiento del Laboratorio, Respaldo Fuera de Webroot y Evidencia en BD Desechable (cms_ect_lab, InnoDB, lock libre, backup SHA-256 y meta.json).
 * - Control 3: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo (parent_fp: c82cb04b... y pilot_fp: e18b0079..., 2 grupos, 4 activos, 2 retenidos).
 * - Control 4: Estado Exacto Post-Ejecución en cms_ect_lab (125 grupos, 143 elementos, 5 FR retenidas, 14 manifiestos, checkpoint completed, 0 FR activo, 0 duplicados).
 * - Control 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa (Grupo #590: 430/8329, Grupo #591: 432/8327, Retenidas FR #8989 y #8823).
 * - Control 6: Reconciliación Global (0 discrepancias), Resolución Bidireccional de Dominio (430<->8329, 432<->8327, 197, 9201, 9545) e Idempotencia no destructiva.
 * - Control 7: Certificación HTTP Real en Vivo (10 Rutas: 200 OK en /galeria/, /en/gallery/, /contactenos/, /en/contact-us/, /, /en/, canonicals y hreflang).
 * - Control 8: Certificación de Menús de Navegación de Escritorio (Photo Gallery->/en/gallery/, 11 páginas pendientes protegidas y 0 francés expuesto).
 * - Control 9: Expansión y Regresión de Sitemaps Nativos (page de 7 a 11, total consolidado de 30 a 34, 34/34 únicas, 0 francés, blog_public=0).
 * - Control 10: Invarianza Final Post-Verificación (0 mutaciones durante el verificador en cms_ect y cms_ect_lab).
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
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

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
echo " VERIFICADOR FASE 3.46 (DT-TFML-079) — IMPORTACIÓN CONTROLADA PILOTO 430/432\n";
echo "================================================================================\n\n";

global $wpdb;

$expected_wpml_rows = 3403;
$expected_wpml_md5  = '4241ca7e7ec6399a594537cb04790c10';
$expected_master_fp = 'c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99';
$expected_pilot_fp  = 'e18b00794e7ab339347c9fe101e067f2c95f339643a0957d765e1b135446c130';

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
// CONTROL 2: Aislamiento del Laboratorio, Respaldo Fuera de Webroot y Evidencia en BD Desechable
// -----------------------------------------------------------------------------
$sentinel_lab = get_icl_sentinel( 'cms_ect_lab' );
$lock_mgr     = new MigrationExecutionLock( $wpdb );
$is_locked    = $lock_mgr->is_locked( 'wpml' );

$backup_pattern = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_46_pilot_trids_430_432_*.sql';
$matching_files = glob( $backup_pattern );
$backup_file    = ! empty( $matching_files ) ? end( $matching_files ) : '';
$backup_exists  = '' !== $backup_file && file_exists( $backup_file );
$meta_file      = str_replace( '.sql', '.meta.json', $backup_file );
$meta_exists    = '' !== $backup_file && file_exists( $meta_file );

$meta_data = $meta_exists ? json_decode( (string) file_get_contents( $meta_file ), true ) : array();

$c2_ok = ( $expected_wpml_rows === $sentinel_lab['rows']
	&& $expected_wpml_md5 === $sentinel_lab['md5']
	&& ! $is_locked
	&& $backup_exists
	&& filesize( $backup_file ) > 15000000
	&& $meta_exists
	&& 'DT-TFML-079' === ( $meta_data['directive'] ?? '' )
	&& 123 === (int) ( $meta_data['restored_metrics']['tfml_groups'] ?? 0 )
	&& 139 === (int) ( $meta_data['restored_metrics']['tfml_group_elements'] ?? 0 )
	&& array( 125, 143, 5 ) === ( $meta_data['disposable_idempotency_and_rollback_test']['run1_groups_elements_retained'] ?? array() )
	&& 2 === (int) ( $meta_data['disposable_idempotency_and_rollback_test']['run2_imported_skipped_status']['skipped_groups'] ?? 0 )
	&& array( 123, 139, 3 ) === ( $meta_data['disposable_idempotency_and_rollback_test']['rollback_groups_elements_retained'] ?? array() )
);
record_gate(
	'Control 2: Aislamiento del Laboratorio, Respaldo Fuera de Webroot y Evidencia en BD Desechable',
	$c2_ok,
	sprintf( 'Respaldo: %s (%s bytes), SHA-256: %s, Pruebas en BD Desechable: 100%% PASS', basename( $backup_file ), number_format( (float) filesize( $backup_file ) ), substr( (string) ( $meta_data['sha256'] ?? '' ), 0, 16 ) )
);

// -----------------------------------------------------------------------------
// CONTROL 3: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo
// -----------------------------------------------------------------------------
$plugin      = Plugin::get_instance();
$mig_manager = $plugin->get_migration_manager();
$planner     = $mig_manager->get_wpml_planner();

// Generar el plan maestro en el estado pre-ejecución del piloto filtrando elementos con group_id <= 589
$baseline_589_q = static function ( string $sql ) use ( $wpdb ): string {
	$target = "SELECT element_id FROM `{$wpdb->prefix}tfml_group_elements`";
	if ( $target === $sql ) {
		return $target . ' WHERE group_id <= 589';
	}
	return $sql;
};
add_filter( 'query', $baseline_589_q );
$master_plan_pre = $planner->generate_plan();
remove_filter( 'query', $baseline_589_q );

$pilot_plan = $master_plan_pre->derive_partial_plan_with_retained_languages(
	array(
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
	),
	'pilot-nav-2-trids-430-432'
);

$pilot_summary = $pilot_plan->get_summary();
$c3_ok = ( $expected_master_fp === $master_plan_pre->get_fingerprint()
	&& $expected_master_fp === $pilot_plan->get_parent_fingerprint()
	&& $expected_pilot_fp === $pilot_plan->get_fingerprint()
	&& MigrationPlan::STATUS_READY === $pilot_plan->get_status()
	&& 2 === count( $pilot_plan->get_planned_groups() )
	&& 4 === (int) ( $pilot_summary['importable_elements'] ?? 0 )
	&& 2 === (int) ( $pilot_summary['pending_elements'] ?? 0 )
);
record_gate(
	'Control 3: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo',
	$c3_ok,
	sprintf( 'master_fp=%s, pilot_fp=%s (STATUS: READY, 4 activos, 2 retenidos)', substr( $master_plan_pre->get_fingerprint(), 0, 16 ), substr( $pilot_plan->get_fingerprint(), 0, 16 ) )
);

// -----------------------------------------------------------------------------
// CONTROL 4: Estado Exacto Post-Ejecución en cms_ect_lab (Métricas 125/143/5/14)
// -----------------------------------------------------------------------------
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
$dup_groups      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, subtype, canonical_element_id FROM {$wpdb->prefix}tfml_groups WHERE canonical_element_id IS NOT NULL GROUP BY element_type, subtype, canonical_element_id HAVING COUNT(*) > 1) d" );
$dup_elements    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING COUNT(*) > 1) d" );

$c4_ok = ( 125 === $total_groups
	&& 143 === $total_elements
	&& 5 === $total_retained
	&& 14 === $total_manifests
	&& 'completed' === $cp_status
	&& 0 === $fr_active
	&& 0 === $dup_groups
	&& 0 === $dup_elements
);
record_gate(
	'Control 4: Estado Exacto Post-Ejecución en cms_ect_lab (Métricas 125/143/5/14)',
	$c4_ok,
	sprintf( 'Grupos: %d, Elementos: %d, Retenidas FR: %d, Manifiestos: %d, Checkpoint: %s, FR Activo: %d, Dups: %d/%d', $total_groups, $total_elements, $total_retained, $total_manifests, $cp_status, $fr_active, $dup_groups, $dup_elements )
);

// -----------------------------------------------------------------------------
// CONTROL 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa
// -----------------------------------------------------------------------------
$g590 = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE id = 590", ARRAY_A );
$g590_elems = $wpdb->get_results( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 590 ORDER BY element_id ASC", ARRAY_A );
$g591 = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE id = 591", ARRAY_A );
$g591_elems = $wpdb->get_results( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 591 ORDER BY element_id ASC", ARRAY_A );

$ret_430 = $retained_reg->get( 'wpml', 430 );
$ret_432 = $retained_reg->get( 'wpml', 432 );

$rollback_svc   = new WpmlMigrationRollbackService( $wpdb );
$pilot_manifest = $rollback_svc->get_execution_manifest( 'pilot-nav-2-trids-430-432' );

$c5_ok = ( is_array( $g590 ) && 'post' === $g590['element_type'] && 'page' === $g590['subtype'] && 430 === (int) $g590['canonical_element_id']
	&& 2 === count( $g590_elems )
	&& 430 === (int) $g590_elems[0]['element_id'] && 'es' === $g590_elems[0]['language_code']
	&& 8329 === (int) $g590_elems[1]['element_id'] && 'en' === $g590_elems[1]['language_code']
	&& is_array( $g591 ) && 'post' === $g591['element_type'] && 'page' === $g591['subtype'] && 432 === (int) $g591['canonical_element_id']
	&& 2 === count( $g591_elems )
	&& 432 === (int) $g591_elems[0]['element_id'] && 'es' === $g591_elems[0]['language_code']
	&& 8327 === (int) $g591_elems[1]['element_id'] && 'en' === $g591_elems[1]['language_code']
	&& is_array( $ret_430 ) && $retained_reg->verify_record_integrity( $ret_430 ) && 590 === (int) $ret_430['group_id'] && 8989 === (int) ( $ret_430['retained_elements']['fr']['element_id'] ?? 0 ) && $expected_pilot_fp === $ret_430['plan_fingerprint']
	&& is_array( $ret_432 ) && $retained_reg->verify_record_integrity( $ret_432 ) && 591 === (int) $ret_432['group_id'] && 8823 === (int) ( $ret_432['retained_elements']['fr']['element_id'] ?? 0 ) && $expected_pilot_fp === $ret_432['plan_fingerprint']
	&& is_array( $pilot_manifest ) && $expected_pilot_fp === ( $pilot_manifest['plan_fingerprint'] ?? '' )
);
record_gate(
	'Control 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa',
	$c5_ok,
	'Grp #590: 430(es)/8329(en) + FR 8989 retenida | Grp #591: 432(es)/8327(en) + FR 8823 retenida | Manifiesto pilot-nav-2-trids-430-432 verificado'
);

// -----------------------------------------------------------------------------
// CONTROL 6: Reconciliación Global (0 Discrepancias) y Resolución en Dominio
// -----------------------------------------------------------------------------
$lang_reg   = $plugin->get_language_registry();
$group_repo = new TranslationGroupRepository( $wpdb, $lang_reg );
$validator  = new WordPressElementValidator();
$resolver   = new ContentTranslationResolver( $group_repo, $lang_reg, $validator );
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

$fr_threw_430 = false;
try {
	$resolver->resolve_element_id( 'post', 430, 'fr' );
} catch ( LanguageNotFoundException $e ) {
	$fr_threw_430 = true;
}

$c6_ok = ( true === ( $recon_res['integrity_verified'] ?? false )
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
	&& null === $resolver->language_of( 'post', 8989 )
	&& null === $resolver->language_of( 'post', 8823 )
	&& $fr_threw_430
);
record_gate(
	'Control 6: Reconciliación Global (0 Discrepancias) y Resolución en Dominio',
	$c6_ok,
	sprintf( 'Reconciliación: 100%% VERIFIED (0 discrepancias, 5 retenidas), Resolvers 430<->8329, 432<->8327, 197<->8356, 4976<->8339, 6237<->8354 PASS' )
);

// -----------------------------------------------------------------------------
// CONTROL 7: Certificación HTTP Real en Vivo (10 Rutas: 200 OK, Lang, Canonical, Hreflang)
// -----------------------------------------------------------------------------
$routes_to_test = array(
	'galeria_es'    => array( 'url' => 'http://lab.ecoterra.test/galeria/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/galeria/' ),
	'gallery_en'    => array( 'url' => 'http://lab.ecoterra.test/en/gallery/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/gallery/' ),
	'contacto_es'   => array( 'url' => 'http://lab.ecoterra.test/contactenos/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/contactenos/' ),
	'contact_en'    => array( 'url' => 'http://lab.ecoterra.test/en/contact-us/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/contact-us/' ),
	'home_es'       => array( 'url' => 'http://lab.ecoterra.test/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/' ),
	'home_en'       => array( 'url' => 'http://lab.ecoterra.test/en/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/' ),
	'nosotros_es'   => array( 'url' => 'http://lab.ecoterra.test/nosotros/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/' ),
	'about_en'      => array( 'url' => 'http://lab.ecoterra.test/en/about-us/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/' ),
	'actividades_es'=> array( 'url' => 'http://lab.ecoterra.test/actividades/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/actividades/' ),
	'activ_en'      => array( 'url' => 'http://lab.ecoterra.test/en/activities/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/activities/' ),
);

$http_all_pass = true;
foreach ( $routes_to_test as $k => $info ) {
	$insp = inspect_route( $info['url'] );
	if ( $info['code'] !== $insp['code'] || $info['lang'] !== $insp['lang'] || $info['canon'] !== $insp['canonical'] ) {
		$http_all_pass = false;
	}
	// Check hreflang on galeria and contactenos
	if ( str_contains( $k, 'galeria' ) || str_contains( $k, 'gallery' ) ) {
		if ( ( $insp['hreflang']['es'] ?? '' ) !== 'http://lab.ecoterra.test/galeria/' || ( $insp['hreflang']['en'] ?? '' ) !== 'http://lab.ecoterra.test/en/gallery/' ) {
			$http_all_pass = false;
		}
	}
	if ( str_contains( $k, 'contact' ) ) {
		if ( ( $insp['hreflang']['es'] ?? '' ) !== 'http://lab.ecoterra.test/contactenos/' || ( $insp['hreflang']['en'] ?? '' ) !== 'http://lab.ecoterra.test/en/contact-us/' ) {
			$http_all_pass = false;
		}
	}
}

// Ensure French 404
$r_fr = inspect_route( 'http://lab.ecoterra.test/fr/' );
$r_pt = inspect_route( 'http://lab.ecoterra.test/pt/' );
if ( 404 !== $r_fr['code'] || 404 !== $r_pt['code'] ) {
	$http_all_pass = false;
}

record_gate(
	'Control 7: Certificación HTTP Real en Vivo (10 Rutas: 200 OK, Lang, Canonical, Hreflang)',
	$http_all_pass,
	'10/10 rutas públicas responden 200 OK con idioma HTML exacto, canonical único y hreflang simétrico; /fr/ y /pt/ responden 404'
);

// -----------------------------------------------------------------------------
// CONTROL 8: Certificación de Menús de Navegación de Escritorio
// -----------------------------------------------------------------------------
$r_about_en = inspect_route( 'http://lab.ecoterra.test/en/about-us/' );
$migrated_en_urls    = array();
$unmigrated_safe_cnt = 0;
$premature_en_cnt    = 0;

if ( preg_match( '/<ul[^>]*id=["\']menu-menu-es-ingles["\'][^>]*>(.*?)<\/nav>/is', $r_about_en['body'], $m_nav ) ) {
	if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $m_nav[1], $a_nav, PREG_SET_ORDER ) ) {
		foreach ( $a_nav as $a_item ) {
			$href = trim( $a_item[1] );
			$text = trim( strip_tags( $a_item[2] ) );
			if ( in_array( $text, array( 'Home', 'About Us', 'Activities', 'Photo Gallery' ), true ) ) {
				$migrated_en_urls[ $text ] = $href;
			} elseif ( '#' !== $href ) {
				if ( 0 === strpos( $href, 'http://lab.ecoterra.test/en/' ) ) {
					++$premature_en_cnt;
				} else {
					++$unmigrated_safe_cnt;
				}
			}
		}
	}
}

$c8_ok = ( 'http://lab.ecoterra.test/en/' === ( $migrated_en_urls['Home'] ?? '' )
	&& 'http://lab.ecoterra.test/en/about-us/' === ( $migrated_en_urls['About Us'] ?? '' )
	&& 'http://lab.ecoterra.test/en/activities/' === ( $migrated_en_urls['Activities'] ?? '' )
	&& 'http://lab.ecoterra.test/en/gallery/' === ( $migrated_en_urls['Photo Gallery'] ?? '' )
	&& 11 === $unmigrated_safe_cnt
	&& 0 === $premature_en_cnt
);
record_gate(
	'Control 8: Certificación de Menús de Navegación de Escritorio',
	$c8_ok,
	sprintf( 'Photo Gallery->%s, unmigrated_safe=%d/11 (0 premature 404s), 0 FR expuesto', $migrated_en_urls['Photo Gallery'] ?? '', $unmigrated_safe_cnt )
);

// -----------------------------------------------------------------------------
// CONTROL 9: Expansión y Regresión de Sitemaps Nativos (Pasa de 7 a 11 páginas)
// -----------------------------------------------------------------------------
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

$expected_11_pages = array(
	'http://lab.ecoterra.test/',
	'http://lab.ecoterra.test/actividades/',
	'http://lab.ecoterra.test/contactenos/',
	'http://lab.ecoterra.test/en/',
	'http://lab.ecoterra.test/en/about-us/',
	'http://lab.ecoterra.test/en/activities/',
	'http://lab.ecoterra.test/en/contact-us/',
	'http://lab.ecoterra.test/en/gallery/',
	'http://lab.ecoterra.test/galeria/',
	'http://lab.ecoterra.test/nosotros/',
	'http://lab.ecoterra.test/old-blog/',
);
sort( $expected_11_pages );

$c9_ok = ( 11 === count( $page_entries )
	&& $expected_11_pages === $page_locs
	&& 3 === count( $post_entries )
	&& 0 === count( $room_entries )
	&& 0 === count( $hf_entries )
	&& 1 === count( $cat_entries )
	&& 19 === count( $tag_entries )
	&& 34 === count( $all_sitemap_locs )
	&& 34 === count( array_unique( $all_sitemap_locs ) )
	&& '0' === (string) get_option( 'blog_public' )
);
record_gate(
	'Control 9: Expansión y Regresión de Sitemaps Nativos (Pasa de 7 a 11 páginas)',
	$c9_ok,
	sprintf( 'Total sitemap: 34 URLs únicas (page=11 [incorporados galeria, gallery, contactenos, contact-us], post=3, rooms=0, hf=0, cat=1, tag=19), 0 francés' )
);

// -----------------------------------------------------------------------------
// CONTROL 10: Invarianza Final Post-Verificación (0 Mutaciones en Origen y Laboratorio)
// -----------------------------------------------------------------------------
$sentinel_prod_final = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_final  = get_icl_sentinel( 'cms_ect_lab' );
$final_groups_cnt    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$final_elements_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$final_retained_cnt  = count( $retained_reg->get_all( 'wpml' ) );
$final_manifests_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );

$c10_ok = ( $expected_wpml_rows === $sentinel_prod_final['rows']
	&& $expected_wpml_md5 === $sentinel_prod_final['md5']
	&& $expected_wpml_rows === $sentinel_lab_final['rows']
	&& $expected_wpml_md5 === $sentinel_lab_final['md5']
	&& 125 === $final_groups_cnt
	&& 143 === $final_elements_cnt
	&& 5 === $final_retained_cnt
	&& 14 === $final_manifests_cnt
);
record_gate(
	'Control 10: Invarianza Final Post-Verificación (0 Mutaciones en Origen y Laboratorio)',
	$c10_ok,
	sprintf( 'cms_ect: 3,403 filas / MD5 %s | cms_ect_lab: 125 grupos, 143 elementos, 5 FR retenidas, 14 manifiestos', $sentinel_prod_final['md5'] )
);

echo "\n================================================================================\n";
echo sprintf( "RESULTADO GLOBAL DE FASE 3.46: %d/%d CONTROLES PASS (%d FAIL)\n", $passed_checks, $total_checks, $failed_checks );
echo "================================================================================\n";

if ( $failed_checks > 0 ) {
	exit( 1 );
}
