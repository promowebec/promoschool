<?php
/**
 * Limpia las notas manuales duplicadas de wp_edu_grades_log.
 *
 * POR QUÉ EXISTE
 * La grilla de calificaciones tiene un input por componente, pero hasta la
 * corrección de agosto de 2026 cada guardado hacía INSERT en vez de reemplazar,
 * así que quedaron filas repetidas. El INSERT ya está corregido en
 * Edu_Score_Service; esto arregla lo ya escrito.
 *
 * QUÉ HACE — y por qué es conservador
 * Borra SOLO copias exactas: filas con la misma nota dentro de la misma celda,
 * dejando una de cada valor. Y solo cuando el promedio de la celda **no cambia**.
 * Si una celda quedaría alterada, no se toca y se reporta para revisarla a mano.
 *
 * La primera versión conservaba "la fila más reciente" y eso estaba MAL. En un
 * entorno de prueba todos los duplicados eran idénticos y parecía inofensivo,
 * pero en producción apareció el patrón «12 filas, 6 valores distintos»: no es
 * un docente corrigiendo doce veces, son seis notas legítimas duplicadas. El
 * modelo académico dice que varias notas de un mismo componente se promedian,
 * así que quedarse con la última habría borrado notas reales — un estudiante
 * pasaba de 6.75 a 0.00.
 *
 * Colapsar (6,6,7,7) a (6,7) conserva el promedio 6.5. Colapsarlo a (7) no.
 *
 * Las notas que vienen de una tarea (assignment_id NO NULL) no se tocan nunca.
 *
 * USO
 *   php tools/limpiar-notas-duplicadas.php              → simula, no borra nada
 *   php tools/limpiar-notas-duplicadas.php --detalle    → vuelca fila a fila las
 *                                                         celdas dudosas
 *   php tools/limpiar-notas-duplicadas.php --aplicar    → borra de verdad
 *
 * En un entorno Local hay que apuntar la base a mano, porque wp-config trae un
 * host que no resuelve desde la linea de comandos:
 *   php tools/limpiar-notas-duplicadas.php --db-host=127.0.0.1:10004
 * En un servidor normal no hace falta.
 *
 * Recalcula los parciales afectados y deja constancia en wp_edu_audit.
 *
 * @package SistemaEducativo
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "Solo por linea de comandos.\n" );
}

$aplicar = in_array( '--aplicar', $argv, true );
$detalle = in_array( '--detalle', $argv, true );

// DB_HOST tiene que definirse ANTES de wp-load para que wp-config no gane.
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--db-host=' ) ) {
		define( 'DB_HOST', substr( $arg, 10 ) );
		break;
	}
}

// wp-load.php sube desde la raiz del sitio: plugin → plugins → wp-content → raiz.
$raiz = dirname( __DIR__, 4 );
require $raiz . '/wp-load.php';

global $wpdb;
$p  = $wpdb->prefix . 'edu_';
$tl = $p . 'grades_log';

echo $aplicar
	? "MODO REAL: se van a borrar filas.\n\n"
	: "SIMULACION: no se borra nada. Pasa --aplicar para ejecutarlo.\n\n";

// Celdas con mas de una nota manual.
$celdas = $wpdb->get_results(
	"SELECT student_id, component_id, COUNT(*) AS n, COUNT(DISTINCT score) AS distintas
	 FROM {$tl}
	 WHERE assignment_id IS NULL
	 GROUP BY student_id, component_id
	 HAVING n > 1
	 ORDER BY distintas DESC, n DESC"
);

if ( ! $celdas ) {
	echo "No hay notas manuales duplicadas. Nada que hacer.\n";
	return;
}

$sobrantes    = 0;
$celdas_ok    = 0;
$para_revisar = array();
$a_recalcular = array();

echo 'Celdas con notas manuales repetidas: ' . count( $celdas ) . "\n\n";

foreach ( $celdas as $c ) {
	$filas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, score, registered_at FROM {$tl}
			 WHERE student_id = %d AND component_id = %d AND assignment_id IS NULL
			 ORDER BY id",
			$c->student_id,
			$c->component_id
		)
	);

	/*
	 * Agrupar por valor y conservar UNA fila de cada uno. Asi (6,6,7,7) queda
	 * (6,7): desaparece la duplicacion y el promedio no se mueve. Quedarse solo
	 * con la ultima seria destruir notas legitimas.
	 */
	$vistos = array();
	$borrar = array();
	foreach ( $filas as $f ) {
		$clave = (string) round( (float) $f->score, 2 );
		if ( isset( $vistos[ $clave ] ) ) {
			$borrar[] = (int) $f->id;
		} else {
			$vistos[ $clave ] = true;
		}
	}

	if ( ! $borrar ) {
		continue; // Valores todos distintos: no hay nada duplicado que quitar.
	}

	// Promedio de la celda antes y despues, contando tambien las notas de tarea.
	$avg_actual = round(
		(float) $wpdb->get_var(
			$wpdb->prepare( "SELECT AVG(score) FROM {$tl} WHERE student_id = %d AND component_id = %d", $c->student_id, $c->component_id )
		),
		2
	);

	$ids_fuera  = implode( ',', $borrar );
	$avg_futuro = round(
		(float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(score) FROM {$tl}
				 WHERE student_id = %d AND component_id = %d AND id NOT IN ($ids_fuera)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs propios, ya enteros.
				$c->student_id,
				$c->component_id
			)
		),
		2
	);

	// Red de seguridad: si el promedio se moviera, NO se toca esa celda.
	if ( abs( $avg_actual - $avg_futuro ) >= 0.005 ) {
		$para_revisar[] = sprintf(
			'  estudiante %d · componente %d: %d filas, %d valores distintos (promedio %.2f)',
			$c->student_id,
			$c->component_id,
			(int) $c->n,
			(int) $c->distintas,
			$avg_actual
		);
		continue;
	}

	$sobrantes += count( $borrar );
	$celdas_ok++;

	if ( $aplicar ) {
		$wpdb->query( "DELETE FROM {$tl} WHERE id IN ($ids_fuera)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs propios, ya enteros.
		$a_recalcular[ (int) $c->student_id ][ (int) $c->component_id ] = true;
	}
}

printf( "Celdas que se limpian sin alterar ninguna nota: %d\n", $celdas_ok );
printf( "Filas duplicadas a borrar: %d\n", $sobrantes );

if ( $para_revisar ) {
	printf( "\nCeldas que NO se tocan (%d) — el promedio cambiaria:\n", count( $para_revisar ) );
	foreach ( $para_revisar as $linea ) {
		echo $linea . "\n";
	}
	echo "\n  Estas tienen varias notas DISTINTAS en el mismo componente. Puede ser\n";
	echo "  correcto (varias notas se promedian, es el modelo) o pueden ser\n";
	echo "  correcciones que quedaron promediadas con la nota equivocada. No hay\n";
	echo "  forma de saberlo desde aqui: revisalas con el desglose de la app y\n";
	echo "  corrigelas desde la grilla, que ahora reemplaza en vez de acumular.\n";
	echo "\n  Para verlas fila a fila:  --detalle\n";
}

/*
 * Volcado fila a fila de las celdas dudosas. Es lo que permite distinguir seis
 * notas legitimas duplicadas de una nota corregida varias veces: en el primer
 * caso los valores se repiten en pares, en el segundo hay una progresion.
 */
if ( $detalle && $para_revisar ) {
	echo "\n" . str_repeat( '─', 62 ) . "\n";
	echo "DETALLE DE LAS CELDAS QUE NO SE TOCAN\n";
	echo str_repeat( '─', 62 ) . "\n";

	foreach ( $celdas as $c ) {
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gl.id, gl.score, gl.registered_at, gl.assignment_id, a.title
				 FROM {$tl} gl
				 LEFT JOIN {$p}assignments a ON a.id = gl.assignment_id
				 WHERE gl.student_id = %d AND gl.component_id = %d
				 ORDER BY gl.registered_at, gl.id",
				$c->student_id,
				$c->component_id
			)
		);

		if ( count( $filas ) < 2 || (int) $c->distintas < 2 ) {
			continue;
		}

		$comp = $wpdb->get_row(
			$wpdb->prepare( "SELECT name, parcial_num FROM {$p}grade_components WHERE id = %d", $c->component_id )
		);

		printf(
			"\nEstudiante %d · componente %d (%s, parcial %s)\n",
			$c->student_id,
			$c->component_id,
			$comp ? $comp->name : '?',
			$comp ? $comp->parcial_num : '?'
		);

		foreach ( $filas as $f ) {
			printf(
				"   %5.2f   %s   %s\n",
				$f->score,
				substr( (string) $f->registered_at, 0, 16 ),
				$f->assignment_id ? 'tarea: ' . $f->title : '(manual)'
			);
		}
	}
	echo "\n";
}

if ( ! $aplicar ) {
	echo "\nSimulacion terminada. Ninguna calificacion cambia al aplicar: solo se\n";
	echo "quitan copias exactas, y toda celda cuyo promedio se moveria se omite.\n";
	return;
}

// Recalcular los parciales tocados.
require_once EDU_PLUGIN_DIR . 'modules/calificaciones/class-edu-grade-calculator.php';

$recalculados = 0;
foreach ( $a_recalcular as $sid => $componentes ) {
	foreach ( array_keys( $componentes ) as $cid ) {
		$comp = $wpdb->get_row(
			$wpdb->prepare( "SELECT subject_id, trimester_id, parcial_num FROM {$p}grade_components WHERE id = %d", $cid )
		);
		if ( ! $comp ) {
			continue;
		}

		Edu_Grade_Calculator::recalculate_parcial( $sid, (int) $comp->subject_id, (int) $comp->trimester_id, (int) $comp->parcial_num );
		$recalculados++;
	}
}

Edu_Audit::log(
	'notas_duplicadas_limpiadas',
	'nota',
	0,
	null,
	array(
		'filas_borradas'      => $sobrantes,
		'celdas_limpiadas'    => $celdas_ok,
		'celdas_omitidas'     => count( $para_revisar ),
		'parciales_recalculados' => $recalculados,
	)
);

printf( "Parciales recalculados: %d\n", $recalculados );
echo "Listo. Queda registro en wp_edu_audit.\n";
