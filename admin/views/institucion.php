<?php
/**
 * Vista: ABM de instituciones.
 *
 * - Superadmin Editorial: lista todas las instituciones + crear/editar/eliminar/activar.
 * - edu_rector: formulario directo sobre su institución asignada.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_view_all' ) ) {
	wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'sistema-educativo' ) );
}

$is_super = Edu_Context::is_superadmin_editorial();
$action   = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
$edit_id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

global $wpdb;
$table = $wpdb->prefix . 'edu_institutions';

// edu_rector: siempre va al formulario de su institución.
if ( ! $is_super ) {
	$action  = 'edit';
	$edit_id = Edu_Context::current_institution_id();
}

$institution = null;
if ( 'edit' === $action && $edit_id ) {
	$institution = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $edit_id ) );
	if ( ! $institution || ! Edu_Context::can_access_institution( $edit_id ) ) {
		wp_die( esc_html__( 'Institución no encontrada o sin acceso.', 'sistema-educativo' ) );
	}
}

$status      = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$status_code = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
?>
<div class="wrap edu-wrap">
	<h1>
		<?php if ( 'new' === $action ) : ?>
			<?php esc_html_e( 'Nueva institución', 'sistema-educativo' ); ?>
		<?php elseif ( 'edit' === $action ) : ?>
			<?php esc_html_e( 'Datos de la institución', 'sistema-educativo' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Instituciones', 'sistema-educativo' ); ?>
			<?php if ( $is_super ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-institucion&action=new' ) ); ?>"><?php esc_html_e( 'Nueva', 'sistema-educativo' ); ?></a>
			<?php endif; ?>
		<?php endif; ?>
	</h1>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Institución guardada.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'deleted' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Institución eliminada.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$messages = array(
				'name_required' => __( 'El nombre/razón social es obligatorio.', 'sistema-educativo' ),
				'not_found'     => __( 'Institución no encontrada.', 'sistema-educativo' ),
				'invalid_id'    => __( 'ID inválido.', 'sistema-educativo' ),
			);
			echo esc_html( $messages[ $status_code ] ?? __( 'Ocurrió un error.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'list' === $action && $is_super ) : ?>
		<?php
		$rows       = $wpdb->get_results( "SELECT * FROM $table ORDER BY name" );
		$current_id = Edu_Context::current_institution_id();
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Razón social', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'RUC', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Régimen', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No hay instituciones registradas. Crea la primera.', 'sistema-educativo' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $r->name ); ?></strong>
							<?php if ( (int) $r->id === $current_id ) : ?>
								<span class="edu-badge edu-badge-active"><?php esc_html_e( 'Activa', 'sistema-educativo' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $r->ruc ?: '—' ); ?></td>
						<td><?php echo esc_html( ucfirst( $r->regime ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=edu-institucion&action=edit&id=' . $r->id ) ); ?>"><?php esc_html_e( 'Editar', 'sistema-educativo' ); ?></a>
							<?php if ( (int) $r->id !== $current_id ) : ?>
								|
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form">
									<?php wp_nonce_field( 'edu_set_active_institution' ); ?>
									<input type="hidden" name="action" value="edu_set_active_institution">
									<input type="hidden" name="institution_id" value="<?php echo esc_attr( $r->id ); ?>">
									<button type="submit" class="edu-link-button"><?php esc_html_e( 'Activar', 'sistema-educativo' ); ?></button>
								</form>
							<?php endif; ?>
							|
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form" onsubmit="return confirm('<?php echo esc_attr__( '¿Eliminar esta institución? Se borrarán períodos, grados y materias asociados.', 'sistema-educativo' ); ?>');">
								<?php wp_nonce_field( 'edu_delete_institution' ); ?>
								<input type="hidden" name="action" value="edu_delete_institution">
								<input type="hidden" name="id" value="<?php echo esc_attr( $r->id ); ?>">
								<button type="submit" class="edu-link-button edu-link-danger"><?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-form">
			<?php wp_nonce_field( 'edu_save_institution' ); ?>
			<input type="hidden" name="action" value="edu_save_institution">
			<?php if ( $institution ) : ?>
				<input type="hidden" name="id" value="<?php echo esc_attr( $institution->id ); ?>">
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="edu-name"><?php esc_html_e( 'Razón social', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td><input id="edu-name" name="name" class="regular-text" type="text" required maxlength="200" value="<?php echo esc_attr( $institution->name ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="edu-ruc"><?php esc_html_e( 'RUC', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-ruc" name="ruc" class="regular-text" type="text" maxlength="13" value="<?php echo esc_attr( $institution->ruc ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="edu-address"><?php esc_html_e( 'Dirección', 'sistema-educativo' ); ?></label></th>
					<td><textarea id="edu-address" name="address" rows="2" class="large-text"><?php echo esc_textarea( $institution->address ?? '' ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="edu-phone"><?php esc_html_e( 'Teléfono', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-phone" name="phone" class="regular-text" type="text" maxlength="20" value="<?php echo esc_attr( $institution->phone ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="edu-email"><?php esc_html_e( 'Email institucional', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-email" name="email" class="regular-text" type="email" value="<?php echo esc_attr( $institution->email ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Régimen', 'sistema-educativo' ); ?></label></th>
					<td>
						<label><input type="radio" name="regime" value="sierra" <?php checked( $institution->regime ?? 'sierra', 'sierra' ); ?>> <?php esc_html_e( 'Sierra-Amazonía', 'sistema-educativo' ); ?></label>
						&nbsp;&nbsp;
						<label><input type="radio" name="regime" value="costa" <?php checked( $institution->regime ?? 'sierra', 'costa' ); ?>> <?php esc_html_e( 'Costa-Galápagos', 'sistema-educativo' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="edu-logo-url"><?php esc_html_e( 'URL del logo', 'sistema-educativo' ); ?></label></th>
					<td>
						<input id="edu-logo-url" name="logo_url" class="regular-text" type="url" value="<?php echo esc_attr( $institution->logo_url ?? '' ); ?>" placeholder="https://...">
						<p class="description"><?php esc_html_e( 'Pega la URL del logo. El uploader de WP Media llega en Fase 1.5.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( $institution ? __( 'Guardar cambios', 'sistema-educativo' ) : __( 'Crear institución', 'sistema-educativo' ) ); ?>
			<?php if ( $is_super ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-institucion' ) ); ?>"><?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?></a>
			<?php endif; ?>
		</form>
	<?php endif; ?>
</div>
