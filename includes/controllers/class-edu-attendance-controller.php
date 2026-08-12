<?php
/**
 * Adaptador HTTP: registro de asistencia.
 *
 * La lógica vive en Edu_Attendance_Service. Aquí solo se verifica el nonce, se
 * traduce $_POST y se redirige.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Attendance_Controller {

	public static function handle_save() {
		check_admin_referer( 'edu_save_attendance' );

		$statuses = isset( $_POST['attendance'] ) && is_array( $_POST['attendance'] )
			? (array) wp_unslash( $_POST['attendance'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio sanea cada fila.
			: array();

		$justifications = isset( $_POST['justification'] ) && is_array( $_POST['justification'] )
			? (array) wp_unslash( $_POST['justification'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$result = Edu_Attendance_Service::save(
			array(
				'grade_id'   => isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0,
				'subject_id' => isset( $_POST['subject_id'] ) ? (int) $_POST['subject_id'] : 0,
				'date'       => isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '',
				'students'   => Edu_Attendance_Service::flatten_matrix( $statuses, $justifications ),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result );
		}

		self::redirect(
			array(
				'grade_id'   => $result['grade_id'],
				'subject_id' => $result['subject_id'],
				'date'       => $result['date'],
				'status'     => 'saved',
			)
		);
	}

	/* ─── Helpers de transporte ─────────────────────────────────────────── */

	private static function handle_error( WP_Error $error ) {
		$code = $error->get_error_code();

		if ( in_array( $code, array( 'forbidden', 'no_institution' ), true ) ) {
			wp_die( esc_html( $error->get_error_message() ) );
		}

		self::redirect(
			array(
				'status' => 'error',
				'code'   => $code,
			)
		);
	}

	private static function redirect( $args = array() ) {
		if ( ! empty( $_POST['_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce ya se verificó en el handler.
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fe   = array(
				'edu_tab'    => 'asistencia',
				'edu_status' => $args['status'] ?? '',
			);
			if ( ! empty( $args['grade_id'] ) ) {
				$fe['edu_att_grade'] = $args['grade_id'];
			}
			if ( ! empty( $args['date'] ) ) {
				$fe['edu_att_date'] = $args['date'];
			}
			if ( ! empty( $args['subject_id'] ) ) {
				$fe['edu_att_subj'] = $args['subject_id'];
			}
			wp_safe_redirect( add_query_arg( $fe, $base ) );
			exit;
		}

		$args = array_merge( array( 'page' => 'edu-asistencia' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
