<?php
/**
 * Vista: ABM de estudiantes.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_view_all' ) ) {
	wp_die( esc_html__( 'No tienes permiso.', 'sistema-educativo' ) );
}

echo '<div class="wrap edu-wrap">';

require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php';

$current_institution_id = Edu_Context::current_institution_id();
if ( ! $current_institution_id ) {
	echo '</div>';
	return;
}

$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
$edit_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

global $wpdb;
$ts = $wpdb->prefix . 'edu_students';
$tg = $wpdb->prefix . 'edu_grades';
$u  = $wpdb->users;

// Grados de la institución (para selector y filtro).
$grades = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, paralelo, sub_level FROM $tg WHERE institution_id = %d ORDER BY level, sub_level, name, paralelo",
	$current_institution_id
) );

$student      = null;
$student_user = null;
if ( 'edit' === $action && $edit_id ) {
	$student = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ts WHERE id = %d", $edit_id ) );
	if ( ! $student ) {
		wp_die( esc_html__( 'Estudiante no encontrado.', 'sistema-educativo' ) );
	}
	$grade_inst = (int) $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM $tg WHERE id = %d", $student->grade_id ) );
	if ( ! Edu_Context::is_superadmin_editorial() && $grade_inst !== $current_institution_id ) {
		wp_die( esc_html__( 'Sin permiso para ver este estudiante.', 'sistema-educativo' ) );
	}
	$student_user = get_user_by( 'id', $student->user_id );
}

$status        = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$status_code   = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
$grade_filter  = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;
$status_filter = isset( $_GET['student_status'] ) ? sanitize_key( $_GET['student_status'] ) : '';

$status_labels = array(
	'active'      => __( 'Activo', 'sistema-educativo' ),
	'inactive'    => __( 'Inactivo', 'sistema-educativo' ),
	'transferred' => __( 'Transferido', 'sistema-educativo' ),
	'graduated'   => __( 'Graduado', 'sistema-educativo' ),
);
?>
	<h1>
		<?php if ( 'list' === $action ) : ?>
			<?php esc_html_e( 'Estudiantes', 'sistema-educativo' ); ?>
			<?php if ( ! empty( $grades ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-estudiantes&action=new' ) ); ?>"><?php esc_html_e( 'Nuevo estudiante', 'sistema-educativo' ); ?></a>
			<?php endif; ?>
		<?php else : ?>
			<?php echo $student ? esc_html__( 'Editar estudiante', 'sistema-educativo' ) : esc_html__( 'Nuevo estudiante', 'sistema-educativo' ); ?>
		<?php endif; ?>
	</h1>

	<?php if ( empty( $grades ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php
			printf(
				wp_kses_post( __( 'No hay grados creados en esta institución. <a href="%s">Crea al menos uno</a> antes de matricular estudiantes.', 'sistema-educativo' ) ),
				esc_url( admin_url( 'admin.php?page=edu-grados&action=new' ) )
			);
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Estudiante guardado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'deleted' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Estudiante eliminado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$messages = array(
				'email_invalid'      => __( 'El email no es válido.', 'sistema-educativo' ),
				'name_required'      => __( 'Nombre y apellido son obligatorios.', 'sistema-educativo' ),
				'grade_invalid'      => __( 'El grado seleccionado no es válido para esta institución.', 'sistema-educativo' ),
				'cedula_duplicate'   => __( 'Ya existe un estudiante con esa cédula.', 'sistema-educativo' ),
				'already_student'    => __( 'Ese usuario ya está registrado como estudiante.', 'sistema-educativo' ),
				'user_create_failed' => __( 'No se pudo crear el usuario de WordPress (¿email ya en uso?).', 'sistema-educativo' ),
				'user_update_failed' => __( 'No se pudo actualizar el usuario de WordPress.', 'sistema-educativo' ),
				'not_found'          => __( 'Estudiante no encontrado.', 'sistema-educativo' ),
			);
			echo esc_html( $messages[ $status_code ] ?? __( 'Error.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'list' === $action ) : ?>
		<?php
		$res_import = Edu_Import_Helper::obtener_resultado( 'students' );
		if ( $res_import ) {
			Edu_Import_Helper::render_resultado( $res_import, __( 'estudiante(s)', 'sistema-educativo' ) );
		}
		?>
		<form method="get" class="edu-filter-bar">
			<input type="hidden" name="page" value="edu-estudiantes">
			<label><?php esc_html_e( 'Grado:', 'sistema-educativo' ); ?></label>
			<select name="grade_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
				<?php foreach ( $grades as $g ) : ?>
					<option value="<?php echo esc_attr( $g->id ); ?>" <?php selected( $grade_filter, (int) $g->id ); ?>>
						<?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<label><?php esc_html_e( 'Estado:', 'sistema-educativo' ); ?></label>
			<select name="student_status" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
				<?php foreach ( $status_labels as $sk => $sl ) : ?>
					<option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $status_filter, $sk ); ?>><?php echo esc_html( $sl ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>

		<?php
		$sql  = "SELECT s.*, u.user_email, u.display_name, g.name AS grade_name, g.paralelo
		         FROM $ts s
		         INNER JOIN $u u ON u.ID = s.user_id
		         INNER JOIN $tg g ON g.id = s.grade_id
		         WHERE g.institution_id = %d";
		$args = array( $current_institution_id );
		if ( $grade_filter ) {
			$sql   .= ' AND s.grade_id = %d';
			$args[] = $grade_filter;
		}
		if ( isset( $status_labels[ $status_filter ] ) ) {
			$sql   .= ' AND s.status = %s';
			$args[] = $status_filter;
		}
		$sql .= ' ORDER BY g.name, g.paralelo, u.display_name';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Cédula', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Grado / Paralelo', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Email', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Matrícula', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Sin estudiantes con esos filtros.', 'sistema-educativo' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->display_name ); ?></strong></td>
						<td><?php echo esc_html( $r->cedula ?: '—' ); ?></td>
						<td><?php echo esc_html( $r->grade_name . ' ' . $r->paralelo ); ?></td>
						<td><?php echo esc_html( $r->user_email ); ?></td>
						<td><?php echo esc_html( $r->enrollment_date ?: '—' ); ?></td>
						<td><?php echo esc_html( $status_labels[ $r->status ] ?? $r->status ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=edu-estudiantes&action=edit&id=' . $r->id ) ); ?>"><?php esc_html_e( 'Editar', 'sistema-educativo' ); ?></a>
							|
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form" onsubmit="return confirm('<?php echo esc_attr__( '¿Eliminar este estudiante? Se quitará el rol edu_estudiante del usuario WordPress (pero el usuario se conserva).', 'sistema-educativo' ); ?>');">
								<?php wp_nonce_field( 'edu_delete_student' ); ?>
								<input type="hidden" name="action" value="edu_delete_student">
								<input type="hidden" name="id" value="<?php echo esc_attr( $r->id ); ?>">
								<button type="submit" class="edu-link-button edu-link-danger"><?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
		Edu_Import_Helper::render_panel_importacion(
			__( 'Importación masiva de estudiantes', 'sistema-educativo' ),
			'edu_download_students_template',
			'edu_download_students_template',
			'edu_import_students_csv',
			'edu_import_students_csv',
			'students_csv',
			array(
				array( 'cedula',           true,  __( 'Cédula del estudiante. Se usa como login y contraseña inicial.', 'sistema-educativo' ) ),
				array( 'nombres',          true,  __( 'Nombres del estudiante.', 'sistema-educativo' ) ),
				array( 'apellidos',        true,  __( 'Apellidos del estudiante.', 'sistema-educativo' ) ),
				array( 'email',            false, __( 'Correo electrónico. Opcional; si está vacío se genera uno automático.', 'sistema-educativo' ) ),
				array( 'fecha_nacimiento', false, __( 'Fecha de nacimiento. Formato <code>YYYY-MM-DD</code>. Ej: <code>2010-05-15</code>', 'sistema-educativo' ) ),
				array( 'grado',            true,  __( 'Nombre exacto del grado. Ej: <code>1ro BG</code>', 'sistema-educativo' ) ),
				array( 'paralelo',         true,  __( '<code>A</code>, <code>B</code>, <code>C</code> o <code>D</code>', 'sistema-educativo' ) ),
				array( 'cedula_padre',     false, __( 'Cédula del representante ya registrado. Si existe, se crea el vínculo automáticamente.', 'sistema-educativo' ) ),
			),
			array(
				__( 'La fecha de matrícula se registra automáticamente con la fecha de importación.', 'sistema-educativo' ),
				__( 'Si el representante aún no existe, impórtalo primero desde Representantes.', 'sistema-educativo' ),
			)
		);
		?>
	<?php elseif ( ! empty( $grades ) ) : ?>
		<?php
		$email           = $student_user ? $student_user->user_email : '';
		$first_name      = $student_user ? $student_user->first_name : '';
		$last_name       = $student_user ? $student_user->last_name : '';
		$cedula          = $student->cedula ?? '';
		$grade_id        = $student->grade_id ?? 0;
		$birth_date      = $student->birth_date ?? '';
		$sexo            = $student->sexo ?? '';
		$direccion       = $student->direccion ?? '';
		$enrollment_date = $student->enrollment_date ?? '';
		$photo_url       = $student->photo_url ?? '';
		$st_status       = $student->status ?? 'active';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-form">
			<?php wp_nonce_field( 'edu_save_student' ); ?>
			<input type="hidden" name="action" value="edu_save_student">
			<?php if ( $student ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( $student->id ); ?>"><?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="edu-email"><?php esc_html_e( 'Email', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td>
						<input id="edu-email" name="email" type="email" class="regular-text" required value="<?php echo esc_attr( $email ); ?>">
						<?php if ( ! $student ) : ?>
							<p class="description"><?php esc_html_e( 'Si el email ya tiene cuenta en WordPress, se le añadirá el rol edu_estudiante. Si no, se crea cuenta nueva.', 'sistema-educativo' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="edu-first-name"><?php esc_html_e( 'Nombres', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td><input id="edu-first-name" name="first_name" type="text" class="regular-text" required maxlength="50" value="<?php echo esc_attr( $first_name ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-last-name"><?php esc_html_e( 'Apellidos', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td><input id="edu-last-name" name="last_name" type="text" class="regular-text" required maxlength="50" value="<?php echo esc_attr( $last_name ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-cedula"><?php esc_html_e( 'Cédula', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-cedula" name="cedula" type="text" maxlength="13" value="<?php echo esc_attr( $cedula ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-grade"><?php esc_html_e( 'Grado / Paralelo', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td>
						<select id="edu-grade" name="grade_id" required>
							<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $grades as $g ) : ?>
								<option value="<?php echo esc_attr( $g->id ); ?>" <?php selected( $grade_id, (int) $g->id ); ?>>
									<?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="edu-birth-date"><?php esc_html_e( 'Fecha de nacimiento', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-birth-date" name="birth_date" type="date" value="<?php echo esc_attr( $birth_date ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-sexo"><?php esc_html_e( 'Sexo', 'sistema-educativo' ); ?></label></th>
					<td>
						<select id="edu-sexo" name="sexo">
							<option value=""><?php esc_html_e( '— No especificado —', 'sistema-educativo' ); ?></option>
							<option value="M" <?php selected( $sexo, 'M' ); ?>><?php esc_html_e( 'Masculino', 'sistema-educativo' ); ?></option>
							<option value="F" <?php selected( $sexo, 'F' ); ?>><?php esc_html_e( 'Femenino', 'sistema-educativo' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Requerido por la nómina AMIE del Mineduc.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="edu-direccion"><?php esc_html_e( 'Dirección', 'sistema-educativo' ); ?></label></th>
					<td>
						<input id="edu-direccion" name="direccion" type="text" class="regular-text" maxlength="255" value="<?php echo esc_attr( $direccion ); ?>" placeholder="<?php esc_attr_e( 'Av. Ejemplo N1-23 y Calle Uno', 'sistema-educativo' ); ?>">
						<p class="description"><?php esc_html_e( 'Domicilio del estudiante. Requerido por la nómina AMIE del Mineduc.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="edu-enrollment-date"><?php esc_html_e( 'Fecha de matrícula', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-enrollment-date" name="enrollment_date" type="date" value="<?php echo esc_attr( $enrollment_date ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-photo-url"><?php esc_html_e( 'URL de la foto', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-photo-url" name="photo_url" type="url" class="regular-text" value="<?php echo esc_attr( $photo_url ); ?>" placeholder="https://..."></td>
				</tr>
				<tr>
					<th><label for="edu-status"><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></label></th>
					<td>
						<select id="edu-status" name="status">
							<?php foreach ( $status_labels as $sk => $sl ) : ?>
								<option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $st_status, $sk ); ?>><?php echo esc_html( $sl ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<?php submit_button( $student ? __( 'Guardar cambios', 'sistema-educativo' ) : __( 'Matricular estudiante', 'sistema-educativo' ) ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-estudiantes' ) ); ?>"><?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?></a>
		</form>
	<?php endif; ?>
</div>
