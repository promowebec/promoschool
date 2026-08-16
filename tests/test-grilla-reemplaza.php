<?php
/**
 * La grilla de calificaciones REEMPLAZA la nota manual, no la acumula.
 *
 * Existe por un fallo que estuvo meses en producción: la grilla tiene un input
 * por componente pero cada guardado hacía INSERT, así que guardar dos veces
 * duplicaba la nota y —lo grave— corregir un 6.00 a 8.00 dejaba al estudiante
 * con 7.00, el promedio de ambas.
 *
 * También protege lo contrario: que al guardar la grilla NO se borren las notas
 * que vienen de una tarea. Varias tareas en un mismo componente se promedian, y
 * eso es el modelo académico, no un bug.
 *
 * Fotografía las filas del caso y las restaura al terminar.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'La grilla reemplaza la nota manual' );

global $wpdb;
$p  = $wpdb->prefix . 'edu_';
$tl = $p . 'grades_log';

$caso = edu_test_caso_abierto();

if ( ! $caso ) {
	edu_test_skip( 'no hay un parcial abierto' );
}

// Restaurar exactamente lo que había, pase lo que pase.
$original = $wpdb->get_results(
	$wpdb->prepare( "SELECT * FROM {$tl} WHERE student_id = %d AND component_id = %d", $caso->student_id, $caso->component_id ),
	ARRAY_A
);

edu_test_cleanup(
	function () use ( $wpdb, $tl, $original, $caso ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tl} WHERE student_id = %d AND component_id = %d", $caso->student_id, $caso->component_id ) );
		foreach ( $original as $fila ) {
			$wpdb->insert( $tl, $fila );
		}
		Edu_Grade_Calculator::recalculate_parcial( (int) $caso->student_id, (int) $caso->subject_id, (int) $caso->trimester_id, (int) $caso->parcial_num );
	}
);

edu_test_as( edu_test_admin_id(), $caso->institution_id );

$guardar = function ( $nota ) use ( $caso ) {
	return Edu_Score_Service::save_batch(
		array(
			'grade_id'     => (int) $caso->grade_id,
			'subject_id'   => (int) $caso->subject_id,
			'trimester_id' => (int) $caso->trimester_id,
			'parcial_num'  => (int) $caso->parcial_num,
			'scores'       => array(
				array(
					'student_id'   => (int) $caso->student_id,
					'component_id' => (int) $caso->component_id,
					'score'        => $nota,
				),
			),
		)
	);
};

$manuales = function () use ( $wpdb, $tl, $caso ) {
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$tl} WHERE student_id = %d AND component_id = %d AND assignment_id IS NULL", $caso->student_id, $caso->component_id )
	);
};

$vigente = function () use ( $wpdb, $tl, $caso ) {
	return round(
		(float) $wpdb->get_var(
			$wpdb->prepare( "SELECT AVG(score) FROM {$tl} WHERE student_id = %d AND component_id = %d", $caso->student_id, $caso->component_id )
		),
		2
	);
};

$r = $guardar( 6.00 );

if ( is_wp_error( $r ) ) {
	edu_test_skip( 'no se pudo guardar en el caso elegido: ' . $r->get_error_code() );
}

edu_assert( 'guardar deja una sola nota manual', 1 === $manuales(), $manuales() . ' filas' );

$guardar( 6.00 );
edu_assert( 'guardar el mismo valor no duplica', 1 === $manuales(), $manuales() . ' filas' );

$guardar( 8.00 );
edu_assert( 'corregir 6.00 → 8.00 deja 8.00, no 7.00', 8.00 === $vigente() && 1 === $manuales(), $vigente() . ' con ' . $manuales() . ' filas' );

/* ── Una nota de tarea sobrevive al guardado de la grilla ────────────────── */

$wpdb->insert(
	$tl,
	array(
		'student_id'    => (int) $caso->student_id,
		'component_id'  => (int) $caso->component_id,
		'assignment_id' => 999999, // Tarea inventada solo para esta prueba.
		'score'         => 10.00,
		'registered_by' => get_current_user_id(),
	),
	array( '%d', '%d', '%d', '%f', '%d' )
);

/*
 * Ahora la celda tiene respaldo, así que la grilla debe RECHAZARLA: una nota que
 * salió de calificar una entrega no se pisa tecleando.
 */
$r = $guardar( 5.00 );

$rechazada = ! is_wp_error( $r ) && 0 === $r['saved'] && ! empty( $r['errors'] );
edu_assert(
	'con respaldo, la grilla rechaza escribir',
	$rechazada && 'graded_from_assignment' === $r['errors'][0]['code'],
	$rechazada ? $r['errors'][0]['code'] : 'la acepto'
);

$de_tarea = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$tl} WHERE student_id = %d AND component_id = %d AND assignment_id = 999999", $caso->student_id, $caso->component_id )
);
edu_assert( 'la nota de la tarea sigue intacta', 1 === $de_tarea, "$de_tarea filas" );

edu_test_finish();
