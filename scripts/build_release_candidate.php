<?php
/**
 * Release Candidate Packager for TF Multilingual.
 *
 * Deterministically packages the plugin for isolated candidate evaluation.
 * Strictly excludes developer tooling, test suites, scratch files, and synthetic data.
 *
 * @package TF\Multilingual
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$version     = '1.0.0-rc1';
$dist_dir    = $plugin_root . '/dist';
$staging_dir = $dist_dir . '/staging/tf-multilingual';
$zip_file    = $dist_dir . "/tf-multilingual-{$version}.zip";

echo "====================================================================\n";
echo "TF MULTILINGUAL — EMPAQUETADO DE VERSIÓN CANDIDATA ({$version})\n";
echo "====================================================================\n";

// 1. Prepare clean dist and staging directories
if (is_dir($dist_dir . '/staging')) {
    exec("rmdir /s /q \"" . str_replace('/', '\\', $dist_dir . '/staging') . "\"");
}
if (!is_dir($staging_dir)) {
    mkdir($staging_dir, 0777, true);
}

// 2. Recursive copy helper
function copy_recursive(string $src, string $dst): void {
    $dir = opendir($src);
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    while (false !== ($file = readdir($dir))) {
        if ('.' === $file || '..' === $file) {
            continue;
        }
        $src_path = $src . '/' . $file;
        $dst_path = $dst . '/' . $file;
        if (is_dir($src_path)) {
            copy_recursive($src_path, $dst_path);
        } else {
            copy($src_path, $dst_path);
        }
    }
    closedir($dir);
}

// 3. Copy production runtime assets and sources
echo "-> Copiando archivos de código fuente runtime (src/)... \n";
copy_recursive($plugin_root . '/src', $staging_dir . '/src');

echo "-> Copiando recursos estáticos (assets/)... \n";
copy_recursive($plugin_root . '/assets', $staging_dir . '/assets');

echo "-> Copiando puntos de entrada y manifiestos principales... \n";
copy($plugin_root . '/tf-multilingual.php', $staging_dir . '/tf-multilingual.php');
copy($plugin_root . '/uninstall.php', $staging_dir . '/uninstall.php');
copy($plugin_root . '/README.md', $staging_dir . '/README.md');

// 4. Create production-only composer.json (strip require-dev and dev scripts)
$original_composer = json_decode(file_get_contents($plugin_root . '/composer.json'), true);
$prod_composer = [
    'name'        => $original_composer['name'],
    'description' => $original_composer['description'],
    'type'        => $original_composer['type'],
    'license'     => $original_composer['license'],
    'authors'     => $original_composer['authors'],
    'require'     => $original_composer['require'],
    'autoload'    => $original_composer['autoload'],
    'config'      => [
        'optimize-autoloader' => true,
        'sort-packages'       => true
    ]
];
file_put_contents(
    $staging_dir . '/composer.json',
    json_encode($prod_composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

// 5. Generate production-only autoloader via Composer
echo "-> Generando autoloader de producción exclusivo (composer dump-autoload --no-dev -o)... \n";
$cmd = 'composer dump-autoload --no-dev --optimize --working-dir="' . str_replace('/', '\\', $staging_dir) . '" 2>&1';
exec($cmd, $composer_output, $composer_status);
if (0 !== $composer_status) {
    echo "ERROR al generar autoloader:\n" . implode("\n", $composer_output) . "\n";
    exit(1);
}

// 6. Exclusion audit
echo "-> Verificando lista de exclusiones de seguridad... \n";
$forbidden_patterns = ['tests', 'scratch', 'docs', 'phpunit', 'phpcs', '.git'];
$staged_files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($staging_dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$violations = [];
$total_staged_files = 0;
foreach ($staged_files as $item) {
    if ($item->isFile()) {
        $total_staged_files++;
        $rel_path = str_replace('\\', '/', substr($item->getPathname(), strlen($staging_dir) + 1));
        foreach ($forbidden_patterns as $pattern) {
            if (str_starts_with($rel_path, $pattern . '/') || str_contains($rel_path, '/' . $pattern . '/') || str_contains($rel_path, $pattern . '.')) {
                $violations[] = $rel_path;
            }
        }
    }
}

if (!empty($violations)) {
    echo "FAIL: Se detectaron archivos prohibidos en el paquete:\n";
    print_r($violations);
    exit(1);
}
echo "   [OK] Cero archivos de pruebas, scratch o herramientas dev detectados ({$total_staged_files} archivos runtime validados).\n";

// 7. Create ZIP archive
echo "-> Creando archivo ZIP distribuible: {$zip_file}... \n";
if (file_exists($zip_file)) {
    unlink($zip_file);
}

$zip = new ZipArchive();
if (true !== $zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    echo "ERROR: No se pudo crear el archivo ZIP.\n";
    exit(1);
}

foreach ($staged_files as $item) {
    $rel_path = 'tf-multilingual/' . str_replace('\\', '/', substr($item->getPathname(), strlen($staging_dir) + 1));
    if ($item->isDir()) {
        $zip->addEmptyDir($rel_path);
    } elseif ($item->isFile()) {
        $zip->addFile($item->getPathname(), $rel_path);
    }
}
$zip->close();

$zip_size = filesize($zip_file);
$sha256   = hash_file('sha256', $zip_file);

echo "====================================================================\n";
echo "RESULTADO DE EMPAQUETADO EXITOSO:\n";
echo "- Archivo: " . basename($zip_file) . "\n";
echo "- Tamaño:  " . number_format($zip_size / 1024, 2) . " KB (" . $zip_size . " bytes)\n";
echo "- SHA-256: " . $sha256 . "\n";
echo "====================================================================\n";
