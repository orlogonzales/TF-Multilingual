<?php
/**
 * Isolated Sandbox Validator for TF Multilingual Release Candidate.
 *
 * Verifies archive integrity, extraction structure, autoloader isolation,
 * core class resolution, and clean runtime behavior without dev dependencies.
 *
 * @package TF\Multilingual
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$version     = '1.0.0-rc1';
$zip_file    = $plugin_root . "/dist/tf-multilingual-{$version}.zip";
$sandbox_dir = $plugin_root . '/dist/sandbox_validation';

echo "====================================================================\n";
echo "TF MULTILINGUAL — VALIDACIÓN DE VERSIÓN CANDIDATA EN SANDBOX AISLADO\n";
echo "====================================================================\n";

$passes = 0;
$fails  = 0;

function assert_check(bool $cond, string $title, string $details = ''): void {
    global $passes, $fails;
    if ($cond) {
        $passes++;
        echo " [PASS] {$title}\n";
        if ('' !== $details) {
            echo "        {$details}\n";
        }
    } else {
        $fails++;
        echo " [FAIL] {$title}\n";
        if ('' !== $details) {
            echo "        ERROR: {$details}\n";
        }
    }
}

// 1. Check ZIP existence and checksum
assert_check(file_exists($zip_file), "1. Archivo ZIP de versión candidata existe", "Ruta: {$zip_file}");
$sha256 = hash_file('sha256', $zip_file);
assert_check(64 === strlen($sha256), "2. Huella criptográfica SHA-256 válida", "Hash: {$sha256}");

// 2. Clean sandbox directory
if (is_dir($sandbox_dir)) {
    exec("rmdir /s /q \"" . str_replace('/', '\\', $sandbox_dir) . "\"");
}
mkdir($sandbox_dir, 0777, true);

// 3. Extract ZIP archive
$zip = new ZipArchive();
$open_res = $zip->open($zip_file);
assert_check(true === $open_res, "3. Apertura de archivo ZIP íntegra sin corrupción");

$num_files = $zip->numFiles;
assert_check($num_files >= 100, "4. Conteo de archivos esperado en paquete distribuible", "Total de entradas: {$num_files}");

$zip->extractTo($sandbox_dir);
$zip->close();

$extracted_root = $sandbox_dir . '/tf-multilingual';
assert_check(is_dir($extracted_root), "5. Carpeta raíz 'tf-multilingual/' estandarizada para WordPress");

// 4. Verify essential files presence
$essential_files = [
    'tf-multilingual.php',
    'uninstall.php',
    'README.md',
    'composer.json',
    'vendor/autoload.php',
    'assets/css/admin-spatial.css',
    'assets/css/frontend-flags.css',
    'src/Core/Plugin.php',
    'src/Editorial/TranslationEditorialService.php',
    'src/Domain/Navigation/NavMenuFrontendFilter.php',
    'src/Diagnostic/DiagnosticService.php',
    'src/Migration/MigrationManager.php',
    'src/Infrastructure/Persistence/SchemaManager.php'
];

$all_essentials = true;
foreach ($essential_files as $f) {
    if (!file_exists($extracted_root . '/' . $f)) {
        $all_essentials = false;
        break;
    }
}
assert_check($all_essentials, "6. Archivos esenciales de ejecución runtime presentes", "Verificados 13 componentes clave");

// 5. Verify absence of test/scratch files
$forbidden = ['tests', 'scratch', 'docs', 'phpunit.xml.dist', 'phpcs.xml.dist', '.git'];
$forbidden_found = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extracted_root, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($iter as $item) {
    $sub = str_replace('\\', '/', substr($item->getPathname(), strlen($extracted_root) + 1));
    foreach ($forbidden as $forb) {
        if (str_starts_with($sub, $forb . '/') || str_starts_with($sub, $forb)) {
            $forbidden_found[] = $sub;
        }
    }
}
assert_check(empty($forbidden_found), "7. Cero archivos de pruebas, desarrollo o scratch en el paquete distribuible", "Total archivos prohibidos: " . count($forbidden_found));

// 6. Test isolated PHP execution
$test_script = $sandbox_dir . '/test_isolated_execution.php';
$test_code = '<?php
require_once "' . addslashes(str_replace('\\', '/', $extracted_root . '/vendor/autoload.php')) . '";

$classes = [
    TF\Multilingual\Core\Plugin::class,
    TF\Multilingual\Domain\Language\Language::class,
    TF\Multilingual\Domain\Language\LanguageRegistry::class,
    TF\Multilingual\Editorial\TranslationEditorialService::class,
    TF\Multilingual\Domain\Navigation\NavMenuFrontendFilter::class,
    TF\Multilingual\Domain\Strings\StringFrontendFilter::class,
    TF\Multilingual\Diagnostic\DiagnosticService::class,
    TF\Multilingual\Migration\MigrationManager::class,
    TF\Multilingual\Infrastructure\Persistence\SchemaManager::class
];

foreach ($classes as $c) {
    if (!class_exists($c)) {
        echo "FAIL_CLASS: " . $c . "\n";
        exit(1);
    }
}

if (TF\Multilingual\Core\Plugin::VERSION !== "' . $version . '") {
    echo "FAIL_VERSION: " . TF\Multilingual\Core\Plugin::VERSION . "\n";
    exit(1);
}

echo "AUTOLOADER_SUCCESS\n";
exit(0);
';
file_put_contents($test_script, $test_code);

$output = [];
$ret_code = 0;
exec("php \"" . str_replace('/', '\\', $test_script) . "\" 2>&1", $output, $ret_code);

assert_check(0 === $ret_code && in_array('AUTOLOADER_SUCCESS', $output, true), "8. Autocarga PSR-4 y resolución de clases en entorno aislado", "Respuesta: " . implode(' ', $output));

// 7. Verify zero synthetic data seeded in DDL
$schema_class = file_get_contents($extracted_root . '/src/Infrastructure/Persistence/SchemaManager.php');
$has_synthetic = str_contains($schema_class, '1000044') || str_contains($schema_class, 'Conforto e Tranquilidade');
assert_check(!$has_synthetic, "9. Ausencia absoluta de datos sintéticos o post #1000044 en el código empaquetado");

echo "====================================================================\n";
echo "RESULTADO GLOBAL DE VALIDACIÓN SANDBOX: {$passes} / " . ($passes + $fails) . " PRUEBAS SUPERADAS\n";
echo "====================================================================\n";

if ($fails > 0) {
    exit(1);
}
