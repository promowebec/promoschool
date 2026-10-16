<?php
/**
 * Parciales sin ponderación (1.17.0).
 *
 * El parcial es el promedio simple de TODAS sus notas: cada nota vale igual,
 * sin pesos ni promedio previo por componente. El 70% sigue siendo (P1+P2)/2.
 *
 * Caso: Tareas (peso 1.00) = 10, 8, 9 y Prueba (peso 2.00) = 6.
 *   Ahora:        (10 + 8 + 9 + 6) / 4          = 8.25
 *   Hasta 1.16.0: (9×1 + 6×2) / 3               = 7.00
 *
 * También prueba el recálculo único de las notas abiertas al actualizar: los
 * parciales cerrados y los de trimestres cerrados no se tocan.
 *
 * Crea su propio colegio y lo borra al terminar, y deja la opción del
 * recálculo como estaba.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$p = $wpdb->prefix . 'edu_';

$admin = edu_test_admin_id();
if ( ! $admin ) {
	edu_test_skip( 'no hay administrador' );
}

/* ── Fixture ─────────────────────────────────────────────────────────────── */

$creado = array( 'users' => array(), 'students' => array(), 'audit' => array() );

$opcion_original = get_option( Edu_Grade_Calculator::OPCION_RECALCULO_SIN_PESOS, null );

$sufijo = wp_generate_password( 6, false );

$wpdb->insert( $p . 'institutions', array( 'name' => 'Colegio Sin Pesos ' . $sufijo, 'regime' => 'sierra' ) );
$inst = (int) $wpdb->insert_id;

$wpdb->insert( $p . 'periods', array(
	'institution_id' => $inst,
	'name'           => '2026-2027',
	'regime'         => 'sierra',
	'start_date'     => '2026-09-01',
	'end_date'       => '2027-07-01',
	'is_active'      => 1,
) );
$period = (int) $wpdb->insert_id;

$wpdb->insert( $p . 'trimesters', array( 'period_id' => $period, 'number' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-12-01' ) );
$tri = (int) $wpdb->insert_id;

$wpdb->insert( $p . 'subjects', array( 'institution_id' => $inst, 'name' => 'Lengua ' . $sufijo ) );
$mat = (int) $wpdb->insert_id;

$wpdb->insert( $p . 'grades', array( 'institution_id' => $inst, 'level' => 'basica', 'sub_level' => 'elemental', 'name' => 'Elemental', 'paralelo' => 'A' ) );
$grado = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'grade_subjects', array( 'grade_id' => $grado, 'subject_id' => $mat ) );

edu_test_cleanup(
	function () use ( $wpdb, $p, $inst, $period, $tri, $mat, $grado, $opcion_original, &$creado ) {
		$st = implode( ',', array_map( 'intval', $creado['students'] ? $creado['students'] : array( 0 ) ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- listas de enteros.
		$wpdb->query( "DELETE FROM {$p}grades_log WHERE student_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}parcial_scores WHERE student_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}trimester_scores WHERE student_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}students WHERE id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}audit WHERE id IN (" . implode( ',', array_map( 'intval', $creado['audit'] ? $creado['audit'] : array( 0 ) ) ) . ')' );
		// phpcs:enable
		$wpdb->delete( $p . 'grade_components', array( 'trimester_id' => $tri ) );
		$wpdb->delete( $p . 'grade_subjects', array( 'grade_id' => $grado ) );
		$wpdb->delete( $p . 'grades', array( 'id' => $grado ) );
		$wpdb->delete( $p . 'subjects', array( 'id' => $mat ) );
		$wpdb->delete( $p . 'trimesters', array( 'id' => $tri ) );
		$wpdb->delete( $p . 'periods', array( 'id' => $period ) );
		$wpdb->delete( $p . 'institutions', array( 'id' => $inst ) );
		foreach ( $creado['users'] as $uid ) {
			wp_delete_user( $uid );
		}
		if ( null === $opcion_original ) {
			delete_option( Edu_Grade_Calculator::OPCION_RECALCULO_SIN_PESOS );
		} else {
			update_option( Edu_Grade_Calculator::OPCION_RECALCULO_SIN_PESOS, $opcion_original, false );
		}
		delete_transient( 'edu_recalculo_sin_pesos_lock' );
	}
);

$estudiante = function ( $letra ) use ( $wpdb, $p, $sufijo, $grado, &$creado ) {
	$uid = wp_insert_user( array(
		'user_login' => 'sinpesos_' . $letra . '_' . $sufijo,
		'user_pass'  => wp_generate_password(),
		'user_email' => 'sinpesos_' . $letra . '_' . $sufijo . '@prueba.invalid',
		'role'       => 'edu_estudiante',
		'first_name' => 'Prueba',
		'last_name'  => 'Pesos',
	) );
	$creado['users'][] = $uid;
	$wpdb->insert( $p . 'students', array( 'user_id' => $uid, 'grade_id' => $grado, 'status' => 'active' ) );
	$id                   = (int) $wpdb->insert_id;
	$creado['students'][] = $id;
	return $id;
};

$componente = function ( $parcial, $nombre, $peso ) use ( $wpdb, $p, $mat, $tri ) {
	$wpdb->insert( $p . 'grade_components', array( 'subject_id' => $mat, 'trimester_id' => $tri, 'parcial_num' => $parcial, 'name' => $nombre, 'weight' => $peso ) );
	return (int) $wpdb->insert_id;
};

$nota = function ( $student, $component, $score ) use ( $wpdb, $p, $admin ) {
	$wpdb->insert( $p . 'grades_log', array( 'student_id' => $student, 'component_id' => $component, 'score' => $score, 'registered_by' => $admin ) );
};

$parcial = function ( $student, $num ) use ( $wpdb, $p, $mat, $tri ) {
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$p}parcial_scores WHERE student_id = %d AND subject_id = %d AND trimester_id = %d AND parcial_num = %d",
		$student, $mat, $tri, $num
	) );
};

$trimestre = function ( $student ) use ( $wpdb, $p, $mat, $tri ) {
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$p}trimester_scores WHERE student_id = %d AND subject_id = %d AND trimester_id = %d",
		$student, $mat, $tri
	) );
};

$tareas  = $componente( 1, 'Tareas', 1.00 );
$prueba  = $componente( 1, 'Prueba', 2.00 );
$leccion = $componente( 2, 'Lección', 1.00 );
$prueba2 = $componente( 2, 'Prueba', 0.50 );

// Las mismas notas para todos los estudiantes del caso.
$notas_del_caso = function ( $est ) use ( $nota, $tareas, $prueba, $leccion, $prueba2 ) {
	$nota( $est, $tareas, 10 );
	$nota( $est, $tareas, 8 );
	$nota( $est, $tareas, 9 );
	$nota( $est, $prueba, 6 );
	$nota( $est, $leccion, 7 );
	$nota( $est, $prueba2, 9 );
};

edu_test_as( $admin, $inst );

/* ── 1. El parcial es el promedio simple de todas sus notas ─────────────── */

edu_test_case( 'Parcial: cada nota vale igual' );

$est_x = $estudiante( 'x' );
$notas_del_caso( $est_x );

// El examen se guarda antes, como pasa en la práctica: la fila del trimestre ya existe.
$wpdb->insert( $p . 'trimester_scores', array( 'student_id' => $est_x, 'subject_id' => $mat, 'trimester_id' => $tri, 'final_exam_score' => 9 ) );

$p1 = Edu_Grade_Calculator::recalculate_parcial( $est_x, $mat, $tri, 1 );
$p2 = Edu_Grade_Calculator::recalculate_parcial( $est_x, $mat, $tri, 2 );

edu_assert( 'P1: (10+8+9+6)/4 = 8.25 (con pesos daba 7.00)', abs( $p1 - 8.25 ) < 0.001, (string) $p1 );
edu_assert( 'P2: (7+9)/2 = 8.00 (con pesos daba 7.67)', abs( $p2 - 8.00 ) < 0.001, (string) $p2 );
edu_assert( 'el parcial queda guardado', abs( (float) $parcial( $est_x, 1 )->computed_score - 8.25 ) < 0.001 );

$t = $trimestre( $est_x );
edu_assert( 'trimestre: ((8.25+8)/2)×0.70 + 9×0.30 = 8.3875 → 8.39', abs( (float) $t->computed_score - 8.39 ) < 0.001, $t->computed_score );
edu_assert( 'el trimestre guarda los dos parciales', abs( (float) $t->parcial1_score - 8.25 ) < 0.001 && abs( (float) $t->parcial2_score - 8.00 ) < 0.001 );

$vacio = Edu_Grade_Calculator::recalculate_parcial( $estudiante( 'v' ), $mat, $tri, 1 );
edu_assert( 'sin notas el parcial es 0, como antes', 0.0 === (float) $vacio );

/* ── 2. Guardar componentes sin peso conserva el que tenían ─────────────── */

edu_test_case( 'Componentes sin peso' );

$r = Edu_Curriculum_Service::save_components( array(
	'subject_id'   => $mat,
	'trimester_id' => $tri,
	'parcial_num'  => 1,
	'rows'         => array(
		array( 'id' => $tareas, 'name' => 'Tareas en casa' ),
		array( 'id' => $prueba, 'name' => 'Prueba' ),
		array( 'id' => 0, 'name' => 'Exposición' ),
	),
) );
edu_assert_ok( 'el formulario sin pesos se guarda', $r );
edu_assert( 'el nombre cambia', 'Tareas en casa' === $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$p}grade_components WHERE id = %d", $tareas ) ) );
edu_assert( 'el peso anterior se conserva', abs( (float) $wpdb->get_var( $wpdb->prepare( "SELECT weight FROM {$p}grade_components WHERE id = %d", $prueba ) ) - 2.00 ) < 0.001 );
edu_assert( 'el componente nuevo nace con 1.00', abs( (float) $wpdb->get_var( $wpdb->prepare( "SELECT weight FROM {$p}grade_components WHERE name = %s AND trimester_id = %d", 'Exposición', $tri ) ) - 1.00 ) < 0.001 );

/* ── 3. Recálculo único al actualizar ────────────────────────────────────── */

edu_test_case( 'Recálculo de las notas abiertas' );

// Y: parciales con los valores ponderados de antes. P1 abierto, P2 cerrado.
$est_y = $estudiante( 'y' );
$notas_del_caso( $est_y );
$wpdb->insert( $p . 'parcial_scores', array( 'student_id' => $est_y, 'subject_id' => $mat, 'trimester_id' => $tri, 'parcial_num' => 1, 'computed_score' => 7.00, 'is_closed' => 0 ) );
$id_y1 = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'parcial_scores', array( 'student_id' => $est_y, 'subject_id' => $mat, 'trimester_id' => $tri, 'parcial_num' => 2, 'computed_score' => 7.67, 'is_closed' => 1 ) );
$wpdb->insert( $p . 'trimester_scores', array( 'student_id' => $est_y, 'subject_id' => $mat, 'trimester_id' => $tri, 'parcial1_score' => 7.00, 'parcial2_score' => 7.67, 'final_exam_score' => 9, 'computed_score' => 7.80, 'is_closed' => 0 ) );

// Z: parcial abierto pero trimestre cerrado.
$est_z = $estudiante( 'z' );
$notas_del_caso( $est_z );
$wpdb->insert( $p . 'parcial_scores', array( 'student_id' => $est_z, 'subject_id' => $mat, 'trimester_id' => $tri, 'parcial_num' => 1, 'computed_score' => 7.00, 'is_closed' => 0 ) );
$wpdb->insert( $p . 'trimester_scores', array( 'student_id' => $est_z, 'subject_id' => $mat, 'trimester_id' => $tri, 'parcial1_score' => 7.00, 'parcial2_score' => 7.67, 'final_exam_score' => 9, 'computed_score' => 7.80, 'is_closed' => 1 ) );

// Solo se recorren las filas del fixture: el cursor arranca justo antes de ellas
// (las de X y la del estudiante sin notas ya están bien: se revisan sin cambio).
$primer_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(id) FROM {$p}parcial_scores WHERE student_id = %d", $est_x ) );
update_option( Edu_Grade_Calculator::OPCION_RECALCULO_SIN_PESOS, array( 'cursor' => $primer_id - 1, 'revisados' => 0, 'cambiados' => 0 ), false );
delete_transient( 'edu_recalculo_sin_pesos_lock' );

$antes_audit = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$p}audit" );
Edu_Grade_Calculator::maybe_recalcular_sin_pesos();

edu_assert( 'Y: el P1 abierto pasa de 7.00 a 8.25', abs( (float) $parcial( $est_y, 1 )->computed_score - 8.25 ) < 0.001, $parcial( $est_y, 1 )->computed_score );
edu_assert( 'Y: el P2 cerrado se queda en 7.67', abs( (float) $parcial( $est_y, 2 )->computed_score - 7.67 ) < 0.001, $parcial( $est_y, 2 )->computed_score );
edu_assert(
	'Y: trimestre ((8.25+7.67)/2)×0.70 + 9×0.30 = 8.272 → 8.27',
	abs( (float) $trimestre( $est_y )->computed_score - 8.27 ) < 0.001,
	$trimestre( $est_y )->computed_score
);
edu_assert( 'Z: con el trimestre cerrado el P1 no se toca', abs( (float) $parcial( $est_z, 1 )->computed_score - 7.00 ) < 0.001, $parcial( $est_z, 1 )->computed_score );
edu_assert( 'Z: el trimestre cerrado no cambia', abs( (float) $trimestre( $est_z )->computed_score - 7.80 ) < 0.001 );

edu_assert( 'el recálculo queda marcado como hecho', 'hecho' === get_option( Edu_Grade_Calculator::OPCION_RECALCULO_SIN_PESOS ) );

$audit = $wpdb->get_row( $wpdb->prepare(
	"SELECT id, new_value FROM {$p}audit WHERE id > %d AND action = %s ORDER BY id DESC LIMIT 1",
	$antes_audit,
	Edu_Audit::RECALCULO_SIN_PESOS
) );
if ( $audit ) {
	$creado['audit'][] = (int) $audit->id;
}
$resumen = $audit ? json_decode( $audit->new_value, true ) : array();
edu_assert( 'deja una entrada de auditoría', (bool) $audit );
edu_assert(
	'revisa 4 parciales (X1, X2, el vacío, Y1) y cambia 1',
	4 === ( $resumen['revisados'] ?? null ) && 1 === ( $resumen['cambiados'] ?? null ),
	wp_json_encode( $resumen )
);

// Una segunda visita no vuelve a recalcular.
$wpdb->update( $p . 'parcial_scores', array( 'computed_score' => 5.00 ), array( 'id' => $id_y1 ) );
Edu_Grade_Calculator::maybe_recalcular_sin_pesos();
edu_assert( 'hecho una vez, no se repite', abs( (float) $parcial( $est_y, 1 )->computed_score - 5.00 ) < 0.001 );

edu_test_finish();
