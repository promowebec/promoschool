<?php
/**
 * Shortcode [edu_portal_estudiante] — Portal del estudiante.
 *
 * Tabs: inicio | notas | tareas | asistencia | comunicados
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

class Edu_Shortcode_Estudiante {

	public static function register() {
		add_shortcode( 'edu_portal_estudiante', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_edu_ack_announcement', array( __CLASS__, 'ajax_ack_announcement' ) );
	}

	public static function ajax_ack_announcement() {
		$id    = isset( $_POST['announcement_id'] ) ? (int) $_POST['announcement_id'] : 0;
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'edu_acknowledge_' . $id ) ) {
			wp_send_json_error( 'nonce', 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'auth', 403 );
		}

		global $wpdb;
		$tr = $wpdb->prefix . 'edu_announcement_recipients';
		$wpdb->update(
			$tr,
			array( 'read_at' => current_time( 'mysql' ) ),
			array(
				'announcement_id' => $id,
				'user_id'         => get_current_user_id(),
				'channel'         => 'portal',
				'read_at'         => null,
			),
			array( '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);

		wp_send_json_success( array( 'read_at' => current_time( 'mysql' ) ) );
	}

	public static function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'Debes iniciar sesión para acceder al portal.', 'sistema-educativo' ) .
				'</div></div>';
		}

		if ( ! Edu_Context::can( 'edu_view_own_grades' ) ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'No tienes permiso para ver este portal.', 'sistema-educativo' ) .
				'</div></div>';
		}

		wp_enqueue_style( 'edu-portales', EDU_PLUGIN_URL . 'public/css/portales.css', array(), EDU_VERSION );

		global $wpdb;
		$p   = $wpdb->prefix . 'edu_';
		$uid = get_current_user_id();

		$student = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.*, g.name AS grade_name, g.paralelo, g.institution_id, g.sub_level
			 FROM {$p}students s INNER JOIN {$p}grades g ON g.id = s.grade_id
			 WHERE s.user_id = %d",
			$uid
		) );

		if ( ! $student ) {
			return '<div class="edu-portal"><div class="edu-login-notice">' .
				esc_html__( 'No se encontró tu registro de estudiante. Contacta al administrador.', 'sistema-educativo' ) .
				'</div></div>';
		}

		$tab = isset( $_GET['edu_tab'] ) ? sanitize_key( $_GET['edu_tab'] ) : 'inicio';
		$valid_tabs = Edu_Modules::filter_tabs( array( 'inicio', 'notas', 'tareas', 'asistencia', 'comunicados', 'textos' ) );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'inicio';
		}

		$today       = date( 'Y-m-d' );
		$month_start = date( 'Y-m-01' );
		$month_end   = date( 'Y-m-t' );
		$current_url = get_permalink();
		$sid         = (int) $student->id;

		// ---- Tareas pendientes del estudiante ----
		$tareas_pendientes = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.id, a.title, a.type, a.status, a.due_date, a.max_score, a.description,
			        a.allow_recovery, a.recovery_due_date,
			        s.name AS subject_name,
			        u.display_name AS teacher_name,
			        sub.id AS sub_id, sub.status AS sub_status, sub.score AS sub_score, sub.feedback,
			        sub.recovery_status, sub.recovery_score, sub.recovery_feedback
			 FROM {$p}assignments a
			 INNER JOIN {$p}subjects s ON s.id = a.subject_id
			 LEFT JOIN {$p}teachers tr ON tr.id = a.teacher_id
			 LEFT JOIN {$wpdb->users} u ON u.ID = tr.user_id
			 LEFT JOIN {$p}submissions sub ON sub.assignment_id = a.id AND sub.student_id = %d
			 WHERE a.grade_id = %d AND a.status IN ('published','closed')
			 ORDER BY a.due_date ASC",
			$sid, (int) $student->grade_id
		) );

		$n_pendientes = count( array_filter( $tareas_pendientes, function( $t ) {
			return empty( $t->sub_id ) || in_array( $t->sub_status, array( 'submitted', 'late' ), true );
		} ) );

		// ---- Comunicados sin leer ----
		$n_sin_leer = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}announcement_recipients r
			 WHERE r.user_id = %d AND r.channel = 'portal' AND r.read_at IS NULL",
			$uid
		) );

		// ---- Notas por materia y trimestre (activo primero, si no el más reciente) ----
		$active_period_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}periods WHERE institution_id = %d ORDER BY is_active DESC, start_date DESC LIMIT 1",
			(int) $student->institution_id
		) );

		$notas = array();
		if ( $active_period_id ) {
			$notas = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.name AS subject_name, t.number AS trim_num,
				        ps.computed_score AS parcial_1, ps2.computed_score AS parcial_2,
				        ts.final_exam_score AS exam_score, ts.proyecto_score,
				        ts.computed_score AS trim_score
				 FROM {$p}grade_subjects gs
				 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
				 CROSS JOIN (SELECT id, number FROM {$p}trimesters WHERE period_id = %d) t
				 LEFT JOIN {$p}parcial_scores ps  ON ps.student_id  = %d AND ps.subject_id  = gs.subject_id AND ps.trimester_id = t.id AND ps.parcial_num = 1
				 LEFT JOIN {$p}parcial_scores ps2 ON ps2.student_id = %d AND ps2.subject_id = gs.subject_id AND ps2.trimester_id = t.id AND ps2.parcial_num = 2
				 LEFT JOIN {$p}trimester_scores ts ON ts.student_id = %d AND ts.subject_id   = gs.subject_id AND ts.trimester_id = t.id
				 WHERE gs.grade_id = %d
				 ORDER BY s.name, t.number",
				$active_period_id, $sid, $sid, $sid, (int) $student->grade_id
			) );
		}

		// Agrupar notas por materia.
		$notas_por_materia = array();
		foreach ( $notas as $n ) {
			if ( ! isset( $notas_por_materia[ $n->subject_name ] ) ) {
				$notas_por_materia[ $n->subject_name ] = array();
			}
			$notas_por_materia[ $n->subject_name ][ (int) $n->trim_num ] = $n;
		}

		// ---- Asistencia del mes (general o por materia; un registro por día) ----
		$att_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT date, status, justification, subject_id
			 FROM {$p}attendance
			 WHERE student_id = %d AND date BETWEEN %s AND %s
			 ORDER BY date DESC, (subject_id IS NULL) DESC",
			$sid, $month_start, $month_end
		) );
		// Quedarse con un registro por fecha: prefiere el general (subject_id IS NULL).
		$att_por_fecha = array();
		foreach ( $att_raw as $row ) {
			if ( ! isset( $att_por_fecha[ $row->date ] ) ) {
				$att_por_fecha[ $row->date ] = $row;
			}
		}
		$att_mes = array_values( $att_por_fecha );
		$att_counts = array( 'presente' => 0, 'atraso' => 0, 'falta_justificada' => 0, 'falta_injustificada' => 0 );
		foreach ( $att_mes as $a ) {
			$att_counts[ $a->status ] = ( $att_counts[ $a->status ] ?? 0 ) + 1;
		}
		$att_total_mes = array_sum( $att_counts );
		$pct_asist     = $att_total_mes > 0
			? round( ( $att_counts['presente'] + $att_counts['atraso'] ) / $att_total_mes * 100 )
			: null;

		// ---- Comunicados del estudiante ----
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

		// Promedio actual
		$promedio_actual = null;
		if ( ! empty( $notas_por_materia ) ) {
			$sumas = array();
			foreach ( $notas_por_materia as $mat => $trims ) {
				foreach ( $trims as $tn => $n ) {
					if ( null !== $n->trim_score ) {
						$sumas[] = (float) $n->trim_score;
					}
				}
			}
			if ( $sumas ) {
				$promedio_actual = round( array_sum( $sumas ) / count( $sumas ), 2 );
			}
		}

		$user       = wp_get_current_user();
		$first_name = $user->first_name ?: '';
		$last_name  = $user->last_name  ?: '';
		if ( ! $first_name && ! $last_name ) {
			$parts      = explode( ' ', $user->display_name, 2 );
			$first_name = $parts[0] ?? '';
			$last_name  = $parts[1] ?? '';
		}
		$display  = $user->display_name ?: trim( $first_name . ' ' . $last_name );
		$initials = strtoupper( mb_substr( $first_name, 0, 1 ) . mb_substr( $last_name, 0, 1 ) );

		$status_labels = array(
			'presente'            => __( 'Presente', 'sistema-educativo' ),
			'atraso'              => __( 'Atraso', 'sistema-educativo' ),
			'falta_justificada'   => __( 'Falta J.', 'sistema-educativo' ),
			'falta_injustificada' => __( 'Falta I.', 'sistema-educativo' ),
		);

		// Tarea abierta para entregar (regular o mejora).
		$tarea_open   = isset( $_GET['edu_entregar'] )        ? (int) $_GET['edu_entregar']        : 0;
		$mejora_open  = isset( $_GET['edu_entregar_mejora'] ) ? (int) $_GET['edu_entregar_mejora'] : 0;

		// Inyectar JS de acuse de recibo en footer (una sola vez).
		static $ack_script_added = false;
		if ( ! $ack_script_added ) {
			$ack_script_added = true;
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
	fetch("' . $_ack_ajax . '",{method:"POST",body:fd})
		.then(function(r){return r.json();})
		.then(function(resp){
			if(resp.success){
				// Quitar punto azul
				var dot=wrap.querySelector("span[style*=\'border-radius:50%\']");
				if(dot)dot.remove();
				// Cambiar badge Nuevo → Leído
				var badge=wrap.querySelector(".edu-badge-blue");
				if(badge){badge.className="edu-badge-status edu-badge-green";badge.innerHTML="&#10003; ' . esc_js( __( 'Leído', 'sistema-educativo' ) ) . '";}
				// Reemplazar botón con mensaje de confirmación
				var ackWrap=btn.closest(".edu-ack-wrap");
				if(ackWrap){
					var p=document.createElement("p");
					p.style.cssText="font-size:12px;color:#16a34a;margin-top:10px;border-top:1px solid #e5e7eb;padding-top:8px;";
					p.innerHTML="&#10003; ' . esc_js( __( 'Lectura confirmada', 'sistema-educativo' ) ) . '";
					ackWrap.parentNode.replaceChild(p,ackWrap);
				}
				// Quitar clase unread
				if(wrap)wrap.classList.remove("unread");
			}else{
				btn.disabled=false;
				btn.textContent="&#10003; ' . esc_js( __( 'Confirmar lectura', 'sistema-educativo' ) ) . '";
			}
		})
		.catch(function(){
			btn.disabled=false;
			btn.textContent="&#10003; ' . esc_js( __( 'Confirmar lectura', 'sistema-educativo' ) ) . '";
		});
}
</script>';
			}, 20 );
		}

		// Institución y período
		$inst_name_e   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$p}institutions WHERE id = %d", (int) $student->institution_id ) );
		$period_row_e  = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$p}periods WHERE institution_id = %d AND is_active = 1 LIMIT 1", (int) $student->institution_id ) );
		$period_name_e = $period_row_e ? $period_row_e->name : '';
		$hour_e        = (int) date( 'G' );
		$saludo_e      = $hour_e < 12 ? '¡Buenos días' : ( $hour_e < 19 ? '¡Buenas tardes' : '¡Buenas noches' );
		$page_urls_e   = array(
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
					<div class="edu-topbar-sub"><?php echo esc_html( $inst_name_e ); ?><?php echo $period_name_e ? ' · ' . esc_html( $period_name_e ) : ''; ?></div>
				</div>
			</div>
			<div class="edu-topbar-roles">
				<?php
				$roles_e = array(
					'docente'    => array( 'icon' => '&#128202;', 'label' => __( 'Docente',    'sistema-educativo' ) ),
					'padre'      => array( 'icon' => '&#128106;', 'label' => __( 'Padre',      'sistema-educativo' ) ),
					'estudiante' => array( 'icon' => '&#127891;', 'label' => __( 'Estudiante', 'sistema-educativo' ) ),
					'rector'     => array( 'icon' => '&#127894;', 'label' => __( 'Rector',     'sistema-educativo' ) ),
				);
				foreach ( $roles_e as $rk => $rd ) :
					$ru = $page_urls_e[ $rk ] ?? '';
					if ( ! $ru ) continue;
				?>
				<a href="<?php echo esc_url( $ru ); ?>" class="edu-role-btn<?php echo 'estudiante' === $rk ? ' active' : ''; ?>">
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
				<div class="edu-avatar" style="background:#d97706;"><?php echo esc_html( $initials ); ?></div>
				<div class="edu-user-name"><?php echo esc_html( $display ); ?></div>
				<div class="edu-user-role"><?php echo esc_html( $student->grade_name . ' ' . $student->paralelo ); ?></div>
				<div class="edu-sidenav-sep"></div>
				<div class="edu-sidenav-section"><?php esc_html_e( 'ESTUDIANTE', 'sistema-educativo' ); ?></div>
				<nav class="edu-sidenav">
					<?php
					$sidenav_e = array(
						'inicio'      => array( 'icon' => '&#127968;', 'label' => __( 'Inicio',       'sistema-educativo' ) ),
						'notas'       => array( 'icon' => '&#128202;', 'label' => __( 'Mis notas',    'sistema-educativo' ) ),
						'tareas'      => array( 'icon' => '&#128203;', 'label' => __( 'Mis tareas',   'sistema-educativo' ) ),
						'asistencia'  => array( 'icon' => '&#9989;',   'label' => __( 'Asistencia',   'sistema-educativo' ) ),
						'comunicados' => array( 'icon' => '&#128226;', 'label' => __( 'Comunicados',  'sistema-educativo' ) ),
						'textos'      => array( 'icon' => '&#128216;', 'label' => __( 'Mis textos',   'sistema-educativo' ) ),
					);
					$sidenav_e = Edu_Modules::filter_sidenav( $sidenav_e );
					foreach ( $sidenav_e as $sk => $sd ) :
					?>
					<a href="<?php echo esc_url( add_query_arg( 'edu_tab', $sk, $current_url ) ); ?>"
					   class="<?php echo $tab === $sk ? 'active' : ''; ?>">
						<span class="edu-nav-icon"><?php echo $sd['icon']; ?></span>
						<?php echo esc_html( $sd['label'] ); ?>
						<?php if ( 'tareas' === $sk && $n_pendientes > 0 ) : ?>
							<span class="edu-badge"><?php echo (int) $n_pendientes; ?></span>
						<?php endif; ?>
						<?php if ( 'comunicados' === $sk && $n_sin_leer > 0 ) : ?>
							<span class="edu-badge"><?php echo (int) $n_sin_leer; ?></span>
						<?php endif; ?>
					</a>
					<?php endforeach; ?>
				</nav>
			</div>
		</aside>

		<!-- ── Contenido principal ── -->
		<main class="edu-content">
		<?php
		$titles_e = array(
			'inicio'      => $saludo_e . ', ' . $first_name . '! 👋',
			'notas'       => __( 'Mis notas',   'sistema-educativo' ),
			'tareas'      => __( 'Mis tareas',  'sistema-educativo' ),
			'asistencia'  => __( 'Asistencia',  'sistema-educativo' ),
			'comunicados' => __( 'Comunicados', 'sistema-educativo' ),
			'textos'      => __( 'Mis textos',  'sistema-educativo' ),
		);
		$subs_e = array(
			'inicio' => $n_pendientes > 0
				? sprintf( _n( 'Tienes %d tarea pendiente esta semana.', 'Tienes %d tareas pendientes esta semana.', $n_pendientes, 'sistema-educativo' ), $n_pendientes )
				: __( 'Estás al día con tus tareas.', 'sistema-educativo' ),
		);
		?>
		<div class="edu-content-header">
			<h1><?php echo esc_html( $titles_e[ $tab ] ?? '' ); ?></h1>
			<?php if ( isset( $subs_e[ $tab ] ) ) : ?>
			<p><?php echo esc_html( $subs_e[ $tab ] ); ?></p>
			<?php endif; ?>
		</div>

		<?php /* ===================== INICIO ===================== */ ?>
		<?php if ( 'inicio' === $tab ) : ?>

		<div class="edu-stats-row" style="grid-template-columns: repeat(2, 1fr);">
			<div class="edu-stat" style="background: linear-gradient(135deg,#1d4ed8,#3b82f6); border:none;">
				<div class="edu-stat-label" style="color:rgba(255,255,255,.75);"><?php esc_html_e( 'Promedio actual', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#fff;"><?php echo null !== $promedio_actual ? esc_html( $promedio_actual ) : '—'; ?></div>
			</div>
			<div class="edu-stat" style="background: linear-gradient(135deg,#059669,#10b981); border:none;">
				<div class="edu-stat-label" style="color:rgba(255,255,255,.75);"><?php esc_html_e( 'Asistencia mes', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#fff;"><?php echo null !== $pct_asist ? esc_html( $pct_asist . '%' ) : '—'; ?></div>
			</div>
			<div class="edu-stat" style="background: linear-gradient(135deg,#d97706,#f59e0b); border:none;">
				<div class="edu-stat-label" style="color:rgba(255,255,255,.75);"><?php esc_html_e( 'Tareas pendientes', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#fff;"><?php echo esc_html( $n_pendientes ); ?></div>
			</div>
			<div class="edu-stat" style="background: linear-gradient(135deg,#7c3aed,#a78bfa); border:none;">
				<div class="edu-stat-label" style="color:rgba(255,255,255,.75);"><?php esc_html_e( 'Comunicados nuevos', 'sistema-educativo' ); ?></div>
				<div class="edu-stat-value" style="color:#fff;"><?php echo esc_html( $n_sin_leer ); ?></div>
			</div>
		</div>

		<!-- Tareas pendientes rápidas -->
		<?php
		$pendientes_rapidas = array_filter( $tareas_pendientes, function( $t ) {
			return empty( $t->sub_id ) || in_array( $t->sub_status, array( 'submitted', 'late' ), true );
		} );
		$pendientes_rapidas = array_slice( array_values( $pendientes_rapidas ), 0, 5 );
		?>
		<div class="edu-card">
			<h3><?php esc_html_e( 'Tareas pendientes', 'sistema-educativo' ); ?></h3>
			<?php if ( empty( $pendientes_rapidas ) ) : ?>
				<p style="color:#16a34a; font-size:13px;">&#10003; <?php esc_html_e( '¡Al día! No tienes tareas pendientes.', 'sistema-educativo' ); ?></p>
			<?php else : ?>
			<ul class="edu-list">
			<?php foreach ( $pendientes_rapidas as $t ) :
				$dias  = $t->due_date ? (int) ceil( ( strtotime( $t->due_date ) - time() ) / 86400 ) : null;
				$color = null === $dias ? '#6b7280' : ( $dias <= 0 ? '#dc2626' : ( $dias <= 2 ? '#d97706' : '#6b7280' ) );
				$dlabel = null === $dias ? '' : ( $dias <= 0 ? __( 'Vencida', 'sistema-educativo' ) : ( $dias === 1 ? __( 'Vence hoy', 'sistema-educativo' ) : sprintf( __( 'en %d días', 'sistema-educativo' ), $dias ) ) );
			?>
				<li style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
					<div>
						<strong><?php echo esc_html( $t->title ); ?></strong>
						<div class="edu-list-meta"><?php echo esc_html( $t->subject_name ); ?> · <?php echo esc_html( $t->teacher_name ?? '' ); ?></div>
					</div>
					<div style="display:flex; gap:8px; align-items:center;">
						<?php if ( $dlabel ) : ?>
							<span style="font-size:12px; color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $dlabel ); ?></span>
						<?php endif; ?>
						<?php if ( 'published' === ( $t->status ?? '' ) ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_entregar' => $t->id ), $current_url ) ); ?>" class="edu-btn edu-btn-primary edu-btn-sm">
							<?php esc_html_e( 'Entregar', 'sistema-educativo' ); ?>
						</a>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>

		<?php /* ===================== NOTAS ===================== */ ?>
		<?php elseif ( 'notas' === $tab ) : ?>

		<?php
		$usa_sumativa_e = in_array( $student->sub_level ?? '', array( 'media', 'superior', 'bg', 'bt' ), true );
		?>
		<?php if ( empty( $notas_por_materia ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'Aún no hay calificaciones registradas.', 'sistema-educativo' ); ?></div>
		<?php else : ?>
		<div class="edu-card" style="padding:0;">
			<div class="edu-table-wrap">
			<table class="edu-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></th>
						<th class="center">T1</th>
						<th class="center">T2</th>
						<th class="center">T3</th>
						<th class="center bg-blue"><?php esc_html_e( 'Promedio', 'sistema-educativo' ); ?></th>
						<th class="center"><?php esc_html_e( 'Cuali.', 'sistema-educativo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $notas_por_materia as $materia => $trims ) :
					$scores = array();
					for ( $tn = 1; $tn <= 3; $tn++ ) {
						$scores[ $tn ] = isset( $trims[ $tn ] ) && null !== $trims[ $tn ]->trim_score
							? round( (float) $trims[ $tn ]->trim_score, 2 )
							: null;
					}
					$valid_scores = array_filter( $scores, fn( $v ) => null !== $v );
					$prom_mat     = $valid_scores ? round( array_sum( $valid_scores ) / count( $valid_scores ), 2 ) : null;
					$color_prom   = null === $prom_mat ? '#9ca3af' : ( $prom_mat >= 7 ? '#1d4ed8' : '#dc2626' );
				?>
					<tr>
						<td><?php echo esc_html( $materia ); ?></td>
						<?php for ( $tn = 1; $tn <= 3; $tn++ ) : ?>
							<td class="center">
								<?php if ( null !== $scores[ $tn ] ) :
									$cual_tn = Edu_Qualitativa_Helper::codigo( $scores[ $tn ] );
									$cual_color_tn = Edu_Qualitativa_Helper::color( $cual_tn );
								?>
									<span style="<?php echo $scores[ $tn ] < 7 ? 'color:#dc2626; font-weight:600;' : ''; ?>"><?php echo esc_html( $scores[ $tn ] ); ?></span>
									<br><span style="font-size:10px; font-weight:700; color:<?php echo esc_attr( $cual_color_tn ); ?>;"><?php echo esc_html( $cual_tn ); ?></span>
								<?php else : ?>
									<span style="color:#d1d5db;">—</span>
								<?php endif; ?>
							</td>
						<?php endfor; ?>
						<td class="center bg-blue" style="color:<?php echo esc_attr( $color_prom ); ?>; font-weight:700;">
							<?php echo null !== $prom_mat ? esc_html( $prom_mat ) : '—'; ?>
						</td>
						<td class="center">
							<?php if ( null !== $prom_mat ) : ?>
								<?php echo Edu_Qualitativa_Helper::badge( $prom_mat, $student->sub_level ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
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
			<?php if ( $usa_sumativa_e ) : ?>
				&#128204; <?php esc_html_e( 'Fórmula: Nota T = Formativa × 70% + ((Examen + Proyecto) / 2) × 30% | Promedio = (T1+T2+T3) / 3', 'sistema-educativo' ); ?>
			<?php else : ?>
				&#128204; <?php esc_html_e( 'Fórmula: Nota T = ((P1+P2)/2 × 70%) + Examen × 30% | Promedio = (T1+T2+T3) / 3', 'sistema-educativo' ); ?>
			<?php endif; ?>
		</p>
		<?php endif; ?>

		<?php /* ===================== TAREAS ===================== */ ?>
		<?php elseif ( 'tareas' === $tab ) : ?>

		<?php if ( $mejora_open ) :
			$mejora_data = null;
			foreach ( $tareas_pendientes as $t ) {
				if ( (int) $t->id === $mejora_open ) { $mejora_data = $t; break; }
			}
			// Disponible para TODOS: allow_recovery activo + no enviada/calificada aún + fecha no vencida.
			$mejora_rec_st     = $mejora_data ? ( $mejora_data->recovery_status ?? 'none' ) : 'none';
			$mejora_disponible = $mejora_data
				&& ! empty( $mejora_data->allow_recovery )
				&& ! in_array( $mejora_rec_st, array( 'submitted', 'graded' ), true )
				&& ( ! $mejora_data->recovery_due_date || strtotime( $mejora_data->recovery_due_date ) >= time() );
			if ( $mejora_disponible ) :
		?>
		<!-- Formulario de entrega de mejora -->
		<div class="edu-card">
			<p style="margin:0 0 12px;"><a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'tareas', $current_url ) ); ?>" style="color:#1d4ed8; font-size:13px;">&#8592; <?php esc_html_e( 'Volver', 'sistema-educativo' ); ?></a></p>
			<div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:6px; padding:8px 14px; font-size:13px; color:#92400e; margin-bottom:14px;">
				<strong>&#9650; <?php esc_html_e( 'Entrega de mejora', 'sistema-educativo' ); ?></strong>
				<?php if ( $mejora_data->recovery_due_date ) : ?>
					&nbsp;·&nbsp; <?php esc_html_e( 'Fecha límite:', 'sistema-educativo' ); ?>
					<strong><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $mejora_data->recovery_due_date ) ) ); ?></strong>
				<?php endif; ?>
				<br><small><?php esc_html_e( 'Se conservará la mejor nota entre la original y esta mejora.', 'sistema-educativo' ); ?></small>
			</div>
			<h3><?php echo esc_html( $mejora_data->title ); ?></h3>
			<p style="font-size:13px; color:#6b7280; margin-bottom:12px;">
				<?php echo esc_html( $mejora_data->subject_name ); ?>
				<?php if ( null !== $mejora_data->sub_score ) : ?>
					· <?php esc_html_e( 'Tu nota original:', 'sistema-educativo' ); ?>
					<strong style="color:#1d4ed8;"><?php echo esc_html( number_format( (float) $mejora_data->sub_score, 2 ) ); ?> / <?php echo esc_html( number_format( (float) $mejora_data->max_score, 2 ) ); ?></strong>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'edu_submit_recovery' ); ?>
				<input type="hidden" name="action"        value="edu_submit_recovery">
				<input type="hidden" name="assignment_id" value="<?php echo (int) $mejora_open; ?>">
				<input type="hidden" name="_redirect"     value="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_status' => 'recovery_submitted' ), $current_url ) ); ?>">
				<div class="edu-form-row">
					<label><?php esc_html_e( 'Archivos de mejora (PDF, Word, imágenes, ZIP — máx. 10 MB)', 'sistema-educativo' ); ?></label>
					<input type="file" name="archivos_recovery[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.zip">
				</div>
				<div class="edu-form-row">
					<label><?php esc_html_e( 'Comentario (opcional)', 'sistema-educativo' ); ?></label>
					<textarea name="recovery_comment" rows="3" placeholder="<?php esc_attr_e( 'Describe tu mejora...', 'sistema-educativo' ); ?>"></textarea>
				</div>
				<button type="submit" class="edu-btn edu-btn-primary">
					<?php esc_html_e( 'Enviar mejora', 'sistema-educativo' ); ?>
				</button>
			</form>
		</div>
		<?php else : ?>
			<div class="edu-alert edu-alert-yellow"><?php esc_html_e( 'La mejora no está disponible para esta tarea.', 'sistema-educativo' ); ?></div>
		<?php endif; ?>

		<?php elseif ( $tarea_open ) :
			$tarea_data = null;
			foreach ( $tareas_pendientes as $t ) {
				if ( (int) $t->id === $tarea_open ) { $tarea_data = $t; break; }
			}
			if ( $tarea_data && 'published' === $tarea_data->status ) :
		?>
		<!-- Formulario de entrega -->
		<div class="edu-card">
			<p style="margin:0 0 12px;"><a href="<?php echo esc_url( add_query_arg( 'edu_tab', 'tareas', $current_url ) ); ?>" style="color:#1d4ed8; font-size:13px;">&#8592; <?php esc_html_e( 'Volver', 'sistema-educativo' ); ?></a></p>
			<h3><?php echo esc_html( $tarea_data->title ); ?></h3>
			<p style="font-size:13px; color:#6b7280; margin-bottom:12px;">
				<?php echo esc_html( $tarea_data->subject_name ); ?>
				<?php if ( $tarea_data->due_date ) : ?>
					· <?php esc_html_e( 'Vence:', 'sistema-educativo' ); ?> <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $tarea_data->due_date ) ) ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $tarea_data->description ) : ?>
				<div style="background:#f9fafb; border-radius:6px; padding:12px 14px; font-size:13px; margin-bottom:16px;">
					<?php echo wp_kses_post( $tarea_data->description ); ?>
				</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'edu_submit_assignment' ); ?>
				<input type="hidden" name="action"        value="edu_submit_assignment">
				<input type="hidden" name="assignment_id" value="<?php echo (int) $tarea_open; ?>">
				<input type="hidden" name="_redirect"     value="<?php echo esc_url( add_query_arg( 'edu_tab', 'tareas', $current_url ) ); ?>">
				<div class="edu-form-row">
					<label><?php esc_html_e( 'Archivos (PDF, Word, imágenes, ZIP — máx. 10 MB)', 'sistema-educativo' ); ?></label>
					<input type="file" name="archivos[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.zip">
				</div>
				<div class="edu-form-row">
					<label><?php esc_html_e( 'Comentario al docente (opcional)', 'sistema-educativo' ); ?></label>
					<textarea name="comment" rows="3" placeholder="<?php esc_attr_e( 'Escribe un comentario...', 'sistema-educativo' ); ?>"></textarea>
				</div>
				<button type="submit" class="edu-btn edu-btn-green">
					<?php esc_html_e( 'Enviar entrega', 'sistema-educativo' ); ?>
				</button>
			</form>
		</div>
		<?php else : ?>
			<div class="edu-alert edu-alert-yellow"><?php esc_html_e( 'Esta tarea ya no está disponible para entrega.', 'sistema-educativo' ); ?></div>
		<?php endif; ?>

		<?php else : ?>
		<!-- Lista de tareas -->
		<?php
		$edu_status = isset( $_GET['edu_status'] ) ? sanitize_key( $_GET['edu_status'] ) : '';
		$edu_code   = isset( $_GET['edu_code'] )   ? sanitize_key( $_GET['edu_code'] )   : '';
		if ( 'submitted' === $edu_status ) : ?>
			<div class="edu-alert edu-alert-green">&#10003; <?php esc_html_e( 'Tarea entregada correctamente.', 'sistema-educativo' ); ?></div>
		<?php elseif ( 'recovery_submitted' === $edu_status ) : ?>
			<div class="edu-alert edu-alert-green">&#9650; <?php esc_html_e( 'Mejora enviada correctamente. El docente la calificará pronto.', 'sistema-educativo' ); ?></div>
		<?php elseif ( 'error' === $edu_status ) : ?>
			<div class="edu-alert edu-alert-red"><?php esc_html_e( 'Error al enviar la entrega.', 'sistema-educativo' ); ?></div>
		<?php endif; ?>

		<?php if ( empty( $tareas_pendientes ) ) : ?>
			<div class="edu-alert edu-alert-blue"><?php esc_html_e( 'No hay tareas publicadas para tu grado.', 'sistema-educativo' ); ?></div>
		<?php else : ?>
		<?php foreach ( $tareas_pendientes as $t ) :
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
			// Disponible para TODOS: allow_recovery activo, no enviada/calificada, fecha no vencida.
			$mejora_disponible = $mejora_activa
				&& ! in_array( $rec_status, array( 'submitted', 'graded' ), true )
				&& ( ! $t->recovery_due_date || strtotime( $t->recovery_due_date ) >= time() );
			$mejora_enviada    = $mejora_activa && 'submitted' === $rec_status;
			$mejora_calificada = $mejora_activa && 'graded' === $rec_status;
		?>
			<div class="edu-tarea-card">
				<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
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
						&#128197; <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $t->due_date ) ) ); ?>
					<?php endif; ?>
					<?php if ( 'graded' === $sub_status && null !== $t->sub_score ) : ?>
						· <?php esc_html_e( 'Nota:', 'sistema-educativo' ); ?> <strong style="color:#1d4ed8;"><?php echo esc_html( number_format( (float) $t->sub_score, 2 ) ); ?> / <?php echo esc_html( number_format( (float) $t->max_score, 2 ) ); ?></strong>
					<?php endif; ?>
					<?php if ( $mejora_calificada && null !== $t->recovery_score ) :
						$mejor = max( (float) $t->sub_score, (float) $t->recovery_score );
					?>
						· <?php esc_html_e( 'Nota mejora:', 'sistema-educativo' ); ?> <strong style="color:#d97706;"><?php echo esc_html( number_format( (float) $t->recovery_score, 2 ) ); ?></strong>
						&nbsp;<span style="font-size:11px; color:#16a34a;">(<?php esc_html_e( 'mejor:', 'sistema-educativo' ); ?> <strong><?php echo esc_html( number_format( $mejor, 2 ) ); ?></strong>)</span>
					<?php endif; ?>
				</div>
				<?php if ( 'graded' === $sub_status && $t->feedback ) : ?>
					<div style="background:#f0fdf4; border-radius:6px; padding:8px 12px; font-size:13px; color:#166534; margin-top:4px;">
						&#128172; <?php echo wp_kses_post( $t->feedback ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $mejora_calificada && $t->recovery_feedback ) : ?>
					<div style="background:#fffbeb; border-radius:6px; padding:8px 12px; font-size:13px; color:#92400e; margin-top:4px;">
						&#128172; <?php esc_html_e( 'Retroalimentación mejora:', 'sistema-educativo' ); ?> <?php echo wp_kses_post( $t->recovery_feedback ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $mejora_disponible ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_entregar_mejora' => $t->id ), $current_url ) ); ?>" class="edu-btn edu-btn-sm" style="margin-top:8px; display:inline-block; background:#fffbeb; color:#92400e; border:1px solid #fcd34d;">
						&#9650; <?php esc_html_e( 'Entregar mejora', 'sistema-educativo' ); ?>
					</a>
				<?php elseif ( $mejora_enviada ) : ?>
					<p style="font-size:12px; color:#1d4ed8; margin-top:8px;">&#9650; <?php esc_html_e( 'Mejora enviada. Esperando calificación del docente.', 'sistema-educativo' ); ?></p>
				<?php elseif ( 'published' === $t->status && ( ! $sub_status || 'late' === $sub_status ) ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'edu_tab' => 'tareas', 'edu_entregar' => $t->id ), $current_url ) ); ?>" class="edu-btn edu-btn-primary edu-btn-sm" style="margin-top:8px; display:inline-block;">
						<?php esc_html_e( 'Entregar', 'sistema-educativo' ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<?php endif; ?>
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
				<h3><?php esc_html_e( 'Registro detallado', 'sistema-educativo' ); ?></h3>
				<?php if ( empty( $att_mes ) ) : ?>
					<p style="color:#9ca3af; font-size:13px;"><?php esc_html_e( 'Sin registros este mes.', 'sistema-educativo' ); ?></p>
				<?php else : ?>
				<div class="edu-table-wrap">
				<table class="edu-table">
					<thead><tr>
						<th><?php esc_html_e( 'Fecha', 'sistema-educativo' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $att_mes as $a ) : ?>
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

		<?php /* ═══════════════════ MIS TEXTOS ═══════════════════ */ ?>
		<?php elseif ( 'textos' === $tab ) : ?>

		<div class="edu-section">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo do_shortcode( '[mis_textos]' );
			?>
		</div>

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
