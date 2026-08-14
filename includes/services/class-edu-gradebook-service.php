<?php
/**
 * Servicio de lectura: calificaciones.
 *
 * Matriz de notas, componentes, notas de trimestre, resumen anual y boleta del
 * estudiante. Todo con la equivalencia cualitativa ya calculada en el servidor:
 * la app no reimplementa la tabla del Instructivo 2025 (contrato §9.4).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Gradebook_Service {

	/* ─────────────────────────────────────────────────────────────────────
	 * Componentes evaluables
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Componentes de un (materia, trimestre, parcial).
	 *
	 * @param array $args subject_id, trimester_id, parcial_num.
	 * @return array|WP_Error
	 */
	public static function components( array $args ) {
		$subject_id   = (int) ( $args['subject_id'] ?? 0 );
		$trimester_id = (int) ( $args['trimester_id'] ?? 0 );
		$parcial_num  = (int) ( $args['parcial_num'] ?? 0 );

		$valid = Edu_Service::validate_parcial( $parcial_num );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$scope = Edu_Service::check_scope(
			array(
				'subject_id'   => $subject_id,
				'trimester_id' => $trimester_id,
			)
		);
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		if ( ! Edu_Service::sees_whole_institution() && ! Edu_Service::teacher_has_assignment( $subject_id ) ) {
			return Edu_Service::out_of_scope();
		}

		return self::component_rows( $subject_id, $trimester_id, $parcial_num );
	}

	/**
	 * Filas de componentes ya con la marca de quién puede editarlas.
	 *
	 * @return array
	 */
	private static function component_rows( $subject_id, $trimester_id, $parcial_num ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, weight, created_by
				 FROM {$wpdb->prefix}edu_grade_components
				 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d
				 ORDER BY id",
				(int) $subject_id,
				(int) $trimester_id,
				(int) $parcial_num
			)
		);

		$uid        = get_current_user_id();
		$puede_todo = Edu_Context::can( 'edu_manage_curriculum' );

		return array_map(
			static function ( $row ) use ( $uid, $puede_todo ) {
				$own = (int) $row->created_by === $uid && $uid > 0;

				return array(
					'id'         => (int) $row->id,
					'name'       => $row->name,
					'weight'     => Edu_Api::decimal( $row->weight ),
					'created_by' => (int) $row->created_by,
					'is_own'     => $own,
					'editable'   => $puede_todo || $own,
				);
			},
			(array) $rows
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Desglose de un componente
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Desglose de las notas que forman cada componente de un parcial.
	 *
	 * La celda de un componente es el PROMEDIO de sus filas de `grades_log`, así
	 * que sin este detalle nadie puede explicar de dónde sale ese número. Aquí
	 * se devuelven las filas una a una, con la tarea que las originó cuando la
	 * hay: las notas escritas desde la grilla dejan `assignment_id` en NULL —
	 * `Edu_Score_Service` no la rellena— y se marcan como `manual`.
	 *
	 * Solo lectura: no toca el cálculo ni los cierres.
	 *
	 * @param array $args student_id, subject_id, trimester_id, parcial_num.
	 * @return array|WP_Error
	 */
	public static function component_breakdown( array $args ) {
		$student_id   = (int) ( $args['student_id'] ?? 0 );
		$subject_id   = (int) ( $args['subject_id'] ?? 0 );
		$trimester_id = (int) ( $args['trimester_id'] ?? 0 );
		$parcial_num  = (int) ( $args['parcial_num'] ?? 0 );

		$valid = Edu_Service::validate_parcial( $parcial_num );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Nivel 3: el estudiante debe estar al alcance de quien pregunta.
		$allowed = Edu_Service::can_view_student( $student_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$grade_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT grade_id FROM {$p}students WHERE id = %d", $student_id )
		);

		if ( ! $grade_id ) {
			return Edu_Service::not_found( __( 'El estudiante no existe.', 'sistema-educativo' ) );
		}

		// Y la materia debe ser de su institución y del alcance del docente.
		$scope = Edu_Service::can_view_grade_subject( $grade_id, $subject_id );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$scope = Edu_Service::check_scope( array( 'trimester_id' => $trimester_id ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$componentes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, weight
				 FROM {$p}grade_components
				 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d
				 ORDER BY id",
				$subject_id,
				$trimester_id,
				$parcial_num
			)
		);

		if ( empty( $componentes ) ) {
			return array(
				'student_id'   => $student_id,
				'subject_id'   => $subject_id,
				'trimester_id' => $trimester_id,
				'parcial_num'  => $parcial_num,
				'components'   => array(),
			);
		}

		$cid_in = implode( ',', array_map( 'intval', wp_list_pluck( $componentes, 'id' ) ) );

		// Una sola consulta para todas las notas del parcial. El LEFT JOIN deja
		// pasar las filas sin tarea en vez de descartarlas.
		$notas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gl.id, gl.component_id, gl.score, gl.registered_at,
				        gl.assignment_id, a.title AS assignment_title, a.type AS assignment_type
				 FROM {$p}grades_log gl
				 LEFT JOIN {$p}assignments a ON a.id = gl.assignment_id
				 WHERE gl.student_id = %d AND gl.component_id IN ($cid_in)
				 ORDER BY gl.registered_at DESC, gl.id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs pasados por intval arriba.
				$student_id
			)
		);

		$por_componente = array();
		foreach ( (array) $notas as $n ) {
			$por_componente[ (int) $n->component_id ][] = array(
				'id'               => (int) $n->id,
				'score'            => Edu_Api::decimal( $n->score ),
				'registered_at'    => Edu_Api::date( $n->registered_at ),
				'origin'           => $n->assignment_id ? 'assignment' : 'manual',
				'assignment_id'    => $n->assignment_id ? (int) $n->assignment_id : null,
				'assignment_title' => $n->assignment_title,
				'assignment_type'  => $n->assignment_type,
			);
		}

		$salida = array();
		foreach ( (array) $componentes as $c ) {
			$entradas = $por_componente[ (int) $c->id ] ?? array();
			$suma     = 0.0;

			foreach ( $entradas as $e ) {
				$suma += (float) $e['score'];
			}

			$salida[] = array(
				'component_id' => (int) $c->id,
				'name'         => $c->name,
				'weight'       => Edu_Api::decimal( $c->weight ),
				'count'        => count( $entradas ),
				// El promedio se recalcula aquí sobre las mismas filas que se
				// listan, para que el número y su desglose no puedan discrepar.
				'average'      => $entradas ? Edu_Api::decimal( round( $suma / count( $entradas ), 2 ) ) : null,
				'entries'      => $entradas,
			);
		}

		return array(
			'student_id'   => $student_id,
			'subject_id'   => $subject_id,
			'trimester_id' => $trimester_id,
			'parcial_num'  => $parcial_num,
			'components'   => $salida,
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Matriz de notas
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Matriz estudiantes × componentes de un parcial (contrato §8.1).
	 *
	 * La nota de cada celda es el PROMEDIO de todas las filas de grades_log de
	 * ese (estudiante, componente), no la última: varias tareas pueden compartir
	 * un mismo componente.
	 *
	 * @param array $args grade_id, subject_id, trimester_id, parcial_num.
	 * @return array|WP_Error
	 */
	public static function gradebook( array $args ) {
		$grade_id     = (int) ( $args['grade_id'] ?? 0 );
		$subject_id   = (int) ( $args['subject_id'] ?? 0 );
		$trimester_id = (int) ( $args['trimester_id'] ?? 0 );
		$parcial_num  = (int) ( $args['parcial_num'] ?? 0 );

		$valid = Edu_Service::validate_parcial( $parcial_num );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$scope = Edu_Service::check_scope( array( 'trimester_id' => $trimester_id ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$allowed = Edu_Service::can_view_grade_subject( $grade_id, $subject_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$context = self::context( $grade_id, $subject_id, $trimester_id, $parcial_num );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$components    = self::component_rows( $subject_id, $trimester_id, $parcial_num );
		$component_ids = wp_list_pluck( $components, 'id' );

		$students = self::students_of_grade( $grade_id );

		// Notas promediadas por (estudiante, componente).
		$scores_map = array();
		$counts_map = array();
		if ( ! empty( $students ) && ! empty( $component_ids ) ) {
			$sid_in = implode( ',', array_map( 'intval', wp_list_pluck( $students, 'student_id' ) ) );
			$cid_in = implode( ',', array_map( 'intval', $component_ids ) );

			// El COUNT viaja junto al promedio para que la grilla pueda avisar de
			// cuántas notas hay detrás de cada celda sin una segunda consulta.
			$rows = $wpdb->get_results(
				"SELECT student_id, component_id, AVG(score) AS score, COUNT(*) AS n
				 FROM {$p}grades_log
				 WHERE student_id IN ($sid_in) AND component_id IN ($cid_in)
				 GROUP BY student_id, component_id" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			foreach ( (array) $rows as $row ) {
				$scores_map[ (int) $row->student_id ][ (int) $row->component_id ] = round( (float) $row->score, 2 );
				$counts_map[ (int) $row->student_id ][ (int) $row->component_id ] = (int) $row->n;
			}
		}

		// Nota del parcial ya calculada y estado de cierre.
		$parcial_map = array();
		if ( ! empty( $students ) ) {
			$sid_in = implode( ',', array_map( 'intval', wp_list_pluck( $students, 'student_id' ) ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT student_id, computed_score, is_closed
					 FROM {$p}parcial_scores
					 WHERE student_id IN ($sid_in) AND subject_id = %d AND trimester_id = %d AND parcial_num = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$subject_id,
					$trimester_id,
					$parcial_num
				)
			);

			foreach ( (array) $rows as $row ) {
				$parcial_map[ (int) $row->student_id ] = $row;
			}
		}

		$out_students = array();
		$closed_count = 0;

		foreach ( $students as $student ) {
			$sid = $student['student_id'];

			$cells  = array();
			$counts = array();
			foreach ( $component_ids as $cid ) {
				// null = sin calificar. El cálculo lo excluye y renormaliza;
				// no es lo mismo que un cero.
				$cells[ (string) $cid ]  = $scores_map[ $sid ][ $cid ] ?? null;
				$counts[ (string) $cid ] = $counts_map[ $sid ][ $cid ] ?? 0;
			}

			$row       = $parcial_map[ $sid ] ?? null;
			$computed  = $row ? round( (float) $row->computed_score, 2 ) : null;
			$is_closed = $row ? Edu_Api::boolean( $row->is_closed ) : false;

			if ( $is_closed ) {
				$closed_count++;
			}

			$out_students[] = array(
				'student_id'     => $sid,
				'nombres'        => $student['nombres'],
				'apellidos'      => $student['apellidos'],
				'scores'         => $cells,
				'score_counts'   => $counts,
				'computed_score' => $computed,
				'cualitativa'    => self::cualitativa( $computed ),
				'is_closed'      => $is_closed,
			);
		}

		$context['is_closed']       = ! empty( $students ) && count( $students ) === $closed_count;
		$context['closed_students'] = $closed_count;

		return array(
			'context'    => $context,
			'components' => $components,
			'students'   => $out_students,
		);
	}

	/**
	 * Contexto de la pantalla: grado, materia, trimestre y fórmula aplicable.
	 *
	 * @return array|WP_Error
	 */
	private static function context( $grade_id, $subject_id, $trimester_id, $parcial_num ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$grade = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, paralelo, sub_level, level FROM {$p}grades WHERE id = %d", $grade_id ) );
		if ( ! $grade ) {
			return Edu_Service::not_found( __( 'El grado no existe.', 'sistema-educativo' ) );
		}

		$subject = $wpdb->get_row( $wpdb->prepare( "SELECT id, name FROM {$p}subjects WHERE id = %d", $subject_id ) );
		if ( ! $subject ) {
			return Edu_Service::not_found( __( 'La materia no existe.', 'sistema-educativo' ) );
		}

		$trimester = $wpdb->get_row( $wpdb->prepare( "SELECT id, number, is_closed FROM {$p}trimesters WHERE id = %d", $trimester_id ) );
		if ( ! $trimester ) {
			return Edu_Service::not_found( __( 'El trimestre no existe.', 'sistema-educativo' ) );
		}

		return array(
			'grade'       => array(
				'id'        => (int) $grade->id,
				'name'      => $grade->name,
				'paralelo'  => $grade->paralelo,
				'level'     => $grade->level,
				'sub_level' => $grade->sub_level,
			),
			'subject'     => array(
				'id'   => (int) $subject->id,
				'name' => $subject->name,
			),
			'trimester'   => array(
				'id'        => (int) $trimester->id,
				'number'    => (int) $trimester->number,
				'is_closed' => Edu_Api::boolean( $trimester->is_closed ),
			),
			'parcial_num' => (int) $parcial_num,
			'formula'     => Edu_Service::formula( $grade_id ),
		);
	}

	/**
	 * Estudiantes activos de un grado, con nombre desde usermeta.
	 *
	 * @param int $grade_id Grado.
	 * @return array
	 */
	private static function students_of_grade( $grade_id ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id,
				        COALESCE(um_fn.meta_value, '') AS nombres,
				        COALESCE(um_ln.meta_value, u.display_name) AS apellidos
				 FROM {$p}students s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE s.grade_id = %d AND s.status = 'active'
				 ORDER BY apellidos, nombres",
				(int) $grade_id
			)
		);

		return array_map(
			static function ( $row ) {
				return array(
					'student_id' => (int) $row->id,
					'nombres'    => $row->nombres,
					'apellidos'  => $row->apellidos,
				);
			},
			(array) $rows
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Notas de trimestre
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Notas de trimestre de un grado y materia.
	 *
	 * @param array $args grade_id, subject_id, trimester_id.
	 * @return array|WP_Error
	 */
	public static function trimester_scores( array $args ) {
		$grade_id     = (int) ( $args['grade_id'] ?? 0 );
		$subject_id   = (int) ( $args['subject_id'] ?? 0 );
		$trimester_id = (int) ( $args['trimester_id'] ?? 0 );

		$scope = Edu_Service::check_scope( array( 'trimester_id' => $trimester_id ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$allowed = Edu_Service::can_view_grade_subject( $grade_id, $subject_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$students = self::students_of_grade( $grade_id );
		if ( empty( $students ) ) {
			return array(
				'formula' => Edu_Service::formula( $grade_id ),
				'items'   => array(),
			);
		}

		$sid_in = implode( ',', array_map( 'intval', wp_list_pluck( $students, 'student_id' ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}trimester_scores
				 WHERE student_id IN ($sid_in) AND subject_id = %d AND trimester_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$subject_id,
				$trimester_id
			)
		);

		$by_student = array();
		foreach ( (array) $rows as $row ) {
			$by_student[ (int) $row->student_id ] = $row;
		}

		$formula = Edu_Service::formula( $grade_id );
		$items   = array();

		foreach ( $students as $student ) {
			$row      = $by_student[ $student['student_id'] ] ?? null;
			$computed = $row ? round( (float) $row->computed_score, 2 ) : null;

			$items[] = array(
				'student_id'       => $student['student_id'],
				'nombres'          => $student['nombres'],
				'apellidos'        => $student['apellidos'],
				'subject_id'       => $subject_id,
				'trimester_id'     => $trimester_id,
				'parcial1_score'   => $row ? Edu_Api::decimal( $row->parcial1_score ) : null,
				'parcial2_score'   => $row ? Edu_Api::decimal( $row->parcial2_score ) : null,
				'final_exam_score' => $row ? Edu_Api::decimal( $row->final_exam_score ) : null,
				'proyecto_score'   => $row ? Edu_Api::decimal( $row->proyecto_score ) : null,
				'recovery_score'   => $row ? Edu_Api::decimal( $row->recovery_score ) : null,
				'computed_score'   => $computed,
				'cualitativa'      => self::cualitativa( $computed ),
				'is_closed'        => $row ? Edu_Api::boolean( $row->is_closed ) : false,
				'formula'          => $formula,
			);
		}

		return array(
			'formula' => $formula,
			'items'   => $items,
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Resumen anual
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Notas anuales de un grado en un período.
	 *
	 * @param array $args grade_id, period_id, subject_id (opcional).
	 * @return array|WP_Error
	 */
	public static function year_scores( array $args ) {
		$grade_id  = (int) ( $args['grade_id'] ?? 0 );
		$period_id = (int) ( $args['period_id'] ?? 0 );

		$scope = Edu_Service::check_scope( array( 'grade_id' => $grade_id ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		if ( ! Edu_Service::sees_whole_institution() ) {
			$identity = Edu_Service::identity();

			if ( ! $identity['teacher_id'] || ! in_array( $grade_id, Edu_Service::own_grade_ids(), true ) ) {
				return Edu_Service::out_of_scope();
			}
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$students = self::students_of_grade( $grade_id );
		if ( empty( $students ) ) {
			return array();
		}

		$sid_in = implode( ',', array_map( 'intval', wp_list_pluck( $students, 'student_id' ) ) );

		$sql    = "SELECT ys.*, s.name AS subject_name
		           FROM {$p}year_scores ys
		           INNER JOIN {$p}subjects s ON s.id = ys.subject_id
		           WHERE ys.student_id IN ($sid_in) AND ys.period_id = %d";
		$params = array( $period_id );

		if ( ! empty( $args['subject_id'] ) ) {
			$sql     .= ' AND ys.subject_id = %d';
			$params[] = (int) $args['subject_id'];
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$by_student = array();
		foreach ( (array) $rows as $row ) {
			$by_student[ (int) $row->student_id ][] = self::shape_year_score( $row );
		}

		$out = array();
		foreach ( $students as $student ) {
			$out[] = array(
				'student_id' => $student['student_id'],
				'nombres'    => $student['nombres'],
				'apellidos'  => $student['apellidos'],
				'subjects'   => $by_student[ $student['student_id'] ] ?? array(),
			);
		}

		return $out;
	}

	private static function shape_year_score( $row ) {
		$average = Edu_Api::decimal( $row->average );

		return array(
			'subject_id'       => (int) $row->subject_id,
			'subject_name'     => $row->subject_name,
			'period_id'        => (int) $row->period_id,
			'trim1'            => Edu_Api::decimal( $row->trim1 ),
			'trim2'            => Edu_Api::decimal( $row->trim2 ),
			'trim3'            => Edu_Api::decimal( $row->trim3 ),
			'average'          => $average,
			'supletorio_score' => Edu_Api::decimal( $row->supletorio_score ),
			'remedial_score'   => Edu_Api::decimal( $row->remedial_score ),
			'gracia_score'     => Edu_Api::decimal( $row->gracia_score ),
			'status'           => $row->status,
			'cualitativa'      => self::cualitativa( $average ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Boleta del estudiante
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Notas de un estudiante en un período: por materia, trimestre a trimestre.
	 *
	 * Es lo que necesitan el portal del estudiante y el del representante.
	 *
	 * @param int $student_id Estudiante.
	 * @param int $period_id  Período (0 = el activo).
	 * @return array|WP_Error
	 */
	public static function student_scores( $student_id, $period_id = 0 ) {
		$allowed = Edu_Service::can_view_student( $student_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		global $wpdb;
		$p          = $wpdb->prefix . 'edu_';
		$student_id = (int) $student_id;

		if ( ! $period_id ) {
			$period_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$p}periods WHERE institution_id = %d AND is_active = 1 ORDER BY id DESC LIMIT 1",
					Edu_Context::current_institution_id()
				)
			);
		}

		if ( ! $period_id ) {
			return Edu_Service::not_found( __( 'No hay un período lectivo activo.', 'sistema-educativo' ) );
		}

		$grade_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT grade_id FROM {$p}students WHERE id = %d", $student_id ) );
		$formula  = Edu_Service::formula( $grade_id );

		// Notas de trimestre del estudiante en ese período.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ts.*, t.number AS trimester_number, s.name AS subject_name
				 FROM {$p}trimester_scores ts
				 INNER JOIN {$p}trimesters t ON t.id = ts.trimester_id
				 INNER JOIN {$p}subjects s   ON s.id = ts.subject_id
				 WHERE ts.student_id = %d AND t.period_id = %d
				 ORDER BY s.name, t.number",
				$student_id,
				$period_id
			)
		);

		$subjects = array();
		foreach ( (array) $rows as $row ) {
			$sid = (int) $row->subject_id;

			if ( ! isset( $subjects[ $sid ] ) ) {
				$subjects[ $sid ] = array(
					'subject_id'   => $sid,
					'subject_name' => $row->subject_name,
					'trimesters'   => array(),
					'year'         => null,
				);
			}

			$computed = Edu_Api::decimal( $row->computed_score );

			$subjects[ $sid ]['trimesters'][] = array(
				'trimester_id'     => (int) $row->trimester_id,
				'number'           => (int) $row->trimester_number,
				'parcial1_score'   => Edu_Api::decimal( $row->parcial1_score ),
				'parcial2_score'   => Edu_Api::decimal( $row->parcial2_score ),
				'final_exam_score' => Edu_Api::decimal( $row->final_exam_score ),
				'proyecto_score'   => Edu_Api::decimal( $row->proyecto_score ),
				'computed_score'   => $computed,
				'cualitativa'      => self::cualitativa( $computed ),
				'is_closed'        => Edu_Api::boolean( $row->is_closed ),
			);
		}

		// Nota anual por materia.
		$year_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ys.*, s.name AS subject_name
				 FROM {$p}year_scores ys
				 INNER JOIN {$p}subjects s ON s.id = ys.subject_id
				 WHERE ys.student_id = %d AND ys.period_id = %d",
				$student_id,
				$period_id
			)
		);

		foreach ( (array) $year_rows as $row ) {
			$sid = (int) $row->subject_id;

			if ( ! isset( $subjects[ $sid ] ) ) {
				$subjects[ $sid ] = array(
					'subject_id'   => $sid,
					'subject_name' => $row->subject_name,
					'trimesters'   => array(),
					'year'         => null,
				);
			}

			$subjects[ $sid ]['year'] = self::shape_year_score( $row );
		}

		return array(
			'student_id' => $student_id,
			'period_id'  => $period_id,
			'grade_id'   => $grade_id,
			'formula'    => $formula,
			'subjects'   => array_values( $subjects ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Equivalencia cualitativa
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Código y color del Instructivo 2025 para una nota.
	 *
	 * Se calcula siempre en el servidor: si la escala cambia, cambia en un solo
	 * sitio y la app no queda desincronizada con el boletín PDF.
	 *
	 * @param float|null $score Nota 0–10.
	 * @return array|null
	 */
	public static function cualitativa( $score ) {
		if ( null === $score ) {
			return null;
		}

		$codigo = Edu_Qualitativa_Helper::codigo( (float) $score );

		return array(
			'codigo' => $codigo,
			'color'  => Edu_Qualitativa_Helper::color( $codigo ),
		);
	}
}
