<?php
/**
 * Vista: ABM de docentes.
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
$t  = $wpdb->prefix . 'edu_teachers';
$um = $wpdb->usermeta;
$u  = $wpdb->users;

$teacher      = null;
$teacher_user = null;
if ( 'edit' === $action && $edit_id ) {
	$teacher = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $edit_id ) );
	if ( ! $teacher ) {
		wp_die( esc_html__( 'Docente no encontrado.', 'sistema-educativo' ) );
	}
	$bound = (int) get_user_meta( $teacher->user_id, 'edu_institution_id', true );
	if ( ! Edu_Context::is_superadmin_editorial() && $bound !== $current_institution_id ) {
		wp_die( esc_html__( 'Sin permiso para ver este docente.', 'sistema-educativo' ) );
	}
	$teacher_user = get_user_by( 'id', $teacher->user_id );
}

$status      = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$status_code = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';

$active_filter = isset( $_GET['active'] ) ? sanitize_key( $_GET['active'] ) : '';
?>
	<h1>
		<?php if ( 'list' === $action ) : ?>
			<?php esc_html_e( 'Docentes', 'sistema-educativo' ); ?>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-docentes&action=new' ) ); ?>"><?php esc_html_e( 'Nuevo docente', 'sistema-educativo' ); ?></a>
		<?php else : ?>
			<?php echo $teacher ? esc_html__( 'Editar docente', 'sistema-educativo' ) : esc_html__( 'Nuevo docente', 'sistema-educativo' ); ?>
		<?php endif; ?>
	</h1>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Docente guardado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'deleted' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Docente eliminado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$messages = array(
				'email_invalid'      => __( 'El email no es válido.', 'sistema-educativo' ),
				'name_required'      => __( 'Nombre y apellido son obligatorios.', 'sistema-educativo' ),
				'cedula_duplicate'   => __( 'Ya existe un docente con esa cédula.', 'sistema-educativo' ),
				'already_teacher'    => __( 'Ese usuario ya está registrado como docente.', 'sistema-educativo' ),
				'user_create_failed' => __( 'No se pudo crear el usuario de WordPress (¿email ya en uso?).', 'sistema-educativo' ),
				'user_update_failed' => __( 'No se pudo actualizar el usuario de WordPress.', 'sistema-educativo' ),
				'not_found'          => __( 'Docente no encontrado.', 'sistema-educativo' ),
			);
			echo esc_html( $messages[ $status_code ] ?? __( 'Error.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'list' === $action ) : ?>
		<?php
		$res_import = Edu_Import_Helper::obtener_resultado( 'teachers' );
		if ( $res_import ) {
			Edu_Import_Helper::render_resultado( $res_import, __( 'docente(s)', 'sistema-educativo' ) );
		}
		?>
		<form method="get" class="edu-filter-bar">
			<input type="hidden" name="page" value="edu-docentes">
			<label><?php esc_html_e( 'Estado:', 'sistema-educativo' ); ?></label>
			<select name="active" onchange="this.form.submit()">
				<option value="" <?php selected( $active_filter, '' ); ?>><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
				<option value="1" <?php selected( $active_filter, '1' ); ?>><?php esc_html_e( 'Activos', 'sistema-educativo' ); ?></option>
				<option value="0" <?php selected( $active_filter, '0' ); ?>><?php esc_html_e( 'Inactivos', 'sistema-educativo' ); ?></option>
			</select>
		</form>

		<?php
		$sql = "SELECT t.*, u.user_email, u.display_name
		        FROM $t t
		        INNER JOIN $u u ON u.ID = t.user_id
		        INNER JOIN $um m ON m.user_id = u.ID AND m.meta_key = 'edu_institution_id' AND m.meta_value = %d";
		$args = array( $current_institution_id );
		if ( '1' === $active_filter || '0' === $active_filter ) {
			$sql   .= ' AND t.is_active = %d';
			$args[] = (int) $active_filter;
		}
		$sql .= ' ORDER BY u.display_name';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Cédula', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Título', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Email', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Teléfono', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Aún no hay docentes en esta institución.', 'sistema-educativo' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->display_name ); ?></strong></td>
						<td><?php echo esc_html( $r->cedula ?: '—' ); ?></td>
						<td><?php echo esc_html( $r->title ?: '—' ); ?></td>
						<td><?php echo esc_html( $r->user_email ); ?></td>
						<td><?php echo esc_html( $r->phone ?: '—' ); ?></td>
						<td>
							<?php if ( (int) $r->is_active === 1 ) : ?>
								<span class="edu-status-active">● <?php esc_html_e( 'Activo', 'sistema-educativo' ); ?></span>
							<?php else : ?>
								<span class="edu-status-inactive">○ <?php esc_html_e( 'Inactivo', 'sistema-educativo' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=edu-docentes&action=edit&id=' . $r->id ) ); ?>"><?php esc_html_e( 'Editar', 'sistema-educativo' ); ?></a>
							|
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form" onsubmit="return confirm('<?php echo esc_attr__( '¿Eliminar este docente? Se quitará el rol edu_docente del usuario WordPress (pero el usuario se conserva).', 'sistema-educativo' ); ?>');">
								<?php wp_nonce_field( 'edu_delete_teacher' ); ?>
								<input type="hidden" name="action" value="edu_delete_teacher">
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
			__( 'Importación masiva de docentes', 'sistema-educativo' ),
			'edu_download_teachers_template',
			'edu_download_teachers_template',
			'edu_import_teachers_csv',
			'edu_import_teachers_csv',
			'teachers_csv',
			array(
				array( 'cedula',   true,  __( 'Cédula del docente. Se usa como login y contraseña inicial.', 'sistema-educativo' ) ),
				array( 'nombres',  true,  __( 'Nombres del docente.', 'sistema-educativo' ) ),
				array( 'apellidos', true, __( 'Apellidos del docente.', 'sistema-educativo' ) ),
				array( 'email',    false, __( 'Correo electrónico. Opcional; si está vacío se genera uno automático.', 'sistema-educativo' ) ),
				array( 'titulo',   false, __( 'Título académico. Ej: <code>Lcdo.</code>, <code>Ing.</code>, <code>MSc.</code>', 'sistema-educativo' ) ),
				array( 'grado',    true,  __( 'Nombre exacto del grado. Ej: <code>1ro BG</code>', 'sistema-educativo' ) ),
				array( 'paralelo', true,  __( '<code>A</code>, <code>B</code>, <code>C</code> o <code>D</code>', 'sistema-educativo' ) ),
				array( 'materia',  true,  __( 'Nombre exacto de la materia asignada al grado.', 'sistema-educativo' ) ),
			),
			array(
				__( 'Un docente puede aparecer en varias filas si enseña varias materias o grados.', 'sistema-educativo' ),
				__( 'La asignación académica requiere un período lectivo activo en la institución.', 'sistema-educativo' ),
			)
		);
		?>
	<?php else : ?>
		<?php
		$email      = $teacher_user ? $teacher_user->user_email : '';
		$first_name = $teacher_user ? $teacher_user->first_name : '';
		$last_name  = $teacher_user ? $teacher_user->last_name : '';
		$cedula     = $teacher->cedula ?? '';
		$phone      = $teacher->phone ?? '';
		$title      = $teacher->title ?? '';
		$hire_date  = $teacher->hire_date ?? '';
		$is_active  = $teacher ? (int) $teacher->is_active : 1;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-form">
			<?php wp_nonce_field( 'edu_save_teacher' ); ?>
			<input type="hidden" name="action" value="edu_save_teacher">
			<?php if ( $teacher ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( $teacher->id ); ?>"><?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="edu-email"><?php esc_html_e( 'Email', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td>
						<input id="edu-email" name="email" type="email" class="regular-text" required value="<?php echo esc_attr( $email ); ?>">
						<?php if ( ! $teacher ) : ?>
							<p class="description"><?php esc_html_e( 'Si el email ya tiene cuenta en WordPress, se le añadirá el rol edu_docente. Si no, se crea cuenta nueva.', 'sistema-educativo' ); ?></p>
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
					<th><label for="edu-phone"><?php esc_html_e( 'Teléfono', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-phone" name="phone" type="text" maxlength="20" value="<?php echo esc_attr( $phone ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-title"><?php esc_html_e( 'Título', 'sistema-educativo' ); ?></label></th>
					<td>
						<input id="edu-title" name="title" type="text" maxlength="50" value="<?php echo esc_attr( $title ); ?>" list="edu-title-list" placeholder="<?php esc_attr_e( 'Ej: Lcdo., Ing., MSc.', 'sistema-educativo' ); ?>">
						<datalist id="edu-title-list">
							<option value="Lcdo."><option value="Lcda."><option value="Ing."><option value="Dr."><option value="Dra."><option value="MSc."><option value="PhD.">
						</datalist>
					</td>
				</tr>
				<tr>
					<th><label for="edu-hire-date"><?php esc_html_e( 'Fecha de contratación', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-hire-date" name="hire_date" type="date" value="<?php echo esc_attr( $hire_date ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					<td><label><input type="checkbox" name="is_active" value="1" <?php checked( $is_active, 1 ); ?>> <?php esc_html_e( 'Docente activo', 'sistema-educativo' ); ?></label></td>
				</tr>
			</table>

			<?php submit_button( $teacher ? __( 'Guardar cambios', 'sistema-educativo' ) : __( 'Crear docente', 'sistema-educativo' ) ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-docentes' ) ); ?>"><?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?></a>
		</form>
	<?php endif; ?>
</div>
