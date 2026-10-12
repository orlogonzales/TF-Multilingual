<?php
/**
 * Script de auditoría de contratos reales de ecoCMS para DT-TFML-058.
 */

$theme_dir = 'D:/laragon/www/lab.ecoterra/wp-content/themes/ecoCMS';
$iterator  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme_dir ) );
$symbols   = [ 'ICL_LANGUAGE_CODE', 'icl_object_id', 'icl_get_languages', 'traducir' ];
$inventory = [];

foreach ( $iterator as $file ) {
	if ( $file->isDir() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$real_path = str_replace( '\\', '/', $file->getPathname() );
	$rel_path  = str_replace( str_replace( '\\', '/', $theme_dir ) . '/', '', $real_path );
	$content   = file_get_contents( $real_path );
	$lines     = explode( "\n", $content );

	foreach ( $lines as $idx => $line ) {
		foreach ( $symbols as $sym ) {
			if ( strpos( $line, $sym ) !== false ) {
				$inventory[] = [
					'symbol' => $sym,
					'file'   => $rel_path,
					'line'   => $idx + 1,
					'code'   => trim( $line ),
				];
			}
		}
	}
}

$summary = [];
foreach ( $inventory as $item ) {
	$sym = $item['symbol'];
	if ( ! isset( $summary[ $sym ] ) ) {
		$summary[ $sym ] = [
			'count' => 0,
			'files' => [],
			'items' => [],
		];
	}
	$summary[ $sym ]['count']++;
	$summary[ $sym ]['files'][ $item['file'] ] = ( $summary[ $sym ]['files'][ $item['file'] ] ?? 0 ) + 1;
	$summary[ $sym ]['items'][]                = $item;
}

echo "=== RESUMEN DE LLAMADAS EN ECOCMS ===\n";
foreach ( $summary as $sym => $data ) {
	echo "Símbolo: {$sym} | Total ocurrencias: {$data['count']}\n";
	foreach ( $data['files'] as $f => $cnt ) {
		echo "  - {$f}: {$cnt}\n";
	}
}

file_put_contents( __DIR__ . '/theme_contracts_inventory.json', json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
echo "\nInventario guardado en scratch/theme_contracts_inventory.json\n";
