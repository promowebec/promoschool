<?php
/**
 * Servicio: registro de asistencia.
 *
 * Guarda el día completo de un grado con un UPSERT por
 * (student_id, subject_id, date), que es la clave única del esquema.
 *
 * Semántica de "día completo": los estudiantes del grado que no vengan en la
 * petición se marcan PRESENTE. Es lo que hace el formulario de wp-admin (los
 * radios sin marcar no se envían) y lo que corresponde a un PUT.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Attendance_Service {

	const VALID_STATUSES = array( 'presente', 'atraso', 'falta_justificada', 'falta_injustificada' );

	/**
	 * Guarda la asistencia de un grado en una fecha.
	 *
	 * @param array $input {
	 *     @type int    $grade_id
	 *     @type int    $subject_id 0 o ausente = asistencia diaria general.
	 *     @type string $date       YYYY-MM-DD; por defecto hoy.
	 *     @type array  $students   Lista de array( student_id, status, justification ).
	 * }
	 * @return array|WP_Error
	 */
	public static function save( array $input ) {
		$cap = Edu_Service::require_cap( array( 'edu_take_attendance', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$grade_id   = isset( $input['grade_id'] ) ? (int) $input['grade_id'] : 0;
		$subject_id = isset( $input['subject_id'] ) ? (int) $input['subject_id'] : 0;

		if ( ! $grade_id ) {
			return Edu_Service::error( 'grade_required', __( 'Falta indicar el grado.', 'sistema-educativo' ), 400 );
		}

		$scope = Edu_Service::check_scope( array( 'grade_id' => $grade_id ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		// Un docente solo toma asistencia en los grados que tiene asignados.
		if ( ! Edu_Service::sees_whole_institution() && ! in_array( $grade_id, Edu_Service::own_grade_ids(), true ) ) {
			return Edu_Service::out_of_scope();
		}

		if ( $subject_id ) {
			$subject_scope = Edu_Service::check_scope( array( 'subject_id' => $subject_id ) );
			if ( is_wp_error( $subject_scope ) ) {
				return $subject_scope;
			}
		}

		$date = self::normalize_date( $input['date'] ?? '' );
		if ( is_wp_error( $date ) ) {
			return $date;
		}

		$teacher_id = self::resolve_teacher_id();
		if ( is_wp_error( $teacher_id ) ) {
			return $teacher_id;
		}

		global $wpdb;
		$tat = $wpdb->prefix . 'edu_attendance';

		// Todos los estudiantes del grado, en el mismo orden que la pantalla.
		$student_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}edu_students WHERE grade_id = %d ORDER BY id", $grade_id )
			)
		);

		// Lo que llega, indexado por estudiante.
		$posted = array();
		foreach ( (array) ( $input['students'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['student_id'] ) ) {
				continue;
			}
			$posted[ (int) $row['student_id'] ] = $row;
		}

		$saved    = 0;
		$absences = 0;

		foreach ( $student_ids as $sid ) {
			$row = $posted[ $sid ] ?? array();

			$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : 'presente';
			if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
				$status = 'presente';
			}

			$justification = isset( $row['justification'] )
				? sanitize_textarea_field( (string) $row['justification'] )
				: '';

			// subject_id NULL debe ser NULL literal, no cadena vacía.
			if ( $subject_id > 0 ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO $tat (student_id, subject_id, teacher_id, date, status, justification)
						 VALUES (%d, %d, %d, %s, %s, %s)
						 ON DUPLICATE KEY UPDATE
						   status        = VALUES(status),
						   justification = VALUES(justification),
						   teacher_id    = VALUES(teacher_id)",
						$sid,
						$subject_id,
						$teacher_id,
						$date,
						$status,
						$justification
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO $tat (student_id, subject_id, teacher_id, date, status, justification)
						 VALUES (%d, NULL, %d, %s, %s, %s)
						 ON DUPLICATE KEY UPDATE
						   status        = VALUES(status),
						   justification = VALUES(justification),
						   teacher_id    = VALUES(teacher_id)",
						$sid,
						$teacher_id,
						$date,
						$status,
						$justification
					)
				);
			}

			$saved++;

			// Solo se notifica desde la asistencia general, para no duplicar
			// un aviso por cada materia del día.
			if ( 0 === $subject_id && in_array( $status, array( 'falta_justificada', 'falta_injustificada' ), true ) ) {
				$absences++;
				do_action( 'edu_attendance_absence', $sid, $date, $status );
			}
		}

		Edu_Audit::log(
			Edu_Audit::ASISTENCIA_GUARDADA,
			'attendance',
			$grade_id,
			null,
			array(
				'grado_id'    => $grade_id,
				'materia_id'  => $subject_id ?: 'general',
				'fecha'       => $date,
				'estudiantes' => count( $student_ids ),
			)
		);

		return array(
			'grade_id'   => $grade_id,
			'subject_id' => $subject_id,
			'date'       => $date,
			'saved'      => $saved,
			'absences'   => $absences,
		);
	}

	/**
	 * Convierte la matriz del formulario de admin
	 * (attendance[sid] = estado, justification[sid] = texto) a la lista que
	 * espera save().
	 *
	 * @param array $statuses       Mapa student_id => estado.
	 * @param array $justifications Mapa student_id => justificación.
	 * @return array
	 */
	public static function flatten_matrix( array $statuses, array $justifications = array() ) {
		$rows = array();

		foreach ( $statuses as $sid => $status ) {
			$rows[] = array(
				'student_id'    => (int) $sid,
				'status'        => $status,
				'justification' => $justifications[ $sid ] ?? '',
			);
		}

		// Justificaciones de estudiantes sin estado explícito.
		foreach ( $justifications as $sid => $justification ) {
			if ( ! isset( $statuses[ $sid ] ) ) {
				$rows[] = array(
					'student_id'    => (int) $sid,
					'justification' => $justification,
				);
			}
		}

		return $rows;
	}

	/* ─── Internos ──────────────────────────────────────────────────────── */

	/**
	 * Fecha válida en formato MySQL.
	 *
	 * @param string $raw Fecha cruda.
	 * @return string|WP_Error
	 */
	private static function normalize_date( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return current_time( 'Y-m-d' );
		}

		$timestamp = strtotime( $raw );
		if ( ! $timestamp ) {
			return Edu_Api::invalid_params(
				array( 'date' => __( 'La fecha no es válida. Usa el formato YYYY-MM-DD.', 'sistema-educativo' ) )
			);
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Docente que firma el registro.
	 *
	 * attendance.teacher_id es NOT NULL, así que un rector sin ficha de docente
	 * usa como respaldo el primer docente del sistema (comportamiento heredado).
	 *
	 * @return int|WP_Error
	 */
	private static function resolve_teacher_id() {
		$identity   = Edu_Service::identity();
		$teacher_id = $identity['teacher_id'];

		if ( ! $teacher_id && ! Edu_Context::can( 'edu_view_all' ) ) {
			return Edu_Service::error(
				'no_teacher',
				__( 'Tu usuario no tiene ficha de docente.', 'sistema-educativo' ),
				409
			);
		}

		if ( ! $teacher_id ) {
			global $wpdb;
			$teacher_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}edu_teachers ORDER BY id LIMIT 1" );
		}

		if ( ! $teacher_id ) {
			return Edu_Service::error(
				'no_teacher',
				__( 'No hay ningún docente registrado en el sistema.', 'sistema-educativo' ),
				409
			);
		}

		return $teacher_id;
	}
}
