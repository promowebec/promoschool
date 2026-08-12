<?php
/**
 * Vista: ABM de representantes + vínculos con estudiantes.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_manage_parents' ) ) {
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
$tp  = $wpdb->prefix . 'edu_parents';
$tps = $wpdb->prefix . 'edu_parent_student';
$ts  = $wpdb->prefix . 'edu_students';
$tg  = $wpdb->prefix . 'edu_grades';
$u   = $wpdb->users;
$um  = $wpdb->usermeta;

// Estudiantes de la institución para el selector de vínculos.
$students = $wpdb->get_results( $wpdb->prepare(
	"SELECT s.id, u.display_name, g.name AS grade_name, g.paralelo
	 FROM $ts s
	 INNER JOIN $u u ON u.ID = s.user_id
	 INNER JOIN $tg g ON g.id = s.grade_id
	 WHERE g.institution_id = %d
	 ORDER BY g.name, g.paralelo, u.display_name",
	$current_institution_id
) );

$parent      = null;
$parent_user = null;
$links       = array();
if ( 'edit' === $action && $edit_id ) {
	$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tp WHERE id = %d", $edit_id ) );
	if ( ! $parent ) {
		wp_die( esc_html__( 'Representante no encontrado.', 'sistema-educativo' ) );
	}
	$bound = (int) get_user_meta( $parent->user_id, 'edu_institution_id', true );
	if ( ! Edu_Context::is_superadmin_editorial() && $bound !== $current_institution_id ) {
		wp_die( esc_html__( 'Sin permiso para ver este representante.', 'sistema-educativo' ) );
	}
	$parent_user = get_user_by( 'id', $parent->user_id );
	$links       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tps WHERE parent_id = %d", $edit_id ) );
}

$status      = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$status_code = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';

$relationships = array(
	'padre' => __( 'Padre', 'sistema-educativo' ),
	'madre' => __( 'Madre', 'sistema-educativo' ),
	'tutor' => __( 'Tutor legal', 'sistema-educativo' ),
	'otro'  => __( 'Otro', 'sistema-educativo' ),
);
?>
	<h1>
		<?php if ( 'list' === $action ) : ?>
			<?php esc_html_e( 'Representantes', 'sistema-educativo' ); ?>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-padres&action=new' ) ); ?>"><?php esc_html_e( 'Nuevo representante', 'sistema-educativo' ); ?></a>
		<?php else : ?>
			<?php echo $parent ? esc_html__( 'Editar representante', 'sistema-educativo' ) : esc_html__( 'Nuevo representante', 'sistema-educativo' ); ?>
		<?php endif; ?>
	</h1>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Representante guardado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'deleted' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Representante eliminado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$messages = array(
				'email_invalid'      => __( 'El email no es válido.', 'sistema-educativo' ),
				'name_required'      => __( 'Nombre y apellido son obligatorios.', 'sistema-educativo' ),
				'cedula_duplicate'   => __( 'Ya existe un representante con esa cédula.', 'sistema-educativo' ),
				'already_parent'     => __( 'Ese usuario ya está registrado como representante.', 'sistema-educativo' ),
				'user_create_failed' => __( 'No se pudo crear el usuario de WordPress (¿email ya en uso?).', 'sistema-educativo' ),
				'user_update_failed' => __( 'No se pudo actualizar el usuario de WordPress.', 'sistema-educativo' ),
				'not_found'          => __( 'Representante no encontrado.', 'sistema-educativo' ),
			);
			echo esc_html( $messages[ $status_code ] ?? __( 'Error.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'list' === $action ) : ?>
		<?php
		$res_import = Edu_Import_Helper::obtener_resultado( 'parents' );
		if ( $res_import ) {
			Edu_Import_Helper::render_resultado( $res_import, __( 'representante(s)', 'sistema-educativo' ) );
		}
		?>
		<?php
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.*, u.user_email, u.display_name,
			        (SELECT COUNT(*) FROM $tps ps WHERE ps.parent_id = p.id) AS link_count
			 FROM $tp p
			 INNER JOIN $u u ON u.ID = p.user_id
			 INNER JOIN $um m ON m.user_id = u.ID AND m.meta_key = 'edu_institution_id' AND m.meta_value = %d
			 ORDER BY u.display_name",
			$current_institution_id
		) );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Cédula', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Email', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Teléfono', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'WhatsApp', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Hijos vinculados', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Aún no hay representantes en esta institución.', 'sistema-educativo' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->display_name ); ?></strong></td>
						<td><?php echo esc_html( $r->cedula ?: '—' ); ?></td>
						<td><?php echo esc_html( $r->user_email ); ?></td>
						<td><?php echo esc_html( $r->phone ?: '—' ); ?></td>
						<td><?php echo esc_html( $r->whatsapp ?: '—' ); ?></td>
						<td><?php echo (int) $r->link_count; ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=edu-padres&action=edit&id=' . $r->id ) ); ?>"><?php esc_html_e( 'Editar', 'sistema-educativo' ); ?></a>
							|
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form" onsubmit="return confirm('<?php echo esc_attr__( '¿Eliminar este representante? Se quitarán los vínculos con estudiantes y el rol edu_padre del usuario WordPress.', 'sistema-educativo' ); ?>');">
								<?php wp_nonce_field( 'edu_delete_parent' ); ?>
								<input type="hidden" name="action" value="edu_delete_parent">
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
			__( 'Importación masiva de representantes', 'sistema-educativo' ),
			'edu_download_parents_template',
			'edu_download_parents_template',
			'edu_import_parents_csv',
			'edu_import_parents_csv',
			'parents_csv',
			array(
				array( 'cedula',            true,  __( 'Cédula del representante. Se usa como login y contraseña inicial.', 'sistema-educativo' ) ),
				array( 'nombres',           true,  __( 'Nombres del representante.', 'sistema-educativo' ) ),
				array( 'apellidos',         true,  __( 'Apellidos del representante.', 'sistema-educativo' ) ),
				array( 'email',             false, __( 'Correo electrónico. Opcional; si está vacío se genera uno automático.', 'sistema-educativo' ) ),
				array( 'telefono',          false, __( 'Teléfono de contacto.', 'sistema-educativo' ) ),
				array( 'whatsapp',          false, __( 'Número WhatsApp. Ej: <code>+593987654321</code>', 'sistema-educativo' ) ),
				array( 'cedula_estudiante', true,  __( 'Cédula del estudiante ya matriculado para vincularlo.', 'sistema-educativo' ) ),
				array( 'parentesco',        false, __( '<code>padre</code> · <code>madre</code> · <code>tutor</code> · <code>otro</code> (defecto: padre)', 'sistema-educativo' ) ),
			),
			array(
				__( 'El estudiante debe estar matriculado antes de importar al representante.', 'sistema-educativo' ),
				__( 'Si el representante ya existe (misma cédula), solo se crea el vínculo con el estudiante.', 'sistema-educativo' ),
			)
		);
		?>
	<?php else : ?>
		<?php
		$email       = $parent_user ? $parent_user->user_email : '';
		$first_name  = $parent_user ? $parent_user->first_name : '';
		$last_name   = $parent_user ? $parent_user->last_name : '';
		$cedula      = $parent->cedula ?? '';
		$phone       = $parent->phone ?? '';
		$whatsapp    = $parent->whatsapp ?? '';
		$occupation  = $parent->occupation ?? '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-form">
			<?php wp_nonce_field( 'edu_save_parent' ); ?>
			<input type="hidden" name="action" value="edu_save_parent">
			<?php if ( $parent ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( $parent->id ); ?>"><?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="edu-email"><?php esc_html_e( 'Email', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td>
						<input id="edu-email" name="email" type="email" class="regular-text" required value="<?php echo esc_attr( $email ); ?>">
						<?php if ( ! $parent ) : ?>
							<p class="description"><?php esc_html_e( 'Si el email ya tiene cuenta en WordPress, se le añadirá el rol edu_padre. Si no, se crea cuenta nueva.', 'sistema-educativo' ); ?></p>
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
					<th><label for="edu-whatsapp"><?php esc_html_e( 'WhatsApp', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-whatsapp" name="whatsapp" type="text" maxlength="20" value="<?php echo esc_attr( $whatsapp ); ?>" placeholder="+593..."></td>
				</tr>
				<tr>
					<th><label for="edu-occupation"><?php esc_html_e( 'Ocupación', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-occupation" name="occupation" type="text" class="regular-text" maxlength="100" value="<?php echo esc_attr( $occupation ); ?>"></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Hijos vinculados', 'sistema-educativo' ); ?></h2>
			<?php if ( empty( $students ) ) : ?>
				<p class="description">
					<?php
					printf(
						wp_kses_post( __( 'No hay estudiantes en esta institución. <a href="%s">Matricula al menos uno</a> para poder vincularlo.', 'sistema-educativo' ) ),
						esc_url( admin_url( 'admin.php?page=edu-estudiantes&action=new' ) )
					);
					?>
				</p>
			<?php else : ?>
				<table class="widefat" id="edu-links-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
							<th style="width:160px"><?php esc_html_e( 'Relación', 'sistema-educativo' ); ?></th>
							<th style="width:120px"><?php esc_html_e( 'Receptor principal', 'sistema-educativo' ); ?></th>
							<th style="width:80px"></th>
						</tr>
					</thead>
					<tbody id="edu-links-body">
						<?php if ( ! empty( $links ) ) : foreach ( $links as $lk ) : ?>
							<tr class="edu-link-row">
								<td>
									<select name="student_ids[]">
										<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
										<?php foreach ( $students as $st ) : ?>
											<option value="<?php echo esc_attr( $st->id ); ?>" <?php selected( (int) $lk->student_id, (int) $st->id ); ?>>
												<?php echo esc_html( $st->display_name . ' · ' . $st->grade_name . ' ' . $st->paralelo ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="relationships[]">
										<?php foreach ( $relationships as $rk => $rl ) : ?>
											<option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $lk->relationship, $rk ); ?>><?php echo esc_html( $rl ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="checkbox" name="is_primary[]" value="1" <?php checked( (int) $lk->is_primary, 1 ); ?>></td>
								<td><button type="button" class="button button-small edu-remove-link"><?php esc_html_e( 'Quitar', 'sistema-educativo' ); ?></button></td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="edu-add-link"><?php esc_html_e( '+ Agregar hijo', 'sistema-educativo' ); ?></button></p>

				<template id="edu-link-template">
					<tr class="edu-link-row">
						<td>
							<select name="student_ids[]">
								<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
								<?php foreach ( $students as $st ) : ?>
									<option value="<?php echo esc_attr( $st->id ); ?>"><?php echo esc_html( $st->display_name . ' · ' . $st->grade_name . ' ' . $st->paralelo ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<select name="relationships[]">
								<?php foreach ( $relationships as $rk => $rl ) : ?>
									<option value="<?php echo esc_attr( $rk ); ?>"><?php echo esc_html( $rl ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="checkbox" name="is_primary[]" value="1"></td>
						<td><button type="button" class="button button-small edu-remove-link"><?php esc_html_e( 'Quitar', 'sistema-educativo' ); ?></button></td>
					</tr>
				</template>

				<script>
				( function () {
					var body = document.getElementById( 'edu-links-body' );
					var tpl  = document.getElementById( 'edu-link-template' );
					document.getElementById( 'edu-add-link' ).addEventListener( 'click', function () {
						body.appendChild( tpl.content.cloneNode( true ) );
					} );
					body.addEventListener( 'click', function ( e ) {
						if ( e.target.classList.contains( 'edu-remove-link' ) ) {
							e.target.closest( '.edu-link-row' ).remove();
						}
					} );
				} )();
				</script>
			<?php endif; ?>

			<?php submit_button( $parent ? __( 'Guardar cambios', 'sistema-educativo' ) : __( 'Crear representante', 'sistema-educativo' ) ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-padres' ) ); ?>"><?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?></a>
		</form>
	<?php endif; ?>
</div>
