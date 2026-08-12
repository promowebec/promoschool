<?php
/**
 * Vista: tareas y actividades (wp_edu_assignments).
 *
 * action=list  → tabla de tareas filtrable por grado/materia/trimestre.
 * action=new   → formulario de creación.
 * action=edit  → formulario de edición (id requerido).
 * action=detail → lista de entregas de los estudiantes + formulario de calificación.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( Edu_Context::can( 'edu_create_assignment' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

$institution_id = Edu_Context::current_institution_id();
if ( ! $institution_id ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Tareas', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$code   = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';

global $wpdb;
$ta  = $wpdb->prefix . 'edu_assignments';
$taf = $wpdb->prefix . 'edu_assignment_files';
$tg  = $wpdb->prefix . 'edu_grades';
$ts  = $wpdb->prefix . 'edu_subjects';
$tt  = $wpdb->prefix . 'edu_trimesters';
$tp  = $wpdb->prefix . 'edu_periods';
$tgs = $wpdb->prefix . 'edu_grade_subjects';
$ttr = $wpdb->prefix . 'edu_teachers';
$tta = $wpdb->prefix . 'edu_teacher_assignments';
$tsb = $wpdb->prefix . 'edu_submissions';

// Detectar si el usuario actual es docente (sin edu_view_all) y obtener su teacher_id.
$is_docente_user    = ! Edu_Context::can( 'edu_view_all' );
$current_teacher_id = 0;
if ( $is_docente_user ) {
	$current_teacher_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $ttr WHERE user_id = %d",
		get_current_user_id()
	) );
}

// -------------------------------------------------------------------------
// DETALLE: entregas de estudiantes + calificación
// -------------------------------------------------------------------------
if ( 'detail' === $action && $id ) {
	$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT a.*, g.name AS grade_name, g.paralelo, s.name AS subject_name, tr.number AS trim_num
		FROM $ta a
		INNER JOIN $tg g ON g.id = a.grade_id
		INNER JOIN $ts s ON s.id = a.subject_id
		INNER JOIN $tt tr ON tr.id = a.trimester_id
		WHERE a.id = %d", $id ) );

	if ( ! $assignment ) {
		echo '<div class="wrap"><p>' . esc_html__( 'Tarea no encontrada.', 'sistema-educativo' ) . '</p></div>';
		return;
	}

	require EDU_PLUGIN_DIR . 'admin/views/tareas-detalle.php';
	return;
}

// -------------------------------------------------------------------------
// FORMULARIO: nuevo / editar
// -------------------------------------------------------------------------
if ( in_array( $action, array( 'new', 'edit' ), true ) ) {

	$assignment = null;
	$files      = array();

	if ( 'edit' === $action && $id ) {
		$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id = %d", $id ) );
		if ( ! $assignment ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Tarea no encontrada.', 'sistema-educativo' ) . '</p></div>';
			return;
		}
		$files = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $taf WHERE assignment_id = %d", $id ) );
	}

	// Datos de selects — docente solo ve sus grados/materias asignados.
	if ( $is_docente_user && $current_teacher_id ) {
		$grades = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT g.id, g.name, g.paralelo
			 FROM $tta ta INNER JOIN $tg g ON g.id = ta.grade_id
			 WHERE ta.teacher_id = %d AND ta.is_active = 1 AND g.institution_id = %d
			 ORDER BY g.sub_level, g.name, g.paralelo",
			$current_teacher_id, $institution_id
		) );
	} else {
		$grades = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
			$institution_id
		) );
	}

	$grade_id_sel = $assignment ? (int) $assignment->grade_id : ( isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0 );

	$subjects = array();
	if ( $grade_id_sel ) {
		if ( $is_docente_user && $current_teacher_id ) {
			$subjects = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT s.id, s.name
				 FROM $tta ta INNER JOIN $ts s ON s.id = ta.subject_id
				 WHERE ta.teacher_id = %d AND ta.grade_id = %d AND ta.is_active = 1
				 ORDER BY s.name",
				$current_teacher_id, $grade_id_sel
			) );
		} else {
			$subjects = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id, s.name FROM $tgs gs INNER JOIN $ts s ON s.id = gs.subject_id
				 WHERE gs.grade_id = %d ORDER BY s.name",
				$grade_id_sel
			) );
		}
	}

	$subject_id_sel   = $assignment ? (int) $assignment->subject_id   : ( isset( $_GET['subject_id'] )   ? (int) $_GET['subject_id']   : 0 );
	$trimester_id_sel = $assignment ? (int) $assignment->trimester_id : ( isset( $_GET['trimester_id'] ) ? (int) $_GET['trimester_id'] : 0 );
	$parcial_num_sel  = $assignment ? (int) $assignment->parcial_num  : ( isset( $_GET['parcial_num'] )  ? (int) $_GET['parcial_num']  : 1 );
	$component_id_sel = $assignment ? (int) $assignment->component_id : 0;

	// Componentes del parcial seleccionado.
	$tgc        = $wpdb->prefix . 'edu_grade_components';
	$components = array();
	if ( $subject_id_sel && $trimester_id_sel && $parcial_num_sel ) {
		$components = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, weight FROM $tgc
			 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d
			 ORDER BY name",
			$subject_id_sel, $trimester_id_sel, $parcial_num_sel
		) );
	}

	$trimesters = array();
	if ( $grade_id_sel ) {
		$trimesters = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id, t.number AS trim_num, p.name AS period_name
			 FROM $tt t INNER JOIN $tp p ON p.id = t.period_id
			 WHERE p.institution_id = %d
			 ORDER BY p.start_date DESC, t.number",
			$institution_id
		) );
	}

	// El tipo de actividad ya no se pide en el formulario: lo deduce
	// Edu_Assignment_Task_Controller::derive_type() del nombre del componente.
	$is_closed = $assignment && 'closed' === $assignment->status;
	?>
	<div class="wrap edu-wrap">
		<h1>
			<?php echo $assignment ? esc_html__( 'Editar tarea', 'sistema-educativo' ) : esc_html__( 'Nueva tarea', 'sistema-educativo' ); ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-tareas' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( '← Volver', 'sistema-educativo' ); ?>
			</a>
		</h1>
		<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

		<?php if ( 'updated' === $status ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Tarea guardada.', 'sistema-educativo' ); ?></p></div>
		<?php elseif ( 'published' === $status ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Tarea publicada.', 'sistema-educativo' ); ?></p></div>
		<?php elseif ( 'closed' === $status ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Tarea cerrada.', 'sistema-educativo' ); ?></p></div>
		<?php elseif ( 'recovery_saved' === $status ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración de mejora guardada.', 'sistema-educativo' ); ?></p></div>
		<?php elseif ( 'error' === $status ) : ?>
			<div class="notice notice-error"><p>
			<?php
			$msgs = array(
				'title_required' => __( 'El título es obligatorio.', 'sistema-educativo' ),
				'invalid_scope'  => __( 'Datos fuera de la institución actual.', 'sistema-educativo' ),
				'already_closed' => __( 'La tarea está cerrada y no se puede editar.', 'sistema-educativo' ),
				'not_found'      => __( 'Tarea no encontrada.', 'sistema-educativo' ),
			);
			echo esc_html( $msgs[ $code ] ?? __( 'Error al guardar.', 'sistema-educativo' ) );
			?>
			</p></div>
		<?php endif; ?>

		<?php if ( $is_closed ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Esta tarea está cerrada. Solo se puede ver.', 'sistema-educativo' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'edu_save_assignment_task' ); ?>
			<input type="hidden" name="action" value="edu_save_assignment_task">
			<?php if ( $assignment ) : ?>
				<input type="hidden" name="id" value="<?php echo (int) $assignment->id; ?>">
			<?php endif; ?>

			<table class="form-table" style="max-width:720px;">
				<tr>
					<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
					<td>
						<select name="grade_id" id="edu-grade-sel" required <?php disabled( $is_closed ); ?>>
							<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $grades as $g ) : ?>
								<option value="<?php echo (int) $g->id; ?>" <?php selected( $grade_id_sel, (int) $g->id ); ?>>
									<?php echo esc_html( $g->name . ' · ' . $g->paralelo ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></th>
					<td>
						<select name="subject_id" id="edu-subject-sel" required <?php disabled( $is_closed ); ?>>
							<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $subjects as $s ) : ?>
								<option value="<?php echo (int) $s->id; ?>" <?php selected( $subject_id_sel, (int) $s->id ); ?>>
									<?php echo esc_html( $s->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Trimestre', 'sistema-educativo' ); ?></th>
					<td>
						<select name="trimester_id" id="edu-trimester-sel" required <?php disabled( $is_closed ); ?>>
							<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $trimesters as $t ) : ?>
								<option value="<?php echo (int) $t->id; ?>" <?php selected( $trimester_id_sel, (int) $t->id ); ?>>
									<?php echo esc_html( 'T' . $t->trim_num . ' — ' . $t->period_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Parcial', 'sistema-educativo' ); ?></th>
					<td>
						<select name="parcial_num" id="edu-parcial-sel" <?php disabled( $is_closed ); ?>>
							<option value="1" <?php selected( $parcial_num_sel, 1 ); ?>>
								<?php esc_html_e( 'Parcial 1', 'sistema-educativo' ); ?>
							</option>
							<option value="2" <?php selected( $parcial_num_sel, 2 ); ?>>
								<?php esc_html_e( 'Parcial 2', 'sistema-educativo' ); ?>
							</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Se evalúa como', 'sistema-educativo' ); ?></th>
					<td>
						<select name="component_id" id="edu-component-sel" <?php disabled( $is_closed ); ?>>
							<?php foreach ( $components as $c ) : ?>
								<option value="<?php echo (int) $c->id; ?>" <?php selected( $component_id_sel, (int) $c->id ); ?>>
									<?php echo esc_html( $c->name ); ?>
								</option>
							<?php endforeach; ?>
							<option value="-1" <?php selected( $component_id_sel, 0 ); ?>>
								<?php esc_html_e( '➕ Crear componente nuevo…', 'sistema-educativo' ); ?>
							</option>
							<option value="0">
								<?php esc_html_e( 'Sin vincular (no cuenta para la nota)', 'sistema-educativo' ); ?>
							</option>
						</select>

						<div id="edu-component-nuevo" style="margin-top:8px;<?php echo $component_id_sel ? ' display:none;' : ''; ?>">
							<input type="text" name="component_new_name" id="edu-component-new-name"
								class="regular-text" maxlength="100"
								placeholder="<?php esc_attr_e( 'Nombre del componente. Ej. Tarea capítulo 3', 'sistema-educativo' ); ?>"
								<?php disabled( $is_closed ); ?>>
							<input type="hidden" name="component_new_weight" value="1.00">
							<p class="description">
								<?php esc_html_e( 'Se crea al guardar la tarea, con el mismo peso que los demás componentes del parcial. Si escribes un nombre que ya existe, se reutiliza ese componente y las notas se promedian.', 'sistema-educativo' ); ?>
							</p>
						</div>

						<?php if ( ! $subject_id_sel || ! $trimester_id_sel ) : ?>
							<p class="description"><?php esc_html_e( 'Selecciona materia, trimestre y parcial primero.', 'sistema-educativo' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Al calificar las entregas, la nota se registra en este componente y recalcula el parcial automáticamente.', 'sistema-educativo' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Título', 'sistema-educativo' ); ?></th>
					<td>
						<input type="text" name="title" value="<?php echo esc_attr( $assignment ? $assignment->title : '' ); ?>"
							class="regular-text" required <?php disabled( $is_closed ); ?>>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Descripción', 'sistema-educativo' ); ?></th>
					<td>
						<textarea name="description" rows="5" class="large-text" <?php disabled( $is_closed ); ?>><?php echo esc_textarea( $assignment ? $assignment->description : '' ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Fecha límite', 'sistema-educativo' ); ?></th>
					<td>
						<input type="datetime-local" name="due_date"
							value="<?php echo esc_attr( $assignment && $assignment->due_date ? date( 'Y-m-d\TH:i', strtotime( $assignment->due_date ) ) : '' ); ?>"
							<?php disabled( $is_closed ); ?>>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Nota máxima', 'sistema-educativo' ); ?></th>
					<td>
						<input type="number" name="max_score" step="0.01" min="0" max="100"
							value="<?php echo esc_attr( $assignment ? number_format( (float) $assignment->max_score, 2, '.', '' ) : '10.00' ); ?>"
							style="width:80px;" <?php disabled( $is_closed ); ?>>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Notificar padres', 'sistema-educativo' ); ?></th>
					<td>
						<input type="checkbox" name="notify_parents" value="1"
							<?php checked( $assignment ? (bool) $assignment->notify_parents : true ); ?>
							<?php disabled( $is_closed ); ?>>
					</td>
				</tr>

				<?php if ( ! $is_closed ) : ?>
				<tr>
					<th><?php esc_html_e( 'Adjuntos', 'sistema-educativo' ); ?></th>
					<td>
						<input type="file" name="adjuntos[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip">
						<p class="description"><?php esc_html_e( 'PDF, Word, PowerPoint, Excel, imágenes, ZIP. Máx. 10 MB por archivo.', 'sistema-educativo' ); ?></p>

						<?php if ( $files ) : ?>
							<table style="margin-top:8px; width:100%; max-width:500px;">
								<thead><tr>
									<th style="text-align:left;"><?php esc_html_e( 'Archivo actual', 'sistema-educativo' ); ?></th>
									<th><?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?></th>
								</tr></thead>
								<tbody>
								<?php foreach ( $files as $f ) : ?>
									<tr>
										<td>
											<a href="<?php echo esc_url( Edu_Assignment_Task_Controller::get_download_url( $f->id ) ); ?>" target="_blank">
												<?php echo esc_html( $f->file_name ); ?>
											</a>
											<small>(<?php echo esc_html( size_format( (int) $f->file_size ) ); ?>)</small>
										</td>
										<td style="text-align:center;">
											<input type="checkbox" name="delete_files[]" value="<?php echo (int) $f->id; ?>">
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<?php if ( ! $is_closed ) : ?>
			<p class="submit">
				<button type="submit" name="publish_now" value="0" class="button button-secondary">
					<?php esc_html_e( 'Guardar borrador', 'sistema-educativo' ); ?>
				</button>
				<button type="submit" name="publish_now" value="1" class="button button-primary" style="margin-left:8px;">
					<?php esc_html_e( 'Guardar y publicar', 'sistema-educativo' ); ?>
				</button>
			</p>
			<?php endif; ?>

			<?php if ( $assignment && 'draft' === $assignment->status ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'edu_publish_assignment_task' ); ?>
				<input type="hidden" name="action" value="edu_publish_assignment_task">
				<input type="hidden" name="id" value="<?php echo (int) $assignment->id; ?>">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Publicar', 'sistema-educativo' ); ?></button>
			</form>
			<?php endif; ?>
		</form>

		<?php if ( $assignment && 'published' === $assignment->status ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<?php wp_nonce_field( 'edu_close_assignment_task' ); ?>
			<input type="hidden" name="action" value="edu_close_assignment_task">
			<input type="hidden" name="id" value="<?php echo (int) $assignment->id; ?>">
			<button type="submit" class="button button-secondary"
				onclick="return confirm('<?php esc_attr_e( '¿Cerrar esta tarea? Los estudiantes no podrán entregar.', 'sistema-educativo' ); ?>')">
				<?php esc_html_e( 'Cerrar tarea', 'sistema-educativo' ); ?>
			</button>
		</form>
		<?php endif; ?>

		<?php if ( $assignment && 'closed' === $assignment->status ) : ?>
		<div style="margin-top:24px; padding:16px 20px; background:#f8f9fa; border:1px solid #c3c4c7; border-radius:4px;">
			<h3 style="margin-top:0; font-size:14px;"><?php esc_html_e( 'Recuperación / Mejora de nota', 'sistema-educativo' ); ?></h3>
			<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Permite que los estudiantes re-entreguen la actividad. Se conservará la mejor nota entre la original y la mejora.', 'sistema-educativo' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'edu_save_recovery_settings' ); ?>
				<input type="hidden" name="action" value="edu_save_recovery_settings">
				<input type="hidden" name="id" value="<?php echo (int) $assignment->id; ?>">
				<table class="form-table" style="max-width:560px;">
					<tr>
						<th style="width:180px;"><?php esc_html_e( 'Permitir mejora', 'sistema-educativo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="allow_recovery" value="1" id="edu-allow-recovery"
									<?php checked( (bool) $assignment->allow_recovery ); ?>>
								<?php esc_html_e( 'Activar entrega de mejora para todos los estudiantes calificados', 'sistema-educativo' ); ?>
							</label>
						</td>
					</tr>
					<tr id="edu-recovery-date-row" <?php echo $assignment->allow_recovery ? '' : 'style="display:none;"'; ?>>
						<th><?php esc_html_e( 'Fecha límite mejora', 'sistema-educativo' ); ?></th>
						<td>
							<input type="datetime-local" name="recovery_due_date"
								value="<?php echo $assignment->recovery_due_date ? esc_attr( date( 'Y-m-d\TH:i', strtotime( $assignment->recovery_due_date ) ) ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Dejar vacío para sin límite de fecha.', 'sistema-educativo' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit" style="margin-top:0;">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Guardar configuración de mejora', 'sistema-educativo' ); ?>
					</button>
				</p>
			</form>
		</div>
		<script>
		document.getElementById('edu-allow-recovery').addEventListener('change', function() {
			document.getElementById('edu-recovery-date-row').style.display = this.checked ? '' : 'none';
		});
		</script>
		<?php endif; ?>

		<?php if ( $assignment ) : ?>
		<p style="margin-top:16px;">
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-tareas', 'action' => 'detail', 'id' => $assignment->id ), admin_url( 'admin.php' ) ) ); ?>" class="button">
				<?php esc_html_e( 'Ver entregas', 'sistema-educativo' ); ?>
			</a>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-left:8px;">
				<?php wp_nonce_field( 'edu_delete_assignment_task' ); ?>
				<input type="hidden" name="action" value="edu_delete_assignment_task">
				<input type="hidden" name="id" value="<?php echo (int) $assignment->id; ?>">
				<button type="submit" class="button" style="color:#b32d2e;"
					onclick="return confirm('<?php esc_attr_e( '¿Eliminar esta tarea y todos sus archivos?', 'sistema-educativo' ); ?>')">
					<?php esc_html_e( 'Eliminar tarea', 'sistema-educativo' ); ?>
				</button>
			</form>
		</p>
		<?php endif; ?>
	</div>
	<script>
	(function(){
		function reloadWith( params ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'action', url.searchParams.get('action') || 'new' );
			for ( var k in params ) {
				if ( params[ k ] !== null ) {
					url.searchParams.set( k, params[ k ] );
				} else {
					url.searchParams.delete( k );
				}
			}
			window.location.href = url.toString();
		}

		var gradeSel     = document.getElementById('edu-grade-sel');
		var subjectSel   = document.getElementById('edu-subject-sel');
		var trimesterSel = document.getElementById('edu-trimester-sel');
		var parcialSel   = document.getElementById('edu-parcial-sel');

		if ( gradeSel ) {
			gradeSel.addEventListener('change', function(){
				reloadWith({ grade_id: this.value, subject_id: null, trimester_id: null });
			});
		}
		if ( subjectSel ) {
			subjectSel.addEventListener('change', function(){
				reloadWith({ subject_id: this.value });
			});
		}
		if ( trimesterSel ) {
			trimesterSel.addEventListener('change', function(){
				reloadWith({ trimester_id: this.value });
			});
		}
		if ( parcialSel ) {
			parcialSel.addEventListener('change', function(){
				reloadWith({ parcial_num: this.value });
			});
		}

		// "Crear componente nuevo…" (valor -1) revela el campo de nombre.
		var compSel  = document.getElementById('edu-component-sel');
		var compWrap = document.getElementById('edu-component-nuevo');
		var compName = document.getElementById('edu-component-new-name');
		if ( compSel && compWrap ) {
			compSel.addEventListener('change', function(){
				var crear = this.value === '-1';
				compWrap.style.display = crear ? '' : 'none';
				if ( ! crear && compName ) { compName.value = ''; }
				if ( crear && compName ) { compName.focus(); }
			});
		}
	})();
	</script>
	<?php
	return;
}

// -------------------------------------------------------------------------
// LISTA
// -------------------------------------------------------------------------
$filter_grade   = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;
$filter_subject = isset( $_GET['subject_id'] ) ? (int) $_GET['subject_id'] : 0;
$filter_status  = isset( $_GET['fstatus'] ) ? sanitize_key( $_GET['fstatus'] ) : '';

if ( $is_docente_user && $current_teacher_id ) {
	$grades = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT g.id, g.name, g.paralelo
		 FROM $tta ta INNER JOIN $tg g ON g.id = ta.grade_id
		 WHERE ta.teacher_id = %d AND ta.is_active = 1 AND g.institution_id = %d
		 ORDER BY g.sub_level, g.name, g.paralelo",
		$current_teacher_id, $institution_id
	) );
} else {
	$grades = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
		$institution_id
	) );
}

$subjects_filter = array();
if ( $filter_grade ) {
	if ( $is_docente_user && $current_teacher_id ) {
		$subjects_filter = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT s.id, s.name
			 FROM $tta ta INNER JOIN $ts s ON s.id = ta.subject_id
			 WHERE ta.teacher_id = %d AND ta.grade_id = %d AND ta.is_active = 1
			 ORDER BY s.name",
			$current_teacher_id, $filter_grade
		) );
	} else {
		$subjects_filter = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.name FROM $tgs gs INNER JOIN $ts s ON s.id = gs.subject_id WHERE gs.grade_id = %d ORDER BY s.name",
			$filter_grade
		) );
	}
}

// Construir query.
$where   = array( 'g.institution_id = %d' );
$params  = array( $institution_id );

// Solo docentes ven sus propias tareas (rectores ven todo).
if ( $is_docente_user && $current_teacher_id ) {
	$where[]  = 'a.teacher_id = %d';
	$params[] = $current_teacher_id;
}

if ( $filter_grade ) {
	$where[]  = 'a.grade_id = %d';
	$params[] = $filter_grade;
}
if ( $filter_subject ) {
	$where[]  = 'a.subject_id = %d';
	$params[] = $filter_subject;
}
if ( $filter_status ) {
	$where[]  = 'a.status = %s';
	$params[] = $filter_status;
}

$where_sql = implode( ' AND ', $where );

$assignments = $wpdb->get_results( $wpdb->prepare(
	"SELECT a.*, g.name AS grade_name, g.paralelo, s.name AS subject_name, t.number AS trim_num,
	        ( SELECT COUNT(*) FROM $tsb sb WHERE sb.assignment_id = a.id ) AS submission_count
	 FROM $ta a
	 INNER JOIN $tg g ON g.id = a.grade_id
	 INNER JOIN $ts s ON s.id = a.subject_id
	 INNER JOIN $tt t ON t.id = a.trimester_id
	 WHERE $where_sql
	 ORDER BY a.created_at DESC",
	...$params
) );

$status_labels = array(
	'draft'     => array( 'label' => __( 'Borrador', 'sistema-educativo' ),  'color' => '#666' ),
	'published' => array( 'label' => __( 'Publicada', 'sistema-educativo' ), 'color' => 'green' ),
	'closed'    => array( 'label' => __( 'Cerrada', 'sistema-educativo' ),   'color' => '#b00' ),
);
$type_labels = array(
	'tarea'      => __( 'Tarea', 'sistema-educativo' ),
	'leccion'    => __( 'Lección', 'sistema-educativo' ),
	'trabajo'    => __( 'Trabajo', 'sistema-educativo' ),
	'deber'      => __( 'Deber', 'sistema-educativo' ),
	'examen'     => __( 'Examen', 'sistema-educativo' ),
	'correccion' => __( 'Corrección', 'sistema-educativo' ),
);
?>
<div class="wrap edu-wrap">
	<h1>
		<?php esc_html_e( 'Tareas y actividades', 'sistema-educativo' ); ?>
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-tareas', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
			<?php esc_html_e( '+ Nueva tarea', 'sistema-educativo' ); ?>
		</a>
	</h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( 'deleted' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Tarea eliminada.', 'sistema-educativo' ); ?></p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
		<input type="hidden" name="page" value="edu-tareas">

		<label><strong><?php esc_html_e( 'Grado:', 'sistema-educativo' ); ?></strong><br>
			<select name="grade_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( '— Todos —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $grades as $g ) : ?>
					<option value="<?php echo (int) $g->id; ?>" <?php selected( $filter_grade, (int) $g->id ); ?>>
						<?php echo esc_html( $g->name . ' · ' . $g->paralelo ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label><strong><?php esc_html_e( 'Materia:', 'sistema-educativo' ); ?></strong><br>
			<select name="subject_id" onchange="this.form.submit()" <?php echo $filter_grade ? '' : 'disabled'; ?>>
				<option value="0"><?php esc_html_e( '— Todas —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $subjects_filter as $s ) : ?>
					<option value="<?php echo (int) $s->id; ?>" <?php selected( $filter_subject, (int) $s->id ); ?>>
						<?php echo esc_html( $s->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label><strong><?php esc_html_e( 'Estado:', 'sistema-educativo' ); ?></strong><br>
			<select name="fstatus" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( '— Todos —', 'sistema-educativo' ); ?></option>
				<option value="draft"     <?php selected( $filter_status, 'draft' ); ?>><?php esc_html_e( 'Borrador', 'sistema-educativo' ); ?></option>
				<option value="published" <?php selected( $filter_status, 'published' ); ?>><?php esc_html_e( 'Publicada', 'sistema-educativo' ); ?></option>
				<option value="closed"    <?php selected( $filter_status, 'closed' ); ?>><?php esc_html_e( 'Cerrada', 'sistema-educativo' ); ?></option>
			</select>
		</label>
	</form>

	<?php if ( empty( $assignments ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'No hay tareas con los filtros seleccionados.', 'sistema-educativo' ); ?></p></div>
	<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Título', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Tipo', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Trim.', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Fecha límite', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Entregas', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $assignments as $a ) :
			$sl = $status_labels[ $a->status ] ?? array( 'label' => $a->status, 'color' => '#666' );
		?>
			<tr>
				<td><strong><?php echo esc_html( $a->title ); ?></strong></td>
				<td><?php echo esc_html( $type_labels[ $a->type ] ?? $a->type ); ?></td>
				<td><?php echo esc_html( $a->grade_name . ' ' . $a->paralelo ); ?></td>
				<td><?php echo esc_html( $a->subject_name ); ?></td>
				<td><?php echo esc_html( 'T' . $a->trim_num ); ?></td>
				<td><?php echo $a->due_date ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $a->due_date ) ) ) : '—'; ?></td>
				<td><span style="color:<?php echo esc_attr( $sl['color'] ); ?>; font-weight:600;"><?php echo esc_html( $sl['label'] ); ?></span></td>
				<td>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-tareas', 'action' => 'detail', 'id' => $a->id ), admin_url( 'admin.php' ) ) ); ?>">
						<?php echo (int) $a->submission_count; ?>
					</a>
				</td>
				<td style="white-space:nowrap;">
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-tareas', 'action' => 'edit', 'id' => $a->id ), admin_url( 'admin.php' ) ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Editar', 'sistema-educativo' ); ?>
					</a>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-tareas', 'action' => 'detail', 'id' => $a->id ), admin_url( 'admin.php' ) ) ); ?>" class="button button-small" style="margin-left:4px;">
						<?php esc_html_e( 'Entregas', 'sistema-educativo' ); ?>
					</a>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
<?php
