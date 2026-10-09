<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 3.2
 * (Compatibilidad con Elementor y Elementor Pro en Entorno Real Activo)
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
use TF\Multilingual\Integration\Elementor\ElementorIntegration;
use TF\Multilingual\Integration\IntegrationManager;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'WordPress environment not found.' );
}

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 3.2 (ELEMENTOR & PRO)\n";
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
	// BLOQUE 1: VERIFICACION DE ACTIVACION REAL DE ELEMENTOR Y ELEMENTOR PRO
	// =========================================================================
	echo "\n--- BLOQUE 1: DETECCION Y ACTIVACION DE ELEMENTOR Y ELEMENTOR PRO ---\n";

	assert_true( $integration_mgr->is_elementor_active(), 'Elementor Core detectado como activo en runtime real' );
	assert_true( defined( 'ELEMENTOR_VERSION' ), 'Constante ELEMENTOR_VERSION definida: ' . ( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'NO' ) );
	assert_true( defined( 'ELEMENTOR_PRO_VERSION' ), 'Constante ELEMENTOR_PRO_VERSION definida: ' . ( defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : 'NO' ) );

	$elementor_adapter = $integration_mgr->get_elementor_integration();
	assert_true( null !== $elementor_adapter, 'ElementorIntegration instanciado y conectado en IntegrationManager' );
	assert_true( $elementor_adapter->is_elementor_pro_active(), 'ElementorIntegration reconoce Elementor Pro activo' );

	// =========================================================================
	// BLOQUE 2: POLITICAS SOBERANAS DE METADATOS DE ELEMENTOR
	// =========================================================================
	echo "\n--- BLOQUE 2: POLITICAS SOBERANAS DE METADATOS ---\n";

	assert_true(
		CustomFieldPolicy::SHARE === $policy_registry->get_policy( ElementorIntegration::META_EDIT_MODE ),
		'Elementor: _elementor_edit_mode registrado con política SHARE'
	);
	assert_true(
		CustomFieldPolicy::SHARE === $policy_registry->get_policy( ElementorIntegration::META_TEMPLATE_TYPE ),
		'Elementor: _elementor_template_type registrado con política SHARE'
	);
	assert_true(
		CustomFieldPolicy::SHARE === $policy_registry->get_policy( ElementorIntegration::META_PAGE_TEMPLATE ),
		'Elementor: _wp_page_template registrado con política SHARE'
	);
	assert_true(
		CustomFieldPolicy::TRANSLATE === $policy_registry->get_policy( ElementorIntegration::META_DATA ),
		'Elementor: _elementor_data registrado con política TRANSLATE (independencia estricta)'
	);
	assert_true(
		CustomFieldPolicy::TRANSLATE === $policy_registry->get_policy( ElementorIntegration::META_PAGE_SETTINGS ),
		'Elementor: _elementor_page_settings registrado con política TRANSLATE'
	);
	assert_true(
		CustomFieldPolicy::IGNORE === $policy_registry->get_policy( ElementorIntegration::META_CSS ),
		'Elementor: _elementor_css registrado con política IGNORE (caché dinámico)'
	);

	// =========================================================================
	// BLOQUE 3: CREACION Y MODELADO DE PAGINA ELEMENTOR EN ESPAÑOL
	// =========================================================================
	echo "\n--- BLOQUE 3: CREACION Y MODELADO DE DOCUMENTO ELEMENTOR EN ESPAÑOL ---\n";

	// Construir árbol JSON realista con sección, columna, heading, imagen, botón con enlace y galería
	$es_tree = array(
		array(
			'id'       => 'sec_hero_es',
			'elType'   => 'section',
			'settings' => array(
				'layout'           => 'full_width',
				'background_image' => array(
					'id'  => 311,
					'url' => 'http://cms.ecoterra/wp-content/uploads/hero-es.jpg',
				),
			),
			'elements' => array(
				array(
					'id'       => 'col_main_es',
					'elType'   => 'column',
					'settings' => array(),
					'elements' => array(
						array(
							'id'         => 'wid_title_es',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title' => 'Bienvenido a Ecoterra Expediciones',
							),
						),
						array(
							'id'         => 'wid_img_es',
							'elType'     => 'widget',
							'widgetType' => 'image',
							'settings'   => array(
								'image' => array(
									'id'  => 312,
									'url' => 'http://cms.ecoterra/wp-content/uploads/tour-es.jpg',
								),
							),
						),
						array(
							'id'         => 'wid_btn_es',
							'elType'     => 'widget',
							'widgetType' => 'button',
							'settings'   => array(
								'text' => 'Ver Paquetes',
								'link' => array(
									'url'         => 'http://cms.ecoterra/tours/',
									'is_external' => '',
								),
							),
						),
						array(
							'id'         => 'wid_gallery_es',
							'elType'     => 'widget',
							'widgetType' => 'image-gallery',
							'settings'   => array(
								'wp_gallery' => array(
									array(
										'id'  => 313,
										'url' => 'http://cms.ecoterra/wp-content/uploads/g1-es.jpg',
									),
									array(
										'id'  => 314,
										'url' => 'http://cms.ecoterra/wp-content/uploads/g2-es.jpg',
									),
								),
							),
						),
					),
				),
			),
		),
	);

	$es_post_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Página Elementor Maestra ES',
			'post_content' => '<div class="elementor-preview">Fallback HTML ES</div>',
		)
	);
	$created_posts[] = $es_post_id;

	update_post_meta( $es_post_id, ElementorIntegration::META_EDIT_MODE, 'builder' );
	update_post_meta( $es_post_id, ElementorIntegration::META_TEMPLATE_TYPE, 'page' );
	update_post_meta( $es_post_id, ElementorIntegration::META_VERSION, ELEMENTOR_VERSION );
	update_post_meta( $es_post_id, ElementorIntegration::META_PRO_VERSION, ELEMENTOR_PRO_VERSION );
	update_post_meta( $es_post_id, ElementorIntegration::META_PAGE_TEMPLATE, 'elementor_header_footer' );
	update_post_meta( $es_post_id, ElementorIntegration::META_DATA, wp_slash( wp_json_encode( $es_tree ) ) );
	update_post_meta( $es_post_id, ElementorIntegration::META_PAGE_SETTINGS, array( 'custom_css' => '.hero { min-height: 400px; }' ) );

	$group_es = $editorial_service->assign_initial_language( 'post', $es_post_id, 'page', 'es' );
	$created_groups[] = $group_es->get_id();

	assert_true( $elementor_adapter->is_elementor_post( $es_post_id ), 'Post ES reconocido correctamente como post Elementor' );

	// =========================================================================
	// BLOQUE 4: TRADUCCION EDITORIAL REAL (ES -> EN) CON ELEMENTOR
	// =========================================================================
	echo "\n--- BLOQUE 4: DUPLICACION ASISTIDA Y LOCALIZACION EN BORRADOR EN ---\n";

	// Mapear adjunto 312 -> 812 en inglés vía filtro oficial
	add_filter(
		'tfml_localize_attachment_id',
		function( int $id, string $lang ): int {
			if ( 'en' === $lang && 312 === $id ) {
				return 812;
			}
			return $id;
		},
		10,
		2
	);

	// Mapear URL interna /tours/ -> /en/tours/
	add_filter(
		'tfml_elementor_localize_url',
		function( string $url, string $lang ): string {
			if ( 'en' === $lang && 'http://cms.ecoterra/tours/' === $url ) {
				return 'http://cms.ecoterra/en/tours/';
			}
			return $url;
		},
		10,
		2
	);

	$en_post_id = $editorial_service->create_post_translation( $es_post_id, 'en' );
	$created_posts[] = $en_post_id;

	$en_post = get_post( $en_post_id );
	assert_true( 'draft' === $en_post->post_status, 'Traducción Elementor creada estrictamente en estado draft' );
	assert_true( 'builder' === get_post_meta( $en_post_id, ElementorIntegration::META_EDIT_MODE, true ), 'Traducción EN: _elementor_edit_mode compartido automáticamente (SHARE)' );
	assert_true( 'page' === get_post_meta( $en_post_id, ElementorIntegration::META_TEMPLATE_TYPE, true ), 'Traducción EN: _elementor_template_type compartido automáticamente (SHARE)' );
	assert_true( 'elementor_header_footer' === get_post_meta( $en_post_id, ElementorIntegration::META_PAGE_TEMPLATE, true ), 'Traducción EN: _wp_page_template compartido automáticamente (SHARE)' );

	$en_tree = $elementor_adapter->get_elementor_data( $en_post_id );
	assert_true( ! empty( $en_tree ), 'Traducción EN: _elementor_data decodificado exitosamente como árbol de elementos' );

	// Comprobar que los IDs técnicos se preservaron
	assert_true( 'sec_hero_es' === $en_tree[0]['id'], 'Elementor: ID técnico de sección preservado' );
	assert_true( 'wid_title_es' === $en_tree[0]['elements'][0]['elements'][0]['id'], 'Elementor: ID técnico de widget heading preservado' );

	// Comprobar localización de media e hipervínculos
	$en_image_widget = $en_tree[0]['elements'][0]['elements'][1];
	$en_btn_widget   = $en_tree[0]['elements'][0]['elements'][2];

	assert_true( 812 === (int) $en_image_widget['settings']['image']['id'], 'Elementor: ID de imagen localizado a 812 en traducción EN' );
	assert_true( 'http://cms.ecoterra/en/tours/' === $en_btn_widget['settings']['link']['url'], 'Elementor: Enlace de botón localizado a URL inglesa' );

	// =========================================================================
	// BLOQUE 5: INDEPENDENCIA EDITORIAL (TRANSLATE) Y EDICION EN ELEMENTOR
	// =========================================================================
	echo "\n--- BLOQUE 5: INDEPENDENCIA EDITORIAL DE EDICION EN ELEMENTOR ---\n";

	// Simular edición de la traducción en Elementor: cambiar título y estilos
	$en_tree[0]['elements'][0]['elements'][0]['settings']['title'] = 'Welcome to Ecoterra Expeditions';
	$en_tree[0]['elements'][0]['elements'][2]['settings']['text']  = 'View Packages';
	update_post_meta( $en_post_id, ElementorIntegration::META_DATA, wp_slash( wp_json_encode( $en_tree ) ) );
	update_post_meta( $en_post_id, ElementorIntegration::META_PAGE_SETTINGS, array( 'custom_css' => '.hero { min-height: 500px; }' ) );

	// Guardar post EN
	wp_update_post(
		array(
			'ID'         => $en_post_id,
			'post_title' => 'Elementor Master Page EN',
		)
	);

	// Verificar que el post ES permanece 100% INTACTO
	$es_tree_after = $elementor_adapter->get_elementor_data( $es_post_id );
	$es_heading_title = $es_tree_after[0]['elements'][0]['elements'][0]['settings']['title'];
	assert_true( 'Bienvenido a Ecoterra Expediciones' === $es_heading_title, 'Independencia editorial: Post ES no sufrió mutación alguna tras editar EN' );

	$es_page_settings = get_post_meta( $es_post_id, ElementorIntegration::META_PAGE_SETTINGS, true );
	assert_true( '.hero { min-height: 400px; }' === $es_page_settings['custom_css'], 'Independencia editorial: Page settings de ES no fueron alterados' );

	// =========================================================================
	// BLOQUE 6: RENDERIZADO CON EL FRONTEND REAL DE ELEMENTOR
	// =========================================================================
	echo "\n--- BLOQUE 6: RENDERIZADO CON EL MOTOR REAL DE ELEMENTOR ---\n";

	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->frontend ) ) {
		$es_rendered = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $es_post_id );
		$en_rendered = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $en_post_id );

		assert_true( is_string( $es_rendered ) && str_contains( $es_rendered, 'Bienvenido a Ecoterra' ), 'Motor Elementor: renderiza correctamente HTML del post ES' );
		assert_true( is_string( $en_rendered ) && str_contains( $en_rendered, 'Welcome to Ecoterra' ), 'Motor Elementor: renderiza correctamente HTML del post EN' );
	} else {
		echo " [SKIP] Elementor frontend renderer no disponible directamente en CLI\n";
	}

	// =========================================================================
	// BLOQUE 7: ZERO-CLONING ESTRICTO EN POSTS SIN BUILDER
	// =========================================================================
	echo "\n--- BLOQUE 7: ZERO-CLONING ESTRICTO EN POSTS REGULARES ---\n";

	$plain_source_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Página Regular Sin Elementor ES',
			'post_content' => '<p>Contenido clásico o Gutenberg.</p>',
		)
	);
	$created_posts[] = $plain_source_id;
	$group_plain = $editorial_service->assign_initial_language( 'post', $plain_source_id, 'page', 'es' );
	$created_groups[] = $group_plain->get_id();

	$plain_trans_id = $editorial_service->create_post_translation( $plain_source_id, 'en' );
	$created_posts[] = $plain_trans_id;

	$plain_trans_post = get_post( $plain_trans_id );
	assert_true( '' === $plain_trans_post->post_content, 'Zero-Cloning: Post estándar mantiene post_content estrictamente vacío' );
	assert_true( ! $elementor_adapter->is_elementor_post( $plain_trans_id ), 'Zero-Cloning: Post estándar no activa modo builder' );

	// =========================================================================
	// BLOQUE 8: REGRESIONES INTEGRALES DEL CORE (SITE HEALTH Y DIAGNÓSTICO)
	// =========================================================================
	echo "\n--- BLOQUE 8: REGRESIONES INTEGRALES DEL CORE ---\n";

	$diag_service = $plugin->get_diagnostic_service();
	$full_report  = $diag_service->run_full_diagnostic();

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
echo "  RESUMEN DE VERIFICACION FASE 3.2\n";
echo "  Aserciones Exitosas: $assertions_passed\n";
echo "  Aserciones Fallidas: $assertions_failed\n";
echo "====================================================================\n";

if ( $assertions_failed > 0 ) {
	exit( 1 );
}

exit( 0 );
