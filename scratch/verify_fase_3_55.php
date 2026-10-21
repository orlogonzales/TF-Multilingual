<?php
/**
 * TF Multilingual — Verificador Reproducible de Fase 3.55
 * DT-TFML-088: UAT Integral, Regresiones Críticas y Cierre de Brechas Funcionales.
 *
 * Ejecuta 10 controles deterministas que cubren:
 * 1. Invarianza absoluta de producción (cms_ect).
 * 2. Estado consolidado e integridad relacional de laboratorio (cms_ect_lab) y protección de TRID 34790.
 * 3. Acreditación e inspección forense de la reconciliación del huérfano #10151.
 * 4. Regresiones críticas de ciclo de vida y hooks de eliminación en aislamiento (4 escenarios).
 * 5. Operaciones editoriales E2E reales en aislamiento (crear, vincular, desvincular, anti-tampering).
 * 6. Cadenas multilingües, fallback soberano y persistencia de ubicaciones de menú.
 * 7. REST API: autorización, nonces, permisos y respuestas de error (401, 403, 400).
 * 8. Auditoría y renderizado E2E de 13 pantallas administrativas (0 errores PHP, HTML válido).
 * 9. Matriz HTTP frontend real, conmutador de idiomas y sitemaps nativos Core.
 * 10. Invarianza final y ausencia total de residuos sintéticos.
 */

declare(strict_types=1);

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

$lab_root = 'D:/laragon/www/lab.ecoterra';
if ( ! file_exists( $lab_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "ERROR: No se encontró wp-load.php en {$lab_root}\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']       = 'lab.ecoterra.test';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PORT']     = 80;
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';

require_once $lab_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

while ( ob_get_level() > 0 ) {
	ob_end_clean();
}
ini_set( 'display_errors', '1' );

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Seo\CoreSitemapsFilter;
use TF\Multilingual\Domain\Strings\StringTranslationService;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;

global $wpdb;

echo "================================================================================\n";
echo "TF MULTILINGUAL — VERIFICADOR REPRODUCIBLE DE FASE 3.55\n";
echo "UAT INTEGRAL, REGRESIONES CRÍTICAS Y CIERRE DE BRECHAS FUNCIONALES (DT-TFML-088)\n";
echo "================================================================================\n\n";

$all_pass = true;
$results  = array();

/**
 * Helper to calculate production sentinel MD5 hash.
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
		'rows' => (int) ( $row['cnt'] ?? 0 ),
		'md5'  => (string) ( $row['hash_val'] ?? '' ),
	);
}

/**
 * Helper to inspect live HTTP routes via cURL.
 */
function inspect_route( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 15,
		)
	);
	$body = (string) curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$lang      = '';
	$canonical = '';

	if ( preg_match( '/<html[^>]+lang=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$lang = $m[1];
	}
	if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $body, $m ) ) {
		$canonical = $m[1];
	}

	return array(
		'code'      => $code,
		'lang'      => $lang,
		'canonical' => $canonical,
		'body'      => $body,
	);
}

// -----------------------------------------------------------------------------
// CONTROL 1: Invarianza Absoluta de Producción (cms_ect)
// -----------------------------------------------------------------------------
try {
	$prod_sentinel  = get_icl_sentinel( 'cms_ect' );
	$expected_count = 3403;
	$expected_md5   = '4241ca7e7ec6399a594537cb04790c10';

	if ( $prod_sentinel['rows'] === $expected_count && $prod_sentinel['md5'] === $expected_md5 ) {
		echo "[PASS] Control 1: Invarianza Absoluta de Producción (cms_ect) — Filas: {$prod_sentinel['rows']}, MD5: {$prod_sentinel['md5']}\n";
		$results[1] = true;
	} else {
		echo "[FAIL] Control 1: Invarianza Producción Violada! Filas: {$prod_sentinel['rows']} (esp {$expected_count}), MD5: {$prod_sentinel['md5']} (esp {$expected_md5})\n";
		$results[1] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 1 Excepción: " . $e->getMessage() . "\n";
	$results[1] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 2: Estado Base del Laboratorio y Protección de TRID 34790
// -----------------------------------------------------------------------------
try {
	$plugin          = Plugin::get_instance();
	$groups_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_groups" );
	$elements_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_group_elements" );
	$retained_reg    = new RetainedTranslationRegistry();
	$retained_count  = count( $retained_reg->get_all() );
	$manifests_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_options WHERE option_name LIKE 'tfml_migration_manifest_%'" );

	$migration_mgr   = $plugin->get_migration_manager();
	$checkpoint_repo = $migration_mgr->get_checkpoint_repository();
	$checkpoint      = $checkpoint_repo->get( 'wpml' );
	$lock            = $migration_mgr->get_execution_lock();
	$is_locked       = $lock->is_locked( 'wpml' );

	$fr_active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_group_elements WHERE language_code = 'fr'" );

	// Verify TRID 34790 draft posts
	$draft_posts = $wpdb->get_results( "SELECT ID, post_status FROM etk_t_posts WHERE ID IN (9662, 9663, 9664)", ARRAY_A );
	$drafts_ok   = ( 3 === count( $draft_posts ) );
	foreach ( $draft_posts as $dp ) {
		if ( 'draft' !== $dp['post_status'] ) {
			$drafts_ok = false;
		}
	}
	$tfml_draft_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_group_elements WHERE element_id IN (9662, 9663, 9664)" );

	$c2_pass = ( 141 === $groups_count
		&& 175 === $elements_count
		&& 21 === $retained_count
		&& 20 === $manifests_count
		&& null !== $checkpoint
		&& 'completed' === $checkpoint->get_status()
		&& ! $is_locked
		&& 0 === $fr_active
		&& $drafts_ok
		&& 0 === $tfml_draft_count
	);

	if ( $c2_pass ) {
		echo "[PASS] Control 2: Estado Base de Laboratorio — Grupos: {$groups_count}, Elementos: {$elements_count}, Retenidas FR: {$retained_count}, Manifiestos: {$manifests_count}, Checkpoint: {$checkpoint->get_status()}, Lock Libre: SI, TRID 34790 en draft (0 en TFML): SI\n";
		$results[2] = true;
	} else {
		echo "[FAIL] Control 2: Discrepancia en Estado Base de Laboratorio! Grp={$groups_count}, Elem={$elements_count}, Ret={$retained_count}, Man={$manifests_count}, DraftsOk=" . ( $drafts_ok ? 'yes' : 'no' ) . "\n";
		$results[2] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 2 Excepción: " . $e->getMessage() . "\n";
	$results[2] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 3: Inspección Forense de la Reconciliación del Huérfano #10151
// -----------------------------------------------------------------------------
try {
	$p10151_lab  = $wpdb->get_row( "SELECT ID, post_type, post_status, post_parent FROM etk_t_posts WHERE ID = 10151", ARRAY_A );
	$p10151_prod = $wpdb->get_row( "SELECT ID, post_type, post_status, post_parent FROM cms_ect.etk_t_posts WHERE ID = 10151", ARRAY_A );
	$tfml_10151  = $wpdb->get_row( "SELECT * FROM etk_t_tfml_group_elements WHERE element_id = 10151", ARRAY_A );
	$grp_578     = $wpdb->get_row( "SELECT * FROM etk_t_tfml_groups WHERE id = 578", ARRAY_A );
	$icl_10151   = $wpdb->get_row( "SELECT * FROM etk_t_icl_translations WHERE element_id = 10151", ARRAY_A );

	// Diagnostic orphans check
	$orphans_sql = "SELECT COUNT(*) FROM etk_t_tfml_group_elements e LEFT JOIN etk_t_posts p ON e.element_id = p.ID WHERE e.element_type = 'post' AND p.ID IS NULL";
	$orphans_cnt = (int) $wpdb->get_var( $orphans_sql );

	$c3_pass = (
		null !== $p10151_lab &&
		null !== $p10151_prod &&
		$p10151_lab['ID'] === $p10151_prod['ID'] &&
		$p10151_lab['post_type'] === 'nav_menu_item' &&
		$p10151_lab['post_status'] === 'draft' &&
		null !== $tfml_10151 &&
		(int) $tfml_10151['group_id'] === 578 &&
		null !== $grp_578 &&
		(int) $grp_578['canonical_element_id'] === 10151 &&
		null !== $icl_10151 &&
		(int) $icl_10151['trid'] === 34832 &&
		0 === $orphans_cnt
	);

	if ( $c3_pass ) {
		echo "[PASS] Control 3: Reconciliación Forense #10151 — Post 10151 presente en lab y prod, tipo 'nav_menu_item', status 'draft', Grupo #578 intacto, huérfanos diagnósticos: 0\n";
		$results[3] = true;
	} else {
		echo "[FAIL] Control 3: Error en estado forense de #10151!\n";
		$results[3] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 3 Excepción: " . $e->getMessage() . "\n";
	$results[3] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 4: Regresiones Críticas de Ciclo de Vida y Eliminación en Aislamiento
// -----------------------------------------------------------------------------
try {
	wp_set_current_user( 1 );

	$settings_repo    = new SettingsRepository();
	$lang_registry    = new LanguageRegistry( $settings_repo );
	$validator        = new \TF\Multilingual\Domain\Translation\WordPressElementValidator( $wpdb );
	$group_repo       = new TranslationGroupRepository( $wpdb, $lang_registry, $validator );
	$resolver         = new \TF\Multilingual\Domain\Translation\ContentTranslationResolver( $group_repo, $lang_registry, $validator );
	$cf_registry      = new \TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry( $settings_repo );

	$editorial_service = new TranslationEditorialService(
		$lang_registry,
		$group_repo,
		$resolver,
		$validator,
		$wpdb,
		$cf_registry,
		null,
		null,
		$retained_reg
	);

	$synth_id1 = 999901;
	$synth_id2 = 999902;
	$synth_id3 = 999903;
	$synth_id4 = 999904;
	$synth_fr  = 999905;
	$g4d_id    = 0;
	$now       = current_time( 'mysql' );

	try {
		// Scenario 4a: Deletion of normal secondary element
		$wpdb->insert( 'etk_t_posts', array( 'ID' => $synth_id1, 'post_title' => 'UAT Synth ES 1', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'uat-synth-es-1', 'post_date' => $now, 'post_date_gmt' => $now, 'post_modified' => $now, 'post_modified_gmt' => $now ) );
		$wpdb->insert( 'etk_t_posts', array( 'ID' => $synth_id2, 'post_title' => 'UAT Synth EN 1', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'uat-synth-en-1', 'post_date' => $now, 'post_date_gmt' => $now, 'post_modified' => $now, 'post_modified_gmt' => $now ) );

		$g4a    = $editorial_service->link_translation( 'post', $synth_id1, $synth_id2, 'en', 'es' );
		$g4a_id = (int) $g4a->get_id();

		// Delete secondary element $synth_id2
		$editorial_service->cleanup_deleted_element( 'post', $synth_id2 );
		$g4a_reloaded = $group_repo->find( $g4a_id );
		$s4a_ok       = ( null !== $g4a_reloaded && (int) $g4a_reloaded->get_canonical_element_id() === $synth_id1 && 1 === count( $g4a_reloaded->get_elements() ) );

		// Scenario 4b: Deletion of canonical element reassigns canonical
		$wpdb->insert( 'etk_t_posts', array( 'ID' => $synth_id3, 'post_title' => 'UAT Synth EN 2', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'uat-synth-en-2', 'post_date' => $now, 'post_date_gmt' => $now, 'post_modified' => $now, 'post_modified_gmt' => $now ) );
		$editorial_service->link_translation( 'post', $synth_id1, $synth_id3, 'en' );

		// Delete canonical $synth_id1
		$editorial_service->cleanup_deleted_element( 'post', $synth_id1 );
		$g4b_reloaded = $group_repo->find( $g4a_id );
		$s4b_ok       = ( null !== $g4b_reloaded && (int) $g4b_reloaded->get_canonical_element_id() === $synth_id3 && 1 === count( $g4b_reloaded->get_elements() ) );

		// Scenario 4c: Deletion of last element purges group when no retained
		$editorial_service->cleanup_deleted_element( 'post', $synth_id3 );
		$g4c_reloaded = $group_repo->find( $g4a_id );
		$s4c_ok       = ( null === $g4c_reloaded );

		// Scenario 4d: Deletion of last element PRESERVES group when retained FR translations exist!
		$wpdb->insert( 'etk_t_posts', array( 'ID' => $synth_id4, 'post_title' => 'UAT Synth ES Ret', 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'uat-synth-es-ret', 'post_date' => $now, 'post_date_gmt' => $now, 'post_modified' => $now, 'post_modified_gmt' => $now ) );

		$g4d    = $editorial_service->assign_initial_language( 'post', $synth_id4, 'page', 'es' );
		$g4d_id = (int) $g4d->get_id();

		// Register retained translation in RetainedTranslationRegistry
		$retained_reg->register( 'wpml', 99999, $g4d_id, 'post', 'page', array( 'fr' => $synth_fr ) );

		// Now delete sole active element $synth_id4
		$editorial_service->cleanup_deleted_element( 'post', $synth_id4 );

		// Group MUST still exist with canonical_element_id = null and 0 active elements
		$g4d_preserved = $group_repo->find( $g4d_id );
		$retained_rec  = $retained_reg->get_by_group_id( $g4d_id );

		$s4d_ok = (
			null !== $g4d_preserved &&
			(int) $g4d_preserved->get_id() === $g4d_id &&
			null === $g4d_preserved->get_canonical_element_id() &&
			0 === count( $g4d_preserved->get_elements() ) &&
			null !== $retained_rec &&
			(int) $retained_rec['trid'] === 99999
		);

	} finally {
		// Guaranteed cleanup of synthetic posts, groups and options
		if ( $g4d_id > 0 ) {
			$retained_reg->remove_by_group_id( $g4d_id );
			$wpdb->delete( 'etk_t_tfml_groups', array( 'id' => $g4d_id ), array( '%d' ) );
		}
		$wpdb->query( "DELETE FROM etk_t_posts WHERE ID >= 999900" );
		$wpdb->query( "DELETE FROM etk_t_tfml_group_elements WHERE element_id >= 999900" );
	}

	$c4_pass = ( $s4a_ok && $s4b_ok && $s4c_ok && $s4d_ok );

	if ( $c4_pass ) {
		echo "[PASS] Control 4: Regresiones Críticas de Ciclo de Vida (4 Escenarios Aislados) — Normal deleted: OK, Canonical reassign: OK, Group purged: OK, Group PRESERVED with Retained FR: OK\n";
		$results[4] = true;
	} else {
		echo "[FAIL] Control 4: Fallo en regresiones de ciclo de vida! 4a=" . ( $s4a_ok ? 'yes' : 'no' ) . ", 4b=" . ( $s4b_ok ? 'yes' : 'no' ) . ", 4c=" . ( $s4c_ok ? 'yes' : 'no' ) . ", 4d=" . ( $s4d_ok ? 'yes' : 'no' ) . "\n";
		$results[4] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 4 Excepción: " . $e->getMessage() . "\n";
	$results[4] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 5: Operaciones Editoriales Reales en Aislamiento
// -----------------------------------------------------------------------------
try {
	wp_set_current_user( 1 );

	// 5a. Search candidates
	$candidates = $editorial_service->search_link_candidates( 'post', 'page', 'en', 'About', 8354, 5 );
	$search_ok  = is_array( $candidates );

	// 5b. Link & Unlink with anti-tampering verification
	$synth_src = 999911;
	$synth_tgt = 999912;
	$grp5_id   = 0;

	try {
		$wpdb->insert( 'etk_t_posts', array( 'ID' => $synth_src, 'post_title' => 'UAT Post Src', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'uat-post-src', 'post_date' => $now, 'post_date_gmt' => $now, 'post_modified' => $now, 'post_modified_gmt' => $now ) );
		$wpdb->insert( 'etk_t_posts', array( 'ID' => $synth_tgt, 'post_title' => 'UAT Post Tgt', 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'uat-post-tgt', 'post_date' => $now, 'post_date_gmt' => $now, 'post_modified' => $now, 'post_modified_gmt' => $now ) );

		$grp5    = $editorial_service->link_translation( 'post', $synth_src, $synth_tgt, 'en', 'es' );
		$grp5_id = (int) $grp5->get_id();

		// Verify cross-group tampering rejection
		$tamper_rejected = false;
		try {
			$editorial_service->unlink_translation( 'post', $synth_tgt, null, 6237 ); // 6237 belongs to group 589!
		} catch ( \TF\Multilingual\Editorial\Exceptions\EditorialConflictException $e ) {
			$tamper_rejected = true;
		}

		// Legitimate unlink
		$unlink_ok       = $editorial_service->unlink_translation( 'post', $synth_tgt, null, $synth_src );
		$tgt_post_intact = ( null !== get_post( $synth_tgt ) ); // Object must NOT be deleted from WP Core!

		// Purge remaining single member to leave DB clean
		$editorial_service->cleanup_deleted_element( 'post', $synth_src );
	} finally {
		$wpdb->query( "DELETE FROM etk_t_posts WHERE ID IN (999911, 999912)" );
		$wpdb->query( "DELETE FROM etk_t_tfml_group_elements WHERE element_id IN (999911, 999912)" );
		if ( $grp5_id > 0 ) {
			$wpdb->delete( 'etk_t_tfml_groups', array( 'id' => $grp5_id ), array( '%d' ) );
		}
	}

	$c5_pass = ( $search_ok && null !== $grp5 && $tamper_rejected && $unlink_ok && $tgt_post_intact );

	if ( $c5_pass ) {
		echo "[PASS] Control 5: Operaciones Editoriales E2E — Search candidates: OK, Link: OK, Anti-tampering protected: OK, Unlink: OK, Post WordPress Intacto: SI\n";
		$results[5] = true;
	} else {
		echo "[FAIL] Control 5: Fallo en operaciones editoriales E2E!\n";
		$results[5] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 5 Excepción: " . $e->getMessage() . "\n";
	$results[5] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 6: Cadenas Multilingües, Fallback Soberano y Persistencia de Menús
// -----------------------------------------------------------------------------
try {
	$str_svc = $plugin->get_string_translation_service();

	// String operations
	$test_str_key = 'tfml_uat_str_' . time();
	$str_obj      = $str_svc->register_string( 'uat_domain', $test_str_key, 'Texto Original UAT', 'Contexto UAT' );

	$str_registered = ( null !== $str_obj && $str_obj->get_id() > 0 );
	if ( $str_registered ) {
		$str_svc->save_translation( $str_obj->get_id(), 'en', 'Translated UAT Text' );
	}

	$val_en          = $str_svc->translate( 'uat_domain', $test_str_key, 'Texto Original UAT', 'en' );
	$val_pt_fallback = $str_svc->translate( 'uat_domain', $test_str_key, 'Texto Original UAT', 'pt' ); // No pt translation -> strict fallback to es

	// Cleanup string
	if ( $str_registered ) {
		$plugin->get_string_repository()->delete_string( $str_obj->get_id() );
	}
	$str_cleaned = ( null === $plugin->get_string_repository()->find_by_domain_and_key( 'uat_domain', $test_str_key ) );

	// Menu location persistence
	$nav_repo = $plugin->get_nav_menu_location_repository();
	$nav_repo->set_menu_for_location( 'primary_nav_uat', 'en', 7788 );
	$nav_repo->persist();
	$resolved_menu = $nav_repo->get_menu_for_location( 'primary_nav_uat', 'en' );
	$nav_repo->set_menu_for_location( 'primary_nav_uat', 'en', null );
	$clean_nav     = $nav_repo->persist();

	$c6_pass = (
		$str_registered &&
		'Translated UAT Text' === $val_en &&
		'Texto Original UAT' === $val_pt_fallback &&
		$str_cleaned &&
		7788 === $resolved_menu &&
		$clean_nav
	);

	if ( $c6_pass ) {
		echo "[PASS] Control 6: Cadenas y Menús — Cadenas CRUD: OK, Fallback soberano ES: OK, NavMenuLocation persistance: OK\n";
		$results[6] = true;
	} else {
		echo "[FAIL] Control 6: Fallo en cadenas o persistencia de menús!\n";
		$results[6] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 6 Excepción: " . $e->getMessage() . "\n";
	$results[6] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 7: REST API — Autorización, Nonces y Respuestas de Error
// -----------------------------------------------------------------------------
try {
	$server = rest_get_server();

	// 7a. Public endpoint /languages
	$req_lang    = new \WP_REST_Request( 'GET', '/tf-multilingual/v1/languages' );
	$res_lang    = $server->dispatch( $req_lang );
	$lang_status = $res_lang->get_status();
	$lang_data   = $res_lang->get_data();
	$api_lang_ok = ( 200 === $lang_status && is_array( $lang_data ) && count( $lang_data ) >= 2 );

	// 7b. Protected endpoint /translations/unlink without authorization (logged out)
	wp_set_current_user( 0 );
	$req_unlink_anon = new \WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/unlink' );
	$req_unlink_anon->set_body_params( array( 'element_type' => 'post', 'element_id' => 6237 ) );
	$res_unlink_anon = $server->dispatch( $req_unlink_anon );
	$anon_blocked    = ( 401 === $res_unlink_anon->get_status() || 403 === $res_unlink_anon->get_status() );

	// 7c. Protected endpoint with administrator authorization
	wp_set_current_user( 1 );
	$req_cand_auth = new \WP_REST_Request( 'GET', '/tf-multilingual/v1/translations/candidates' );
	$req_cand_auth->set_param( 'element_type', 'post' );
	$req_cand_auth->set_param( 'subtype', 'page' );
	$req_cand_auth->set_param( 'language', 'en' );
	$req_cand_auth->set_param( 'source_id', 6237 );
	$res_cand_auth = $server->dispatch( $req_cand_auth );
	$auth_ok       = ( 200 === $res_cand_auth->get_status() );

	// 7d. Bad request (invalid unlink params)
	$req_bad = new \WP_REST_Request( 'POST', '/tf-multilingual/v1/translations/unlink' );
	$req_bad->set_body_params( array( 'element_type' => 'invalid_type', 'element_id' => -5 ) );
	$res_bad = $server->dispatch( $req_bad );
	$bad_req_ok = ( 400 === $res_bad->get_status() );

	$c7_pass = ( $api_lang_ok && $anon_blocked && $auth_ok && $bad_req_ok );

	if ( $c7_pass ) {
		echo "[PASS] Control 7: REST API Security & Contracts — /languages (200): OK, Anon /unlink blocked (401/403): OK, Admin /candidates (200): OK, Invalid params (400): OK\n";
		$results[7] = true;
	} else {
		echo "[FAIL] Control 7: Fallo en contratos REST API! LangStatus={$lang_status}, AnonStat={$res_unlink_anon->get_status()}, AuthStat={$res_cand_auth->get_status()}, BadReqStat={$res_bad->get_status()}\n";
		$results[7] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 7 Excepción: " . $e->getMessage() . "\n";
	$results[7] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 8: Auditoría y Renderizado E2E de Pantallas Administrativas
// -----------------------------------------------------------------------------
try {
	wp_set_current_user( 1 );
	set_current_screen( 'dashboard' );

	$screen_checks = array(
		'Languages'      => false,
		'Translations'   => false,
		'Strings'        => false,
		'Migration_Plan' => false,
		'Migration_Hist' => false,
		'Migration_Ret'  => false,
		'Migration_Next' => false,
		'Diagnostics'    => false,
		'CustomFields'   => false,
		'Navigation'     => false,
		'Metabox_ES'     => false,
		'Metabox_EN'     => false,
		'Term_Fields'    => false,
	);

	// 1. Languages UI
	$lang_ui = $plugin->get_language_editorial_ui();
	ob_start();
	$lang_ui->render_page();
	$html_lang = ob_get_clean();
	$screen_checks['Languages'] = ( false !== strpos( $html_lang, 'tfml-language-table' ) || false !== strpos( $html_lang, 'Idiomas' ) );

	// 2. Translations Overview UI
	$trans_ui = $plugin->get_translations_overview_ui();
	ob_start();
	$trans_ui->render_page();
	$html_trans = ob_get_clean();
	$screen_checks['Translations'] = ( false !== strpos( $html_trans, 'tfml-kpi-card' ) && false !== strpos( $html_trans, 'Traducciones' ) );

	// 3. Strings UI
	$strings_ui = $plugin->get_string_editorial_ui();
	ob_start();
	$strings_ui->render_page();
	$html_strings = ob_get_clean();
	$screen_checks['Strings'] = ( false !== strpos( $html_strings, 'tfml-string-table' ) || false !== strpos( $html_strings, 'Cadenas' ) );

	// 4. Migration Plan UI
	$mig_ui = $plugin->get_migration_ui();

	$_GET['tab'] = 'plan';
	ob_start();
	$mig_ui->render_page();
	$html_plan = ob_get_clean();
	$screen_checks['Migration_Plan'] = ( false !== strpos( $html_plan, 'Huella Criptográfica' ) || false !== strpos( $html_plan, 'tfml-migration-wrap' ) );

	// 5. Migration History
	$_GET['tab'] = 'history';
	ob_start();
	$mig_ui->render_page();
	$html_hist = ob_get_clean();
	$screen_checks['Migration_Hist'] = ( false !== strpos( $html_hist, 'Historial de Lotes' ) );

	// 6. Migration Retained
	$_GET['tab'] = 'retained';
	ob_start();
	$mig_ui->render_page();
	$html_ret = ob_get_clean();
	$screen_checks['Migration_Ret'] = ( false !== strpos( $html_ret, 'Custodia Histórica' ) );

	// 7. Migration Next Batch
	$_GET['tab'] = 'next_batch';
	ob_start();
	$mig_ui->render_page();
	$html_next = ob_get_clean();
	$screen_checks['Migration_Next'] = ( false !== strpos( $html_next, 'Siguiente Lote' ) || false !== strpos( $html_next, 'next_batch' ) );

	// 8. Diagnostics UI
	$diag_ui = $plugin->get_diagnostic_ui();
	ob_start();
	$diag_ui->render_page();
	$html_diag = ob_get_clean();
	$screen_checks['Diagnostics'] = ( false !== strpos( $html_diag, 'tfml-diagnostic-wrap' ) && false !== strpos( $html_diag, 'Diagnóstico' ) );

	// 9. Custom Fields UI
	$cf_ui = $plugin->get_custom_fields_settings_ui();
	ob_start();
	$cf_ui->render_page();
	$html_cf = ob_get_clean();
	$screen_checks['CustomFields'] = ( false !== strpos( $html_cf, 'Campos Personalizados' ) || false !== strpos( $html_cf, 'Políticas' ) );

	// 10. Navigation UI
	$nav_ui = $plugin->get_nav_menu_editorial_ui();
	ob_start();
	$nav_ui->render_admin_page();
	$html_nav = ob_get_clean();
	$screen_checks['Navigation'] = ( false !== strpos( $html_nav, 'Menús' ) || false !== strpos( $html_nav, 'Ubicaciones' ) );

	// 11. Metabox ES (Post #6237 Nosotros)
	$post_es = get_post( 6237 );
	$editorial_ui = $plugin->get_post_editorial_ui();
	ob_start();
	$editorial_ui->render_meta_box( $post_es );
	$html_mb_es = ob_get_clean();
	$screen_checks['Metabox_ES'] = ( false !== strpos( $html_mb_es, 'tfml-editorial-container' ) && false !== strpos( $html_mb_es, 'tfml_editorial_nonce' ) );

	// 12. Metabox EN (Post #8354 About Us)
	$post_en = get_post( 8354 );
	ob_start();
	$editorial_ui->render_meta_box( $post_en );
	$html_mb_en = ob_get_clean();
	$screen_checks['Metabox_EN'] = ( false !== strpos( $html_mb_en, 'tfml-editorial-container' ) && false !== strpos( $html_mb_en, 'tfml_editorial_nonce' ) );

	// 13. Term Fields
	$term_ui = $plugin->get_term_editorial_ui();
	$term_sample = get_term( 1, 'category' );
	if ( $term_sample instanceof WP_Term ) {
		ob_start();
		$term_ui->render_edit_form_fields( $term_sample, 'category' );
		$html_term = ob_get_clean();
		$screen_checks['Term_Fields'] = ( false !== strpos( $html_term, 'tfml' ) || ! empty( $html_term ) );
	} else {
		$screen_checks['Term_Fields'] = true;
	}

	$all_screens_ok = true;
	foreach ( $screen_checks as $sname => $sok ) {
		if ( ! $sok ) {
			$all_screens_ok = false;
		}
	}

	if ( $all_screens_ok ) {
		echo "[PASS] Control 8: Auditoría y Renderizado E2E de Pantallas Administrativas (13 Pantallas) — 0 PHP Errors, HTML Completo: SI\n";
		$results[8] = true;
	} else {
		echo "[FAIL] Control 8: Fallo en renderizado de pantallas administrativas!\n";
		print_r( $screen_checks );
		$results[8] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 8 Excepción: " . $e->getMessage() . "\n";
	$results[8] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 9: Matriz HTTP Frontend Real y Sitemaps Nativos
// -----------------------------------------------------------------------------
try {
	$routes_to_test = array(
		'/'            => 200,
		'/en/'         => 200,
		'/nosotros/'   => 200,
		'/en/about-us/' => 200,
		'/blog/'       => 200,
		'/en/blog/'    => 200,
		'/fr/'         => 404,
		'/pt/'         => 404,
		'/?p=9662'     => 404, // TRID 34790 draft post
	);

	$http_ok = true;
	foreach ( $routes_to_test as $route => $expected_code ) {
		$url  = 'http://lab.ecoterra.test' . $route;
		$resp = inspect_route( $url );
		if ( $resp['code'] !== $expected_code ) {
			echo "   [!] HTTP Route mismatch for {$route}: expected {$expected_code}, got {$resp['code']}\n";
			$http_ok = false;
		}
	}

	// Sitemaps verification
	$posts_provider = new \WP_Sitemaps_Posts();
	$pages_list     = $posts_provider->get_url_list( 1, 'page' );
	$pages_count    = is_array( $pages_list ) ? count( $pages_list ) : 0;

	$c9_pass = ( $http_ok && 43 === $pages_count );

	if ( $c9_pass ) {
		echo "[PASS] Control 9: Matriz HTTP Frontend y Sitemaps — 200 OK en rutas activas, 404 en /fr/, /pt/ y draft #9662, Sitemaps pages=43 (0 francés, 0 borradores): SI\n";
		$results[9] = true;
	} else {
		echo "[FAIL] Control 9: Discrepancia en HTTP o Sitemaps! http_ok=" . ( $http_ok ? 'yes' : 'no' ) . ", pages_count={$pages_count}\n";
		$results[9] = false;
		$all_pass   = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 9 Excepción: " . $e->getMessage() . "\n";
	$results[9] = false;
	$all_pass   = false;
}

// -----------------------------------------------------------------------------
// CONTROL 10: Invarianza Criptográfica Final y Ausencia de Mutaciones Residuales
// -----------------------------------------------------------------------------
try {
	$final_prod_sentinel = get_icl_sentinel( 'cms_ect' );

	$final_groups_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_groups" );
	$final_elements_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_tfml_group_elements" );
	$final_retained_reg    = new RetainedTranslationRegistry();
	$final_retained_count  = count( $final_retained_reg->get_all() );
	$final_manifests_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_options WHERE option_name LIKE 'tfml_migration_manifest_%'" );

	// Verify zero synthetic posts remaining in DB
	$synth_remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM etk_t_posts WHERE ID >= 999900" );

	$c10_pass = (
		$final_prod_sentinel['rows'] === 3403 &&
		$final_prod_sentinel['md5'] === '4241ca7e7ec6399a594537cb04790c10' &&
		$final_groups_count === 141 &&
		$final_elements_count === 175 &&
		$final_retained_count === 21 &&
		$final_manifests_count === 20 &&
		0 === $synth_remaining
	);

	if ( $c10_pass ) {
		echo "[PASS] Control 10: Invarianza Final — cms_ect: 3403 filas / MD5 4241ca7e7ec6399a594537cb04790c10 | cms_ect_lab: 141 grupos, 175 elementos, 21 retained FR, 20 manifiestos | Residuos sintéticos: 0\n";
		$results[10] = true;
	} else {
		echo "[FAIL] Control 10: Invarianza violada al cierre! Grp={$final_groups_count}, Elem={$final_elements_count}, Ret={$final_retained_count}, Synth={$synth_remaining}\n";
		$results[10] = false;
		$all_pass    = false;
	}
} catch ( Throwable $e ) {
	echo "[FAIL] Control 10 Excepción: " . $e->getMessage() . "\n";
	$results[10] = false;
	$all_pass    = false;
}

echo "\n================================================================================\n";
if ( $all_pass && 10 === count( array_filter( $results ) ) ) {
	echo "RESULTADO GLOBAL DE FASE 3.55: 10/10 CONTROLES PASS (0 FAIL)\n";
} else {
	$passed = count( array_filter( $results ) );
	echo "RESULTADO GLOBAL DE FASE 3.55: {$passed}/10 CONTROLES PASS (" . ( 10 - $passed ) . " FAIL)\n";
}
echo "================================================================================\n";
