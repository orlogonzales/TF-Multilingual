<?php
/**
 * TF Multilingual — Verificación Integral en Laboratorio Real: Fase 2.7 (REST API Multilingüe)
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

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

set_exception_handler(
	function( Throwable $e ) {
		echo "\nFATAL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
		exit( 1 );
	}
);

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Scratch\ExternalPluginIsolationGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'WordPress environment not found.' );
}

global $wpdb;

echo "====================================================================\n";
echo "  TF MULTILINGUAL — VERIFICACION INTEGRAL FASE 2.7 (REST API)\n";
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
	if ( $admin_id <= 0 ) {
		$admin_id = 1;
	}

	echo "--- SECCION 1: Endpoint Publico de Idiomas (GET /languages) ---\n";
	wp_set_current_user( 0 ); // Unauthenticated guest

	$req_lang  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/languages' );
	$resp_lang = rest_do_request( $req_lang );

	assert_true( 200 === $resp_lang->get_status(), 'GET /languages responde 200 OK para usuario no autenticado' );
	$lang_data = $resp_lang->get_data();
	assert_true( is_array( $lang_data ) && count( $lang_data ) >= 2, 'GET /languages retorna lista con al menos 2 idiomas' );

	$codes = array_column( $lang_data, 'code' );
	assert_true( in_array( 'es', $codes, true ), 'Lista contiene idioma principal "es"' );
	assert_true( in_array( 'en', $codes, true ), 'Lista contiene idioma secundario "en"' );

	$first_lang = $lang_data[0];
	assert_true( isset( $first_lang['code'], $first_lang['locale'], $first_lang['name'], $first_lang['is_default'], $first_lang['active'] ), 'Esquema de respuesta contiene campos obligatorios' );
	assert_true( true === $first_lang['is_default'] && 'es' === $first_lang['code'], 'Idioma soberano por defecto es "es" con is_default=true' );

	echo "\n--- SECCION 2: Consulta de Post Sin Asignar (GET /translations/post/{id}) ---\n";
	$post_unassigned = wp_insert_post(
		array(
			'post_title'   => 'TFML Lab REST Unassigned Post',
			'post_content' => 'Contenido de prueba REST sin traducir.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_unassigned;

	$req_unassigned  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/' . $post_unassigned );
	$resp_unassigned = rest_do_request( $req_unassigned );

	assert_true( 200 === $resp_unassigned->get_status(), 'GET /translations/post/{id} responde 200 para post no asignado' );
	$unassigned_data = $resp_unassigned->get_data();
	assert_true( null === $unassigned_data['group_id'], 'Post sin grupo retorna group_id = null' );
	assert_true( $post_unassigned === $unassigned_data['element_id'], 'element_id coincide con el post consultado' );
	assert_true( in_array( 'en', $unassigned_data['untranslated_languages'], true ), 'Idiomas activos pendientes aparecen en untranslated_languages' );

	echo "\n--- SECCION 3: Control de Acceso y Anti-IDOR en Lectura (Draft Post) ---\n";
	$post_draft = wp_insert_post(
		array(
			'post_title'   => 'TFML Lab REST Draft Post',
			'post_content' => 'Borrador privado confidencial.',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_draft;

	// Request as unauthenticated visitor
	wp_set_current_user( 0 );
	$req_draft_anon  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/' . $post_draft );
	$resp_draft_anon = rest_do_request( $req_draft_anon );
	assert_true( 401 === $resp_draft_anon->get_status() || 403 === $resp_draft_anon->get_status(), 'Usuario no autorizado recibe 401/403 al consultar post borrador (Anti-IDOR)' );

	// Request as authorized admin
	wp_set_current_user( $admin_id );
	$req_draft_auth  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/' . $post_draft );
	$resp_draft_auth = rest_do_request( $req_draft_auth );
	assert_true( 200 === $resp_draft_auth->get_status(), 'Editor autenticado recibe 200 OK al consultar post borrador' );

	echo "\n--- SECCION 4: Vinculacion Editorial Anti-IDOR (POST /translations/link) ---\n";
	$post_es = wp_insert_post(
		array(
			'post_title'   => 'TFML Lab REST ES Original',
			'post_content' => 'Contenido original en espanol para la API REST.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_es;

	$post_en = wp_insert_post(
		array(
			'post_title'   => 'TFML Lab REST EN Translation',
			'post_content' => 'Original English translation content for REST API.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
	$created_posts[] = $post_en;

	// Test Anti-IDOR on link: unauthorized guest attempts to link
	wp_set_current_user( 0 );
	$req_link_anon = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/link' );
	$req_link_anon->set_body_params(
		array(
			'element_type'    => 'post',
			'source_id'       => $post_es,
			'target_id'       => $post_en,
			'target_language' => 'en',
		)
	);
	$resp_link_anon = rest_do_request( $req_link_anon );
	assert_true( 401 === $resp_link_anon->get_status() || 403 === $resp_link_anon->get_status(), 'Intento no autorizado de vincular traducciones es rechazado (401/403)' );

	// Test authorized linking
	wp_set_current_user( $admin_id );
	$req_link_auth = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/link' );
	$req_link_auth->set_body_params(
		array(
			'element_type'    => 'post',
			'source_id'       => $post_es,
			'target_id'       => $post_en,
			'target_language' => 'en',
			'source_language' => 'es',
		)
	);
	$resp_link_auth = rest_do_request( $req_link_auth );
	assert_true( 200 === $resp_link_auth->get_status(), 'Vinculacion autorizada responde 200 OK' );
	$link_data = $resp_link_auth->get_data();
	assert_true( ! empty( $link_data['group_id'] ), 'Grupo de traduccion creado exitosamente con ID numerico' );
	if ( ! empty( $link_data['group_id'] ) ) {
		$created_groups[] = (int) $link_data['group_id'];
	}
	assert_true( $post_es === $link_data['canonical_element_id'], 'Post fuente ES es el elemento canonico del grupo' );
	assert_true( isset( $link_data['translations']['es'], $link_data['translations']['en'] ), 'Ambas traducciones (es, en) constan en el mapa' );
	assert_true( TranslationStatus::UPDATED === $link_data['translations']['en']['status'], 'Traduccion recien vinculada tiene estado UPDATED' );

	echo "\n--- SECCION 5: Consulta de Estado Editorial y Versionado (GET /status) ---\n";
	$req_status_en  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/status/post/' . $post_en );
	$resp_status_en = rest_do_request( $req_status_en );
	assert_true( 200 === $resp_status_en->get_status(), 'GET /status/post/{id} responde 200 OK' );
	$status_data_en = $resp_status_en->get_data();
	assert_true( TranslationStatus::UPDATED === $status_data_en['status'], 'Estado es UPDATED' );
	assert_true( false === $status_data_en['needs_review'], 'needs_review es false inicialmente' );
	assert_true( 1 === $status_data_en['current_version'], 'Version actual de la traduccion es 1' );
	assert_true( 1 === $status_data_en['canonical_version'], 'Version actual del canonico es 1' );

	echo "\n--- SECCION 6: Deteccion de Desactualizacion (Transicion a REVIEW) ---\n";
	// Actualizamos el post canonico ES (incrementa su version a v2)
	wp_update_post(
		array(
			'ID'           => $post_es,
			'post_content' => 'Contenido modificado y ampliado en espanol (Version 2).',
		)
	);
	$editorial_service->sync_post_version( $post_es );

	$req_status_after  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/status/post/' . $post_en );
	$resp_status_after = rest_do_request( $req_status_after );
	$status_after_data = $resp_status_after->get_data();

	assert_true( TranslationStatus::REVIEW === $status_after_data['status'], 'Estado de la traduccion EN pasa a REVIEW tras cambio en canonico ES' );
	assert_true( true === $status_after_data['needs_review'], 'needs_review es true tras actualizacion del canonico' );
	assert_true( 2 === $status_after_data['canonical_version'], 'Version del canonico refleja v2' );
	assert_true( 1 === $status_after_data['source_version'], 'source_version de la traduccion permanece en 1' );

	echo "\n--- SECCION 7: Marcado de Traduccion Revisada (POST /status/reviewed) ---\n";
	// Anti-IDOR check on reviewed
	wp_set_current_user( 0 );
	$req_rev_anon = new WP_REST_Request( 'POST', '/tf-multilingual/v1/status/reviewed' );
	$req_rev_anon->set_body_params(
		array(
			'element_type' => 'post',
			'element_id'   => $post_en,
		)
	);
	$resp_rev_anon = rest_do_request( $req_rev_anon );
	assert_true( 401 === $resp_rev_anon->get_status() || 403 === $resp_rev_anon->get_status(), 'Usuario no autorizado no puede marcar como revisado (401/403)' );

	// Authorized mark reviewed
	wp_set_current_user( $admin_id );
	$req_rev_auth = new WP_REST_Request( 'POST', '/tf-multilingual/v1/status/reviewed' );
	$req_rev_auth->set_body_params(
		array(
			'element_type' => 'post',
			'element_id'   => $post_en,
		)
	);
	$resp_rev_auth = rest_do_request( $req_rev_auth );
	assert_true( 200 === $resp_rev_auth->get_status(), 'POST /status/reviewed responde 200 OK' );
	$rev_data = $resp_rev_auth->get_data();
	assert_true( TranslationStatus::UPDATED === $rev_data['status'], 'Estado vuelve a ser UPDATED tras revision' );
	assert_true( false === $rev_data['needs_review'], 'needs_review vuelve a ser false' );
	assert_true( 2 === $rev_data['source_version'], 'source_version se alineo a la version v2 del canonico' );

	echo "\n--- SECCION 8: Listado y Paginacion de Coleccion (GET /translations) ---\n";
	$req_col  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations' );
	$req_col->set_param( 'element_type', 'post' );
	$req_col->set_param( 'per_page', 5 );
	$resp_col = rest_do_request( $req_col );

	assert_true( 200 === $resp_col->get_status(), 'GET /translations responde 200 OK' );
	$headers = $resp_col->get_headers();
	assert_true( isset( $headers['X-WP-Total'] ) && (int) $headers['X-WP-Total'] >= 1, 'Encabezado de paginacion X-WP-Total presente y >= 1' );
	assert_true( isset( $headers['X-WP-TotalPages'] ) && (int) $headers['X-WP-TotalPages'] >= 1, 'Encabezado de paginacion X-WP-TotalPages presente y >= 1' );

	$items = $resp_col->get_data();
	assert_true( is_array( $items ) && ! empty( $items ), 'Coleccion retorna array de grupos' );

	echo "\n--- SECCION 9: Desvinculacion de Traduccion (POST /translations/unlink) ---\n";
	// Anti-IDOR check on unlink
	wp_set_current_user( 0 );
	$req_unlink_anon = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/unlink' );
	$req_unlink_anon->set_body_params(
		array(
			'element_type' => 'post',
			'element_id'   => $post_en,
		)
	);
	$resp_unlink_anon = rest_do_request( $req_unlink_anon );
	assert_true( 401 === $resp_unlink_anon->get_status() || 403 === $resp_unlink_anon->get_status(), 'Usuario no autorizado no puede desvincular (401/403)' );

	// Authorized unlink
	wp_set_current_user( $admin_id );
	$req_unlink_auth = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/unlink' );
	$req_unlink_auth->set_body_params(
		array(
			'element_type' => 'post',
			'element_id'   => $post_en,
		)
	);
	$resp_unlink_auth = rest_do_request( $req_unlink_auth );
	assert_true( 200 === $resp_unlink_auth->get_status(), 'POST /translations/unlink responde 200 OK' );
	$unlink_res = $resp_unlink_auth->get_data();
	assert_true( true === $unlink_res['success'], 'Respuesta indica success=true' );

	// Verify post EN is now unassigned
	$req_verify_unlink  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/post/' . $post_en );
	$resp_verify_unlink = rest_do_request( $req_verify_unlink );
	$verify_unlink_data = $resp_verify_unlink->get_data();
	assert_true( null === $verify_unlink_data['group_id'], 'Post EN desvinculado ahora tiene group_id = null' );

	echo "\n--- SECCION 10: Vinculacion y Consulta de Terminos de Taxonomia ---\n";
	$term_es = wp_insert_term( 'TFML Lab REST Categoria ES', 'category' );
	$term_en = wp_insert_term( 'TFML Lab REST Category EN', 'category' );

	if ( ! is_wp_error( $term_es ) && ! is_wp_error( $term_en ) ) {
		$term_es_id     = (int) $term_es['term_id'];
		$term_en_id     = (int) $term_en['term_id'];
		$created_terms[] = array( 'id' => $term_es_id, 'taxonomy' => 'category' );
		$created_terms[] = array( 'id' => $term_en_id, 'taxonomy' => 'category' );

		wp_set_current_user( $admin_id );
		$req_link_terms = new WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/link' );
		$req_link_terms->set_body_params(
			array(
				'element_type'    => 'term',
				'source_id'       => $term_es_id,
				'target_id'       => $term_en_id,
				'target_language' => 'en',
				'source_language' => 'es',
			)
		);
		$resp_link_terms = rest_do_request( $req_link_terms );
		assert_true( 200 === $resp_link_terms->get_status(), 'Vinculacion de terminos responde 200 OK' );
		$terms_group_data = $resp_link_terms->get_data();
		if ( ! empty( $terms_group_data['group_id'] ) ) {
			$created_groups[] = (int) $terms_group_data['group_id'];
		}
		assert_true( isset( $terms_group_data['translations']['es'], $terms_group_data['translations']['en'] ), 'Ambos terminos constan en el grupo multilingue' );

		// Query term via REST
		$req_get_term  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/term/' . $term_es_id );
		$resp_get_term = rest_do_request( $req_get_term );
		assert_true( 200 === $resp_get_term->get_status(), 'GET /translations/term/{id} responde 200 OK' );
	}

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
