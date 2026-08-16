<?php
/**
 * Una entrega, una calificación.
 *
 * Tres reglas con un principio de fondo: **una nota con respaldo no se sustituye
 * por una sin respaldo**. La que sale de calificar una entrega se apoya en el
 * archivo que subió el estudiante; una tecleada en la grilla no se apoya en nada.
 *
 *   1. El estudiante entrega una vez (salvo que el docente devuelva el trabajo).
 *   2. El docente califica una vez.
 *   3. Devolver deshace la nota y permite volver a empezar, dejando rastro.
 *
 * Crea su propia tarea y la borra al terminar.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'Una entrega, una calificación' );

global $wpdb;
$p = $wpdb->prefix . 'edu_';

$caso = $wpdb->get_row(
	"SELECT c.id AS component_id, c.subject_id, c.trimester_id, c.parcial_num,
	        s.id AS student_id, s.user_id, s.grade_id, su.institution_id
	 FROM {$p}grade_components c
	 JOIN {$p}subjects su ON su.id = c.subject_id
	 JOIN {$p}grades g ON g.institution_id = su.institution_id
	 JOIN {$p}students s ON s.grade_id = g.id AND s.status = 'active' AND s.user_id > 0
	 LEFT JOIN {$p}parcial_scores ps ON ps.student_id = s.id AND ps.subject_id = c.subject_id
	      AND ps.trimester_id = c.trimester_id AND ps.parcial_num = c.parcial_num
	 WHERE COALESCE(ps.is_closed, 0) = 0
	 LIMIT 1"
);

if ( ! $caso ) {
	edu_test_skip( 'no hay un estudiante con usuario en un parcial abierto' );
}

$teacher_id = (int) $wpdb->get_var( "SELECT id FROM {$p}teachers LIMIT 1" );

$wpdb->insert(
	$p . 'assignments',
	array(
		'teacher_id'   => $teacher_id,
		'grade_id'     => (int) $caso->grade_id,
		'subject_id'   => (int) $caso->subject_id,
		'trimester_id' => (int) $caso->trimester_id,
		'parcial_num'  => (int) $caso->parcial_num,
		'component_id' => (int) $caso->component_id,
		'type'         => 'tarea',
		'title'        => 'PRUEBA AUTOMATICA — se borra sola',
		'max_score'    => 10.00,
		'status'       => 'published',
	)
);
$tarea_id = (int) $wpdb->insert_id;

edu_test_cleanup(
	function () use ( $wpdb, $p, $tarea_id, $caso ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}grades_log WHERE assignment_id = %d", $tarea_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}submissions WHERE assignment_id = %d", $tarea_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}assignments WHERE id = %d", $tarea_id ) );
		Edu_Grade_Calculator::recalculate_parcial( (int) $caso->student_id, (int) $caso->subject_id, (int) $caso->trimester_id, (int) $caso->parcial_num );
	}
);

/* ── 1. El estudiante entrega una vez ────────────────────────────────────── */

edu_test_as( (int) $caso->user_id, $caso->institution_id );

$entregar = fn( $comentario ) => Edu_Submission_Service::submit(
	array( 'assignment_id' => $tarea_id, 'comment' => $comentario, 'files' => array() )
);

edu_assert_ok( 'la primera entrega se acepta', $entregar( 'primera' ) );
edu_assert_error( 'la segunda entrega se rechaza', $entregar( 'segunda' ), 'already_submitted' );

$sub_id = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT id FROM {$p}submissions WHERE assignment_id = %d AND student_id = %d", $tarea_id, $caso->student_id )
);

/* ── 2. El docente califica una vez ──────────────────────────────────────── */

edu_test_as( edu_test_admin_id(), $caso->institution_id );

$calificar = fn( $nota ) => Edu_Submission_Service::grade(
	array( 'submission_id' => $sub_id, 'score' => $nota, 'feedback' => '' )
);

edu_assert_ok( 'la primera calificación se acepta', $calificar( 8.00 ) );
edu_assert_error( 'recalificar se rechaza', $calificar( 10.00 ), 'already_graded' );

/* ── 3. La celda queda bloqueada en la grilla ────────────────────────────── */

$gb = Edu_Gradebook_Service::gradebook(
	array(
		'grade_id'     => (int) $caso->grade_id,
		'subject_id'   => (int) $caso->subject_id,
		'trimester_id' => (int) $caso->trimester_id,
		'parcial_num'  => (int) $caso->parcial_num,
	)
);

$fila = null;
if ( ! is_wp_error( $gb ) ) {
	foreach ( $gb['students'] as $e ) {
		if ( (int) $e['student_id'] === (int) $caso->student_id ) {
			$fila = $e;
		}
	}
}

edu_assert(
	'el gradebook marca la celda como bloqueada',
	$fila && ! empty( $fila['score_locked'][ (string) $caso->component_id ] )
);

/* ── 4. Devolver el trabajo deshace la nota ──────────────────────────────── */

$antes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}grades_log WHERE assignment_id = %d", $tarea_id ) );

$dev = Edu_Submission_Service::return_to_student(
	array( 'submission_id' => $sub_id, 'comment' => 'revisa la página 3' )
);

$estado  = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}submissions WHERE id = %d", $sub_id ) );
$despues = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}grades_log WHERE assignment_id = %d", $tarea_id ) );

edu_assert( 'devolver deja la entrega en returned', ! is_wp_error( $dev ) && 'returned' === $estado, (string) $estado );
edu_assert( 'la nota deja de contar en el promedio', 1 === $antes && 0 === $despues, "antes=$antes despues=$despues" );

/* ── 5. Tras devolver, el circuito vuelve a abrirse ──────────────────────── */

edu_test_as( (int) $caso->user_id, $caso->institution_id );
edu_assert_ok( 'tras devolver, el estudiante puede reenviar', $entregar( 'corregida' ) );

edu_test_as( edu_test_admin_id(), $caso->institution_id );
edu_assert_ok( 'y el docente vuelve a calificar', $calificar( 9.00 ) );

edu_test_finish();
