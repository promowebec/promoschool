<?php
/**
 * Controller: ajustes generales del plugin.
 *
 * Guarda opciones globales en wp_options con prefijo edu_.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Settings_Controller {

	public static function handle_save() {
		check_admin_referer( 'edu_save_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		// Módulos del sistema: checkbox sin marcar no viaja en el POST, por eso
		// se recorre el catálogo completo y solo se guarda si la sección se envió.
		if ( ! empty( $_POST['edu_modules_submitted'] ) ) {
			$mods_post = isset( $_POST['edu_modules'] ) && is_array( $_POST['edu_modules'] )
				? array_map( 'absint', wp_unslash( $_POST['edu_modules'] ) )
				: array();
			$mods = array();
			foreach ( array_keys( Edu_Modules::catalog() ) as $mod_key ) {
				$mods[ $mod_key ] = ! empty( $mods_post[ $mod_key ] ) ? 1 : 0;
			}
			update_option( Edu_Modules::OPTION, $mods );
		}

		$tareas_page_id      = isset( $_POST['edu_tareas_page_id'] ) ? (int) $_POST['edu_tareas_page_id'] : 0;
		$comunicados_page_id = isset( $_POST['edu_comunicados_page_id'] ) ? (int) $_POST['edu_comunicados_page_id'] : 0;
		$rector_page_id      = isset( $_POST['edu_portal_rector_page_id'] )     ? (int) $_POST['edu_portal_rector_page_id']     : 0;
		$docente_page_id     = isset( $_POST['edu_portal_docente_page_id'] )    ? (int) $_POST['edu_portal_docente_page_id']    : 0;
		$estudiante_page_id  = isset( $_POST['edu_portal_estudiante_page_id'] ) ? (int) $_POST['edu_portal_estudiante_page_id'] : 0;
		$padre_page_id       = isset( $_POST['edu_portal_padre_page_id'] )      ? (int) $_POST['edu_portal_padre_page_id']      : 0;
		$email_from_name     = isset( $_POST['edu_email_from_name'] )
			? sanitize_text_field( wp_unslash( $_POST['edu_email_from_name'] ) )
			: '';
		$email_from_address  = isset( $_POST['edu_email_from_address'] )
			? sanitize_email( wp_unslash( $_POST['edu_email_from_address'] ) )
			: '';

		// WhatsApp.
		$wa_provider           = isset( $_POST['edu_wa_provider'] ) ? sanitize_key( wp_unslash( $_POST['edu_wa_provider'] ) ) : 'disabled';
		$wa_twilio_sid         = isset( $_POST['edu_wa_twilio_sid'] )    ? sanitize_text_field( wp_unslash( $_POST['edu_wa_twilio_sid'] ) )    : '';
		$wa_twilio_token       = isset( $_POST['edu_wa_twilio_token'] )  ? sanitize_text_field( wp_unslash( $_POST['edu_wa_twilio_token'] ) )  : '';
		$wa_twilio_from        = isset( $_POST['edu_wa_twilio_from'] )   ? sanitize_text_field( wp_unslash( $_POST['edu_wa_twilio_from'] ) )   : '';
		$wa_meta_phone_id      = isset( $_POST['edu_wa_meta_phone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edu_wa_meta_phone_id'] ) ) : '';
		$wa_meta_token         = isset( $_POST['edu_wa_meta_token'] )    ? sanitize_text_field( wp_unslash( $_POST['edu_wa_meta_token'] ) )    : '';
		$wa_notify_comunicados = ! empty( $_POST['edu_wa_notify_comunicados'] ) ? 1 : 0;
		$wa_notify_notas       = ! empty( $_POST['edu_wa_notify_notas'] )       ? 1 : 0;
		$wa_notify_pagos       = ! empty( $_POST['edu_wa_notify_pagos'] )       ? 1 : 0;
		$wa_notify_asistencia  = ! empty( $_POST['edu_wa_notify_asistencia'] )  ? 1 : 0;

		update_option( 'edu_tareas_page_id', $tareas_page_id );
		update_option( 'edu_comunicados_page_id', $comunicados_page_id );
		update_option( 'edu_portal_rector_page_id',     $rector_page_id );
		update_option( 'edu_portal_docente_page_id',    $docente_page_id );
		update_option( 'edu_portal_estudiante_page_id', $estudiante_page_id );
		update_option( 'edu_portal_padre_page_id',      $padre_page_id );
		if ( $email_from_name )    update_option( 'edu_email_from_name', $email_from_name );
		if ( $email_from_address ) update_option( 'edu_email_from_address', $email_from_address );

		// WhatsApp: proveedor y credenciales.
		if ( in_array( $wa_provider, array( 'disabled', 'twilio', 'meta' ), true ) ) {
			update_option( 'edu_wa_provider', $wa_provider );
		}
		if ( $wa_twilio_sid )    update_option( 'edu_wa_twilio_sid', $wa_twilio_sid );
		if ( $wa_twilio_token )  update_option( 'edu_wa_twilio_token', $wa_twilio_token );
		if ( $wa_twilio_from )   update_option( 'edu_wa_twilio_from', $wa_twilio_from );
		if ( $wa_meta_phone_id ) update_option( 'edu_wa_meta_phone_id', $wa_meta_phone_id );
		if ( $wa_meta_token )    update_option( 'edu_wa_meta_token', $wa_meta_token );
		update_option( 'edu_wa_notify_comunicados', $wa_notify_comunicados );
		update_option( 'edu_wa_notify_notas',       $wa_notify_notas );
		update_option( 'edu_wa_notify_pagos',       $wa_notify_pagos );
		update_option( 'edu_wa_notify_asistencia',  $wa_notify_asistencia );

		// Plantillas Meta.
		$wa_tpl_comunicado = isset( $_POST['edu_wa_tpl_comunicado'] ) ? sanitize_text_field( wp_unslash( $_POST['edu_wa_tpl_comunicado'] ) ) : '';
		$wa_tpl_nota       = isset( $_POST['edu_wa_tpl_nota'] )       ? sanitize_text_field( wp_unslash( $_POST['edu_wa_tpl_nota'] ) )       : '';
		$wa_tpl_pago       = isset( $_POST['edu_wa_tpl_pago'] )       ? sanitize_text_field( wp_unslash( $_POST['edu_wa_tpl_pago'] ) )       : '';
		$wa_tpl_asistencia = isset( $_POST['edu_wa_tpl_asistencia'] ) ? sanitize_text_field( wp_unslash( $_POST['edu_wa_tpl_asistencia'] ) ) : '';
		$wa_tpl_lang       = isset( $_POST['edu_wa_tpl_lang'] )       ? sanitize_text_field( wp_unslash( $_POST['edu_wa_tpl_lang'] ) )       : 'es';
		update_option( 'edu_wa_tpl_comunicado', $wa_tpl_comunicado );
		update_option( 'edu_wa_tpl_nota',       $wa_tpl_nota );
		update_option( 'edu_wa_tpl_pago',       $wa_tpl_pago );
		update_option( 'edu_wa_tpl_asistencia', $wa_tpl_asistencia );
		update_option( 'edu_wa_tpl_lang',       $wa_tpl_lang );

		// API REST: orígenes permitidos para CORS (uno por línea).
		if ( isset( $_POST['edu_api_allowed_origins'] ) ) {
			$origins_raw = sanitize_textarea_field( wp_unslash( $_POST['edu_api_allowed_origins'] ) );
			$origins     = array();

			foreach ( preg_split( '/[\r\n,]+/', $origins_raw ) as $origin ) {
				$origin = untrailingslashit( trim( $origin ) );
				if ( '' === $origin ) {
					continue;
				}
				// Solo orígenes con esquema: nunca comodines.
				if ( preg_match( '#^https?://[^/\s]+$#i', $origin ) ) {
					$origins[] = strtolower( $origin );
				}
			}

			update_option( Edu_Api::ORIGINS_OPTION, implode( "\n", array_unique( $origins ) ) );
		}

		// Rotación del secreto de firma: cierra TODAS las sesiones de la API.
		if ( ! empty( $_POST['edu_api_rotate_secret'] ) ) {
			Edu_Api_Jwt::rotate_secret();
			Edu_Audit::log( 'api_secreto_rotado', 'api', null, null, array( 'canal' => 'admin' ) );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'edu-ajustes', 'status' => 'updated' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * AJAX: prueba la conexión con el proveedor WhatsApp configurado.
	 * Acción: wp_ajax_edu_test_whatsapp
	 */
	public static function handle_test_whatsapp(): void {
		check_ajax_referer( 'edu_test_whatsapp' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'Sin permiso.', 'sistema-educativo' ) ) );
		}

		$result = Edu_Whatsapp::test_connection();
		wp_send_json( $result );
	}
}
