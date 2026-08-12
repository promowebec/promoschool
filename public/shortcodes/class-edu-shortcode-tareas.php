<?php
/**
 * Shortcode [edu_mis_tareas] — portal del estudiante.
 *
 * Muestra la lista de tareas publicadas para el grado del estudiante,
 * su estado de entrega y el formulario para subir archivos.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Shortcode_Tareas {

	public static function register() {
		add_shortcode( 'edu_mis_tareas', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Debes iniciar sesión para ver las tareas.', 'sistema-educativo' ) . '</p>';
		}

		$is_padre = current_user_can( 'edu_view_child_grades' );

		if ( ! Edu_Context::can( 'edu_submit_assignment' ) && ! $is_padre ) {
			return '<p>' . esc_html__( 'Esta sección es solo para estudiantes y representantes.', 'sistema-educativo' ) . '</p>';
		}

		// Padre: mostrar selector de hijo y delegar al render del hijo.
		if ( $is_padre ) {
			return self::render_padre();
		}

		global $wpdb;
		$ta  = $wpdb->prefix . 'edu_assignments';
		$taf = $wpdb->prefix . 'edu_assignment_files';
		$tst = $wpdb->prefix . 'edu_students';
		$tsb = $wpdb->prefix . 'edu_submissions';
		$tsf = $wpdb->prefix . 'edu_submission_files';
		$ts  = $wpdb->prefix . 'edu_subjects';
		$tt  = $wpdb->prefix . 'edu_trimesters';
		$ttr = $wpdb->prefix . 'edu_teachers';

		$student = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, grade_id FROM $tst WHERE user_id = %d",
			get_current_user_id()
		) );

		if ( ! $student ) {
			return '<p>' . esc_html__( 'No se encontró tu registro de estudiante.', 'sistema-educativo' ) . '</p>';
		}

		// Mensajes de redirección.
		$edu_status     = isset( $_GET['edu_status'] ) ? sanitize_key( $_GET['edu_status'] ) : '';
		$edu_assignment = isset( $_GET['edu_assignment'] ) ? (int) $_GET['edu_assignment'] : 0;
		$edu_code       = isset( $_GET['edu_code'] ) ? sanitize_key( $_GET['edu_code'] ) : '';

		// Parámetro de expansión: qué tarea está activa (accordion).
		$open_id = isset( $_GET['edu_open'] ) ? (int) $_GET['edu_open'] : $edu_assignment;

		// Tareas publicadas del grado del estudiante, ordenadas por fecha límite.
		$assignments = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, s.name AS subject_name, t.number AS trim_num,
			        u.display_name AS teacher_name
			 FROM $ta a
			 INNER JOIN $ts s ON s.id = a.subject_id
			 INNER JOIN $tt t ON t.id = a.trimester_id
			 LEFT JOIN $ttr tr ON tr.id = a.teacher_id
			 LEFT JOIN {$wpdb->users} u ON u.ID = tr.user_id
			 WHERE a.grade_id = %d AND a.status IN ('published','closed')
			 ORDER BY a.due_date ASC, a.created_at DESC",
			(int) $student->grade_id
		) );

		if ( empty( $assignments ) ) {
			return '<div class="edu-tareas"><p>' . esc_html__( 'No hay tareas publicadas para tu grado.', 'sistema-educativo' ) . '</p></div>';
		}

		$assignment_ids = array_map( 'intval', wp_list_pluck( $assignments, 'id' ) );
		$ids_in         = implode( ',', $assignment_ids );

		// Entregas del estudiante.
		$subs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $tsb WHERE assignment_id IN ($ids_in) AND student_id = %d",
			(int) $student->id
		) );
		$submissions = array();
		foreach ( $subs as $s ) {
			$submissions[ (int) $s->assignment_id ] = $s;
		}

		// Archivos de entrega.
		$sub_files = array();
		if ( $subs ) {
			$sub_ids = implode( ',', array_map( 'intval', wp_list_pluck( $subs, 'id' ) ) );
			$frows   = $wpdb->get_results( "SELECT * FROM $tsf WHERE submission_id IN ($sub_ids)" );
			foreach ( $frows as $f ) {
				$sub_files[ (int) $f->submission_id ][] = $f;
			}
		}

		// Archivos adjuntos de la tarea.
		$task_files_map = array();
		$tfiles = $wpdb->get_results( "SELECT * FROM $taf WHERE assignment_id IN ($ids_in)" );
		foreach ( $tfiles as $f ) {
			$task_files_map[ (int) $f->assignment_id ][] = $f;
		}

		$type_labels = array(
			'tarea'      => __( 'Tarea', 'sistema-educativo' ),
			'leccion'    => __( 'Lección', 'sistema-educativo' ),
			'trabajo'    => __( 'Trabajo', 'sistema-educativo' ),
			'deber'      => __( 'Deber', 'sistema-educativo' ),
			'examen'     => __( 'Examen', 'sistema-educativo' ),
			'correccion' => __( 'Corrección', 'sistema-educativo' ),
		);

		ob_start();
		?>
		<div class="edu-tareas">

		<?php if ( $edu_status ) :
			if ( 'submitted' === $edu_status ) : ?>
				<div class="edu-notice edu-notice-success"><?php esc_html_e( 'Entrega realizada correctamente.', 'sistema-educativo' ); ?></div>
			<?php elseif ( 'error' === $edu_status ) :
				$err_msgs = array(
					'already_graded' => __( 'Esta tarea ya fue calificada y no puede modificarse.', 'sistema-educativo' ),
				); ?>
				<div class="edu-notice edu-notice-error"><?php echo esc_html( $err_msgs[ $edu_code ] ?? __( 'Ocurrió un error.', 'sistema-educativo' ) ); ?></div>
			<?php endif;
		endif; ?>

		<?php foreach ( $assignments as $a ) :
			$sub           = $submissions[ (int) $a->id ] ?? null;
			$sub_status    = $sub ? $sub->status : 'pending';
			$is_overdue    = $a->due_date && strtotime( $a->due_date ) < time() && ! $sub;
			$can_submit    = 'published' === $a->status && ( ! $sub || 'graded' !== $sub->status );
			$files_of_sub  = $sub ? ( $sub_files[ (int) $sub->id ] ?? array() ) : array();
			$task_files    = $task_files_map[ (int) $a->id ] ?? array();
			$is_open       = (int) $a->id === $open_id;

			$badge_text  = '';
			$badge_style = '';
			if ( 'graded' === $sub_status ) {
				$badge_text  = __( 'Calificada', 'sistema-educativo' );
				$badge_style = 'background:#0073aa;color:#fff;';
			} elseif ( in_array( $sub_status, array( 'submitted', 'late' ), true ) ) {
				$badge_text  = 'late' === $sub_status ? __( 'Entregada (tarde)', 'sistema-educativo' ) : __( 'Entregada', 'sistema-educativo' );
				$badge_style = 'background:green;color:#fff;';
			} elseif ( $is_overdue ) {
				$badge_text  = __( 'Vencida', 'sistema-educativo' );
				$badge_style = 'background:#b00;color:#fff;';
			} elseif ( 'closed' === $a->status ) {
				$badge_text  = __( 'Cerrada', 'sistema-educativo' );
				$badge_style = 'background:#999;color:#fff;';
			} else {
				$badge_text  = __( 'Pendiente', 'sistema-educativo' );
				$badge_style = 'background:#f0a500;color:#fff;';
			}
			?>
			<div class="edu-tarea-card" style="border:1px solid #ddd; border-radius:6px; margin-bottom:12px; overflow:hidden;">
				<div class="edu-tarea-header" style="padding:12px 16px; background:#f9f9f9; display:flex; justify-content:space-between; align-items:center; cursor:pointer;"
					onclick="(function(el){var b=el.closest('.edu-tarea-card').querySelector('.edu-tarea-body');b.style.display=b.style.display==='none'?'block':'none';})(this)">
					<div>
						<strong><?php echo esc_html( $a->title ); ?></strong>
						<small style="display:block; color:#666; margin-top:2px;">
							<?php echo esc_html( $type_labels[ $a->type ] ?? $a->type ); ?> · <?php echo esc_html( $a->subject_name ); ?>
							<?php if ( ! empty( $a->teacher_name ) ) : ?>
								· <span style="color:#0073aa;"><?php echo esc_html( $a->teacher_name ); ?></span>
							<?php endif; ?>
						</small>
					</div>
					<div style="display:flex; gap:8px; align-items:center;">
						<?php if ( $a->due_date ) : ?>
							<small style="color:#666;">
								<?php esc_html_e( 'Límite:', 'sistema-educativo' ); ?>
								<?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $a->due_date ) ) ); ?>
							</small>
						<?php endif; ?>
						<span style="padding:2px 8px; border-radius:4px; font-size:12px; font-weight:600; <?php echo esc_attr( $badge_style ); ?>">
							<?php echo esc_html( $badge_text ); ?>
						</span>
					</div>
				</div>

				<div class="edu-tarea-body" style="padding:16px; display:<?php echo $is_open ? 'block' : 'none'; ?>;">

					<?php if ( $a->description ) : ?>
						<div style="margin-bottom:12px;"><?php echo wp_kses_post( $a->description ); ?></div>
					<?php endif; ?>

					<?php if ( $task_files ) : ?>
						<p><strong><?php esc_html_e( 'Archivos adjuntos:', 'sistema-educativo' ); ?></strong></p>
						<ul style="margin:0 0 12px; padding-left:20px;">
						<?php foreach ( $task_files as $f ) : ?>
							<li>
								<a href="<?php echo esc_url( Edu_Assignment_Task_Controller::get_download_url( $f->id ) ); ?>">
									<?php echo esc_html( $f->file_name ); ?>
								</a>
								<small>(<?php echo esc_html( size_format( (int) $f->file_size ) ); ?>)</small>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $sub && 'graded' === $sub->status ) : ?>
						<div style="background:#f0f8ff; border-left:4px solid #0073aa; padding:10px 14px; margin-bottom:12px;">
							<strong><?php esc_html_e( 'Nota:', 'sistema-educativo' ); ?></strong>
							<?php echo null !== $sub->score ? esc_html( number_format( (float) $sub->score, 2 ) . ' / ' . number_format( (float) $a->max_score, 2 ) ) : '—'; ?>
							<?php if ( $sub->feedback ) : ?>
								<br><strong><?php esc_html_e( 'Retroalimentación:', 'sistema-educativo' ); ?></strong>
								<?php echo wp_kses_post( $sub->feedback ); ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( $sub && $files_of_sub ) : ?>
						<p><strong><?php esc_html_e( 'Tu entrega:', 'sistema-educativo' ); ?></strong></p>
						<ul style="margin:0 0 12px; padding-left:20px;">
						<?php foreach ( $files_of_sub as $f ) : ?>
							<li>
								<a href="<?php echo esc_url( Edu_Submission_Controller::get_sub_download_url( $f->id ) ); ?>">
									<?php echo esc_html( $f->file_name ); ?>
								</a>
								<small>(<?php echo esc_html( size_format( (int) $f->file_size ) ); ?>)</small>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $can_submit ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data"
						style="border-top:1px solid #eee; padding-top:12px; margin-top:12px;">
						<?php wp_nonce_field( 'edu_submit_assignment' ); ?>
						<input type="hidden" name="action"        value="edu_submit_assignment">
						<input type="hidden" name="assignment_id" value="<?php echo (int) $a->id; ?>">

						<label style="display:block; margin-bottom:8px;">
							<strong><?php esc_html_e( 'Comentario (opcional):', 'sistema-educativo' ); ?></strong><br>
							<textarea name="comment" rows="3" style="width:100%; max-width:480px;"><?php echo $sub ? esc_textarea( $sub->comment ) : ''; ?></textarea>
						</label>

						<label style="display:block; margin-bottom:8px;">
							<strong><?php esc_html_e( 'Archivos:', 'sistema-educativo' ); ?></strong><br>
							<input type="file" name="archivos[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip">
							<br><small><?php esc_html_e( 'PDF, Word, Excel, PowerPoint, imágenes, ZIP. Máx. 10 MB por archivo.', 'sistema-educativo' ); ?></small>
						</label>

						<button type="submit" class="button button-primary">
							<?php echo $sub ? esc_html__( 'Volver a entregar', 'sistema-educativo' ) : esc_html__( 'Enviar entrega', 'sistema-educativo' ); ?>
						</button>
					</form>
					<?php endif; ?>

				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* -------------------------------------------------------------------------
	 * Vista del padre: selector de hijo + tareas en modo lectura
	 * ---------------------------------------------------------------------- */
	private static function render_padre() {
		global $wpdb;
		$tst = $wpdb->prefix . 'edu_students';
		$tps = $wpdb->prefix . 'edu_parents';
		$tpa = $wpdb->prefix . 'edu_parent_student';
		$ta  = $wpdb->prefix . 'edu_assignments';
		$taf = $wpdb->prefix . 'edu_assignment_files';
		$tsb = $wpdb->prefix . 'edu_submissions';
		$tsf = $wpdb->prefix . 'edu_submission_files';
		$ts  = $wpdb->prefix . 'edu_subjects';
		$tt  = $wpdb->prefix . 'edu_trimesters';

		$parent = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM $tps WHERE user_id = %d",
			get_current_user_id()
		) );
		if ( ! $parent ) {
			return '<p>' . esc_html__( 'No se encontró tu registro de representante.', 'sistema-educativo' ) . '</p>';
		}

		$children = $wpdb->get_results( $wpdb->prepare(
			"SELECT st.id, st.grade_id, u.display_name
			 FROM $tpa pa
			 INNER JOIN $tst st ON st.id = pa.student_id
			 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
			 WHERE pa.parent_id = %d AND st.status = 'active'
			 ORDER BY u.display_name",
			(int) $parent->id
		) );

		if ( empty( $children ) ) {
			return '<p>' . esc_html__( 'No tienes hijos registrados en el sistema.', 'sistema-educativo' ) . '</p>';
		}

		// Selector de hijo por GET.
		$child_id = isset( $_GET['edu_hijo'] ) ? (int) $_GET['edu_hijo'] : (int) $children[0]->id;
		$child    = null;
		foreach ( $children as $c ) {
			if ( (int) $c->id === $child_id ) {
				$child = $c;
				break;
			}
		}
		if ( ! $child ) {
			$child    = $children[0];
			$child_id = (int) $child->id;
		}

		$ttr = $wpdb->prefix . 'edu_teachers';

		$assignments = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, s.name AS subject_name, t.number AS trim_num,
			        u.display_name AS teacher_name
			 FROM $ta a
			 INNER JOIN $ts s ON s.id = a.subject_id
			 INNER JOIN $tt t ON t.id = a.trimester_id
			 LEFT JOIN $ttr tr ON tr.id = a.teacher_id
			 LEFT JOIN {$wpdb->users} u ON u.ID = tr.user_id
			 WHERE a.grade_id = %d AND a.status IN ('published','closed')
			 ORDER BY a.due_date ASC, a.created_at DESC",
			(int) $child->grade_id
		) );

		$submissions = array();
		$sub_files   = array();
		$task_files_map = array();

		if ( $assignments ) {
			$ids_in = implode( ',', array_map( 'intval', wp_list_pluck( $assignments, 'id' ) ) );

			$subs = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $tsb WHERE assignment_id IN ($ids_in) AND student_id = %d",
				$child_id
			) );
			foreach ( $subs as $s ) {
				$submissions[ (int) $s->assignment_id ] = $s;
			}
			if ( $subs ) {
				$sub_ids = implode( ',', array_map( 'intval', wp_list_pluck( $subs, 'id' ) ) );
				$frows   = $wpdb->get_results( "SELECT * FROM $tsf WHERE submission_id IN ($sub_ids)" );
				foreach ( $frows as $f ) {
					$sub_files[ (int) $f->submission_id ][] = $f;
				}
			}
			$tfiles = $wpdb->get_results( "SELECT * FROM $taf WHERE assignment_id IN ($ids_in)" );
			foreach ( $tfiles as $f ) {
				$task_files_map[ (int) $f->assignment_id ][] = $f;
			}
		}

		$type_labels = array(
			'tarea'      => __( 'Tarea', 'sistema-educativo' ),
			'leccion'    => __( 'Lección', 'sistema-educativo' ),
			'trabajo'    => __( 'Trabajo', 'sistema-educativo' ),
			'deber'      => __( 'Deber', 'sistema-educativo' ),
			'examen'     => __( 'Examen', 'sistema-educativo' ),
			'correccion' => __( 'Corrección', 'sistema-educativo' ),
		);

		ob_start();
		?>
		<div class="edu-tareas">

		<?php if ( count( $children ) > 1 ) : ?>
			<div style="margin-bottom:16px;">
				<strong><?php esc_html_e( 'Estudiante:', 'sistema-educativo' ); ?></strong>
				<?php foreach ( $children as $c ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'edu_hijo', (int) $c->id ) ); ?>"
						style="margin-left:8px; padding:4px 10px; border-radius:4px; background:<?php echo ( (int) $c->id === $child_id ) ? '#0073aa' : '#eee'; ?>; color:<?php echo ( (int) $c->id === $child_id ) ? '#fff' : '#333'; ?>; text-decoration:none; font-size:13px;">
						<?php echo esc_html( $c->display_name ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p><strong><?php esc_html_e( 'Estudiante:', 'sistema-educativo' ); ?></strong> <?php echo esc_html( $child->display_name ); ?></p>
		<?php endif; ?>

		<?php if ( empty( $assignments ) ) : ?>
			<p><?php esc_html_e( 'No hay tareas publicadas para este estudiante.', 'sistema-educativo' ); ?></p>
		<?php endif; ?>

		<?php foreach ( $assignments as $a ) :
			$sub          = $submissions[ (int) $a->id ] ?? null;
			$sub_status   = $sub ? $sub->status : 'pending';
			$files_of_sub = $sub ? ( $sub_files[ (int) $sub->id ] ?? array() ) : array();
			$task_files   = $task_files_map[ (int) $a->id ] ?? array();

			$badge_text  = '';
			$badge_style = '';
			if ( 'graded' === $sub_status ) {
				$badge_text  = __( 'Calificada', 'sistema-educativo' );
				$badge_style = 'background:#0073aa;color:#fff;';
			} elseif ( in_array( $sub_status, array( 'submitted', 'late' ), true ) ) {
				$badge_text  = 'late' === $sub_status ? __( 'Entregada (tarde)', 'sistema-educativo' ) : __( 'Entregada', 'sistema-educativo' );
				$badge_style = 'background:green;color:#fff;';
			} elseif ( $a->due_date && strtotime( $a->due_date ) < time() ) {
				$badge_text  = __( 'Vencida', 'sistema-educativo' );
				$badge_style = 'background:#b00;color:#fff;';
			} else {
				$badge_text  = __( 'Pendiente', 'sistema-educativo' );
				$badge_style = 'background:#f0a500;color:#fff;';
			}
		?>
			<div class="edu-tarea-card" style="border:1px solid #ddd; border-radius:6px; margin-bottom:12px; overflow:hidden;">
				<div class="edu-tarea-header" style="padding:12px 16px; background:#f9f9f9; display:flex; justify-content:space-between; align-items:center; cursor:pointer;"
					onclick="(function(el){var b=el.closest('.edu-tarea-card').querySelector('.edu-tarea-body');b.style.display=b.style.display==='none'?'block':'none';})(this)">
					<div>
						<strong><?php echo esc_html( $a->title ); ?></strong>
						<small style="display:block; color:#666; margin-top:2px;">
							<?php echo esc_html( $type_labels[ $a->type ] ?? $a->type ); ?> · <?php echo esc_html( $a->subject_name ); ?>
							<?php if ( ! empty( $a->teacher_name ) ) : ?>
								· <span style="color:#0073aa;"><?php echo esc_html( $a->teacher_name ); ?></span>
							<?php endif; ?>
						</small>
					</div>
					<div style="display:flex; gap:8px; align-items:center;">
						<?php if ( $a->due_date ) : ?>
							<small style="color:#666;"><?php esc_html_e( 'Límite:', 'sistema-educativo' ); ?> <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $a->due_date ) ) ); ?></small>
						<?php endif; ?>
						<span style="padding:2px 8px; border-radius:4px; font-size:12px; font-weight:600; <?php echo esc_attr( $badge_style ); ?>">
							<?php echo esc_html( $badge_text ); ?>
						</span>
					</div>
				</div>
				<div class="edu-tarea-body" style="padding:16px; display:none;">
					<?php if ( $a->description ) : ?>
						<div style="margin-bottom:12px;"><?php echo wp_kses_post( $a->description ); ?></div>
					<?php endif; ?>
					<?php if ( $task_files ) : ?>
						<p><strong><?php esc_html_e( 'Archivos de la tarea:', 'sistema-educativo' ); ?></strong></p>
						<ul style="margin:0 0 12px; padding-left:20px;">
						<?php foreach ( $task_files as $f ) : ?>
							<li><a href="<?php echo esc_url( Edu_Assignment_Task_Controller::get_download_url( $f->id ) ); ?>"><?php echo esc_html( $f->file_name ); ?></a></li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( 'graded' === $sub_status ) : ?>
						<div style="background:#f0f8ff; border-left:4px solid #0073aa; padding:10px 14px; margin-bottom:8px;">
							<strong><?php esc_html_e( 'Nota:', 'sistema-educativo' ); ?></strong>
							<?php echo null !== $sub->score ? esc_html( number_format( (float) $sub->score, 2 ) . ' / ' . number_format( (float) $a->max_score, 2 ) ) : '—'; ?>
							<?php if ( $sub->feedback ) : ?>
								<br><strong><?php esc_html_e( 'Retroalimentación:', 'sistema-educativo' ); ?></strong> <?php echo wp_kses_post( $sub->feedback ); ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<?php if ( $sub && $files_of_sub ) : ?>
						<p><strong><?php esc_html_e( 'Entrega del estudiante:', 'sistema-educativo' ); ?></strong></p>
						<ul style="margin:0; padding-left:20px;">
						<?php foreach ( $files_of_sub as $f ) : ?>
							<li><a href="<?php echo esc_url( Edu_Submission_Controller::get_sub_download_url( $f->id ) ); ?>"><?php echo esc_html( $f->file_name ); ?></a></li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
