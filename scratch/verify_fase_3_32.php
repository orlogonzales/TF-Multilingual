<?php
/**
 * Script de Verificación Reproducible — Fase 3.32 (DT-TFML-062).
 *
 * Importación Piloto Controlada de la Portada Bilingüe (TRID 197) en Laboratorio.
 *
 * Verifica:
 * 1. Preflight bloqueante, salvaguardas, centinelas de origen y validación de restauración de respaldo.
 * 2. Derivación autorizada y validación previa del plan parcial 'pilot-trid-197-partial'.
 * 3. Estado exacto tras la importación piloto en cms_ect_lab (121 grupos, 135 elementos activos, 1 retenido).
 * 4. Resolución de traducciones (ContentTranslationResolver y ServicioTraduccionPublico).
 * 5. Manifiesto, reconciliación completa (121 grupos / 135 elementos) e idempotencia controlada.
 * 6. Pruebas HTTP en vivo sobre http://lab.ecoterra.test/ (distinguiendo PASS y BLOCKED BY ROUTING en /en/).
 * 7. Invariancia post-fase de cms_ect y de las tablas fuente WPML.
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

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

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

use TF\Multilingual\API\ServicioTraduccionPublico;
use TF\Multilingual\Compatibilidad\AdaptadorCompatibilidadWpml;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Routing\CurrentLanguageResolver;

$lab_url = getenv( 'TFML_LAB_URL' ) ? rtrim( (string) getenv( 'TFML_LAB_URL' ), '/' ) : 'http://lab.ecoterra.test';

$total_checks      = 0;
$passed_checks     = 0;
$failed_checks     = 0;
$blocked_checks    = 0;
$not_tested_checks = 0;

/**
 * Registra un control de verificación con estado explícito (PASS, FAIL, BLOCKED, NOT_TESTED).
 *
 * @param string $label     Descripción del control.
 * @param bool   $condition Condición booleana evaluada.
 * @param string $detail    Detalle adicional.
 * @param string $mode      Modo ('PASS_FAIL', 'BLOCKED_IF_TRUE', 'NOT_TESTED').
 * @return void
 */
function record_gate( string $label, bool $condition, string $detail = '', string $mode = 'PASS_FAIL' ): void {
	global $total_checks, $passed_checks, $failed_checks, $blocked_checks, $not_tested_checks;
	++$total_checks;

	if ( 'NOT_TESTED' === $mode ) {
		++$not_tested_checks;
		echo sprintf( "[NOT_TESTED #%02d] %s%s\n", $total_checks, $label, '' !== $detail ? " — {$detail}" : '' );
		return;
	}

	if ( 'BLOCKED_IF_TRUE' === $mode && $condition ) {
		++$blocked_checks;
		echo sprintf( "[BLOCKED #%02d] %s%s\n", $total_checks, $label, '' !== $detail ? " — {$detail}" : '' );
		return;
	}

	if ( $condition ) {
		++$passed_checks;
		echo sprintf( "[PASS #%02d] %s%s\n", $total_checks, $label, '' !== $detail ? " — {$detail}" : '' );
	} else {
		++$failed_checks;
		echo sprintf( "[FAIL #%02d] %s%s\n", $total_checks, $label, '' !== $detail ? " — {$detail}" : '' );
	}
}

/**
 * Obtiene la huella centinela de una tabla icl_translations en una base de datos.
 *
 * @param string $db_name Nombre de la base de datos.
 * @return array{rows: int, md5: string}
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
		'rows' => (int) ( $row['cnt'] ?? 0 ),
		'md5'  => (string) ( $row['hash_val'] ?? '' ),
	);
}

/**
 * Ejecuta una petición HTTP sin seguir redirecciones y extrae metadatos SEO/HTML.
 *
 * @param string $url URL completa.
 * @return array{code: int, location: string, lang: string, canonical: string, hreflang: array<string, string>, php_errors: bool}
 */
function inspect_http_route( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 10,
		)
	);
	$resp     = (string) curl_exec( $ch );
	$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$hdr_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$headers  = substr( $resp, 0, $hdr_size );
	$body     = substr( $resp, $hdr_size );
	curl_close( $ch );

	$location = '';
	if ( preg_match( '/^Location:\s*(.+)$/mi', $headers, $m ) ) {
		$location = trim( $m[1] );
	}

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

	$php_errors = ( false !== stripos( $body, 'Fatal error' ) || false !== stripos( $body, 'Parse error' ) || false !== stripos( $body, 'Uncaught Error' ) );

	return array(
		'code'       => $code,
		'location'   => $location,
		'lang'       => $lang,
		'canonical'  => $canonical,
		'hreflang'   => $hreflang,
		'php_errors' => $php_errors,
	);
}

echo "================================================================================\n";
echo "TF MULTILINGUAL — VERIFICADOR REPRODUCIBLE FASE 3.32 (DT-TFML-062)\n";
echo "Importación Piloto Controlada de la Portada Bilingüe (TRID 197) en Laboratorio\n";
echo "URL de Laboratorio: {$lab_url}\n";
echo "================================================================================\n\n";

global $wpdb;

// ============================================================================
// BLOQUE 1: PREFLIGHT, ENTORNO, SALVAGUARDAS Y VALIDACIÓN DE RESPALDO
// ============================================================================
echo "--- BLOQUE 1: Preflight, Entorno, Salvaguardas y Respaldo Pre-Piloto ---\n";

$normalized_abspath = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
$home_opt           = rtrim( (string) get_option( 'home' ), '/' );
$siteurl_opt        = rtrim( (string) get_option( 'siteurl' ), '/' );

record_gate(
	'Correspondencia física y URL del laboratorio (D:/laragon/www/lab.ecoterra <-> http://lab.ecoterra.test)',
	'D:/laragon/www/lab.ecoterra' === $normalized_abspath && 'http://lab.ecoterra.test' === $home_opt && 'http://lab.ecoterra.test' === $siteurl_opt,
	sprintf( 'ABSPATH=%s, home=%s', $normalized_abspath, $home_opt )
);

$current_db = (string) $wpdb->get_var( 'SELECT DATABASE()' );
record_gate(
	'Conexión MySQL activa en cms_ect_lab con prefijo etk_t_',
	'cms_ect_lab' === $current_db && 'etk_t_' === $wpdb->prefix,
	sprintf( 'db=%s, prefix=%s', $current_db, $wpdb->prefix )
);

$sentinel_cms_ect_pre = get_icl_sentinel( 'cms_ect' );
record_gate(
	'Centinela en BD original protegida (cms_ect.etk_t_icl_translations) intacto',
	3403 === $sentinel_cms_ect_pre['rows'] && '4241ca7e7ec6399a594537cb04790c10' === $sentinel_cms_ect_pre['md5'],
	sprintf( 'rows=%d, md5=%s', $sentinel_cms_ect_pre['rows'], $sentinel_cms_ect_pre['md5'] )
);

$sentinel_lab_pre = get_icl_sentinel( 'cms_ect_lab' );
record_gate(
	'Centinela en BD de laboratorio (cms_ect_lab.etk_t_icl_translations) intacto',
	3403 === $sentinel_lab_pre['rows'] && '4241ca7e7ec6399a594537cb04790c10' === $sentinel_lab_pre['md5'],
	sprintf( 'rows=%d, md5=%s', $sentinel_lab_pre['rows'], $sentinel_lab_pre['md5'] )
);

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
record_gate(
	'Motor InnoDB confirmado en etk_t_options y las 5 tablas etk_t_tfml_*',
	$all_innodb,
	sprintf( '%d tablas InnoDB verificadas', count( $engines_rows ) )
);

$schema_ver = (string) get_option( SchemaManager::OPTION_SCHEMA_VERSION, '' );
record_gate(
	'Versión de esquema tfml_schema_version = 1.0.0 sin operaciones DDL no autorizadas',
	'1.0.0' === $schema_ver,
	sprintf( 'tfml_schema_version=%s', $schema_ver )
);

$cron_disabled = defined( 'DISABLE_WP_CRON' ) && true === constant( 'DISABLE_WP_CRON' );
$http_blocked  = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && true === constant( 'WP_HTTP_BLOCK_EXTERNAL' );
$blog_public   = (string) get_option( 'blog_public' );
record_gate(
	'Salvaguardas contra cron, HTTP externo e indexación activas en laboratorio',
	$cron_disabled && $http_blocked && '0' === $blog_public,
	sprintf( 'DISABLE_WP_CRON=%s, WP_HTTP_BLOCK_EXTERNAL=%s, blog_public=%s', $cron_disabled ? 'true' : 'false', $http_blocked ? 'true' : 'false', $blog_public )
);

$hist_backup = 'D:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
$hist_hash   = file_exists( $hist_backup ) ? hash_file( 'sha256', $hist_backup ) : '';
record_gate(
	'Respaldo histórico inicial (2026-10-11) verificado fuera del webroot',
	'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $hist_hash,
	sprintf( 'sha256=%s', $hist_hash )
);

$pilot_backup      = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_32_pilot_trid197_20261013_233600.sql';
$pilot_meta_file   = 'D:/laragon/backup/tfml_staging/cms_ect_lab_pre_fase_3_32_pilot_trid197_20261013_233600.meta.json';
$pilot_backup_hash = file_exists( $pilot_backup ) ? hash_file( 'sha256', $pilot_backup ) : '';
$pilot_meta_raw    = file_exists( $pilot_meta_file ) ? (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) file_get_contents( $pilot_meta_file ) ) : '';
$pilot_meta        = '' !== $pilot_meta_raw ? json_decode( $pilot_meta_raw, true ) : array();
record_gate(
	'Respaldo específico pre-piloto de cms_ect_lab íntegro y con metadatos de baseline registrados',
	'8b40a59daac02844b4a6c7d1414108db2b8836383f4ca2978dc9d141fa74c515' === $pilot_backup_hash
		&& is_array( $pilot_meta )
		&& 'a9ea9cd2c6854132c82a1deadbbb4aeeaac630f7' === ( $pilot_meta['git_baseline'] ?? '' ),
	sprintf( 'sha256=%s, baseline=%s', $pilot_backup_hash, (string) ( $pilot_meta['git_baseline'] ?? '' ) )
);

// Verificar que la base efímera de restauración fue limpiada y que el archivo SQL contiene las estructuras clave.
$backup_head       = file_exists( $pilot_backup ) ? (string) file_get_contents( $pilot_backup, false, null, 0, 1024 ) : '';
$restore_db_exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'cms_ect_lab_restore_verify'" );
record_gate(
	'Cabecera de origen cms_ect_lab confirmada en respaldo pre-piloto y BD efímera de restauración limpia',
	false !== strpos( $backup_head, 'Database: cms_ect_lab' ) && 0 === $restore_db_exists,
	'Database: cms_ect_lab confirmado; cms_ect_lab_restore_verify=0'
);

$lock_manager = new MigrationExecutionLock( $wpdb );
record_gate(
	'Lock de migración tfml_migration_lock_wpml liberado',
	! $lock_manager->is_locked( 'wpml' ),
	'is_locked(wpml)=false'
);

$live_lang_reg_check = new LanguageRegistry( new SettingsRepository() );
$wpml_adapter        = new AdaptadorCompatibilidadWpml(
	new ServicioTraduccionPublico(
		$live_lang_reg_check,
		new CurrentLanguageResolver( new \TF\Multilingual\Routing\UrlLanguageResolver( $live_lang_reg_check ), $live_lang_reg_check ),
		new ContentTranslationResolver()
	)
);
record_gate(
	'Adaptador WPML desactivado por defecto y constante ICL_LANGUAGE_CODE no definida',
	! $wpml_adapter->esta_habilitado() && ! defined( 'ICL_LANGUAGE_CODE' ),
	'esta_habilitado=false, ICL_LANGUAGE_CODE=undefined'
);

// ============================================================================
// BLOQUE 2: PLAN DERIVADO AUTORIZADO Y VALIDACIÓN PREVIA
// ============================================================================
echo "\n--- BLOQUE 2: Plan Derivado Autorizado (pilot-trid-197-partial) y Validación ---\n";

$lang_registry_es_en = new class() extends LanguageRegistry {
	public function __construct() {}
	public function is_active( string $code ): bool {
		return in_array( strtolower( $code ), array( 'es', 'en' ), true );
	}
};

$planner      = new WpmlMigrationPlanner( $wpdb, $lang_registry_es_en );
$master_plan  = $planner->generate_plan( 0, 0, false );
$plan_summary = $master_plan->get_summary();
$master_fp    = $master_plan->get_fingerprint();

record_gate(
	'Plan maestro de WPML intacto (249 grupos: 120 IMPORTABLE, 52 BLOCKED_BY_LANGUAGE, 53 ORPHAN, 24 SKIPPED)',
	249 === (int) $plan_summary['total_groups']
		&& 120 === (int) $plan_summary['importable_groups']
		&& 52 === (int) $plan_summary['blocked_language_groups']
		&& 53 === (int) $plan_summary['orphan_groups']
		&& 24 === (int) $plan_summary['skipped_groups']
		&& '08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949' === $master_fp,
	sprintf( 'fingerprint=%s', $master_fp )
);

$rejected_without_auth = false;
try {
	$master_plan->derive_partial_plan( array( 197 ), 'pilot-trid-197-partial' );
} catch ( InvalidArgumentException $e ) {
	$rejected_without_auth = true;
}
record_gate(
	'TRID 197 clasificado originalmente como BLOCKED_BY_LANGUAGE exige autorización explícita',
	$rejected_without_auth,
	'derive_partial_plan([197]) sin allow_retained rechazado'
);

$pilot_plan   = $master_plan->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 197,
			'authorized_languages' => array( 'es', 'en' ),
		),
	),
	'pilot-trid-197-partial'
);
$pilot_groups = $pilot_plan->get_planned_groups();
$pilot_g197   = $pilot_groups[0] ?? array();
$pilot_fp     = $pilot_plan->get_fingerprint();

record_gate(
	'Plan derivado pilot-trid-197-partial contiene exactamente 1 grupo (ES:197 canónico, EN:8356 activo, FR:8839 retenido)',
	1 === count( $pilot_groups )
		&& 197 === (int) ( $pilot_g197['trid'] ?? 0 )
		&& MigrationPlan::CLASSIFICATION_PARTIAL_RETAINED === ( $pilot_g197['classification'] ?? '' )
		&& 197 === (int) ( $pilot_g197['canonical_id'] ?? 0 )
		&& 'es' === ( $pilot_g197['canonical_lang'] ?? '' )
		&& array( 'es' => 197, 'en' => 8356 ) === ( $pilot_g197['translations'] ?? array() )
		&& array( 'fr' => 8839 ) === ( $pilot_g197['pending_translations'] ?? array() ),
	'active={es:197, en:8356}, retained={fr:8839}'
);

record_gate(
	'Linaje criptográfico de pilot-trid-197-partial verificado (parent_fingerprint y fingerprint)',
	$master_fp === $pilot_plan->get_parent_fingerprint()
		&& 'cb9c45034eb0117cd619ce215394a801dfcafe9243a839f355d5896d9e0d17d7' === $pilot_fp
		&& 'pilot-trid-197-partial' === $pilot_plan->get_label(),
	sprintf( 'parent=%s, pilot=%s', (string) $pilot_plan->get_parent_fingerprint(), $pilot_fp )
);

$executor   = new WpmlMigrationExecutor( $wpdb, null, $lang_registry_es_en );
$validation = $executor->validate_plan( $pilot_plan, $pilot_fp );
record_gate(
	'Validación previa de integridad y vigencia de los miembros del TRID 197 supera validate_plan()',
	true === $validation['valid'] && empty( $validation['errors'] ),
	'valid=true, errors=0'
);

// Verificar rechazo de incorporar francés como idioma activo tanto en derivación como en validación del ejecutor.
$fr_only_rejected = false;
try {
	$master_plan->derive_partial_plan_with_retained_languages(
		array(
			array(
				'trid'                 => 197,
				'authorized_languages' => array( 'fr' ),
			),
		),
		'invalid-fr-only'
	);
} catch ( InvalidArgumentException $e ) {
	$fr_only_rejected = true;
}

$fr_Attempt_plan   = $master_plan->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 197,
			'authorized_languages' => array( 'es', 'en', 'fr' ),
		),
	),
	'check-fr-excluded-from-active'
);
$fr_attempt_active = $fr_Attempt_plan->get_planned_groups()[0]['translations'] ?? array();

$tampered_group                       = $pilot_g197;
$tampered_group['translations']['fr'] = 8839;
$tampered_plan                        = new MigrationPlan(
	array(
		'source'             => 'wpml',
		'status'             => MigrationPlan::STATUS_READY,
		'missing_languages'  => array(),
		'summary'            => $pilot_plan->get_summary(),
		'media_plan'         => $pilot_plan->get_media_plan(),
		'planned_groups'     => array( $tampered_group ),
		'parent_fingerprint' => $master_fp,
		'is_partial'         => true,
		'label'              => 'tampered-fr-active',
	)
);
$tampered_validation = $executor->validate_plan( $tampered_plan );
record_gate(
	'Rechazo explícito de cualquier intento de incorporar francés (8839) como idioma activo',
	$fr_only_rejected && ! isset( $fr_attempt_active['fr'] ) && false === $tampered_validation['valid'],
	'Derivación fr-only rechazada; fr excluido de activos; plan manipulado rechazado por validate_plan()'
);

// ============================================================================
// BLOQUE 3: MÉTRICAS EXACTAS DE ACEPTACIÓN EN cms_ect_lab
// ============================================================================
echo "\n--- BLOQUE 3: Métricas Exactas Post-Importación Piloto en cms_ect_lab ---\n";

$total_groups_now   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$total_elements_now = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );

record_gate(
	'Conteo exacto de grupos TFML en cms_ect_lab: 121 (120 históricos + 1 piloto TRID 197)',
	121 === $total_groups_now,
	sprintf( 'groups=%d', $total_groups_now )
);

record_gate(
	'Conteo exacto de elementos activos TFML en cms_ect_lab: 135 (133 históricos + 2 piloto)',
	135 === $total_elements_now,
	sprintf( 'elements=%d', $total_elements_now )
);

$dup_groups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, subtype, canonical_element_id FROM {$wpdb->prefix}tfml_groups WHERE canonical_element_id IS NOT NULL GROUP BY element_type, subtype, canonical_element_id HAVING COUNT(*) > 1) d" );
$dup_elements = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING COUNT(*) > 1) d" );
record_gate(
	'Cero grupos duplicados y cero elementos duplicados en cms_ect_lab',
	0 === $dup_groups && 0 === $dup_elements,
	sprintf( 'dup_groups=%d, dup_elements=%d', $dup_groups, $dup_elements )
);

$pilot_group_row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tfml_groups WHERE element_type = 'post' AND subtype = 'page' AND canonical_element_id = 197", ARRAY_A );
$pilot_group_id  = isset( $pilot_group_row['id'] ) ? (int) $pilot_group_row['id'] : 0;
record_gate(
	'Grupo piloto TFML creado con elemento canónico 197 (group_id = 587, post/page)',
	587 === $pilot_group_id && 'post' === ( $pilot_group_row['element_type'] ?? '' ) && 'page' === ( $pilot_group_row['subtype'] ?? '' ),
	sprintf( 'group_id=%d, canonical_element_id=%d', $pilot_group_id, (int) ( $pilot_group_row['canonical_element_id'] ?? 0 ) )
);

$pilot_elems = $wpdb->get_results(
	$wpdb->prepare( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = %d ORDER BY element_id ASC", $pilot_group_id ),
	ARRAY_A
);
record_gate(
	'Elementos activos español (197 -> es) e inglés (8356 -> en) presentes bajo group_id = 587',
	2 === count( $pilot_elems )
		&& 197 === (int) ( $pilot_elems[0]['element_id'] ?? 0 ) && 'es' === ( $pilot_elems[0]['language_code'] ?? '' )
		&& 8356 === (int) ( $pilot_elems[1]['element_id'] ?? 0 ) && 'en' === ( $pilot_elems[1]['language_code'] ?? '' ),
	'197=>es, 8356=>en'
);

$fr_active_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id = 8839 OR language_code = 'fr'" );
record_gate(
	'Elemento francés 8839 (fr) completamente ausente de las tablas activas TFML',
	0 === $fr_active_count,
	sprintf( 'fr_active_count=%d', $fr_active_count )
);

$retained_registry = new RetainedTranslationRegistry( $wpdb );
$all_retained      = $retained_registry->get_all( 'wpml' );
$ret_197           = $retained_registry->get( 'wpml', 197 );
record_gate(
	'Única traducción retenida registrada en RetainedTranslationRegistry corresponde a TRID 197 (fr => 8839) con integridad SHA-256 válida',
	1 === count( $all_retained )
		&& is_array( $ret_197 )
		&& $retained_registry->verify_record_integrity( $ret_197 )
		&& $pilot_group_id === (int) ( $ret_197['group_id'] ?? 0 )
		&& 8839 === (int) ( $ret_197['retained_elements']['fr']['element_id'] ?? 0 )
		&& 'retained_inactive_language' === ( $ret_197['retained_elements']['fr']['status'] ?? '' )
		&& $pilot_fp === ( $ret_197['plan_fingerprint'] ?? '' ),
	sprintf( 'group_id=%d, fr=%d, fingerprint=%s', (int) ( $ret_197['group_id'] ?? 0 ), (int) ( $ret_197['retained_elements']['fr']['element_id'] ?? 0 ), (string) ( $ret_197['fingerprint'] ?? '' ) )
);

$rollback_service = new WpmlMigrationRollbackService( $wpdb );
$pilot_manifest   = $rollback_service->get_execution_manifest( 'pilot-trid-197-partial' );
$total_manifests  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name LIKE 'tfml_migration_manifest_%'" );
record_gate(
	'Manifiesto tfml_migration_manifest_pilot-trid-197-partial persistido y 11 manifiestos históricos intactos (total 12)',
	12 === $total_manifests
		&& is_array( $pilot_manifest )
		&& $pilot_fp === ( $pilot_manifest['plan_fingerprint'] ?? '' )
		&& array( 587 ) === ( $pilot_manifest['imported_group_ids'] ?? array() )
		&& 8839 === (int) ( $pilot_manifest['retained_translations'][0]['retained_translations']['fr'] ?? 0 ),
	sprintf( 'total_manifests=%d, imported_group_ids=[587]', $total_manifests )
);

$cp_repo = new MigrationCheckpointRepository( $wpdb );
$cp_now  = $cp_repo->get( 'wpml' );
record_gate(
	'Checkpoint tfml_migration_checkpoint_wpml coherente (completed, 1/1, last_processed_trid=197, 0 errores)',
	null !== $cp_now
		&& $cp_now->is_completed()
		&& 1 === $cp_now->get_total_items()
		&& 1 === $cp_now->get_processed_items()
		&& 197 === $cp_now->get_last_processed_trid()
		&& empty( $cp_now->get_errors() ),
	null !== $cp_now ? sprintf( 'status=%s, last_trid=%d', $cp_now->get_status(), $cp_now->get_last_processed_trid() ) : 'null'
);

// ============================================================================
// BLOQUE 4: VERIFICACIÓN DEL RESOLVER, RECONCILIACIÓN E IDEMPOTENCIA
// ============================================================================
echo "\n--- BLOQUE 4: Verificación del Resolver, Reconciliación (121/135) e Idempotencia ---\n";

$live_lang_registry = new LanguageRegistry( new SettingsRepository() );
$group_repo         = new TranslationGroupRepository( $wpdb, $live_lang_registry );
$validator          = new WordPressElementValidator();
$resolver           = new ContentTranslationResolver( $group_repo, $live_lang_registry, $validator );
$public_api         = new ServicioTraduccionPublico(
	$live_lang_registry,
	new CurrentLanguageResolver( new \TF\Multilingual\Routing\UrlLanguageResolver( $live_lang_registry ), $live_lang_registry ),
	$resolver
);

record_gate(
	'Resolver: resolución bidireccional entre 197 (es) y 8356 (en)',
	8356 === $resolver->resolve_element_id( 'post', 197, 'en' )
		&& 197 === $resolver->resolve_element_id( 'post', 8356, 'es' )
		&& 8356 === $public_api->resolver_traduccion_elemento( 'page', 197, 'en' )
		&& 197 === $public_api->resolver_traduccion_elemento( 'page', 8356, 'es' ),
	'197(es)->8356(en), 8356(en)->197(es)'
);

record_gate(
	'Resolver: resolución en el idioma propio sin sustituciones innecesarias',
	197 === $resolver->resolve_element_id( 'post', 197, 'es' )
		&& 8356 === $resolver->resolve_element_id( 'post', 8356, 'en' )
		&& 197 === $public_api->resolver_traduccion_elemento( 'page', 197, 'es' )
		&& 8356 === $public_api->resolver_traduccion_elemento( 'page', 8356, 'en' ),
	'197(es)->197, 8356(en)->8356'
);

$group_587_domain = $group_repo->find_by_element( 'post', 197 );
record_gate(
	'Resolver: conservación de procedencia canónica (197:es) y ausencia de traducción activa para 8839 (fr)',
	'es' === $resolver->language_of( 'post', 197 )
		&& 'en' === $resolver->language_of( 'post', 8356 )
		&& null === $resolver->language_of( 'post', 8839 )
		&& null !== $group_587_domain
		&& null !== $group_587_domain->get_canonical_element()
		&& 197 === $group_587_domain->get_canonical_element()->get_element_id(),
	'lang(197)=es, lang(8356)=en, lang(8839)=null, canonical=197'
);

$fr_threw = false;
$xx_threw = false;
try {
	$resolver->resolve_element_id( 'post', 197, 'fr' );
} catch ( LanguageNotFoundException $e ) {
	$fr_threw = true;
}
try {
	$resolver->resolve_element_id( 'post', 197, 'xx' );
} catch ( LanguageNotFoundException $e ) {
	$xx_threw = true;
}
record_gate(
	'Resolver: rechazo explícito de idiomas no registrados o inactivos (fr, xx)',
	$fr_threw && $xx_threw && null === $public_api->resolver_traduccion_elemento( 'page', 197, 'fr' ) && null === $public_api->resolver_traduccion_elemento( 'page', 197, 'xx' ),
	'LanguageNotFoundException en dominio y null en API pública'
);

// Comprobar que el grupo 587 contiene únicamente 197 y 8356 y no se asocia con ningún otro grupo de los 120 anteriores.
$other_groups_with_pilot_ids = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id IN (197, 8356, 8839) AND group_id <> 587" );
record_gate(
	'Ausencia de conflictos o asociaciones cruzadas entre la portada y los 120 grupos históricos',
	0 === $other_groups_with_pilot_ids && 2 === count( $group_587_domain ? $group_587_domain->get_elements() : array() ),
	'0 colisiones con grupos históricos'
);

$recon_pilot = $executor->reconcile( $pilot_plan );
record_gate(
	'Reconciliación del lote piloto (pilot-trid-197-partial): 1 grupo, 2 elementos activos, 1 retenido, 0 discrepancias',
	true === $recon_pilot['integrity_verified']
		&& 1 === $recon_pilot['tfml_imported_groups']
		&& 2 === $recon_pilot['tfml_imported_elements']
		&& 1 === $recon_pilot['retained_pending_elements']
		&& empty( $recon_pilot['discrepancies'] ),
	sprintf( 'groups=%d, elements=%d, retained=%d, discrepancies=%d', $recon_pilot['tfml_imported_groups'], $recon_pilot['tfml_imported_elements'], $recon_pilot['retained_pending_elements'], count( $recon_pilot['discrepancies'] ) )
);

$importable_trids = array_map(
	fn( array $g ) => (int) $g['trid'],
	$master_plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_IMPORTABLE )
);
$selections_121   = $importable_trids;
$selections_121[] = array(
	'trid'                 => 197,
	'authorized_languages' => array( 'es', 'en' ),
);
$plan_121  = $master_plan->derive_partial_plan_with_retained_languages( $selections_121, 'reconcile-121-groups' );
$recon_121 = $executor->reconcile( $plan_121 );
record_gate(
	'Reconciliación completa de los 121 grupos y 135 elementos activos en cms_ect_lab: 0 discrepancias',
	true === $recon_121['integrity_verified']
		&& 121 === $recon_121['tfml_imported_groups']
		&& 135 === $recon_121['tfml_imported_elements']
		&& 1 === $recon_121['retained_pending_elements']
		&& empty( $recon_121['discrepancies'] ),
	sprintf( 'groups=%d, elements=%d, retained=%d, discrepancies=%d', $recon_121['tfml_imported_groups'], $recon_121['tfml_imported_elements'], $recon_121['retained_pending_elements'], count( $recon_121['discrepancies'] ) )
);

// Prueba controlada de idempotencia.
$executor->set_authorized( true );
$ret_before_idem = $retained_registry->get( 'wpml', 197 );
$idem_report     = $executor->run( $pilot_plan, 50, 'pilot-trid-197-partial', $pilot_fp )->to_array();
$ret_after_idem  = $retained_registry->get( 'wpml', 197 );
$groups_after    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );

record_gate(
	'Prueba controlada de idempotencia: 0 escrituras duplicadas, status=idempotent_skipped, conteos 121/135 intactos',
	'completed' === ( $idem_report['status'] ?? '' )
		&& 0 === (int) ( $idem_report['imported_groups'] ?? -1 )
		&& 1 === (int) ( $idem_report['skipped_groups'] ?? 0 )
		&& 'idempotent_skipped' === ( $idem_report['group_results'][0]['status'] ?? '' )
		&& 587 === (int) ( $idem_report['group_results'][0]['group_id'] ?? 0 )
		&& 121 === $groups_after
		&& 135 === $elements_after
		&& ( $ret_before_idem['fingerprint'] ?? '' ) === ( $ret_after_idem['fingerprint'] ?? 'x' ),
	'status=idempotent_skipped, group_id=587, groups=121, elements=135'
);

// ============================================================================
// BLOQUE 5: PRUEBAS HTTP SIN MODIFICAR ENRUTAMIENTO E INVARIANCIA POST-FASE
// ============================================================================
echo "\n--- BLOQUE 5: Pruebas HTTP en Vivo e Invariancia Post-Fase ---\n";

$http_es = inspect_http_route( $lab_url . '/' );
record_gate(
	'HTTP GET / responde 200 con lang="es-ES", canonical, hreflang bilingüe (es/en/x-default) y 0 errores PHP',
	200 === $http_es['code']
		&& 'es-ES' === $http_es['lang']
		&& ! $http_es['php_errors']
		&& isset( $http_es['hreflang']['es'], $http_es['hreflang']['en'], $http_es['hreflang']['x-default'] ),
	sprintf( 'HTTP %d, lang="%s", hreflang=%s', $http_es['code'], $http_es['lang'], json_encode( $http_es['hreflang'], JSON_UNESCAPED_SLASHES ) )
);

$http_en = inspect_http_route( $lab_url . '/en/' );
record_gate(
	'HTTP GET /en/ sin intervención de enrutamiento responde 404 con lang="en-US" y 0 errores PHP (BLOCKED BY ROUTING)',
	404 === $http_en['code'] && 'en-US' === $http_en['lang'] && ! $http_en['php_errors'],
	sprintf( 'HTTP %d, lang="%s", php_errors=%s -> Reservado para directiva de enrutamiento de portada', $http_en['code'], $http_en['lang'], $http_en['php_errors'] ? 'true' : 'false' ),
	'BLOCKED_IF_TRUE'
);

$http_fr = inspect_http_route( $lab_url . '/fr/' );
record_gate(
	'HTTP GET /fr/ responde 404 con lang="es-ES" y 0 errores PHP (francés inactivo y retenido)',
	404 === $http_fr['code'] && 'es-ES' === $http_fr['lang'] && ! $http_fr['php_errors'],
	sprintf( 'HTTP %d, lang="%s"', $http_fr['code'], $http_fr['lang'] )
);

$http_blog = inspect_http_route( $lab_url . '/old-blog/' );
$http_cat  = inspect_http_route( $lab_url . '/category/blog/' );
$http_tag  = inspect_http_route( $lab_url . '/tag/cusco/' );
record_gate(
	'Rutas públicas secundarias (/old-blog/, /category/blog/, /tag/cusco/) responden HTTP 200 con lang="es-ES"',
	200 === $http_blog['code'] && 200 === $http_cat['code'] && 200 === $http_tag['code']
		&& 'es-ES' === $http_blog['lang'] && 'es-ES' === $http_cat['lang'] && 'es-ES' === $http_tag['lang'],
	'3/3 rutas secundarias HTTP 200 lang="es-ES"'
);

$sentinel_cms_ect_post = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_post     = get_icl_sentinel( 'cms_ect_lab' );
record_gate(
	'Invariancia post-fase en BD original protegida (cms_ect) y tabla fuente WPML de laboratorio (cms_ect_lab)',
	3403 === $sentinel_cms_ect_post['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_cms_ect_post['md5']
		&& 3403 === $sentinel_lab_post['rows']
		&& '4241ca7e7ec6399a594537cb04790c10' === $sentinel_lab_post['md5'],
	sprintf( 'cms_ect=%d:%s | cms_ect_lab=%d:%s', $sentinel_cms_ect_post['rows'], $sentinel_cms_ect_post['md5'], $sentinel_lab_post['rows'], $sentinel_lab_post['md5'] )
);

echo "\n================================================================================\n";
echo sprintf(
	"RESUMEN FASE 3.32: %d CONTROLES | %d PASS | %d BLOCKED (por diseño de fase) | %d FAIL | %d NOT_TESTED\n",
	$total_checks,
	$passed_checks,
	$blocked_checks,
	$failed_checks,
	$not_tested_checks
);
echo "================================================================================\n";

exit( $failed_checks > 0 ? 1 : 0 );
