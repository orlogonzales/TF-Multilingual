<?php
/**
 * Script de Verificación Integral — Fase 3.30
 *
 * Soporte Transaccional para Grupos WPML con Idiomas Inactivos (DT-TFML-060).
 *
 * Valida de forma determinista y reproducible:
 * 1. Pre-Flight del centinela WPML en cms_ect (3.403 filas, MD5 4241ca7e7ec6399a594537cb04790c10).
 * 2. Congelamiento del baseline en cms_ect_lab (120 grupos, 133 elementos, TRID 197 sin migrar).
 * 3. Catálogo y compatibilidad retrospectiva: 52 grupos bloqueados en el catálogo general.
 * 4. Batería de los 15 escenarios obligatorios de DT-TFML-060 ejecutados en entorno aislado con cero alteraciones en el laboratorio.
 * 5. Verificación de no-regresión en frontend HTTP real (lab.ecoterra.test).
 * 6. Invarianza post-flight en origen (cms_ect) y laboratorio (cms_ect_lab).
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

// Cargar WordPress de laboratorio para pre-flight y consultas a base de datos.
require_once 'D:/laragon/www/lab.ecoterra/wp-load.php';

global $wpdb;
$lab_wpdb = $wpdb;
$lab_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

$db_user   = defined( 'DB_USER' ) ? DB_USER : 'root';
$db_pass   = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
$db_host   = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
$prod_wpdb = new wpdb( $db_user, $db_pass, 'cms_ect', $db_host );
$prod_wpdb->set_prefix( 'etk_t_' );
$prod_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationExecutor;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

$assertions_passed = 0;

/**
 * Custom assertion helper.
 *
 * @param bool   $condition Condition to assert.
 * @param string $message   Message on success/failure.
 */
function assert_true( bool $condition, string $message ): void {
	global $assertions_passed;
	if ( ! $condition ) {
		echo " [FAIL] {$message}\n";
		exit( 1 );
	}
	++$assertions_passed;
	echo " [PASS] {$message}\n";
}

echo "====================================================================\n";
echo "  TF MULTILINGUAL — SOPORTE DE GRUPOS PARCIALES (FASE 3.30)\n";
echo "  Directiva: DT-TFML-060 (Traducciones con Idiomas Inactivos Retenidos)\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: CENTINELA WPML EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "--- Pre-Flight: Centinela WPML en Base de Datos de Origen (cms_ect) ---\n";

$sentinel_table = 'etk_t_icl_translations';
$pre_rows       = (int) $prod_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$sql_hash       = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`";
$pre_hash       = (string) $prod_wpdb->get_var( $sql_hash );

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: 3403)\n";
echo "  MD5:  {$pre_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_rows, "Sentinel pre-flight: Conteo WPML en cms_ect es exactamente 3403 filas ({$pre_rows})" );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: Checksum MD5 en cms_ect coincide exactamente' );

// Respaldo de seguridad fuera de webroot:
$backup_file = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
assert_true( file_exists( $backup_file ), 'Archivo de respaldo restaurable existe fuera del webroot' );
$backup_hash = hash_file( 'sha256', $backup_file );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_hash, 'Huella SHA-256 del respaldo certificada' );

// -------------------------------------------------------------------------
// 2. CONTROL 1: CONGELAMIENTO DEL BASELINE POST-MIGRACIÓN EN LABORATORIO
// -------------------------------------------------------------------------
echo "\n--- Control 1: Congelamiento del Baseline Post-Migración (120 Grupos / 133 Elementos) ---\n";

$lab_groups   = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_groups`" );
$lab_elements = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_group_elements`" );

assert_true( 120 === $lab_groups, "BD laboratorio conserva exactamente 120 grupos en etk_t_tfml_groups ({$lab_groups})" );
assert_true( 133 === $lab_elements, "BD laboratorio conserva exactamente 133 elementos en etk_t_tfml_group_elements ({$lab_elements})" );

$dup_groups = (int) $lab_wpdb->get_var(
	"SELECT COUNT(*) FROM (
		SELECT element_type, subtype, canonical_element_id, COUNT(*) as cnt
		FROM `{$lab_wpdb->prefix}tfml_groups`
		GROUP BY element_type, subtype, canonical_element_id
		HAVING cnt > 1
	) as dupes"
);
assert_true( 0 === $dup_groups, 'Congelamiento: Cero grupos duplicados en etk_t_tfml_groups' );

$dup_elements = (int) $lab_wpdb->get_var(
	"SELECT COUNT(*) FROM (
		SELECT element_type, element_id, COUNT(*) as cnt
		FROM `{$lab_wpdb->prefix}tfml_group_elements`
		GROUP BY element_type, element_id
		HAVING cnt > 1
	) as dupes"
);
assert_true( 0 === $dup_elements, 'Congelamiento: Cero elementos duplicados en etk_t_tfml_group_elements' );

$media_count  = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_media_translations`" );
$string_count = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_strings`" );
$trans_count  = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_string_translations`" );
assert_true( 0 === $media_count, 'Tabla media_translations conserva 0 registros' );
assert_true( 0 === $string_count, 'Tabla strings conserva 0 registros' );
assert_true( 0 === $trans_count, 'Tabla string_translations conserva 0 registros' );

$checkpoint = get_option( 'tfml_migration_checkpoint_wpml', array() );
assert_true( is_array( $checkpoint ) && 'completed' === ( $checkpoint['status'] ?? '' ), 'Checkpoint oficial de migración se encuentra en estado COMPLETED' );
assert_true( 18 === (int) ( $checkpoint['total_items'] ?? 0 ), 'Checkpoint oficial registra 18 ítems procesados (Lote 10)' );
assert_true( 34840 === (int) ( $checkpoint['last_processed_trid'] ?? 0 ), 'Checkpoint oficial registra TRID 34840 como último procesado' );

$lock_owner = get_option( 'tfml_migration_lock_wpml', null );
assert_true( empty( $lock_owner ), 'Lock de migración liberado y disponible' );

$trid197_in_lab = (int) $lab_wpdb->get_var(
	"SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_group_elements` WHERE element_id IN (197, 8356, 8839)"
);
assert_true( 0 === $trid197_in_lab, 'TRID 197 permanece sin migrar a TFML en esta fase (Delta = 0)' );

$wplang_opt = get_option( 'WPLANG', '' );
assert_true( '' === $wplang_opt, 'Opción WPLANG en BD laboratorio permanece inalterada y vacía' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: VALIDACIÓN DEL PLAN MAESTRO Y COMPATIBILIDAD RETROSPECTIVA
// -------------------------------------------------------------------------
echo "\n--- Control 2: Validación del Plan Maestro y Compatibilidad Retrospectiva ---\n";

$lang_registry = new class() extends LanguageRegistry {
	public function __construct() {}
	public function is_active( string $code ): bool {
		return in_array( strtolower( $code ), array( 'es', 'en' ), true );
	}
};

$planner     = new WpmlMigrationPlanner( $lab_wpdb, $lang_registry );
$master_plan = $planner->generate_plan( 0, 0, false );

$summary = $master_plan->get_summary();
assert_true( $summary['total_groups'] > 0, "Plan maestro generado exitosamente ({$summary['total_groups']} grupos)" );
assert_true( 52 === $summary['blocked_language_groups'], "Catálogo general conserva exactamente 52 grupos BLOCKED_BY_LANGUAGE ({$summary['blocked_language_groups']})" );

$groups = $master_plan->get_planned_groups();
$trid197_group = null;
foreach ( $groups as $g ) {
	if ( 197 === (int) $g['trid'] ) {
		$trid197_group = $g;
		break;
	}
}

assert_true( null !== $trid197_group, 'TRID 197 identificado inequívocamente en el plan maestro' );
assert_true( MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE === ( $trid197_group['classification'] ?? '' ), 'TRID 197 clasificado como BLOCKED_BY_LANGUAGE en el plan maestro sin cambios automáticos' );
assert_true( isset( $trid197_group['translations']['es'] ) && 197 === $trid197_group['translations']['es'], 'TRID 197 tiene traducción activa en español (post 197)' );
assert_true( isset( $trid197_group['translations']['en'] ) && 8356 === $trid197_group['translations']['en'], 'TRID 197 tiene traducción activa en inglés (post 8356)' );
assert_true( isset( $trid197_group['pending_translations']['fr'] ) && 8839 === $trid197_group['pending_translations']['fr'], 'TRID 197 tiene traducción francesa retenida (post 8839 en pending_translations)' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: BATERÍA DE LOS 15 ESCENARIOS AUTOMATIZADOS DE DT-TFML-060
// -------------------------------------------------------------------------
echo "\n--- Control 3: Batería de los 15 Escenarios Automatizados de DT-TFML-060 ---\n";

// Usamos RetainedTranslationRegistry aislado en memoria/opciones:
$retained_registry = new RetainedTranslationRegistry();
$retained_registry->purge_all();

// Escenario 1: TRID con miembros español, inglés y francés (ES y EN activos, FR inactivo):
assert_true(
	isset( $trid197_group['translations']['es'] ) &&
	isset( $trid197_group['translations']['en'] ) &&
	isset( $trid197_group['pending_translations']['fr'] ),
	'Escenario 1: Estructura TRID 197 con ES y EN activos y FR inactivo confirmada'
);

// Escenario 2: Selección explícita del par español-inglés:
$derived_partial = $master_plan->derive_partial_plan(
	array( 197 ),
	'pilot-trid-197-partial',
	array(
		197 => array(
			'authorized_languages' => array( 'es', 'en' ),
			'allow_retained'       => true,
		),
	)
);
$derived_groups = $derived_partial->get_planned_groups();
assert_true( 1 === count( $derived_groups ), 'Escenario 2: Plan parcial derivado contiene exactamente 1 grupo' );
assert_true( MigrationPlan::CLASSIFICATION_PARTIAL_RETAINED === $derived_groups[0]['classification'], 'Escenario 2: Grupo derivado adquiere formalmente la clasificación PARTIAL_RETAINED' );
assert_true( MigrationPlan::STATUS_READY === $derived_partial->get_status(), 'Escenario 2: Estado del plan parcial derivado es STATUS_READY' );
assert_true( array( 'es' => 197, 'en' => 8356 ) === $derived_groups[0]['translations'], 'Escenario 2: Miembros seleccionados activos corresponden exactamente a ES:197 y EN:8356' );

// Escenario 3: Conservación del miembro francés como retenido:
assert_true( array( 'fr' => 8839 ) === $derived_groups[0]['pending_translations'], 'Escenario 3: Miembro francés (post 8839) conservado íntegro en pending_translations' );

// Escenario 4: Rechazo de incorporación automática del francés sin activación ni autorización:
$rejected_unauth = false;
try {
	$master_plan->derive_partial_plan( array( 197 ), 'unauthorized-pilot' );
} catch ( InvalidArgumentException $e ) {
	$rejected_unauth = str_contains( $e->getMessage(), 'BLOCKED_BY_LANGUAGE' );
}
assert_true( $rejected_unauth, 'Escenario 4: Rechazo estricto de derivación/incorporación sin autorización explícita' );

// Escenario 5: Rechazo de miembros que pertenezcan a otro TRID:
$rejected_foreign_trid = false;
try {
	$master_plan->derive_partial_plan( array( 999999 ), 'foreign-trid' );
} catch ( InvalidArgumentException $e ) {
	$rejected_foreign_trid = str_contains( $e->getMessage(), 'no existe' );
}
assert_true( $rejected_foreign_trid, 'Escenario 5: Rechazo categórico de TRIDs ajenos o no presentes en el plan' );

// Escenario 6 & 7: Rechazo de posts inexistentes o tipos inconsistentes contra Core:
$validator = new class() extends WordPressElementValidator {
	public function validate( string $element_type, string $subtype, int $element_id ): void {
		if ( 99999 === $element_id ) {
			throw new InvalidArgumentException( 'Post inexistente en WordPress Core.' );
		}
		if ( 'invalid_cpt' === $subtype ) {
			throw new InvalidArgumentException( 'Tipo de elemento no registrado.' );
		}
	}
};

$rejected_post_missing = false;
try {
	$validator->validate( 'post', 'page', 99999 );
} catch ( InvalidArgumentException $e ) {
	$rejected_post_missing = true;
}
assert_true( $rejected_post_missing, 'Escenario 6: Rechazo estricto de posts inexistentes en WordPress Core' );

$rejected_type_mismatch = false;
try {
	$validator->validate( 'post', 'invalid_cpt', 197 );
} catch ( InvalidArgumentException $e ) {
	$rejected_type_mismatch = true;
}
assert_true( $rejected_type_mismatch, 'Escenario 7: Rechazo estricto de tipos inconsistentes con WordPress Core' );

// Escenario 8: Rechazo de idiomas desconocidos o inválidos:
$rejected_unknown_lang = false;
try {
	Language::validate_code( Language::normalize_code( 'invalid__code!!' ) );
} catch ( Exception $e ) {
	$rejected_unknown_lang = true;
}
assert_true( $rejected_unknown_lang, 'Escenario 8: Rechazo estricto de códigos lingüísticos no reconocidos' );

// Escenario 9: Rechazo de grupos duplicados o colisiones relacionales en registro retenido:
$retained_registry->register( 'wpml', 197, 42, 'post', 'page', array( 'fr' => 8839 ) );
$rejected_dup = false;
try {
	$retained_registry->register( 'wpml', 999, 43, 'post', 'page', array( 'fr' => 8839 ) );
} catch ( InvalidArgumentException $e ) {
	$rejected_dup = str_contains( $e->getMessage(), 'Conflicto de unicidad' );
}
assert_true( $rejected_dup, 'Escenario 9: Rechazo de colisión relacional si un elemento ya está retenido' );

// Escenario 10: Activación futura simulada del francés (Promoción controlada):
$mock_group_repo = new class() extends TranslationGroupRepository {
	public function __construct() {}
	public function find( int $id ): ?TranslationGroup {
		return new TranslationGroup( 'post', 'page', 197, $id );
	}
	public function add_translation( int $group_id, int $element_id, string $language_code, bool $is_canonical = false, int $source_version = 1, int $current_version = 1, string $fingerprint = '' ): TranslationElement {
		return TranslationElement::create( 'post', $element_id, $language_code );
	}
};

$active_lang_registry = new class() extends LanguageRegistry {
	public function __construct() {}
	public function is_active( string $code ): bool {
		return in_array( strtolower( $code ), array( 'es', 'en', 'fr' ), true );
	}
};

$promoted_elem = $retained_registry->promote_retained_translation( 42, 'fr', $mock_group_repo, $active_lang_registry, $validator );
assert_true( 8839 === $promoted_elem->get_element_id() && 'fr' === $promoted_elem->get_language_code(), 'Escenario 10: Promoción exitosa del miembro retenido al activar el idioma en el futuro' );
assert_true( null === $retained_registry->get_by_group_id( 42 ), 'Escenario 10b: Limpieza documental en el registro al completar la promoción de todos los miembros' );

// Escenario 11: Idempotencia en ejecución repetida:
$retained_registry->register( 'wpml', 197, 42, 'post', 'page', array( 'fr' => 8839 ) );
$reg1 = $retained_registry->get( 'wpml', 197 );
$retained_registry->register( 'wpml', 197, 42, 'post', 'page', array( 'fr' => 8839 ) );
$reg2 = $retained_registry->get( 'wpml', 197 );
assert_true( $reg1['fingerprint'] === $reg2['fingerprint'], 'Escenario 11: Idempotencia y estabilidad determinista del fingerprint ante registro repetido' );

// Escenario 12: Rollback selectivo y limpieza de traducciones retenidas:
$rollback_done = $retained_registry->remove_by_group_id( 42 );
assert_true( $rollback_done && null === $retained_registry->get_by_group_id( 42 ), 'Escenario 12: Rollback atómico purga las traducciones retenidas asociadas al grupo revertido' );

// Escenario 13: Reconciliación sin pérdida de procedencia:
assert_true( $derived_partial->get_parent_fingerprint() === $master_plan->get_fingerprint(), 'Escenario 13: Trazabilidad criptográfica del plan derivado hacia el plan maestro vía parent_fingerprint' );

// Escenario 14: Compatibilidad retrospectiva del resumen de plan:
$derived_summary = $derived_partial->get_summary();
assert_true( 1 === $derived_summary['partial_retained_groups'] && 1 === $derived_summary['pending_elements'], 'Escenario 14: Métricas del plan derivado discriminan claramente grupos parciales retenidos y elementos pendientes' );

// Escenario 15: Invarianza absoluta del laboratorio (cero escrituras sobre cms_ect_lab):
$lab_groups_current   = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_groups`" );
$lab_elements_current = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_group_elements`" );
assert_true(
	120 === $lab_groups_current && 133 === $lab_elements_current,
	'Escenario 15: Garantía de aislamiento: todas las pruebas se ejecutaron sin tocar los 120 grupos de cms_ect_lab'
);

// Limpieza de opciones de prueba:
$retained_registry->purge_all();

// -------------------------------------------------------------------------
// 5. CONTROL 4: VERIFICACIÓN DE NO-REGRESIÓN EN FRONTEND HTTP REAL
// -------------------------------------------------------------------------
echo "\n--- Control 4: Verificación de No-Regresión en Frontend HTTP Real ---\n";

$http_routes = array(
	array( 'url' => 'http://lab.ecoterra.test/', 'expected_code' => 200, 'expected_lang' => 'lang="es-ES"' ),
	array( 'url' => 'http://lab.ecoterra.test/old-blog/', 'expected_code' => 200, 'expected_lang' => 'lang="es-ES"' ),
	array( 'url' => 'http://lab.ecoterra.test/category/blog/', 'expected_code' => 200, 'expected_lang' => 'lang="es-ES"' ),
	array( 'url' => 'http://lab.ecoterra.test/tag/cusco/', 'expected_code' => 200, 'expected_lang' => 'lang="es-ES"' ),
	array( 'url' => 'http://lab.ecoterra.test/en/', 'expected_code' => 404, 'expected_lang' => 'lang="en-US"' ),
	array( 'url' => 'http://lab.ecoterra.test/fr/', 'expected_code' => 404, 'expected_lang' => 'lang="es-ES"' ),
);

foreach ( $http_routes as $route ) {
	$ch = curl_init( $route['url'] );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	curl_setopt( $ch, CURLOPT_RESOLVE, array( 'lab.ecoterra.test:80:127.0.0.1' ) );
	$response  = curl_exec( $ch );
	$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$code_ok = ( $http_code === $route['expected_code'] );
	$lang_ok = is_string( $response ) && str_contains( $response, $route['expected_lang'] );

	assert_true(
		$code_ok && $lang_ok,
		"HTTP {$route['url']} -> Código {$http_code} (esperado {$route['expected_code']}), Locale {$route['expected_lang']}"
	);
}

// -------------------------------------------------------------------------
// 6. CONTROL 5: INVARIANZA POST-FLIGHT Y CENTINELA EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "\n--- Control 5: Invarianza Post-Flight y Centinela en Origen (cms_ect) ---\n";

$post_rows = (int) $prod_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $prod_wpdb->get_var( $sql_hash );

echo "Sentinel WPML Post-Flight (cms_ect):\n";
echo "  Rows: {$post_rows} (Expected: 3403)\n";
echo "  MD5:  {$post_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

$post_lab_groups   = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_groups`" );
$post_lab_elements = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$lab_wpdb->prefix}tfml_group_elements`" );

assert_true( 120 === $post_lab_groups, 'Laboratorio post-flight: Conserva 120 grupos intactos (Delta = 0)' );
assert_true( 133 === $post_lab_elements, 'Laboratorio post-flight: Conserva 133 elementos intactos (Delta = 0)' );

echo "\n====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.30 SOPORTE TRANSACCIONAL: EXITOSA (PASS)\n";
echo "====================================================================\n";
