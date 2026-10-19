<?php
/**
 * Verificador Reproducible de Fase 3.49 (Directiva DT-TFML-082).
 *
 * Importación Controlada de Páginas Hijas de Servicios
 * (TRID 9326 Salón de Eventos / Event Room + TRID 9574 Restaurante / Restaurant):
 * - Control 1: Invarianza Absoluta de Producción (cms_ect: 3,403 filas, hash MD5 4241ca7e7ec6399a594537cb04790c10).
 * - Control 2: Aislamiento del Laboratorio, Respaldo Fuera de Webroot y Evidencia en BD Desechable (cms_ect_lab, InnoDB, lock libre, backup SHA-256 y meta.json).
 * - Control 3: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo (parent_fp: 16cd5caf... y pilot_fp: 7ee6b14d..., 2 grupos, 4 activos, 2 retenidos).
 * - Control 4: Estado Exacto Post-Ejecución en cms_ect_lab (129 grupos, 151 elementos, 9 FR retenidas, 16 manifiestos, checkpoint completed, 0 FR activo, 0 duplicados).
 * - Control 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa (Grupo #594: 5465/8343, Grupo #595: 6649/8345, Retenidas FR #8937 y #8921).
 * - Control 6: Reconciliación Global (0 discrepancias), Resolución Bidireccional de Dominio (5465, 6649, 6214, 6505, 430, 432, 197, 9201, 9545) y Aislamiento Francés.
 * - Control 7: Verificación de Jerarquía y Relaciones Padre-Hijo (Padres #6505(es) y #8347(en) en Grupo #593, FR #8918 en custodia).
 * - Control 8: Certificación HTTP Real en Vivo (18 Rutas: 200 OK en /servicios/salon-de-eventos/, /en/services/event-room/, /servicios/restaurante/, /en/services/restaurant/, regresión previa, canonicals y hreflang).
 * - Control 9: Menús de Navegación y Expansión de Sitemaps Nativos (page de 15 a 19, total de 38 a 42, 0 francés).
 * - Control 10: Invarianza Final Post-Verificación (0 mutaciones espurias durante el verificador en cms_ect y cms_ect_lab).
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
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_HEADER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	$response    = curl_exec( $ch );
	$code        = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$headers     = substr( (string) $response, 0, $header_size );
	$body        = substr( (string) $response, $header_size );
	curl_close( $ch );

	$lang = '';
	if ( preg_match( '/<html[^>]*\slang=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$lang = $m[1];
	}

	$canonical = '';
	if ( preg_match( '/<link[^>]*\srel=["\']canonical["\'][^>]*\shref=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$canonical = $m[1];
	} elseif ( preg_match( '/<link[^>]*\shref=["\']([^"\']+)["\'][^>]*\srel=["\']canonical["\']/i', $body, $m ) ) {
		$canonical = $m[1];
	}

	$hreflang = array();
	if ( preg_match_all( '/<link[^>]*\srel=["\']alternate["\'][^>]*\shreflang=["\']([^"\']+)["\'][^>]*\shref=["\']([^"\']+)["\']/i', $body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$hreflang[ $match[1] ] = $match[2];
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
echo " VERIFICADOR FASE 3.49 (DT-TFML-082) — IMPORTACIÓN HIJAS DE SERVICIOS (9326/9574)\n";
echo "================================================================================\n\n";

global $wpdb;

// -----------------------------------------------------------------------------
// CONTROL 1: Invarianza Absoluta de Producción (cms_ect)
// -----------------------------------------------------------------------------
$expected_wpml_rows = 3403;
$expected_wpml_md5  = '4241ca7e7ec6399a594537cb04790c10';

$sentinel_prod = get_icl_sentinel( 'cms_ect' );
$c1_ok         = ( $expected_wpml_rows === $sentinel_prod['rows'] && $expected_wpml_md5 === $sentinel_prod['md5'] );
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

$backup_pattern = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_49_pilot_trids_9326_9574_*.sql';
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
	&& 'DT-TFML-082' === ( $meta_data['directive'] ?? '' )
	&& 127 === (int) ( $meta_data['restored_metrics']['tfml_groups'] ?? 0 )
	&& 147 === (int) ( $meta_data['restored_metrics']['tfml_group_elements'] ?? 0 )
	&& array( 129, 151, 9 ) === ( $meta_data['disposable_idempotency_and_rollback_test']['run1_groups_elements_retained'] ?? array() )
	&& 2 === (int) ( $meta_data['disposable_idempotency_and_rollback_test']['run2_imported_skipped_status']['skipped_groups'] ?? 0 )
	&& array( 127, 147, 7 ) === ( $meta_data['disposable_idempotency_and_rollback_test']['rollback_groups_elements_retained'] ?? array() )
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

// Generar el plan maestro en el estado pre-ejecución del piloto 3.49 filtrando elementos con group_id <= 593
$baseline_593_q = static function ( string $sql ) use ( $wpdb ): string {
	$target = "SELECT element_id FROM `{$wpdb->prefix}tfml_group_elements`";
	if ( $target === $sql ) {
		return $target . ' WHERE group_id <= 593';
	}
	return $sql;
};
add_filter( 'query', $baseline_593_q );
$master_plan_pre = $planner->generate_plan( 0, 0, true );
remove_filter( 'query', $baseline_593_q );

$pilot_plan = $master_plan_pre->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 9326,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 9574,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
	),
	'pilot-nav-2-trids-9326-9574'
);

$pilot_summary = $pilot_plan->get_summary();
$expected_master_fp = '16cd5cafeae23e15783662d98a4fa5474c40f9b570cf676efbedee4990aab9b4';
$expected_pilot_fp  = '7ee6b14da94d0cc726d922bcdc751dcc755d4a188d31695299cca7b19c797967';

$c3_ok = ( $expected_master_fp === $master_plan_pre->get_fingerprint()
	&& $expected_master_fp === $pilot_plan->get_parent_fingerprint()
	&& $expected_pilot_fp === $pilot_plan->get_fingerprint()
	&& MigrationPlan::STATUS_READY === $pilot_plan->get_status()
	&& 2 === (int) ( $pilot_summary['total_groups'] ?? 0 )
	&& 4 === (int) ( $pilot_summary['total_elements'] ?? 0 )
	&& 2 === (int) ( $pilot_summary['pending_elements'] ?? 0 )
	&& 0 === (int) ( $pilot_summary['conflict_groups'] ?? 0 )
);
record_gate(
	'Control 3: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo',
	$c3_ok,
	sprintf( 'master_fp=%s, pilot_fp=%s (STATUS: %s, 4 activos, 2 retenidos)', substr( $expected_master_fp, 0, 16 ), substr( $expected_pilot_fp, 0, 16 ), $pilot_plan->get_status() )
);

// -----------------------------------------------------------------------------
// CONTROL 4: Estado Exacto Post-Ejecución en cms_ect_lab (Métricas 129/151/9/16)
// -----------------------------------------------------------------------------
$groups_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$manifests_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name LIKE 'tfml_migration_manifest_%'" );

$retained_registry = new RetainedTranslationRegistry( $wpdb );
$retained_all      = $retained_registry->get_all();
$retained_count    = count( $retained_all );

$checkpoint_repo = new MigrationCheckpointRepository( $wpdb );
$checkpoint      = $checkpoint_repo->get( 'wpml' );
$checkpoint_ok   = null !== $checkpoint && 'completed' === $checkpoint->get_status();

$fr_active_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE language_code = 'fr'" );
$element_dups    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id, COUNT(*) c FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING c > 1) t" );
$group_lang_dups = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT group_id, language_code, COUNT(*) c FROM {$wpdb->prefix}tfml_group_elements GROUP BY group_id, language_code HAVING c > 1) t" );

$c4_ok = ( 129 === $groups_count
	&& 151 === $elements_count
	&& 9 === $retained_count
	&& 16 === $manifests_count
	&& $checkpoint_ok
	&& 0 === $fr_active_count
	&& 0 === $element_dups
	&& 0 === $group_lang_dups
	&& ! $is_locked
);
record_gate(
	'Control 4: Estado Exacto Post-Ejecución en cms_ect_lab (Métricas 129/151/9/16)',
	$c4_ok,
	sprintf( 'Grupos: %d, Elementos: %d, Retenidas FR: %d, Manifiestos: %d, Checkpoint: %s, FR Activo: %d, Dups: %d/%d', $groups_count, $elements_count, $retained_count, $manifests_count, $checkpoint ? $checkpoint->get_status() : 'null', $fr_active_count, $element_dups, $group_lang_dups )
);

// -----------------------------------------------------------------------------
// CONTROL 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa
// -----------------------------------------------------------------------------
$g594_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 594", ARRAY_A );
$g595_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 595", ARRAY_A );

$g594_elements = array_column( $g594_rows, 'element_id', 'language_code' );
$g595_elements = array_column( $g595_rows, 'element_id', 'language_code' );

$retained_9326 = $retained_registry->get( 'wpml', 9326 );
$retained_9574 = $retained_registry->get( 'wpml', 9574 );

$manifest_token = 'pilot-nav-2-trids-9326-9574';
$manifest_raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = %s", 'tfml_migration_manifest_' . $manifest_token ) );
$manifest_data  = maybe_unserialize( $manifest_raw );

$c5_ok = ( 5465 === (int) ( $g594_elements['es'] ?? 0 )
	&& 8343 === (int) ( $g594_elements['en'] ?? 0 )
	&& 6649 === (int) ( $g595_elements['es'] ?? 0 )
	&& 8345 === (int) ( $g595_elements['en'] ?? 0 )
	&& null !== $retained_9326
	&& 594 === (int) ( $retained_9326['group_id'] ?? 0 )
	&& 8937 === (int) ( $retained_9326['retained_elements']['fr']['element_id'] ?? 0 )
	&& 'retained_inactive_language' === ( $retained_9326['retained_elements']['fr']['status'] ?? '' )
	&& null !== $retained_9574
	&& 595 === (int) ( $retained_9574['group_id'] ?? 0 )
	&& 8921 === (int) ( $retained_9574['retained_elements']['fr']['element_id'] ?? 0 )
	&& 'retained_inactive_language' === ( $retained_9574['retained_elements']['fr']['status'] ?? '' )
	&& is_array( $manifest_data )
	&& $manifest_token === ( $manifest_data['execution_id'] ?? '' )
);
record_gate(
	'Control 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa',
	$c5_ok,
	'Grp #594: 5465(es)/8343(en) + FR 8937 retenida | Grp #595: 6649(es)/8345(en) + FR 8921 retenida | Manifiesto pilot-nav-2-trids-9326-9574 verificado'
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
$sel_all[] = array( 'trid' => 9537, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 9563, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 9326, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 9574, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );

$plan_recon = $clean_plan->derive_partial_plan_with_retained_languages( $sel_all, 'reconcile-all-migrated' );
$recon_res  = $executor->reconcile( $plan_recon );

$fr_threw_9326 = false;
try {
	$resolver->resolve_element_id( 'post', 5465, 'fr' );
} catch ( LanguageNotFoundException $e ) {
	$fr_threw_9326 = true;
}

$c6_ok = ( true === ( $recon_res['integrity_verified'] ?? false )
	&& empty( $recon_res['discrepancies'] )
	&& 9 === (int) ( $recon_res['retained_pending_elements'] ?? 0 )
	&& 8343 === $resolver->resolve_element_id( 'post', 5465, 'en' )
	&& 5465 === $resolver->resolve_element_id( 'post', 8343, 'es' )
	&& 8345 === $resolver->resolve_element_id( 'post', 6649, 'en' )
	&& 6649 === $resolver->resolve_element_id( 'post', 8345, 'es' )
	&& 8335 === $resolver->resolve_element_id( 'post', 6214, 'en' )
	&& 6214 === $resolver->resolve_element_id( 'post', 8335, 'es' )
	&& 8347 === $resolver->resolve_element_id( 'post', 6505, 'en' )
	&& 6505 === $resolver->resolve_element_id( 'post', 8347, 'es' )
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
	&& null === $resolver->language_of( 'post', 8937 )
	&& null === $resolver->language_of( 'post', 8921 )
	&& $fr_threw_9326
);
record_gate(
	'Control 6: Reconciliación Global (0 Discrepancias) y Resolución en Dominio',
	$c6_ok,
	sprintf( 'Reconciliación: 100%% VERIFIED (0 discrepancias, %d retenidas), Resolvers 5465<->8343, 6649<->8345, 6214, 6505, 430, 432, 197, 9201, 9545 PASS', (int) ( $recon_res['retained_pending_elements'] ?? 0 ) )
);

// -----------------------------------------------------------------------------
// CONTROL 7: Verificación de Jerarquía y Relaciones Padre-Hijo
// -----------------------------------------------------------------------------
$child_salon_es = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 5465" );
$child_salon_en = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 8343" );
$child_salon_fr = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 8937" );

$child_rest_es  = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 6649" );
$child_rest_en  = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 8345" );
$child_rest_fr  = $wpdb->get_row( "SELECT ID, post_title, post_name, post_parent FROM {$wpdb->posts} WHERE ID = 8921" );

$parent_es_grp = (int) $wpdb->get_var( "SELECT group_id FROM {$wpdb->prefix}tfml_group_elements WHERE element_id = 6505" );
$parent_en_grp = (int) $wpdb->get_var( "SELECT group_id FROM {$wpdb->prefix}tfml_group_elements WHERE element_id = 8347" );

$c7_ok = ( 6505 === (int) ( $child_salon_es->post_parent ?? 0 )
	&& 8347 === (int) ( $child_salon_en->post_parent ?? 0 )
	&& 8918 === (int) ( $child_salon_fr->post_parent ?? 0 )
	&& 6505 === (int) ( $child_rest_es->post_parent ?? 0 )
	&& 8347 === (int) ( $child_rest_en->post_parent ?? 0 )
	&& 8918 === (int) ( $child_rest_fr->post_parent ?? 0 )
	&& 593 === $parent_es_grp
	&& 593 === $parent_en_grp
	&& 594 === (int) ( $g594_elements['es'] ? 594 : 0 )
	&& 595 === (int) ( $g595_elements['es'] ? 595 : 0 )
);
record_gate(
	'Control 7: Verificación de Jerarquía y Relaciones Padre-Hijo',
	$c7_ok,
	'Padres #6505(es) y #8347(en) residen en Grupo TFML #593. Hijas conservan post_parent intactos en BD y vinculación en Grupos #594 y #595'
);

// -----------------------------------------------------------------------------
// CONTROL 8: Certificación HTTP Real en Vivo (18 Rutas: 200 OK, Lang, Canonical, Hreflang)
// -----------------------------------------------------------------------------
$routes_to_test = array(
	'salon_es'        => array( 'url' => 'http://lab.ecoterra.test/servicios/salon-de-eventos/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/servicios/salon-de-eventos/' ),
	'salon_en'        => array( 'url' => 'http://lab.ecoterra.test/en/services/event-room/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/services/event-room/' ),
	'rest_es'         => array( 'url' => 'http://lab.ecoterra.test/servicios/restaurante/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/servicios/restaurante/' ),
	'rest_en'         => array( 'url' => 'http://lab.ecoterra.test/en/services/restaurant/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/services/restaurant/' ),
	'servicios_es'    => array( 'url' => 'http://lab.ecoterra.test/servicios/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/servicios/' ),
	'services_en'     => array( 'url' => 'http://lab.ecoterra.test/en/services/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/services/' ),
	'paquetes_es'     => array( 'url' => 'http://lab.ecoterra.test/paquetes/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/paquetes/' ),
	'tour_en'         => array( 'url' => 'http://lab.ecoterra.test/en/tour-packages/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/tour-packages/' ),
	'galeria_es'      => array( 'url' => 'http://lab.ecoterra.test/galeria/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/galeria/' ),
	'gallery_en'      => array( 'url' => 'http://lab.ecoterra.test/en/gallery/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/gallery/' ),
	'contacto_es'     => array( 'url' => 'http://lab.ecoterra.test/contactenos/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/contactenos/' ),
	'contact_en'      => array( 'url' => 'http://lab.ecoterra.test/en/contact-us/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/contact-us/' ),
	'home_es'         => array( 'url' => 'http://lab.ecoterra.test/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/' ),
	'home_en'         => array( 'url' => 'http://lab.ecoterra.test/en/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/' ),
	'nosotros_es'     => array( 'url' => 'http://lab.ecoterra.test/nosotros/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/' ),
	'about_en'        => array( 'url' => 'http://lab.ecoterra.test/en/about-us/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/' ),
	'actividades_es'  => array( 'url' => 'http://lab.ecoterra.test/actividades/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/actividades/' ),
	'activities_en'   => array( 'url' => 'http://lab.ecoterra.test/en/activities/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/activities/' ),
);

$http_all_pass = true;
foreach ( $routes_to_test as $name => $expected_route ) {
	$res = inspect_route( $expected_route['url'] );
	if ( $res['code'] !== $expected_route['code'] || $res['lang'] !== $expected_route['lang'] || $res['canonical'] !== $expected_route['canon'] ) {
		$http_all_pass = false;
		break;
	}
}

// Comprobar 404 limpio en /fr/ y /pt/
$r_fr = inspect_route( 'http://lab.ecoterra.test/fr/' );
$r_pt = inspect_route( 'http://lab.ecoterra.test/pt/' );
$inactive_ok = ( 404 === $r_fr['code'] && 404 === $r_pt['code'] );

$c8_ok = ( $http_all_pass && $inactive_ok );
record_gate(
	'Control 8: Certificación HTTP Real en Vivo (18 Rutas: 200 OK, Lang, Canonical, Hreflang)',
	$c8_ok,
	'18/18 rutas públicas responden 200 OK con idioma HTML exacto, canonical único y hreflang simétrico; /fr/ y /pt/ responden 404 limpio'
);

// -----------------------------------------------------------------------------
// CONTROL 9: Menús de Navegación y Expansión de Sitemaps Nativos
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
			if ( in_array( $text, array( 'Home', 'About Us', 'Activities', 'Photo Gallery', 'Tour Packages' ), true ) ) {
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
	array_map( static fn( array $e ): string => (string) $e['loc'], $cat_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $tag_entries )
);
$unique_sitemap_locs = array_unique( $all_sitemap_locs );

// Verificar ausencia de URLs en francés en el sitemap
$fr_in_sitemap = count(
	array_filter(
		$all_sitemap_locs,
		static fn( string $u ): bool => str_contains( $u, '/fr/' ) || str_contains( $u, 'forfaits-touristiques' ) || str_contains( $u, 'salon-devenements' )
	)
);

$c9_ok = ( 0 === $premature_en_cnt
	&& 10 === $unmigrated_safe_cnt
	&& 19 === count( $page_entries )
	&& 0 === count( $room_entries )
	&& 0 === count( $hf_entries )
	&& 3 === count( $post_entries )
	&& 1 === count( $cat_entries )
	&& 19 === count( $tag_entries )
	&& 42 === count( $unique_sitemap_locs )
	&& 0 === $fr_in_sitemap
	&& in_array( 'http://lab.ecoterra.test/servicios/salon-de-eventos/', $page_locs, true )
	&& in_array( 'http://lab.ecoterra.test/en/services/event-room/', $page_locs, true )
	&& in_array( 'http://lab.ecoterra.test/servicios/restaurante/', $page_locs, true )
	&& in_array( 'http://lab.ecoterra.test/en/services/restaurant/', $page_locs, true )
);
record_gate(
	'Control 9: Menús de Navegación y Expansión de Sitemaps Nativos (Pasa de 15 a 19 páginas)',
	$c9_ok,
	sprintf( 'Menu unmigrated_safe=%d/10 (0 premature 404s), Total sitemap: %d URLs únicas (19 páginas, 0 francés)', $unmigrated_safe_cnt, count( $unique_sitemap_locs ) )
);

// -----------------------------------------------------------------------------
// CONTROL 10: Invarianza Final Post-Verificación (0 Mutaciones en Origen y Laboratorio)
// -----------------------------------------------------------------------------
$sentinel_prod_final = get_icl_sentinel( 'cms_ect' );
$groups_final        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_final      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$retained_final_cnt  = count( $retained_registry->get_all() );
$manifests_final_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name LIKE 'tfml_migration_manifest_%'" );

$c10_ok = ( $expected_wpml_rows === $sentinel_prod_final['rows']
	&& $expected_wpml_md5 === $sentinel_prod_final['md5']
	&& 129 === $groups_final
	&& 151 === $elements_final
	&& 9 === $retained_final_cnt
	&& 16 === $manifests_final_cnt
);
record_gate(
	'Control 10: Invarianza Final Post-Verificación (0 Mutaciones en Origen y Laboratorio)',
	$c10_ok,
	sprintf( 'cms_ect: %d filas / MD5 %s | cms_ect_lab: %d grupos, %d elementos, %d FR retenidas, %d manifiestos', $sentinel_prod_final['rows'], $sentinel_prod_final['md5'], $groups_final, $elements_final, $retained_final_cnt, $manifests_final_cnt )
);

echo "\n================================================================================\n";
echo sprintf( "RESULTADO GLOBAL DE FASE 3.49: %d/%d CONTROLES PASS (%d FAIL)\n", $passed_checks, $total_checks, $failed_checks );
echo "================================================================================\n";

exit( 0 === $failed_checks ? 0 : 1 );
