<?php
/**
 * Script de Verificación Integral — Fase 3.29
 *
 * Blindaje de Activación y Ausencia de Efectos Secundarios del Adaptador WPML (DT-TFML-059).
 *
 * Valida de forma determinista y reproducible:
 * 1. Pre-Flight del centinela WPML en cms_ect (3.403 filas, MD5 intacto).
 * 2. Congelamiento del baseline en cms_ect_lab (120 grupos, 133 elementos, TRID 197 sin migrar).
 * 3. Batería de 14 pruebas obligatorias en procesos PHP independientes aislados.
 * 4. Verificación de no-regresión en frontend HTTP real (lab.ecoterra.test).
 * 5. Invarianza post-flight en origen (cms_ect) y laboratorio (cms_ect_lab).
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

/**
 * Ejecuta un script PHP en un proceso CLI completamente aislado e independiente.
 *
 * @param string $code Código PHP a ejecutar.
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function run_isolated_php( string $code ): array {
	$temp_file = __DIR__ . '/tfml_iso_' . uniqid() . '.php';
	$wrapper   = "<?php\nini_set('display_errors', '1');\nerror_reporting(E_ALL);\n" . $code;
	file_put_contents( $temp_file, $wrapper );

	$php_bin = PHP_BINARY;
	$cmd     = escapeshellarg( $php_bin ) . ' ' . escapeshellarg( $temp_file );

	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = proc_open( $cmd, $descriptors, $pipes );
	if ( ! is_resource( $process ) ) {
		unlink( $temp_file );
		throw new RuntimeException( 'No se pudo iniciar el proceso PHP aislado.' );
	}

	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );

	$exit_code = proc_close( $process );
	@unlink( $temp_file );

	return array(
		'exit_code' => $exit_code,
		'stdout'    => (string) $stdout,
		'stderr'    => (string) $stderr,
	);
}

echo "====================================================================\n";
echo "  TF MULTILINGUAL — BLINDAJE DEL ADAPTADOR HEREDADO (FASE 3.29)\n";
echo "  Directiva: DT-TFML-059 (Ausencia de Efectos Secundarios WPML)\n";
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

assert_true( 3403 === $pre_rows, 'Sentinel pre-flight: Conteo WPML en cms_ect es exactamente 3403 filas' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $pre_hash, 'Sentinel pre-flight: Checksum MD5 en cms_ect coincide exactamente' );

$backup_file = 'd:/laragon/backup/tfml_staging/preflight_backup_cms_ect_snapshot.sql';
assert_true( file_exists( $backup_file ), 'Archivo de respaldo restaurable existe fuera del webroot' );
$backup_hash = hash_file( 'sha256', $backup_file );
assert_true( 'f4db11d9c18bd393fb684a38128a1f7be65b76a09249484ef39de67ba73c4b1a' === $backup_hash, 'Huella SHA-256 del respaldo certificada' );

// -------------------------------------------------------------------------
// 2. CONTROL 1: CONGELAMIENTO DEL BASELINE POST-MIGRACIÓN (120/133)
// -------------------------------------------------------------------------
echo "\n--- Control 1: Congelamiento del Baseline Post-Migración (120 Grupos / 133 Elementos) ---\n";

$lab_groups   = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_groups' );
$lab_elements = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_group_elements' );

assert_true( 120 === $lab_groups, "BD laboratorio conserva exactamente 120 grupos en etk_t_tfml_groups ({$lab_groups})" );
assert_true( 133 === $lab_elements, "BD laboratorio conserva exactamente 133 elementos en etk_t_tfml_group_elements ({$lab_elements})" );

$dup_groups   = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM (SELECT id, COUNT(*) c FROM etk_t_tfml_groups GROUP BY id HAVING c > 1) t' );
$dup_elements = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM (SELECT group_id, element_type, element_id, COUNT(*) c FROM etk_t_tfml_group_elements GROUP BY group_id, element_type, element_id HAVING c > 1) t' );

assert_true( 0 === $dup_groups, 'Congelamiento: Cero grupos duplicados en etk_t_tfml_groups' );
assert_true( 0 === $dup_elements, 'Congelamiento: Cero elementos duplicados en etk_t_tfml_group_elements' );

$media_count   = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_media_translations' );
$strings_count = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_strings' );
$str_tr_count  = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_string_translations' );

assert_true( 0 === $media_count, 'Tabla media_translations conserva 0 registros' );
assert_true( 0 === $strings_count, 'Tabla strings conserva 0 registros' );
assert_true( 0 === $str_tr_count, 'Tabla string_translations conserva 0 registros' );

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
assert_true( 3403 === $lab_wpml_rows, "WPML en cms_ect_lab contiene exactamente 3403 filas ({$lab_wpml_rows})" );

$trid_197_in_tfml = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_group_elements WHERE element_id = 197' );
assert_true( 0 === $trid_197_in_tfml, 'TRID 197 permanece sin migrar a TFML en esta fase (Delta = 0)' );

$wplang_opt = (string) $lab_wpdb->get_var( "SELECT option_value FROM `etk_t_options` WHERE option_name = 'WPLANG'" );
assert_true( '' === $wplang_opt, 'Opción WPLANG en BD laboratorio permanece inalterada y vacía' );

// -------------------------------------------------------------------------
// 3. CONTROL 2: BATERÍA OBLIGATORIA DE PRUEBAS EN PROCESOS AISLADOS (DT-TFML-059)
// -------------------------------------------------------------------------
echo "\n--- Control 2: Batería de 14 Pruebas en Procesos PHP Aislados ---\n";

// -------------------------------------------------------------------------
// Escenario 1, 6 y 13: TFML sin adaptador (desactivado por omisión)
// -------------------------------------------------------------------------
echo "  [Proceso 1] Evaluando TFML con adaptador desactivado por defecto...\n";
$proc1 = run_isolated_php( <<<'PHP'
require_once 'D:/laragon/www/lab.ecoterra/wp-load.php';
$plugin = \TF\Multilingual\Core\Plugin::get_instance();
$adaptador = $plugin->get_adaptador_compatibilidad_wpml();

$data = [
    'fn_icl_object_id'    => function_exists('icl_object_id'),
    'fn_icl_get_languages'=> function_exists('icl_get_languages'),
    'const_icl_lang'      => defined('ICL_LANGUAGE_CODE'),
    'adapter_enabled'     => $adaptador ? $adaptador->esta_habilitado() : null,
    'wrappers_registered' => $adaptador ? $adaptador->estan_envoltorios_registrados() : null,
];
echo json_encode($data);
PHP
);

if ( 0 !== $proc1['exit_code'] ) {
	echo "STDOUT: " . $proc1['stdout'] . "\n";
	echo "STDERR: " . $proc1['stderr'] . "\n";
}
assert_true( 0 === $proc1['exit_code'], 'Proceso 1 ejecutó limpiamente (código 0)' );
$d1 = json_decode( $proc1['stdout'], true );
assert_true( false === $d1['fn_icl_object_id'], 'Escenario 1 & 6: function_exists(icl_object_id) es FALSE con adaptador desactivado' );
assert_true( false === $d1['fn_icl_get_languages'], 'Escenario 1 & 6: function_exists(icl_get_languages) es FALSE con adaptador desactivado' );
assert_true( false === $d1['const_icl_lang'], 'Escenario 13: ICL_LANGUAGE_CODE NO está definida globalmente' );
assert_true( false === $d1['adapter_enabled'], 'Escenario 1: Adaptador inicia en estado deshabilitado por omisión' );
assert_true( false === $d1['wrappers_registered'], 'Escenario 1: Envoltorios globales no fueron registrados en memoria' );

// -------------------------------------------------------------------------
// Escenario 5: API pública nativa (Superficie A) disponible sin adaptador
// -------------------------------------------------------------------------
echo "  [Proceso 2] Evaluando Superficie A (API pública nativa) sin adaptador...\n";
$proc2 = run_isolated_php( <<<'PHP'
require_once 'D:/laragon/www/lab.ecoterra/wp-load.php';
$plugin = \TF\Multilingual\Core\Plugin::get_instance();
$api = $plugin->get_servicio_traduccion_publico();

$tour_en = $api->resolver_traduccion_elemento('post', 5286, 'en');
$term_en = $api->resolver_traduccion_elemento('category', 41, 'en');

$data = [
    'actual'   => $api->obtener_idioma_actual(),
    'default'  => $api->obtener_idioma_por_defecto(),
    'activos'  => $api->obtener_idiomas_activos(),
    'es_es'    => $api->es_idioma_activo('es'),
    'es_fr'    => $api->es_idioma_activo('fr'),
    'tour_en'  => $tour_en,
    'term_en'  => $term_en,
    'fn_exists'=> function_exists('icl_object_id'),
];
echo json_encode($data);
PHP
);

assert_true( 0 === $proc2['exit_code'], 'Proceso 2 ejecutó limpiamente (código 0)' );
$d2 = json_decode( $proc2['stdout'], true );
assert_true( 'es' === $d2['actual'], 'Escenario 5: API nativa resuelve idioma actual es' );
assert_true( 'es' === $d2['default'], 'Escenario 5: API nativa resuelve idioma por defecto es' );
assert_true( in_array( 'es', $d2['activos'], true ) && in_array( 'en', $d2['activos'], true ) && ! in_array( 'fr', $d2['activos'], true ), 'Escenario 5: API nativa lista activos (es y en presentes, fr inactivo ausente)' );
assert_true( true === $d2['es_es'] && false === $d2['es_fr'], 'Escenario 5: API nativa discrimina activo es vs inactivo fr' );
assert_true( 5287 === $d2['tour_en'], 'Escenario 5: API nativa resuelve tour bilingüe 5286 ES -> 5287 EN' );
assert_true( 47 === $d2['term_en'], 'Escenario 5: API nativa resuelve término bilingüe 41 ES -> 47 EN' );
assert_true( false === $d2['fn_exists'], 'Escenario 5: La API nativa opera plenamente SIN que exista icl_object_id' );

// -------------------------------------------------------------------------
// Escenario 2, 7, 8, 9, 10, 11: TFML con adaptador activado explícitamente
// -------------------------------------------------------------------------
echo "  [Proceso 3] Evaluando TFML con adaptador activado explícitamente...\n";
$proc3 = run_isolated_php( <<<'PHP'
// Habilitar adaptador mediante constante oficial antes de que wp-load / Plugin::init() corran.
define('TFML_ENABLE_WPML_COMPATIBILITY', true);
require_once 'D:/laragon/www/lab.ecoterra/wp-load.php';

$plugin = \TF\Multilingual\Core\Plugin::get_instance();
$adaptador = $plugin->get_adaptador_compatibilidad_wpml();

$tour_en = icl_object_id(5286, 'tours', false, 'en');
$tour_es = icl_object_id(5287, 'tours', false, 'es');
$term_en = icl_object_id(41, 'category', false, 'en');

$missing_false = icl_object_id(999999, 'post', false, 'en');
$missing_true  = icl_object_id(999999, 'post', true, 'en');

$fr_false = icl_object_id(5286, 'tours', false, 'fr');
$fr_true  = icl_object_id(5286, 'tours', true, 'fr');

$langs = icl_get_languages('skip_missing=0&orderby=custom');

$data = [
    'fn_icl_object_id'    => function_exists('icl_object_id'),
    'fn_icl_get_languages'=> function_exists('icl_get_languages'),
    'const_icl_lang'      => defined('ICL_LANGUAGE_CODE'),
    'adapter_enabled'     => $adaptador ? $adaptador->esta_habilitado() : false,
    'wrappers_registered' => $adaptador ? $adaptador->estan_envoltorios_registrados() : false,
    'tour_en'             => $tour_en,
    'tour_es'             => $tour_es,
    'term_en'             => $term_en,
    'missing_false'       => $missing_false,
    'missing_true'        => $missing_true,
    'fr_false'            => $fr_false,
    'fr_true'             => $fr_true,
    'langs_count'         => count($langs),
    'langs_has_es'        => isset($langs['es']),
    'langs_has_en'        => isset($langs['en']),
    'langs_has_fr'        => isset($langs['fr']),
    'es_active'           => $langs['es']['active'] ?? null,
    'en_active'           => $langs['en']['active'] ?? null,
    'es_native'           => $langs['es']['native_name'] ?? null,
    'es_flag'             => !empty($langs['es']['country_flag_url']),
];
echo json_encode($data);
PHP
);

if ( 0 !== $proc3['exit_code'] ) {
	echo "STDOUT: " . $proc3['stdout'] . "\n";
	echo "STDERR: " . $proc3['stderr'] . "\n";
}
assert_true( 0 === $proc3['exit_code'], 'Proceso 3 ejecutó limpiamente (código 0)' );
$d3 = json_decode( $proc3['stdout'], true );
assert_true( true === $d3['adapter_enabled'], 'Escenario 2: Adaptador activado explícitamente vía filtro oficial' );
assert_true( true === $d3['wrappers_registered'], 'Escenario 2: Envoltorios globales registrados exitosamente' );
assert_true( true === $d3['fn_icl_object_id'], 'Escenario 7: function_exists(icl_object_id) es TRUE con adaptador activo' );
assert_true( true === $d3['fn_icl_get_languages'], 'Escenario 7: function_exists(icl_get_languages) es TRUE con adaptador activo' );
assert_true( false === $d3['const_icl_lang'], 'Escenario 13: Restricción ICL_LANGUAGE_CODE strictly respetada con adaptador activo' );
assert_true( 5287 === $d3['tour_en'], 'Escenario 8: icl_object_id resuelve tour bilingüe 5286 ES -> 5287 EN' );
assert_true( 5286 === $d3['tour_es'], 'Escenario 8: icl_object_id resuelve tour bilingüe 5287 EN -> 5286 ES' );
assert_true( 47 === $d3['term_en'], 'Escenario 8: icl_object_id resuelve término bilingüe 41 ES -> 47 EN' );
assert_true( null === $d3['missing_false'], 'Escenario 9: Falta traducción con fallback false devuelve null' );
assert_true( 999999 === $d3['missing_true'], 'Escenario 9: Falta traducción con fallback true devuelve ID original' );
assert_true( null === $d3['fr_false'], 'Escenario 11: Francés inactivo con fallback false devuelve null' );
assert_true( 5286 === $d3['fr_true'], 'Escenario 11: Francés inactivo con fallback true devuelve ID original exacto' );
assert_true( $d3['langs_count'] >= 2, 'Escenario 10: icl_get_languages entrega idiomas activos (al menos 2)' );
assert_true( true === $d3['langs_has_es'] && true === $d3['langs_has_en'], 'Escenario 10: icl_get_languages contiene es y en' );
assert_true( false === $d3['langs_has_fr'], 'Escenario 11: Exclusión categórica del francés inactivo en icl_get_languages' );
assert_true( 1 === $d3['es_active'] && 0 === $d3['en_active'], 'Escenario 10: Marca correctamente el idioma activo (es=1, en=0)' );
assert_true( ! empty( $d3['es_native'] ) && true === $d3['es_flag'], 'Escenario 10: Contiene native_name y country_flag_url válidos' );

// -------------------------------------------------------------------------
// Escenario 3: TFML con WPML real presente (simulado)
// -------------------------------------------------------------------------
echo "  [Proceso 4] Evaluando comportamiento ante presencia de WPML real...\n";
$proc4 = run_isolated_php( <<<'PHP'
// Simular presencia de WPML real antes de cargar plugins.
define('ICL_SITEPRESS_VERSION', '4.6.0');
class SitePress {}

require_once 'D:/laragon/www/lab.ecoterra/wp-load.php';

$plugin = \TF\Multilingual\Core\Plugin::get_instance();
$api = $plugin->get_servicio_traduccion_publico();

// Intentar forzar activación de adaptador TFML en presencia de WPML real.
$adaptador = new \TF\Multilingual\Compatibilidad\AdaptadorCompatibilidadWpml($api, true);

$data = [
    'detecta_wpml'        => $adaptador->detectar_presencia_wpml_real(),
    'admisible'           => $adaptador->es_admisible_para_registro(),
    'esta_habilitado'     => $adaptador->esta_habilitado(),
    'motivo_inhibicion'   => $adaptador->get_motivo_inhibicion(),
    'envoltorios_cargados'=> $adaptador->estan_envoltorios_registrados(),
];
echo json_encode($data);
PHP
);

assert_true( 0 === $proc4['exit_code'], 'Proceso 4 ejecutó limpiamente (código 0)' );
$d4 = json_decode( $proc4['stdout'], true );
assert_true( true === $d4['detecta_wpml'], 'Escenario 3: Detecta presencia real de WPML en el runtime' );
assert_true( false === $d4['admisible'], 'Escenario 3: Determina no admisible la activación ante WPML real' );
assert_true( false === $d4['esta_habilitado'], 'Escenario 3: Adaptador se auto-inhibe y permanece deshabilitado' );
assert_true( false === $d4['envoltorios_cargados'], 'Escenario 3: No registra funciones envolventes ni colisiona' );
assert_true( false !== strpos( (string) $d4['motivo_inhibicion'], 'WPML real detectado' ), 'Escenario 3: Emite diagnóstico formal de inhibición' );

// -------------------------------------------------------------------------
// Escenario 4 & 12: Activación solicitada ante símbolos globales preexistentes
// -------------------------------------------------------------------------
echo "  [Proceso 5] Evaluando comportamiento ante colisión de símbolos globales...\n";
$proc5 = run_isolated_php( <<<'PHP'
// Declarar función ajena previa simulando un plugin externo.
function icl_object_id($id, $type = 'post') {
    return 'FOREIGN_FUNCTION_RESULT_' . $id;
}

require_once 'D:/laragon/www/lab.ecoterra/wp-load.php';

$plugin = \TF\Multilingual\Core\Plugin::get_instance();
$api = $plugin->get_servicio_traduccion_publico();

$adaptador = new \TF\Multilingual\Compatibilidad\AdaptadorCompatibilidadWpml($api, true);

$data = [
    'detecta_colision'    => $adaptador->detectar_colision_simbolos(),
    'admisible'           => $adaptador->es_admisible_para_registro(),
    'esta_habilitado'     => $adaptador->esta_habilitado(),
    'motivo_inhibicion'   => $adaptador->get_motivo_inhibicion(),
    'foreign_call'        => icl_object_id(123),
];
echo json_encode($data);
PHP
);

assert_true( 0 === $proc5['exit_code'], 'Proceso 5 ejecutó limpiamente sin fatales (código 0)' );
$d5 = json_decode( $proc5['stdout'], true );
assert_true( true === $d5['detecta_colision'], 'Escenario 4 & 12: Detecta colisión de símbolos globales preexistentes' );
assert_true( false === $d5['admisible'], 'Escenario 4 & 12: Determina no admisible para registro' );
assert_true( false === $d5['esta_habilitado'], 'Escenario 4 & 12: Adaptador se auto-inhibe de manera segura' );
assert_true( 'FOREIGN_FUNCTION_RESULT_123' === $d5['foreign_call'], 'Escenario 12: Ausencia de fatal por redeclaración, función preexistente intacta' );

// -------------------------------------------------------------------------
// 4. CONTROL 3: NO-REGRESIÓN EN FRONTEND HTTP REAL (lab.ecoterra.test)
// -------------------------------------------------------------------------
echo "\n--- Control 3: Verificación de No-Regresión en Frontend HTTP Real ---\n";

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

	return array(
		'status' => (int) $status,
		'body'   => (string) $body,
	);
}

$r1 = http_request_lab( 'http://lab.ecoterra.test/' );
assert_true( 200 === $r1['status'], "Portada (/) responde HTTP 200 OK ({$r1['status']})" );
assert_true( false !== strpos( $r1['body'], 'lang="es-ES"' ), 'Portada (/) preserva lang="es-ES" (Fase 3.27 sin regresión)' );
assert_true( false === strpos( $r1['body'], 'lang="en-US"' ), 'Portada (/) no contiene lang="en-US"' );
assert_true( strlen( $r1['body'] ) > 40000, 'Portada (/) renderiza tema ecoCMS completamente (>40KB HTML)' );

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
assert_true( 404 === $r5['status'], 'Ruta (/en/) responde HTTP 404 (portada inglesa pendiente de TRID 197)' );
assert_true( false !== strpos( $r5['body'], 'lang="en-US"' ), 'Ruta (/en/) conmuta limpiamente a lang="en-US"' );

$r6 = http_request_lab( 'http://lab.ecoterra.test/fr/' );
assert_true( 404 === $r6['status'], 'Ruta (/fr/) responde HTTP 404 (idioma inactivo)' );
assert_true( false !== strpos( $r6['body'], 'lang="es-ES"' ), 'Ruta (/fr/) preserva lang="es-ES" (francés no se activa)' );

// -------------------------------------------------------------------------
// 5. CONTROL 4: POST-FLIGHT E INVARIANZA DE CENTINELAS
// -------------------------------------------------------------------------
echo "\n--- Control 4: Invarianza Post-Flight y Centinela en Origen (cms_ect) ---\n";

$post_rows = (int) $prod_wpdb->get_var( "SELECT COUNT(*) FROM `{$sentinel_table}`" );
$post_hash = (string) $prod_wpdb->get_var( $sql_hash );

echo "Sentinel WPML Post-Flight (cms_ect):\n";
echo "  Rows: {$post_rows} (Expected: 3403)\n";
echo "  MD5:  {$post_hash} (Expected: 4241ca7e7ec6399a594537cb04790c10)\n\n";

assert_true( 3403 === $post_rows, 'Sentinel post-flight: Conteo WPML en cms_ect intacto (3403 filas, Delta = 0)' );
assert_true( '4241ca7e7ec6399a594537cb04790c10' === $post_hash, 'Sentinel post-flight: Checksum WPML en cms_ect intacto (Delta = 0)' );

$post_lab_groups   = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_groups' );
$post_lab_elements = (int) $lab_wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_group_elements' );

assert_true( 120 === $post_lab_groups, 'Laboratorio post-flight: Conserva 120 grupos intactos (Delta = 0)' );
assert_true( 133 === $post_lab_elements, 'Laboratorio post-flight: Conserva 133 elementos intactos (Delta = 0)' );

echo "====================================================================\n";
echo "  RESULTADO GLOBAL: {$assertions_passed}/{$assertions_passed} CONTROLES PASS\n";
echo "  FASE 3.29 BLINDAJE DEL ADAPTADOR HEREDADO: EXITOSA (PASS)\n";
echo "====================================================================\n";

exit( 0 );
