<?php
/**
 * Servicio de lectura: personas.
 *
 * Docentes, estudiantes, representantes y asignaciones académicas.
 * Los nombres viven en wp_usermeta (first_name / last_name), no en las tablas
 * wp_edu_*: por eso todas las consultas hacen JOIN con usermeta.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_People_Service {

	/**
	 * Fragmento SQL reutilizable para traer nombre y correo desde wp_users
	 * y wp_usermeta. $alias es el alias de la tabla que tiene user_id.
	 *
	 * @param string $alias Alias de la tabla con la columna user_id.
	 * @return array{select:string, join:string}
	 */
	private static function name_sql( $alias ) {
		global $wpdb;

		return array(
			'select' => "COALESCE(um_fn.meta_value, '') AS nombres,
			             COALESCE(um_ln.meta_value, u.display_name) AS apellidos,
			             u.user_email, u.display_name, u.ID AS wp_user_id",
			'join'   => "INNER JOIN {$wpdb->users} u ON u.ID = {$alias}.user_id
			             LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = {$alias}.user_id AND um_fn.meta_key = 'first_name'
			             LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = {$alias}.user_id AND um_ln.meta_key = 'last_name'",
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Docentes
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Docentes de la institución activa.
	 *
	 * El vínculo docente–institución vive en usermeta.edu_institution_id:
	 * la tabla wp_edu_teachers no tiene institution_id.
	 *
	 * @param array $args Filtros: is_active, search.
	 * @return array|WP_Error
	 */
	public static function teachers( array $args = array() ) {
		$cap = Edu_Service::require_cap( array( 'edu_view_all', 'edu_grade_students' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		global $wpdb;
		$p    = $wpdb->prefix . 'edu_';
		$name = self::name_sql( 't' );

		$sql = "SELECT t.*, {$name['select']}
		        FROM {$p}teachers t
		        {$name['join']}
		        INNER JOIN {$wpdb->usermeta} um_inst
		                ON um_inst.user_id = t.user_id
		               AND um_inst.meta_key = 'edu_institution_id'
		               AND um_inst.meta_value = %d
		        WHERE 1=1";

		$params = array( Edu_Context::current_institution_id() );

		if ( isset( $args['is_active'] ) ) {
			$sql     .= ' AND t.is_active = %d';
			$params[] = $args['is_active'] ? 1 : 0;
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$sql     .= ' AND (um_fn.meta_value LIKE %s OR um_ln.meta_value LIKE %s OR t.cedula LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql .= ' ORDER BY apellidos, nombres';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'shape_teacher' ), (array) $rows );
	}

	private static function shape_teacher( $row ) {
		return array(
			'id'         => (int) $row->id,
			'user_id'    => (int) $row->user_id,
			'nombres'    => $row->nombres,
			'apellidos'  => $row->apellidos,
			'email'      => $row->user_email,
			'cedula'     => $row->cedula,
			'phone'      => $row->phone,
			'title'      => $row->title,
			'hire_date'  => $row->hire_date,
			'is_active'  => Edu_Api::boolean( $row->is_active ),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Estudiantes
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Estudiantes visibles para el usuario actual.
	 *
	 * @param array $args Filtros: grade_id, status, search, page, per_page.
	 * @return array{items:array, total:int}|WP_Error
	 */
	public static function students( array $args = array() ) {
		global $wpdb;
		$p    = $wpdb->prefix . 'edu_';
		$name = self::name_sql( 's' );

		$sql = "FROM {$p}students s
		        INNER JOIN {$p}grades g ON g.id = s.grade_id
		        {$name['join']}
		        WHERE g.institution_id = %d";

		$params = array( Edu_Context::current_institution_id() );

		if ( ! empty( $args['grade_id'] ) ) {
			$sql     .= ' AND s.grade_id = %d';
			$params[] = (int) $args['grade_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$sql     .= ' AND s.status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$sql     .= ' AND (um_fn.meta_value LIKE %s OR um_ln.meta_value LIKE %s OR s.cedula LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// Alcance personal.
		$allowed = self::visible_student_ids();
		if ( null !== $allowed ) {
			if ( empty( $allowed ) ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}
			$sql .= ' AND s.id IN (' . implode( ',', $allowed ) . ')';
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $sql", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT s.*, g.name AS grade_name, g.paralelo, g.sub_level, g.level, {$name['select']}
				 $sql
				 ORDER BY apellidos, nombres
				 LIMIT %d OFFSET %d",
				$list_params
			)
		);

		return array(
			'items' => array_map( array( __CLASS__, 'shape_student' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Un estudiante, con sus representantes.
	 *
	 * @param int $id Estudiante.
	 * @return array|WP_Error
	 */
	public static function student( $id ) {
		$allowed = Edu_Service::can_view_student( $id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		global $wpdb;
		$p    = $wpdb->prefix . 'edu_';
		$name = self::name_sql( 's' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, g.name AS grade_name, g.paralelo, g.sub_level, g.level, {$name['select']}
				 FROM {$p}students s
				 INNER JOIN {$p}grades g ON g.id = s.grade_id
				 {$name['join']}
				 WHERE s.id = %d",
				(int) $id
			)
		);

		if ( ! $row ) {
			return Edu_Service::not_found();
		}

		$student            = self::shape_student( $row );
		$student['parents'] = self::student_parents( (int) $id );

		return $student;
	}

	/**
	 * Estudiantes que puede ver el usuario, o null si no hay restricción.
	 *
	 * @return int[]|null
	 */
	private static function visible_student_ids() {
		if ( Edu_Service::sees_whole_institution() ) {
			return null;
		}

		$identity = Edu_Service::identity();
		$ids      = array();

		if ( $identity['student_id'] ) {
			$ids[] = $identity['student_id'];
		}

		if ( $identity['parent_id'] ) {
			$ids = array_merge( $ids, Edu_Service::own_children_ids() );
		}

		if ( $identity['teacher_id'] ) {
			$grades = Edu_Service::own_grade_ids();

			if ( ! empty( $grades ) ) {
				global $wpdb;
				$in  = implode( ',', array_map( 'intval', $grades ) );
				$ids = array_merge(
					$ids,
					array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}edu_students WHERE grade_id IN ($in)" ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
			}
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	private static function shape_student( $row ) {
		return array(
			'id'              => (int) $row->id,
			'user_id'         => (int) $row->user_id,
			'nombres'         => $row->nombres,
			'apellidos'       => $row->apellidos,
			'email'           => $row->user_email,
			'cedula'          => $row->cedula,
			'birth_date'      => $row->birth_date,
			'sexo'            => $row->sexo,
			'direccion'       => $row->direccion,
			'enrollment_date' => $row->enrollment_date,
			'photo_url'       => $row->photo_url ? esc_url_raw( $row->photo_url ) : null,
			'status'          => $row->status,
			'grade'           => array(
				'id'        => (int) $row->grade_id,
				'name'      => $row->grade_name ?? null,
				'paralelo'  => $row->paralelo ?? null,
				'level'     => $row->level ?? null,
				'sub_level' => $row->sub_level ?? null,
			),
		);
	}

	/**
	 * Representantes de un estudiante.
	 *
	 * @param int $student_id Estudiante.
	 * @return array
	 */
	private static function student_parents( $student_id ) {
		global $wpdb;
		$p    = $wpdb->prefix . 'edu_';
		$name = self::name_sql( 'pa' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pa.*, ps.relationship, ps.is_primary, {$name['select']}
				 FROM {$p}parent_student ps
				 INNER JOIN {$p}parents pa ON pa.id = ps.parent_id
				 {$name['join']}
				 WHERE ps.student_id = %d
				 ORDER BY ps.is_primary DESC, pa.id",
				(int) $student_id
			)
		);

		return array_map( array( __CLASS__, 'shape_parent' ), (array) $rows );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Representantes
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Representantes de la institución activa.
	 *
	 * @param array $args Filtros: search.
	 * @return array|WP_Error
	 */
	public static function parents( array $args = array() ) {
		$cap = Edu_Service::require_cap( 'edu_view_all' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		global $wpdb;
		$p    = $wpdb->prefix . 'edu_';
		$name = self::name_sql( 'pa' );

		$sql = "SELECT DISTINCT pa.*, {$name['select']}
		        FROM {$p}parents pa
		        {$name['join']}
		        INNER JOIN {$p}parent_student ps ON ps.parent_id = pa.id
		        INNER JOIN {$p}students s ON s.id = ps.student_id
		        INNER JOIN {$p}grades g ON g.id = s.grade_id
		        WHERE g.institution_id = %d";

		$params = array( Edu_Context::current_institution_id() );

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$sql     .= ' AND (um_fn.meta_value LIKE %s OR um_ln.meta_value LIKE %s OR pa.cedula LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql .= ' ORDER BY apellidos, nombres';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'shape_parent' ), (array) $rows );
	}

	private static function shape_parent( $row ) {
		$out = array(
			'id'         => (int) $row->id,
			'user_id'    => (int) $row->user_id,
			'nombres'    => $row->nombres,
			'apellidos'  => $row->apellidos,
			'email'      => $row->user_email,
			'cedula'     => $row->cedula,
			'phone'      => $row->phone,
			'whatsapp'   => $row->whatsapp,
			'occupation' => $row->occupation,
		);

		if ( isset( $row->relationship ) ) {
			$out['relationship'] = $row->relationship;
			$out['is_primary']   = Edu_Api::boolean( $row->is_primary );
		}

		return $out;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Asignaciones académicas
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Asignaciones docente–grado–materia–período.
	 *
	 * Un docente solo ve las suyas.
	 *
	 * @param array $args Filtros: teacher_id, grade_id, subject_id, period_id, is_active.
	 * @return array|WP_Error
	 */
	public static function teacher_assignments( array $args = array() ) {
		$cap = Edu_Service::require_cap( array( 'edu_view_all', 'edu_grade_students' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		global $wpdb;
		$p    = $wpdb->prefix . 'edu_';
		$name = self::name_sql( 't' );

		/*
		 * Los dos contadores son los que el docente mira de un vistazo: cuántas
		 * tareas tiene vivas en esa materia y cuántas entregas le esperan sin
		 * calificar. Van como subconsultas correlacionadas y acotadas al docente
		 * dueño de la asignación: dos docentes pueden compartir grado y materia.
		 */
		$sql = "SELECT ta.*, g.name AS grade_name, g.paralelo, g.sub_level,
		               s.name AS subject_name, pe.name AS period_name,
		               (SELECT COUNT(*) FROM {$p}assignments a
		                 WHERE a.grade_id = ta.grade_id AND a.subject_id = ta.subject_id
		                   AND a.teacher_id = ta.teacher_id AND a.status = 'published') AS n_tareas,
		               (SELECT COUNT(*) FROM {$p}submissions sb
		                 INNER JOIN {$p}assignments a2 ON a2.id = sb.assignment_id
		                 WHERE a2.grade_id = ta.grade_id AND a2.subject_id = ta.subject_id
		                   AND a2.teacher_id = ta.teacher_id
		                   AND sb.status IN ('submitted','late')) AS n_entregas,
		               {$name['select']}
		        FROM {$p}teacher_assignments ta
		        INNER JOIN {$p}grades g   ON g.id = ta.grade_id
		        INNER JOIN {$p}subjects s ON s.id = ta.subject_id
		        INNER JOIN {$p}periods pe ON pe.id = ta.period_id
		        INNER JOIN {$p}teachers t ON t.id = ta.teacher_id
		        {$name['join']}
		        WHERE g.institution_id = %d";

		$params = array( Edu_Context::current_institution_id() );

		// Un docente solo ve sus propias asignaciones.
		if ( ! Edu_Service::sees_whole_institution() ) {
			$identity = Edu_Service::identity();

			if ( ! $identity['teacher_id'] ) {
				return array();
			}

			$sql     .= ' AND ta.teacher_id = %d';
			$params[] = $identity['teacher_id'];
		} elseif ( ! empty( $args['teacher_id'] ) ) {
			$sql     .= ' AND ta.teacher_id = %d';
			$params[] = (int) $args['teacher_id'];
		}

		foreach ( array( 'grade_id', 'subject_id', 'period_id' ) as $filter ) {
			if ( ! empty( $args[ $filter ] ) ) {
				$sql     .= " AND ta.$filter = %d";
				$params[] = (int) $args[ $filter ];
			}
		}

		if ( isset( $args['is_active'] ) ) {
			$sql     .= ' AND ta.is_active = %d';
			$params[] = $args['is_active'] ? 1 : 0;
		}

		$sql .= ' ORDER BY g.name, s.name';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'id'           => (int) $row->id,
					'teacher_id'   => (int) $row->teacher_id,
					'teacher_name' => trim( $row->nombres . ' ' . $row->apellidos ),
					'grade_id'     => (int) $row->grade_id,
					'grade_name'   => trim( $row->grade_name . ' ' . $row->paralelo ),
					'sub_level'    => $row->sub_level,
					'subject_id'   => (int) $row->subject_id,
					'subject_name' => $row->subject_name,
					'period_id'    => (int) $row->period_id,
					'period_name'  => $row->period_name,
					'is_active'    => Edu_Api::boolean( $row->is_active ),
					'n_tareas'     => (int) $row->n_tareas,
					'n_entregas'   => (int) $row->n_entregas,
				);
			},
			(array) $rows
		);
	}
}
