<?php
/**
 * La limpieza de notas duplicadas no puede alterar ninguna calificación.
 *
 * Existe porque la primera versión del script conservaba "la fila más reciente"
 * de cada celda. En desarrollo todos los duplicados eran idénticos y parecía
 * inofensivo; la simulación en producción destapó el patrón «12 filas, 6 valores
 * distintos» —seis notas legítimas duplicadas— y con esa regla un estudiante
 * habría pasado de 6.75 a 0.00.
 *
 * Fabrica los dos patrones sobre componentes SIN notas, para no mezclarse con
 * datos reales, y los borra al terminar.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'Limpieza de notas duplicadas' );

global $wpdb;
$p    = $wpdb->prefix . 'edu_';
$tl   = $p . 'grades_log';
$MARK = 987654; // registered_by ficticio: identifica las filas de esta prueba.

$wpdb->query( $wpdb->prepare( "DELETE FROM {$tl} WHERE registered_by = %d", $MARK ) );
edu_test_cleanup(
	function () use ( $wpdb, $tl, $MARK ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tl} WHERE registered_by = %d", $MARK ) );
	}
);

// Componentes vírgenes: si se usa uno con notas reales, el fixture se contamina
// y la prueba mide otra cosa (ya pasó una vez).
$comps = $wpdb->get_col(
	"SELECT c.id FROM {$p}grade_components c
	 LEFT JOIN {$tl} gl ON gl.component_id = c.id
	 WHERE gl.id IS NULL
	 GROUP BY c.id ORDER BY c.id LIMIT 2"
);
$est = $wpdb->get_col( "SELECT id FROM {$p}students WHERE status = 'active' ORDER BY id LIMIT 2" );

if ( count( $comps ) < 2 || count( $est ) < 2 ) {
	edu_test_skip( 'no hay dos componentes sin notas y dos estudiantes' );
}

$insertar = function ( $sid, $cid, $score ) use ( $wpdb, $tl, $MARK ) {
	$wpdb->insert(
		$tl,
		array( 'student_id' => $sid, 'component_id' => $cid, 'score' => $score, 'registered_by' => $MARK ),
		array( '%d', '%d', '%f', '%d' )
	);
};

$promedio = function ( $sid, $cid ) use ( $wpdb, $tl ) {
	return round( (float) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(score) FROM {$tl} WHERE student_id = %d AND component_id = %d", $sid, $cid ) ), 2 );
};

$filas = function ( $sid, $cid ) use ( $wpdb, $tl ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tl} WHERE student_id = %d AND component_id = %d", $sid, $cid ) );
};

// A) Seis notas legítimas, cada una duplicada. Limpiar NO debe mover el promedio.
foreach ( array( 5.00, 6.00, 7.00, 8.00, 9.00, 10.00 ) as $v ) {
	$insertar( $est[0], $comps[0], $v );
	$insertar( $est[0], $comps[0], $v );
}
$avg_a = $promedio( $est[0], $comps[0] );

// B) Una nota corregida: tres valores distintos, sin repetirse. No se toca.
foreach ( array( 4.00, 6.00, 9.00 ) as $v ) {
	$insertar( $est[1], $comps[1], $v );
}
$avg_b = $promedio( $est[1], $comps[1] );

// Ejecutar la limpieza de verdad, en su propio proceso como en el uso real.
$cmd = escapeshellarg( PHP_BINARY );
foreach ( array( 'mysqli', 'curl', 'mbstring', 'gd', 'openssl', 'exif' ) as $ext ) {
	if ( extension_loaded( $ext ) ) {
		$cmd .= ' -d ' . escapeshellarg( 'extension=' . $ext );
	}
}
$cmd .= ' -d ' . escapeshellarg( 'extension_dir=' . ini_get( 'extension_dir' ) );
$cmd .= ' ' . escapeshellarg( EDU_PLUGIN_DIR . 'tools/limpiar-notas-duplicadas.php' ) . ' --aplicar';

if ( defined( 'DB_HOST' ) ) {
	$cmd .= ' ' . escapeshellarg( '--db-host=' . DB_HOST );
}

exec( $cmd . ' 2>&1', $salida, $code );

edu_assert( 'el script corre sin error', 0 === $code, "codigo $code" );

edu_assert(
	'6 notas duplicadas quedan en 6 filas',
	6 === $filas( $est[0], $comps[0] ),
	$filas( $est[0], $comps[0] ) . ' filas'
);
edu_assert(
	'y el promedio no se mueve',
	$avg_a === $promedio( $est[0], $comps[0] ),
	$avg_a . ' → ' . $promedio( $est[0], $comps[0] )
);

edu_assert(
	'una nota corregida NO se toca',
	3 === $filas( $est[1], $comps[1] ),
	$filas( $est[1], $comps[1] ) . ' filas'
);
edu_assert(
	'y su promedio tampoco',
	$avg_b === $promedio( $est[1], $comps[1] ),
	$avg_b . ' → ' . $promedio( $est[1], $comps[1] )
);

edu_test_finish();
