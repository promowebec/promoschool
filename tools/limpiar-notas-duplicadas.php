<?php
/**
 * Limpia las notas manuales duplicadas de wp_edu_grades_log.
 *
 * POR QUÉ EXISTE
 * La grilla de calificaciones tiene un input por componente, pero hasta la
 * corrección de agosto de 2026 cada guardado hacía INSERT en vez de reemplazar.
 * Guardar dos veces dejaba dos filas; corregir un 6.00 a 8.00 dejaba al
 * estudiante con 7.00, el promedio de ambas. Este script arregla lo ya escrito;
 * el INSERT ya está corregido en Edu_Score_Service.
 *
 * QUÉ HACE
 * Por cada (estudiante, componente) con más de una nota MANUAL conserva la más
 * reciente y borra las anteriores. Las notas que vienen de una tarea
 * (assignment_id NO NULL) no se tocan nunca: varias tareas en un mismo
 * componente deben seguir promediándose, que es el modelo académico.
 *
 * USO
 *   php tools/limpiar-notas-duplicadas.php              → simula, no borra nada
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

$sobrantes   = 0;
$con_cambio  = 0;
$a_recalcular = array();

echo "Celdas afectadas: " . count( $celdas ) . "\n\n";

foreach ( $celdas as $c ) {
	// La fila que se conserva: la ultima registrada.
	$filas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, score, registered_at FROM {$tl}
			 WHERE student_id = %d AND component_id = %d AND assignment_id IS NULL
			 ORDER BY registered_at DESC, id DESC",
			$c->student_id,
			$c->component_id
		)
	);

	$conservar = array_shift( $filas );
	$borrar    = wp_list_pluck( $filas, 'id' );

	$sobrantes += count( $borrar );

	/*
	 * Solo importa avisar cuando la nota que ve el estudiante cambia de verdad.
	 * Con filas duplicadas identicas el promedio ya coincide con la que se
	 * conserva, asi que limpiarlas no altera ninguna calificacion.
	 */
	$avg_actual = round(
		(float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(score) FROM {$tl} WHERE student_id = %d AND component_id = %d",
				$c->student_id,
				$c->component_id
			)
		),
		2
	);

	// Promedio que quedara: la fila conservada mas las notas de tareas.
	$avg_futuro = round(
		(float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(score) FROM {$tl}
				 WHERE student_id = %d AND component_id = %d
				   AND (assignment_id IS NOT NULL OR id = %d)",
				$c->student_id,
				$c->component_id,
				$conservar->id
			)
		),
		2
	);

	if ( abs( $avg_actual - $avg_futuro ) >= 0.005 ) {
		$con_cambio++;
		printf(
			"  CAMBIA LA NOTA · estudiante %d · componente %d: %.2f → %.2f (%d filas, %d valores distintos)\n",
			$c->student_id,
			$c->component_id,
			$avg_actual,
			$avg_futuro,
			(int) $c->n,
			(int) $c->distintas
		);
	}

	if ( $aplicar && $borrar ) {
		$ids = implode( ',', array_map( 'intval', $borrar ) );
		$wpdb->query( "DELETE FROM {$tl} WHERE id IN ($ids)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs pasados por intval.

		$a_recalcular[ (int) $c->student_id ][ (int) $c->component_id ] = true;
	}
}

printf( "\nFilas sobrantes: %d\n", $sobrantes );
printf( "Celdas donde la nota vigente CAMBIA: %d\n", $con_cambio );

if ( ! $aplicar ) {
	echo "\n";
	echo $con_cambio
		? "Simulacion terminada. Revisa arriba las lineas 'CAMBIA LA NOTA': son\ncalificaciones de estudiantes reales que van a variar.\n"
		: "Simulacion terminada. Ninguna calificacion cambia: las filas sobrantes son\nduplicados identicos, asi que limpiarlas no altera ninguna nota.\n";
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
		'celdas_afectadas'    => count( $celdas ),
		'celdas_con_cambio'   => $con_cambio,
		'parciales_recalculados' => $recalculados,
	)
);

printf( "Parciales recalculados: %d\n", $recalculados );
echo "Listo. Queda registro en wp_edu_audit.\n";
