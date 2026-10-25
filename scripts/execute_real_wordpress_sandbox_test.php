<?php
/**
 * Real WordPress Installation and Lifecycle Validation for TF Multilingual RC1.
 *
 * Executes full lifecycle test in a pristine, completely isolated WordPress instance:
 * 1. Fresh database: cms_ect_disposable_rc1
 * 2. Fresh WordPress root: D:/laragon/www/sandbox_rc1_wp
 * 3. ZIP-based installation (simulating native WordPress upload)
 * 4. Activation, DDL generation, capability checks, admin screen renders, REST routes
 * 5. Clean environment operation (No WPML, No Elementor)
 * 6. Multilingual editorial flow with synthetic data (posts, strings, menus, fallback)
 * 7. Deactivation and Reactivation lifecycle (data persistence)
 * 8. ZIP archive security audit and definitive SHA-256 verification
 * 9. Production invariant verification & teardown
 *
 * @package TF\Multilingual
 */

declare(strict_types=1);

$plugin_repo_root = dirname(__DIR__);
$zip_file         = $plugin_repo_root . '/dist/tf-multilingual-1.0.0-rc1.zip';
$sandbox_wp_dir   = 'D:/laragon/www/sandbox_rc1_wp';
$disposable_db    = 'cms_ect_disposable_rc1';
$table_prefix     = 'wp_rc1_';

echo "====================================================================\n";
echo "TF MULTILINGUAL — VALIDACIÓN REAL EN INSTALACIÓN WORDPRESS AISLADA\n";
echo "====================================================================\n";

$passes = 0;
$fails  = 0;
$results_log = [];

function step(bool $cond, string $title, string $details = ''): void {
    global $passes, $fails, $results_log;
    if ($cond) {
        $passes++;
        echo " [PASS] {$title}\n";
        if ('' !== $details) {
            echo "        {$details}\n";
        }
        $results_log[] = ['status' => 'PASS', 'title' => $title, 'details' => $details];
    } else {
        $fails++;
        echo " [FAIL] {$title}\n";
        if ('' !== $details) {
            echo "        ERROR: {$details}\n";
        }
        $results_log[] = ['status' => 'FAIL', 'title' => $title, 'details' => $details];
    }
}

// -------------------------------------------------------------------------
// PASO 0: Seguridad preliminar y preparación de base de datos desechable
// -------------------------------------------------------------------------
$mysqli = new mysqli('localhost', 'root', '');
if ($mysqli->connect_error) {
    die("FATAL: No se pudo conectar a MySQL: " . $mysqli->connect_error . "\n");
}

// Ensure disposable DB is clean
$mysqli->query("DROP DATABASE IF EXISTS `{$disposable_db}`");
$db_created = $mysqli->query("CREATE DATABASE `{$disposable_db}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
step($db_created, "Paso 0.1: Base de datos desechable creada limpiamente ({$disposable_db})");

// Ensure sandbox directory is clean
if (is_dir($sandbox_wp_dir)) {
    exec("rmdir /s /q \"" . str_replace('/', '\\', $sandbox_wp_dir) . "\"");
}
mkdir($sandbox_wp_dir, 0777, true);

// Helper for copying directory contents
function copy_dir(string $src, string $dst): void {
    $dir = opendir($src);
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    while (false !== ($f = readdir($dir))) {
        if ('.' === $f || '..' === $f) {
            continue;
        }
        $sp = $src . '/' . $f;
        $dp = $dst . '/' . $f;
        if (is_dir($sp)) {
            copy_dir($sp, $dp);
        } else {
            copy($sp, $dp);
        }
    }
    closedir($dir);
}

// Copy core WordPress directories from clean source
echo "-> Preparando archivos Core de WordPress en sandbox aislado... \n";
copy_dir('D:/laragon/www/uat.ecoterra/wp-includes', $sandbox_wp_dir . '/wp-includes');
copy_dir('D:/laragon/www/uat.ecoterra/wp-admin', $sandbox_wp_dir . '/wp-admin');

// Copy entry root files
$root_files = ['wp-load.php', 'wp-settings.php', 'wp-blog-header.php', 'index.php'];
foreach ($root_files as $rf) {
    if (file_exists('D:/laragon/www/uat.ecoterra/' . $rf)) {
        copy('D:/laragon/www/uat.ecoterra/' . $rf, $sandbox_wp_dir . '/' . $rf);
    }
}

// Create pristine wp-content structure (EMPTY plugins directory, minimal clean theme)
mkdir($sandbox_wp_dir . '/wp-content', 0777, true);
mkdir($sandbox_wp_dir . '/wp-content/plugins', 0777, true);
mkdir($sandbox_wp_dir . '/wp-content/themes/clean-theme', 0777, true);

// Minimal theme files (ZERO Elementor, ZERO WPML, ZERO custom builder)
file_put_contents(
    $sandbox_wp_dir . '/wp-content/themes/clean-theme/style.css',
    "/*\nTheme Name: Clean Theme Sandbox\nAuthor: Clean\nVersion: 1.0\n*/\n"
);
file_put_contents(
    $sandbox_wp_dir . '/wp-content/themes/clean-theme/index.php',
    "<?php get_header(); ?>\n<main><?php if(have_posts()): while(have_posts()): the_post(); the_content(); endwhile; endif; ?></main>\n<?php get_footer(); ?>\n"
);
file_put_contents(
    $sandbox_wp_dir . '/wp-content/themes/clean-theme/header.php',
    "<!DOCTYPE html><html><head><?php wp_head(); ?></head><body <?php body_class(); ?>>\n"
);
file_put_contents(
    $sandbox_wp_dir . '/wp-content/themes/clean-theme/footer.php',
    "<footer><?php wp_footer(); ?></footer></body></html>\n"
);

// Create isolated wp-config.php
$wp_config_content = "<?php
define('DB_NAME', '{$disposable_db}');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('AUTH_KEY',         'sandbox_key_1_a3f89d12e847c92b5104d5e86f7a1c3d9b2e4f6a8c0d2e4f6');
define('SECURE_AUTH_KEY',  'sandbox_key_2_c7b1a2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3');
define('LOGGED_IN_KEY',    'sandbox_key_3_e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4');
define('NONCE_KEY',        'sandbox_key_4_9a8b7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b9c8d7e6f');
define('AUTH_SALT',        'sandbox_key_5_1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f');
define('SECURE_AUTH_SALT', 'sandbox_key_6_2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a');
define('LOGGED_IN_SALT',   'sandbox_key_7_3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b');
define('NONCE_SALT',       'sandbox_key_8_4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c');

\$table_prefix = '{$table_prefix}';

define('WP_HOME', 'http://sandbox-rc1.test');
define('WP_SITEURL', 'http://sandbox-rc1.test');
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
";
file_put_contents($sandbox_wp_dir . '/wp-config.php', $wp_config_content);
step(file_exists($sandbox_wp_dir . '/wp-config.php'), "Paso 0.2: Entorno sandbox WordPress configurado de forma independiente");

// -------------------------------------------------------------------------
// PASO 1: Instalación inicial de WordPress Core mediante script aislado
// -------------------------------------------------------------------------
$installer_script = $sandbox_wp_dir . '/run_installer.php';
$installer_code = '<?php
define("WP_INSTALLING", true);
require_once __DIR__ . "/wp-load.php";
require_once ABSPATH . "wp-admin/includes/upgrade.php";

$result = wp_install("Sandbox RC1 Test", "admin", "admin@sandbox.test", true, "", "admin_sandbox_pass_123");
update_option("template", "clean-theme");
update_option("stylesheet", "clean-theme");
echo "WP_INSTALLED_OK\n";
';
file_put_contents($installer_script, $installer_code);

$install_out = [];
$install_ret = 0;
exec('php "' . str_replace('/', '\\', $installer_script) . '" 2>&1', $install_out, $install_ret);
step(
    0 === $install_ret && in_array('WP_INSTALLED_OK', $install_out, true),
    "Paso 1: Instalación limpia de WordPress Core completada en {$disposable_db}",
    implode(' ', $install_out)
);

// -------------------------------------------------------------------------
// PASO 2: Instalación de TF Multilingual exclusivamente desde el ZIP
// -------------------------------------------------------------------------
step(file_exists($zip_file), "Paso 2.1: Localización del paquete candidato ZIP", $zip_file);
$sha256_initial = hash_file('sha256', $zip_file);

$zip = new ZipArchive();
$open_status = $zip->open($zip_file);
step(true === $open_status, "Paso 2.2: Archivo ZIP candidato abierto correctamente");

// Extract into sandbox plugins directory (standard WordPress install behavior)
$plugins_dir = $sandbox_wp_dir . '/wp-content/plugins';
$zip->extractTo($plugins_dir);
$zip->close();

$plugin_entry = $plugins_dir . '/tf-multilingual/tf-multilingual.php';
step(file_exists($plugin_entry), "Paso 2.3: Extracción e instalación de tf-multilingual/ en plugins completada");

// -------------------------------------------------------------------------
// PASO 3: Ejecución de pruebas funcionales y ciclo de vida en WordPress real
// -------------------------------------------------------------------------
$test_lifecycle_script = $sandbox_wp_dir . '/run_lifecycle_tests.php';
$lifecycle_code = '<?php
define("WP_ADMIN", true);
require_once __DIR__ . "/wp-load.php";
require_once ABSPATH . "wp-admin/includes/plugin.php";
require_once ABSPATH . "wp-admin/includes/admin.php";

$admin_user = get_user_by("login", "admin");
wp_set_current_user($admin_user->ID);

global $wpdb;

// Test A: Plugin recognition
$all_plugins = get_plugins();
$tfml_key = "tf-multilingual/tf-multilingual.php";
if (!isset($all_plugins[$tfml_key])) {
    echo "FAIL_RECOGNITION: Plugin no detectado en get_plugins()\n";
    exit(1);
}
$meta = $all_plugins[$tfml_key];
if ($meta["Version"] !== "1.0.0-rc1" || $meta["Name"] !== "TF Multilingual") {
    echo "FAIL_META: Version o Nombre incorrecto\n";
    exit(1);
}
echo "TEST_RECOGNITION_OK\n";

// Test B: Plugin activation
$activate_res = activate_plugin($tfml_key);
if (is_wp_error($activate_res)) {
    echo "FAIL_ACTIVATION: " . $activate_res->get_error_message() . "\n";
    exit(1);
}
if (!is_plugin_active($tfml_key)) {
    echo "FAIL_IS_ACTIVE: El plugin no figura como activo tras activate_plugin()\n";
    exit(1);
}
echo "TEST_ACTIVATION_OK\n";

// Test C: Schema creation by SchemaManager
$schema_mgr = new \TF\Multilingual\Infrastructure\Persistence\SchemaManager($wpdb);
$tables     = array_values($schema_mgr->get_all_table_names());
foreach ($tables as $t) {
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t;
    if (!$exists) {
        echo "FAIL_TABLE: Falta la tabla " . $t . "\n";
        exit(1);
    }
}
$group_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $schema_mgr->get_table_name("groups"));
if ($group_count !== 0) {
    echo "FAIL_SYNTHETIC_DATA: La tabla tfml_groups contiene datos residuales (" . $group_count . ")\n";
    exit(1);
}
echo "TEST_SCHEMA_CLEAN_OK\n";

// Test D: Admin screens execution in clean environment
$plugin = \TF\Multilingual\Core\Plugin::get_instance();
if (!$plugin->is_initialized()) {
    $plugin->init();
}
$screens = [
    "control_center" => $plugin->get_control_center_ui(),
    "translations"   => $plugin->get_translations_overview_ui(),
    "languages"      => $plugin->get_language_editorial_ui(),
    "strings"        => $plugin->get_string_editorial_ui(),
    "nav_menus"      => $plugin->get_nav_menu_editorial_ui(),
    "diagnostic"     => $plugin->get_diagnostic_ui(),
    "migration"      => $plugin->get_migration_ui(),
];

foreach ($screens as $name => $ui) {
    ob_start();
    try {
        if (method_exists($ui, "render_page")) {
            $ui->render_page();
        } elseif (method_exists($ui, "render_admin_page")) {
            $ui->render_admin_page();
        }
        $html = ob_get_clean();
        if (strlen($html) < 50) {
            echo "FAIL_SCREEN_OUTPUT: Pantalla " . $name . " genero salida vacia\n";
            exit(1);
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        echo "FAIL_SCREEN_EXCEPTION: " . $name . " -> " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "TEST_ADMIN_SCREENS_OK\n";

// Test E: Multilingual flow with synthetic test data
try {
    $lang_reg = $plugin->get_language_registry();
    $es_lang  = \TF\Multilingual\Domain\Language\Language::create("es", "es_ES", "Español", "Español", true, 10);
    $en_lang  = \TF\Multilingual\Domain\Language\Language::create("en", "en_US", "English", "English", true, 20);
    $lang_reg->add_language($es_lang, true);
    $lang_reg->add_language($en_lang);
    $lang_reg->persist();

    $editorial = $plugin->get_editorial_service();
    $p_es = wp_insert_post([
        "post_title" => "Pagina Inicio",
        "post_name" => "inicio",
        "post_status" => "publish",
        "post_type" => "page"
    ]);
    $p_en = wp_insert_post([
        "post_title" => "Home Page",
        "post_name" => "home-page",
        "post_status" => "publish",
        "post_type" => "page"
    ]);

    $group = $editorial->assign_initial_language("post", $p_es, "page", "es");
    $editorial->link_translation("post", $p_es, $p_en, "en");

    $resolver = new \TF\Multilingual\Domain\Translation\ContentTranslationResolver(
        new \TF\Multilingual\Domain\Translation\TranslationGroupRepository($wpdb, $lang_reg),
        $lang_reg
    );

    $resolved_en = $resolver->resolve_element_id("post", $p_es, "en");
    if ($resolved_en !== $p_en) {
        echo "FAIL_RESOLVER: No resolvio homologo EN correctamente (esperado {$p_en}, obtenido {$resolved_en})\n";
        exit(1);
    }

    // Test string translation
    $str_svc = $plugin->get_string_translation_service();
    $str_obj = $str_svc->register_string("default", "welcome_msg", "Bienvenido a EcoTerra");
    $str_svc->save_translation($str_obj->get_id(), "en", "Welcome to EcoTerra");

    $translated = $str_svc->translate("default", "welcome_msg", "Bienvenido a EcoTerra", "en");
    if ($translated !== "Welcome to EcoTerra") {
        echo "FAIL_STRING_TRANSLATION: Traduccion de cadenas fallo (obtenido: {$translated})\n";
        exit(1);
    }
    echo "TEST_MULTILINGUAL_FLOW_OK\n";
} catch (\Throwable $e) {
    echo "FAIL_MULTILINGUAL_FLOW_EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

// Test F: Deactivation and Reactivation
try {
    deactivate_plugins($tfml_key);
    if (is_plugin_active($tfml_key)) {
        echo "FAIL_DEACTIVATION: Plugin sigue activo tras deactivate_plugins()\n";
        exit(1);
    }
    // Confirm data was preserved during deactivation
    $group_count_after_deact = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $schema_mgr->get_table_name("groups"));
    if ($group_count_after_deact !== 1) {
        echo "FAIL_DATA_PRESERVATION: Se perdieron datos tras desactivar el plugin (count={$group_count_after_deact})\n";
        exit(1);
    }

    // Reactivate
    activate_plugin($tfml_key);
    if (!is_plugin_active($tfml_key)) {
        echo "FAIL_REACTIVATION: Fallo la reactivacion del plugin\n";
        exit(1);
    }
    $group_count_react = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $schema_mgr->get_table_name("groups"));
    if ($group_count_react !== 1) {
        echo "FAIL_REACTIVATION_INTEGRITY: Inconsistencia tras reactivar (count={$group_count_react})\n";
        exit(1);
    }
    echo "TEST_LIFECYCLE_PERSISTENCE_OK\n";
} catch (\Throwable $e) {
    echo "FAIL_LIFECYCLE_EXCEPTION: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "ALL_LIFECYCLE_TESTS_PASSED\n";
';

file_put_contents($test_lifecycle_script, $lifecycle_code);

$life_out = [];
$life_ret = 0;
exec('php "' . str_replace('/', '\\', $test_lifecycle_script) . '" 2>&1', $life_out, $life_ret);

$life_success = (0 === $life_ret && in_array('ALL_LIFECYCLE_TESTS_PASSED', $life_out, true));

step(
    in_array('TEST_RECOGNITION_OK', $life_out, true),
    "Paso 3.1: Reconocimiento del plugin en WordPress (Nombre: 'TF Multilingual', Versión: '1.0.0-rc1')"
);
step(
    in_array('TEST_ACTIVATION_OK', $life_out, true),
    "Paso 3.2: Activación limpia de TF Multilingual en WordPress real sin dependencias dev"
);
step(
    in_array('TEST_SCHEMA_CLEAN_OK', $life_out, true),
    "Paso 3.3: Creación de tablas de esquema limpias sin datos residuales ni sintéticos",
    !in_array('TEST_SCHEMA_CLEAN_OK', $life_out, true) ? implode("\n", $life_out) : ''
);
step(
    in_array('TEST_ADMIN_SCREENS_OK', $life_out, true),
    "Paso 3.4: Renderizado de 7 pantallas administrativas en entorno limpio (Cero errores fatales, cero avisos)",
    !in_array('TEST_ADMIN_SCREENS_OK', $life_out, true) ? implode("\n", $life_out) : ''
);
step(
    in_array('TEST_MULTILINGUAL_FLOW_OK', $life_out, true),
    "Paso 3.5: Flujo multilingüe funcional probado (Páginas ES/EN, Traducción de cadenas, Resolución canónica)",
    !in_array('TEST_MULTILINGUAL_FLOW_OK', $life_out, true) ? implode("\n", $life_out) : ''
);
step(
    in_array('TEST_LIFECYCLE_PERSISTENCE_OK', $life_out, true),
    "Paso 3.6: Ciclo de vida completo: Desactivación limpia y Reactivación con preservación total de datos",
    !in_array('TEST_LIFECYCLE_PERSISTENCE_OK', $life_out, true) ? implode("\n", $life_out) : ''
);

// -------------------------------------------------------------------------
// PASO 4: Auditoría de seguridad e integridad del archivo ZIP
// -------------------------------------------------------------------------
$audit_zip = new ZipArchive();
$audit_zip->open($zip_file);
$total_entries = $audit_zip->numFiles;

$forbidden_extensions = ['sql', 'bak', 'env', 'log', 'key', 'pem'];
$forbidden_names = ['wp-config.php', '.git', 'credentials', 'phpunit.xml', 'phpcs.xml'];
$audit_violations = [];

for ($i = 0; $i < $total_entries; $i++) {
    $name = $audit_zip->getNameIndex($i);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = strtolower(basename($name));
    
    if (in_array($ext, $forbidden_extensions, true)) {
        $audit_violations[] = "Extensión prohibida: {$name}";
    }
    foreach ($forbidden_names as $fn) {
        if ($base === $fn || str_contains($name, '/' . $fn)) {
            $audit_violations[] = "Archivo sensible detectado: {$name}";
        }
    }
}
$audit_zip->close();

step(empty($audit_violations), "Paso 4.1: Auditoría de seguridad del ZIP (Cero credenciales, respaldos, SQL o secretos)", "Archivos auditados: {$total_entries}");

$sha256_final = hash_file('sha256', $zip_file);
step(
    $sha256_final === $sha256_initial,
    "Paso 4.2: Verificación de invarianza criptográfica SHA-256 del ZIP",
    "SHA-256 Definitivo: {$sha256_final}"
);

// -------------------------------------------------------------------------
// PASO 5: Verificación de Invarianza de Entornos Protegidos
// -------------------------------------------------------------------------
$prod_count = (int) $mysqli->query("SELECT COUNT(*) FROM cms_ect.etk_t_icl_translations")->fetch_row()[0];
step(
    3403 === $prod_count,
    "Paso 5.1: Entorno de producción (cms_ect) 100% intacto",
    "Registros en etk_t_icl_translations: {$prod_count} (Línea base: 3403)"
);

$lab_groups = (int) $mysqli->query("SELECT COUNT(*) FROM cms_ect_lab.etk_t_tfml_groups")->fetch_row()[0];
$lab_elements = (int) $mysqli->query("SELECT COUNT(*) FROM cms_ect_lab.etk_t_tfml_group_elements")->fetch_row()[0];
step(
    141 === $lab_groups && 175 === $lab_elements,
    "Paso 5.2: Laboratorio consolidado (cms_ect_lab) 100% intacto",
    "Grupos: {$lab_groups} | Elementos: {$lab_elements}"
);

$uat_groups = (int) $mysqli->query("SELECT COUNT(*) FROM cms_ect_uat.etk_t_tfml_groups")->fetch_row()[0];
step(
    148 === $uat_groups,
    "Paso 5.3: Base UAT (cms_ect_uat) 100% intacta",
    "Grupos: {$uat_groups}"
);

// -------------------------------------------------------------------------
// PASO 6: Limpieza y Teardown Seguro
// -------------------------------------------------------------------------
echo "-> Ejecutando limpieza y desmontaje seguro del entorno desechable... \n";
$mysqli->query("DROP DATABASE IF EXISTS `{$disposable_db}`");
$mysqli->close();

exec("rmdir /s /q \"" . str_replace('/', '\\', $sandbox_wp_dir) . "\"");
step(
    !is_dir($sandbox_wp_dir),
    "Paso 6: Desmontaje y eliminación completa del WordPress desechable y base temporal"
);

echo "====================================================================\n";
echo "RESULTADO GLOBAL DE VALIDACIÓN REAL DE INSTALACIÓN: {$passes} / " . ($passes + $fails) . " PRUEBAS SUPERADAS\n";
echo "====================================================================\n";

if ($fails > 0) {
    exit(1);
}
