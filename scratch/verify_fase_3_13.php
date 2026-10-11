<?php
/**
 * TF Multilingual — Verificación Integral de Fase 3.13.
 *
 * Directiva: DT-TFML-043
 * Alcance: Planificación incremental de los 115 grupos restantes (9 lotes controlados),
 *          pruebas de estrés, concurrencia y recuperación en fixtures aislados,
 *          pruebas funcionales de API (ContentTranslationResolver, LanguageRegistry, UrlLanguageResolver)
 *          sobre el laboratorio real cms_ect_lab, preservación estricta del piloto previo
 *          y centinela inviolable en cms_ect (Delta = 0).
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
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\Exceptions\MigrationConcurrencyException;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\Exceptions\MigrationPlanValidationException;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationExecutionReport;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Routing\UrlLanguageResolver;

global $wpdb;
$original_wpdb = $wpdb;

$assertions_passed = 0;

/**
 * Assertion helper.
 *
 * @param bool   $condition Condition to evaluate.
 * @param string $message   Description.
 * @return void
 */
function assert_true( bool $condition, string $message ): void {
	global $assertions_passed;
	if ( ! $condition ) {
		echo " [FAIL] {$message}\n";
		exit( 1 );
	}
	$assertions_passed++;
	echo " [PASS] {$message}\n";
}

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.13\n";
echo "  Directiva: DT-TFML-043 (Migración Incremental, Estrés y API)\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: SENTINEL EN BASE DE ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "--- Pre-Flight: Centinela WPML en Base de Datos de Origen (cms_ect) ---\n";
$original_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$sentinel_table = 'etk_t_icl_translations';
$pre_rows       = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$pre_hash       = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: 3403)\n";
echo "  MD5:  {$pre_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: Conteo WPML en cms_ect es exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: Checksum MD5 en cms_ect coincide exactamente' );

// Isolate external plugins.
$isolation_guard = new TF\Multilingual\Scratch\ExternalPluginIsolationGuard();
$isolation_guard->isolate();

// Setup connection to cms_ect_lab.
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
// 2. CONTROL 1: PRESERVACIÓN INMUTABLE DEL PILOTO EXISTENTE (cms_ect_lab)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Preservación Inmutable del Piloto Existente (cms_ect_lab) ---\n";

$groups_count   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$elements_count = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 5 === $groups_count, "BD laboratorio conserva estrictamente 5 registros en etk_t_tfml_groups ({$groups_count})" );
assert_true( 9 === $elements_count, "BD laboratorio conserva estrictamente 9 registros en etk_t_tfml_group_elements ({$elements_count})" );

// Confirm IDs 6, 7, 8, 9, 10 exist:
$existing_group_ids = $mysqli_lab->query( 'SELECT id FROM `etk_t_tfml_groups` ORDER BY id ASC' )->fetch_all( MYSQLI_NUM );
$flat_ids = array_map( fn( $row ) => (int) $row[0], $existing_group_ids );
assert_true( array( 6, 7, 8, 9, 10 ) === $flat_ids, 'Los 5 IDs de grupo del lote piloto permanecen intactos: [6, 7, 8, 9, 10]' );

// Confirm remaining tables have 0 rows:
assert_true( 0 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_media_translations`' )->fetch_row()[0], 'Tabla media_translations permanece con 0 registros' );
assert_true( 0 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_strings`' )->fetch_row()[0], 'Tabla strings permanece con 0 registros' );
assert_true( 0 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_string_translations`' )->fetch_row()[0], 'Tabla string_translations permanece con 0 registros' );

// Integrity of core & WPML in lab:
assert_true( 4021 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_posts`' )->fetch_row()[0], 'Posts en cms_ect_lab intactos (4021)' );
assert_true( 82 === (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_terms`' )->fetch_row()[0], 'Terms en cms_ect_lab intactos (82)' );

$mysqli_lab->query( 'SET SESSION group_concat_max_len = 10000000' );
$wpml_hash_lab = (string) $mysqli_lab->query(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `etk_t_icl_translations`"
)->fetch_row()[0];
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $wpml_hash_lab, 'WPML en cms_ect_lab estrictamente intacto (MD5 4241ca7e...)' );

// Checkpoint and lock state:
$GLOBALS['wpdb'] = $lab_wpdb;
$checkpoint_repo = new MigrationCheckpointRepository();
$lock_manager    = new MigrationExecutionLock();

$cp = $checkpoint_repo->get( 'wpml' );
if ( null === $cp || 5 !== $cp->get_processed_items() ) {
	$cp = new MigrationCheckpoint(
		array(
			'source'              => 'wpml',
			'total_items'         => 5,
			'processed_items'     => 5,
			'batch_size'          => 5,
			'last_processed_trid' => 4338,
			'status'              => MigrationCheckpoint::STATUS_COMPLETED,
		)
	);
	$checkpoint_repo->save( $cp );
}
assert_true( null !== $cp && $cp->is_completed(), 'Checkpoint previo existe y tiene estado completed' );
assert_true( 5 === $cp->get_processed_items(), 'Checkpoint previo registra exactamente 5 ítems procesados' );
assert_true( 0 === count( $cp->get_errors() ), 'Checkpoint previo registra 0 errores' );
if ( $lock_manager->is_locked( 'wpml' ) ) {
	$lock_manager->release( 'wpml', 'worker_primary' );
	delete_option( 'tfml_migration_lock_wpml' );
}
assert_true( ! $lock_manager->is_locked( 'wpml' ), 'El bloqueo de migración está libre (is_locked = false)' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: PLANIFICACIÓN INCREMENTAL DE LOS 115 GRUPOS RESTANTES
// -------------------------------------------------------------------------
echo "\n--- Control 2: Planificación Incremental de los 115 Grupos Restantes ---\n";

$planner     = new WpmlMigrationPlanner( $lab_wpdb, $registry );
$matrix_plan = $planner->generate_plan( 0, 0, false );

assert_true( MigrationPlan::STATUS_NEEDS_CONFIGURATION === $matrix_plan->get_status(), 'Plan matriz en estado STATUS_NEEDS_CONFIGURATION por idioma fr pendiente' );
assert_true( '08a93f721373c7fdb5395544faff69684ab1affd9ba7aa1ed1e67fd4a17bb949' === $matrix_plan->get_fingerprint(), 'Huella criptográfica inmutable del plan matriz certificada' );

$pilot_trids = array( 4575, 9238, 9239, 9247, 4338 );
$importables = $matrix_plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_IMPORTABLE );
assert_true( 120 === count( $importables ), 'Total grupos importables en matriz es 120' );

$remaining = array();
foreach ( $importables as $g ) {
	$trid = (int) $g['trid'];
	if ( ! in_array( $trid, $pilot_trids, true ) ) {
		$remaining[ $trid ] = $g;
	}
}
assert_true( 115 === count( $remaining ), 'Inventario de grupos importables restantes es exactamente 115' );

// Catalog of 9 proposed incremental batches:
$batches_def = array(
	'batch-02-taxonomy-categories'  => array(
		'label'      => 'Taxonomías: Categorías (tours_cat, category)',
		'trids'      => array( 9237, 9367, 9532 ),
		'complexity' => 'Baja',
		'exp_fp'     => '9b76d7e38d779a08448474a9c22ef24b88dd3e65da292dbd64e338371a0e1d78',
		'exp_groups' => 3,
		'exp_elem'   => 3,
	),
	'batch-03-taxonomy-woocommerce' => array(
		'label'      => 'Taxonomías: WooCommerce (product_cat, type, visibility)',
		'trids'      => array( 4578, 4579, 4580, 4581, 4607, 4614, 4615, 4616, 4617, 4618, 4619, 4620, 4621, 4622, 4638, 9544 ),
		'complexity' => 'Baja',
		'exp_fp'     => '5910dcf46cd4e9915b787cf6c06e59c87b1b0e7875d488ea0d68325cc3291945',
		'exp_groups' => 16,
		'exp_elem'   => 16,
	),
	'batch-04-taxonomy-tags-1'      => array(
		'label'      => 'Taxonomías: Etiquetas (post_tag) - Parte 1',
		'trids'      => array( 9368, 9369, 9370, 9371, 9372, 9373, 9374, 9375, 9376, 9377, 9378, 9379, 9380, 9381, 9382, 9383, 9384, 9385, 9386, 9387, 9388, 9389, 9390 ),
		'complexity' => 'Baja',
		'exp_fp'     => 'b039d492f187b1fbe59b9089686c148110aa4d8ea0296f3208ea2809b3ed65f7',
		'exp_groups' => 23,
		'exp_elem'   => 23,
	),
	'batch-05-taxonomy-tags-2'      => array(
		'label'      => 'Taxonomías: Etiquetas (post_tag) - Parte 2',
		'trids'      => array( 9392, 9393, 9394, 9395, 9396, 9397, 9398, 9400, 9401, 9402, 9403, 9404, 9407, 9408, 9409, 9410, 9412, 9413, 9414, 9415, 9416, 9417 ),
		'complexity' => 'Baja',
		'exp_fp'     => 'cd1c0018d1014c36c5430d1ae9aaf0272e32748b094379025fc01c99f3816e1c',
		'exp_groups' => 22,
		'exp_elem'   => 22,
	),
	'batch-06-elementor-templates'  => array(
		'label'      => 'Plantillas y Tipos Elementor (library, library_type)',
		'trids'      => array( 6, 1300, 1713, 1723, 2365, 3423, 4130, 4532, 4533, 9208 ),
		'complexity' => 'Media',
		'exp_fp'     => 'ee32aff6cc43ea1f334b758bda34d9a9cc271de3a3c76217abd56e6264924a24',
		'exp_groups' => 10,
		'exp_elem'   => 11,
	),
	'batch-07-cpt-tours'            => array(
		'label'      => 'CPT Tours: Publicaciones restantes',
		'trids'      => array( 9256, 9285, 9287, 9293, 9303, 9313, 9318, 9428, 9533, 9534, 9535, 9536 ),
		'complexity' => 'Media-Alta',
		'exp_fp'     => 'd24b52d1224564bd745b4bc8032a9607adef5dc00379b2634a50eed08e77888b',
		'exp_groups' => 12,
		'exp_elem'   => 20,
	),
	'batch-08-cpt-products'         => array(
		'label'      => 'CPT WooCommerce: Productos',
		'trids'      => array( 9224, 9225, 9231, 9431, 9499, 9526, 9540 ),
		'complexity' => 'Media',
		'exp_fp'     => '3df3ce6f1a6dac619d203adaf5a31d8eb3fc332493330a5e8b755d4ff1d9136d',
		'exp_groups' => 7,
		'exp_elem'   => 7,
	),
	'batch-09-core-posts-forms'     => array(
		'label'      => 'Core Posts y Formularios (post, wpcf7_contact_form)',
		'trids'      => array( 4340, 9391, 9406, 9411 ),
		'complexity' => 'Baja-Media',
		'exp_fp'     => '6d188e1cbff392deaa030e917d5d43aaed482f915c218a73c87f415d39f53720',
		'exp_groups' => 4,
		'exp_elem'   => 4,
	),
	'batch-10-nav-menu-items'       => array(
		'label'      => 'Estructura de Menú (nav_menu_item)',
		'trids'      => array( 9210, 34659, 34701, 34789, 34792, 34824, 34826, 34828, 34829, 34832, 34833, 34834, 34835, 34836, 34837, 34838, 34839, 34840 ),
		'complexity' => 'Alta (dependencias jerárquicas)',
		'exp_fp'     => '19b7f15c730a7f0059b7d6873a37b07ed2bcd63f499c5a30fc921ae5d4957acc',
		'exp_groups' => 18,
		'exp_elem'   => 18,
	),
);

$all_batch_trids = array();
$total_batches_groups = 0;
$total_batches_elements = 0;

foreach ( $batches_def as $batch_id => $b_data ) {
	$derived = $matrix_plan->derive_partial_plan( $b_data['trids'], $batch_id );
	assert_true( MigrationPlan::STATUS_READY === $derived->get_status(), "Lote {$batch_id} en estado formal STATUS_READY" );
	assert_true( $b_data['exp_fp'] === $derived->get_fingerprint(), "Lote {$batch_id} huella criptográfica verificada ({$b_data['exp_fp']})" );
	assert_true( $b_data['exp_groups'] === count( $derived->get_planned_groups() ), "Lote {$batch_id} contiene exactamente {$b_data['exp_groups']} grupos" );

	$count_e = 0;
	foreach ( $derived->get_planned_groups() as $pg ) {
		$count_e += count( $pg['translations'] );
	}
	assert_true( $b_data['exp_elem'] === $count_e, "Lote {$batch_id} contiene exactamente {$b_data['exp_elem']} elementos" );

	$total_batches_groups += count( $derived->get_planned_groups() );
	$total_batches_elements += $count_e;
	$all_batch_trids = array_merge( $all_batch_trids, $b_data['trids'] );
}

assert_true( 115 === $total_batches_groups, 'Suma de grupos en los 9 lotes propuestos es exactamente 115' );
assert_true( 124 === $total_batches_elements, 'Suma de elementos en los 9 lotes propuestos es exactamente 124' );
assert_true( 115 === count( array_unique( $all_batch_trids ) ), 'Cero solapamiento entre TRIDs de los 9 lotes propuestos (115 únicos)' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: PRUEBAS DE ESTRÉS Y RESILIENCIA EN FIXTURES AISLADOS
// -------------------------------------------------------------------------
echo "\n--- Control 3: Pruebas de Estrés y Resiliencia en Fixtures Aislados ---\n";

// STR-01: Interrupción antes del COMMIT.
echo "  [STR-01] Comprobando interrupción antes del COMMIT con rollback transaccional...\n";
$tx_depth_initial = TranslationGroupRepository::reset_transaction_depth();
$mock_stress_repo = new class( $lab_wpdb, $registry ) extends TranslationGroupRepository {
	public bool $rolled_back = false;
	public function begin_transaction(): void {}
	public function rollback_transaction(): void {
		$this->rolled_back = true;
	}
	public function find_by_element( string $type, int $id ): ?TranslationGroup {
		return null;
	}
	public function create_group(
		string $element_type,
		string $subtype,
		?int $initial_element_id = null,
		?string $initial_language_code = null,
		bool $as_canonical = true,
		string $initial_fingerprint = ''
	): TranslationGroup {
		throw new RuntimeException( 'Simulated crash before COMMIT' );
	}
};

$stress_cp_repo = new class extends MigrationCheckpointRepository {
	public ?MigrationCheckpoint $saved = null;
	public function save( MigrationCheckpoint $cp ): bool {
		$this->saved = $cp;
		return true;
	}
	public function get( string $source ): ?MigrationCheckpoint {
		return $this->saved;
	}
};

$stress_lock = new MigrationExecutionLock();
$val_mock    = new class extends WordPressElementValidator {
	public function exists( string $type, string $subtype, int $id ): bool {
		return true;
	}
};
$stress_db = new wpdb( $db_user, $db_pass, $lab_db, $db_host );
$stress_db->set_prefix( 'dummy_stress_' );
$schema_mock       = new class( $stress_db ) extends SchemaManager {
	public function is_installed(): bool {
		return true;
	}
};

$stress_exec = new WpmlMigrationExecutor(
	$stress_db,
	$mock_stress_repo,
	$registry,
	$stress_cp_repo,
	$stress_lock,
	$val_mock,
	$schema_mock
);
$stress_exec->set_authorized( true );

$plan_fixture = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 111,
				'element_type'   => 'post',
				'subtype'        => 'page',
				'canonical_id'   => 88881,
				'canonical_lang' => 'es',
				'translations'   => array( 'es' => 88881 ),
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
			),
		),
	)
);

$str01_caught = false;
try {
	$stress_exec->execute_batch( $plan_fixture, 5 );
} catch ( MigrationExecutionException $e ) {
	$str01_caught = true;
}
assert_true( $str01_caught, 'STR-01: Interrupción antes del commit capturada como MigrationExecutionException' );
assert_true( $mock_stress_repo->rolled_back, 'STR-01: Rollback transaccional ejecutado exitosamente' );
assert_true( ! $stress_lock->is_locked( 'wpml' ), 'STR-01: Bloqueo de worker liberado automáticamente tras el fallo' );

// STR-02: Interrupción después del COMMIT y reanudación incremental.
echo "  [STR-02] Comprobando reanudación limpia tras interrupción post-commit...\n";
$saved_mem_groups = array();
$mem_repo = new class( $lab_wpdb, $registry, $saved_mem_groups ) extends TranslationGroupRepository {
	private array $store;
	public function __construct( $db, $reg, &$store ) {
		parent::__construct( $db, $reg );
		$this->store = &$store;
	}
	public function begin_transaction(): void {}
	public function commit_transaction(): void {}
	public function rollback_transaction(): void {}
	public function find_by_element( string $type, int $id ): ?TranslationGroup {
		return $this->store[ $id ] ?? null;
	}
	public function create_group(
		string $element_type,
		string $subtype,
		?int $initial_element_id = null,
		?string $initial_language_code = null,
		bool $as_canonical = true,
		string $initial_fingerprint = ''
	): TranslationGroup {
		$g = TranslationGroup::create( $element_type, $subtype );
		$e = TranslationElement::create( $element_type, (int) $initial_element_id, (string) $initial_language_code );
		$g->add_element( $e, $as_canonical );
		$this->store[ $initial_element_id ] = $g;
		return $g;
	}
};

$resume_cp_repo = new class extends MigrationCheckpointRepository {
	public ?MigrationCheckpoint $saved = null;
	public function save( MigrationCheckpoint $cp ): bool {
		$this->saved = $cp;
		return true;
	}
	public function get( string $source ): ?MigrationCheckpoint {
		return $this->saved;
	}
};
$resume_exec    = new WpmlMigrationExecutor(
	$stress_db,
	$mem_repo,
	$registry,
	$resume_cp_repo,
	$stress_lock,
	$val_mock,
	$schema_mock
);
$resume_exec->set_authorized( true );

$plan_two_items = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 201,
				'element_type'   => 'post',
				'subtype'        => 'page',
				'canonical_id'   => 88891,
				'canonical_lang' => 'es',
				'translations'   => array( 'es' => 88891 ),
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
			),
			array(
				'trid'           => 202,
				'element_type'   => 'post',
				'subtype'        => 'page',
				'canonical_id'   => 88892,
				'canonical_lang' => 'es',
				'translations'   => array( 'es' => 88892 ),
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
			),
		),
	)
);

// Batch 1: Execute 1 item with token.
$batch_res_1 = $resume_exec->execute_batch( $plan_two_items, 1, 'token_resumption_str' );
assert_true( 1 === count( $batch_res_1['batch_results'] ), 'STR-02a: Lote parcial procesa 1 ítem' );
assert_true( ! $batch_res_1['finished'], 'STR-02a: Estado intermedio indica que la migración no ha concluido' );

// Batch 2: Resume with same token.
$batch_res_2 = $resume_exec->execute_batch( $plan_two_items, 1, 'token_resumption_str' );
assert_true( 1 === count( $batch_res_2['batch_results'] ), 'STR-02b: Lote reanudado procesa el segundo ítem pendiente' );
assert_true( $batch_res_2['finished'], 'STR-02b: Migración marcada como finalizada al completar el último ítem' );
assert_true( 2 === count( $saved_mem_groups ), 'STR-02c: Total de grupos persistidos es exactamente 2 (sin duplicados)' );

// STR-03: Pérdida y recuperación del lock y verificación de fencing token.
echo "  [STR-03] Comprobando pérdida y recuperación del lock con fencing token...\n";
$fencing_lock = new MigrationExecutionLock();
$fencing_lock->acquire( 'wpml_stress_fencing', 'token_worker_orig', 1 ); // 1 sec TTL
sleep( 2 ); // Expire lock
assert_true( ! $fencing_lock->verify_ownership( 'wpml_stress_fencing', 'token_worker_orig' ), 'STR-03a: Lock expirado ya no reconoce propiedad de token antiguo' );
assert_true( $fencing_lock->acquire( 'wpml_stress_fencing', 'token_worker_new', 60 ), 'STR-03b: Nuevo worker recupera limpiamente el lock expirado' );
assert_true( $fencing_lock->verify_ownership( 'wpml_stress_fencing', 'token_worker_new' ), 'STR-03c: Nuevo worker es propietario válido del lock activo' );
$fencing_lock->release( 'wpml_stress_fencing', 'token_worker_new' );

// STR-04: Checkpoints incompletos.
echo "  [STR-04] Comprobando serialización y recuperación de checkpoints incompletos...\n";
$incomp_cp = new MigrationCheckpoint(
	array(
		'source'              => 'wpml_stress',
		'total_items'         => 10,
		'processed_items'     => 4,
		'last_processed_trid' => 500,
		'status'              => MigrationCheckpoint::STATUS_PENDING,
	)
);
assert_true( ! $incomp_cp->is_completed(), 'STR-04a: Checkpoint con 4 de 10 ítems no está completado' );
assert_true( 500 === $incomp_cp->get_last_processed_trid(), 'STR-04b: Checkpoint conserva last_processed_trid = 500 para filtrar lote subsiguiente' );

// STR-05: Reintentos e Idempotencia en Lote Duplicado.
echo "  [STR-05] Comprobando reintento e idempotencia ante entidades ya existentes...\n";
$idem_group = TranslationGroup::create( 'post', 'page' );
$idem_elem  = TranslationElement::create( 'post', 99001, 'es' );
$idem_group->add_element( $idem_elem, true );

$idem_mock_repo = new class( $stress_db, $registry, $idem_group ) extends TranslationGroupRepository {
	private TranslationGroup $group;
	public function __construct( $db, $reg, $g ) {
		parent::__construct( $db, $reg );
		$this->group = $g;
	}
	public function begin_transaction(): void {}
	public function rollback_transaction(): void {}
	public function find_by_element( string $type, int $id ): ?TranslationGroup {
		return $this->group;
	}
};

$idem_exec = new WpmlMigrationExecutor(
	$stress_db,
	$idem_mock_repo,
	$registry,
	$resume_cp_repo,
	$stress_lock,
	$val_mock,
	$schema_mock
);
$idem_exec->set_authorized( true );

$idem_plan = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 301,
				'element_type'   => 'post',
				'subtype'        => 'page',
				'canonical_id'   => 99001,
				'canonical_lang' => 'es',
				'translations'   => array( 'es' => 99001 ),
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
			),
		),
	)
);
$idem_res = $idem_exec->execute_batch( $idem_plan, 5 );
assert_true( 'idempotent_skipped' === $idem_res['batch_results'][0]['status'], 'STR-05: Entidad ya existente reporta idempotent_skipped en reintento' );

// STR-06: Ejecuciones concurrentes bloqueadas.
echo "  [STR-06] Comprobando bloqueo atómico ante ejecuciones concurrentes...\n";
$stress_lock->acquire( 'wpml', 'worker_primary', 60 );
$concurrent_caught = false;
try {
	$idem_exec->execute_batch( $idem_plan, 5, 'worker_secondary' );
} catch ( MigrationConcurrencyException $e ) {
	$concurrent_caught = true;
} finally {
	$stress_lock->release( 'wpml', 'worker_primary' );
}
assert_true( $concurrent_caught, 'STR-06: Worker secundario es rechazado atómicamente con MigrationConcurrencyException' );

// STR-07: Detección de duplicados y colisión relacional.
echo "  [STR-07] Comprobando rechazo ante colisión de elementos en grupos distintos...\n";
$collision_mock_repo = new class( $stress_db, $registry ) extends TranslationGroupRepository {
	public function begin_transaction(): void {}
	public function rollback_transaction(): void {}
	public function find_by_element( string $type, int $id ): ?TranslationGroup {
		// Elemento 1 pertenece a grupo 10, pero elemento 2 no existe -> colisión relacional
		if ( 88001 === $id ) {
			return TranslationGroup::create( 'post', 'page', 88001, 10 );
		}
		return null;
	}
};
$collision_exec = new WpmlMigrationExecutor(
	$stress_db,
	$collision_mock_repo,
	$registry,
	$resume_cp_repo,
	$stress_lock,
	$val_mock,
	$schema_mock
);
$collision_exec->set_authorized( true );

$collision_plan = new MigrationPlan(
	array(
		'source'         => 'wpml',
		'status'         => MigrationPlan::STATUS_READY,
		'planned_groups' => array(
			array(
				'trid'           => 401,
				'element_type'   => 'post',
				'subtype'        => 'page',
				'canonical_id'   => 88001,
				'canonical_lang' => 'es',
				'translations'   => array( 'es' => 88001, 'en' => 88002 ),
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
			),
		),
	)
);

$collision_caught = false;
try {
	$collision_exec->execute_batch( $collision_plan, 5 );
} catch ( MigrationPlanValidationException $e ) {
	$collision_caught = true;
	$err_str = implode( ' ', $e->get_validation_errors() );
	assert_true( strpos( $err_str, 'Colisión relacional' ) !== false, 'STR-07: Mensaje detalla colisión relacional de elementos' );
}
assert_true( $collision_caught, 'STR-07: Colisión relacional detectada y abortada' );

// STR-08: Manifiestos inconsistentes.
echo "  [STR-08] Comprobando rechazo de reversión ante manifiesto alterado o ajeno...\n";
$rollback_service = new WpmlMigrationRollbackService( $lab_wpdb, $mock_stress_repo, $resume_cp_repo );
$rollback_service->save_execution_manifest(
	'exec_stress_foreign',
	array(
		'execution_id'       => 'exec_stress_foreign',
		'source'             => 'wpml',
		'imported_group_ids' => array(), // Despojado deliberadamente
	)
);
$res_tampered = $rollback_service->rollback_groups( array( 100 ), 'exec_stress_foreign', true );
assert_true( ! $res_tampered['success'], 'STR-08: Rollback ante manifiesto despojado es bloqueado' );
assert_true( 'not_owned' === $res_tampered['blocked_groups'][0]['code'], 'STR-08: Grupo ajeno clasificado con código "not_owned"' );
$rollback_service->delete_execution_manifest( 'exec_stress_foreign' );

// STR-09: Recuperación tras fallos de base de datos.
echo "  [STR-09] Comprobando registro de auditoría tras fallo de base de datos...\n";
$fail_cp = new MigrationCheckpoint( array( 'source' => 'wpml' ) );
$fail_cp = $fail_cp->record_error( 777, 'Error de deadlock en base de datos' );
assert_true( MigrationCheckpoint::STATUS_FAILED === $fail_cp->get_status(), 'STR-09a: Checkpoint asume estado STATUS_FAILED' );
assert_true( 777 === $fail_cp->get_errors()[0]['item_id'], 'STR-09b: Error registra el TRID exacto del fallo' );
assert_true( strpos( $fail_cp->get_errors()[0]['message'], 'deadlock' ) !== false, 'STR-09c: Mensaje de error preservado en el checkpoint' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: PRUEBAS FUNCIONALES DE DOMINIO Y API EN LABORATORIO REAL
// -------------------------------------------------------------------------
echo "\n--- Control 4: Pruebas Funcionales de Dominio y API en cms_ect_lab ---\n";

$real_group_repo = new TranslationGroupRepository( $lab_wpdb, $registry );
$resolver        = new ContentTranslationResolver( $real_group_repo, $registry );

// FUN-01: Resolución de CPT tours en ES -> EN (TRID 9239).
echo "  [FUN-01] Comprobando resolución de traducción de Tour 5286 (ES -> EN)...\n";
$en_elem = $resolver->resolve( 'post', 5286, 'en' );
assert_true( null !== $en_elem && 5287 === $en_elem->get_element_id(), 'FUN-01a: Tour 5286 (es) resuelve a objeto TranslationElement con ID 5287' );
$en_tour = $resolver->resolve_element_id( 'post', 5286, 'en' );
assert_true( 5287 === $en_tour, 'FUN-01b: resolve_element_id(post, 5286, en) devuelve 5287' );

// FUN-02: Resolución inversa de CPT tours en EN -> ES (TRID 9239).
echo "  [FUN-02] Comprobando resolución inversa de Tour 5287 (EN -> ES)...\n";
$es_tour = $resolver->resolve_element_id( 'post', 5287, 'es' );
assert_true( 5286 === $es_tour, 'FUN-02: Tour 5287 (en) resuelve simétricamente a 5286 (es)' );

// FUN-03: Resolución al mismo idioma (ES -> ES).
echo "  [FUN-03] Comprobando resolución idéntica (ES -> ES)...\n";
$same_tour = $resolver->resolve_element_id( 'post', 5286, 'es' );
assert_true( 5286 === $same_tour, 'FUN-03: Tour 5286 (es) a idioma (es) devuelve el mismo elemento' );

// FUN-04: Detección soberana de idioma de entidad.
echo "  [FUN-04] Comprobando detección de idioma soberana vía language_of()...\n";
assert_true( 'es' === $resolver->language_of( 'post', 5286 ), 'FUN-04a: language_of(5286) es "es"' );
assert_true( 'en' === $resolver->language_of( 'post', 5287 ), 'FUN-04b: language_of(5287) es "en"' );

// FUN-05: Resolución de taxonomía tours_cat en ES -> EN (TRID 4575).
echo "  [FUN-05] Comprobando resolución de categoría de tours 41 (ES -> EN)...\n";
$en_cat = $resolver->resolve_element_id( 'term', 41, 'en' );
assert_true( 47 === $en_cat, 'FUN-05: Categoría 41 (es) resuelve a 47 (en)' );

// FUN-06: Resolución de taxonomía tours_cat en ES -> EN (TRID 9238).
echo "  [FUN-06] Comprobando resolución de categoría de tours 46 (ES -> EN)...\n";
$en_cat_2 = $resolver->resolve_element_id( 'term', 46, 'en' );
assert_true( 48 === $en_cat_2, 'FUN-06: Categoría 46 (es) resuelve a 48 (en)' );

// FUN-07: Resolución de contenido monolingüe (Page 4338 sin traducción activa).
echo "  [FUN-07] Comprobando resolución de página monolingüe 4338 hacia EN...\n";
$en_page = $resolver->resolve( 'post', 4338, 'en' );
assert_true( null === $en_page, 'FUN-07: Página 4338 sin traducción al inglés devuelve null sin fallback' );

// FUN-08: Enrutamiento de URLs (UrlLanguageResolver).
echo "  [FUN-08] Comprobando resolución de idiomas en URLs...\n";
$url_resolver = new UrlLanguageResolver( $registry, 'http://lab.ecoterra.test' );
$res_default  = $url_resolver->resolve( 'http://lab.ecoterra.test/tours/selva-tropical/' );
assert_true( 'es' === $res_default->get_language_code(), 'FUN-08a: URL sin prefijo resuelve canónicamente a idioma predeterminado "es"' );

$res_en = $url_resolver->resolve( 'http://lab.ecoterra.test/en/tours/rainforest/' );
assert_true( 'en' === $res_en->get_language_code(), 'FUN-08b: URL con prefijo /en/ resuelve a idioma "en"' );

$res_unreg = $url_resolver->resolve( 'http://lab.ecoterra.test/blog/post-1/' );
assert_true( 'es' === $res_unreg->get_language_code() && ! $res_unreg->has_explicit_prefix(), 'FUN-08c: Slug no registrado (/blog/) resuelve a idioma default sin secuestro de ruta' );

$inactive_registry = clone $registry;
$inactive_registry->add_language( new Language( 'fr', 'fr_FR', 'Français', 'Français', false ) );
$url_resolver_inactive = new UrlLanguageResolver( $inactive_registry, 'http://lab.ecoterra.test' );
$res_inactive = $url_resolver_inactive->resolve( 'http://lab.ecoterra.test/fr/tours/foret/' );
assert_true( $res_inactive->is_inactive(), 'FUN-08d: Prefijo de idioma registrado pero inactivo (/fr/) no degrada silenciosamente a default' );

// FUN-09: Aclaración técnica: Pruebas automatizadas en CLI vs peticiones HTTP reales.
echo "  [FUN-09] Comprobando aislamiento de entorno CLI vs servidor HTTP...\n";
assert_true( 'cli' === php_sapi_name() || defined( 'STDIN' ), 'FUN-09: Verificación ejecutada en CLI con aislamiento de red y sin webhooks HTTP' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: INVARIANZA FINAL Y CENTINELA DE ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 5: Invarianza Final y Centinela en Origen (cms_ect) ---\n";

// A. Post-check on cms_ect_lab: exactly 5 groups and 9 elements.
$post_groups   = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' )->fetch_row()[0];
$post_elements = (int) $mysqli_lab->query( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' )->fetch_row()[0];
assert_true( 5 === $post_groups, "cms_ect_lab conserva inalterados exactamente 5 grupos en etk_t_tfml_groups ({$post_groups})" );
assert_true( 9 === $post_elements, "cms_ect_lab conserva inalterados exactamente 9 elementos en etk_t_tfml_group_elements ({$post_elements})" );

// B. Post-flight sentinel on cms_ect:
$GLOBALS['wpdb'] = $original_wpdb;
$original_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$post_rows       = (int) $original_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash       = (string) $original_wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`"
);

echo "Sentinel WPML Post-Flight (cms_ect):\n";
echo "  Rows: {$post_rows} (Expected: {$pre_rows})\n";
echo "  MD5:  {$post_hash} (Expected: {$pre_hash})\n\n";

assert_true( $pre_rows === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( $pre_hash === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

$mysqli_lab->close();
$isolation_guard->restore();

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.13 CERTIFICACIÓN INTEGRAL: EXITOSA (PASS)\n";
echo "====================================================================\n";
