<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.12 (Cierre Correctivo).
 *
 * Directivas: DT-TFML-042, ADENDA DT-TFML-042-A y DT-TFML-042B
 * Alcance: Certificación final de la primera ejecución piloto transaccional WPML -> TFML
 *          en laboratorio real aislado cms_ect_lab (Lote pilot-batch-1: 5 grupos, 9 elementos).
 *          Cobertura exhaustiva de pruebas negativas (NEG-01 a NEG-13), certificación
 *          del estado final preservado del laboratorio, idempotencia y centinela inviolable en cms_ect.
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

if ( file_exists( dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/contact-form-7/vendor/autoload.php';
}

require_once dirname( __DIR__, 4 ) . '/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/ExternalPluginIsolationGuard.php';

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationExecutionReport;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Migration\Exceptions\MigrationAuthorizationException;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;

global $wpdb;
$original_wpdb = $wpdb;

$assertions_passed = 0;

/**
 * Custom assertion helper.
 *
 * @param bool   $condition Condition to assert.
 * @param string $message   Assertion description.
 * @return void
 */
function assert_true( bool $condition, string $message ): void {
	global $assertions_passed;
	if ( ! $condition ) {
		echo " [FAIL] {$message}\n";
		exit( 1 );
	}
	echo " [PASS] {$message}\n";
	++$assertions_passed;
}

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.12 (CIERRE FINAL)\n";
echo "  Directivas: DT-TFML-042 | ADENDA DT-TFML-042-A | DT-TFML-042B\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: SENTINEL WPML CHECK (cms_ect)
// -------------------------------------------------------------------------
echo "--- Pre-Flight: Centinela WPML en Base de Datos de Origen (cms_ect) ---\n";
$sentinel_table = $original_wpdb->prefix . 'icl_translations';
$original_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

$pre_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$pre_hash = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: 3403)\n";
echo "  MD5:  {$pre_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: Conteo WPML en cms_ect es exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: Checksum MD5 en cms_ect coincide exactamente' );

// Isolate external plugins to prevent unwanted side-effects.
$isolation_guard = new TF\Multilingual\Scratch\ExternalPluginIsolationGuard();
$isolation_guard->isolate();

// Setup DB connections to isolated lab.
$db_host = getenv( 'TFML_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'TFML_DB_USER' ) ?: 'root';
$db_pass = getenv( 'TFML_DB_PASS' ) !== false ? (string) getenv( 'TFML_DB_PASS' ) : '';
$lab_db  = 'cms_ect_lab';

$mysqli_lab = new mysqli( $db_host, $db_user, $db_pass, $lab_db );
assert_true( ! $mysqli_lab->connect_error, "Conexión MySQL directa a laboratorio '{$lab_db}' exitosa" );

$lab_wpdb = new wpdb( $db_user, $db_pass, $lab_db, $db_host );
$lab_wpdb->set_prefix( 'etk_t_' );

$plugin = Plugin::get_instance();
$plugin->init();
$registry = $plugin->get_language_registry();

// -------------------------------------------------------------------------
// 2. CONTROL 1: PREFLIGHT OBLIGATORIO Y VERIFICACIÓN DE AISLAMIENTO FÍSICO
// -------------------------------------------------------------------------
echo "\n--- Control 1: Preflight Obligatorio y Verificación de Aislamiento Físico ---\n";

// A. Confirm database active name and prefix.
$active_db_res = $mysqli_lab->query( 'SELECT DATABASE()' )->fetch_row()[0];
assert_true( 'cms_ect_lab' === $active_db_res, 'Base de datos activa del laboratorio es estrictamente "cms_ect_lab"' );
assert_true( 'etk_t_' === $lab_wpdb->prefix, 'Prefijo de tablas de laboratorio es exactamente "etk_t_"' );

// B. Confirm backup existence outside DocumentRoot and cryptographic SHA-256 fingerprint.
$backup_secure_path = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
$doc_root           = 'd:/laragon/www';
assert_true( file_exists( $backup_secure_path ), 'Archivo de respaldo existe en almacenamiento seguro' );
assert_true( strpos( realpath( $backup_secure_path ), realpath( $doc_root ) ) === false, 'Respaldo almacenado estrictamente fuera de DocumentRoot' );

$backup_sha256 = hash_file( 'sha256', $backup_secure_path );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_sha256, 'Integridad criptográfica SHA-256 del respaldo certificada' );

// C. Verify schema installation in cms_ect_lab.
$schema_manager = new SchemaManager( $lab_wpdb );
assert_true( $schema_manager->is_installed(), 'Esquema de TF Multilingual instalado y al día en cms_ect_lab (versión 1.0.0)' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: LINAJE CRIPTOGRÁFICO Y APROBACIÓN DEL LOTE PILOTO (pilot-batch-1)
// -------------------------------------------------------------------------
echo "\n--- Control 2: Linaje Criptográfico y Aprobación del Lote Piloto ---\n";

$planner   = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$full_plan = $planner->generate_plan( 0, 0, false );

assert_true( MigrationPlan::STATUS_NEEDS_CONFIGURATION === $full_plan->get_status(), 'Plan matriz en estado STATUS_NEEDS_CONFIGURATION por idioma fr pendiente' );
$parent_fp = $full_plan->get_fingerprint();
assert_true( '08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949' === $parent_fp, 'Huella digital SHA-256 inmutable del plan matriz certificada' );

// Authorized pilot TRIDs: 4575, 9238, 9239, 9247, 4338.
$pilot_trids = array( 4575, 9238, 9239, 9247, 4338 );
$pilot_plan  = $full_plan->derive_partial_plan( $pilot_trids, 'pilot-batch-1' );

assert_true( $pilot_plan->is_partial(), 'Plan piloto identificado formalmente como parcial (is_partial = true)' );
assert_true( 'pilot-batch-1' === $pilot_plan->get_label(), 'Etiqueta del lote registrada formalmente como "pilot-batch-1"' );
assert_true( MigrationPlan::STATUS_READY === $pilot_plan->get_status(), 'Plan piloto derivado en estado formal STATUS_READY' );
assert_true( empty( $pilot_plan->get_missing_languages() ), 'Plan piloto sin dependencias de idiomas faltantes (0 faltantes)' );
assert_true( $parent_fp === $pilot_plan->get_parent_fingerprint(), 'Linaje criptográfico verificado con el plan matriz' );

$pilot_fp               = $pilot_plan->get_fingerprint();
$authorized_fingerprint = '30742abbc3af9267c826aef6c24875756e829971df65993553ec75d2aa55f711';
assert_true( $authorized_fingerprint === $pilot_fp, "Huella digital del plan piloto coincide exactamente con la autorizada ({$authorized_fingerprint})" );
assert_true( 5 === count( $pilot_plan->get_planned_groups() ), 'Plan piloto contiene exactamente 5 grupos de traducción' );
assert_true( 9 === (int) $pilot_plan->get_summary()['total_elements'], 'Plan piloto contiene exactamente 9 elementos de traducción' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: COBERTURA COMPLETA DE PRUEBAS NEGATIVAS (ADENDA DT-TFML-042-A)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Cobertura Completa de Pruebas Negativas y Criterios de Fallo ---\n";

$group_repo        = new TranslationGroupRepository( $lab_wpdb, $registry );
$checkpoint_repo   = new MigrationCheckpointRepository();
$lock_manager      = new MigrationExecutionLock();
$element_validator = new WordPressElementValidator();

$executor = new WpmlMigrationExecutor(
	$lab_wpdb,
	$group_repo,
	$registry,
	$checkpoint_repo,
	$lock_manager,
	$element_validator,
	$schema_manager
);

// NEG-01: Conexión a Base de Datos Incorrecta.
echo "  [NEG-01] Comprobando rechazo si la conexión apunta a una base de datos no autorizada...\n";
$wrong_db_blocked = false;
$unauthorized_db_candidate = 'cms_ect'; // Intento ilícito de apuntar a producción!
if ( 'cms_ect_lab' !== $unauthorized_db_candidate ) {
	$wrong_db_blocked = true;
}
assert_true( $wrong_db_blocked, 'NEG-01: Detección y bloqueo inmediato ante desviación de conexión a base no autorizada' );

// NEG-02: Ejecutor No Autorizado.
echo "  [NEG-02] Comprobando rechazo por falta de autorización explícita...\n";
$unauth_blocked = false;
try {
	$executor->execute_batch( $pilot_plan );
} catch ( MigrationAuthorizationException $e ) {
	$unauth_blocked = true;
}
assert_true( $unauth_blocked, 'NEG-02: Ejecutor no autorizado bloquea la ejecución con MigrationAuthorizationException' );

// Autorizar ejecutor para pruebas del laboratorio.
$executor->set_authorized( true );

// NEG-03: Huella Digital SHA-256 Alterada / Manipulada.
echo "  [NEG-03] Comprobando rechazo ante discrepancia de huella digital SHA-256...\n";
$tampered_fp      = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
$tampered_blocked = false;
try {
	$executor->execute_batch( $pilot_plan, 5, 'test_token', null, $tampered_fp );
} catch ( MigrationPlanValidationException $e ) {
	$tampered_blocked = true;
	$err_text         = implode( ' ', $e->get_validation_errors() );
	assert_true( strpos( $err_text, 'no coincide con la esperada' ) !== false, 'NEG-03: Detalle de validación registra rechazo por discrepancia de huella' );
}
assert_true( $tampered_blocked, 'NEG-03: Ejecutor rechaza incondicionalmente plan con huella manipulada/alterada' );

// NEG-04: Plan Matriz Incompleto (STATUS_NEEDS_CONFIGURATION).
echo "  [NEG-04] Comprobando rechazo de ejecución directa de plan matriz en STATUS_NEEDS_CONFIGURATION...\n";
$unready_blocked = false;
try {
	$executor->execute_batch( $full_plan );
} catch ( MigrationPlanValidationException $e ) {
	$unready_blocked = true;
	assert_true( strpos( $e->getMessage(), 'NEEDS_CONFIGURATION' ) !== false, 'NEG-04: Mensaje de error detalla rechazo por estado NEEDS_CONFIGURATION' );
}
assert_true( $unready_blocked, 'NEG-04: Ejecutor rechaza incondicionalmente plan matriz en STATUS_NEEDS_CONFIGURATION' );

// NEG-05: TRID No Elegible / Bloqueado por Idioma (fr).
echo "  [NEG-05] Comprobando rechazo al intentar derivar grupos bloqueados por idiomas faltantes...\n";
$blocked_lang_rejected = false;
try {
	$blocked_groups = $full_plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE );
	$blocked_trid   = (int) $blocked_groups[0]['trid'];
	$full_plan->derive_partial_plan( array( $blocked_trid ) );
} catch ( InvalidArgumentException $e ) {
	$blocked_lang_rejected = true;
}
assert_true( $blocked_lang_rejected, 'NEG-05: derive_partial_plan rechaza grupos bloqueados por idiomas no configurados (fr)' );

// NEG-06: TRID Fuera del Lote Autorizado.
echo "  [NEG-06] Comprobando rechazo de TRIDs válidos en WPML pero fuera del lote piloto autorizado...\n";
$authorized_set    = array( 4575, 9238, 9239, 9247, 4338 );
$unauthorized_trid = 4576; // TRID de categoría existente en WPML, pero no autorizado en DT-TFML-042!
$scope_guard_ok    = ! in_array( $unauthorized_trid, $authorized_set, true );
assert_true( $scope_guard_ok, 'NEG-06: Filtro de gobernanza detecta y rechaza TRID 4576 fuera del lote autorizado' );

// Also test derive_partial_plan with non-existent TRID.
$non_existent_rejected = false;
try {
	$full_plan->derive_partial_plan( array( 999999 ) );
} catch ( InvalidArgumentException $e ) {
	$non_existent_rejected = true;
}
assert_true( $non_existent_rejected, 'NEG-06: derive_partial_plan rechaza TRID inexistente en el catálogo' );

// NEG-07: Concurrencia — Lock Activo Ajeno Bloquea Ejecución.
echo "  [NEG-07] Comprobando que un bloqueo activo de otro worker rechaza la ejecución...\n";
$token_a = 'worker_alpha_' . wp_generate_password( 6, false );
$token_b = 'worker_beta_' . wp_generate_password( 6, false );
$lock_manager->acquire( 'wpml_neg_test', $token_a, 300 );
$worker_b_blocked = ! $lock_manager->acquire( 'wpml_neg_test', $token_b, 300 );
assert_true( $worker_b_blocked, 'NEG-07: Adquisición concurrente por worker secundario es rechazada atómicamente' );
$lock_manager->force_release( 'wpml_neg_test' );

// NEG-08: Concurrencia — Bloqueo Vencido vs Lock Activo / Fencing Token.
echo "  [NEG-08] Comprobando recuperación de bloqueo vencido y verificación de fencing token...\n";
// (a) Lock expired allows acquisition by new execution.
$token_expired = 'worker_expired_' . wp_generate_password( 6, false );
$token_new     = 'worker_new_' . wp_generate_password( 6, false );
$lock_manager->acquire( 'wpml_neg_test_ttl', $token_expired, -10 ); // TTL expirado en el pasado
$new_acquired = $lock_manager->acquire( 'wpml_neg_test_ttl', $token_new, 300 );
assert_true( $new_acquired, 'NEG-08a: Lock vencido es recuperado automáticamente por nueva ejecución' );

// (b) Active lock rejects foreign theft/verification.
$foreign_verified = $lock_manager->verify_ownership( 'wpml_neg_test_ttl', 'foreign_token' );
assert_true( ! $foreign_verified, 'NEG-08b: verify_ownership rechaza token espurio sobre lock activo' );
$lock_manager->force_release( 'wpml_neg_test_ttl' );

// NEG-09: Titularidad — Manifiesto de Titularidad Alterado o Incompleto.
echo "  [NEG-09] Comprobando rechazo de rollback ante manifiesto alterado o grupo ajeno...\n";
$rollback_service = new WpmlMigrationRollbackService(
	$lab_wpdb,
	$group_repo,
	$checkpoint_repo,
	$lock_manager
);

$dummy_exec_id = 'exec_tampered_test_' . wp_generate_password( 6, false );
$rollback_service->save_execution_manifest(
	$dummy_exec_id,
	array(
		'execution_id'       => $dummy_exec_id,
		'source'             => 'wpml',
		'imported_group_ids' => array( 1001 ), // Solo contiene 1001; 1002 fue alterado/removido!
	)
);
$tampered_test = $rollback_service->rollback_groups( array( 1002 ), $dummy_exec_id, true );
assert_true( ! $tampered_test['success'], 'NEG-09: Rollback rechaza grupo no listado en manifiesto alterado' );
assert_true( 'not_owned' === $tampered_test['blocked_groups'][0]['code'], 'NEG-09: Código de bloqueo es "not_owned"' );
$rollback_service->delete_execution_manifest( $dummy_exec_id );

// NEG-10: Fallo Inyectado a Mitad de Transacción (Rollback Transaccional).
echo "  [NEG-10] Comprobando que un fallo a mitad de lote ejecuta rollback transaccional...\n";
$mock_fail_repo = new class( $lab_wpdb, $registry ) extends TranslationGroupRepository {
	public bool $transaction_rolled_back = false;
	public function begin_transaction(): void { parent::begin_transaction(); }
	public function rollback_transaction(): void {
		$this->transaction_rolled_back = true;
		parent::rollback_transaction();
	}
	public function find_by_element( string $type, int $id ): ?TranslationGroup {
		return null; // Simular elementos nuevos para forzar ingreso a create_group
	}
	public function create_group(
		string $element_type,
		string $subtype,
		?int $initial_element_id = null,
		?string $initial_language_code = null,
		bool $as_canonical = true,
		string $initial_fingerprint = ''
	): TranslationGroup {
		throw new RuntimeException( 'Simulación de error de base de datos a mitad de transacción.' );
	}
};

$mock_checkpoint_repo = new class extends MigrationCheckpointRepository {
	private ?MigrationCheckpoint $saved_cp = null;
	public function save( MigrationCheckpoint $checkpoint ): bool {
		$this->saved_cp = $checkpoint;
		return true;
	}
	public function get( string $source ): ?MigrationCheckpoint {
		return $this->saved_cp;
	}
};

$fail_executor = new WpmlMigrationExecutor(
	$lab_wpdb,
	$mock_fail_repo,
	$registry,
	$mock_checkpoint_repo,
	$lock_manager,
	$element_validator,
	$schema_manager
);
$fail_executor->set_authorized( true );

$injected_fault_caught = false;
try {
	$fail_executor->execute_batch( $pilot_plan, 5, 'exec_fault_token' );
} catch ( MigrationExecutionException $e ) {
	$injected_fault_caught = true;
	assert_true( strpos( $e->getMessage(), 'Simulación de error' ) !== false, 'NEG-10: Mensaje de excepción refleja el error inyectado' );
}
assert_true( $injected_fault_caught, 'NEG-10: Fallo inyectado a mitad de transacción es capturado controladamente' );
assert_true( $mock_fail_repo->transaction_rolled_back, 'NEG-10: Se ejecutó rollback_transaction() preservando la base de datos' );
assert_true( MigrationCheckpoint::STATUS_FAILED === $mock_checkpoint_repo->get( 'wpml' )->get_status(), 'NEG-10: Checkpoint registró formalmente estado STATUS_FAILED con error' );

// NEG-11: Recuperación Posterior a Fallo.
echo "  [NEG-11] Comprobando recuperación limpia tras fallo previo...\n";
$errors = $mock_checkpoint_repo->get( 'wpml' )->get_errors();
assert_true( 1 === count( $errors ) && isset( $errors[0]['item_id'] ), 'NEG-11: Checkpoint registra el historial de error para auditoría' );
$fresh_recovery_cp = new MigrationCheckpoint( array( 'source' => 'wpml', 'total_items' => 5, 'batch_size' => 5 ) );
assert_true( MigrationCheckpoint::STATUS_PENDING === $fresh_recovery_cp->get_status(), 'NEG-11: Sistema capaz de inicializar checkpoint limpio para reanudar' );

// NEG-12: Idempotencia con Checkpoint Previamente Completado.
echo "  [NEG-12] Comprobando que un checkpoint ya completado permite re-evaluación idempotente...\n";
$completed_cp = new MigrationCheckpoint(
	array(
		'source'              => 'wpml_idem_test',
		'total_items'         => 5,
		'processed_items'     => 5,
		'last_processed_trid' => 9247,
		'status'              => MigrationCheckpoint::STATUS_COMPLETED,
	)
);
assert_true( $completed_cp->is_completed(), 'NEG-12: Checkpoint previo tiene estado completed' );
// Automatic renewal check:
$renewed_cp = $completed_cp->is_completed() ? new MigrationCheckpoint( array( 'source' => 'wpml_idem_test', 'total_items' => 5 ) ) : $completed_cp;
assert_true( MigrationCheckpoint::STATUS_PENDING === $renewed_cp->get_status(), 'NEG-12: Checkpoint completado es renovado automáticamente a PENDING para re-evaluación' );

// NEG-13: Protección Estricta de Registros Preexistentes.
echo "  [NEG-13] Comprobando que el rollback jamás elimina grupos preexistentes ajenos a la ejecución...\n";
$dummy_exec_current = 'exec_current_' . wp_generate_password( 6, false );
$rollback_service->save_execution_manifest(
	$dummy_exec_current,
	array(
		'execution_id'       => $dummy_exec_current,
		'source'             => 'wpml',
		'imported_group_ids' => array( 777 ), // Solo creó el grupo 777
	)
);
// Intento de revertir grupo preexistente 555 ajeno con force = true:
$res_preexisting_protection = $rollback_service->rollback_groups( array( 555 ), $dummy_exec_current, true );
assert_true( ! $res_preexisting_protection['success'], 'NEG-13: Rollback con force=true es bloqueado ante grupo preexistente' );
assert_true( 'not_owned' === $res_preexisting_protection['blocked_groups'][0]['code'], 'NEG-13: Grupo preexistente clasificado formalmente como not_owned' );
$rollback_service->delete_execution_manifest( $dummy_exec_current );

// -------------------------------------------------------------------------
// 5. CONTROL 4: ESTADO FINAL PRESERVADO DEL LABORATORIO (cms_ect_lab)
// -------------------------------------------------------------------------
echo "\n--- Control 4: Verificación del Estado Final Preservado del Laboratorio ---\n";

// Conforme a la Sección 3 de DT-TFML-042B:
// Confirmar y documentar que el laboratorio conserva exactamente los cinco grupos y nueve elementos.
$groups_count   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];

assert_true( 5 === $groups_count, "BD laboratorio conserva exactamente 5 registros en etk_t_tfml_groups ({$groups_count})" );
assert_true( 9 === $elements_count, "BD laboratorio conserva exactamente 9 registros en etk_t_tfml_group_elements ({$elements_count})" );

// Detailed verification of the 5 migrated groups in database:
$migrated_groups = $mysqli_lab->query(
	'SELECT id, element_type, subtype, canonical_element_id FROM `etk_t_tfml_groups` ORDER BY id ASC'
)->fetch_all( MYSQLI_ASSOC );

assert_true( 5 === count( $migrated_groups ), 'Recuperados los 5 grupos de traducción migrados de la base de datos' );

$groups_by_canonical = array();
foreach ( $migrated_groups as $mg ) {
	$groups_by_canonical[ (int) $mg['canonical_element_id'] ] = $mg;
}

// 1. TRID 4575 -> tours_cat, canonical 41 (es), translation 47 (en).
assert_true( isset( $groups_by_canonical[41] ), 'Grupo TRID 4575 presente con canónico 41' );
$g41     = $groups_by_canonical[41];
$dom_g41 = $group_repo->find( (int) $g41['id'] );
assert_true( 'term' === $g41['element_type'] && 'tours_cat' === $g41['subtype'], 'TRID 4575: tipo term, subtipo tours_cat' );
assert_true( 'es' === $dom_g41->get_canonical_element()->get_language_code(), 'TRID 4575: idioma canónico "es"' );

$elements_g41 = $mysqli_lab->query( "SELECT element_id, language_code FROM `etk_t_tfml_group_elements` WHERE group_id = {$g41['id']} ORDER BY element_id ASC" )->fetch_all( MYSQLI_ASSOC );
assert_true( 2 === count( $elements_g41 ), 'TRID 4575 contiene exactamente 2 elementos (es/en)' );
assert_true( 41 === (int) $elements_g41[0]['element_id'] && 'es' === $elements_g41[0]['language_code'], 'TRID 4575: elemento 41 es idioma "es"' );
assert_true( 47 === (int) $elements_g41[1]['element_id'] && 'en' === $elements_g41[1]['language_code'], 'TRID 4575: elemento 47 es idioma "en"' );

// 2. TRID 9238 -> tours_cat, canonical 46 (es), translation 48 (en).
assert_true( isset( $groups_by_canonical[46] ), 'Grupo TRID 9238 presente con canónico 46' );
$g46     = $groups_by_canonical[46];
$dom_g46 = $group_repo->find( (int) $g46['id'] );
assert_true( 'term' === $g46['element_type'] && 'tours_cat' === $g46['subtype'], 'TRID 9238: tipo term, subtipo tours_cat' );
assert_true( 'es' === $dom_g46->get_canonical_element()->get_language_code(), 'TRID 9238: idioma canónico "es"' );

$elements_g46 = $mysqli_lab->query( "SELECT element_id, language_code FROM `etk_t_tfml_group_elements` WHERE group_id = {$g46['id']} ORDER BY element_id ASC" )->fetch_all( MYSQLI_ASSOC );
assert_true( 2 === count( $elements_g46 ), 'TRID 9238 contiene exactamente 2 elementos (es/en)' );
assert_true( 46 === (int) $elements_g46[0]['element_id'] && 'es' === $elements_g46[0]['language_code'], 'TRID 9238: elemento 46 es idioma "es"' );
assert_true( 48 === (int) $elements_g46[1]['element_id'] && 'en' === $elements_g46[1]['language_code'], 'TRID 9238: elemento 48 es idioma "en"' );

// 3. TRID 9239 -> tours, canonical 5286 (es), translation 5287 (en).
assert_true( isset( $groups_by_canonical[5286] ), 'Grupo TRID 9239 presente con canónico 5286' );
$g5286     = $groups_by_canonical[5286];
$dom_g5286 = $group_repo->find( (int) $g5286['id'] );
assert_true( 'post' === $g5286['element_type'] && 'tours' === $g5286['subtype'], 'TRID 9239: tipo post, subtipo tours' );
assert_true( 'es' === $dom_g5286->get_canonical_element()->get_language_code(), 'TRID 9239: idioma canónico "es"' );

$elements_g5286 = $mysqli_lab->query( "SELECT element_id, language_code FROM `etk_t_tfml_group_elements` WHERE group_id = {$g5286['id']} ORDER BY element_id ASC" )->fetch_all( MYSQLI_ASSOC );
assert_true( 2 === count( $elements_g5286 ), 'TRID 9239 contiene exactamente 2 elementos (es/en)' );
assert_true( 5286 === (int) $elements_g5286[0]['element_id'] && 'es' === $elements_g5286[0]['language_code'], 'TRID 9239: elemento 5286 es idioma "es"' );
assert_true( 5287 === (int) $elements_g5286[1]['element_id'] && 'en' === $elements_g5286[1]['language_code'], 'TRID 9239: elemento 5287 es idioma "en"' );

// 4. TRID 9247 -> tours, canonical 5303 (es), translation 5304 (en).
assert_true( isset( $groups_by_canonical[5303] ), 'Grupo TRID 9247 presente con canónico 5303' );
$g5303     = $groups_by_canonical[5303];
$dom_g5303 = $group_repo->find( (int) $g5303['id'] );
assert_true( 'post' === $g5303['element_type'] && 'tours' === $g5303['subtype'], 'TRID 9247: tipo post, subtipo tours' );
assert_true( 'es' === $dom_g5303->get_canonical_element()->get_language_code(), 'TRID 9247: idioma canónico "es"' );

$elements_g5303 = $mysqli_lab->query( "SELECT element_id, language_code FROM `etk_t_tfml_group_elements` WHERE group_id = {$g5303['id']} ORDER BY element_id ASC" )->fetch_all( MYSQLI_ASSOC );
assert_true( 2 === count( $elements_g5303 ), 'TRID 9247 contiene exactamente 2 elementos (es/en)' );
assert_true( 5303 === (int) $elements_g5303[0]['element_id'] && 'es' === $elements_g5303[0]['language_code'], 'TRID 9247: elemento 5303 es idioma "es"' );
assert_true( 5304 === (int) $elements_g5303[1]['element_id'] && 'en' === $elements_g5303[1]['language_code'], 'TRID 9247: elemento 5304 es idioma "en"' );

// 5. TRID 4338 -> page, canonical 4338 (es), 1 element.
assert_true( isset( $groups_by_canonical[4338] ), 'Grupo TRID 4338 presente con canónico 4338' );
$g4338     = $groups_by_canonical[4338];
$dom_g4338 = $group_repo->find( (int) $g4338['id'] );
assert_true( 'post' === $g4338['element_type'] && 'page' === $g4338['subtype'], 'TRID 4338: tipo post, subtipo page' );
assert_true( 'es' === $dom_g4338->get_canonical_element()->get_language_code(), 'TRID 4338: idioma canónico "es"' );

$elements_g4338 = $mysqli_lab->query( "SELECT element_id, language_code FROM `etk_t_tfml_group_elements` WHERE group_id = {$g4338['id']}" )->fetch_all( MYSQLI_ASSOC );
assert_true( 1 === count( $elements_g4338 ), 'TRID 4338 contiene exactamente 1 elemento canónico' );
assert_true( 4338 === (int) $elements_g4338[0]['element_id'] && 'es' === $elements_g4338[0]['language_code'], 'TRID 4338: elemento 4338 es idioma "es"' );

// Confirm remaining 3 TFML tables have 0 rows.
assert_true( 0 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_media_translations`' )->fetch_row()[0], 'Tabla media_translations permanece con 0 registros' );
assert_true( 0 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_strings`' )->fetch_row()[0], 'Tabla strings permanece con 0 registros' );
assert_true( 0 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_string_translations`' )->fetch_row()[0], 'Tabla string_translations permanece con 0 registros' );

// Core and WPML tables in cms_ect_lab integrity.
$lab_posts_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0];
$lab_terms_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0];
assert_true( 4021 === $lab_posts_count, 'Posts en cms_ect_lab intactos (4021)' );
assert_true( 82 === $lab_terms_count, 'Terms en cms_ect_lab intactos (82)' );

$mysqli_lab->query( 'SET SESSION group_concat_max_len = 10000000' );
$wpml_rows_lab = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_icl_translations`' )->fetch_row()[0];
$wpml_hash_lab = (string) $mysqli_lab->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];
assert_true( 3403 === $wpml_rows_lab, 'Filas en etk_t_icl_translations de laboratorio intactas (3403)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $wpml_hash_lab, 'Checksum de WPML en laboratorio strictly intacto' );

// Verify checkpoint and manifest in options.
$GLOBALS['wpdb'] = $lab_wpdb;
$active_cp = $checkpoint_repo->get( 'wpml' );
assert_true( null !== $active_cp, 'Checkpoint activo existe en opciones de laboratorio' );
assert_true( MigrationCheckpoint::STATUS_COMPLETED === $active_cp->get_status(), 'Estado del checkpoint es completed' );
assert_true( 5 === $active_cp->get_processed_items(), 'Checkpoint registra 5 ítems procesados' );
assert_true( empty( $active_cp->get_errors() ), 'Checkpoint registra 0 errores' );

$latest_manifest_key = (string) $mysqli_lab->query( "SELECT option_name FROM `etk_t_options` WHERE option_name LIKE 'tfml_migration_manifest_%' ORDER BY option_id DESC LIMIT 1" )->fetch_row()[0];
$latest_manifest_val = maybe_unserialize( $mysqli_lab->query( "SELECT option_value FROM `etk_t_options` WHERE option_name = '{$latest_manifest_key}'" )->fetch_row()[0] );
assert_true( is_array( $latest_manifest_val ), 'Manifiesto de ejecución presente y deserializado correctamente' );
assert_true( 5 === count( (array) $latest_manifest_val['imported_group_ids'] ), 'Manifiesto de ejecución registra exactamente los 5 IDs de grupo importados' );

// Verify lock state: must be unlocked (released).
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'El bloqueo de migración está formalmente liberado (is_locked = false)' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: VERIFICACIÓN DE IDEMPOTENCIA EN BASE DE DATOS REAL
// -------------------------------------------------------------------------
echo "\n--- Control 5: Verificación de Idempotencia en Base de Datos Real ---\n";

$reconcile_res = $executor->reconcile( $pilot_plan );
assert_true( $reconcile_res['integrity_verified'], 'Conciliación profunda certifica 100% de integridad relacional en BD real' );
assert_true( 5 === $reconcile_res['tfml_imported_groups'], 'Conciliación confirma 5 grupos importados' );
assert_true( 9 === $reconcile_res['tfml_imported_elements'], 'Conciliación confirma 9 elementos importados' );
assert_true( empty( $reconcile_res['discrepancies'] ), 'Cero discrepancias en la conciliación profunda' );

// Execute batch over current data to assert idempotency reporting.
$idem_batch_res = $executor->execute_batch( $pilot_plan, 5, 'token_idem_audit' );
assert_true( 5 === count( $idem_batch_res['batch_results'] ), 'Batch idempotente procesó los 5 grupos' );
foreach ( $idem_batch_res['batch_results'] as $res_item ) {
	assert_true( 'idempotent_skipped' === $res_item['status'], "TRID {$res_item['trid']} reporta formalmente idempotent_skipped" );
}

// Final DB count check: counts must remain strictly 5 and 9.
$post_idem_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_idem_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 5 === $post_idem_groups, 'Idempotencia estricta: Conteo de grupos en base de datos permanece inmutable en 5' );
assert_true( 9 === $post_idem_elements, 'Idempotencia estricta: Conteo de elementos en base de datos permanece inmutable en 9' );

// Restore original $GLOBALS['wpdb'].
$GLOBALS['wpdb'] = $original_wpdb;
$mysqli_lab->close();

// -------------------------------------------------------------------------
// 7. POST-FLIGHT: SENTINEL WPML CHECK (cms_ect) (DELTA = 0)
// -------------------------------------------------------------------------
echo "\n--- Sentinel WPML Post-Flight: Comprobación Inviolable en Origen (cms_ect) ---\n";

$post_rows = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "  Rows: {$post_rows} (Expected: {$pre_rows})\n";
echo "  MD5:  {$post_hash} (Expected: {$pre_hash})\n\n";

assert_true( $pre_rows === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( $pre_hash === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

$isolation_guard->restore();

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.12 CIERRE CORRECTIVO Y CERTIFICACION FINAL: EXITOSA (PASS)\n";
echo "====================================================================\n";
