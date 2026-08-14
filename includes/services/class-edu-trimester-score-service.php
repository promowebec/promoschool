<?php
/**
 * Servicio: examen final, proyecto y cierres de parcial y trimestre.
 *
 * Fórmulas (Instructivo 2025), según el subnivel del grado:
 *   inicial / preparatoria / elemental:
 *       Nota_Trimestre = ((P1 + P2) / 2) × 0.70 + Examen × 0.30
 *   media / superior / bg / bt:
 *       Nota_Trimestre = ((P1 + P2) / 2) × 0.70 + ((Examen + Proyecto) / 2) × 0.30
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Trimester_Score_Service {

	/* ─────────────────────────────────────────────────────────────────────
	 * Examen final y proyecto
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Guarda examen y proyecto de un grupo de estudiantes y recalcula la nota
	 * del trimestre.
	 *
	 * Los parciales se leen de wp_edu_parcial_scores y no de las columnas
	 * parcial1_score/parcial2_score de trimester_scores: esas solo se llenan al
	 * cerrar el parcial, y aquí se necesita poder guardar el examen antes.
	 *
	 * @param array $input {
	 *     @type int   $grade_id
	 *     @type int   $subject_id
	 *     @type int   $trimester_id
	 *     @type array $students Lista de array( student_id, exam, proyecto ).
	 *                           Un valor vacío conserva el guardado antes.
	 * }
	 * @return array|WP_Error
	 */
	public static function save_exam( array $input ) {
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
		$tts = $wpdb->prefix . 'edu_trimester_scores';

		$student_ids  = Edu_Service::active_student_ids( $grade_id );
		$usa_sumativa = Edu_Service::uses_sumativa( $grade_id );
		$parcial_map  = self::parcial_map( $student_ids, $subject_id, $trimester_id );

		$saved    = 0;
		$skipped  = 0;
		$errors   = array();
		$results  = array();
		$db_error = '';

		foreach ( (array) ( $input['students'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$sid = isset( $row['student_id'] ) ? (int) $row['student_id'] : 0;

			if ( ! in_array( $sid, $student_ids, true ) ) {
				$skipped++;
				continue;
			}

			$exam_in = array_key_exists( 'exam', $row ) ? Edu_Service::parse_score( $row['exam'] ) : null;
			$proy_in = array_key_exists( 'proyecto', $row ) ? Edu_Service::parse_score( $row['proyecto'] ) : null;

			// Sin ninguno de los dos no hay nada que guardar.
			if ( null === $exam_in && null === $proy_in ) {
				$skipped++;
				continue;
			}

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, is_closed, final_exam_score, proyecto_score FROM $tts
					 WHERE student_id = %d AND subject_id = %d AND trimester_id = %d",
					$sid,
					$subject_id,
					$trimester_id
				)
			);

			// Un trimestre cerrado no se sobrescribe.
			if ( $existing && 1 === (int) $existing->is_closed ) {
				$skipped++;
				$errors[] = array(
					'student_id' => $sid,
					'code'       => 'trimester_closed',
					'message'    => __( 'El trimestre de este estudiante ya está cerrado.', 'sistema-educativo' ),
				);
				continue;
			}

			// Lo que no venga en la petición conserva su valor actual.
			$exam_score = ( null !== $exam_in ) ? $exam_in : ( $existing ? (float) $existing->final_exam_score : 0.0 );
			$proy_score = ( null !== $proy_in ) ? $proy_in : ( $existing ? (float) $existing->proyecto_score : 0.0 );

			$exam_score = max( 0, min( 10, $exam_score ) );
			$proy_score = max( 0, min( 10, $proy_score ) );

			$p1       = $parcial_map[ $sid ][1] ?? 0.0;
			$p2       = $parcial_map[ $sid ][2] ?? 0.0;
			$parc_avg = ( $p1 + $p2 ) / 2;

			$computed = $usa_sumativa
				? round( $parc_avg * 0.70 + ( ( $exam_score + $proy_score ) / 2 ) * 0.30, 2 )
				: round( $parc_avg * 0.70 + $exam_score * 0.30, 2 );

			if ( $existing ) {
				$result = $wpdb->update(
					$tts,
					array(
						'final_exam_score' => $exam_score,
						'proyecto_score'   => $proy_score,
						'computed_score'   => $computed,
					),
					array( 'id' => (int) $existing->id ),
					array( '%f', '%f', '%f' ),
					array( '%d' )
				);
			} else {
				$result = $wpdb->insert(
					$tts,
					array(
						'student_id'       => $sid,
						'subject_id'       => $subject_id,
						'trimester_id'     => $trimester_id,
						'parcial1_score'   => 0,
						'parcial2_score'   => 0,
						'final_exam_score' => $exam_score,
						'proyecto_score'   => $proy_score,
						'computed_score'   => $computed,
						'is_closed'        => 0,
					),
					array( '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%d' )
				);
			}

			if ( false === $result ) {
				if ( $wpdb->last_error ) {
					$db_error = $wpdb->last_error;
				}
				continue;
			}

			$saved++;
			$results[] = array(
				'student_id'       => $sid,
				'final_exam_score' => $exam_score,
				'proyecto_score'   => $proy_score,
				'computed_score'   => $computed,
			);

			Edu_Audit::log(
				Edu_Audit::EXAMEN_GUARDADO,
				'trimester_score',
				$sid,
				null,
				array(
					'materia_id'   => $subject_id,
					'trimestre_id' => $trimester_id,
					'examen'       => $exam_score,
					'proyecto'     => $proy_score,
					'nota_final'   => $computed,
				)
			);
		}

		return array(
			'saved'      => $saved,
			'skipped'    => $skipped,
			'errors'     => $errors,
			'results'    => $results,
			'db_error'   => $db_error,
			'formula'    => $usa_sumativa ? 'sumativa_proyecto' : 'elemental',
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Cierre de parcial
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Cierra un parcial para todos los estudiantes activos del grado.
	 *
	 * @param array $input grade_id, subject_id, trimester_id, parcial_num.
	 * @return array|WP_Error
	 */
	public static function close_parcial( array $input ) {
		$cap = Edu_Service::require_cap( 'edu_close_partial' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
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

		$student_ids = Edu_Service::active_student_ids( $grade_id );

		if ( empty( $student_ids ) ) {
			return Edu_Service::error(
				'no_students',
				__( 'Este grado no tiene estudiantes activos.', 'sistema-educativo' ),
				422
			);
		}

		global $wpdb;
		$tps    = $wpdb->prefix . 'edu_parcial_scores';
		$sid_in = implode( ',', $student_ids );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $tps SET is_closed = 1
				 WHERE student_id IN ($sid_in)
				   AND subject_id = %d AND trimester_id = %d AND parcial_num = %d",
				$subject_id,
				$trimester_id,
				$parcial_num
			)
		);

		foreach ( $student_ids as $sid ) {
			do_action( 'edu_partial_closed', $sid, $subject_id, $trimester_id, $parcial_num );
		}

		Edu_Audit::log(
			Edu_Audit::PARCIAL_CERRADO,
			'parcial_score',
			$subject_id,
			null,
			array(
				'grado_id'     => $grade_id,
				'materia_id'   => $subject_id,
				'trimestre_id' => $trimester_id,
				'parcial'      => $parcial_num,
				'estudiantes'  => count( $student_ids ),
			)
		);

		return array(
			'closed'      => true,
			'parcial_num' => $parcial_num,
			'students'    => count( $student_ids ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Cierre de trimestre
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Cierra el trimestre. Exige que los dos parciales estén cerrados.
	 *
	 * @param array $input grade_id, subject_id, trimester_id.
	 * @return array|WP_Error
	 */
	public static function close_trimester( array $input ) {
		$cap = Edu_Service::require_cap( 'edu_close_partial' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$grade_id     = isset( $input['grade_id'] ) ? (int) $input['grade_id'] : 0;
		$subject_id   = isset( $input['subject_id'] ) ? (int) $input['subject_id'] : 0;
		$trimester_id = isset( $input['trimester_id'] ) ? (int) $input['trimester_id'] : 0;

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

		$student_ids = Edu_Service::active_student_ids( $grade_id );

		if ( empty( $student_ids ) ) {
			return Edu_Service::error(
				'no_students',
				__( 'Este grado no tiene estudiantes activos.', 'sistema-educativo' ),
				422
			);
		}

		global $wpdb;
		$tps    = $wpdb->prefix . 'edu_parcial_scores';
		$tts    = $wpdb->prefix . 'edu_trimester_scores';
		$sid_in = implode( ',', $student_ids );

		/*
		 * Se cuentan las filas abiertas, no los estudiantes. Un parcial sin
		 * notas no deja fila en parcial_scores, y cerrarlo así es válido: el
		 * rector cierra el parcial aunque no se haya evaluado nada.
		 */
		$open = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $tps
				 WHERE student_id IN ($sid_in)
				   AND subject_id = %d AND trimester_id = %d AND is_closed = 0",
				$subject_id,
				$trimester_id
			)
		);

		if ( $open > 0 ) {
			return Edu_Service::error(
				'parcials_open',
				__( 'Hay parciales sin cerrar. Cierra los dos parciales antes de cerrar el trimestre.', 'sistema-educativo' ),
				409,
				array( 'open' => $open )
			);
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $tts SET is_closed = 1
				 WHERE student_id IN ($sid_in)
				   AND subject_id = %d AND trimester_id = %d",
				$subject_id,
				$trimester_id
			)
		);

		foreach ( $student_ids as $sid ) {
			do_action( 'edu_trimester_closed', $sid, $subject_id, $trimester_id );
		}

		Edu_Audit::log(
			Edu_Audit::TRIMESTRE_CERRADO,
			'trimester_score',
			$subject_id,
			null,
			array(
				'grado_id'     => $grade_id,
				'materia_id'   => $subject_id,
				'trimestre_id' => $trimester_id,
				'estudiantes'  => count( $student_ids ),
			)
		);

		return array(
			'closed'   => true,
			'students' => count( $student_ids ),
		);
	}

	/* ─── Internos ──────────────────────────────────────────────────────── */

	/**
	 * Notas de parcial por estudiante: [student_id][parcial_num] => nota.
	 *
	 * @param int[] $student_ids  Estudiantes.
	 * @param int   $subject_id   Materia.
	 * @param int   $trimester_id Trimestre.
	 * @return array
	 */
	private static function parcial_map( array $student_ids, $subject_id, $trimester_id ) {
		if ( empty( $student_ids ) ) {
			return array();
		}

		global $wpdb;
		$tps    = $wpdb->prefix . 'edu_parcial_scores';
		$sid_in = implode( ',', $student_ids );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT student_id, parcial_num, computed_score
				 FROM $tps
				 WHERE student_id IN ($sid_in) AND subject_id = %d AND trimester_id = %d",
				(int) $subject_id,
				(int) $trimester_id
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->student_id ][ (int) $row->parcial_num ] = (float) $row->computed_score;
		}

		return $map;
	}
}
