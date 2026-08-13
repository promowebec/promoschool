<?php
/**
 * Servicio: dashboards y acceso a reportes.
 *
 * Los dashboards se devuelven **ya armados** (contrato §14, decisión 5): son
 * pantallas concretas con métricas concretas, y componerlas en el cliente
 * obligaría a media docena de llamadas por carga.
 *
 * Los binarios (boletín PDF, exportes .xlsx, ZIP) no se sirven desde aquí: el
 * servicio valida el permiso y emite una URL firmada de vida corta, porque el
 * navegador no puede mandar la cabecera Authorization en una descarga directa
 * (contrato §10).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Report_Service {

	/* ─────────────────────────────────────────────────────────────────────
	 * Dashboard del rector
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Métricas institucionales, rendimiento por grado y alertas.
	 *
	 * @param array $args period_id, trimester_id.
	 * @return array|WP_Error
	 */
	public static function dashboard_rector( array $args = array() ) {
		$cap = Edu_Service::require_cap( 'edu_view_all' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$stats = array(
			'estudiantes' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$p}students s
					 INNER JOIN {$p}grades g ON g.id = s.grade_id
					 WHERE g.institution_id = %d AND s.status = 'active'",
					$institution_id
				)
			),
			// wp_edu_teachers no tiene institution_id: el vínculo vive en usermeta.
			'docentes'    => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$p}teachers t
					 INNER JOIN {$wpdb->usermeta} m
					         ON m.user_id = t.user_id
					        AND m.meta_key = 'edu_institution_id'
					        AND m.meta_value = %d",
					$institution_id
				)
			),
			'grados'      => (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$p}grades WHERE institution_id = %d", $institution_id )
			),
			'materias'    => (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$p}subjects WHERE institution_id = %d", $institution_id )
			),
		);

		if ( Edu_Modules::is_active( 'tareas' ) ) {
			$stats['tareas_publicadas'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$p}assignments a
					 INNER JOIN {$p}grades g ON g.id = a.grade_id
					 WHERE g.institution_id = %d AND a.status = 'published'",
					$institution_id
				)
			);
		}

		$trimester_id = isset( $args['trimester_id'] ) ? (int) $args['trimester_id'] : 0;

		return array(
			'stats'       => $stats,
			'rendimiento' => self::rendimiento_por_grado( $institution_id, $trimester_id ),
			'alertas'     => self::alertas_asistencia( $institution_id ),
			'pagos'       => self::resumen_pagos( $institution_id, isset( $args['period_id'] ) ? (int) $args['period_id'] : 0 ),
		);
	}

	/**
	 * Promedio por grado en un trimestre, con su equivalencia cualitativa.
	 *
	 * @param int $institution_id Institución.
	 * @param int $trimester_id   Trimestre (0 = todos).
	 * @return array
	 */
	private static function rendimiento_por_grado( $institution_id, $trimester_id ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$sql = "SELECT g.id, g.name, g.paralelo, g.sub_level,
		               AVG(ts.computed_score) AS promedio,
		               COUNT(DISTINCT ts.student_id) AS estudiantes
		        FROM {$p}trimester_scores ts
		        INNER JOIN {$p}students s ON s.id = ts.student_id
		        INNER JOIN {$p}grades g   ON g.id = s.grade_id
		        WHERE g.institution_id = %d AND ts.computed_score > 0";

		$params = array( $institution_id );

		if ( $trimester_id ) {
			$sql     .= ' AND ts.trimester_id = %d';
			$params[] = $trimester_id;
		}

		$sql .= ' GROUP BY g.id, g.name, g.paralelo, g.sub_level ORDER BY g.name, g.paralelo';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				$promedio = round( (float) $row->promedio, 2 );

				return array(
					'grade_id'     => (int) $row->id,
					'grade_name'   => trim( $row->name . ' ' . $row->paralelo ),
					'sub_level'    => $row->sub_level,
					'promedio'     => $promedio,
					'cualitativa'  => Edu_Gradebook_Service::cualitativa( $promedio ),
					'estudiantes'  => (int) $row->estudiantes,
				);
			},
			(array) $rows
		);
	}

	/**
	 * Estudiantes con asistencia baja en los últimos 30 días.
	 *
	 * @param int $institution_id Institución.
	 * @return array
	 */
	private static function alertas_asistencia( $institution_id ) {
		if ( ! Edu_Modules::is_active( 'asistencia' ) ) {
			return array();
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id AS student_id,
				        COALESCE(um_fn.meta_value, '') AS nombres,
				        COALESCE(um_ln.meta_value, u.display_name) AS apellidos,
				        g.name AS grade_name, g.paralelo,
				        COUNT(DISTINCT a.date) AS dias,
				        SUM(CASE WHEN a.status IN ('falta_justificada','falta_injustificada') THEN 1 ELSE 0 END) AS faltas
				 FROM {$p}attendance a
				 INNER JOIN {$p}students s ON s.id = a.student_id
				 INNER JOIN {$p}grades g   ON g.id = s.grade_id
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE g.institution_id = %d
				   AND a.subject_id IS NULL
				   AND a.date >= DATE_SUB(%s, INTERVAL 30 DAY)
				 GROUP BY s.id, nombres, apellidos, g.name, g.paralelo
				 HAVING faltas >= 3
				 ORDER BY faltas DESC
				 LIMIT 20",
				$institution_id,
				gmdate( 'Y-m-d' )
			)
		);

		return array_map(
			static function ( $row ) {
				$dias = max( 1, (int) $row->dias );

				return array(
					'student_id' => (int) $row->student_id,
					'nombres'    => $row->nombres,
					'apellidos'  => $row->apellidos,
					'grade_name' => trim( $row->grade_name . ' ' . $row->paralelo ),
					'dias'       => (int) $row->dias,
					'faltas'     => (int) $row->faltas,
					'porcentaje' => round( ( $dias - (int) $row->faltas ) / $dias * 100, 2 ),
				);
			},
			(array) $rows
		);
	}

	/**
	 * Resumen de cobranza del período.
	 *
	 * @param int $institution_id Institución.
	 * @param int $period_id      Período (0 = el activo).
	 * @return array|null
	 */
	private static function resumen_pagos( $institution_id, $period_id ) {
		if ( ! Edu_Modules::is_active( 'pagos' ) ) {
			return null;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		if ( ! $period_id ) {
			$period_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$p}periods WHERE institution_id = %d AND is_active = 1 ORDER BY id DESC LIMIT 1",
					$institution_id
				)
			);
		}

		if ( ! $period_id ) {
			return null;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pay.status, COUNT(*) AS n, COALESCE(SUM(pay.amount),0) AS total
				 FROM {$p}payments pay
				 INNER JOIN {$p}students s ON s.id = pay.student_id
				 INNER JOIN {$p}grades g   ON g.id = s.grade_id
				 WHERE g.institution_id = %d AND pay.period_id = %d
				 GROUP BY pay.status",
				$institution_id,
				$period_id
			)
		);

		$out = array(
			'period_id' => $period_id,
			'pending'   => array( 'count' => 0, 'amount' => 0.0 ),
			'paid'      => array( 'count' => 0, 'amount' => 0.0 ),
			'overdue'   => array( 'count' => 0, 'amount' => 0.0 ),
			'waived'    => array( 'count' => 0, 'amount' => 0.0 ),
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row->status ] ) ) {
				$out[ $row->status ] = array(
					'count'  => (int) $row->n,
					'amount' => Edu_Api::decimal( $row->total ),
				);
			}
		}

		return $out;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Dashboard del docente
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Lo que el docente necesita al entrar: sus asignaciones, si ya tomó
	 * asistencia hoy y qué entregas tiene sin calificar.
	 *
	 * @return array|WP_Error
	 */
	public static function dashboard_docente() {
		$cap = Edu_Service::require_cap( array( 'edu_grade_students', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$identity = Edu_Service::identity();

		if ( ! $identity['teacher_id'] ) {
			return Edu_Service::error(
				'not_a_teacher',
				__( 'Tu usuario no tiene ficha de docente.', 'sistema-educativo' ),
				409
			);
		}

		global $wpdb;
		$p     = $wpdb->prefix . 'edu_';
		$hoy   = current_time( 'Y-m-d' );
		$grade_ids = Edu_Service::own_grade_ids();

		$asistencia_hoy = array();
		if ( Edu_Modules::is_active( 'asistencia' ) && ! empty( $grade_ids ) ) {
			$in = implode( ',', array_map( 'intval', $grade_ids ) );

			$asistencia_hoy = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT g.id AS grade_id, g.name, g.paralelo,
					        (SELECT COUNT(*) FROM {$p}students s WHERE s.grade_id = g.id AND s.status = 'active') AS estudiantes,
					        (SELECT COUNT(*) FROM {$p}attendance a
					          INNER JOIN {$p}students s2 ON s2.id = a.student_id
					          WHERE s2.grade_id = g.id AND a.date = %s AND a.subject_id IS NULL) AS registrados
					 FROM {$p}grades g
					 WHERE g.id IN ($in)
					 ORDER BY g.name, g.paralelo", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$hoy
				)
			);
		}

		$pendientes = array();
		if ( Edu_Modules::is_active( 'tareas' ) ) {
			$pendientes = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.id, a.title, a.due_date, g.name AS grade_name, g.paralelo, s.name AS subject_name,
					        COUNT(su.id) AS por_calificar
					 FROM {$p}assignments a
					 INNER JOIN {$p}grades g   ON g.id = a.grade_id
					 INNER JOIN {$p}subjects s ON s.id = a.subject_id
					 INNER JOIN {$p}submissions su ON su.assignment_id = a.id AND su.status IN ('submitted','late')
					 WHERE a.teacher_id = %d
					 GROUP BY a.id, a.title, a.due_date, g.name, g.paralelo, s.name
					 ORDER BY a.due_date DESC
					 LIMIT 20",
					$identity['teacher_id']
				)
			);
		}

		return array(
			'teacher_id'     => $identity['teacher_id'],
			'date'           => $hoy,
			'assignments'    => Edu_People_Service::teacher_assignments( array( 'is_active' => true ) ),
			'asistencia_hoy' => array_map(
				static function ( $row ) {
					return array(
						'grade_id'    => (int) $row->grade_id,
						'grade_name'  => trim( $row->name . ' ' . $row->paralelo ),
						'estudiantes' => (int) $row->estudiantes,
						'registrados' => (int) $row->registrados,
						'tomada'      => (int) $row->registrados > 0 && (int) $row->registrados >= (int) $row->estudiantes,
					);
				},
				(array) $asistencia_hoy
			),
			'por_calificar'  => array_map(
				static function ( $row ) {
					return array(
						'assignment_id' => (int) $row->id,
						'title'         => $row->title,
						'grade_name'    => trim( $row->grade_name . ' ' . $row->paralelo ),
						'subject_name'  => $row->subject_name,
						'due_date'      => Edu_Api::date( $row->due_date ),
						'pendientes'    => (int) $row->por_calificar,
					);
				},
				(array) $pendientes
			),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Panel de docentes (supervisión del rector)
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Avance de cada docente por asignación: componentes definidos, tareas
	 * creadas, notas registradas y última actividad.
	 *
	 * Cubre la deuda técnica #3 de la bitácora: el panel existía solo en
	 * wp-admin y ahora la app también lo tiene.
	 *
	 * @param array $args period_id.
	 * @return array|WP_Error
	 */
	public static function teacher_panel( array $args = array() ) {
		$cap = Edu_Service::require_cap( 'edu_view_all' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$sql = "SELECT ta.id, ta.teacher_id, ta.grade_id, ta.subject_id,
		               g.name AS grade_name, g.paralelo, s.name AS subject_name,
		               COALESCE(um_fn.meta_value, '') AS nombres,
		               COALESCE(um_ln.meta_value, u.display_name) AS apellidos,
		               (SELECT COUNT(*) FROM {$p}grade_components c
		                 INNER JOIN {$p}trimesters t ON t.id = c.trimester_id
		                 WHERE c.subject_id = ta.subject_id AND t.period_id = ta.period_id) AS componentes,
		               (SELECT COUNT(*) FROM {$p}assignments a
		                 WHERE a.grade_id = ta.grade_id AND a.subject_id = ta.subject_id) AS tareas,
		               (SELECT COUNT(*) FROM {$p}grades_log gl
		                 INNER JOIN {$p}grade_components c2 ON c2.id = gl.component_id
		                 INNER JOIN {$p}students st ON st.id = gl.student_id
		                 WHERE c2.subject_id = ta.subject_id AND st.grade_id = ta.grade_id) AS notas,
		               (SELECT MAX(gl2.registered_at) FROM {$p}grades_log gl2
		                 INNER JOIN {$p}grade_components c3 ON c3.id = gl2.component_id
		                 INNER JOIN {$p}students st2 ON st2.id = gl2.student_id
		                 WHERE c3.subject_id = ta.subject_id AND st2.grade_id = ta.grade_id) AS ultima_nota
		        FROM {$p}teacher_assignments ta
		        INNER JOIN {$p}grades g   ON g.id = ta.grade_id
		        INNER JOIN {$p}subjects s ON s.id = ta.subject_id
		        INNER JOIN {$p}teachers t ON t.id = ta.teacher_id
		        INNER JOIN {$wpdb->users} u ON u.ID = t.user_id
		        LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = t.user_id AND um_fn.meta_key = 'first_name'
		        LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = t.user_id AND um_ln.meta_key = 'last_name'
		        WHERE g.institution_id = %d AND ta.is_active = 1";

		$params = array( $institution_id );

		if ( ! empty( $args['period_id'] ) ) {
			$sql     .= ' AND ta.period_id = %d';
			$params[] = (int) $args['period_id'];
		}

		$sql .= ' ORDER BY apellidos, nombres, g.name';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'assignment_id' => (int) $row->id,
					'teacher_id'    => (int) $row->teacher_id,
					'teacher_name'  => trim( $row->nombres . ' ' . $row->apellidos ),
					'grade_id'      => (int) $row->grade_id,
					'grade_name'    => trim( $row->grade_name . ' ' . $row->paralelo ),
					'subject_id'    => (int) $row->subject_id,
					'subject_name'  => $row->subject_name,
					'componentes'   => (int) $row->componentes,
					'tareas'        => (int) $row->tareas,
					'notas'         => (int) $row->notas,
					'ultima_nota'   => Edu_Api::date( $row->ultima_nota ),
				);
			},
			(array) $rows
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Acceso a binarios
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Autoriza la descarga del boletín de un estudiante y emite la URL firmada.
	 *
	 * @param int $student_id Estudiante.
	 * @param int $period_id  Período.
	 * @return array|WP_Error
	 */
	public static function boletin_url( $student_id, $period_id ) {
		if ( ! Edu_Modules::is_active( 'boletines' ) ) {
			return Edu_Service::error(
				'module_disabled',
				__( 'El módulo de boletines no está habilitado.', 'sistema-educativo' ),
				404
			);
		}

		$allowed = Edu_Service::can_view_student( $student_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return Edu_File_Service::signed_url(
			'boletin',
			array(
				'student_id' => (int) $student_id,
				'period_id'  => (int) $period_id,
			)
		);
	}

	/**
	 * Autoriza un exporte Mineduc y emite la URL firmada.
	 *
	 * @param string $tipo Tipo de exporte.
	 * @param array  $args period_id, grade_id.
	 * @return array|WP_Error
	 */
	public static function mineduc_url( $tipo, array $args ) {
		if ( ! Edu_Modules::is_active( 'exportes' ) ) {
			return Edu_Service::error(
				'module_disabled',
				__( 'El módulo de exportes no está habilitado.', 'sistema-educativo' ),
				404
			);
		}

		$cap = Edu_Service::require_cap( array( 'edu_generate_reports', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$tipos = array( 'acta-consolidada', 'nomina-amie', 'distributivo-docente', 'asistencia-acumulada' );

		if ( ! in_array( $tipo, $tipos, true ) ) {
			return Edu_Api::invalid_params(
				array( 'tipo' => __( 'Tipo de exporte desconocido.', 'sistema-educativo' ) )
			);
		}

		if ( ! empty( $args['grade_id'] ) ) {
			$scope = Edu_Service::check_scope( array( 'grade_id' => (int) $args['grade_id'] ) );
			if ( is_wp_error( $scope ) ) {
				return $scope;
			}
		}

		return Edu_File_Service::signed_url(
			'mineduc',
			array(
				'tipo'      => $tipo,
				'period_id' => (int) ( $args['period_id'] ?? 0 ),
				'grade_id'  => (int) ( $args['grade_id'] ?? 0 ),
			)
		);
	}
}
