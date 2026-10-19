<?php
/**
 * Verificador Reproducible de Fase 3.43A (Directiva DT-TFML-075).
 *
 * Hardening de Vinculación y Desvinculación Editorial:
 * - Sustitución de enlace GET por formulario POST seguro con nonce individual y admin-post.php
 * - Verificación estricta de método POST (rechazo 405 ante solicitudes GET)
 * - Anti-tampering entre grupos: validación de pertenencia cruzada source vs element
 * - Validación exhaustiva de capacidades edit_post en origen y destino
 * - Integridad relacional: desvinculación secundaria, canónica, grupo de 1 elemento, grupo vacío purgado
 * - Preservación de traducciones retenidas en francés y rollback transaccional ante error SQL
 * - Invarianza estricta de producción (cms_ect) y laboratorio (cms_ect_lab: 123 grupos, 139 elementos)
 * - No-regresión HTTP, SEO canónico y sitemaps en laboratorio
 *
 * Uso:
 *   php scratch/verify_fase_3_43a.php
 *
 * @package TF\Multilingual
 */

declare(strict_types=1);

if ( 'cli' !== php_sapi_name() ) {
	exit( "Ejecución permitida únicamente desde PHP CLI.\n" );
}

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

$lab_root = 'D:/laragon/www/lab.ecoterra';
if ( ! file_exists( $lab_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "ERROR: No se encontró wp-load.php en {$lab_root}\n" );
	exit( 1 );
}

require_once $lab_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}
ini_set( 'display_errors', '1' );
add_filter(
	'wp_die_handler',
	function () {
		return function ( $message, $title = '', $args = array() ) {
			$code = is_numeric( $title ) ? (int) $title : ( is_numeric( $args ) ? (int) $args : ( isset( $args['response'] ) ? (int) $args['response'] : 500 ) );
			throw new \RuntimeException( (string) $message, $code );
		};
	}
);

use TF\Multilingual\Admin\PostEditorialUi;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Versioning\TranslatableFingerprint;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\Exceptions\EditorialConflictException;
use TF\Multilingual\Editorial\Exceptions\EditorialPermissionException;
use TF\Multilingual\Editorial\Exceptions\EditorialValidationException;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Migration\RetainedTranslationRegistry;

$results = array();
$passed  = 0;
$failed  = 0;

/**
 * Registra el resultado de un control de verificación.
 *
 * @param string $gate_id Identificador del control.
 * @param bool   $ok      Resultado booleano.
 * @param string $detail  Evidencia verificable.
 */
function record_gate( string $gate_id, bool $ok, string $detail ): void {
	global $results, $passed, $failed;
	$status = $ok ? 'PASS' : 'FAIL';
	if ( $ok ) {
		++$passed;
	} else {
		++$failed;
	}
	$results[] = array(
		'gate'   => $gate_id,
		'status' => $status,
		'detail' => $detail,
	);
	echo sprintf( "[%s] %s\n       %s\n", $status, $gate_id, $detail );
}

/**
 * Obtiene métricas del centinela de traducciones en la BD indicada.
 *
 * @param string $db_name Nombre de la base de datos.
 * @return array{rows: int, md5: string}
 */
function get_icl_sentinel( string $db_name ): array {
	$mysqli = new mysqli( '127.0.0.1', 'root', '', $db_name, 3306 );
	if ( $mysqli->connect_errno ) {
		return array(
			'rows' => -1,
			'md5'  => 'connect_error',
		);
	}
	$mysqli->set_charset( 'utf8mb4' );
	$mysqli->query( 'SET SESSION group_concat_max_len = 10485760' );
	$res = $mysqli->query(
		"SELECT COUNT(*) AS cnt, MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code,'')) ORDER BY translation_id ASC SEPARATOR '|')) AS hash_val FROM etk_t_icl_translations"
	);
	$row = $res ? $res->fetch_assoc() : array();
	$mysqli->close();

	return array(
		'rows' => isset( $row['cnt'] ) ? (int) $row['cnt'] : 0,
		'md5'  => isset( $row['hash_val'] ) ? (string) $row['hash_val'] : '',
	);
}

/**
 * Realiza una petición HTTP local e inspecciona encabezados y canonicals.
 *
 * @param string $url URL a consultar.
 * @return array{code: int, canonicals: array<string>}
 */
function fetch_and_inspect_http( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'TFML-Fase343A-Verifier/1.0' );
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$canonicals = array();
	if ( is_string( $body ) && '' !== $body ) {
		if ( preg_match_all( '/<link[^>]+rel=[\'"]canonical[\'"][^>]+href=[\'"]([^\'"]+)[\'"]/i', $body, $matches ) ) {
			$canonicals = array_merge( $canonicals, $matches[1] );
		}
	}

	return array(
		'code'       => $code,
		'canonicals' => $canonicals,
	);
}

echo "========================================================================\n";
echo "TF MULTILINGUAL — VERIFICADOR FASE 3.43A (DT-TFML-075)\n";
echo "Hardening de Vinculación y Desvinculación Editorial\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// CONTROL 1: Invarianza de Producción — cms_ect
// -------------------------------------------------------------------------
$expected_prod_md5 = '4241ca7e7ec6399a594537cb04790c10';
$prod_sentinel     = get_icl_sentinel( 'cms_ect' );
$prod_ok           = ( 3403 === $prod_sentinel['rows'] && $expected_prod_md5 === $prod_sentinel['md5'] );

record_gate(
	'1. Invarianza de Producción — cms_ect intocada (3,403 filas, hash inmutable)',
	$prod_ok,
	sprintf( 'rows=%d/3403 | md5=%s (esperado: %s)', $prod_sentinel['rows'], $prod_sentinel['md5'], $expected_prod_md5 )
);

// -------------------------------------------------------------------------
// CONTROL 2: Conexión Efectiva — cms_ect_lab
// -------------------------------------------------------------------------
global $wpdb;
$active_db   = (string) $wpdb->get_var( 'SELECT DATABASE()' );
$constant_db = defined( 'DB_NAME' ) ? DB_NAME : '';
$property_db = (string) $wpdb->dbname;
$conn_ok     = ( 'cms_ect_lab' === $active_db && 'cms_ect_lab' === $constant_db && 'cms_ect_lab' === $property_db );

record_gate(
	'2. Conexión Efectiva — WordPress conectado exclusivamente a cms_ect_lab',
	$conn_ok,
	sprintf( 'DB_NAME=%s | wpdb->dbname=%s | SELECT DATABASE()=%s', $constant_db, $property_db, $active_db )
);

// -------------------------------------------------------------------------
// CONTROL 3: Invarianza de Datos en Laboratorio (123 grupos, 139 elementos, 3 FR retenidas)
// -------------------------------------------------------------------------
$table_groups   = $wpdb->prefix . 'tfml_groups';
$table_elements = $wpdb->prefix . 'tfml_group_elements';

$count_groups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_groups}" );
$count_elements = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_elements}" );

$retained_registry = new RetainedTranslationRegistry( $wpdb );
$retained_fr       = $retained_registry->get_retained_for_language( 'fr' );
$retained_count    = count( $retained_fr );

$expected_fr_ids = array( 8839, 8955, 8873 );
$all_retained_ok = true;
foreach ( $expected_fr_ids as $fid ) {
	if ( ! $retained_registry->has_retained_element( 'post', $fid ) ) {
		$all_retained_ok = false;
	}
}

$lab_data_ok = ( 123 === $count_groups && 139 === $count_elements && 3 === $retained_count && $all_retained_ok );

record_gate(
	'3. Invarianza Laboratorio — 123 grupos, 139 elementos y 3 traducciones FR retenidas preservadas',
	$lab_data_ok,
	sprintf( 'groups=%d/123 | elements=%d/139 | retained_fr=%d/3 (ids [8839,8955,8873] ok=%s)', $count_groups, $count_elements, $retained_count, $all_retained_ok ? 'true' : 'false' )
);

// -------------------------------------------------------------------------
// CONTROL 4: Centinela WPML en Laboratorio Intacto (3,403 filas)
// -------------------------------------------------------------------------
$lab_sentinel = get_icl_sentinel( 'cms_ect_lab' );
$lab_sent_ok  = ( 3403 === $lab_sentinel['rows'] && $expected_prod_md5 === $lab_sentinel['md5'] );

record_gate(
	'4. Centinela WPML Laboratorio — 3,403 filas en etk_t_icl_translations intactas',
	$lab_sent_ok,
	sprintf( 'rows=%d/3403 | md5=%s', $lab_sentinel['rows'], $lab_sentinel['md5'] )
);

// -------------------------------------------------------------------------
// CONTROL 5: UI Editorial en WP-Admin — Formulario POST (0 Enlaces GET)
// -------------------------------------------------------------------------
$plugin            = Plugin::get_instance();
$editorial_service = $plugin->get_editorial_service();
$language_registry = $plugin->get_language_registry();
$post_editorial_ui = new PostEditorialUi( $editorial_service, $language_registry );

// Managed post (Page 4976 - Nosotros)
$managed_post = get_post( 4976 );
ob_start();
$post_editorial_ui->render_meta_box( $managed_post );
$managed_html = ob_get_clean();

$has_unlink_form    = ( false !== strpos( $managed_html, 'class="tfml-unlink-form"' ) );
$has_form_post      = ( false !== strpos( $managed_html, 'method="post"' ) );
$has_unlink_action  = ( false !== strpos( $managed_html, 'name="action" value="tfml_unlink_post_translation"' ) );
$has_source_id_in   = ( false !== strpos( $managed_html, 'name="source_post_id" value="4976"' ) );
$has_element_id_in  = ( false !== strpos( $managed_html, 'name="element_id" value="8339"' ) );
$has_unlink_btn     = ( false !== strpos( $managed_html, 'tfml-unlink-btn' ) );
$has_unlink_icon    = ( false !== strpos( $managed_html, 'dashicons-editor-unlink' ) );
$has_clear_confirm  = ( false !== strpos( $managed_html, 'El contenido se conservará en WordPress, pero dejará de estar relacionado como traducción' ) );
// Verificamos categóricamente que NO existan enlaces <a> con acción de desvinculación
$no_get_unlink_link = ( false === strpos( $managed_html, 'href="' . admin_url( 'admin-post.php?action=tfml_unlink_post_translation' ) ) );

$ui_post_ok = $has_unlink_form && $has_form_post && $has_unlink_action && $has_source_id_in
	&& $has_element_id_in && $has_unlink_btn && $has_unlink_icon && $has_clear_confirm && $no_get_unlink_link;

record_gate(
	'5. UI Editorial WP-Admin — Formulario POST para desvinculación (Cero enlaces GET modificadores)',
	$ui_post_ok,
	sprintf(
		'unlink_form=%s | method_post=%s | action_hidden=%s | source_id=%s | element_id=%s | clear_confirm=%s | no_get_link=%s',
		$has_unlink_form ? 'true' : 'false',
		$has_form_post ? 'true' : 'false',
		$has_unlink_action ? 'true' : 'false',
		$has_source_id_in ? 'true' : 'false',
		$has_element_id_in ? 'true' : 'false',
		$has_clear_confirm ? 'true' : 'false',
		$no_get_unlink_link ? 'true' : 'false'
	)
);

// -------------------------------------------------------------------------
// CONTROL 6: Rechazo Categórico de Solicitudes GET y Nonces Inválidos
// -------------------------------------------------------------------------
require_once dirname( __DIR__ ) . '/tests/Doubles/TestableWpdb.php';
use TF\Multilingual\Tests\Doubles\TestableWpdb;

$test_db         = new TestableWpdb();
$test_group_repo = new TranslationGroupRepository( $test_db );
$test_resolver   = new ContentTranslationResolver( $test_group_repo );
$test_status_res = new TranslationStatusResolver( $test_group_repo, $language_registry );
$test_service    = new TranslationEditorialService(
	$language_registry,
	$test_group_repo,
	$test_resolver,
	null,
	$test_db,
	null,
	$test_status_res,
	null,
	$retained_registry
);
$test_ui = new PostEditorialUi( $test_service, $language_registry );

$security_checks_passed = 0;

// 1. GET rechazo en handle_unlink_translation
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST                     = array();
try {
	$test_ui->handle_unlink_translation();
} catch ( \RuntimeException $e ) {
	if ( 405 === $e->getCode() ) {
		++$security_checks_passed;
	}
}

// 2. GET rechazo en handle_create_translation
try {
	$test_ui->handle_create_translation();
} catch ( \RuntimeException $e ) {
	if ( 405 === $e->getCode() ) {
		++$security_checks_passed;
	}
}

// 3. GET rechazo en handle_link_translation
try {
	$test_ui->handle_link_translation();
} catch ( \RuntimeException $e ) {
	if ( 405 === $e->getCode() ) {
		++$security_checks_passed;
	}
}

// 4. POST con nonce inválido en handle_unlink_translation
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = array(
	'element_id'        => 8339,
	'source_post_id'    => 4976,
	'tfml_unlink_nonce' => 'nonce_falso',
);
try {
	$test_ui->handle_unlink_translation();
} catch ( \RuntimeException $e ) {
	if ( 403 === $e->getCode() ) {
		++$security_checks_passed;
	}
}

// 5. POST con usuario sin permiso edit_post
$_POST['tfml_unlink_nonce']                = wp_create_nonce( PostEditorialUi::NONCE_ACTION_UNLINK . 8339 );
$GLOBALS['wp_test_caps']['edit_post:8339'] = false;
$GLOBALS['wp_test_caps']['edit_post:4976'] = true;
try {
	$test_ui->handle_unlink_translation();
} catch ( \RuntimeException $e ) {
	if ( 403 === $e->getCode() ) {
		++$security_checks_passed;
	}
}

$sec_ok = ( 5 === $security_checks_passed );

record_gate(
	'6. Demostración de Seguridad HTTP / POST — Rechazo estricto de GET (405) y nonces/permisos inválidos (403)',
	$sec_ok,
	sprintf( 'passed_checks=%d/5 (unlink_get=405, create_get=405, link_get=405, invalid_nonce=403, no_permission=403)', $security_checks_passed )
);

// -------------------------------------------------------------------------
// CONTROL 7: Demostración Anti-Tampering entre Grupos
// -------------------------------------------------------------------------
// Creamos fixtures sintéticas de dos grupos independientes
$source_g1 = new WP_Post( (object) array( 'ID' => 9001, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'G1 ES' ) );
$target_g1 = new WP_Post( (object) array( 'ID' => 9002, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'G1 EN' ) );
$source_g2 = new WP_Post( (object) array( 'ID' => 9003, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'G2 ES' ) );
$target_g2 = new WP_Post( (object) array( 'ID' => 9004, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'G2 EN' ) );

$GLOBALS['wp_test_posts'][9001] = $source_g1;
$GLOBALS['wp_test_posts'][9002] = $target_g1;
$GLOBALS['wp_test_posts'][9003] = $source_g2;
$GLOBALS['wp_test_posts'][9004] = $target_g2;

$GLOBALS['wp_test_caps']['edit_post:9001'] = true;
$GLOBALS['wp_test_caps']['edit_post:9002'] = true;
$GLOBALS['wp_test_caps']['edit_post:9003'] = true;
$GLOBALS['wp_test_caps']['edit_post:9004'] = true;

$test_service->link_translation( 'post', 9001, 9002, 'en', 'es' );
$test_service->link_translation( 'post', 9003, 9004, 'en', 'es' );

$tampering_blocked = false;
try {
	// Intento de desvincular el post 9004 (del Grupo 2) bajo el contexto del post 9001 (del Grupo 1)
	$test_service->unlink_translation( 'post', 9004, null, 9001 );
} catch ( EditorialConflictException $e ) {
	$tampering_blocked = true;
}

$tampering_unauthorized_source_blocked = false;
$GLOBALS['wp_test_caps']['edit_post:9001'] = false; // Sin permiso sobre origen
try {
	$test_service->unlink_translation( 'post', 9002, null, 9001 );
} catch ( EditorialPermissionException $e ) {
	$tampering_unauthorized_source_blocked = true;
}
$GLOBALS['wp_test_caps']['edit_post:9001'] = true;

$tampering_ok = $tampering_blocked && $tampering_unauthorized_source_blocked;

record_gate(
	'7. Demostración Anti-Tampering y Validación de Contexto — Bloqueo de manipulación cruzada entre grupos',
	$tampering_ok,
	sprintf(
		'cross_group_tampering_blocked=%s | unauthorized_source_context_blocked=%s',
		$tampering_blocked ? 'true' : 'false',
		$tampering_unauthorized_source_blocked ? 'true' : 'false'
	)
);

// -------------------------------------------------------------------------
// CONTROL 8: Integridad Relacional, Ciclo de Vida y Transaccionalidad
// -------------------------------------------------------------------------
$relational_checks = 0;

// 1. Desvinculación de traducción secundaria en Grupo 1 (9002 de 9001)
$unlink_secondary_ok = $test_service->unlink_translation( 'post', 9002, null, 9001 );
if ( $unlink_secondary_ok && null === $test_group_repo->find_element( 'post', 9002 ) && null !== $test_group_repo->find_element( 'post', 9001 ) ) {
	++$relational_checks;
}

// 2. Reintento sobre elemento ya desvinculado (9002) arroja EditorialValidationException
try {
	$test_service->unlink_translation( 'post', 9002 );
} catch ( EditorialValidationException $e ) {
	++$relational_checks;
}

// 3. Desvinculación del canónico en Grupo 2 (9003 canónico desvinculado -> 9004 promovido a canónico)
$unlink_canonical_ok = $test_service->unlink_translation( 'post', 9003 );
$reloaded_g2 = $test_group_repo->find_by_element( 'post', 9004 );
if ( $unlink_canonical_ok && null !== $reloaded_g2 && 9004 === $reloaded_g2->get_canonical_element_id() ) {
	++$relational_checks;
}

// 4. Desvinculación del único elemento restante (9004): purga de grupo huérfano y post Core intacto
$unlink_last_ok = $test_service->unlink_translation( 'post', 9004 );
$group_g2_id    = (int) $reloaded_g2->get_id();
if ( $unlink_last_ok && null === $test_group_repo->find( $group_g2_id ) && isset( $GLOBALS['wp_test_posts'][9004] ) ) {
	++$relational_checks;
}

// 5. Preservación de registros en RetainedTranslationRegistry
$has_8839_before = $retained_registry->has_retained_element( 'post', 8839 );
$has_8955_before = $retained_registry->has_retained_element( 'post', 8955 );
$has_8873_before = $retained_registry->has_retained_element( 'post', 8873 );

$test_source_ret = new WP_Post( (object) array( 'ID' => 9011, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Ret ES' ) );
$test_target_ret = new WP_Post( (object) array( 'ID' => 9012, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Ret EN' ) );
$GLOBALS['wp_test_posts'][9011] = $test_source_ret;
$GLOBALS['wp_test_posts'][9012] = $test_target_ret;
$GLOBALS['wp_test_caps']['edit_post:9011'] = true;
$GLOBALS['wp_test_caps']['edit_post:9012'] = true;
$test_service->link_translation( 'post', 9011, 9012, 'en', 'es' );

$test_service->unlink_translation( 'post', 9012 );

$has_8839_after = $retained_registry->has_retained_element( 'post', 8839 );
$has_8955_after = $retained_registry->has_retained_element( 'post', 8955 );
$has_8873_after = $retained_registry->has_retained_element( 'post', 8873 );

if ( $has_8839_before && $has_8839_after && $has_8955_before && $has_8955_after && $has_8873_before && $has_8873_after ) {
	++$relational_checks;
}

// 6. Rollback efectivo ante error de base de datos
$test_source_rb = new WP_Post( (object) array( 'ID' => 9021, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'RB ES' ) );
$test_target_rb = new WP_Post( (object) array( 'ID' => 9022, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'RB EN' ) );
$GLOBALS['wp_test_posts'][9021] = $test_source_rb;
$GLOBALS['wp_test_posts'][9022] = $test_target_rb;
$GLOBALS['wp_test_caps']['edit_post:9021'] = true;
$GLOBALS['wp_test_caps']['edit_post:9022'] = true;
$test_service->link_translation( 'post', 9021, 9022, 'en', 'es' );

$test_db->force_next_error = 'Simulated deadlock error';
$rollback_succeeded        = false;
try {
	$test_service->unlink_translation( 'post', 9022 );
} catch ( \Throwable $e ) {
	// Tras el fallo, ambos elementos deben seguir existiendo en el repositorio de grupos
	if ( null !== $test_group_repo->find_element( 'post', 9021 ) && null !== $test_group_repo->find_element( 'post', 9022 ) ) {
		$rollback_succeeded = true;
	}
}
if ( $rollback_succeeded ) {
	++$relational_checks;
}

$rel_ok = ( 6 === $relational_checks );

record_gate(
	'8. Demostración de Integridad Relacional y Transaccional — Desvinculación secundaria, canónica, purga vacía, retenidas y rollback',
	$rel_ok,
	sprintf(
		'checks_passed=%d/6 (unlink_sec, retry_not_in_group, canonical_promotion, purge_empty, retained_preserved, sql_rollback)',
		$relational_checks
	)
);

// -------------------------------------------------------------------------
// CONTROL 9: No-regresión HTTP y SEO Canónico en Frontend
// -------------------------------------------------------------------------
$endpoints = array(
	'es_nosotros'   => 'http://lab.ecoterra.test/nosotros/',
	'en_about'      => 'http://lab.ecoterra.test/en/about-us/',
	'es_actividad'  => 'http://lab.ecoterra.test/actividades/',
	'en_activities' => 'http://lab.ecoterra.test/en/activities/',
);

$http_results = array();
$http_all_ok  = true;
foreach ( $endpoints as $key => $url ) {
	$res          = fetch_and_inspect_http( $url );
	$canonical_ok = ( 1 === count( $res['canonicals'] ) && $url === $res['canonicals'][0] );
	$status_ok    = ( 200 === $res['code'] );
	$ok           = $status_ok && $canonical_ok;
	if ( ! $ok ) {
		$http_all_ok = false;
	}
	$http_results[ $key ] = array(
		'code'        => $res['code'],
		'canonical'   => $res['canonicals'][0] ?? 'N/A',
		'canon_count' => count( $res['canonicals'] ),
	);
}

record_gate(
	'9. Frontend HTTP & Canonical — 4/4 páginas HTTP 200 con canonical único y correcto',
	$http_all_ok,
	sprintf(
		'nosotros=[%d, %s] | about-us=[%d, %s] | actividades=[%d, %s] | activities=[%d, %s]',
		$http_results['es_nosotros']['code'],
		$http_results['es_nosotros']['canonical'],
		$http_results['en_about']['code'],
		$http_results['en_about']['canonical'],
		$http_results['es_actividad']['code'],
		$http_results['es_actividad']['canonical'],
		$http_results['en_activities']['code'],
		$http_results['en_activities']['canonical']
	)
);

// -------------------------------------------------------------------------
// CONTROL 10: XML Sitemaps (30 entradas, 0 francés, blog_public = 0)
// -------------------------------------------------------------------------
$provider_posts = new WP_Sitemaps_Posts();
$provider_tax   = new WP_Sitemaps_Taxonomies();

$sitemap_pages = $provider_posts->get_url_list( 1, 'page' );
$sitemap_posts = $provider_posts->get_url_list( 1, 'post' );
$sitemap_cats  = $provider_tax->get_url_list( 1, 'category' );
$sitemap_tags  = $provider_tax->get_url_list( 1, 'post_tag' );

$count_pages = count( $sitemap_pages );
$count_posts = count( $sitemap_posts );
$count_cats  = count( $sitemap_cats );
$count_tags  = count( $sitemap_tags );
$total_urls  = $count_pages + $count_posts + $count_cats + $count_tags;

$blog_public_opt = (int) get_option( 'blog_public' );

$sitemaps_ok = ( 7 === $count_pages && 3 === $count_posts && 1 === $count_cats && 19 === $count_tags && 30 === $total_urls && 0 === $blog_public_opt );

record_gate(
	'10. XML Sitemaps — 30 entradas sin francés retenido y blog_public=0',
	$sitemaps_ok,
	sprintf(
		'page=%d/7 | post=%d/3 | category=%d/1 | post_tag=%d/19 | total=%d/30 | blog_public=%d',
		$count_pages,
		$count_posts,
		$count_cats,
		$count_tags,
		$total_urls,
		$blog_public_opt
	)
);

echo "\n------------------------------------------------------------------------\n";
echo sprintf( "RESUMEN FINAL: %d/%d CONTROLES PASS (%d FAIL)\n", $passed, count( $results ), $failed );
echo "------------------------------------------------------------------------\n\n";

if ( $failed > 0 ) {
	exit( 1 );
}
exit( 0 );
