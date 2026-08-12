<?php
/**
 * Base de la capa de servicios.
 *
 * Un servicio contiene la lógica de negocio y NO sabe nada de HTTP: no lee
 * $_POST, no escribe salida, no redirige y no llama a wp_die(). Recibe un array
 * ya sanitizado y devuelve un array de resultado o un WP_Error.
 *
 * Sobre los servicios se apoyan dos adaptadores:
 *   - Los controllers `Edu_*_Controller` (formularios de wp-admin y portales).
 *   - Los endpoints REST `Edu_Api_*` (app propia).
 *
 * Así una regla de negocio se escribe una sola vez y vale para las dos caras.
 * Contrato: docs/API_CONTRATO_V1.md §2.3.
 *
 * Los códigos de error son los mismos strings que ya usaban los controllers
 * ('invalid_scope', 'no_components', …) para no romper los mensajes de las
 * vistas de admin. La capa REST les antepone el prefijo `edu_`.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Service {

	/**
	 * Error de servicio con el status HTTP sugerido para la capa REST.
	 *
	 * @param string $code    Código corto y estable.
	 * @param string $message Mensaje legible.
	 * @param int    $status  Status HTTP que le corresponde.
	 * @param array  $details Datos extra.
	 * @return WP_Error
	 */
	public static function error( $code, $message, $status = 400, $details = array() ) {
		$data = array( 'status' => (int) $status );

		if ( ! empty( $details ) ) {
			$data['details'] = $details;
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Exige al menos una de las capabilities indicadas.
	 *
	 * @param string|string[] $caps Capability o lista.
	 * @return true|WP_Error
	 */
	public static function require_cap( $caps ) {
		foreach ( (array) $caps as $cap ) {
			if ( Edu_Context::can( $cap ) ) {
				return true;
			}
		}

		return self::error( 'forbidden', __( 'Sin permiso.', 'sistema-educativo' ), 403 );
	}

	/**
	 * Institución activa, o error si no hay ninguna.
	 *
	 * @return int|WP_Error
	 */
	public static function require_institution() {
		$institution_id = Edu_Context::current_institution_id();

		if ( ! $institution_id ) {
			return self::error( 'no_institution', __( 'No hay institución activa.', 'sistema-educativo' ), 409 );
		}

		return $institution_id;
	}

	/**
	 * Valida que el parcial sea 1 o 2.
	 *
	 * @param int $parcial_num Número de parcial.
	 * @return true|WP_Error
	 */
	public static function validate_parcial( $parcial_num ) {
		if ( ! in_array( (int) $parcial_num, array( 1, 2 ), true ) ) {
			return self::error( 'invalid_parcial', __( 'El parcial debe ser 1 o 2.', 'sistema-educativo' ), 400 );
		}

		return true;
	}

	/**
	 * Verifica que grado, materia y trimestre pertenezcan a la institución activa.
	 *
	 * El Superadmin Editorial pasa siempre: gestiona todas las instituciones.
	 * Solo se comprueban las claves presentes en $ids.
	 *
	 * @param array $ids Con claves opcionales grade_id, subject_id, trimester_id.
	 * @return true|WP_Error
	 */
	public static function check_scope( array $ids ) {
		if ( Edu_Context::is_superadmin_editorial() ) {
			return true;
		}

		$institution_id = Edu_Context::current_institution_id();
		if ( ! $institution_id ) {
			return self::error( 'no_institution', __( 'No hay institución activa.', 'sistema-educativo' ), 409 );
		}

		if ( isset( $ids['grade_id'] ) && self::grade_institution( $ids['grade_id'] ) !== $institution_id ) {
			return self::invalid_scope();
		}

		if ( isset( $ids['subject_id'] ) && self::subject_institution( $ids['subject_id'] ) !== $institution_id ) {
			return self::invalid_scope();
		}

		if ( isset( $ids['trimester_id'] ) && self::trimester_institution( $ids['trimester_id'] ) !== $institution_id ) {
			return self::invalid_scope();
		}

		return true;
	}

	public static function invalid_scope() {
		return self::error(
			'invalid_scope',
			__( 'El recurso no pertenece a la institución activa.', 'sistema-educativo' ),
			403
		);
	}

	/* ─── Lecturas auxiliares ───────────────────────────────────────────── */

	public static function grade_institution( $grade_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT institution_id FROM {$wpdb->prefix}edu_grades WHERE id = %d", (int) $grade_id )
		);
	}

	public static function subject_institution( $subject_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT institution_id FROM {$wpdb->prefix}edu_subjects WHERE id = %d", (int) $subject_id )
		);
	}

	public static function trimester_institution( $trimester_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.institution_id
				 FROM {$wpdb->prefix}edu_trimesters t
				 INNER JOIN {$wpdb->prefix}edu_periods p ON p.id = t.period_id
				 WHERE t.id = %d",
				(int) $trimester_id
			)
		);
	}

	/**
	 * IDs de los estudiantes activos de un grado.
	 *
	 * @param int $grade_id Grado.
	 * @return int[]
	 */
	public static function active_student_ids( $grade_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}edu_students WHERE grade_id = %d AND status = 'active'",
				(int) $grade_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * ¿El grado usa la fórmula sumativa con proyecto? (Instructivo 2025)
	 *
	 * Media, Superior y Bachillerato promedian examen y proyecto en el 30%.
	 *
	 * @param int $grade_id Grado.
	 * @return bool
	 */
	public static function uses_sumativa( $grade_id ) {
		global $wpdb;

		$sub_level = $wpdb->get_var(
			$wpdb->prepare( "SELECT sub_level FROM {$wpdb->prefix}edu_grades WHERE id = %d", (int) $grade_id )
		);

		return in_array( $sub_level, array( 'media', 'superior', 'bg', 'bt' ), true );
	}

	/**
	 * Nombre de la fórmula aplicable, tal como la publica la API.
	 *
	 * @param int $grade_id Grado.
	 * @return string 'sumativa_proyecto' | 'elemental'
	 */
	public static function formula( $grade_id ) {
		return self::uses_sumativa( $grade_id ) ? 'sumativa_proyecto' : 'elemental';
	}

	/**
	 * Convierte una nota escrita por una persona a float, aceptando coma decimal.
	 *
	 * @param mixed $raw Valor crudo.
	 * @return float|null null si viene vacío.
	 */
	public static function parse_score( $raw ) {
		$value = trim( (string) $raw );

		if ( '' === $value ) {
			return null;
		}

		return round( (float) str_replace( ',', '.', $value ), 2 );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Alcance personal (nivel 3 del contrato §5.1)
	 *
	 * Un docente solo ve sus asignaciones, un representante solo a sus hijos y
	 * un estudiante solo sus propios datos. Es el nivel donde vivían los IDORs
	 * cerrados en el hardening v1.0.9, así que se valida siempre en el
	 * servicio, nunca solo en la interfaz.
	 * ────────────────────────────────────────────────────────────────── */

	/** @var array Cache por request de los identificadores del usuario. */
	private static $identity = null;

	/**
	 * Identificadores del usuario actual en las tablas del sistema.
	 *
	 * @return array{teacher_id:int, student_id:int, parent_id:int}
	 */
	public static function identity() {
		if ( null !== self::$identity ) {
			return self::$identity;
		}

		global $wpdb;
		$uid = get_current_user_id();
		$p   = $wpdb->prefix . 'edu_';

		self::$identity = array(
			'teacher_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}teachers WHERE user_id = %d", $uid ) ),
			'student_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}students WHERE user_id = %d", $uid ) ),
			'parent_id'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}parents WHERE user_id = %d", $uid ) ),
		);

		return self::$identity;
	}

	/**
	 * Reinicia la cache de identidad. Solo lo necesitan las pruebas, que
	 * cambian de usuario dentro del mismo proceso.
	 */
	public static function reset_identity() {
		self::$identity = null;
	}

	/**
	 * ¿El usuario ve toda la institución? (rector o Superadmin Editorial)
	 *
	 * @return bool
	 */
	public static function sees_whole_institution() {
		return Edu_Context::can( 'edu_view_all' );
	}

	/**
	 * IDs de los estudiantes hijos del representante actual.
	 *
	 * @return int[]
	 */
	public static function own_children_ids() {
		$identity = self::identity();

		if ( ! $identity['parent_id'] ) {
			return array();
		}

		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT student_id FROM {$wpdb->prefix}edu_parent_student WHERE parent_id = %d",
					$identity['parent_id']
				)
			)
		);
	}

	/**
	 * IDs de los grados donde el docente actual tiene asignación activa.
	 *
	 * @return int[]
	 */
	public static function own_grade_ids() {
		$identity = self::identity();

		if ( ! $identity['teacher_id'] ) {
			return array();
		}

		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT grade_id FROM {$wpdb->prefix}edu_teacher_assignments
					 WHERE teacher_id = %d AND is_active = 1",
					$identity['teacher_id']
				)
			)
		);
	}

	/**
	 * ¿El docente actual tiene asignación activa en esta materia y grado?
	 *
	 * @param int $subject_id Materia.
	 * @param int $grade_id   Grado (0 = cualquiera de sus grados).
	 * @return bool
	 */
	public static function teacher_has_assignment( $subject_id, $grade_id = 0 ) {
		$identity = self::identity();

		if ( ! $identity['teacher_id'] ) {
			return false;
		}

		global $wpdb;
		$sql    = "SELECT COUNT(*) FROM {$wpdb->prefix}edu_teacher_assignments
		           WHERE teacher_id = %d AND subject_id = %d AND is_active = 1";
		$params = array( $identity['teacher_id'], (int) $subject_id );

		if ( $grade_id ) {
			$sql     .= ' AND grade_id = %d';
			$params[] = (int) $grade_id;
		}

		return (bool) (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * ¿Puede el usuario actual ver los datos de este estudiante?
	 *
	 * @param int $student_id Estudiante.
	 * @return true|WP_Error
	 */
	public static function can_view_student( $student_id ) {
		$student_id = (int) $student_id;

		if ( ! $student_id ) {
			return self::error( 'not_found', __( 'El estudiante no existe.', 'sistema-educativo' ), 404 );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.id, s.grade_id, g.institution_id
				 FROM {$p}students s
				 INNER JOIN {$p}grades g ON g.id = s.grade_id
				 WHERE s.id = %d",
				$student_id
			)
		);

		if ( ! $row ) {
			return self::error( 'not_found', __( 'El estudiante no existe.', 'sistema-educativo' ), 404 );
		}

		if ( ! Edu_Context::is_superadmin_editorial() && (int) $row->institution_id !== Edu_Context::current_institution_id() ) {
			return self::invalid_scope();
		}

		if ( self::sees_whole_institution() ) {
			return true;
		}

		$identity = self::identity();

		// El propio estudiante.
		if ( $identity['student_id'] && $identity['student_id'] === $student_id ) {
			return true;
		}

		// Representante de ese estudiante.
		if ( $identity['parent_id'] && in_array( $student_id, self::own_children_ids(), true ) ) {
			return true;
		}

		// Docente con asignación activa en el grado del estudiante.
		if ( $identity['teacher_id'] && in_array( (int) $row->grade_id, self::own_grade_ids(), true ) ) {
			return true;
		}

		return self::out_of_scope();
	}

	/**
	 * ¿Puede el usuario ver las notas de esta materia en este grado?
	 *
	 * @param int $grade_id   Grado.
	 * @param int $subject_id Materia.
	 * @return true|WP_Error
	 */
	public static function can_view_grade_subject( $grade_id, $subject_id ) {
		$scope = self::check_scope(
			array(
				'grade_id'   => $grade_id,
				'subject_id' => $subject_id,
			)
		);

		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		if ( self::sees_whole_institution() ) {
			return true;
		}

		if ( self::teacher_has_assignment( $subject_id, $grade_id ) ) {
			return true;
		}

		return self::out_of_scope();
	}

	public static function out_of_scope() {
		return self::error(
			'out_of_scope',
			__( 'Este recurso está fuera de tu alcance.', 'sistema-educativo' ),
			403
		);
	}

	public static function not_found( $message = '' ) {
		return self::error(
			'not_found',
			$message ?: __( 'No se encontró el recurso.', 'sistema-educativo' ),
			404
		);
	}
}
