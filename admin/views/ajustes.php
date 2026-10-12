<?php
/**
 * Vista: ajustes generales del plugin.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

$tareas_page_id          = (int) get_option( 'edu_tareas_page_id', 0 );
$comunicados_page_id     = (int) get_option( 'edu_comunicados_page_id', 0 );
$rector_page_id          = (int) get_option( 'edu_portal_rector_page_id', 0 );
$docente_page_id         = (int) get_option( 'edu_portal_docente_page_id', 0 );
$estudiante_page_id      = (int) get_option( 'edu_portal_estudiante_page_id', 0 );
$padre_page_id           = (int) get_option( 'edu_portal_padre_page_id', 0 );
$email_from_name         = (string) get_option( 'edu_email_from_name', get_bloginfo( 'name' ) );
$email_from_address      = (string) get_option( 'edu_email_from_address', get_option( 'admin_email' ) );

// WhatsApp.
$wa_provider            = (string) get_option( 'edu_wa_provider', 'disabled' );
$wa_twilio_sid          = (string) get_option( 'edu_wa_twilio_sid', '' );
$wa_twilio_token        = (string) get_option( 'edu_wa_twilio_token', '' );
$wa_twilio_from         = (string) get_option( 'edu_wa_twilio_from', '' );
$wa_meta_phone_id       = (string) get_option( 'edu_wa_meta_phone_id', '' );
$wa_meta_token          = (string) get_option( 'edu_wa_meta_token', '' );
$wa_waha_url            = (string) get_option( 'edu_wa_waha_url', '' );
$wa_waha_key            = (string) get_option( 'edu_wa_waha_key', '' );
$wa_waha_session        = (string) get_option( 'edu_wa_waha_session', '' );
$wa_waha_sugerida       = 'promoschool-' . sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$wa_waha_listo          = '' !== $wa_waha_url && '' !== $wa_waha_session;
$wa_notify_comunicados  = (int) get_option( 'edu_wa_notify_comunicados', 0 );
$wa_notify_notas        = (int) get_option( 'edu_wa_notify_notas', 0 );
$wa_notify_pagos        = (int) get_option( 'edu_wa_notify_pagos', 0 );
$wa_notify_asistencia   = (int) get_option( 'edu_wa_notify_asistencia', 0 );
$wa_tpl_comunicado      = (string) get_option( 'edu_wa_tpl_comunicado', '' );
$wa_tpl_nota            = (string) get_option( 'edu_wa_tpl_nota', '' );
$wa_tpl_pago            = (string) get_option( 'edu_wa_tpl_pago', '' );
$wa_tpl_asistencia      = (string) get_option( 'edu_wa_tpl_asistencia', '' );
$wa_tpl_lang            = (string) get_option( 'edu_wa_tpl_lang', 'es' );

// Todas las páginas publicadas para los selectores.
$pages = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title' ) );
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Ajustes del plugin', 'sistema-educativo' ); ?></h1>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ajustes guardados.', 'sistema-educativo' ); ?></p></div>
	<?php endif; ?>

	<?php
	// ── Plan contratado ─────────────────────────────────────────────────
	$plan          = Edu_Plan::estado();
	$plan_lic      = $plan['licencia'];
	$plan_error    = 'license_error' === $status ? (string) get_transient( 'edu_license_error_' . get_current_user_id() ) : '';
	$plan_catalogo = Edu_Modules::catalog();
	$plan_modos    = array(
		'licencia' => __( 'Licencia activa', 'sistema-educativo' ),
		'heredado' => __( 'Instalación anterior al plan: conserva todos sus módulos hasta que se cargue una licencia.', 'sistema-educativo' ),
		'nuevo'    => __( 'Sin licencia: solo el núcleo académico está activo.', 'sistema-educativo' ),
	);
	if ( $plan_error ) {
		delete_transient( 'edu_license_error_' . get_current_user_id() );
	}
	?>

	<?php if ( 'license_saved' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Licencia actualizada.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'license_error' === $status ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $plan_error ? $plan_error : __( 'No se pudo guardar la licencia.', 'sistema-educativo' ) ); ?></p></div>
	<?php endif; ?>

	<div class="edu-card" style="max-width:600px; margin:16px 0 24px; padding:16px 20px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Plan contratado', 'sistema-educativo' ); ?></h2>

		<p><strong><?php echo esc_html( $plan_modos[ $plan['modo'] ] ); ?></strong></p>

		<?php if ( $plan['error'] ) : ?>
			<p style="color:#b91c1c;"><?php echo esc_html( sprintf( __( 'La licencia guardada no es válida: %s', 'sistema-educativo' ), $plan['error'] ) ); ?></p>
		<?php endif; ?>

		<?php if ( $plan_lic ) : ?>
			<table class="widefat striped" style="margin-bottom:12px;">
				<tr><th><?php esc_html_e( 'Institución', 'sistema-educativo' ); ?></th><td><?php echo esc_html( $plan_lic['cliente'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Edición', 'sistema-educativo' ); ?></th><td><?php echo esc_html( ucfirst( $plan_lic['edicion'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Módulos incluidos', 'sistema-educativo' ); ?></th>
					<td>
						<?php
						$plan_nombres = array();
						foreach ( $plan_lic['modulos'] as $plan_mod ) {
							$plan_nombres[] = isset( $plan_catalogo[ $plan_mod ] ) ? $plan_catalogo[ $plan_mod ]['label'] : $plan_mod;
						}
						echo esc_html( $plan_nombres ? implode( ', ', $plan_nombres ) : __( 'Solo núcleo académico', 'sistema-educativo' ) );
						?>
					</td>
				</tr>
				<?php if ( ! empty( $plan_lic['limites']['estudiantes'] ) ) : ?>
					<tr><th><?php esc_html_e( 'Capacidad', 'sistema-educativo' ); ?></th>
						<td><?php echo esc_html( sprintf( __( '%1$d estudiantes (activos hoy: %2$d)', 'sistema-educativo' ), $plan_lic['limites']['estudiantes'], Edu_Plan::estudiantes_activos() ) ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $plan_lic['actualizaciones_hasta'] ) : ?>
					<tr><th><?php esc_html_e( 'Actualizaciones hasta', 'sistema-educativo' ); ?></th><td><?php echo esc_html( $plan_lic['actualizaciones_hasta'] ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Licencia', 'sistema-educativo' ); ?></th><td><code><?php echo esc_html( $plan_lic['lid'] ); ?></code></td></tr>
			</table>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'edu_save_license' ); ?>
			<input type="hidden" name="action" value="edu_save_license">
			<label for="edu_license"><strong><?php echo esc_html( $plan_lic ? __( 'Reemplazar licencia', 'sistema-educativo' ) : __( 'Pegar licencia', 'sistema-educativo' ) ); ?></strong></label>
			<textarea name="edu_license" id="edu_license" rows="4" class="large-text code" placeholder="PS1...."></textarea>
			<p class="description"><?php esc_html_e( 'La licencia la emite PromoWebEc e indica los módulos contratados. Al cambiarla los módulos se activan o desactivan al instante; los datos nunca se borran.', 'sistema-educativo' ); ?></p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar licencia', 'sistema-educativo' ); ?></button>
				<?php if ( $plan_lic ) : ?>
					<button type="submit" name="edu_license_remove" value="1" class="button"><?php esc_html_e( 'Quitar licencia', 'sistema-educativo' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:600px;">
		<?php wp_nonce_field( 'edu_save_settings' ); ?>
		<input type="hidden" name="action" value="edu_save_settings">
		<input type="hidden" name="edu_modules_submitted" value="1">

		<h2><?php esc_html_e( 'Módulos del sistema', 'sistema-educativo' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Habilita o deshabilita módulos completos. Un módulo desactivado desaparece del menú admin y de los portales, y sus procesos automáticos (cron, notificaciones) se detienen. Los datos NO se borran: al reactivar el módulo todo vuelve a estar disponible.', 'sistema-educativo' ); ?>
		</p>
		<table class="form-table">
			<?php
			$modulos_guardados = (array) get_option( Edu_Modules::OPTION, array() );
			foreach ( Edu_Modules::catalog() as $mod_key => $mod ) :
				$mod_activo  = ! array_key_exists( $mod_key, $modulos_guardados ) || ! empty( $modulos_guardados[ $mod_key ] );
				$mod_en_plan = Edu_Modules::in_plan( $mod_key );
			?>
			<tr<?php echo $mod_en_plan ? '' : ' style="opacity:.6;"'; ?>>
				<th><?php echo esc_html( $mod['label'] ); ?></th>
				<td>
					<?php if ( $mod_en_plan ) : ?>
						<label>
							<input type="checkbox" name="edu_modules[<?php echo esc_attr( $mod_key ); ?>]" value="1" <?php checked( $mod_activo ); ?>>
							<?php esc_html_e( 'Activo', 'sistema-educativo' ); ?>
						</label>
					<?php else : ?>
						<label>
							<input type="checkbox" disabled>
							<?php esc_html_e( 'No incluido en su plan — contacte a PromoWebEc', 'sistema-educativo' ); ?>
						</label>
					<?php endif; ?>
					<p class="description"><?php echo esc_html( $mod['desc'] ); ?></p>
				</td>
			</tr>
			<?php endforeach; ?>
		</table>

		<h2><?php esc_html_e( 'Páginas del portal', 'sistema-educativo' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Asigna las páginas de WordPress donde están publicados los shortcodes del plugin. Esto permite que las redirecciones después de entregar tareas, etc., lleven al lugar correcto.', 'sistema-educativo' ); ?>
		</p>

		<table class="form-table">
			<tr>
				<th>
					<label for="edu_tareas_page_id">
						<?php esc_html_e( 'Página de tareas del estudiante', 'sistema-educativo' ); ?>
					</label>
				</th>
				<td>
					<select name="edu_tareas_page_id" id="edu_tareas_page_id">
						<option value="0"><?php esc_html_e( '— Sin asignar —', 'sistema-educativo' ); ?></option>
						<?php foreach ( $pages as $page ) : ?>
							<option value="<?php echo (int) $page->ID; ?>" <?php selected( $tareas_page_id, (int) $page->ID ); ?>>
								<?php echo esc_html( $page->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Página que contiene el shortcode [edu_mis_tareas].', 'sistema-educativo' ); ?>
						<?php if ( $tareas_page_id ) : ?>
							<a href="<?php echo esc_url( get_permalink( $tareas_page_id ) ); ?>" target="_blank">
								<?php esc_html_e( 'Ver página', 'sistema-educativo' ); ?>
							</a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( get_edit_post_link( $tareas_page_id ) ); ?>">
								<?php esc_html_e( 'Editar', 'sistema-educativo' ); ?>
							</a>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="edu_comunicados_page_id">
						<?php esc_html_e( 'Página de comunicados', 'sistema-educativo' ); ?>
					</label>
				</th>
				<td>
					<select name="edu_comunicados_page_id" id="edu_comunicados_page_id">
						<option value="0"><?php esc_html_e( '— Sin asignar —', 'sistema-educativo' ); ?></option>
						<?php foreach ( $pages as $page ) : ?>
							<option value="<?php echo (int) $page->ID; ?>" <?php selected( $comunicados_page_id, (int) $page->ID ); ?>>
								<?php echo esc_html( $page->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Página que contiene el shortcode [edu_mis_comunicados].', 'sistema-educativo' ); ?>
						<?php if ( $comunicados_page_id ) : ?>
							<a href="<?php echo esc_url( get_permalink( $comunicados_page_id ) ); ?>" target="_blank">
								<?php esc_html_e( 'Ver página', 'sistema-educativo' ); ?>
							</a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( get_edit_post_link( $comunicados_page_id ) ); ?>">
								<?php esc_html_e( 'Editar', 'sistema-educativo' ); ?>
							</a>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Páginas de portales (PWA)', 'sistema-educativo' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Asigna las páginas donde se publican los portales. Sirve para inyectar el manifest PWA solo en esas páginas y para los atajos de acceso directo desde el ícono de la app.', 'sistema-educativo' ); ?>
		</p>
		<table class="form-table">
			<?php
			$portales = array(
				'edu_portal_rector_page_id'     => array( __( 'Portal Rector',        'sistema-educativo' ), $rector_page_id,     '[edu_portal_rector]' ),
				'edu_portal_docente_page_id'    => array( __( 'Portal Docente',       'sistema-educativo' ), $docente_page_id,    '[edu_portal_docente]' ),
				'edu_portal_estudiante_page_id' => array( __( 'Portal Estudiante',    'sistema-educativo' ), $estudiante_page_id, '[edu_portal_estudiante]' ),
				'edu_portal_padre_page_id'      => array( __( 'Portal Representante', 'sistema-educativo' ), $padre_page_id,      '[edu_portal_padre]' ),
			);
			foreach ( $portales as $opt => list( $label, $cur_id, $shortcode ) ) : ?>
			<tr>
				<th><label><?php echo esc_html( $label ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( $opt ); ?>">
						<option value="0"><?php esc_html_e( '— Sin asignar —', 'sistema-educativo' ); ?></option>
						<?php foreach ( $pages as $page ) : ?>
							<option value="<?php echo (int) $page->ID; ?>" <?php selected( $cur_id, (int) $page->ID ); ?>>
								<?php echo esc_html( $page->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php printf( esc_html__( 'Página con el shortcode %s', 'sistema-educativo' ), '<code>' . esc_html( $shortcode ) . '</code>' ); ?></p>
				</td>
			</tr>
			<?php endforeach; ?>
		</table>

		<h2><?php esc_html_e( 'Email de envío', 'sistema-educativo' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Configura el remitente que aparece en los emails del plugin. Para mayor entregabilidad, instala un plugin SMTP (WP Mail SMTP, FluentSMTP, etc.).', 'sistema-educativo' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><label for="edu_email_from_name"><?php esc_html_e( 'Nombre del remitente', 'sistema-educativo' ); ?></label></th>
				<td>
					<input type="text" name="edu_email_from_name" id="edu_email_from_name"
						class="regular-text"
						value="<?php echo esc_attr( $email_from_name ); ?>">
					<p class="description"><?php esc_html_e( 'Ej: Instituto Simón Bolívar', 'sistema-educativo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="edu_email_from_address"><?php esc_html_e( 'Dirección de envío', 'sistema-educativo' ); ?></label></th>
				<td>
					<input type="email" name="edu_email_from_address" id="edu_email_from_address"
						class="regular-text"
						value="<?php echo esc_attr( $email_from_address ); ?>">
					<p class="description"><?php esc_html_e( 'Debe coincidir con el dominio configurado en tu servidor SMTP.', 'sistema-educativo' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'WhatsApp Business', 'sistema-educativo' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Configura el proveedor de WhatsApp para enviar notificaciones a padres y estudiantes. Los números de WhatsApp se toman del campo "WhatsApp" del registro de representantes.', 'sistema-educativo' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><label for="edu_wa_provider"><?php esc_html_e( 'Proveedor', 'sistema-educativo' ); ?></label></th>
				<td>
					<select name="edu_wa_provider" id="edu_wa_provider" onchange="eduWaProviderChange(this.value)">
						<option value="disabled" <?php selected( $wa_provider, 'disabled' ); ?>><?php esc_html_e( 'Desactivado', 'sistema-educativo' ); ?></option>
						<option value="twilio"   <?php selected( $wa_provider, 'twilio' ); ?>>Twilio WhatsApp API</option>
						<option value="meta"     <?php selected( $wa_provider, 'meta' ); ?>>Meta WhatsApp Business Cloud</option>
						<option value="waha"     <?php selected( $wa_provider, 'waha' ); ?>><?php esc_html_e( 'WAHA (servidor de PromoWebEc)', 'sistema-educativo' ); ?></option>
					</select>
				</td>
			</tr>
		</table>

		<!-- Credenciales Twilio -->
		<div id="edu-wa-twilio" style="<?php echo 'twilio' === $wa_provider ? '' : 'display:none'; ?>">
			<h3><?php esc_html_e( 'Credenciales Twilio', 'sistema-educativo' ); ?></h3>
			<p class="description" style="margin-bottom:12px;">
				<?php esc_html_e( 'Obtén tus credenciales en console.twilio.com. El número de origen debe tener habilitado el canal WhatsApp.', 'sistema-educativo' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th><label for="edu_wa_twilio_sid"><?php esc_html_e( 'Account SID', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="text" name="edu_wa_twilio_sid" id="edu_wa_twilio_sid" class="regular-text"
							value="<?php echo esc_attr( $wa_twilio_sid ); ?>" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
					</td>
				</tr>
				<tr>
					<th><label for="edu_wa_twilio_token"><?php esc_html_e( 'Auth Token', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="password" name="edu_wa_twilio_token" id="edu_wa_twilio_token" class="regular-text"
							value="<?php echo esc_attr( $wa_twilio_token ); ?>">
					</td>
				</tr>
				<tr>
					<th><label for="edu_wa_twilio_from"><?php esc_html_e( 'Número de origen', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="text" name="edu_wa_twilio_from" id="edu_wa_twilio_from" class="regular-text"
							value="<?php echo esc_attr( $wa_twilio_from ); ?>" placeholder="+14155238886">
						<p class="description"><?php esc_html_e( 'Formato E.164. Para el sandbox usa +14155238886.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Credenciales Meta -->
		<div id="edu-wa-meta" style="<?php echo 'meta' === $wa_provider ? '' : 'display:none'; ?>">
			<h3><?php esc_html_e( 'Credenciales Meta', 'sistema-educativo' ); ?></h3>
			<p class="description" style="margin-bottom:12px;">
				<?php esc_html_e( 'Configura tu aplicación en developers.facebook.com. Necesitas un número verificado y un token de acceso permanente. Los mensajes de texto libre solo funcionan dentro de la ventana de 24 h o con plantillas aprobadas.', 'sistema-educativo' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th><label for="edu_wa_meta_phone_id"><?php esc_html_e( 'Phone Number ID', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="text" name="edu_wa_meta_phone_id" id="edu_wa_meta_phone_id" class="regular-text"
							value="<?php echo esc_attr( $wa_meta_phone_id ); ?>" placeholder="123456789012345">
					</td>
				</tr>
				<tr>
					<th><label for="edu_wa_meta_token"><?php esc_html_e( 'Access Token', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="password" name="edu_wa_meta_token" id="edu_wa_meta_token" class="regular-text"
							value="<?php echo esc_attr( $wa_meta_token ); ?>">
						<p class="description"><?php esc_html_e( 'Token de acceso de larga duración (60 días) o token permanente de sistema.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Plantillas aprobadas (envío proactivo)', 'sistema-educativo' ); ?></h3>
			<p class="description" style="margin-bottom:12px;">
				<?php esc_html_e( 'Ingresa el nombre exacto de cada plantilla aprobada en Meta Business Manager. Si el campo queda vacío, se usará texto libre (solo funciona dentro de la ventana de 24h).', 'sistema-educativo' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th><label for="edu_wa_tpl_comunicado"><?php esc_html_e( 'Plantilla — Comunicados', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="text" name="edu_wa_tpl_comunicado" id="edu_wa_tpl_comunicado" class="regular-text"
							value="<?php echo esc_attr( $wa_tpl_comunicado ); ?>" placeholder="edu_comunicado">
						<p class="description"><?php esc_html_e( 'Parámetros: {{1}} título · {{2}} cuerpo · {{3}} institución', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="edu_wa_tpl_nota"><?php esc_html_e( 'Plantilla — Notas', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="text" name="edu_wa_tpl_nota" id="edu_wa_tpl_nota" class="regular-text"
							value="<?php echo esc_attr( $wa_tpl_nota ); ?>" placeholder="edu_nota_trimestre">
						<p class="description"><?php esc_html_e( 'Parámetros: {{1}} institución · {{2}} estudiante · {{3}} materia · {{4}} trimestre · {{5}} nota', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="edu_wa_tpl_pago"><?php esc_html_e( 'Plantilla — Pagos vencidos', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="text" name="edu_wa_tpl_pago" id="edu_wa_tpl_pago" class="regular-text"
							value="<?php echo esc_attr( $wa_tpl_pago ); ?>" placeholder="edu_pago_vencido">
						<p class="description"><?php esc_html_e( 'Parámetros: {{1}} institución · {{2}} estudiante · {{3}} concepto · {{4}} mes · {{5}} valor · {{6}} fecha vencimiento', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="edu_wa_tpl_asistencia"><?php esc_html_e( 'Plantilla — Asistencia', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="text" name="edu_wa_tpl_asistencia" id="edu_wa_tpl_asistencia" class="regular-text"
							value="<?php echo esc_attr( $wa_tpl_asistencia ); ?>" placeholder="edu_falta_asistencia">
						<p class="description"><?php esc_html_e( 'Parámetros: {{1}} institución · {{2}} estudiante · {{3}} fecha · {{4}} tipo de falta', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="edu_wa_tpl_lang"><?php esc_html_e( 'Idioma de plantillas', 'sistema-educativo' ); ?></label></th>
					<td>
						<select name="edu_wa_tpl_lang" id="edu_wa_tpl_lang">
							<option value="es" <?php selected( $wa_tpl_lang, 'es' ); ?>>Español (es)</option>
							<option value="es_EC" <?php selected( $wa_tpl_lang, 'es_EC' ); ?>>Español Ecuador (es_EC)</option>
							<option value="en_US" <?php selected( $wa_tpl_lang, 'en_US' ); ?>>English (en_US)</option>
						</select>
						<p class="description"><?php esc_html_e( 'Debe coincidir con el idioma seleccionado al crear la plantilla en Meta.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Credenciales WAHA -->
		<div id="edu-wa-waha" style="<?php echo 'waha' === $wa_provider ? '' : 'display:none'; ?>">
			<h3><?php esc_html_e( 'Servidor WAHA', 'sistema-educativo' ); ?></h3>
			<p class="description" style="margin-bottom:12px;">
				<?php esc_html_e( 'Los datos los entrega PromoWebEc. Cada colegio tiene su propia sesión, es decir su propio número, que se empareja escaneando el QR en «Conectar número», más abajo. Los mensajes salen como texto, sin plantillas de Meta.', 'sistema-educativo' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th><label for="edu_wa_waha_url"><?php esc_html_e( 'URL del servidor', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="url" name="edu_wa_waha_url" id="edu_wa_waha_url" class="regular-text"
							value="<?php echo esc_attr( $wa_waha_url ); ?>" placeholder="https://waha.ejemplo.com">
					</td>
				</tr>
				<tr>
					<th><label for="edu_wa_waha_key"><?php esc_html_e( 'API key', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="password" name="edu_wa_waha_key" id="edu_wa_waha_key" class="regular-text"
							value="<?php echo esc_attr( $wa_waha_key ); ?>" autocomplete="new-password">
					</td>
				</tr>
				<tr>
					<th><label for="edu_wa_waha_session"><?php esc_html_e( 'Sesión', 'sistema-educativo' ); ?></label></th>
					<td>
						<input type="text" name="edu_wa_waha_session" id="edu_wa_waha_session" class="regular-text"
							value="<?php echo esc_attr( $wa_waha_session ? $wa_waha_session : $wa_waha_sugerida ); ?>" pattern="[A-Za-z0-9_\-]+">
						<p class="description"><?php esc_html_e( 'Nombre de la sesión de este colegio en WAHA (letras, números, guiones). La sugerida es única para este sitio: no usar la de otro colegio ni la del CRM, o los mensajes saldrían por ese número.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Conectar número', 'sistema-educativo' ); ?></h3>
			<?php if ( ! $wa_waha_listo ) : ?>
				<p class="description"><?php esc_html_e( 'Guarde los ajustes con la URL, la API key y la sesión; después aparece aquí el botón para conectar el número.', 'sistema-educativo' ); ?></p>
			<?php else : ?>
				<div id="edu-waha-panel" style="padding:12px 16px;border:1px solid #dcdcde;background:#fff;max-width:640px;">
					<p style="margin-top:0;">
						<?php esc_html_e( 'Estado:', 'sistema-educativo' ); ?>
						<strong id="edu-waha-estado"><?php esc_html_e( 'consultando…', 'sistema-educativo' ); ?></strong>
					</p>
					<p id="edu-waha-msg" class="description" style="margin:4px 0 10px;"></p>
					<div id="edu-waha-qr-wrap" style="display:none;margin-bottom:12px;">
						<img id="edu-waha-qr" alt="<?php esc_attr_e( 'Código QR de WhatsApp', 'sistema-educativo' ); ?>" style="width:264px;height:264px;border:1px solid #dcdcde;image-rendering:pixelated;">
						<p class="description"><?php esc_html_e( 'En el teléfono del colegio: WhatsApp → Dispositivos vinculados → Vincular un dispositivo, y escanee este código. Se renueva solo.', 'sistema-educativo' ); ?></p>
					</div>
					<button type="button" class="button button-primary" id="edu-waha-conectar" onclick="eduWaha('conectar')"><?php esc_html_e( 'Conectar número', 'sistema-educativo' ); ?></button>
					<button type="button" class="button" id="edu-waha-desconectar" onclick="eduWaha('desconectar')"><?php esc_html_e( 'Desconectar', 'sistema-educativo' ); ?></button>
					<button type="button" class="button" id="edu-waha-revincular" onclick="eduWaha('revincular')"><?php esc_html_e( 'Volver a vincular', 'sistema-educativo' ); ?></button>
				</div>
			<?php endif; ?>
		</div>

		<!-- Estado de la cola -->
		<?php if ( 'disabled' !== $wa_provider ) : ?>
			<?php $wa_cola = Edu_Whatsapp_Queue::resumen(); ?>
			<p style="margin:0 0 16px 10px;">
				<strong><?php esc_html_e( 'Cola de envío:', 'sistema-educativo' ); ?></strong>
				<?php
				printf(
					/* translators: 1: pendientes, 2: enviados hoy, 3: tope diario */
					esc_html__( '%1$d pendientes · %2$d enviados hoy de %3$s', 'sistema-educativo' ),
					(int) $wa_cola['pendientes'],
					(int) $wa_cola['enviados_hoy'],
					$wa_cola['tope'] > 0 ? esc_html( (string) $wa_cola['tope'] ) : esc_html__( 'sin tope', 'sistema-educativo' )
				);
				if ( $wa_cola['fallidos'] || $wa_cola['caducados'] ) {
					echo ' · ';
					printf(
						/* translators: 1: fallidos, 2: caducados */
						esc_html__( 'últimos 7 días: %1$d fallidos, %2$d caducados', 'sistema-educativo' ),
						(int) $wa_cola['fallidos'],
						(int) $wa_cola['caducados']
					);
				}
				?>
				<br><span class="description"><?php esc_html_e( 'Los mensajes salen por lotes cada minuto. El tope diario lo fija el plan contratado; lo que no alcanza a salir hoy sale mañana, y lo que lleva más de 72 horas en cola se descarta.', 'sistema-educativo' ); ?></span>
			</p>
		<?php endif; ?>

		<!-- Botón de prueba de conexión (fuera del form) -->
		<div id="edu-wa-test-wrap" style="<?php echo 'disabled' === $wa_provider ? 'display:none;' : ''; ?>margin-bottom:20px;padding-left:10px;">
			<button type="button" class="button" id="edu-wa-test-btn" onclick="eduWaTestConnection()">
				<?php esc_html_e( 'Probar conexión', 'sistema-educativo' ); ?>
			</button>
			<span id="edu-wa-test-result" style="margin-left:10px;font-style:italic;"></span>
		</div>

		<!-- Eventos WhatsApp -->
		<div id="edu-wa-events" style="<?php echo 'disabled' === $wa_provider ? 'display:none;' : ''; ?>">
			<h3><?php esc_html_e( 'Notificaciones automáticas', 'sistema-educativo' ); ?></h3>
			<p class="description" style="margin-bottom:12px;">
				<?php esc_html_e( 'Selecciona los eventos que enviarán un mensaje de WhatsApp al representante del estudiante.', 'sistema-educativo' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Comunicados', 'sistema-educativo' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="edu_wa_notify_comunicados" value="1" <?php checked( $wa_notify_comunicados, 1 ); ?>>
							<?php esc_html_e( 'Enviar comunicados por WhatsApp cuando el docente/rector marque la opción al crear uno.', 'sistema-educativo' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Notas de trimestre', 'sistema-educativo' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="edu_wa_notify_notas" value="1" <?php checked( $wa_notify_notas, 1 ); ?>>
							<?php esc_html_e( 'Notificar al representante cuando se cierra un trimestre y se calcula la nota final.', 'sistema-educativo' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Pagos vencidos', 'sistema-educativo' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="edu_wa_notify_pagos" value="1" <?php checked( $wa_notify_pagos, 1 ); ?>>
							<?php esc_html_e( 'Notificar cuando un pago cambia a estado "vencido" (cron diario).', 'sistema-educativo' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Faltas de asistencia', 'sistema-educativo' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="edu_wa_notify_asistencia" value="1" <?php checked( $wa_notify_asistencia, 1 ); ?>>
							<?php esc_html_e( 'Notificar al representante cada vez que el docente registra una falta (justificada o injustificada).', 'sistema-educativo' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<h2><?php esc_html_e( 'API REST (app propia)', 'sistema-educativo' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'La API permite que una aplicación propia consuma el sistema. Solo hace falta configurar esto si la app se sirve desde otro dominio.', 'sistema-educativo' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Dirección base', 'sistema-educativo' ); ?></th>
				<td>
					<code><?php echo esc_html( rest_url( 'edu/v1' ) ); ?></code>
					<p class="description"><?php esc_html_e( 'Punto de entrada de la API. Este sitio siempre está autorizado a consumirla.', 'sistema-educativo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="edu_api_allowed_origins"><?php esc_html_e( 'Dominios autorizados', 'sistema-educativo' ); ?></label></th>
				<td>
					<textarea name="edu_api_allowed_origins" id="edu_api_allowed_origins"
						rows="3" class="large-text code"
						placeholder="https://app.miinstitucion.edu.ec"><?php echo esc_textarea( (string) get_option( Edu_Api::ORIGINS_OPTION, '' ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Uno por línea, con https:// y sin barra final. Solo estos dominios podrán consumir la API desde un navegador. No se admiten comodines.', 'sistema-educativo' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Cerrar todas las sesiones', 'sistema-educativo' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="edu_api_rotate_secret" value="1">
						<?php esc_html_e( 'Renovar la clave de firma al guardar', 'sistema-educativo' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Desconecta la app en todos los dispositivos y obliga a volver a iniciar sesión. Úsalo solo si sospechas que una sesión fue comprometida.', 'sistema-educativo' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Guardar ajustes', 'sistema-educativo' ); ?>
			</button>
		</p>
	</form>
</div>

<script>
function eduWaProviderChange(val) {
	document.getElementById('edu-wa-twilio').style.display = val === 'twilio' ? '' : 'none';
	document.getElementById('edu-wa-meta').style.display   = val === 'meta'   ? '' : 'none';
	document.getElementById('edu-wa-waha').style.display   = val === 'waha'   ? '' : 'none';
	var hide = val === 'disabled';
	document.getElementById('edu-wa-test-wrap').style.display  = hide ? 'none' : '';
	document.getElementById('edu-wa-events').style.display     = hide ? 'none' : '';
}
function eduWaTestConnection() {
	var btn = document.getElementById('edu-wa-test-btn');
	var res = document.getElementById('edu-wa-test-result');
	btn.disabled = true;
	res.textContent = '<?php echo esc_js( __( 'Probando…', 'sistema-educativo' ) ); ?>';
	res.style.color = '#555';
	var data = new FormData();
	data.append('action',   'edu_test_whatsapp');
	data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'edu_test_whatsapp' ) ); ?>');
	fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
		.then(r => r.json())
		.then(function(d) {
			res.textContent = d.message;
			res.style.color = d.ok ? '#16a34a' : '#dc2626';
		})
		.catch(function() {
			res.textContent = '<?php echo esc_js( __( 'Error de red.', 'sistema-educativo' ) ); ?>';
			res.style.color = '#dc2626';
		})
		.finally(function() { btn.disabled = false; });
}

/* Panel WAHA: consulta el estado y, mientras espera el escaneo, muestra el QR. */
var eduWahaTimer = null, eduWahaIntentos = 0;
var eduWahaTextos = {
	WORKING:      '<?php echo esc_js( __( 'Conectado', 'sistema-educativo' ) ); ?>',
	SCAN_QR_CODE: '<?php echo esc_js( __( 'Esperando que se escanee el QR', 'sistema-educativo' ) ); ?>',
	STARTING:     '<?php echo esc_js( __( 'Iniciando…', 'sistema-educativo' ) ); ?>',
	STOPPED:      '<?php echo esc_js( __( 'Desconectado', 'sistema-educativo' ) ); ?>',
	FAILED:       '<?php echo esc_js( __( 'Falló — use «Volver a vincular»', 'sistema-educativo' ) ); ?>',
	NO_EXISTE:    '<?php echo esc_js( __( 'Sin conectar todavía', 'sistema-educativo' ) ); ?>'
};
var eduWahaConfirmar = {
	desconectar: '<?php echo esc_js( __( '¿Desconectar el número? Los mensajes dejarán de salir hasta que se vuelva a escanear el QR.', 'sistema-educativo' ) ); ?>',
	revincular:  '<?php echo esc_js( __( '¿Borrar la sesión y crearla de nuevo? Habrá que escanear un QR nuevo.', 'sistema-educativo' ) ); ?>'
};
function eduWahaPost(accion) {
	var data = new FormData();
	data.append('action', 'edu_waha');
	data.append('accion', accion);
	data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'edu_waha' ) ); ?>');
	return fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' }).then(function(r) { return r.json(); });
}
function eduWahaPintar(d) {
	var estado = document.getElementById('edu-waha-estado');
	var msg    = document.getElementById('edu-waha-msg');
	if (!d.ok) {
		msg.textContent = d.message || '';
		msg.style.color = '#dc2626';
		if (d.code !== 'waha_sin_qr') { estado.textContent = '—'; }
		return;
	}
	msg.textContent = '';
	estado.textContent = (eduWahaTextos[d.estado] || d.estado) + (d.numero ? ' · ' + d.numero : '');
	estado.style.color = d.estado === 'WORKING' ? '#16a34a' : '';
	document.getElementById('edu-waha-conectar').style.display   = d.estado === 'WORKING' ? 'none' : '';
	document.getElementById('edu-waha-desconectar').style.display = d.estado === 'WORKING' ? '' : 'none';
	if (d.estado !== 'SCAN_QR_CODE' && d.estado !== 'SCAN_QR') {
		document.getElementById('edu-waha-qr-wrap').style.display = 'none';
	}
}
function eduWahaSeguir() {
	clearTimeout(eduWahaTimer);
	if (++eduWahaIntentos > 60) { return; } // ~3 minutos y se deja de consultar.
	eduWahaPost('estado').then(function(d) {
		eduWahaPintar(d);
		if (!d.ok) { return; }
		if (d.estado === 'SCAN_QR_CODE' || d.estado === 'SCAN_QR') {
			eduWahaPost('qr').then(function(q) {
				if (q.ok && q.qr) {
					document.getElementById('edu-waha-qr').src = q.qr;
					document.getElementById('edu-waha-qr-wrap').style.display = '';
				}
			});
		}
		if (d.estado === 'SCAN_QR_CODE' || d.estado === 'SCAN_QR' || d.estado === 'STARTING') {
			eduWahaTimer = setTimeout(eduWahaSeguir, 3000);
		}
	});
}
function eduWaha(accion) {
	if (eduWahaConfirmar[accion] && !window.confirm(eduWahaConfirmar[accion])) { return; }
	var botones = document.querySelectorAll('#edu-waha-panel button');
	botones.forEach(function(b) { b.disabled = true; });
	document.getElementById('edu-waha-msg').textContent = '<?php echo esc_js( __( 'Procesando…', 'sistema-educativo' ) ); ?>';
	document.getElementById('edu-waha-msg').style.color = '#555';
	eduWahaPost(accion).then(function(d) {
		eduWahaPintar(d);
		if (d.ok) { eduWahaIntentos = 0; eduWahaSeguir(); }
	}).catch(function() {
		document.getElementById('edu-waha-msg').textContent = '<?php echo esc_js( __( 'Error de red.', 'sistema-educativo' ) ); ?>';
	}).finally(function() {
		botones.forEach(function(b) { b.disabled = false; });
	});
}
if (document.getElementById('edu-waha-panel')) { eduWahaSeguir(); }
</script>
