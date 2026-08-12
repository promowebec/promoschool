<?php
/**
 * Servicio de lectura: estructura institucional y personas.
 *
 * Instituciones, períodos, trimestres, grados, materias, pensum, docentes,
 * estudiantes, representantes y asignaciones académicas.
 *
 * Todo se devuelve ya acotado a la institución activa y al alcance personal
 * del usuario (contrato §5.1). Los nombres de las personas viven en
 * wp_usermeta, no en las tablas wp_edu_*.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Catalog_Service {

	/* ─────────────────────────────────────────────────────────────────────
	 * Instituciones
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Instituciones visibles para el usuario actual.
	 *
	 * @return array
	 */
	public static function institutions() {
		global $wpdb;

		$rows = Edu_Context::is_superadmin_editorial()
			? $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}edu_institutions ORDER BY name" )
			: $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}edu_institutions WHERE id = %d",
					Edu_Context::current_institution_id()
				)
			);

		return array_map( array( __CLASS__, 'shape_institution' ), (array) $rows );
	}

	/**
	 * Una institución.
	 *
	 * @param int $id Institución.
	 * @return array|WP_Error
	 */
	public static function institution( $id ) {
		if ( ! Edu_Context::can_access_institution( $id ) ) {
			return Edu_Service::invalid_scope();
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}edu_institutions WHERE id = %d", (int) $id )
		);

		return $row ? self::shape_institution( $row ) : Edu_Service::not_found();
	}

	private static function shape_institution( $row ) {
		return array(
			'id'         => (int) $row->id,
			'name'       => $row->name,
			'ruc'        => $row->ruc,
			'address'    => $row->address,
			'phone'      => $row->phone,
			'email'      => $row->email,
			'logo_url'   => $row->logo_url ? esc_url_raw( $row->logo_url ) : null,
			'regime'     => $row->regime,
			'created_at' => Edu_Api::date( $row->created_at ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Períodos y trimestres
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Períodos lectivos de la institución activa.
	 *
	 * @param array $args Filtros: is_active.
	 * @return array
	 */
	public static function periods( array $args = array() ) {
		global $wpdb;

		$sql    = "SELECT * FROM {$wpdb->prefix}edu_periods WHERE institution_id = %d";
		$params = array( Edu_Context::current_institution_id() );

		if ( isset( $args['is_active'] ) ) {
			$sql     .= ' AND is_active = %d';
			$params[] = $args['is_active'] ? 1 : 0;
		}

		$sql .= ' ORDER BY start_date DESC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'shape_period' ), (array) $rows );
	}

	private static function shape_period( $row ) {
		return array(
			'id'             => (int) $row->id,
			'institution_id' => (int) $row->institution_id,
			'name'           => $row->name,
			'regime'         => $row->regime,
			'start_date'     => $row->start_date,
			'end_date'       => $row->end_date,
			'working_days'   => (int) $row->working_days,
			'is_active'      => Edu_Api::boolean( $row->is_active ),
			'num_trimesters' => (int) $row->num_trimesters,
		);
	}

	/**
	 * Trimestres de un período.
	 *
	 * @param int $period_id Período.
	 * @return array|WP_Error
	 */
	public static function trimesters( $period_id ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$institution = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT institution_id FROM {$p}periods WHERE id = %d", (int) $period_id )
		);

		if ( ! $institution ) {
			return Edu_Service::not_found( __( 'El período no existe.', 'sistema-educativo' ) );
		}

		if ( ! Edu_Context::can_access_institution( $institution ) ) {
			return Edu_Service::invalid_scope();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$p}trimesters WHERE period_id = %d ORDER BY number", (int) $period_id )
		);

		return array_map(
			static function ( $row ) {
				return array(
					'id'         => (int) $row->id,
					'period_id'  => (int) $row->period_id,
					'number'     => (int) $row->number,
					'start_date' => $row->start_date,
					'end_date'   => $row->end_date,
					'is_closed'  => Edu_Api::boolean( $row->is_closed ),
					'closed_at'  => Edu_Api::date( $row->closed_at ),
				);
			},
			(array) $rows
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Grados
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Grados de la institución activa.
	 *
	 * Un docente solo ve los grados donde tiene asignación activa; un
	 * estudiante y un representante, los de sus propios cursos.
	 *
	 * @param array $args Filtros: level, sub_level.
	 * @return array
	 */
	public static function grades( array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$sql    = "SELECT * FROM {$p}grades WHERE institution_id = %d";
		$params = array( Edu_Context::current_institution_id() );

		if ( ! empty( $args['level'] ) ) {
			$sql     .= ' AND level = %s';
			$params[] = $args['level'];
		}

		if ( ! empty( $args['sub_level'] ) ) {
			$sql     .= ' AND sub_level = %s';
			$params[] = $args['sub_level'];
		}

		$visible = self::visible_grade_ids();
		if ( null !== $visible ) {
			if ( empty( $visible ) ) {
				return array();
			}
			$sql .= ' AND id IN (' . implode( ',', $visible ) . ')';
		}

		$sql .= ' ORDER BY name, paralelo';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'shape_grade' ), (array) $rows );
	}

	/**
	 * Un grado.
	 *
	 * @param int $id Grado.
	 * @return array|WP_Error
	 */
	public static function grade( $id ) {
		$scope = Edu_Service::check_scope( array( 'grade_id' => $id ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$visible = self::visible_grade_ids();
		if ( null !== $visible && ! in_array( (int) $id, $visible, true ) ) {
			return Edu_Service::out_of_scope();
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}edu_grades WHERE id = %d", (int) $id )
		);

		return $row ? self::shape_grade( $row ) : Edu_Service::not_found();
	}

	/**
	 * Grados que puede ver el usuario, o null si no hay restricción personal.
	 *
	 * @return int[]|null
	 */
	private static function visible_grade_ids() {
		if ( Edu_Service::sees_whole_institution() ) {
			return null;
		}

		$identity = Edu_Service::identity();
		$ids      = array();

		if ( $identity['teacher_id'] ) {
			$ids = array_merge( $ids, Edu_Service::own_grade_ids() );
		}

		if ( $identity['student_id'] || $identity['parent_id'] ) {
			global $wpdb;

			$student_ids = $identity['student_id']
				? array( $identity['student_id'] )
				: Edu_Service::own_children_ids();

			if ( ! empty( $student_ids ) ) {
				$in  = implode( ',', array_map( 'intval', $student_ids ) );
				$ids = array_merge(
					$ids,
					array_map( 'intval', (array) $wpdb->get_col( "SELECT grade_id FROM {$wpdb->prefix}edu_students WHERE id IN ($in)" ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	private static function shape_grade( $row ) {
		return array(
			'id'             => (int) $row->id,
			'institution_id' => (int) $row->institution_id,
			'level'          => $row->level,
			'sub_level'      => $row->sub_level,
			'name'           => $row->name,
			'paralelo'       => $row->paralelo,
			'display_name'   => trim( $row->name . ' ' . $row->paralelo ),
			'specialty'      => $row->specialty,
			'tutor_user_id'  => $row->tutor_user_id ? (int) $row->tutor_user_id : null,
			'formula'        => in_array( $row->sub_level, array( 'media', 'superior', 'bg', 'bt' ), true ) ? 'sumativa_proyecto' : 'elemental',
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Materias
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Catálogo oficial del Mineduc.
	 *
	 * @param array $args Filtros: sub_level.
	 * @return array
	 */
	public static function subjects_catalog( array $args = array() ) {
		global $wpdb;

		$sql    = "SELECT * FROM {$wpdb->prefix}edu_subjects_catalog WHERE is_active = 1";
		$params = array();

		if ( ! empty( $args['sub_level'] ) ) {
			$sql     .= ' AND FIND_IN_SET(%s, sub_levels)';
			$params[] = $args['sub_level'];
		}

		$sql .= ' ORDER BY name';

		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'id'         => (int) $row->id,
					'name'       => $row->name,
					'sub_levels' => array_values( array_filter( explode( ',', (string) $row->sub_levels ) ) ),
					'area'       => $row->area,
					'source'     => $row->source,
				);
			},
			(array) $rows
		);
	}

	/**
	 * Materias adoptadas por la institución activa.
	 *
	 * @param array $args Filtros: grade_id (materias del pensum de ese grado).
	 * @return array
	 */
	public static function subjects( array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		if ( ! empty( $args['grade_id'] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.*, gs.hours_week
					 FROM {$p}grade_subjects gs
					 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
					 WHERE gs.grade_id = %d
					 ORDER BY s.name",
					(int) $args['grade_id']
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT *, NULL AS hours_week FROM {$p}subjects WHERE institution_id = %d ORDER BY name",
					Edu_Context::current_institution_id()
				)
			);
		}

		$subjects = array_map( array( __CLASS__, 'shape_subject' ), (array) $rows );

		// Un docente solo ve las materias que dicta.
		if ( ! Edu_Service::sees_whole_institution() ) {
			$identity = Edu_Service::identity();

			if ( $identity['teacher_id'] ) {
				$mine     = self::teacher_subject_ids( $identity['teacher_id'], $args['grade_id'] ?? 0 );
				$subjects = array_values(
					array_filter(
						$subjects,
						static function ( $s ) use ( $mine ) {
							return in_array( $s['id'], $mine, true );
						}
					)
				);
			}
		}

		return $subjects;
	}

	private static function teacher_subject_ids( $teacher_id, $grade_id = 0 ) {
		global $wpdb;

		$sql    = "SELECT DISTINCT subject_id FROM {$wpdb->prefix}edu_teacher_assignments
		           WHERE teacher_id = %d AND is_active = 1";
		$params = array( (int) $teacher_id );

		if ( $grade_id ) {
			$sql     .= ' AND grade_id = %d';
			$params[] = (int) $grade_id;
		}

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function shape_subject( $row ) {
		return array(
			'id'             => (int) $row->id,
			'institution_id' => (int) $row->institution_id,
			'catalog_id'     => $row->catalog_id ? (int) $row->catalog_id : null,
			'name'           => $row->name,
			'code'           => $row->code,
			'area'           => $row->area,
			'is_custom'      => Edu_Api::boolean( $row->is_custom ),
			'hours_week'     => isset( $row->hours_week ) && null !== $row->hours_week ? (int) $row->hours_week : null,
		);
	}

	/**
	 * Pensum de un grado: qué materias se dictan y con cuántas horas.
	 *
	 * @param int $grade_id Grado.
	 * @return array|WP_Error
	 */
	public static function pensum( $grade_id ) {
		$scope = Edu_Service::check_scope( array( 'grade_id' => $grade_id ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return self::subjects( array( 'grade_id' => (int) $grade_id ) );
	}
}
