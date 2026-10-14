<?php
/**
 * Script de Verificación — Fase 3.31 (DT-TFML-061).
 *
 * Blindaje Transaccional y Certificación de Recuperación de Traducciones Retenidas.
 *
 * Ejecuta:
 * 1. Controles preflight e invariancia estricta sobre cms_ect y cms_ect_lab.
 * 2. Verificación de compatibilidad histórica (120 grupos, 133 elementos, 11 manifiestos, 52 grupos bloqueados).
 * 3. Suite de 15 escenarios obligatorios de fallo inducido, concurrencia, promoción, reconciliación y rollback
 *    sobre una base de datos MySQL InnoDB efímera y aislada (cms_ect_lab_tx_test), eliminada al finalizar.
 * 4. Verificación HTTP en vivo sobre el laboratorio oficial (configurable vía TFML_LAB_URL).
 * 5. Controles post-verificación confirmando 0 mutaciones en cms_ect y cms_ect_lab.
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

// Bootstrap del entorno de laboratorio (D:\laragon\www\lab.ecoterra).
$lab_root = 'D:/laragon/www/lab.ecoterra';
if ( ! file_exists( $lab_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "ERROR: No se encontró wp-load.php en {$lab_root}\n" );
	exit( 1 );
}

require_once $lab_root . '/wp-load.php';

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

$lab_url = getenv( 'TFML_LAB_URL' ) ? rtrim( (string) getenv( 'TFML_LAB_URL' ), '/' ) : 'http://lab.ecoterra.test';

$total_checks  = 0;
$passed_checks = 0;
$failed_checks = 0;

/**
 * Registra el resultado de un control de verificación.
 *
 * @param string $label     Descripción del control.
 * @param bool   $condition Condición booleana evaluada.
 * @param string $detail    Detalle adicional opcional.
 * @return void
 */
function assert_check( string $label, bool $condition, string $detail = '' ): void {
	global $total_checks, $passed_checks, $failed_checks;
	++$total_checks;
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

echo "================================================================================\n";
echo "TF MULTILINGUAL — VERIFICADOR FASE 3.31 (DT-TFML-061)\n";
echo "Blindaje Transaccional y Certificación de Recuperación de Traducciones Retenidas\n";
echo "URL de Laboratorio configurada: {$lab_url}\n";
echo "================================================================================\n\n";

global $wpdb;

// ============================================================================
// BLOQUE 1: PREFLIGHT E INVARIANCIA DE ENTRADA (cms_ect y cms_ect_lab)
// ============================================================================
echo "--- BLOQUE 1: Preflight e Invariancia de Entrada ---\n";

$sentinel_cms_ect_pre = get_icl_sentinel( 'cms_ect' );
assert_check(
	'Centinela pre-fase en BD original protegida (cms_ect.etk_t_icl_translations)',
	3403 === $sentinel_cms_ect_pre['rows'] && '4241ca7e7ec6399a594537cb04790c10' === $sentinel_cms_ect_pre['md5'],
	sprintf( 'rows=%d, md5=%s', $sentinel_cms_ect_pre['rows'], $sentinel_cms_ect_pre['md5'] )
);

$sentinel_lab_pre = get_icl_sentinel( 'cms_ect_lab' );
assert_check(
	'Centinela pre-fase en BD de laboratorio (cms_ect_lab.etk_t_icl_translations)',
	3403 === $sentinel_lab_pre['rows'] && '4241ca7e7ec6399a594537cb04790c10' === $sentinel_lab_pre['md5'],
	sprintf( 'rows=%d, md5=%s', $sentinel_lab_pre['rows'], $sentinel_lab_pre['md5'] )
);

$lab_groups_pre   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$lab_elements_pre = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$lab_trid197_pre  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id IN (197, 8356, 8839)" );
$lab_dups_pre     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT element_type, element_id FROM {$wpdb->prefix}tfml_group_elements GROUP BY element_type, element_id HAVING COUNT(*) > 1) d" );

assert_check(
	'Estado oficial de cms_ect_lab intacto (120 grupos, 133 elementos, 0 duplicados)',
	120 === $lab_groups_pre && 133 === $lab_elements_pre && 0 === $lab_dups_pre,
	sprintf( 'groups=%d, elements=%d, duplicates=%d', $lab_groups_pre, $lab_elements_pre, $lab_dups_pre )
);

assert_check(
	'TRID 197 (IDs 197, 8356, 8839) NO importado en cms_ect_lab',
	0 === $lab_trid197_pre,
	sprintf( 'count=%d', $lab_trid197_pre )
);

$lab_retained_registry = new RetainedTranslationRegistry( $wpdb );
assert_check(
	'RetainedTranslationRegistry vacío en cms_ect_lab antes de pruebas',
	0 === count( $lab_retained_registry->get_all() ),
	'0 registros retenidos en cms_ect_lab'
);

$backup_file = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
$backup_hash = file_exists( $backup_file ) ? hash_file( 'sha256', $backup_file ) : '';
assert_check(
	'Backup SQL fuera del webroot intacto con SHA-256 verificado',
	'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_hash,
	$backup_hash
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
assert_check(
	'Motor transaccional InnoDB confirmado en etk_t_options y las 5 tablas etk_t_tfml_*',
	$all_innodb,
	sprintf( '%d tablas verificadas con motor InnoDB', count( $engines_rows ) )
);

$schema_version_lab = (string) get_option( SchemaManager::OPTION_SCHEMA_VERSION, '' );
assert_check(
	'Alcance DDL respetado: tfml_schema_version permanece en 1.0.0 sin migraciones DDL no autorizadas',
	'1.0.0' === $schema_version_lab,
	sprintf( 'tfml_schema_version=%s', $schema_version_lab )
);

// ============================================================================
// BLOQUE 2: COMPATIBILIDAD HISTÓRICA Y RECONCILIACIÓN EN cms_ect_lab
// ============================================================================
echo "\n--- BLOQUE 2: Compatibilidad Histórica en cms_ect_lab ---\n";

$checkpoint_repo_lab = new MigrationCheckpointRepository( $wpdb );
$cp_lab              = $checkpoint_repo_lab->get( 'wpml' );
assert_check(
	'Checkpoint histórico de cms_ect_lab intacto (completed, Lote 10: 18/18, last_trid=34840)',
	null !== $cp_lab && $cp_lab->is_completed() && 18 === $cp_lab->get_processed_items() && 34840 === $cp_lab->get_last_processed_trid(),
	null !== $cp_lab ? sprintf( 'status=%s, processed=%d/%d, last_trid=%d', $cp_lab->get_status(), $cp_lab->get_processed_items(), $cp_lab->get_total_items(), $cp_lab->get_last_processed_trid() ) : 'null'
);

$manifests_count_lab = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name LIKE 'tfml_migration_manifest_%'" );
assert_check(
	'Los 11 manifiestos históricos de los 10 lotes (Fases 3.22–3.24) permanecen intactos',
	11 === $manifests_count_lab,
	sprintf( 'manifests=%d', $manifests_count_lab )
);

$lang_registry_lab = new class() extends LanguageRegistry {
	public function __construct() {}
	public function is_active( string $code ): bool {
		return in_array( strtolower( $code ), array( 'es', 'en' ), true );
	}
};
$planner      = new WpmlMigrationPlanner( $wpdb, $lang_registry_lab );
$master_plan  = $planner->generate_plan( 0, 0, false );
$plan_summary = $master_plan->get_summary();

assert_check(
	'Plan maestro en cms_ect_lab clasifica 249 grupos (120 IMPORTABLE, 52 BLOCKED_BY_LANGUAGE, 53 ORPHAN, 24 SKIPPED)',
	249 === (int) $plan_summary['total_groups']
		&& 120 === (int) $plan_summary['importable_groups']
		&& 52 === (int) $plan_summary['blocked_language_groups']
		&& 53 === (int) $plan_summary['orphan_groups'],
	sprintf(
		'total=%d, importable=%d, blocked=%d, orphan=%d',
		(int) $plan_summary['total_groups'],
		(int) $plan_summary['importable_groups'],
		(int) $plan_summary['blocked_language_groups'],
		(int) $plan_summary['orphan_groups']
	)
);

$blocked_rejected_without_auth = false;
try {
	$master_plan->derive_partial_plan( array( 197 ), 'unauth-197' );
} catch ( InvalidArgumentException $e ) {
	$blocked_rejected_without_auth = true;
}
assert_check(
	'Los 52 grupos BLOCKED_BY_LANGUAGE siguen bloqueados por defecto sin autorización explícita',
	$blocked_rejected_without_auth,
	'derive_partial_plan(array(197)) rechazado sin allow_retained'
);

$derived_197 = $master_plan->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 197,
			'authorized_languages' => array( 'es', 'en' ),
		),
	),
	'dry-run-trid-197'
);
$g197        = $derived_197->get_planned_groups()[0] ?? array();
assert_check(
	'Derivación controlada de TRID 197 en memoria preserva invariantes (ES:197 canónico, EN:8356 activo, FR:8839 retenido)',
	MigrationPlan::CLASSIFICATION_PARTIAL_RETAINED === ( $g197['classification'] ?? '' )
		&& 197 === (int) ( $g197['canonical_id'] ?? 0 )
		&& 'es' === ( $g197['canonical_lang'] ?? '' )
		&& array( 'es' => 197, 'en' => 8356 ) === ( $g197['translations'] ?? array() )
		&& array( 'fr' => 8839 ) === ( $g197['pending_translations'] ?? array() ),
	'classification=PARTIAL_RETAINED, active={es:197,en:8356}, retained={fr:8839}'
);

$executor_lab = new WpmlMigrationExecutor( $wpdb );
$recon_lab    = $executor_lab->reconcile( $master_plan );
assert_check(
	'Reconciliación de los 120 grupos migrados en cms_ect_lab reporta 0 discrepancias e integridad verificada',
	true === $recon_lab['integrity_verified']
		&& 120 === $recon_lab['tfml_imported_groups']
		&& 133 === $recon_lab['tfml_imported_elements']
		&& empty( $recon_lab['discrepancies'] ),
	sprintf( 'groups=%d, elements=%d, discrepancies=%d', $recon_lab['tfml_imported_groups'], $recon_lab['tfml_imported_elements'], count( $recon_lab['discrepancies'] ) )
);

// ============================================================================
// BLOQUE 3: SUITE DE 15 ESCENARIOS TRANSACCIONALES EN BD AISLADA EFÍMERA
// ============================================================================
echo "\n--- BLOQUE 3: 15 Escenarios de Fallo Inducido y Concurrencia en BD Efímera (cms_ect_lab_tx_test) ---\n";

$tx_db_name = 'cms_ect_lab_tx_test';
$admin_conn = new mysqli( '127.0.0.1', 'root', '', '', 3306 );
$admin_conn->set_charset( 'utf8mb4' );
$admin_conn->query( "SET SESSION sql_mode = ''" );

try {
	$admin_conn->query( "DROP DATABASE IF EXISTS `{$tx_db_name}`" );
	$admin_conn->query( "CREATE DATABASE `{$tx_db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );

	// Crear tablas base InnoDB en la BD efímera clonando estructura exacta de cms_ect_lab sin usar dbDelta global.
	$admin_conn->select_db( $tx_db_name );
	$admin_conn->query(
		"CREATE TABLE `etk_t_options` (
			`option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`option_name` varchar(191) NOT NULL DEFAULT '',
			`option_value` longtext NOT NULL,
			`autoload` varchar(20) NOT NULL DEFAULT 'yes',
			PRIMARY KEY (`option_id`),
			UNIQUE KEY `option_name` (`option_name`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
	);
	$admin_conn->query(
		"CREATE TABLE `etk_t_posts` (
			`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`post_type` varchar(20) NOT NULL DEFAULT 'page',
			`post_status` varchar(20) NOT NULL DEFAULT 'publish',
			`post_title` text NOT NULL,
			PRIMARY KEY (`ID`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
	);
	$admin_conn->query(
		"CREATE TABLE `etk_t_icl_translations` (
			`translation_id` bigint(20) NOT NULL AUTO_INCREMENT,
			`element_type` varchar(60) NOT NULL DEFAULT 'post_page',
			`element_id` bigint(20) DEFAULT NULL,
			`trid` bigint(20) NOT NULL,
			`language_code` varchar(7) NOT NULL,
			`source_language_code` varchar(7) DEFAULT NULL,
			PRIMARY KEY (`translation_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
	);

	$tfml_tables = array(
		'etk_t_tfml_groups',
		'etk_t_tfml_group_elements',
		'etk_t_tfml_media_translations',
		'etk_t_tfml_strings',
		'etk_t_tfml_string_translations',
	);
	foreach ( $tfml_tables as $tt ) {
		$admin_conn->query( "CREATE TABLE `{$tx_db_name}`.`{$tt}` LIKE `cms_ect_lab`.`{$tt}`" );
	}

	// Insertar páginas sintéticas para TRID 197 (197, 8356, 8839) y TRID 500 (500, 501, 502).
	$admin_conn->query(
		"INSERT INTO `etk_t_posts` (`ID`, `post_type`, `post_status`, `post_title`) VALUES
		(197, 'page', 'publish', 'Inicio ES'),
		(8356, 'page', 'publish', 'Home EN'),
		(8839, 'page', 'publish', 'Accueil FR'),
		(500, 'page', 'publish', 'Contacto ES'),
		(501, 'page', 'publish', 'Contact EN'),
		(502, 'page', 'publish', 'Contact FR')"
	);
	$admin_conn->query(
		"INSERT INTO `etk_t_icl_translations` (`element_type`, `element_id`, `trid`, `language_code`, `source_language_code`) VALUES
		('post_page', 197, 197, 'es', NULL),
		('post_page', 8356, 197, 'en', 'es'),
		('post_page', 8839, 197, 'fr', 'es')"
	);

	$tx_db = new wpdb( 'root', '', $tx_db_name, '127.0.0.1' );
	$tx_db->set_prefix( 'etk_t_' );
	$tx_db->suppress_errors( true );

	$tx_schema = new class( $tx_db ) extends SchemaManager {
		public function is_installed(): bool {
			return true;
		}
	};

	// Validator ligado a $tx_db->prefix . 'posts'.
	$tx_validator = new class( $tx_db ) extends WordPressElementValidator {
		private wpdb $db;
		public function __construct( wpdb $db ) {
			$this->db = $db;
		}
		public function validate( string $element_type, string $subtype, int $element_id ): void {
			if ( 'post' === $element_type ) {
				$found = $this->db->get_var(
					$this->db->prepare( "SELECT ID FROM `{$this->db->prefix}posts` WHERE ID = %d LIMIT 1", $element_id )
				);
				if ( null === $found ) {
					throw \TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException::for_non_existent_entity( 'post', $element_id );
				}
			}
		}
		public function exists( string $element_type, string $subtype, int $element_id ): bool {
			try {
				$this->validate( $element_type, $subtype, $element_id );
				return true;
			} catch ( \Throwable $e ) {
				return false;
			}
		}
	};

	// Configurar idiomas activos (es, en) en LanguageRegistry e instancia de validación sobre $tx_db.
	$tx_lang_registry  = new class() extends LanguageRegistry {
		public function __construct() {}
		public function is_active( string $code ): bool {
			return in_array( strtolower( trim( $code ) ), array( 'es', 'en' ), true );
		}
		public function exists( string $code ): bool {
			return in_array( strtolower( trim( $code ) ), array( 'es', 'en' ), true );
		}
	};
	$tx_group_repo     = new TranslationGroupRepository( $tx_db, $tx_lang_registry, $tx_validator );
	$tx_retained_reg   = new RetainedTranslationRegistry( $tx_db );
	$tx_checkpoint_rep = new MigrationCheckpointRepository( $tx_db );
	$tx_lock           = new MigrationExecutionLock( $tx_db );
	$tx_rollback_svc   = new WpmlMigrationRollbackService( $tx_db, $tx_group_repo, $tx_checkpoint_rep, $tx_lock, $tx_retained_reg );

	$tx_executor = new WpmlMigrationExecutor(
		$tx_db,
		$tx_group_repo,
		$tx_lang_registry,
		$tx_checkpoint_rep,
		$tx_lock,
		$tx_validator,
		$tx_schema,
		$tx_retained_reg
	);
	$tx_executor->set_authorized( true );

	// Helper para contar filas reales en la BD efímera.
	$get_tx_counts = function () use ( $tx_db, $tx_retained_reg ): array {
		return array(
			'groups'    => (int) $tx_db->get_var( "SELECT COUNT(*) FROM `{$tx_db->prefix}tfml_groups`" ),
			'elements'  => (int) $tx_db->get_var( "SELECT COUNT(*) FROM `{$tx_db->prefix}tfml_group_elements`" ),
			'retained'  => count( $tx_retained_reg->get_all() ),
			'manifests' => (int) $tx_db->get_var( "SELECT COUNT(*) FROM `{$tx_db->prefix}options` WHERE `option_name` LIKE 'tfml_migration_manifest_%'" ),
		);
	};

	// Escenario 1: Fallo al crear tfml_groups -> RetainedTranslationRegistry queda vacío.
	$failing_group_repo_1 = new class( $tx_db, $tx_lang_registry, $tx_validator ) extends TranslationGroupRepository {
		public function create_group( string $element_type, string $subtype, ?int $initial_element_id = null, ?string $initial_language_code = null, bool $as_canonical = true, string $initial_fingerprint = '' ): \TF\Multilingual\Domain\Translation\TranslationGroup {
			throw new RuntimeException( 'Fallo inducido al insertar en tfml_groups' );
		}
	};
	$exec_sc1 = new WpmlMigrationExecutor( $tx_db, $failing_group_repo_1, $tx_lang_registry, $tx_checkpoint_rep, $tx_lock, $tx_validator, $tx_schema, $tx_retained_reg );
	$exec_sc1->set_authorized( true );
	$sc1_caught = false;
	try {
		$exec_sc1->execute_batch( $derived_197, 10, 'tx-sc1', null, $derived_197->get_fingerprint() );
	} catch ( MigrationExecutionException $e ) {
		$sc1_caught = str_contains( $e->getMessage(), 'Fallo inducido al insertar en tfml_groups' );
	}
	$c1 = $get_tx_counts();
	assert_check(
		'Escenario 1 (MySQL Real): Fallo al crear tfml_groups -> RetainedTranslationRegistry queda vacío',
		$sc1_caught && 0 === $c1['groups'] && 0 === $c1['elements'] && 0 === $c1['retained'],
		sprintf( 'groups=%d, elements=%d, retained=%d', $c1['groups'], $c1['elements'], $c1['retained'] )
	);

	// Escenario 2: Fallo al insertar tfml_group_elements activo -> grupo TFML y RetainedTranslationRegistry se revierten por ROLLBACK SQL.
	$tx_checkpoint_rep->delete( 'wpml' );
	$failing_group_repo_2 = new class( $tx_db, $tx_lang_registry, $tx_validator ) extends TranslationGroupRepository {
		public function add_translation( int $group_id, int $element_id, string $language_code, bool $is_canonical = false, int $source_version = 1, int $current_version = 1, string $fingerprint = '' ): \TF\Multilingual\Domain\Translation\TranslationElement {
			throw new RuntimeException( 'Fallo inducido al insertar traducción activa 8356 en tfml_group_elements' );
		}
	};
	$exec_sc2 = new WpmlMigrationExecutor( $tx_db, $failing_group_repo_2, $tx_lang_registry, $tx_checkpoint_rep, $tx_lock, $tx_validator, $tx_schema, $tx_retained_reg );
	$exec_sc2->set_authorized( true );
	$sc2_caught = false;
	try {
		$exec_sc2->execute_batch( $derived_197, 10, 'tx-sc2', null, $derived_197->get_fingerprint() );
	} catch ( MigrationExecutionException $e ) {
		$sc2_caught = str_contains( $e->getMessage(), 'Fallo inducido al insertar traducción activa 8356' );
	}
	$c2 = $get_tx_counts();
	assert_check(
		'Escenario 2 (MySQL Real): Fallo al insertar tfml_group_elements activo -> grupo TFML (197) y RetainedTranslationRegistry se revierten',
		$sc2_caught && 0 === $c2['groups'] && 0 === $c2['elements'] && 0 === $c2['retained'],
		sprintf( 'groups=%d, elements=%d, retained=%d', $c2['groups'], $c2['elements'], $c2['retained'] )
	);

	// Escenario 3: Fallo inducido al escribir RetainedTranslationRegistry -> grupo TFML y elementos activos (197, 8356) se revierten por ROLLBACK SQL.
	$tx_checkpoint_rep->delete( 'wpml' );
	$tx_retained_reg->set_simulate_write_failure( true );
	$sc3_caught = false;
	try {
		$tx_executor->execute_batch( $derived_197, 10, 'tx-sc3', null, $derived_197->get_fingerprint() );
	} catch ( MigrationExecutionException $e ) {
		$sc3_caught = str_contains( $e->getMessage(), 'RetainedTranslationRegistry' );
	}
	$tx_retained_reg->set_simulate_write_failure( false );
	$c3 = $get_tx_counts();
	assert_check(
		'Escenario 3 (MySQL Real): Fallo inducido al escribir RetainedTranslationRegistry -> grupo TFML y elementos activos se revierten',
		$sc3_caught && 0 === $c3['groups'] && 0 === $c3['elements'] && 0 === $c3['retained'],
		sprintf( 'groups=%d, elements=%d, retained=%d', $c3['groups'], $c3['elements'], $c3['retained'] )
	);

	// Escenario 4: Fallo al guardar manifiesto de ejecución -> lote completo (grupos, elementos, retenidos) se revierte sin datos huérfanos.
	$tx_checkpoint_rep->delete( 'wpml' );
	$tx_executor->set_simulate_manifest_failure( true );
	$sc4_caught = false;
	try {
		$tx_executor->execute_batch( $derived_197, 10, 'tx-sc4', null, $derived_197->get_fingerprint() );
	} catch ( MigrationExecutionException $e ) {
		$sc4_caught = str_contains( $e->getMessage(), 'manifiesto' );
	}
	$tx_executor->set_simulate_manifest_failure( false );
	$c4 = $get_tx_counts();
	assert_check(
		'Escenario 4 (MySQL Real): Fallo al guardar manifiesto de ejecución -> lote completo y registro retenido se revierten sin huérfanos',
		$sc4_caught && 0 === $c4['groups'] && 0 === $c4['elements'] && 0 === $c4['retained'] && 0 === $c4['manifests'],
		sprintf( 'groups=%d, elements=%d, retained=%d, manifests=%d', $c4['groups'], $c4['elements'], $c4['retained'], $c4['manifests'] )
	);

	// Escenario 5: Fallo al guardar checkpoint -> no queda desalineación entre grupos creados, registro retenido y checkpoint.
	$tx_checkpoint_rep->delete( 'wpml' );
	$tx_checkpoint_rep->set_simulate_write_failure( true );
	$sc5_caught = false;
	try {
		$tx_executor->execute_batch( $derived_197, 10, 'tx-sc5', null, $derived_197->get_fingerprint() );
	} catch ( MigrationExecutionException $e ) {
		$sc5_caught = str_contains( $e->getMessage(), 'checkpoint' );
	}
	$tx_checkpoint_rep->set_simulate_write_failure( false );
	$c5 = $get_tx_counts();
	assert_check(
		'Escenario 5 (MySQL Real): Fallo al guardar checkpoint -> reversión atómica sin desalineación entre grupos, retenidos y checkpoint',
		$sc5_caught && 0 === $c5['groups'] && 0 === $c5['elements'] && 0 === $c5['retained'],
		sprintf( 'groups=%d, elements=%d, retained=%d', $c5['groups'], $c5['elements'], $c5['retained'] )
	);

	// Escenario 6: Interrupción simulada antes de finalizar el lote -> reejecución posterior no duplica grupos ni registros retenidos.
	$tx_checkpoint_rep->delete( 'wpml' );
	$tx_executor->set_simulate_interrupt_before_commit( true );
	$sc6_interrupted = false;
	try {
		$tx_executor->execute_batch( $derived_197, 10, 'tx-sc6', null, $derived_197->get_fingerprint() );
	} catch ( MigrationExecutionException $e ) {
		$sc6_interrupted = str_contains( $e->getMessage(), 'Interrupción simulada antes de confirmar' );
	}
	$c6_mid = $get_tx_counts();

	$tx_executor->set_simulate_interrupt_before_commit( false );
	$tx_checkpoint_rep->delete( 'wpml' );
	$res_sc6 = $tx_executor->execute_batch( $derived_197, 10, 'tx-sc6-recovery', null, $derived_197->get_fingerprint() );
	$c6_end  = $get_tx_counts();
	$ret_197 = $tx_retained_reg->get( 'wpml', 197 );
	assert_check(
		'Escenario 6 (MySQL Real): Interrupción simulada antes de COMMIT revierte el lote y reejecución posterior importa sin duplicar',
		$sc6_interrupted
			&& 0 === $c6_mid['groups'] && 0 === $c6_mid['retained']
			&& 1 === $c6_end['groups'] && 2 === $c6_end['elements'] && 1 === $c6_end['retained']
			&& null !== $ret_197 && $tx_retained_reg->verify_record_integrity( $ret_197 ),
		sprintf( 'mid=(g:%d,r:%d) -> recovered=(g:%d,e:%d,r:%d)', $c6_mid['groups'], $c6_mid['retained'], $c6_end['groups'], $c6_end['elements'], $c6_end['retained'] )
	);

	// Escenario 7: Reejecución idempotente del mismo plan parcial con PARTIAL_RETAINED -> 0 duplicados activos, 0 duplicados retenidos, mismos timestamps y fingerprint.
	$tx_checkpoint_rep->delete( 'wpml' );
	$res_sc7     = $tx_executor->execute_batch( $derived_197, 10, 'tx-sc7-idempotent', null, $derived_197->get_fingerprint() );
	$c7          = $get_tx_counts();
	$ret_197_sc7 = $tx_retained_reg->get( 'wpml', 197 );
	assert_check(
		'Escenario 7 (MySQL Real): Reejecución idempotente de PARTIAL_RETAINED produce 0 duplicados y preserva timestamps/fingerprint',
		'idempotent_skipped' === ( $res_sc7['batch_results'][0]['status'] ?? '' )
			&& 1 === $c7['groups'] && 2 === $c7['elements'] && 1 === $c7['retained']
			&& null !== $ret_197 && null !== $ret_197_sc7
			&& $ret_197['created_at'] === $ret_197_sc7['created_at']
			&& $ret_197['fingerprint'] === $ret_197_sc7['fingerprint'],
		sprintf( 'status=%s, fingerprint=%s', $res_sc7['batch_results'][0]['status'] ?? '', substr( (string) ( $ret_197_sc7['fingerprint'] ?? '' ), 0, 16 ) )
	);

	// Escenario 8: Intento de registrar el mismo elemento retenido (8839) en otro grupo -> rechazo explícito sin alterar el primer registro.
	$sc8_rejected = false;
	try {
		$tx_retained_reg->register( 'wpml', 999, 999, 'post', 'page', array( 'fr' => 8839 ), 'fp-999' );
	} catch ( InvalidArgumentException $e ) {
		$sc8_rejected = true;
	}
	$ret_after_sc8 = $tx_retained_reg->get( 'wpml', 197 );
	assert_check(
		'Escenario 8 (MySQL Real): Intento de registrar el mismo elemento retenido (8839) en otro grupo es rechazado sin alterar TRID 197',
		$sc8_rejected && $ret_197_sc7 === $ret_after_sc8 && null === $tx_retained_reg->get( 'wpml', 999 ),
		'Rechazo explícito por unicidad de elemento retenido'
	);

	// Escenario 9: Intento de promover fr:8839 con fr inactivo -> rechazo sin cambios en tablas activas ni pérdida del registro retenido.
	$group_id_197 = (int) ( $ret_197_sc7['group_id'] ?? 0 );
	$sc9_rejected = false;
	try {
		$tx_retained_reg->promote_retained_translation( $group_id_197, 'fr', $tx_group_repo, $tx_lang_registry, $tx_validator );
	} catch ( InvalidArgumentException $e ) {
		$sc9_rejected = true;
	}
	$c9 = $get_tx_counts();
	assert_check(
		'Escenario 9 (MySQL Real): Intento de promover fr:8839 con fr inactivo es rechazado sin cambios en tablas activas ni pérdida de retenido',
		$sc9_rejected && 1 === $c9['groups'] && 2 === $c9['elements'] && 1 === $c9['retained'] && $tx_retained_reg->has_retained_element( 'post', 8839 ),
		sprintf( 'elements=%d, retained=%d', $c9['elements'], $c9['retained'] )
	);

	// Crear registro de lenguaje activo con FR habilitado para escenarios 10, 11 y 12.
	$tx_lang_with_fr = new class() extends LanguageRegistry {
		public function is_active( string $code ): bool {
			return in_array( strtolower( trim( $code ) ), array( 'es', 'en', 'fr' ), true );
		}
		public function exists( string $code ): bool {
			return in_array( strtolower( trim( $code ) ), array( 'es', 'en', 'fr' ), true );
		}
	};
	$tx_group_repo_fr = new TranslationGroupRepository( $tx_db, $tx_lang_with_fr, $tx_validator );

	// Escenario 10: Intento de promover elemento retenido inexistente en WordPress (post 8839 borrado temporalmente) -> rechazo sin cambios.
	$admin_conn->query( 'DELETE FROM `etk_t_posts` WHERE `ID` = 8839' );
	$sc10_rejected = false;
	try {
		$tx_retained_reg->promote_retained_translation( $group_id_197, 'fr', $tx_group_repo_fr, $tx_lang_with_fr, $tx_validator );
	} catch ( \Throwable $e ) {
		$sc10_rejected = str_contains( $e->getMessage(), '8839' );
	}
	$admin_conn->query( "INSERT INTO `etk_t_posts` (`ID`, `post_type`, `post_status`, `post_title`) VALUES (8839, 'page', 'publish', 'Accueil FR')" );
	$c10 = $get_tx_counts();
	assert_check(
		'Escenario 10 (MySQL Real): Intento de promover elemento retenido inexistente en WordPress es rechazado sin corromper el estado',
		$sc10_rejected && 2 === $c10['elements'] && 1 === $c10['retained'] && $tx_retained_reg->has_retained_element( 'post', 8839 ),
		sprintf( 'elements=%d, retained=%d', $c10['elements'], $c10['retained'] )
	);

	// Escenario 11: Intento de promover cuando existe colisión en tfml_group_elements O fallo de escritura en RetainedTranslationRegistry -> rechazo/reversión atómica.
	$other_group = $tx_group_repo_fr->create_group( 'post', 'page', 500, 'es', true );
	$tx_group_repo_fr->add_translation( (int) $other_group->get_id(), 8839, 'fr', false );
	$sc11_collision_rejected = false;
	try {
		$tx_retained_reg->promote_retained_translation( $group_id_197, 'fr', $tx_group_repo_fr, $tx_lang_with_fr, $tx_validator );
	} catch ( InvalidArgumentException $e ) {
		$sc11_collision_rejected = true;
	}
	$tx_group_repo_fr->delete( (int) $other_group->get_id() );

	// Además en Escenario 11: Fallo inducido al actualizar RetainedTranslationRegistry durante promote_retained_translation revierte la inserción en tfml_group_elements!
	$tx_retained_reg->set_simulate_write_failure( true );
	$sc11_tx_rolled_back = false;
	try {
		$tx_retained_reg->promote_retained_translation( $group_id_197, 'fr', $tx_group_repo_fr, $tx_lang_with_fr, $tx_validator );
	} catch ( MigrationExecutionException $e ) {
		$sc11_tx_rolled_back = true;
	}
	$tx_retained_reg->set_simulate_write_failure( false );
	$c11 = $get_tx_counts();
	assert_check(
		'Escenario 11 (MySQL Real): Colisión en tfml_group_elements y fallo en RetainedTranslationRegistry durante promoción son rechazados/revertidos atómicamente',
		$sc11_collision_rejected && $sc11_tx_rolled_back && 1 === $c11['groups'] && 2 === $c11['elements'] && 1 === $c11['retained'] && null === $tx_group_repo_fr->find_by_element( 'post', 8839 ),
		sprintf( 'collision_rejected=%s, tx_rollback=%s, elements=%d, retained=%d', $sc11_collision_rejected ? 'true' : 'false', $sc11_tx_rolled_back ? 'true' : 'false', $c11['elements'], $c11['retained'] )
	);

	// Escenario 12: Promoción exitosa tras activar fr -> 8839 pasa a tfml_group_elements y desaparece de forma atómica en RetainedTranslationRegistry.
	$promoted_el = $tx_retained_reg->promote_retained_translation( $group_id_197, 'fr', $tx_group_repo_fr, $tx_lang_with_fr, $tx_validator );
	$c12         = $get_tx_counts();
	$g197_after  = $tx_group_repo_fr->find( $group_id_197 );
	assert_check(
		'Escenario 12 (MySQL Real): Promoción exitosa tras activar fr -> 8839 pasa a tfml_group_elements (3 elementos) y se limpia de RetainedTranslationRegistry',
		8839 === $promoted_el->get_element_id()
			&& 'fr' === $promoted_el->get_language_code()
			&& 1 === $c12['groups'] && 3 === $c12['elements'] && 0 === $c12['retained']
			&& null !== $g197_after && null !== $g197_after->get_translation( 'fr' )
			&& ! $tx_retained_reg->has_retained_element( 'post', 8839 ),
		sprintf( 'elements=%d, retained=%d', $c12['elements'], $c12['retained'] )
	);

	// Preparar estado para Escenario 13: volver a dejar grupo 197 con 2 elementos activos (ES:197, EN:8356).
	$tx_group_repo_fr->remove_translation( $group_id_197, 'fr' );

	// Escenario 13: Reconciliación detecta discrepancia cuando falta registro retenido o existe registro retenido huérfano sin grupo TFML.
	$recon_missing_ret = $tx_executor->reconcile( $derived_197 );
	$missing_detected  = false === $recon_missing_ret['integrity_verified'] && ! empty( $recon_missing_ret['discrepancies'] );

	// Restaurar registro retenido de TRID 197 y agregar registro retenido huérfano (TRID 888 -> group_id 9999 inexistente).
	$tx_retained_reg->register( 'wpml', 197, $group_id_197, 'post', 'page', array( 'fr' => 8839 ), $derived_197->get_fingerprint() );
	$tx_retained_reg->register( 'wpml', 888, 9999, 'post', 'page', array( 'de' => 9888 ), 'fp-orphan' );
	$recon_orphan_ret = $tx_executor->reconcile( $derived_197 );
	$orphan_detected  = false === $recon_orphan_ret['integrity_verified'] && ! empty( $recon_orphan_ret['discrepancies'] );

	// Limpiar huérfano y verificar que la reconciliación vuelve a dar PASS (integrity_verified = true).
	$tx_retained_reg->remove( 'wpml', 888 );
	$recon_clean = $tx_executor->reconcile( $derived_197 );
	assert_check(
		'Escenario 13 (MySQL Real): Reconciliación detecta registro retenido faltante y registro retenido huérfano, y certifica estado limpio tras corrección',
		$missing_detected && $orphan_detected && true === $recon_clean['integrity_verified'],
		sprintf( 'missing_detected=%s, orphan_detected=%s, clean_verified=%s', $missing_detected ? 'true' : 'false', $orphan_detected ? 'true' : 'false', $recon_clean['integrity_verified'] ? 'true' : 'false' )
	);

	// Escenario 14: Rollback del lote limpia tanto el grupo activo como el registro retenido asociado (y revierte el DELETE si falla RetainedTranslationRegistry).
	$group_500 = $tx_group_repo->create_group( 'post', 'page', 500, 'es', true );
	$tx_group_repo->add_translation( (int) $group_500->get_id(), 501, 'en', false );
	$tx_retained_reg->register( 'wpml', 500, (int) $group_500->get_id(), 'post', 'page', array( 'fr' => 502 ), 'fp-500' );

	// Primero probamos fallo inducido de RetainedTranslationRegistry durante rollback -> el grupo activo NO debe borrarse (ROLLBACK SQL).
	$tx_retained_reg->set_simulate_write_failure( true );
	$rollback_tx_failed = false;
	try {
		$tx_rollback_svc->rollback_groups( array( $group_id_197 ), 'tx-sc6-recovery', false );
	} catch ( MigrationExecutionException $e ) {
		$rollback_tx_failed = true;
	}
	$tx_retained_reg->set_simulate_write_failure( false );
	$group_197_preserved_on_fail = null !== $tx_group_repo->find( $group_id_197 ) && null !== $tx_retained_reg->get_by_group_id( $group_id_197 );

	// Ahora ejecutamos rollback normal del grupo 197.
	$rb_res_14 = $tx_rollback_svc->rollback_groups( array( $group_id_197 ), 'tx-sc6-recovery', false );
	assert_check(
		'Escenario 14 (MySQL Real): Rollback limpia atómicamente el grupo activo y su registro retenido (y revierte el DELETE si falla el registro retenido)',
		$rollback_tx_failed && $group_197_preserved_on_fail
			&& true === $rb_res_14['success'] && 1 === $rb_res_14['reverted_groups_count']
			&& null === $tx_group_repo->find( $group_id_197 )
			&& null === $tx_retained_reg->get_by_group_id( $group_id_197 ),
		sprintf( 'reverted_groups=%d, reverted_elements=%d', $rb_res_14['reverted_groups_count'], $rb_res_14['reverted_elements_count'] )
	);

	// Escenario 15: Rollback repetido sobre grupo ya revertido es idempotente y no borra otros registros retenidos (TRID 500).
	$rb_res_15 = $tx_rollback_svc->rollback_groups( array( $group_id_197 ), 'tx-sc6-recovery', false );
	assert_check(
		'Escenario 15 (MySQL Real): Rollback repetido sobre grupo ya revertido es idempotente y preserva intactos otros grupos y registros retenidos (TRID 500)',
		true === $rb_res_15['success']
			&& 0 === $rb_res_15['reverted_groups_count']
			&& null !== $tx_group_repo->find( (int) $group_500->get_id() )
			&& null !== $tx_retained_reg->get_by_group_id( (int) $group_500->get_id() )
			&& $tx_retained_reg->has_retained_element( 'post', 502 ),
		'Idempotencia de rollback verificada y TRID 500 intacto'
	);

	// Control adicional de concurrencia sobre BD efímera: bloqueo atómico INSERT IGNORE en etk_t_options.
	$tx_lock->force_release( 'wpml' );
	$lock_a = $tx_lock->acquire( 'wpml', 'token-A', 60 );
	$lock_b = $tx_lock->acquire( 'wpml', 'token-B', 60 );
	$own_a  = $tx_lock->verify_ownership( 'wpml', 'token-A' );
	$own_b  = $tx_lock->verify_ownership( 'wpml', 'token-B' );
	$tx_lock->release( 'wpml', 'token-A' );
	assert_check(
		'Control de concurrencia (MySQL Real): MigrationExecutionLock atómico impide adquisición concurrente y valida fencing token',
		true === $lock_a && false === $lock_b && true === $own_a && false === $own_b && ! $tx_lock->is_locked( 'wpml' ),
		'lock_a=true, lock_b=false, own_a=true, own_b=false'
	);
} finally {
	$admin_conn->query( "DROP DATABASE IF EXISTS `{$tx_db_name}`" );
	$db_still_exists = $admin_conn->query( "SHOW DATABASES LIKE '{$tx_db_name}'" );
	$dropped_ok      = false !== $db_still_exists && 0 === $db_still_exists->num_rows;
	$admin_conn->close();

	assert_check(
		'Base de datos efímera cms_ect_lab_tx_test eliminada limpiamente tras la suite de pruebas',
		$dropped_ok,
		'DROP DATABASE cms_ect_lab_tx_test verificado'
	);
}

// ============================================================================
// BLOQUE 4: VERIFICACIÓN HTTP EN VIVO SOBRE EL LABORATORIO
// ============================================================================
echo "\n--- BLOQUE 4: Verificación HTTP en Vivo ({$lab_url}) ---\n";

$http_routes = array(
	array(
		'path'          => '/',
		'expected_code' => 200,
		'expected_lang' => 'es-ES',
	),
	array(
		'path'          => '/old-blog/',
		'expected_code' => 200,
		'expected_lang' => 'es-ES',
	),
	array(
		'path'          => '/category/blog/',
		'expected_code' => 200,
		'expected_lang' => 'es-ES',
	),
	array(
		'path'          => '/tag/cusco/',
		'expected_code' => 200,
		'expected_lang' => 'es-ES',
	),
	array(
		'path'          => '/en/',
		'expected_code' => 404,
		'expected_lang' => 'en-US',
	),
	array(
		'path'          => '/fr/',
		'expected_code' => 404,
		'expected_lang' => 'es-ES',
	),
);

foreach ( $http_routes as $route ) {
	$url  = $lab_url . $route['path'];
	$ch   = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => array( 'Cache-Control: no-cache' ),
		)
	);
	$body = (string) curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$lang_match = '';
	if ( preg_match( '/<html[^>]*\blang=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$lang_match = $m[1];
	}

	$ok = ( $code === $route['expected_code'] )
		&& ( null === $route['expected_lang'] || $lang_match === $route['expected_lang'] );

	assert_check(
		sprintf( 'Ruta HTTP %s responde %d%s', $route['path'], $route['expected_code'], null !== $route['expected_lang'] ? " con lang=\"{$route['expected_lang']}\"" : ' (TRID 197 no importado)' ),
		$ok,
		sprintf( 'HTTP %d, lang="%s"', $code, $lang_match )
	);
}

// ============================================================================
// BLOQUE 5: INVARIANCIA POST-VERIFICACIÓN (cms_ect y cms_ect_lab)
// ============================================================================
echo "\n--- BLOQUE 5: Invariancia Post-Verificación ---\n";

$sentinel_cms_ect_post = get_icl_sentinel( 'cms_ect' );
assert_check(
	'Invariancia post-fase en BD original protegida (cms_ect.etk_t_icl_translations)',
	3403 === $sentinel_cms_ect_post['rows'] && '4241ca7e7ec6399a594537cb04790c10' === $sentinel_cms_ect_post['md5'],
	sprintf( 'rows=%d, md5=%s', $sentinel_cms_ect_post['rows'], $sentinel_cms_ect_post['md5'] )
);

$sentinel_lab_post = get_icl_sentinel( 'cms_ect_lab' );
assert_check(
	'Invariancia post-fase en BD de laboratorio (cms_ect_lab.etk_t_icl_translations)',
	3403 === $sentinel_lab_post['rows'] && '4241ca7e7ec6399a594537cb04790c10' === $sentinel_lab_post['md5'],
	sprintf( 'rows=%d, md5=%s', $sentinel_lab_post['rows'], $sentinel_lab_post['md5'] )
);

$lab_groups_post   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$lab_elements_post = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$lab_trid197_post  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id IN (197, 8356, 8839)" );
$lab_retained_post = count( ( new RetainedTranslationRegistry( $wpdb ) )->get_all() );

assert_check(
	'Invariancia post-fase en tablas TFML de cms_ect_lab (120 grupos, 133 elementos, 0 TRID 197, 0 retenidos)',
	120 === $lab_groups_post && 133 === $lab_elements_post && 0 === $lab_trid197_post && 0 === $lab_retained_post,
	sprintf( 'groups=%d, elements=%d, trid197=%d, retained=%d', $lab_groups_post, $lab_elements_post, $lab_trid197_post, $lab_retained_post )
);

echo "\n================================================================================\n";
echo sprintf( "RESUMEN FASE 3.31: %d/%d PASS (%d FAIL)\n", $passed_checks, $total_checks, $failed_checks );
echo "================================================================================\n";

exit( $failed_checks > 0 ? 1 : 0 );
