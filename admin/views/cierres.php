<?php
/**
 * Vista: panel de cierre de parciales y trimestres.
 *
 * Filtros (grado + materia + trimestre) → tabla con estado de cada parcial
 * (abierto/cerrado, cuántos estudiantes tienen nota) y botones de cierre.
 * Botón de cierre de trimestre disponible solo cuando ambos parciales cerrados.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_close_partial' ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

$institution_id = Edu_Context::current_institution_id();
if ( ! $institution_id ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Cierres', 'sistema-educativo' ) . '</h1>';
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
$tps = $wpdb->prefix . 'edu_parcial_scores';
$tts = $wpdb->prefix . 'edu_trimester_scores';

$grade_id     = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;
$subject_id   = isset( $_GET['subject_id'] ) ? (int) $_GET['subject_id'] : 0;
$trimester_id = isset( $_GET['trimester_id'] ) ? (int) $_GET['trimester_id'] : 0;
$status       = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$code         = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
$parcial_num  = isset( $_GET['parcial_num'] ) ? (int) $_GET['parcial_num'] : 0;

$grades = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
	$institution_id
) );

$subjects = array();
if ( $grade_id ) {
	$subjects = $wpdb->get_results( $wpdb->prepare(
		"SELECT s.id, s.name FROM $tgs gs INNER JOIN $ts s ON s.id = gs.subject_id
		 WHERE gs.grade_id = %d ORDER BY s.name",
		$grade_id
	) );
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
	<h1><?php esc_html_e( 'Cierre de parciales y trimestre', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( 'parcial_closed' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php echo esc_html( sprintf( __( 'Parcial %d cerrado correctamente.', 'sistema-educativo' ), $parcial_num ) ); ?>
		</p></div>
	<?php elseif ( 'trimester_closed' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php esc_html_e( 'Trimestre cerrado. Nota anual actualizada.', 'sistema-educativo' ); ?>
		</p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$msgs = array(
				'invalid_parcial' => __( 'Parcial inválido.', 'sistema-educativo' ),
				'no_students'     => __( 'El grado no tiene estudiantes activos.', 'sistema-educativo' ),
				'parcials_open'   => __( 'No se puede cerrar el trimestre: hay parciales aún abiertos.', 'sistema-educativo' ),
				'invalid_scope'   => __( 'El grado, la materia o el trimestre no pertenecen a la institución activa.', 'sistema-educativo' ),
			);
			echo esc_html( $msgs[ $code ] ?? __( 'Error.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
		<input type="hidden" name="page" value="edu-cierres">

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
						<?php echo esc_html( $s->name ); ?>
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
		$total_students = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $tst WHERE grade_id = %d AND status = 'active'",
			$grade_id
		) );

		// Estado de cada parcial.
		$parcial_info = array();
		foreach ( array( 1, 2 ) as $pnum ) {
			$closed = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $tps
				 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d AND is_closed = 1",
				$subject_id, $trimester_id, $pnum
			) );
			$with_score = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $tps
				 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d",
				$subject_id, $trimester_id, $pnum
			) );
			$parcial_info[ $pnum ] = array(
				'closed'     => $closed > 0,
				'with_score' => $with_score,
			);
		}

		$both_closed = $parcial_info[1]['closed'] && $parcial_info[2]['closed'];

		// Estado del trimestre.
		$trim_closed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $tts
			 WHERE subject_id = %d AND trimester_id = %d AND is_closed = 1",
			$subject_id, $trimester_id
		) ) > 0;
	?>

	<table class="widefat" style="max-width:700px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Elemento', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Estudiantes con nota', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Acción', 'sistema-educativo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( array( 1, 2 ) as $pnum ) :
				$info   = $parcial_info[ $pnum ];
				$closed = $info['closed'];
			?>
			<tr>
				<td><strong><?php echo esc_html( sprintf( __( 'Parcial %d', 'sistema-educativo' ), $pnum ) ); ?></strong></td>
				<td><?php echo esc_html( $info['with_score'] . ' / ' . $total_students ); ?></td>
				<td>
					<?php if ( $closed ) : ?>
						<span style="color:green;">&#10003; <?php esc_html_e( 'Cerrado', 'sistema-educativo' ); ?></span>
					<?php else : ?>
						<span style="color:#b00;">&#9675; <?php esc_html_e( 'Abierto', 'sistema-educativo' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( ! $trim_closed ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							onsubmit="return confirm('<?php
								if ( $closed ) {
									echo esc_js( sprintf( __( '¿Re-cerrar Parcial %d? Se recalcularán las notas de los estudiantes que aún no tenían cierre. Las notas ya cerradas no se modifican.', 'sistema-educativo' ), $pnum ) );
								} else {
									echo esc_js( sprintf( __( '¿Cerrar Parcial %d? Esta acción fijará las notas actuales.', 'sistema-educativo' ), $pnum ) );
								}
							?>')">
							<?php wp_nonce_field( 'edu_close_parcial' ); ?>
							<input type="hidden" name="action"       value="edu_close_parcial">
							<input type="hidden" name="grade_id"     value="<?php echo (int) $grade_id; ?>">
							<input type="hidden" name="subject_id"   value="<?php echo (int) $subject_id; ?>">
							<input type="hidden" name="trimester_id" value="<?php echo (int) $trimester_id; ?>">
							<input type="hidden" name="parcial_num"  value="<?php echo (int) $pnum; ?>">
							<button type="submit" class="button <?php echo $closed ? 'button-secondary' : 'button-primary'; ?>">
								<?php
								if ( $closed ) {
									echo esc_html( sprintf( __( 'Re-cerrar Parcial %d', 'sistema-educativo' ), $pnum ) );
								} else {
									echo esc_html( sprintf( __( 'Cerrar Parcial %d', 'sistema-educativo' ), $pnum ) );
								}
								?>
							</button>
						</form>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>

			<tr style="background:#f9f9f9;">
				<td><strong><?php esc_html_e( 'Trimestre', 'sistema-educativo' ); ?></strong></td>
				<td>—</td>
				<td>
					<?php if ( $trim_closed ) : ?>
						<span style="color:green;">&#10003; <?php esc_html_e( 'Cerrado', 'sistema-educativo' ); ?></span>
					<?php elseif ( $both_closed ) : ?>
						<span style="color:#996600;">&#9650; <?php esc_html_e( 'Listo para cerrar', 'sistema-educativo' ); ?></span>
					<?php else : ?>
						<span style="color:#b00;">&#9675; <?php esc_html_e( 'Esperando cierres de parciales', 'sistema-educativo' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $both_closed && ! $trim_closed ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							onsubmit="return confirm('<?php echo esc_js( __( '¿Cerrar el trimestre? Esta acción no se puede deshacer.', 'sistema-educativo' ) ); ?>')">
							<?php wp_nonce_field( 'edu_close_trimester' ); ?>
							<input type="hidden" name="action"       value="edu_close_trimester">
							<input type="hidden" name="grade_id"     value="<?php echo (int) $grade_id; ?>">
							<input type="hidden" name="subject_id"   value="<?php echo (int) $subject_id; ?>">
							<input type="hidden" name="trimester_id" value="<?php echo (int) $trimester_id; ?>">
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Cerrar trimestre', 'sistema-educativo' ); ?>
							</button>
						</form>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<?php if ( $trim_closed ) : ?>
		<div class="notice notice-info" style="margin-top:16px;"><p>
			<?php esc_html_e( 'El trimestre está cerrado. Las notas anuales han sido actualizadas en "Resumen anual".', 'sistema-educativo' ); ?>
		</p></div>
	<?php endif; ?>

	<?php endif; ?>
</div>
