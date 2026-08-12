<?php
/**
 * Controller: entregas de estudiantes (wp_edu_submissions + wp_edu_submission_files)
 * y calificación por parte del docente.
 *
 * handle_submit  → estudiante sube archivos + comentario (status: submitted/late).
 * handle_grade   → docente ingresa score + feedback (status: graded).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Submission_Controller {

	/* -------------------------------------------------------------------------
	 * Entrega del estudiante
	 * ---------------------------------------------------------------------- */

	public static function handle_submit() {
		check_admin_referer( 'edu_submit_assignment' );

		if ( ! Edu_Context::can( 'edu_submit_assignment' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$assignment_id = isset( $_POST['assignment_id'] ) ? (int) $_POST['assignment_id'] : 0;
		$comment       = wp_kses_post( wp_unslash( $_POST['comment'] ?? '' ) );

		global $wpdb;
		$ta  = $wpdb->prefix . 'edu_assignments';
		$tst = $wpdb->prefix . 'edu_students';
		$tsb = $wpdb->prefix . 'edu_submissions';
		$tsf = $wpdb->prefix . 'edu_submission_files';

		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id = %d", $assignment_id ) );
		if ( ! $assignment || 'published' !== $assignment->status ) {
			wp_die( esc_html__( 'Tarea no disponible.', 'sistema-educativo' ) );
		}

		$student = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM $tst WHERE user_id = %d",
			get_current_user_id()
		) );
		if ( ! $student ) {
			wp_die( esc_html__( 'No se encontró el registro de estudiante.', 'sistema-educativo' ) );
		}

		// Verificar que el estudiante pertenece al grado de la tarea.
		$in_grade = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $tst WHERE id = %d AND grade_id = %d",
			$student->id, $assignment->grade_id
		) );
		if ( ! $in_grade ) {
			wp_die( esc_html__( 'No puedes entregar esta tarea.', 'sistema-educativo' ) );
		}

		$is_late = ( $assignment->due_date && strtotime( $assignment->due_date ) < time() ) ? 'late' : 'submitted';

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM $tsb WHERE assignment_id = %d AND student_id = %d",
			$assignment_id, $student->id
		) );

		if ( $existing && 'graded' === $existing->status ) {
			self::redirect_public( $assignment_id, 'error', 'already_graded' );
		}

		$now = current_time( 'mysql' );

		if ( $existing ) {
			$wpdb->update(
				$tsb,
				array(
					'comment'      => $comment,
					'status'       => $is_late,
					'submitted_at' => $now,
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			$submission_id = (int) $existing->id;
			// Borrar archivos anteriores si el estudiante re-entrega.
			$old_files = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tsf WHERE submission_id = %d", $submission_id ) );
			foreach ( $old_files as $f ) {
				$path = Edu_Assignment_Task_Controller::get_download_url( 0 ); // Solo para reutilizar url_to_path.
				// Borrar físicamente.
				$upload_dir = wp_upload_dir();
				$fpath      = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $f->file_url );
				if ( file_exists( $fpath ) ) {
					@unlink( $fpath );
				}
				$wpdb->delete( $tsf, array( 'id' => (int) $f->id ), array( '%d' ) );
			}
		} else {
			$wpdb->insert(
				$tsb,
				array(
					'assignment_id' => $assignment_id,
					'student_id'    => (int) $student->id,
					'comment'       => $comment,
					'status'        => $is_late,
					'submitted_at'  => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
			$submission_id = (int) $wpdb->insert_id;
		}

		// Subir archivos de la entrega.
		if ( ! empty( $_FILES['archivos']['name'][0] ) ) {
			self::handle_submission_files( $submission_id, $_FILES['archivos'], $assignment_id );
		}

		self::redirect_public( $assignment_id, 'submitted' );
	}

	/* -------------------------------------------------------------------------
	 * Calificación por docente
	 * ---------------------------------------------------------------------- */

	public static function handle_grade() {
		check_admin_referer( 'edu_grade_submission' );

		if ( ! ( Edu_Context::can( 'edu_grade_students' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		$score_raw     = isset( $_POST['score'] ) ? str_replace( ',', '.', (string) $_POST['score'] ) : '';
		$feedback      = wp_kses_post( wp_unslash( $_POST['feedback'] ?? '' ) );

		global $wpdb;
		$tsb = $wpdb->prefix . 'edu_submissions';
		$ta  = $wpdb->prefix . 'edu_assignments';

		$submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsb WHERE id = %d", $submission_id ) );
		if ( ! $submission ) {
			self::redirect_admin( 0, 'error', 'not_found' );
		}

		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id = %d", $submission->assignment_id ) );
		if ( ! $assignment ) {
			self::redirect_admin( 0, 'error', 'not_found' );
		}

		$score = '' !== $score_raw ? round( (float) $score_raw, 2 ) : null;
		if ( null !== $score && ( $score < 0 || $score > (float) $assignment->max_score ) ) {
			self::redirect_admin( (int) $assignment->id, 'error', 'invalid_score' );
		}

		$wpdb->update(
			$tsb,
			array(
				'score'      => $score,
				'feedback'   => $feedback,
				'status'     => 'graded',
				'graded_at'  => current_time( 'mysql' ),
				'graded_by'  => get_current_user_id(),
			),
			array( 'id' => $submission_id ),
			array( '%f', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		// Si la tarea está vinculada a un componente evaluable, registrar en grades_log.
		if ( null !== $score && ! empty( $assignment->component_id ) ) {
			$tgl        = $wpdb->prefix . 'edu_grades_log';
			$student_id = (int) $submission->student_id;
			$comp_id    = (int) $assignment->component_id;

			// Normalizar a escala 0-10.
			$max = (float) $assignment->max_score;
			$normalized = $max > 0 ? round( $score * 10.00 / $max, 2 ) : $score;
			$normalized = max( 0.00, min( 10.00, $normalized ) );

			// UPSERT: reemplazar entrada previa para mismo estudiante + componente + tarea.
			$wpdb->delete(
				$tgl,
				array(
					'student_id'    => $student_id,
					'component_id'  => $comp_id,
					'assignment_id' => (int) $assignment->id,
				),
				array( '%d', '%d', '%d' )
			);

			$wpdb->insert(
				$tgl,
				array(
					'student_id'    => $student_id,
					'component_id'  => $comp_id,
					'assignment_id' => (int) $assignment->id,
					'score'         => $normalized,
					'registered_by' => get_current_user_id(),
				),
				array( '%d', '%d', '%d', '%f', '%d' )
			);

			do_action( 'edu_grade_logged', $student_id, $comp_id, $normalized );
		}

		Edu_Audit::log(
			Edu_Audit::ENTREGA_CALIFICADA,
			'submission',
			$submission_id,
			null,
			array(
				'tarea_id'   => (int) $assignment->id,
				'estudiante' => (int) $submission->student_id,
				'nota'       => $score,
				'max'        => $assignment->max_score,
			)
		);

		self::redirect_admin( (int) $assignment->id, 'graded' );
	}

	/* -------------------------------------------------------------------------
	 * Configuración de recuperación (docente activa/desactiva mejora)
	 * ---------------------------------------------------------------------- */

	public static function handle_save_recovery_settings() {
		check_admin_referer( 'edu_save_recovery_settings' );

		if ( ! ( Edu_Context::can( 'edu_grade_students' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$allow_recovery = isset( $_POST['allow_recovery'] ) ? 1 : 0;
		$rdate_raw      = isset( $_POST['recovery_due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['recovery_due_date'] ) ) : '';

		global $wpdb;
		$ta  = $wpdb->prefix . 'edu_assignments';
		$tsb = $wpdb->prefix . 'edu_submissions';

		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id = %d", $id ) );
		if ( ! $assignment || 'closed' !== $assignment->status ) {
			wp_die( esc_html__( 'Tarea no disponible.', 'sistema-educativo' ) );
		}

		$recovery_due_date = null;
		if ( $rdate_raw ) {
			$recovery_due_date = date( 'Y-m-d H:i:s', strtotime( str_replace( 'T', ' ', $rdate_raw ) ) );
		}

		$wpdb->update(
			$ta,
			array(
				'allow_recovery'    => $allow_recovery,
				'recovery_due_date' => $recovery_due_date,
			),
			array( 'id' => $id ),
			array( '%d', $recovery_due_date ? '%s' : 'NULL' ),
			array( '%d' )
		);

		// Al activar: marcar submissions existentes como 'available'.
		// Estudiantes sin submission acceden via allow_recovery en la tarea directamente.
		if ( $allow_recovery ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE $tsb SET recovery_status = 'available'
				 WHERE assignment_id = %d AND recovery_status = 'none'",
				$id
			) );
		} else {
			$wpdb->query( $wpdb->prepare(
				"UPDATE $tsb SET recovery_status = 'none'
				 WHERE assignment_id = %d AND recovery_status = 'available'",
				$id
			) );
		}

		// Si viene del frontend, redirigir al portal en lugar de wp-admin.
		if ( ! empty( $_POST['_redirect'] ) ) {
			wp_safe_redirect( esc_url_raw( wp_unslash( $_POST['_redirect'] ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array(
			'page'   => 'edu-tareas',
			'action' => 'edit',
			'id'     => $id,
			'status' => 'recovery_saved',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* -------------------------------------------------------------------------
	 * Calificación de la mejora (docente califica recovery)
	 * ---------------------------------------------------------------------- */

	public static function handle_grade_recovery() {
		check_admin_referer( 'edu_grade_recovery' );

		if ( ! ( Edu_Context::can( 'edu_grade_students' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		$score_raw     = isset( $_POST['recovery_score'] ) ? str_replace( ',', '.', (string) $_POST['recovery_score'] ) : '';
		$feedback      = wp_kses_post( wp_unslash( $_POST['recovery_feedback'] ?? '' ) );

		global $wpdb;
		$tsb = $wpdb->prefix . 'edu_submissions';
		$ta  = $wpdb->prefix . 'edu_assignments';
		$tgl = $wpdb->prefix . 'edu_grades_log';

		$submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsb WHERE id = %d", $submission_id ) );
		if ( ! $submission ) {
			self::redirect_admin( 0, 'error', 'not_found' );
		}

		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id = %d", $submission->assignment_id ) );
		if ( ! $assignment ) {
			self::redirect_admin( 0, 'error', 'not_found' );
		}

		$recovery_score = '' !== $score_raw ? round( (float) $score_raw, 2 ) : null;
		if ( null !== $recovery_score && ( $recovery_score < 0 || $recovery_score > (float) $assignment->max_score ) ) {
			self::redirect_admin( (int) $assignment->id, 'error', 'invalid_score' );
		}

		$wpdb->update(
			$tsb,
			array(
				'recovery_score'    => $recovery_score,
				'recovery_feedback' => $feedback,
				'recovery_status'   => 'graded',
			),
			array( 'id' => $submission_id ),
			array( '%f', '%s', '%s' ),
			array( '%d' )
		);

		// Registrar la MEJOR nota en grades_log.
		if ( null !== $recovery_score && ! empty( $assignment->component_id ) ) {
			$max             = (float) $assignment->max_score;
			$orig_score      = null !== $submission->score ? (float) $submission->score : 0.0;
			$norm_original   = $max > 0 ? round( $orig_score * 10.00 / $max, 2 ) : $orig_score;
			$norm_recovery   = $max > 0 ? round( $recovery_score * 10.00 / $max, 2 ) : $recovery_score;
			$best_normalized = max( 0.00, min( 10.00, max( $norm_original, $norm_recovery ) ) );

			$student_id = (int) $submission->student_id;
			$comp_id    = (int) $assignment->component_id;

			$wpdb->delete( $tgl, array(
				'student_id'    => $student_id,
				'component_id'  => $comp_id,
				'assignment_id' => (int) $assignment->id,
			), array( '%d', '%d', '%d' ) );

			$wpdb->insert( $tgl, array(
				'student_id'    => $student_id,
				'component_id'  => $comp_id,
				'assignment_id' => (int) $assignment->id,
				'score'         => $best_normalized,
				'registered_by' => get_current_user_id(),
			), array( '%d', '%d', '%d', '%f', '%d' ) );

			do_action( 'edu_grade_logged', $student_id, $comp_id, $best_normalized );
		}

		Edu_Audit::log(
			Edu_Audit::ENTREGA_CALIFICADA,
			'submission_recovery',
			$submission_id,
			null,
			array(
				'tarea_id'      => (int) $assignment->id,
				'estudiante'    => (int) $submission->student_id,
				'nota_original' => $submission->score,
				'nota_mejora'   => $recovery_score,
				'max'           => $assignment->max_score,
			)
		);

		self::redirect_admin( (int) $assignment->id, 'graded' );
	}

	/* -------------------------------------------------------------------------
	 * Entrega de mejora por estudiante
	 * ---------------------------------------------------------------------- */

	public static function handle_submit_recovery() {
		check_admin_referer( 'edu_submit_recovery' );

		if ( ! Edu_Context::can( 'edu_submit_assignment' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$assignment_id = isset( $_POST['assignment_id'] ) ? (int) $_POST['assignment_id'] : 0;
		$comment       = wp_kses_post( wp_unslash( $_POST['recovery_comment'] ?? '' ) );

		global $wpdb;
		$ta  = $wpdb->prefix . 'edu_assignments';
		$tst = $wpdb->prefix . 'edu_students';
		$tsb = $wpdb->prefix . 'edu_submissions';

		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id = %d", $assignment_id ) );
		if ( ! $assignment || ! $assignment->allow_recovery ) {
			wp_die( esc_html__( 'Mejora no disponible.', 'sistema-educativo' ) );
		}

		// Verificar fecha límite de mejora.
		if ( $assignment->recovery_due_date && strtotime( $assignment->recovery_due_date ) < time() ) {
			wp_die( esc_html__( 'El plazo para la mejora ha vencido.', 'sistema-educativo' ) );
		}

		$student = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM $tst WHERE user_id = %d",
			get_current_user_id()
		) );
		if ( ! $student ) {
			wp_die( esc_html__( 'No se encontró el registro de estudiante.', 'sistema-educativo' ) );
		}

		$submission = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $tsb WHERE assignment_id = %d AND student_id = %d",
			$assignment_id, $student->id
		) );

		// No permitir re-entrega si la mejora ya fue calificada.
		if ( $submission && 'graded' === $submission->recovery_status ) {
			wp_die( esc_html__( 'Tu mejora ya fue calificada.', 'sistema-educativo' ) );
		}

		$now = current_time( 'mysql' );

		if ( $submission ) {
			// Actualizar entrega existente con datos de mejora.
			$wpdb->update(
				$tsb,
				array(
					'recovery_comment'      => $comment,
					'recovery_status'       => 'submitted',
					'recovery_submitted_at' => $now,
				),
				array( 'id' => (int) $submission->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			$submission_id = (int) $submission->id;
		} else {
			// Estudiante nunca entregó la original — crear fila de entrega vacía.
			$wpdb->insert(
				$tsb,
				array(
					'assignment_id'         => $assignment_id,
					'student_id'            => (int) $student->id,
					'status'                => 'pending',
					'recovery_comment'      => $comment,
					'recovery_status'       => 'submitted',
					'recovery_submitted_at' => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			$submission_id = (int) $wpdb->insert_id;
		}

		// Subir archivos de mejora.
		if ( ! empty( $_FILES['archivos_recovery']['name'][0] ) ) {
			self::handle_recovery_files( $submission_id, $_FILES['archivos_recovery'], $assignment_id );
		}

		self::redirect_public( $assignment_id, 'recovery_submitted' );
	}

	/* -------------------------------------------------------------------------
	 * Descarga de archivo de entrega
	 * ---------------------------------------------------------------------- */

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
		$p    = $wpdb->prefix . 'edu_';
		$tsf  = $p . 'submission_files';
		$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsf WHERE id = %d", $file_id ) );
		if ( ! $file ) {
			wp_die( esc_html__( 'Archivo no encontrado.', 'sistema-educativo' ) );
		}

		// Verificación de propiedad: solo el estudiante dueño de la entrega,
		// sus representantes, el docente de la tarea (propietario o asignado
		// al grado/materia) o el rector pueden descargar.
		$sub = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.student_id, a.id AS assignment_id, a.teacher_id, a.grade_id, a.subject_id
			 FROM {$p}submissions s
			 INNER JOIN {$p}assignments a ON a.id = s.assignment_id
			 WHERE s.id = %d",
			(int) $file->submission_id
		) );
		if ( ! $sub ) {
			wp_die( esc_html__( 'Archivo no encontrado.', 'sistema-educativo' ) );
		}

		$uid     = get_current_user_id();
		$allowed = current_user_can( 'manage_options' ) || Edu_Context::can( 'edu_view_all' );

		if ( ! $allowed ) {
			// Estudiante dueño de la entrega.
			$allowed = (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$p}students WHERE id = %d AND user_id = %d LIMIT 1",
				(int) $sub->student_id, $uid
			) );
		}
		if ( ! $allowed ) {
			// Representante del estudiante dueño.
			$allowed = (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT ps.id FROM {$p}parents pa
				 INNER JOIN {$p}parent_student ps ON ps.parent_id = pa.id
				 WHERE pa.user_id = %d AND ps.student_id = %d LIMIT 1",
				$uid, (int) $sub->student_id
			) );
		}
		if ( ! $allowed && Edu_Context::can( 'edu_grade_students' ) ) {
			// Docente: propietario de la tarea o asignado al grado/materia.
			$teacher_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$p}teachers WHERE user_id = %d", $uid
			) );
			if ( $teacher_id ) {
				$allowed = ( (int) $sub->teacher_id === $teacher_id )
					|| (bool) $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$p}teacher_assignments
						 WHERE teacher_id = %d AND grade_id = %d AND subject_id = %d AND is_active = 1
						 LIMIT 1",
						$teacher_id, (int) $sub->grade_id, (int) $sub->subject_id
					) );
			}
		}
		if ( ! $allowed ) {
			wp_die( esc_html__( 'Sin permiso para descargar este archivo.', 'sistema-educativo' ) );
		}

		$upload_dir = wp_upload_dir();
		$path       = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $file->file_url );
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Archivo no disponible.', 'sistema-educativo' ) );
		}

		$mime = mime_content_type( $path ) ?: 'application/octet-stream';
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $file->file_name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
		exit;
	}

	public static function get_sub_download_url( $file_id ) {
		return add_query_arg( array(
			'action'   => 'edu_download_sub_file',
			'file_id'  => (int) $file_id,
			'_wpnonce' => wp_create_nonce( 'edu_download_sub_file_' . (int) $file_id ),
		), admin_url( 'admin-post.php' ) );
	}

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	private static function handle_submission_files( $submission_id, $files_array, $assignment_id ) {
		global $wpdb;
		$tsf        = $wpdb->prefix . 'edu_submission_files';
		$upload_dir = wp_upload_dir();
		Edu_Assignment_Task_Controller::ensure_protected_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] )
			. Edu_Assignment_Task_Controller::UPLOAD_SUBDIR
			. '/submissions/' . (int) $assignment_id . '/' . (int) $submission_id . '/';

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		$count = is_array( $files_array['name'] ) ? count( $files_array['name'] ) : 0;
		for ( $i = 0; $i < $count; $i++ ) {
			if ( UPLOAD_ERR_OK !== $files_array['error'][ $i ] ) {
				continue;
			}
			if ( $files_array['size'][ $i ] > Edu_Assignment_Task_Controller::MAX_FILE_SIZE ) {
				continue;
			}
			$orig_name = sanitize_file_name( $files_array['name'][ $i ] );
			$ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
			if ( ! array_key_exists( $ext, Edu_Assignment_Task_Controller::ALLOWED_TYPES ) ) {
				continue;
			}
			$unique_name = wp_unique_filename( $target_dir, $orig_name );
			$target_path = $target_dir . $unique_name;

			if ( ! move_uploaded_file( $files_array['tmp_name'][ $i ], $target_path ) ) {
				continue;
			}

			$file_url = trailingslashit( $upload_dir['baseurl'] )
				. Edu_Assignment_Task_Controller::UPLOAD_SUBDIR
				. '/submissions/' . (int) $assignment_id . '/' . (int) $submission_id . '/' . $unique_name;

			$wpdb->insert(
				$tsf,
				array(
					'submission_id' => $submission_id,
					'file_url'      => $file_url,
					'file_name'     => $orig_name,
					'file_type'     => $ext,
					'file_size'     => (int) $files_array['size'][ $i ],
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			);
		}
	}

	private static function handle_recovery_files( $submission_id, $files_array, $assignment_id ) {
		global $wpdb;
		$tsf        = $wpdb->prefix . 'edu_submission_files';
		$upload_dir = wp_upload_dir();
		Edu_Assignment_Task_Controller::ensure_protected_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] )
			. Edu_Assignment_Task_Controller::UPLOAD_SUBDIR
			. '/submissions/' . (int) $assignment_id . '/' . (int) $submission_id . '/recovery/';

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		$count = is_array( $files_array['name'] ) ? count( $files_array['name'] ) : 0;
		for ( $i = 0; $i < $count; $i++ ) {
			if ( UPLOAD_ERR_OK !== $files_array['error'][ $i ] ) {
				continue;
			}
			if ( $files_array['size'][ $i ] > Edu_Assignment_Task_Controller::MAX_FILE_SIZE ) {
				continue;
			}
			$orig_name = sanitize_file_name( $files_array['name'][ $i ] );
			$ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
			if ( ! array_key_exists( $ext, Edu_Assignment_Task_Controller::ALLOWED_TYPES ) ) {
				continue;
			}
			$unique_name = wp_unique_filename( $target_dir, $orig_name );
			$target_path = $target_dir . $unique_name;
			if ( ! move_uploaded_file( $files_array['tmp_name'][ $i ], $target_path ) ) {
				continue;
			}
			$file_url = trailingslashit( $upload_dir['baseurl'] )
				. Edu_Assignment_Task_Controller::UPLOAD_SUBDIR
				. '/submissions/' . (int) $assignment_id . '/' . (int) $submission_id . '/recovery/' . $unique_name;

			$wpdb->insert(
				$tsf,
				array(
					'submission_id' => $submission_id,
					'file_url'      => $file_url,
					'file_name'     => 'mejora_' . $orig_name,
					'file_type'     => $ext,
					'file_size'     => (int) $files_array['size'][ $i ],
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			);
		}
	}

	private static function redirect_public( $assignment_id, $status, $code = '' ) {
		$args = array(
			'edu_tab'    => 'tareas',
			'edu_status' => $status,
		);
		if ( $code ) {
			$args['edu_code'] = $code;
		}

		// Usar la URL de retorno enviada en el formulario (_redirect).
		$base = '';
		if ( ! empty( $_POST['_redirect'] ) ) {
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) );
		}
		if ( ! $base ) {
			$page = get_option( 'edu_portal_estudiante_page_id' );
			$base = $page ? get_permalink( (int) $page ) : home_url();
		}

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}

	private static function redirect_admin( $assignment_id, $status, $code = '' ) {
		if ( ! empty( $_POST['_redirect'] ) ) {
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) );
			$fe   = array( 'edu_tab' => 'tareas', 'edu_status' => $status );
			if ( $assignment_id ) $fe['edu_tarea_id'] = $assignment_id;
			if ( $code )          $fe['edu_code']     = $code;
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
