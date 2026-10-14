<?php
/**
 * Modo de calificación «solo trimestre» (aplicativo del Ministerio).
 *
 * Crea dos colegios propios y los borra al terminar:
 *   · Colegio A en modo trimestre, con un grado de Elemental y uno de Media.
 *   · Colegio B en el modo por defecto (parciales), para comprobar que no cambia.
 *
 * Caso de aportes: Tareas (peso 1.00) = 10 y Lecciones (peso 0.50) = 5, 5, 5.
 *   Por parciales:  (10×1 + 5×0.5) / 1.5 = 8.33
 *   Solo trimestre: (10 + 5 + 5 + 5) / 4  = 6.25   ← pesos ignorados
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

$creado = array( 'users' => array(), 'students' => array(), 'subjects' => array(), 'trims' => array(), 'grades' => array(), 'periods' => array(), 'insts' => array() );

edu_test_cleanup(
	function () use ( $wpdb, $p, &$creado ) {
		$in = function ( $ids ) {
			return implode( ',', array_map( 'intval', $ids ? $ids : array( 0 ) ) );
		};
		$st = $in( $creado['students'] );
		$su = $in( $creado['subjects'] );
		$tr = $in( $creado['trims'] );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- listas de enteros.
		$wpdb->query( "DELETE FROM {$p}grades_log WHERE student_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}parcial_scores WHERE student_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}trimester_scores WHERE student_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}year_scores WHERE student_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}grade_components WHERE trimester_id IN ($tr)" );
		$wpdb->query( "DELETE FROM {$p}audit WHERE entity_type IN ('student','grade_log','trimester_score','parcial_score') AND entity_id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}audit WHERE entity_type IN ('trimester_score','parcial_score','grade_component') AND entity_id IN ($su)" );
		$wpdb->query( "DELETE FROM {$p}students WHERE id IN ($st)" );
		$wpdb->query( "DELETE FROM {$p}grade_subjects WHERE subject_id IN ($su)" );
		$wpdb->query( "DELETE FROM {$p}subjects WHERE id IN ($su)" );
		$wpdb->query( "DELETE FROM {$p}trimesters WHERE id IN ($tr)" );
		$wpdb->query( "DELETE FROM {$p}grades WHERE id IN (" . $in( $creado['grades'] ) . ')' );
		$wpdb->query( "DELETE FROM {$p}periods WHERE id IN (" . $in( $creado['periods'] ) . ')' );
		$wpdb->query( "DELETE FROM {$p}audit WHERE entity_type = 'institution' AND entity_id IN (" . $in( $creado['insts'] ) . ')' );
		$wpdb->query( "DELETE FROM {$p}institutions WHERE id IN (" . $in( $creado['insts'] ) . ')' );
		// phpcs:enable
		foreach ( $creado['insts'] as $id ) {
			delete_option( Edu_Service::OPCION_MODO . $id );
		}
		foreach ( $creado['users'] as $uid ) {
			wp_delete_user( $uid );
		}
	}
);

$sufijo = wp_generate_password( 6, false );

$colegio = function ( $nombre ) use ( $wpdb, $p, $sufijo, &$creado ) {
	$wpdb->insert( $p . 'institutions', array( 'name' => $nombre . ' ' . $sufijo, 'regime' => 'sierra' ) );
	$inst              = (int) $wpdb->insert_id;
	$creado['insts'][] = $inst;

	$wpdb->insert( $p . 'periods', array(
		'institution_id' => $inst,
		'name'           => '2026-2027',
		'regime'         => 'sierra',
		'start_date'     => '2026-09-01',
		'end_date'       => '2027-07-01',
		'is_active'      => 1,
	) );
	$period              = (int) $wpdb->insert_id;
	$creado['periods'][] = $period;

	$wpdb->insert( $p . 'trimesters', array( 'period_id' => $period, 'number' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-12-01' ) );
	$tri               = (int) $wpdb->insert_id;
	$creado['trims'][] = $tri;

	$wpdb->insert( $p . 'subjects', array( 'institution_id' => $inst, 'name' => 'Matemática ' . $sufijo ) );
	$subject              = (int) $wpdb->insert_id;
	$creado['subjects'][] = $subject;

	return array( $inst, $tri, $subject );
};

$grado = function ( $inst, $subject, $sub_level ) use ( $wpdb, $p, &$creado ) {
	$wpdb->insert( $p . 'grades', array( 'institution_id' => $inst, 'level' => 'basica', 'sub_level' => $sub_level, 'name' => $sub_level, 'paralelo' => 'A' ) );
	$g                  = (int) $wpdb->insert_id;
	$creado['grades'][] = $g;
	$wpdb->insert( $p . 'grade_subjects', array( 'grade_id' => $g, 'subject_id' => $subject ) );
	return $g;
};

$usuario = function ( $login, $rol ) use ( &$creado ) {
	$uid               = wp_insert_user( array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password(),
		'user_email' => $login . '@prueba.invalid',
		'role'       => $rol,
		'first_name' => 'Prueba',
		'last_name'  => 'Modo',
	) );
	$creado['users'][] = $uid;
	return $uid;
};

$estudiante = function ( $grade, $letra ) use ( $wpdb, $p, $sufijo, $usuario, &$creado ) {
	$uid = $usuario( 'modo_' . $letra . '_' . $sufijo, 'edu_estudiante' );
	$wpdb->insert( $p . 'students', array( 'user_id' => $uid, 'grade_id' => $grade, 'status' => 'active' ) );
	$id                   = (int) $wpdb->insert_id;
	$creado['students'][] = $id;
	return $id;
};

$componente = function ( $subject, $tri, $parcial, $nombre, $peso ) use ( $wpdb, $p ) {
	$wpdb->insert( $p . 'grade_components', array( 'subject_id' => $subject, 'trimester_id' => $tri, 'parcial_num' => $parcial, 'name' => $nombre, 'weight' => $peso ) );
	return (int) $wpdb->insert_id;
};

$nota = function ( $student, $component, $score ) use ( $wpdb, $p, $admin ) {
	$wpdb->insert( $p . 'grades_log', array( 'student_id' => $student, 'component_id' => $component, 'score' => $score, 'registered_by' => $admin ) );
};

list( $inst_a, $tri_a, $mat_a ) = $colegio( 'Colegio Modo Trimestre' );
list( $inst_b, $tri_b, $mat_b ) = $colegio( 'Colegio Modo Parciales' );

$elemental = $grado( $inst_a, $mat_a, 'elemental' );
$media     = $grado( $inst_a, $mat_a, 'media' );
$grado_b   = $grado( $inst_b, $mat_b, 'elemental' );

$est_e = $estudiante( $elemental, 'e' );
$est_m = $estudiante( $media, 'm' );
$est_b = $estudiante( $grado_b, 'b' );

/* ── 1. Cambio de modo ───────────────────────────────────────────────────── */

edu_test_case( 'Cambiar el modo de calificación' );

edu_test_as( $admin, $inst_a );
edu_assert( 'una institución nueva califica por parciales', Edu_Service::MODO_PARCIALES === Edu_Service::grading_mode( $inst_a ) );

edu_assert_error(
	'un modo inventado se rechaza',
	Edu_Trimester_Score_Service::set_grading_mode( array( 'institution_id' => $inst_a, 'modo' => 'mensual' ) ),
	'invalid_mode'
);

$docente = $usuario( 'modo_doc_' . $sufijo, 'edu_docente' );
edu_test_as( $docente, $inst_a );
edu_assert_error(
	'el docente no puede cambiarlo',
	Edu_Trimester_Score_Service::set_grading_mode( array( 'institution_id' => $inst_a, 'modo' => 'trimestre' ) ),
	'forbidden'
);

$rector_b = $usuario( 'modo_rec_' . $sufijo, 'edu_rector' );
update_user_meta( $rector_b, 'edu_institution_id', $inst_b );
edu_test_as( $rector_b, $inst_b );
edu_assert_error(
	'el rector de otro colegio no puede cambiarlo',
	Edu_Trimester_Score_Service::set_grading_mode( array( 'institution_id' => $inst_a, 'modo' => 'trimestre' ) ),
	'invalid_scope'
);

edu_test_as( $admin, $inst_a );
$r = Edu_Trimester_Score_Service::set_grading_mode( array( 'institution_id' => $inst_a, 'modo' => 'trimestre' ) );
edu_assert_ok( 'sin notas, el colegio A pasa a trimestre', $r );
edu_assert( 'el modo queda guardado', Edu_Service::MODO_TRIMESTRE === Edu_Service::grading_mode( $inst_a ) );
edu_assert( 'el colegio B sigue por parciales', Edu_Service::MODO_PARCIALES === Edu_Service::grading_mode( $inst_b ) );
edu_assert(
	'el cambio queda en la auditoría',
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}audit WHERE action = %s AND entity_id = %d", Edu_Audit::MODO_NOTAS, $inst_a ) ) === 1
);

// En B: un componente del segundo parcial impide pasar a trimestre.
$comp_b2 = $componente( $mat_b, $tri_b, 2, 'Prueba', 1 );
edu_test_as( $admin, $inst_b );
edu_assert_error(
	'con componentes en el parcial 2 no pasa a trimestre',
	Edu_Trimester_Score_Service::set_grading_mode( array( 'institution_id' => $inst_b, 'modo' => 'trimestre' ) ),
	'second_parcial_components'
);
$wpdb->delete( $p . 'grade_components', array( 'id' => $comp_b2 ) );

/* ── 2. Aportes: promedio simple, pesos ignorados ───────────────────────── */

edu_test_case( 'Aportes del trimestre: promedio simple' );

edu_test_as( $admin, $inst_a );

edu_assert_error( 'no existe el parcial 2', Edu_Service::validate_parcial( 2 ), 'invalid_parcial' );
edu_assert( 'el parcial 1 sigue siendo válido', true === Edu_Service::validate_parcial( 1 ) );
edu_assert( 'una tarea del parcial 2 cae en el bloque 1', 1 === Edu_Service::normalize_parcial( 2 ) );

$cid_nuevo = Edu_Curriculum_Service::resolve_or_create_component( 0, 'Exposición', 1, $mat_a, $tri_a, 2 );
edu_assert(
	'un componente pedido en el parcial 2 se crea en el 1',
	1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT parcial_num FROM {$p}grade_components WHERE id = %d", $cid_nuevo ) )
);
$wpdb->delete( $p . 'grade_components', array( 'id' => $cid_nuevo ) );

$tareas    = $componente( $mat_a, $tri_a, 1, 'Tareas', 1.00 );
$lecciones = $componente( $mat_a, $tri_a, 1, 'Lecciones', 0.50 );

foreach ( array( $est_e, $est_m ) as $sid ) {
	$nota( $sid, $lecciones, 5 );
	$nota( $sid, $lecciones, 5 );
	$nota( $sid, $lecciones, 5 );
}

// La nota de Tareas entra por la grilla, el camino real del docente.
$guardado = Edu_Score_Service::save_batch(
	array(
		'grade_id'     => $elemental,
		'subject_id'   => $mat_a,
		'trimester_id' => $tri_a,
		'parcial_num'  => 1,
		'scores'       => array( array( 'student_id' => $est_e, 'component_id' => $tareas, 'score' => '10' ) ),
	)
);
edu_assert_ok( 'la grilla guarda en modo trimestre', $guardado );
$nota( $est_m, $tareas, 10 );
Edu_Grade_Calculator::recalculate_parcial( $est_m, $mat_a, $tri_a, 1 );

$aportes = function ( $sid ) use ( $wpdb, $p, $mat_a, $tri_a ) {
	return (float) $wpdb->get_var( $wpdb->prepare( "SELECT computed_score FROM {$p}parcial_scores WHERE student_id = %d AND subject_id = %d AND trimester_id = %d AND parcial_num = 1", $sid, $mat_a, $tri_a ) );
};
$trimestre = function ( $sid ) use ( $wpdb, $p, $mat_a, $tri_a ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}trimester_scores WHERE student_id = %d AND subject_id = %d AND trimester_id = %d", $sid, $mat_a, $tri_a ) );
};

edu_assert( 'aportes 10,5,5,5 → 6.25 (no 8.33)', abs( $aportes( $est_e ) - 6.25 ) < 0.001, (string) $aportes( $est_e ) );

$t = $trimestre( $est_e );
edu_assert( 'el trimestre existe sin cerrar ningún parcial', (bool) $t );
edu_assert( 'sin examen: 6.25×0.70 = 4.375 → 4.38 (5 sube)', $t && abs( (float) $t->computed_score - 4.38 ) < 0.001, $t ? $t->computed_score : '' );

/* ── 3. Sumativa por subnivel ────────────────────────────────────────────── */

edu_test_case( 'Sumativa por subnivel' );

$r = Edu_Trimester_Score_Service::save_exam( array(
	'grade_id'     => $elemental,
	'subject_id'   => $mat_a,
	'trimester_id' => $tri_a,
	'students'     => array( array( 'student_id' => $est_e, 'exam' => '8', 'proyecto' => '' ) ),
) );
edu_assert_ok( 'examen de Elemental guardado', $r );
edu_assert( 'la respuesta informa el modo', is_array( $r ) && 'trimestre' === ( $r['modo'] ?? '' ) );
edu_assert( 'Elemental: 6.25×0.70 + 8×0.30 = 6.775 → 6.78', abs( (float) $trimestre( $est_e )->computed_score - 6.78 ) < 0.001, $trimestre( $est_e )->computed_score );

$r = Edu_Trimester_Score_Service::save_exam( array(
	'grade_id'     => $media,
	'subject_id'   => $mat_a,
	'trimester_id' => $tri_a,
	'students'     => array( array( 'student_id' => $est_m, 'exam' => '8', 'proyecto' => '6' ) ),
) );
edu_assert_ok( 'examen y proyecto de Media guardados', $r );
edu_assert( 'Media: 6.25×0.70 + ((8+6)/2)×0.30 = 6.475 → 6.48', abs( (float) $trimestre( $est_m )->computed_score - 6.48 ) < 0.001, $trimestre( $est_m )->computed_score );

// Una nota nueva después del examen refresca el trimestre: 10,5,5,5,10 → 7.00.
$nota( $est_e, $lecciones, 10 );
Edu_Grade_Calculator::recalculate_parcial( $est_e, $mat_a, $tri_a, 1 );
edu_assert( 'una nota nueva recalcula: 7×0.70 + 8×0.30 = 7.30', abs( (float) $trimestre( $est_e )->computed_score - 7.30 ) < 0.001, $trimestre( $est_e )->computed_score );

/* ── 4. Lecturas de la API ──────────────────────────────────────────────── */

edu_test_case( 'Las lecturas informan el modo' );

$gb = Edu_Gradebook_Service::gradebook( array( 'grade_id' => $elemental, 'subject_id' => $mat_a, 'trimester_id' => $tri_a, 'parcial_num' => 1 ) );
edu_assert( 'la grilla trae modo trimestre', is_array( $gb ) && 'trimestre' === $gb['context']['modo'] );
edu_assert( 'la grilla muestra los dos componentes', is_array( $gb ) && 2 === count( $gb['components'] ) );

$ts = Edu_Gradebook_Service::trimester_scores( array( 'grade_id' => $elemental, 'subject_id' => $mat_a, 'trimester_id' => $tri_a ) );
edu_assert( 'las notas de trimestre traen el modo', is_array( $ts ) && 'trimestre' === $ts['modo'] );

/* ── 5. Bloqueo y cierre ─────────────────────────────────────────────────── */

edu_test_case( 'Con notas el modo queda fijo; el cierre no pide parciales' );

edu_assert_error(
	'con notas ya no se vuelve a parciales',
	Edu_Trimester_Score_Service::set_grading_mode( array( 'institution_id' => $inst_a, 'modo' => 'parciales' ) ),
	'mode_locked'
);

$r = Edu_Trimester_Score_Service::close_trimester( array( 'grade_id' => $elemental, 'subject_id' => $mat_a, 'trimester_id' => $tri_a ) );
edu_assert_ok( 'el trimestre cierra sin cerrar parciales a mano', $r );
edu_assert( 'el bloque de aportes quedó cerrado', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_closed FROM {$p}parcial_scores WHERE student_id = %d AND subject_id = %d AND trimester_id = %d", $est_e, $mat_a, $tri_a ) ) );
edu_assert( 'el trimestre quedó cerrado', 1 === (int) $trimestre( $est_e )->is_closed );

$nota( $est_e, $lecciones, 0 );
Edu_Grade_Calculator::recalculate_parcial( $est_e, $mat_a, $tri_a, 1 );
edu_assert( 'cerrado, una nota tardía no lo recalcula', abs( (float) $trimestre( $est_e )->computed_score - 7.30 ) < 0.001, $trimestre( $est_e )->computed_score );

/* ── 6. El modo por parciales no cambia ─────────────────────────────────── */

edu_test_case( 'Por parciales: la fórmula de siempre' );

edu_assert( 'B: ((8+6)/2)×0.70 + 9×0.30 = 7.60', abs( Edu_Service::trimester_score( $grado_b, 8, 6, 9, 0 ) - 7.60 ) < 0.001 );
edu_assert( 'A: el P2 se ignora en modo trimestre', abs( Edu_Service::trimester_score( $elemental, 8, 6, 9, 0 ) - 8.30 ) < 0.001 );

edu_test_as( $admin, $inst_b );
edu_assert( 'B acepta el parcial 2', true === Edu_Service::validate_parcial( 2 ) );

$tareas_b    = $componente( $mat_b, $tri_b, 1, 'Tareas', 1.00 );
$lecciones_b = $componente( $mat_b, $tri_b, 1, 'Lecciones', 0.50 );
$nota( $est_b, $tareas_b, 10 );
$nota( $est_b, $lecciones_b, 5 );
$nota( $est_b, $lecciones_b, 5 );
$nota( $est_b, $lecciones_b, 5 );
Edu_Grade_Calculator::recalculate_parcial( $est_b, $mat_b, $tri_b, 1 );
$p1_b = (float) $wpdb->get_var( $wpdb->prepare( "SELECT computed_score FROM {$p}parcial_scores WHERE student_id = %d AND subject_id = %d AND trimester_id = %d AND parcial_num = 1", $est_b, $mat_b, $tri_b ) );
edu_assert( 'B: los mismos aportes dan 8.33 ponderado', abs( $p1_b - 8.33 ) < 0.001, (string) $p1_b );
edu_assert(
	'B: sin examen ni cierre no se crea el trimestre',
	null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}trimester_scores WHERE student_id = %d AND trimester_id = %d", $est_b, $tri_b ) )
);

edu_test_finish();
