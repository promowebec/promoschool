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
			// Solo se tocan los módulos del plan: los que no incluye llegan
			// deshabilitados (sin valor en el POST) y guardarlos como 0 los
			// dejaría apagados el día que se contraten.
			$mods = (array) get_option( Edu_Modules::OPTION, array() );
			foreach ( array_keys( Edu_Modules::catalog() ) as $mod_key ) {
				if ( Edu_Modules::in_plan( $mod_key ) ) {
					$mods[ $mod_key ] = ! empty( $mods_post[ $mod_key ] ) ? 1 : 0;
				}
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
		$wa_waha_url           = isset( $_POST['edu_wa_waha_url'] )      ? esc_url_raw( untrailingslashit( trim( wp_unslash( $_POST['edu_wa_waha_url'] ) ) ), array( 'http', 'https' ) ) : '';
		$wa_waha_key           = isset( $_POST['edu_wa_waha_key'] )      ? sanitize_text_field( wp_unslash( $_POST['edu_wa_waha_key'] ) )      : '';
		$wa_waha_session       = isset( $_POST['edu_wa_waha_session'] )  ? preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash( $_POST['edu_wa_waha_session'] ) ) : '';
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
		if ( in_array( $wa_provider, array( 'disabled', 'twilio', 'meta', 'waha' ), true ) ) {
			update_option( 'edu_wa_provider', $wa_provider );
		}
		if ( $wa_twilio_sid )    update_option( 'edu_wa_twilio_sid', $wa_twilio_sid );
		if ( $wa_twilio_token )  update_option( 'edu_wa_twilio_token', $wa_twilio_token );
		if ( $wa_twilio_from )   update_option( 'edu_wa_twilio_from', $wa_twilio_from );
		if ( $wa_meta_phone_id ) update_option( 'edu_wa_meta_phone_id', $wa_meta_phone_id );
		if ( $wa_meta_token )    update_option( 'edu_wa_meta_token', $wa_meta_token );
		if ( $wa_waha_url )      update_option( 'edu_wa_waha_url', $wa_waha_url );
		if ( $wa_waha_key )      update_option( 'edu_wa_waha_key', $wa_waha_key );
		if ( $wa_waha_session )  update_option( 'edu_wa_waha_session', $wa_waha_session );
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
	 * Guarda o quita la licencia del plan. Formulario propio en Ajustes.
	 * Acción: admin_post_edu_save_license
	 */
	public static function handle_save_license() {
		check_admin_referer( 'edu_save_license' );

		if ( ! empty( $_POST['edu_license_remove'] ) ) {
			$result = Edu_Plan::quitar_licencia();
		} else {
			$result = Edu_Plan::guardar_licencia(
				array( 'licencia' => isset( $_POST['edu_license'] ) ? sanitize_textarea_field( wp_unslash( $_POST['edu_license'] ) ) : '' )
			);
		}

		if ( is_wp_error( $result ) ) {
			// El motivo concreto (firma, dominio…) no cabe en un código de URL.
			set_transient( 'edu_license_error_' . get_current_user_id(), $result->get_error_message(), 120 );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'edu-ajustes', 'status' => is_wp_error( $result ) ? 'license_error' : 'license_saved' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Aviso en las pantallas del plugin cuando se supera la capacidad
	 * contratada. Solo avisa: la matrícula sigue funcionando.
	 * Acción: admin_notices
	 */
	public static function capacity_notice() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 0 !== strpos( $page, 'edu' ) || ! Edu_Context::can( 'edu_view_all' ) ) {
			return;
		}

		$exceso = Edu_Plan::exceso_capacidad();
		if ( ! $exceso ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: estudiantes activos, 2: estudiantes contratados. */
					__( 'La institución tiene %1$d estudiantes activos y su plan cubre %2$d. Contacte a PromoWebEc para ampliar el plan.', 'sistema-educativo' ),
					$exceso['activos'],
					$exceso['contratados']
				)
			)
		);
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

	/**
	 * AJAX: panel «Conectar número» de WAHA.
	 * Acción: wp_ajax_edu_waha · parámetro `accion`.
	 *
	 * Responde {ok, estado, numero, qr?, message}. Conectar, desconectar y
	 * volver a vincular cambian el número por el que salen los mensajes del
	 * colegio: se auditan.
	 */
	public static function handle_waha(): void {
		check_ajax_referer( 'edu_waha' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'Sin permiso.', 'sistema-educativo' ) ) );
		}

		$accion   = isset( $_POST['accion'] ) ? sanitize_key( wp_unslash( $_POST['accion'] ) ) : '';
		$acciones = array(
			'estado'      => 'waha_estado',
			'conectar'    => 'waha_conectar',
			'qr'          => 'waha_qr',
			'desconectar' => 'waha_desconectar',
			'revincular'  => 'waha_revincular',
		);

		if ( ! isset( $acciones[ $accion ] ) ) {
			wp_send_json( array( 'ok' => false, 'message' => __( 'Acción desconocida.', 'sistema-educativo' ) ) );
		}

		$result = call_user_func( array( 'Edu_Whatsapp', $acciones[ $accion ] ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json(
				array(
					'ok'      => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}

		if ( in_array( $accion, array( 'conectar', 'desconectar', 'revincular' ), true ) ) {
			Edu_Audit::log(
				Edu_Audit::WHATSAPP_SESION,
				'whatsapp',
				null,
				null,
				array(
					'accion' => $accion,
					'sesion' => (string) get_option( 'edu_wa_waha_session', '' ),
					'estado' => $result['estado'] ?? '',
				)
			);
		}

		wp_send_json( array_merge( array( 'ok' => true ), $result ) );
	}
}
