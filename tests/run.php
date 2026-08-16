<?php
/**
 * Corre todas las pruebas y devuelve un resumen.
 *
 *   php tests/run.php
 *   php tests/run.php --db-host=127.0.0.1:10004     (entornos Local)
 *   php tests/run.php permisos                       (solo las que coincidan)
 *
 * Cada prueba corre en su PROPIO proceso: así un fatal en una no se lleva por
 * delante a las demás, y el estado global de WordPress no se contamina entre
 * pruebas que cambian de usuario.
 *
 * Código de salida: 0 si todo pasa, 1 si algo falla. Sirve para CI.
 *
 * @package SistemaEducativo
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "Solo por linea de comandos.\n" );
}

$dir     = __DIR__;
$db_host = '';
$filtro  = '';

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--db-host=' ) ) {
		$db_host = $arg;
	} elseif ( 0 !== strpos( $arg, '--' ) ) {
		$filtro = $arg;
	}
}

$archivos = glob( $dir . '/test-*.php' );
sort( $archivos );

if ( $filtro ) {
	$archivos = array_values(
		array_filter(
			$archivos,
			static function ( $f ) use ( $filtro ) {
				return false !== stripos( basename( $f ), $filtro );
			}
		)
	);
}

if ( ! $archivos ) {
	echo "No hay pruebas que coincidan.\n";
	exit( 1 );
}

/*
 * El PHP del PATH puede no traer las extensiones que hacen falta (en Windows con
 * Local, el php de la máquina es otro). Se reutiliza el binario que está
 * corriendo este script, que por definición sí sirve.
 */
$php = PHP_BINARY;

/*
 * ...pero el binario solo no basta: si a este proceso le pasaron las extensiones
 * por `-d` en vez de tenerlas en un php.ini, los hijos arrancan sin ellas y
 * WordPress muere con "missing the MySQL extension".
 *
 * Se comprueba con una sonda y, si hace falta, se les reponen las que este
 * proceso sí tiene cargadas.
 */
$flags = '';
exec( escapeshellarg( $php ) . ' -r "echo extension_loaded(\'mysqli\') ? 1 : 0;" 2>&1', $sonda );

if ( '1' !== trim( implode( '', $sonda ) ) ) {
	$necesarias = array( 'mysqli', 'curl', 'mbstring', 'gd', 'openssl', 'exif', 'zip' );
	$dir        = ini_get( 'extension_dir' );

	if ( $dir ) {
		$flags .= ' -d ' . escapeshellarg( 'extension_dir=' . $dir );
	}

	foreach ( $necesarias as $ext ) {
		if ( extension_loaded( $ext ) ) {
			$flags .= ' -d ' . escapeshellarg( 'extension=' . $ext );
		}
	}
}

echo "PromoSchool · pruebas\n";
echo str_repeat( '=', 62 ) . "\n";

$resultados = array();
$inicio     = microtime( true );

foreach ( $archivos as $archivo ) {
	$nombre = basename( $archivo, '.php' );
	$cmd    = escapeshellarg( $php ) . $flags . ' ' . escapeshellarg( $archivo );

	if ( $db_host ) {
		$cmd .= ' ' . escapeshellarg( $db_host );
	}

	$salida = array();
	$code   = 0;
	exec( $cmd . ' 2>&1', $salida, $code );

	foreach ( $salida as $linea ) {
		// wp-config redefine DB_HOST; el aviso es esperado y solo hace ruido.
		if ( false === strpos( $linea, 'Constant DB_HOST already defined' ) ) {
			echo $linea . "\n";
		}
	}

	$resultados[ $nombre ] = $code;
}

echo "\n" . str_repeat( '=', 62 ) . "\n";

$fallidas = array();
foreach ( $resultados as $nombre => $code ) {
	if ( 0 !== $code ) {
		$fallidas[] = $nombre . ( 1 === $code ? '' : " (error fatal, codigo $code)" );
	}
}

printf(
	"%d pruebas · %d ok · %d con fallas · %.1fs\n",
	count( $resultados ),
	count( $resultados ) - count( $fallidas ),
	count( $fallidas ),
	microtime( true ) - $inicio
);

if ( $fallidas ) {
	echo "\nCon fallas:\n";
	foreach ( $fallidas as $f ) {
		echo "  - $f\n";
	}
	exit( 1 );
}

echo "\nTodo en verde.\n";
exit( 0 );
