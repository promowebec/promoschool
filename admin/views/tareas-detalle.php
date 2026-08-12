<?php
/**
 * Vista: entregas de estudiantes para una tarea específica.
 *
 * Incluido desde tareas.php cuando action=detail.
 * Requiere $assignment ya cargado con grade_name, paralelo, subject_name, trim_num.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$tsb = $wpdb->prefix . 'edu_submissions';
$tsf = $wpdb->prefix . 'edu_submission_files';
$tst = $wpdb->prefix . 'edu_students';

$detail_status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$detail_code   = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';

// Todos los estudiantes del grado.
$students = $wpdb->get_results( $wpdb->prepare(
	"SELECT st.id, u.display_name, st.cedula
	 FROM $tst st
	 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
	 WHERE st.grade_id = %d AND st.status = 'active'
	 ORDER BY u.display_name",
	(int) $assignment->grade_id
) );

// Entregas existentes.
$student_ids = array_map( 'intval', wp_list_pluck( $students, 'id' ) );
$submissions = array();

if ( $student_ids ) {
	$ids_in = implode( ',', $student_ids );
	$rows   = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $tsb WHERE assignment_id = %d AND student_id IN ($ids_in)",
		(int) $assignment->id
	) );
	foreach ( $rows as $r ) {
		$submissions[ (int) $r->student_id ] = $r;
	}
}

// Archivos agrupados por submission_id.
$sub_files = array();
if ( $submissions ) {
	$sub_ids = implode( ',', array_map( 'intval', wp_list_pluck( array_values( $submissions ), 'id' ) ) );
	$frows   = $wpdb->get_results( "SELECT * FROM $tsf WHERE submission_id IN ($sub_ids)" );
	foreach ( $frows as $f ) {
		$sub_files[ (int) $f->submission_id ][] = $f;
	}
}

$status_labels = array(
	'submitted' => array( 'label' => __( 'Entregada', 'sistema-educativo' ),       'color' => 'green' ),
	'late'      => array( 'label' => __( 'Con retraso', 'sistema-educativo' ),      'color' => '#996600' ),
	'graded'    => array( 'label' => __( 'Calificada', 'sistema-educativo' ),       'color' => '#0073aa' ),
	'pending'   => array( 'label' => __( 'Sin entregar', 'sistema-educativo' ),     'color' => '#b00' ),
);
?>
<div class="wrap edu-wrap">
	<h1>
		<?php esc_html_e( 'Entregas', 'sistema-educativo' ); ?> — <?php echo esc_html( $assignment->title ); ?>
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-tareas', 'action' => 'edit', 'id' => $assignment->id ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
			<?php esc_html_e( '← Volver a la tarea', 'sistema-educativo' ); ?>
		</a>
	</h1>

	<p>
		<strong><?php esc_html_e( 'Grado:', 'sistema-educativo' ); ?></strong> <?php echo esc_html( $assignment->grade_name . ' ' . $assignment->paralelo ); ?> &nbsp;|&nbsp;
		<strong><?php esc_html_e( 'Materia:', 'sistema-educativo' ); ?></strong> <?php echo esc_html( $assignment->subject_name ); ?> &nbsp;|&nbsp;
		<strong><?php esc_html_e( 'Trimestre:', 'sistema-educativo' ); ?></strong> T<?php echo (int) $assignment->trim_num; ?> &nbsp;|&nbsp;
		<strong><?php esc_html_e( 'Nota máx.:', 'sistema-educativo' ); ?></strong> <?php echo number_format( (float) $assignment->max_score, 2 ); ?> &nbsp;|&nbsp;
		<strong><?php esc_html_e( 'Estado:', 'sistema-educativo' ); ?></strong>
		<?php
		$asl = array( 'draft' => __( 'Borrador', 'sistema-educativo' ), 'published' => __( 'Publicada', 'sistema-educativo' ), 'closed' => __( 'Cerrada', 'sistema-educativo' ) );
		echo esc_html( $asl[ $assignment->status ] ?? $assignment->status );
		?>
	</p>

	<?php if ( ! empty( $assignment->allow_recovery ) ) : ?>
	<div style="background:#fff3cd; border:1px solid #ffc107; border-radius:4px; padding:10px 16px; margin-bottom:12px; font-size:13px;">
		<strong><?php esc_html_e( 'Mejora activa', 'sistema-educativo' ); ?></strong>
		<?php if ( $assignment->recovery_due_date ) : ?>
			&nbsp;—&nbsp; <?php esc_html_e( 'Fecha límite:', 'sistema-educativo' ); ?>
			<strong><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $assignment->recovery_due_date ) ) ); ?></strong>
		<?php else : ?>
			&nbsp;—&nbsp; <?php esc_html_e( 'Sin fecha límite', 'sistema-educativo' ); ?>
		<?php endif; ?>
		&nbsp;|&nbsp; <?php esc_html_e( 'Criterio: se guarda la mejor nota', 'sistema-educativo' ); ?>
	</div>
	<?php endif; ?>

	<?php if ( 'graded' === $detail_status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Calificación guardada.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $detail_status ) : ?>
		<div class="notice notice-error"><p>
		<?php
		$msgs = array(
			'not_found'     => __( 'Entrega no encontrada.', 'sistema-educativo' ),
			'invalid_score' => __( 'Nota inválida.', 'sistema-educativo' ),
		);
		echo esc_html( $msgs[ $detail_code ] ?? __( 'Error.', 'sistema-educativo' ) );
		?>
		</p></div>
	<?php endif; ?>

	<?php if ( empty( $students ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'El grado no tiene estudiantes activos.', 'sistema-educativo' ); ?></p></div>
	<?php else : ?>
	<table class="widefat striped" style="margin-top:16px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Entregado', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Comentario', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Archivos', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Nota', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Retroalimentación', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Calificar', 'sistema-educativo' ); ?></th>
				<?php if ( ! empty( $assignment->allow_recovery ) ) : ?>
				<th><?php esc_html_e( 'Mejora', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Nota mejora', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Calificar mejora', 'sistema-educativo' ); ?></th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $students as $st ) :
			$sub = $submissions[ (int) $st->id ] ?? null;
			$sub_status = $sub ? $sub->status : 'pending';
			$sl  = $status_labels[ $sub_status ] ?? array( 'label' => $sub_status, 'color' => '#666' );
			$files_of_sub = $sub ? ( $sub_files[ (int) $sub->id ] ?? array() ) : array();
		?>
			<tr>
				<td>
					<strong><?php echo esc_html( $st->display_name ); ?></strong>
					<?php if ( $st->cedula ) : ?><br><small><?php echo esc_html( $st->cedula ); ?></small><?php endif; ?>
				</td>
				<td>
					<span style="color:<?php echo esc_attr( $sl['color'] ); ?>; font-weight:600;">
						<?php echo esc_html( $sl['label'] ); ?>
					</span>
				</td>
				<td>
					<?php echo $sub ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $sub->submitted_at ) ) ) : '—'; ?>
				</td>
				<td style="max-width:200px; word-break:break-word;">
					<?php echo $sub && $sub->comment ? wp_kses_post( $sub->comment ) : '—'; ?>
				</td>
				<td>
					<?php if ( $files_of_sub ) : ?>
						<ul style="margin:0; padding:0; list-style:none;">
						<?php foreach ( $files_of_sub as $f ) : ?>
							<li>
								<a href="<?php echo esc_url( Edu_Submission_Controller::get_sub_download_url( $f->id ) ); ?>" target="_blank">
									<?php echo esc_html( $f->file_name ); ?>
								</a>
								<small>(<?php echo esc_html( size_format( (int) $f->file_size ) ); ?>)</small>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
				<td>
					<?php echo $sub && null !== $sub->score ? esc_html( number_format( (float) $sub->score, 2 ) ) : '—'; ?>
				</td>
				<td style="max-width:180px; word-break:break-word;">
					<?php echo $sub && $sub->feedback ? wp_kses_post( $sub->feedback ) : '—'; ?>
				</td>
				<td>
					<?php if ( $sub && in_array( $sub->status, array( 'submitted', 'late', 'graded' ), true ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="min-width:160px;">
						<?php wp_nonce_field( 'edu_grade_submission' ); ?>
						<input type="hidden" name="action"        value="edu_grade_submission">
						<input type="hidden" name="submission_id" value="<?php echo (int) $sub->id; ?>">
						<label style="display:block; margin-bottom:4px;">
							<?php esc_html_e( 'Nota:', 'sistema-educativo' ); ?>
							<input type="number" name="score" step="0.01" min="0"
								max="<?php echo esc_attr( number_format( (float) $assignment->max_score, 2, '.', '' ) ); ?>"
								value="<?php echo $sub->score !== null ? esc_attr( number_format( (float) $sub->score, 2, '.', '' ) ) : ''; ?>"
								style="width:70px;">
						</label>
						<label style="display:block; margin-bottom:4px;">
							<?php esc_html_e( 'Retroalimentación:', 'sistema-educativo' ); ?><br>
							<textarea name="feedback" rows="2" style="width:100%; max-width:200px;"><?php echo $sub->feedback ? esc_textarea( $sub->feedback ) : ''; ?></textarea>
						</label>
						<button type="submit" class="button button-small button-primary">
							<?php esc_html_e( 'Guardar', 'sistema-educativo' ); ?>
						</button>
					</form>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>

				<?php if ( ! empty( $assignment->allow_recovery ) ) :
					$rec_labels = array(
						'none'      => array( 'label' => __( '—', 'sistema-educativo' ),              'color' => '#666' ),
						'available' => array( 'label' => __( 'Disponible', 'sistema-educativo' ),      'color' => '#996600' ),
						'submitted' => array( 'label' => __( 'Entregada', 'sistema-educativo' ),       'color' => 'green' ),
						'graded'    => array( 'label' => __( 'Calificada', 'sistema-educativo' ),      'color' => '#0073aa' ),
					);
					$rec_status = $sub ? ( $sub->recovery_status ?? 'none' ) : 'none';
					$rl = $rec_labels[ $rec_status ] ?? $rec_labels['none'];
				?>
				<td>
					<span style="color:<?php echo esc_attr( $rl['color'] ); ?>; font-weight:600;">
						<?php echo esc_html( $rl['label'] ); ?>
					</span>
					<?php if ( $sub && $sub->recovery_submitted_at ) : ?>
						<br><small><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $sub->recovery_submitted_at ) ) ); ?></small>
					<?php endif; ?>
					<?php if ( $sub && $sub->recovery_comment ) : ?>
						<br><small style="color:#555;"><?php echo wp_kses_post( $sub->recovery_comment ); ?></small>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $sub && null !== $sub->recovery_score ) :
						$mejor = max( (float) ( $sub->score ?? 0 ), (float) $sub->recovery_score );
					?>
						<?php echo esc_html( number_format( (float) $sub->recovery_score, 2 ) ); ?>
						<br><small style="color:#0073aa;"><?php esc_html_e( 'Mejor:', 'sistema-educativo' ); ?> <strong><?php echo esc_html( number_format( $mejor, 2 ) ); ?></strong></small>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $sub && 'submitted' === $rec_status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="min-width:160px;">
						<?php wp_nonce_field( 'edu_grade_recovery' ); ?>
						<input type="hidden" name="action"        value="edu_grade_recovery">
						<input type="hidden" name="submission_id" value="<?php echo (int) $sub->id; ?>">
						<label style="display:block; margin-bottom:4px;">
							<?php esc_html_e( 'Nota mejora:', 'sistema-educativo' ); ?>
							<input type="number" name="recovery_score" step="0.01" min="0"
								max="<?php echo esc_attr( number_format( (float) $assignment->max_score, 2, '.', '' ) ); ?>"
								style="width:70px;">
						</label>
						<label style="display:block; margin-bottom:4px;">
							<?php esc_html_e( 'Retroalimentación:', 'sistema-educativo' ); ?><br>
							<textarea name="recovery_feedback" rows="2" style="width:100%; max-width:200px;"></textarea>
						</label>
						<button type="submit" class="button button-small button-primary">
							<?php esc_html_e( 'Calificar mejora', 'sistema-educativo' ); ?>
						</button>
					</form>
					<?php elseif ( $sub && 'graded' === $rec_status ) : ?>
						<span style="color:#0073aa;">&#10003; <?php esc_html_e( 'Calificada', 'sistema-educativo' ); ?></span>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
				<?php endif; ?>

			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
