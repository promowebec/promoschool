<?php
/**
 * Shortcode [edu_portal_rector] — Portal del rector / administrador institucional.
 *
 * Tabs: inicio | rendimiento | alertas | comunicados
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

class Edu_Shortcode_Rector {

	public static function register() {
		add_shortcode( 'edu_portal_rector', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'Debes iniciar sesión para acceder al portal.', 'sistema-educativo' ) .
				'</div></div>';
		}

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
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

		$tab = isset( $_GET['edu_tab'] ) ? sanitize_key( $_GET['edu_tab'] ) : 'inicio';
		$valid_tabs = Edu_Modules::filter_tabs( array( 'inicio', 'rendimiento', 'alertas', 'comunicados', 'cierres', 'auditoria', 'boletines', 'resumen_anual', 'cuentas', 'pagos' ) );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'inicio';
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$today       = date( 'Y-m-d' );
		$month_start = date( 'Y-m-01' );
		$month_end   = date( 'Y-m-t' );

		// ---- Datos comunes ----
		$n_estudiantes = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}students s INNER JOIN {$p}grades g ON g.id = s.grade_id WHERE g.institution_id = %d",
			$institution_id
		) );
		// wp_edu_teachers no tiene institution_id: el vínculo del docente con su
		// institución vive en usermeta.edu_institution_id.
		$n_docentes = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}teachers t
			 INNER JOIN {$wpdb->usermeta} m
			         ON m.user_id = t.user_id
			        AND m.meta_key = 'edu_institution_id'
			        AND m.meta_value = %d",
			$institution_id
		) );
		$n_grados = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}grades WHERE institution_id = %d", $institution_id
		) );

		// Asistencia mes % — contamos pares únicos (student_id, date) para no duplicar registros por materia
		$att_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT CONCAT(a.student_id, '-', a.date))
			 FROM {$p}attendance a
			 INNER JOIN {$p}students s ON s.id = a.student_id
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 WHERE g.institution_id = %d AND a.date BETWEEN %s AND %s",
			$institution_id, $month_start, $month_end
		) );
		$att_pres = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT CONCAT(a.student_id, '-', a.date))
			 FROM {$p}attendance a
			 INNER JOIN {$p}students s ON s.id = a.student_id
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 WHERE g.institution_id = %d AND a.date BETWEEN %s AND %s
			   AND a.status IN ('presente','atraso')",
			$institution_id, $month_start, $month_end
		) );
		$pct_asist = $att_total > 0 ? round( $att_pres / $att_total * 100 ) : null;

		// Promedio general (trimester_scores de todos los trimestres)
		$promedio_inst = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(ts.computed_score)
			 FROM {$p}trimester_scores ts
			 INNER JOIN {$p}students s ON s.id = ts.student_id
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 WHERE g.institution_id = %d AND ts.computed_score IS NOT NULL AND ts.computed_score > 0",
			$institution_id
		) );
		$promedio_inst = $promedio_inst ? round( (float) $promedio_inst, 2 ) : null;

		// Estudiantes en riesgo: trimester_scores con nota < 7
		$en_riesgo = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT ts.student_id)
			 FROM {$p}trimester_scores ts
			 INNER JOIN {$p}students s ON s.id = ts.student_id
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 WHERE g.institution_id = %d AND ts.computed_score < 7 AND ts.computed_score IS NOT NULL",
			$institution_id
		) );

		// Trimestres disponibles para el selector de rendimiento
		$trimestres_list = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id, t.number, p.name AS period_name, p.is_active
			 FROM {$p}trimesters t
			 INNER JOIN {$p}periods p ON p.id = t.period_id
			 WHERE p.institution_id = %d
			 ORDER BY p.is_active DESC, p.start_date DESC, t.number ASC",
			$institution_id
		) );

		$selected_trimester_id = isset( $_GET['edu_trim'] ) ? (int) $_GET['edu_trim'] : 0;
		// Si no se seleccionó trimestre y hay trimestres, preseleccionar el del período activo
		if ( ! $selected_trimester_id && ! empty( $trimestres_list ) ) {
			foreach ( $trimestres_list as $tr_item ) {
				if ( $tr_item->is_active ) {
					$selected_trimester_id = (int) $tr_item->id;
					break;
				}
			}
			if ( ! $selected_trimester_id ) {
				$selected_trimester_id = (int) $trimestres_list[0]->id;
			}
		}

		// Rendimiento por grado — filtrado por trimestre seleccionado
		if ( $selected_trimester_id ) {
			$grados_rendimiento = $wpdb->get_results( $wpdb->prepare(
				"SELECT g.id, g.name, g.paralelo, g.sub_level, AVG(ts.computed_score) AS promedio,
				        COUNT(DISTINCT ts.student_id) AS n_est
				 FROM {$p}grades g
				 LEFT JOIN {$p}students s ON s.grade_id = g.id
				 LEFT JOIN {$p}trimester_scores ts ON ts.student_id = s.id
				        AND ts.trimester_id = %d
				        AND ts.computed_score IS NOT NULL AND ts.computed_score > 0
				 WHERE g.institution_id = %d
				 GROUP BY g.id
				 ORDER BY promedio DESC",
				$selected_trimester_id, $institution_id
			) );
		} else {
			$grados_rendimiento = $wpdb->get_results( $wpdb->prepare(
				"SELECT g.id, g.name, g.paralelo, g.sub_level, AVG(ts.computed_score) AS promedio,
				        COUNT(DISTINCT ts.student_id) AS n_est
				 FROM {$p}grades g
				 LEFT JOIN {$p}students s ON s.grade_id = g.id
				 LEFT JOIN {$p}trimester_scores ts ON ts.student_id = s.id
				        AND ts.computed_score IS NOT NULL AND ts.computed_score > 0
				 WHERE g.institution_id = %d
				 GROUP BY g.id
				 ORDER BY promedio DESC",
				$institution_id
			) );
		}

		// Alertas asistencia baja — contamos pares únicos (student_id, date) por grado
		$alertas_att = $wpdb->get_results( $wpdb->prepare(
			"SELECT g.id, g.name, g.paralelo,
			        COUNT(DISTINCT CASE WHEN a.status IN ('presente','atraso') THEN CONCAT(a.student_id,'-',a.date) END) AS asist,
			        COUNT(DISTINCT CONCAT(a.student_id, '-', a.date)) AS total
			 FROM {$p}attendance a
			 INNER JOIN {$p}students s ON s.id = a.student_id
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 WHERE g.institution_id = %d AND a.date BETWEEN %s AND %s
			 GROUP BY g.id
			 HAVING total > 0",
			$institution_id, $month_start, $month_end
		) );
		$alertas_att_baja = array_filter( $alertas_att, function( $r ) {
			return $r->total > 0 && ( $r->asist / $r->total * 100 ) < 90;
		} );

		// Estudiantes en riesgo detalle
		$riesgo_detalle = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, g.name AS grade_name, g.paralelo, g.sub_level,
			        COALESCE(um_fn.meta_value, '') AS first_name,
			        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
			        AVG(ts.computed_score) AS promedio,
			        (SELECT COUNT(DISTINCT CONCAT(a2.student_id, '-', a2.date))
			                FROM {$p}attendance a2 WHERE a2.student_id = s.id
			                AND a2.status IN ('falta_justificada','falta_injustificada')
			                AND a2.date BETWEEN %s AND %s) AS faltas
			 FROM {$p}trimester_scores ts
			 INNER JOIN {$p}students s ON s.id = ts.student_id
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
			 WHERE g.institution_id = %d AND ts.computed_score IS NOT NULL
			 GROUP BY s.id, g.name, g.paralelo, u.display_name, um_fn.meta_value, um_ln.meta_value
			 HAVING promedio < 7
			 ORDER BY promedio ASC
			 LIMIT 30",
			$month_start, $month_end, $institution_id
		) );

		// Avance de carga de notas por docente (grades_log)
		$avance_docentes = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id, u.display_name, COUNT(DISTINCT gl.component_id) AS componentes_cargados,
			        (SELECT COUNT(*) FROM {$p}grade_components gc2
			                INNER JOIN {$p}grade_subjects gs2 ON gs2.subject_id = gc2.subject_id
			                INNER JOIN {$p}teacher_assignments ta2 ON ta2.subject_id = gs2.subject_id AND ta2.teacher_id = t.id) AS componentes_total
			 FROM {$p}teachers t
			 INNER JOIN {$wpdb->users} u ON u.ID = t.user_id
			 LEFT JOIN {$p}grades_log gl ON gl.registered_by = t.user_id
			 WHERE t.institution_id = %d
			 GROUP BY t.id
			 ORDER BY componentes_cargados DESC
			 LIMIT 20",
			$institution_id
		) );

		// Comunicados enviados — filtramos por institución via:
		// 1) docente remitente (teachers.institution_id),
		// 2) rector/otros usuarios con edu_institution_id en usermeta,
		// 3) grado destino pertenece a la institución.
		$comunicados = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT a.id, a.title, a.sent_at,
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
			       AND um.meta_key = 'edu_institution_id'
			       AND um.meta_value = %d
			   )
			   OR EXISTS (
			     SELECT 1 FROM {$p}grades g
			     WHERE g.id = a.target_grade_id AND g.institution_id = %d
			   )
			 )
			 ORDER BY a.sent_at DESC
			 LIMIT 200",
			$institution_id, $institution_id, $institution_id
		) );

		// ── Datos: Tab Auditoría ─────────────────────────────────────────────
		$audit_rows   = array();
		$audit_total  = 0;
		$audit_ppage  = 30;
		$audit_pnum   = isset( $_GET['audit_p'] ) ? max( 1, (int) $_GET['audit_p'] ) : 1;
		$audit_offset = ( $audit_pnum - 1 ) * $audit_ppage;
		$audit_f_action = isset( $_GET['aud_action'] ) ? sanitize_key( $_GET['aud_action'] ) : '';
		$audit_f_desde  = isset( $_GET['aud_desde'] )  ? sanitize_text_field( wp_unslash( $_GET['aud_desde'] ) ) : '';
		$audit_f_hasta  = isset( $_GET['aud_hasta'] )  ? sanitize_text_field( wp_unslash( $_GET['aud_hasta'] ) ) : '';

		if ( 'auditoria' === $tab ) {
			$ta_t  = $wpdb->prefix . 'edu_audit';
			$where = 'WHERE 1=1';
			$qp    = array();
			if ( $audit_f_action ) { $where .= ' AND a.action = %s'; $qp[] = $audit_f_action; }
			if ( $audit_f_desde )  { $where .= ' AND DATE(a.timestamp) >= %s'; $qp[] = $audit_f_desde; }
			if ( $audit_f_hasta )  { $where .= ' AND DATE(a.timestamp) <= %s'; $qp[] = $audit_f_hasta; }

			$base_sql    = "FROM $ta_t a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id $where";
			$audit_total = (int) ( $qp
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $base_sql", ...$qp ) )
				: $wpdb->get_var( "SELECT COUNT(*) $base_sql" ) );

			$data_sql  = "SELECT a.*, u.display_name AS user_name $base_sql ORDER BY a.timestamp DESC LIMIT %d OFFSET %d";
			$qp_full   = array_merge( $qp, array( $audit_ppage, $audit_offset ) );
			$audit_rows = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$qp_full ) );

			$audit_actions_list = $wpdb->get_col( "SELECT DISTINCT action FROM $ta_t ORDER BY action" );
		} else {
			$audit_actions_list = array();
		}

		// Grados de la institución (reutilizado en varios tabs).
		$all_grades = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, paralelo FROM {$p}grades WHERE institution_id = %d ORDER BY name, paralelo",
			$institution_id
		) );

		// ── Datos: Tab Cierres / Recuperación ───────────────────────────────
		$cie_grade_id     = isset( $_GET['edu_grade'] )  ? (int) $_GET['edu_grade']  : 0;
		$cie_subject_id   = isset( $_GET['edu_subj'] )   ? (int) $_GET['edu_subj']   : 0;
		$cie_trimester_id = isset( $_GET['edu_trim'] )   ? (int) $_GET['edu_trim']   : 0;
		$cie_period_id    = isset( $_GET['edu_period'] ) ? (int) $_GET['edu_period'] : 0;
		$cie_status       = isset( $_GET['edu_status'] ) ? sanitize_key( $_GET['edu_status'] ) : '';
		$cie_code         = isset( $_GET['edu_code'] )   ? sanitize_key( $_GET['edu_code'] )   : '';

		$cie_periods = array();
		$cie_grades  = array();
		$cie_subjects = array();
		$cie_trimesters = array();
		$cie_students   = array();
		$cie_parcial_data = array();
		$cie_trim_data    = array();
		$cie_year_data    = array();
		$cie_sub_level    = '';

		if ( 'cierres' === $tab ) {
			$cie_periods = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, is_active FROM {$p}periods WHERE institution_id = %d ORDER BY start_date DESC",
				$institution_id
			) );
			$cie_grades = $all_grades;
			if ( $cie_grade_id ) {
				$cie_sub_level = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT sub_level FROM {$p}grades WHERE id = %d", $cie_grade_id
				) );
				$cie_subjects = $wpdb->get_results( $wpdb->prepare(
					"SELECT s.id, s.name FROM {$p}subjects s
					 INNER JOIN {$p}grade_subjects gs ON gs.subject_id = s.id
					 WHERE gs.grade_id = %d ORDER BY s.name",
					$cie_grade_id
				) );
				$cie_students = $wpdb->get_results( $wpdb->prepare(
					"SELECT s.id, COALESCE(um_fn.meta_value,'') AS first_name,
					        COALESCE(um_ln.meta_value, u.display_name) AS last_name
					 FROM {$p}students s
					 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
					 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
					 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
					 WHERE s.grade_id = %d AND s.status = 'active'
					 ORDER BY last_name, first_name",
					$cie_grade_id
				) );
			}
			if ( $cie_period_id ) {
				$cie_trimesters = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, number, name FROM {$p}trimesters WHERE period_id = %d ORDER BY number",
					$cie_period_id
				) );
			}
			if ( $cie_grade_id && $cie_subject_id && $cie_trimester_id && ! empty( $cie_students ) ) {
				$_sids_in = implode( ',', array_map( 'intval', array_column( (array) $cie_students, 'id' ) ) );
				$ps_rows  = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, parcial_num, computed_score, is_closed
					 FROM {$p}parcial_scores
					 WHERE student_id IN ($_sids_in) AND subject_id = %d AND trimester_id = %d",
					$cie_subject_id, $cie_trimester_id
				) );
				foreach ( $ps_rows as $r ) {
					$cie_parcial_data[ (int) $r->student_id ][ (int) $r->parcial_num ] = $r;
				}
				$ts_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, final_exam_score, computed_score AS trim_score, is_closed
					 FROM {$p}trimester_scores
					 WHERE student_id IN ($_sids_in) AND subject_id = %d AND trimester_id = %d",
					$cie_subject_id, $cie_trimester_id
				) );
				foreach ( $ts_rows as $r ) {
					$cie_trim_data[ (int) $r->student_id ] = $r;
				}
			}
			if ( $cie_grade_id && $cie_subject_id && $cie_period_id && ! empty( $cie_students ) ) {
				$_sids_in2 = implode( ',', array_map( 'intval', array_column( (array) $cie_students, 'id' ) ) );
				$yr_rows   = $wpdb->get_results( $wpdb->prepare(
					"SELECT student_id, average, supletorio_score, remedial_score, gracia_score, status
					 FROM {$p}year_scores
					 WHERE student_id IN ($_sids_in2) AND subject_id = %d AND period_id = %d",
					$cie_subject_id, $cie_period_id
				) );
				foreach ( $yr_rows as $r ) {
					$cie_year_data[ (int) $r->student_id ] = $r;
				}
			}
		}

		// ── Mejoras activas (allow_recovery = 1, tarea cerrada) ─────────────────
		$mejoras_abiertas = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.id, a.title, a.recovery_due_date,
			        g.name AS grade_name, g.paralelo,
			        s.name AS subject_name,
			        (SELECT COUNT(*) FROM {$p}submissions sb WHERE sb.assignment_id = a.id AND sb.recovery_status = 'submitted') AS pendientes,
			        (SELECT COUNT(*) FROM {$p}submissions sb WHERE sb.assignment_id = a.id AND sb.recovery_status = 'graded')    AS calificadas
			 FROM {$p}assignments a
			 INNER JOIN {$p}grades g ON g.id = a.grade_id
			 INNER JOIN {$p}subjects s ON s.id = a.subject_id
			 WHERE g.institution_id = %d AND a.status = 'closed' AND a.allow_recovery = 1
			 ORDER BY a.recovery_due_date ASC, a.title",
			$institution_id
		) );
		$n_mejoras_pendientes = (int) array_sum( array_column( (array) $mejoras_abiertas, 'pendientes' ) );

		$user        = wp_get_current_user();
		$initials    = strtoupper( mb_substr( $user->display_name, 0, 2 ) );
		$current_url = get_permalink();

		// Institución, período y saludo
		$inst_name_r   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$p}institutions WHERE id = %d", $institution_id ) );
		$period_row_r  = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$p}periods WHERE institution_id = %d AND is_active = 1 LIMIT 1", $institution_id ) );
		$period_name_r = $period_row_r ? $period_row_r->name : '';
		$hour_r        = (int) date( 'G' );
		$saludo_r      = $hour_r < 12 ? 'Buenos días' : ( $hour_r < 19 ? 'Buenas tardes' : 'Buenas noches' );
		$first_name_r  = trim( $user->first_name ?: explode( ' ', $user->display_name )[0] );
		$page_urls_r   = array(
			'docente'    => ( $pid = (int) get_option( 'edu_page_docente',     0 ) ) ? get_permalink( $pid ) : '',
			'padre'      => ( $pid = (int) get_option( 'edu_page_padre',       0 ) ) ? get_permalink( $pid ) : '',
			'estudiante' => ( $pid = (int) get_option( 'edu_page_estudiante',  0 ) ) ? get_permalink( $pid ) : '',
			'rector'     => ( $pid = (int) get_option( 'edu_page_rector',      0 ) ) ? get_permalink( $pid ) : '',
		);
		$alertas_total_r = (int) $en_riesgo + count( $alertas_att_baja ) + $n_mejoras_pendientes;

		// ---- Datos para tab Boletines ----
		$bol_periods    = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, is_active FROM {$p}periods WHERE institution_id = %d ORDER BY start_date DESC",
			$institution_id
		) );
		$bol_grades     = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, paralelo FROM {$p}grades WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
			$institution_id
		) );
		$bol_period_sel = isset( $_GET['bol_period'] ) ? (int) $_GET['bol_period'] : 0;
		$bol_grade_sel  = isset( $_GET['bol_grade'] )  ? (int) $_GET['bol_grade']  : 0;
		if ( ! $bol_period_sel ) {
			foreach ( $bol_periods as $bper ) {
				if ( $bper->is_active ) { $bol_period_sel = (int) $bper->id; break; }
			}
		}
		$bol_students = array();
		if ( $bol_grade_sel && $bol_period_sel ) {
			$bol_students = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id,
				        COALESCE(um_fn.meta_value,'') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
				        (SELECT COUNT(DISTINCT ys.subject_id)
				           FROM {$p}year_scores ys WHERE ys.student_id = s.id AND ys.period_id = %d) AS materias_con_nota,
				        (SELECT COUNT(DISTINCT ys2.subject_id)
				           FROM {$p}year_scores ys2 WHERE ys2.student_id = s.id AND ys2.period_id = %d AND ys2.status = 'aprobado') AS materias_aprobadas,
				        (SELECT ys3.status FROM {$p}year_scores ys3
				           WHERE ys3.student_id = s.id AND ys3.period_id = %d
				           ORDER BY FIELD(ys3.status,'reprobado','gracia','remedial','supletorio','en_curso','aprobado') ASC
				           LIMIT 1) AS estado_global
				 FROM {$p}students s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE s.grade_id = %d ORDER BY last_name, first_name",
				$bol_period_sel, $bol_period_sel, $bol_period_sel, $bol_grade_sel
			) );
		}
		$bol_mpdf_ok = file_exists( EDU_PLUGIN_DIR . 'vendor/autoload.php' );

		// ---- Datos para tab Resumen Anual ----
		// Parámetros: usa ra_* en navegación normal; edu_* viene del redirect del controlador.
		$ra_grade_sel   = isset( $_GET['ra_grade'] )   ? (int) $_GET['ra_grade']   : ( isset( $_GET['edu_grade'] ) ? (int) $_GET['edu_grade'] : 0 );
		$ra_subject_sel = isset( $_GET['ra_subject'] ) ? (int) $_GET['ra_subject'] : ( isset( $_GET['edu_subj'] )  ? (int) $_GET['edu_subj']  : 0 );
		$ra_period_sel  = isset( $_GET['ra_period'] )  ? (int) $_GET['ra_period']  : ( isset( $_GET['edu_period'] )? (int) $_GET['edu_period']: 0 );
		$ra_status_msg  = isset( $_GET['edu_status'] ) ? sanitize_key( $_GET['edu_status'] ) : '';
		if ( ! $ra_period_sel ) {
			foreach ( $bol_periods as $bper ) {
				if ( $bper->is_active ) { $ra_period_sel = (int) $bper->id; break; }
			}
		}
		$ra_subjects = array();
		if ( $ra_grade_sel ) {
			$ra_subjects = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id, s.name, s.code FROM {$p}grade_subjects gs
				 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
				 WHERE gs.grade_id = %d ORDER BY s.name",
				$ra_grade_sel
			) );
		}
		$ra_students        = array();
		$ra_year_rows       = array();
		$ra_grade_sub_level = '';
		$ra_has_recovery    = false;
		if ( $ra_grade_sel && $ra_subject_sel && $ra_period_sel ) {
			$ra_grade_sub_level = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT sub_level FROM {$p}grades WHERE id = %d", $ra_grade_sel
			) );
			$ra_students = $wpdb->get_results( $wpdb->prepare(
				"SELECT st.id, st.user_id, u.display_name,
				        COALESCE(um_fn.meta_value,'') AS first_name,
				        COALESCE(um_ln.meta_value,'') AS last_name,
				        st.cedula
				 FROM {$p}students st
				 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = st.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = st.user_id AND um_ln.meta_key = 'last_name'
				 WHERE st.grade_id = %d AND st.status = 'active'
				 ORDER BY last_name, first_name, u.display_name",
				$ra_grade_sel
			) );
			if ( $ra_students ) {
				$ra_sids = implode( ',', array_map( 'intval', wp_list_pluck( $ra_students, 'id' ) ) );
				$ra_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$p}year_scores
					 WHERE subject_id = %d AND period_id = %d AND student_id IN ($ra_sids)",
					$ra_subject_sel, $ra_period_sel
				) );
				foreach ( $ra_rows as $r ) {
					$ra_year_rows[ (int) $r->student_id ] = $r;
				}
				foreach ( $ra_year_rows as $r ) {
					if ( in_array( $r->status, array( 'supletorio', 'remedial', 'gracia' ), true ) ) {
						$ra_has_recovery = true; break;
					}
				}
			}
		}
		$ra_recovery_field_map = array(
			'supletorio' => 'supletorio_score',
			'remedial'   => 'remedial_score',
			'gracia'     => 'gracia_score',
		);
		$ra_status_labels = array(
			'en_curso'   => array( 'label' => __( 'En curso',   'sistema-educativo' ), 'bg' => '#dbeafe', 'tx' => '#1e40af' ),
			'aprobado'   => array( 'label' => __( 'Aprobado',   'sistema-educativo' ), 'bg' => '#dcfce7', 'tx' => '#15803d' ),
			'supletorio' => array( 'label' => __( 'Supletorio', 'sistema-educativo' ), 'bg' => '#fef9c3', 'tx' => '#854d0e' ),
			'remedial'   => array( 'label' => __( 'Remedial',   'sistema-educativo' ), 'bg' => '#ffedd5', 'tx' => '#9a3412' ),
			'gracia'     => array( 'label' => __( 'Gracia',     'sistema-educativo' ), 'bg' => '#fee2e2', 'tx' => '#9a3412' ),
			'reprobado'  => array( 'label' => __( 'Reprobado',  'sistema-educativo' ), 'bg' => '#fee2e2', 'tx' => '#b91c1c' ),
		);
		$bol_nonce   = wp_create_nonce( 'edu_download_boletin' );
		$bol_base_dl = admin_url( 'admin-post.php' );
		$bol_status_labels = array(
			'en_curso'   => __( 'En curso',   'sistema-educativo' ),
			'aprobado'   => __( 'Aprobado',   'sistema-educativo' ),
			'supletorio' => __( 'Supletorio', 'sistema-educativo' ),
			'remedial'   => __( 'Remedial',   'sistema-educativo' ),
			'gracia'     => __( 'Gracia',     'sistema-educativo' ),
			'reprobado'  => __( 'Reprobado',  'sistema-educativo' ),
		);
		$bol_status_colors = array(
			'aprobado'   => '#dcfce7|#15803d',
			'supletorio' => '#fef9c3|#854d0e',
			'remedial'   => '#ffedd5|#9a3412',
			'gracia'     => '#fee2e2|#9a3412',
			'reprobado'  => '#fee2e2|#b91c1c',
			'en_curso'   => '#dbeafe|#1e40af',
		);

		// ---- Datos para tab Cuentas ----
		$cta_role_filter   = isset( $_GET['cta_role'] )   ? sanitize_key( $_GET['cta_role'] )   : 'all';
		$cta_grade_filter  = isset( $_GET['cta_grade'] )  ? (int) $_GET['cta_grade']            : 0;
		$cta_status_filter = isset( $_GET['cta_status'] ) ? sanitize_key( $_GET['cta_status'] ) : 'all';
		$cta_action_status = isset( $_GET['cta_ok'] )     ? sanitize_key( $_GET['cta_ok'] )     : '';
		$cta_changed       = isset( $_GET['cta_changed'] )? (int) $_GET['cta_changed']          : 0;

		$cta_users = array();
		if ( 'all' === $cta_role_filter || 'estudiante' === $cta_role_filter ) {
			$cta_where_grade = $cta_grade_filter ? $wpdb->prepare( ' AND g.id = %d', $cta_grade_filter ) : '';
			$cta_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT u.ID AS user_id, u.user_email,
				        COALESCE(um_fn.meta_value,'') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
				        COALESCE(um_st.meta_value,'active') AS account_status,
				        'estudiante' AS rol,
				        g.name AS grade_name, g.paralelo
				 FROM {$p}students s
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 INNER JOIN {$p}grades g ON g.id = s.grade_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = u.ID AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = u.ID AND um_ln.meta_key = 'last_name'
				 LEFT JOIN {$wpdb->usermeta} um_st ON um_st.user_id = u.ID AND um_st.meta_key = 'edu_account_status'
				 WHERE g.institution_id = %d $cta_where_grade
				 ORDER BY last_name, first_name",
				$institution_id
			) );
			$cta_users = array_merge( $cta_users, (array) $cta_rows );
		}
		if ( 'all' === $cta_role_filter || 'padre' === $cta_role_filter ) {
			$cta_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT u.ID AS user_id, u.user_email,
				        COALESCE(um_fn.meta_value,'') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
				        COALESCE(um_st.meta_value,'active') AS account_status,
				        'padre' AS rol, NULL AS grade_name, NULL AS paralelo
				 FROM {$p}parents par
				 INNER JOIN {$wpdb->users} u ON u.ID = par.user_id
				 INNER JOIN {$p}parent_student ps ON ps.parent_id = par.id
				 INNER JOIN {$p}students st ON st.id = ps.student_id
				 INNER JOIN {$p}grades g ON g.id = st.grade_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = u.ID AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = u.ID AND um_ln.meta_key = 'last_name'
				 LEFT JOIN {$wpdb->usermeta} um_st ON um_st.user_id = u.ID AND um_st.meta_key = 'edu_account_status'
				 WHERE g.institution_id = %d
				 ORDER BY last_name, first_name",
				$institution_id
			) );
			$cta_users = array_merge( $cta_users, (array) $cta_rows );
		}
		if ( 'all' !== $cta_status_filter ) {
			$cta_users = array_values( array_filter( $cta_users, function( $u ) use ( $cta_status_filter ) {
				return $u->account_status === $cta_status_filter;
			} ) );
		}
		$cta_n_suspendidos = count( array_filter( $cta_users, fn( $u ) => 'suspended' === $u->account_status ) );
		$cta_redirect_base = get_permalink();

		// ---- Datos para tab Pagos ----
		$pagos_periodos   = $pagos_period_id   = 0;
		$pagos_list       = $pagos_configs     = $pagos_grades = array();
		$n_pagos_pend_r   = 0;
		$pagos_sub        = isset( $_GET['pago_sub'] )    ? sanitize_key( $_GET['pago_sub'] )    : 'dashboard';
		$pagos_grade_f    = isset( $_GET['pago_grade'] )  ? (int) $_GET['pago_grade']             : 0;
		$pagos_status_f   = isset( $_GET['pago_status'] ) ? sanitize_key( $_GET['pago_status'] )  : 'all';
		$pagos_month_f    = isset( $_GET['pago_month'] )  ? sanitize_text_field( wp_unslash( $_GET['pago_month'] ) ) : '';
		$pagos_status_msg = isset( $_GET['status'] )      ? sanitize_key( $_GET['status'] )       : '';
		$pagos_changed    = isset( $_GET['changed'] )     ? (int) $_GET['changed']                : 0;
		$pagos_pay_link   = isset( $_GET['pay_link'] )    ? esc_url( rawurldecode( wp_unslash( $_GET['pay_link'] ) ) ) : '';
		$pagos_ph_test    = isset( $_GET['ph_test'] )     ? sanitize_key( $_GET['ph_test'] )      : '';
		$pagos_ph_msg     = isset( $_GET['ph_test_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ph_test_msg'] ) ) ) : '';

		$pagos_periodos = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, is_active FROM {$p}periods WHERE institution_id = %d ORDER BY is_active DESC, start_date DESC",
			$institution_id
		) );
		$pagos_period_id_raw = isset( $_GET['pago_period'] ) ? (int) $_GET['pago_period'] : 0;
		if ( ! $pagos_period_id_raw ) {
			foreach ( $pagos_periodos as $_per ) {
				if ( $_per->is_active ) { $pagos_period_id_raw = (int) $_per->id; break; }
			}
			if ( ! $pagos_period_id_raw && ! empty( $pagos_periodos ) ) {
				$pagos_period_id_raw = (int) $pagos_periodos[0]->id;
			}
		}
		$pagos_period_id = $pagos_period_id_raw;

		$pagos_grades = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, paralelo FROM {$p}grades WHERE institution_id = %d ORDER BY name, paralelo",
			$institution_id
		) );

		// Badge sidenav: pagos pendientes/vencidos.
		if ( $pagos_period_id ) {
			$n_pagos_pend_r = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}payments pay
				 INNER JOIN {$p}students st ON st.id = pay.student_id
				 INNER JOIN {$p}grades g ON g.id = st.grade_id
				 WHERE pay.period_id = %d AND g.institution_id = %d AND pay.status IN ('pending','overdue')",
				$pagos_period_id, $institution_id
			) );
		}

		// Cargar datos específicos del sub-tab activo.
		if ( 'pagos' === $tab && $pagos_period_id ) {
			if ( 'config' === $pagos_sub ) {
				$cfg_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$p}payment_config WHERE institution_id = %d AND period_id = %d ORDER BY grade_id ASC",
					$institution_id, $pagos_period_id
				) );
				foreach ( $cfg_rows as $_c ) {
					$_key = null === $_c->grade_id ? 'all' : (int) $_c->grade_id;
					$pagos_configs[ $_key ] = $_c;
				}
			} else {
				$_pw_grade  = $pagos_grade_f  ? $wpdb->prepare( ' AND g.id = %d', $pagos_grade_f )  : '';
				$_pw_status = ( 'all' !== $pagos_status_f && $pagos_status_f ) ? $wpdb->prepare( ' AND pay.status = %s', $pagos_status_f ) : '';
				$_pw_month  = $pagos_month_f ? $wpdb->prepare( ' AND pay.month = %s', $pagos_month_f ) : '';
				$pagos_list = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT pay.id, pay.month, pay.amount, pay.due_date, pay.status, pay.paid_at,
					        pay.payment_method, pay.payment_ref, pay.payphone_transaction_id,
					        COALESCE(um_fn.meta_value,'') AS first_name,
					        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
					        g.name AS grade_name, g.paralelo
					 FROM {$p}payments pay
					 INNER JOIN {$p}students st ON st.id = pay.student_id
					 INNER JOIN {$p}grades g ON g.id = st.grade_id
					 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
					 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = u.ID AND um_fn.meta_key = 'first_name'
					 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = u.ID AND um_ln.meta_key = 'last_name'
					 WHERE pay.period_id = %d AND g.institution_id = %d $_pw_grade $_pw_status $_pw_month
					 ORDER BY last_name, first_name, pay.month",
					$pagos_period_id, $institution_id
				) );
			}
		}
		$pagos_ph_token    = get_option( 'edu_payphone_token', '' );
		$pagos_ph_store_id = get_option( 'edu_payphone_store_id', '' );
		$pagos_meses_disp  = array();
		if ( $pagos_period_id ) {
			$pagos_meses_disp = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT pay.month FROM {$p}payments pay
				 INNER JOIN {$p}students st ON st.id = pay.student_id
				 INNER JOIN {$p}grades g ON g.id = st.grade_id
				 WHERE pay.period_id = %d AND g.institution_id = %d ORDER BY pay.month",
				$pagos_period_id, $institution_id
			) );
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
					<div class="edu-topbar-sub"><?php echo esc_html( $inst_name_r ); ?><?php echo $period_name_r ? ' · ' . esc_html( $period_name_r ) : ''; ?></div>
				</div>
			</div>
			<div class="edu-topbar-roles">
				<?php
				$roles_r = array(
					'docente'    => array( 'icon' => '&#128202;', 'label' => __( 'Docente',    'sistema-educativo' ) ),
					'padre'      => array( 'icon' => '&#128106;', 'label' => __( 'Padre',      'sistema-educativo' ) ),
					'estudiante' => array( 'icon' => '&#127891;', 'label' => __( 'Estudiante', 'sistema-educativo' ) ),
					'rector'     => array( 'icon' => '&#127894;', 'label' => __( 'Rector',     'sistema-educativo' ) ),
				);
				foreach ( $roles_r as $rk => $rd ) :
					$ru = $page_urls_r[ $rk ] ?? '';
					if ( ! $ru ) continue;
				?>
				<a href="<?php echo esc_url( $ru ); ?>" class="edu-role-btn<?php echo 'rector' === $rk ? ' active' : ''; ?>">
					<?php echo $rd['icon']; ?> <?php echo esc_html( $rd['label'] ); ?>
				</a>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- ── Layout ── -->
		<div class="edu-layout">

		<!-- ── Sidebar ── -->
		<aside class="edu-sidebar">
			<div class="edu-sidebar-card">
				<div class="edu-avatar" style="background:#4f46e5;"><?php echo esc_html( $initials ); ?></div>
				<div class="edu-user-name"><?php echo esc_html( $user->display_name ); ?></div>
				<div class="edu-user-role"><?php esc_html_e( 'Rector', 'sistema-educativo' ); ?></div>
				<div class="edu-sidenav-sep"></div>
				<div class="edu-sidenav-section"><?php esc_html_e( 'RECTORADO', 'sistema-educativo' ); ?></div>
				<nav class="edu-sidenav">
					<?php
					$sidenav_r = array(
						'inicio'      => array( 'icon' => '&#127968;', 'label' => __( 'Inicio',       'sistema-educativo' ), 'badge' => 0 ),
						'rendimiento' => array( 'icon' => '&#128202;', 'label' => __( 'Rendimiento',  'sistema-educativo' ), 'badge' => 0 ),
						'alertas'     => array( 'icon' => '&#128680;', 'label' => __( 'Alertas',      'sistema-educativo' ), 'badge' => $alertas_total_r ),
						'comunicados' => array( 'icon' => '&#128226;', 'label' => __( 'Comunicados',  'sistema-educativo' ), 'badge' => 0 ),
						'cierres'     => array( 'icon' => '&#128274;', 'label' => __( 'Cierres',      'sistema-educativo' ), 'badge' => 0 ),
						'auditoria'   => array( 'icon' => '&#128196;', 'label' => __( 'Auditoría',    'sistema-educativo' ), 'badge' => 0 ),
						'boletines'    => array( 'icon' => '&#128209;', 'label' => __( 'Boletines',      'sistema-educativo' ), 'badge' => 0 ),
						'resumen_anual' => array( 'icon' => '&#128200;', 'label' => __( 'Resumen anual',  'sistema-educativo' ), 'badge' => 0 ),
						'cuentas'      => array( 'icon' => '&#128274;', 'label' => __( 'Cuentas',        'sistema-educativo' ), 'badge' => $cta_n_suspendidos ),
							'pagos'        => array( 'icon' => '&#128179;', 'label' => __( 'Pagos',           'sistema-educativo' ), 'badge' => $n_pagos_pend_r ),
					);
					$sidenav_r = Edu_Modules::filter_sidenav( $sidenav_r );
					foreach ( $sidenav_r as $sk => $sd ) :
					?>
					<a href="<?php echo esc_url( add_query_arg( 'edu_tab', $sk, $current_url ) ); ?>"
					   class="<?php echo $tab === $sk ? 'active' : ''; ?>">
						<span class="edu-nav-icon"><?php echo $sd['icon']; ?></span>
						<?php echo esc_html( $sd['label'] ); ?>
						<?php if ( $sd['badge'] > 0 ) : ?>
							<span class="edu-badge"><?php echo (int) $sd['badge']; ?></span>
						<?php endif; ?>
					</a>
					<?php endforeach; ?>
				</nav>
			</div>
		</aside>

		<!-- ── Contenido principal ── -->
		<main class="edu-content">
		<?php
		$titles_r = array(
			'inicio'      => $saludo_r . ', ' . $first_name_r . ' 👋',
			'rendimiento' => __( 'Rendimiento académico', 'sistema-educativo' ),
			'alertas'     => __( 'Alertas',               'sistema-educativo' ),
			'comunicados' => __( 'Comunicados',           'sistema-educativo' ),
			'cierres'     => __( 'Cierres y recuperación','sistema-educativo' ),
			'auditoria'   => __( 'Auditoría',             'sistema-educativo' ),
			'boletines'    => __( 'Boletines',             'sistema-educativo' ),
			'resumen_anual' => __( 'Resumen anual',       'sistema-educativo' ),
			'cuentas'      => __( 'Gestión de cuentas',  'sistema-educativo' ),
			'pagos'        => __( 'Pagos',                'sistema-educativo' ),
		);
		?>
		<div class="edu-content-header">
			<h1><?php echo esc_html( $titles_r[ $tab ] ?? '' ); ?></h1>
			<?php if ( 'inicio' === $tab && $period_name_r ) : ?>
			<p><?php echo esc_html( $inst_name_r . ' · ' . $period_name_r ); ?></p>
			<?php endif; ?>
		</div>

		<?php /* ===================== INICIO ===================== */ ?>
		<?php if ( 'inicio' === $tab ) : ?>

		<div class="edu-stats-row">
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Estudiantes', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#1d4ed8;"><?php echo esc_html( $n_estudiantes ); ?></div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Docentes', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#16a34a;"><?php echo esc_html( $n_docentes ); ?></div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Asistencia mes', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:<?php echo null !== $pct_asist ? ( $pct_asist >= 90 ? '#16a34a' : ( $pct_asist >= 75 ? '#d97706' : '#dc2626' ) ) : '#9ca3af'; ?>;">
					<?php echo null !== $pct_asist ? esc_html( $pct_asist . '%' ) : '—'; ?>
				</div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Promedio general', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#7c3aed;"><?php echo null !== $promedio_inst ? esc_html( $promedio_inst ) : '—'; ?></div>
			</div>
		</div>

		<?php if ( ! empty( $alertas_att_baja ) ) : ?>
		<div class="edu-alert edu-alert-yellow">
			<strong><?php esc_html_e( 'Asistencia baja este mes (< 90%):', 'sistema-educativo' ); ?></strong>
			<?php foreach ( $alertas_att_baja as $a ) :
				$pct = round( $a->asist / $a->total * 100 );
			?>
				<span style="margin-left:8px; background:#fef3c7; padding:2px 8px; border-radius:4px;">
					<?php echo esc_html( $a->name . ' ' . $a->paralelo . ' · ' . $pct . '%' ); ?>
				</span>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<div class="edu-grid-2">
			<!-- Rendimiento por grado -->
			<div class="edu-card">
				<h3><?php esc_html_e( 'Rendimiento por grado', 'sistema-educativo' ); ?></h3>
				<?php if ( empty( $grados_rendimiento ) ) : ?>
					<p style="color:#9ca3af; font-size:13px;"><?php esc_html_e( 'Sin datos de calificaciones aún.', 'sistema-educativo' ); ?></p>
				<?php else : ?>
				<?php foreach ( $grados_rendimiento as $gr ) :
					$prom = $gr->promedio ? round( (float) $gr->promedio, 1 ) : null;
					$pct_bar = $prom ? min( 100, (int) ( $prom * 10 ) ) : 0;
					$color = $prom ? ( $prom >= 8 ? '#16a34a' : ( $prom >= 7 ? '#d97706' : '#dc2626' ) ) : '#e5e7eb';
				?>
				<div class="edu-progress-row">
					<div class="edu-pr-label">
						<span><?php echo esc_html( $gr->name . ' ' . $gr->paralelo ); ?></span>
						<strong style="color:<?php echo esc_attr( $color ); ?>;"><?php echo $prom ? esc_html( $prom ) : '—'; ?></strong>
					</div>
					<div class="edu-progress-bar">
						<div class="edu-progress-fill" style="width:<?php echo $pct_bar; ?>%; background:<?php echo esc_attr( $color ); ?>;"></div>
					</div>
				</div>
				<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<!-- Alertas activas -->
			<div class="edu-card">
				<h3>&#9888; <?php esc_html_e( 'Alertas activas', 'sistema-educativo' ); ?></h3>
				<?php if ( $en_riesgo === 0 && empty( $alertas_att_baja ) ) : ?>
					<p style="color:#16a34a; font-size:13px;">&#10003; <?php esc_html_e( 'Sin alertas activas.', 'sistema-educativo' ); ?></p>
				<?php else : ?>
				<ul class="edu-list">
					<?php if ( $en_riesgo > 0 ) : ?>
					<li>
						<strong style="color:#dc2626;"><?php echo esc_html( $en_riesgo ); ?> <?php esc_html_e( 'estudiantes en riesgo', 'sistema-educativo' ); ?></strong>
						<div class="edu-list-meta"><?php esc_html_e( 'Nota de trimestre < 7', 'sistema-educativo' ); ?></div>
					</li>
					<?php endif; ?>
					<?php foreach ( $alertas_att_baja as $a ) :
						$pct = round( $a->asist / $a->total * 100 );
					?>
					<li>
						<strong><?php esc_html_e( 'Asistencia baja ·', 'sistema-educativo' ); ?> <?php echo esc_html( $a->name . ' ' . $a->paralelo ); ?></strong>
						<div class="edu-list-meta"><?php echo esc_html( $pct . '%' ); ?> <?php esc_html_e( 'promedio mensual', 'sistema-educativo' ); ?></div>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'alertas', $current_url ) ); ?>" class="edu-btn edu-btn-outline edu-btn-sm" style="margin-top:12px;">
					<?php esc_html_e( 'Ver detalle', 'sistema-educativo' ); ?>
				</a>
			</div>
		</div>

		<!-- Carga de notas por docente -->
		<?php if ( ! empty( $avance_docentes ) ) : ?>
		<div class="edu-card">
			<h3><?php esc_html_e( 'Carga de notas por docente', 'sistema-educativo' ); ?></h3>
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Docente', 'sistema-educativo' ); ?></th>
						<th><?php esc_html_e( 'Componentes cargados', 'sistema-educativo' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $avance_docentes as $d ) : ?>
					<tr>
						<td><?php echo esc_html( $d->display_name ); ?></td>
						<td class="center"><?php echo (int) $d->componentes_cargados; ?></td>
						<td>
							<?php if ( (int) $d->componentes_cargados > 0 ) : ?>
								<span class="edu-badge-status edu-badge-green">&#10003; <?php esc_html_e( 'Activo', 'sistema-educativo' ); ?></span>
							<?php else : ?>
								<span class="edu-badge-status edu-badge-yellow"><?php esc_html_e( 'Sin notas', 'sistema-educativo' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php endif; ?>

		<!-- Mejoras activas -->
		<?php if ( ! empty( $mejoras_abiertas ) ) : ?>
		<div class="edu-card">
			<h3>&#9650; <?php esc_html_e( 'Mejoras activas', 'sistema-educativo' ); ?>
				<?php if ( $n_mejoras_pendientes > 0 ) : ?>
					<span style="margin-left:8px; font-size:12px; font-weight:600; background:#fef3c7; color:#92400e; padding:2px 10px; border-radius:99px;">
						<?php echo (int) $n_mejoras_pendientes; ?> <?php esc_html_e( 'pendientes de calificar', 'sistema-educativo' ); ?>
					</span>
				<?php endif; ?>
			</h3>
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead><tr>
					<th><?php esc_html_e( 'Tarea', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Fecha límite', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Pendientes', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Calificadas', 'sistema-educativo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $mejoras_abiertas as $m ) :
					$vencida = $m->recovery_due_date && strtotime( $m->recovery_due_date ) < time();
				?>
					<tr>
						<td><?php echo esc_html( $m->title ); ?></td>
						<td><?php echo esc_html( $m->grade_name . ' ' . $m->paralelo ); ?></td>
						<td><?php echo esc_html( $m->subject_name ); ?></td>
						<td style="<?php echo $vencida ? 'color:#dc2626;' : ''; ?>">
							<?php echo $m->recovery_due_date ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $m->recovery_due_date ) ) ) : '—'; ?>
							<?php if ( $vencida ) : ?><br><small><?php esc_html_e( 'Vencida', 'sistema-educativo' ); ?></small><?php endif; ?>
						</td>
						<td class="center">
							<?php if ( (int) $m->pendientes > 0 ) : ?>
								<span style="background:#fef3c7; color:#92400e; font-weight:700; padding:2px 10px; border-radius:99px; font-size:12px;">
									<?php echo (int) $m->pendientes; ?>
								</span>
							<?php else : ?>
								<span style="color:#9ca3af;">0</span>
							<?php endif; ?>
						</td>
						<td class="center">
							<span style="color:#16a34a; font-weight:600;"><?php echo (int) $m->calificadas; ?></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php endif; ?>

		<?php /* ===================== RENDIMIENTO ===================== */ ?>
		<?php elseif ( 'rendimiento' === $tab ) : ?>

		<?php if ( ! empty( $trimestres_list ) ) : ?>
		<div class="edu-card" style="padding:16px 20px;">
			<form method="get" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
				<?php
				// Preservar otros parámetros GET excepto edu_trim
				foreach ( $_GET as $k => $v ) {
					if ( $k !== 'edu_trim' ) {
						echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
					}
				}
				?>
				<label style="font-weight:600; font-size:14px;"><?php esc_html_e( 'Trimestre:', 'sistema-educativo' ); ?></label>
				<select name="edu_trim" onchange="this.form.submit()" style="padding:6px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
					<?php foreach ( $trimestres_list as $tr_item ) : ?>
					<option value="<?php echo (int) $tr_item->id; ?>" <?php selected( $selected_trimester_id, (int) $tr_item->id ); ?>>
						<?php echo esc_html( $tr_item->period_name . ' · Trimestre ' . $tr_item->number ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</form>
		</div>
		<?php endif; ?>

		<div class="edu-card">
			<h3>
				<?php esc_html_e( 'Rendimiento por grado', 'sistema-educativo' ); ?>
				<?php if ( $selected_trimester_id ) :
					foreach ( $trimestres_list as $tr_item ) {
						if ( (int) $tr_item->id === $selected_trimester_id ) {
							echo ' <span style="font-size:13px; font-weight:400; color:#6b7280;">— ' . esc_html( $tr_item->period_name . ' · Trimestre ' . $tr_item->number ) . '</span>';
							break;
						}
					}
				endif; ?>
			</h3>
			<?php if ( empty( $grados_rendimiento ) ) : ?>
				<p style="color:#9ca3af;"><?php esc_html_e( 'Sin datos de calificaciones para este trimestre.', 'sistema-educativo' ); ?></p>
			<?php else : ?>
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Estudiantes con nota', 'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Promedio', 'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Cualitativa', 'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $grados_rendimiento as $gr ) :
					$prom = $gr->promedio ? round( (float) $gr->promedio, 2 ) : null;
					$badge = ! $prom ? 'gray' : ( $prom >= 8 ? 'green' : ( $prom >= 7 ? 'yellow' : 'red' ) );
					$label = ! $prom ? __( 'Sin datos', 'sistema-educativo' ) : ( $prom >= 8 ? __( 'Bueno', 'sistema-educativo' ) : ( $prom >= 7 ? __( 'Regular', 'sistema-educativo' ) : __( 'Riesgo', 'sistema-educativo' ) ) );
				?>
					<tr>
						<td><strong><?php echo esc_html( $gr->name . ' ' . $gr->paralelo ); ?></strong></td>
						<td class="center"><?php echo (int) $gr->n_est; ?></td>
						<td class="center"><strong style="color:<?php echo $prom ? ( $prom >= 8 ? '#16a34a' : ( $prom >= 7 ? '#d97706' : '#dc2626' ) ) : '#9ca3af'; ?>;"><?php echo $prom ? esc_html( $prom ) : '—'; ?></strong></td>
						<td class="center">
							<?php if ( $prom ) : ?>
								<?php echo Edu_Qualitativa_Helper::badge( $prom, (string) $gr->sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php else : ?>
								<span style="color:#9ca3af;">—</span>
							<?php endif; ?>
						</td>
						<td class="center"><span class="edu-badge-status edu-badge-<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
		</div>

		<?php /* ===================== ALERTAS ===================== */ ?>
		<?php elseif ( 'alertas' === $tab ) : ?>

		<?php if ( ! empty( $alertas_att_baja ) ) : ?>
		<div class="edu-card">
			<h3>&#9888; <?php esc_html_e( 'Grados con asistencia baja este mes', 'sistema-educativo' ); ?></h3>
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead><tr>
					<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( '% Asistencia', 'sistema-educativo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $alertas_att_baja as $a ) :
					$pct = round( $a->asist / $a->total * 100 );
				?>
					<tr>
						<td><?php echo esc_html( $a->name . ' ' . $a->paralelo ); ?></td>
						<td class="center"><strong style="color:#dc2626;"><?php echo esc_html( $pct . '%' ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php endif; ?>

		<div class="edu-card">
			<h3><?php esc_html_e( 'Estudiantes en riesgo (nota < 7)', 'sistema-educativo' ); ?></h3>
			<?php if ( empty( $riesgo_detalle ) ) : ?>
				<p style="color:#16a34a;">&#10003; <?php esc_html_e( 'No hay estudiantes en riesgo.', 'sistema-educativo' ); ?></p>
			<?php else : ?>
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead><tr>
					<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Promedio', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Cualitativa', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Faltas mes', 'sistema-educativo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $riesgo_detalle as $r ) :
					$prom_r = round( (float) $r->promedio, 2 );
					$color_r = $prom_r < 6 ? '#dc2626' : '#d97706';
				?>
					<tr>
						<td><?php echo esc_html( $r->last_name . ', ' . $r->first_name ); ?></td>
						<td><?php echo esc_html( $r->grade_name . ' ' . $r->paralelo ); ?></td>
						<td class="center"><strong style="color:<?php echo esc_attr( $color_r ); ?>;"><?php echo esc_html( $prom_r ); ?></strong></td>
						<td class="center"><?php echo Edu_Qualitativa_Helper::badge( $prom_r, (string) $r->sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td class="center"><?php echo (int) $r->faltas; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
		</div>

		<!-- Mejoras pendientes en alertas -->
		<?php if ( ! empty( $mejoras_abiertas ) ) : ?>
		<div class="edu-card" style="margin-top:16px;">
			<h3>&#9650; <?php esc_html_e( 'Mejoras activas por tarea', 'sistema-educativo' ); ?></h3>
			<?php if ( $n_mejoras_pendientes > 0 ) : ?>
				<div class="edu-alert edu-alert-yellow" style="margin-bottom:12px;">
					&#9650; <?php printf(
						esc_html( _n( 'Hay %d mejora entregada pendiente de calificar.', 'Hay %d mejoras entregadas pendientes de calificar.', $n_mejoras_pendientes, 'sistema-educativo' ) ),
						$n_mejoras_pendientes
					); ?>
				</div>
			<?php endif; ?>
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead><tr>
					<th><?php esc_html_e( 'Tarea', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Grado / Materia', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Fecha límite', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Pendientes', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Calificadas', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $mejoras_abiertas as $m ) :
					$vencida = $m->recovery_due_date && strtotime( $m->recovery_due_date ) < time();
					$todas_calificadas = (int) $m->pendientes === 0 && (int) $m->calificadas > 0;
				?>
					<tr>
						<td><strong><?php echo esc_html( $m->title ); ?></strong></td>
						<td><?php echo esc_html( $m->grade_name . ' ' . $m->paralelo ); ?><br><small style="color:#6b7280;"><?php echo esc_html( $m->subject_name ); ?></small></td>
						<td style="<?php echo $vencida ? 'color:#dc2626; font-weight:600;' : ''; ?>">
							<?php echo $m->recovery_due_date ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $m->recovery_due_date ) ) ) : '—'; ?>
						</td>
						<td class="center">
							<?php if ( (int) $m->pendientes > 0 ) : ?>
								<span style="background:#fef3c7; color:#92400e; font-weight:700; padding:2px 10px; border-radius:99px; font-size:12px;">
									<?php echo (int) $m->pendientes; ?>
								</span>
							<?php else : ?>
								<span style="color:#9ca3af;">0</span>
							<?php endif; ?>
						</td>
						<td class="center"><strong style="color:#16a34a;"><?php echo (int) $m->calificadas; ?></strong></td>
						<td class="center">
							<?php if ( $vencida && (int) $m->pendientes === 0 ) : ?>
								<span class="edu-badge-status edu-badge-green">&#10003; <?php esc_html_e( 'Completada', 'sistema-educativo' ); ?></span>
							<?php elseif ( $vencida ) : ?>
								<span class="edu-badge-status edu-badge-red"><?php esc_html_e( 'Vencida', 'sistema-educativo' ); ?></span>
							<?php elseif ( (int) $m->pendientes > 0 ) : ?>
								<span class="edu-badge-status edu-badge-yellow"><?php esc_html_e( 'Con pendientes', 'sistema-educativo' ); ?></span>
							<?php else : ?>
								<span class="edu-badge-status" style="background:#f0fdf4; color:#166534; border:1px solid #86efac;">&#10003; <?php esc_html_e( 'Al día', 'sistema-educativo' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php else : ?>
		<div class="edu-alert edu-alert-blue" style="margin-top:16px;">
			<?php esc_html_e( 'No hay tareas con mejora activa en este momento.', 'sistema-educativo' ); ?>
		</div>
		<?php endif; ?>

		<?php /* ===================== COMUNICADOS ===================== */ ?>
		<?php elseif ( 'comunicados' === $tab ) : ?>

		<?php
		$com_status = isset( $_GET['edu_status'] ) ? sanitize_key( $_GET['edu_status'] ) : '';
		$com_code   = isset( $_GET['edu_code'] )   ? sanitize_key( $_GET['edu_code'] )   : '';
		if ( 'sent' === $com_status ) : ?>
			<div class="edu-alert edu-alert-green">&#10003; <?php esc_html_e( 'Comunicado enviado exitosamente.', 'sistema-educativo' ); ?></div>
		<?php elseif ( 'deleted' === $com_status ) : ?>
			<div class="edu-alert edu-alert-green">&#10003; <?php esc_html_e( 'Comunicado eliminado.', 'sistema-educativo' ); ?></div>
		<?php elseif ( 'error' === $com_status ) :
			$com_msgs = array(
				'title_required'   => __( 'El asunto del comunicado es obligatorio.', 'sistema-educativo' ),
				'scope_not_allowed'=> __( 'Tipo de destinatario no permitido.', 'sistema-educativo' ),
				'invalid_scope'    => __( 'El grado seleccionado no pertenece a esta institución.', 'sistema-educativo' ),
			);
		?>
			<div class="edu-alert" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
				&#9888; <?php echo esc_html( $com_msgs[ $com_code ] ?? __( 'Error al enviar el comunicado.', 'sistema-educativo' ) ); ?>
			</div>
		<?php endif; ?>

		<div class="edu-card" style="margin-bottom:16px;">
			<h3 style="margin-bottom:14px;"><?php esc_html_e( 'Nuevo comunicado', 'sistema-educativo' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'edu_send_announcement' ); ?>
				<input type="hidden" name="action"    value="edu_send_announcement">
				<input type="hidden" name="_redirect" value="<?php echo esc_url( $current_url ); ?>">
				<div style="display:flex; flex-direction:column; gap:12px;">
					<div>
						<label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;"><?php esc_html_e( 'Asunto *', 'sistema-educativo' ); ?></label>
						<input type="text" name="title" required
							style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:8px 10px; font-size:14px;">
					</div>
					<div>
						<label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;"><?php esc_html_e( 'Mensaje', 'sistema-educativo' ); ?></label>
						<textarea name="body" rows="4"
							style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:8px 10px; font-size:14px; resize:vertical;"></textarea>
					</div>
					<div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
						<div>
							<label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;"><?php esc_html_e( 'Destinatarios', 'sistema-educativo' ); ?></label>
							<select name="scope" onchange="eduToggleGradeSelect(this)"
								style="border:1px solid #d1d5db; border-radius:6px; padding:7px 10px; font-size:14px;">
								<option value="institution"><?php esc_html_e( 'Toda la institución', 'sistema-educativo' ); ?></option>
								<option value="grade"><?php esc_html_e( 'Un grado', 'sistema-educativo' ); ?></option>
							</select>
						</div>
						<div id="edu-grade-select-wrap" style="display:none;">
							<label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;"><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></label>
							<select name="target_grade_id"
								style="border:1px solid #d1d5db; border-radius:6px; padding:7px 10px; font-size:14px;">
								<option value="">-- <?php esc_html_e( 'Seleccionar', 'sistema-educativo' ); ?> --</option>
								<?php foreach ( $all_grades as $g ) : ?>
								<option value="<?php echo (int) $g->id; ?>"><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div style="display:flex; align-items:center; gap:6px; padding-bottom:2px;">
							<input type="checkbox" name="send_email" id="edu-send-email-check" value="1" checked style="width:16px; height:16px;">
							<label for="edu-send-email-check" style="font-size:13px; color:#374151;"><?php esc_html_e( 'Enviar también por email', 'sistema-educativo' ); ?></label>
						</div>
						<?php if ( Edu_Modules::is_active( 'whatsapp' ) && 'disabled' !== (string) get_option( 'edu_wa_provider', 'disabled' ) ) : ?>
						<div style="display:flex; align-items:center; gap:6px; padding-bottom:2px;">
							<input type="checkbox" name="send_whatsapp" id="edu-send-wa-check" value="1" style="width:16px; height:16px;">
							<label for="edu-send-wa-check" style="font-size:13px; color:#374151;">&#128242; <?php esc_html_e( 'Enviar por WhatsApp', 'sistema-educativo' ); ?></label>
						</div>
						<?php endif; ?>
					</div>
					<div>
						<button type="submit" class="edu-btn edu-btn-primary edu-btn-sm">
							&#128226; <?php esc_html_e( 'Enviar comunicado', 'sistema-educativo' ); ?>
						</button>
					</div>
				</div>
			</form>
			<script>
			function eduToggleGradeSelect(sel){
				document.getElementById('edu-grade-select-wrap').style.display = sel.value === 'grade' ? '' : 'none';
			}
			</script>
		</div>

		<div class="edu-card">
			<h3><?php esc_html_e( 'Historial de comunicados institucionales', 'sistema-educativo' ); ?></h3>
			<?php if ( empty( $comunicados ) ) : ?>
				<p style="color:#9ca3af;"><?php esc_html_e( 'No hay comunicados enviados.', 'sistema-educativo' ); ?></p>
			<?php else : ?>
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead><tr>
					<th><?php esc_html_e( 'Título', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Destinatarios', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Leídos', 'sistema-educativo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $comunicados as $c ) :
					$pct_leido = $c->total_dest > 0 ? round( $c->leidos / $c->total_dest * 100 ) : 0;
				?>
					<tr>
						<td><?php echo esc_html( $c->title ); ?></td>
						<td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $c->sent_at ) ) ); ?></td>
						<td class="center"><?php echo (int) $c->total_dest; ?></td>
						<td class="center">
							<span style="color:<?php echo $pct_leido >= 80 ? '#16a34a' : ( $pct_leido >= 50 ? '#d97706' : '#dc2626' ); ?>; font-weight:600;">
								<?php echo (int) $c->leidos; ?> / <?php echo (int) $c->total_dest; ?> (<?php echo $pct_leido; ?>%)
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
		</div>

		<?php /* ===================== CIERRES / RECUPERACIÓN ===================== */ ?>
		<?php elseif ( 'cierres' === $tab ) : ?>

		<?php // Mensajes de estado
		if ( 'parcial_closed' === $cie_status ) : ?>
			<div class="edu-alert edu-alert-green">&#10003; <?php esc_html_e( 'Parcial cerrado exitosamente.', 'sistema-educativo' ); ?></div>
		<?php elseif ( 'trimester_closed' === $cie_status ) : ?>
			<div class="edu-alert edu-alert-green">&#10003; <?php esc_html_e( 'Trimestre cerrado exitosamente.', 'sistema-educativo' ); ?></div>
		<?php elseif ( 'updated' === $cie_status ) : ?>
			<div class="edu-alert edu-alert-green">&#10003; <?php esc_html_e( 'Notas de recuperación guardadas.', 'sistema-educativo' ); ?></div>
		<?php elseif ( 'error' === $cie_status ) :
			$cie_msgs = array(
				'parcials_open'   => __( 'No se puede cerrar el trimestre: hay parciales sin cerrar.', 'sistema-educativo' ),
				'no_students'     => __( 'No se encontraron estudiantes activos en este grado.', 'sistema-educativo' ),
				'invalid_parcial' => __( 'Número de parcial inválido.', 'sistema-educativo' ),
				'invalid_scope'   => __( 'Datos inválidos. Verifique grado, materia y período.', 'sistema-educativo' ),
			);
		?>
			<div class="edu-alert" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
				&#9888; <?php echo esc_html( $cie_msgs[ $cie_code ] ?? __( 'Error al procesar la solicitud.', 'sistema-educativo' ) ); ?>
			</div>
		<?php endif; ?>

		<!-- Filtros -->
		<form method="get" class="edu-card" style="padding:14px; margin-bottom:16px; display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
			<input type="hidden" name="edu_tab" value="cierres">
			<div class="edu-form-row" style="margin:0; min-width:140px;">
				<label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;"><?php esc_html_e( 'Período', 'sistema-educativo' ); ?></label>
				<select name="edu_period" style="width:100%;" onchange="this.form.submit()">
					<option value="">-- <?php esc_html_e( 'Seleccionar', 'sistema-educativo' ); ?> --</option>
					<?php foreach ( $cie_periods as $per ) : ?>
					<option value="<?php echo (int) $per->id; ?>" <?php selected( $cie_period_id, (int) $per->id ); ?>>
						<?php echo esc_html( $per->name ); ?><?php echo $per->is_active ? ' ✓' : ''; ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="edu-form-row" style="margin:0; min-width:150px;">
				<label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;"><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></label>
				<select name="edu_grade" style="width:100%;" onchange="this.form.submit()">
					<option value="">-- <?php esc_html_e( 'Seleccionar', 'sistema-educativo' ); ?> --</option>
					<?php foreach ( $cie_grades as $g ) : ?>
					<option value="<?php echo (int) $g->id; ?>" <?php selected( $cie_grade_id, (int) $g->id ); ?>>
						<?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php if ( ! empty( $cie_subjects ) ) : ?>
			<div class="edu-form-row" style="margin:0; min-width:160px;">
				<label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;"><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></label>
				<select name="edu_subj" style="width:100%;" onchange="this.form.submit()">
					<option value="">-- <?php esc_html_e( 'Seleccionar', 'sistema-educativo' ); ?> --</option>
					<?php foreach ( $cie_subjects as $s ) : ?>
					<option value="<?php echo (int) $s->id; ?>" <?php selected( $cie_subject_id, (int) $s->id ); ?>>
						<?php echo esc_html( $s->name ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>
			<?php if ( ! empty( $cie_trimesters ) ) : ?>
			<div class="edu-form-row" style="margin:0; min-width:130px;">
				<label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:4px;"><?php esc_html_e( 'Trimestre', 'sistema-educativo' ); ?></label>
				<select name="edu_trim" style="width:100%;" onchange="this.form.submit()">
					<option value="">-- <?php esc_html_e( 'Todos', 'sistema-educativo' ); ?> --</option>
					<?php foreach ( $cie_trimesters as $t ) : ?>
					<option value="<?php echo (int) $t->id; ?>" <?php selected( $cie_trimester_id, (int) $t->id ); ?>>
						T<?php echo (int) $t->number; ?> <?php echo esc_html( $t->name ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>
			<button type="submit" class="edu-btn edu-btn-primary edu-btn-sm"><?php esc_html_e( 'Filtrar', 'sistema-educativo' ); ?></button>
		</form>

		<?php if ( ! $cie_grade_id || ! $cie_subject_id ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'Selecciona período, grado y materia para ver las opciones de cierre y recuperación.', 'sistema-educativo' ); ?></div>

		<?php else : ?>

		<?php /* ---- Cierres de Parciales y Trimestre ---- */ ?>
		<?php if ( $cie_trimester_id && ! empty( $cie_students ) ) :
			// Determinar si todos los parciales de cada tipo están cerrados
			$p1_all_closed = true;
			$p2_all_closed = true;
			foreach ( $cie_students as $st ) {
				$sid = (int) $st->id;
				if ( empty( $cie_parcial_data[$sid][1] ) || ! $cie_parcial_data[$sid][1]->is_closed ) $p1_all_closed = false;
				if ( empty( $cie_parcial_data[$sid][2] ) || ! $cie_parcial_data[$sid][2]->is_closed ) $p2_all_closed = false;
			}
			$trim_all_closed = true;
			foreach ( $cie_students as $st ) {
				$sid = (int) $st->id;
				if ( empty( $cie_trim_data[$sid] ) || ! $cie_trim_data[$sid]->is_closed ) { $trim_all_closed = false; break; }
			}
		?>

		<div class="edu-card" style="margin-bottom:16px;">
			<h3 style="margin-bottom:12px;"><?php esc_html_e( 'Cierre de Parciales', 'sistema-educativo' ); ?></h3>
			<div style="overflow-x:auto;">
			<table style="width:100%; border-collapse:collapse; font-size:13px;">
				<thead>
					<tr style="background:#f9fafb;">
						<th style="padding:8px; text-align:left; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'P1', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Estado P1', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'P2', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Estado P2', 'sistema-educativo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $cie_students as $st ) :
					$sid   = (int) $st->id;
					$p1    = $cie_parcial_data[$sid][1] ?? null;
					$p2    = $cie_parcial_data[$sid][2] ?? null;
				?>
				<tr style="border-bottom:1px solid #f3f4f6;">
					<td style="padding:7px 8px;"><?php echo esc_html( $st->first_name . ' ' . $st->last_name ); ?></td>
					<td style="padding:7px 8px; text-align:center;"><?php echo $p1 ? number_format( (float) $p1->computed_score, 2 ) : '–'; ?></td>
					<td style="padding:7px 8px; text-align:center;">
						<?php if ( $p1 && $p1->is_closed ) : ?>
							<span style="color:#16a34a; font-weight:600;">&#10003; <?php esc_html_e( 'Cerrado', 'sistema-educativo' ); ?></span>
						<?php else : ?>
							<span style="color:#d97706;"><?php esc_html_e( 'Abierto', 'sistema-educativo' ); ?></span>
						<?php endif; ?>
					</td>
					<td style="padding:7px 8px; text-align:center;"><?php echo $p2 ? number_format( (float) $p2->computed_score, 2 ) : '–'; ?></td>
					<td style="padding:7px 8px; text-align:center;">
						<?php if ( $p2 && $p2->is_closed ) : ?>
							<span style="color:#16a34a; font-weight:600;">&#10003; <?php esc_html_e( 'Cerrado', 'sistema-educativo' ); ?></span>
						<?php else : ?>
							<span style="color:#d97706;"><?php esc_html_e( 'Abierto', 'sistema-educativo' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<div style="display:flex; gap:10px; margin-top:14px; flex-wrap:wrap;">
				<?php if ( ! $p1_all_closed ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'edu_close_parcial' ); ?>
					<input type="hidden" name="action"       value="edu_close_parcial">
					<input type="hidden" name="grade_id"     value="<?php echo (int) $cie_grade_id; ?>">
					<input type="hidden" name="subject_id"   value="<?php echo (int) $cie_subject_id; ?>">
					<input type="hidden" name="trimester_id" value="<?php echo (int) $cie_trimester_id; ?>">
					<input type="hidden" name="parcial_num"  value="1">
					<input type="hidden" name="_redirect"    value="<?php echo esc_url( $current_url ); ?>">
					<button type="submit" class="edu-btn edu-btn-primary edu-btn-sm"
						onclick="return confirm('<?php echo esc_js( __( '¿Confirmar cierre del Parcial 1? Esta acción no se puede deshacer.', 'sistema-educativo' ) ); ?>')">
						&#128274; <?php esc_html_e( 'Cerrar Parcial 1', 'sistema-educativo' ); ?>
					</button>
				</form>
				<?php else : ?>
					<span style="font-size:12px; color:#16a34a; align-self:center;">&#10003; <?php esc_html_e( 'P1 cerrado', 'sistema-educativo' ); ?></span>
				<?php endif; ?>
				<?php if ( ! $p2_all_closed ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'edu_close_parcial' ); ?>
					<input type="hidden" name="action"       value="edu_close_parcial">
					<input type="hidden" name="grade_id"     value="<?php echo (int) $cie_grade_id; ?>">
					<input type="hidden" name="subject_id"   value="<?php echo (int) $cie_subject_id; ?>">
					<input type="hidden" name="trimester_id" value="<?php echo (int) $cie_trimester_id; ?>">
					<input type="hidden" name="parcial_num"  value="2">
					<input type="hidden" name="_redirect"    value="<?php echo esc_url( $current_url ); ?>">
					<button type="submit" class="edu-btn edu-btn-primary edu-btn-sm"
						onclick="return confirm('<?php echo esc_js( __( '¿Confirmar cierre del Parcial 2? Esta acción no se puede deshacer.', 'sistema-educativo' ) ); ?>')">
						&#128274; <?php esc_html_e( 'Cerrar Parcial 2', 'sistema-educativo' ); ?>
					</button>
				</form>
				<?php else : ?>
					<span style="font-size:12px; color:#16a34a; align-self:center;">&#10003; <?php esc_html_e( 'P2 cerrado', 'sistema-educativo' ); ?></span>
				<?php endif; ?>
			</div>
		</div>

		<div class="edu-card" style="margin-bottom:16px;">
			<h3 style="margin-bottom:12px;"><?php esc_html_e( 'Cierre de Trimestre', 'sistema-educativo' ); ?></h3>
			<div style="overflow-x:auto;">
			<table style="width:100%; border-collapse:collapse; font-size:13px;">
				<thead>
					<tr style="background:#f9fafb;">
						<th style="padding:8px; text-align:left; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'P1', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'P2', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Examen', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Nota T', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Cuali.', 'sistema-educativo' ); ?></th>
						<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $cie_students as $st ) :
					$sid  = (int) $st->id;
					$p1s  = isset( $cie_parcial_data[$sid][1] ) ? (float) $cie_parcial_data[$sid][1]->computed_score : null;
					$p2s  = isset( $cie_parcial_data[$sid][2] ) ? (float) $cie_parcial_data[$sid][2]->computed_score : null;
					$tr   = $cie_trim_data[$sid] ?? null;
					$exam = $tr ? (float) $tr->final_exam_score : null;
					$nota = $tr ? (float) $tr->trim_score : null;
				?>
				<tr style="border-bottom:1px solid #f3f4f6;">
					<td style="padding:7px 8px;"><?php echo esc_html( $st->first_name . ' ' . $st->last_name ); ?></td>
					<td style="padding:7px 8px; text-align:center;"><?php echo $p1s !== null ? number_format( $p1s, 2 ) : '–'; ?></td>
					<td style="padding:7px 8px; text-align:center;"><?php echo $p2s !== null ? number_format( $p2s, 2 ) : '–'; ?></td>
					<td style="padding:7px 8px; text-align:center;"><?php echo $exam !== null ? number_format( $exam, 2 ) : '–'; ?></td>
					<td style="padding:7px 8px; text-align:center; font-weight:600; color:<?php echo ( $nota !== null && $nota >= 7 ) ? '#16a34a' : '#dc2626'; ?>">
						<?php echo $nota !== null ? number_format( $nota, 2 ) : '–'; ?>
					</td>
					<td style="padding:7px 8px; text-align:center;">
						<?php if ( $nota !== null && $nota > 0 ) : ?>
							<?php echo Edu_Qualitativa_Helper::badge( $nota, $cie_sub_level ); // phpcs:ignore ?>
						<?php else : ?>
							<span style="color:#9ca3af;">–</span>
						<?php endif; ?>
					</td>
					<td style="padding:7px 8px; text-align:center;">
						<?php if ( $tr && $tr->is_closed ) : ?>
							<span style="color:#16a34a; font-weight:600;">&#10003; <?php esc_html_e( 'Cerrado', 'sistema-educativo' ); ?></span>
						<?php else : ?>
							<span style="color:#d97706;"><?php esc_html_e( 'Abierto', 'sistema-educativo' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php if ( ! $trim_all_closed ) : ?>
			<div style="margin-top:14px;">
				<?php if ( ! $p1_all_closed || ! $p2_all_closed ) : ?>
					<p style="font-size:12px; color:#d97706; margin-bottom:8px;">
						&#9888; <?php esc_html_e( 'Deben cerrarse ambos parciales antes de cerrar el trimestre.', 'sistema-educativo' ); ?>
					</p>
				<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'edu_close_trimester' ); ?>
					<input type="hidden" name="action"       value="edu_close_trimester">
					<input type="hidden" name="grade_id"     value="<?php echo (int) $cie_grade_id; ?>">
					<input type="hidden" name="subject_id"   value="<?php echo (int) $cie_subject_id; ?>">
					<input type="hidden" name="trimester_id" value="<?php echo (int) $cie_trimester_id; ?>">
					<input type="hidden" name="_redirect"    value="<?php echo esc_url( $current_url ); ?>">
					<button type="submit" class="edu-btn edu-btn-primary edu-btn-sm"
						onclick="return confirm('<?php echo esc_js( __( '¿Confirmar cierre del trimestre? Esta acción no se puede deshacer.', 'sistema-educativo' ) ); ?>')">
						&#128274; <?php esc_html_e( 'Cerrar Trimestre', 'sistema-educativo' ); ?>
					</button>
				</form>
				<?php endif; ?>
			</div>
			<?php else : ?>
				<p style="font-size:12px; color:#16a34a; margin-top:12px;">&#10003; <?php esc_html_e( 'Trimestre ya cerrado.', 'sistema-educativo' ); ?></p>
			<?php endif; ?>
		</div>

		<?php endif; // if cie_trimester_id ?>

		<?php /* ---- Recuperación ---- */ ?>
		<?php
		$has_recovery = false;
		foreach ( $cie_year_data as $yr ) {
			if ( in_array( $yr->status, array( 'supletorio', 'remedial', 'gracia' ), true ) ) {
				$has_recovery = true;
				break;
			}
		}
		if ( $has_recovery ) :
			$recovery_labels = array(
				'supletorio' => __( 'Supletorio', 'sistema-educativo' ),
				'remedial'   => __( 'Remedial',   'sistema-educativo' ),
				'gracia'     => __( 'Gracia',      'sistema-educativo' ),
			);
			$recovery_fields = array(
				'supletorio' => 'supletorio_score',
				'remedial'   => 'remedial_score',
				'gracia'     => 'gracia_score',
			);
		?>
		<div class="edu-card">
			<h3 style="margin-bottom:12px;"><?php esc_html_e( 'Recuperación', 'sistema-educativo' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'edu_save_recovery' ); ?>
				<input type="hidden" name="action"     value="edu_save_recovery">
				<input type="hidden" name="grade_id"   value="<?php echo (int) $cie_grade_id; ?>">
				<input type="hidden" name="subject_id" value="<?php echo (int) $cie_subject_id; ?>">
				<input type="hidden" name="period_id"  value="<?php echo (int) $cie_period_id; ?>">
				<input type="hidden" name="_redirect"  value="<?php echo esc_url( $current_url ); ?>">
				<div style="overflow-x:auto;">
				<table style="width:100%; border-collapse:collapse; font-size:13px;">
					<thead>
						<tr style="background:#f9fafb;">
							<th style="padding:8px; text-align:left; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
							<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Nota Anual', 'sistema-educativo' ); ?></th>
							<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
							<th style="padding:8px; text-align:center; border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Nota Recuperación', 'sistema-educativo' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $cie_students as $st ) :
						$sid = (int) $st->id;
						$yr  = $cie_year_data[$sid] ?? null;
						if ( ! $yr || ! in_array( $yr->status, array( 'supletorio', 'remedial', 'gracia' ), true ) ) continue;
						$field  = $recovery_fields[ $yr->status ];
						$r_val  = $yr->$field;
					?>
					<tr style="border-bottom:1px solid #f3f4f6;">
						<td style="padding:7px 8px;"><?php echo esc_html( $st->first_name . ' ' . $st->last_name ); ?></td>
						<td style="padding:7px 8px; text-align:center; font-weight:600; color:#dc2626;">
							<?php echo $yr->average !== null ? number_format( (float) $yr->average, 2 ) : '–'; ?>
						</td>
						<td style="padding:7px 8px; text-align:center;">
							<span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:600;">
								<?php echo esc_html( $recovery_labels[ $yr->status ] ); ?>
							</span>
						</td>
						<td style="padding:7px 8px; text-align:center;">
							<input type="number" name="recovery[<?php echo $sid; ?>]"
								value="<?php echo $r_val !== null ? esc_attr( number_format( (float) $r_val, 2, '.', '' ) ) : ''; ?>"
								min="0" max="10" step="0.01"
								style="width:80px; text-align:center; border:1px solid #d1d5db; border-radius:4px; padding:4px 6px; font-size:13px;">
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<div style="margin-top:14px;">
					<button type="submit" class="edu-btn edu-btn-primary edu-btn-sm">
						&#128190; <?php esc_html_e( 'Guardar Recuperación', 'sistema-educativo' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php elseif ( $cie_period_id && $cie_subject_id && $cie_grade_id ) : ?>
			<div class="edu-alert edu-alert-green" style="margin-top:0;">
				&#10003; <?php esc_html_e( 'No hay estudiantes pendientes de recuperación para esta selección.', 'sistema-educativo' ); ?>
			</div>
		<?php endif; // has_recovery ?>

		<?php endif; // cie_grade_id && cie_subject_id ?>

		<?php /* ===================== AUDITORÍA ===================== */ ?>
		<?php elseif ( 'auditoria' === $tab ) : ?>

		<!-- Filtros de auditoría -->
		<form method="get" class="edu-card" style="padding:14px; margin-bottom:14px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
			<input type="hidden" name="edu_tab" value="auditoria">
			<div class="edu-form-row" style="margin:0; min-width:170px;">
				<label><?php esc_html_e( 'Acción', 'sistema-educativo' ); ?></label>
				<select name="aud_action" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( '— Todas —', 'sistema-educativo' ); ?></option>
					<?php foreach ( $audit_actions_list as $act ) : ?>
						<option value="<?php echo esc_attr( $act ); ?>" <?php selected( $audit_f_action, $act ); ?>>
							<?php echo esc_html( Edu_Audit::action_label( $act ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="edu-form-row" style="margin:0;">
				<label><?php esc_html_e( 'Desde', 'sistema-educativo' ); ?></label>
				<input type="date" name="aud_desde" value="<?php echo esc_attr( $audit_f_desde ); ?>" onchange="this.form.submit()">
			</div>
			<div class="edu-form-row" style="margin:0;">
				<label><?php esc_html_e( 'Hasta', 'sistema-educativo' ); ?></label>
				<input type="date" name="aud_hasta" value="<?php echo esc_attr( $audit_f_hasta ); ?>" onchange="this.form.submit()">
			</div>
			<?php if ( $audit_f_action || $audit_f_desde || $audit_f_hasta ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'auditoria', $current_url ) ); ?>" class="edu-btn edu-btn-outline edu-btn-sm" style="align-self:flex-end;">
					&#215; <?php esc_html_e( 'Limpiar', 'sistema-educativo' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<div class="edu-card" style="padding:0;">
			<div style="padding:12px 16px; border-bottom:1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:center;">
				<strong style="font-size:13px;">&#128274; <?php esc_html_e( 'Registro de auditoría', 'sistema-educativo' ); ?></strong>
				<span style="font-size:12px; color:#9ca3af;"><?php printf( esc_html__( '%d registros encontrados', 'sistema-educativo' ), $audit_total ); ?></span>
			</div>

			<?php if ( empty( $audit_rows ) ) : ?>
				<p style="padding:20px; color:#9ca3af; text-align:center;"><?php esc_html_e( 'No hay registros para los filtros seleccionados.', 'sistema-educativo' ); ?></p>
			<?php else : ?>
			<div class="edu-table-wrap">
			<table class="edu-table" style="font-size:12px;">
				<thead><tr>
					<th style="min-width:120px;"><?php esc_html_e( 'Fecha y hora', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Usuario', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Acción', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Detalle', 'sistema-educativo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $audit_rows as $arow ) :
					$dt    = strtotime( $arow->timestamp );
					$color = Edu_Audit::action_color( $arow->action );
					$nv    = $arow->new_value ? json_decode( $arow->new_value, true ) : null;
				?>
					<tr>
						<td style="white-space:nowrap;">
							<strong><?php echo esc_html( date_i18n( 'd/m/Y', $dt ) ); ?></strong><br>
							<span style="color:#9ca3af;"><?php echo esc_html( date_i18n( 'H:i:s', $dt ) ); ?></span>
						</td>
						<td><?php echo esc_html( $arow->user_name ?: __( 'Sistema', 'sistema-educativo' ) ); ?></td>
						<td>
							<span style="background:<?php echo esc_attr( $color ); ?>18; color:<?php echo esc_attr( $color ); ?>; padding:2px 8px; border-radius:4px; font-weight:600; white-space:nowrap; font-size:11px;">
								<?php echo esc_html( Edu_Audit::action_label( $arow->action ) ); ?>
							</span>
						</td>
						<td style="color:#374151; font-size:11px;">
							<?php
							if ( is_array( $nv ) ) {
								$parts = array();
								foreach ( $nv as $k => $v ) {
									if ( $v !== null && $v !== '' ) {
										$parts[] = esc_html( $k ) . ': <strong>' . esc_html( $v ) . '</strong>';
									}
								}
								echo implode( ' · ', $parts );
							} elseif ( $arow->new_value ) {
								echo esc_html( $arow->new_value );
							} else {
								echo '<span style="color:#d1d5db;">—</span>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<!-- Paginación -->
			<?php
			$audit_total_pages = $audit_total > 0 ? ceil( $audit_total / $audit_ppage ) : 1;
			if ( $audit_total_pages > 1 ) :
				$pag_args = array_filter( array(
					'edu_tab'    => 'auditoria',
					'aud_action' => $audit_f_action,
					'aud_desde'  => $audit_f_desde,
					'aud_hasta'  => $audit_f_hasta,
				) );
			?>
			<div style="display:flex; gap:6px; padding:12px 16px; align-items:center; border-top:1px solid #f3f4f6;">
				<?php if ( $audit_pnum > 1 ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array_merge( $pag_args, array( 'audit_p' => $audit_pnum - 1 ) ), $current_url ) ); ?>" class="edu-btn edu-btn-outline edu-btn-sm">&#8592; <?php esc_html_e( 'Anterior', 'sistema-educativo' ); ?></a>
				<?php endif; ?>
				<span style="font-size:12px; color:#6b7280;"><?php printf( esc_html__( 'Página %1$d de %2$d', 'sistema-educativo' ), $audit_pnum, $audit_total_pages ); ?></span>
				<?php if ( $audit_pnum < $audit_total_pages ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array_merge( $pag_args, array( 'audit_p' => $audit_pnum + 1 ) ), $current_url ) ); ?>" class="edu-btn edu-btn-outline edu-btn-sm"><?php esc_html_e( 'Siguiente', 'sistema-educativo' ); ?> &#8594;</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php endif; ?>
		</div>

		<?php /* ═══════════════════ BOLETINES ═══════════════════ */ ?>
		<?php elseif ( 'boletines' === $tab ) : ?>

		<?php if ( ! $bol_mpdf_ok ) : ?>
		<div class="edu-alert" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:12px 16px; border-radius:8px; margin-bottom:16px;">
			<strong><?php esc_html_e( 'mPDF no instalado.', 'sistema-educativo' ); ?></strong>
			<?php esc_html_e( 'Ejecuta', 'sistema-educativo' ); ?>
			<code style="background:#fef2f2; padding:2px 6px; border-radius:4px; font-size:12px;">composer install</code>
			<?php esc_html_e( 'en la carpeta del plugin.', 'sistema-educativo' ); ?>
		</div>
		<?php endif; ?>

		<!-- Filtros -->
		<div class="edu-card" style="padding:16px; margin-bottom:16px; display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
			<div class="edu-form-row" style="margin:0; min-width:200px;">
				<label><?php esc_html_e( 'Período', 'sistema-educativo' ); ?></label>
				<select id="bol-period" onchange="eduReloadBol()">
					<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
					<?php foreach ( $bol_periods as $bper ) : ?>
					<option value="<?php echo (int) $bper->id; ?>" <?php selected( $bol_period_sel, (int) $bper->id ); ?>>
						<?php echo esc_html( $bper->name . ( $bper->is_active ? ' ★' : '' ) ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="edu-form-row" style="margin:0; min-width:200px;">
				<label><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></label>
				<select id="bol-grade" onchange="eduReloadBol()">
					<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
					<?php foreach ( $bol_grades as $bg ) : ?>
					<option value="<?php echo (int) $bg->id; ?>" <?php selected( $bol_grade_sel, (int) $bg->id ); ?>>
						<?php echo esc_html( $bg->name . ' ' . $bg->paralelo ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<?php if ( ! $bol_period_sel || ! $bol_grade_sel ) : ?>
			<p style="color:#9ca3af; padding:8px 0;"><?php esc_html_e( 'Selecciona un período y grado para ver los estudiantes.', 'sistema-educativo' ); ?></p>

		<?php elseif ( empty( $bol_students ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No hay estudiantes en este grado.', 'sistema-educativo' ); ?></div>

		<?php else : ?>

		<!-- Descarga masiva -->
		<?php if ( $bol_mpdf_ok ) : ?>
		<div style="margin-bottom:16px; display:flex; align-items:center; gap:12px;">
			<a href="<?php echo esc_url( add_query_arg( array(
				'action'    => 'edu_download_boletines_zip',
				'grade_id'  => $bol_grade_sel,
				'period_id' => $bol_period_sel,
				'_wpnonce'  => $bol_nonce,
			), $bol_base_dl ) ); ?>"
			   class="edu-btn edu-btn-primary">
				&#11015; <?php esc_html_e( 'Descargar todos (ZIP)', 'sistema-educativo' ); ?>
			</a>
			<span style="font-size:13px; color:#6b7280;">
				<?php printf( esc_html__( '%d estudiante(s)', 'sistema-educativo' ), count( $bol_students ) ); ?>
			</span>
		</div>
		<?php endif; ?>

		<!-- Tabla de estudiantes -->
		<div class="edu-card" style="padding:0;">
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead><tr>
					<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Materias aprobadas', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Estado global', 'sistema-educativo' ); ?></th>
					<th class="center"><?php esc_html_e( 'Boletín PDF', 'sistema-educativo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $bol_students as $bst ) :
					$estado   = $bst->estado_global ?: 'en_curso';
					$colors   = explode( '|', $bol_status_colors[ $estado ] ?? '#dbeafe|#1e40af' );
					$bg_col   = $colors[0];
					$tx_col   = $colors[1] ?? '#111';
					$label    = $bol_status_labels[ $estado ] ?? $estado;
					$dl_url   = add_query_arg( array(
						'action'     => 'edu_download_boletin',
						'student_id' => (int) $bst->id,
						'period_id'  => $bol_period_sel,
						'_wpnonce'   => $bol_nonce,
					), $bol_base_dl );
				?>
				<tr>
					<td><strong><?php echo esc_html( trim( $bst->last_name . ', ' . $bst->first_name ) ); ?></strong></td>
					<td class="center">
						<?php echo (int) $bst->materias_aprobadas; ?> / <?php echo (int) $bst->materias_con_nota; ?>
					</td>
					<td class="center">
						<span style="background:<?php echo esc_attr( $bg_col ); ?>; color:<?php echo esc_attr( $tx_col ); ?>; padding:2px 12px; border-radius:99px; font-size:11px; font-weight:600; white-space:nowrap;">
							<?php echo esc_html( $label ); ?>
						</span>
					</td>
					<td class="center">
						<?php if ( $bol_mpdf_ok ) : ?>
						<a href="<?php echo esc_url( $dl_url ); ?>" class="edu-btn edu-btn-outline edu-btn-sm" target="_blank">
							&#128196; PDF
						</a>
						<?php else : ?>
						<span style="color:#9ca3af; font-size:12px;"><?php esc_html_e( 'mPDF requerido', 'sistema-educativo' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>

		<?php endif; // bol_students ?>

		<script>
		function eduReloadBol() {
			var url = new URL( window.location.href );
			url.searchParams.set( 'edu_tab',   'boletines' );
			url.searchParams.set( 'bol_period', document.getElementById('bol-period').value );
			url.searchParams.set( 'bol_grade',  document.getElementById('bol-grade').value );
			window.location.href = url.toString();
		}
		</script>

		<?php /* ═══════════════════ RESUMEN ANUAL ═══════════════════ */ ?>
		<?php elseif ( 'resumen_anual' === $tab ) : ?>

		<?php if ( 'updated' === $ra_status_msg ) : ?>
		<div class="edu-alert" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:10px 16px; border-radius:8px; margin-bottom:16px;">
			&#10003; <?php esc_html_e( 'Notas de recuperación guardadas correctamente.', 'sistema-educativo' ); ?>
		</div>
		<?php elseif ( 'error' === $ra_status_msg ) : ?>
		<div class="edu-alert" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:10px 16px; border-radius:8px; margin-bottom:16px;">
			<?php esc_html_e( 'Error al guardar. Verifica los datos.', 'sistema-educativo' ); ?>
		</div>
		<?php endif; ?>

		<!-- Filtros -->
		<div class="edu-card" style="padding:16px; margin-bottom:16px; display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
			<div class="edu-form-row" style="margin:0; min-width:180px;">
				<label><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></label>
				<select id="ra-grade" onchange="eduReloadRA('grade')">
					<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
					<?php foreach ( $bol_grades as $rg ) : ?>
					<option value="<?php echo (int) $rg->id; ?>" <?php selected( $ra_grade_sel, (int) $rg->id ); ?>>
						<?php echo esc_html( $rg->name . ' ' . $rg->paralelo ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="edu-form-row" style="margin:0; min-width:200px;">
				<label><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></label>
				<select id="ra-subject" onchange="eduReloadRA('subject')" <?php echo $ra_grade_sel ? '' : 'disabled'; ?>>
					<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
					<?php foreach ( $ra_subjects as $rs ) : ?>
					<option value="<?php echo (int) $rs->id; ?>" <?php selected( $ra_subject_sel, (int) $rs->id ); ?>>
						<?php echo esc_html( $rs->name . ( $rs->code ? ' (' . $rs->code . ')' : '' ) ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="edu-form-row" style="margin:0; min-width:180px;">
				<label><?php esc_html_e( 'Período', 'sistema-educativo' ); ?></label>
				<select id="ra-period" onchange="eduReloadRA('period')">
					<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
					<?php foreach ( $bol_periods as $rp ) : ?>
					<option value="<?php echo (int) $rp->id; ?>" <?php selected( $ra_period_sel, (int) $rp->id ); ?>>
						<?php echo esc_html( $rp->name . ( $rp->is_active ? ' ★' : '' ) ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<?php if ( ! $ra_grade_sel || ! $ra_subject_sel || ! $ra_period_sel ) : ?>
			<p style="color:#9ca3af; padding:8px 0;"><?php esc_html_e( 'Selecciona grado, materia y período para ver el resumen.', 'sistema-educativo' ); ?></p>

		<?php elseif ( empty( $ra_students ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'El grado no tiene estudiantes activos.', 'sistema-educativo' ); ?></div>

		<?php else : ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'edu_save_recovery' ); ?>
			<input type="hidden" name="action"     value="edu_save_recovery">
			<input type="hidden" name="_redirect"  value="<?php echo esc_url( $current_url ); ?>">
			<input type="hidden" name="_fe_tab"    value="resumen_anual">
			<input type="hidden" name="grade_id"   value="<?php echo (int) $ra_grade_sel; ?>">
			<input type="hidden" name="subject_id" value="<?php echo (int) $ra_subject_sel; ?>">
			<input type="hidden" name="period_id"  value="<?php echo (int) $ra_period_sel; ?>">

		<div class="edu-card" style="padding:0; overflow-x:auto;">
		<table class="edu-table" style="min-width:700px;">
			<thead><tr>
				<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
				<th class="center"><?php esc_html_e( 'T1', 'sistema-educativo' ); ?></th>
				<th class="center"><?php esc_html_e( 'T2', 'sistema-educativo' ); ?></th>
				<th class="center"><?php esc_html_e( 'T3', 'sistema-educativo' ); ?></th>
				<th class="center"><?php esc_html_e( 'Promedio', 'sistema-educativo' ); ?></th>
				<th class="center"><?php esc_html_e( 'Cualitativa', 'sistema-educativo' ); ?></th>
				<th class="center"><?php esc_html_e( 'Supletorio', 'sistema-educativo' ); ?></th>
				<th class="center"><?php esc_html_e( 'Remedial', 'sistema-educativo' ); ?></th>
				<th class="center"><?php esc_html_e( 'Gracia', 'sistema-educativo' ); ?></th>
				<th class="center"><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
				<?php if ( $ra_has_recovery ) : ?>
				<th class="center"><?php esc_html_e( 'Ingresar nota', 'sistema-educativo' ); ?></th>
				<?php endif; ?>
			</tr></thead>
			<tbody>
			<?php
			$fmt = function( $v ) { return null !== $v && '' !== $v ? number_format( (float) $v, 2 ) : '—'; };
			foreach ( $ra_students as $rst ) :
				$yr      = $ra_year_rows[ (int) $rst->id ] ?? null;
				$st_info = $ra_status_labels[ $yr ? $yr->status : 'en_curso' ];
				$nombre  = trim( $rst->last_name . ' ' . $rst->first_name ) ?: $rst->display_name;
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( $nombre ); ?></strong>
					<?php if ( $rst->cedula ) : ?><br><small style="color:#9ca3af;"><?php echo esc_html( $rst->cedula ); ?></small><?php endif; ?>
				</td>
				<td class="center">
					<?php if ( $yr && (float) $yr->trim1 > 0 ) : ?>
						<?php echo esc_html( number_format( (float) $yr->trim1, 2 ) ); ?>&nbsp;
						<?php echo Edu_Qualitativa_Helper::badge( (float) $yr->trim1, $ra_grade_sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php else : ?>—<?php endif; ?>
				</td>
				<td class="center">
					<?php if ( $yr && (float) $yr->trim2 > 0 ) : ?>
						<?php echo esc_html( number_format( (float) $yr->trim2, 2 ) ); ?>&nbsp;
						<?php echo Edu_Qualitativa_Helper::badge( (float) $yr->trim2, $ra_grade_sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php else : ?>—<?php endif; ?>
				</td>
				<td class="center">
					<?php if ( $yr && (float) $yr->trim3 > 0 ) : ?>
						<?php echo esc_html( number_format( (float) $yr->trim3, 2 ) ); ?>&nbsp;
						<?php echo Edu_Qualitativa_Helper::badge( (float) $yr->trim3, $ra_grade_sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php else : ?>—<?php endif; ?>
				</td>
				<td class="center"><strong><?php echo esc_html( $yr ? number_format( (float) $yr->average, 2 ) : '—' ); ?></strong></td>
				<td class="center">
					<?php if ( $yr && (float) $yr->average > 0 ) : ?>
						<?php echo Edu_Qualitativa_Helper::badge( (float) $yr->average, $ra_grade_sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php else : ?><span style="color:#9ca3af;">—</span><?php endif; ?>
				</td>
				<td class="center"><?php echo esc_html( $yr ? $fmt( $yr->supletorio_score ) : '—' ); ?></td>
				<td class="center"><?php echo esc_html( $yr ? $fmt( $yr->remedial_score )   : '—' ); ?></td>
				<td class="center"><?php echo esc_html( $yr ? $fmt( $yr->gracia_score )     : '—' ); ?></td>
				<td class="center">
					<span style="background:<?php echo esc_attr( $st_info['bg'] ); ?>; color:<?php echo esc_attr( $st_info['tx'] ); ?>; padding:2px 12px; border-radius:99px; font-size:11px; font-weight:600; white-space:nowrap;">
						<?php echo esc_html( $st_info['label'] ); ?>
					</span>
				</td>
				<?php if ( $ra_has_recovery ) : ?>
				<td class="center">
					<?php if ( $yr && in_array( $yr->status, array( 'supletorio', 'remedial', 'gracia' ), true ) ) :
						$ra_field   = $ra_recovery_field_map[ $yr->status ];
						$ra_cur_val = null !== $yr->$ra_field ? number_format( (float) $yr->$ra_field, 2, '.', '' ) : '';
					?>
						<small style="color:#6b7280;"><?php echo esc_html( ucfirst( $yr->status ) ); ?>:</small><br>
						<input type="number" step="0.01" min="0" max="10"
							name="recovery[<?php echo (int) $rst->id; ?>]"
							value="<?php echo esc_attr( $ra_cur_val ); ?>"
							style="width:80px; padding:4px 6px; border:1px solid #d1d5db; border-radius:4px; font-size:13px;">
					<?php else : ?>—<?php endif; ?>
				</td>
				<?php endif; ?>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<?php if ( $ra_has_recovery ) : ?>
		<div style="margin-top:16px;">
			<button type="submit" class="edu-btn edu-btn-primary">
				&#10003; <?php esc_html_e( 'Guardar notas de recuperación', 'sistema-educativo' ); ?>
			</button>
		</div>
		<?php endif; ?>
		</form>

		<?php endif; // ra_students ?>

		<script>
		function eduReloadRA(field) {
			var url = new URL(window.location.href);
			url.searchParams.set('edu_tab',    'resumen_anual');
			url.searchParams.set('ra_grade',   document.getElementById('ra-grade').value);
			url.searchParams.set('ra_period',  document.getElementById('ra-period').value);
			if (field === 'grade') {
				url.searchParams.delete('ra_subject');
			} else {
				url.searchParams.set('ra_subject', document.getElementById('ra-subject').value);
			}
			window.location.href = url.toString();
		}
		</script>

		<?php /* ===================== CUENTAS ===================== */ ?>
		<?php elseif ( 'cuentas' === $tab ) : ?>

		<?php if ( 'updated' === $cta_action_status ) : ?>
			<div class="edu-alert edu-alert-green">&#10003; <?php printf( esc_html__( '%d cuenta(s) actualizada(s).', 'sistema-educativo' ), $cta_changed ); ?></div>
		<?php elseif ( 'error' === $cta_action_status ) : ?>
			<div class="edu-alert" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">&#9888; <?php esc_html_e( 'Error al procesar la solicitud.', 'sistema-educativo' ); ?></div>
		<?php endif; ?>

		<!-- Filtros -->
		<form method="GET" action="" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;align-items:center;">
			<input type="hidden" name="edu_tab" value="cuentas">
			<select name="cta_role" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
				<option value="all"        <?php selected( $cta_role_filter, 'all' ); ?>><?php esc_html_e( 'Todos los roles',    'sistema-educativo' ); ?></option>
				<option value="estudiante" <?php selected( $cta_role_filter, 'estudiante' ); ?>><?php esc_html_e( 'Estudiantes',  'sistema-educativo' ); ?></option>
				<option value="padre"      <?php selected( $cta_role_filter, 'padre' ); ?>><?php esc_html_e( 'Representantes',   'sistema-educativo' ); ?></option>
			</select>

			<?php if ( 'padre' !== $cta_role_filter ) : ?>
			<select name="cta_grade" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
				<option value="0"><?php esc_html_e( 'Todos los grados', 'sistema-educativo' ); ?></option>
				<?php foreach ( $all_grades as $g ) : ?>
				<option value="<?php echo (int) $g->id; ?>" <?php selected( $cta_grade_filter, (int) $g->id ); ?>>
					<?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?>
				</option>
				<?php endforeach; ?>
			</select>
			<?php endif; ?>

			<select name="cta_status" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
				<option value="all"       <?php selected( $cta_status_filter, 'all' ); ?>><?php esc_html_e( 'Todos los estados', 'sistema-educativo' ); ?></option>
				<option value="active"    <?php selected( $cta_status_filter, 'active' ); ?>><?php esc_html_e( 'Activos',          'sistema-educativo' ); ?></option>
				<option value="suspended" <?php selected( $cta_status_filter, 'suspended' ); ?>><?php esc_html_e( 'Suspendidos',   'sistema-educativo' ); ?></option>
			</select>

			<span style="font-size:12px;color:#6b7280;"><?php printf( esc_html__( '%d usuario(s)', 'sistema-educativo' ), count( $cta_users ) ); ?></span>
		</form>

		<!-- Tabla con acciones masivas -->
		<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="edu_bulk_account_status">
			<input type="hidden" name="_redirect" value="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'cuentas', 'cta_role' => $cta_role_filter, 'cta_grade' => $cta_grade_filter, 'cta_status' => $cta_status_filter, 'cta_ok' => 'updated', 'cta_changed' => '' ), $cta_redirect_base ) ); ?>">
			<?php wp_nonce_field( 'edu_bulk_account_status' ); ?>

			<div style="display:flex;gap:8px;margin-bottom:10px;align-items:center;flex-wrap:wrap;">
				<select name="bulk_action" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
					<option value=""><?php esc_html_e( 'Acción masiva', 'sistema-educativo' ); ?></option>
					<option value="activate"><?php esc_html_e( '✅ Activar seleccionados',   'sistema-educativo' ); ?></option>
					<option value="suspend"><?php esc_html_e( '🚫 Suspender seleccionados', 'sistema-educativo' ); ?></option>
				</select>
				<button type="submit" class="edu-btn edu-btn-outline edu-btn-sm"><?php esc_html_e( 'Aplicar', 'sistema-educativo' ); ?></button>
			</div>

			<?php if ( empty( $cta_users ) ) : ?>
				<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No se encontraron usuarios con los filtros seleccionados.', 'sistema-educativo' ); ?></div>
			<?php else : ?>
			<div class="edu-card" style="padding:0;">
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead>
					<tr>
						<th style="width:32px;"><input type="checkbox" id="cta-sel-all" style="cursor:pointer;"></th>
						<th><?php esc_html_e( 'Nombre',  'sistema-educativo' ); ?></th>
						<th><?php esc_html_e( 'Rol',     'sistema-educativo' ); ?></th>
						<th><?php esc_html_e( 'Grado',   'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Estado',  'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Acción',  'sistema-educativo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $cta_users as $cu ) :
					$is_active   = ( 'suspended' !== $cu->account_status );
					$new_status  = $is_active ? 'suspended' : 'active';
					$badge_bg    = $is_active ? '#dcfce7' : '#fee2e2';
					$badge_tx    = $is_active ? '#15803d' : '#b91c1c';
					$badge_label = $is_active ? __( 'Activo', 'sistema-educativo' ) : __( 'Suspendido', 'sistema-educativo' );
					$btn_label   = $is_active ? __( '🚫 Suspender', 'sistema-educativo' ) : __( '✅ Activar', 'sistema-educativo' );
					$rol_label   = 'padre' === $cu->rol ? __( 'Representante', 'sistema-educativo' ) : __( 'Estudiante', 'sistema-educativo' );
					$rol_color   = 'padre' === $cu->rol ? '#7c3aed' : '#1d4ed8';
					$full_name   = trim( $cu->first_name . ' ' . $cu->last_name ) ?: $cu->user_email;
					$toggle_url  = add_query_arg( array( 'edu_tab' => 'cuentas', 'cta_role' => $cta_role_filter, 'cta_grade' => $cta_grade_filter, 'cta_status' => $cta_status_filter, 'cta_ok' => 'updated', 'cta_changed' => 1 ), $cta_redirect_base );
				?>
				<tr>
					<td><input type="checkbox" name="user_ids[]" value="<?php echo (int) $cu->user_id; ?>"></td>
					<td>
						<strong><?php echo esc_html( $full_name ); ?></strong>
						<div style="font-size:11px;color:#9ca3af;"><?php echo esc_html( $cu->user_email ); ?></div>
					</td>
					<td><span style="font-size:11px;font-weight:700;color:<?php echo esc_attr( $rol_color ); ?>;"><?php echo esc_html( $rol_label ); ?></span></td>
					<td style="font-size:12px;"><?php echo $cu->grade_name ? esc_html( $cu->grade_name . ' ' . $cu->paralelo ) : '—'; ?></td>
					<td class="center">
						<span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:99px;background:<?php echo esc_attr( $badge_bg ); ?>;color:<?php echo esc_attr( $badge_tx ); ?>;">
							<?php echo esc_html( $badge_label ); ?>
						</span>
						<?php if ( 'padre' === $cu->rol && ! $is_active ) : ?>
							<div style="font-size:10px;color:#9ca3af;margin-top:2px;"><?php esc_html_e( 'hijos suspendidos', 'sistema-educativo' ); ?></div>
						<?php endif; ?>
					</td>
					<td class="center">
						<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<input type="hidden" name="action"     value="edu_toggle_account_status">
							<input type="hidden" name="user_id"    value="<?php echo (int) $cu->user_id; ?>">
							<input type="hidden" name="new_status" value="<?php echo esc_attr( $new_status ); ?>">
							<input type="hidden" name="_redirect"  value="<?php echo esc_url( $toggle_url ); ?>">
							<?php wp_nonce_field( 'edu_toggle_account_status' ); ?>
							<button type="submit" class="edu-btn edu-btn-sm <?php echo $is_active ? 'edu-btn-danger' : 'edu-btn-outline'; ?>"
								<?php if ( $is_active ) : ?>
									onclick="return confirm('<?php echo esc_js( __( '¿Suspender esta cuenta? Si es representante, sus estudiantes también serán suspendidos.', 'sistema-educativo' ) ); ?>')"
								<?php endif; ?>>
								<?php echo esc_html( $btn_label ); ?>
							</button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			</div>
			<?php endif; ?>
		</form>

		<p style="font-size:12px;color:#6b7280;margin-top:8px;">
			&#9432; <?php esc_html_e( 'Suspender un representante suspende automáticamente todos sus estudiantes vinculados.', 'sistema-educativo' ); ?>
		</p>

		<script>
		(function(){
			var cb = document.getElementById('cta-sel-all');
			if (!cb) return;
			cb.addEventListener('change', function(){
				document.querySelectorAll('input[name="user_ids[]"]').forEach(function(c){ c.checked = cb.checked; });
			});
		})();
		</script>

		<?php endif; ?>

		<?php if ( 'pagos' === $tab ) : ?>
		<?php
		// URL base de esta página para redirects de acciones.
		$pagos_page_url    = add_query_arg( array( 'edu_tab' => 'pagos' ), $current_url );
		$pagos_sub_dash    = add_query_arg( array( 'edu_tab' => 'pagos', 'pago_sub' => 'dashboard', 'pago_period' => $pagos_period_id ), $current_url );
		$pagos_sub_cfg     = add_query_arg( array( 'edu_tab' => 'pagos', 'pago_sub' => 'config', 'pago_period' => $pagos_period_id ), $current_url );

		// Mensajes de estado.
		$pagos_notices = array();
		if ( 'updated' === $pagos_status_msg )    $pagos_notices[] = array( 'type' => 'success', 'msg' => __( 'Configuración guardada.', 'sistema-educativo' ) );
		if ( 'paid'    === $pagos_status_msg )    $pagos_notices[] = array( 'type' => 'success', 'msg' => __( 'Pago registrado correctamente.', 'sistema-educativo' ) );
		if ( 'waived'  === $pagos_status_msg )    $pagos_notices[] = array( 'type' => 'success', 'msg' => __( 'Pago exonerado.', 'sistema-educativo' ) );
		if ( 'error'   === $pagos_status_msg )    $pagos_notices[] = array( 'type' => 'error',   'msg' => __( 'Ocurrió un error. Intenta de nuevo.', 'sistema-educativo' ) );
		if ( 'suspended' === $pagos_status_msg )  $pagos_notices[] = array( 'type' => 'success', 'msg' => sprintf( __( '%d cuenta(s) suspendidas por mora.', 'sistema-educativo' ), $pagos_changed ) );
		if ( $pagos_pay_link ) {
			$pagos_notices[] = array( 'type' => 'info', 'msg' => __( 'Link de pago generado:', 'sistema-educativo' ) . ' <a href="' . esc_url( $pagos_pay_link ) . '" target="_blank">' . esc_html( $pagos_pay_link ) . '</a>' );
		}
		if ( 'ok'    === $pagos_ph_test ) $pagos_notices[] = array( 'type' => 'success', 'msg' => esc_html( $pagos_ph_msg ) );
		if ( 'error' === $pagos_ph_test ) $pagos_notices[] = array( 'type' => 'error',   'msg' => esc_html( $pagos_ph_msg ) );
		?>

		<!-- Selector de período -->
		<div style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<label style="font-weight:600;font-size:14px;"><?php esc_html_e( 'Período:', 'sistema-educativo' ); ?></label>
			<select onchange="window.location='<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'pagos', 'pago_sub' => $pagos_sub, 'pago_period' => '' ), $current_url ) ); ?>'+this.value"
			        style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
				<?php foreach ( $pagos_periodos as $_per ) : ?>
				<option value="<?php echo (int) $_per->id; ?>"<?php selected( (int) $_per->id, $pagos_period_id ); ?>>
					<?php echo esc_html( $_per->name ); ?><?php echo $_per->is_active ? ' ★' : ''; ?>
				</option>
				<?php endforeach; ?>
			</select>

			<!-- Sub-tabs -->
			<div style="margin-left:auto;display:flex;gap:8px;">
				<a href="<?php echo esc_url( $pagos_sub_dash ); ?>"
				   style="padding:6px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;
				          <?php echo 'config' !== $pagos_sub ? 'background:#4f46e5;color:#fff;' : 'background:#e5e7eb;color:#374151;'; ?>">
					<?php esc_html_e( 'Dashboard', 'sistema-educativo' ); ?>
				</a>
				<a href="<?php echo esc_url( $pagos_sub_cfg ); ?>"
				   style="padding:6px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;
				          <?php echo 'config' === $pagos_sub ? 'background:#4f46e5;color:#fff;' : 'background:#e5e7eb;color:#374151;'; ?>">
					<?php esc_html_e( 'Configuración', 'sistema-educativo' ); ?>
				</a>
			</div>
		</div>

		<!-- Notificaciones -->
		<?php foreach ( $pagos_notices as $_n ) : ?>
		<div style="padding:10px 16px;border-radius:6px;margin-bottom:12px;font-size:14px;
		     <?php echo 'success' === $_n['type'] ? 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;' : ( 'error' === $_n['type'] ? 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;' : 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;' ); ?>">
			<?php echo wp_kses_post( $_n['msg'] ); ?>
		</div>
		<?php endforeach; ?>

		<?php if ( 'config' !== $pagos_sub ) : /* ===== DASHBOARD ===== */ ?>

		<!-- Filtros -->
		<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end;">
			<input type="hidden" name="edu_tab" value="pagos">
			<input type="hidden" name="pago_period" value="<?php echo (int) $pagos_period_id; ?>">
			<div>
				<label style="display:block;font-size:12px;color:#6b7280;margin-bottom:3px;"><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></label>
				<select name="pago_grade" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
					<option value=""><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
					<?php foreach ( $pagos_grades as $_g ) : ?>
					<option value="<?php echo (int) $_g->id; ?>"<?php selected( (int) $_g->id, $pagos_grade_f ); ?>>
						<?php echo esc_html( $_g->name . ' ' . $_g->paralelo ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label style="display:block;font-size:12px;color:#6b7280;margin-bottom:3px;"><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></label>
				<select name="pago_status" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
					<option value="all"><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
					<option value="pending"  <?php selected( 'pending',  $pagos_status_f ); ?>><?php esc_html_e( 'Pendiente', 'sistema-educativo' ); ?></option>
					<option value="paid"     <?php selected( 'paid',     $pagos_status_f ); ?>><?php esc_html_e( 'Pagado',    'sistema-educativo' ); ?></option>
					<option value="overdue"  <?php selected( 'overdue',  $pagos_status_f ); ?>><?php esc_html_e( 'Vencido',   'sistema-educativo' ); ?></option>
					<option value="waived"   <?php selected( 'waived',   $pagos_status_f ); ?>><?php esc_html_e( 'Exonerado', 'sistema-educativo' ); ?></option>
				</select>
			</div>
			<?php if ( ! empty( $pagos_meses_disp ) ) : ?>
			<div>
				<label style="display:block;font-size:12px;color:#6b7280;margin-bottom:3px;"><?php esc_html_e( 'Mes', 'sistema-educativo' ); ?></label>
				<select name="pago_month" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
					<option value=""><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
					<?php foreach ( $pagos_meses_disp as $_m ) : ?>
					<option value="<?php echo esc_attr( $_m ); ?>"<?php selected( $_m, $pagos_month_f ); ?>><?php echo esc_html( $_m ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>
			<button type="submit" style="padding:6px 16px;background:#4f46e5;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer;">
				<?php esc_html_e( 'Filtrar', 'sistema-educativo' ); ?>
			</button>
		</form>

		<!-- Generar cuotas del mes -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
			<input type="hidden" name="action" value="edu_generate_monthly_payments">
			<input type="hidden" name="period_id" value="<?php echo (int) $pagos_period_id; ?>">
			<input type="hidden" name="_return_url" value="<?php echo esc_attr( $pagos_page_url ); ?>">
			<?php wp_nonce_field( 'edu_generate_monthly_payments' ); ?>
			<button type="submit" style="padding:7px 18px;background:#0891b2;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer;font-weight:600;">
				&#128197; <?php esc_html_e( 'Generar cuotas del mes', 'sistema-educativo' ); ?>
			</button>
			<span style="font-size:12px;color:#6b7280;margin-left:8px;"><?php esc_html_e( 'Crea los registros de pago para todos los estudiantes activos del período.', 'sistema-educativo' ); ?></span>
		</form>

		<!-- Tabla de pagos -->
		<?php if ( empty( $pagos_list ) ) : ?>
		<p style="color:#6b7280;font-size:14px;"><?php esc_html_e( 'No hay registros de pago con los filtros aplicados.', 'sistema-educativo' ); ?></p>
		<?php else : ?>
		<div style="overflow-x:auto;">
		<table style="width:100%;border-collapse:collapse;font-size:13px;">
			<thead>
			<tr style="background:#f3f4f6;">
				<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
				<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
				<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Mes', 'sistema-educativo' ); ?></th>
				<th style="padding:8px 12px;text-align:right;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Monto', 'sistema-educativo' ); ?></th>
				<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Vence', 'sistema-educativo' ); ?></th>
				<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
				<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Referencia', 'sistema-educativo' ); ?></th>
				<th style="padding:8px 12px;text-align:center;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php
			$pagos_status_labels = array(
				'pending' => array( 'label' => __( 'Pendiente', 'sistema-educativo' ), 'bg' => '#fef9c3', 'color' => '#854d0e' ),
				'paid'    => array( 'label' => __( 'Pagado',    'sistema-educativo' ), 'bg' => '#dcfce7', 'color' => '#166534' ),
				'overdue' => array( 'label' => __( 'Vencido',   'sistema-educativo' ), 'bg' => '#fee2e2', 'color' => '#991b1b' ),
				'waived'  => array( 'label' => __( 'Exonerado', 'sistema-educativo' ), 'bg' => '#eff6ff', 'color' => '#1d4ed8' ),
			);
			foreach ( $pagos_list as $_pay ) :
				$_st = $pagos_status_labels[ $_pay->status ] ?? array( 'label' => esc_html( $_pay->status ), 'bg' => '#f3f4f6', 'color' => '#374151' );
				$_pendiente = in_array( $_pay->status, array( 'pending', 'overdue' ), true );
			?>
			<tr style="border-bottom:1px solid #f3f4f6;">
				<td style="padding:8px 12px;"><?php echo esc_html( trim( $_pay->first_name . ' ' . $_pay->last_name ) ); ?></td>
				<td style="padding:8px 12px;"><?php echo esc_html( $_pay->grade_name . ' ' . $_pay->paralelo ); ?></td>
				<td style="padding:8px 12px;"><?php echo esc_html( $_pay->month ); ?></td>
				<td style="padding:8px 12px;text-align:right;">$<?php echo number_format( (float) $_pay->amount, 2 ); ?></td>
				<td style="padding:8px 12px;"><?php echo esc_html( $_pay->due_date ?? '' ); ?></td>
				<td style="padding:8px 12px;">
					<span style="padding:2px 8px;border-radius:12px;font-size:12px;background:<?php echo esc_attr( $_st['bg'] ); ?>;color:<?php echo esc_attr( $_st['color'] ); ?>;">
						<?php echo esc_html( $_st['label'] ); ?>
					</span>
				</td>
				<td style="padding:8px 12px;font-size:12px;color:#6b7280;">
					<?php echo esc_html( $_pay->payment_ref ?: ( $_pay->payphone_transaction_id ?: '—' ) ); ?>
				</td>
				<td style="padding:8px 12px;text-align:center;">
					<?php if ( $_pendiente ) : ?>
					<!-- Registrar manual -->
					<button type="button"
					        onclick="document.getElementById('pagos-modal-manual-<?php echo (int) $_pay->id; ?>').style.display='flex'"
					        style="padding:4px 10px;background:#16a34a;color:#fff;border:none;border-radius:4px;font-size:12px;cursor:pointer;margin:2px;">
						<?php esc_html_e( 'Registrar', 'sistema-educativo' ); ?>
					</button>
					<!-- Generar link -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="edu_generate_payment_link">
						<input type="hidden" name="payment_id" value="<?php echo (int) $_pay->id; ?>">
						<input type="hidden" name="_return_url" value="<?php echo esc_attr( $pagos_page_url ); ?>">
						<?php wp_nonce_field( 'edu_generate_payment_link' ); ?>
						<button type="submit" style="padding:4px 10px;background:#0891b2;color:#fff;border:none;border-radius:4px;font-size:12px;cursor:pointer;margin:2px;">
							<?php esc_html_e( 'Link', 'sistema-educativo' ); ?>
						</button>
					</form>
					<!-- Exonerar -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
					      onsubmit="return confirm('<?php esc_attr_e( '¿Exonerar este pago?', 'sistema-educativo' ); ?>')">
						<input type="hidden" name="action" value="edu_waive_payment">
						<input type="hidden" name="payment_id" value="<?php echo (int) $_pay->id; ?>">
						<input type="hidden" name="_return_url" value="<?php echo esc_attr( $pagos_page_url ); ?>">
						<?php wp_nonce_field( 'edu_waive_payment' ); ?>
						<button type="submit" style="padding:4px 10px;background:#d97706;color:#fff;border:none;border-radius:4px;font-size:12px;cursor:pointer;margin:2px;">
							<?php esc_html_e( 'Exonerar', 'sistema-educativo' ); ?>
						</button>
					</form>
					<?php else : ?>
					<span style="color:#9ca3af;font-size:12px;">—</span>
					<?php endif; ?>
				</td>
			</tr>

			<?php if ( $_pendiente ) : ?>
			<!-- Modal registro manual -->
			<div id="pagos-modal-manual-<?php echo (int) $_pay->id; ?>"
			     style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
				<div style="background:#fff;border-radius:8px;padding:24px;width:380px;max-width:90%;">
					<h3 style="margin:0 0 16px;font-size:16px;"><?php esc_html_e( 'Registrar pago', 'sistema-educativo' ); ?></h3>
					<p style="font-size:13px;color:#6b7280;margin:0 0 12px;">
						<?php echo esc_html( trim( $_pay->first_name . ' ' . $_pay->last_name ) . ' — ' . $_pay->month . ' — $' . number_format( (float) $_pay->amount, 2 ) ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="edu_register_manual_payment">
						<input type="hidden" name="payment_id" value="<?php echo (int) $_pay->id; ?>">
						<input type="hidden" name="_return_url" value="<?php echo esc_attr( $pagos_page_url ); ?>">
						<?php wp_nonce_field( 'edu_register_manual_payment' ); ?>
						<div style="margin-bottom:12px;">
							<label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Método de pago', 'sistema-educativo' ); ?></label>
							<select name="payment_method" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
								<option value="efectivo"><?php esc_html_e( 'Efectivo', 'sistema-educativo' ); ?></option>
								<option value="transferencia"><?php esc_html_e( 'Transferencia', 'sistema-educativo' ); ?></option>
								<option value="deposito"><?php esc_html_e( 'Depósito', 'sistema-educativo' ); ?></option>
								<option value="cheque"><?php esc_html_e( 'Cheque', 'sistema-educativo' ); ?></option>
								<option value="payphone"><?php esc_html_e( 'Payphone', 'sistema-educativo' ); ?></option>
							</select>
						</div>
						<div style="margin-bottom:16px;">
							<label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Referencia / Comprobante', 'sistema-educativo' ); ?></label>
							<input type="text" name="payment_ref" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" placeholder="<?php esc_attr_e( 'Núm. transacción, recibo, etc.', 'sistema-educativo' ); ?>">
						</div>
						<div style="display:flex;gap:10px;justify-content:flex-end;">
							<button type="button"
							        onclick="document.getElementById('pagos-modal-manual-<?php echo (int) $_pay->id; ?>').style.display='none'"
							        style="padding:7px 16px;border:1px solid #d1d5db;background:#fff;border-radius:6px;font-size:13px;cursor:pointer;">
								<?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?>
							</button>
							<button type="submit" style="padding:7px 16px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer;font-weight:600;">
								<?php esc_html_e( 'Guardar', 'sistema-educativo' ); ?>
							</button>
						</div>
					</form>
				</div>
			</div>
			<?php endif; ?>

			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php endif; /* tabla vacía */ ?>

		<!-- Suspender morosos -->
		<div style="margin-top:24px;padding:16px;border:1px solid #fecaca;border-radius:8px;background:#fff5f5;">
			<h3 style="font-size:15px;margin:0 0 12px;color:#991b1b;">&#9888; <?php esc_html_e( 'Suspender cuentas con mora', 'sistema-educativo' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			      onsubmit="return confirm('<?php esc_attr_e( '¿Suspender todas las cuentas con mora según los días indicados?', 'sistema-educativo' ); ?>')">
				<input type="hidden" name="action" value="edu_suspend_overdue_payments">
				<input type="hidden" name="period_id" value="<?php echo (int) $pagos_period_id; ?>">
				<input type="hidden" name="_return_url" value="<?php echo esc_attr( $pagos_page_url ); ?>">
				<?php wp_nonce_field( 'edu_suspend_overdue_payments' ); ?>
				<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
					<label style="font-size:13px;"><?php esc_html_e( 'Días de mora mínimos:', 'sistema-educativo' ); ?></label>
					<input type="number" name="days_threshold" value="30" min="1" max="365"
					       style="width:80px;padding:6px 8px;border:1px solid #fca5a5;border-radius:6px;font-size:13px;">
					<button type="submit" style="padding:6px 16px;background:#dc2626;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer;font-weight:600;">
						<?php esc_html_e( 'Suspender morosos', 'sistema-educativo' ); ?>
					</button>
				</div>
			</form>
		</div>

		<?php else : /* ===== CONFIGURACIÓN ===== */ ?>

		<!-- Mensaje test conexión -->
		<?php
		$last_wh = get_option( 'edu_debug_last_webhook' );
		if ( $last_wh ) :
		?>
		<div style="padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:12px;margin-bottom:16px;">
			<strong><?php esc_html_e( 'Último webhook Payphone:', 'sistema-educativo' ); ?></strong>
			<?php echo esc_html( $last_wh['time'] ?? '' ); ?> —
			<pre style="margin:6px 0 0;overflow-x:auto;"><?php echo esc_html( wp_json_encode( $last_wh['body'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
		</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="edu_save_payment_config">
			<input type="hidden" name="config_period_id" value="<?php echo (int) $pagos_period_id; ?>">
			<input type="hidden" name="_return_url" value="<?php echo esc_attr( add_query_arg( array( 'edu_tab' => 'pagos', 'pago_sub' => 'config', 'pago_period' => $pagos_period_id ), $current_url ) ); ?>">
			<?php wp_nonce_field( 'edu_save_payment_config' ); ?>

			<!-- Credenciales Payphone -->
			<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">
				<h3 style="font-size:15px;margin:0 0 16px;">&#128083; <?php esc_html_e( 'Credenciales Payphone', 'sistema-educativo' ); ?></h3>
				<div style="display:grid;gap:12px;">
					<div>
						<label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Bearer Token', 'sistema-educativo' ); ?></label>
						<input type="text" name="edu_payphone_token" value="<?php echo esc_attr( $pagos_ph_token ); ?>"
						       style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:monospace;"
						       placeholder="Pega aquí el token de Payphone">
					</div>
					<div>
						<label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Store ID', 'sistema-educativo' ); ?></label>
						<input type="text" name="edu_payphone_store_id" value="<?php echo esc_attr( $pagos_ph_store_id ); ?>"
						       style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:monospace;"
						       placeholder="Store ID de la aplicación WEB">
					</div>
					<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;font-size:12px;">
						<strong><?php esc_html_e( 'URL webhook a configurar en Payphone:', 'sistema-educativo' ); ?></strong><br>
						<code style="word-break:break-all;"><?php echo esc_html( home_url( '/wp-json/edu/v1/payphone/webhook' ) ); ?></code>
					</div>
				</div>
			</div>

			<!-- Pensiones por grado -->
			<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">
				<h3 style="font-size:15px;margin:0 0 16px;">&#128176; <?php esc_html_e( 'Valores de pensión por grado', 'sistema-educativo' ); ?></h3>
				<?php if ( empty( $pagos_grades ) ) : ?>
				<p style="font-size:13px;color:#6b7280;"><?php esc_html_e( 'No hay grados registrados en esta institución.', 'sistema-educativo' ); ?></p>
				<?php else : ?>
				<div style="overflow-x:auto;">
				<table style="width:100%;border-collapse:collapse;font-size:13px;">
					<thead>
					<tr style="background:#f3f4f6;">
						<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
						<th style="padding:8px 12px;text-align:right;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Pensión mensual $', 'sistema-educativo' ); ?></th>
						<th style="padding:8px 12px;text-align:right;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Matrícula $', 'sistema-educativo' ); ?></th>
						<th style="padding:8px 12px;text-align:center;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Día vence', 'sistema-educativo' ); ?></th>
						<th style="padding:8px 12px;text-align:center;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Días gracia', 'sistema-educativo' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $pagos_grades as $_g ) :
						$_cfg_g = $pagos_configs[ (int) $_g->id ] ?? null;
						$_cfg_d = $pagos_configs['all'] ?? null;
					?>
					<tr style="border-bottom:1px solid #f3f4f6;">
						<td style="padding:8px 12px;"><?php echo esc_html( $_g->name . ' ' . $_g->paralelo ); ?></td>
						<td style="padding:8px 12px;text-align:right;">
							<input type="number" name="cfg[<?php echo (int) $_g->id; ?>][monthly_amount]"
							       value="<?php echo esc_attr( number_format( (float) ( $_cfg_g->monthly_amount ?? $_cfg_d->monthly_amount ?? 0 ), 2, '.', '' ) ); ?>"
							       step="0.01" min="0" style="width:90px;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;text-align:right;">
						</td>
						<td style="padding:8px 12px;text-align:right;">
							<input type="number" name="cfg[<?php echo (int) $_g->id; ?>][matricula_amount]"
							       value="<?php echo esc_attr( number_format( (float) ( $_cfg_g->matricula_amount ?? $_cfg_d->matricula_amount ?? 0 ), 2, '.', '' ) ); ?>"
							       step="0.01" min="0" style="width:90px;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;text-align:right;">
						</td>
						<td style="padding:8px 12px;text-align:center;">
							<input type="number" name="cfg[<?php echo (int) $_g->id; ?>][due_day]"
							       value="<?php echo (int) ( $_cfg_g->due_day ?? $_cfg_d->due_day ?? 5 ); ?>"
							       min="1" max="28" style="width:60px;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;text-align:center;">
						</td>
						<td style="padding:8px 12px;text-align:center;">
							<input type="number" name="cfg[<?php echo (int) $_g->id; ?>][grace_days]"
							       value="<?php echo (int) ( $_cfg_g->grace_days ?? $_cfg_d->grace_days ?? 5 ); ?>"
							       min="0" max="30" style="width:60px;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;text-align:center;">
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php endif; ?>
			</div>

			<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
				<button type="submit" style="padding:9px 24px;background:#4f46e5;color:#fff;border:none;border-radius:6px;font-size:14px;cursor:pointer;font-weight:600;">
					&#128190; <?php esc_html_e( 'Guardar configuración', 'sistema-educativo' ); ?>
				</button>
				<button type="button" id="pagos-btn-test-ph"
				        style="padding:9px 18px;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:6px;font-size:13px;cursor:pointer;">
					&#128279; <?php esc_html_e( 'Probar conexión Payphone', 'sistema-educativo' ); ?>
				</button>
				<span style="font-size:12px;color:#6b7280;"><?php esc_html_e( 'Guarda primero, luego prueba.', 'sistema-educativo' ); ?></span>
			</div>
		</form>

		<!-- Formulario oculto para test de conexión -->
		<form id="pagos-form-test-ph" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
			<input type="hidden" name="action" value="edu_test_payphone_connection">
			<input type="hidden" name="_return_url" value="<?php echo esc_attr( add_query_arg( array( 'edu_tab' => 'pagos', 'pago_sub' => 'config', 'pago_period' => $pagos_period_id ), $current_url ) ); ?>">
			<?php wp_nonce_field( 'edu_test_payphone_connection' ); ?>
		</form>
		<script>
		(function(){
			var btn = document.getElementById('pagos-btn-test-ph');
			if (btn) btn.addEventListener('click', function(){
				document.getElementById('pagos-form-test-ph').submit();
			});
		}());
		</script>

		<?php endif; /* config vs dashboard */ ?>
		<?php endif; /* tab pagos */ ?>

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
