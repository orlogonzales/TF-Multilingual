<?php
/**
 * TF Multilingual — Verificación Integral de Compatibilidad Heredada Controlada: Fase 3.28.
 *
 * Directiva: DT-TFML-058
 * Alcance:
 *   1. Pre-flight y centinela estricto en base de datos de origen (cms_ect: 3.403 filas, MD5 intacto).
 *   2. Congelamiento del baseline post-migración (120 grupos / 133 elementos en cms_ect_lab).
 *   3. Restricción absoluta: ICL_LANGUAGE_CODE no definida globalmente.
 *   4. Separación arquitectónica: API pública (Superficie A) y Adaptador heredado (Superficie B).
 *   5. Preservación del código de ecoCMS (Superficie C intacta, traducir() no alterado).
 *   6. Auditoría y verificación de contratos: icl_object_id() e icl_get_languages().
 *   7. Seguridad: Adaptador desactivado por defecto, sin activación silenciosa.
 *   8. Aislamiento y no regresión: Certificación HTTP y HTML real en servidor Apache local.
 *   9. Invarianza de centinelas (Delta = 0 absoluto).
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

require_once 'D:/laragon/www/lab.ecoterra/wp-load.php';

use TF\Multilingual\API\ServicioTraduccionPublico;
use TF\Multilingual\Compatibilidad\AdaptadorCompatibilidadWpml;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

global $wpdb;
$lab_wpdb = $wpdb;
$lab_wpdb->set_prefix( 'etk_t_' );
$lab_wpdb->posts = $lab_wpdb->prefix . 'posts';
$lab_wpdb->terms = $lab_wpdb->prefix . 'terms';
$lab_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

$db_user   = defined( 'DB_USER' ) ? DB_USER : 'root';
$db_pass   = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
$db_host   = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
$prod_wpdb = new wpdb( $db_user, $db_pass, 'cms_ect', $db_host );
$prod_wpdb->set_prefix( 'etk_t_' );
$prod_wpdb->query( 'SET SESSION group_concat_max_len = 10000000' );

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
echo "  TF MULTILINGUAL — COMPATIBILIDAD HEREDADA CONTROLADA (FASE 3.28)\n";
echo "  Directiva: DT-TFML-058 (Contratos y Adaptadores de WPML)\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// 1. PRE-FLIGHT: CENTINELA WPML EN ORIGEN (cms_ect)
// -------------------------------------------------------------------------
echo "--- Pre-Flight: Centinela WPML en Base de Datos de Origen (cms_ect) ---\n";

$sentinel_table = 'etk_t_icl_translations';
$pre_rows = (int) $prod_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$sql_hash = "SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', translation_id, element_type, element_id, trid, language_code, IFNULL(source_language_code, '')) ORDER BY translation_id ASC SEPARATOR '|')) FROM `{$sentinel_table}`";
$pre_hash = (string) $prod_wpdb->get_var( $sql_hash );

echo "Sentinel WPML Pre-Flight (cms_ect):\n";
echo "  Rows: {$pre_rows} (Expected: 3403)\n";
echo "  MD5:  {$pre_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $pre_rows, "Sentinel pre-flight: Conteo WPML en cms_ect es exactamente 3403 filas" );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, "Sentinel pre-flight: Checksum MD5 en cms_ect coincide exactamente" );

$backup_file = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
assert_true( file_exists( $backup_file ), 'Archivo de respaldo restaurable existe fuera del webroot' );
$backup_hash = hash_file( 'sha256', $backup_file );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_hash, 'Huella SHA-256 del respaldo certificada' );

// -------------------------------------------------------------------------
// 2. CONTROL 1: CONGELAMIENTO DEL BASELINE POST-MIGRACIÓN (120/133)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Congelamiento del Baseline Post-Migración (120 Grupos / 133 Elementos) ---\n";

$cnt_groups   = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' );
$cnt_elements = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' );

assert_true( 120 === $cnt_groups, "BD laboratorio conserva exactamente 120 grupos en etk_t_tfml_groups ({$cnt_groups})" );
assert_true( 133 === $cnt_elements, "BD laboratorio conserva exactamente 133 elementos en etk_t_tfml_group_elements ({$cnt_elements})" );

$dup_groups = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) - COUNT(DISTINCT id) FROM `etk_t_tfml_groups`' );
assert_true( 0 === $dup_groups, 'Congelamiento: Cero grupos duplicados en etk_t_tfml_groups' );

$dup_elements = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) - COUNT(DISTINCT id) FROM `etk_t_tfml_group_elements`' );
assert_true( 0 === $dup_elements, 'Congelamiento: Cero elementos duplicados en etk_t_tfml_group_elements' );

assert_true( 0 === (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_media_translations`' ), 'Tabla media_translations conserva 0 registros' );
assert_true( 0 === (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_strings`' ), 'Tabla strings conserva 0 registros' );
assert_true( 0 === (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_string_translations`' ), 'Tabla string_translations conserva 0 registros' );

// Checkpoint.
$cp_raw = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'tfml_migration_checkpoint_wpml'" );
$cp     = maybe_unserialize( $cp_raw );
if ( is_string( $cp_raw ) && is_array( json_decode( $cp_raw, true ) ) ) {
	$cp = json_decode( $cp_raw, true );
}

$cp_status    = is_array( $cp ) ? ( $cp['status'] ?? '' ) : ( is_object( $cp ) ? ( $cp->status ?? '' ) : '' );
$cp_processed = is_array( $cp ) ? ( $cp['processed_items'] ?? 0 ) : ( is_object( $cp ) ? ( $cp->processed_items ?? 0 ) : 0 );
$cp_trid      = is_array( $cp ) ? ( $cp['last_processed_trid'] ?? 0 ) : ( is_object( $cp ) ? ( $cp->last_processed_trid ?? 0 ) : 0 );

assert_true( 'completed' === $cp_status, 'Checkpoint oficial de migración se encuentra en estado COMPLETED' );
assert_true( 18 === (int) $cp_processed, 'Checkpoint oficial registra 18 ítems procesados (Lote 10)' );
assert_true( 34840 === (int) $cp_trid, 'Checkpoint oficial registra TRID 34840 como último procesado' );

$lock_raw = $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'tfml_migration_lock_wpml'" );
assert_true( empty( $lock_raw ), 'Lock de migración liberado y disponible' );

$lab_wpml_rows = (int) $lab_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$lab_wpml_hash = (string) $lab_wpdb->get_var( $sql_hash );
assert_true( 3403 === $lab_wpml_rows, 'WPML en cms_ect_lab contiene exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $lab_wpml_hash, 'WPML en cms_ect_lab estrictamente intacto' );

// TRID 197 no importado en esta fase.
$cnt_197 = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements` WHERE element_id = 197' );
assert_true( 0 === $cnt_197, 'TRID 197 permanece sin migrar a TFML en esta fase (Delta = 0)' );

$wplang = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'WPLANG'" );
assert_true( '' === $wplang, 'Opción WPLANG en BD laboratorio permanece inalterada y vacía' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: RESTRICCIÓN ICL_LANGUAGE_CODE Y SUPERFICIES ARQUITECTÓNICAS
// -------------------------------------------------------------------------
echo "\n--- Control 2: Restricción ICL_LANGUAGE_CODE y Superficies Arquitectónicas ---\n";

assert_true( ! defined( 'ICL_LANGUAGE_CODE' ), 'Restricción DT-TFML-058: ICL_LANGUAGE_CODE NO está definida globalmente' );

assert_true( class_exists( ServicioTraduccionPublico::class ), 'Superficie A: Clase ServicioTraduccionPublico existe en TF\\Multilingual\\API' );
assert_true( class_exists( AdaptadorCompatibilidadWpml::class ), 'Superficie B: Clase AdaptadorCompatibilidadWpml existe en TF\\Multilingual\\Compatibilidad' );

$theme_ecoterra = 'D:/laragon/www/lab.ecoterra/wp-content/themes/ecoCMS/inc/ecoterra.php';
$ecoterra_code  = file_get_contents( $theme_ecoterra );
assert_true( false !== strpos( $ecoterra_code, 'function traducir(' ), 'Superficie C: traducir() conservado intacto en ecoCMS/inc/ecoterra.php' );
assert_true( false !== strpos( $ecoterra_code, "defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : 'es'" ), 'ecoCMS conserva su mecanismo de fallback nativo a español' );

// Plugin.php tiene el adaptador DESACTIVADO por defecto.
$plugin_instance = Plugin::get_instance();
$plugin_adapter  = $plugin_instance->get_adaptador_compatibilidad_wpml();
assert_true( null !== $plugin_adapter, 'Instancia de AdaptadorCompatibilidadWpml presente en Plugin' );
assert_true( ! $plugin_adapter->esta_habilitado(), 'Seguridad DT-TFML-058: Adaptador WPML permanece DESACTIVADO POR DEFECTO' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: AUDITORÍA Y VERIFICACIÓN DE CONTRATOS EN ENTORNO CONTROLADO
// -------------------------------------------------------------------------
echo "\n--- Control 3: Auditoría y Verificación de Contratos Heredados ---\n";

$api = $plugin_instance->get_servicio_traduccion_publico();
assert_true( null !== $api, 'ServicioTraduccionPublico activo en Plugin' );
assert_true( $api->es_idioma_activo( 'es' ), 'Idioma español está activo en API pública' );
assert_true( $api->es_idioma_activo( 'en' ), 'Idioma inglés está activo en API pública' );
assert_true( ! $api->es_idioma_activo( 'fr' ), 'Idioma francés está inactivo en API pública' );

// Adaptador: inicia inactivo por defecto.
$adaptador = new AdaptadorCompatibilidadWpml( $api, false );
assert_true( ! $adaptador->esta_habilitado(), 'Adaptador inicia en estado inactivo por defecto' );

// Activar adaptador para probar llamadas de contratos auditados.
$adaptador->activar();
assert_true( $adaptador->esta_habilitado(), 'Adaptador activado explícitamente para verificación de contratos' );

// En cms_ect_lab, el tour bilingüe Post 5286 (ES, "Avistamiento de Aves") tiene traducción en Post 5287 (EN, "Tour Demo").
$res_en = $adaptador->ejecutar_icl_object_id( 5286, 'tours', false, 'en' );
assert_true( 5287 === $res_en, "Contrato icl_object_id: Resuelve tour bilingüe 5286 ES a 5287 EN ({$res_en})" );

$res_es = $adaptador->ejecutar_icl_object_id( 5287, 'tours', false, 'es' );
assert_true( 5286 === $res_es, "Contrato icl_object_id: Resuelve tour bilingüe 5287 EN a 5286 ES ({$res_es})" );

// También verificar términos: Term 41 (ES) <-> Term 47 (EN).
$term_en = $adaptador->ejecutar_icl_object_id( 41, 'category', false, 'en' );
assert_true( 47 === $term_en, "Contrato icl_object_id: Resuelve término bilingüe 41 ES a 47 EN ({$term_en})" );

// Contrato: Sin traducción y return_original = false -> null.
$missing_false = $adaptador->ejecutar_icl_object_id( 999999, 'post', false, 'en' );
assert_true( null === $missing_false, 'icl_object_id: Retorna null cuando falta traducción y return_original es false' );

// Contrato: Sin traducción y return_original = true -> ID original.
$missing_true = $adaptador->ejecutar_icl_object_id( 999999, 'post', true, 'en' );
assert_true( 999999 === $missing_true, 'icl_object_id: Retorna ID original cuando falta traducción y return_original es true' );

// Contrato: Idioma inactivo (francés).
$fr_res = $adaptador->ejecutar_icl_object_id( 5286, 'tours', false, 'fr' );
assert_true( null === $fr_res, 'icl_object_id: Francés inactivo retorna null sin fallback a idioma erróneo' );

$fr_fallback = $adaptador->ejecutar_icl_object_id( 5286, 'tours', true, 'fr' );
assert_true( 5286 === $fr_fallback, 'icl_object_id: Francés inactivo con fallback retorna el ID original exacto' );

// Contrato: icl_get_languages estructurado como espera ecoCMS/inc/short-codes.php:363.
$languages = $adaptador->ejecutar_icl_get_languages( 'skip_missing=0&orderby=custom' );
assert_true( isset( $languages['es'] ) && isset( $languages['en'] ), 'icl_get_languages: Entrega lista con idiomas activos (es, en)' );
assert_true( ! isset( $languages['fr'] ), 'icl_get_languages: Excluye estrictamente el idioma francés inactivo' );
assert_true( 1 === $languages['es']['active'], 'icl_get_languages: Identifica active = 1 para el idioma actual' );
assert_true( 0 === $languages['en']['active'], 'icl_get_languages: Identifica active = 0 para el idioma secundario' );
assert_true( ! empty( $languages['es']['url'] ), 'icl_get_languages: URL del idioma presente' );
assert_true( ! empty( $languages['es']['country_flag_url'] ), 'icl_get_languages: Bandera country_flag_url presente' );
assert_true( ! empty( $languages['es']['native_name'] ), 'icl_get_languages: native_name presente' );

// Contrato: Funciones globales envolventes (require_once funciones_wpml.php ya cargado por activar()).
assert_true( function_exists( 'icl_object_id' ), 'Función global icl_object_id está disponible' );
assert_true( function_exists( 'icl_get_languages' ), 'Función global icl_get_languages está disponible' );

$fn_res = icl_object_id( 5286, 'tours', false, 'en' );
assert_true( 5287 === $fn_res, "Función global icl_object_id opera fielmente a través del adaptador ({$fn_res})" );

// Desactivar adaptador.
$adaptador->desactivar();
assert_true( ! $adaptador->esta_habilitado(), 'Adaptador de prueba desactivado limpiamente' );
assert_true( null === icl_object_id( 5286, 'tours', false, 'en' ), 'Función global se degrada limpiamente a null tras desactivar adaptador' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: NO-REGRESIÓN EN FRONTEND HTTP REAL (lab.ecoterra.test)
// -------------------------------------------------------------------------
echo "\n--- Control 4: Verificación de No-Regresión en Frontend HTTP Real ---\n";

function http_request_lab( string $url ): array {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_RESOLVE, array( 'lab.ecoterra.test:80:127.0.0.1' ) );
	$body   = curl_exec( $ch );
	$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	return [
		'status' => (int) $status,
		'body'   => (string) $body,
	];
}

$r1 = http_request_lab( 'http://lab.ecoterra.test/' );
assert_true( 200 === $r1['status'], "Portada (/) responde HTTP 200 OK ({$r1['status']})" );
assert_true( false !== strpos( $r1['body'], 'lang="es-ES"' ), 'Portada (/) preserva lang="es-ES" (Fase 3.27 sin regresión)' );
assert_true( false === strpos( $r1['body'], 'lang="en-US"' ), 'Portada (/) no contiene lang="en-US"' );

$r2 = http_request_lab( 'http://lab.ecoterra.test/old-blog/' );
assert_true( 200 === $r2['status'], "Página monolingüe (/old-blog/) responde HTTP 200 OK ({$r2['status']})" );
assert_true( false !== strpos( $r2['body'], 'lang="es-ES"' ), 'Página monolingüe emite lang="es-ES"' );

$r3 = http_request_lab( 'http://lab.ecoterra.test/category/blog/' );
assert_true( 200 === $r3['status'], "Categoría (/category/blog/) responde HTTP 200 OK ({$r3['status']})" );
assert_true( false !== strpos( $r3['body'], 'lang="es-ES"' ), 'Categoría emite lang="es-ES"' );

$r4 = http_request_lab( 'http://lab.ecoterra.test/tag/cusco/' );
assert_true( 200 === $r4['status'], "Etiqueta (/tag/cusco/) responde HTTP 200 OK ({$r4['status']})" );
assert_true( false !== strpos( $r4['body'], 'lang="es-ES"' ), 'Etiqueta emite lang="es-ES"' );

$r5 = http_request_lab( 'http://lab.ecoterra.test/en/' );
assert_true( 404 === $r5['status'], "Ruta (/en/) responde HTTP 404 (portada inglesa pendiente de TRID 197)" );
assert_true( false !== strpos( $r5['body'], 'lang="en-US"' ), 'Ruta (/en/) conmuta limpiamente a lang="en-US"' );

$r6 = http_request_lab( 'http://lab.ecoterra.test/fr/' );
assert_true( 404 === $r6['status'], "Ruta (/fr/) responde HTTP 404 (idioma inactivo)" );
assert_true( false !== strpos( $r6['body'], 'lang="es-ES"' ), 'Ruta (/fr/) preserva lang="es-ES" (francés no se activa)' );

// -------------------------------------------------------------------------
// 6. CONTROL 5: POST-FLIGHT E INVARIANZA DE CENTINELAS
// -------------------------------------------------------------------------
echo "\n--- Control 5: Invarianza Post-Flight y Centinela en Origen (cms_ect) ---\n";

$post_rows = (int) $prod_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $prod_wpdb->get_var( $sql_hash );

echo "Sentinel WPML Post-Flight (cms_ect):\n";
echo "  Rows: {$post_rows} (Expected: 3403)\n";
echo "  MD5:  {$post_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

$post_cnt_groups   = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_groups`' );
$post_cnt_elements = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM `etk_t_tfml_group_elements`' );

assert_true( 120 === $post_cnt_groups, 'Laboratorio post-flight: Conserva 120 grupos intactos (Delta = 0)' );
assert_true( 133 === $post_cnt_elements, 'Laboratorio post-flight: Conserva 133 elementos intactos (Delta = 0)' );

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.28 COMPATIBILIDAD HEREDADA CONTROLADA: EXITOSA (PASS)\n";
echo "====================================================================\n";

exit( 0 );
