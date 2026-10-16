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
				'invalid_image' => __( 'La firma y el sello deben ser imágenes PNG o JPG. Los demás datos sí se guardaron.', 'sistema-educativo' ),
				'image_too_big' => __( 'La imagen supera 1 MB. Los demás datos sí se guardaron.', 'sistema-educativo' ),
				'upload_failed' => __( 'No se pudo guardar la imagen. Los demás datos sí se guardaron.', 'sistema-educativo' ),
				'mode_locked'   => __( 'No se cambió el modo de calificación: el período activo ya tiene notas. Los demás datos sí se guardaron.', 'sistema-educativo' ),
				'second_parcial_components' => __( 'No se cambió el modo de calificación: hay componentes en el segundo parcial. Elimínalos o muévelos al primero. Los demás datos sí se guardaron.', 'sistema-educativo' ),
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
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-form" enctype="multipart/form-data">
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

			<?php $edu_modo = $institution ? Edu_Service::grading_mode( (int) $institution->id ) : Edu_Service::MODO_PARCIALES; ?>
			<h2><?php esc_html_e( 'Calificación', 'sistema-educativo' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Modo de calificación', 'sistema-educativo' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="modo_notas" value="<?php echo esc_attr( Edu_Service::MODO_PARCIALES ); ?>" <?php checked( $edu_modo, Edu_Service::MODO_PARCIALES ); ?>>
								<?php esc_html_e( 'Por parciales', 'sistema-educativo' ); ?></label>
							<p class="description"><?php esc_html_e( 'Dos parciales por trimestre. Cada parcial es el promedio simple de sus notas; el 70% es el promedio de los dos parciales.', 'sistema-educativo' ); ?></p>
							<br>
							<label><input type="radio" name="modo_notas" value="<?php echo esc_attr( Edu_Service::MODO_TRIMESTRE ); ?>" <?php checked( $edu_modo, Edu_Service::MODO_TRIMESTRE ); ?>>
								<?php esc_html_e( 'Solo trimestre (aplicativo del Ministerio)', 'sistema-educativo' ); ?></label>
							<p class="description"><?php esc_html_e( 'Sin parciales. El 70% es el promedio simple de todos los aportes del trimestre.', 'sistema-educativo' ); ?></p>
						</fieldset>
						<p class="description"><strong><?php esc_html_e( 'Se elige antes de empezar a calificar: con notas en el período activo ya no se puede cambiar.', 'sistema-educativo' ); ?></strong>
							<?php esc_html_e( 'En los dos modos la evaluación sumativa (30%) sigue la regla de cada subnivel.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Certificado de promoción', 'sistema-educativo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Datos que aparecen en el certificado. Las firmas y el sello se guardan en la carpeta privada del sistema: no tienen dirección pública y solo se usan al generar el PDF.', 'sistema-educativo' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="edu-amie"><?php esc_html_e( 'Código AMIE', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-amie" name="amie_code" class="regular-text" type="text" maxlength="10" value="<?php echo esc_attr( $institution->amie_code ?? '' ); ?>" placeholder="17H00000"></td>
				</tr>
				<tr>
					<th scope="row"><label for="edu-city"><?php esc_html_e( 'Ciudad', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-city" name="city" class="regular-text" type="text" maxlength="100" value="<?php echo esc_attr( $institution->city ?? '' ); ?>" placeholder="Quito"></td>
				</tr>
				<tr>
					<th scope="row"><label for="edu-rector-name"><?php esc_html_e( 'Rector/a (nombre para la firma)', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-rector-name" name="rector_name" class="regular-text" type="text" maxlength="150" value="<?php echo esc_attr( $institution->rector_name ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Mgs. Nombre Apellido', 'sistema-educativo' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="edu-secretary-name"><?php esc_html_e( 'Secretario/a (nombre para la firma)', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-secretary-name" name="secretary_name" class="regular-text" type="text" maxlength="150" value="<?php echo esc_attr( $institution->secretary_name ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Lic. Nombre Apellido', 'sistema-educativo' ); ?>"></td>
				</tr>
				<?php
				$edu_cert_imgs = array(
					'firma_rector'     => __( 'Firma del rector/a', 'sistema-educativo' ),
					'firma_secretario' => __( 'Firma del secretario/a', 'sistema-educativo' ),
					'sello'            => __( 'Sello de la institución', 'sistema-educativo' ),
				);
				foreach ( $edu_cert_imgs as $edu_tipo => $edu_label ) :
					$edu_prev = $institution ? Edu_Certificate_Service::vista_previa( (int) $institution->id, $edu_tipo ) : '';
					?>
					<tr>
						<th scope="row"><label for="edu-cert-<?php echo esc_attr( $edu_tipo ); ?>"><?php echo esc_html( $edu_label ); ?></label></th>
						<td>
							<?php if ( $edu_prev ) : ?>
								<img src="<?php echo esc_attr( $edu_prev ); ?>" alt="" style="max-height:70px; max-width:200px; border:1px solid #dcdcde; padding:4px; background:#fff; display:block; margin-bottom:6px;">
								<label><input type="checkbox" name="borrar_<?php echo esc_attr( $edu_tipo ); ?>" value="1"> <?php esc_html_e( 'Quitar', 'sistema-educativo' ); ?></label><br>
							<?php endif; ?>
							<input id="edu-cert-<?php echo esc_attr( $edu_tipo ); ?>" name="cert_<?php echo esc_attr( $edu_tipo ); ?>" type="file" accept="image/png,image/jpeg">
							<p class="description"><?php esc_html_e( 'PNG o JPG de hasta 1 MB. Mejor PNG con fondo transparente.', 'sistema-educativo' ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php if ( $institution ) : ?>
				<?php
				$edu_pl  = Edu_Certificate_Service::plantilla( (int) $institution->id );
				$edu_def = Edu_Certificate_Service::plantilla_defecto();
				?>
				<h3><?php esc_html_e( 'Textos del certificado', 'sistema-educativo' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Deje un campo vacío para volver al texto de fábrica. Marcadores que se reemplazan solos:', 'sistema-educativo' ); ?>
					<code><?php echo esc_html( '{' . implode( '} {', Edu_Certificate_Service::MARCADORES ) . '}' ); ?></code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="edu-cert-titulo"><?php esc_html_e( 'Título', 'sistema-educativo' ); ?></label></th>
						<td><input id="edu-cert-titulo" name="cert[titulo]" class="regular-text" type="text" maxlength="80" value="<?php echo esc_attr( $edu_pl['titulo'] ); ?>"></td>
					</tr>
					<?php
					$edu_textos = array(
						'introduccion' => __( 'Párrafo de introducción', 'sistema-educativo' ),
						'promocion'    => __( 'Párrafo de promoción', 'sistema-educativo' ),
						'cierre'       => __( 'Párrafo de cierre', 'sistema-educativo' ),
					);
					foreach ( $edu_textos as $edu_k => $edu_lbl ) :
						?>
						<tr>
							<th scope="row"><label for="edu-cert-<?php echo esc_attr( $edu_k ); ?>"><?php echo esc_html( $edu_lbl ); ?></label></th>
							<td><textarea id="edu-cert-<?php echo esc_attr( $edu_k ); ?>" name="cert[<?php echo esc_attr( $edu_k ); ?>]" rows="<?php echo 'introduccion' === $edu_k ? 4 : 2; ?>" class="large-text" placeholder="<?php echo esc_attr( $edu_def[ $edu_k ] ); ?>"><?php echo esc_textarea( $edu_pl[ $edu_k ] ); ?></textarea></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cargos bajo las firmas', 'sistema-educativo' ); ?></th>
						<td>
							<input name="cert[cargo_rector]" type="text" maxlength="80" value="<?php echo esc_attr( $edu_pl['cargo_rector'] ); ?>" aria-label="<?php esc_attr_e( 'Cargo del rector', 'sistema-educativo' ); ?>">
							<input name="cert[cargo_secretario]" type="text" maxlength="80" value="<?php echo esc_attr( $edu_pl['cargo_secretario'] ); ?>" aria-label="<?php esc_attr_e( 'Cargo del secretario', 'sistema-educativo' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Columnas de notas', 'sistema-educativo' ); ?></th>
						<td>
							<label><input type="checkbox" name="cert[mostrar_letras]" value="1" <?php checked( $edu_pl['mostrar_letras'] ); ?>> <?php esc_html_e( 'Nota en letras', 'sistema-educativo' ); ?></label>
							&nbsp;&nbsp;
							<label><input type="checkbox" name="cert[mostrar_cualitativa]" value="1" <?php checked( $edu_pl['mostrar_cualitativa'] ); ?>> <?php esc_html_e( 'Equivalencia cualitativa', 'sistema-educativo' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="edu-cert-color"><?php esc_html_e( 'Color principal', 'sistema-educativo' ); ?></label></th>
						<td><input id="edu-cert-color" name="cert[color]" type="color" value="<?php echo esc_attr( $edu_pl['color'] ); ?>"></td>
					</tr>
				</table>
				<p>
					<a class="button" target="_blank" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'edu_preview_certificado', 'id' => (int) $institution->id ), admin_url( 'admin-post.php' ) ), 'edu_preview_certificado' ) ); ?>">
						<?php esc_html_e( 'Ver certificado de muestra', 'sistema-educativo' ); ?>
					</a>
					<span class="description"><?php esc_html_e( 'Usa lo último guardado: guarde los cambios antes de verlo.', 'sistema-educativo' ); ?></span>
				</p>
			<?php endif; ?>

			<?php submit_button( $institution ? __( 'Guardar cambios', 'sistema-educativo' ) : __( 'Crear institución', 'sistema-educativo' ) ); ?>
			<?php if ( $is_super ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-institucion' ) ); ?>"><?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?></a>
			<?php endif; ?>
		</form>
	<?php endif; ?>
</div>
