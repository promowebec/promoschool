<?php
/**
 * Quién puede ver el desglose de las notas de un estudiante.
 *
 * Existe porque este endpoint salió a producción rechazando al estudiante y al
 * representante en TODAS sus materias: usaba `can_view_grade_subject()`, que
 * exige asignación docente. Se escapó porque la primera prueba corrió solo como
 * administrador.
 *
 * Moraleja versionada aquí: en este proyecto el nivel 3 —el alcance personal— es
 * donde han aparecido casi todos los agujeros. Un endpoint probado con un solo
 * perfil no está probado.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'Permisos del desglose por componente' );

global $wpdb;
$p = $wpdb->prefix . 'edu_';

// Hace falta un estudiante CON usuario de WordPress y con notas registradas.
$caso = $wpdb->get_row(
	"SELECT gl.student_id, c.subject_id, c.trimester_id, c.parcial_num, s.grade_id, s.user_id,
	        su.institution_id
	 FROM {$p}grades_log gl
	 JOIN {$p}grade_components c ON c.id = gl.component_id
	 JOIN {$p}students s ON s.id = gl.student_id
	 JOIN {$p}subjects su ON su.id = c.subject_id
	 WHERE s.user_id > 0
	 GROUP BY gl.student_id, c.subject_id, c.trimester_id, c.parcial_num, s.grade_id, s.user_id, su.institution_id
	 ORDER BY COUNT(*) DESC
	 LIMIT 1"
);

if ( ! $caso ) {
	edu_test_skip( 'no hay un estudiante con usuario y notas' );
}

$pedir = function () use ( $caso ) {
	return Edu_Gradebook_Service::component_breakdown(
		array(
			'student_id'   => (int) $caso->student_id,
			'subject_id'   => (int) $caso->subject_id,
			'trimester_id' => (int) $caso->trimester_id,
			'parcial_num'  => (int) $caso->parcial_num,
		)
	);
};

/* ── Quienes SÍ deben ver ────────────────────────────────────────────────── */

edu_test_as( edu_test_admin_id(), $caso->institution_id );
edu_assert_ok( 'el administrador ve el desglose', $pedir() );

edu_test_as( (int) $caso->user_id, $caso->institution_id );
edu_assert_ok( 'el propio estudiante ve sus notas', $pedir() );

$padre = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT pa.user_id FROM {$p}parent_student ps
		 JOIN {$p}parents pa ON pa.id = ps.parent_id
		 WHERE ps.student_id = %d AND pa.user_id > 0 LIMIT 1",
		$caso->student_id
	)
);

if ( $padre ) {
	edu_test_as( $padre, $caso->institution_id );
	edu_assert_ok( 'el representante ve las de su hijo', $pedir() );
}

$docente = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT t.user_id FROM {$p}teacher_assignments ta
		 JOIN {$p}teachers t ON t.id = ta.teacher_id
		 WHERE ta.subject_id = %d AND ta.grade_id = %d AND ta.is_active = 1 AND t.user_id > 0 LIMIT 1",
		$caso->subject_id,
		$caso->grade_id
	)
);

if ( $docente ) {
	edu_test_as( $docente, $caso->institution_id );
	edu_assert_ok( 'el docente de la materia las ve', $pedir() );
}

/* ── Quienes NO deben ver ────────────────────────────────────────────────── */

$docente_ajeno = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT t.user_id FROM {$p}teachers t
		 WHERE t.user_id > 0 AND t.id NOT IN (
			SELECT teacher_id FROM {$p}teacher_assignments
			WHERE subject_id = %d AND grade_id = %d AND is_active = 1
		 ) LIMIT 1",
		$caso->subject_id,
		$caso->grade_id
	)
);

if ( $docente_ajeno ) {
	edu_test_as( $docente_ajeno, $caso->institution_id );
	edu_assert_error( 'un docente que no dicta la materia NO', $pedir(), 'out_of_scope' );
}

$padre_ajeno = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT pa.user_id FROM {$p}parents pa
		 WHERE pa.user_id > 0 AND pa.id NOT IN (
			SELECT parent_id FROM {$p}parent_student WHERE student_id = %d
		 ) LIMIT 1",
		$caso->student_id
	)
);

if ( $padre_ajeno ) {
	edu_test_as( $padre_ajeno, $caso->institution_id );
	edu_assert_error( 'un representante ajeno NO', $pedir(), 'out_of_scope' );
}

/* ── El desglose cuadra con la grilla ────────────────────────────────────── */

edu_test_as( edu_test_admin_id(), $caso->institution_id );
$r = $pedir();

if ( ! is_wp_error( $r ) ) {
	$discrepan = array();

	foreach ( $r['components'] as $c ) {
		$avg = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(score) FROM {$p}grades_log WHERE student_id = %d AND component_id = %d",
				$caso->student_id,
				$c['component_id']
			)
		);
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}grades_log WHERE student_id = %d AND component_id = %d",
				$caso->student_id,
				$c['component_id']
			)
		);

		$esperado = null === $avg ? null : round( (float) $avg, 2 );
		$dado     = null === $c['average'] ? null : round( (float) $c['average'], 2 );

		if ( $esperado !== $dado || $n !== $c['count'] ) {
			$discrepan[] = $c['name'];
		}
	}

	edu_assert( 'promedios y conteos cuadran con la grilla', ! $discrepan, implode( ', ', $discrepan ) );
}

edu_test_finish();
