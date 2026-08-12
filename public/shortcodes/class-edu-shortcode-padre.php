<?php
/**
 * Shortcode [edu_portal_padre] — Portal del representante / padre de familia.
 *
 * Tabs: inicio | notas | tareas | asistencia | comunicados
 * Selector de hijo cuando hay más de un hijo vinculado.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Shortcode_Padre {

	public static function register() {
		add_shortcode( 'edu_portal_padre', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'Debes iniciar sesión para acceder al portal.', 'sistema-educativo' ) .
				'</div></div>';
		}

		if ( ! Edu_Context::can( 'edu_view_child_grades' ) ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'No tienes permiso para ver este portal.', 'sistema-educativo' ) .
				'</div></div>';
		}

		wp_enqueue_style( 'edu-portales', EDU_PLUGIN_URL . 'public/css/portales.css', array(), EDU_VERSION );

		global $wpdb;
		$p   = $wpdb->prefix . 'edu_';
		$uid = get_current_user_id();

		// Obtener el registro del padre.
		$parent = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$p}parents WHERE user_id = %d", $uid
		) );

		if ( ! $parent ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'No se encontró tu registro de representante. Contacta al administrador.', 'sistema-educativo' ) .
				'</div></div>';
		}

		// Hijos vinculados.
		$hijos = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.grade_id, s.user_id, g.name AS grade_name, g.paralelo, g.institution_id, g.sub_level,
			        COALESCE(um_fn.meta_value, '') AS first_name,
			        COALESCE(um_ln.meta_value, u.display_name) AS last_name
			 FROM {$p}parent_student ps
			 INNER JOIN {$p}students s ON s.id = ps.student_id
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
			 WHERE ps.parent_id = %d
			 ORDER BY last_name, first_name",
			(int) $parent->id
		) );

		if ( empty( $hijos ) ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'No tienes estudiantes vinculados. Contacta al administrador.', 'sistema-educativo' ) .
				'</div></div>';
		}

		// Hijo actualmente seleccionado.
		$hijo_id = isset( $_GET['edu_hijo'] ) ? (int) $_GET['edu_hijo'] : (int) $hijos[0]->id;
		$hijo    = null;
		foreach ( $hijos as $h ) {
			if ( (int) $h->id === $hijo_id ) { $hijo = $h; break; }
		}
		if ( ! $hijo ) {
			$hijo    = $hijos[0];
			$hijo_id = (int) $hijo->id;
		}

		$tab = isset( $_GET['edu_tab'] ) ? sanitize_key( $_GET['edu_tab'] ) : 'inicio';
		$valid_tabs = Edu_Modules::filter_tabs( array( 'inicio', 'notas', 'tareas', 'asistencia', 'comunicados', 'boletines', 'pagos' ) );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'inicio';
		}

		$sub_level_p   = (string) $hijo->sub_level;
		$usa_sumativa_p = in_array( $sub_level_p, array( 'media', 'superior', 'bg', 'bt' ), true );

		$today       = date( 'Y-m-d' );
		$month_start = date( 'Y-m-01' );
		$month_end   = date( 'Y-m-t' );
		$current_url = get_permalink();
		$sid         = $hijo_id;

		// Período más relevante: activo primero, si no el más reciente.
		$periodo_id_p = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}periods WHERE institution_id = %d ORDER BY is_active DESC, start_date DESC LIMIT 1",
			(int) $hijo->institution_id
		) );

		// ---- Notas por materia y trimestre ----
		$notas_raw = array();
		if ( $periodo_id_p ) {
			$notas_raw = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.name AS subject_name, t.number AS trim_num,
				        ps.computed_score AS parcial_1, ps2.computed_score AS parcial_2,
				        ts.final_exam_score AS exam_score, ts.proyecto_score, ts.computed_score AS trim_score
				 FROM {$p}grade_subjects gs
				 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
				 CROSS JOIN (SELECT id, number FROM {$p}trimesters WHERE period_id = %d) t
				 LEFT JOIN {$p}parcial_scores ps  ON ps.student_id  = %d AND ps.subject_id  = gs.subject_id AND ps.trimester_id = t.id AND ps.parcial_num = 1
				 LEFT JOIN {$p}parcial_scores ps2 ON ps2.student_id = %d AND ps2.subject_id = gs.subject_id AND ps2.trimester_id = t.id AND ps2.parcial_num = 2
				 LEFT JOIN {$p}trimester_scores ts ON ts.student_id = %d AND ts.subject_id   = gs.subject_id AND ts.trimester_id = t.id
				 WHERE gs.grade_id = %d
				 ORDER BY s.name, t.number",
				$periodo_id_p, $sid, $sid, $sid, (int) $hijo->grade_id
			) );
		}

		$notas_por_materia = array();
		foreach ( $notas_raw as $n ) {
			if ( ! isset( $notas_por_materia[ $n->subject_name ] ) ) {
				$notas_por_materia[ $n->subject_name ] = array();
			}
			$notas_por_materia[ $n->subject_name ][ (int) $n->trim_num ] = $n;
		}

		// Promedio actual.
		$sumas_prom = array();
		foreach ( $notas_por_materia as $trims ) {
			foreach ( $trims as $n ) {
				if ( null !== $n->trim_score ) { $sumas_prom[] = (float) $n->trim_score; }
			}
		}
		$promedio_actual = $sumas_prom ? round( array_sum( $sumas_prom ) / count( $sumas_prom ), 2 ) : null;

		// ---- Tareas del hijo ----
		$tareas = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.id, a.title, a.type, a.due_date, a.max_score,
			        a.allow_recovery, a.recovery_due_date,
			        s.name AS subject_name,
			        u.display_name AS teacher_name,
			        sub.status AS sub_status, sub.score AS sub_score,
			        sub.recovery_status, sub.recovery_score
			 FROM {$p}assignments a
			 INNER JOIN {$p}subjects s ON s.id = a.subject_id
			 LEFT JOIN {$p}teachers tr ON tr.id = a.teacher_id
			 LEFT JOIN {$wpdb->users} u ON u.ID = tr.user_id
			 LEFT JOIN {$p}submissions sub ON sub.assignment_id = a.id AND sub.student_id = %d
			 WHERE a.grade_id = %d AND a.status IN ('published','closed')
			 ORDER BY a.due_date ASC",
			$sid, (int) $hijo->grade_id
		) );

		$n_pendientes = count( array_filter( $tareas, function( $t ) {
			return empty( $t->sub_status ) || in_array( $t->sub_status, array( 'submitted', 'late' ), true );
		} ) );

		// ---- Asistencia del hijo ----
		$att_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT date,
			        CASE WHEN SUM(status = 'falta_injustificada') > 0 THEN 'falta_injustificada'
			             WHEN SUM(status = 'falta_justificada')   > 0 THEN 'falta_justificada'
			             WHEN SUM(status = 'atraso')              > 0 THEN 'atraso'
			             ELSE 'presente' END AS status
			 FROM {$p}attendance
			 WHERE student_id = %d AND date BETWEEN %s AND %s
			 GROUP BY date
			 ORDER BY date DESC",
			$sid, $month_start, $month_end
		) );
		$att_counts = array( 'presente' => 0, 'atraso' => 0, 'falta_justificada' => 0, 'falta_injustificada' => 0 );
		foreach ( $att_rows as $a ) {
			$att_counts[ $a->status ] = ( $att_counts[ $a->status ] ?? 0 ) + 1;
		}
		$att_total_mes = array_sum( $att_counts );
		$pct_asist     = $att_total_mes > 0
			? round( ( $att_counts['presente'] + $att_counts['atraso'] ) / $att_total_mes * 100 )
			: null;

		// ---- Comunicados del padre ----
		$n_sin_leer = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}announcement_recipients
			 WHERE user_id = %d AND channel = 'portal' AND read_at IS NULL",
			$uid
		) );

		$comunicados = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.id, a.title, a.body, a.sent_at, a.sender_user_id,
			        u.display_name AS sender_name,
			        r.id AS recip_id, r.read_at
			 FROM {$p}announcement_recipients r
			 INNER JOIN {$p}announcements a ON a.id = r.announcement_id
			 INNER JOIN {$wpdb->users} u ON u.ID = a.sender_user_id
			 WHERE r.user_id = %d AND r.channel = 'portal'
			 ORDER BY a.sent_at DESC",
			$uid
		) );

		// ---- Períodos de la institución del hijo (para boletines) ----
		$periodos_boletin = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, is_active FROM {$p}periods WHERE institution_id = %d ORDER BY start_date DESC",
			(int) $hijo->institution_id
		) );

		$mpdf_ok   = file_exists( EDU_PLUGIN_DIR . 'vendor/autoload.php' );
		$nonce_bol = wp_create_nonce( 'edu_download_boletin_padre' );

		// ---- Pagos del hijo ----
		$pagos_hijo  = array();
		$pay_message = null;
		if ( $periodo_id_p ) {
			// Procesar retorno de Payphone si aplica.
			if ( 'pagos' === $tab && ! empty( $_GET['edu_pay_return'] ) ) {
				$pay_message = Edu_Payment_Controller::process_payment_return();
			}

			$pagos_hijo = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, month, concept, amount, due_date, status, paid_at, payment_method
				 FROM {$p}payments
				 WHERE student_id = %d AND period_id = %d
				 ORDER BY month DESC",
				$sid, $periodo_id_p
			) );
		}
		$n_pagos_pendientes = count( array_filter( $pagos_hijo, function( $pay ) {
			return in_array( $pay->status, array( 'pending', 'overdue' ), true );
		} ) );
		$portal_padre_url = get_permalink();

		$user = wp_get_current_user();
		$initials = strtoupper( mb_substr( $user->display_name, 0, 2 ) );

		$status_labels = array(
			'presente'            => __( 'Presente', 'sistema-educativo' ),
			'atraso'              => __( 'Atraso', 'sistema-educativo' ),
			'falta_justificada'   => __( 'Falta J.', 'sistema-educativo' ),
			'falta_injustificada' => __( 'Falta I.', 'sistema-educativo' ),
		);

		// Inyectar JS de acuse de recibo si hay comunicados sin leer.
		static $ack_script_padre = false;
		if ( ! $ack_script_padre && 'comunicados' === $tab ) {
			$ack_script_padre = true;
			$_ack_ajax = esc_js( esc_url( admin_url( 'admin-ajax.php' ) ) );
			add_action( 'wp_footer', function() use ( $_ack_ajax ) {
				if ( defined( 'EDU_ACK_JS_ADDED' ) ) return;
				define( 'EDU_ACK_JS_ADDED', true );
				echo '<script>
function eduAckAnnouncement(btn){
	var id=btn.dataset.id, nonce=btn.dataset.nonce;
	var wrap=btn.closest(".edu-comunicado");
	btn.disabled=true;
	btn.textContent="' . esc_js( __( 'Confirmando…', 'sistema-educativo' ) ) . '";
	var fd=new FormData();
	fd.append("action","edu_ack_announcement");
	fd.append("announcement_id",id);
	fd.append("_wpnonce",nonce);
	fetch("' . $_ack_ajax . '",{method:"POST",body:fd,credentials:"same-origin"})
	.then(function(r){return r.json();})
	.then(function(d){
		if(d.success){
			var dot=wrap.querySelector("span[style*=\'background:#3b82f6\']");
			if(dot) dot.remove();
			var badge=wrap.querySelector(".edu-badge-status.edu-badge-blue");
			if(badge){badge.className="edu-badge-status edu-badge-green";badge.textContent="' . esc_js( __( 'Leído', 'sistema-educativo' ) ) . '";}
			var ackWrap=btn.closest(".edu-ack-wrap");
			if(ackWrap) ackWrap.innerHTML="<p style=\'font-size:12px;color:#16a34a;margin-top:10px;border-top:1px solid #e5e7eb;padding-top:8px;\'>&#10003; ' . esc_js( __( 'Lectura confirmada', 'sistema-educativo' ) ) . '</p>";
			if(wrap) wrap.classList.remove("unread");
		}
	})
	.catch(function(){btn.disabled=false;btn.textContent="' . esc_js( __( 'Confirmar lectura', 'sistema-educativo' ) ) . '";});
}
</script>';
			}, 20 );
		}

		// Institución, período y saludo
		$inst_name_p   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$p}institutions WHERE id = %d", (int) $hijo->institution_id ) );
		$period_row_p  = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$p}periods WHERE institution_id = %d AND is_active = 1 LIMIT 1", (int) $hijo->institution_id ) );
		$period_name_p = $period_row_p ? $period_row_p->name : '';
		$hour_p        = (int) date( 'G' );
		$saludo_p      = $hour_p < 12 ? 'Buenos días' : ( $hour_p < 19 ? 'Buenas tardes' : 'Buenas noches' );
		$first_name_p  = trim( $user->first_name ?: explode( ' ', $user->display_name )[0] );
		$page_urls_p   = array(
			'docente'    => ( $pid = (int) get_option( 'edu_page_docente',     0 ) ) ? get_permalink( $pid ) : '',
			'padre'      => ( $pid = (int) get_option( 'edu_page_padre',       0 ) ) ? get_permalink( $pid ) : '',
			'estudiante' => ( $pid = (int) get_option( 'edu_page_estudiante',  0 ) ) ? get_permalink( $pid ) : '',
			'rector'     => ( $pid = (int) get_option( 'edu_page_rector',      0 ) ) ? get_permalink( $pid ) : '',
		);

		ob_start();
		?>
		<div class="edu-portal-wrap">

		<!-- ── Top bar ── -->
		<div class="edu-topbar">
			<div class="edu-topbar-brand">
				<div class="edu-topbar-logo">SE</div>
				<div class="edu-topbar-info">
					<div class="edu-topbar-name">Sistema Educativo Integral</div>
					<div class="edu-topbar-sub"><?php echo esc_html( $inst_name_p ); ?><?php echo $period_name_p ? ' · ' . esc_html( $period_name_p ) : ''; ?></div>
				</div>
			</div>
			<div class="edu-topbar-roles">
				<?php
				$roles_p = array(
					'docente'    => array( 'icon' => '&#128202;', 'label' => __( 'Docente',    'sistema-educativo' ) ),
					'padre'      => array( 'icon' => '&#128106;', 'label' => __( 'Padre',      'sistema-educativo' ) ),
					'estudiante' => array( 'icon' => '&#127891;', 'label' => __( 'Estudiante', 'sistema-educativo' ) ),
					'rector'     => array( 'icon' => '&#127894;', 'label' => __( 'Rector',     'sistema-educativo' ) ),
				);
				foreach ( $roles_p as $rk => $rd ) :
					$ru = $page_urls_p[ $rk ] ?? '';
					if ( ! $ru ) continue;
				?>
				<a href="<?php echo esc_url( $ru ); ?>" class="edu-role-btn<?php echo 'padre' === $rk ? ' active' : ''; ?>">
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
				<div class="edu-avatar" style="background:#7c3aed;"><?php echo esc_html( $initials ); ?></div>
				<div class="edu-user-name"><?php echo esc_html( $user->display_name ); ?></div>
				<div class="edu-user-role"><?php esc_html_e( 'Representante', 'sistema-educativo' ); ?></div>

				<!-- Selector de hijo dentro del sidebar -->
				<?php if ( count( $hijos ) > 1 ) : ?>
				<div class="edu-sidebar-selector">
					<label><?php esc_html_e( 'VIENDO A', 'sistema-educativo' ); ?></label>
					<select onchange="window.location=this.value">
						<?php foreach ( $hijos as $h ) :
							$url_h = add_query_arg( array( 'edu_tab' => $tab, 'edu_hijo' => $h->id ), $current_url );
						?>
							<option value="<?php echo esc_url( $url_h ); ?>" <?php selected( $hijo_id, (int) $h->id ); ?>>
								<?php echo esc_html( $h->first_name . ' ' . $h->last_name . ' · ' . $h->grade_name . ' ' . $h->paralelo ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php else : ?>
				<div class="edu-sidebar-selector">
					<label><?php esc_html_e( 'VIENDO A', 'sistema-educativo' ); ?></label>
					<div style="font-size:13px; font-weight:600; color:#1e293b; padding:4px 0;">
						<?php echo esc_html( $hijo->first_name . ' ' . $hijo->last_name ); ?><br>
						<span style="font-weight:400; color:#64748b; font-size:12px;"><?php echo esc_html( $hijo->grade_name . ' ' . $hijo->paralelo ); ?></span>
					</div>
				</div>
				<?php endif; ?>

				<div class="edu-sidenav-sep"></div>
				<div class="edu-sidenav-section"><?php esc_html_e( 'REPRESENTANTE', 'sistema-educativo' ); ?></div>
				<nav class="edu-sidenav">
					<?php
					$sidenav_p = array(
						'inicio'      => array( 'icon' => '&#127968;', 'label' => __( 'Inicio',       'sistema-educativo' ), 'badge' => 0 ),
						'notas'       => array( 'icon' => '&#128202;', 'label' => __( 'Notas',        'sistema-educativo' ), 'badge' => 0 ),
						'tareas'      => array( 'icon' => '&#128203;', 'label' => __( 'Tareas',       'sistema-educativo' ), 'badge' => $n_pendientes ),
						'asistencia'  => array( 'icon' => '&#9989;',   'label' => __( 'Asistencia',   'sistema-educativo' ), 'badge' => 0 ),
						'comunicados' => array( 'icon' => '&#128226;', 'label' => __( 'Comunicados',  'sistema-educativo' ), 'badge' => $n_sin_leer ),
						'boletines'   => array( 'icon' => '&#128196;', 'label' => __( 'Boletines',    'sistema-educativo' ), 'badge' => 0 ),
						'pagos'       => array( 'icon' => '&#128179;', 'label' => __( 'Pagos',        'sistema-educativo' ), 'badge' => $n_pagos_pendientes ),
					);
					$sidenav_p = Edu_Modules::filter_sidenav( $sidenav_p );
					foreach ( $sidenav_p as $sk => $sd ) :
						$url_tab = add_query_arg( array( 'edu_tab' => $sk, 'edu_hijo' => $hijo_id ), $current_url );
					?>
					<a href="<?php echo esc_url( $url_tab ); ?>"
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
		$hijo_label = esc_html( $hijo->first_name . ' ' . $hijo->last_name . ' · ' . $hijo->grade_name . ' ' . $hijo->paralelo );
		$titles_p = array(
			'inicio'      => $saludo_p . ', ' . $first_name_p,
			'notas'       => __( 'Notas',       'sistema-educativo' ),
			'tareas'      => __( 'Tareas',       'sistema-educativo' ),
			'asistencia'  => __( 'Asistencia',   'sistema-educativo' ),
			'comunicados' => __( 'Comunicados',  'sistema-educativo' ),
			'boletines'   => __( 'Boletines',    'sistema-educativo' ),
			'pagos'       => __( 'Pagos',        'sistema-educativo' ),
		);
		?>
		<div class="edu-content-header">
			<h1><?php echo esc_html( $titles_p[ $tab ] ?? '' ); ?></h1>
			<p><?php echo $hijo_label; ?></p>
		</div>

		<?php /* ===================== INICIO ===================== */ ?>
		<?php if ( 'inicio' === $tab ) : ?>

		<div class="edu-stats-row">
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Promedio actual', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#16a34a;"><?php echo null !== $promedio_actual ? esc_html( $promedio_actual ) : '—'; ?></div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Asistencia mes', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#1d4ed8;"><?php echo null !== $pct_asist ? esc_html( $pct_asist . '%' ) : '—'; ?></div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Tareas pendientes', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#d97706;"><?php echo (int) $n_pendientes; ?></div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Comunicados nuevos', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#dc2626;"><?php echo (int) $n_sin_leer; ?></div>
			</div>
		</div>

		<div class="edu-grid-2">
			<!-- Últimas notas -->
			<div class="edu-card">
				<h3><?php esc_html_e( 'Últimas notas', 'sistema-educativo' ); ?></h3>
				<?php
				$ultimas_notas = array();
				foreach ( $notas_por_materia as $materia => $trims ) {
					foreach ( array_reverse( $trims, true ) as $tn => $n ) {
						if ( null !== $n->parcial_1 || null !== $n->parcial_2 || null !== $n->trim_score ) {
							$ultimas_notas[] = array(
								'materia' => $materia,
								'trim'    => $tn,
								'nota'    => $n->trim_score ?? $n->parcial_1 ?? $n->parcial_2,
							);
							break;
						}
					}
				}
				$ultimas_notas = array_slice( $ultimas_notas, 0, 5 );
				?>
				<?php if ( empty( $ultimas_notas ) ) : ?>
					<p style="color:#9ca3af; font-size:13px;"><?php esc_html_e( 'Sin notas registradas.', 'sistema-educativo' ); ?></p>
				<?php else : ?>
				<ul class="edu-list">
				<?php foreach ( $ultimas_notas as $un ) :
					$color_nota = (float) $un['nota'] >= 7 ? '#16a34a' : '#dc2626';
				?>
					<li style="display:flex; justify-content:space-between; align-items:center;">
						<div>
							<strong><?php echo esc_html( $un['materia'] ); ?></strong>
							<div class="edu-list-meta">T<?php echo (int) $un['trim']; ?></div>
						</div>
						<strong style="color:<?php echo esc_attr( $color_nota ); ?>; font-size:18px;"><?php echo esc_html( number_format( (float) $un['nota'], 2 ) ); ?></strong>
					</li>
				<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'notas', 'edu_hijo' => $hijo_id ), $current_url ) ); ?>" class="edu-btn edu-btn-outline edu-btn-sm" style="margin-top:12px;">
					<?php esc_html_e( 'Ver todas', 'sistema-educativo' ); ?>
				</a>
			</div>

			<!-- Comunicados sin leer -->
			<div class="edu-card">
				<h3><?php esc_html_e( 'Comunicados sin leer', 'sistema-educativo' ); ?></h3>
				<?php
				$no_leidos = array_filter( $comunicados, function( $c ) { return empty( $c->read_at ); } );
				$no_leidos = array_slice( array_values( $no_leidos ), 0, 5 );
				?>
				<?php if ( empty( $no_leidos ) ) : ?>
					<p style="color:#16a34a; font-size:13px;">&#10003; <?php esc_html_e( 'Todos los comunicados leídos.', 'sistema-educativo' ); ?></p>
				<?php else : ?>
				<ul class="edu-list">
				<?php foreach ( $no_leidos as $c ) : ?>
					<li>
						<strong><?php echo esc_html( $c->title ); ?></strong>
						<div class="edu-list-meta"><?php echo esc_html( $c->sender_name ); ?> · <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $c->sent_at ) ) ); ?></div>
					</li>
				<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'comunicados', 'edu_hijo' => $hijo_id ), $current_url ) ); ?>" class="edu-btn edu-btn-outline edu-btn-sm" style="margin-top:12px;">
					<?php esc_html_e( 'Ver comunicados', 'sistema-educativo' ); ?>
				</a>
			</div>
		</div>

		<?php /* ===================== NOTAS ===================== */ ?>
		<?php elseif ( 'notas' === $tab ) : ?>

		<?php if ( empty( $notas_por_materia ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'Aún no hay calificaciones registradas.', 'sistema-educativo' ); ?></div>
		<?php else : ?>
		<div class="edu-card" style="padding:0;">
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Parcial 1', 'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Parcial 2', 'sistema-educativo' ); ?></th>
						<th class="center"><?php echo $usa_sumativa_p ? esc_html__( 'Examen (15%)', 'sistema-educativo' ) : esc_html__( 'Examen (30%)', 'sistema-educativo' ); ?></th>
						<?php if ( $usa_sumativa_p ) : ?>
						<th class="center"><?php esc_html_e( 'Proyecto (15%)', 'sistema-educativo' ); ?></th>
						<?php endif; ?>
						<th class="center">T1</th>
						<th class="center">T2</th>
						<th class="center">T3</th>
						<th class="center bg-blue"><?php esc_html_e( 'Promedio', 'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Cuali.', 'sistema-educativo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $notas_por_materia as $materia => $trims ) :
					// Tomar datos del trimestre más reciente con datos.
					$last_trim = null;
					for ( $tn = 3; $tn >= 1; $tn-- ) {
						if ( isset( $trims[ $tn ] ) ) { $last_trim = $trims[ $tn ]; break; }
					}
					$scores = array();
					for ( $tn = 1; $tn <= 3; $tn++ ) {
						$scores[ $tn ] = isset( $trims[ $tn ] ) && null !== $trims[ $tn ]->trim_score
							? round( (float) $trims[ $tn ]->trim_score, 2 )
							: null;
					}
					$valid_s = array_filter( $scores, fn( $v ) => null !== $v );
					$prom_m  = $valid_s ? round( array_sum( $valid_s ) / count( $valid_s ), 2 ) : null;
					$cc      = null === $prom_m ? '#9ca3af' : ( $prom_m >= 7 ? '#1d4ed8' : '#dc2626' );
				?>
					<tr>
						<td><?php echo esc_html( $materia ); ?></td>
						<td class="center"><?php echo $last_trim && null !== $last_trim->parcial_1 ? esc_html( number_format( (float) $last_trim->parcial_1, 2 ) ) : '<span style="color:#d1d5db;">—</span>'; ?></td>
						<td class="center"><?php echo $last_trim && null !== $last_trim->parcial_2 ? esc_html( number_format( (float) $last_trim->parcial_2, 2 ) ) : '<span style="color:#d1d5db;">—</span>'; ?></td>
						<td class="center"><?php echo $last_trim && null !== $last_trim->exam_score ? esc_html( number_format( (float) $last_trim->exam_score, 2 ) ) : '<span style="color:#d1d5db;">—</span>'; ?></td>
						<?php if ( $usa_sumativa_p ) : ?>
						<td class="center"><?php echo $last_trim && null !== $last_trim->proyecto_score ? esc_html( number_format( (float) $last_trim->proyecto_score, 2 ) ) : '<span style="color:#d1d5db;">—</span>'; ?></td>
						<?php endif; ?>
						<?php for ( $tn = 1; $tn <= 3; $tn++ ) : ?>
							<td class="center">
								<?php if ( null !== $scores[ $tn ] ) : ?>
									<span style="<?php echo $scores[ $tn ] < 7 ? 'color:#dc2626; font-weight:600;' : ''; ?>"><?php echo esc_html( $scores[ $tn ] ); ?></span>
									<br><?php
									$_cod_p = Edu_Qualitativa_Helper::codigo( $scores[ $tn ] );
									$_clr_p = Edu_Qualitativa_Helper::color( $_cod_p );
									?>
									<span style="font-size:10px; font-weight:700; color:<?php echo esc_attr( $_clr_p ); ?>;"><?php echo esc_html( $_cod_p ); ?></span>
								<?php else : ?>
									<span style="color:#d1d5db;">—</span>
								<?php endif; ?>
							</td>
						<?php endfor; ?>
						<td class="center bg-blue" style="color:<?php echo esc_attr( $cc ); ?>;"><?php echo null !== $prom_m ? esc_html( $prom_m ) : '—'; ?></td>
						<td class="center">
							<?php if ( null !== $prom_m ) : ?>
								<?php echo Edu_Qualitativa_Helper::badge( $prom_m, $sub_level_p ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php else : ?>
								<span style="color:#d1d5db;">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<p style="font-size:12px; color:#6b7280; margin-top:8px;">
			&#128204; <?php echo $usa_sumativa_p
				? esc_html__( 'Nota T = ((P1+P2)/2 × 70%) + ((Examen+Proyecto)/2 × 30%)', 'sistema-educativo' )
				: esc_html__( 'Nota T = ((P1+P2)/2 × 70%) + Examen × 30%', 'sistema-educativo' ); ?>
		</p>
		<?php endif; ?>

		<?php /* ===================== TAREAS ===================== */ ?>
		<?php elseif ( 'tareas' === $tab ) : ?>

		<?php if ( empty( $tareas ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No hay tareas publicadas para este estudiante.', 'sistema-educativo' ); ?></div>
		<?php else : ?>
		<?php foreach ( $tareas as $t ) :
			$sub_status = $t->sub_status ?? null;
			$rec_status = $t->recovery_status ?? 'none';
			if ( ! $sub_status ) {
				$badge_label = __( 'Sin entregar', 'sistema-educativo' );
				$badge_class = 'edu-badge-yellow';
			} elseif ( 'graded' === $sub_status ) {
				$badge_label = __( 'Calificada', 'sistema-educativo' );
				$badge_class = 'edu-badge-green';
			} elseif ( 'late' === $sub_status ) {
				$badge_label = __( 'Atrasada', 'sistema-educativo' );
				$badge_class = 'edu-badge-red';
			} else {
				$badge_label = __( 'Entregada', 'sistema-educativo' );
				$badge_class = 'edu-badge-blue';
			}
			$mejora_activa     = ! empty( $t->allow_recovery );
			$mejora_disponible = $mejora_activa
				&& ! in_array( $rec_status, array( 'submitted', 'graded' ), true )
				&& ( ! $t->recovery_due_date || strtotime( $t->recovery_due_date ) >= time() );
			$mejora_enviada    = $mejora_activa && 'submitted' === $rec_status;
			$mejora_calificada = $mejora_activa && 'graded' === $rec_status;
		?>
			<div class="edu-tarea-card">
				<div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:6px; margin-bottom:6px;">
					<div style="display:flex; gap:6px; flex-wrap:wrap;">
						<span class="edu-badge-status <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
						<?php if ( $mejora_disponible ) : ?>
							<span class="edu-badge-status" style="background:#fffbeb; color:#92400e; border:1px solid #fcd34d;">&#9650; <?php esc_html_e( 'Mejora disponible', 'sistema-educativo' ); ?></span>
						<?php elseif ( $mejora_enviada ) : ?>
							<span class="edu-badge-status" style="background:#eff6ff; color:#1d4ed8; border:1px solid #93c5fd;">&#9650; <?php esc_html_e( 'Mejora enviada', 'sistema-educativo' ); ?></span>
						<?php elseif ( $mejora_calificada ) : ?>
							<span class="edu-badge-status" style="background:#f0fdf4; color:#166534; border:1px solid #86efac;">&#9650; <?php esc_html_e( 'Mejora calificada', 'sistema-educativo' ); ?></span>
						<?php endif; ?>
					</div>
					<span style="font-size:12px; color:#6b7280;"><?php echo esc_html( $t->subject_name ); ?><?php if ( $t->teacher_name ) : ?> · <span style="color:#2563eb;"><?php echo esc_html( $t->teacher_name ); ?></span><?php endif; ?></span>
				</div>
				<h4><?php echo esc_html( $t->title ); ?></h4>
				<div class="edu-tarea-meta">
					<?php if ( $t->due_date ) : ?>
						&#128197; <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $t->due_date ) ) ); ?>
					<?php endif; ?>
					<?php if ( 'graded' === $sub_status && null !== $t->sub_score ) : ?>
						· <?php esc_html_e( 'Nota:', 'sistema-educativo' ); ?>
						<strong style="color:#1d4ed8;"><?php echo esc_html( number_format( (float) $t->sub_score, 2 ) ); ?> / <?php echo esc_html( number_format( (float) $t->max_score, 2 ) ); ?></strong>
					<?php endif; ?>
					<?php if ( $mejora_calificada && null !== $t->recovery_score ) :
						$mejor_p = max( (float) ( $t->sub_score ?? 0 ), (float) $t->recovery_score );
					?>
						· <?php esc_html_e( 'Mejora:', 'sistema-educativo' ); ?>
						<strong style="color:#d97706;"><?php echo esc_html( number_format( (float) $t->recovery_score, 2 ) ); ?></strong>
						<span style="font-size:11px; color:#16a34a;">(<?php esc_html_e( 'mejor:', 'sistema-educativo' ); ?> <strong><?php echo esc_html( number_format( $mejor_p, 2 ) ); ?></strong>)</span>
					<?php endif; ?>
				</div>
				<?php if ( $mejora_disponible && $t->recovery_due_date ) : ?>
					<div style="font-size:11px; color:#92400e; margin-top:4px;">
						&#9650; <?php esc_html_e( 'Fecha límite mejora:', 'sistema-educativo' ); ?>
						<?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $t->recovery_due_date ) ) ); ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<?php endif; ?>

		<?php /* ===================== ASISTENCIA ===================== */ ?>
		<?php elseif ( 'asistencia' === $tab ) : ?>

		<div class="edu-stats-row" style="grid-template-columns: repeat(2, 1fr);">
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Asistencia mes', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:<?php echo null !== $pct_asist ? ( $pct_asist >= 90 ? '#16a34a' : ( $pct_asist >= 75 ? '#d97706' : '#dc2626' ) ) : '#9ca3af'; ?>;">
					<?php echo null !== $pct_asist ? esc_html( $pct_asist . '%' ) : '—'; ?>
				</div>
			</div>
			<div class="edu-stat">
				<div class="edu-stat-label"><?php esc_html_e( 'Faltas este mes', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#dc2626;"><?php echo (int) ( $att_counts['falta_justificada'] + $att_counts['falta_injustificada'] ); ?></div>
			</div>
		</div>

		<div class="edu-grid-2">
			<div class="edu-card">
				<h3><?php esc_html_e( 'Resumen del mes', 'sistema-educativo' ); ?></h3>
				<ul class="edu-list">
					<?php foreach ( $status_labels as $key => $label ) : ?>
					<li style="display:flex; justify-content:space-between;">
						<span class="att-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></span>
						<strong><?php echo (int) ( $att_counts[ $key ] ?? 0 ); ?></strong>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="edu-card">
				<h3><?php esc_html_e( 'Historial', 'sistema-educativo' ); ?></h3>
				<?php if ( empty( $att_rows ) ) : ?>
					<p style="color:#9ca3af; font-size:13px;"><?php esc_html_e( 'Sin registros este mes.', 'sistema-educativo' ); ?></p>
				<?php else : ?>
				<div class="edu-table-wrap">
				<table class="edu-table">
					<thead><tr>
						<th><?php esc_html_e( 'Fecha', 'sistema-educativo' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $att_rows as $a ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $a->date ) ) ); ?></td>
							<td><span class="att-<?php echo esc_attr( $a->status ); ?>"><?php echo esc_html( $status_labels[ $a->status ] ?? $a->status ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<?php /* ===================== COMUNICADOS ===================== */ ?>
		<?php elseif ( 'comunicados' === $tab ) : ?>

		<?php
		$edu_ack = isset( $_GET['edu_ack'] ) ? (int) $_GET['edu_ack'] : 0;
		if ( $edu_ack ) : ?>
			<div class="edu-alert edu-alert-green">&#10003; <?php esc_html_e( 'Acuse de recibo confirmado.', 'sistema-educativo' ); ?></div>
		<?php endif; ?>

		<?php if ( empty( $comunicados ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No tienes comunicados.', 'sistema-educativo' ); ?></div>
		<?php else : ?>
		<?php foreach ( $comunicados as $c ) :
			$is_read = ! empty( $c->read_at );
		?>
			<div class="edu-comunicado <?php echo $is_read ? '' : 'unread'; ?>">
				<div class="edu-comunicado-header" onclick="(function(el){var b=el.parentNode.querySelector('.edu-comunicado-body');b.style.display=b.style.display==='none'?'block':'none';})(this)">
					<div>
						<?php if ( ! $is_read ) : ?><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#3b82f6;margin-right:6px;vertical-align:middle;"></span><?php endif; ?>
						<strong><?php echo esc_html( $c->title ); ?></strong>
						<div style="font-size:12px; color:#6b7280; margin-top:2px;">
							<?php esc_html_e( 'De:', 'sistema-educativo' ); ?> <?php echo esc_html( $c->sender_name ); ?>
						</div>
					</div>
					<div style="display:flex; gap:8px; align-items:center; flex-shrink:0;">
						<span style="font-size:12px; color:#9ca3af;"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $c->sent_at ) ) ); ?></span>
						<?php if ( $is_read ) : ?>
							<span class="edu-badge-status edu-badge-green">&#10003; <?php esc_html_e( 'Leído', 'sistema-educativo' ); ?></span>
						<?php else : ?>
							<span class="edu-badge-status edu-badge-blue"><?php esc_html_e( 'Nuevo', 'sistema-educativo' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<div class="edu-comunicado-body" style="display:<?php echo ( ! $is_read || (int) $c->id === $edu_ack ) ? 'block' : 'none'; ?>;">
					<?php echo wp_kses_post( $c->body ); ?>
					<?php if ( ! $is_read ) : ?>
					<div class="edu-ack-wrap" style="margin-top:12px; border-top:1px solid #e5e7eb; padding-top:10px;">
						<button type="button"
							class="edu-btn edu-btn-green edu-btn-sm"
							data-id="<?php echo (int) $c->id; ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'edu_acknowledge_' . (int) $c->id ) ); ?>"
							onclick="eduAckAnnouncement(this)">
							&#10003; <?php esc_html_e( 'Confirmar lectura', 'sistema-educativo' ); ?>
						</button>
					</div>
					<?php else : ?>
					<p style="font-size:12px; color:#16a34a; margin-top:10px; border-top:1px solid #e5e7eb; padding-top:8px;">
						&#10003; <?php esc_html_e( 'Leído el', 'sistema-educativo' ); ?> <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $c->read_at ) ) ); ?>
					</p>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		<?php endif; ?>

		<?php /* ===================== BOLETINES ===================== */ ?>
		<?php elseif ( 'boletines' === $tab ) : ?>

		<?php if ( ! $mpdf_ok ) : ?>
			<div class="edu-alert" style="background:#fef2f2; border-color:#fecaca; color:#991b1b;">
				&#9888; <?php esc_html_e( 'La generación de boletines PDF no está disponible en este momento. Contacta al administrador.', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( empty( $periodos_boletin ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No hay períodos académicos disponibles.', 'sistema-educativo' ); ?></div>
		<?php else : ?>
			<div class="edu-card">
				<h3><?php esc_html_e( 'Boletines de Calificaciones', 'sistema-educativo' ); ?></h3>
				<p style="font-size:13px; color:#6b7280; margin-bottom:16px;">
					<?php esc_html_e( 'Descarga el boletín PDF de cada período académico de tu representado.', 'sistema-educativo' ); ?>
				</p>
				<ul class="edu-list">
				<?php foreach ( $periodos_boletin as $per ) :
					$dl_url = add_query_arg( array(
						'action'     => 'edu_download_boletin_padre',
						'student_id' => $hijo_id,
						'period_id'  => (int) $per->id,
						'_wpnonce'   => $nonce_bol,
					), admin_url( 'admin-post.php' ) );
				?>
					<li style="display:flex; justify-content:space-between; align-items:center; padding:10px 0;">
						<div>
							<strong><?php echo esc_html( $per->name ); ?></strong>
							<?php if ( $per->is_active ) : ?>
								<span style="margin-left:8px; font-size:11px; background:#dbeafe; color:#1e40af; padding:2px 8px; border-radius:99px; font-weight:600;">
									<?php esc_html_e( 'Activo', 'sistema-educativo' ); ?>
								</span>
							<?php endif; ?>
						</div>
						<a href="<?php echo esc_url( $dl_url ); ?>"
						   class="edu-btn edu-btn-outline edu-btn-sm"
						   target="_blank">
							&#128196; <?php esc_html_e( 'Descargar PDF', 'sistema-educativo' ); ?>
						</a>
					</li>
				<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php endif; ?>

		<?php /* ===================== PAGOS ===================== */ ?>
		<?php if ( 'pagos' === $tab ) : ?>

		<?php if ( 'success' === $pay_message ) : ?>
			<div style="padding:12px 16px; background:#dcfce7; color:#15803d; border-radius:8px; margin-bottom:16px; font-size:14px;">
				✅ <?php esc_html_e( 'Pago registrado exitosamente. ¡Gracias!', 'sistema-educativo' ); ?>
			</div>
		<?php elseif ( 'failed' === $pay_message ) : ?>
			<div style="padding:12px 16px; background:#fee2e2; color:#dc2626; border-radius:8px; margin-bottom:16px; font-size:14px;">
				❌ <?php esc_html_e( 'El pago no pudo completarse. Intenta de nuevo.', 'sistema-educativo' ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $_GET['edu_payment_error'] ) ) : ?>
			<div style="padding:12px 16px; background:#fee2e2; color:#dc2626; border-radius:8px; margin-bottom:16px; font-size:14px;">
				❌ <?php echo esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['edu_payment_error'] ) ) ) ); ?>
			</div>
		<?php endif; ?>

		<?php if ( empty( $pagos_hijo ) ) : ?>
			<div class="edu-card">
				<p style="color:#9ca3af; font-size:13px; text-align:center; padding:24px 0;">
					<?php esc_html_e( 'No hay registros de pago para el período actual.', 'sistema-educativo' ); ?>
				</p>
			</div>
		<?php else : ?>
		<div class="edu-card" style="padding:0; overflow:hidden;">
			<table style="width:100%; border-collapse:collapse; font-size:14px;">
				<thead>
					<tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
						<th style="padding:12px 16px; text-align:left; font-weight:600; color:#374151;"><?php esc_html_e( 'Mes', 'sistema-educativo' ); ?></th>
						<th style="padding:12px 16px; text-align:left; font-weight:600; color:#374151;"><?php esc_html_e( 'Concepto', 'sistema-educativo' ); ?></th>
						<th style="padding:12px 16px; text-align:right; font-weight:600; color:#374151;"><?php esc_html_e( 'Monto', 'sistema-educativo' ); ?></th>
						<th style="padding:12px 16px; text-align:left; font-weight:600; color:#374151;"><?php esc_html_e( 'Vence', 'sistema-educativo' ); ?></th>
						<th style="padding:12px 16px; text-align:left; font-weight:600; color:#374151;"><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
						<th style="padding:12px 16px; text-align:center; font-weight:600; color:#374151;"><?php esc_html_e( 'Acción', 'sistema-educativo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$pay_status_colors = array(
					'pending' => array( '#dbeafe', '#1d4ed8' ),
					'paid'    => array( '#dcfce7', '#15803d' ),
					'overdue' => array( '#fee2e2', '#dc2626' ),
					'waived'  => array( '#f3f4f6', '#6b7280' ),
				);
				foreach ( $pagos_hijo as $pay_item ) :
					$sc_p  = $pay_status_colors[ $pay_item->status ] ?? array( '#f3f4f6', '#374151' );
					$is_op = in_array( $pay_item->status, array( 'pending', 'overdue' ), true );
					$conceptos_p = array( 'pension' => __( 'Pensión', 'sistema-educativo' ), 'matricula' => __( 'Matrícula', 'sistema-educativo' ) );
				?>
				<tr style="border-bottom:1px solid #f1f5f9;">
					<td style="padding:12px 16px;"><?php echo esc_html( Edu_Payment_Manager::month_label( $pay_item->month ) ); ?></td>
					<td style="padding:12px 16px; color:#64748b;"><?php echo esc_html( $conceptos_p[ $pay_item->concept ] ?? $pay_item->concept ); ?></td>
					<td style="padding:12px 16px; text-align:right; font-weight:700; color:#1d4ed8;">$<?php echo esc_html( number_format( (float) $pay_item->amount, 2 ) ); ?></td>
					<td style="padding:12px 16px; font-size:12px; color:#64748b;"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $pay_item->due_date ) ) ); ?></td>
					<td style="padding:12px 16px;">
						<span style="font-size:11px; font-weight:600; color:<?php echo esc_attr( $sc_p[1] ); ?>; background:<?php echo esc_attr( $sc_p[0] ); ?>; padding:2px 10px; border-radius:99px;">
							<?php echo esc_html( Edu_Payment_Manager::status_label( $pay_item->status ) ); ?>
						</span>
						<?php if ( $pay_item->paid_at ) : ?>
							<div style="font-size:11px; color:#9ca3af; margin-top:2px;"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $pay_item->paid_at ) ) ); ?></div>
						<?php endif; ?>
					</td>
					<td style="padding:12px 16px; text-align:center;">
						<?php if ( $is_op ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="edu_init_payment">
							<input type="hidden" name="payment_id" value="<?php echo (int) $pay_item->id; ?>">
							<input type="hidden" name="_redirect" value="<?php echo esc_attr( add_query_arg( array( 'edu_tab' => 'pagos', 'edu_hijo' => $hijo_id ), $portal_padre_url ) ); ?>">
							<?php wp_nonce_field( 'edu_init_payment' ); ?>
							<button type="submit" style="padding:6px 14px; background:#1d4ed8; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;">
								💳 <?php esc_html_e( 'Pagar', 'sistema-educativo' ); ?>
							</button>
						</form>
						<?php else : ?>
							<span style="font-size:12px; color:#9ca3af;">—</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

		<?php endif; ?>

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
