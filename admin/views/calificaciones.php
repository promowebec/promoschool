<?php
/**
 * Vista: captura batch de notas por (grado + materia + trimestre + parcial).
 *
 * Filtros encadenados → matriz estudiantes × componentes. Cada celda muestra
 * la última nota registrada (puede dejarse vacía para no modificar). Submit
 * batch vía Edu_Score_Controller::handle_save_scores().
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( Edu_Context::can( 'edu_grade_students' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

$institution_id = Edu_Context::current_institution_id();
if ( ! $institution_id ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Calificaciones', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

global $wpdb;
$tg  = $wpdb->prefix . 'edu_grades';
$ts  = $wpdb->prefix . 'edu_subjects';
$tt  = $wpdb->prefix . 'edu_trimesters';
$tp  = $wpdb->prefix . 'edu_periods';
$tgs = $wpdb->prefix . 'edu_grade_subjects';
$tc  = $wpdb->prefix . 'edu_grade_components';
$tl  = $wpdb->prefix . 'edu_grades_log';
$tst = $wpdb->prefix . 'edu_students';
$tps = $wpdb->prefix . 'edu_parcial_scores';
$tta = $wpdb->prefix . 'edu_teacher_assignments';
$ttr = $wpdb->prefix . 'edu_teachers';

// Detectar si es docente (no rector/admin).
$is_docente_user    = ! Edu_Context::can( 'edu_view_all' ) && Edu_Context::can( 'edu_grade_students' );
$current_teacher_id = 0;
if ( $is_docente_user ) {
	$current_teacher_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $ttr WHERE user_id = %d",
		get_current_user_id()
	) );
}

$grade_id     = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;
$subject_id   = isset( $_GET['subject_id'] ) ? (int) $_GET['subject_id'] : 0;
$trimester_id = isset( $_GET['trimester_id'] ) ? (int) $_GET['trimester_id'] : 0;
$parcial_num  = isset( $_GET['parcial_num'] ) ? (int) $_GET['parcial_num'] : 0;

if ( $is_docente_user && $current_teacher_id ) {
	$grades = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT g.id, g.name, g.paralelo
		 FROM $tg g
		 INNER JOIN $tta ta ON ta.grade_id = g.id
		 WHERE g.institution_id = %d AND ta.teacher_id = %d AND ta.is_active = 1
		 ORDER BY g.sub_level, g.name, g.paralelo",
		$institution_id, $current_teacher_id
	) );
} else {
	$grades = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
		$institution_id
	) );
}

// Materias del grado seleccionado: para docentes solo las asignadas; para rector/admin todo el pensum.
$subjects = array();
if ( $grade_id ) {
	if ( $is_docente_user && $current_teacher_id ) {
		$subjects = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT s.id, s.name, s.code
			 FROM $tta ta
			 INNER JOIN $ts s ON s.id = ta.subject_id
			 WHERE ta.teacher_id = %d AND ta.grade_id = %d AND ta.is_active = 1
			 ORDER BY s.name",
			$current_teacher_id, $grade_id
		) );
	} else {
		$subjects = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.name, s.code
			 FROM $tgs gs
			 INNER JOIN $ts s ON s.id = gs.subject_id
			 WHERE gs.grade_id = %d
			 ORDER BY s.name",
			$grade_id
		) );
	}
}

$trimesters = $wpdb->get_results( $wpdb->prepare(
	"SELECT t.id, t.number, p.name AS period_name
	 FROM $tt t
	 INNER JOIN $tp p ON p.id = t.period_id
	 WHERE p.institution_id = %d
	 ORDER BY p.start_date DESC, t.number",
	$institution_id
) );

$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$code     = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
$saved    = isset( $_GET['saved'] ) ? (int) $_GET['saved'] : 0;
$ready    = ( $grade_id && $subject_id && $trimester_id && in_array( $parcial_num, array( 1, 2 ), true ) );
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Captura de calificaciones', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			/* translators: %d: número de notas guardadas */
			echo esc_html( sprintf( _n( '%d nota guardada.', '%d notas guardadas.', $saved, 'sistema-educativo' ), $saved ) );
			?>
		</p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$msgs = array(
				'invalid_parcial' => __( 'Parcial inválido.', 'sistema-educativo' ),
				'invalid_scope'   => __( 'Recursos fuera de la institución actual.', 'sistema-educativo' ),
				'no_components'   => __( 'No hay componentes definidos para esta materia/trimestre/parcial. Crea componentes primero.', 'sistema-educativo' ),
			);
			echo esc_html( $msgs[ $code ] ?? __( 'Error.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
		<input type="hidden" name="page" value="edu-calificaciones">

		<label><strong><?php esc_html_e( 'Grado:', 'sistema-educativo' ); ?></strong>
			<select name="grade_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $grades as $g ) : ?>
					<option value="<?php echo (int) $g->id; ?>" <?php selected( $grade_id, (int) $g->id ); ?>>
						<?php echo esc_html( $g->name . ' · ' . $g->paralelo ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="margin-left:12px;"><strong><?php esc_html_e( 'Materia:', 'sistema-educativo' ); ?></strong>
			<select name="subject_id" onchange="this.form.submit()" <?php echo $grade_id ? '' : 'disabled'; ?>>
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $subjects as $s ) : ?>
					<option value="<?php echo (int) $s->id; ?>" <?php selected( $subject_id, (int) $s->id ); ?>>
						<?php echo esc_html( $s->name . ( $s->code ? ' (' . $s->code . ')' : '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="margin-left:12px;"><strong><?php esc_html_e( 'Trimestre:', 'sistema-educativo' ); ?></strong>
			<select name="trimester_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $trimesters as $t ) : ?>
					<option value="<?php echo (int) $t->id; ?>" <?php selected( $trimester_id, (int) $t->id ); ?>>
						<?php echo esc_html( $t->period_name . ' · Trimestre ' . $t->number ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="margin-left:12px;"><strong><?php esc_html_e( 'Parcial:', 'sistema-educativo' ); ?></strong>
			<select name="parcial_num" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<option value="1" <?php selected( $parcial_num, 1 ); ?>><?php esc_html_e( 'Parcial 1', 'sistema-educativo' ); ?></option>
				<option value="2" <?php selected( $parcial_num, 2 ); ?>><?php esc_html_e( 'Parcial 2', 'sistema-educativo' ); ?></option>
			</select>
		</label>
	</form>

	<?php if ( $grade_id && empty( $subjects ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'El grado no tiene materias en el pensum.', 'sistema-educativo' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $ready ) :
		$components = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, weight FROM $tc WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d ORDER BY id",
			$subject_id, $trimester_id, $parcial_num
		) );

		$students = $wpdb->get_results( $wpdb->prepare(
			"SELECT st.id, st.user_id, u.display_name, st.cedula
			 FROM $tst st
			 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
			 WHERE st.grade_id = %d AND st.status = 'active'
			 ORDER BY u.display_name",
			$grade_id
		) );

		$student_ids   = array_map( 'intval', wp_list_pluck( $students, 'id' ) );
		$component_ids = array_map( 'intval', wp_list_pluck( $components, 'id' ) );

		// Promedio de notas por (student, component) — múltiples tareas del mismo componente se promedian.
		$last_scores = array();
		if ( $student_ids && $component_ids ) {
			$sid_in = implode( ',', $student_ids );
			$cid_in = implode( ',', $component_ids );
			$rows   = $wpdb->get_results(
				"SELECT student_id, component_id, AVG(score) AS score
				 FROM $tl
				 WHERE student_id IN ($sid_in) AND component_id IN ($cid_in)
				 GROUP BY student_id, component_id"
			);
			foreach ( $rows as $r ) {
				$last_scores[ (int) $r->student_id ][ (int) $r->component_id ] = (float) $r->score;
			}
		}

		// Nota actual del parcial.
		$parcial_rows = array();
		if ( $student_ids ) {
			$sid_in       = implode( ',', $student_ids );
			$pr           = $wpdb->get_results( $wpdb->prepare(
				"SELECT student_id, computed_score, is_closed FROM $tps
				 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d
				 AND student_id IN ($sid_in)",
				$subject_id, $trimester_id, $parcial_num
			) );
			foreach ( $pr as $r ) {
				$parcial_rows[ (int) $r->student_id ] = $r;
			}
		}

		$weight_sum = 0;
		foreach ( $components as $c ) {
			$weight_sum += (float) $c->weight;
		}
		$weight_ok = ( abs( $weight_sum - 1.0 ) <= 0.01 );

		// Sub-nivel del grado para equivalencia cualitativa.
		$grade_sub_level = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT sub_level FROM $tg WHERE id = %d", $grade_id
		) );
	?>

	<?php if ( empty( $components ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'No hay componentes definidos para esta combinación. Defina componentes en "Componentes evaluables" antes de capturar notas.', 'sistema-educativo' ); ?>
		</p></div>
	<?php elseif ( empty( $students ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'El grado no tiene estudiantes activos.', 'sistema-educativo' ); ?></p></div>
	<?php else : ?>

	<?php if ( ! $weight_ok ) : ?>
		<div class="notice notice-warning"><p>
			<?php
			/* translators: %s: suma actual de pesos */
			echo esc_html( sprintf( __( 'Atención: la suma de pesos de los componentes es %s (debería ser 1.00). El cálculo del parcial se normalizará proporcionalmente.', 'sistema-educativo' ), number_format( $weight_sum, 2 ) ) );
			?>
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'edu_save_scores' ); ?>
		<input type="hidden" name="action" value="edu_save_scores">
		<input type="hidden" name="grade_id"     value="<?php echo (int) $grade_id; ?>">
		<input type="hidden" name="subject_id"   value="<?php echo (int) $subject_id; ?>">
		<input type="hidden" name="trimester_id" value="<?php echo (int) $trimester_id; ?>">
		<input type="hidden" name="parcial_num"  value="<?php echo (int) $parcial_num; ?>">

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
					<?php foreach ( $components as $c ) : ?>
						<th>
							<?php echo esc_html( $c->name ); ?><br>
							<small style="font-weight:normal;">(<?php echo esc_html( number_format( (float) $c->weight, 2 ) ); ?>)</small>
						</th>
					<?php endforeach; ?>
					<th><?php esc_html_e( 'Nota parcial', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Cualitativa', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $students as $st ) :
					$pr       = $parcial_rows[ (int) $st->id ] ?? null;
					$is_closed = $pr && (int) $pr->is_closed === 1;
				?>
				<tr<?php echo $is_closed ? ' style="opacity:0.6;"' : ''; ?>>
					<td>
						<strong><?php echo esc_html( $st->display_name ); ?></strong>
						<?php if ( $st->cedula ) : ?><br><small><?php echo esc_html( $st->cedula ); ?></small><?php endif; ?>
						<?php if ( $is_closed ) : ?><br><small style="color:#b00;"><?php esc_html_e( 'Cerrado', 'sistema-educativo' ); ?></small><?php endif; ?>
					</td>
					<?php foreach ( $components as $c ) :
						$prev = isset( $last_scores[ (int) $st->id ][ (int) $c->id ] )
							? number_format( $last_scores[ (int) $st->id ][ (int) $c->id ], 2, '.', '' )
							: '';
					?>
					<td>
						<input type="number" step="0.01" min="0" max="10"
							name="scores[<?php echo (int) $st->id; ?>][<?php echo (int) $c->id; ?>]"
							value="<?php echo esc_attr( $prev ); ?>"
							style="width:80px;"
							<?php echo $is_closed ? 'disabled' : ''; ?>>
					</td>
					<?php endforeach; ?>
					<td>
						<strong><?php echo $pr ? esc_html( number_format( (float) $pr->computed_score, 2 ) ) : '—'; ?></strong>
					</td>
					<td>
						<?php if ( $pr && (float) $pr->computed_score > 0 ) : ?>
							<?php echo Edu_Qualitativa_Helper::badge( (float) $pr->computed_score, $grade_sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php else : ?>
							<span style="color:#9ca3af;">—</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description" style="margin-top:8px;">
			<?php esc_html_e( 'Dejar la celda en blanco no borra la nota anterior. Cada captura agrega un registro nuevo al historial (la última nota es la que cuenta).', 'sistema-educativo' ); ?>
		</p>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar notas', 'sistema-educativo' ); ?></button>
		</p>
	</form>

	<?php endif; ?>
	<?php endif; ?>
</div>
