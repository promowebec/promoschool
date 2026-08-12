<?php
/**
 * Vista: Asistencia diaria.
 *
 * Docente/rector selecciona grado + fecha → tabla de radio buttons por estudiante.
 * También permite ver el historial del mes actual filtrado por grado.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( Edu_Context::can( 'edu_take_attendance' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

$institution_id = Edu_Context::current_institution_id();
if ( ! $institution_id ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Asistencia', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$code   = isset( $_GET['code'] )   ? sanitize_key( $_GET['code'] )   : '';

global $wpdb;
$tg  = $wpdb->prefix . 'edu_grades';
$tst = $wpdb->prefix . 'edu_students';
$tat = $wpdb->prefix . 'edu_attendance';
$ttr = $wpdb->prefix . 'edu_teachers';
$tgs = $wpdb->prefix . 'edu_grade_subjects';
$ts  = $wpdb->prefix . 'edu_subjects';

// Solo docentes ven sus grados asignados; rectores ven todos.
$is_rector = Edu_Context::can( 'edu_view_all' );

if ( $is_rector ) {
	$grades = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
		$institution_id
	) );
} else {
	$teacher_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $ttr WHERE user_id = %d",
		get_current_user_id()
	) );
	$grades = $teacher_id ? $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT g.id, g.name, g.paralelo
		 FROM {$wpdb->prefix}edu_teacher_assignments ta
		 INNER JOIN $tg g ON g.id = ta.grade_id
		 WHERE ta.teacher_id = %d AND ta.is_active = 1 AND g.institution_id = %d
		 ORDER BY g.sub_level, g.name, g.paralelo",
		$teacher_id, $institution_id
	) ) : array();
}

$grade_id_sel   = isset( $_GET['grade_id'] )   ? (int) $_GET['grade_id']   : ( count( $grades ) === 1 ? (int) $grades[0]->id : 0 );
$subject_id_sel = isset( $_GET['subject_id'] )  ? (int) $_GET['subject_id'] : 0;
$date_sel       = isset( $_GET['date'] )         ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : date( 'Y-m-d' );

// Materias del grado: docente solo las asignadas; rector/admin todo el pensum.
$subjects = array();
if ( $grade_id_sel ) {
	if ( ! $is_rector && ! empty( $teacher_id ) ) {
		$subjects = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT s.id, s.name
			 FROM {$wpdb->prefix}edu_teacher_assignments ta
			 INNER JOIN $ts s ON s.id = ta.subject_id
			 WHERE ta.teacher_id = %d AND ta.grade_id = %d AND ta.is_active = 1
			 ORDER BY s.name",
			$teacher_id, $grade_id_sel
		) );
	} else {
		$subjects = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.name FROM $tgs gs INNER JOIN $ts s ON s.id = gs.subject_id
			 WHERE gs.grade_id = %d ORDER BY s.name",
			$grade_id_sel
		) );
	}
}

// Estudiantes del grado seleccionado.
$students = array();
if ( $grade_id_sel ) {
	$students = $wpdb->get_results( $wpdb->prepare(
		"SELECT s.id,
		        COALESCE(um_fn.meta_value, '') AS first_name,
		        COALESCE(um_ln.meta_value, u.display_name) AS last_name
		 FROM $tst s
		 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
		 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
		 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
		 WHERE s.grade_id = %d
		 ORDER BY last_name, first_name",
		$grade_id_sel
	) );
}

// Asistencia ya guardada para este grado + fecha (para prellenar radios).
$saved = array();
if ( $grade_id_sel && $date_sel ) {
	$sub_cond = $subject_id_sel
		? $wpdb->prepare( "AND subject_id = %d", $subject_id_sel )
		: "AND subject_id IS NULL";

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT student_id, status, justification FROM $tat
		 WHERE student_id IN (
		   SELECT id FROM $tst WHERE grade_id = %d
		 )
		 AND date = %s $sub_cond",
		$grade_id_sel, $date_sel
	) );
	foreach ( $rows as $r ) {
		$saved[ (int) $r->student_id ] = array(
			'status'        => $r->status,
			'justification' => $r->justification,
		);
	}
}

// Resumen del mes en curso para el grado seleccionado.
$month_summary = array();
if ( $grade_id_sel ) {
	$month_start = date( 'Y-m-01' );
	$month_end   = date( 'Y-m-t' );
	$sub_cond    = $subject_id_sel
		? $wpdb->prepare( "AND a.subject_id = %d", $subject_id_sel )
		: "AND a.subject_id IS NULL";

	$month_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT a.student_id, a.date, a.status
		 FROM $tat a
		 INNER JOIN $tst s ON s.id = a.student_id
		 WHERE s.grade_id = %d AND a.date BETWEEN %s AND %s $sub_cond
		 ORDER BY a.date",
		$grade_id_sel, $month_start, $month_end
	) );

	foreach ( $month_rows as $r ) {
		$sid = (int) $r->student_id;
		if ( ! isset( $month_summary[ $sid ] ) ) {
			$month_summary[ $sid ] = array(
				'presente'           => 0,
				'atraso'             => 0,
				'falta_justificada'  => 0,
				'falta_injustificada'=> 0,
			);
		}
		$month_summary[ $sid ][ $r->status ]++;
	}
}

$status_labels = array(
	'presente'            => __( 'Presente',   'sistema-educativo' ),
	'atraso'              => __( 'Atraso',     'sistema-educativo' ),
	'falta_justificada'   => __( 'Falta J.',   'sistema-educativo' ),
	'falta_injustificada' => __( 'Falta I.',   'sistema-educativo' ),
);
$status_colors = array(
	'presente'            => 'green',
	'atraso'              => '#e08800',
	'falta_justificada'   => '#0073aa',
	'falta_injustificada' => '#b32d2e',
);
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Asistencia', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( 'saved' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Asistencia guardada.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
		<?php
		$msgs = array(
			'grade_required' => __( 'Selecciona un grado.', 'sistema-educativo' ),
			'invalid_scope'  => __( 'Grado fuera de la institución activa.', 'sistema-educativo' ),
			'no_teacher'     => __( 'No se encontró registro de docente para tu usuario.', 'sistema-educativo' ),
		);
		echo esc_html( $msgs[ $code ] ?? __( 'Error al guardar.', 'sistema-educativo' ) );
		?>
		</p></div>
	<?php endif; ?>

	<?php if ( empty( $grades ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'No tienes grados asignados.', 'sistema-educativo' ); ?></p></div>
		<?php return; ?>
	<?php endif; ?>

	<!-- Filtros -->
	<div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end; margin-bottom:20px; background:#fff; padding:16px; border:1px solid #ddd; border-radius:4px;">
		<label>
			<strong><?php esc_html_e( 'Grado:', 'sistema-educativo' ); ?></strong><br>
			<select id="edu-att-grade" onchange="eduReloadAtt()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $grades as $g ) : ?>
					<option value="<?php echo (int) $g->id; ?>" <?php selected( $grade_id_sel, (int) $g->id ); ?>>
						<?php echo esc_html( $g->name . ' · ' . $g->paralelo ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>
			<strong><?php esc_html_e( 'Materia (opcional):', 'sistema-educativo' ); ?></strong><br>
			<select id="edu-att-subject" onchange="eduReloadAtt()">
				<option value="0"><?php esc_html_e( '— General (diaria) —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $subjects as $s ) : ?>
					<option value="<?php echo (int) $s->id; ?>" <?php selected( $subject_id_sel, (int) $s->id ); ?>>
						<?php echo esc_html( $s->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>
			<strong><?php esc_html_e( 'Fecha:', 'sistema-educativo' ); ?></strong><br>
			<input type="date" id="edu-att-date" value="<?php echo esc_attr( $date_sel ); ?>" max="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" onchange="eduReloadAtt()">
		</label>
	</div>

	<?php if ( ! $grade_id_sel ) : ?>
		<p class="description"><?php esc_html_e( 'Selecciona un grado para registrar asistencia.', 'sistema-educativo' ); ?></p>
	<?php elseif ( empty( $students ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'Este grado no tiene estudiantes registrados.', 'sistema-educativo' ); ?></p></div>
	<?php else : ?>

	<!-- Formulario de asistencia -->
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'edu_save_attendance' ); ?>
		<input type="hidden" name="action"     value="edu_save_attendance">
		<input type="hidden" name="grade_id"   value="<?php echo (int) $grade_id_sel; ?>">
		<input type="hidden" name="subject_id" value="<?php echo (int) $subject_id_sel; ?>">
		<input type="hidden" name="date"       value="<?php echo esc_attr( $date_sel ); ?>">

		<div style="overflow-x:auto;">
		<table class="widefat" style="max-width:860px;">
			<thead>
				<tr>
					<th style="width:220px;"><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
					<?php foreach ( $status_labels as $key => $label ) : ?>
						<th style="text-align:center; color:<?php echo esc_attr( $status_colors[ $key ] ); ?>;">
							<?php echo esc_html( $label ); ?>
						</th>
					<?php endforeach; ?>
					<th><?php esc_html_e( 'Justificación', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $students as $student ) :
				$sid         = (int) $student->id;
				$current_att = $saved[ $sid ]['status']        ?? 'presente';
				$current_jus = $saved[ $sid ]['justification'] ?? '';
			?>
				<tr>
					<td><strong><?php echo esc_html( $student->last_name . ', ' . $student->first_name ); ?></strong></td>
					<?php foreach ( array_keys( $status_labels ) as $key ) : ?>
						<td style="text-align:center;">
							<input type="radio"
								name="attendance[<?php echo $sid; ?>]"
								value="<?php echo esc_attr( $key ); ?>"
								<?php checked( $current_att, $key ); ?>
								class="edu-att-radio"
								data-sid="<?php echo $sid; ?>">
						</td>
					<?php endforeach; ?>
					<td>
						<input type="text"
							name="justification[<?php echo $sid; ?>]"
							value="<?php echo esc_attr( $current_jus ); ?>"
							id="jus-<?php echo $sid; ?>"
							placeholder="<?php esc_attr_e( 'Opcional', 'sistema-educativo' ); ?>"
							style="width:100%; display:<?php echo in_array( $current_att, array( 'falta_justificada', 'falta_injustificada', 'atraso' ), true ) ? 'block' : 'none'; ?>;">
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<p class="submit" style="margin-top:12px;">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Guardar asistencia', 'sistema-educativo' ); ?>
			</button>
			<span style="margin-left:12px; color:#666; font-size:13px;">
				<?php echo esc_html( date_i18n( 'l, d \d\e F \d\e Y', strtotime( $date_sel ) ) ); ?>
			</span>
		</p>
	</form>

	<?php if ( ! empty( $month_summary ) ) : ?>
	<!-- Resumen mensual -->
	<h2 style="margin-top:32px;">
		<?php printf( esc_html__( 'Resumen del mes · %s', 'sistema-educativo' ), esc_html( date_i18n( 'F Y', strtotime( $date_sel ) ) ) ); ?>
	</h2>
	<div style="overflow-x:auto;">
	<table class="widefat striped" style="max-width:760px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
				<th style="text-align:center; color:green;"><?php esc_html_e( 'Presentes', 'sistema-educativo' ); ?></th>
				<th style="text-align:center; color:#e08800;"><?php esc_html_e( 'Atrasos', 'sistema-educativo' ); ?></th>
				<th style="text-align:center; color:#0073aa;"><?php esc_html_e( 'Faltas J.', 'sistema-educativo' ); ?></th>
				<th style="text-align:center; color:#b32d2e;"><?php esc_html_e( 'Faltas I.', 'sistema-educativo' ); ?></th>
				<th style="text-align:center;"><?php esc_html_e( '% Asist.', 'sistema-educativo' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $students as $student ) :
			$sid  = (int) $student->id;
			$ms   = $month_summary[ $sid ] ?? array( 'presente' => 0, 'atraso' => 0, 'falta_justificada' => 0, 'falta_injustificada' => 0 );
			$total = array_sum( $ms );
			$pct   = $total > 0 ? round( ( $ms['presente'] + $ms['atraso'] ) / $total * 100 ) : 100;
			$color = $pct >= 90 ? 'green' : ( $pct >= 75 ? '#e08800' : '#b32d2e' );
		?>
			<tr>
				<td><?php echo esc_html( $student->last_name . ', ' . $student->first_name ); ?></td>
				<td style="text-align:center;"><?php echo (int) $ms['presente']; ?></td>
				<td style="text-align:center;"><?php echo (int) $ms['atraso']; ?></td>
				<td style="text-align:center;"><?php echo (int) $ms['falta_justificada']; ?></td>
				<td style="text-align:center;"><?php echo (int) $ms['falta_injustificada']; ?></td>
				<td style="text-align:center; font-weight:600; color:<?php echo esc_attr( $color ); ?>;"><?php echo $pct; ?>%</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php endif; ?>

	<?php endif; ?>
</div>

<script>
function eduReloadAtt() {
	var url = new URL( window.location.href );
	url.searchParams.set( 'page',       'edu-asistencia' );
	url.searchParams.set( 'grade_id',   document.getElementById('edu-att-grade').value );
	url.searchParams.set( 'subject_id', document.getElementById('edu-att-subject').value );
	url.searchParams.set( 'date',       document.getElementById('edu-att-date').value );
	window.location.href = url.toString();
}

// Mostrar/ocultar campo justificación según estado elegido.
document.querySelectorAll('.edu-att-radio').forEach(function(radio){
	radio.addEventListener('change', function(){
		var sid = this.dataset.sid;
		var jus = document.getElementById('jus-' + sid);
		if ( jus ) {
			jus.style.display = ['atraso','falta_justificada','falta_injustificada'].includes(this.value) ? 'block' : 'none';
		}
	});
});
</script>
