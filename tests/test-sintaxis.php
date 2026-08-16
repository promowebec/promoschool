<?php
/**
 * Sintaxis de todo el PHP del plugin y de todo el JavaScript de la app.
 *
 * Las plantillas de la SPA se compilan con el MISMO Vue que se sirve en
 * producción: son cadenas que se compilan en runtime, así que un `<div>` sin
 * cerrar no lo detecta ningún linter de JavaScript — solo aparece cuando el
 * usuario abre esa pantalla y se le queda en blanco.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'Sintaxis' );

/* ── PHP ─────────────────────────────────────────────────────────────────── */

$php_files = array();
$iterador  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( EDU_PLUGIN_DIR, FilesystemIterator::SKIP_DOTS ) );

foreach ( $iterador as $f ) {
	$ruta = str_replace( '\\', '/', $f->getPathname() );

	// vendor/ es de terceros y node_modules ni existe aquí; no son nuestro código.
	if ( false !== strpos( $ruta, '/vendor/' ) || false !== strpos( $ruta, '/node_modules/' ) ) {
		continue;
	}

	if ( $f->isFile() && 'php' === strtolower( $f->getExtension() ) ) {
		$php_files[] = $ruta;
	}
}

$errores = array();
foreach ( $php_files as $archivo ) {
	$salida = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $archivo ) . ' 2>&1', $salida, $code );

	if ( 0 !== $code ) {
		$errores[] = str_replace( EDU_PLUGIN_DIR, '', $archivo ) . ': ' . trim( implode( ' ', $salida ) );
	}
}

edu_assert( count( $php_files ) . ' archivos PHP sin errores de sintaxis', ! $errores, implode( ' | ', $errores ) );

/* ── Plantillas de la SPA ────────────────────────────────────────────────── */

$node = trim( (string) shell_exec( 'node --version 2>&1' ) );

if ( ! $node || 0 !== strpos( $node, 'v' ) ) {
	echo "   (node no disponible: no se compilan las plantillas de la SPA)\n";
	edu_test_finish();
	return;
}

$salida = array();
exec(
	escapeshellarg( 'node' ) . ' ' . escapeshellarg( __DIR__ . '/compilar-plantillas.cjs' ) . ' 2>&1',
	$salida,
	$code
);

$resumen = '';
foreach ( $salida as $linea ) {
	if ( false !== strpos( $linea, 'plantillas compiladas' ) ) {
		$resumen = trim( $linea, "= \t" );
	}
	if ( 0 === strpos( $linea, 'FALLA' ) ) {
		echo '      ' . $linea . "\n";
	}
}

edu_assert( 'las plantillas de la SPA compilan (' . $node . ')', 0 === $code, $resumen );

edu_test_finish();
