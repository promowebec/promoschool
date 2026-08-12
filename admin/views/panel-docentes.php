<?php
/**
 * Vista: Panel de docentes (solo rector/admin).
 *
 * Vista consolidada por asignación académica (docente + grado + materia):
 * cuántos componentes tiene el parcial (institucionales y propios del docente),
 * cuántas tareas creó y cuáles alimentan notas, cuántas notas registró,
 * avance de calificación sobre el total de estudiantes y última actividad.
 *
 * Con ?edu_pd_detail=<ta_id> se expande el detalle de componentes de esa
 * asignación: peso, origen, notas registradas, promedio y tareas vinculadas.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_view_all' ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

$institution_id = Edu_Context::current_institution_id();
if ( ! $institution_id ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Panel de docentes', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

global $wpdb;
$tta = $wpdb->prefix . 'edu_teacher_assignments';
$tte = $wpdb->prefix . 'edu_teachers';
$tg  = $wpdb->prefix . 'edu_grades';
$ts  = $wpdb->prefix . 'edu_subjects';
$tt  = $wpdb->prefix . 'edu_trimesters';
$tc  = $wpdb->prefix . 'edu_grade_components';
$tl  = $wpdb->prefix . 'edu_grades_log';
$tst = $wpdb->prefix . 'edu_students';
$ta  = $wpdb->prefix . 'edu_assignments';

$f_grade   = isset( $_GET['edu_pd_grade'] )   ? (int) $_GET['edu_pd_grade']   : 0;
$f_teacher = isset( $_GET['edu_pd_teacher'] ) ? (int) $_GET['edu_pd_teacher'] : 0;
$detail_id = isset( $_GET['edu_pd_detail'] )  ? (int) $_GET['edu_pd_detail']  : 0;

// Filtros: grados y docentes de la institución.
$all_grades = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY name, paralelo",
	$institution_id
) );
$all_teachers = $wpdb->get_results( $wpdb->prepare(
	"SELECT DISTINCT te.id, u.display_name
	 FROM $tte te
	 INNER JOIN {$wpdb->users} u ON u.ID = te.user_id
	 INNER JOIN $tta x ON x.teacher_id = te.id AND x.is_active = 1
	 INNER JOIN $tg g ON g.id = x.grade_id
	 WHERE g.institution_id = %d
	 ORDER BY u.display_name",
	$institution_id
) );

// Asignaciones activas (base del panel).
$sql = "SELECT ta2.id, ta2.teacher_id, ta2.grade_id, ta2.subject_id, ta2.period_id,
               te.user_id AS teacher_user_id, u.display_name AS docente,
               g.name AS grade_name, g.paralelo, s.name AS subject_name
        FROM $tta ta2
        INNER JOIN $tte te ON te.id = ta2.teacher_id
        INNER JOIN {$wpdb->users} u ON u.ID = te.user_id
        INNER JOIN $tg g ON g.id = ta2.grade_id
        INNER JOIN $ts s ON s.id = ta2.subject_id
        WHERE ta2.is_active = 1 AND g.institution_id = %d";
$params = array( $institution_id );
if ( $f_grade ) {
	$sql     .= ' AND ta2.grade_id = %d';
	$params[] = $f_grade;
}
if ( $f_teacher ) {
	$sql     .= ' AND ta2.teacher_id = %d';
	$params[] = $f_teacher;
}
$sql .= ' ORDER BY u.display_name, g.name, g.paralelo, s.name';

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders construidos arriba.
$asignaciones = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

/**
 * Métricas por asignación.
 */
$edu_pd_metrics = function ( $row ) use ( $wpdb, $tt, $tc, $tl, $tst, $ta ) {
	$trim_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM $tt WHERE period_id = %d",
		(int) $row->period_id
	) );
	$m = array(
		'comp_total'   => 0,
		'comp_propios' => 0,
		'tareas'       => 0,
		'tareas_nota'  => 0,
		'notas'        => 0,
		'calificados'  => 0,
		'estudiantes'  => 0,
		'ultima'       => null,
	);
	$m['estudiantes'] = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $tst WHERE grade_id = %d AND status = 'active'",
		(int) $row->grade_id
	) );
	if ( empty( $trim_ids ) ) {
		return $m;
	}
	$in = implode( ',', array_map( 'intval', $trim_ids ) );

	$comp = $wpdb->get_row( $wpdb->prepare(
		"SELECT COUNT(*) AS total, SUM(created_by = %d) AS propios
		 FROM $tc WHERE subject_id = %d AND trimester_id IN ($in)",
		(int) $row->teacher_user_id, (int) $row->subject_id
	) );
	$m['comp_total']   = (int) $comp->total;
	$m['comp_propios'] = (int) $comp->propios;

	$tar = $wpdb->get_row( $wpdb->prepare(
		"SELECT COUNT(*) AS total, SUM(component_id IS NOT NULL) AS con_nota
		 FROM $ta WHERE teacher_id = %d AND grade_id = %d AND subject_id = %d AND trimester_id IN ($in)",
		(int) $row->teacher_id, (int) $row->grade_id, (int) $row->subject_id
	) );
	$m['tareas']      = (int) $tar->total;
	$m['tareas_nota'] = (int) $tar->con_nota;

	$notas = $wpdb->get_row( $wpdb->prepare(
		"SELECT COUNT(*) AS n, COUNT(DISTINCT l.student_id) AS calificados, MAX(l.registered_at) AS ultima
		 FROM $tl l
		 INNER JOIN $tc c ON c.id = l.component_id
		 INNER JOIN $tst st ON st.id = l.student_id
		 WHERE c.subject_id = %d AND c.trimester_id IN ($in) AND st.grade_id = %d",
		(int) $row->subject_id, (int) $row->grade_id
	) );
	$m['notas']       = (int) $notas->n;
	$m['calificados'] = (int) $notas->calificados;
	$m['ultima']      = $notas->ultima;

	return $m;
};

// Detalle de una asignación.
$detalle     = null;
$detalle_row = null;
if ( $detail_id ) {
	foreach ( $asignaciones as $x ) {
		if ( (int) $x->id === $detail_id ) {
			$detalle_row = $x;
			break;
		}
	}
	if ( $detalle_row ) {
		$trim_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $tt WHERE period_id = %d", (int) $detalle_row->period_id ) );
		$in       = empty( $trim_ids ) ? '0' : implode( ',', array_map( 'intval', $trim_ids ) );
		$detalle  = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.name, c.weight, c.created_by, c.parcial_num, t.number AS trim_num,
			        (SELECT COUNT(*)   FROM $tl l INNER JOIN $tst st ON st.id = l.student_id WHERE l.component_id = c.id AND st.grade_id = %d) AS n_notas,
			        (SELECT AVG(l.score) FROM $tl l INNER JOIN $tst st ON st.id = l.student_id WHERE l.component_id = c.id AND st.grade_id = %d) AS promedio,
			        (SELECT GROUP_CONCAT(a.title SEPARATOR ' · ') FROM $ta a WHERE a.component_id = c.id AND a.grade_id = %d) AS tareas
			 FROM $tc c
			 INNER JOIN $tt t ON t.id = c.trimester_id
			 WHERE c.subject_id = %d AND c.trimester_id IN ($in)
			 ORDER BY t.number, c.parcial_num, c.created_by, c.id",
			(int) $detalle_row->grade_id, (int) $detalle_row->grade_id, (int) $detalle_row->grade_id, (int) $detalle_row->subject_id
		) );
	}
}

$base_url = add_query_arg(
	array_filter( array(
		'page'           => 'edu-panel-docentes',
		'edu_pd_grade'   => $f_grade,
		'edu_pd_teacher' => $f_teacher,
	) ),
	admin_url( 'admin.php' )
);
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Panel de docentes', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<p class="description" style="max-width:760px;">
		<?php esc_html_e( 'Seguimiento del trabajo de calificación de cada docente: componentes evaluables (institucionales y propios), tareas creadas y cuáles alimentan notas, notas registradas y avance sobre el total de estudiantes del grado.', 'sistema-educativo' ); ?>
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
		<input type="hidden" name="page" value="edu-panel-docentes">
		<label><strong><?php esc_html_e( 'Grado:', 'sistema-educativo' ); ?></strong>
			<select name="edu_pd_grade" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
				<?php foreach ( $all_grades as $g ) : ?>
					<option value="<?php echo (int) $g->id; ?>" <?php selected( $f_grade, (int) $g->id ); ?>>
						<?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
		<label style="margin-left:12px;"><strong><?php esc_html_e( 'Docente:', 'sistema-educativo' ); ?></strong>
			<select name="edu_pd_teacher" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
				<?php foreach ( $all_teachers as $t ) : ?>
					<option value="<?php echo (int) $t->id; ?>" <?php selected( $f_teacher, (int) $t->id ); ?>>
						<?php echo esc_html( $t->display_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Docente', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Componentes (propios)', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Tareas (a nota)', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Notas', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Avance estudiantes', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Última nota', 'sistema-educativo' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $asignaciones ) ) : ?>
			<tr><td colspan="9"><?php esc_html_e( 'No hay asignaciones académicas activas con los filtros seleccionados.', 'sistema-educativo' ); ?></td></tr>
		<?php else : foreach ( $asignaciones as $row ) :
			$m   = $edu_pd_metrics( $row );
			$pct = $m['estudiantes'] > 0 ? round( 100 * $m['calificados'] / $m['estudiantes'] ) : 0;
			$pct_color = $pct >= 80 ? '#15803d' : ( $pct >= 40 ? '#d97706' : '#dc2626' );
		?>
			<tr>
				<td><strong><?php echo esc_html( $row->docente ); ?></strong></td>
				<td><?php echo esc_html( $row->grade_name . ' ' . $row->paralelo ); ?></td>
				<td><?php echo esc_html( $row->subject_name ); ?></td>
				<td>
					<?php echo (int) $m['comp_total']; ?>
					<?php if ( $m['comp_propios'] > 0 ) : ?>
						<span style="color:#1d4ed8;">(<?php echo (int) $m['comp_propios']; ?> <?php esc_html_e( 'propios', 'sistema-educativo' ); ?>)</span>
					<?php endif; ?>
				</td>
				<td><?php echo (int) $m['tareas']; ?> (<?php echo (int) $m['tareas_nota']; ?>)</td>
				<td><?php echo (int) $m['notas']; ?></td>
				<td>
					<?php if ( $m['estudiantes'] > 0 ) : ?>
						<span style="color:<?php echo esc_attr( $pct_color ); ?>; font-weight:600;"><?php echo (int) $pct; ?>%</span>
						<span class="description">(<?php echo (int) $m['calificados']; ?>/<?php echo (int) $m['estudiantes']; ?>)</span>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
				<td><?php echo $m['ultima'] ? esc_html( mysql2date( 'd/m/Y H:i', $m['ultima'] ) ) : '—'; ?></td>
				<td>
					<a href="<?php echo esc_url( add_query_arg( 'edu_pd_detail', (int) $row->id, $base_url ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Detalle', 'sistema-educativo' ); ?>
					</a>
				</td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>

	<?php if ( $detalle_row ) : ?>
	<h2 style="margin-top:28px;">
		<?php
		/* translators: 1: docente, 2: materia, 3: grado */
		echo esc_html( sprintf( __( 'Detalle: %1$s — %2$s (%3$s)', 'sistema-educativo' ), $detalle_row->docente, $detalle_row->subject_name, $detalle_row->grade_name . ' ' . $detalle_row->paralelo ) );
		?>
	</h2>
	<table class="widefat striped" style="max-width:1100px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Trimestre', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Parcial', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Componente', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Peso', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Origen', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Notas', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Promedio', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Tareas vinculadas', 'sistema-educativo' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $detalle ) ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'Esta materia aún no tiene componentes evaluables en el período.', 'sistema-educativo' ); ?></td></tr>
		<?php else : foreach ( $detalle as $d ) :
			if ( (int) $d->created_by ) {
				$u_creador = get_userdata( (int) $d->created_by );
				$origen    = $u_creador ? $u_creador->display_name : __( 'Docente', 'sistema-educativo' );
			} else {
				$origen = __( 'Institucional', 'sistema-educativo' );
			}
		?>
			<tr>
				<td>T<?php echo (int) $d->trim_num; ?></td>
				<td>P<?php echo (int) $d->parcial_num; ?></td>
				<td><?php echo esc_html( $d->name ); ?></td>
				<td><?php echo esc_html( number_format( (float) $d->weight, 2, '.', '' ) ); ?></td>
				<td><?php echo (int) $d->created_by ? esc_html( $origen ) : '<strong>' . esc_html( $origen ) . '</strong>'; ?></td>
				<td><?php echo (int) $d->n_notas; ?></td>
				<td>
					<?php if ( null !== $d->promedio ) : ?>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput -- badge retorna HTML pre-construido.
						echo esc_html( number_format( (float) $d->promedio, 2 ) ) . ' ' . Edu_Qualitativa_Helper::badge( (float) $d->promedio, '' );
						?>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
				<td><?php echo $d->tareas ? esc_html( $d->tareas ) : '—'; ?></td>
			</tr>
		<?php endforeach; endif; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
