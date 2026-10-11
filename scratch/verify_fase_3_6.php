<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.6
 * (Endurecimiento del Ejecutor, Atomicidad, Fencing Tokens y Pruebas de Recuperación)
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
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\Exceptions\MigrationAuthorizationException;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationExecutionReport;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.6 (ENDURECIMIENTO)\n";
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
// 1. PRE-FLIGHT: SENTINEL WPML CHECK
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

$manager    = $plugin->get_migration_manager();
$lock       = $manager->get_execution_lock();
$cp_repo    = $manager->get_checkpoint_repository();
$group_repo = new TranslationGroupRepository( $wpdb, $plugin->get_language_registry() );

// Clean any leftover test options.
delete_option( 'tfml_migration_lock_wpml' );
delete_option( 'tfml_migration_checkpoint_wpml' );

// -------------------------------------------------------------------------
// 2. CONCURRENCIA, EXPIRACION Y FENCING TOKENS
// -------------------------------------------------------------------------
echo "--- Control 1: Concurrencia, Renovacion Segura y Fencing Tokens ---\n";

$token_a = 'fencing_worker_alpha';
$token_b = 'fencing_worker_beta';

// 2.1 Acquire and verify ownership.
assert_true( $lock->acquire( 'wpml', $token_a, 60 ), 'Worker Alpha adquiere lock exitosamente' );
assert_true( $lock->verify_ownership( 'wpml', $token_a ), 'verify_ownership confirma propiedad para Worker Alpha' );
assert_true( ! $lock->verify_ownership( 'wpml', $token_b ), 'verify_ownership rechaza Worker Beta (no propietario)' );

// 2.2 Renewal.
assert_true( $lock->renew( 'wpml', $token_a, 120 ), 'renew extiende TTL correctamente para Worker Alpha' );
assert_true( ! $lock->renew( 'wpml', $token_b, 120 ), 'renew rechaza renovar lock para proceso no propietario' );

// 2.3 Expiration fencing.
update_option(
	'tfml_migration_lock_wpml',
	array(
		'execution_id' => $token_a,
		'source'       => 'wpml',
		'acquired_at'  => gmdate( 'Y-m-d H:i:s', time() - 300 ),
		'expires_at'   => time() - 10, // Expired 10s ago.
	),
	false
);
assert_true( ! $lock->verify_ownership( 'wpml', $token_a ), 'verify_ownership detecta expiración de fencing token' );
assert_true( ! $lock->renew( 'wpml', $token_a, 60 ), 'renew rechaza extender lock ya expirado' );

// Release test lock.
$lock->force_release( 'wpml' );
assert_true( ! $lock->is_locked( 'wpml' ), 'Lock liberado limpiamente' );

// -------------------------------------------------------------------------
// 3. CRIPTOGRAFIA Y HUELLA DIGITAL DEL PLAN (FINGERPRINTING)
// -------------------------------------------------------------------------
echo "\n--- Control 2: Determinismo e Inmutabilidad de Huellas Digitales (Fingerprints) ---\n";

$test_plan_data = array(
	'source'         => 'wpml',
	'status'         => MigrationPlan::STATUS_READY,
	'summary'        => array(
		'total_groups'     => 1,
		'importable_groups'=> 1,
	),
	'planned_groups' => array(
		array(
			'trid'           => 99991,
			'element_type'   => 'post',
			'subtype'        => 'post',
			'canonical_id'   => 1,
			'canonical_lang' => 'es',
			'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
			'translations'   => array( 'es' => 1 ),
		),
	),
);

$plan_original = new MigrationPlan( $test_plan_data );
$fp1           = $plan_original->get_fingerprint();
$plan_clone    = new MigrationPlan( $test_plan_data );
$fp2           = $plan_clone->get_fingerprint();

assert_true( 64 === strlen( $fp1 ), 'get_fingerprint genera hash criptográfico SHA-256 de 64 caracteres' );
assert_true( $fp1 === $fp2, 'get_fingerprint es estrictamente determinista ante planes idénticos' );
assert_true( $plan_original->verify_fingerprint( $fp1 ), 'verify_fingerprint valida positivamente la huella calculada' );
assert_true( ! $plan_original->verify_fingerprint( 'tampered_hash_00000000000000000000000000000000000000000000000000000000' ), 'verify_fingerprint rechaza huella alterada' );

// -------------------------------------------------------------------------
// 4. DETECCION DE PLANES OBSOLETOS / ALTERACIONES (POST EN TRASH Y COLISIONES)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Detección de Planes Obsoletos (Trash y Colisiones Sobrevenidas) ---\n";

// Create isolated fixture posts.
$fixture_post_1 = wp_insert_post(
	array(
		'post_title'   => 'Lab 3.6 Fixture Post ES',
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_content' => 'Test content ES',
	)
);
$fixture_post_2 = wp_insert_post(
	array(
		'post_title'   => 'Lab 3.6 Fixture Post EN',
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_content' => 'Test content EN',
	)
);

assert_true( $fixture_post_1 > 0 && $fixture_post_2 > 0, 'Posts de prueba en laboratorio creados exitosamente' );

$executor = new WpmlMigrationExecutor();
$executor->set_authorized( true );

// 4.1 Test trash detection.
wp_trash_post( $fixture_post_1 );

$plan_trash = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 99992,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => $fixture_post_1,
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $fixture_post_1,
					'en' => $fixture_post_2,
				),
			),
		),
	)
);

$trash_validation = $executor->validate_plan( $plan_trash );
assert_true( ! $trash_validation['valid'], 'validate_plan detecta y rechaza post enviado a la papelera (trash)' );
assert_true( strpos( $trash_validation['errors'][0], 'papelera (trash)' ) !== false, 'Mensaje de error especifica estado trash' );

// Restore post from trash.
wp_untrash_post( $fixture_post_1 );

// 4.2 Test post-planning partial collision.
// Assign fixture_post_1 to an independent TFML group beforehand.
$conflict_group = $group_repo->create_group( 'post', 'post', $fixture_post_1, 'es', true );

$plan_collision = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 99993,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => $fixture_post_1,
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $fixture_post_1,
					'en' => $fixture_post_2,
				),
			),
		),
	)
);

$collision_validation = $executor->validate_plan( $plan_collision );
assert_true( ! $collision_validation['valid'], 'validate_plan detecta colisión relacional sobrevenida tras la planificación' );
assert_true( strpos( $collision_validation['errors'][0], 'Colisión relacional sobrevenida' ) !== false, 'Mensaje de error especifica colisión detectada' );

// Clean up conflict group.
$wpdb->delete( $wpdb->prefix . 'tfml_group_elements', array( 'group_id' => $conflict_group->get_id() ) );
$wpdb->delete( $wpdb->prefix . 'tfml_groups', array( 'id' => $conflict_group->get_id() ) );

// -------------------------------------------------------------------------
// 5. PRUEBA DE RECUPERACION ANTE INTERRUPCION POST-COMMIT (ATOMICIDAD)
// -------------------------------------------------------------------------
echo "\n--- Control 4: Recuperación ante Interrupción Post-COMMIT (Cero Duplicados) ---\n";

// Scenario: Group was committed in TFML tables, but execution halted before saving checkpoint.
$plan_valid = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'summary'        => array(
			'importable_groups' => 1,
			'pending_elements'  => 0,
		),
		'planned_groups' => array(
			array(
				'trid'           => 99994,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => $fixture_post_1,
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $fixture_post_1,
					'en' => $fixture_post_2,
				),
			),
		),
	)
);

// First execution: initial import.
$exec_token = 'token_lab_recovery';
$res1       = $executor->execute_batch( $plan_valid, 50, $exec_token );
assert_true( 1 === count( $res1['batch_results'] ), 'Lote 1 ejecutado con 1 grupo procesado' );
assert_true( 'imported' === $res1['batch_results'][0]['status'], 'Grupo importado en la primera pasada' );
$imported_group_id = (int) $res1['batch_results'][0]['group_id'];

// Simulate crash: rewind checkpoint to TRID 0 as if option was never saved after MySQL COMMIT.
$rewound_cp = new MigrationCheckpoint(
	array(
		'source'              => 'wpml',
		'total_items'         => 1,
		'processed_items'     => 0,
		'last_processed_trid' => 0,
		'status'              => MigrationCheckpoint::STATUS_PENDING,
	)
);
$cp_repo->save( $rewound_cp );

// Count rows in TFML tables right now.
$groups_count_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}tfml_groups`" );
$elements_count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}tfml_group_elements`" );

// Resume execution from rewound checkpoint.
$res2 = $executor->execute_batch( $plan_valid, 50, $exec_token );

assert_true( $res2['finished'], 'Ejecutor completa lote tras recuperación' );
assert_true( 'idempotent_skipped' === $res2['batch_results'][0]['status'], 'Grupo ya persistido es reconocido como idempotent_skipped' );
assert_true( $imported_group_id === $res2['batch_results'][0]['group_id'], 'ID de grupo preservado exactamente sin re-crear registros' );

$groups_count_after   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}tfml_groups`" );
$elements_count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}tfml_group_elements`" );

assert_true( $groups_count_before === $groups_count_after, 'Cero grupos duplicados en tfml_groups tras reanudación post-COMMIT' );
assert_true( $elements_count_before === $elements_count_after, 'Cero elementos duplicados en tfml_group_elements tras reanudación' );

// -------------------------------------------------------------------------
// 6. RECONCILIACION PROFUNDA DE INTEGRIDAD
// -------------------------------------------------------------------------
echo "\n--- Control 5: Reconciliación Profunda de Integridad Relacional ---\n";

$reconciliation = $executor->reconcile( $plan_valid );
assert_true( $reconciliation['integrity_verified'], 'reconcile valida integridad 100% satisfactoria' );
assert_true( 1 === $reconciliation['tfml_imported_groups'], 'Grupos importados reconciliados: 1' );
assert_true( 2 === $reconciliation['tfml_imported_elements'], 'Elementos importados reconciliados: 2' );
assert_true( empty( $reconciliation['discrepancies'] ), 'Lista de discrepancias estrictamente vacía' );

// Mismatched canonical plan test.
$plan_tampered = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'summary'        => array(
			'importable_groups' => 1,
			'pending_elements'  => 0,
		),
		'planned_groups' => array(
			array(
				'trid'           => 99994,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => 999999, // Tampered canonical ID!
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $fixture_post_1,
					'en' => $fixture_post_2,
				),
			),
		),
	)
);

$reconciliation_tampered = $executor->reconcile( $plan_tampered );
assert_true( ! $reconciliation_tampered['integrity_verified'], 'reconcile detecta canónico alterado en el plan' );
assert_true( ! empty( $reconciliation_tampered['discrepancies'] ), 'reconcile reporta detalle de la discrepancia encontrada' );

// -------------------------------------------------------------------------
// 7. TEARDOWN Y LIMPIEZA TOTAL DE FIXTURES
// -------------------------------------------------------------------------
echo "\n--- Limpieza de Fixtures de Laboratorio ---\n";

// Delete TFML test group.
$wpdb->delete( $wpdb->prefix . 'tfml_group_elements', array( 'group_id' => $imported_group_id ) );
$wpdb->delete( $wpdb->prefix . 'tfml_groups', array( 'id' => $imported_group_id ) );

// Delete test posts.
wp_delete_post( $fixture_post_1, true );
wp_delete_post( $fixture_post_2, true );

// Clear test options.
delete_option( 'tfml_migration_lock_wpml' );
delete_option( 'tfml_migration_checkpoint_wpml' );

echo "Fixtures eliminados y opciones temporales limpiadas.\n";

// -------------------------------------------------------------------------
// 8. POST-FLIGHT: SENTINEL WPML CHECK (DELTA = 0)
// -------------------------------------------------------------------------
echo "\n--- Sentinel WPML Post-Flight ---\n";

$post_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "  Rows: {$post_rows} (Expected: {$pre_rows})\n";
echo "  MD5:  {$post_hash} (Expected: {$pre_hash})\n\n";

assert_true( $pre_rows === $post_rows, 'Sentinel post-flight: WPML row count intact (Delta = 0)' );
assert_true( $pre_hash === $post_hash, 'Sentinel post-flight: WPML checksum strictly intact (Delta = 0)' );

echo "====================================================================\n";
echo "  RESULTADO: {$assertions_passed}/{$assertions_passed} VERIFICACIONES PASS\n";
echo "  FASE 3.6 COMPLETADA Y CERTIFICADA EXITOSAMENTE EN LABORATORIO REAL\n";
echo "====================================================================\n";
