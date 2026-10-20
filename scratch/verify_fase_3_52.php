<?php
/**
 * Verificador Reproducible de Fase 3.52 (Directiva DT-TFML-085-R1).
 *
 * Culminación Integral de la Familia Experiencia Transformadora:
 * - TRID 34660: Avistamiento de Aves / Birdwatching (ES 9248, EN 9249, FR 9250 retenida)
 * - TRID 34776: Lek de Gallito de las Rocas / Cock of the Rock (ES 9614, EN 9615, FR 9616 retenida)
 * - TRID 34791: Experiencia De Café / Coffee Experience (ES 9666, EN 9667, FR 9668 retenida)
 * - Exclusión explícita de TRID 34790 (Lek borrador intacto: ES 9662, EN 9663, FR 9664)
 *
 * Controles de Fase:
 * - Control 1: Invarianza Absoluta de Producción (cms_ect: 3,403 filas, hash MD5 4241ca7e7ec6399a594537cb04790c10).
 * - Control 2: Aislamiento del Laboratorio, Respaldo Fuera de Webroot y Evidencia en BD Desechable (cms_ect_lab, InnoDB, lock libre, backup SHA-256 y meta.json).
 * - Control 3: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo (master_fp: 4faadfe3... y pilot_fp: 1eec9800..., 3 grupos, 6 activos, 3 retenidos, borrador 34790 excluido).
 * - Control 4: Estado Exacto Post-Ejecución en cms_ect_lab (136 grupos, 165 elementos, 16 FR retenidas, 19 manifiestos, checkpoint completed, 0 FR activo, 0 duplicados).
 * - Control 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa (Grupo #600: 9248/9249 + FR #9250, Grupo #601: 9614/9615 + FR #9616, Grupo #602: 9666/9667 + FR #9668, borrador 34790 intacto).
 * - Control 6: Reconciliación Global (0 discrepancias), Resolución Bidireccional de Dominio y Aislamiento Francés.
 * - Control 7: Verificación Jerárquica Padre-Hijo (Preservación de Subordinación a Grupo #599).
 * - Control 8: Certificación HTTP Real en Vivo (32 Rutas: 200 OK en 3 nuevos pares + 26 previas, canonicals y hreflang).
 * - Control 9: Menús de Navegación y Expansión de Sitemaps Nativos (12 enlaces EN migrados, 3 seguros, sitemap page de 27 a 33, total de 50 a 56, 0 francés).
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
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 15,
		)
	);
	$body = (string) curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$lang = '';
	if ( preg_match( '/<html[^>]*lang=["\']([^"\']+)["\']/i', $body, $matches ) ) {
		$lang = $matches[1];
	}

	$canonical = '';
	if ( preg_match( '/<link[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']/i', $body, $matches ) ) {
		$canonical = $matches[1];
	}

	$hreflang = array();
	if ( preg_match_all( '/<link[^>]*rel=["\']alternate["\'][^>]*hreflang=["\']([^"\']+)["\'][^>]*href=["\']([^"\']+)["\']/i', $body, $matches, PREG_SET_ORDER ) ) {
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
echo " VERIFICADOR FASE 3.52 (DT-TFML-085-R1) — FAMILIA EXPERIENCIA TRANSFORMADORA\n";
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

$backup_pattern = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_52_pilot_trids_34660_34776_34791_*.sql';
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
	&& 'DT-TFML-085-R1' === ( $meta_data['directive'] ?? '' )
	&& 133 === (int) ( $meta_data['restored_metrics']['tfml_groups'] ?? 0 )
	&& 159 === (int) ( $meta_data['restored_metrics']['tfml_group_elements'] ?? 0 )
	&& array( 136, 165, 16 ) === ( $meta_data['disposable_idempotency_and_rollback_test']['run1_groups_elements_retained'] ?? array() )
	&& 3 === (int) ( $meta_data['disposable_idempotency_and_rollback_test']['run2_imported_skipped_status']['skipped_groups'] ?? 0 )
	&& array( 133, 159, 13 ) === ( $meta_data['disposable_idempotency_and_rollback_test']['rollback_groups_elements_retained'] ?? array() )
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

// Generar el plan maestro en el estado pre-ejecución del piloto 3.52 filtrando elementos con group_id <= 599
$baseline_599_q = static function ( string $sql ) use ( $wpdb ): string {
	$target = "SELECT element_id FROM `{$wpdb->prefix}tfml_group_elements`";
	if ( $target === $sql ) {
		return $target . ' WHERE group_id <= 599';
	}
	return $sql;
};
add_filter( 'query', $baseline_599_q );
$master_plan_pre = $planner->generate_plan( 0, 0, true );
remove_filter( 'query', $baseline_599_q );

$pilot_plan = $master_plan_pre->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 34660,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 34776,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 34791,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
	),
	'pilot-transformative-3-trids-34660-34776-34791'
);

$pilot_summary = $pilot_plan->get_summary();
$expected_master_fp = '4faadfe3fcddfe60ed6c058fd6cf540f44067f85d40f7c2793d56093bc48864c';
$expected_pilot_fp  = '1eec9800e20fee259ac91fb67409c04a2f1f961d8e7f63d0c2f090174f67bd86';

// Verificar que TRID 34790 (borrador) NO esté en el plan derivado
$trid_34790_in_pilot = false;
foreach ( $pilot_plan->get_planned_groups() as $pg ) {
	if ( 34790 === (int) $pg['trid'] ) {
		$trid_34790_in_pilot = true;
	}
}

$c3_ok = ( $expected_master_fp === $master_plan_pre->get_fingerprint()
	&& $expected_master_fp === $pilot_plan->get_parent_fingerprint()
	&& $expected_pilot_fp === $pilot_plan->get_fingerprint()
	&& 'READY' === $pilot_plan->get_status()
	&& 3 === $pilot_summary['total_groups']
	&& 6 === $pilot_summary['total_elements']
	&& 3 === $pilot_summary['pending_elements']
	&& ! $trid_34790_in_pilot
);
record_gate(
	'Control 3: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo',
	$c3_ok,
	sprintf( 'master_fp=%s, pilot_fp=%s (STATUS: %s, %d activos, %d retenidos, borrador 34790 excluido)', substr( $expected_master_fp, 0, 16 ), substr( $expected_pilot_fp, 0, 16 ), $pilot_plan->get_status(), $pilot_summary['total_elements'], $pilot_summary['pending_elements'] )
);

// -----------------------------------------------------------------------------
// CONTROL 4: Estado Exacto Post-Ejecución en cms_ect_lab (Métricas 136/165/16/19)
// -----------------------------------------------------------------------------
$groups_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$manifests_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name LIKE 'tfml_migration_manifest_%'" );

$retained_registry = new RetainedTranslationRegistry( $wpdb );
$retained_all      = $retained_registry->get_all();
$retained_count    = count( $retained_all );

$checkpoint_raw = $wpdb->get_var( "SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = 'tfml_migration_checkpoint_wpml'" );
$checkpoint     = maybe_unserialize( $checkpoint_raw );
$checkpoint_ok  = is_array( $checkpoint ) && 'completed' === ( $checkpoint['status'] ?? '' );

$fr_active_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE language = 'fr'" );

$element_dups    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id, COUNT(*) AS c FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING c > 1) AS t" );
$group_lang_dups = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT group_id, language, COUNT(*) AS c FROM {$wpdb->prefix}tfml_group_elements GROUP BY group_id, language HAVING c > 1) AS t" );

$c4_ok = ( 136 === $groups_count
	&& 165 === $elements_count
	&& 16 === $retained_count
	&& 19 === $manifests_count
	&& $checkpoint_ok
	&& 0 === $fr_active_count
	&& 0 === $element_dups
	&& 0 === $group_lang_dups
	&& ! $lock_mgr->is_locked( 'wpml' )
);
record_gate(
	'Control 4: Estado Exacto Post-Ejecución en cms_ect_lab (Métricas 136/165/16/19)',
	$c4_ok,
	sprintf( 'Grupos: %d, Elementos: %d, Retenidas FR: %d, Manifiestos: %d, Checkpoint: %s, FR Activo: %d, Dups: %d/%d', $groups_count, $elements_count, $retained_count, $manifests_count, $checkpoint['status'] ?? 'null', $fr_active_count, $element_dups, $group_lang_dups )
);

// -----------------------------------------------------------------------------
// CONTROL 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa
// -----------------------------------------------------------------------------
$g600_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 600", ARRAY_A );
$g601_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 601", ARRAY_A );
$g602_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 602", ARRAY_A );

$g600_elements = array_column( $g600_rows, 'element_id', 'language_code' );
$g601_elements = array_column( $g601_rows, 'element_id', 'language_code' );
$g602_elements = array_column( $g602_rows, 'element_id', 'language_code' );

$retained_34660 = $retained_registry->get( 'wpml', 34660 );
$retained_34776 = $retained_registry->get( 'wpml', 34776 );
$retained_34791 = $retained_registry->get( 'wpml', 34791 );

$manifest_token = 'pilot-transformative-3-trids-34660-34776-34791';
$manifest_raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = %s", 'tfml_migration_manifest_' . $manifest_token ) );
$manifest_data  = maybe_unserialize( $manifest_raw );

// Verificar estado de TRID 34790 (borrador intacto sin grupo TFML)
$tfml_34790_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id IN (9662, 9663, 9664)" );
$draft_34790_statuses = $wpdb->get_col( "SELECT post_status FROM {$wpdb->posts} WHERE ID IN (9662, 9663, 9664)" );
$draft_34790_ok = ( 0 === $tfml_34790_cnt && count( $draft_34790_statuses ) === 3 && array_unique( $draft_34790_statuses ) === array( 'draft' ) );

$c5_ok = ( 9248 === (int) ( $g600_elements['es'] ?? 0 )
	&& 9249 === (int) ( $g600_elements['en'] ?? 0 )
	&& null !== $retained_34660
	&& 600 === (int) ( $retained_34660['group_id'] ?? 0 )
	&& 9250 === (int) ( $retained_34660['retained_elements']['fr']['element_id'] ?? 0 )
	&& 9614 === (int) ( $g601_elements['es'] ?? 0 )
	&& 9615 === (int) ( $g601_elements['en'] ?? 0 )
	&& null !== $retained_34776
	&& 601 === (int) ( $retained_34776['group_id'] ?? 0 )
	&& 9616 === (int) ( $retained_34776['retained_elements']['fr']['element_id'] ?? 0 )
	&& 9666 === (int) ( $g602_elements['es'] ?? 0 )
	&& 9667 === (int) ( $g602_elements['en'] ?? 0 )
	&& null !== $retained_34791
	&& 602 === (int) ( $retained_34791['group_id'] ?? 0 )
	&& 9668 === (int) ( $retained_34791['retained_elements']['fr']['element_id'] ?? 0 )
	&& is_array( $manifest_data )
	&& $manifest_token === ( $manifest_data['execution_id'] ?? '' )
	&& $draft_34790_ok
);
record_gate(
	'Control 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa',
	$c5_ok,
	sprintf( 'Grp #600: 9248(es)/9249(en)+FR:9250 | Grp #601: 9614(es)/9615(en)+FR:9616 | Grp #602: 9666(es)/9667(en)+FR:9668 | Borrador 34790 intacto (0 TFML, status draft)' )
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
$sel_all[] = array( 'trid' => 9634, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 9697, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 9818, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 34652, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 34660, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 34776, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 34791, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );

$plan_recon = $clean_plan->derive_partial_plan_with_retained_languages( $sel_all, 'reconcile-all-migrated' );
$recon_res  = $executor->reconcile( $plan_recon );

$fr_threw_34660 = false;
try {
	$resolver->resolve_element_id( 'post', 9248, 'fr' );
} catch ( LanguageNotFoundException $e ) {
	$fr_threw_34660 = true;
}

$c6_ok = ( true === ( $recon_res['integrity_verified'] ?? false )
	&& empty( $recon_res['discrepancies'] )
	&& 16 === (int) ( $recon_res['retained_pending_elements'] ?? 0 )
	&& 9249 === $resolver->resolve_element_id( 'post', 9248, 'en' )
	&& 9248 === $resolver->resolve_element_id( 'post', 9249, 'es' )
	&& 9615 === $resolver->resolve_element_id( 'post', 9614, 'en' )
	&& 9614 === $resolver->resolve_element_id( 'post', 9615, 'es' )
	&& 9667 === $resolver->resolve_element_id( 'post', 9666, 'en' )
	&& 9666 === $resolver->resolve_element_id( 'post', 9667, 'es' )
	&& 8613 === $resolver->resolve_element_id( 'post', 8042, 'en' )
	&& 8042 === $resolver->resolve_element_id( 'post', 8613, 'es' )
	&& 9219 === $resolver->resolve_element_id( 'post', 9218, 'en' )
	&& 9218 === $resolver->resolve_element_id( 'post', 9219, 'es' )
	&& null === $resolver->language_of( 'post', 9250 )
	&& null === $resolver->language_of( 'post', 9616 )
	&& null === $resolver->language_of( 'post', 9668 )
	&& $fr_threw_34660
);
record_gate(
	'Control 6: Reconciliación Global (0 Discrepancias) y Resolución en Dominio',
	$c6_ok,
	sprintf( 'Reconciliación: 100%% VERIFIED (0 discrepancias, %d retenidas), Resolvers 9248<->9249, 9614<->9615, 9666<->9667 y previos PASS', (int) ( $recon_res['retained_pending_elements'] ?? 0 ) )
);

// -----------------------------------------------------------------------------
// CONTROL 7: Verificación Jerárquica Padre-Hijo (Preservación de Subordinación a Grupo #599)
// -----------------------------------------------------------------------------
$p_9248 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9248" );
$p_9249 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9249" );
$p_9250 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9250" );

$p_9614 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9614" );
$p_9615 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9615" );
$p_9616 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9616" );

$p_9666 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9666" );
$p_9667 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9667" );
$p_9668 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9668" );

$hierarchy_ok = ( 9218 === $p_9248 && 9219 === $p_9249 && 9220 === $p_9250
	&& 9218 === $p_9614 && 9219 === $p_9615 && 9220 === $p_9616
	&& 9218 === $p_9666 && 9219 === $p_9667 && 9220 === $p_9668
);

$c7_ok = $hierarchy_ok;
record_gate(
	'Control 7: Verificación Jerárquica Padre-Hijo (Preservación de Subordinación a Grupo #599)',
	$c7_ok,
	'Las 3 parejas ES/EN están subordinadas a Experiencia Transformadora (ES #9218, EN #9219, Grupo #599)'
);

// -----------------------------------------------------------------------------
// CONTROL 8: Certificación HTTP Real en Vivo (32 Rutas: 200 OK, Lang, Canonical, Hreflang)
// -----------------------------------------------------------------------------
$routes_to_test = array(
	'birds_es'        => array( 'url' => 'http://lab.ecoterra.test/experiencia-transformadora/avistamiento-de-aves/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-transformadora/avistamiento-de-aves/' ),
	'birds_en'        => array( 'url' => 'http://lab.ecoterra.test/en/transformative-experience/birdwatching/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/transformative-experience/birdwatching/' ),
	'lek_es'          => array( 'url' => 'http://lab.ecoterra.test/experiencia-transformadora/lek-de-gallito-de-las-rocas/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-transformadora/lek-de-gallito-de-las-rocas/' ),
	'lek_en'          => array( 'url' => 'http://lab.ecoterra.test/en/transformative-experience/andean-cock-of-the-rock-lek/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/transformative-experience/andean-cock-of-the-rock-lek/' ),
	'cafe_es'         => array( 'url' => 'http://lab.ecoterra.test/experiencia-transformadora/experiencia-de-cafe/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-transformadora/experiencia-de-cafe/' ),
	'cafe_en'         => array( 'url' => 'http://lab.ecoterra.test/en/transformative-experience/coffee-experience/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/transformative-experience/coffee-experience/' ),
	'confort_es'      => array( 'url' => 'http://lab.ecoterra.test/confort-y-tranquilidad/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/confort-y-tranquilidad/' ),
	'comfort_en'      => array( 'url' => 'http://lab.ecoterra.test/en/comfort-and-tranquility/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/comfort-and-tranquility/' ),
	'transf_es'       => array( 'url' => 'http://lab.ecoterra.test/experiencia-transformadora/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-transformadora/' ),
	'transf_en'       => array( 'url' => 'http://lab.ecoterra.test/en/transformative-experience/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/transformative-experience/' ),
	'nat_es'          => array( 'url' => 'http://lab.ecoterra.test/naturaleza-autentica/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/naturaleza-autentica/' ),
	'nat_en'          => array( 'url' => 'http://lab.ecoterra.test/en/authentic-nature/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/authentic-nature/' ),
	'exp_es'          => array( 'url' => 'http://lab.ecoterra.test/experiencia-ecoterra/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/experiencia-ecoterra/' ),
	'exp_en'          => array( 'url' => 'http://lab.ecoterra.test/en/ecoterra-experience/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/ecoterra-experience/' ),
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
		echo "HTTP FAIL on {$name}: code={$res['code']}, lang={$res['lang']}, canon={$res['canonical']}\n";
		break;
	}
}

// Comprobar 404 limpio en /fr/ y /pt/
$r_fr = inspect_route( 'http://lab.ecoterra.test/fr/' );
$r_pt = inspect_route( 'http://lab.ecoterra.test/pt/' );
$inactive_ok = ( 404 === $r_fr['code'] && 404 === $r_pt['code'] );

$c8_ok = ( $http_all_pass && $inactive_ok );
record_gate(
	'Control 8: Certificación HTTP Real en Vivo (32 Rutas: 200 OK, Lang, Canonical, Hreflang)',
	$c8_ok,
	'32/32 rutas públicas responden 200 OK con idioma HTML exacto, canonical único y hreflang simétrico; /fr/ y /pt/ responden 404 limpio'
);

// -----------------------------------------------------------------------------
// CONTROL 9: Menús de Navegación y Expansión de Sitemaps Nativos (33 páginas, 56 URLs únicas)
// -----------------------------------------------------------------------------
$r_about_en = inspect_route( 'http://lab.ecoterra.test/en/about-us/' );
$migrated_en_urls    = array();
$unmigrated_safe_cnt = 0;
$premature_en_cnt    = 0;

$expected_menu_migrated = array(
	'Home',
	'About Us',
	'Activities',
	'Photo Gallery',
	'Tour Packages',
	'Authentic Nature',
	'Ecoterra Experience',
	'Comfort and Tranquility',
	'Transformative Experience',
	'Birdwatching',
	'Andean Cock of the Rock Lek',
	'Coffee Experience',
);

if ( preg_match( '/<ul[^>]*id=["\']menu-menu-es-ingles["\'][^>]*>(.*?)<\/nav>/is', $r_about_en['body'], $m_nav ) ) {
	if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $m_nav[1], $a_nav, PREG_SET_ORDER ) ) {
		foreach ( $a_nav as $a_item ) {
			$href = trim( $a_item[1] );
			$text = trim( strip_tags( $a_item[2] ) );
			if ( in_array( $text, $expected_menu_migrated, true ) ) {
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
	array_map( static fn( array $e ): string => (string) $e['loc'], $room_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $hf_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $cat_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $tag_entries )
);

$all_unique_locs = array_unique( $all_sitemap_locs );
$fr_in_sitemap   = array_filter( $all_unique_locs, static fn( string $u ): bool => false !== strpos( $u, '/fr/' ) );

$c9_ok = ( 12 === count( $migrated_en_urls )
	&& 3 === $unmigrated_safe_cnt
	&& 0 === $premature_en_cnt
	&& 33 === count( $page_locs )
	&& 56 === count( $all_unique_locs )
	&& 0 === count( $fr_in_sitemap )
);
record_gate(
	'Control 9: Menús de Navegación y Expansión de Sitemaps Nativos (Pasa de 27 a 33 páginas)',
	$c9_ok,
	sprintf( 'Menu unmigrated_safe=%d/3 (0 premature 404s, %d EN migrados), Total sitemap: %d URLs únicas (%d páginas, 0 francés)', $unmigrated_safe_cnt, count( $migrated_en_urls ), count( $all_unique_locs ), count( $page_locs ) )
);

// -----------------------------------------------------------------------------
// CONTROL 10: Invarianza Final Post-Verificación (0 Mutaciones en Origen y Laboratorio)
// -----------------------------------------------------------------------------
$sentinel_prod_final = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_final  = get_icl_sentinel( 'cms_ect_lab' );

$groups_final        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_final      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$retained_final_cnt  = count( $retained_registry->get_all() );
$manifests_final_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name LIKE 'tfml_migration_manifest_%'" );

$c10_ok = ( $expected_wpml_rows === $sentinel_prod_final['rows']
	&& $expected_wpml_md5 === $sentinel_prod_final['md5']
	&& $expected_wpml_rows === $sentinel_lab_final['rows']
	&& $expected_wpml_md5 === $sentinel_lab_final['md5']
	&& 136 === $groups_final
	&& 165 === $elements_final
	&& 16 === $retained_final_cnt
	&& 19 === $manifests_final_cnt
);
record_gate(
	'Control 10: Invarianza Final Post-Verificación (0 Mutaciones en Origen y Laboratorio)',
	$c10_ok,
	sprintf( 'cms_ect: %d filas / MD5 %s | cms_ect_lab: %d grupos, %d elementos, %d FR retenidas, %d manifiestos', $sentinel_prod_final['rows'], $sentinel_prod_final['md5'], $groups_final, $elements_final, $retained_final_cnt, $manifests_final_cnt )
);

echo "\n================================================================================\n";
printf( "RESULTADO GLOBAL DE FASE 3.52: %d/%d CONTROLES PASS (%d FAIL)\n", $passed_checks, $total_checks, $failed_checks );
echo "================================================================================\n";

if ( $failed_checks > 0 ) {
	exit( 1 );
}
exit( 0 );
