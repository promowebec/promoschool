<?php
/**
 * Servicio: captura de notas por componente evaluable.
 *
 * Escribe en wp_edu_grades_log (log append-only: cada nota es una fila nueva)
 * y recalcula el parcial una sola vez por estudiante al terminar el lote.
 *
 * Regla de cálculo, delegada en Edu_Grade_Calculator:
 *   nota_parcial = Σ(nota_componente × peso) ÷ Σ(pesos con nota)
 * Los componentes sin nota se excluyen y los pesos se renormalizan, así que
 * NO es obligatorio que los pesos sumen 1.00.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Score_Service {

	/**
	 * Guarda un lote de notas.
	 *
	 * Una celda inválida no tumba el lote: se salta y se reporta en `errors`.
	 * Un docente que carga 35 estudiantes no puede perder el trabajo por un
	 * tipeo (contrato §8.2).
	 *
	 * @param array $input {
	 *     @type int   $grade_id
	 *     @type int   $subject_id
	 *     @type int   $trimester_id
	 *     @type int   $parcial_num
	 *     @type array $scores Lista de array( student_id, component_id, score ).
	 *                         score null o '' → se ignora esa celda.
	 * }
	 * @return array|WP_Error
	 */
	public static function save_batch( array $input ) {
		$cap = Edu_Service::require_cap( array( 'edu_grade_students', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution = Edu_Service::require_institution();
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		$grade_id     = isset( $input['grade_id'] ) ? (int) $input['grade_id'] : 0;
		$subject_id   = isset( $input['subject_id'] ) ? (int) $input['subject_id'] : 0;
		$trimester_id = isset( $input['trimester_id'] ) ? (int) $input['trimester_id'] : 0;
		$parcial_num  = isset( $input['parcial_num'] ) ? (int) $input['parcial_num'] : 0;

		$valid = Edu_Service::validate_parcial( $parcial_num );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$scope = Edu_Service::check_scope(
			array(
				'grade_id'     => $grade_id,
				'subject_id'   => $subject_id,
				'trimester_id' => $trimester_id,
			)
		);
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		global $wpdb;
		$tc = $wpdb->prefix . 'edu_grade_components';
		$tl = $wpdb->prefix . 'edu_grades_log';

		// Set válido de componentes del parcial.
		$component_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM $tc WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d",
					$subject_id,
					$trimester_id,
					$parcial_num
				)
			)
		);

		if ( empty( $component_ids ) ) {
			return Edu_Service::error(
				'no_components',
				__( 'Este parcial todavía no tiene componentes evaluables definidos.', 'sistema-educativo' ),
				422
			);
		}

		$student_ids = Edu_Service::active_student_ids( $grade_id );
		$closed      = self::closed_students( $student_ids, $subject_id, $trimester_id, $parcial_num );

		/*
		 * Celdas con respaldo: las que ya tienen una nota puesta al calificar una
		 * entrega. No se editan desde la grilla — esa nota se apoya en el archivo
		 * que subió el estudiante, y sustituirla tecleando rompería el vínculo sin
		 * dejar constancia de por qué cambió.
		 *
		 * Para cambiarla, el docente devuelve el trabajo o habilita la
		 * recuperación de la tarea; las dos vías quedan documentadas.
		 */
		$con_respaldo = array();
		if ( $student_ids && $component_ids ) {
			$sid_in = implode( ',', array_map( 'intval', $student_ids ) );
			$cid_in = implode( ',', array_map( 'intval', $component_ids ) );

			$filas = $wpdb->get_results(
				"SELECT DISTINCT student_id, component_id
				 FROM $tl
				 WHERE student_id IN ($sid_in) AND component_id IN ($cid_in)
				   AND assignment_id IS NOT NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs ya pasados por intval.
			);

			foreach ( (array) $filas as $f ) {
				$con_respaldo[ (int) $f->student_id . ':' . (int) $f->component_id ] = true;
			}
		}

		$registered_by = get_current_user_id();
		$saved         = 0;
		$replaced      = 0;
		$skipped       = 0;
		$errors        = array();
		$to_recalc     = array();

		foreach ( (array) ( $input['scores'] ?? array() ) as $cell ) {
			if ( ! is_array( $cell ) ) {
				continue;
			}

			$sid = isset( $cell['student_id'] ) ? (int) $cell['student_id'] : 0;
			$cid = isset( $cell['component_id'] ) ? (int) $cell['component_id'] : 0;

			if ( ! in_array( $sid, $student_ids, true ) ) {
				$skipped++;
				$errors[] = self::cell_error( $sid, $cid, 'invalid_student', __( 'El estudiante no pertenece a este grado o no está activo.', 'sistema-educativo' ) );
				continue;
			}

			if ( ! in_array( $cid, $component_ids, true ) ) {
				$skipped++;
				$errors[] = self::cell_error( $sid, $cid, 'invalid_component', __( 'El componente no pertenece a este parcial.', 'sistema-educativo' ) );
				continue;
			}

			// Un parcial cerrado no admite notas nuevas. El cierre es POR
			// ESTUDIANTE (wp_edu_parcial_scores.is_closed), así que se salta
			// solo a los cerrados en vez de rechazar el lote entero.
			if ( isset( $closed[ $sid ] ) ) {
				$skipped++;
				$errors[] = self::cell_error( $sid, $cid, 'partial_closed', __( 'El parcial de este estudiante ya está cerrado.', 'sistema-educativo' ) );
				continue;
			}

			if ( isset( $con_respaldo[ $sid . ':' . $cid ] ) ) {
				$skipped++;
				$errors[] = self::cell_error(
					$sid,
					$cid,
					'graded_from_assignment',
					__( 'Esta nota viene de una entrega calificada y no se edita desde la grilla. Para cambiarla, devuelve el trabajo al estudiante o habilita la recuperación de la tarea.', 'sistema-educativo' )
				);
				continue;
			}

			$score = Edu_Service::parse_score( $cell['score'] ?? null );

			if ( null === $score ) {
				$skipped++; // Celda vacía: no se toca la nota anterior.
				continue;
			}

			if ( $score < 0 || $score > 10 ) {
				$skipped++;
				$errors[] = self::cell_error( $sid, $cid, 'out_of_range', __( 'La nota debe estar entre 0 y 10.', 'sistema-educativo' ) );
				continue;
			}

			/*
			 * La grilla tiene UN input por componente, así que lo que se
			 * escribe ahí reemplaza a lo que había, no se suma.
			 *
			 * Sin esto, cada guardado insertaba una fila más: guardar dos veces
			 * duplicaba la nota, y —lo grave— corregir un 6.00 a 8.00 dejaba al
			 * estudiante con 7.00, el promedio de las dos. El camino de las
			 * tareas ya lo resolvía así (`Edu_Submission_Service`).
			 *
			 * Se borran SOLO las notas manuales (assignment_id IS NULL). Las que
			 * vienen de una tarea son de otro origen y varias tareas en un mismo
			 * componente deben seguir promediándose, que es el modelo.
			 */
			$reemplazadas = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $tl WHERE student_id = %d AND component_id = %d AND assignment_id IS NULL",
					$sid,
					$cid
				)
			);

			if ( $reemplazadas > 0 ) {
				$replaced += $reemplazadas;
			}

			$wpdb->insert(
				$tl,
				array(
					'student_id'    => $sid,
					'component_id'  => $cid,
					'score'         => $score,
					'registered_by' => $registered_by,
				),
				array( '%d', '%d', '%f', '%d' )
			);

			$saved++;
			$to_recalc[ $sid ] = true;

			/**
			 * Nota individual registrada.
			 *
			 * El recálculo del parcial se hace en bloque al final para evitar
			 * N recálculos por estudiante.
			 */
			do_action( 'edu_grade_logged', $sid, $cid, $score );
		}

		// Recalcular el parcial una vez por estudiante.
		require_once EDU_PLUGIN_DIR . 'modules/calificaciones/class-edu-grade-calculator.php';

		$recalculated = array();
		foreach ( array_keys( $to_recalc ) as $sid ) {
			Edu_Grade_Calculator::recalculate_parcial( $sid, $subject_id, $trimester_id, $parcial_num );
			$recalculated[] = array(
				'student_id'     => $sid,
				'computed_score' => self::parcial_score( $sid, $subject_id, $trimester_id, $parcial_num ),
			);
		}

		if ( $saved > 0 ) {
			Edu_Audit::log(
				Edu_Audit::NOTA_INGRESADA,
				'nota',
				$subject_id,
				null,
				array(
					'grado_id'        => $grade_id,
					'materia_id'      => $subject_id,
					'trimestre_id'    => $trimester_id,
					'parcial'         => $parcial_num,
					'notas_guardadas' => $saved,
					// Cuántas notas manuales previas se reemplazaron. Queda en
					// la auditoría porque es una eliminación de notas.
					'notas_sustituidas' => $replaced,
				)
			);
		}

		return array(
			'saved'        => $saved,
			'replaced'     => $replaced,
			'skipped'      => $skipped,
			'errors'       => $errors,
			'recalculated' => $recalculated,
		);
	}

	/**
	 * Convierte la matriz anidada de los formularios de admin
	 * (scores[student_id][component_id] = valor) a la lista de celdas que
	 * espera save_batch().
	 *
	 * @param array $matrix Matriz cruda.
	 * @return array
	 */
	public static function flatten_matrix( array $matrix ) {
		$cells = array();

		foreach ( $matrix as $student_id => $components ) {
			if ( ! is_array( $components ) ) {
				continue;
			}
			foreach ( $components as $component_id => $value ) {
				$cells[] = array(
					'student_id'   => (int) $student_id,
					'component_id' => (int) $component_id,
					'score'        => $value,
				);
			}
		}

		return $cells;
	}

	/* ─── Internos ──────────────────────────────────────────────────────── */

	/**
	 * Estudiantes con el parcial ya cerrado, indexados por ID.
	 *
	 * @param int[] $student_ids  Estudiantes del grado.
	 * @param int   $subject_id   Materia.
	 * @param int   $trimester_id Trimestre.
	 * @param int   $parcial_num  Parcial.
	 * @return array<int, true>
	 */
	private static function closed_students( array $student_ids, $subject_id, $trimester_id, $parcial_num ) {
		if ( empty( $student_ids ) ) {
			return array();
		}

		global $wpdb;
		$tps = $wpdb->prefix . 'edu_parcial_scores';

		// $student_ids ya viene pasado por intval en Edu_Service::active_student_ids().
		$sid_in = implode( ',', $student_ids );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT student_id FROM $tps
				 WHERE student_id IN ($sid_in)
				   AND subject_id = %d AND trimester_id = %d AND parcial_num = %d
				   AND is_closed = 1",
				$subject_id,
				$trimester_id,
				$parcial_num
			)
		);

		$closed = array();
		foreach ( (array) $rows as $sid ) {
			$closed[ (int) $sid ] = true;
		}

		return $closed;
	}

	/**
	 * Nota del parcial ya recalculada.
	 *
	 * @return float|null
	 */
	private static function parcial_score( $student_id, $subject_id, $trimester_id, $parcial_num ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT computed_score FROM {$wpdb->prefix}edu_parcial_scores
				 WHERE student_id = %d AND subject_id = %d AND trimester_id = %d AND parcial_num = %d",
				(int) $student_id,
				(int) $subject_id,
				(int) $trimester_id,
				(int) $parcial_num
			)
		);

		return ( null === $value ) ? null : round( (float) $value, 2 );
	}

	private static function cell_error( $student_id, $component_id, $code, $message ) {
		return array(
			'student_id'   => (int) $student_id,
			'component_id' => (int) $component_id,
			'code'         => $code,
			'message'      => $message,
		);
	}
}
