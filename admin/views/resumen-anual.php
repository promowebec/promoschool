<?php
/**
 * Vista: resumen anual de calificaciones por (grado + materia + período).
 *
 * Tabla con T1/T2/T3/promedio/estado y, para estudiantes en recuperación,
 * input inline para la nota del examen que corresponde al estado actual.
 * Submit batch vía Edu_Year_Score_Controller::handle_save_recovery().
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
	echo '<div class="wrap"><h1>' . esc_html__( 'Resumen anual', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

global $wpdb;
$tg  = $wpdb->prefix . 'edu_grades';
$ts  = $wpdb->prefix . 'edu_subjects';
$tp  = $wpdb->prefix . 'edu_periods';
$tgs = $wpdb->prefix . 'edu_grade_subjects';
$tst = $wpdb->prefix . 'edu_students';
$tys = $wpdb->prefix . 'edu_year_scores';

$grade_id   = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;
$subject_id = isset( $_GET['subject_id'] ) ? (int) $_GET['subject_id'] : 0;
$period_id  = isset( $_GET['period_id'] ) ? (int) $_GET['period_id'] : 0;
$status_msg = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$code       = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
$saved      = isset( $_GET['saved'] ) ? (int) $_GET['saved'] : 0;

$grades = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
	$institution_id
) );

$subjects = array();
if ( $grade_id ) {
	$subjects = $wpdb->get_results( $wpdb->prepare(
		"SELECT s.id, s.name, s.code FROM $tgs gs
		 INNER JOIN $ts s ON s.id = gs.subject_id
		 WHERE gs.grade_id = %d ORDER BY s.name",
		$grade_id
	) );
}

$periods = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name FROM $tp WHERE institution_id = %d ORDER BY start_date DESC",
	$institution_id
) );

$status_labels = array(
	'en_curso'  => array( 'label' => __( 'En curso', 'sistema-educativo' ),   'color' => '#666' ),
	'aprobado'  => array( 'label' => __( 'Aprobado', 'sistema-educativo' ),   'color' => 'green' ),
	'supletorio'=> array( 'label' => __( 'Supletorio', 'sistema-educativo' ), 'color' => '#996600' ),
	'remedial'  => array( 'label' => __( 'Remedial', 'sistema-educativo' ),   'color' => '#cc4400' ),
	'gracia'    => array( 'label' => __( 'Gracia', 'sistema-educativo' ),     'color' => '#aa0000' ),
	'reprobado' => array( 'label' => __( 'Reprobado', 'sistema-educativo' ),  'color' => '#b00' ),
);

$recovery_statuses = array( 'supletorio', 'remedial', 'gracia' );
$recovery_field_map = array(
	'supletorio' => 'supletorio_score',
	'remedial'   => 'remedial_score',
	'gracia'     => 'gracia_score',
);

$ready = ( $grade_id && $subject_id && $period_id );
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Resumen anual', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( 'updated' === $status_msg ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php echo esc_html( sprintf( _n( '%d nota guardada.', '%d notas guardadas.', $saved, 'sistema-educativo' ), $saved ) ); ?>
		</p></div>
	<?php elseif ( 'error' === $status_msg ) : ?>
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
		<input type="hidden" name="page" value="edu-resumen-anual">

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

		<label style="margin-left:12px;"><strong><?php esc_html_e( 'Período:', 'sistema-educativo' ); ?></strong>
			<select name="period_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $periods as $p ) : ?>
					<option value="<?php echo (int) $p->id; ?>" <?php selected( $period_id, (int) $p->id ); ?>>
						<?php echo esc_html( $p->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
	</form>

	<?php if ( $ready ) :
		$students = $wpdb->get_results( $wpdb->prepare(
			"SELECT st.id, st.user_id, u.display_name, st.cedula
			 FROM $tst st
			 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
			 WHERE st.grade_id = %d AND st.status = 'active'
			 ORDER BY u.display_name",
			$grade_id
		) );

		$student_ids = array_map( 'intval', wp_list_pluck( $students, 'id' ) );

		$year_rows = array();
		if ( $student_ids ) {
			$sid_in = implode( ',', $student_ids );
			$rows   = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $tys
				 WHERE subject_id = %d AND period_id = %d AND student_id IN ($sid_in)",
				$subject_id, $period_id
			) );
			foreach ( $rows as $r ) {
				$year_rows[ (int) $r->student_id ] = $r;
			}
		}

		$has_recovery = false;
		foreach ( $year_rows as $r ) {
			if ( in_array( $r->status, $recovery_statuses, true ) ) {
				$has_recovery = true;
				break;
			}
		}
	?>

	<?php
	// Sub-nivel del grado para equivalencia cualitativa.
	$grade_sub_level = (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT sub_level FROM $tg WHERE id = %d", $grade_id
	) );
	?>

	<?php if ( empty( $students ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'El grado no tiene estudiantes activos.', 'sistema-educativo' ); ?></p></div>
	<?php else : ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'edu_save_recovery' ); ?>
		<input type="hidden" name="action"     value="edu_save_recovery">
		<input type="hidden" name="grade_id"   value="<?php echo (int) $grade_id; ?>">
		<input type="hidden" name="subject_id" value="<?php echo (int) $subject_id; ?>">
		<input type="hidden" name="period_id"  value="<?php echo (int) $period_id; ?>">

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'T1', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'T2', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'T3', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Promedio', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Cualitativa', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Supletorio', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Remedial', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Gracia', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					<?php if ( $has_recovery ) : ?>
					<th><?php esc_html_e( 'Ingresar nota', 'sistema-educativo' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $students as $st ) :
					$yr      = $year_rows[ (int) $st->id ] ?? null;
					$st_info = $status_labels[ $yr ? $yr->status : 'en_curso' ];

					$fmt = function( $v ) {
						return null !== $v ? number_format( (float) $v, 2 ) : '—';
					};
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $st->display_name ); ?></strong>
						<?php if ( $st->cedula ) : ?><br><small><?php echo esc_html( $st->cedula ); ?></small><?php endif; ?>
					</td>
					<td>
						<?php if ( $yr && (float) $yr->trim1 > 0 ) : ?>
							<?php echo esc_html( number_format( (float) $yr->trim1, 2 ) ); ?>&nbsp;
							<?php echo Edu_Qualitativa_Helper::badge( (float) $yr->trim1, $grade_sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php else : ?>—<?php endif; ?>
					</td>
					<td>
						<?php if ( $yr && (float) $yr->trim2 > 0 ) : ?>
							<?php echo esc_html( number_format( (float) $yr->trim2, 2 ) ); ?>&nbsp;
							<?php echo Edu_Qualitativa_Helper::badge( (float) $yr->trim2, $grade_sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php else : ?>—<?php endif; ?>
					</td>
					<td>
						<?php if ( $yr && (float) $yr->trim3 > 0 ) : ?>
							<?php echo esc_html( number_format( (float) $yr->trim3, 2 ) ); ?>&nbsp;
							<?php echo Edu_Qualitativa_Helper::badge( (float) $yr->trim3, $grade_sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php else : ?>—<?php endif; ?>
					</td>
					<td>
						<strong><?php echo esc_html( $yr ? number_format( (float) $yr->average, 2 ) : '—' ); ?></strong>
					</td>
					<td>
						<?php if ( $yr && (float) $yr->average > 0 ) : ?>
							<?php echo Edu_Qualitativa_Helper::badge( (float) $yr->average, $grade_sub_level ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php else : ?>
							<span style="color:#9ca3af;">—</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $yr ? $fmt( $yr->supletorio_score ) : '—' ); ?></td>
					<td><?php echo esc_html( $yr ? $fmt( $yr->remedial_score ) : '—' ); ?></td>
					<td><?php echo esc_html( $yr ? $fmt( $yr->gracia_score ) : '—' ); ?></td>
					<td>
						<span style="color:<?php echo esc_attr( $st_info['color'] ); ?>; font-weight:600;">
							<?php echo esc_html( $st_info['label'] ); ?>
						</span>
					</td>
					<?php if ( $has_recovery ) : ?>
					<td>
						<?php if ( $yr && in_array( $yr->status, $recovery_statuses, true ) ) :
							$recovery_label = array(
								'supletorio' => __( 'Supletorio', 'sistema-educativo' ),
								'remedial'   => __( 'Remedial', 'sistema-educativo' ),
								'gracia'     => __( 'Gracia', 'sistema-educativo' ),
							);
							$field       = $recovery_field_map[ $yr->status ];
							$current_val = null !== $yr->$field
								? number_format( (float) $yr->$field, 2, '.', '' )
								: '';
						?>
							<small><?php echo esc_html( $recovery_label[ $yr->status ] ); ?>:</small><br>
							<input type="number" step="0.01" min="0" max="10"
								name="recovery[<?php echo (int) $st->id; ?>]"
								value="<?php echo esc_attr( $current_val ); ?>"
								style="width:80px;">
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<?php endif; ?>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $has_recovery ) : ?>
		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Guardar notas de recuperación', 'sistema-educativo' ); ?>
			</button>
		</p>
		<?php endif; ?>
	</form>

	<?php endif; ?>
	<?php endif; ?>
</div>
