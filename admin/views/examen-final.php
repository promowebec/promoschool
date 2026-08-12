<?php
/**
 * Vista: captura del examen final por (grado + materia + trimestre).
 *
 * Lista estudiantes activos del grado con input de nota (0–10). Muestra la nota
 * actual de parcial 1 y 2 (desde parcial_scores), el examen y la nota del
 * trimestre calculada. Submit via Edu_Trimester_Score_Controller::handle_save_exam().
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
	echo '<div class="wrap"><h1>' . esc_html__( 'Examen final', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

global $wpdb;
$tg  = $wpdb->prefix . 'edu_grades';
$ts  = $wpdb->prefix . 'edu_subjects';
$tt  = $wpdb->prefix . 'edu_trimesters';
$tp  = $wpdb->prefix . 'edu_periods';
$tgs = $wpdb->prefix . 'edu_grade_subjects';
$tst = $wpdb->prefix . 'edu_students';
$tts = $wpdb->prefix . 'edu_trimester_scores';
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
$status       = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$code         = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
$saved        = isset( $_GET['saved'] ) ? (int) $_GET['saved'] : 0;

// Grados: docente solo ve los suyos; rector/admin ve todos.
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

// Materias: docente solo las asignadas; rector/admin todo el pensum.
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
			 FROM $tgs gs INNER JOIN $ts s ON s.id = gs.subject_id
			 WHERE gs.grade_id = %d ORDER BY s.name",
			$grade_id
		) );
	}
}

$trimesters = $wpdb->get_results( $wpdb->prepare(
	"SELECT t.id, t.number, p.name AS period_name
	 FROM $tt t INNER JOIN $tp p ON p.id = t.period_id
	 WHERE p.institution_id = %d ORDER BY p.start_date DESC, t.number",
	$institution_id
) );

$ready = ( $grade_id && $subject_id && $trimester_id );
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Evaluación Sumativa', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php echo esc_html( sprintf( _n( '%d nota guardada.', '%d notas guardadas.', $saved, 'sistema-educativo' ), $saved ) ); ?>
		</p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$msgs = array(
				'invalid_scope' => __( 'Recursos fuera de la institución actual.', 'sistema-educativo' ),
			);
			echo esc_html( $msgs[ $code ] ?? __( 'Error.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
		<input type="hidden" name="page" value="edu-examen-final">

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
	</form>

	<?php if ( $ready ) :
		// Detectar si el grado usa evaluación sumativa (Media/Superior/Bach).
		$grade_sub_level = $wpdb->get_var( $wpdb->prepare( "SELECT sub_level FROM $tg WHERE id = %d", $grade_id ) );
		$usa_sumativa    = in_array( $grade_sub_level, array( 'media', 'superior', 'bg', 'bt' ), true );

		$students = $wpdb->get_results( $wpdb->prepare(
			"SELECT st.id, st.user_id, u.display_name, st.cedula
			 FROM $tst st
			 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
			 WHERE st.grade_id = %d AND st.status = 'active'
			 ORDER BY u.display_name",
			$grade_id
		) );

		$student_ids = array_map( 'intval', wp_list_pluck( $students, 'id' ) );

		// Notas del examen/proyecto y estado de cierre desde trimester_scores.
		$trimester_rows = array();
		// Notas de parcial 1 y 2 desde parcial_scores (fuente real, independiente del cierre).
		$parcial1_rows  = array();
		$parcial2_rows  = array();

		if ( $student_ids ) {
			$sid_in = implode( ',', $student_ids );

			$t_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT student_id, final_exam_score, proyecto_score, computed_score, is_closed
				 FROM $tts WHERE subject_id = %d AND trimester_id = %d AND student_id IN ($sid_in)",
				$subject_id, $trimester_id
			) );
			foreach ( $t_rows as $r ) {
				$trimester_rows[ (int) $r->student_id ] = $r;
			}

			$p1_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT student_id, computed_score, is_closed
				 FROM $tps WHERE subject_id = %d AND trimester_id = %d AND parcial_num = 1
				 AND student_id IN ($sid_in)",
				$subject_id, $trimester_id
			) );
			foreach ( $p1_rows as $r ) {
				$parcial1_rows[ (int) $r->student_id ] = (float) $r->computed_score;
			}

			$p2_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT student_id, computed_score, is_closed
				 FROM $tps WHERE subject_id = %d AND trimester_id = %d AND parcial_num = 2
				 AND student_id IN ($sid_in)",
				$subject_id, $trimester_id
			) );
			foreach ( $p2_rows as $r ) {
				$parcial2_rows[ (int) $r->student_id ] = (float) $r->computed_score;
			}
		}
	?>

	<?php if ( empty( $students ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'El grado no tiene estudiantes activos.', 'sistema-educativo' ); ?></p></div>
	<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'edu_save_exam' ); ?>
		<input type="hidden" name="action"       value="edu_save_exam">
		<input type="hidden" name="grade_id"     value="<?php echo (int) $grade_id; ?>">
		<input type="hidden" name="subject_id"   value="<?php echo (int) $subject_id; ?>">
		<input type="hidden" name="trimester_id" value="<?php echo (int) $trimester_id; ?>">

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Parcial 1', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Parcial 2', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Promedio parciales (70%)', 'sistema-educativo' ); ?></th>
					<?php if ( $usa_sumativa ) : ?>
					<th><?php esc_html_e( 'Examen (15%)', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Proyecto (15%)', 'sistema-educativo' ); ?></th>
					<?php else : ?>
					<th><?php esc_html_e( 'Examen (30%)', 'sistema-educativo' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Nota trimestre', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $students as $st ) :
					$sid       = (int) $st->id;
					$t_row     = $trimester_rows[ $sid ] ?? null;
					$is_closed = $t_row && (int) $t_row->is_closed === 1;

					$has_p1 = isset( $parcial1_rows[ $sid ] );
					$has_p2 = isset( $parcial2_rows[ $sid ] );
					$p1_val = $has_p1 ? $parcial1_rows[ $sid ] : null;
					$p2_val = $has_p2 ? $parcial2_rows[ $sid ] : null;

					$p1_disp = $has_p1 ? number_format( $p1_val, 2 ) : '—';
					$p2_disp = $has_p2 ? number_format( $p2_val, 2 ) : '—';

					if ( $has_p1 && $has_p2 ) {
						$p_avg      = ( $p1_val + $p2_val ) / 2;
						$p_avg_disp = number_format( $p_avg, 2 );
					} elseif ( $has_p1 ) {
						$p_avg      = $p1_val;
						$p_avg_disp = number_format( $p_avg, 2 ) . ' *';
					} else {
						$p_avg      = null;
						$p_avg_disp = '—';
					}

					$exam_val = $t_row ? number_format( (float) $t_row->final_exam_score, 2, '.', '' ) : '';
					$proy_val = $t_row ? number_format( (float) $t_row->proyecto_score,   2, '.', '' ) : '';

					if ( $t_row && $p_avg !== null ) {
						if ( $usa_sumativa ) {
							$projected = number_format( $p_avg * 0.70 + ( ( (float) $t_row->final_exam_score + (float) $t_row->proyecto_score ) / 2 ) * 0.30, 2 );
						} else {
							$projected = number_format( $p_avg * 0.70 + (float) $t_row->final_exam_score * 0.30, 2 );
						}
					} elseif ( $t_row && (float) $t_row->computed_score > 0 ) {
						$projected = number_format( (float) $t_row->computed_score, 2 );
					} else {
						$projected = '—';
					}
				?>
				<tr<?php echo $is_closed ? ' style="opacity:0.6;"' : ''; ?>>
					<td>
						<strong><?php echo esc_html( $st->display_name ); ?></strong>
						<?php if ( $st->cedula ) : ?><br><small><?php echo esc_html( $st->cedula ); ?></small><?php endif; ?>
						<?php if ( $is_closed ) : ?><br><small style="color:#b00;"><?php esc_html_e( 'Trimestre cerrado', 'sistema-educativo' ); ?></small><?php endif; ?>
					</td>
					<td><?php echo esc_html( $p1_disp ); ?></td>
					<td><?php echo esc_html( $p2_disp ); ?></td>
					<td><?php echo esc_html( $p_avg_disp ); ?></td>
					<td>
						<input type="number" step="0.01" min="0" max="10"
							name="exam[<?php echo $sid; ?>]"
							value="<?php echo esc_attr( $exam_val ); ?>"
							style="width:80px;"
							<?php echo $is_closed ? 'disabled' : ''; ?>>
					</td>
					<?php if ( $usa_sumativa ) : ?>
					<td>
						<input type="number" step="0.01" min="0" max="10"
							name="proyecto[<?php echo $sid; ?>]"
							value="<?php echo esc_attr( $proy_val ); ?>"
							style="width:80px;"
							<?php echo $is_closed ? 'disabled' : ''; ?>>
					</td>
					<?php endif; ?>
					<td><strong><?php echo esc_html( $projected ); ?></strong></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description" style="margin-top:8px;">
			<?php esc_html_e( 'Parcial 1 y 2 muestran la nota calculada actual. * = solo parcial 1 disponible.', 'sistema-educativo' ); ?><br>
			<?php if ( $usa_sumativa ) : ?>
				<?php esc_html_e( 'Fórmula: Nota trimestre = Promedio parciales × 0.70 + ((Examen + Proyecto) / 2) × 0.30', 'sistema-educativo' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Fórmula: Nota trimestre = Promedio parciales × 0.70 + Examen × 0.30', 'sistema-educativo' ); ?>
			<?php endif; ?>
		</p>
		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar evaluación sumativa', 'sistema-educativo' ); ?></button>
		</p>
	</form>

	<?php endif; ?>
	<?php endif; ?>
</div>
