<?php
/**
 * Servicio: tareas y actividades evaluables.
 *
 * Crear, editar, publicar, cerrar y eliminar. El vínculo con el componente
 * evaluable se resuelve con Edu_Curriculum_Service, que lo crea al vuelo si el
 * docente escribió un nombre nuevo.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Assignment_Service {

	/** Tipos válidos de la columna assignments.type. */
	const VALID_TYPES = array( 'tarea', 'leccion', 'trabajo', 'deber', 'examen', 'correccion' );

	/**
	 * Palabras clave para deducir el tipo desde el nombre del componente.
	 * El orden importa: gana la primera coincidencia.
	 */
	const TYPE_KEYWORDS = array(
		'examen'     => array( 'examen', 'prueba', 'evaluacion', 'test' ),
		'leccion'    => array( 'leccion', 'oral', 'quiz' ),
		'correccion' => array( 'correccion', 'recuperacion', 'mejora', 'supletorio' ),
		'trabajo'    => array( 'trabajo', 'proyecto', 'investigacion', 'exposicion', 'ensayo' ),
		'deber'      => array( 'deber', 'consulta' ),
		'tarea'      => array( 'tarea', 'actividad', 'taller' ),
	);

	/* ─────────────────────────────────────────────────────────────────────
	 * Guardar
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Crea o actualiza una tarea.
	 *
	 * @param array $input {
	 *     @type int    $id                  0 = crear.
	 *     @type int    $grade_id
	 *     @type int    $subject_id
	 *     @type int    $trimester_id
	 *     @type int    $parcial_num
	 *     @type string $title
	 *     @type string $description
	 *     @type string $due_date
	 *     @type float  $max_score
	 *     @type bool   $notify_parents
	 *     @type bool   $publish_now
	 *     @type string $type                Opcional; si no viene se deduce.
	 *     @type int    $component_id        Componente existente.
	 *     @type string $component_new_name  Componente a crear al vuelo.
	 *     @type float  $component_new_weight
	 *     @type array  $files               Estructura $_FILES del campo múltiple.
	 *     @type array  $delete_files        IDs de adjuntos a eliminar.
	 * }
	 * @return array|WP_Error
	 */
	public static function save( array $input ) {
		$cap = Edu_Service::require_cap( array( 'edu_create_assignment', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$id           = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$grade_id     = isset( $input['grade_id'] ) ? (int) $input['grade_id'] : 0;
		$subject_id   = isset( $input['subject_id'] ) ? (int) $input['subject_id'] : 0;
		$trimester_id = isset( $input['trimester_id'] ) ? (int) $input['trimester_id'] : 0;
		$parcial_num  = isset( $input['parcial_num'] ) ? (int) $input['parcial_num'] : 1;
		$title        = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$description  = wp_kses_post( (string) ( $input['description'] ?? '' ) );
		$due_date     = sanitize_text_field( (string) ( $input['due_date'] ?? '' ) );
		$max_score    = isset( $input['max_score'] ) ? round( (float) $input['max_score'], 2 ) : 10.00;
		$notify       = ! empty( $input['notify_parents'] ) ? 1 : 0;
		$publish_now  = ! empty( $input['publish_now'] );

		if ( '' === $title ) {
			return Edu_Service::error( 'title_required', __( 'La tarea necesita un título.', 'sistema-educativo' ), 400 );
		}

		if ( ! in_array( $parcial_num, array( 1, 2 ), true ) ) {
			$parcial_num = 1;
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

		// Un docente solo crea tareas en sus asignaciones activas.
		if ( ! Edu_Service::sees_whole_institution() && ! Edu_Service::teacher_has_assignment( $subject_id, $grade_id ) ) {
			return Edu_Service::out_of_scope();
		}

		global $wpdb;
		$ta = $wpdb->prefix . 'edu_assignments';

		$existing = $id > 0 ? self::load_for_manage( $id ) : null;

		if ( $id > 0 && ! $existing ) {
			return Edu_Service::not_found( __( 'La tarea no existe o no puedes editarla.', 'sistema-educativo' ) );
		}

		if ( $existing && 'closed' === $existing->status ) {
			return Edu_Service::error(
				'already_closed',
				__( 'La tarea está cerrada y ya no admite cambios.', 'sistema-educativo' ),
				409
			);
		}

		// Componente evaluable: existente, nuevo al vuelo, o ninguno.
		$component_id = Edu_Curriculum_Service::resolve_or_create_component(
			isset( $input['component_id'] ) ? (int) $input['component_id'] : 0,
			(string) ( $input['component_new_name'] ?? '' ),
			isset( $input['component_new_weight'] ) ? (float) $input['component_new_weight'] : 1.00,
			$subject_id,
			$trimester_id,
			$parcial_num
		);

		// El tipo ya no se pide en el formulario: se deduce del componente.
		$type_posted = sanitize_key( (string) ( $input['type'] ?? '' ) );
		if ( ! in_array( $type_posted, self::VALID_TYPES, true ) ) {
			$type_posted = '';
		}

		if ( '' !== $type_posted ) {
			$type = $type_posted;
		} else {
			$comp_name = $component_id
				? (string) $wpdb->get_var(
					$wpdb->prepare( "SELECT name FROM {$wpdb->prefix}edu_grade_components WHERE id = %d", $component_id )
				)
				: '';

			$type = self::derive_type(
				'' !== $comp_name ? $comp_name : $title,
				$existing ? (string) $existing->type : 'tarea'
			);
		}

		$identity = Edu_Service::identity();
		$due_dt   = $due_date ? gmdate( 'Y-m-d H:i:s', strtotime( $due_date ) ) : null;

		$data = array(
			'grade_id'       => $grade_id,
			'subject_id'     => $subject_id,
			'trimester_id'   => $trimester_id,
			'parcial_num'    => $parcial_num,
			'component_id'   => $component_id,
			'type'           => $type,
			'title'          => $title,
			'description'    => $description,
			'due_date'       => $due_dt,
			'max_score'      => $max_score,
			'notify_parents' => $notify,
			'status'         => $publish_now ? 'published' : 'draft',
		);

		$formats = array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%d', '%s' );

		if ( $id > 0 ) {
			$wpdb->update( $ta, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$saved_id = $id;
		} else {
			if ( $identity['teacher_id'] ) {
				$data['teacher_id'] = $identity['teacher_id'];
				$formats[]          = '%d';
			}
			$wpdb->insert( $ta, $data, $formats );
			$saved_id = (int) $wpdb->insert_id;
		}

		if ( ! $saved_id ) {
			return Edu_Service::error( 'db_error', __( 'No se pudo guardar la tarea.', 'sistema-educativo' ), 500 );
		}

		$uploaded = self::attach_files( $saved_id, $input['files'] ?? array() );
		$removed  = self::remove_files( $saved_id, $input['delete_files'] ?? array() );

		Edu_Audit::log(
			$id > 0 ? Edu_Audit::TAREA_EDITADA : Edu_Audit::TAREA_CREADA,
			'assignment',
			$saved_id,
			null,
			array(
				'titulo'     => $title,
				'tipo'       => $type,
				'grado_id'   => $grade_id,
				'materia_id' => $subject_id,
				'estado'     => $data['status'],
			)
		);

		return array(
			'id'             => $saved_id,
			'created'        => 0 === $id,
			'status'         => $data['status'],
			'type'           => $type,
			'component_id'   => $component_id,
			'files_added'    => $uploaded,
			'files_removed'  => $removed,
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Cambios de estado
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Publica una tarea en borrador.
	 *
	 * @param int $id Tarea.
	 * @return array|WP_Error
	 */
	public static function publish( $id ) {
		return self::change_status( $id, 'published', Edu_Audit::TAREA_PUBLICADA, 'draft' );
	}

	/**
	 * Cierra una tarea: deja de admitir entregas.
	 *
	 * @param int $id Tarea.
	 * @return array|WP_Error
	 */
	public static function close( $id ) {
		return self::change_status( $id, 'closed', Edu_Audit::TAREA_CERRADA, 'published' );
	}

	private static function change_status( $id, $new_status, $audit_action, $old_status ) {
		$cap = Edu_Service::require_cap( array( 'edu_create_assignment', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$id  = (int) $id;
		$row = self::load_for_manage( $id );

		if ( ! $row ) {
			return Edu_Service::not_found( __( 'La tarea no existe o no puedes administrarla.', 'sistema-educativo' ) );
		}

		// Una tarea cerrada no se puede volver a publicar.
		if ( 'published' === $new_status && 'closed' === $row->status ) {
			return Edu_Service::error(
				'already_closed',
				__( 'La tarea está cerrada y no puede volver a publicarse.', 'sistema-educativo' ),
				409
			);
		}

		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'edu_assignments',
			array( 'status' => $new_status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		Edu_Audit::log( $audit_action, 'assignment', $id, $old_status, $new_status );

		return array(
			'id'     => $id,
			'status' => $new_status,
		);
	}

	/**
	 * Elimina una tarea y sus adjuntos físicos.
	 *
	 * @param int $id Tarea.
	 * @return array|WP_Error
	 */
	public static function delete( $id ) {
		$cap = Edu_Service::require_cap( array( 'edu_create_assignment', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$id = (int) $id;

		if ( ! self::load_for_manage( $id ) ) {
			return Edu_Service::not_found( __( 'La tarea no existe o no puedes administrarla.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$taf = $wpdb->prefix . 'edu_assignment_files';

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT file_url FROM $taf WHERE assignment_id = %d", $id ) ) as $file ) {
			Edu_File_Service::delete_physical( $file->file_url );
		}

		// assignment_files cae por FOREIGN KEY ON DELETE CASCADE del esquema.
		$wpdb->delete( $wpdb->prefix . 'edu_assignments', array( 'id' => $id ), array( '%d' ) );

		Edu_Audit::log( Edu_Audit::TAREA_ELIMINADA, 'assignment', $id );

		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Adjuntos
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Guarda adjuntos nuevos de una tarea.
	 *
	 * @param int   $assignment_id Tarea.
	 * @param array $files         Estructura $_FILES.
	 * @return int Cuántos se guardaron.
	 */
	public static function attach_files( $assignment_id, $files ) {
		if ( empty( $files ) || empty( $files['name'] ) ) {
			return 0;
		}

		global $wpdb;
		$taf   = $wpdb->prefix . 'edu_assignment_files';
		$saved = 0;

		foreach ( Edu_File_Service::store_uploads( 'assignments/' . (int) $assignment_id, $files ) as $file ) {
			$wpdb->insert(
				$taf,
				array(
					'assignment_id' => (int) $assignment_id,
					'file_url'      => $file['file_url'],
					'file_name'     => $file['file_name'],
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
	 * Elimina adjuntos de una tarea (solo los que le pertenecen).
	 *
	 * @param int   $assignment_id Tarea.
	 * @param array $file_ids      IDs a borrar.
	 * @return int Cuántos se borraron.
	 */
	public static function remove_files( $assignment_id, $file_ids ) {
		$file_ids = array_filter( array_map( 'intval', (array) $file_ids ) );

		if ( empty( $file_ids ) ) {
			return 0;
		}

		global $wpdb;
		$taf     = $wpdb->prefix . 'edu_assignment_files';
		$removed = 0;

		foreach ( $file_ids as $fid ) {
			$file = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM $taf WHERE id = %d AND assignment_id = %d", $fid, (int) $assignment_id )
			);

			if ( ! $file ) {
				continue;
			}

			Edu_File_Service::delete_physical( $file->file_url );
			$wpdb->delete( $taf, array( 'id' => $fid ), array( '%d' ) );
			$removed++;
		}

		return $removed;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Helpers de dominio
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Deduce el tipo de actividad desde el nombre del componente evaluable o,
	 * si no hay componente, desde el título.
	 *
	 * Existe para que el docente no tenga que elegir un "tipo" que en la
	 * práctica duplicaba al componente.
	 *
	 * @param string $texto    Nombre del componente o título.
	 * @param string $fallback Tipo a conservar si no hay coincidencia.
	 * @return string Uno de self::VALID_TYPES.
	 */
	public static function derive_type( string $texto, string $fallback = 'tarea' ): string {
		$normalizado = strtolower( remove_accents( $texto ) );

		foreach ( self::TYPE_KEYWORDS as $tipo => $palabras ) {
			foreach ( $palabras as $palabra ) {
				if ( false !== strpos( $normalizado, strtolower( remove_accents( $palabra ) ) ) ) {
					return $tipo;
				}
			}
		}

		return in_array( $fallback, self::VALID_TYPES, true ) ? $fallback : 'tarea';
	}

	/**
	 * Carga una tarea comprobando que el usuario pueda administrarla: debe ser
	 * de la institución activa y, si no tiene edu_view_all, ser suya.
	 *
	 * @param int $id Tarea.
	 * @return object|null
	 */
	public static function load_for_manage( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*, g.institution_id
				 FROM {$wpdb->prefix}edu_assignments a
				 INNER JOIN {$wpdb->prefix}edu_grades g ON g.id = a.grade_id
				 WHERE a.id = %d",
				(int) $id
			)
		);

		if ( ! $row ) {
			return null;
		}

		if ( Edu_Context::is_superadmin_editorial() ) {
			return $row;
		}

		if ( (int) $row->institution_id !== Edu_Context::current_institution_id() ) {
			return null;
		}

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			$identity = Edu_Service::identity();

			if ( ! $identity['teacher_id'] || (int) $row->teacher_id !== $identity['teacher_id'] ) {
				return null;
			}
		}

		return $row;
	}

	/**
	 * ¿Puede el usuario actual acceder a los adjuntos de esta tarea?
	 *
	 * Rector o admin de la institución, docente propietario o asignado al
	 * grado y materia, estudiante del grado, o representante con un hijo ahí.
	 *
	 * @param object $assignment Fila de assignments.
	 * @return bool
	 */
	public static function can_access( $assignment ) {
		if ( current_user_can( 'manage_options' ) || Edu_Context::can( 'edu_view_all' ) ) {
			return true;
		}

		global $wpdb;
		$p        = $wpdb->prefix . 'edu_';
		$identity = Edu_Service::identity();

		if ( $identity['teacher_id'] ) {
			if ( (int) $assignment->teacher_id === $identity['teacher_id'] ) {
				return true;
			}

			if ( Edu_Service::teacher_has_assignment( (int) $assignment->subject_id, (int) $assignment->grade_id ) ) {
				return true;
			}
		}

		if ( $identity['student_id'] ) {
			$grade_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT grade_id FROM {$p}students WHERE id = %d", $identity['student_id'] )
			);

			if ( $grade_id === (int) $assignment->grade_id ) {
				return true;
			}
		}

		if ( $identity['parent_id'] ) {
			$children = Edu_Service::own_children_ids();

			if ( ! empty( $children ) ) {
				$in    = implode( ',', array_map( 'intval', $children ) );
				$match = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$p}students WHERE id IN ($in) AND grade_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						(int) $assignment->grade_id
					)
				);

				if ( $match ) {
					return true;
				}
			}
		}

		return false;
	}
}
