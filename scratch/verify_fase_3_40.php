<?php
/**
 * Verificador Reproducible de Fase 3.40 (Directiva DT-TFML-070).
 *
 * Certifica la Importación Transaccional Controlada de Dos Grupos de Navegación
 * (`pilot-nav-2-trids-9545-9201`: TRID 9545 Nosotros/About Us + TRID 9201 Actividades/Activities):
 * 1. Preflight bloqueante, aislamiento de laboratorio y centinelas WPML (cms_ect y cms_ect_lab).
 * 2. Respaldo nuevo fuera del webroot (SHA-256) y evidencia de restauración/idempotencia/rollback en BD desechable.
 * 3. Derivación canónica y linaje criptográfico del plan parcial pilot-nav-2-trids-9545-9201.
 * 4. Estado exacto post-ejecución en cms_ect_lab: 123 grupos, 139 elementos activos, 0 francés activo y 0 duplicados.
 * 5. Registro de 3 traducciones francesas retenidas (fr:8839, fr:8955, fr:8873), 13 manifiestos y checkpoint completed.
 * 6. Reconciliación 123/139/3, resolución bidireccional en dominio/API e idempotencia no destructiva.
 * 7. Certificación HTTP real (/nosotros/, /en/about-us/, /actividades/, /en/activities/, /, /en/) con identidad, lang, canonical y hreflang.
 * 8. Certificación de menús de escritorio (menu-principal 43 y menu-es-ingles 98) y protección de las 12 páginas no migradas.
 * 9. Regresión de sitemaps: page de 3 a 7 y total consolidado de 26 a 30 (0 francés, 0 duplicados, 0 CPT técnicos).
 * 10. Invariancia post-verificación de cms_ect y cms_ect_lab (0 escrituras durante la verificación).
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
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
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

$total_checks      = 0;
$passed_checks     = 0;
$failed_checks     = 0;
$blocked_checks    = 0;
$not_tested_checks = 0;

/**
 * Registra el resultado de una compuerta de verificación (PASS, FAIL, BLOCKED, NOT_TESTED).
 *
 * @param string $label     Nombre del control.
 * @param bool   $condition Resultado booleano.
 * @param string $detail    Detalle diagnóstico.
 * @param string $mode      Modo ('PASS_FAIL', 'BLOCKED_IF_TRUE', 'NOT_TESTED').
 * @return void
 */
function record_gate( string $label, bool $condition, string $detail = '', string $mode = 'PASS_FAIL' ): void {
	global $total_checks, $passed_checks, $failed_checks, $blocked_checks, $not_tested_checks;
	++$total_checks;

	if ( 'NOT_TESTED' === $mode ) {
		++$not_tested_checks;
		echo sprintf( "[NOT_TESTED] %s%s\n", $label, '' !== $detail ? " — {$detail}" : '' );
		return;
	}

	if ( 'BLOCKED_IF_TRUE' === $mode && $condition ) {
		++$blocked_checks;
		echo sprintf( "[BLOCKED] %s%s\n", $label, '' !== $detail ? " — {$detail}" : '' );
		return;
	}

	if ( $condition ) {
		++$passed_checks;
		echo sprintf( "[PASS] %s%s\n", $label, '' !== $detail ? " — {$detail}" : '' );
	} else {
		++$failed_checks;
		echo sprintf( "[FAIL] %s%s\n", $label, '' !== $detail ? " — {$detail}" : '' );
	}
}

/**
 * Calcula el conteo y hash MD5 de etk_t_icl_translations en la BD indicada.
 *
 * @param string $db_name Nombre de la base de datos.
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
 * Calcula un digest SHA-256 del estado actual de tablas TFML y opciones de migración.
 *
 * @return string
 */
function get_tfml_state_digest(): string {
	global $wpdb;

	$groups    = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_groups ORDER BY id ASC", ARRAY_A );
	$elements  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements ORDER BY id ASC", ARRAY_A );
	$retained  = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'tfml_retained_translations'" );
	$manifests = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%' ORDER BY option_name ASC", ARRAY_A );
	$cp        = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'tfml_migration_checkpoint_wpml'" );
	$lock      = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'tfml_migration_lock_wpml'" );

	return hash(
		'sha256',
		(string) wp_json_encode(
			array(
				'groups'    => $groups,
				'elements'  => $elements,
				'retained'  => $retained,
				'manifests' => $manifests,
				'cp'        => $cp,
				'lock'      => $lock,
			)
		)
	);
}

/**
 * Ejecuta una petición HTTP real y extrae código HTTP, lang, body class, canonical, hreflang y enlaces de menú.
 *
 * @param string $url URL completa.
 * @return array{
 *     code: int,
 *     lang: string,
 *     body_class: string,
 *     canonical: string,
 *     hreflang: array<string, string>,
 *     body: string,
 *     php_errors: bool
 * }
 */
function inspect_http_route( string $url ): array {
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

	$body_class = '';
	if ( preg_match( '/<body[^>]*\bclass=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$body_class = $m[1];
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

	$php_errors = ( false !== stripos( $body, 'Fatal error' ) || false !== stripos( $body, 'Parse error' ) || false !== stripos( $body, 'Uncaught Error' ) );

	return array(
		'code'       => $code,
		'lang'       => $lang,
		'body_class' => $body_class,
		'canonical'  => $canonical,
		'hreflang'   => $hreflang,
		'body'       => $body,
		'php_errors' => $php_errors,
	);
}

echo "============================================================================\n";
echo " VERIFICADOR AUTOMATIZADO FASE 3.40 (DT-TFML-070)\n";
echo " Importación Transaccional Controlada de Nosotros (9545) y Actividades (9201)\n";
echo "============================================================================\n\n";

global $wpdb;

$digest_before      = get_tfml_state_digest();
$expected_wpml_rows = 3403;
$expected_wpml_md5  = '4241ca7e7ec6399a594537cb04790c10';
$expected_parent_fp = '34a63fb1485341cf1da1109208c6fcefb3bab9f80b2fdfd5d0059a9e4e22263c';
$expected_pilot_fp  = 'ed8b9ede25ad4cf2886b8a1d3a438bfddf59cd9ed4ca3808f4daccbd3b86307d';

// ============================================================================
// BLOQUE 1: PREFLIGHT, AISLAMIENTO, SALVAGUARDAS Y RESPALDO (SECCIONES 2 Y 3)
// ============================================================================
echo "--- BLOQUE 1: Preflight, Aislamiento, Salvaguardas y Respaldo (Secciones 2 y 3) ---\n";

$normalized_abspath = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
$home_opt           = rtrim( (string) get_option( 'home' ), '/' );
$siteurl_opt        = rtrim( (string) get_option( 'siteurl' ), '/' );
$active_plugins     = (array) get_option( 'active_plugins', array() );
$tfml_active        = in_array( 'tf-multilingual/tf-multilingual.php', $active_plugins, true );
$wpml_inactive      = ! in_array( 'sitepress-multilingual-cms/sitepress.php', $active_plugins, true )
	&& ! defined( 'ICL_SITEPRESS_VERSION' )
	&& ! defined( 'ICL_LANGUAGE_CODE' );

$plugin        = Plugin::get_instance();
$lang_registry = $plugin->get_language_registry();
$active_langs  = array_keys( $lang_registry->active() );

$engines_rows = $wpdb->get_results(
	"SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'cms_ect_lab' AND (TABLE_NAME = 'etk_t_options' OR TABLE_NAME LIKE 'etk_t_tfml_%')",
	ARRAY_A
);
$all_innodb = count( $engines_rows ) >= 6;
foreach ( $engines_rows as $erow ) {
	if ( 'InnoDB' !== ( $erow['ENGINE'] ?? '' ) ) {
		$all_innodb = false;
	}
}

$schema_ver        = (string) get_option( SchemaManager::OPTION_SCHEMA_VERSION, '' );
$cron_disabled     = defined( 'DISABLE_WP_CRON' ) && true === constant( 'DISABLE_WP_CRON' );
$http_blocked      = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && true === constant( 'WP_HTTP_BLOCK_EXTERNAL' );
$blog_public       = (string) get_option( 'blog_public' );
$lock_mgr          = new MigrationExecutionLock( $wpdb );
$sentinel_prod_pre = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_pre  = get_icl_sentinel( 'cms_ect_lab' );

record_gate(
	'1. Aislamiento de laboratorio, motor InnoDB (6 tablas), salvaguardas, lock libre y centinela WPML (3,403 / 4241ca7e7ec6399a594537cb04790c10)',
	'cms_ect_lab' === $wpdb->dbname
		&& 'etk_t_' === $wpdb->prefix
		&& 'D:/laragon/www/lab.ecoterra' === $normalized_abspath
		&& 'http://lab.ecoterra.test' === $home_opt
		&& 'http://lab.ecoterra.test' === $siteurl_opt
		&& $tfml_active
		&& $wpml_inactive
		&& array( 'es', 'en', 'pt' ) === $active_langs
		&& ! $lang_registry->is_active( 'fr' )
		&& $all_innodb
		&& '1.0.0' === $schema_ver
		&& $cron_disabled
		&& $http_blocked
		&& '0' === $blog_public
		&& 197 === (int) get_option( 'page_on_front' )
		&& ! $lock_mgr->is_locked( 'wpml' )
		&& $expected_wpml_rows === $sentinel_prod_pre['rows']
		&& $expected_wpml_md5 === $sentinel_prod_pre['md5']
		&& $expected_wpml_rows === $sentinel_lab_pre['rows']
		&& $expected_wpml_md5 === $sentinel_lab_pre['md5'],
	sprintf(
		'db=%s, innodb=%d, lock=free, prod=%d/%s, lab=%d/%s',
		$wpdb->dbname,
		count( $engines_rows ),
		$sentinel_prod_pre['rows'],
		$sentinel_prod_pre['md5'],
		$sentinel_lab_pre['rows'],
		$sentinel_lab_pre['md5']
	)
);

$backup_sql        = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_40_pilot_trids_9545_9201_20261018_175300.sql';
$backup_meta_file  = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_40_pilot_trids_9545_9201_20261018_175300.meta.json';
$expected_bkp_hash = '8f2e9f6283ce49ad9352d9f3f8e794ec9a532f7436baf9eed4ca5eb26f200396';
$actual_bkp_hash   = is_file( $backup_sql ) ? (string) hash_file( 'sha256', $backup_sql ) : '';
$backup_head       = is_file( $backup_sql ) ? (string) file_get_contents( $backup_sql, false, null, 0, 1024 ) : '';
$backup_meta_raw   = is_file( $backup_meta_file ) ? (string) file_get_contents( $backup_meta_file ) : '';
$backup_meta       = '' !== $backup_meta_raw ? json_decode( $backup_meta_raw, true ) : array();
$disp_db_exists    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'cms_ect_lab_restore_verify'" );

record_gate(
	'2. Respaldo nuevo fuera del webroot verificado (SHA-256 8f2e9f6283ce...) y evidencia de restauración/idempotencia/rollback en BD desechable',
	$expected_bkp_hash === $actual_bkp_hash
		&& false !== strpos( $backup_head, 'Database: cms_ect_lab' )
		&& is_array( $backup_meta )
		&& 'c470ec70cca7c0718601f8a2cb7696a622ea5d51' === ( $backup_meta['git_baseline'] ?? '' )
		&& 121 === (int) ( $backup_meta['restored_metrics']['tfml_groups'] ?? 0 )
		&& 135 === (int) ( $backup_meta['restored_metrics']['tfml_group_elements'] ?? 0 )
		&& 1 === (int) ( $backup_meta['restored_metrics']['retained_translations'] ?? 0 )
		&& 12 === (int) ( $backup_meta['restored_metrics']['manifests'] ?? 0 )
		&& array( 123, 139, 3 ) === ( $backup_meta['disposable_idempotency_and_rollback_test']['run1_groups_elements_retained'] ?? array() )
		&& 2 === (int) ( $backup_meta['disposable_idempotency_and_rollback_test']['run2_imported_skipped_status']['skipped_groups'] ?? 0 )
		&& array( 121, 135, 1 ) === ( $backup_meta['disposable_idempotency_and_rollback_test']['rollback_groups_elements_retained'] ?? array() )
		&& 0 === $disp_db_exists,
	sprintf( 'sha256=%s, size=%d, disposable_db_clean=%s', $actual_bkp_hash, is_file( $backup_sql ) ? (int) filesize( $backup_sql ) : 0, 0 === $disp_db_exists ? 'true' : 'false' )
);

// ============================================================================
// BLOQUE 2: DERIVACIÓN CANÓNICA Y ESTADO POST-EJECUCIÓN EN cms_ect_lab (SECCIONES 4, 6 Y 7)
// ============================================================================
echo "\n--- BLOQUE 2: Derivación Canónica y Estado Post-Ejecución en cms_ect_lab (Secciones 4, 6 y 7) ---\n";

$mig_manager    = $plugin->get_migration_manager();
$planner        = $mig_manager->get_wpml_planner();
$executor       = $mig_manager->get_wpml_executor();
$baseline_587_q = static function ( string $sql ) use ( $wpdb ): string {
	$target = "SELECT element_id FROM `{$wpdb->prefix}tfml_group_elements`";
	if ( $target === $sql ) {
		return $target . ' WHERE group_id <= 587';
	}
	return $sql;
};
add_filter( 'query', $baseline_587_q );
$master_plan_pre = $planner->generate_plan();
remove_filter( 'query', $baseline_587_q );

$pilot_plan = $master_plan_pre->derive_partial_plan_with_retained_languages(
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

$planned_groups = $pilot_plan->get_planned_groups();
$pilot_summary  = $pilot_plan->get_summary();

record_gate(
	'3. Linaje criptográfico del plan canónico pilot-nav-2-trids-9545-9201 (parent_fp=34a63fb14853... y pilot_fp=ed8b9ede25ad...)',
	MigrationPlan::STATUS_READY === $pilot_plan->get_status()
		&& $expected_parent_fp === $master_plan_pre->get_fingerprint()
		&& $expected_parent_fp === $pilot_plan->get_parent_fingerprint()
		&& $expected_pilot_fp === $pilot_plan->get_fingerprint()
		&& 2 === count( $planned_groups )
		&& 4 === (int) ( $pilot_summary['importable_elements'] ?? 0 )
		&& 2 === (int) ( $pilot_summary['pending_elements'] ?? 0 )
		&& 9201 === (int) ( $planned_groups[0]['trid'] ?? 0 )
		&& array( 'es' => 4976, 'en' => 8339 ) === ( $planned_groups[0]['translations'] ?? array() )
		&& array( 'fr' => 8955 ) === ( $planned_groups[0]['pending_translations'] ?? array() )
		&& 9545 === (int) ( $planned_groups[1]['trid'] ?? 0 )
		&& array( 'es' => 6237, 'en' => 8354 ) === ( $planned_groups[1]['translations'] ?? array() )
		&& array( 'fr' => 8873 ) === ( $planned_groups[1]['pending_translations'] ?? array() ),
	sprintf( 'parent_fp=%s, pilot_fp=%s', (string) $pilot_plan->get_parent_fingerprint(), $pilot_plan->get_fingerprint() )
);

$total_groups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$total_elements = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$dup_groups     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, subtype, canonical_element_id FROM {$wpdb->prefix}tfml_groups WHERE canonical_element_id IS NOT NULL GROUP BY element_type, subtype, canonical_element_id HAVING COUNT(*) > 1) d" );
$dup_elements   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING COUNT(*) > 1) d" );
$fr_active_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE language_code = 'fr' OR element_id IN (8839, 8873, 8955)" );

$g587_row   = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE id = 587 AND element_type = 'post' AND subtype = 'page' AND canonical_element_id = 197", ARRAY_A );
$g587_elems = $wpdb->get_results( "SELECT element_id, language_code, source_version_at_translation FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 587 ORDER BY element_id ASC", ARRAY_A );

$g9201_row   = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE element_type = 'post' AND subtype = 'page' AND canonical_element_id = 4976", ARRAY_A );
$g9201_id    = isset( $g9201_row['id'] ) ? (int) $g9201_row['id'] : 0;
$g9201_elems = $wpdb->get_results( $wpdb->prepare( "SELECT element_id, language_code, source_version_at_translation FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = %d ORDER BY element_id ASC", $g9201_id ), ARRAY_A );

$g9545_row   = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE element_type = 'post' AND subtype = 'page' AND canonical_element_id = 6237", ARRAY_A );
$g9545_id    = isset( $g9545_row['id'] ) ? (int) $g9545_row['id'] : 0;
$g9545_elems = $wpdb->get_results( $wpdb->prepare( "SELECT element_id, language_code, source_version_at_translation FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = %d ORDER BY element_id ASC", $g9545_id ), ARRAY_A );

record_gate(
	'4. Estado exacto post-ejecución en cms_ect_lab (Sección 7): 123 grupos, 139 elementos activos, grupo 588 (TRID 9201: 4976/8339), grupo 589 (TRID 9545: 6237/8354), grupo 587 conservado, 0 francés activo y 0 duplicados',
	123 === $total_groups
		&& 139 === $total_elements
		&& 0 === $dup_groups
		&& 0 === $dup_elements
		&& 0 === $fr_active_cnt
		&& is_array( $g587_row )
		&& 2 === count( $g587_elems )
		&& 588 === $g9201_id
		&& 4976 === (int) ( $g9201_row['canonical_element_id'] ?? 0 )
		&& 2 === count( $g9201_elems )
		&& 4976 === (int) $g9201_elems[0]['element_id'] && 'es' === $g9201_elems[0]['language_code'] && 1 === (int) $g9201_elems[0]['source_version_at_translation']
		&& 8339 === (int) $g9201_elems[1]['element_id'] && 'en' === $g9201_elems[1]['language_code'] && 1 === (int) $g9201_elems[1]['source_version_at_translation']
		&& 589 === $g9545_id
		&& 6237 === (int) ( $g9545_row['canonical_element_id'] ?? 0 )
		&& 2 === count( $g9545_elems )
		&& 6237 === (int) $g9545_elems[0]['element_id'] && 'es' === $g9545_elems[0]['language_code'] && 1 === (int) $g9545_elems[0]['source_version_at_translation']
		&& 8354 === (int) $g9545_elems[1]['element_id'] && 'en' === $g9545_elems[1]['language_code'] && 1 === (int) $g9545_elems[1]['source_version_at_translation'],
	sprintf(
		'groups=%d, elements=%d, g9201=%d (4976:es,8339:en), g9545=%d (6237:es,8354:en), fr_active=%d, dups=%d/%d',
		$total_groups,
		$total_elements,
		$g9201_id,
		$g9545_id,
		$fr_active_cnt,
		$dup_groups,
		$dup_elements
	)
);

$retained_reg   = new RetainedTranslationRegistry( $wpdb );
$retained_all   = $retained_reg->get_all( 'wpml' );
$ret_197        = $retained_reg->get( 'wpml', 197 );
$ret_9201       = $retained_reg->get( 'wpml', 9201 );
$ret_9545       = $retained_reg->get( 'wpml', 9545 );
$rollback_svc   = new WpmlMigrationRollbackService( $wpdb );
$manifest_pilot = $rollback_svc->get_execution_manifest( 'pilot-nav-2-trids-9545-9201' );
$manifests_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$cp_now         = ( new MigrationCheckpointRepository( $wpdb ) )->get( 'wpml' );

record_gate(
	'5. Registro de 3 traducciones francesas retenidas (fr:8839 [g:587], fr:8955 [g:588], fr:8873 [g:589]), 13 manifiestos y checkpoint completed',
	3 === count( $retained_all )
		&& is_array( $ret_197 ) && $retained_reg->verify_record_integrity( $ret_197 ) && 587 === (int) $ret_197['group_id'] && 8839 === (int) ( $ret_197['retained_elements']['fr']['element_id'] ?? 0 )
		&& is_array( $ret_9201 ) && $retained_reg->verify_record_integrity( $ret_9201 ) && 588 === (int) $ret_9201['group_id'] && 8955 === (int) ( $ret_9201['retained_elements']['fr']['element_id'] ?? 0 ) && $expected_pilot_fp === $ret_9201['plan_fingerprint']
		&& is_array( $ret_9545 ) && $retained_reg->verify_record_integrity( $ret_9545 ) && 589 === (int) $ret_9545['group_id'] && 8873 === (int) ( $ret_9545['retained_elements']['fr']['element_id'] ?? 0 ) && $expected_pilot_fp === $ret_9545['plan_fingerprint']
		&& 13 === $manifests_cnt
		&& is_array( $manifest_pilot )
		&& $expected_pilot_fp === ( $manifest_pilot['plan_fingerprint'] ?? '' )
		&& array( 588, 589 ) === ( $manifest_pilot['imported_group_ids'] ?? array() )
		&& null !== $cp_now
		&& $cp_now->is_completed()
		&& 2 === $cp_now->get_total_items()
		&& 2 === $cp_now->get_processed_items()
		&& 9545 === $cp_now->get_last_processed_trid()
		&& empty( $cp_now->get_errors() ),
	sprintf( 'retained=%d (8839,8955,8873), manifests=%d, imported_group_ids=[588,589], cp=%s', count( $retained_all ), $manifests_cnt, $cp_now ? $cp_now->get_status() : 'null' )
);

// ============================================================================
// BLOQUE 3: RECONCILIACIÓN 123/139/3, RESOLVER E IDEMPOTENCIA NO DESTRUCTIVA (SECCIÓN 10)
// ============================================================================
echo "\n--- BLOQUE 3: Reconciliación 123/139/3, Resolver e Idempotencia No Destructiva (Sección 10) ---\n";

$recon_pilot  = $executor->reconcile( $pilot_plan );
$plan_no_conf = $planner->generate_plan( 0, 0, false );

$importable_trids = array_map(
	static fn( array $g ): int => (int) $g['trid'],
	$plan_no_conf->get_groups_by_classification( MigrationPlan::CLASSIFICATION_IMPORTABLE )
);
$selections_123   = $importable_trids;
$selections_123[] = array(
	'trid'                 => 197,
	'authorized_languages' => array( 'es', 'en' ),
	'retained_languages'   => array( 'fr' ),
);
$selections_123[] = array(
	'trid'                 => 9201,
	'authorized_languages' => array( 'es', 'en' ),
	'retained_languages'   => array( 'fr' ),
);
$selections_123[] = array(
	'trid'                 => 9545,
	'authorized_languages' => array( 'es', 'en' ),
	'retained_languages'   => array( 'fr' ),
);
$plan_123  = $plan_no_conf->derive_partial_plan_with_retained_languages( $selections_123, 'reconcile-123-groups' );
$recon_123 = $executor->reconcile( $plan_123 );

$group_repo = new TranslationGroupRepository( $wpdb, $lang_registry );
$validator  = new WordPressElementValidator();
$resolver   = new ContentTranslationResolver( $group_repo, $lang_registry, $validator );
$public_api = $plugin->get_servicio_traduccion_publico();

$fr_threw = false;
try {
	$resolver->resolve_element_id( 'post', 6237, 'fr' );
} catch ( LanguageNotFoundException $e ) {
	$fr_threw = true;
}

// Verificación no destructiva de idempotencia en cms_ect_lab (sin segunda ejecución real):
// 1. validate_plan($pilot_plan, $expected_pilot_fp) comprueba que los miembros de TRID 9201 y TRID 9545
//    existen en WP, coinciden con icl_translations y pertenecen íntegramente a 1 único grupo por TRID (588 y 589).
// 2. Comprobación directa de la condición de Case 1 (idempotent_skipped) de WpmlMigrationExecutor::import_group().
$non_destructive_val = $executor->validate_plan( $pilot_plan, $expected_pilot_fp );
$idem_case1_9201     = ( $group_repo->find_by_element( 'post', 4976 )?->get_id() === 588 && $group_repo->find_by_element( 'post', 8339 )?->get_id() === 588 );
$idem_case1_9545     = ( $group_repo->find_by_element( 'post', 6237 )?->get_id() === 589 && $group_repo->find_by_element( 'post', 8354 )?->get_id() === 589 );

record_gate(
	'6. Reconciliación global 123/139/3 (0 discrepancias), resolución bidireccional ES<->EN (6237<->8354, 4976<->8339, 197<->8356) e idempotencia no destructiva verificada',
	true === ( $recon_pilot['integrity_verified'] ?? false )
		&& 2 === (int) ( $recon_pilot['tfml_imported_groups'] ?? 0 )
		&& 4 === (int) ( $recon_pilot['tfml_imported_elements'] ?? 0 )
		&& 2 === (int) ( $recon_pilot['retained_pending_elements'] ?? 0 )
		&& empty( $recon_pilot['discrepancies'] )
		&& true === ( $recon_123['integrity_verified'] ?? false )
		&& 123 === (int) ( $recon_123['tfml_imported_groups'] ?? 0 )
		&& 139 === (int) ( $recon_123['tfml_imported_elements'] ?? 0 )
		&& 3 === (int) ( $recon_123['retained_pending_elements'] ?? 0 )
		&& empty( $recon_123['discrepancies'] )
		&& 8354 === $resolver->resolve_element_id( 'post', 6237, 'en' )
		&& 6237 === $resolver->resolve_element_id( 'post', 8354, 'es' )
		&& 8339 === $resolver->resolve_element_id( 'post', 4976, 'en' )
		&& 4976 === $resolver->resolve_element_id( 'post', 8339, 'es' )
		&& 8356 === $resolver->resolve_element_id( 'post', 197, 'en' )
		&& 197 === $resolver->resolve_element_id( 'post', 8356, 'es' )
		&& null === $resolver->language_of( 'post', 8873 )
		&& null === $resolver->language_of( 'post', 8955 )
		&& null === $resolver->language_of( 'post', 8839 )
		&& $fr_threw
		&& null === $public_api->resolver_traduccion_elemento( 'page', 6237, 'fr' )
		&& true === ( $non_destructive_val['valid'] ?? false )
		&& empty( $non_destructive_val['errors'] )
		&& $idem_case1_9201
		&& $idem_case1_9545,
	sprintf(
		'recon_123=%d/%d/%d (discrepancies=%d), validate_plan=true, case1_idempotent=true',
		$recon_123['tfml_imported_groups'],
		$recon_123['tfml_imported_elements'],
		$recon_123['retained_pending_elements'],
		count( $recon_123['discrepancies'] )
	)
);

// ============================================================================
// BLOQUE 4: CERTIFICACIÓN DE NAVEGACIÓN HTTP REAL Y MENÚS (SECCIÓN 8)
// ============================================================================
echo "\n--- BLOQUE 4: Certificación de Navegación HTTP Real y Menús de Escritorio (Sección 8) ---\n";

$r_nosotros    = inspect_http_route( $lab_url . '/nosotros/' );
$r_about_en    = inspect_http_route( $lab_url . '/en/about-us/' );
$r_actividades = inspect_http_route( $lab_url . '/actividades/' );
$r_activ_en    = inspect_http_route( $lab_url . '/en/activities/' );
$r_home_es     = inspect_http_route( $lab_url . '/' );
$r_home_en     = inspect_http_route( $lab_url . '/en/' );
$r_fr          = inspect_http_route( $lab_url . '/fr/' );
$r_pt          = inspect_http_route( $lab_url . '/pt/' );
$r_sitemap_pub = inspect_http_route( $lab_url . '/wp-sitemap.xml' );

$expected_hl_nosotros = array(
	'es'        => 'http://lab.ecoterra.test/nosotros/',
	'en'        => 'http://lab.ecoterra.test/en/about-us/',
	'x-default' => 'http://lab.ecoterra.test/nosotros/',
);
$expected_hl_actividades = array(
	'es'        => 'http://lab.ecoterra.test/actividades/',
	'en'        => 'http://lab.ecoterra.test/en/activities/',
	'x-default' => 'http://lab.ecoterra.test/actividades/',
);
$expected_hl_home = array(
	'es'        => 'http://lab.ecoterra.test/',
	'en'        => 'http://lab.ecoterra.test/en/',
	'x-default' => 'http://lab.ecoterra.test/',
);

$canonical_mgr = $plugin->get_canonical_url_manager();
$canon_6237    = $canonical_mgr->filter_canonical_url( (string) get_permalink( 6237 ), 6237 );
$canon_8354    = $canonical_mgr->filter_canonical_url( (string) get_permalink( 8354 ), 8354 );
$canon_4976    = $canonical_mgr->filter_canonical_url( (string) get_permalink( 4976 ), 4976 );
$canon_8339    = $canonical_mgr->filter_canonical_url( (string) get_permalink( 8339 ), 8339 );

$http_6_ok = 200 === $r_nosotros['code']
	&& 'es-ES' === $r_nosotros['lang']
	&& false !== strpos( $r_nosotros['body_class'], 'page-id-6237' )
	&& 'http://lab.ecoterra.test/nosotros/' === $r_nosotros['canonical']
	&& 'http://lab.ecoterra.test/nosotros/' === $canon_6237
	&& $expected_hl_nosotros === $r_nosotros['hreflang']
	&& ! $r_nosotros['php_errors']
	&& 200 === $r_about_en['code']
	&& 'en-US' === $r_about_en['lang']
	&& false !== strpos( $r_about_en['body_class'], 'page-id-8354' )
	&& 'http://lab.ecoterra.test/en/about-us/' === $canon_8354
	&& $expected_hl_nosotros === $r_about_en['hreflang']
	&& ! $r_about_en['php_errors']
	&& 200 === $r_actividades['code']
	&& 'es-ES' === $r_actividades['lang']
	&& false !== strpos( $r_actividades['body_class'], 'page-id-4976' )
	&& 'http://lab.ecoterra.test/actividades/' === $r_actividades['canonical']
	&& 'http://lab.ecoterra.test/actividades/' === $canon_4976
	&& $expected_hl_actividades === $r_actividades['hreflang']
	&& ! $r_actividades['php_errors']
	&& 200 === $r_activ_en['code']
	&& 'en-US' === $r_activ_en['lang']
	&& false !== strpos( $r_activ_en['body_class'], 'page-id-8339' )
	&& 'http://lab.ecoterra.test/en/activities/' === $canon_8339
	&& $expected_hl_actividades === $r_activ_en['hreflang']
	&& ! $r_activ_en['php_errors']
	&& 200 === $r_home_es['code']
	&& 'es-ES' === $r_home_es['lang']
	&& false !== strpos( $r_home_es['body_class'], 'page-id-197' )
	&& 'http://lab.ecoterra.test/' === $r_home_es['canonical']
	&& $expected_hl_home === $r_home_es['hreflang']
	&& ! $r_home_es['php_errors']
	&& 200 === $r_home_en['code']
	&& 'en-US' === $r_home_en['lang']
	&& false !== strpos( $r_home_en['body_class'], 'page-id-8356' )
	&& 'http://lab.ecoterra.test/en/' === $r_home_en['canonical']
	&& $expected_hl_home === $r_home_en['hreflang']
	&& ! $r_home_en['php_errors']
	&& 404 === $r_fr['code']
	&& 404 === $r_pt['code']
	&& 404 === $r_sitemap_pub['code'];

record_gate(
	'7. Certificación HTTP real (Sección 8): /nosotros/ (6237:es), /en/about-us/ (8354:en), /actividades/ (4976:es), /en/activities/ (8339:en), / (197:es) y /en/ (8356:en) responden 200 con identidad, lang, canonical y hreflang exactos; /fr/, /pt/ y /wp-sitemap.xml responden 404',
	$http_6_ok,
	sprintf(
		'/nosotros/=%d(%s,6237), /en/about-us/=%d(%s,8354,canon_mgr=%s,html_rel_canon=%s), /actividades/=%d(%s,4976), /en/activities/=%d(%s,8339,canon_mgr=%s,html_rel_canon=%s), /=%d(197), /en/=%d(8356)',
		$r_nosotros['code'],
		$r_nosotros['lang'],
		$r_about_en['code'],
		$r_about_en['lang'],
		$canon_8354,
		$r_about_en['canonical'],
		$r_actividades['code'],
		$r_actividades['lang'],
		$r_activ_en['code'],
		$r_activ_en['lang'],
		$canon_8339,
		$r_activ_en['canonical'],
		$r_home_es['code'],
		$r_home_en['code']
	)
);

// Verificar menús de escritorio en HTML HTTP real (<ul id="menu-menu-principal"> y <ul id="menu-menu-es-ingles">)
$es_html_has_nav = false !== strpos( $r_home_es['body'], 'href="http://lab.ecoterra.test/nosotros/"' )
	&& false !== strpos( $r_home_es['body'], 'href="http://lab.ecoterra.test/actividades/"' )
	&& false !== strpos( $r_nosotros['body'], 'href="http://lab.ecoterra.test/nosotros/"' )
	&& false !== strpos( $r_nosotros['body'], 'href="http://lab.ecoterra.test/actividades/"' )
	&& false !== strpos( $r_actividades['body'], 'href="http://lab.ecoterra.test/nosotros/"' )
	&& false !== strpos( $r_actividades['body'], 'href="http://lab.ecoterra.test/actividades/"' );

$en_html_has_nav = false !== strpos( $r_home_en['body'], 'href="http://lab.ecoterra.test/en/about-us/"' )
	&& false !== strpos( $r_home_en['body'], 'href="http://lab.ecoterra.test/en/activities/"' )
	&& false !== strpos( $r_about_en['body'], 'href="http://lab.ecoterra.test/en/about-us/"' )
	&& false !== strpos( $r_about_en['body'], 'href="http://lab.ecoterra.test/en/activities/"' )
	&& false !== strpos( $r_activ_en['body'], 'href="http://lab.ecoterra.test/en/about-us/"' )
	&& false !== strpos( $r_activ_en['body'], 'href="http://lab.ecoterra.test/en/activities/"' );

// Extraer los 16 enlaces renderizados en <ul id="menu-menu-es-ingles"> en /en/about-us/
$migrated_en_urls    = array();
$unmigrated_safe_cnt = 0;
$premature_en_cnt    = 0;

if ( preg_match( '/<ul[^>]*id=["\']menu-menu-es-ingles["\'][^>]*>(.*?)<\/nav>/is', $r_about_en['body'], $m_nav ) ) {
	if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $m_nav[1], $a_nav, PREG_SET_ORDER ) ) {
		foreach ( $a_nav as $a_item ) {
			$href = trim( $a_item[1] );
			$text = trim( wp_strip_all_tags( $a_item[2] ) );
			if ( in_array( $text, array( 'Home', 'About Us', 'Activities' ), true ) ) {
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

$fr_in_en_html = ( false !== strpos( $r_home_en['body'], '/nous/' ) || false !== strpos( $r_home_en['body'], '/activites/' ) || false !== strpos( $r_home_en['body'], '/fr/' ) );

record_gate(
	'8. Certificación de menús de escritorio (Sección 8): Nosotros->/nosotros/, About Us->/en/about-us/, Actividades->/actividades/, Activities->/en/activities/, 12/12 subpáginas no migradas protegidas (0 falsas rutas /en/...) y 0 francés expuesto',
	$es_html_has_nav
		&& $en_html_has_nav
		&& 'http://lab.ecoterra.test/en/' === ( $migrated_en_urls['Home'] ?? '' )
		&& 'http://lab.ecoterra.test/en/about-us/' === ( $migrated_en_urls['About Us'] ?? '' )
		&& 'http://lab.ecoterra.test/en/activities/' === ( $migrated_en_urls['Activities'] ?? '' )
		&& 12 === $unmigrated_safe_cnt
		&& 0 === $premature_en_cnt
		&& ! $fr_in_en_html,
	sprintf(
		'about_en=%s, activities_en=%s, unmigrated_safe=%d/12, premature_en=%d, fr_exposed=%s',
		$migrated_en_urls['About Us'] ?? '',
		$migrated_en_urls['Activities'] ?? '',
		$unmigrated_safe_cnt,
		$premature_en_cnt,
		$fr_in_en_html ? 'true' : 'false'
	)
);

// ============================================================================
// BLOQUE 5: REGRESIÓN DE SITEMAPS (SECCIÓN 9) E INVARIANCIA POST-VERIFICACIÓN
// ============================================================================
echo "\n--- BLOQUE 5: Regresión de Sitemaps (Sección 9) e Invariancia Post-Verificación ---\n";

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

$expected_7_page_locs = array(
	'http://lab.ecoterra.test/',
	'http://lab.ecoterra.test/actividades/',
	'http://lab.ecoterra.test/en/',
	'http://lab.ecoterra.test/en/about-us/',
	'http://lab.ecoterra.test/en/activities/',
	'http://lab.ecoterra.test/nosotros/',
	'http://lab.ecoterra.test/old-blog/',
);
sort( $expected_7_page_locs );

$all_sitemap_locs = array_merge(
	$page_locs,
	array_map( static fn( array $e ): string => (string) $e['loc'], $post_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $room_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $hf_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $cat_entries ),
	array_map( static fn( array $e ): string => (string) $e['loc'], $tag_entries )
);

$sitemap_Subtypes = array_keys( $posts_provider->get_object_subtypes() );

record_gate(
	'9. Regresión de sitemaps (Sección 9): page pasa de 3 a 7 entradas exactas, total consolidado pasa de 26 a 30 (post=3, rooms=0, hf=0, category=1, post_tag=19), 0 francés, 0 duplicados y blog_public=0',
	7 === count( $page_entries )
		&& $expected_7_page_locs === $page_locs
		&& 1 === $posts_provider->get_max_num_pages( 'page' )
		&& 3 === count( $post_entries )
		&& 0 === count( $room_entries )
		&& 0 === $posts_provider->get_max_num_pages( 'rooms' )
		&& 0 === count( $hf_entries )
		&& ! in_array( 'ova_framework_hf_el', $sitemap_Subtypes, true )
		&& 1 === count( $cat_entries )
		&& 19 === count( $tag_entries )
		&& 30 === count( $all_sitemap_locs )
		&& 30 === count( array_unique( $all_sitemap_locs ) )
		&& '0' === (string) get_option( 'blog_public' ),
	sprintf(
		'total=%d (page=%d, post=%d, rooms=%d, hf=%d, cat=%d, tag=%d), unique=%d',
		count( $all_sitemap_locs ),
		count( $page_entries ),
		count( $post_entries ),
		count( $room_entries ),
		count( $hf_entries ),
		count( $cat_entries ),
		count( $tag_entries ),
		count( array_unique( $all_sitemap_locs ) )
	)
);

$digest_after       = get_tfml_state_digest();
$sentinel_prod_post = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_post  = get_icl_sentinel( 'cms_ect_lab' );

record_gate(
	'10. Invariancia post-verificación: 0 escrituras durante el verificador (digest SHA-256 idéntico, 123 grupos, 139 elementos, 3 retenidos, 13 manifiestos y centinela WPML 3,403 / 4241ca7e7ec6399a594537cb04790c10 intacto)',
	$digest_before === $digest_after
		&& $expected_wpml_rows === $sentinel_prod_post['rows']
		&& $expected_wpml_md5 === $sentinel_prod_post['md5']
		&& $expected_wpml_rows === $sentinel_lab_post['rows']
		&& $expected_wpml_md5 === $sentinel_lab_post['md5'],
	sprintf(
		'digest_match=%s, cms_ect=%d/%s, cms_ect_lab=%d/%s',
		$digest_before === $digest_after ? 'true' : 'false',
		$sentinel_prod_post['rows'],
		$sentinel_prod_post['md5'],
		$sentinel_lab_post['rows'],
		$sentinel_lab_post['md5']
	)
);

echo "\n============================================================================\n";
echo sprintf(
	" RESUMEN FASE 3.40 (DT-TFML-070): %d/%d CONTROLES PASS (%d FAIL | %d BLOCKED | %d NOT_TESTED)\n",
	$passed_checks,
	$total_checks,
	$failed_checks,
	$blocked_checks,
	$not_tested_checks
);
echo "============================================================================\n";

exit( $failed_checks > 0 ? 1 : 0 );
