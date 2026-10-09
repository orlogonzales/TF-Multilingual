<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 2.8 (Diagnóstico, Health Check y Cierre del Core)
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
use TF\Multilingual\Diagnostic\DiagnosticService;
use TF\Multilingual\Admin\DiagnosticUi;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Infrastructure\Lifecycle;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'WordPress environment not found.' );
}

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 2.8 (HEALTH CHECK & CORE)\n";
echo "====================================================================\n\n";

$assertions_passed = 0;
$assertions_failed = 0;

function assert_true( bool $condition, string $message ): void {
	global $assertions_passed, $assertions_failed;
	if ( $condition ) {
		echo " [PASS] $message\n";
		$assertions_passed++;
	} else {
		echo " [FAIL] $message\n";
		$assertions_failed++;
	}
}

// 0. Sentinel Pre-Flight Check
$wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
$table_icl = $wpdb->prefix . 'icl_translations';
$pre_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_icl}`" );
$pre_md5   = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$table_icl}`"
);

echo "Sentinel WPML Pre-Flight:\n";
echo "  Rows: $pre_count (Expected: 3403)\n";
echo "  MD5:  $pre_md5 (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_count, 'Sentinel pre-flight: WPML row count is exactly 3403' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_md5, 'Sentinel pre-flight: WPML checksum matches' );

// 1. Isolate external plugin interceptors
$guard = new ExternalPluginIsolationGuard();
$isolated_count = $guard->isolate();
echo "External Plugin Isolation Guard active: isolated $isolated_count third-party hooks.\n\n";

// Track fixtures for deterministic cleanup
$created_posts  = array();
$created_terms  = array();
$created_groups = array();

try {
	// Initialize plugin subsystems first
	$plugin = Plugin::get_instance();
	$plugin->init();

	// Initialize REST server and trigger route registration
	rest_get_server();
	do_action( 'rest_api_init' );
	$plugin->get_rest_api_registrar()->register_routes();

	$editorial_service = $plugin->get_editorial_service();
	$group_repo        = new TranslationGroupRepository();
	$language_registry = $plugin->get_language_registry();

	// Find an admin user for authenticated requests
	$admin_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );

	// =========================================================================
	// SECCION 1: Verificación de Seguridad REST Acotada (Anti-IDOR en Hermanos)
	// =========================================================================
	echo "--- SECCION 1: Seguridad REST Acotada (Anti-IDOR en Elementos Hermanos) ---\n";

	// Asegurar usuario admin para crear fixtures y vincular
	wp_set_current_user( $admin_id );

	// Crear Post 1 (ES, publish, canonical)
	$post_es_id = wp_insert_post(
		array(
			'post_title'   => 'Live Post Public ES 2.8 ' . time(),
			'post_content' => 'Contenido público en español.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_es_id;

	// Crear Post 2 (EN, draft, hermano)
	$post_en_id = wp_insert_post(
		array(
			'post_title'   => 'Live Post Draft EN 2.8 ' . time(),
			'post_content' => 'Draft content in English.',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_en_id;

	// Vincular en grupo vía REST endpoint
	$req_link1 = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/link' );
	$req_link1->set_body_params(
		array(
			'element_type'    => 'post',
			'source_id'       => $post_es_id,
			'target_id'       => $post_en_id,
			'target_language' => 'en',
			'source_language' => 'es',
		)
	);
	$resp_link1 = rest_do_request( $req_link1 );
	$d_link1    = $resp_link1->get_data();
	if ( ! empty( $d_link1['group_id'] ) ) {
		$created_groups[] = (int) $d_link1['group_id'];
	}

	// Petición anónima a post público ES
	wp_set_current_user( 0 );
	$req_public = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/' . $post_es_id );
	$resp_public = rest_do_request( $req_public );

	assert_true( 200 === $resp_public->get_status(), 'GET /translations responde 200 OK para post público' );
	$data_public = $resp_public->get_data();
	$trans_public = (array) $data_public['translations'];

	assert_true( isset( $trans_public['es'] ), 'Post público ES consta en las traducciones' );
	assert_true( ! isset( $trans_public['en'] ), 'Post borrador EN NO es revelado al usuario anónimo (Anti-IDOR)' );
	assert_true( in_array( 'en', $data_public['untranslated_languages'], true ), 'Idioma EN aparece en untranslated_languages para anónimo' );

	// Petición de editor autenticado al mismo post ES
	wp_set_current_user( $admin_id );
	$req_editor = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/' . $post_es_id );
	$resp_editor = rest_do_request( $req_editor );

	assert_true( 200 === $resp_editor->get_status(), 'Editor autenticado recibe 200 OK' );
	$data_editor = $resp_editor->get_data();
	$trans_editor = (array) $data_editor['translations'];

	assert_true( isset( $trans_editor['es'] ) && isset( $trans_editor['en'] ), 'Editor autenticado ve ambas traducciones (es y en)' );
	assert_true( (int) $trans_editor['en']['element_id'] === $post_en_id, 'Traducción borrador EN contiene ID correcto para editor' );

	// Crear Post 3: Draft ES (canonical), Post 4: Publish EN (traducción pública)
	$post_draft_canon = wp_insert_post(
		array(
			'post_title'   => 'Draft Canonical ES 2.8 ' . time(),
			'post_content' => 'Borrador canónico en español.',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_draft_canon;

	$post_pub_trans = wp_insert_post(
		array(
			'post_title'   => 'Public Trans EN 2.8 ' . time(),
			'post_content' => 'Public translation in English.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_pub_trans;

	wp_set_current_user( $admin_id );
	$req_link2 = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/link' );
	$req_link2->set_body_params(
		array(
			'element_type'    => 'post',
			'source_id'       => $post_draft_canon,
			'target_id'       => $post_pub_trans,
			'target_language' => 'en',
			'source_language' => 'es',
		)
	);
	$resp_link2 = rest_do_request( $req_link2 );
	$d_link2    = $resp_link2->get_data();
	if ( ! empty( $d_link2['group_id'] ) ) {
		$created_groups[] = (int) $d_link2['group_id'];
	}

	// Petición anónima sobre post público inglés (cuyo canonical es borrador)
	wp_set_current_user( 0 );
	$req_canon_check = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/' . $post_pub_trans );
	$resp_canon_check = rest_do_request( $req_canon_check );
	$data_canon_check = $resp_canon_check->get_data();

	assert_true( null === $data_canon_check['canonical_element_id'], 'Canonical borrador es enmascarado (null) en /translations para anónimo' );
	assert_true( null === $data_canon_check['canonical_language'], 'Idioma canonical borrador es enmascarado (null) para anónimo' );

	// Status endpoint canonical masking
	$req_status_check = new WP_REST_Request( 'GET', '/tf-multilingual/v1/status/post/' . $post_pub_trans );
	$resp_status_check = rest_do_request( $req_status_check );
	$data_status_check = $resp_status_check->get_data();

	assert_true( null === $data_status_check['canonical_element_id'], 'Canonical ID en /status es enmascarado (null) si es privado' );
	assert_true( null === $data_status_check['canonical_version'], 'Canonical versión en /status es enmascarada (null) si es privado' );

	// =========================================================================
	// SECCION 2: DiagnosticService y Reportes de Salud
	// =========================================================================
	echo "\n--- SECCION 2: DiagnosticService y Health Check ---\n";

	$diag_service = $plugin->get_diagnostic_service();
	assert_true( null !== $diag_service, 'DiagnosticService está instanciado y disponible' );

	$env_report = $diag_service->get_environment_report();
	assert_true( true === $env_report['php_compatible'], 'Entorno: Versión PHP es compatible (>= 8.1)' );
	assert_true( true === $env_report['wordpress_compatible'], 'Entorno: Versión WordPress es compatible (>= 6.8)' );
	assert_true( true === $env_report['all_extensions_loaded'], 'Entorno: Todas las extensiones requeridas están cargadas' );
	assert_true( 'good' === $env_report['status'], 'Entorno: Estado general es "good"' );

	$tables_report = $diag_service->get_tables_report();
	assert_true( true === $tables_report['all_tables_exist'], 'Tablas: Todas las 5 tablas maestras oficiales existen' );
	assert_true( 'good' === $tables_report['status'], 'Tablas: Estado general es "good"' );
	assert_true( true === $tables_report['tables']['groups']['exists'], 'Tablas: tfml_groups existe' );
	assert_true( true === $tables_report['tables']['group_elements']['exists'], 'Tablas: tfml_group_elements existe' );
	assert_true( true === $tables_report['tables']['media_translations']['exists'], 'Tablas: tfml_media_translations existe' );
	assert_true( true === $tables_report['tables']['strings']['exists'], 'Tablas: tfml_strings existe' );
	assert_true( true === $tables_report['tables']['string_translations']['exists'], 'Tablas: tfml_string_translations existe' );

	$lang_report = $diag_service->get_languages_report();
	assert_true( true === $lang_report['is_configured'], 'Idiomas: Registro está configurado' );
	assert_true( 'es' === $lang_report['default_language'], 'Idiomas: Idioma predeterminado es "es"' );
	assert_true( true === $lang_report['default_is_active'], 'Idiomas: Idioma predeterminado está activo' );
	assert_true( 'good' === $lang_report['status'], 'Idiomas: Estado general es "good"' );

	$rel_report = $diag_service->get_relations_integrity_report();
	assert_true( 0 === $rel_report['duplicate_languages_count'], 'Relaciones: Duplicados en mismo grupo = 0' );
	assert_true( 0 === $rel_report['duplicate_elements_count'], 'Relaciones: Elementos duplicados en múltiples grupos = 0' );
	assert_true( 0 === $rel_report['orphaned_canonical_count'], 'Relaciones: Canonical huérfanos = 0' );
	assert_true( 0 === $rel_report['unregistered_languages_count'], 'Relaciones: Idiomas no registrados = 0' );

	$mod_report = $diag_service->get_modules_report();
	assert_true( true === $mod_report['media']['enabled'], 'Módulos: Media multilingüe activo' );
	assert_true( true === $mod_report['strings']['enabled'], 'Módulos: Strings activo' );
	assert_true( true === $mod_report['menus']['enabled'], 'Módulos: Menús activo' );
	assert_true( true === $mod_report['seo']['enabled'], 'Módulos: SEO activo' );
	assert_true( true === $mod_report['rest']['enabled'], 'Módulos: REST API activo' );

	$full_diag = $diag_service->run_full_diagnostic();
	assert_true( 0 === $full_diag['summary']['critical'], 'Auditoría completa unificada: 0 errores críticos' );
	assert_true( 6 === $full_diag['summary']['total'], 'Auditoría completa unificada: 6 secciones auditadas' );

	// =========================================================================
	// SECCION 3: Integración con Site Health de WordPress Core
	// =========================================================================
	echo "\n--- SECCION 3: WordPress Core Site Health Integration ---\n";

	$diag_ui = $plugin->get_diagnostic_ui();
	assert_true( null !== $diag_ui, 'DiagnosticUi está instanciado y disponible' );

	$site_health_tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
	assert_true( isset( $site_health_tests['direct']['tfml_tables_integrity'] ), 'Site Health: Test de tablas registrado' );
	assert_true( isset( $site_health_tests['direct']['tfml_default_language'] ), 'Site Health: Test de idioma default registrado' );
	assert_true( isset( $site_health_tests['direct']['tfml_relations_integrity'] ), 'Site Health: Test de integridad relacional registrado' );

	$sh_tables = $diag_ui->test_tables_integrity();
	assert_true( 'good' === $sh_tables['status'], 'Site Health: Callback de tablas retorna status "good"' );

	$sh_default = $diag_ui->test_default_language();
	assert_true( 'good' === $sh_default['status'], 'Site Health: Callback de default language retorna status "good"' );

	$sh_rel = $diag_ui->test_relations_integrity();
	assert_true( in_array( $sh_rel['status'], array( 'good', 'recommended' ), true ), 'Site Health: Callback de relaciones retorna status válido ("good" o "recommended")' );
	assert_true( 'critical' !== $sh_rel['status'], 'Site Health: Cero fallos críticos en integridad relacional' );

	// =========================================================================
	// SECCION 4: Cierre del Core y Verificación de Lifecycle
	// =========================================================================
	echo "\n--- SECCION 4: Cierre del Core y Lifecycle ---\n";

	// Test non-destructive deactivation.
	Lifecycle::deactivate();
	$after_deact_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}tfml_groups`" );
	assert_true( $after_deact_count >= 1, 'Lifecycle::deactivate es estrictamente no destructivo (tablas y filas preservadas)' );

	// Test schema manager needs_upgrade idempotency.
	$schema_manager = new SchemaManager();
	assert_true( false === $schema_manager->needs_upgrade(), 'SchemaManager: el esquema instalado coincide con la versión requerida' );

	// Test plugin versioning.
	assert_true( Plugin::VERSION === '0.1.0', 'Versión oficial del Core es 0.1.0' );

} finally {
	// Restore external plugin hooks
	$restored_count = $guard->restore();
	echo "\nExternal Plugin Isolation Guard restored $restored_count callbacks.\n";

	// Cleanup test fixtures
	echo "Cleaning up laboratory fixtures...\n";
	foreach ( $created_posts as $pid ) {
		wp_delete_post( $pid, true );
	}
	foreach ( $created_terms as $tinfo ) {
		wp_delete_term( $tinfo['id'], $tinfo['taxonomy'] );
	}
	foreach ( $created_groups as $gid ) {
		$wpdb->delete( $wpdb->prefix . 'tfml_group_elements', array( 'group_id' => $gid ) );
		$wpdb->delete( $wpdb->prefix . 'tfml_groups', array( 'id' => $gid ) );
	}

	// Post-flight Sentinel Check
	$wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );
	$post_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_icl}`" );
	$post_md5   = (string) $wpdb->get_var(
		"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$table_icl}`"
	);

	echo "\nSentinel WPML Post-Flight:\n";
	echo "  Rows: $post_count (Expected: 3403)\n";
	echo "  MD5:  $post_md5 (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

	assert_true( 3403 === $post_count, 'WPML post-count is exactly 3403 (Zero rows written to WPML)' );
	assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_md5, 'WPML post-checksum matches 4241ca7e7ec6399a594537cb04790c10 (Zero bit drift)' );
}

echo "====================================================================\n";
echo "  RESULTADOS TOTALES: $assertions_passed PASSED, $assertions_failed FAILED\n";
echo "====================================================================\n";

if ( $assertions_failed > 0 ) {
	exit( 1 );
}

exit( 0 );
