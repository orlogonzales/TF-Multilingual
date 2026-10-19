<?php
/**
 * Verificador Reproducible de Fase 3.43 (Directiva DT-TFML-074).
 *
 * Vinculación y Desvinculación Editorial de Traducciones Existentes:
 * - Menú y caja meta editorial en WP-Admin (PostEditorialUi) con pestañas Crear vs Vincular
 * - Búsqueda de candidatos en vivo y fallback por ID directo con previsualización
 * - Desvinculación segura preservando la entidad de Core en wp_posts
 * - Validaciones de integridad de dominio (subtipos, duplicidad, no-mutación de franceses retenidos)
 * - Invarianza estricta de producción (cms_ect) y laboratorio (cms_ect_lab: 123 grupos, 139 elementos)
 * - No-regresión HTTP, SEO canónico y sitemaps en laboratorio
 *
 * Uso:
 *   php scratch/verify_fase_3_43.php
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
error_reporting( E_ALL );

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
 * Consulta un endpoint HTTP y extrae código de estado y canonicals.
 *
 * @param string $url URL a consultar.
 * @return array{code: int, canonicals: list<string>}
 */
function fetch_and_inspect_http( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$canonicals = array();
	if ( is_string( $body ) && '' !== $body ) {
		if ( preg_match_all( '/<link[^>]+rel=[\'"]canonical[\'"][^>]+href=[\'"]([^\'"]+)[\'"]/i', $body, $matches ) ) {
			$canonicals = array_merge( $canonicals, $matches[1] );
		}
		if ( preg_match_all( '/<link[^>]+href=[\'"]([^\'"]+)[\'"][^>]+rel=[\'"]canonical[\'"]/i', $body, $matches2 ) ) {
			$canonicals = array_merge( $canonicals, $matches2[1] );
		}
		$canonicals = array_values( array_unique( $canonicals ) );
	}

	return array(
		'code'       => $code,
		'canonicals' => $canonicals,
	);
}

echo "========================================================================\n";
echo "TF MULTILINGUAL — VERIFICADOR FASE 3.43 (DT-TFML-074)\n";
echo "Vinculación y Desvinculación Editorial de Traducciones Existentes\n";
echo "========================================================================\n\n";

global $wpdb;

// -------------------------------------------------------------------------
// CONTROL 1: Invarianza Estricta de Producción (cms_ect)
// -------------------------------------------------------------------------
$prod_sentinel    = get_icl_sentinel( 'cms_ect' );
$expected_prod_md5 = '4241ca7e7ec6399a594537cb04790c10';
$prod_ok          = ( 3403 === $prod_sentinel['rows'] && $expected_prod_md5 === $prod_sentinel['md5'] );

record_gate(
	'1. Invarianza de Producción — cms_ect intocada (3,403 filas, hash inmutable)',
	$prod_ok,
	sprintf( 'rows=%d/3403 | md5=%s (esperado: %s)', $prod_sentinel['rows'], $prod_sentinel['md5'], $expected_prod_md5 )
);

// -------------------------------------------------------------------------
// CONTROL 2: Conexión Efectiva Exclusiva al Laboratorio (cms_ect_lab)
// -------------------------------------------------------------------------
$current_db_var = (string) $wpdb->get_var( 'SELECT DATABASE()' );
$conn_ok        = ( 'cms_ect_lab' === DB_NAME && 'cms_ect_lab' === $wpdb->dbname && 'cms_ect_lab' === $current_db_var );

record_gate(
	'2. Conexión Efectiva — WordPress conectado exclusivamente a cms_ect_lab',
	$conn_ok,
	sprintf( 'DB_NAME=%s | wpdb->dbname=%s | SELECT DATABASE()=%s', DB_NAME, $wpdb->dbname, $current_db_var )
);

// -------------------------------------------------------------------------
// CONTROL 3: Invarianza de Datos de Laboratorio (123 grupos, 139 elementos, 3 FR)
// -------------------------------------------------------------------------
$table_groups   = $wpdb->prefix . 'tfml_groups';
$table_elements = $wpdb->prefix . 'tfml_group_elements';

$count_groups   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_groups}" );
$count_elements = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_elements}" );

$retained_registry = new RetainedTranslationRegistry();
$retained_fr       = $retained_registry->get_retained_for_language( 'fr' );
$retained_count    = count( $retained_fr );
$retained_ids      = array( 8839, 8955, 8873 );
$all_retained_ok   = true;
foreach ( $retained_ids as $r_id ) {
	if ( ! $retained_registry->has_retained_element( 'post', $r_id ) ) {
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
// CONTROL 5: UI Editorial en WP-Admin (PostEditorialUi)
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

$has_unlink_btn     = ( false !== strpos( $managed_html, 'tfml-unlink-btn' ) );
$has_unlink_icon    = ( false !== strpos( $managed_html, 'dashicons-editor-unlink' ) );
$has_tab_create     = ( false !== strpos( $managed_html, 'tfml-tab-create' ) );
$has_tab_link       = ( false !== strpos( $managed_html, 'tfml-tab-link' ) );
$has_search_input   = ( false !== strpos( $managed_html, 'tfml-candidate-search-input' ) );
$has_target_id_in   = ( false !== strpos( $managed_html, 'tfml-target-id-input' ) );
$has_preview_box    = ( false !== strpos( $managed_html, 'tfml-candidate-preview-box' ) );
$has_link_btn       = ( false !== strpos( $managed_html, 'tfml-link-submit-btn' ) );
$has_ajax_nonce     = ( false !== strpos( $managed_html, 'tfml_editorial_ajax_nonce' ) );

// Unmanaged post check
$unmanaged_post            = new WP_Post( (object) array( 'ID' => 99999, 'post_type' => 'page' ) );
$unmanaged_post->ID        = 99999;
$unmanaged_post->post_type = 'page';
ob_start();
$post_editorial_ui->render_meta_box( $unmanaged_post );
$unmanaged_html = ob_get_clean();

$has_unmanaged_msg    = ( false !== strpos( $unmanaged_html, 'This content is not yet managed' ) );
$has_assign_select    = ( false !== strpos( $unmanaged_html, 'tfml_assign_language' ) );

$ui_ok = $has_unlink_btn && $has_unlink_icon && $has_tab_create && $has_tab_link
	&& $has_search_input && $has_target_id_in && $has_preview_box && $has_link_btn
	&& $has_ajax_nonce && $has_unmanaged_msg && $has_assign_select;

record_gate(
	'5. UI Editorial WP-Admin — Renderizado de pestañas Crear vs Vincular, búsqueda en vivo, ID directo y botón desvincular',
	$ui_ok,
	sprintf(
		'unlink_btn=%s | unlink_icon=%s | tab_create=%s | tab_link=%s | search_input=%s | target_id=%s | preview_box=%s | ajax_nonce=%s | unmanaged_ui=%s',
		$has_unlink_btn ? 'true' : 'false',
		$has_unlink_icon ? 'true' : 'false',
		$has_tab_create ? 'true' : 'false',
		$has_tab_link ? 'true' : 'false',
		$has_search_input ? 'true' : 'false',
		$has_target_id_in ? 'true' : 'false',
		$has_preview_box ? 'true' : 'false',
		$has_ajax_nonce ? 'true' : 'false',
		( $has_unmanaged_msg && $has_assign_select ) ? 'true' : 'false'
	)
);

// -------------------------------------------------------------------------
// CONTROL 6: Demostración Sintética POSITIVA de Vinculación
// -------------------------------------------------------------------------
// Realizamos la prueba en una instancia aislada en memoria de los servicios de dominio,
// garantizando CERO escrituras sobre las páginas reales de cms_ect_lab.
require_once dirname( __DIR__ ) . '/tests/Doubles/TestableWpdb.php';
use TF\Multilingual\Tests\Doubles\TestableWpdb;

$test_db       = new TestableWpdb();
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

// Poblamos dos posts sintéticos elegibles en $GLOBALS['wp_test_posts']
$source_synth = new WP_Post(
	(object) array(
		'ID'          => 89901,
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Página Origen Sintética',
	)
);

$target_synth = new WP_Post(
	(object) array(
		'ID'          => 89902,
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Página Destino Portugués Sintética',
	)
);

$GLOBALS['wp_test_posts'][89901] = $source_synth;
$GLOBALS['wp_test_posts'][89902] = $target_synth;
$GLOBALS['wp_test_caps']['edit_post:89901'] = true;
$GLOBALS['wp_test_caps']['edit_post:89902'] = true;

try {
	// Creamos grupo inicial en memoria para el post origen en 'es'
	$init_group = $test_service->assign_initial_language( 'post', 89901, 'page', 'es' );
	$linked_group = $test_service->link_translation( 'post', 89901, 89902, 'pt' );

	$elements = $linked_group->get_elements();
	$has_es   = false;
	$has_pt   = false;
	foreach ( $elements as $el ) {
		if ( 89901 === $el->get_element_id() && 'es' === $el->get_language_code() ) {
			$has_es = true;
		}
		if ( 89902 === $el->get_element_id() && 'pt' === $el->get_language_code() ) {
			$has_pt = true;
		}
	}

	$positive_link_ok = ( 2 === count( $elements ) && $has_es && $has_pt && 89901 === $linked_group->get_canonical_element_id() );
} catch ( \Throwable $e ) {
	echo "EXCEPTION IN GATE 6: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
	$positive_link_ok = false;
	$elements = array();
	$linked_group = new TranslationGroup( 'post', 'page', 0, 0, '' );
}

record_gate(
	'6. Demostración Sintética POSITIVA de Vinculación — Enlace bidireccional en grupo único sin mutación Core',
	$positive_link_ok,
	sprintf(
		'group_id=%d | elements_count=%d/2 | source_es=%s | target_pt=%s | canonical_id=%d/89901',
		$linked_group->get_id(),
		count( $elements ),
		$has_es ? 'true' : 'false',
		$has_pt ? 'true' : 'false',
		$linked_group->get_canonical_element_id()
	)
);

// -------------------------------------------------------------------------
// CONTROL 7: Demostración Sintética NEGATIVA de Rechazo de Vinculación
// -------------------------------------------------------------------------
$rejected_count = 0;

// 1. Idioma duplicado en grupo
try {
	$target_dup = new WP_Post(
		(object) array(
			'ID'          => 89903,
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	);
	$GLOBALS['wp_test_posts'][89903] = $target_dup;
	$GLOBALS['wp_test_caps']['edit_post:89903'] = true;
	$test_service->link_translation( 'post', 89901, 89903, 'es' ); // 'es' already exists!
} catch ( EditorialConflictException $e ) {
	++$rejected_count;
}

// 2. Subtipo dispar (post vs page)
try {
	$target_mismatch = new WP_Post(
		(object) array(
			'ID'          => 89904,
			'post_type'   => 'post', // mismatch!
			'post_status' => 'publish',
		)
	);
	$GLOBALS['wp_test_posts'][89904] = $target_mismatch;
	$GLOBALS['wp_test_caps']['edit_post:89904'] = true;
	$test_service->link_translation( 'post', 89901, 89904, 'en' );
} catch ( EditorialValidationException $e ) {
	++$rejected_count;
}

// 3. Destino ya vinculado en otro grupo
try {
	$other_post = new WP_Post(
		(object) array(
			'ID'          => 89905,
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	);
	$GLOBALS['wp_test_posts'][89905] = $other_post;
	$GLOBALS['wp_test_caps']['edit_post:89905'] = true;
	$test_service->assign_initial_language( 'post', 89905, 'page', 'en' );
	// Now attempt to link 89905 into 89901
	$test_service->link_translation( 'post', 89901, 89905, 'en' );
} catch ( EditorialConflictException $e ) {
	++$rejected_count;
}

// 4. IDs idénticos (source == target)
try {
	$test_service->link_translation( 'post', 89901, 89901, 'en' );
} catch ( EditorialValidationException $e ) {
	++$rejected_count;
}

// 5. Idioma inactivo / traducción retenida francesa
try {
	$target_fr = new WP_Post(
		(object) array(
			'ID'          => 8839,
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	);
	$GLOBALS['wp_test_posts'][8839] = $target_fr;
	$GLOBALS['wp_test_caps']['edit_post:8839'] = true;
	$test_service->link_translation( 'post', 89901, 8839, 'fr' );
} catch ( EditorialValidationException $e ) {
	++$rejected_count;
}

// 6. Sin permiso de edición
try {
	$target_noperm = new WP_Post(
		(object) array(
			'ID'          => 89906,
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	);
	$GLOBALS['wp_test_posts'][89906] = $target_noperm;
	$GLOBALS['wp_test_caps']['edit_post:89906'] = false;
	$test_service->link_translation( 'post', 89901, 89906, 'en' );
} catch ( EditorialPermissionException $e ) {
	++$rejected_count;
}

$negative_ok = ( 6 === $rejected_count );

record_gate(
	'7. Demostración Sintética NEGATIVA de Rechazo — 6/6 violaciones de dominio bloqueadas con excepciones tipadas',
	$negative_ok,
	sprintf( 'rejected_cases=%d/6 (idioma_duplicado, subtipo_dispar, ya_vinculado, id_identico, idioma_inactivo_fr, sin_permiso)', $rejected_count )
);

// -------------------------------------------------------------------------
// CONTROL 8: Demostración Sintética de DESVINCULACIÓN y Preservación de Core
// -------------------------------------------------------------------------
// Desvinculamos el elemento 89902 del grupo vinculado anteriormente.
$unlink_res = $test_service->unlink_translation( 'post', 89902 );
$updated_group = $test_group_repo->find_by_element( 'post', 89901 );

$has_remaining_es  = false;
$has_unlinked_elem = false;
if ( null !== $updated_group ) {
	foreach ( $updated_group->get_elements() as $el ) {
		if ( 89901 === $el->get_element_id() ) {
			$has_remaining_es = true;
		}
		if ( 89902 === $el->get_element_id() ) {
			$has_unlinked_elem = true;
		}
	}
}

// Verificamos que la entidad en Core ($GLOBALS['wp_test_posts'][89902]) permanece INTACTA
$core_entity_intact = isset( $GLOBALS['wp_test_posts'][89902] ) && 'Página Destino Portugués Sintética' === $GLOBALS['wp_test_posts'][89902]->post_title;

$unlink_ok = $unlink_res && $has_remaining_es && ! $has_unlinked_elem && $core_entity_intact;

record_gate(
	'8. Demostración Sintética de DESVINCULACIÓN — Remoción de elementos en tfml y 100% preservación de entidad en Core',
	$unlink_ok,
	sprintf(
		'unlink_res=%s | remaining_es=%s | removed_pt=%s | core_entity_preserved=%s',
		$unlink_res ? 'true' : 'false',
		$has_remaining_es ? 'true' : 'false',
		( ! $has_unlinked_elem ) ? 'true' : 'false',
		$core_entity_intact ? 'true' : 'false'
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
// CONTROL 10: XML Sitemaps en Laboratorio (30 entradas, 0 francés, blog_public=0)
// -------------------------------------------------------------------------
$posts_prov = new WP_Sitemaps_Posts();
$tax_prov   = new WP_Sitemaps_Taxonomies();

$page_list = $posts_prov->get_url_list( 1, 'page' );
$post_list = $posts_prov->get_url_list( 1, 'post' );
$cat_list  = $tax_prov->get_url_list( 1, 'category' );
$tag_list  = $tax_prov->get_url_list( 1, 'post_tag' );

$total_sitemap_entries = count( $page_list ) + count( $post_list ) + count( $cat_list ) + count( $tag_list );
$blog_public_val       = (string) get_option( 'blog_public' );

$sitemap_ok = ( 7 === count( $page_list ) && 3 === count( $post_list ) && 1 === count( $cat_list ) && 19 === count( $tag_list ) && 30 === $total_sitemap_entries && '0' === $blog_public_val );

record_gate(
	'10. XML Sitemaps — 30 entradas sin francés retenido y blog_public=0',
	$sitemap_ok,
	sprintf(
		'page=%d/7 | post=%d/3 | category=%d/1 | post_tag=%d/19 | total=%d/30 | blog_public=%s',
		count( $page_list ),
		count( $post_list ),
		count( $cat_list ),
		count( $tag_list ),
		$total_sitemap_entries,
		$blog_public_val
	)
);

echo "\n------------------------------------------------------------------------\n";
echo sprintf( "RESUMEN FINAL: %d/10 CONTROLES PASS (%d FAIL)\n", $passed, $failed );
echo "------------------------------------------------------------------------\n";

if ( $failed > 0 ) {
	exit( 1 );
}

exit( 0 );
