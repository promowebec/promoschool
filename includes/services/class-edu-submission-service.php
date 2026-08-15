<?php
/**
 * Servicio: entregas de estudiantes y su calificación.
 *
 * Incluye el circuito de mejora/recuperación: el docente la habilita sobre una
 * tarea ya cerrada, el estudiante vuelve a entregar y al calificar se guarda la
 * MEJOR de las dos notas en el registro de calificaciones.
 *
 * Al calificar, si la tarea está vinculada a un componente evaluable, la nota
 * se normaliza a la escala 0–10 y se escribe en wp_edu_grades_log, que es lo
 * que hace que una tarea "cuente" para el parcial.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Submission_Service {

	/* ─────────────────────────────────────────────────────────────────────
	 * Entrega del estudiante
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Registra la entrega de una tarea.
	 *
	 * @param array $input {
	 *     @type int    $assignment_id
	 *     @type string $comment
	 *     @type array  $files Estructura $_FILES.
	 * }
	 * @return array|WP_Error
	 */
	public static function submit( array $input ) {
		$cap = Edu_Service::require_cap( 'edu_submit_assignment' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$assignment_id = isset( $input['assignment_id'] ) ? (int) $input['assignment_id'] : 0;
		$comment       = wp_kses_post( (string) ( $input['comment'] ?? '' ) );

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}assignments WHERE id = %d", $assignment_id ) );

		if ( ! $assignment || 'published' !== $assignment->status ) {
			return Edu_Service::error(
				'assignment_not_available',
				__( 'La tarea no está disponible para entregas.', 'sistema-educativo' ),
				409
			);
		}

		$student_id = self::own_student_id();
		if ( is_wp_error( $student_id ) ) {
			return $student_id;
		}

		// El estudiante debe pertenecer al grado de la tarea.
		$in_grade = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}students WHERE id = %d AND grade_id = %d",
				$student_id,
				(int) $assignment->grade_id
			)
		);

		if ( ! $in_grade ) {
			return Edu_Service::out_of_scope();
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$p}submissions WHERE assignment_id = %d AND student_id = %d",
				$assignment_id,
				$student_id
			)
		);

		/*
		 * Una tarea se entrega UNA vez.
		 *
		 * Antes se podia reenviar mientras no estuviera calificada, y eso hacia
		 * que al docente le constaran varias entregas del mismo estudiante. Si
		 * hace falta una segunda oportunidad, el canal es la recuperacion, que
		 * el docente habilita a proposito con su propia fecha limite.
		 *
		 * `returned` si admite reenvio: significa que el docente devolvio el
		 * trabajo pidiendo correcciones, o sea que la segunda entrega la pidio el.
		 */
		if ( $existing && in_array( $existing->status, array( 'submitted', 'late', 'graded' ), true ) ) {
			return Edu_Service::error(
				'already_submitted',
				'graded' === $existing->status
					? __( 'Tu entrega ya fue calificada y no admite cambios.', 'sistema-educativo' )
					: __( 'Ya entregaste esta tarea. Si necesitas volver a entregarla, pídele a tu docente que habilite la recuperación.', 'sistema-educativo' ),
				409
			);
		}

		$status = ( $assignment->due_date && strtotime( $assignment->due_date ) < time() ) ? 'late' : 'submitted';
		$now    = current_time( 'mysql' );

		if ( $existing ) {
			$submission_id = (int) $existing->id;

			$wpdb->update(
				$p . 'submissions',
				array(
					'comment'      => $comment,
					'status'       => $status,
					'submitted_at' => $now,
				),
				array( 'id' => $submission_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			// Re-entrega: los archivos anteriores se reemplazan.
			self::purge_files( $submission_id );
		} else {
			$wpdb->insert(
				$p . 'submissions',
				array(
					'assignment_id' => $assignment_id,
					'student_id'    => $student_id,
					'comment'       => $comment,
					'status'        => $status,
					'submitted_at'  => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);

			$submission_id = (int) $wpdb->insert_id;
		}

		if ( ! $submission_id ) {
			return Edu_Service::error( 'db_error', __( 'No se pudo guardar la entrega.', 'sistema-educativo' ), 500 );
		}

		$uploaded = self::store_files(
			$submission_id,
			$assignment_id,
			$input['files'] ?? array(),
			false
		);

		return array(
			'id'            => $submission_id,
			'assignment_id' => $assignment_id,
			'status'        => $status,
			'is_late'       => 'late' === $status,
			'files_added'   => $uploaded,
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Calificación
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Califica una entrega.
	 *
	 * @param array $input submission_id, score, feedback.
	 * @return array|WP_Error
	 */
	public static function grade( array $input ) {
		$cap = Edu_Service::require_cap( array( 'edu_grade_students', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$submission_id = isset( $input['submission_id'] ) ? (int) $input['submission_id'] : 0;
		$feedback      = wp_kses_post( (string) ( $input['feedback'] ?? '' ) );

		$context = self::load_for_grading( $submission_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		list( $submission, $assignment ) = $context;

		/*
		 * Una entrega se califica UNA vez.
		 *
		 * La nota de una entrega tiene respaldo: el archivo que subio el
		 * estudiante. Recalificarla a mano borra ese vinculo sin dejar rastro de
		 * por que cambio. Para dar otra oportunidad estan la recuperacion y la
		 * entrega atrasada, que son canales explicitos y quedan registrados.
		 *
		 * Si de verdad hubo un error al calificar, el docente devuelve el trabajo
		 * (`returned`) y vuelve a empezar; eso si queda documentado.
		 */
		if ( 'graded' === $submission->status ) {
			return Edu_Service::error(
				'already_graded',
				__( 'Esta entrega ya fue calificada. Para dar otra oportunidad, habilita la recuperación de la tarea; para corregir un error, devuelve el trabajo al estudiante.', 'sistema-educativo' ),
				409
			);
		}

		$score = Edu_Service::parse_score( $input['score'] ?? '' );
		if ( null !== $score && ( $score < 0 || $score > (float) $assignment->max_score ) ) {
			return Edu_Service::error(
				'invalid_score',
				sprintf(
					/* translators: %s: nota máxima de la tarea. */
					__( 'La nota debe estar entre 0 y %s.', 'sistema-educativo' ),
					number_format( (float) $assignment->max_score, 2 )
				),
				400
			);
		}

		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'edu_submissions',
			array(
				'score'     => $score,
				'feedback'  => $feedback,
				'status'    => 'graded',
				'graded_at' => current_time( 'mysql' ),
				'graded_by' => get_current_user_id(),
			),
			array( 'id' => $submission_id ),
			array( '%f', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		$normalized = null;
		if ( null !== $score && ! empty( $assignment->component_id ) ) {
			$normalized = self::log_component_score(
				(int) $submission->student_id,
				(int) $assignment->component_id,
				(int) $assignment->id,
				$score,
				(float) $assignment->max_score
			);
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

		return array(
			'id'              => $submission_id,
			'assignment_id'   => (int) $assignment->id,
			'score'           => $score,
			'normalized_score' => $normalized,
			'status'          => 'graded',
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Mejora / recuperación
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Habilita o deshabilita la mejora sobre una tarea cerrada.
	 *
	 * @param array $input assignment_id, allow_recovery, recovery_due_date.
	 * @return array|WP_Error
	 */
	/**
	 * Devuelve una entrega al estudiante para que la corrija y la reenvíe.
	 *
	 * Es la única forma de deshacer una calificación, y existe justamente porque
	 * recalificar en silencio no deja rastro de por qué cambió la nota. Aquí sí:
	 * la entrega vuelve a `returned`, se borra la nota que había puesto en
	 * `grades_log` —para que no siga contando en el promedio—, se recalcula el
	 * parcial y queda auditado.
	 *
	 * @param array $input submission_id, comment (motivo, opcional).
	 * @return array|WP_Error
	 */
	public static function return_to_student( array $input ) {
		$cap = Edu_Service::require_cap( array( 'edu_grade_students', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$submission_id = isset( $input['submission_id'] ) ? (int) $input['submission_id'] : 0;
		$motivo        = wp_kses_post( (string) ( $input['comment'] ?? '' ) );

		$context = self::load_for_grading( $submission_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		list( $submission, $assignment ) = $context;

		if ( 'returned' === $submission->status ) {
			return Edu_Service::error(
				'already_returned',
				__( 'Esta entrega ya está devuelta.', 'sistema-educativo' ),
				409
			);
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$nota_previa = $submission->score;

		$wpdb->update(
			$p . 'submissions',
			array(
				'status'    => 'returned',
				'score'     => null,
				'feedback'  => $motivo ? $motivo : $submission->feedback,
				'graded_at' => null,
				'graded_by' => null,
			),
			array( 'id' => $submission_id ),
			array( '%s', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		// La nota deja de contar en el promedio del componente.
		if ( ! empty( $assignment->component_id ) ) {
			$wpdb->delete(
				$p . 'grades_log',
				array(
					'student_id'    => (int) $submission->student_id,
					'component_id'  => (int) $assignment->component_id,
					'assignment_id' => (int) $assignment->id,
				),
				array( '%d', '%d', '%d' )
			);

			require_once EDU_PLUGIN_DIR . 'modules/calificaciones/class-edu-grade-calculator.php';
			Edu_Grade_Calculator::recalculate_parcial(
				(int) $submission->student_id,
				(int) $assignment->subject_id,
				(int) $assignment->trimester_id,
				(int) $assignment->parcial_num
			);
		}

		Edu_Audit::log(
			Edu_Audit::NOTA_INGRESADA,
			'entrega',
			$submission_id,
			array( 'score' => $nota_previa, 'status' => $submission->status ),
			array(
				'accion'     => 'entrega_devuelta',
				'tarea_id'   => (int) $assignment->id,
				'estudiante' => (int) $submission->student_id,
				'motivo'     => $motivo,
			)
		);

		return array(
			'submission_id' => $submission_id,
			'status'        => 'returned',
			'score'         => null,
		);
	}

	public static function save_recovery_settings( array $input ) {
		$cap = Edu_Service::require_cap( array( 'edu_grade_students', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$assignment_id  = isset( $input['assignment_id'] ) ? (int) $input['assignment_id'] : 0;
		$allow_recovery = ! empty( $input['allow_recovery'] ) ? 1 : 0;
		$raw_date       = sanitize_text_field( (string) ( $input['recovery_due_date'] ?? '' ) );

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}assignments WHERE id = %d", $assignment_id ) );

		// La mejora solo se abre sobre una tarea ya cerrada.
		if ( ! $assignment || 'closed' !== $assignment->status ) {
			return Edu_Service::error(
				'assignment_not_available',
				__( 'La mejora solo puede activarse en una tarea cerrada.', 'sistema-educativo' ),
				409
			);
		}

		$scope = Edu_Service::can_view_grade_subject( (int) $assignment->grade_id, (int) $assignment->subject_id );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$due = $raw_date ? gmdate( 'Y-m-d H:i:s', strtotime( str_replace( 'T', ' ', $raw_date ) ) ) : null;

		$wpdb->update(
			$p . 'assignments',
			array(
				'allow_recovery'    => $allow_recovery,
				'recovery_due_date' => $due,
			),
			array( 'id' => $assignment_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		// Abrir o cerrar la mejora en las entregas que aún no la usaron.
		if ( $allow_recovery ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}submissions SET recovery_status = 'available'
					 WHERE assignment_id = %d AND recovery_status = 'none'",
					$assignment_id
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}submissions SET recovery_status = 'none'
					 WHERE assignment_id = %d AND recovery_status = 'available'",
					$assignment_id
				)
			);
		}

		return array(
			'assignment_id'     => $assignment_id,
			'allow_recovery'    => (bool) $allow_recovery,
			'recovery_due_date' => Edu_Api::date( $due ),
		);
	}

	/**
	 * Entrega de mejora por parte del estudiante.
	 *
	 * @param array $input assignment_id, comment, files.
	 * @return array|WP_Error
	 */
	public static function submit_recovery( array $input ) {
		$cap = Edu_Service::require_cap( 'edu_submit_assignment' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$assignment_id = isset( $input['assignment_id'] ) ? (int) $input['assignment_id'] : 0;
		$comment       = wp_kses_post( (string) ( $input['comment'] ?? '' ) );

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}assignments WHERE id = %d", $assignment_id ) );

		if ( ! $assignment || ! $assignment->allow_recovery ) {
			return Edu_Service::error(
				'recovery_not_available',
				__( 'Esta tarea no tiene la mejora habilitada.', 'sistema-educativo' ),
				409
			);
		}

		if ( $assignment->recovery_due_date && strtotime( $assignment->recovery_due_date ) < time() ) {
			return Edu_Service::error(
				'recovery_expired',
				__( 'El plazo para la mejora ya venció.', 'sistema-educativo' ),
				409
			);
		}

		$student_id = self::own_student_id();
		if ( is_wp_error( $student_id ) ) {
			return $student_id;
		}

		$submission = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$p}submissions WHERE assignment_id = %d AND student_id = %d",
				$assignment_id,
				$student_id
			)
		);

		if ( $submission && 'graded' === $submission->recovery_status ) {
			return Edu_Service::error(
				'recovery_already_graded',
				__( 'Tu mejora ya fue calificada.', 'sistema-educativo' ),
				409
			);
		}

		$now = current_time( 'mysql' );

		if ( $submission ) {
			$submission_id = (int) $submission->id;

			$wpdb->update(
				$p . 'submissions',
				array(
					'recovery_comment'      => $comment,
					'recovery_status'       => 'submitted',
					'recovery_submitted_at' => $now,
				),
				array( 'id' => $submission_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// El estudiante nunca entregó la original: se crea la fila.
			$wpdb->insert(
				$p . 'submissions',
				array(
					'assignment_id'         => $assignment_id,
					'student_id'            => $student_id,
					'status'                => 'pending',
					'recovery_comment'      => $comment,
					'recovery_status'       => 'submitted',
					'recovery_submitted_at' => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s' )
			);

			$submission_id = (int) $wpdb->insert_id;
		}

		if ( ! $submission_id ) {
			return Edu_Service::error( 'db_error', __( 'No se pudo guardar la mejora.', 'sistema-educativo' ), 500 );
		}

		$uploaded = self::store_files( $submission_id, $assignment_id, $input['files'] ?? array(), true );

		return array(
			'id'            => $submission_id,
			'assignment_id' => $assignment_id,
			'files_added'   => $uploaded,
		);
	}

	/**
	 * Califica la mejora. En el registro de notas queda la MEJOR de las dos.
	 *
	 * @param array $input submission_id, recovery_score, recovery_feedback.
	 * @return array|WP_Error
	 */
	public static function grade_recovery( array $input ) {
		$cap = Edu_Service::require_cap( array( 'edu_grade_students', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$submission_id = isset( $input['submission_id'] ) ? (int) $input['submission_id'] : 0;
		$feedback      = wp_kses_post( (string) ( $input['recovery_feedback'] ?? '' ) );

		$context = self::load_for_grading( $submission_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		list( $submission, $assignment ) = $context;

		$recovery_score = Edu_Service::parse_score( $input['recovery_score'] ?? '' );
		if ( null !== $recovery_score && ( $recovery_score < 0 || $recovery_score > (float) $assignment->max_score ) ) {
			return Edu_Service::error(
				'invalid_score',
				sprintf(
					/* translators: %s: nota máxima de la tarea. */
					__( 'La nota debe estar entre 0 y %s.', 'sistema-educativo' ),
					number_format( (float) $assignment->max_score, 2 )
				),
				400
			);
		}

		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'edu_submissions',
			array(
				'recovery_score'    => $recovery_score,
				'recovery_feedback' => $feedback,
				'recovery_status'   => 'graded',
			),
			array( 'id' => $submission_id ),
			array( '%f', '%s', '%s' ),
			array( '%d' )
		);

		$best = null;
		if ( null !== $recovery_score && ! empty( $assignment->component_id ) ) {
			$max      = (float) $assignment->max_score;
			$original = null !== $submission->score ? (float) $submission->score : 0.0;

			// Se guarda la mejor de las dos, ya normalizada a 0–10.
			$best = self::log_component_score(
				(int) $submission->student_id,
				(int) $assignment->component_id,
				(int) $assignment->id,
				max( $original, $recovery_score ),
				$max
			);
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

		return array(
			'id'               => $submission_id,
			'assignment_id'    => (int) $assignment->id,
			'recovery_score'   => $recovery_score,
			'normalized_score' => $best,
			'recovery_status'  => 'graded',
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Descarga
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * ¿Puede el usuario actual descargar este archivo de entrega?
	 *
	 * Lo pueden ver el estudiante dueño, sus representantes, el docente de la
	 * tarea (propietario o asignado al grado y materia) y el rectorado.
	 *
	 * @param object $sub Fila con student_id, teacher_id, grade_id, subject_id.
	 * @return bool
	 */
	public static function can_download( $sub ) {
		if ( current_user_can( 'manage_options' ) || Edu_Context::can( 'edu_view_all' ) ) {
			return true;
		}

		$identity   = Edu_Service::identity();
		$student_id = (int) $sub->student_id;

		if ( $identity['student_id'] && $identity['student_id'] === $student_id ) {
			return true;
		}

		if ( $identity['parent_id'] && in_array( $student_id, Edu_Service::own_children_ids(), true ) ) {
			return true;
		}

		if ( $identity['teacher_id'] && Edu_Context::can( 'edu_grade_students' ) ) {
			if ( (int) $sub->teacher_id === $identity['teacher_id'] ) {
				return true;
			}

			return Edu_Service::teacher_has_assignment( (int) $sub->subject_id, (int) $sub->grade_id );
		}

		return false;
	}

	/* ─── Internos ──────────────────────────────────────────────────────── */

	/**
	 * Estudiante correspondiente al usuario actual.
	 *
	 * @return int|WP_Error
	 */
	private static function own_student_id() {
		$identity = Edu_Service::identity();

		if ( ! $identity['student_id'] ) {
			return Edu_Service::error(
				'not_a_student',
				__( 'Tu usuario no tiene ficha de estudiante.', 'sistema-educativo' ),
				409
			);
		}

		return $identity['student_id'];
	}

	/**
	 * Carga la entrega y su tarea, validando que el usuario pueda calificarla.
	 *
	 * @param int $submission_id Entrega.
	 * @return array|WP_Error array( $submission, $assignment )
	 */
	private static function load_for_grading( $submission_id ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}submissions WHERE id = %d", (int) $submission_id ) );
		if ( ! $submission ) {
			return Edu_Service::not_found( __( 'La entrega no existe.', 'sistema-educativo' ) );
		}

		$assignment = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$p}assignments WHERE id = %d", (int) $submission->assignment_id )
		);
		if ( ! $assignment ) {
			return Edu_Service::not_found( __( 'La tarea de esta entrega no existe.', 'sistema-educativo' ) );
		}

		// Solo se califica dentro del propio alcance.
		$scope = Edu_Service::can_view_grade_subject( (int) $assignment->grade_id, (int) $assignment->subject_id );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return array( $submission, $assignment );
	}

	/**
	 * Escribe la nota en grades_log normalizada a 0–10 y dispara el recálculo.
	 *
	 * Reemplaza la entrada previa de esa misma tarea para no acumular notas
	 * duplicadas al recalificar.
	 *
	 * @param int   $student_id    Estudiante.
	 * @param int   $component_id  Componente evaluable.
	 * @param int   $assignment_id Tarea.
	 * @param float $score         Nota en la escala de la tarea.
	 * @param float $max_score     Nota máxima de la tarea.
	 * @return float Nota normalizada.
	 */
	private static function log_component_score( $student_id, $component_id, $assignment_id, $score, $max_score ) {
		global $wpdb;
		$tgl = $wpdb->prefix . 'edu_grades_log';

		$normalized = $max_score > 0 ? round( $score * 10.00 / $max_score, 2 ) : $score;
		$normalized = max( 0.00, min( 10.00, $normalized ) );

		$wpdb->delete(
			$tgl,
			array(
				'student_id'    => $student_id,
				'component_id'  => $component_id,
				'assignment_id' => $assignment_id,
			),
			array( '%d', '%d', '%d' )
		);

		$wpdb->insert(
			$tgl,
			array(
				'student_id'    => $student_id,
				'component_id'  => $component_id,
				'assignment_id' => $assignment_id,
				'score'         => $normalized,
				'registered_by' => get_current_user_id(),
			),
			array( '%d', '%d', '%d', '%f', '%d' )
		);

		do_action( 'edu_grade_logged', $student_id, $component_id, $normalized );

		return $normalized;
	}

	/**
	 * Guarda los archivos de una entrega (o de su mejora).
	 *
	 * @param int   $submission_id Entrega.
	 * @param int   $assignment_id Tarea.
	 * @param array $files         Estructura $_FILES.
	 * @param bool  $is_recovery   Si son archivos de mejora.
	 * @return int Cuántos se guardaron.
	 */
	private static function store_files( $submission_id, $assignment_id, $files, $is_recovery ) {
		if ( empty( $files ) || empty( $files['name'] ) ) {
			return 0;
		}

		$folder = 'submissions/' . (int) $assignment_id . '/' . (int) $submission_id . ( $is_recovery ? '/recovery' : '' );

		global $wpdb;
		$tsf   = $wpdb->prefix . 'edu_submission_files';
		$saved = 0;

		foreach ( Edu_File_Service::store_uploads( $folder, $files ) as $file ) {
			$wpdb->insert(
				$tsf,
				array(
					'submission_id' => (int) $submission_id,
					'file_url'      => $file['file_url'],
					'file_name'     => $is_recovery ? 'mejora_' . $file['file_name'] : $file['file_name'],
					'file_type'     => $file['file_type'],
					'file_size'     => $file['file_size'],
				),
				array( '%d', '%s', '%s', '%s', '%d' )
			);
			$saved++;
		}

		return $saved;
	}

	/**
	 * Borra los archivos de una entrega (al re-entregar).
	 *
	 * @param int $submission_id Entrega.
	 */
	private static function purge_files( $submission_id ) {
		global $wpdb;
		$tsf = $wpdb->prefix . 'edu_submission_files';

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, file_url FROM $tsf WHERE submission_id = %d", (int) $submission_id ) ) as $file ) {
			Edu_File_Service::delete_physical( $file->file_url );
			$wpdb->delete( $tsf, array( 'id' => (int) $file->id ), array( '%d' ) );
		}
	}
}
