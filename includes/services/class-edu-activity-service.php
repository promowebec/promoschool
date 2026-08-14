<?php
/**
 * Servicio de lectura: tareas, entregas, asistencia, comunicados, pagos y
 * auditoría.
 *
 * Son los dominios que dependen de un módulo activable. El gate del módulo lo
 * aplica la capa REST (Edu_Api::require_module); aquí solo va el alcance.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Activity_Service {

	/* ─────────────────────────────────────────────────────────────────────
	 * Tareas
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Tareas visibles para el usuario actual.
	 *
	 * El docente ve las de sus asignaciones; el estudiante y el representante
	 * solo las publicadas de su grado.
	 *
	 * @param array $args grade_id, subject_id, trimester_id, parcial_num, status, type.
	 * @return array|WP_Error
	 */
	public static function assignments( array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$sql = "SELECT a.*, g.name AS grade_name, g.paralelo, s.name AS subject_name,
		               c.name AS component_name,
		               (SELECT COUNT(*) FROM {$p}submissions su WHERE su.assignment_id = a.id AND su.status <> 'pending') AS entregas,
		               (SELECT COUNT(*) FROM {$p}submissions su WHERE su.assignment_id = a.id AND su.status = 'graded') AS calificadas
		        FROM {$p}assignments a
		        INNER JOIN {$p}grades g   ON g.id = a.grade_id
		        INNER JOIN {$p}subjects s ON s.id = a.subject_id
		        LEFT JOIN {$p}grade_components c ON c.id = a.component_id
		        WHERE g.institution_id = %d";

		$params = array( Edu_Context::current_institution_id() );

		$restriction = self::assignment_restriction();
		if ( is_wp_error( $restriction ) ) {
			return $restriction;
		}

		$sql   .= $restriction['sql'];
		$params = array_merge( $params, $restriction['params'] );

		foreach ( array( 'grade_id', 'subject_id', 'trimester_id', 'parcial_num' ) as $filter ) {
			if ( ! empty( $args[ $filter ] ) ) {
				$sql     .= " AND a.$filter = %d";
				$params[] = (int) $args[ $filter ];
			}
		}

		foreach ( array( 'status', 'type' ) as $filter ) {
			if ( ! empty( $args[ $filter ] ) ) {
				$sql     .= " AND a.$filter = %s";
				$params[] = $args[ $filter ];
			}
		}

		$sql .= ' ORDER BY a.due_date DESC, a.id DESC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'shape_assignment' ), (array) $rows );
	}

	/**
	 * Restricción SQL de tareas según el rol.
	 *
	 * @return array{sql:string, params:array}|WP_Error
	 */
	private static function assignment_restriction() {
		if ( Edu_Service::sees_whole_institution() ) {
			return array(
				'sql'    => '',
				'params' => array(),
			);
		}

		$identity = Edu_Service::identity();

		// Docente: las de sus asignaciones activas.
		if ( $identity['teacher_id'] ) {
			return array(
				'sql'    => " AND EXISTS (
					SELECT 1 FROM {$GLOBALS['wpdb']->prefix}edu_teacher_assignments ta
					WHERE ta.teacher_id = %d AND ta.is_active = 1
					  AND ta.grade_id = a.grade_id AND ta.subject_id = a.subject_id
				)",
				'params' => array( $identity['teacher_id'] ),
			);
		}

		// Estudiante o representante: solo publicadas o cerradas de su grado.
		$student_ids = $identity['student_id'] ? array( $identity['student_id'] ) : Edu_Service::own_children_ids();

		if ( empty( $student_ids ) ) {
			return Edu_Service::out_of_scope();
		}

		global $wpdb;
		$in     = implode( ',', array_map( 'intval', $student_ids ) );
		$grades = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT grade_id FROM {$wpdb->prefix}edu_students WHERE id IN ($in)" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $grades ) ) {
			return Edu_Service::out_of_scope();
		}

		return array(
			'sql'    => ' AND a.grade_id IN (' . implode( ',', $grades ) . ") AND a.status IN ('published','closed')",
			'params' => array(),
		);
	}

	/**
	 * Una tarea, con sus archivos adjuntos.
	 *
	 * @param int $id Tarea.
	 * @return array|WP_Error
	 */
	public static function assignment( $id ) {
		$rows = self::assignments();
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		foreach ( $rows as $row ) {
			if ( $row['id'] === (int) $id ) {
				$row['files'] = self::assignment_files( (int) $id );
				return $row;
			}
		}

		return Edu_Service::not_found( __( 'La tarea no existe o está fuera de tu alcance.', 'sistema-educativo' ) );
	}

	private static function assignment_files( $assignment_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, file_name, file_type, file_size
				 FROM {$wpdb->prefix}edu_assignment_files WHERE assignment_id = %d",
				(int) $assignment_id
			)
		);

		// Nunca se devuelve file_url: las descargas van por URL firmada (§10).
		return array_map(
			static function ( $row ) {
				return array(
					'id'        => (int) $row->id,
					'file_name' => $row->file_name,
					'file_type' => $row->file_type,
					'file_size' => (int) $row->file_size,
				);
			},
			(array) $rows
		);
	}

	private static function shape_assignment( $row ) {
		return array(
			'id'                 => (int) $row->id,
			'teacher_id'         => (int) $row->teacher_id,
			'grade_id'           => (int) $row->grade_id,
			'grade_name'         => trim( $row->grade_name . ' ' . $row->paralelo ),
			'subject_id'         => (int) $row->subject_id,
			'subject_name'       => $row->subject_name,
			'trimester_id'       => (int) $row->trimester_id,
			'parcial_num'        => (int) $row->parcial_num,
			'component_id'       => $row->component_id ? (int) $row->component_id : null,
			'component_name'     => $row->component_name,
			'type'               => $row->type,
			'title'              => $row->title,
			'description'        => $row->description,
			'due_date'           => Edu_Api::date( $row->due_date ),
			'max_score'          => Edu_Api::decimal( $row->max_score ),
			'status'             => $row->status,
			'allow_recovery'     => isset( $row->allow_recovery ) ? Edu_Api::boolean( $row->allow_recovery ) : false,
			'recovery_due_date'  => isset( $row->recovery_due_date ) ? Edu_Api::date( $row->recovery_due_date ) : null,
			'created_at'         => Edu_Api::date( $row->created_at ),
			'submissions_count'  => (int) $row->entregas,
			'graded_count'       => (int) $row->calificadas,
		);
	}

	/**
	 * Entregas de una tarea.
	 *
	 * @param int $assignment_id Tarea.
	 * @return array|WP_Error
	 */
	public static function submissions( $assignment_id ) {
		$assignment = self::assignment( $assignment_id );
		if ( is_wp_error( $assignment ) ) {
			return $assignment;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT su.*,
				        COALESCE(um_fn.meta_value, '') AS nombres,
				        COALESCE(um_ln.meta_value, u.display_name) AS apellidos
				 FROM {$p}submissions su
				 INNER JOIN {$p}students st ON st.id = su.student_id
				 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = st.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = st.user_id AND um_ln.meta_key = 'last_name'
				 WHERE su.assignment_id = %d
				 ORDER BY apellidos, nombres",
				(int) $assignment_id
			)
		);

		// Un estudiante o representante solo ve sus propias entregas.
		$only = null;
		if ( ! Edu_Service::sees_whole_institution() ) {
			$identity = Edu_Service::identity();

			if ( ! $identity['teacher_id'] ) {
				$only = $identity['student_id'] ? array( $identity['student_id'] ) : Edu_Service::own_children_ids();
			}
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( null !== $only && ! in_array( (int) $row->student_id, $only, true ) ) {
				continue;
			}

			$out[] = array(
				'id'           => (int) $row->id,
				'student_id'   => (int) $row->student_id,
				'nombres'      => $row->nombres,
				'apellidos'    => $row->apellidos,
				'status'       => $row->status,
				'comment'      => $row->comment,
				'submitted_at' => Edu_Api::date( $row->submitted_at ),
				'score'        => Edu_Api::decimal( $row->score ),
				'feedback'     => $row->feedback,
				'graded_at'    => Edu_Api::date( $row->graded_at ),
				'files'        => array(),
			);
		}

		return self::attach_submission_files( $out );
	}

	/**
	 * Cuelga de cada entrega la lista de sus archivos.
	 *
	 * Una sola consulta para todas: la pantalla de calificar abre la tarea
	 * completa y una consulta por entrega se notaba con un curso entero.
	 *
	 * @param array $submissions Entregas ya filtradas por alcance.
	 * @return array
	 */
	private static function attach_submission_files( array $submissions ) {
		if ( ! $submissions ) {
			return $submissions;
		}

		global $wpdb;

		$ids          = wp_list_pluck( $submissions, 'id' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, submission_id, file_name, file_type, file_size
				 FROM {$wpdb->prefix}edu_submission_files
				 WHERE submission_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids
			)
		);

		$por_entrega = array();
		foreach ( (array) $rows as $row ) {
			// Nunca se devuelve file_url: las descargas van por URL firmada (§10).
			$por_entrega[ (int) $row->submission_id ][] = array(
				'id'        => (int) $row->id,
				'file_name' => $row->file_name,
				'file_type' => $row->file_type,
				'file_size' => (int) $row->file_size,
			);
		}

		foreach ( $submissions as &$sub ) {
			$sub['files'] = $por_entrega[ $sub['id'] ] ?? array();
		}

		return $submissions;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Asistencia
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Asistencia de un grado en una fecha.
	 *
	 * subject_id nulo o ausente = asistencia diaria general.
	 *
	 * @param array $args grade_id, date, subject_id.
	 * @return array|WP_Error
	 */
	public static function attendance( array $args ) {
		$grade_id = (int) ( $args['grade_id'] ?? 0 );
		$date     = (string) ( $args['date'] ?? '' );

		$scope = Edu_Service::check_scope( array( 'grade_id' => $grade_id ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		if ( ! Edu_Service::sees_whole_institution() && ! in_array( $grade_id, Edu_Service::own_grade_ids(), true ) ) {
			return Edu_Service::out_of_scope();
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return Edu_Api::invalid_params( array( 'date' => __( 'La fecha debe tener el formato YYYY-MM-DD.', 'sistema-educativo' ) ) );
		}

		global $wpdb;
		$p          = $wpdb->prefix . 'edu_';
		$subject_id = isset( $args['subject_id'] ) ? (int) $args['subject_id'] : 0;

		$students = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id,
				        COALESCE(um_fn.meta_value, '') AS nombres,
				        COALESCE(um_ln.meta_value, u.display_name) AS apellidos
				 FROM {$p}students s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE s.grade_id = %d AND s.status = 'active'
				 ORDER BY apellidos, nombres",
				$grade_id
			)
		);

		$marks = array();
		if ( ! empty( $students ) ) {
			$sid_in = implode( ',', array_map( static fn( $s ) => (int) $s->id, $students ) );

			$rows = $subject_id
				? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}attendance WHERE student_id IN ($sid_in) AND date = %s AND subject_id = %d", $date, $subject_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}attendance WHERE student_id IN ($sid_in) AND date = %s AND subject_id IS NULL", $date ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( (array) $rows as $row ) {
				$marks[ (int) $row->student_id ] = $row;
			}
		}

		$items = array();
		foreach ( (array) $students as $student ) {
			$row = $marks[ (int) $student->id ] ?? null;

			$items[] = array(
				'student_id'    => (int) $student->id,
				'nombres'       => $student->nombres,
				'apellidos'     => $student->apellidos,
				'status'        => $row ? $row->status : null, // null = todavía no se tomó.
				'justification' => $row ? $row->justification : null,
				'registered_at' => $row ? Edu_Api::date( $row->registered_at ) : null,
			);
		}

		return array(
			'grade_id'   => $grade_id,
			'subject_id' => $subject_id ?: null,
			'date'       => $date,
			'items'      => $items,
		);
	}

	/**
	 * Asistencia de un estudiante en un rango de fechas, con su resumen.
	 *
	 * @param int   $student_id Estudiante.
	 * @param array $args       from, to.
	 * @return array|WP_Error
	 */
	public static function student_attendance( $student_id, array $args = array() ) {
		$allowed = Edu_Service::can_view_student( $student_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		global $wpdb;
		$p    = $wpdb->prefix . 'edu_';
		$from = ! empty( $args['from'] ) ? (string) $args['from'] : gmdate( 'Y-m-01' );
		$to   = ! empty( $args['to'] ) ? (string) $args['to'] : gmdate( 'Y-m-d' );

		foreach ( array( 'from' => $from, 'to' => $to ) as $key => $value ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return Edu_Api::invalid_params( array( $key => __( 'La fecha debe tener el formato YYYY-MM-DD.', 'sistema-educativo' ) ) );
			}
		}

		// Un día puede tener varias filas (general + por materia): se toma el
		// estado más grave del día, igual que hacen los exportes.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date,
				        SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY FIELD(status,'falta_injustificada','falta_justificada','atraso','presente')), ',', 1) AS status,
				        MAX(justification) AS justification
				 FROM {$p}attendance
				 WHERE student_id = %d AND date BETWEEN %s AND %s
				 GROUP BY date
				 ORDER BY date DESC",
				(int) $student_id,
				$from,
				$to
			)
		);

		$summary = array(
			'presente'            => 0,
			'atraso'              => 0,
			'falta_justificada'   => 0,
			'falta_injustificada' => 0,
		);

		$days = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $summary[ $row->status ] ) ) {
				$summary[ $row->status ]++;
			}

			$days[] = array(
				'date'          => $row->date,
				'status'        => $row->status,
				'justification' => $row->justification,
			);
		}

		$total    = array_sum( $summary );
		$presente = $summary['presente'] + $summary['atraso'];

		return array(
			'student_id' => (int) $student_id,
			'from'       => $from,
			'to'         => $to,
			'summary'    => $summary,
			'total_days' => $total,
			'percentage' => $total ? round( $presente / $total * 100, 2 ) : null,
			'days'       => $days,
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Comunicados
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Comunicados de la institución activa.
	 *
	 * wp_edu_announcements no tiene institution_id: se acota por el remitente
	 * (vía sus asignaciones o su usermeta) o por el grado destino.
	 *
	 * @param array $args scope, grade_id.
	 * @return array|WP_Error
	 */
	public static function announcements( array $args = array() ) {
		$cap = Edu_Service::require_cap( array( 'edu_view_all', 'edu_send_grade_announcement' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		global $wpdb;
		$p    = $wpdb->prefix . 'edu_';
		$inst = Edu_Context::current_institution_id();

		$sql = "SELECT a.*,
		               (SELECT COUNT(*) FROM {$p}announcement_recipients r WHERE r.announcement_id = a.id) AS total_dest,
		               (SELECT COUNT(*) FROM {$p}announcement_recipients r WHERE r.announcement_id = a.id AND r.read_at IS NOT NULL) AS leidos
		        FROM {$p}announcements a
		        WHERE (
		          EXISTS (
		            SELECT 1 FROM {$p}teachers t
		            INNER JOIN {$p}teacher_assignments ta ON ta.teacher_id = t.id
		            INNER JOIN {$p}grades gt ON gt.id = ta.grade_id
		            WHERE t.user_id = a.sender_user_id AND gt.institution_id = %d
		          )
		          OR EXISTS (
		            SELECT 1 FROM {$wpdb->usermeta} um
		            WHERE um.user_id = a.sender_user_id
		              AND um.meta_key = 'edu_institution_id' AND um.meta_value = %d
		          )
		          OR EXISTS (
		            SELECT 1 FROM {$p}grades g
		            WHERE g.id = a.target_grade_id AND g.institution_id = %d
		          )
		        )";

		$params = array( $inst, $inst, $inst );

		// Un docente solo ve los que envió.
		if ( ! Edu_Service::sees_whole_institution() ) {
			$sql     .= ' AND a.sender_user_id = %d';
			$params[] = get_current_user_id();
		}

		if ( ! empty( $args['scope'] ) ) {
			$sql     .= ' AND a.scope = %s';
			$params[] = $args['scope'];
		}

		if ( ! empty( $args['grade_id'] ) ) {
			$sql     .= ' AND a.target_grade_id = %d';
			$params[] = (int) $args['grade_id'];
		}

		$sql .= ' ORDER BY a.sent_at DESC LIMIT 200';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'id'                => (int) $row->id,
					'sender_user_id'    => (int) $row->sender_user_id,
					'scope'             => $row->scope,
					'target_grade_id'   => $row->target_grade_id ? (int) $row->target_grade_id : null,
					'target_student_id' => $row->target_student_id ? (int) $row->target_student_id : null,
					'title'             => $row->title,
					'body'              => wp_kses_post( (string) $row->body ),
					'channels'          => array_values( array_filter( explode( ',', (string) $row->channels ) ) ),
					'sent_at'           => Edu_Api::date( $row->sent_at ),
					'recipients_total'  => (int) $row->total_dest,
					'recipients_read'   => (int) $row->leidos,
				);
			},
			(array) $rows
		);
	}

	/**
	 * Bandeja del usuario actual: comunicados que le llegaron.
	 *
	 * @param array $args unread (bool).
	 * @return array
	 */
	public static function my_announcements( array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$sql = "SELECT a.*, r.read_at, r.channel
		        FROM {$p}announcement_recipients r
		        INNER JOIN {$p}announcements a ON a.id = r.announcement_id
		        WHERE r.user_id = %d";

		$params = array( get_current_user_id() );

		if ( ! empty( $args['unread'] ) ) {
			$sql .= ' AND r.read_at IS NULL';
		}

		$sql .= ' ORDER BY a.sent_at DESC LIMIT 200';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'id'       => (int) $row->id,
					'title'    => $row->title,
					'body'     => wp_kses_post( (string) $row->body ),
					'scope'    => $row->scope,
					'sent_at'  => Edu_Api::date( $row->sent_at ),
					'read_at'  => Edu_Api::date( $row->read_at ),
					'is_read'  => ! empty( $row->read_at ),
					'channel'  => $row->channel,
				);
			},
			(array) $rows
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Pagos
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Pagos visibles: todos los de la institución para el rector, solo los de
	 * sus hijos para el representante.
	 *
	 * @param array $args student_id, period_id, status, month.
	 * @return array|WP_Error
	 */
	public static function payments( array $args = array() ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$sql = "SELECT pay.*, s.id AS sid, g.name AS grade_name, g.paralelo,
		               COALESCE(um_fn.meta_value, '') AS nombres,
		               COALESCE(um_ln.meta_value, u.display_name) AS apellidos
		        FROM {$p}payments pay
		        INNER JOIN {$p}students s ON s.id = pay.student_id
		        INNER JOIN {$p}grades g   ON g.id = s.grade_id
		        INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
		        LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
		        LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
		        WHERE g.institution_id = %d";

		$params = array( Edu_Context::current_institution_id() );

		if ( ! Edu_Service::sees_whole_institution() ) {
			$identity = Edu_Service::identity();
			$mine     = $identity['student_id'] ? array( $identity['student_id'] ) : Edu_Service::own_children_ids();

			if ( empty( $mine ) ) {
				return Edu_Service::out_of_scope();
			}

			$sql .= ' AND pay.student_id IN (' . implode( ',', array_map( 'intval', $mine ) ) . ')';
		}

		if ( ! empty( $args['student_id'] ) ) {
			$sql     .= ' AND pay.student_id = %d';
			$params[] = (int) $args['student_id'];
		}

		if ( ! empty( $args['period_id'] ) ) {
			$sql     .= ' AND pay.period_id = %d';
			$params[] = (int) $args['period_id'];
		}

		foreach ( array( 'status', 'month', 'concept' ) as $filter ) {
			if ( ! empty( $args[ $filter ] ) ) {
				$sql     .= " AND pay.$filter = %s";
				$params[] = $args[ $filter ];
			}
		}

		$sql .= ' ORDER BY pay.due_date DESC, pay.id DESC LIMIT 500';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'id'             => (int) $row->id,
					'student_id'     => (int) $row->student_id,
					'nombres'        => $row->nombres,
					'apellidos'      => $row->apellidos,
					'grade_name'     => trim( $row->grade_name . ' ' . $row->paralelo ),
					'period_id'      => (int) $row->period_id,
					'month'          => $row->month,
					'concept'        => $row->concept,
					'amount'         => Edu_Api::decimal( $row->amount ),
					'due_date'       => $row->due_date,
					'status'         => $row->status,
					'paid_at'        => Edu_Api::date( $row->paid_at ),
					'payment_method' => $row->payment_method,
					'payment_ref'    => $row->payment_ref,
				);
			},
			(array) $rows
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Auditoría
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Registro de auditoría.
	 *
	 * @param array $args entity_type, user_id, action, from, to, page, per_page.
	 * @return array{items:array, total:int}|WP_Error
	 */
	public static function audit( array $args = array() ) {
		$cap = Edu_Service::require_cap( 'edu_view_audit' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		global $wpdb;
		$t = $wpdb->prefix . 'edu_audit';

		$sql    = "FROM $t WHERE 1=1";
		$params = array();

		foreach ( array( 'entity_type', 'action' ) as $filter ) {
			if ( ! empty( $args[ $filter ] ) ) {
				$sql     .= " AND $filter = %s";
				$params[] = $args[ $filter ];
			}
		}

		if ( ! empty( $args['user_id'] ) ) {
			$sql     .= ' AND user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( ! empty( $args['from'] ) ) {
			$sql     .= ' AND timestamp >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}

		if ( ! empty( $args['to'] ) ) {
			$sql     .= ' AND timestamp <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}

		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $sql", $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( "SELECT COUNT(*) $sql" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * $sql ORDER BY timestamp DESC, id DESC LIMIT %d OFFSET %d", $list_params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$items = array_map(
			static function ( $row ) {
				return array(
					'id'          => (int) $row->id,
					'user_id'     => (int) $row->user_id,
					'user_name'   => get_the_author_meta( 'display_name', (int) $row->user_id ),
					'action'      => $row->action,
					'action_label' => Edu_Audit::action_label( $row->action ),
					'color'       => Edu_Audit::action_color( $row->action ),
					'entity_type' => $row->entity_type,
					'entity_id'   => $row->entity_id ? (int) $row->entity_id : null,
					'old_value'   => json_decode( (string) $row->old_value, true ),
					'new_value'   => json_decode( (string) $row->new_value, true ),
					'ip_address'  => $row->ip_address,
					'timestamp'   => Edu_Api::date( $row->timestamp ),
				);
			},
			(array) $rows
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
