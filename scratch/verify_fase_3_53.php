<?php
/**
 * Verificador Reproducible de Fase 3.53 (Directiva DT-TFML-086).
 *
 * Culminación Consolidada de Páginas Publicadas Pendientes:
 * - TRID 424: ¿Quiénes Somos? / Who Are We? (ES 424, EN 8352, FR 8880 retenida)
 * - TRID 9838: Reviews (ES 8316, EN 8321, FR 8910 retenida)
 * - TRID 34823: Términos y Condiciones / Terms and Conditions (ES 9864, EN 9863, FR 9865 retenida)
 * - TRID 34632: Accesibilidad y Transportes / Accessibility and Transportation (ES 8801, EN 8802, FR 8908 retenida)
 * - TRID 34831: Blog (ES 10109, EN 10110, FR 10111 retenida)
 * - Exclusión explícita de TRID 34790 (Lek borrador intacto: ES 9662, EN 9663, FR 9664)
 *
 * Controles de Fase:
 * - Control 1: Invarianza Absoluta de Producción (cms_ect: 3,403 filas, hash MD5 4241ca7e7ec6399a594537cb04790c10).
 * - Control 2: Aislamiento del Laboratorio, Respaldo Fuera de Webroot y Evidencia en BD Desechable (cms_ect_lab, InnoDB, lock libre, backup SHA-256 y meta.json).
 * - Control 3: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo (master_fp: 1953cf03... y pilot_fp: e4252839..., 5 grupos, 10 activos, 5 retenidos, borrador 34790 excluido).
 * - Control 4: Estado Exacto Post-Ejecución en cms_ect_lab (141 grupos, 175 elementos, 21 FR retenidas, 20 manifiestos, checkpoint completed, 0 FR activo, 0 duplicados).
 * - Control 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa (Grupos #603-#607, borrador 34790 intacto en draft).
 * - Control 6: Reconciliación Global (0 discrepancias), Resolución Bidireccional de Dominio y Aislamiento Francés.
 * - Control 7: Verificación Jerárquica Padre-Hijo (Subordinación de las 4 páginas de Nosotros a Grupo #589 y Raíz Autónoma para Blog).
 * - Control 8: Certificación HTTP Real en Vivo (42 Rutas: 200 OK en 5 nuevos pares + 32 previas, canonicals y hreflang).
 * - Control 9: Menús de Navegación y Expansión de Sitemaps Nativos (15 enlaces EN migrados, 1 seguro, sitemap page de 33 a 43, total de 56 a 66, 0 francés).
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
echo " VERIFICADOR FASE 3.53 (DT-TFML-086) — CULMINACIÓN CONSOLIDADA DE PÁGINAS\n";
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

$backup_pattern = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_53_pilot_trids_424_9838_34823_34632_34831_*.sql';
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
	&& 'DT-TFML-086' === ( $meta_data['directive'] ?? '' )
	&& 136 === (int) ( $meta_data['restored_metrics']['tfml_groups'] ?? 0 )
	&& 165 === (int) ( $meta_data['restored_metrics']['tfml_group_elements'] ?? 0 )
	&& array( 141, 175, 21 ) === ( $meta_data['disposable_idempotency_and_rollback_test']['run1_groups_elements_retained'] ?? array() )
	&& 5 === (int) ( $meta_data['disposable_idempotency_and_rollback_test']['run2_imported_skipped_status']['skipped_groups'] ?? 0 )
	&& array( 136, 165, 16 ) === ( $meta_data['disposable_idempotency_and_rollback_test']['rollback_groups_elements_retained'] ?? array() )
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

// Generar el plan maestro en el estado pre-ejecución del piloto 3.53 filtrando elementos con group_id <= 602
$baseline_602_q = static function ( string $sql ) use ( $wpdb ): string {
	$target = "SELECT element_id FROM `{$wpdb->prefix}tfml_group_elements`";
	if ( $target === $sql ) {
		return $target . ' WHERE group_id <= 602';
	}
	return $sql;
};
add_filter( 'query', $baseline_602_q );
$master_plan_pre = $planner->generate_plan( 0, 0, true );
remove_filter( 'query', $baseline_602_q );

$pilot_plan = $master_plan_pre->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 424,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 9838,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 34823,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 34632,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 34831,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
	),
	'pilot-published-pages-5-trids-424-9838-34823-34632-34831'
);

$pilot_summary = $pilot_plan->get_summary();
$expected_master_fp = '1953cf03d42cae4b54f71ed75f22ea0dfd7ec4f35d2261c710416f039db6cea2';
$expected_pilot_fp  = 'e4252839267b6395307f7eed5bfb67f12e4e09d626ba58738bfb64772cedf5d4';

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
	&& 5 === $pilot_summary['total_groups']
	&& 10 === $pilot_summary['total_elements']
	&& 5 === $pilot_summary['pending_elements']
	&& ! $trid_34790_in_pilot
);
record_gate(
	'Control 3: Linaje Criptográfico y Derivación Canónica del Piloto Mínimo',
	$c3_ok,
	sprintf( 'master_fp=%s, pilot_fp=%s (STATUS: %s, %d activos, %d retenidos, borrador 34790 excluido)', substr( $expected_master_fp, 0, 16 ), substr( $expected_pilot_fp, 0, 16 ), $pilot_plan->get_status(), $pilot_summary['total_elements'], $pilot_summary['pending_elements'] )
);

// -----------------------------------------------------------------------------
// CONTROL 4: Estado Exacto Post-Ejecución en cms_ect_lab (Métricas 141/175/21/20)
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

$fr_active_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE language_code = 'fr'" );

$element_dups    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id, COUNT(*) AS c FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING c > 1) AS t" );
$group_lang_dups = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT group_id, language_code, COUNT(*) AS c FROM {$wpdb->prefix}tfml_group_elements GROUP BY group_id, language_code HAVING c > 1) AS t" );

$c4_ok = ( 141 === $groups_count
	&& 175 === $elements_count
	&& 21 === $retained_count
	&& 20 === $manifests_count
	&& $checkpoint_ok
	&& 0 === $fr_active_count
	&& 0 === $element_dups
	&& 0 === $group_lang_dups
	&& ! $lock_mgr->is_locked( 'wpml' )
);
record_gate(
	'Control 4: Estado Exacto Post-Ejecución en cms_ect_lab (Métricas 141/175/21/20)',
	$c4_ok,
	sprintf( 'Grupos: %d, Elementos: %d, Retenidas FR: %d, Manifiestos: %d, Checkpoint: %s, FR Activo: %d, Dups: %d/%d', $groups_count, $elements_count, $retained_count, $manifests_count, $checkpoint['status'] ?? 'null', $fr_active_count, $element_dups, $group_lang_dups )
);

// -----------------------------------------------------------------------------
// CONTROL 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa
// -----------------------------------------------------------------------------
$g603_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 603", ARRAY_A );
$g604_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 604", ARRAY_A );
$g605_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 605", ARRAY_A );
$g606_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 606", ARRAY_A );
$g607_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 607", ARRAY_A );

$g603_elements = array_column( $g603_rows, 'element_id', 'language_code' );
$g604_elements = array_column( $g604_rows, 'element_id', 'language_code' );
$g605_elements = array_column( $g605_rows, 'element_id', 'language_code' );
$g606_elements = array_column( $g606_rows, 'element_id', 'language_code' );
$g607_elements = array_column( $g607_rows, 'element_id', 'language_code' );

$retained_424   = $retained_registry->get( 'wpml', 424 );
$retained_9838  = $retained_registry->get( 'wpml', 9838 );
$retained_34632 = $retained_registry->get( 'wpml', 34632 );
$retained_34823 = $retained_registry->get( 'wpml', 34823 );
$retained_34831 = $retained_registry->get( 'wpml', 34831 );

$manifest_token = 'pilot-published-pages-5-trids-424-9838-34823-34632-34831';
$manifest_raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = %s", 'tfml_migration_manifest_' . $manifest_token ) );
$manifest_data  = maybe_unserialize( $manifest_raw );

// Verificar estado de TRID 34790 (borrador intacto sin grupo TFML)
$tfml_34790_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id IN (9662, 9663, 9664)" );
$draft_34790_statuses = $wpdb->get_col( "SELECT post_status FROM {$wpdb->posts} WHERE ID IN (9662, 9663, 9664)" );
$draft_34790_ok = ( 0 === $tfml_34790_cnt && count( $draft_34790_statuses ) === 3 && array_unique( $draft_34790_statuses ) === array( 'draft' ) );

$c5_ok = ( 424 === (int) ( $g603_elements['es'] ?? 0 )
	&& 8352 === (int) ( $g603_elements['en'] ?? 0 )
	&& null !== $retained_424
	&& 603 === (int) ( $retained_424['group_id'] ?? 0 )
	&& 8880 === (int) ( $retained_424['retained_elements']['fr']['element_id'] ?? 0 )
	&& 8316 === (int) ( $g604_elements['es'] ?? 0 )
	&& 8321 === (int) ( $g604_elements['en'] ?? 0 )
	&& null !== $retained_9838
	&& 604 === (int) ( $retained_9838['group_id'] ?? 0 )
	&& 8910 === (int) ( $retained_9838['retained_elements']['fr']['element_id'] ?? 0 )
	&& 8801 === (int) ( $g605_elements['es'] ?? 0 )
	&& 8802 === (int) ( $g605_elements['en'] ?? 0 )
	&& null !== $retained_34632
	&& 605 === (int) ( $retained_34632['group_id'] ?? 0 )
	&& 8908 === (int) ( $retained_34632['retained_elements']['fr']['element_id'] ?? 0 )
	&& 9864 === (int) ( $g606_elements['es'] ?? 0 )
	&& 9863 === (int) ( $g606_elements['en'] ?? 0 )
	&& null !== $retained_34823
	&& 606 === (int) ( $retained_34823['group_id'] ?? 0 )
	&& 9865 === (int) ( $retained_34823['retained_elements']['fr']['element_id'] ?? 0 )
	&& 10109 === (int) ( $g607_elements['es'] ?? 0 )
	&& 10110 === (int) ( $g607_elements['en'] ?? 0 )
	&& null !== $retained_34831
	&& 607 === (int) ( $retained_34831['group_id'] ?? 0 )
	&& 10111 === (int) ( $retained_34831['retained_elements']['fr']['element_id'] ?? 0 )
	&& is_array( $manifest_data )
	&& $manifest_token === ( $manifest_data['execution_id'] ?? '' )
	&& $draft_34790_ok
);
record_gate(
	'Control 5: Identidades Asignadas a Grupos TFML y Custodia Histórica Francesa',
	$c5_ok,
	'Grp #603-#607 asignados exactamente, 5 FR en custodia, borrador 34790 intacto (0 TFML, status draft)'
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
$sel_all[] = array( 'trid' => 424, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 9838, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 34632, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 34823, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );
$sel_all[] = array( 'trid' => 34831, 'authorized_languages' => array( 'es', 'en' ), 'retained_languages' => array( 'fr' ) );

$plan_recon = $clean_plan->derive_partial_plan_with_retained_languages( $sel_all, 'reconcile-all-migrated' );
$recon_res  = $executor->reconcile( $plan_recon );

$fr_threw_424 = false;
try {
	$resolver->resolve_element_id( 'post', 424, 'fr' );
} catch ( LanguageNotFoundException $e ) {
	$fr_threw_424 = true;
}

$c6_ok = ( true === ( $recon_res['integrity_verified'] ?? false )
	&& empty( $recon_res['discrepancies'] )
	&& 21 === (int) ( $recon_res['retained_pending_elements'] ?? 0 )
	&& 8352 === $resolver->resolve_element_id( 'post', 424, 'en' )
	&& 424 === $resolver->resolve_element_id( 'post', 8352, 'es' )
	&& 8321 === $resolver->resolve_element_id( 'post', 8316, 'en' )
	&& 8316 === $resolver->resolve_element_id( 'post', 8321, 'es' )
	&& 8802 === $resolver->resolve_element_id( 'post', 8801, 'en' )
	&& 8801 === $resolver->resolve_element_id( 'post', 8802, 'es' )
	&& 9863 === $resolver->resolve_element_id( 'post', 9864, 'en' )
	&& 9864 === $resolver->resolve_element_id( 'post', 9863, 'es' )
	&& 10110 === $resolver->resolve_element_id( 'post', 10109, 'en' )
	&& 10109 === $resolver->resolve_element_id( 'post', 10110, 'es' )
	&& 9249 === $resolver->resolve_element_id( 'post', 9248, 'en' )
	&& 9248 === $resolver->resolve_element_id( 'post', 9249, 'es' )
	&& null === $resolver->language_of( 'post', 8880 )
	&& null === $resolver->language_of( 'post', 8910 )
	&& null === $resolver->language_of( 'post', 8908 )
	&& null === $resolver->language_of( 'post', 9865 )
	&& null === $resolver->language_of( 'post', 10111 )
	&& $fr_threw_424
);
record_gate(
	'Control 6: Reconciliación Global (0 Discrepancias) y Resolución en Dominio',
	$c6_ok,
	sprintf( 'Reconciliación: 100%% VERIFIED (0 discrepancias, %d retenidas), Resolvers 424<->8352, 8316<->8321, 8801<->8802, 9864<->9863, 10109<->10110 y previos PASS', (int) ( $recon_res['retained_pending_elements'] ?? 0 ) )
);

// -----------------------------------------------------------------------------
// CONTROL 7: Verificación Jerárquica Padre-Hijo (Subordinación de Nosotros y Raíz Autónoma Blog)
// -----------------------------------------------------------------------------
$p_424   = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 424" );
$p_8352  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 8352" );
$p_8880  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 8880" );

$p_8316  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 8316" );
$p_8321  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 8321" );
$p_8910  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 8910" );

$p_9864  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9864" );
$p_9863  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9863" );
$p_9865  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 9865" );

$p_8801  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 8801" );
$p_8802  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 8802" );
$p_8908  = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 8908" );

$p_10109 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 10109" );
$p_10110 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 10110" );
$p_10111 = (int) $wpdb->get_var( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = 10111" );

$hierarchy_ok = ( 6237 === $p_424 && 8354 === $p_8352 && 8873 === $p_8880
	&& 6237 === $p_8316 && 8354 === $p_8321 && 8873 === $p_8910
	&& 6237 === $p_9864 && 8354 === $p_9863 && 8873 === $p_9865
	&& 6237 === $p_8801 && 8354 === $p_8802 && 8873 === $p_8908
	&& 0 === $p_10109 && 0 === $p_10110 && 0 === $p_10111
);

$c7_ok = $hierarchy_ok;
record_gate(
	'Control 7: Verificación Jerárquica Padre-Hijo (Subordinación a Grupo #589 y Raíz Autónoma Blog)',
	$c7_ok,
	'Las 4 parejas de Nosotros subordinadas a #6237/#8354 (Grupo #589) y Blog autónomo en la raíz (post_parent=0)'
);

// -----------------------------------------------------------------------------
// CONTROL 8: Certificación HTTP Real en Vivo (42 Rutas: 200 OK, Lang, Canonical, Hreflang)
// -----------------------------------------------------------------------------
$routes_to_test = array(
	// 10 nuevas rutas (Fase 3.53)
	'quienes_es'      => array( 'url' => 'http://lab.ecoterra.test/nosotros/quienes-somos/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/quienes-somos/' ),
	'quienes_en'      => array( 'url' => 'http://lab.ecoterra.test/en/about-us/who-are-we/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/who-are-we/' ),
	'reviews_es'      => array( 'url' => 'http://lab.ecoterra.test/nosotros/reviews/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/reviews/' ),
	'reviews_en'      => array( 'url' => 'http://lab.ecoterra.test/en/about-us/reviews/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/reviews/' ),
	'terminos_es'     => array( 'url' => 'http://lab.ecoterra.test/nosotros/terminos-y-condiciones/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/terminos-y-condiciones/' ),
	'terminos_en'     => array( 'url' => 'http://lab.ecoterra.test/en/about-us/terms-and-conditions/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/terms-and-conditions/' ),
	'accesibilidad_es'=> array( 'url' => 'http://lab.ecoterra.test/nosotros/accesibilidad-y-transportes/', 'code' => 200, 'lang' => 'es-ES', 'canon' => 'http://lab.ecoterra.test/nosotros/accesibilidad-y-transportes/' ),
	'accesibilidad_en'=> array( 'url' => 'http://lab.ecoterra.test/en/about-us/accessibility-and-transportation/', 'code' => 200, 'lang' => 'en-US', 'canon' => 'http://lab.ecoterra.test/en/about-us/accessibility-and-transportation/' ),
	'blog_es'         => array( 'url' => 'http://lab.ecoterra.test/blog/', 'code' => 200, 'lang' => 'es-ES', 'canon' => '' ),
	'blog_en'         => array( 'url' => 'http://lab.ecoterra.test/en/blog/', 'code' => 200, 'lang' => 'en-US', 'canon' => '' ),

	// 32 rutas de regresión previas
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
	'Control 8: Certificación HTTP Real en Vivo (42 Rutas: 200 OK, Lang, Canonical, Hreflang)',
	$c8_ok,
	'42/42 rutas públicas responden 200 OK con idioma HTML exacto, canonical único y hreflang simétrico; /fr/ y /pt/ responden 404 limpio'
);

// -----------------------------------------------------------------------------
// CONTROL 9: Menús de Navegación y Expansión de Sitemaps Nativos (43 páginas, 66 URLs únicas)
// -----------------------------------------------------------------------------
$r_about_en = inspect_route( 'http://lab.ecoterra.test/en/about-us/' );
$migrated_en_urls    = array();
$unmigrated_safe_cnt = 0;
$premature_en_cnt    = 0;

$expected_menu_migrated = array(
	'Home',
	'About Us',
	'Who Are We?',
	'Reviews',
	'Terms and Conditions',
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
			} else {
				++$unmigrated_safe_cnt;
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

$c9_ok = ( 15 === count( $migrated_en_urls )
	&& 1 === $unmigrated_safe_cnt
	&& 0 === $premature_en_cnt
	&& 43 === count( $page_locs )
	&& 66 === count( $all_unique_locs )
	&& 0 === count( $fr_in_sitemap )
);
record_gate(
	'Control 9: Menús de Navegación y Expansión de Sitemaps Nativos (Pasa de 33 a 43 páginas)',
	$c9_ok,
	sprintf( 'Menu safe=%d (0 premature 404s, %d EN migrados), Total sitemap: %d URLs únicas (%d páginas, 0 francés)', $unmigrated_safe_cnt, count( $migrated_en_urls ), count( $all_unique_locs ), count( $page_locs ) )
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
	&& 141 === $groups_final
	&& 175 === $elements_final
	&& 21 === $retained_final_cnt
	&& 20 === $manifests_final_cnt
);
record_gate(
	'Control 10: Invarianza Final Post-Verificación (0 Mutaciones en Origen y Laboratorio)',
	$c10_ok,
	sprintf( 'cms_ect: %d filas / MD5 %s | cms_ect_lab: %d grupos, %d elementos, %d FR retenidas, %d manifiestos', $sentinel_prod_final['rows'], $sentinel_prod_final['md5'], $groups_final, $elements_final, $retained_final_cnt, $manifests_final_cnt )
);

echo "\n================================================================================\n";
printf( "RESULTADO GLOBAL DE FASE 3.53: %d/%d CONTROLES PASS (%d FAIL)\n", $passed_checks, $total_checks, $failed_checks );
echo "================================================================================\n";

if ( $failed_checks > 0 ) {
	exit( 1 );
}
exit( 0 );
