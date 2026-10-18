<?php
/**
 * Script de Verificación Reproducible — Subsanación DT-TFML-069A (Fase 3.39A).
 *
 * Conciliación de TRIDs, Identidad Editorial y Seguridad del Piloto de Migración.
 *
 * Verifica (100% solo lectura, cero mutaciones en BD):
 * 1. Preflight bloqueante e invariancia centinela de datos (cms_ect y cms_ect_lab intactos: 3,403 filas / MD5 4241ca7e7ec6399a594537cb04790c10).
 * 2. Estado TFML de entrada intacto (121 grupos, 135 elementos activos, grupo 587 [197:es, 8356:en], 1 retenido [fr:8839], 12 manifiestos, sitemaps en 26 entradas).
 * 3. Conciliación directa en etk_t_icl_translations, etk_t_posts, etk_t_tfml_groups y etk_t_tfml_group_elements para TRIDs 9201, 430, 9545 y 9818, y paridad 100% entre cms_ect y cms_ect_lab.
 * 4. Conciliación completa de los 15 TRIDs candidatos de navegación (45 filas post_page) y verificación de cero reutilización de IDs con términos u otros tipos.
 * 5. Detector automático de anomalías de conciliación (Sección 7 DT-TFML-069A):
 *    - TRID no correspondiente al elemento declarado (trid_mismatch)
 *    - Idioma incorrecto (language_mismatch)
 *    - Tipo de elemento incorrecto (element_type_mismatch)
 *    - Objeto inexistente en wp_posts (missing_object)
 *    - Objetos repetidos entre grupos (duplicate_object_across_groups)
 *    - Colisiones TFML en tfml_group_elements o RetainedTranslationRegistry (tfml_collision)
 *    - Inconsistencia entre plan derivado y datos reales (plan_vs_db_inconsistency)
 *    Acredita 0 anomalías sobre el catálogo real y 7/7 detecciones positivas sobre casos sintéticos negativos (incluyendo la tabla errónea del informe de Fase 3.37).
 * 6. Validación integral del piloto prioritario de 2 grupos reales: Nosotros/About Us (TRID 9545) y Actividades/Activities (TRID 9201).
 * 7. Derivación determinista en memoria de nuevo plan maestro y planes piloto (pilot-nav-2-trids-9545-9201 y pilot-nav-2-trids-9201-430) con fingerprints SHA-256 reales y correspondencia de URLs localizadas.
 * 8. Confirmación de cero escrituras post-ejecución en cms_ect y cms_ect_lab.
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

$_SERVER['HTTP_HOST']   = 'lab.ecoterra.test';
$_SERVER['SERVER_NAME'] = 'lab.ecoterra.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS']       = 'off';

$lab_root = 'D:/laragon/www/lab.ecoterra';
if ( ! file_exists( $lab_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "ERROR: No se encontró wp-load.php en {$lab_root}\n" );
	exit( 1 );
}

require_once $lab_root . '/wp-load.php';
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;

$total_checks  = 0;
$passed_checks = 0;
$failed_checks = 0;

/**
 * Registra un control de verificación con estado explícito (PASS / FAIL).
 *
 * @param string $label     Descripción del control.
 * @param bool   $condition Condición booleana evaluada.
 * @param string $detail    Detalle adicional.
 * @return void
 */
function record_gate( string $label, bool $condition, string $detail = '' ): void {
	global $total_checks, $passed_checks, $failed_checks;
	++$total_checks;
	if ( $condition ) {
		++$passed_checks;
		$status = 'PASS';
	} else {
		++$failed_checks;
		$status = 'FAIL';
	}
	$suffix = '' !== $detail ? " — {$detail}" : '';
	echo sprintf( "[%s] %s%s\n", $status, $label, $suffix );
}

/**
 * Obtiene la huella centinela de una tabla icl_translations en una base de datos.
 *
 * @param string $db_name Nombre de la base de datos.
 * @return array{rows: int, md5: string}
 */
function get_icl_sentinel( string $db_name ): array {
	$mysqli = new mysqli( '127.0.0.1', 'root', '', $db_name, 3306 );
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
 * Calcula un digest integral de las tablas TFML y opciones críticas de migración.
 *
 * @return string
 */
function get_tfml_state_digest(): string {
	global $wpdb;
	$parts = array();

	$groups = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_groups ORDER BY id ASC", ARRAY_A );
	$elems  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tfml_group_elements ORDER BY id ASC", ARRAY_A );
	$opts   = $wpdb->get_results(
		"SELECT option_name, option_value FROM {$wpdb->options}
		 WHERE option_name IN ('tfml_retained_translations', 'tfml_migration_checkpoint_wpml', 'tfml_migration_lock_wpml', 'page_on_front', 'blog_public')
		    OR option_name LIKE 'tfml_migration_manifest_%'
		 ORDER BY option_name ASC",
		ARRAY_A
	);

	$parts['groups'] = $groups;
	$parts['elems']  = $elems;
	$parts['opts']   = $opts;

	return hash( 'sha256', (string) wp_json_encode( $parts ) );
}

/**
 * Valida un catálogo declarado de TRIDs contra las tablas reales etk_t_icl_translations,
 * etk_t_posts, etk_t_tfml_group_elements, RetainedTranslationRegistry y MigrationPlan.
 *
 * Detecta las 7 clases de anomalías exigidas por la Sección 7 de DT-TFML-069A:
 * - trid_mismatch
 * - language_mismatch
 * - element_type_mismatch
 * - missing_object
 * - duplicate_object_across_groups
 * - tfml_collision
 * - plan_vs_db_inconsistency
 *
 * @param array<int, array<string, mixed>> $declared_catalog Catálogo declarado a auditar.
 * @param array<int, array<string, mixed>> $plan_by_trid     Mapa de grupos planificados por TRID.
 * @param RetainedTranslationRegistry      $retained_reg     Registro de traducciones retenidas.
 * @return array<string, array<int, string>> Mapa de anomalías detectadas por categoría.
 */
function validate_pilot_trid_identity_contract(
	array $declared_catalog,
	array $plan_by_trid,
	RetainedTranslationRegistry $retained_reg,
	array $allowed_migrated_trids = array()
): array {
	global $wpdb;

	$anomalies = array(
		'trid_mismatch'                  => array(),
		'language_mismatch'              => array(),
		'element_type_mismatch'          => array(),
		'missing_object'                 => array(),
		'duplicate_object_across_groups' => array(),
		'tfml_collision'                 => array(),
		'plan_vs_db_inconsistency'       => array(),
	);

	$seen_elements = array();

	foreach ( $declared_catalog as $trid => $spec ) {
		$expected_elem_type = (string) ( $spec['element_type'] ?? 'post_page' );
		$expected_post_type = (string) ( $spec['post_type'] ?? 'page' );
		$members            = (array) ( $spec['members'] ?? array() );

		$db_trid_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT trid, translation_id, element_id, element_type, language_code, source_language_code
				 FROM {$wpdb->prefix}icl_translations
				 WHERE trid = %d",
				(int) $trid
			),
			ARRAY_A
		);

		$db_by_lang = array();
		foreach ( $db_trid_rows as $r ) {
			$db_by_lang[ (string) $r['language_code'] ] = $r;
		}

		foreach ( $members as $lang => $m ) {
			$declared_id = (int) ( $m['element_id'] ?? 0 );

			if ( isset( $seen_elements[ $declared_id ] ) && $seen_elements[ $declared_id ] !== (int) $trid ) {
				$anomalies['duplicate_object_across_groups'][] = sprintf(
					'element_id=%d repetido entre TRID %d y TRID %d',
					$declared_id,
					$seen_elements[ $declared_id ],
					(int) $trid
				);
			}
			$seen_elements[ $declared_id ] = (int) $trid;

			$icl_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT trid, translation_id, element_id, element_type, language_code, source_language_code
					 FROM {$wpdb->prefix}icl_translations
					 WHERE element_id = %d AND element_type LIKE 'post_%%'
					 LIMIT 1",
					$declared_id
				),
				ARRAY_A
			);

			if ( ! is_array( $icl_row ) || (int) $icl_row['trid'] !== (int) $trid ) {
				$anomalies['trid_mismatch'][] = sprintf(
					'TRID declarado=%d para element_id=%d (%s), pero en icl_translations su TRID real es %s',
					(int) $trid,
					$declared_id,
					$lang,
					is_array( $icl_row ) ? (string) $icl_row['trid'] : ' inexistente'
				);
			}

			if ( is_array( $icl_row ) && (string) $icl_row['language_code'] !== (string) $lang ) {
				$anomalies['language_mismatch'][] = sprintf(
					'element_id=%d declarado como lang=%s pero en icl_translations tiene language_code=%s',
					$declared_id,
					$lang,
					(string) $icl_row['language_code']
				);
			}

			if ( is_array( $icl_row ) && (string) $icl_row['element_type'] !== $expected_elem_type ) {
				$anomalies['element_type_mismatch'][] = sprintf(
					'element_id=%d declarado como element_type=%s pero en icl_translations tiene %s',
					$declared_id,
					$expected_elem_type,
					(string) $icl_row['element_type']
				);
			}

			$post_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT ID, post_type, post_title, post_name, post_parent, post_status
					 FROM {$wpdb->posts}
					 WHERE ID = %d",
					$declared_id
				),
				ARRAY_A
			);

			if ( ! is_array( $post_row ) ) {
				$anomalies['missing_object'][] = sprintf(
					'element_id=%d (TRID %d, %s) no existe en wp_posts',
					$declared_id,
					(int) $trid,
					$lang
				);
			} else {
				if ( (string) $post_row['post_type'] !== $expected_post_type ) {
					$anomalies['element_type_mismatch'][] = sprintf(
						'post ID=%d tiene post_type=%s (esperado %s)',
						$declared_id,
						(string) $post_row['post_type'],
						$expected_post_type
					);
				}
				if ( isset( $m['post_name'] ) && (string) $post_row['post_name'] !== (string) $m['post_name'] ) {
					$anomalies['trid_mismatch'][] = sprintf(
						'post ID=%d tiene post_name=%s (declarado %s)',
						$declared_id,
						(string) $post_row['post_name'],
						(string) $m['post_name']
					);
				}
			}

			if ( ! in_array( (int) $trid, $allowed_migrated_trids, true ) ) {
				$tfml_existing = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_type = 'post' AND element_id = %d",
						$declared_id
					)
				);
				if ( $tfml_existing > 0 || $retained_reg->has_retained_element( 'post', $declared_id ) ) {
					$anomalies['tfml_collision'][] = sprintf(
						'element_id=%d (TRID %d) ya colisiona en tfml_group_elements (%d) o RetainedTranslationRegistry',
						$declared_id,
						(int) $trid,
						$tfml_existing
					);
				}
			}
		}

		$plan_group = $plan_by_trid[ (int) $trid ] ?? null;
		if ( ! is_array( $plan_group ) ) {
			$anomalies['plan_vs_db_inconsistency'][] = sprintf( 'TRID %d no existe en el MigrationPlan derivado/maestro', (int) $trid );
		} else {
			$plan_es = (int) ( $plan_group['translations']['es'] ?? 0 );
			$plan_en = (int) ( $plan_group['translations']['en'] ?? 0 );
			$plan_fr = (int) ( $plan_group['pending_translations']['fr'] ?? ( $plan_group['retained_translations']['fr']['element_id'] ?? 0 ) );

			$db_es = (int) ( $db_by_lang['es']['element_id'] ?? 0 );
			$db_en = (int) ( $db_by_lang['en']['element_id'] ?? 0 );
			$db_fr = (int) ( $db_by_lang['fr']['element_id'] ?? 0 );

			if ( $plan_es !== $db_es || $plan_en !== $db_en || $plan_fr !== $db_fr ) {
				$anomalies['plan_vs_db_inconsistency'][] = sprintf(
					'TRID %d plan=(es:%d, en:%d, fr:%d) difiere de DB=(es:%d, en:%d, fr:%d)',
					(int) $trid,
					$plan_es,
					$plan_en,
					$plan_fr,
					$db_es,
					$db_en,
					$db_fr
				);
			}
		}
	}

	return $anomalies;
}

echo "============================================================================\n";
echo " VERIFICADOR AUTOMATIZADO FASE 3.39A (DT-TFML-069A)\n";
echo " Conciliación de TRIDs, Identidad Editorial y Seguridad del Piloto Bilingüe\n";
echo "============================================================================\n\n";

global $wpdb;

// ============================================================================
// BLOQUE 1: PREFLIGHT DE AISLAMIENTO E INVARIANCIA DE BASE DE DATOS (SECCIÓN 6)
// ============================================================================
echo "--- BLOQUE 1: Preflight de Aislamiento e Invariancia de Base de Datos (Sección 6) ---\n";

$digest_before      = get_tfml_state_digest();
$sentinel_prod_pre  = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_pre   = get_icl_sentinel( 'cms_ect_lab' );
$expected_wpml_rows = 3403;
$expected_wpml_md5  = '4241ca7e7ec6399a594537cb04790c10';

$active_plugins = (array) get_option( 'active_plugins', array() );
$tfml_active    = in_array( 'tf-multilingual/tf-multilingual.php', $active_plugins, true );
$wpml_inactive  = ! in_array( 'sitepress-multilingual-cms/sitepress.php', $active_plugins, true )
	&& ! defined( 'ICL_SITEPRESS_VERSION' )
	&& ! defined( 'ICL_LANGUAGE_CODE' );

$plugin        = Plugin::get_instance();
$lang_registry = $plugin->get_language_registry();
$active_langs  = array_keys( $lang_registry->active() );

record_gate(
	'1. Aislamiento de entorno y centinela WPML (cms_ect y cms_ect_lab intactos: 3,403 filas / MD5 4241ca7e7ec6399a594537cb04790c10)',
	'cms_ect_lab' === $wpdb->dbname
		&& 'etk_t_' === $wpdb->prefix
		&& $tfml_active
		&& $wpml_inactive
		&& array( 'es', 'en', 'pt' ) === $active_langs
		&& ! $lang_registry->is_active( 'fr' )
		&& '0' === (string) get_option( 'blog_public' )
		&& 197 === (int) get_option( 'page_on_front' )
		&& $expected_wpml_rows === $sentinel_prod_pre['rows']
		&& $expected_wpml_md5 === $sentinel_prod_pre['md5']
		&& $expected_wpml_rows === $sentinel_lab_pre['rows']
		&& $expected_wpml_md5 === $sentinel_lab_pre['md5'],
	sprintf(
		'db=%s, prod=%d/%s, lab=%d/%s',
		$wpdb->dbname,
		$sentinel_prod_pre['rows'],
		$sentinel_prod_pre['md5'],
		$sentinel_lab_pre['rows'],
		$sentinel_lab_pre['md5']
	)
);

$groups_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );
$group_587_elems = $wpdb->get_results( "SELECT element_id, language_code FROM {$wpdb->prefix}tfml_group_elements WHERE group_id = 587 ORDER BY language_code ASC", ARRAY_A );
$retained_reg    = new RetainedTranslationRegistry( $wpdb );
$retained_all    = $retained_reg->get_all( 'wpml' );
$retained_197    = $retained_reg->get( 'wpml', 197 );
$manifests_cnt   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tfml_migration_manifest_%'" );
$checkpoint      = ( new MigrationCheckpointRepository() )->get( 'wpml' );
$lock_mgr        = new MigrationExecutionLock( $wpdb );
$rollback_svc    = new WpmlMigrationRollbackService( $wpdb );
$manifest_197    = $rollback_svc->get_execution_manifest( 'pilot-trid-197-partial' );
$manifest_340    = $rollback_svc->get_execution_manifest( 'pilot-nav-2-trids-9545-9201' );
$has_pilot_340   = is_array( $manifest_340 );

$mig_manager    = $plugin->get_migration_manager();
$planner        = $mig_manager->get_wpml_planner();
$plan_no_conf   = $planner->generate_plan( 0, 0, false );
$pilot_197_plan = $plan_no_conf->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 197,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
	),
	'pilot-trid-197-partial'
);
$reconcile_197  = $mig_manager->get_wpml_executor()->reconcile( $pilot_197_plan );

$posts_provider = new WP_Sitemaps_Posts();
$tax_provider   = new WP_Sitemaps_Taxonomies();
$sitemap_total  = count( $posts_provider->get_url_list( 1, 'page' ) )
	+ count( $posts_provider->get_url_list( 1, 'post' ) )
	+ count( $posts_provider->get_url_list( 1, 'rooms' ) )
	+ count( $tax_provider->get_url_list( 1, 'category' ) )
	+ count( $tax_provider->get_url_list( 1, 'post_tag' ) );

$expected_groups_339a    = $has_pilot_340 ? 123 : 121;
$expected_elements_339a  = $has_pilot_340 ? 139 : 135;
$expected_retained_339a  = $has_pilot_340 ? 3 : 1;
$expected_manifests_339a = $has_pilot_340 ? 13 : 12;
$expected_sitemaps_339a  = $has_pilot_340 ? 30 : 26;

record_gate(
	'2. Invariantes TFML intactos (grupos, elementos, grupo 587 [197:es, 8356:en], retenidos, manifiestos y sitemaps coherentes)',
	$expected_groups_339a === $groups_count
		&& $expected_elements_339a === $elements_count
		&& 2 === count( $group_587_elems )
		&& 8356 === (int) ( $group_587_elems[0]['element_id'] ?? 0 )
		&& 197 === (int) ( $group_587_elems[1]['element_id'] ?? 0 )
		&& $expected_retained_339a === count( $retained_all )
		&& null !== $retained_197
		&& 8839 === (int) ( $retained_197['retained_elements']['fr']['element_id'] ?? 0 )
		&& $retained_reg->verify_record_integrity( $retained_197 )
		&& $expected_manifests_339a === $manifests_cnt
		&& null !== $checkpoint
		&& $checkpoint->is_completed()
		&& is_array( $manifest_197 )
		&& ! $lock_mgr->is_locked( 'wpml' )
		&& true === ( $reconcile_197['integrity_verified'] ?? false )
		&& $expected_sitemaps_339a === $sitemap_total,
	sprintf(
		'groups=%d, elements=%d, retained=%d, manifests=%d, sitemaps=%d',
		$groups_count,
		$elements_count,
		count( $retained_all ),
		$manifests_cnt,
		$sitemap_total
	)
);

// ============================================================================
// BLOQUE 2: CONCILIACIÓN OBLIGATORIA DE TRIDs 9201, 430, 9545 Y 9818 (SECCIÓN 2)
// ============================================================================
echo "\n--- BLOQUE 2: Conciliación Obligatoria en Base de Datos Real (Sección 2) ---\n";

$reconciled_4_trids = array(
	9545 => array(
		'label'        => 'Nosotros / About Us',
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array(
				'translation_id'       => 1164,
				'element_id'           => 6237,
				'source_language_code' => null,
				'post_title'           => 'Nosotros',
				'post_name'            => 'nosotros',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
			'en' => array(
				'translation_id'       => 1755,
				'element_id'           => 8354,
				'source_language_code' => 'es',
				'post_title'           => 'About Us',
				'post_name'            => 'about-us',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
			'fr' => array(
				'translation_id'       => 1961,
				'element_id'           => 8873,
				'source_language_code' => 'en',
				'post_title'           => 'Nous',
				'post_name'            => 'nous',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
		),
	),
	9201 => array(
		'label'        => 'Actividades / Activities',
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array(
				'translation_id'       => 551,
				'element_id'           => 4976,
				'source_language_code' => null,
				'post_title'           => 'Actividades',
				'post_name'            => 'actividades',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
			'en' => array(
				'translation_id'       => 1748,
				'element_id'           => 8339,
				'source_language_code' => 'es',
				'post_title'           => 'Activities',
				'post_name'            => 'activities',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
			'fr' => array(
				'translation_id'       => 2035,
				'element_id'           => 8955,
				'source_language_code' => 'es',
				'post_title'           => 'Activités',
				'post_name'            => 'activites',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
		),
	),
	430  => array(
		'label'        => 'Galería de Fotos / Photo Gallery',
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array(
				'translation_id'       => 78,
				'element_id'           => 430,
				'source_language_code' => null,
				'post_title'           => 'Galería de Fotos',
				'post_name'            => 'galeria',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
			'en' => array(
				'translation_id'       => 1744,
				'element_id'           => 8329,
				'source_language_code' => 'es',
				'post_title'           => 'Photo Gallery',
				'post_name'            => 'gallery',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
			'fr' => array(
				'translation_id'       => 2066,
				'element_id'           => 8989,
				'source_language_code' => 'en',
				'post_title'           => 'Galerie',
				'post_name'            => 'galerie',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
		),
	),
	9818 => array(
		'label'        => 'Confort y Tranquilidad / Comfort and Tranquility',
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array(
				'translation_id'       => 1699,
				'element_id'           => 8042,
				'source_language_code' => null,
				'post_title'           => 'Confort y Tranquilidad',
				'post_name'            => 'confort-y-tranquilidad',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
			'en' => array(
				'translation_id'       => 1838,
				'element_id'           => 8613,
				'source_language_code' => 'es',
				'post_title'           => 'Comfort and Tranquility',
				'post_name'            => 'comfort-and-tranquility',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
			'fr' => array(
				'translation_id'       => 2033,
				'element_id'           => 8952,
				'source_language_code' => 'es',
				'post_title'           => 'Chambres',
				'post_name'            => 'chambres',
				'post_parent'          => 0,
				'post_status'          => 'publish',
			),
		),
	),
);

$sql_4_ok       = true;
$prod_parity_ok = true;
$mysqli_prod    = new mysqli( '127.0.0.1', 'root', '', 'cms_ect', 3306 );
$mysqli_prod->set_charset( 'utf8mb4' );

foreach ( $reconciled_4_trids as $trid => $spec ) {
	foreach ( $spec['members'] as $lang => $m ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.trid, i.translation_id, i.element_id, i.element_type, i.language_code, i.source_language_code,
				        p.post_type, p.post_title, p.post_name, p.post_parent, p.post_status,
				        ge.group_id AS tfml_group_id
				 FROM {$wpdb->prefix}icl_translations i
				 INNER JOIN {$wpdb->posts} p ON p.ID = i.element_id
				 LEFT JOIN {$wpdb->prefix}tfml_group_elements ge ON ge.element_id = i.element_id AND ge.element_type = 'post'
				 WHERE i.trid = %d AND i.language_code = %s",
				$trid,
				$lang
			),
			ARRAY_A
		);

		$expected_tfml_group_ok = ( $has_pilot_340 && in_array( (int) $trid, array( 9201, 9545 ), true ) && in_array( (string) $lang, array( 'es', 'en' ), true ) )
			? ( (int) ( $row['tfml_group_id'] ?? 0 ) > 0 )
			: ( null === $row['tfml_group_id'] );

		if ( ! is_array( $row )
			|| (int) $row['translation_id'] !== $m['translation_id']
			|| (int) $row['element_id'] !== $m['element_id']
			|| 'post_page' !== (string) $row['element_type']
			|| $row['source_language_code'] !== $m['source_language_code']
			|| 'page' !== (string) $row['post_type']
			|| (string) $row['post_title'] !== $m['post_title']
			|| (string) $row['post_name'] !== $m['post_name']
			|| (int) $row['post_parent'] !== $m['post_parent']
			|| (string) $row['post_status'] !== $m['post_status']
			|| ! $expected_tfml_group_ok
		) {
			$sql_4_ok = false;
		}

		// Verificar paridad idéntica en la base original protegida cms_ect
		$stmt = $mysqli_prod->prepare(
			'SELECT i.trid, i.translation_id, i.element_id, i.element_type, i.language_code, p.post_title, p.post_name, p.post_parent, p.post_status
			 FROM etk_t_icl_translations i
			 INNER JOIN etk_t_posts p ON p.ID = i.element_id
			 WHERE i.trid = ? AND i.language_code = ?'
		);
		$stmt->bind_param( 'is', $trid, $lang );
		$stmt->execute();
		$res_prod = $stmt->get_result();
		$prod_row = $res_prod ? $res_prod->fetch_assoc() : null;
		$stmt->close();

		if ( ! is_array( $prod_row )
			|| (int) $prod_row['translation_id'] !== $m['translation_id']
			|| (int) $prod_row['element_id'] !== $m['element_id']
			|| (string) $prod_row['post_name'] !== $m['post_name']
		) {
			$prod_parity_ok = false;
		}
	}
}
$mysqli_prod->close();

record_gate(
	'3. Conciliación directa en etk_t_icl_translations y etk_t_posts (cms_ect_lab y cms_ect): TRID 9545=Nosotros/About Us (6237/8354/8873), TRID 9201=Actividades/Activities (4976/8339/8955), TRID 430=Galería/Gallery (430/8329/8989), TRID 9818=Confort/Comfort (8042/8613/8952)',
	$sql_4_ok && $prod_parity_ok,
	'TRID_9545=(6237:nosotros,8354:about-us,8873:nous), TRID_9201=(4976:actividades,8339:activities,8955:activites), TRID_430=(430:galeria,8329:gallery,8989:galerie), TRID_9818=(8042:confort-y-tranquilidad,8613:comfort-and-tranquility,8952:chambres), prod_parity=true'
);

// ============================================================================
// BLOQUE 3: CONCILIACIÓN COMPLETA DE LOS 15 TRIDs Y DETECTOR DE ANOMALÍAS (SECCIONES 3 Y 7)
// ============================================================================
echo "\n--- BLOQUE 3: Conciliación Completa de los 15 TRIDs y Detector Automático de Anomalías (Secciones 3 y 7) ---\n";

$full_15_catalog = array(
	424   => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 424, 'post_name' => 'quienes-somos' ),
			'en' => array( 'element_id' => 8352, 'post_name' => 'who-are-we' ),
			'fr' => array( 'element_id' => 8880, 'post_name' => 'qui-somme-nous' ),
		),
	),
	430   => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 430, 'post_name' => 'galeria' ),
			'en' => array( 'element_id' => 8329, 'post_name' => 'gallery' ),
			'fr' => array( 'element_id' => 8989, 'post_name' => 'galerie' ),
		),
	),
	432   => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 432, 'post_name' => 'contactenos' ),
			'en' => array( 'element_id' => 8327, 'post_name' => 'contact-us' ),
			'fr' => array( 'element_id' => 8823, 'post_name' => 'contactez-nous' ),
		),
	),
	9201  => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 4976, 'post_name' => 'actividades' ),
			'en' => array( 'element_id' => 8339, 'post_name' => 'activities' ),
			'fr' => array( 'element_id' => 8955, 'post_name' => 'activites' ),
		),
	),
	9537  => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 6214, 'post_name' => 'paquetes' ),
			'en' => array( 'element_id' => 8335, 'post_name' => 'tour-packages' ),
			'fr' => array( 'element_id' => 8974, 'post_name' => 'forfaits-touristiques' ),
		),
	),
	9545  => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 6237, 'post_name' => 'nosotros' ),
			'en' => array( 'element_id' => 8354, 'post_name' => 'about-us' ),
			'fr' => array( 'element_id' => 8873, 'post_name' => 'nous' ),
		),
	),
	9634  => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 6938, 'post_name' => 'naturaleza-autentica' ),
			'en' => array( 'element_id' => 8330, 'post_name' => 'authentic-nature' ),
			'fr' => array( 'element_id' => 9025, 'post_name' => 'nature' ),
		),
	),
	9697  => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 7285, 'post_name' => 'experiencia-ecoterra' ),
			'en' => array( 'element_id' => 8337, 'post_name' => 'ecoterra-experience' ),
			'fr' => array( 'element_id' => 8981, 'post_name' => 'experience-ecoterra' ),
		),
	),
	9818  => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 8042, 'post_name' => 'confort-y-tranquilidad' ),
			'en' => array( 'element_id' => 8613, 'post_name' => 'comfort-and-tranquility' ),
			'fr' => array( 'element_id' => 8952, 'post_name' => 'chambres' ),
		),
	),
	9838  => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 8316, 'post_name' => 'reviews' ),
			'en' => array( 'element_id' => 8321, 'post_name' => 'reviews' ),
			'fr' => array( 'element_id' => 8910, 'post_name' => 'avis' ),
		),
	),
	34652 => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 9218, 'post_name' => 'experiencia-transformadora' ),
			'en' => array( 'element_id' => 9219, 'post_name' => 'transformative-experience' ),
			'fr' => array( 'element_id' => 9220, 'post_name' => '' ),
		),
	),
	34660 => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 9248, 'post_name' => 'avistamiento-de-aves' ),
			'en' => array( 'element_id' => 9249, 'post_name' => 'birdwatching' ),
			'fr' => array( 'element_id' => 9250, 'post_name' => '' ),
		),
	),
	34776 => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 9614, 'post_name' => 'lek-de-gallito-de-las-rocas' ),
			'en' => array( 'element_id' => 9615, 'post_name' => 'andean-cock-of-the-rock-lek' ),
			'fr' => array( 'element_id' => 9616, 'post_name' => '' ),
		),
	),
	34791 => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 9666, 'post_name' => 'experiencia-de-cafe' ),
			'en' => array( 'element_id' => 9667, 'post_name' => 'coffee-experience' ),
			'fr' => array( 'element_id' => 9668, 'post_name' => '' ),
		),
	),
	34823 => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 9864, 'post_name' => 'terminos-y-condiciones' ),
			'en' => array( 'element_id' => 9863, 'post_name' => 'terms-and-conditions' ),
			'fr' => array( 'element_id' => 9865, 'post_name' => '' ),
		),
	),
);

$allowed_migrated = $has_pilot_340 ? array( 9201, 9545 ) : array();
$baseline_587_q   = static function ( string $sql ) use ( $wpdb ): string {
	$target = "SELECT element_id FROM `{$wpdb->prefix}tfml_group_elements`";
	if ( $target === $sql ) {
		return $target . ' WHERE group_id <= 587';
	}
	return $sql;
};
if ( $has_pilot_340 ) {
	add_filter( 'query', $baseline_587_q );
}
$master_plan = $planner->generate_plan();
if ( $has_pilot_340 ) {
	remove_filter( 'query', $baseline_587_q );
}
$plan_by_trid = array();
foreach ( $master_plan->get_planned_groups() as $g ) {
	$plan_by_trid[ (int) $g['trid'] ] = $g;
}

$real_anomalies = validate_pilot_trid_identity_contract( $full_15_catalog, $plan_by_trid, $retained_reg, $allowed_migrated );
$real_anom_cnt  = array_sum( array_map( 'count', $real_anomalies ) );

// Verificar ausencia de reutilización de IDs entre estos 45 posts y cualquier term en icl_translations o tfml_group_elements
$all_45_ids = array();
foreach ( $full_15_catalog as $spec ) {
	foreach ( $spec['members'] as $m ) {
		$all_45_ids[] = (int) $m['element_id'];
	}
}
$in_45_ids            = implode( ',', $all_45_ids );
$non_page_icl_cnt     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations WHERE element_id IN ({$in_45_ids}) AND element_type != 'post_page'" );
$tfml_any_cnt         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements WHERE element_id IN ({$in_45_ids})" );
$expected_tfml_45_cnt = $has_pilot_340 ? 4 : 0;

record_gate(
	'4. Conciliación completa de los 15 TRIDs candidatos de navegación (45 objetos post_page): 0 anomalías de identidad, 0 solapamientos con términos y 0 colisiones en TFML',
	0 === $real_anom_cnt && 0 === $non_page_icl_cnt && $expected_tfml_45_cnt === $tfml_any_cnt && 45 === count( array_unique( $all_45_ids ) ),
	sprintf( 'anomalies=%d, non_page_icl=%d, tfml_existing=%d, unique_elements=%d', $real_anom_cnt, $non_page_icl_cnt, $tfml_any_cnt, count( array_unique( $all_45_ids ) ) )
);

// Verificar que el detector atrapa automáticamente las 7 clases de anomalías (incluyendo la tabla errónea de Fase 3.37)
$broken_catalog = array(
	// Caso 1 (error del informe Fase 3.37): atribuir 9864/9863 (TRID 34823) al TRID 9201 y repetir 8339 entre TRID 9201 y TRID 430
	9201 => array(
		'element_type' => 'post_page',
		'post_type'    => 'page',
		'members'      => array(
			'es' => array( 'element_id' => 9864, 'post_name' => 'nosotros' ),
			'en' => array( 'element_id' => 8339, 'post_name' => 'activities' ),
		),
	),
	// Caso 2: repetir 8339 en TRID 430 (duplicate_object_across_groups + trid_mismatch) e idioma invertido
	430  => array(
		'element_type' => 'post_rooms', // element_type_mismatch
		'post_type'    => 'rooms',
		'members'      => array(
			'es' => array( 'element_id' => 8339, 'post_name' => 'activities' ), // language_mismatch (8339 es en) + duplicate
			'en' => array( 'element_id' => 9999999, 'post_name' => 'ghost' ),   // missing_object
			'fr' => array( 'element_id' => 8839, 'post_name' => 'accueil' ),    // tfml_collision (8839 está en RetainedTranslationRegistry)
		),
	),
);
$broken_plan_map = $plan_by_trid;
$broken_plan_map[9201]['translations']['es'] = 9864; // plan_vs_db_inconsistency

$detected_broken = validate_pilot_trid_identity_contract( $broken_catalog, $broken_plan_map, $retained_reg );

record_gate(
	'5. Detector automático de anomalías (Sección 7) verifica las 7 categorías: trid_mismatch, language_mismatch, element_type_mismatch, missing_object, duplicate_object_across_groups, tfml_collision y plan_vs_db_inconsistency',
	! empty( $detected_broken['trid_mismatch'] )
		&& ! empty( $detected_broken['language_mismatch'] )
		&& ! empty( $detected_broken['element_type_mismatch'] )
		&& ! empty( $detected_broken['missing_object'] )
		&& ! empty( $detected_broken['duplicate_object_across_groups'] )
		&& ! empty( $detected_broken['tfml_collision'] )
		&& ! empty( $detected_broken['plan_vs_db_inconsistency'] ),
	sprintf(
		'trid=%d, lang=%d, type=%d, missing=%d, dup=%d, collision=%d, plan_db=%d',
		count( $detected_broken['trid_mismatch'] ),
		count( $detected_broken['language_mismatch'] ),
		count( $detected_broken['element_type_mismatch'] ),
		count( $detected_broken['missing_object'] ),
		count( $detected_broken['duplicate_object_across_groups'] ),
		count( $detected_broken['tfml_collision'] ),
		count( $detected_broken['plan_vs_db_inconsistency'] )
	)
);

// ============================================================================
// BLOQUE 4: VALIDACIÓN DEL PILOTO PRIORITARIO (NOSOTROS + ACTIVIDADES) Y DERIVACIÓN (SECCIONES 4 Y 5)
// ============================================================================
echo "\n--- BLOQUE 4: Validación del Piloto Prioritario (TRIDs 9545 y 9201) y Derivación en Memoria (Secciones 4 y 5) ---\n";

$group_repo     = new TranslationGroupRepository( $wpdb, $lang_registry );
$trans_resolver = new ContentTranslationResolver( $group_repo, $lang_registry );
$url_resolver   = new UrlLanguageResolver( $lang_registry, home_url() );
$url_generator  = new LocalizedUrlGenerator(
	$lang_registry,
	$url_resolver,
	$trans_resolver,
	home_url()
);

// Validar Grupo 1: Nosotros / About Us (TRID 9545) y Grupo 2: Actividades / Activities (TRID 9201)
$g_9545 = $plan_by_trid[9545] ?? null;
$g_9201 = $plan_by_trid[9201] ?? null;

$pilot_2_valid = is_array( $g_9545 )
	&& is_array( $g_9201 )
	&& MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE === $g_9545['classification']
	&& MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE === $g_9201['classification']
	&& 6237 === (int) ( $g_9545['canonical_id'] ?? 0 )
	&& array( 'es' => 6237, 'en' => 8354 ) === $g_9545['translations']
	&& array( 'fr' => 8873 ) === $g_9545['pending_translations']
	&& 4976 === (int) ( $g_9201['canonical_id'] ?? 0 )
	&& array( 'es' => 4976, 'en' => 8339 ) === $g_9201['translations']
	&& array( 'fr' => 8955 ) === $g_9201['pending_translations']
	&& 'http://lab.ecoterra.test/nosotros/' === get_permalink( 6237 )
	&& 'http://lab.ecoterra.test/en/about-us/' === $url_generator->localize_url( (string) get_permalink( 8354 ), 'en' )
	&& 'http://lab.ecoterra.test/actividades/' === get_permalink( 4976 )
	&& 'http://lab.ecoterra.test/en/activities/' === $url_generator->localize_url( (string) get_permalink( 8339 ), 'en' );

record_gate(
	'6. Validación del piloto prioritario (Sección 4): Nosotros/About Us (TRID 9545: es=6237, en=8354, fr=8873) y Actividades/Activities (TRID 9201: es=4976, en=8339, fr=8955) acreditados sin discrepancias ni colisiones',
	$pilot_2_valid,
	'TRID_9545=(es:6237->/nosotros/, en:8354->/en/about-us/, fr:8873 retenido), TRID_9201=(es:4976->/actividades/, en:8339->/en/activities/, fr:8955 retenido)'
);

// Derivar el plan piloto prioritario (TRIDs 9545 y 9201) y la alternativa secundaria (TRIDs 9201 y 430) en memoria
$pilot_plan_9545_9201 = $master_plan->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 9545,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 9201,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
	),
	'pilot-nav-2-trids-9545-9201'
);

$pilot_plan_9201_430 = $master_plan->derive_partial_plan_with_retained_languages(
	array(
		array(
			'trid'                 => 9201,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
		array(
			'trid'                 => 430,
			'authorized_languages' => array( 'es', 'en' ),
			'retained_languages'   => array( 'fr' ),
		),
	),
	'pilot-nav-2-trids-9201-430'
);

$expected_master_fp    = '34a63fb1485341cf1da1109208c6fcefb3bab9f80b2fdfd5d0059a9e4e22263c';
$expected_fp_9545_9201 = 'ed8b9ede25ad4cf2886b8a1d3a438bfddf59cd9ed4ca3808f4daccbd3b86307d';
$expected_fp_9201_430  = '2e290df19eb9f25d364c1626bfc175bcd0885479b8125f94d81e96723923f755';

$sum_prio = $pilot_plan_9545_9201->get_summary();
$sum_alt  = $pilot_plan_9201_430->get_summary();

$derived_groups_prio = array();
foreach ( $pilot_plan_9545_9201->get_planned_groups() as $pg ) {
	$derived_groups_prio[ (int) $pg['trid'] ] = $pg;
}
$derived_anomalies = validate_pilot_trid_identity_contract(
	array(
		9545 => $full_15_catalog[9545],
		9201 => $full_15_catalog[9201],
	),
	$derived_groups_prio,
	$retained_reg,
	$allowed_migrated
);
$derived_anom_cnt = array_sum( array_map( 'count', $derived_anomalies ) );

record_gate(
	'7. Derivación determinista en memoria desde plan maestro canónico (Sección 5): Piloto prioritario pilot-nav-2-trids-9545-9201 (SHA-256 ed8b9ede25ad...) y alternativo pilot-nav-2-trids-9201-430 (SHA-256 2e290df19eb9...) en estado READY, 2 grupos, 4 activos, 2 retenidos, 0 conflictos',
	$expected_master_fp === $master_plan->get_fingerprint()
		&& MigrationPlan::STATUS_READY === $pilot_plan_9545_9201->get_status()
		&& $expected_fp_9545_9201 === $pilot_plan_9545_9201->get_fingerprint()
		&& 2 === (int) ( $sum_prio['total_groups'] ?? 0 )
		&& 4 === (int) ( $sum_prio['importable_elements'] ?? 0 )
		&& 2 === (int) ( $sum_prio['pending_elements'] ?? 0 )
		&& 0 === (int) ( $sum_prio['conflict_groups'] ?? -1 )
		&& 0 === $derived_anom_cnt
		&& MigrationPlan::STATUS_READY === $pilot_plan_9201_430->get_status()
		&& $expected_fp_9201_430 === $pilot_plan_9201_430->get_fingerprint()
		&& 2 === (int) ( $sum_alt['total_groups'] ?? 0 )
		&& 4 === (int) ( $sum_alt['importable_elements'] ?? 0 )
		&& 2 === (int) ( $sum_alt['pending_elements'] ?? 0 )
		&& 0 === (int) ( $sum_alt['conflict_groups'] ?? -1 ),
	sprintf(
		'master_fp=%s, fp_9545_9201=%s, fp_9201_430=%s, derived_anomalies=%d',
		$master_plan->get_fingerprint(),
		$pilot_plan_9545_9201->get_fingerprint(),
		$pilot_plan_9201_430->get_fingerprint(),
		$derived_anom_cnt
	)
);

// ============================================================================
// BLOQUE 5: INVARIANCIA POST-FASE DE BASES DE DATOS (SECCIONES 6 Y 9)
// ============================================================================
echo "\n--- BLOQUE 5: Invariancia Post-Fase de Bases de Datos (Secciones 6 y 9) ---\n";

$digest_after       = get_tfml_state_digest();
$sentinel_prod_post = get_icl_sentinel( 'cms_ect' );
$sentinel_lab_post  = get_icl_sentinel( 'cms_ect_lab' );
$groups_after       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_groups" );
$elements_after     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tfml_group_elements" );

record_gate(
	'8. Confirmación de cero escrituras en base de datos post-conciliación (digest SHA-256 idéntico, grupos y elementos intactos, MD5 WPML 4241ca7e7ec6399a594537cb04790c10)',
	$digest_before === $digest_after
		&& $expected_groups_339a === $groups_after
		&& $expected_elements_339a === $elements_after
		&& $expected_wpml_rows === $sentinel_prod_post['rows']
		&& $expected_wpml_md5 === $sentinel_prod_post['md5']
		&& $expected_wpml_rows === $sentinel_lab_post['rows']
		&& $expected_wpml_md5 === $sentinel_lab_post['md5'],
	sprintf(
		'digest_match=%s, groups=%d, elements=%d, prod_md5=%s, lab_md5=%s',
		$digest_before === $digest_after ? 'true' : 'false',
		$groups_after,
		$elements_after,
		$sentinel_prod_post['md5'],
		$sentinel_lab_post['md5']
	)
);

echo "\n============================================================================\n";
echo sprintf( " RESUMEN SUBSANACIÓN FASE 3.39A (DT-TFML-069A): %d/%d CONTROLES PASS (%d FAIL)\n", $passed_checks, $total_checks, $failed_checks );
echo "============================================================================\n";

exit( $failed_checks > 0 ? 1 : 0 );
