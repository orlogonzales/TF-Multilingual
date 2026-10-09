<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.1
 * (Hardening del Ciclo de Vida y Compatibilidad con WPBakery Page Builder)
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
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Infrastructure\Lifecycle;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Integration\IntegrationManager;
use TF\Multilingual\Integration\WPBakery\WPBakeryIntegration;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'WordPress environment not found.' );
}

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.1 (LIFECYCLE & WPBAKERY)\n";
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
	// Initialize plugin subsystems
	$plugin = Plugin::get_instance();
	$plugin->init();

	$admin_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );
	wp_set_current_user( $admin_id );

	$editorial_service = $plugin->get_editorial_service();
	$policy_registry   = $plugin->get_custom_field_policy_registry();
	$media_resolver    = $plugin->get_media_resolver();
	$integration_mgr   = $plugin->get_integration_manager();

	// =========================================================================
	// BLOQUE 1: HARDENING DEL CICLO DE VIDA (DESACTIVACIÓN, UPGRADE Y UNINSTALL)
	// =========================================================================
	echo "\n--- BLOQUE 1: CICLO DE VIDA (LIFECYCLE) Y POLÍTICA DE RETENCIÓN ---\n";

	// 1.1 Deactivación no destructiva
	Lifecycle::deactivate();
	assert_true( true, 'Lifecycle::deactivate() se ejecuta limpiamente de forma no destructiva' );

	$schema_mgr = new SchemaManager();
	assert_true( $schema_mgr->verify_tables(), 'Lifecycle: Las 5 tablas maestras siguen 100% intactas tras deactivación' );
	assert_true( ! $schema_mgr->needs_upgrade(), 'SchemaManager: needs_upgrade() reporta false con esquema al día' );

	// 1.2 Uninstall con retención por defecto (preserva datos)
	update_option( Lifecycle::OPTION_PURGE_ON_UNINSTALL, false );
	Lifecycle::uninstall();
	assert_true( $schema_mgr->verify_tables(), 'Lifecycle::uninstall() por defecto preserva todas las tablas maestras (retención)' );
	assert_true( ! empty( get_option( 'tfml_settings' ) ), 'Lifecycle::uninstall() por defecto preserva tfml_settings' );

	// 1.3 Verificación de la firma de SchemaManager::drop_tables
	$reflection = new ReflectionClass( SchemaManager::class );
	assert_true( $reflection->hasMethod( 'drop_tables' ), 'SchemaManager implementa método drop_tables()' );

	// =========================================================================
	// BLOQUE 2: ADAPTADOR DE INTEGRACIÓN WPBAKERY PAGE BUILDER
	// =========================================================================
	echo "\n--- BLOQUE 2: ADAPTADOR DE COMPATIBILIDAD WPBAKERY PAGE BUILDER ---\n";

	$wpb_adapter = new WPBakeryIntegration( $policy_registry, $media_resolver );
	$wpb_adapter->init_hooks();

	// 2.1 Políticas de metadatos registradas
	assert_true(
		CustomFieldPolicy::SHARE === $policy_registry->get_policy( WPBakeryIntegration::META_JS_STATUS ),
		'WPBakery: _wpb_vc_js_status registrado con política SHARE'
	);
	assert_true(
		CustomFieldPolicy::TRANSLATE === $policy_registry->get_policy( WPBakeryIntegration::META_POST_CUSTOM_CSS ),
		'WPBakery: _wpb_post_custom_css registrado con política TRANSLATE'
	);
	assert_true(
		CustomFieldPolicy::TRANSLATE === $policy_registry->get_policy( WPBakeryIntegration::META_SHORTCODES_CUSTOM_CSS ),
		'WPBakery: _wpb_shortcodes_custom_css registrado con política TRANSLATE'
	);

	// 2.2 Detección de contenido WPBakery
	$vc_snippet = '[vc_row][vc_column width="1/2"][vc_single_image image="111"][/vc_column][vc_column width="1/2"][vc_column_text]Texto[/vc_column_text][/vc_column][/vc_row]';
	$plain_snippet = '<p>Texto estándar clásico o Gutenberg sin shortcodes visuales.</p>';

	assert_true( $wpb_adapter->has_wpbakery_content( $vc_snippet ), 'WPBakery: has_wpbakery_content detecta [vc_row]' );
	assert_true( ! $wpb_adapter->has_wpbakery_content( $plain_snippet ), 'WPBakery: has_wpbakery_content descarta contenido estándar' );

	// 2.3 Extracción y mapeo de Media IDs en shortcodes
	$vc_gallery_snippet = '[vc_row][vc_column][vc_single_image image="210"][vc_gallery images="310, 320,330"][/vc_column][/vc_row]';
	$extracted_ids = $wpb_adapter->extract_media_ids( $vc_gallery_snippet );
	assert_true(
		count( $extracted_ids ) === 4 && in_array( 210, $extracted_ids, true ) && in_array( 330, $extracted_ids, true ),
		'WPBakery: extract_media_ids extrae correctamente IDs individuales y listas CSV con espacios'
	);

	// 2.4 Localización de shortcodes con mapeo de attachment IDs
	add_filter(
		'tfml_localize_attachment_id',
		function( int $id, string $lang ): int {
			if ( 'en' === $lang ) {
				if ( 210 === $id ) return 710;
				if ( 320 === $id ) return 720;
			}
			return $id;
		},
		10,
		2
	);

	$localized_vc = $wpb_adapter->localize_shortcode_media( $vc_gallery_snippet, 'en' );
	assert_true(
		str_contains( $localized_vc, 'image="710"' ) && str_contains( $localized_vc, 'images="310,720,330"' ),
		'WPBakery: localize_shortcode_media sustituye IDs mapeados preservando no mapeados'
	);

	// =========================================================================
	// BLOQUE 3: PRUEBA EDITORIAL REAL (POST CON WPBAKERY VS POST REGULAR)
	// =========================================================================
	echo "\n--- BLOQUE 3: FLUJO EDITORIAL REAL Y ZERO-CLONING SELECTIVO ---\n";

	// 3.1 Crear Post WPBakery de origen en Español
	$wpb_source_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Página Maestra WPBakery ES',
			'post_content' => $vc_gallery_snippet,
		)
	);
	$created_posts[] = $wpb_source_id;
	update_post_meta( $wpb_source_id, WPBakeryIntegration::META_JS_STATUS, 'true' );
	update_post_meta( $wpb_source_id, WPBakeryIntegration::META_POST_CUSTOM_CSS, '.vc_custom_es { padding: 10px; }' );

	$group_wpb = $editorial_service->assign_initial_language( 'post', $wpb_source_id, 'page', 'es' );
	$created_groups[] = $group_wpb->get_id();

	// 3.2 Crear traducción EN del post WPBakery
	$wpb_trans_id = $editorial_service->create_post_translation( $wpb_source_id, 'en' );
	$created_posts[] = $wpb_trans_id;

	$wpb_trans_post = get_post( $wpb_trans_id );
	assert_true(
		'draft' === $wpb_trans_post->post_status,
		'WPBakery editorial: traducción creada estrictamente en estado draft'
	);
	assert_true(
		str_contains( $wpb_trans_post->post_content, 'image="710"' ),
		'WPBakery editorial: post_content inicializado con la estructura del builder y media localizada'
	);
	assert_true(
		'true' === get_post_meta( $wpb_trans_id, WPBakeryIntegration::META_JS_STATUS, true ),
		'WPBakery editorial: _wpb_vc_js_status compartido automáticamente por política SHARE'
	);
	assert_true(
		'.vc_custom_es { padding: 10px; }' === get_post_meta( $wpb_trans_id, WPBakeryIntegration::META_POST_CUSTOM_CSS, true ),
		'WPBakery editorial: custom CSS heredado como borrador inicial de trabajo'
	);

	// 3.3 Probar independencia editorial (TRANSLATE) de custom CSS
	update_post_meta( $wpb_trans_id, WPBakeryIntegration::META_POST_CUSTOM_CSS, '.vc_custom_en { padding: 20px; }' );
	assert_true(
		'.vc_custom_es { padding: 10px; }' === get_post_meta( $wpb_source_id, WPBakeryIntegration::META_POST_CUSTOM_CSS, true ),
		'WPBakery editorial: mutación de custom CSS en EN no altera el custom CSS de ES (política TRANSLATE)'
	);

	// 3.4 Verificar que posts estándar SIN WPBakery preservan estrictamente Zero-Cloning
	$plain_source_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Página Regular Sin Builder ES',
			'post_content' => '<p>Contenido clásico o Gutenberg.</p>',
		)
	);
	$created_posts[] = $plain_source_id;
	$group_plain = $editorial_service->assign_initial_language( 'post', $plain_source_id, 'page', 'es' );
	$created_groups[] = $group_plain->get_id();

	$plain_trans_id = $editorial_service->create_post_translation( $plain_source_id, 'en' );
	$created_posts[] = $plain_trans_id;

	$plain_trans_post = get_post( $plain_trans_id );
	assert_true(
		'' === $plain_trans_post->post_content,
		'Zero-Cloning: Post estándar sin WPBakery mantiene post_content estrictamente vacío'
	);

	// =========================================================================
	// BLOQUE 4: REGRESIONES INTEGRALES DEL CORE (DIAGNÓSTICO Y HEALTH CHECK)
	// =========================================================================
	echo "\n--- BLOQUE 4: REGRESIONES INTEGRALES DEL CORE (SITE HEALTH Y DIAGNÓSTICO) ---\n";

	$diag_service = $plugin->get_diagnostic_service();
	$full_report  = $diag_service->run_full_diagnostic();

	echo "Relations report details: " . json_encode( $full_report['sections']['relations'] ) . "\n";
	assert_true( 'good' === $full_report['sections']['tables']['status'], 'Diagnóstico Core: Tablas maestras en estado good' );
	assert_true( 'good' === $full_report['sections']['languages']['status'], 'Diagnóstico Core: Registro de idiomas en estado good' );
	assert_true( in_array( $full_report['sections']['relations']['status'], array( 'good', 'warning' ), true ) && 'critical' !== $full_report['sections']['relations']['status'], 'Diagnóstico Core: Cero fallos críticos en integridad relacional' );
	assert_true( 'good' === $full_report['sections']['modules']['status'], 'Diagnóstico Core: Módulos funcionales en estado good' );

} finally {
	// =========================================================================
	// LIMPIEZA RIGUROSA Y DETERMINISTA DE FIXTURES
	// =========================================================================
	echo "\nLimpiando fixtures de prueba...\n";

	foreach ( $created_posts as $pid ) {
		wp_delete_post( $pid, true );
	}

	$table_ge = $wpdb->prefix . SchemaManager::TABLES['group_elements'];
	$table_gr = $wpdb->prefix . SchemaManager::TABLES['groups'];

	foreach ( $created_groups as $gid ) {
		$wpdb->delete( $table_ge, array( 'group_id' => $gid ) );
		$wpdb->delete( $table_gr, array( 'id' => $gid ) );
	}

	// Restaurar aislamiento de hooks de plugins externos
	$guard->restore();
	echo "Isolation Guard restaurado.\n";
}

// =============================================================================
// POST-FLIGHT SENTINEL CHECK (INVARIANZA WPML)
// =============================================================================
echo "\n--- SENTINEL POST-FLIGHT VERIFICATION ---\n";
$post_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_icl}`" );
$post_md5   = (string) $wpdb->get_var(
	"SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$table_icl}`"
);

echo "Sentinel WPML Post-Flight:\n";
echo "  Rows: $post_count (Delta: " . ( $post_count - $pre_count ) . ")\n";
echo "  MD5:  $post_md5\n\n";

assert_true( $post_count === $pre_count, 'Invarianza WPML: Total de registros inalterado (3403)' );
assert_true( $post_md5 === $pre_md5, 'Invarianza WPML: Checksum criptográfico inalterado (4241ca7e7ec6399a594537cb04790c10)' );

echo "\n====================================================================\n";
echo "  RESUMEN DE VERIFICACION FASE 3.1\n";
echo "  Aserciones Exitosas: $assertions_passed\n";
echo "  Aserciones Fallidas: $assertions_failed\n";
echo "====================================================================\n";

if ( $assertions_failed > 0 ) {
	exit( 1 );
}

exit( 0 );
