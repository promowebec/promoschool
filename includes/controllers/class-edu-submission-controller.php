<?php
/**
 * Adaptador HTTP: entregas de estudiantes y su calificación.
 *
 * La lógica vive en Edu_Submission_Service. Aquí solo se verifica el nonce, se
 * traduce $_POST/$_FILES y se redirige o se sirve la descarga.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Submission_Controller {

	/* ─────────────────────────────────────────────────────────────────────
	 * Entrega del estudiante
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_submit() {
		check_admin_referer( 'edu_submit_assignment' );

		$assignment_id = isset( $_POST['assignment_id'] ) ? (int) $_POST['assignment_id'] : 0;

		$result = Edu_Submission_Service::submit(
			array(
				'assignment_id' => $assignment_id,
				'comment'       => isset( $_POST['comment'] ) ? wp_unslash( $_POST['comment'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio aplica wp_kses_post().
				'files'         => isset( $_FILES['archivos'] ) ? $_FILES['archivos'] : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Edu_File_Service valida tamaño, extensión y origen.
			)
		);

		if ( is_wp_error( $result ) ) {
			self::handle_public_error( $result, $assignment_id );
		}

		self::redirect_public( $assignment_id, 'submitted' );
	}

	public static function handle_submit_recovery() {
		check_admin_referer( 'edu_submit_recovery' );

		$assignment_id = isset( $_POST['assignment_id'] ) ? (int) $_POST['assignment_id'] : 0;

		$result = Edu_Submission_Service::submit_recovery(
			array(
				'assignment_id' => $assignment_id,
				'comment'       => isset( $_POST['recovery_comment'] ) ? wp_unslash( $_POST['recovery_comment'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio aplica wp_kses_post().
				'files'         => isset( $_FILES['archivos_recovery'] ) ? $_FILES['archivos_recovery'] : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Edu_File_Service valida.
			)
		);

		if ( is_wp_error( $result ) ) {
			self::handle_public_error( $result, $assignment_id );
		}

		self::redirect_public( $assignment_id, 'recovery_submitted' );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Calificación
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_grade() {
		check_admin_referer( 'edu_grade_submission' );

		$result = Edu_Submission_Service::grade(
			array(
				'submission_id' => isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0,
				'score'         => isset( $_POST['score'] ) ? wp_unslash( $_POST['score'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio castea la nota.
				'feedback'      => isset( $_POST['feedback'] ) ? wp_unslash( $_POST['feedback'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio aplica wp_kses_post().
			)
		);

		if ( is_wp_error( $result ) ) {
			self::handle_admin_error( $result );
		}

		self::redirect_admin( $result['assignment_id'], 'graded' );
	}

	public static function handle_grade_recovery() {
		check_admin_referer( 'edu_grade_recovery' );

		$result = Edu_Submission_Service::grade_recovery(
			array(
				'submission_id'     => isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0,
				'recovery_score'    => isset( $_POST['recovery_score'] ) ? wp_unslash( $_POST['recovery_score'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio castea la nota.
				'recovery_feedback' => isset( $_POST['recovery_feedback'] ) ? wp_unslash( $_POST['recovery_feedback'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio aplica wp_kses_post().
			)
		);

		if ( is_wp_error( $result ) ) {
			self::handle_admin_error( $result );
		}

		self::redirect_admin( $result['assignment_id'], 'graded' );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Configuración de la mejora
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_save_recovery_settings() {
		check_admin_referer( 'edu_save_recovery_settings' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$result = Edu_Submission_Service::save_recovery_settings(
			array(
				'assignment_id'     => $id,
				'allow_recovery'    => ! empty( $_POST['allow_recovery'] ),
				'recovery_due_date' => isset( $_POST['recovery_due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['recovery_due_date'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();

			if ( in_array( $code, array( 'forbidden', 'no_institution' ), true ) ) {
				wp_die( esc_html( $result->get_error_message() ) );
			}

			// Comportamiento anterior: una tarea no apta cortaba con wp_die().
			if ( 'assignment_not_available' === $code ) {
				wp_die( esc_html__( 'Tarea no disponible.', 'sistema-educativo' ) );
			}

			self::redirect_admin( $id, 'error', $code );
		}

		if ( ! empty( $_POST['_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce ya se verificó.
			wp_safe_redirect( esc_url_raw( wp_unslash( $_POST['_redirect'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'edu-tareas',
					'action' => 'edit',
					'id'     => $id,
					'status' => 'recovery_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Descarga de archivos de entrega
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_download_submission_file() {
		$file_id = isset( $_GET['file_id'] ) ? (int) $_GET['file_id'] : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'edu_download_sub_file_' . $file_id ) ) {
			wp_die( esc_html__( 'Enlace inválido o expirado.', 'sistema-educativo' ) );
		}

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}submission_files WHERE id = %d", $file_id ) );
		if ( ! $file ) {
			wp_die( esc_html__( 'Archivo no encontrado.', 'sistema-educativo' ) );
		}

		$sub = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.student_id, a.id AS assignment_id, a.teacher_id, a.grade_id, a.subject_id
				 FROM {$p}submissions s
				 INNER JOIN {$p}assignments a ON a.id = s.assignment_id
				 WHERE s.id = %d",
				(int) $file->submission_id
			)
		);

		if ( ! $sub ) {
			wp_die( esc_html__( 'Archivo no encontrado.', 'sistema-educativo' ) );
		}

		if ( ! Edu_Submission_Service::can_download( $sub ) ) {
			wp_die( esc_html__( 'Sin permiso para descargar este archivo.', 'sistema-educativo' ) );
		}

		$path = Edu_File_Service::url_to_path( $file->file_url );
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Archivo no disponible.', 'sistema-educativo' ) );
		}

		Edu_File_Service::stream( $path, $file->file_name );
	}

	/** Enlace firmado de descarga de un archivo de entrega. */
	public static function get_sub_download_url( $file_id ) {
		return Edu_File_Service::download_url( $file_id, 'edu_download_sub_file', 'file_id' );
	}

	/* ─── Helpers de transporte ─────────────────────────────────────────── */

	/**
	 * Errores del lado del estudiante: los que antes cortaban con wp_die()
	 * siguen haciéndolo; el resto vuelve al portal con el código.
	 *
	 * @param WP_Error $error         Error del servicio.
	 * @param int      $assignment_id Tarea.
	 */
	private static function handle_public_error( WP_Error $error, $assignment_id ) {
		$code = $error->get_error_code();

		$fatales = array(
			'forbidden'                => __( 'Sin permiso.', 'sistema-educativo' ),
			'assignment_not_available' => __( 'Tarea no disponible.', 'sistema-educativo' ),
			'recovery_not_available'   => __( 'Mejora no disponible.', 'sistema-educativo' ),
			'recovery_expired'         => __( 'El plazo para la mejora ha vencido.', 'sistema-educativo' ),
			'recovery_already_graded'  => __( 'Tu mejora ya fue calificada.', 'sistema-educativo' ),
			'not_a_student'            => __( 'No se encontró el registro de estudiante.', 'sistema-educativo' ),
			'out_of_scope'             => __( 'No puedes entregar esta tarea.', 'sistema-educativo' ),
		);

		if ( isset( $fatales[ $code ] ) ) {
			wp_die( esc_html( $fatales[ $code ] ) );
		}

		self::redirect_public( $assignment_id, 'error', $code );
	}

	/**
	 * Errores del lado del docente: vuelven a la pantalla de la tarea.
	 *
	 * @param WP_Error $error Error del servicio.
	 */
	private static function handle_admin_error( WP_Error $error ) {
		$code = $error->get_error_code();

		if ( in_array( $code, array( 'forbidden', 'no_institution' ), true ) ) {
			wp_die( esc_html( $error->get_error_message() ) );
		}

		$data          = $error->get_error_data();
		$assignment_id = isset( $data['details']['assignment_id'] ) ? (int) $data['details']['assignment_id'] : 0;

		self::redirect_admin( $assignment_id, 'error', $code );
	}

	private static function redirect_public( $assignment_id, $status, $code = '' ) {
		$args = array(
			'edu_tab'    => 'tareas',
			'edu_status' => $status,
		);

		if ( $code ) {
			$args['edu_code'] = $code;
		}

		$base = '';
		if ( ! empty( $_POST['_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce ya se verificó.
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( ! $base ) {
			$page = get_option( 'edu_portal_estudiante_page_id' );
			$base = $page ? get_permalink( (int) $page ) : home_url();
		}

		unset( $assignment_id );

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}

	private static function redirect_admin( $assignment_id, $status, $code = '' ) {
		if ( ! empty( $_POST['_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce ya se verificó.
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fe   = array(
				'edu_tab'    => 'tareas',
				'edu_status' => $status,
			);
			if ( $assignment_id ) {
				$fe['edu_tarea_id'] = $assignment_id;
			}
			if ( $code ) {
				$fe['edu_code'] = $code;
			}
			wp_safe_redirect( add_query_arg( $fe, $base ) );
			exit;
		}

		$args = array(
			'page'   => 'edu-tareas',
			'action' => 'detail',
			'id'     => $assignment_id,
			'status' => $status,
		);

		if ( $code ) {
			$args['code'] = $code;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
