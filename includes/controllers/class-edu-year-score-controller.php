<?php
/**
 * Controller: ingreso de notas de recuperación (supletorio, remedial, gracia).
 *
 * Recibe batch recovery[student_id] = score. Solo escribe el campo que
 * corresponde al estado actual del estudiante; no permite saltar etapas.
 * Tras guardar recalcula el status con Edu_Grade_Calculator::recalculate_recovery_status().
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Year_Score_Controller {

	public static function handle_save_recovery() {
		check_admin_referer( 'edu_save_recovery' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$grade_id       = isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0;
		$subject_id     = isset( $_POST['subject_id'] ) ? (int) $_POST['subject_id'] : 0;
		$period_id      = isset( $_POST['period_id'] ) ? (int) $_POST['period_id'] : 0;

		global $wpdb;
		$tg  = $wpdb->prefix . 'edu_grades';
		$ts  = $wpdb->prefix . 'edu_subjects';
		$tp  = $wpdb->prefix . 'edu_periods';
		$tst = $wpdb->prefix . 'edu_students';
		$tys = $wpdb->prefix . 'edu_year_scores';

		if ( ! Edu_Context::is_superadmin_editorial() ) {
			$grade_inst = (int) $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM $tg WHERE id = %d", $grade_id ) );
			$subj_inst  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM $ts WHERE id = %d", $subject_id ) );
			$peri_inst  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM $tp WHERE id = %d", $period_id ) );
			if ( $grade_inst !== $institution_id || $subj_inst !== $institution_id || $peri_inst !== $institution_id ) {
				self::redirect( array( 'status' => 'error', 'code' => 'invalid_scope' ) );
			}
		}

		$student_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $tst WHERE grade_id = %d AND status = 'active'",
			$grade_id
		) );
		$student_ids = array_map( 'intval', (array) $student_ids );

		$recovery_input = isset( $_POST['recovery'] ) ? (array) $_POST['recovery'] : array();
		$saved = 0;

		foreach ( $recovery_input as $sid_raw => $val_raw ) {
			$sid = (int) $sid_raw;
			if ( ! in_array( $sid, $student_ids, true ) ) {
				continue;
			}
			$val_str = trim( (string) $val_raw );
			if ( '' === $val_str ) {
				continue;
			}
			$score = round( (float) str_replace( ',', '.', $val_str ), 2 );
			if ( $score < 0 || $score > 10 ) {
				continue;
			}

			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $tys WHERE student_id = %d AND subject_id = %d AND period_id = %d",
				$sid, $subject_id, $period_id
			) );

			if ( ! $row ) {
				continue; // Sin nota anual base: no se puede ingresar recuperación.
			}

			// Determinar qué campo escribir según el estado actual.
			$field = null;
			switch ( $row->status ) {
				case 'supletorio':
					$field = 'supletorio_score';
					break;
				case 'remedial':
					$field = 'remedial_score';
					break;
				case 'gracia':
					$field = 'gracia_score';
					break;
			}

			if ( ! $field ) {
				continue; // Estado aprobado/reprobado/en_curso: no acepta recuperación.
			}

			// Actualizar score y recalcular status.
			$row->$field = $score;
			$new_status  = Edu_Grade_Calculator::recalculate_recovery_status( $row );

			$wpdb->update(
				$tys,
				array(
					$field   => $score,
					'status' => $new_status,
				),
				array( 'id' => (int) $row->id ),
				array( '%f', '%s' ),
				array( '%d' )
			);
			$saved++;
		}

		Edu_Audit::log(
			Edu_Audit::RECUPERACION_GUARDADA,
			'year_score',
			$subject_id,
			null,
			array(
				'grado_id'   => $grade_id,
				'materia_id' => $subject_id,
				'periodo_id' => $period_id,
				'guardadas'  => $saved,
			)
		);

		self::redirect( array(
			'grade_id'   => $grade_id,
			'subject_id' => $subject_id,
			'period_id'  => $period_id,
			'status'     => 'updated',
			'saved'      => $saved,
		) );
	}

	private static function guard() {
		if ( ! ( Edu_Context::can( 'edu_grade_students' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}
		if ( ! Edu_Context::current_institution_id() ) {
			wp_die( esc_html__( 'No hay institución activa.', 'sistema-educativo' ) );
		}
	}

	private static function redirect( $args = array() ) {
		if ( ! empty( $_POST['_redirect'] ) ) {
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) );
			$fe   = array(
				'edu_tab'    => ! empty( $_POST['_fe_tab'] ) ? sanitize_key( $_POST['_fe_tab'] ) : 'cierres',
				'edu_status' => $args['status'] ?? '',
			);
			if ( ! empty( $args['grade_id'] ) )   $fe['edu_grade']  = $args['grade_id'];
			if ( ! empty( $args['subject_id'] ) ) $fe['edu_subj']   = $args['subject_id'];
			if ( ! empty( $args['period_id'] ) )  $fe['edu_period'] = $args['period_id'];
			wp_safe_redirect( add_query_arg( $fe, $base ) );
			exit;
		}
		$args = array_merge( array( 'page' => 'edu-resumen-anual' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
