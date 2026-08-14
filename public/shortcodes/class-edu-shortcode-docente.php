<?php
/**
 * Shortcode [edu_portal_docente] — Portal del docente con funcionalidad completa.
 *
 * Todos los formularios de acción se procesan vía admin-post.php con _redirect al portal.
 * Tabs: inicio | calificaciones | tareas | asistencia | comunicados
 *
 *
 * ─────────────────────────────────────────────────────────────────────────
 * CONGELADO — 12 de agosto de 2026
 *
 * Este portal está en mantenimiento correctivo: se corrigen errores, no se
 * agregan funciones. Todo lo nuevo va a la SPA de la Fase 2, que consume la
 * API `edu/v1` y terminará reemplazando a este shortcode.
 *
 * Si vas a añadir una función aquí, para: probablemente corresponde a la SPA.
 * Contexto en docs/BITACORA.md y docs/API_CONTRATO_V1.md.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Shortcode_Docente {

	public static function register() {
		add_shortcode( 'edu_portal_docente', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_edu_get_subjects_for_grade', array( __CLASS__, 'ajax_subjects_for_grade' ) );
	}

	public static function ajax_subjects_for_grade() {
		if ( ! check_ajax_referer( 'edu_docente_nonce', 'nonce', false ) ) {
			wp_send_json_error( 'nonce', 403 );
		}
		$grade_id = isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0;
		if ( ! $grade_id ) {
			wp_send_json_success( array() );
		}

		global $wpdb;
		$p          = $wpdb->prefix . 'edu_';
		$teacher_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}teachers WHERE user_id = %d",
			get_current_user_id()
		) );

		$subjects = array();
		if ( $teacher_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT s.id, s.name
				 FROM {$p}teacher_assignments ta
				 INNER JOIN {$p}subjects s ON s.id = ta.subject_id
				 WHERE ta.teacher_id = %d AND ta.grade_id = %d AND ta.is_active = 1
				 ORDER BY s.name",
				$teacher_id, $grade_id
			) );
			foreach ( $rows as $r ) {
				$subjects[] = array( 'id' => (int) $r->id, 'name' => $r->name );
			}
		}
		if ( empty( $subjects ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id, s.name FROM {$p}grade_subjects gs
				 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
				 WHERE gs.grade_id = %d ORDER BY s.name",
				$grade_id
			) );
			foreach ( $rows as $r ) {
				$subjects[] = array( 'id' => (int) $r->id, 'name' => $r->name );
			}
		}
		wp_send_json_success( $subjects );
	}

	public static function render( $atts ) {
		// ── Autenticación y permisos ──────────────────────────────────────────
		if ( ! is_user_logged_in() ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'Debes iniciar sesión para acceder al portal.', 'sistema-educativo' ) .
				'</div></div>';
		}
		if ( ! ( Edu_Context::can( 'edu_grade_students' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'No tienes permiso para ver este portal.', 'sistema-educativo' ) .
				'</div></div>';
		}

		$institution_id = Edu_Context::current_institution_id();
		if ( ! $institution_id ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'No hay institución activa asignada a tu cuenta.', 'sistema-educativo' ) .
				'</div></div>';
		}

		wp_enqueue_style( 'edu-portales', EDU_PLUGIN_URL . 'public/css/portales.css', array(), EDU_VERSION );

		// ── Navegación ────────────────────────────────────────────────────────
		$tab        = isset( $_GET['edu_tab'] ) ? sanitize_key( $_GET['edu_tab'] ) : 'inicio';
		$valid_tabs = Edu_Modules::filter_tabs( array( 'inicio', 'materias', 'calificaciones', 'tareas', 'asistencia', 'comunicados', 'textos' ) );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'inicio';
		}

		// ── Contexto DB ───────────────────────────────────────────────────────
		global $wpdb;
		$p           = $wpdb->prefix . 'edu_';
		$uid         = get_current_user_id();
		$today       = date( 'Y-m-d' );
		$month_start = date( 'Y-m-01' );
		$month_end   = date( 'Y-m-t' );
		$current_url = get_permalink();

		// Nombre institución y período activo
		$inst_name  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$p}institutions WHERE id = %d", $institution_id ) );
		$period_row = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$p}periods WHERE institution_id = %d AND is_active = 1 LIMIT 1", $institution_id ) );
		$period_name = $period_row ? $period_row->name : '';

		// teacher_id del usuario actual
		$teacher_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}teachers WHERE user_id = %d",
			$uid
		) );

		if ( ! $teacher_id && ! Edu_Context::can( 'edu_view_all' ) ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'No se encontró tu registro de docente. Contacta al administrador.', 'sistema-educativo' ) .
				'</div></div>';
		}

		// Grados asignados al docente (solo asignaciones activas)
		if ( $teacher_id ) {
			$mis_grados = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT g.id, g.name, g.paralelo, g.sub_level
				 FROM {$p}teacher_assignments ta
				 INNER JOIN {$p}grades g ON g.id = ta.grade_id
				 WHERE ta.teacher_id = %d AND g.institution_id = %d AND ta.is_active = 1
				 ORDER BY g.sub_level, g.name, g.paralelo",
				$teacher_id, $institution_id
			) );
		} else {
			$mis_grados = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, paralelo, sub_level FROM {$p}grades
				 WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
				$institution_id
			) );
		}

		$grade_ids = array_map( 'intval', array_column( $mis_grados, 'id' ) );
		$ids_ph    = $grade_ids ? implode( ',', array_fill( 0, count( $grade_ids ), '%d' ) ) : '0';

		// Trimestres (reutilizado en varios tabs)
		$trimesters = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id, t.number AS trim_num, p.name AS period_name
			 FROM {$p}trimesters t INNER JOIN {$p}periods p ON p.id = t.period_id
			 WHERE p.institution_id = %d ORDER BY p.start_date DESC, t.number",
			$institution_id
		) );

		// ── Datos: Tab Inicio ─────────────────────────────────────────────────
		$entregas_pendientes = 0;
		$tareas_activas      = 0;
		$asistencia_hoy      = array( 'p' => 0, 't' => 0 );

		if ( $grade_ids ) {
			$cond_t = $teacher_id ? $wpdb->prepare( 'AND a.teacher_id = %d', $teacher_id ) : '';

			$entregas_pendientes = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}submissions sb
				 INNER JOIN {$p}assignments a ON a.id = sb.assignment_id
				 WHERE a.grade_id IN ($ids_ph) AND sb.status IN ('submitted','late') $cond_t",
				...$grade_ids
			) );
			$tareas_activas = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}assignments a
				 WHERE a.grade_id IN ($ids_ph) AND a.status = 'published' $cond_t",
				...$grade_ids
			) );
			$asistencia_hoy['t'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}students WHERE grade_id IN ($ids_ph)",
				...$grade_ids
			) );
			$asistencia_hoy['p'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT CONCAT(att.student_id, '-', att.date))
				 FROM {$p}attendance att
				 INNER JOIN {$p}students s ON s.id = att.student_id
				 WHERE s.grade_id IN ($ids_ph) AND att.date = %s AND att.status IN ('presente','atraso')",
				...array_merge( $grade_ids, array( $today ) )
			) );
		}

		// ── Datos: Próximas entregas (inicio) ────────────────────────────────
		$proximas_entregas = array();
		if ( $grade_ids ) {
			$cond_te = $teacher_id ? $wpdb->prepare( 'AND a.teacher_id = %d', $teacher_id ) : '';
			$proximas_entregas = $wpdb->get_results( $wpdb->prepare(
				"SELECT a.id, a.title, a.due_date, g.name AS grade_name, g.paralelo, s.name AS subject_name
				 FROM {$p}assignments a
				 INNER JOIN {$p}grades g ON g.id = a.grade_id
				 INNER JOIN {$p}subjects s ON s.id = a.subject_id
				 WHERE a.grade_id IN ($ids_ph) AND a.status = 'published'
				   AND a.due_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
				   $cond_te
				 ORDER BY a.due_date ASC LIMIT 5",
				...$grade_ids
			) );
		}

		// ── Datos: Alertas de estudiantes (inicio) ────────────────────────────
		$alertas_inicio = array();
		if ( $grade_ids ) {
			// Faltas recientes (≥ 3 en los últimos 7 días)
			$faltas_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id,
				        COALESCE(um_fn.meta_value,'') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
				        COUNT(*) AS n_faltas
				 FROM {$p}attendance att
				 INNER JOIN {$p}students s ON s.id = att.student_id
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE s.grade_id IN ($ids_ph)
				   AND att.date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
				   AND att.status IN ('falta_injustificada','falta_justificada')
				 GROUP BY s.id HAVING n_faltas >= 3
				 ORDER BY n_faltas DESC LIMIT 4",
				...$grade_ids
			) );
			foreach ( $faltas_rows as $r ) {
				$alertas_inicio[] = array(
					'name'   => trim( $r->first_name . ' ' . $r->last_name ),
					'detail' => sprintf( _n( '%d falta en 7 días', '%d faltas en 7 días', (int) $r->n_faltas, 'sistema-educativo' ), (int) $r->n_faltas ),
					'color'  => '#ef4444',
				);
			}
			// Notas bajas en parcial (computed_score < 5)
			$notas_bajas_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT s.id,
				        COALESCE(um_fn.meta_value,'') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
				        sub.name AS subject_name, ps.computed_score
				 FROM {$p}parcial_scores ps
				 INNER JOIN {$p}students s ON s.id = ps.student_id
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 INNER JOIN {$p}subjects sub ON sub.id = ps.subject_id
				 WHERE s.grade_id IN ($ids_ph) AND ps.computed_score < 5 AND ps.computed_score IS NOT NULL
				 ORDER BY ps.computed_score ASC LIMIT 4",
				...$grade_ids
			) );
			foreach ( $notas_bajas_rows as $r ) {
				$alertas_inicio[] = array(
					'name'   => trim( $r->first_name . ' ' . $r->last_name ),
					'detail' => sprintf( __( 'Nota baja en %s (%.1f)', 'sistema-educativo' ), $r->subject_name, (float) $r->computed_score ),
					'color'  => '#f59e0b',
				);
			}
			$alertas_inicio = array_slice( $alertas_inicio, 0, 5 );
		}

		// ── Datos: Tab Mis materias ──────────────────────────────────────────
		$mis_materias = array();
		if ( $teacher_id && $grade_ids ) {
			$rows_mat = $wpdb->get_results( $wpdb->prepare(
				"SELECT g.id AS grade_id, g.name AS grade_name, g.paralelo, g.sub_level,
				        s.id AS subject_id, s.name AS subject_name,
				        (SELECT COUNT(*) FROM {$p}assignments a
				           WHERE a.grade_id = g.id AND a.subject_id = s.id
				             AND a.teacher_id = ta.teacher_id AND a.status = 'published') AS n_tareas,
				        (SELECT COUNT(*) FROM {$p}submissions sb
				           INNER JOIN {$p}assignments a2 ON a2.id = sb.assignment_id
				           WHERE a2.grade_id = g.id AND a2.subject_id = s.id
				             AND a2.teacher_id = ta.teacher_id AND sb.status IN ('submitted','late')) AS n_entregas
				 FROM {$p}teacher_assignments ta
				 INNER JOIN {$p}grades g ON g.id = ta.grade_id
				 INNER JOIN {$p}subjects s ON s.id = ta.subject_id
				 WHERE ta.teacher_id = %d AND g.institution_id = %d AND ta.is_active = 1
				 ORDER BY g.sub_level, g.name, g.paralelo, s.name",
				$teacher_id, $institution_id
			) );
			foreach ( $rows_mat as $rm ) {
				$gk = $rm->grade_id;
				if ( ! isset( $mis_materias[ $gk ] ) ) {
					$mis_materias[ $gk ] = array(
						'grade_name' => $rm->grade_name,
						'paralelo'   => $rm->paralelo,
						'sub_level'  => $rm->sub_level,
						'materias'   => array(),
					);
				}
				$mis_materias[ $gk ]['materias'][] = array(
					'subject_id'   => $rm->subject_id,
					'subject_name' => $rm->subject_name,
					'n_tareas'     => (int) $rm->n_tareas,
					'n_entregas'   => (int) $rm->n_entregas,
				);
			}
		}

		// ── Datos: Tab Calificaciones ─────────────────────────────────────────
		$grade_sel   = isset( $_GET['edu_grade'] )   ? (int) $_GET['edu_grade']   : ( $grade_ids ? (int) $grade_ids[0] : 0 );
		$subject_sel = isset( $_GET['edu_subject'] )  ? (int) $_GET['edu_subject'] : 0;
		$trim_sel    = isset( $_GET['edu_trim'] )     ? (int) $_GET['edu_trim']    : 0;
		$parcial_sel = isset( $_GET['edu_parcial'] )  ? (int) $_GET['edu_parcial'] : 1;

		$materias_grado = array();
		if ( $grade_sel ) {
			if ( $teacher_id ) {
				// Docente: solo las materias que le asignaron en asignaciones académicas para este grado
				$materias_grado = $wpdb->get_results( $wpdb->prepare(
					"SELECT DISTINCT s.id, s.name
					 FROM {$p}teacher_assignments ta
					 INNER JOIN {$p}subjects s ON s.id = ta.subject_id
					 WHERE ta.teacher_id = %d AND ta.grade_id = %d
					 ORDER BY s.name",
					$teacher_id, $grade_sel
				) );
			} else {
				// Rector / admin: todas las materias del pensum del grado
				$materias_grado = $wpdb->get_results( $wpdb->prepare(
					"SELECT DISTINCT s.id, s.name
					 FROM {$p}grade_subjects gs
					 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
					 WHERE gs.grade_id = %d ORDER BY s.name",
					$grade_sel
				) );
			}
			// Si subject_sel no pertenece a este grado (tras cambio de grado), resetear
			$valid_subj_ids = array_map( 'intval', array_column( $materias_grado, 'id' ) );
			if ( $subject_sel && ! in_array( $subject_sel, $valid_subj_ids, true ) ) {
				$subject_sel = 0;
			}
			if ( ! $subject_sel && $materias_grado ) {
				$subject_sel = (int) $materias_grado[0]->id;
			}
		}
		if ( ! $trim_sel && $trimesters ) {
			$trim_sel = (int) $trimesters[0]->id;
		}

		$componentes = array();
		if ( $subject_sel && $trim_sel ) {
			$componentes = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, weight FROM {$p}grade_components
				 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d ORDER BY name",
				$subject_sel, $trim_sel, $parcial_sel
			) );
		}

		$estudiantes_notas = array();
		if ( $grade_sel && $subject_sel && $trim_sel && $componentes ) {
			$estudiantes_notas = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id,
				        COALESCE(um_fn.meta_value, '') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name
				 FROM {$p}students s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE s.grade_id = %d ORDER BY last_name, first_name",
				$grade_sel
			) );

			if ( $estudiantes_notas ) {
				$comp_ids = array_map( 'intval', array_column( $componentes, 'id' ) );
				$est_ids  = array_map( 'intval', array_column( $estudiantes_notas, 'id' ) );
				$comp_ph  = implode( ',', array_fill( 0, count( $comp_ids ), '%d' ) );
				$est_ph   = implode( ',', array_fill( 0, count( $est_ids ), '%d' ) );

				// Promedio de notas por (student, component) — múltiples tareas del mismo componente se promedian.
				$notas_raw = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, component_id, AVG(score) AS score
					 FROM {$p}grades_log
					 WHERE component_id IN ($comp_ph) AND student_id IN ($est_ph)
					 GROUP BY student_id, component_id",
					...array_merge( $comp_ids, $est_ids )
				) );
				$notas_idx = array();
				foreach ( $notas_raw as $n ) {
					$notas_idx[ (int) $n->student_id ][ (int) $n->component_id ] = (float) $n->score;
				}
				foreach ( $estudiantes_notas as &$est ) {
					$est->notas = $notas_idx[ (int) $est->id ] ?? array();
				}
				unset( $est );
			}
		}

		// ── Datos: Evaluación Sumativa (Examen + Proyecto) ───────────────────
		// Solo carga si hay grado+materia+trimestre seleccionados.
		$sumativa_estudiantes = array();
		$sumativa_parc1_rows  = array();
		$sumativa_parc2_rows  = array();
		$sumativa_trim_rows   = array();
		$grade_sub_level      = '';
		$usa_sumativa_fe      = false;

		if ( $grade_sel && $subject_sel && $trim_sel ) {
			$grade_sub_level = $wpdb->get_var( $wpdb->prepare(
				"SELECT sub_level FROM {$p}grades WHERE id = %d",
				$grade_sel
			) );
			$usa_sumativa_fe = in_array( $grade_sub_level, array( 'media', 'superior', 'bg', 'bt' ), true );

			$sumativa_estudiantes = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id,
				        COALESCE(um_fn.meta_value,'') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name
				 FROM {$p}students s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE s.grade_id = %d AND s.status = 'active'
				 ORDER BY last_name, first_name",
				$grade_sel
			) );

			if ( $sumativa_estudiantes ) {
				$sum_ids    = array_map( 'intval', array_column( $sumativa_estudiantes, 'id' ) );
				$sum_ids_ph = implode( ',', $sum_ids );

				$t_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, final_exam_score, proyecto_score, computed_score, is_closed
					 FROM {$p}trimester_scores
					 WHERE subject_id = %d AND trimester_id = %d AND student_id IN ($sum_ids_ph)",
					$subject_sel, $trim_sel
				) );
				foreach ( $t_rows as $r ) {
					$sumativa_trim_rows[ (int) $r->student_id ] = $r;
				}

				$p1_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, computed_score FROM {$p}parcial_scores
					 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = 1 AND student_id IN ($sum_ids_ph)",
					$subject_sel, $trim_sel
				) );
				foreach ( $p1_rows as $r ) {
					$sumativa_parc1_rows[ (int) $r->student_id ] = (float) $r->computed_score;
				}

				$p2_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, computed_score FROM {$p}parcial_scores
					 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = 2 AND student_id IN ($sum_ids_ph)",
					$subject_sel, $trim_sel
				) );
				foreach ( $p2_rows as $r ) {
					$sumativa_parc2_rows[ (int) $r->student_id ] = (float) $r->computed_score;
				}
			}
		}

		// ── Datos: Tab Tareas ─────────────────────────────────────────────────
		$task_grade_filter  = isset( $_GET['edu_task_grade'] )   ? (int) $_GET['edu_task_grade']           : 0;
		$task_subj_filter   = isset( $_GET['edu_task_subj'] )    ? (int) $_GET['edu_task_subj']            : 0;
		$task_status_filter = isset( $_GET['edu_task_status'] )  ? sanitize_key( $_GET['edu_task_status'] ) : '';
		if ( ! in_array( $task_status_filter, array( '', 'draft', 'published', 'closed' ), true ) ) {
			$task_status_filter = '';
		}
		// Validar que el grado del filtro pertenezca al docente.
		if ( $task_grade_filter && ! in_array( $task_grade_filter, $grade_ids, true ) ) {
			$task_grade_filter = 0;
		}

		$tareas_lista = array();
		if ( $grade_ids ) {
			$sql_tareas      = "SELECT a.id, a.title, a.type, a.due_date, a.status, a.max_score,
			        a.allow_recovery, a.recovery_due_date,
			        g.name AS grade_name, g.paralelo, s.name AS subject_name,
			        (SELECT COUNT(*) FROM {$p}students WHERE grade_id = a.grade_id) AS total_est,
			        (SELECT COUNT(*) FROM {$p}submissions sb WHERE sb.assignment_id = a.id AND sb.status IN ('submitted','late')) AS pendientes,
			        (SELECT COUNT(*) FROM {$p}submissions sb2 WHERE sb2.assignment_id = a.id AND sb2.status = 'graded') AS calificadas,
			        (SELECT COUNT(*) FROM {$p}submissions sb3 WHERE sb3.assignment_id = a.id AND sb3.recovery_status = 'submitted') AS recovery_pending
			 FROM {$p}assignments a
			 INNER JOIN {$p}grades g ON g.id = a.grade_id
			 INNER JOIN {$p}subjects s ON s.id = a.subject_id
			 WHERE 1=1";
			$sql_tareas_args = array();

			if ( $task_grade_filter ) {
				$sql_tareas       .= ' AND a.grade_id = %d';
				$sql_tareas_args[] = $task_grade_filter;
			} else {
				$sql_tareas       .= " AND a.grade_id IN ($ids_ph)";
				$sql_tareas_args   = array_merge( $sql_tareas_args, $grade_ids );
			}

			if ( $teacher_id ) {
				$sql_tareas       .= ' AND a.teacher_id = %d';
				$sql_tareas_args[] = $teacher_id;
			}

			if ( $task_subj_filter ) {
				$sql_tareas       .= ' AND a.subject_id = %d';
				$sql_tareas_args[] = $task_subj_filter;
			}

			if ( $task_status_filter ) {
				$sql_tareas       .= ' AND a.status = %s';
				$sql_tareas_args[] = $task_status_filter;
			}

			$sql_tareas  .= " ORDER BY FIELD(a.status,'published','draft','closed'), a.due_date ASC, a.id DESC LIMIT 50";
			$tareas_lista = $wpdb->get_results( $wpdb->prepare( $sql_tareas, ...$sql_tareas_args ) );
		}

		// Tarea seleccionada para ver/calificar entregas
		$tarea_id_sel    = isset( $_GET['edu_tarea_id'] ) ? (int) $_GET['edu_tarea_id'] : 0;
		$tarea_detail    = null;
		$tarea_students  = array();
		$tarea_subs      = array();
		$tarea_sub_files = array();

		if ( $tarea_id_sel ) {
			// Mismo criterio que el listado: un docente solo puede abrir SUS tareas
			// dentro de sus grados; edu_view_all queda acotado a la institución activa.
			$sql_detail      = "SELECT a.*, g.name AS grade_name, g.paralelo, s.name AS subject_name
				 FROM {$p}assignments a
				 INNER JOIN {$p}grades g ON g.id = a.grade_id
				 INNER JOIN {$p}subjects s ON s.id = a.subject_id
				 WHERE a.id = %d AND g.institution_id = %d";
			$sql_detail_args = array( $tarea_id_sel, $institution_id );
			if ( $teacher_id ) {
				$sql_detail       .= ' AND a.teacher_id = %d';
				$sql_detail_args[] = $teacher_id;
			}
			$tarea_detail = $wpdb->get_row( $wpdb->prepare( $sql_detail, ...$sql_detail_args ) );

			if ( $tarea_detail ) {
				$tarea_students = $wpdb->get_results( $wpdb->prepare(
					"SELECT s.id,
					        COALESCE(um_fn.meta_value, '') AS first_name,
					        COALESCE(um_ln.meta_value, u.display_name) AS last_name
					 FROM {$p}students s
					 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
					 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
					 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
					 WHERE s.grade_id = %d AND s.status = 'active'
					 ORDER BY last_name, first_name",
					(int) $tarea_detail->grade_id
				) );

				$tst_ids = array_map( 'intval', array_column( $tarea_students, 'id' ) );
				if ( $tst_ids ) {
					$sph  = implode( ',', array_fill( 0, count( $tst_ids ), '%d' ) );
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT * FROM {$p}submissions WHERE assignment_id = %d AND student_id IN ($sph)",
						...array_merge( array( $tarea_id_sel ), $tst_ids )
					) );
					foreach ( $rows as $r ) {
						$tarea_subs[ (int) $r->student_id ] = $r;
					}

					if ( $tarea_subs ) {
						$sub_ids = array_map( 'intval', array_column( array_values( $tarea_subs ), 'id' ) );
						$sfph    = implode( ',', array_fill( 0, count( $sub_ids ), '%d' ) );
						$frows   = $wpdb->get_results( $wpdb->prepare(
							"SELECT * FROM {$p}submission_files WHERE submission_id IN ($sfph)",
							...$sub_ids
						) );
						foreach ( $frows as $f ) {
							$tarea_sub_files[ (int) $f->submission_id ][] = $f;
						}
					}
				}
			}
		}

		// Datos para formulario "Nueva tarea": grado → materias y componentes
		$grade_subjects_map = array();
		if ( $teacher_id ) {
			$ta_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT ta.grade_id, s.id, s.name
				 FROM {$p}teacher_assignments ta
				 INNER JOIN {$p}subjects s ON s.id = ta.subject_id
				 WHERE ta.teacher_id = %d AND ta.is_active = 1
				 ORDER BY ta.grade_id, s.name",
				$teacher_id
			) );
			foreach ( $ta_rows as $r ) {
				$grade_subjects_map[ (int) $r->grade_id ][] = array( 'id' => (int) $r->id, 'name' => $r->name );
			}
			// Fallback: para grados asignados al docente sin materia en teacher_assignments, usar el pensum
			foreach ( $grade_ids as $gid ) {
				if ( ! isset( $grade_subjects_map[ $gid ] ) ) {
					$srows = $wpdb->get_results( $wpdb->prepare(
						"SELECT s.id, s.name FROM {$p}grade_subjects gs
						 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
						 WHERE gs.grade_id = %d ORDER BY s.name",
						$gid
					) );
					foreach ( $srows as $sr ) {
						$grade_subjects_map[ $gid ][] = array( 'id' => (int) $sr->id, 'name' => $sr->name );
					}
				}
			}
		} elseif ( $mis_grados ) {
			foreach ( $mis_grados as $g ) {
				$srows = $wpdb->get_results( $wpdb->prepare(
					"SELECT s.id, s.name FROM {$p}grade_subjects gs
					 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
					 WHERE gs.grade_id = %d ORDER BY s.name",
					(int) $g->id
				) );
				foreach ( $srows as $sr ) {
					$grade_subjects_map[ (int) $g->id ][] = array( 'id' => (int) $sr->id, 'name' => $sr->name );
				}
			}
		}

		$all_components_map = array();
		$all_subj_ids       = array();
		foreach ( $grade_subjects_map as $gsubs ) {
			foreach ( $gsubs as $s ) {
				$all_subj_ids[] = $s['id'];
			}
		}
		$all_subj_ids = array_unique( $all_subj_ids );
		if ( $all_subj_ids ) {
			$sph      = implode( ',', array_fill( 0, count( $all_subj_ids ), '%d' ) );
			$comp_all = $wpdb->get_results( $wpdb->prepare(
				"SELECT subject_id, trimester_id, parcial_num, id, name
				 FROM {$p}grade_components WHERE subject_id IN ($sph)",
				...$all_subj_ids
			) );
			foreach ( $comp_all as $cr ) {
				$key = $cr->subject_id . '_' . $cr->trimester_id . '_' . $cr->parcial_num;
				$all_components_map[ $key ][] = array( 'id' => (int) $cr->id, 'name' => $cr->name );
			}
		}

		// Materias del filtro de tareas: solo las asignadas al docente en el grado seleccionado.
		$task_filter_subjects = array();
		if ( $task_grade_filter && isset( $grade_subjects_map[ $task_grade_filter ] ) ) {
			$task_filter_subjects = $grade_subjects_map[ $task_grade_filter ];
			$valid_task_subj_ids  = array_map( 'intval', array_column( $task_filter_subjects, 'id' ) );
			if ( $task_subj_filter && ! in_array( $task_subj_filter, $valid_task_subj_ids, true ) ) {
				$task_subj_filter = 0;
			}
		} elseif ( $task_subj_filter ) {
			$task_subj_filter = 0; // sin grado seleccionado no hay materia válida
		}

		// Nueva tarea: grado pre-seleccionado vía GET para cascada server-side.
		$nt_grade_sel = isset( $_GET['edu_nt_grade'] ) ? (int) $_GET['edu_nt_grade'] : 0;
		if ( $nt_grade_sel && ! in_array( $nt_grade_sel, $grade_ids, true ) ) {
			$nt_grade_sel = 0;
		}
		$nt_subjects = array();
		if ( $nt_grade_sel ) {
			if ( $teacher_id ) {
				// Query directa igual al admin: teacher_id + grade_id
				$nt_subj_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT DISTINCT s.id, s.name
					 FROM {$p}teacher_assignments ta
					 INNER JOIN {$p}subjects s ON s.id = ta.subject_id
					 WHERE ta.teacher_id = %d AND ta.grade_id = %d AND ta.is_active = 1
					 ORDER BY s.name",
					$teacher_id, $nt_grade_sel
				) );
				foreach ( $nt_subj_rows as $r ) {
					$nt_subjects[] = array( 'id' => (int) $r->id, 'name' => $r->name );
				}
			}
			if ( empty( $nt_subjects ) ) {
				// Fallback: materias del pensum del grado
				$nt_subj_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT s.id, s.name FROM {$p}grade_subjects gs
					 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
					 WHERE gs.grade_id = %d ORDER BY s.name",
					$nt_grade_sel
				) );
				foreach ( $nt_subj_rows as $r ) {
					$nt_subjects[] = array( 'id' => (int) $r->id, 'name' => $r->name );
				}
			}
		}

		// ── Tarea a editar ────────────────────────────────────────────────────
		$edit_task_id       = isset( $_GET['edu_edit_task'] ) ? (int) $_GET['edu_edit_task'] : 0;
		$edit_task          = null;
		$edit_task_files    = array();
		$edit_task_subjects = array();

		if ( $edit_task_id ) {
			$edit_task = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$p}assignments WHERE id = %d",
				$edit_task_id
			) );
			// Docente solo puede editar sus propias tareas.
			if ( $edit_task && $teacher_id && (int) $edit_task->teacher_id !== $teacher_id
				&& ! Edu_Context::can( 'edu_view_all' ) ) {
				$edit_task = null;
			}
			if ( $edit_task ) {
				$edit_task_files = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$p}assignment_files WHERE assignment_id = %d",
					$edit_task_id
				) );
				if ( $teacher_id ) {
					$edit_task_subjects = $wpdb->get_results( $wpdb->prepare(
						"SELECT DISTINCT s.id, s.name
						 FROM {$p}teacher_assignments ta
						 INNER JOIN {$p}subjects s ON s.id = ta.subject_id
						 WHERE ta.teacher_id = %d AND ta.grade_id = %d AND ta.is_active = 1
						 ORDER BY s.name",
						$teacher_id, (int) $edit_task->grade_id
					) );
				} else {
					$edit_task_subjects = $wpdb->get_results( $wpdb->prepare(
						"SELECT s.id, s.name FROM {$p}grade_subjects gs
						 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
						 WHERE gs.grade_id = %d ORDER BY s.name",
						(int) $edit_task->grade_id
					) );
				}
			}
		}

		// ── Datos: Tab Asistencia ─────────────────────────────────────────────
		$att_grade_sel   = isset( $_GET['edu_att_grade'] ) ? (int) $_GET['edu_att_grade'] : ( $grade_ids ? (int) $grade_ids[0] : 0 );
		$att_date_sel    = isset( $_GET['edu_att_date'] )  ? sanitize_text_field( wp_unslash( $_GET['edu_att_date'] ) ) : $today;
		$att_subject_sel = isset( $_GET['edu_att_subj'] )  ? (int) $_GET['edu_att_subj'] : 0;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $att_date_sel ) ) {
			$att_date_sel = $today;
		}

		$att_subjects  = array();
		$att_students  = array();
		$att_current   = array();
		$att_month_idx = array();

		if ( $att_grade_sel ) {
			// Materias: solo las asignadas al docente para ese grado.
			if ( $teacher_id ) {
				$att_subjects = $wpdb->get_results( $wpdb->prepare(
					"SELECT DISTINCT s.id, s.name
					 FROM {$p}teacher_assignments ta
					 INNER JOIN {$p}subjects s ON s.id = ta.subject_id
					 WHERE ta.teacher_id = %d AND ta.grade_id = %d AND ta.is_active = 1
					 ORDER BY s.name",
					$teacher_id, $att_grade_sel
				) );
			} else {
				$att_subjects = $wpdb->get_results( $wpdb->prepare(
					"SELECT s.id, s.name FROM {$p}grade_subjects gs
					 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
					 WHERE gs.grade_id = %d ORDER BY s.name",
					$att_grade_sel
				) );
			}

			$att_students = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id,
				        COALESCE(um_fn.meta_value, '') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name
				 FROM {$p}students s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE s.grade_id = %d AND s.status = 'active'
				 ORDER BY last_name, first_name",
				$att_grade_sel
			) );

			if ( $att_subject_sel > 0 ) {
				$att_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, status, justification FROM {$p}attendance
					 WHERE date = %s AND subject_id = %d
					   AND student_id IN (SELECT id FROM {$p}students WHERE grade_id = %d AND status = 'active')",
					$att_date_sel, $att_subject_sel, $att_grade_sel
				) );
			} else {
				$att_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, status, justification FROM {$p}attendance
					 WHERE date = %s AND subject_id IS NULL
					   AND student_id IN (SELECT id FROM {$p}students WHERE grade_id = %d AND status = 'active')",
					$att_date_sel, $att_grade_sel
				) );
			}
			foreach ( $att_rows as $ar ) {
				$att_current[ (int) $ar->student_id ] = $ar;
			}

			if ( $att_subject_sel > 0 ) {
				$sum_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, status, COUNT(*) AS total FROM {$p}attendance
					 WHERE student_id IN (SELECT id FROM {$p}students WHERE grade_id = %d AND status = 'active')
					   AND date BETWEEN %s AND %s AND subject_id = %d
					 GROUP BY student_id, status",
					$att_grade_sel, $month_start, $month_end, $att_subject_sel
				) );
			} else {
				$sum_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, status, COUNT(*) AS total FROM {$p}attendance
					 WHERE student_id IN (SELECT id FROM {$p}students WHERE grade_id = %d AND status = 'active')
					   AND date BETWEEN %s AND %s AND subject_id IS NULL
					 GROUP BY student_id, status",
					$att_grade_sel, $month_start, $month_end
				) );
			}
			foreach ( $sum_rows as $sr ) {
				$att_month_idx[ (int) $sr->student_id ][ $sr->status ] = (int) $sr->total;
			}
		}

		// ── Datos: Tab Comunicados ────────────────────────────────────────────
		$comunicados_env = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.id, a.title, a.sent_at, a.scope,
			        (SELECT COUNT(*) FROM {$p}announcement_recipients r WHERE r.announcement_id = a.id) AS total,
			        (SELECT COUNT(*) FROM {$p}announcement_recipients r WHERE r.announcement_id = a.id AND r.read_at IS NOT NULL) AS leidos
			 FROM {$p}announcements a
			 WHERE a.sender_user_id = %d
			 ORDER BY a.sent_at DESC LIMIT 30",
			$uid
		) );

		// Estudiantes por grado para cascade JS del formulario de comunicados
		$com_students_map = array();
		foreach ( $mis_grados as $g ) {
			$sts = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id,
				        COALESCE(um_fn.meta_value, '') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name
				 FROM {$p}students s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE s.grade_id = %d AND s.status = 'active'
				 ORDER BY last_name, first_name",
				(int) $g->id
			) );
			$com_students_map[ (int) $g->id ] = array_map( function ( $s ) {
				return array( 'id' => (int) $s->id, 'name' => trim( $s->last_name . ', ' . $s->first_name ) );
			}, $sts );
		}

		// ── Status del query string ───────────────────────────────────────────
		$edu_status = isset( $_GET['edu_status'] ) ? sanitize_key( $_GET['edu_status'] ) : '';
		$edu_code   = isset( $_GET['edu_code'] )   ? sanitize_key( $_GET['edu_code'] )   : '';

		// ── Labels de tipo de tarea ───────────────────────────────────────────
		$type_labels = array(
			'tarea'      => 'Tarea',      'leccion'    => 'Lección',
			'trabajo'    => 'Trabajo',    'deber'      => 'Deber',
			'examen'     => 'Examen',     'correccion' => 'Corrección',
		);
		$type_colors = array(
			'tarea' => '#dbeafe', 'leccion' => '#ede9fe', 'trabajo' => '#fef9c3',
			'deber' => '#dcfce7', 'examen'  => '#fee2e2', 'correccion' => '#f3f4f6',
		);
		$type_text = array(
			'tarea' => '#1e40af', 'leccion' => '#6d28d9', 'trabajo' => '#854d0e',
			'deber' => '#15803d', 'examen'  => '#b91c1c', 'correccion' => '#374151',
		);

		$user     = wp_get_current_user();
		$initials = strtoupper( mb_substr( $user->display_name, 0, 2 ) );

		// Saludo y nombre corto
		$hour       = (int) date( 'G' );
		$saludo     = $hour < 12 ? 'Buenos días' : ( $hour < 19 ? 'Buenas tardes' : 'Buenas noches' );
		$first_name = trim( $user->first_name ?: explode( ' ', $user->display_name )[0] );
		$rol_subtitulo = 'Docente';
		if ( $mis_grados ) {
			$g0 = reset( $mis_grados );
			$rol_subtitulo = $g0->name . ' ' . $g0->paralelo;
		}
		// URLs de portales configurados
		$page_urls = array(
			'docente'    => ( $pid = (int) get_option( 'edu_page_docente',     0 ) ) ? get_permalink( $pid ) : '',
			'padre'      => ( $pid = (int) get_option( 'edu_page_padre',       0 ) ) ? get_permalink( $pid ) : '',
			'estudiante' => ( $pid = (int) get_option( 'edu_page_estudiante',  0 ) ) ? get_permalink( $pid ) : '',
			'rector'     => ( $pid = (int) get_option( 'edu_page_rector',      0 ) ) ? get_permalink( $pid ) : '',
		);

		// Todas las funciones JS del portal se inyectan en wp_footer
		static $footer_script_added = false;
		if ( ! $footer_script_added ) {
			$footer_script_added  = true;
			$_ajax_url            = esc_js( esc_url( admin_url( 'admin-ajax.php' ) ) );
			$_nonce               = esc_js( wp_create_nonce( 'edu_docente_nonce' ) );
			$_components_json     = wp_json_encode( $all_components_map ) ?: '{}';
			$_grade_subjects_json = wp_json_encode( $grade_subjects_map ) ?: '{}';
			$_com_students_json   = wp_json_encode( $com_students_map )   ?: '{}';
			$_edit_comp_id        = $edit_task ? (int) $edit_task->component_id : 0;
			add_action( 'wp_footer', function() use (
				$_ajax_url, $_nonce,
				$_components_json, $_grade_subjects_json, $_com_students_json,
				$_edit_comp_id
			) {
				$j = '<script>' .
					'var _gsm=' . $_grade_subjects_json . ';' .
					'var _cmp=' . $_components_json . ';' .
					'var _sts=' . $_com_students_json . ';' .

					// Etiquetas de las dos opciones fijas del selector de componente.
					'var _lblNuevo=' . wp_json_encode( '➕ ' . __( 'Crear componente nuevo…', 'sistema-educativo' ) ) . ';' .
					'var _lblSin=' . wp_json_encode( __( 'Sin vincular (no cuenta para la nota)', 'sistema-educativo' ) ) . ';' .

					// Rellena un selector de componente: existentes + las dos opciones fijas.
					'window.eduFillComponentes=function(cp,items,pre){' .
						'cp.innerHTML="";' .
						'(items||[]).forEach(function(c){var o=document.createElement("option");o.value=c.id;o.textContent=c.name;cp.appendChild(o);});' .
						'var n=document.createElement("option");n.value="-1";n.textContent=_lblNuevo;cp.appendChild(n);' .
						'var s=document.createElement("option");s.value="0";s.textContent=_lblSin;cp.appendChild(s);' .
						'cp.value=pre?String(pre):"-1";' .
					'};' .

					// Muestra u oculta el campo "nombre del componente" según la opción elegida.
					'window.eduToggleNuevoComponente=function(pfx,foco){' .
						'var cp=document.getElementById(pfx+"_component"),wr=document.getElementById(pfx+"_component_nuevo");' .
						'if(!cp||!wr)return;' .
						'var crear=(cp.value==="-1");' .
						'wr.style.display=crear?"":"none";' .
						'var inp=wr.querySelector("input[name=\'component_new_name\']");' .
						'if(inp){if(!crear){inp.value="";}else if(foco){inp.focus();}}' .
					'};' .

					// eduToggle
					'window.eduToggle=function(sId,bId,lShow,lHide){var el=document.getElementById(sId),btn=document.getElementById(bId);if(!el)return;var v=el.style.display!="none";el.style.display=v?"none":"block";if(btn)btn.textContent=v?lShow:lHide;};' .

					// eduCalifGradeChange
					'window.eduCalifGradeChange=function(sel){var f=sel.form,s=f?f.querySelector("[name=\'edu_subject\']"):null;if(s)s.value="0";if(f)f.submit();};' .

					// eduTaskGradeChange
					'window.eduTaskGradeChange=function(sel){var f=sel.form,s=f?f.querySelector("[name=\'edu_task_subj\']"):null;if(s)s.value="0";if(f)f.submit();};' .

					// eduNtGradeChange: AJAX grado → materias
					'window.eduNtGradeChange=function(g){' .
						'var s=document.getElementById("nt_subject"),c=document.getElementById("nt_component");' .
						'if(!s)return;' .
						'if(c)eduFillComponentes(c,[],0);' .
						'if(!g){s.innerHTML=\'<option value="">-- Seleccionar grado primero --</option>\';return;}' .
						'var cached=_gsm[g]||[];' .
						'if(cached.length){fill(s,cached);return;}' .
						's.innerHTML=\'<option value="">-- Cargando... --</option>\';' .
						'var fd=new FormData();fd.append("action","edu_get_subjects_for_grade");fd.append("nonce","' . $_nonce . '");fd.append("grade_id",g);' .
						'fetch("' . $_ajax_url . '",{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){fill(s,d.success&&d.data?d.data:[]);}).catch(function(){s.innerHTML=\'<option value="">-- Error --</option>\';});' .
						'function fill(sel,items){sel.innerHTML="";if(items.length){items.forEach(function(x){var o=document.createElement("option");o.value=x.id;o.textContent=x.name;sel.appendChild(o);});_gsm[g]=items;if(typeof eduUpdateComponents==="function")eduUpdateComponents();}else{sel.innerHTML=\'<option value="">-- Sin materias --</option>\';}}' .
					'};' .

					// eduUpdateComponents: materia+trimestre+parcial → componentes
					'window.eduUpdateComponents=function(){' .
						'var sb=document.getElementById("nt_subject"),tr=document.getElementById("nt_trim"),pr=document.getElementById("nt_parcial"),cp=document.getElementById("nt_component");' .
						'if(!sb||!tr||!pr||!cp)return;' .
						'eduFillComponentes(cp,_cmp[sb.value+"_"+tr.value+"_"+pr.value],0);' .
						'eduToggleNuevoComponente("nt");' .
					'};' .

					// eduEditGradeChange
					'window.eduEditGradeChange=function(g){' .
						'var s=document.getElementById("edit_subject"),c=document.getElementById("edit_component");' .
						'if(!s)return;' .
						's.innerHTML=\'<option value="">-- Seleccionar --</option>\';' .
						'(_gsm[g]||[]).forEach(function(x){var o=document.createElement("option");o.value=x.id;o.textContent=x.name;s.appendChild(o);});' .
						'if(c){eduFillComponentes(c,[],0);eduToggleNuevoComponente("edit");}' .
					'};' .

					// eduEditUpdateComponents
					'window.eduEditUpdateComponents=function(pre){' .
						'var sb=document.getElementById("edit_subject"),tr=document.getElementById("edit_trim"),pr=document.getElementById("edit_parcial"),cp=document.getElementById("edit_component");' .
						'if(!sb||!tr||!pr||!cp)return;' .
						'eduFillComponentes(cp,_cmp[sb.value+"_"+tr.value+"_"+pr.value],pre);' .
						'eduToggleNuevoComponente("edit");' .
					'};' .

					// eduComScopeChange
					'window.eduComScopeChange=function(scope){' .
						'var gw=document.getElementById("com_grade_wrap"),sw=document.getElementById("com_student_wrap"),xw=document.getElementById("com_subject_wrap");' .
						'if(!gw||!sw)return;' .
						'if(scope==="institution"){gw.style.display="none";sw.style.display="none";if(xw)xw.style.display="none";}' .
						'else if(scope==="student"){gw.style.display="block";sw.style.display="block";}' .
						'else{gw.style.display="block";sw.style.display="none";}' .
					'};' .

					// eduComGradeChange
					'window.eduComGradeChange=function(g){' .
						'var st=document.getElementById("com_student");' .
						'if(st){st.innerHTML=\'<option value="">-- Seleccionar --</option>\';(_sts[g]||[]).forEach(function(s){var o=document.createElement("option");o.value=s.id;o.textContent=s.name;st.appendChild(o);});}' .
						'var ss=document.getElementById("com_subject_ref"),sw=document.getElementById("com_subject_wrap");' .
						'if(ss){ss.innerHTML=\'<option value="">-- Sin especificar --</option>\';var subs=_gsm[g]||[];subs.forEach(function(s){var o=document.createElement("option");o.value=s.id;o.textContent=s.name;ss.appendChild(o);});if(sw)sw.style.display=subs.length?"block":"none";}' .
					'};' .

					// eduComSubjectChange
					'window.eduComSubjectChange=function(sel){var t=sel.form?sel.form.querySelector("[name=\'title\']"):null;if(!t)return;var n=sel.options[sel.selectedIndex]?sel.options[sel.selectedIndex].textContent:"";t.value=(sel.value?"["+n+"] ":"")+t.value.replace(/^\[.+?\]\s*/,"");t.focus();};' .

					// Init edit form component
					'(function(){var e=document.getElementById("edit_subject");if(e&&e.value)eduEditUpdateComponents(' . (int) $_edit_comp_id . '||"");})();' .

					// Init new task form component if pre-selected
					'(function(){var n=document.getElementById("nt_subject");if(n&&n.value)eduUpdateComponents();})();' .

				'</script>';
				echo $j;
			}, 20 );
		}

		ob_start();
		?>
		<div class="edu-portal-wrap">

		<!-- ── Top bar ── -->
		<div class="edu-topbar">
			<div class="edu-topbar-brand">
				<div class="edu-topbar-logo">SE</div>
				<div class="edu-topbar-info">
					<div class="edu-topbar-name">Sistema Educativo Integral</div>
					<div class="edu-topbar-sub"><?php echo esc_html( $inst_name ); ?><?php echo $period_name ? ' · ' . esc_html( $period_name ) : ''; ?></div>
				</div>
			</div>
			<div class="edu-topbar-roles">
				<?php
				$roles_display = array(
					'docente'    => array( 'icon' => '&#128202;', 'label' => __( 'Docente',    'sistema-educativo' ) ),
					'padre'      => array( 'icon' => '&#128106;', 'label' => __( 'Padre',      'sistema-educativo' ) ),
					'estudiante' => array( 'icon' => '&#127891;', 'label' => __( 'Estudiante', 'sistema-educativo' ) ),
					'rector'     => array( 'icon' => '&#127894;', 'label' => __( 'Rector',     'sistema-educativo' ) ),
				);
				foreach ( $roles_display as $rkey => $rdata ) :
					$rurl = $page_urls[ $rkey ] ?? '';
					if ( ! $rurl ) continue;
				?>
				<a href="<?php echo esc_url( $rurl ); ?>" class="edu-role-btn<?php echo 'docente' === $rkey ? ' active' : ''; ?>">
					<?php echo $rdata['icon']; ?> <?php echo esc_html( $rdata['label'] ); ?>
				</a>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- ── Layout ── -->
		<div class="edu-layout">

		<!-- ── Sidebar ── -->
		<aside class="edu-sidebar">
			<div class="edu-sidebar-card">
				<div class="edu-avatar" style="background:#059669;"><?php echo esc_html( $initials ); ?></div>
				<div class="edu-user-name"><?php echo esc_html( $user->display_name ); ?></div>
				<div class="edu-user-role"><?php echo esc_html( $rol_subtitulo ); ?></div>
				<div class="edu-sidenav-sep"></div>
				<div class="edu-sidenav-section"><?php esc_html_e( 'DOCENTE', 'sistema-educativo' ); ?></div>
				<nav class="edu-sidenav">
					<?php
					$sidenav_items = array(
						'inicio'         => array( 'icon' => '&#127968;', 'label' => __( 'Inicio',           'sistema-educativo' ) ),
						'materias'       => array( 'icon' => '&#128218;', 'label' => __( 'Mis materias',     'sistema-educativo' ) ),
						'calificaciones' => array( 'icon' => '&#128221;', 'label' => __( 'Calificaciones',   'sistema-educativo' ) ),
						'tareas'         => array( 'icon' => '&#128203;', 'label' => __( 'Tareas y lecciones','sistema-educativo' ) ),
						'asistencia'     => array( 'icon' => '&#9989;',   'label' => __( 'Asistencia',       'sistema-educativo' ) ),
						'comunicados'    => array( 'icon' => '&#128226;', 'label' => __( 'Comunicados',      'sistema-educativo' ) ),
						'textos'         => array( 'icon' => '&#128216;', 'label' => __( 'Mis textos',       'sistema-educativo' ) ),
					);
					$sidenav_items = Edu_Modules::filter_sidenav( $sidenav_items );
					foreach ( $sidenav_items as $skey => $sdata ) :
					?>
					<a href="<?php echo esc_url( add_query_arg( 'edu_tab', $skey, $current_url ) ); ?>"
					   class="<?php echo $tab === $skey ? 'active' : ''; ?>">
						<span class="edu-nav-icon"><?php echo $sdata['icon']; ?></span>
						<?php echo esc_html( $sdata['label'] ); ?>
						<?php if ( 'tareas' === $skey && $entregas_pendientes > 0 ) : ?>
							<span class="edu-badge"><?php echo (int) $entregas_pendientes; ?></span>
						<?php endif; ?>
					</a>
					<?php endforeach; ?>
				</nav>
			</div>
		</aside>

		<!-- ── Contenido principal ── -->
		<main class="edu-content">

		<!-- Encabezado del contenido -->
		<?php
		$content_titles = array(
			'inicio'         => $saludo . ', ' . $first_name . ' 👋',
			'materias'       => __( 'Mis materias', 'sistema-educativo' ),
			'calificaciones' => __( 'Calificaciones', 'sistema-educativo' ),
			'tareas'         => __( 'Tareas y lecciones', 'sistema-educativo' ),
			'asistencia'     => __( 'Asistencia', 'sistema-educativo' ),
			'comunicados'    => __( 'Comunicados', 'sistema-educativo' ),
			'textos'         => __( 'Mis textos', 'sistema-educativo' ),
		);
		?>
		<div class="edu-content-header">
			<h1><?php echo esc_html( $content_titles[ $tab ] ?? '' ); ?></h1>
			<?php if ( 'inicio' === $tab && $period_name ) : ?>
			<p><?php echo esc_html( $period_name ); ?></p>
			<?php endif; ?>
		</div>

		<!-- ── Mensajes de estado globales ── -->
		<?php if ( 'updated' === $edu_status || 'saved' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Guardado correctamente.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( 'sent' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Comunicado enviado correctamente.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( 'graded' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Calificación guardada correctamente.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( 'published' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Tarea publicada correctamente.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( 'closed' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Tarea cerrada. Los estudiantes ya no pueden entregar.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( 'recovery_saved' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Configuración de mejora guardada correctamente.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( 'deleted' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#f3f4f6; color:#374151; border:1px solid #d1d5db; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Tarea eliminada.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( 'exam_saved' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Evaluación sumativa guardada correctamente.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( 'error' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php echo esc_html( $edu_code ? $edu_code : __( 'Error al guardar. Revisa los datos.', 'sistema-educativo' ) ); ?>
			</div>
		<?php endif; ?>
		<?php
		$_edu_db_err = get_transient( 'edu_exam_db_error' );
		if ( $_edu_db_err ) :
			delete_transient( 'edu_exam_db_error' );
		?>
			<div class="edu-alert" style="background:#fef3c7; color:#92400e; border:1px solid #fcd34d; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-family:monospace; font-size:12px;">
				<strong>Error DB:</strong> <?php echo esc_html( $_edu_db_err ); ?>
			</div>
		<?php endif; ?>

		<?php /* ═══════════════════ INICIO ═══════════════════ */ ?>
		<?php if ( 'inicio' === $tab ) : ?>

		<div class="edu-stats-row">
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Por calificar', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#d97706;"><?php echo esc_html( $entregas_pendientes ); ?></div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Tareas activas', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#1d4ed8;"><?php echo esc_html( $tareas_activas ); ?></div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Asistencia hoy', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#16a34a;"><?php echo esc_html( $asistencia_hoy['p'] . '/' . $asistencia_hoy['t'] ); ?></div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Mis grados', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#7c3aed;"><?php echo count( $mis_grados ); ?></div>
			</div>
		</div>

		<div class="edu-grid-2">
			<div>
				<div class="edu-card">
					<h3><?php esc_html_e( 'Próximas entregas', 'sistema-educativo' ); ?></h3>
					<?php if ( empty( $proximas_entregas ) ) : ?>
						<p style="color:#9ca3af; font-size:13px;"><?php esc_html_e( 'No hay tareas publicadas próximas.', 'sistema-educativo' ); ?></p>
					<?php else : ?>
					<ul class="edu-list">
					<?php foreach ( $proximas_entregas as $pe ) :
						$diff       = $pe->due_date ? (int) ceil( ( strtotime( $pe->due_date ) - time() ) / 86400 ) : null;
						if ( null === $diff ) {
							$due_label = __( 'Sin fecha', 'sistema-educativo' );
							$due_color = '#6b7280';
						} elseif ( $diff < 0 ) {
							$due_label = sprintf( __( 'venció hace %d día(s)', 'sistema-educativo' ), abs( $diff ) );
							$due_color = '#dc2626';
						} elseif ( 0 === $diff ) {
							$due_label = __( 'vence hoy', 'sistema-educativo' );
							$due_color = '#dc2626';
						} elseif ( 1 === $diff ) {
							$due_label = __( 'vence mañana', 'sistema-educativo' );
							$due_color = '#f59e0b';
						} elseif ( $diff <= 3 ) {
							$due_label = sprintf( __( 'vence en %d días', 'sistema-educativo' ), $diff );
							$due_color = '#f59e0b';
						} else {
							$due_label = sprintf( __( 'en %d días', 'sistema-educativo' ), $diff );
							$due_color = '#6b7280';
						}
					?>
						<li style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
							<div>
								<div style="font-weight:500; font-size:13px;"><?php echo esc_html( $pe->title ); ?></div>
								<div class="edu-list-meta"><?php echo esc_html( $pe->subject_name . ' · ' . $pe->grade_name . ' ' . $pe->paralelo ); ?></div>
							</div>
							<span style="color:<?php echo esc_attr( $due_color ); ?>; font-size:12px; font-weight:600; white-space:nowrap;"><?php echo esc_html( $due_label ); ?></span>
						</li>
					<?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>

				<div class="edu-card">
					<h3><?php esc_html_e( 'Acciones rápidas', 'sistema-educativo' ); ?></h3>
					<ul class="edu-list">
						<li><a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'asistencia', $current_url ) ); ?>" style="color:#059669; font-weight:600;">&#9989; <?php esc_html_e( 'Tomar asistencia de hoy', 'sistema-educativo' ); ?></a></li>
						<li><a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_nueva' => '1' ), $current_url ) ); ?>" style="color:#1d4ed8; font-weight:600;">&#43; <?php esc_html_e( 'Crear nueva tarea', 'sistema-educativo' ); ?></a></li>
						<li><a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'calificaciones', $current_url ) ); ?>" style="color:#7c3aed; font-weight:600;">&#128221; <?php esc_html_e( 'Ingresar notas', 'sistema-educativo' ); ?></a></li>
						<li><a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'comunicados', 'edu_nuevo_com' => '1' ), $current_url ) ); ?>" style="color:#d97706; font-weight:600;">&#128226; <?php esc_html_e( 'Enviar comunicado', 'sistema-educativo' ); ?></a></li>
					</ul>
				</div>
			</div>

			<div>
				<div class="edu-card">
					<h3><?php esc_html_e( 'Alertas de estudiantes', 'sistema-educativo' ); ?></h3>
					<?php if ( empty( $alertas_inicio ) ) : ?>
						<p style="color:#9ca3af; font-size:13px;">&#10003; <?php esc_html_e( 'Sin alertas activas.', 'sistema-educativo' ); ?></p>
					<?php else : ?>
					<ul class="edu-list">
					<?php foreach ( $alertas_inicio as $al ) : ?>
						<li style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
							<div style="display:flex; align-items:center; gap:8px;">
								<span style="width:10px; height:10px; border-radius:50%; background:<?php echo esc_attr( $al['color'] ); ?>; flex-shrink:0; display:inline-block;"></span>
								<span style="font-weight:500; font-size:13px;"><?php echo esc_html( $al['name'] ); ?></span>
							</div>
							<span style="color:#6b7280; font-size:12px;"><?php echo esc_html( $al['detail'] ); ?></span>
						</li>
					<?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>

				<div class="edu-card">
					<h3><?php esc_html_e( 'Mis grados asignados', 'sistema-educativo' ); ?></h3>
					<?php if ( empty( $mis_grados ) ) : ?>
						<p style="color:#9ca3af; font-size:13px;"><?php esc_html_e( 'Sin grados asignados.', 'sistema-educativo' ); ?></p>
					<?php else : ?>
					<ul class="edu-list">
					<?php foreach ( $mis_grados as $g ) : ?>
						<li>
							<strong><?php echo esc_html( $g->name . ' · ' . $g->paralelo ); ?></strong>
							<div class="edu-list-meta"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $g->sub_level ) ) ); ?></div>
						</li>
					<?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>
			</div>

		</div>

		<?php /* ══════════════════════ MIS MATERIAS ══════════════════════ */ ?>
		<?php elseif ( 'materias' === $tab ) : ?>

		<?php if ( empty( $mis_materias ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No tienes materias asignadas en el período activo.', 'sistema-educativo' ); ?></div>
		<?php else : ?>
		<?php foreach ( $mis_materias as $gid => $gdata ) : ?>
		<div class="edu-card">
			<h3 style="margin-bottom:12px;">
				<?php echo esc_html( $gdata['grade_name'] . ' · ' . $gdata['paralelo'] ); ?>
				<span style="font-size:12px; font-weight:400; color:#94a3b8; margin-left:8px;"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $gdata['sub_level'] ) ) ); ?></span>
			</h3>
			<div class="edu-table-wrap">
				<table class="edu-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></th>
							<th class="center"><?php esc_html_e( 'Tareas activas', 'sistema-educativo' ); ?></th>
							<th class="center"><?php esc_html_e( 'Por revisar', 'sistema-educativo' ); ?></th>
							<th class="center"><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $gdata['materias'] as $mat ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $mat['subject_name'] ); ?></strong></td>
							<td class="center">
								<?php if ( $mat['n_tareas'] > 0 ) : ?>
									<span class="edu-badge-status edu-badge-blue"><?php echo (int) $mat['n_tareas']; ?></span>
								<?php else : ?>
									<span style="color:#94a3b8;">—</span>
								<?php endif; ?>
							</td>
							<td class="center">
								<?php if ( $mat['n_entregas'] > 0 ) : ?>
									<span class="edu-badge-status edu-badge-yellow"><?php echo (int) $mat['n_entregas']; ?></span>
								<?php else : ?>
									<span style="color:#94a3b8;">—</span>
								<?php endif; ?>
							</td>
							<td class="center">
								<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'calificaciones', 'edu_grade' => $gid, 'edu_subject' => $mat['subject_id'] ), $current_url ) ); ?>"
								   class="edu-btn edu-btn-sm edu-btn-outline">
									<?php esc_html_e( 'Notas', 'sistema-educativo' ); ?>
								</a>
								<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_grade' => $gid, 'edu_subject' => $mat['subject_id'] ), $current_url ) ); ?>"
								   class="edu-btn edu-btn-sm edu-btn-outline" style="margin-left:4px;">
									<?php esc_html_e( 'Tareas', 'sistema-educativo' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endforeach; ?>
		<?php endif; ?>

		<?php /* ═══════════════════ CALIFICACIONES ═══════════════════ */ ?>
		<?php elseif ( 'calificaciones' === $tab ) : ?>

		<!-- Filtros -->
		<div class="edu-card" style="padding:14px; margin-bottom:14px;">
			<form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
				<input type="hidden" name="edu_tab" value="calificaciones">
				<?php foreach ( $_GET as $qk => $qv ) :
					if ( in_array( $qk, array( 'edu_tab', 'edu_grade', 'edu_subject', 'edu_trim', 'edu_parcial', 'edu_status', 'edu_code' ), true ) ) continue; ?>
					<input type="hidden" name="<?php echo esc_attr( $qk ); ?>" value="<?php echo esc_attr( $qv ); ?>">
				<?php endforeach; ?>
				<div class="edu-form-row" style="margin:0; flex:1; min-width:130px;">
					<label><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></label>
					<select name="edu_grade" id="edu_calif_grade" onchange="eduCalifGradeChange(this)">
						<?php foreach ( $mis_grados as $g ) : ?>
							<option value="<?php echo (int) $g->id; ?>" <?php selected( $grade_sel, (int) $g->id ); ?>><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="edu-form-row" style="margin:0; flex:1; min-width:150px;">
					<label><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></label>
					<select name="edu_subject" onchange="this.form.submit()">
						<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
						<?php foreach ( $materias_grado as $m ) : ?>
							<option value="<?php echo (int) $m->id; ?>" <?php selected( $subject_sel, (int) $m->id ); ?>><?php echo esc_html( $m->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="edu-form-row" style="margin:0; flex:1; min-width:150px;">
					<label><?php esc_html_e( 'Trimestre', 'sistema-educativo' ); ?></label>
					<select name="edu_trim" onchange="this.form.submit()">
						<?php foreach ( $trimesters as $t ) : ?>
							<option value="<?php echo (int) $t->id; ?>" <?php selected( $trim_sel, (int) $t->id ); ?>><?php echo esc_html( 'T' . $t->trim_num . ' — ' . $t->period_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="edu-form-row" style="margin:0; min-width:110px;">
					<label><?php esc_html_e( 'Parcial', 'sistema-educativo' ); ?></label>
					<select name="edu_parcial" onchange="this.form.submit()">
						<option value="1" <?php selected( $parcial_sel, 1 ); ?>><?php esc_html_e( 'Parcial 1', 'sistema-educativo' ); ?></option>
						<option value="2" <?php selected( $parcial_sel, 2 ); ?>><?php esc_html_e( 'Parcial 2', 'sistema-educativo' ); ?></option>
					</select>
				</div>
			</form>
		</div>

		<?php if ( empty( $componentes ) ) : ?>
			<div class="edu-alert edu-alert-yellow">
				<?php esc_html_e( 'No hay componentes evaluables para esta combinación. Defínelos en el panel admin.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( empty( $estudiantes_notas ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No hay estudiantes en este grado.', 'sistema-educativo' ); ?></div>
		<?php else : ?>

		<!-- Tabla de notas editable -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'edu_save_scores' ); ?>
			<input type="hidden" name="action"       value="edu_save_scores">
			<input type="hidden" name="_redirect"    value="<?php echo esc_url( $current_url ); ?>">
			<input type="hidden" name="grade_id"     value="<?php echo (int) $grade_sel; ?>">
			<input type="hidden" name="subject_id"   value="<?php echo (int) $subject_sel; ?>">
			<input type="hidden" name="trimester_id" value="<?php echo (int) $trim_sel; ?>">
			<input type="hidden" name="parcial_num"  value="<?php echo (int) $parcial_sel; ?>">

			<div class="edu-card" style="padding:0;">
				<div class="edu-table-wrap">
				<table class="edu-table">
					<thead>
						<tr>
							<th style="min-width:160px;"><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
							<?php foreach ( $componentes as $c ) : ?>
								<th class="center" style="min-width:110px;">
									<?php echo esc_html( $c->name ); ?>
									<div style="font-weight:400; font-size:10px; color:#9ca3af;">(<?php echo number_format( (float) $c->weight * 100, 0 ); ?>%)</div>
								</th>
							<?php endforeach; ?>
							<th class="center bg-blue" style="min-width:80px;"><?php esc_html_e( 'Parcial', 'sistema-educativo' ); ?></th>
							<th class="center" style="min-width:80px;"><?php esc_html_e( 'Cuali.', 'sistema-educativo' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $estudiantes_notas as $est ) :
						$parcial_score = null;
						$peso_total    = 0;
						$suma_pond     = 0;
						foreach ( $componentes as $c ) {
							$nota = $est->notas[ (int) $c->id ] ?? null;
							if ( null !== $nota ) {
								$suma_pond  += $nota * (float) $c->weight;
								$peso_total += (float) $c->weight;
							}
						}
						if ( $peso_total > 0 ) {
							$parcial_score = round( $suma_pond / $peso_total, 2 );
						}
						$color_p = null === $parcial_score ? '#9ca3af' : ( $parcial_score >= 7 ? '#1d4ed8' : '#dc2626' );
					?>
						<tr>
							<td><?php echo esc_html( $est->last_name . ', ' . $est->first_name ); ?></td>
							<?php foreach ( $componentes as $c ) :
								$val = $est->notas[ (int) $c->id ] ?? '';
							?>
								<td class="center" style="padding:4px;">
									<input type="number"
									       name="scores[<?php echo (int) $est->id; ?>][<?php echo (int) $c->id; ?>]"
									       value="<?php echo '' !== $val ? esc_attr( number_format( (float) $val, 2, '.', '' ) ) : ''; ?>"
									       min="0" max="10" step="0.01"
									       style="width:72px; text-align:center; padding:4px 6px; border:1px solid #d1d5db; border-radius:4px; font-size:13px;">
								</td>
							<?php endforeach; ?>
							<td class="center bg-blue" style="color:<?php echo esc_attr( $color_p ); ?>; font-weight:600;">
								<?php echo null !== $parcial_score ? esc_html( number_format( $parcial_score, 2 ) ) : '—'; ?>
							</td>
							<td class="center">
								<?php if ( null !== $parcial_score ) : ?>
									<?php echo Edu_Qualitativa_Helper::badge( $parcial_score, $grade_sub_level ); // phpcs:ignore ?>
								<?php else : ?>
									<span style="color:#9ca3af;">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</div>

			<div style="display:flex; justify-content:flex-end; margin-top:12px;">
				<button type="submit" class="edu-btn edu-btn-primary">
					&#128190; <?php esc_html_e( 'Guardar notas', 'sistema-educativo' ); ?>
				</button>
			</div>
		</form>
		<?php endif; ?>

		<?php /* ── Evaluación Sumativa: Examen + Proyecto ── */ ?>
		<?php if ( $sumativa_estudiantes ) : ?>
		<div class="edu-card" style="margin-top:20px;">
			<h3 style="margin-top:0; margin-bottom:4px;">
				<?php esc_html_e( 'Evaluación Sumativa (30%)', 'sistema-educativo' ); ?>
			</h3>
			<p style="margin:0 0 12px; font-size:12px; color:#6b7280;">
				<?php if ( $usa_sumativa_fe ) : ?>
					<?php esc_html_e( 'Fórmula: Promedio parciales × 0.70 + ((Examen + Proyecto) / 2) × 0.30', 'sistema-educativo' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Fórmula: Promedio parciales × 0.70 + Examen × 0.30', 'sistema-educativo' ); ?>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'edu_save_exam' ); ?>
				<input type="hidden" name="action"       value="edu_save_exam">
				<input type="hidden" name="_redirect"    value="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'calificaciones', 'edu_grade' => $grade_sel, 'edu_subject' => $subject_sel, 'edu_trim' => $trim_sel, 'edu_status' => 'exam_saved' ), $current_url ) ); ?>">
				<input type="hidden" name="grade_id"     value="<?php echo (int) $grade_sel; ?>">
				<input type="hidden" name="subject_id"   value="<?php echo (int) $subject_sel; ?>">
				<input type="hidden" name="trimester_id" value="<?php echo (int) $trim_sel; ?>">

				<div class="edu-table-wrap">
				<table class="edu-table">
					<thead>
						<tr>
							<th style="min-width:160px;"><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
							<th class="center" style="min-width:80px;"><?php esc_html_e( 'P1', 'sistema-educativo' ); ?></th>
							<th class="center" style="min-width:80px;"><?php esc_html_e( 'P2', 'sistema-educativo' ); ?></th>
							<th class="center" style="min-width:90px;"><?php esc_html_e( 'Prom. (70%)', 'sistema-educativo' ); ?></th>
							<th class="center" style="min-width:110px;">
								<?php echo $usa_sumativa_fe ? esc_html__( 'Examen (15%)', 'sistema-educativo' ) : esc_html__( 'Examen (30%)', 'sistema-educativo' ); ?>
							</th>
							<?php if ( $usa_sumativa_fe ) : ?>
							<th class="center" style="min-width:120px;"><?php esc_html_e( 'Proyecto (15%)', 'sistema-educativo' ); ?></th>
							<?php endif; ?>
							<th class="center bg-blue" style="min-width:90px;"><?php esc_html_e( 'Nota T', 'sistema-educativo' ); ?></th>
							<th class="center" style="min-width:80px;"><?php esc_html_e( 'Cuali.', 'sistema-educativo' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $sumativa_estudiantes as $est ) :
						$esid      = (int) $est->id;
						$t_row     = $sumativa_trim_rows[ $esid ] ?? null;
						$is_closed = $t_row && (int) $t_row->is_closed === 1;

						$p1_v = $sumativa_parc1_rows[ $esid ] ?? null;
						$p2_v = $sumativa_parc2_rows[ $esid ] ?? null;

						$p1_disp = null !== $p1_v ? number_format( $p1_v, 2 ) : '—';
						$p2_disp = null !== $p2_v ? number_format( $p2_v, 2 ) : '—';

						if ( null !== $p1_v && null !== $p2_v ) {
							$p_avg = ( $p1_v + $p2_v ) / 2;
						} elseif ( null !== $p1_v ) {
							$p_avg = $p1_v;
						} else {
							$p_avg = null;
						}
						$p_avg_disp = null !== $p_avg ? number_format( $p_avg, 2 ) : '—';

						$exam_val = $t_row ? number_format( (float) $t_row->final_exam_score, 2, '.', '' ) : '';
						$proy_val = $t_row ? number_format( (float) $t_row->proyecto_score,   2, '.', '' ) : '';

						$nota_t_num  = null;
						if ( null !== $p_avg && $t_row ) {
							if ( $usa_sumativa_fe ) {
								$nota_t = round( $p_avg * 0.70 + ( ( (float) $t_row->final_exam_score + (float) $t_row->proyecto_score ) / 2 ) * 0.30, 2 );
							} else {
								$nota_t = round( $p_avg * 0.70 + (float) $t_row->final_exam_score * 0.30, 2 );
							}
							$nota_t_num  = $nota_t;
							$nota_t_disp = number_format( $nota_t, 2 );
							$nota_color  = $nota_t >= 7 ? '#1d4ed8' : '#dc2626';
						} elseif ( $t_row ) {
							$nota_t_num  = (float) $t_row->computed_score;
							$nota_t_disp = number_format( $nota_t_num, 2 );
							$nota_color  = $nota_t_num >= 7 ? '#1d4ed8' : '#dc2626';
						} else {
							$nota_t_disp = '—';
							$nota_color  = '#9ca3af';
						}
					?>
						<tr<?php echo $is_closed ? ' style="opacity:0.65;"' : ''; ?>>
							<td>
								<?php echo esc_html( $est->last_name . ', ' . $est->first_name ); ?>
								<?php if ( $is_closed ) : ?>
									<span style="font-size:11px; color:#b91c1c; margin-left:4px;"><?php esc_html_e( '(cerrado)', 'sistema-educativo' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="center" style="color:#6b7280;"><?php echo esc_html( $p1_disp ); ?></td>
							<td class="center" style="color:#6b7280;"><?php echo esc_html( $p2_disp ); ?></td>
							<td class="center" style="font-weight:600;"><?php echo esc_html( $p_avg_disp ); ?></td>
							<td class="center" style="padding:4px;">
								<input type="number" step="0.01" min="0" max="10"
									name="exam[<?php echo $esid; ?>]"
									value="<?php echo esc_attr( $exam_val ); ?>"
									style="width:72px; text-align:center; padding:4px 6px; border:1px solid #d1d5db; border-radius:4px; font-size:13px;"
									<?php echo $is_closed ? 'disabled' : ''; ?>>
							</td>
							<?php if ( $usa_sumativa_fe ) : ?>
							<td class="center" style="padding:4px;">
								<input type="number" step="0.01" min="0" max="10"
									name="proyecto[<?php echo $esid; ?>]"
									value="<?php echo esc_attr( $proy_val ); ?>"
									style="width:72px; text-align:center; padding:4px 6px; border:1px solid #d1d5db; border-radius:4px; font-size:13px;"
									<?php echo $is_closed ? 'disabled' : ''; ?>>
							</td>
							<?php endif; ?>
							<td class="center bg-blue" style="color:<?php echo esc_attr( $nota_color ); ?>; font-weight:700;">
								<?php echo esc_html( $nota_t_disp ); ?>
							</td>
							<td class="center">
								<?php if ( null !== $nota_t_num && $nota_t_num > 0 ) : ?>
									<?php echo Edu_Qualitativa_Helper::badge( $nota_t_num, $grade_sub_level ); // phpcs:ignore ?>
								<?php else : ?>
									<span style="color:#9ca3af;">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<div style="display:flex; justify-content:flex-end; margin-top:12px;">
					<button type="submit" class="edu-btn edu-btn-primary">
						&#128190; <?php esc_html_e( 'Guardar evaluación sumativa', 'sistema-educativo' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php endif; ?>

		<?php /* ═══════════════════ TAREAS ═══════════════════ */ ?>
		<?php elseif ( 'tareas' === $tab ) : ?>

		<!-- Toggle nueva tarea (se oculta en modo edición) -->
		<?php $mostrar_form_tarea = ! $edit_task_id && ( ! empty( $_GET['edu_nueva'] ) || $nt_grade_sel || ( 'error' === $edu_status && ! $tarea_id_sel ) ); ?>
		<?php if ( ! $edit_task_id ) : ?>
		<div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
			<button type="button" id="btn-nueva-tarea" class="edu-btn edu-btn-primary"
			        onclick="eduToggle('form-nueva-tarea','btn-nueva-tarea','+ <?php echo esc_js( __( 'Nueva tarea', 'sistema-educativo' ) ); ?>','× <?php echo esc_js( __( 'Cerrar', 'sistema-educativo' ) ); ?>')">
				<?php echo $mostrar_form_tarea ? '× ' . esc_html__( 'Cerrar', 'sistema-educativo' ) : '+ ' . esc_html__( 'Nueva tarea', 'sistema-educativo' ); ?>
			</button>
		</div>
		<?php endif; ?>

		<!-- Formulario nueva tarea -->
		<div id="form-nueva-tarea" class="edu-card" style="margin-bottom:20px;<?php echo $mostrar_form_tarea ? '' : ' display:none;'; ?>">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Nueva tarea / actividad', 'sistema-educativo' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'edu_save_assignment_task' ); ?>
				<input type="hidden" name="action"    value="edu_save_assignment_task">
				<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">

				<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Grado *', 'sistema-educativo' ); ?></label>
						<select name="grade_id" id="nt_grade" required onchange="eduNtGradeChange(this.value)">
							<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $mis_grados as $g ) : ?>
								<option value="<?php echo (int) $g->id; ?>" <?php selected( $nt_grade_sel, (int) $g->id ); ?>><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Materia *', 'sistema-educativo' ); ?></label>
						<select name="subject_id" id="nt_subject" required onchange="eduUpdateComponents()">
							<?php if ( $nt_subjects ) : ?>
								<?php foreach ( $nt_subjects as $s ) : ?>
									<option value="<?php echo (int) $s['id']; ?>"><?php echo esc_html( $s['name'] ); ?></option>
								<?php endforeach; ?>
							<?php else : ?>
								<option value=""><?php echo $nt_grade_sel ? esc_html__( '— Sin materias asignadas —', 'sistema-educativo' ) : esc_html__( '— Seleccionar grado primero —', 'sistema-educativo' ); ?></option>
							<?php endif; ?>
						</select>
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Trimestre *', 'sistema-educativo' ); ?></label>
						<select name="trimester_id" id="nt_trim" required onchange="eduUpdateComponents()">
							<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $trimesters as $t ) : ?>
								<option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( 'T' . $t->trim_num . ' — ' . $t->period_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Parcial *', 'sistema-educativo' ); ?></label>
						<select name="parcial_num" id="nt_parcial" required onchange="eduUpdateComponents()">
							<option value="1"><?php esc_html_e( 'Parcial 1', 'sistema-educativo' ); ?></option>
							<option value="2"><?php esc_html_e( 'Parcial 2', 'sistema-educativo' ); ?></option>
						</select>
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Se evalúa como *', 'sistema-educativo' ); ?></label>
						<select name="component_id" id="nt_component" onchange="eduToggleNuevoComponente('nt',1)">
							<option value="-1"><?php esc_html_e( '➕ Crear componente nuevo…', 'sistema-educativo' ); ?></option>
							<option value="0"><?php esc_html_e( 'Sin vincular (no cuenta para la nota)', 'sistema-educativo' ); ?></option>
						</select>
					</div>
					<div class="edu-form-row" id="nt_component_nuevo">
						<label><?php esc_html_e( 'Nombre del componente', 'sistema-educativo' ); ?></label>
						<input type="text" name="component_new_name" maxlength="100"
						       placeholder="<?php esc_attr_e( 'Ej. Tarea capítulo 3', 'sistema-educativo' ); ?>">
						<input type="hidden" name="component_new_weight" value="1.00">
						<span style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'Pesa igual que los demás componentes del parcial. Si el nombre ya existe, se reutiliza y las notas se promedian.', 'sistema-educativo' ); ?></span>
					</div>
				</div>

				<div class="edu-form-row">
					<label><?php esc_html_e( 'Título *', 'sistema-educativo' ); ?></label>
					<input type="text" name="title" required placeholder="<?php esc_attr_e( 'Ej. Tarea de Matemáticas — Capítulo 3', 'sistema-educativo' ); ?>">
				</div>
				<div class="edu-form-row">
					<label><?php esc_html_e( 'Descripción / instrucciones', 'sistema-educativo' ); ?></label>
					<textarea name="description" rows="3" style="width:100%;"></textarea>
				</div>

				<div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Fecha de entrega', 'sistema-educativo' ); ?></label>
						<input type="datetime-local" name="due_date">
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Nota máxima', 'sistema-educativo' ); ?></label>
						<input type="number" name="max_score" value="10" min="1" max="100" step="0.5">
					</div>
					<div class="edu-form-row" style="display:flex; flex-direction:column; justify-content:flex-end;">
						<label style="display:flex; align-items:center; gap:6px; font-weight:400; cursor:pointer;">
							<input type="checkbox" name="notify_parents" value="1"> <?php esc_html_e( 'Notificar a padres', 'sistema-educativo' ); ?>
						</label>
						<label style="display:flex; align-items:center; gap:6px; font-weight:400; cursor:pointer; margin-top:6px;">
							<input type="checkbox" name="publish_now" value="1" checked> <?php esc_html_e( 'Publicar ahora', 'sistema-educativo' ); ?>
						</label>
					</div>
				</div>

				<div class="edu-form-row">
					<label><?php esc_html_e( 'Archivos adjuntos (opcional)', 'sistema-educativo' ); ?></label>
					<input type="file" name="adjuntos[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip">
					<span style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'Máx. 10 MB por archivo. Tipos: PDF, Word, PowerPoint, Excel, imágenes, ZIP.', 'sistema-educativo' ); ?></span>
				</div>

				<div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
					<button type="button" class="edu-btn edu-btn-outline" onclick="eduToggle('form-nueva-tarea','btn-nueva-tarea','+ <?php echo esc_js( __( 'Nueva tarea', 'sistema-educativo' ) ); ?>','× <?php echo esc_js( __( 'Cerrar', 'sistema-educativo' ) ); ?>')">
						<?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?>
					</button>
					<button type="submit" class="edu-btn edu-btn-primary">
						&#128190; <?php esc_html_e( 'Crear tarea', 'sistema-educativo' ); ?>
					</button>
				</div>
			</form>
		</div>

		<?php /* ── Formulario EDITAR tarea ── */ ?>
		<?php if ( $edit_task_id && $edit_task ) :
			$et_closed = ( 'closed' === $edit_task->status );
		?>
		<div class="edu-card" style="margin-bottom:20px; border-left:4px solid #7c3aed;">
			<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
				<div>
					<h3 style="margin:0 0 2px;"><?php esc_html_e( 'Editar tarea', 'sistema-educativo' ); ?></h3>
					<span style="font-size:12px; color:#6b7280;"><?php echo esc_html( $edit_task->title ); ?></span>
				</div>
				<a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'tareas', $current_url ) ); ?>" class="edu-btn edu-btn-outline edu-btn-sm">
					&#8592; <?php esc_html_e( 'Volver a lista', 'sistema-educativo' ); ?>
				</a>
			</div>

			<?php if ( $et_closed ) : ?>
			<div class="edu-alert" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Esta tarea está cerrada. Solo puedes visualizarla.', 'sistema-educativo' ); ?>
			</div>

			<!-- Bloque de recuperación para tarea cerrada -->
			<div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:14px 16px; margin-bottom:14px;">
				<div style="font-weight:700; font-size:13px; margin-bottom:8px; color:#92400e;">&#9650; <?php esc_html_e( 'Recuperación / Mejora de nota', 'sistema-educativo' ); ?></div>
				<p style="font-size:12px; color:#78350f; margin:0 0 10px;">
					<?php esc_html_e( 'Permite que los estudiantes re-entreguen la actividad. Se conservará la mejor nota entre la original y la mejora.', 'sistema-educativo' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'edu_save_recovery_settings' ); ?>
					<input type="hidden" name="action"    value="edu_save_recovery_settings">
					<input type="hidden" name="id"        value="<?php echo (int) $edit_task_id; ?>">
					<input type="hidden" name="_redirect" value="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_edit_task' => $edit_task_id, 'edu_status' => 'recovery_saved' ), $current_url ) ); ?>">
					<div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
						<label style="display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer;">
							<input type="checkbox" name="allow_recovery" value="1" id="fe-allow-recovery"
								<?php checked( (bool) $edit_task->allow_recovery ); ?>>
							<?php esc_html_e( 'Activar mejora', 'sistema-educativo' ); ?>
						</label>
						<div id="fe-recovery-date-wrap" <?php echo $edit_task->allow_recovery ? '' : 'style="display:none;"'; ?>>
							<label style="font-size:12px; color:#6b7280; display:block; margin-bottom:3px;"><?php esc_html_e( 'Fecha límite (opcional)', 'sistema-educativo' ); ?></label>
							<input type="datetime-local" name="recovery_due_date"
								value="<?php echo $edit_task->recovery_due_date ? esc_attr( date( 'Y-m-d\TH:i', strtotime( $edit_task->recovery_due_date ) ) ) : ''; ?>"
								style="font-size:13px;">
						</div>
						<button type="submit" class="edu-btn edu-btn-primary edu-btn-sm">
							&#128190; <?php esc_html_e( 'Guardar', 'sistema-educativo' ); ?>
						</button>
					</div>
				</form>
			</div>
			<script>
			document.getElementById('fe-allow-recovery').addEventListener('change', function() {
				document.getElementById('fe-recovery-date-wrap').style.display = this.checked ? '' : 'none';
			});
			</script>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'edu_save_assignment_task' ); ?>
				<input type="hidden" name="action"    value="edu_save_assignment_task">
				<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">
				<input type="hidden" name="id"        value="<?php echo (int) $edit_task_id; ?>">
				<!-- Preserva el estado actual al guardar (Publicar/Cerrar son acciones separadas) -->
				<input type="hidden" name="publish_now" value="<?php echo 'published' === $edit_task->status ? '1' : '0'; ?>">

				<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Grado *', 'sistema-educativo' ); ?></label>
						<select name="grade_id" id="edit_grade" required <?php echo $et_closed ? 'disabled' : ''; ?> onchange="eduEditGradeChange(this.value)">
							<?php foreach ( $mis_grados as $g ) : ?>
								<option value="<?php echo (int) $g->id; ?>" <?php selected( (int) $edit_task->grade_id, (int) $g->id ); ?>><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Materia *', 'sistema-educativo' ); ?></label>
						<select name="subject_id" id="edit_subject" required <?php echo $et_closed ? 'disabled' : ''; ?> onchange="eduEditUpdateComponents()">
							<?php foreach ( $edit_task_subjects as $s ) : ?>
								<option value="<?php echo (int) $s->id; ?>" <?php selected( (int) $edit_task->subject_id, (int) $s->id ); ?>><?php echo esc_html( $s->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Trimestre *', 'sistema-educativo' ); ?></label>
						<select name="trimester_id" id="edit_trim" required <?php echo $et_closed ? 'disabled' : ''; ?> onchange="eduEditUpdateComponents()">
							<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $trimesters as $t ) : ?>
								<option value="<?php echo (int) $t->id; ?>" <?php selected( (int) $edit_task->trimester_id, (int) $t->id ); ?>><?php echo esc_html( 'T' . $t->trim_num . ' — ' . $t->period_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Parcial *', 'sistema-educativo' ); ?></label>
						<select name="parcial_num" id="edit_parcial" required <?php echo $et_closed ? 'disabled' : ''; ?> onchange="eduEditUpdateComponents()">
							<option value="1" <?php selected( (int) $edit_task->parcial_num, 1 ); ?>><?php esc_html_e( 'Parcial 1', 'sistema-educativo' ); ?></option>
							<option value="2" <?php selected( (int) $edit_task->parcial_num, 2 ); ?>><?php esc_html_e( 'Parcial 2', 'sistema-educativo' ); ?></option>
						</select>
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Se evalúa como', 'sistema-educativo' ); ?></label>
						<select name="component_id" id="edit_component" <?php echo $et_closed ? 'disabled' : ''; ?> onchange="eduToggleNuevoComponente('edit',1)">
							<option value="-1"><?php esc_html_e( '➕ Crear componente nuevo…', 'sistema-educativo' ); ?></option>
							<option value="0"><?php esc_html_e( 'Sin vincular (no cuenta para la nota)', 'sistema-educativo' ); ?></option>
						</select>
					</div>
					<div class="edu-form-row" id="edit_component_nuevo">
						<label><?php esc_html_e( 'Nombre del componente', 'sistema-educativo' ); ?></label>
						<input type="text" name="component_new_name" maxlength="100"
						       placeholder="<?php esc_attr_e( 'Ej. Tarea capítulo 3', 'sistema-educativo' ); ?>"
						       <?php echo $et_closed ? 'disabled' : ''; ?>>
						<input type="hidden" name="component_new_weight" value="1.00">
						<span style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'Pesa igual que los demás componentes del parcial.', 'sistema-educativo' ); ?></span>
					</div>
				</div>

				<div class="edu-form-row">
					<label><?php esc_html_e( 'Título *', 'sistema-educativo' ); ?></label>
					<input type="text" name="title" required value="<?php echo esc_attr( $edit_task->title ); ?>" <?php echo $et_closed ? 'disabled' : ''; ?>>
				</div>
				<div class="edu-form-row">
					<label><?php esc_html_e( 'Descripción / instrucciones', 'sistema-educativo' ); ?></label>
					<textarea name="description" rows="3" style="width:100%;" <?php echo $et_closed ? 'disabled' : ''; ?>><?php echo esc_textarea( $edit_task->description ?? '' ); ?></textarea>
				</div>

				<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Fecha de entrega', 'sistema-educativo' ); ?></label>
						<input type="datetime-local" name="due_date"
						       value="<?php echo $edit_task->due_date ? esc_attr( date( 'Y-m-d\TH:i', strtotime( $edit_task->due_date ) ) ) : ''; ?>"
						       <?php echo $et_closed ? 'disabled' : ''; ?>>
					</div>
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Nota máxima', 'sistema-educativo' ); ?></label>
						<input type="number" name="max_score"
						       value="<?php echo esc_attr( number_format( (float) $edit_task->max_score, 2, '.', '' ) ); ?>"
						       min="1" max="100" step="0.5" <?php echo $et_closed ? 'disabled' : ''; ?>>
					</div>
				</div>

				<div class="edu-form-row" style="margin-bottom:8px;">
					<label style="display:flex; align-items:center; gap:6px; font-weight:400; cursor:pointer;">
						<input type="checkbox" name="notify_parents" value="1" <?php checked( (bool) $edit_task->notify_parents ); ?> <?php echo $et_closed ? 'disabled' : ''; ?>>
						<?php esc_html_e( 'Notificar a padres', 'sistema-educativo' ); ?>
					</label>
				</div>

				<?php if ( $edit_task_files ) : ?>
				<div class="edu-form-row">
					<label><?php esc_html_e( 'Archivos adjuntos actuales', 'sistema-educativo' ); ?></label>
					<div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:4px;">
						<?php foreach ( $edit_task_files as $etf ) :
							$dl_etf = Edu_Assignment_Task_Controller::get_download_url( $etf->id );
						?>
						<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:8px 10px; display:flex; align-items:center; gap:8px; font-size:12px;">
							<a href="<?php echo esc_url( $dl_etf ); ?>" target="_blank" style="color:#1d4ed8;">
								&#128196; <?php echo esc_html( $etf->file_name ); ?>
							</a>
							<small style="color:#9ca3af;">(<?php echo esc_html( size_format( (int) $etf->file_size ) ); ?>)</small>
							<?php if ( ! $et_closed ) : ?>
							<label style="display:flex; align-items:center; gap:3px; color:#dc2626; font-size:11px; cursor:pointer;">
								<input type="checkbox" name="delete_files[]" value="<?php echo (int) $etf->id; ?>">
								<?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?>
							</label>
							<?php endif; ?>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( ! $et_closed ) : ?>
				<div class="edu-form-row">
					<label><?php esc_html_e( 'Adjuntar más archivos (opcional)', 'sistema-educativo' ); ?></label>
					<input type="file" name="adjuntos[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip">
					<span style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'Máx. 10 MB por archivo.', 'sistema-educativo' ); ?></span>
				</div>

				<div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
					<a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'tareas', $current_url ) ); ?>" class="edu-btn edu-btn-outline">
						<?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?>
					</a>
					<button type="submit" class="edu-btn edu-btn-primary">
						&#128190; <?php esc_html_e( 'Guardar cambios', 'sistema-educativo' ); ?>
					</button>
				</div>
				<?php endif; ?>
			</form>

			<?php if ( ! $et_closed ) : ?>
			<div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; padding-top:12px; border-top:1px solid #f3f4f6;">

				<?php if ( 'draft' === $edit_task->status ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'edu_publish_assignment_task' ); ?>
					<input type="hidden" name="action"    value="edu_publish_assignment_task">
					<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">
					<input type="hidden" name="id"        value="<?php echo (int) $edit_task_id; ?>">
					<button type="submit" class="edu-btn edu-btn-primary">
						&#10003; <?php esc_html_e( 'Publicar tarea', 'sistema-educativo' ); ?>
					</button>
				</form>
				<?php endif; ?>

				<?php if ( 'published' === $edit_task->status ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'edu_close_assignment_task' ); ?>
					<input type="hidden" name="action"    value="edu_close_assignment_task">
					<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">
					<input type="hidden" name="id"        value="<?php echo (int) $edit_task_id; ?>">
					<button type="submit" class="edu-btn edu-btn-outline"
					        onclick="return confirm('<?php esc_attr_e( '¿Cerrar esta tarea? Los estudiantes ya no podrán entregar.', 'sistema-educativo' ); ?>')">
						&#128274; <?php esc_html_e( 'Cerrar tarea', 'sistema-educativo' ); ?>
					</button>
				</form>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'edu_delete_assignment_task' ); ?>
					<input type="hidden" name="action"    value="edu_delete_assignment_task">
					<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">
					<input type="hidden" name="id"        value="<?php echo (int) $edit_task_id; ?>">
					<button type="submit" class="edu-btn edu-btn-outline" style="color:#dc2626; border-color:#fca5a5;"
					        onclick="return confirm('<?php esc_attr_e( '¿Eliminar esta tarea y todos sus archivos? Esta acción no se puede deshacer.', 'sistema-educativo' ); ?>')">
						&#128465; <?php esc_html_e( 'Eliminar tarea', 'sistema-educativo' ); ?>
					</button>
				</form>

			</div>
			<?php endif; ?>
		</div>
		<?php endif; // edit_task ?>

		<?php /* ── Ver entregas de una tarea seleccionada ── */ ?>
		<?php if ( $tarea_id_sel && $tarea_detail ) : ?>
		<div class="edu-card" style="border-left:4px solid #1d4ed8; margin-bottom:20px;">
			<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
				<div>
					<h3 style="margin:0 0 4px;">
						<?php esc_html_e( 'Entregas:', 'sistema-educativo' ); ?> <?php echo esc_html( $tarea_detail->title ); ?>
					</h3>
					<span style="font-size:12px; color:#6b7280;">
						<?php echo esc_html( $tarea_detail->grade_name . ' ' . $tarea_detail->paralelo . ' · ' . $tarea_detail->subject_name ); ?>
						&nbsp;·&nbsp; <?php esc_html_e( 'Nota máx.:', 'sistema-educativo' ); ?> <?php echo number_format( (float) $tarea_detail->max_score, 2 ); ?>
					</span>
				</div>
				<a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'tareas', $current_url ) ); ?>" class="edu-btn edu-btn-outline edu-btn-sm">
					&#8592; <?php esc_html_e( 'Volver a tareas', 'sistema-educativo' ); ?>
				</a>
			</div>

			<?php if ( ! empty( $tarea_detail->allow_recovery ) ) : ?>
			<div style="background:#fff3cd; border:1px solid #fcd34d; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:13px;">
				<strong>&#9650; <?php esc_html_e( 'Mejora activa', 'sistema-educativo' ); ?></strong>
				<?php if ( $tarea_detail->recovery_due_date ) : ?>
					&nbsp;—&nbsp; <?php esc_html_e( 'Límite:', 'sistema-educativo' ); ?>
					<strong><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $tarea_detail->recovery_due_date ) ) ); ?></strong>
				<?php else : ?>
					&nbsp;—&nbsp; <?php esc_html_e( 'Sin fecha límite', 'sistema-educativo' ); ?>
				<?php endif; ?>
				&nbsp;·&nbsp; <?php esc_html_e( 'Se guarda la mejor nota', 'sistema-educativo' ); ?>
			</div>
			<?php endif; ?>

			<?php if ( empty( $tarea_students ) ) : ?>
				<p style="color:#9ca3af; font-size:13px;"><?php esc_html_e( 'El grado no tiene estudiantes activos.', 'sistema-educativo' ); ?></p>
			<?php else : ?>
			<?php foreach ( $tarea_students as $ts ) :
				$sid  = (int) $ts->id;
				$sub  = $tarea_subs[ $sid ] ?? null;
				$name = esc_html( $ts->last_name . ', ' . $ts->first_name );

				if ( ! $sub ) :
					$sub_status = 'pending';
				elseif ( 'graded' === $sub->status ) :
					$sub_status = 'graded';
				elseif ( 'late' === $sub->status ) :
					$sub_status = 'late';
				else :
					$sub_status = 'submitted';
				endif;

				$status_colors = array(
					'pending'   => array( 'bg' => '#fef3c7', 'tc' => '#92400e', 'label' => __( 'Sin entregar', 'sistema-educativo' ) ),
					'submitted' => array( 'bg' => '#dcfce7', 'tc' => '#15803d', 'label' => __( 'Entregada', 'sistema-educativo' ) ),
					'late'      => array( 'bg' => '#fef3c7', 'tc' => '#854d0e', 'label' => __( 'Con retraso', 'sistema-educativo' ) ),
					'graded'    => array( 'bg' => '#dbeafe', 'tc' => '#1e40af', 'label' => __( 'Calificada', 'sistema-educativo' ) ),
				);
				$sc = $status_colors[ $sub_status ];
			?>
			<div style="border:1px solid #e5e7eb; border-radius:8px; padding:12px; margin-bottom:10px;">
				<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
					<div>
						<strong><?php echo $name; ?></strong>
						<?php if ( $sub ) : ?>
							<div style="font-size:11px; color:#6b7280; margin-top:2px;">
								<?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $sub->submitted_at ) ) ); ?>
							</div>
						<?php endif; ?>
					</div>
					<span style="background:<?php echo esc_attr( $sc['bg'] ); ?>; color:<?php echo esc_attr( $sc['tc'] ); ?>; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px;">
						<?php echo esc_html( $sc['label'] ); ?>
					</span>
				</div>

				<?php if ( $sub && $sub->comment ) : ?>
					<div style="background:#f9fafb; border-radius:4px; padding:8px; margin-top:8px; font-size:13px; color:#374151;">
						<?php echo wp_kses_post( $sub->comment ); ?>
					</div>
				<?php endif; ?>

				<?php
				// Archivos de la entrega
				if ( $sub && ! empty( $tarea_sub_files[ (int) $sub->id ] ) ) : ?>
				<div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:6px;">
					<?php foreach ( $tarea_sub_files[ (int) $sub->id ] as $f ) :
						$dl_url = add_query_arg( array(
							'action'   => 'edu_download_sub_file',
							'file_id'  => (int) $f->id,
							'_wpnonce' => wp_create_nonce( 'edu_download_sub_file_' . (int) $f->id ),
						), admin_url( 'admin-post.php' ) );
					?>
						<a href="<?php echo esc_url( $dl_url ); ?>" class="edu-btn edu-btn-outline edu-btn-sm" download>
							&#128196; <?php echo esc_html( $f->file_name ); ?>
						</a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<?php if ( $sub && in_array( $sub->status, array( 'submitted', 'late', 'graded' ), true ) ) : ?>
				<!-- Formulario de calificación -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px; padding-top:10px; border-top:1px solid #f3f4f6;">
					<?php wp_nonce_field( 'edu_grade_submission' ); ?>
					<input type="hidden" name="action"        value="edu_grade_submission">
					<input type="hidden" name="_redirect"     value="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_tarea_id' => $tarea_id_sel ), $current_url ) ); ?>">
					<input type="hidden" name="submission_id" value="<?php echo (int) $sub->id; ?>">
					<div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
						<div class="edu-form-row" style="margin:0; min-width:100px;">
							<label><?php esc_html_e( 'Nota (0–', 'sistema-educativo' ); ?><?php echo number_format( (float) $tarea_detail->max_score, 0 ); ?>)</label>
							<input type="number" name="score"
							       value="<?php echo 'graded' === $sub->status && null !== $sub->score ? esc_attr( number_format( (float) $sub->score, 2, '.', '' ) ) : ''; ?>"
							       min="0" max="<?php echo (float) $tarea_detail->max_score; ?>" step="0.5"
							       style="width:80px; padding:5px 8px; border:1px solid #d1d5db; border-radius:4px;">
						</div>
						<div class="edu-form-row" style="margin:0; flex:1; min-width:180px;">
							<label><?php esc_html_e( 'Comentario al estudiante', 'sistema-educativo' ); ?></label>
							<input type="text" name="feedback" value="<?php echo esc_attr( $sub->feedback ?? '' ); ?>" style="width:100%;">
						</div>
						<button type="submit" class="edu-btn edu-btn-primary edu-btn-sm">
							<?php echo 'graded' === $sub->status ? esc_html__( 'Actualizar', 'sistema-educativo' ) : esc_html__( 'Calificar', 'sistema-educativo' ); ?>
						</button>
					</div>
				</form>
				<?php endif; ?>

				<?php
				// ── Sección de mejora — disponible para TODOS cuando allow_recovery activo ──
				if ( ! empty( $tarea_detail->allow_recovery ) ) :
					$rec_st = $sub ? ( $sub->recovery_status ?? 'none' ) : 'none';
				?>
				<div style="margin-top:10px; padding:10px 12px; background:#fffbeb; border:1px solid #fcd34d; border-radius:6px;">
					<div style="font-size:12px; font-weight:700; color:#92400e; margin-bottom:6px;">
						&#9650; <?php esc_html_e( 'Mejora', 'sistema-educativo' ); ?>
						<?php
						$rec_badge = array(
							'none'      => array( '#e5e7eb', '#6b7280', __( 'No entregada', 'sistema-educativo' ) ),
							'available' => array( '#fef9c3', '#92400e', __( 'Disponible', 'sistema-educativo' ) ),
							'submitted' => array( '#dcfce7', '#15803d', __( 'Entregada — pendiente de calificar', 'sistema-educativo' ) ),
							'graded'    => array( '#dbeafe', '#1e40af', __( 'Calificada', 'sistema-educativo' ) ),
						);
						$rb = $rec_badge[ $rec_st ] ?? $rec_badge['none'];
						?>
						<span style="background:<?php echo esc_attr( $rb[0] ); ?>; color:<?php echo esc_attr( $rb[1] ); ?>; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px; margin-left:6px;">
							<?php echo esc_html( $rb[2] ); ?>
						</span>
					</div>

					<?php if ( 'submitted' === $rec_st ) : ?>
						<?php if ( $sub->recovery_comment ) : ?>
						<div style="font-size:12px; color:#374151; margin-bottom:8px; background:#fff; padding:6px 8px; border-radius:4px;">
							<?php echo wp_kses_post( $sub->recovery_comment ); ?>
						</div>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'edu_grade_recovery' ); ?>
							<input type="hidden" name="action"        value="edu_grade_recovery">
							<input type="hidden" name="_redirect"     value="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_tarea_id' => $tarea_id_sel ), $current_url ) ); ?>">
							<input type="hidden" name="submission_id" value="<?php echo (int) $sub->id; ?>">
							<div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
								<div class="edu-form-row" style="margin:0; min-width:100px;">
									<label style="font-size:12px;"><?php esc_html_e( 'Nota mejora (0–', 'sistema-educativo' ); ?><?php echo number_format( (float) $tarea_detail->max_score, 0 ); ?>)</label>
									<input type="number" name="recovery_score" min="0"
									       max="<?php echo (float) $tarea_detail->max_score; ?>" step="0.5"
									       style="width:80px; padding:5px 8px; border:1px solid #fcd34d; border-radius:4px;" required>
								</div>
								<div class="edu-form-row" style="margin:0; flex:1; min-width:180px;">
									<label style="font-size:12px;"><?php esc_html_e( 'Comentario', 'sistema-educativo' ); ?></label>
									<input type="text" name="recovery_feedback" style="width:100%;">
								</div>
								<button type="submit" class="edu-btn edu-btn-sm" style="background:#f59e0b; color:#fff; border:none;">
									&#9650; <?php esc_html_e( 'Calificar mejora', 'sistema-educativo' ); ?>
								</button>
							</div>
						</form>

					<?php elseif ( 'graded' === $rec_st ) : ?>
						<?php
						$score_orig = $sub && null !== $sub->score ? (float) $sub->score : null;
						$mejor      = max( $score_orig ?? 0.0, (float) $sub->recovery_score );
						?>
						<div style="font-size:12px; color:#374151;">
							<?php esc_html_e( 'Nota original:', 'sistema-educativo' ); ?> <strong><?php echo null !== $score_orig ? number_format( $score_orig, 2 ) : '—'; ?></strong>
							&nbsp;·&nbsp;
							<?php esc_html_e( 'Nota mejora:', 'sistema-educativo' ); ?> <strong><?php echo number_format( (float) $sub->recovery_score, 2 ); ?></strong>
							&nbsp;·&nbsp;
							<?php esc_html_e( 'Mejor nota:', 'sistema-educativo' ); ?> <strong style="color:#1d4ed8;"><?php echo number_format( $mejor, 2 ); ?></strong>
						</div>
						<?php if ( $sub->recovery_feedback ) : ?>
						<div style="font-size:11px; color:#6b7280; margin-top:4px;"><?php echo wp_kses_post( $sub->recovery_feedback ); ?></div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
				<?php endif; // allow_recovery ?>

			</div>
			<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php /* ── Filtro y lista: solo cuando NO estamos en modo "nueva tarea" ── */ ?>
		<?php if ( ! $mostrar_form_tarea ) : ?>

		<?php /* ── Filtro: Grado, Materia y Estado ── */ ?>
		<div class="edu-card" style="padding:14px; margin-bottom:14px;">
			<form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
				<input type="hidden" name="edu_tab" value="tareas">
				<div class="edu-form-row" style="margin:0; flex:1; min-width:150px;">
					<label><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></label>
					<select name="edu_task_grade" id="task_filter_grade" onchange="eduTaskGradeChange(this)">
						<option value="0" <?php selected( $task_grade_filter, 0 ); ?>><?php esc_html_e( 'Todos los grados', 'sistema-educativo' ); ?></option>
						<?php foreach ( $mis_grados as $g ) : ?>
							<option value="<?php echo (int) $g->id; ?>" <?php selected( $task_grade_filter, (int) $g->id ); ?>><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="edu-form-row" style="margin:0; flex:1; min-width:150px;">
					<label><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></label>
					<select name="edu_task_subj" id="task_filter_subj" onchange="this.form.submit()" <?php echo ! $task_grade_filter ? 'disabled' : ''; ?>>
						<option value="0" <?php selected( $task_subj_filter, 0 ); ?>>
							<?php echo $task_grade_filter ? esc_html__( 'Todas las materias', 'sistema-educativo' ) : esc_html__( '— Seleccionar grado primero —', 'sistema-educativo' ); ?>
						</option>
						<?php foreach ( $task_filter_subjects as $s ) : ?>
							<option value="<?php echo (int) $s['id']; ?>" <?php selected( $task_subj_filter, (int) $s['id'] ); ?>><?php echo esc_html( $s['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="edu-form-row" style="margin:0; min-width:130px;">
					<label><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></label>
					<select name="edu_task_status" onchange="this.form.submit()">
						<option value="" <?php selected( $task_status_filter, '' ); ?>><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
						<option value="published" <?php selected( $task_status_filter, 'published' ); ?>><?php esc_html_e( 'Publicadas', 'sistema-educativo' ); ?></option>
						<option value="draft"     <?php selected( $task_status_filter, 'draft' ); ?>><?php esc_html_e( 'Borrador', 'sistema-educativo' ); ?></option>
						<option value="closed"    <?php selected( $task_status_filter, 'closed' ); ?>><?php esc_html_e( 'Cerradas', 'sistema-educativo' ); ?></option>
					</select>
				</div>
				<?php if ( $task_grade_filter || $task_subj_filter || $task_status_filter ) : ?>
				<div class="edu-form-row" style="margin:0;">
					<label>&nbsp;</label>
					<a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'tareas', $current_url ) ); ?>" class="edu-btn edu-btn-outline">
						&times; <?php esc_html_e( 'Limpiar', 'sistema-educativo' ); ?>
					</a>
				</div>
				<?php endif; ?>
			</form>
		</div>

		<?php /* ── Lista de tareas ── */ ?>
		<?php if ( empty( $tareas_lista ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No hay tareas creadas aún.', 'sistema-educativo' ); ?></div>
		<?php else : ?>
		<?php foreach ( $tareas_lista as $t ) :
			$tk  = $t->type;
			$bg  = $type_colors[ $tk ] ?? '#f3f4f6';
			$tc  = $type_text[ $tk ] ?? '#374151';
			$st_colors = array(
				'draft'     => array( '#f3f4f6', '#6b7280', __( 'Borrador', 'sistema-educativo' ) ),
				'published' => array( '#dcfce7', '#15803d', __( 'Publicada', 'sistema-educativo' ) ),
				'closed'    => array( '#fee2e2', '#b91c1c', __( 'Cerrada', 'sistema-educativo' ) ),
			);
			$st = $st_colors[ $t->status ] ?? $st_colors['draft'];
		?>
			<div class="edu-tarea-card" style="<?php echo $tarea_id_sel === (int)$t->id ? 'border-color:#1d4ed8;' : ''; ?>">
				<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:6px;">
					<div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
						<span style="background:<?php echo esc_attr( $bg ); ?>; color:<?php echo esc_attr( $tc ); ?>; font-size:11px; font-weight:600; padding:2px 8px; border-radius:4px;">
							<?php echo esc_html( $type_labels[ $tk ] ?? $tk ); ?>
						</span>
						<span style="background:<?php echo esc_attr( $st[0] ); ?>; color:<?php echo esc_attr( $st[1] ); ?>; font-size:11px; padding:2px 8px; border-radius:4px;">
							<?php echo esc_html( $st[2] ); ?>
						</span>
					</div>
					<span style="font-size:11px; color:#6b7280;"><?php echo esc_html( $t->subject_name . ' · ' . $t->grade_name . ' ' . $t->paralelo ); ?></span>
				</div>
				<h4 style="margin:4px 0 8px;"><?php echo esc_html( $t->title ); ?></h4>
				<div style="margin-top:8px; font-size:12px; color:#6b7280;">
					<?php if ( $t->due_date ) : ?>
						&#128197; <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $t->due_date ) ) ); ?>
						&nbsp;·&nbsp;
					<?php endif; ?>
					<?php if ( (int) $t->pendientes > 0 ) : ?>
						<span style="color:#d97706; font-weight:600;">&#128229; <?php echo (int) $t->pendientes; ?> <?php esc_html_e( 'por calificar', 'sistema-educativo' ); ?></span>
						&nbsp;·&nbsp;
					<?php endif; ?>
					<?php echo esc_html( (int) $t->calificadas . '/' . (int) $t->total_est . ' ' . __( 'calificadas', 'sistema-educativo' ) ); ?>
					<?php if ( ! empty( $t->allow_recovery ) ) : ?>
						&nbsp;·&nbsp;
						<span style="background:#fff3cd; color:#92400e; font-weight:600; padding:1px 7px; border-radius:4px;">
							&#9650; <?php esc_html_e( 'Mejora activa', 'sistema-educativo' ); ?>
							<?php if ( (int) $t->recovery_pending > 0 ) : ?>
								<strong style="color:#dc2626;">(<?php echo (int) $t->recovery_pending; ?>)</strong>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				</div>
				<div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; padding-top:10px; border-top:1px solid #f3f4f6; align-items:center;">
					<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_tarea_id' => (int) $t->id ), $current_url ) ); ?>"
					   class="edu-btn edu-btn-outline edu-btn-sm">
						&#128196; <?php esc_html_e( 'Ver entregas', 'sistema-educativo' ); ?>
					</a>
					<?php if ( 'closed' !== $t->status ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_edit_task' => (int) $t->id ), $current_url ) ); ?>"
					   class="edu-btn edu-btn-outline edu-btn-sm">
						&#9998; <?php esc_html_e( 'Editar', 'sistema-educativo' ); ?>
					</a>
					<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_edit_task' => (int) $t->id ), $current_url ) ); ?>"
					   class="edu-btn edu-btn-sm" style="background:#fffbeb; color:#92400e; border:1px solid #fcd34d;">
						&#9650; <?php echo ! empty( $t->allow_recovery ) ? esc_html__( 'Config. mejora', 'sistema-educativo' ) : esc_html__( 'Activar mejora', 'sistema-educativo' ); ?>
					</a>
					<?php endif; ?>
					<?php if ( 'draft' === $t->status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<?php wp_nonce_field( 'edu_publish_assignment_task' ); ?>
						<input type="hidden" name="action"    value="edu_publish_assignment_task">
						<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">
						<input type="hidden" name="id"        value="<?php echo (int) $t->id; ?>">
						<button type="submit" class="edu-btn edu-btn-primary edu-btn-sm">
							&#10003; <?php esc_html_e( 'Publicar', 'sistema-educativo' ); ?>
						</button>
					</form>
					<?php endif; ?>
					<?php if ( 'published' === $t->status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<?php wp_nonce_field( 'edu_close_assignment_task' ); ?>
						<input type="hidden" name="action"    value="edu_close_assignment_task">
						<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">
						<input type="hidden" name="id"        value="<?php echo (int) $t->id; ?>">
						<button type="submit" class="edu-btn edu-btn-outline edu-btn-sm"
						        onclick="return confirm('<?php esc_attr_e( '¿Cerrar esta tarea?', 'sistema-educativo' ); ?>')">
							&#128274; <?php esc_html_e( 'Cerrar', 'sistema-educativo' ); ?>
						</button>
					</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<?php wp_nonce_field( 'edu_delete_assignment_task' ); ?>
						<input type="hidden" name="action"    value="edu_delete_assignment_task">
						<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">
						<input type="hidden" name="id"        value="<?php echo (int) $t->id; ?>">
						<button type="submit" class="edu-btn edu-btn-outline edu-btn-sm" style="color:#dc2626; border-color:#fca5a5;"
						        onclick="return confirm('<?php esc_attr_e( '¿Eliminar esta tarea?', 'sistema-educativo' ); ?>')">
							&#128465; <?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?>
						</button>
					</form>
				</div>
			</div>
		<?php endforeach; ?>
		<?php endif; ?>

		<?php endif; // ! $mostrar_form_tarea ?>

		<?php /* ═══════════════════ ASISTENCIA ═══════════════════ */ ?>
		<?php elseif ( 'asistencia' === $tab ) : ?>

		<?php if ( empty( $mis_grados ) ) : ?>
			<div class="edu-alert edu-alert-yellow"><?php esc_html_e( 'No tienes grados asignados.', 'sistema-educativo' ); ?></div>
		<?php else : ?>

		<!-- Selector de grado / fecha / materia (GET) -->
		<div class="edu-card" style="padding:14px; margin-bottom:14px;">
			<form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
				<input type="hidden" name="edu_tab" value="asistencia">
				<div class="edu-form-row" style="margin:0; flex:1; min-width:150px;">
					<label><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></label>
					<select name="edu_att_grade" onchange="this.form.submit()">
						<?php foreach ( $mis_grados as $g ) : ?>
							<option value="<?php echo (int) $g->id; ?>" <?php selected( $att_grade_sel, (int) $g->id ); ?>><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="edu-form-row" style="margin:0; min-width:140px;">
					<label><?php esc_html_e( 'Fecha', 'sistema-educativo' ); ?></label>
					<input type="date" name="edu_att_date" value="<?php echo esc_attr( $att_date_sel ); ?>" onchange="this.form.submit()">
				</div>
				<div class="edu-form-row" style="margin:0; flex:1; min-width:150px;">
					<label><?php esc_html_e( 'Materia (opcional)', 'sistema-educativo' ); ?></label>
					<select name="edu_att_subj" onchange="this.form.submit()">
						<option value="0" <?php selected( $att_subject_sel, 0 ); ?>><?php esc_html_e( '— General —', 'sistema-educativo' ); ?></option>
						<?php foreach ( $att_subjects as $s ) : ?>
							<option value="<?php echo (int) $s->id; ?>" <?php selected( $att_subject_sel, (int) $s->id ); ?>><?php echo esc_html( $s->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" class="edu-btn edu-btn-outline"><?php esc_html_e( 'Actualizar', 'sistema-educativo' ); ?></button>
			</form>
		</div>

		<?php if ( 'saved' === $edu_status ) : ?>
			<div class="edu-alert" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:10px 14px; border-radius:6px; margin-bottom:14px;">
				<?php esc_html_e( 'Asistencia guardada correctamente.', 'sistema-educativo' ); ?>
			</div>
		<?php endif; ?>

		<?php if ( empty( $att_students ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No hay estudiantes activos en este grado.', 'sistema-educativo' ); ?></div>
		<?php else : ?>

		<!-- Formulario de asistencia (POST) -->
		<div class="edu-card" style="padding:0; margin-bottom:20px;">
			<div style="padding:14px 16px; border-bottom:1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:center;">
				<strong style="font-size:14px;">
					<?php echo esc_html( date_i18n( 'l, d \d\e F \d\e Y', strtotime( $att_date_sel ) ) ); ?>
					<?php if ( $att_subject_sel ) : ?>
						— <?php foreach ( $att_subjects as $s ) { if ( (int)$s->id === $att_subject_sel ) echo esc_html( $s->name ); } ?>
					<?php else : ?>
						— <?php esc_html_e( 'General', 'sistema-educativo' ); ?>
					<?php endif; ?>
				</strong>
				<?php
				$ya_tomada = count( $att_current ) > 0;
				if ( $ya_tomada ) : ?>
					<span style="background:#dcfce7; color:#15803d; font-size:11px; padding:2px 8px; border-radius:4px;">
						<?php esc_html_e( 'Ya registrada — editando', 'sistema-educativo' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'edu_save_attendance' ); ?>
				<input type="hidden" name="action"     value="edu_save_attendance">
				<input type="hidden" name="_redirect"  value="<?php echo esc_url( $current_url ); ?>">
				<input type="hidden" name="grade_id"   value="<?php echo (int) $att_grade_sel; ?>">
				<input type="hidden" name="subject_id" value="<?php echo (int) $att_subject_sel; ?>">
				<input type="hidden" name="date"       value="<?php echo esc_attr( $att_date_sel ); ?>">

				<div class="edu-table-wrap">
				<table class="edu-table">
					<thead>
						<tr>
							<th style="min-width:180px;"><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
							<th class="center" style="color:#16a34a;"><?php esc_html_e( 'Presente', 'sistema-educativo' ); ?></th>
							<th class="center" style="color:#d97706;"><?php esc_html_e( 'Atraso', 'sistema-educativo' ); ?></th>
							<th class="center" style="color:#2563eb;"><?php esc_html_e( 'Falta Just.', 'sistema-educativo' ); ?></th>
							<th class="center" style="color:#dc2626;"><?php esc_html_e( 'Falta Injust.', 'sistema-educativo' ); ?></th>
							<th style="min-width:140px;"><?php esc_html_e( 'Justificación', 'sistema-educativo' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $att_students as $ast ) :
						$sid       = (int) $ast->id;
						$cur_att   = $att_current[ $sid ] ?? null;
						$cur_status = $cur_att ? $cur_att->status : 'presente';
						$cur_justif = $cur_att ? $cur_att->justification : '';
					?>
						<tr>
							<td><?php echo esc_html( $ast->last_name . ', ' . $ast->first_name ); ?></td>
							<?php
							$statuses = array( 'presente', 'atraso', 'falta_justificada', 'falta_injustificada' );
							foreach ( $statuses as $sv ) : ?>
								<td class="center">
									<input type="radio"
									       name="attendance[<?php echo (int) $sid; ?>]"
									       value="<?php echo esc_attr( $sv ); ?>"
									       <?php checked( $cur_status, $sv ); ?>
									       style="transform:scale(1.2);">
								</td>
							<?php endforeach; ?>
							<td>
								<input type="text"
								       name="justification[<?php echo (int) $sid; ?>]"
								       value="<?php echo esc_attr( $cur_justif ); ?>"
								       placeholder="<?php esc_attr_e( 'Motivo...', 'sistema-educativo' ); ?>"
								       style="width:100%; padding:4px 6px; border:1px solid #d1d5db; border-radius:4px; font-size:12px;">
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<div style="padding:12px 16px; display:flex; justify-content:flex-end; gap:8px;">
					<button type="submit" class="edu-btn edu-btn-primary">
						&#9989; <?php esc_html_e( 'Guardar asistencia', 'sistema-educativo' ); ?>
					</button>
				</div>
			</form>
		</div>

		<!-- Resumen mensual -->
		<div class="edu-card" style="padding:0;">
			<div style="padding:12px 16px; border-bottom:1px solid #f3f4f6;">
				<strong style="font-size:13px;">
					<?php echo esc_html( date_i18n( 'F Y', strtotime( $att_date_sel ) ) ); ?> —
					<?php
					if ( $att_subject_sel ) {
						$att_subj_name = '';
						foreach ( $att_subjects as $_s ) {
							if ( (int) $_s->id === $att_subject_sel ) {
								$att_subj_name = $_s->name;
								break;
							}
						}
						echo esc_html( sprintf( __( 'Resumen mensual · %s', 'sistema-educativo' ), $att_subj_name ) );
					} else {
						esc_html_e( 'Resumen mensual (general)', 'sistema-educativo' );
					}
					?>
				</strong>
			</div>
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead><tr>
					<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
					<th class="center" style="color:#16a34a;"><?php esc_html_e( 'Presentes', 'sistema-educativo' ); ?></th>
					<th class="center" style="color:#d97706;"><?php esc_html_e( 'Atrasos', 'sistema-educativo' ); ?></th>
					<th class="center" style="color:#2563eb;"><?php esc_html_e( 'Faltas J.', 'sistema-educativo' ); ?></th>
					<th class="center" style="color:#dc2626;"><?php esc_html_e( 'Faltas I.', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( '% Asist.', 'sistema-educativo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $att_students as $ast ) :
					$sid  = (int) $ast->id;
					$ms   = $att_month_idx[ $sid ] ?? array();
					$pres = $ms['presente'] ?? 0;
					$atra = $ms['atraso'] ?? 0;
					$fj   = $ms['falta_justificada'] ?? 0;
					$fi   = $ms['falta_injustificada'] ?? 0;
					$tot  = $pres + $atra + $fj + $fi;
					$pct  = $tot > 0 ? round( ( $pres + $atra ) / $tot * 100 ) : 100;
					$cc   = $pct >= 90 ? '#16a34a' : ( $pct >= 75 ? '#d97706' : '#dc2626' );
				?>
					<tr>
						<td><?php echo esc_html( $ast->last_name . ', ' . $ast->first_name ); ?></td>
						<td class="center"><?php echo $pres; ?></td>
						<td class="center"><?php echo $atra; ?></td>
						<td class="center"><?php echo $fj; ?></td>
						<td class="center"><?php echo $fi; ?></td>
						<td class="center"><strong style="color:<?php echo esc_attr( $cc ); ?>;"><?php echo $pct; ?>%</strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>

		<?php endif; // att_students ?>
		<?php endif; // mis_grados ?>

		<?php /* ═══════════════════ COMUNICADOS ═══════════════════ */ ?>
		<?php elseif ( 'comunicados' === $tab ) : ?>

		<?php $mostrar_form_com = ! empty( $_GET['edu_nuevo_com'] ) || 'error' === $edu_status; ?>
		<div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
			<button type="button" id="btn-nuevo-com" class="edu-btn edu-btn-primary"
			        onclick="eduToggle('form-nuevo-com','btn-nuevo-com','+ <?php echo esc_js( __( 'Nuevo comunicado', 'sistema-educativo' ) ); ?>','× <?php echo esc_js( __( 'Cerrar', 'sistema-educativo' ) ); ?>')">
				<?php echo $mostrar_form_com ? '× ' . esc_html__( 'Cerrar', 'sistema-educativo' ) : '+ ' . esc_html__( 'Nuevo comunicado', 'sistema-educativo' ); ?>
			</button>
		</div>

		<!-- Formulario nuevo comunicado -->
		<div id="form-nuevo-com" class="edu-card" style="margin-bottom:20px;<?php echo $mostrar_form_com ? '' : ' display:none;'; ?>">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Nuevo comunicado', 'sistema-educativo' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'edu_send_announcement' ); ?>
				<input type="hidden" name="action"    value="edu_send_announcement">
				<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">

				<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
					<div class="edu-form-row">
						<label><?php esc_html_e( 'Destinatarios *', 'sistema-educativo' ); ?></label>
						<select name="scope" id="com_scope" onchange="eduComScopeChange(this.value)">
							<option value="grade"><?php esc_html_e( 'Todo el grado', 'sistema-educativo' ); ?></option>
							<option value="student"><?php esc_html_e( 'Estudiante específico', 'sistema-educativo' ); ?></option>
							<?php if ( Edu_Context::can( 'edu_view_all' ) ) : ?>
								<option value="institution"><?php esc_html_e( 'Toda la institución', 'sistema-educativo' ); ?></option>
							<?php endif; ?>
						</select>
					</div>
					<div class="edu-form-row" id="com_grade_wrap">
						<label><?php esc_html_e( 'Grado *', 'sistema-educativo' ); ?></label>
						<select name="target_grade_id" id="com_grade" onchange="eduComGradeChange(this.value)">
							<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $mis_grados as $g ) : ?>
								<option value="<?php echo (int) $g->id; ?>"><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="edu-form-row" id="com_student_wrap" style="display:none;">
						<label><?php esc_html_e( 'Estudiante *', 'sistema-educativo' ); ?></label>
						<select name="target_student_id" id="com_student">
							<option value=""><?php esc_html_e( '— Seleccionar grado primero —', 'sistema-educativo' ); ?></option>
						</select>
					</div>
					<div class="edu-form-row" id="com_subject_wrap" style="display:none;">
						<label><?php esc_html_e( 'Materia (referencia)', 'sistema-educativo' ); ?></label>
						<select name="context_subject_id" id="com_subject_ref" onchange="eduComSubjectChange(this)">
							<option value=""><?php esc_html_e( '— Sin especificar —', 'sistema-educativo' ); ?></option>
						</select>
						<span style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'Opcional — se agrega como prefijo al asunto', 'sistema-educativo' ); ?></span>
					</div>
				</div>

				<div class="edu-form-row">
					<label><?php esc_html_e( 'Asunto / Título *', 'sistema-educativo' ); ?></label>
					<input type="text" name="title" required placeholder="<?php esc_attr_e( 'Ej. Reunión de padres de familia — viernes 24', 'sistema-educativo' ); ?>">
				</div>
				<div class="edu-form-row">
					<label><?php esc_html_e( 'Mensaje *', 'sistema-educativo' ); ?></label>
					<textarea name="body" rows="4" required style="width:100%;" placeholder="<?php esc_attr_e( 'Escribe aquí el mensaje completo...', 'sistema-educativo' ); ?>"></textarea>
				</div>
				<div class="edu-form-row">
					<label style="display:flex; align-items:center; gap:6px; font-weight:400; cursor:pointer;">
						<input type="checkbox" name="send_email" value="1">
						<?php esc_html_e( 'Enviar también por email', 'sistema-educativo' ); ?>
					</label>
				</div>
				<?php if ( Edu_Modules::is_active( 'whatsapp' ) && 'disabled' !== (string) get_option( 'edu_wa_provider', 'disabled' ) ) : ?>
				<div class="edu-form-row">
					<label style="display:flex; align-items:center; gap:6px; font-weight:400; cursor:pointer;">
						<input type="checkbox" name="send_whatsapp" value="1">
						&#128242; <?php esc_html_e( 'Enviar por WhatsApp', 'sistema-educativo' ); ?>
					</label>
				</div>
				<?php endif; ?>

				<div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
					<button type="button" class="edu-btn edu-btn-outline" onclick="eduToggle('form-nuevo-com','btn-nuevo-com','+ <?php echo esc_js( __( 'Nuevo comunicado', 'sistema-educativo' ) ); ?>','× <?php echo esc_js( __( 'Cerrar', 'sistema-educativo' ) ); ?>')">
						<?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?>
					</button>
					<button type="submit" class="edu-btn edu-btn-primary">
						&#128228; <?php esc_html_e( 'Enviar comunicado', 'sistema-educativo' ); ?>
					</button>
				</div>
			</form>
		</div>

		<!-- Lista de comunicados enviados -->
		<?php if ( empty( $comunicados_env ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No has enviado comunicados aún.', 'sistema-educativo' ); ?></div>
		<?php else : ?>
		<div class="edu-card" style="padding:0;">
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead><tr>
					<th><?php esc_html_e( 'Título', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Alcance', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Leídos', 'sistema-educativo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$scope_labels = array(
					'grade'       => __( 'Grado', 'sistema-educativo' ),
					'student'     => __( 'Estudiante', 'sistema-educativo' ),
					'institution' => __( 'Institución', 'sistema-educativo' ),
				);
				foreach ( $comunicados_env as $c ) :
					$pct   = $c->total > 0 ? round( $c->leidos / $c->total * 100 ) : 0;
					$color = $pct >= 80 ? '#16a34a' : ( $pct >= 50 ? '#d97706' : '#dc2626' );
				?>
					<tr>
						<td><?php echo esc_html( $c->title ); ?></td>
						<td style="font-size:12px; color:#6b7280;"><?php echo esc_html( $scope_labels[ $c->scope ] ?? $c->scope ); ?></td>
						<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $c->sent_at ) ) ); ?></td>
						<td class="center" style="color:<?php echo esc_attr( $color ); ?>; font-weight:600;">
							<?php echo (int) $c->leidos; ?>/<?php echo (int) $c->total; ?>
							<?php if ( $c->total > 0 ) : ?>
								<span style="font-size:11px; font-weight:400;">(<?php echo $pct; ?>%)</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php endif; ?>

		<?php /* ═══════════════════ MIS TEXTOS ═══════════════════ */ ?>
		<?php elseif ( 'textos' === $tab ) : ?>

		<div class="edu-section">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo do_shortcode( '[mis_textos]' );
			?>
		</div>

		<?php endif; // tabs ?>
		</main><!-- .edu-content -->
		</div><!-- .edu-layout -->
		</div><!-- .edu-portal-wrap -->

		<?php
		$html = ob_get_clean();
		$html = preg_replace( '/<p[^>]*>\s*<!--.*?-->\s*<\/p>/si', '', $html );
		$html = preg_replace( '/<p[^>]*>\s*<\/p>/i', '', $html );
		return $html;
	}
}
