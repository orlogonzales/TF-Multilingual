<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.5
 * (Ejecutor Transaccional de Migraciones, Idempotencia y Recuperación ante Fallos)
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

// Bootstrap WordPress.
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/ExternalPluginIsolationGuard.php';

if ( ! defined( 'TFML_PLUGIN_FILE' ) ) {
	define( 'TFML_PLUGIN_FILE', dirname( __DIR__ ) . '/tf-multilingual.php' );
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

set_exception_handler(
	function( Throwable $e ) {
		echo "\nFATAL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
		exit( 1 );
	}
);

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\Exceptions\MigrationAuthorizationException;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.5 (EJECUTOR)\n";
echo "====================================================================\n\n";

$assertions_passed = 0;

function assert_true( bool $condition, string $message ): void {
	global $assertions_passed;
	if ( ! $condition ) {
		echo " [FAIL] {$message}\n";
		exit( 1 );
	}
	echo " [PASS] {$message}\n";
	++$assertions_passed;
}

// -------------------------------------------------------------------------
// PRE-FLIGHT: SENTINEL WPML CHECK
// -------------------------------------------------------------------------
$wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$sentinel_table = $wpdb->prefix . 'icl_translations';
$expected_rows  = 3403;
$expected_md5   = '4241ca7e7ec6399a594537cb04790c10';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pre_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pre_hash = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Pre-Flight:\n";
echo "  Rows: {$pre_rows} (Expected: 3403)\n";
echo "  MD5:  {$pre_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: WPML row count is exactly 3403' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: WPML checksum matches' );


// Activate isolation guard.
$isolation_guard = new ExternalPluginIsolationGuard();
$isolated_count  = $isolation_guard->isolate();
echo "External Plugin Isolation Guard active: isolated {$isolated_count} third-party hooks.\n\n";

// Initialize plugin.
$plugin = Plugin::get_instance();
$plugin->init();

$manager = $plugin->get_migration_manager();
$lock    = $manager->get_execution_lock();
$cp_repo = $manager->get_checkpoint_repository();

// Clean any leftover test options.
delete_option( 'tfml_migration_lock_wpml' );
delete_option( 'tfml_migration_checkpoint_wpml' );
TranslationGroupRepository::reset_transaction_depth();

// -------------------------------------------------------------------------
// BLOQUE 1: SEGURIDAD, GOBERNANZA Y AUTORIZACION
// -------------------------------------------------------------------------
echo "--- BLOQUE 1: SEGURIDAD, GOBERNANZA Y AUTORIZACION ---\n";

$executor = $manager->get_wpml_executor();

// 1.1 Verify executor defaults to UNAUTHORIZED.
assert_true( ! $executor->is_authorized(), 'Seguridad: El ejecutor transaccional inicia por defecto NO AUTORIZADO' );

// 1.2 Attempt execution while unauthorized -> MUST throw MigrationAuthorizationException.
$plan_test = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 99991,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'classification' => MigrationPlan::CLASSIFICATION_SKIPPED,
			),
		),
	)
);

$auth_blocked = false;
try {
	$executor->execute_batch( $plan_test );
} catch ( MigrationAuthorizationException $e ) {
	$auth_blocked = true;
}
assert_true( $auth_blocked, 'Seguridad: Ejecución sin autorización arroja MigrationAuthorizationException' );

// 1.3 Validation detects stale plan or non-existent Core element.
$executor->set_authorized( true );
assert_true( $executor->is_authorized(), 'Seguridad: Autorización explícita aceptada en entorno controlado' );

$stale_plan = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 99992,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => 99999999, // Does not exist!
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array( 'es' => 99999999 ),
			),
		),
	)
);

$stale_detected = false;
try {
	$executor->execute_batch( $stale_plan );
} catch ( MigrationPlanValidationException $e ) {
	$stale_detected = true;
}
assert_true( $stale_detected, 'Gobernanza: Plan obsoleto o con datos alterados es rechazado (MigrationPlanValidationException)' );

// -------------------------------------------------------------------------
// BLOQUE 2: CONTROL DE CONCURRENCIA (EXECUTION LOCK)
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 2: CONTROL DE CONCURRENCIA (LOCKS) ---\n";

// Acquire lock by Process 1.
$lock_acquired = $lock->acquire( 'wpml', 'proc_alpha', 300 );
assert_true( $lock_acquired, 'Concurrencia: Proceso Alfa adquiere el bloqueo exclusivo' );
assert_true( $lock->is_locked( 'wpml' ), 'Concurrencia: La fuente wpml se reporta como bloqueada' );

// Process 2 attempts execution -> MUST be rejected.
$concurrent_blocked = false;
try {
	$executor->execute_batch( $plan_test, 50, 'proc_beta' );
} catch ( MigrationConcurrencyException $e ) {
	$concurrent_blocked = true;
}
assert_true( $concurrent_blocked, 'Concurrencia: Proceso Beta es rechazado con MigrationConcurrencyException' );

// Release lock by Process 1.
$released = $lock->release( 'wpml', 'proc_alpha' );
assert_true( $released, 'Concurrencia: Proceso Alfa libera el bloqueo correctamente' );
assert_true( ! $lock->is_locked( 'wpml' ), 'Concurrencia: La fuente queda libre para ejecución' );

// -------------------------------------------------------------------------
// BLOQUE 3: TRANSACCIONALIDAD Y ROLLBACK ANTE INTERRUPCION
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 3: TRANSACCIONALIDAD Y ROLLBACK ANTE FALLOS ---\n";

// Create isolated test posts.
$test_post_es = wp_insert_post(
	array(
		'post_title'  => 'Test Migration Post ES',
		'post_type'   => 'post',
		'post_status' => 'publish',
	)
);
$test_post_en = wp_insert_post(
	array(
		'post_title'  => 'Test Migration Post EN',
		'post_type'   => 'post',
		'post_status' => 'publish',
	)
);
$test_post_es2 = wp_insert_post(
	array(
		'post_title'  => 'Test Migration Post ES 2',
		'post_type'   => 'post',
		'post_status' => 'publish',
	)
);

assert_true( $test_post_es > 0 && $test_post_en > 0 && $test_post_es2 > 0, 'Fixtures: Posts aislados creados en wp_posts' );

// Create a batch containing one valid group and one group that triggers an in-transaction collision.
$failing_plan = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 90001,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => $test_post_es,
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $test_post_es,
					'en' => $test_post_en,
				),
			),
			array(
				'trid'           => 90002,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => $test_post_es2,
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $test_post_es2,
					'en' => $test_post_en, // Collides with group 1!
				),
			),
		),
	)
);


// Count groups in tfml_groups before batch.
$groups_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );

// Try execute batch -> fails on second group.
$tx_failed = false;
try {
	// Temporarily bypass element_validator exists check for this test plan
	// to test database-level transactional rollback inside execute_batch.
	$executor->execute_batch( $failing_plan, 50, 'token_tx_test' );
} catch ( \Throwable $e ) {
	$tx_failed = true;
}

$groups_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
assert_true( $tx_failed, 'Transacción: El lote con fallo arrojó excepción esperada' );
assert_true( $groups_before === $groups_after, 'Transacción: Rollback ejecutado limpiamente (cero registros huérfanos del lote)' );

// Check that checkpoint captured failure.
$checkpoint = $cp_repo->get( 'wpml' );
assert_true( null !== $checkpoint, 'Checkpoint: Checkpoint de fallo persistido' );
assert_true( MigrationCheckpoint::STATUS_FAILED === $checkpoint->get_status(), 'Checkpoint: Estado es STATUS_FAILED' );

// Reset checkpoint for next test.
$cp_repo->delete( 'wpml' );

// -------------------------------------------------------------------------
// BLOQUE 4: IDEMPOTENCIA Y RE-EJECUCION SEGURA
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 4: IDEMPOTENCIA Y RE-EJECUCION SEGURA ---\n";

$valid_plan = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 90010,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => $test_post_es,
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $test_post_es,
					'en' => $test_post_en,
				),
			),
		),
	)
);

// First execution: imports the group.
$res1 = $executor->execute_batch( $valid_plan, 50, 'token_idem_lab' );
assert_true( 1 === count( $res1['batch_results'] ), 'Lote 1: Se procesó 1 grupo' );
assert_true( 'imported' === $res1['batch_results'][0]['status'], 'Lote 1: Grupo importado exitosamente' );
$imported_group_id = (int) $res1['batch_results'][0]['group_id'];
assert_true( $imported_group_id > 0, "Lote 1: ID de grupo asignado ({$imported_group_id})" );

// Second duplicate execution: MUST detect group and skip idempotently.
$fresh_cp = new MigrationCheckpoint( array( 'source' => 'wpml', 'total_items' => 1 ) );
$res2     = $executor->execute_batch( $valid_plan, 50, 'token_idem_lab', $fresh_cp );
assert_true( 'idempotent_skipped' === $res2['batch_results'][0]['status'], 'Lote 2: Idempotencia demostrada (idempotent_skipped)' );
assert_true( $imported_group_id === $res2['batch_results'][0]['group_id'], 'Lote 2: Reconcilia hacia el mismo ID de grupo' );

// Verify database count has not duplicated.
$element_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = %d",
		$imported_group_id
	)
);
assert_true( 2 === $element_count, 'Idempotencia: Exactamente 2 elementos registrados en TFML (cero duplicados)' );

// -------------------------------------------------------------------------
// BLOQUE 5: RECONCILIACION E INTEGRIDAD RELACIONAL
// -------------------------------------------------------------------------
echo "\n--- BLOQUE 5: RECONCILIACION E INTEGRIDAD RELACIONAL ---\n";

$reconciliation = $executor->reconcile( $valid_plan );
assert_true( $reconciliation['integrity_verified'], 'Reconciliación: Integridad relacional verificada como VERDADERA' );
assert_true( 1 === $reconciliation['tfml_imported_groups'], 'Reconciliación: 1 grupo importado verificado' );
assert_true( 2 === $reconciliation['tfml_imported_elements'], 'Reconciliación: 2 elementos importados verificados' );

// -------------------------------------------------------------------------
// LIMPIEZA DE FIXTURES DE LABORATORIO
// -------------------------------------------------------------------------
echo "\n--- LIMPIEZA DE FIXTURES DE LABORATORIO ---\n";

// Delete group from TFML tables.
$group_repo = new TranslationGroupRepository();
$group_repo->delete( $imported_group_id );

// Delete test posts.
wp_delete_post( $test_post_es, true );
wp_delete_post( $test_post_en, true );
wp_delete_post( $test_post_es2, true );


// Delete options.
delete_option( 'tfml_migration_lock_wpml' );
delete_option( 'tfml_migration_checkpoint_wpml' );

echo "Fixtures temporales de laboratorio eliminadas con éxito.\n";

// Restore isolation guard.
$isolation_guard->restore();
echo "External Plugin Isolation Guard restored.\n\n";

// -------------------------------------------------------------------------
// POST-FLIGHT: SENTINEL WPML INVARIANCE
// -------------------------------------------------------------------------
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$post_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$post_hash = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);


$delta_rows = $post_rows - $pre_rows;

echo "--- SENTINEL POST-FLIGHT VERIFICATION ---\n";
echo "Sentinel WPML Post-Flight:\n";
echo "  Rows: {$post_rows} (Delta: {$delta_rows})\n";
echo "  MD5:  {$post_hash}\n\n";

assert_true( 3403 === $post_rows, 'Invarianza WPML: Total de registros inalterado (3403)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_hash, 'Invarianza WPML: Checksum criptográfico inalterado (4241ca7e7ec6399a594537cb04790c10)' );

// Diagnostic check.
$diagnostic = $plugin->get_diagnostic_service();
$tables_rep = $diagnostic->get_tables_report();
$lang_rep   = $diagnostic->get_languages_report();
$rel_rep    = $diagnostic->get_relations_integrity_report();
$mod_rep    = $diagnostic->get_modules_report();

assert_true( 'good' === $tables_rep['status'], 'Diagnóstico Core: Tablas maestras en estado good' );
assert_true( 'good' === $lang_rep['status'], 'Diagnóstico Core: Registro de idiomas en estado good' );
assert_true( 0 === $rel_rep['orphaned_canonical_count'], 'Diagnóstico Core: Cero canonicals huérfanos' );
assert_true( 'good' === $mod_rep['status'], 'Diagnóstico Core: Módulos funcionales en estado good' );


echo "\n====================================================================\n";
echo "  RESUMEN DE VERIFICACION FASE 3.5\n";
echo "  Aserciones Exitosas: {$assertions_passed}\n";
echo "  Aserciones Fallidas: 0\n";
echo "====================================================================\n";
