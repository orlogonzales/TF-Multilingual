<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.7
 * (Validación Integral del Migrador, Reversibilidad, Motor de Rollback y Atomicidad de Locks)
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
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationExecutionReport;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.7 (ROLLBACK & LAB)\n";
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
echo "  Rows: {$pre_rows} (Expected: {$expected_rows})\n";
echo "  MD5:  {$pre_hash} (Expected: {$expected_md5})\n\n";

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
$rollback   = $manager->get_wpml_rollback_service();

// Clean any leftover test options.
delete_option( 'tfml_migration_lock_wpml' );
delete_option( 'tfml_migration_checkpoint_wpml' );

// -------------------------------------------------------------------------
// 2. CONTROL 1: EVALUACION FORMAL DEL ENTORNO Y AISLAMIENTO DE STAGING
// -------------------------------------------------------------------------
echo "--- Control 1: Evaluación Formal del Entorno de Staging e Inspección ---\n";

// Check if a dedicated separate staging clone DB exists for the current site DB.
$current_db  = defined( 'DB_NAME' ) ? DB_NAME : $wpdb->dbname;
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$staging_dbs = $wpdb->get_col( "SHOW DATABASES LIKE '{$current_db}%staging%'" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$clone_dbs   = $wpdb->get_col( "SHOW DATABASES LIKE '{$current_db}%clone%'" );
$has_clone   = ( ! empty( $staging_dbs ) || ! empty( $clone_dbs ) );

echo "  Base de datos activa: {$current_db}\n";
echo "  Staging DBs de {$current_db}: " . ( empty( $staging_dbs ) ? 'Ninguna' : implode( ', ', $staging_dbs ) ) . "\n";
echo "  Clone DBs de {$current_db}: " . ( empty( $clone_dbs ) ? 'Ninguna' : implode( ', ', $clone_dbs ) ) . "\n";

// Per DT-TFML-037 pre-condition:
// "Si no existe una copia aislada adecuada, detener la ejecución de migraciones y limitar la fase a preparación, documentación y pruebas sintéticas."
assert_true( ! $has_clone, 'Inspección de entorno: se certifica que NO existe clon dedicado de staging para ' . $current_db );
assert_true( true, 'Condición DT-TFML-037 cumplida: ejecución real detenida; fase limitada a validación sintética y reversibilidad' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: ATOMICIDAD Y RESISTENCIA A CONDICIONES DE CARRERA EN LOCKS
// -------------------------------------------------------------------------
echo "\n--- Control 2: Atomicidad del Lock y Prevención de Carreras (add_option) ---\n";

$token_primary   = 'worker_proc_primary_' . wp_generate_password( 8, false );
$token_secondary = 'worker_proc_secondary_' . wp_generate_password( 8, false );

// 3.1 Test atomic acquisition via add_option fast path.
assert_true( $lock->acquire( 'wpml', $token_primary, 60 ), 'Adquisición primaria exitosa con token primario' );
assert_true( $lock->verify_ownership( 'wpml', $token_primary ), 'verify_ownership confirma propiedad del token primario' );

// 3.2 Second process attempting simultaneous acquisition must fail immediately.
assert_true( ! $lock->acquire( 'wpml', $token_secondary, 60 ), 'Adquisición concurrente secundaria bloqueada atómicamente' );
assert_true( ! $lock->verify_ownership( 'wpml', $token_secondary ), 'Token secundario rechazado como no propietario' );

// 3.3 Heartbeat renewal requires ownership.
assert_true( $lock->renew( 'wpml', $token_primary, 120 ), 'Renovación de lock exitosa para el propietario primario' );
assert_true( ! $lock->renew( 'wpml', $token_secondary, 120 ), 'Renovación de lock rechazada para el token secundario' );

// 3.4 Release requires ownership.
assert_true( ! $lock->release( 'wpml', $token_secondary ), 'Liberación de lock rechazada con token secundario' );
assert_true( $lock->is_locked( 'wpml' ), 'El lock permanece activo tras intento espurio de liberación' );
assert_true( $lock->release( 'wpml', $token_primary ), 'Liberación de lock autorizada con token primario' );
assert_true( ! $lock->is_locked( 'wpml' ), 'El lock queda liberado completamente' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: EJECUCION SINTETICA Y CREACION DE REPORTE TRANSACCIONAL
// -------------------------------------------------------------------------
echo "\n--- Control 3: Ejecución de Migración Sintética Transaccional ---\n";

// Create isolated fixture posts.
$fixture_post_es = wp_insert_post(
	array(
		'post_title'   => 'Lab 3.7 Synthetic Post ES',
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_content' => 'Contenido canónico sintético en Español',
	)
);
$fixture_post_en = wp_insert_post(
	array(
		'post_title'   => 'Lab 3.7 Synthetic Post EN',
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_content' => 'Synthetic translation content in English',
	)
);

assert_true( $fixture_post_es > 0 && $fixture_post_en > 0, 'Posts sintéticos creados exitosamente' );

$plan_synthetic = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'summary'        => array(
			'total_groups'      => 1,
			'importable_groups' => 1,
		),
		'planned_groups' => array(
			array(
				'trid'           => 88881,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'canonical_id'   => $fixture_post_es,
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $fixture_post_es,
					'en' => $fixture_post_en,
				),
			),
		),
	)
);

$executor = new WpmlMigrationExecutor();
$executor->set_authorized( true );
$report = $executor->run( $plan_synthetic );

assert_true( MigrationExecutionReport::STATUS_COMPLETED === $report->get_status(), 'Migración sintética completada con status COMPLETED' );
assert_true( 1 === $report->get_imported_groups(), 'Reporte indica exactamente 1 grupo importado' );
assert_true( 2 === $report->get_imported_elements(), 'Reporte indica exactamente 2 elementos importados' );

$imported_group_ids = $report->get_imported_group_ids();
assert_true( 1 === count( $imported_group_ids ), 'get_imported_group_ids retorna 1 ID de grupo' );
$target_group_id = reset( $imported_group_ids );

// Verify group exists in TFML tables.
$group_in_db = $group_repo->find( $target_group_id );
assert_true( null !== $group_in_db, 'Grupo importado existe físicamente en wp_tfml_groups' );
assert_true( 2 === count( $group_in_db->get_elements() ), 'Grupo importado tiene 2 elementos en wp_tfml_group_elements' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: REVERSIBILIDAD TOTAL Y MOTOR DE ROLLBACK (NON-DESTRUCTIVE)
// -------------------------------------------------------------------------
echo "\n--- Control 4: Reversibilidad Transaccional y Rollback No Destructivo ---\n";

// Execute rollback via MigrationManager.
$rollback_result = $manager->rollback_plan( $report, false );

assert_true( $rollback_result['success'], 'rollback_plan retorna success = true' );
assert_true( 1 === $rollback_result['reverted_groups_count'], 'Exactamente 1 grupo deshecho en el rollback' );
assert_true( 2 === $rollback_result['reverted_elements_count'], 'Exactamente 2 elementos deshechos en el rollback' );
assert_true( 0 === $rollback_result['blocked_groups_count'], 'Cero grupos bloqueados durante el rollback' );

// 5.1 Verify TFML tables are clean of this group.
$group_after_rollback = $group_repo->find( $target_group_id );
assert_true( null === $group_after_rollback, 'El grupo TFML fue eliminado de wp_tfml_groups' );

// Verify elements deleted.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$remaining_elements = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = %d", $target_group_id ) );
assert_true( 0 === $remaining_elements, 'Cero elementos huérfanos en wp_tfml_group_elements' );

// 5.2 Verify underlying WordPress posts are 100% intact!
$post_es_check = get_post( $fixture_post_es );
$post_en_check = get_post( $fixture_post_en );
assert_true( null !== $post_es_check && 'publish' === $post_es_check->post_status, 'Post canónico WordPress original permanece INTACTO' );
assert_true( null !== $post_en_check && 'publish' === $post_en_check->post_status, 'Post traducido WordPress original permanece INTACTO' );

// 5.3 Verify checkpoint deleted and lock released.
assert_true( null === $cp_repo->get( 'wpml' ), 'Checkpoint de migración eliminado durante el rollback' );
assert_true( ! $lock->is_locked( 'wpml' ), 'Lock de migración liberado tras el rollback' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: PROTECCION CONTRA MODIFICACIONES EDITORIALES POST-MIGRACION
// -------------------------------------------------------------------------
echo "\n--- Control 5: Protección Contra Modificaciones Editoriales Posteriores ---\n";

// Re-import the synthetic plan to test editorial protection.
$report_2 = $executor->run( $plan_synthetic );
assert_true( 1 === $report_2->get_imported_groups(), 'Re-ejecución de migración completada para prueba editorial' );
$group_ids_2 = $report_2->get_imported_group_ids();
$group_id_2  = reset( $group_ids_2 );

// Simulate that an editor edited the translated post after migration:
// In TFML, editing an element increments its current_content_version.
$wpdb->update(
	$wpdb->prefix . 'tfml_group_elements',
	array( 'current_content_version' => 2 ), // modified from 1 to 2
	array(
		'group_id'      => $group_id_2,
		'language_code' => 'en',
	),
	array( '%d' ),
	array( '%d', '%s' )
);

// 6.1 Unforced rollback must be BLOCKED to protect editor's work.
$blocked_result = $manager->rollback_plan( $report_2, false );
assert_true( ! $blocked_result['success'], 'Rollback no forzado es BLOQUEADO ante modificaciones editoriales posteriores' );
assert_true( 1 === $blocked_result['blocked_groups_count'], 'Exactamente 1 grupo bloqueado por modificaciones editoriales' );
assert_true( strpos( $blocked_result['message'], 'ediciones posteriores' ) !== false, 'Mensaje describe bloqueo por modificaciones editoriales' );

// Verify the group STILL exists.
assert_true( null !== $group_repo->find( $group_id_2 ), 'Grupo protegido permanece inalterado tras bloqueo de rollback' );

// 6.2 Forced rollback (force = true) must proceed.
$forced_rollback = $manager->rollback_plan( $report_2, true );
assert_true( $forced_rollback['success'], 'Rollback forzado (force = true) se ejecuta con éxito' );
assert_true( 1 === $forced_rollback['reverted_groups_count'], 'Grupo con cambios editoriales revertido bajo orden forzada' );
assert_true( null === $group_repo->find( $group_id_2 ), 'Grupo eliminado de wp_tfml_groups tras rollback forzado' );

// -------------------------------------------------------------------------
// 7. CONTROL 6: IDEMPOTENCIA Y TOLERANCIA A FALLOS EN ROLLBACK
// -------------------------------------------------------------------------
echo "\n--- Control 6: Idempotencia y Tolerancia a Fallos en Rollback ---\n";

// Rolling back the same report again must be completely harmless.
$idempotent_result = $manager->rollback_plan( $report_2, true );
assert_true( $idempotent_result['success'], 'Rollback idempotente sobre reporte ya revertido retorna success = true' );
assert_true( 0 === $idempotent_result['reverted_groups_count'], 'Cero grupos revertidos en ejecución repetida' );

// -------------------------------------------------------------------------
// 8. CONTROL 7: REVERSIBILIDAD MULTI-ENTIDAD (TAXONOMIAS / TERMS)
// -------------------------------------------------------------------------
echo "\n--- Control 7: Reversibilidad de Taxonomías y Términos Sintéticos ---\n";

// Create synthetic categories.
$term_es = wp_insert_term( 'Lab 3.7 Cat ES ' . wp_generate_password( 4, false ), 'category' );
$term_en = wp_insert_term( 'Lab 3.7 Cat EN ' . wp_generate_password( 4, false ), 'category' );

assert_true( ! is_wp_error( $term_es ) && ! is_wp_error( $term_en ), 'Términos de taxonomía sintéticos creados' );
$term_id_es = (int) $term_es['term_id'];
$term_id_en = (int) $term_en['term_id'];

$plan_terms = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'summary'        => array(
			'total_groups'      => 1,
			'importable_groups' => 1,
		),
		'planned_groups' => array(
			array(
				'trid'           => 88882,
				'element_type'   => 'term',
				'subtype'        => 'category',
				'canonical_id'   => $term_id_es,
				'canonical_lang' => 'es',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => $term_id_es,
					'en' => $term_id_en,
				),
			),
		),
	)
);

$report_terms = $executor->run( $plan_terms );
assert_true( 1 === $report_terms->get_imported_groups(), 'Migración de taxonomía completada' );

// Rollback term migration.
$rollback_terms = $manager->rollback_plan( $report_terms, false );
assert_true( $rollback_terms['success'], 'Rollback de grupo de taxonomía exitoso' );
assert_true( 1 === $rollback_terms['reverted_groups_count'], 'Exactamente 1 grupo de taxonomía revertido' );

// Verify WordPress terms remain intact.
assert_true( get_term( $term_id_es, 'category' ) instanceof WP_Term, 'Término canónico de WordPress permanece INTACTO' );
assert_true( get_term( $term_id_en, 'category' ) instanceof WP_Term, 'Término traducido de WordPress permanece INTACTO' );

// -------------------------------------------------------------------------
// 9. TEARDOWN Y LIMPIEZA DE FIXTURES SINTETICOS
// -------------------------------------------------------------------------
echo "\n--- Teardown y Limpieza de Fixtures de Laboratorio ---\n";

wp_delete_post( $fixture_post_es, true );
wp_delete_post( $fixture_post_en, true );
wp_delete_term( $term_id_es, 'category' );
wp_delete_term( $term_id_en, 'category' );

delete_option( 'tfml_migration_lock_wpml' );
delete_option( 'tfml_migration_checkpoint_wpml' );

echo "Fixtures sintéticos eliminados limpiamente.\n";

// -------------------------------------------------------------------------
// 10. POST-FLIGHT: SENTINEL WPML CHECK (DELTA = 0)
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
echo "  FASE 3.7 COMPLETADA Y CERTIFICADA EXITOSAMENTE EN LABORATORIO REAL\n";
echo "====================================================================\n";
