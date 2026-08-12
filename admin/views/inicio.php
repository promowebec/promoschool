<?php
/**
 * Vista: Inicio del admin — dashboard según rol.
 *
 * Superadmin / Rector → métricas institucionales + alertas de asistencia baja.
 * Docente             → asistencia del día + accesos rápidos.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_id = Edu_Context::current_institution_id();
global $wpdb;
$p = $wpdb->prefix . 'edu_';

$is_rector  = Edu_Context::can( 'edu_view_all' );
$is_docente = Edu_Context::can( 'edu_take_attendance' ) && ! $is_rector;

$today      = date( 'Y-m-d' );
$month_start= date( 'Y-m-01' );
$month_end  = date( 'Y-m-t' );

// -------------------------------------------------------------------------
// Stats comunes (solo si hay institución activa)
// -------------------------------------------------------------------------
$stats = array(
	'estudiantes'      => 0,
	'docentes'         => 0,
	'grados'           => 0,
	'asistencia_hoy'   => array( 'presentes' => 0, 'total' => 0 ),
	'asistencia_mes'   => 0, // %
	'tareas_abiertas'  => 0,
	'alertas'          => array(), // grados con asistencia < umbral
);

if ( $current_id ) {
	$stats['estudiantes'] = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$p}students s
		 INNER JOIN {$p}grades g ON g.id = s.grade_id
		 WHERE g.institution_id = %d",
		$current_id
	) );
	// wp_edu_teachers no tiene institution_id: el vínculo del docente con su
	// institución vive en usermeta.edu_institution_id (mismo criterio que la
	// lista de admin/views/docentes.php).
	$stats['docentes'] = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$p}teachers t
		 INNER JOIN {$wpdb->usermeta} m
		         ON m.user_id = t.user_id
		        AND m.meta_key = 'edu_institution_id'
		        AND m.meta_value = %d",
		$current_id
	) );
	$stats['grados'] = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$p}grades WHERE institution_id = %d",
		$current_id
	) );
	$stats['tareas_abiertas'] = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$p}assignments a
		 INNER JOIN {$p}grades g ON g.id = a.grade_id
		 WHERE g.institution_id = %d AND a.status = 'published'",
		$current_id
	) );

	// Asistencia del día (general, subject_id IS NULL).
	$total_hoy = $stats['estudiantes'];
	$presentes_hoy = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$p}attendance a
		 INNER JOIN {$p}students s ON s.id = a.student_id
		 INNER JOIN {$p}grades g ON g.id = s.grade_id
		 WHERE g.institution_id = %d AND a.date = %s
		   AND a.status IN ('presente','atraso') AND a.subject_id IS NULL",
		$current_id, $today
	) );
	$stats['asistencia_hoy'] = array( 'presentes' => $presentes_hoy, 'total' => $total_hoy );

	// Asistencia % del mes.
	$registros_mes = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$p}attendance a
		 INNER JOIN {$p}students s ON s.id = a.student_id
		 INNER JOIN {$p}grades g ON g.id = s.grade_id
		 WHERE g.institution_id = %d AND a.date BETWEEN %s AND %s AND a.subject_id IS NULL",
		$current_id, $month_start, $month_end
	) );
	$presentes_mes = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$p}attendance a
		 INNER JOIN {$p}students s ON s.id = a.student_id
		 INNER JOIN {$p}grades g ON g.id = s.grade_id
		 WHERE g.institution_id = %d AND a.date BETWEEN %s AND %s
		   AND a.status IN ('presente','atraso') AND a.subject_id IS NULL",
		$current_id, $month_start, $month_end
	) );
	$stats['asistencia_mes'] = $registros_mes > 0
		? round( $presentes_mes / $registros_mes * 100 )
		: null;

	// Alertas: grados con asistencia mensual < 90%.
	if ( $is_rector ) {
		$grade_att = $wpdb->get_results( $wpdb->prepare(
			"SELECT g.id, g.name, g.paralelo,
			        SUM( CASE WHEN a.status IN ('presente','atraso') THEN 1 ELSE 0 END ) AS asist,
			        COUNT(*) AS total
			 FROM {$p}attendance a
			 INNER JOIN {$p}students s ON s.id = a.student_id
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 WHERE g.institution_id = %d AND a.date BETWEEN %s AND %s AND a.subject_id IS NULL
			 GROUP BY g.id",
			$current_id, $month_start, $month_end
		) );
		foreach ( $grade_att as $ga ) {
			if ( $ga->total > 0 ) {
				$pct = round( $ga->asist / $ga->total * 100 );
				if ( $pct < 90 ) {
					$stats['alertas'][] = array(
						'grado' => $ga->name . ' ' . $ga->paralelo,
						'pct'   => $pct,
					);
				}
			}
		}
	}
}

// -------------------------------------------------------------------------
// Docente: stats propios
// -------------------------------------------------------------------------
$docente_stats = array();
if ( $is_docente && $current_id ) {
	$teacher_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$p}teachers WHERE user_id = %d",
		get_current_user_id()
	) );
	if ( $teacher_id ) {
		// Grados asignados.
		$mis_grados = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT g.id, g.name, g.paralelo FROM {$p}teacher_assignments ta
			 INNER JOIN {$p}grades g ON g.id = ta.grade_id
			 WHERE ta.teacher_id = %d AND g.institution_id = %d",
			$teacher_id, $current_id
		) );

		// Asistencia del día para mis grados.
		$ids = array_column( $mis_grados, 'id' );
		$mis_presentes = 0;
		$mis_total     = 0;
		if ( $ids ) {
			$ids_ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$mis_total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}students WHERE grade_id IN ($ids_ph)",
				...$ids
			) );
			$mis_presentes = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}attendance a
				 INNER JOIN {$p}students s ON s.id = a.student_id
				 WHERE s.grade_id IN ($ids_ph) AND a.date = %s
				   AND a.status IN ('presente','atraso') AND a.subject_id IS NULL",
				...array_merge( $ids, array( $today ) )
			) );
		}

		$docente_stats = array(
			'grados'      => $mis_grados,
			'presentes'   => $mis_presentes,
			'total'       => $mis_total,
			'teacher_id'  => $teacher_id,
		);
	}
}
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Sistema Educativo · Inicio', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( ! $current_id ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'Seleccione una institución para ver el dashboard.', 'sistema-educativo' ); ?></p></div>
		<?php return; ?>
	<?php endif; ?>

	<?php if ( $is_rector ) : ?>
	<!-- ================================================================
	     DASHBOARD RECTOR / ADMIN
	     ============================================================= -->
	<div class="edu-stats-grid" style="grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap:16px; margin-bottom:24px;">

		<div class="edu-stat-card">
			<div class="edu-stat-label"><?php esc_html_e( 'Estudiantes', 'sistema-educativo' ); ?></div>
			<div class="edu-stat-value"><?php echo esc_html( $stats['estudiantes'] ); ?></div>
		</div>

		<div class="edu-stat-card">
			<div class="edu-stat-label"><?php esc_html_e( 'Docentes', 'sistema-educativo' ); ?></div>
			<div class="edu-stat-value"><?php echo esc_html( $stats['docentes'] ); ?></div>
		</div>

		<div class="edu-stat-card">
			<div class="edu-stat-label"><?php esc_html_e( 'Grados / paralelos', 'sistema-educativo' ); ?></div>
			<div class="edu-stat-value"><?php echo esc_html( $stats['grados'] ); ?></div>
		</div>

		<div class="edu-stat-card">
			<div class="edu-stat-label"><?php esc_html_e( 'Tareas publicadas', 'sistema-educativo' ); ?></div>
			<div class="edu-stat-value" style="color:#0073aa;"><?php echo esc_html( $stats['tareas_abiertas'] ); ?></div>
		</div>

		<div class="edu-stat-card">
			<div class="edu-stat-label"><?php esc_html_e( 'Asistencia hoy', 'sistema-educativo' ); ?></div>
			<div class="edu-stat-value" style="color:green;">
				<?php echo esc_html( $stats['asistencia_hoy']['presentes'] . ' / ' . $stats['asistencia_hoy']['total'] ); ?>
			</div>
		</div>

		<div class="edu-stat-card">
			<div class="edu-stat-label"><?php esc_html_e( 'Asistencia mes', 'sistema-educativo' ); ?></div>
			<div class="edu-stat-value" style="color:<?php echo null !== $stats['asistencia_mes'] ? ( $stats['asistencia_mes'] >= 90 ? 'green' : ( $stats['asistencia_mes'] >= 75 ? '#e08800' : '#b32d2e' ) ) : '#999'; ?>;">
				<?php echo null !== $stats['asistencia_mes'] ? esc_html( $stats['asistencia_mes'] . '%' ) : '—'; ?>
			</div>
		</div>

	</div>

	<?php if ( ! empty( $stats['alertas'] ) ) : ?>
	<div class="notice notice-warning" style="margin-bottom:20px;">
		<p><strong><?php esc_html_e( 'Alertas de asistencia baja (< 90% este mes):', 'sistema-educativo' ); ?></strong></p>
		<ul style="margin:4px 0 0 16px; list-style:disc;">
			<?php foreach ( $stats['alertas'] as $alerta ) : ?>
				<li>
					<strong><?php echo esc_html( $alerta['grado'] ); ?></strong>
					— <?php echo esc_html( $alerta['pct'] . '%' ); ?> <?php esc_html_e( 'promedio mensual', 'sistema-educativo' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=edu-asistencia&date=' . $today ) ); ?>" style="margin-left:8px; font-size:12px;">
						<?php esc_html_e( 'Ver asistencia', 'sistema-educativo' ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<div class="edu-quick-links">
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-asistencia' ) ); ?>"><?php esc_html_e( 'Asistencia', 'sistema-educativo' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-calificaciones' ) ); ?>"><?php esc_html_e( 'Calificaciones', 'sistema-educativo' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-tareas' ) ); ?>"><?php esc_html_e( 'Tareas', 'sistema-educativo' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-comunicados' ) ); ?>"><?php esc_html_e( 'Comunicados', 'sistema-educativo' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-estudiantes' ) ); ?>"><?php esc_html_e( 'Estudiantes', 'sistema-educativo' ); ?></a>
	</div>

	<?php elseif ( $is_docente ) : ?>
	<!-- ================================================================
	     DASHBOARD DOCENTE
	     ============================================================= -->
	<div class="edu-stats-grid" style="grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap:16px; margin-bottom:24px;">

		<div class="edu-stat-card">
			<div class="edu-stat-label"><?php esc_html_e( 'Asistencia hoy', 'sistema-educativo' ); ?></div>
			<div class="edu-stat-value" style="color:green;">
				<?php
				if ( ! empty( $docente_stats ) ) {
					echo esc_html( $docente_stats['presentes'] . ' / ' . $docente_stats['total'] );
				} else {
					echo '—';
				}
				?>
			</div>
		</div>

		<div class="edu-stat-card">
			<div class="edu-stat-label"><?php esc_html_e( 'Mis grados', 'sistema-educativo' ); ?></div>
			<div class="edu-stat-value"><?php echo ! empty( $docente_stats ) ? count( $docente_stats['grados'] ) : '—'; ?></div>
		</div>

		<div class="edu-stat-card">
			<div class="edu-stat-label"><?php esc_html_e( 'Tareas publicadas', 'sistema-educativo' ); ?></div>
			<div class="edu-stat-value" style="color:#0073aa;"><?php echo esc_html( $stats['tareas_abiertas'] ); ?></div>
		</div>

	</div>

	<div class="edu-quick-links">
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-asistencia' ) ); ?>"><?php esc_html_e( 'Tomar asistencia', 'sistema-educativo' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-calificaciones' ) ); ?>"><?php esc_html_e( 'Calificaciones', 'sistema-educativo' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-tareas&action=new' ) ); ?>"><?php esc_html_e( '+ Nueva tarea', 'sistema-educativo' ); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-comunicados&action=new' ) ); ?>"><?php esc_html_e( '+ Comunicado', 'sistema-educativo' ); ?></a>
	</div>

	<?php else : ?>
	<!-- Otros roles (estudiante, padre): acceso mínimo -->
	<p><?php esc_html_e( 'Bienvenido al Sistema Educativo.', 'sistema-educativo' ); ?></p>
	<?php endif; ?>

</div>
