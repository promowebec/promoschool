<?php
/**
 * Abstracción de envío WhatsApp.
 *
 * Soporta dos proveedores:
 *   - Twilio WhatsApp API
 *   - Meta WhatsApp Business Cloud API
 *
 * Uso:
 *   Edu_Whatsapp::send( '+593999123456', 'Texto del mensaje' );
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Whatsapp {

	/**
	 * Normaliza un número telefónico a formato E.164 con prefijo Ecuador (+593).
	 *
	 * '0999123456'  → '+593999123456'
	 * '593999123456'→ '+593999123456'
	 * '+593999123456'→ '+593999123456'
	 */
	public static function normalize_number( string $number ): string {
		$number = preg_replace( '/[^0-9+]/', '', $number );
		if ( '' === $number ) {
			return '';
		}
		if ( str_starts_with( $number, '+' ) ) {
			return $number;
		}
		if ( str_starts_with( $number, '0' ) ) {
			return '+593' . substr( $number, 1 );
		}
		if ( str_starts_with( $number, '593' ) ) {
			return '+' . $number;
		}
		return '+' . $number;
	}

	/**
	 * Envía un mensaje de WhatsApp.
	 *
	 * @param string $to      Número del destinatario (se normaliza a E.164).
	 * @param string $message Texto del mensaje.
	 * @return bool           true si la API aceptó el mensaje.
	 */
	public static function send( string $to, string $message ): bool {
		$provider = (string) get_option( 'edu_wa_provider', 'disabled' );

		if ( 'disabled' === $provider || '' === $provider ) {
			return false;
		}

		$to = self::normalize_number( $to );
		if ( '' === $to ) {
			return false;
		}

		if ( 'twilio' === $provider ) {
			return self::send_twilio( $to, $message );
		}

		if ( 'meta' === $provider ) {
			return self::send_meta( $to, $message );
		}

		return false;
	}

	/**
	 * Verifica la conexión con el proveedor configurado.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function test_connection(): array {
		$provider = (string) get_option( 'edu_wa_provider', 'disabled' );

		if ( 'disabled' === $provider ) {
			return array(
				'ok'      => false,
				'message' => __( 'WhatsApp está desactivado. Selecciona un proveedor primero.', 'sistema-educativo' ),
			);
		}

		if ( 'twilio' === $provider ) {
			return self::test_twilio();
		}

		if ( 'meta' === $provider ) {
			return self::test_meta();
		}

		return array( 'ok' => false, 'message' => __( 'Proveedor desconocido.', 'sistema-educativo' ) );
	}

	/**
	 * Envía un mensaje usando una plantilla aprobada de Meta.
	 *
	 * Solo funciona con el proveedor 'meta'. Para Twilio, usa send() con texto.
	 *
	 * @param string   $to            Número del destinatario (se normaliza).
	 * @param string   $template_name Nombre exacto de la plantilla en Meta Business Manager.
	 * @param string[] $params        Valores de los parámetros {{1}}, {{2}}, … en orden.
	 * @param string   $lang          Código de idioma (por defecto 'es').
	 * @return bool
	 */
	public static function send_template( string $to, string $template_name, array $params, string $lang = 'es' ): bool {
		if ( 'meta' !== (string) get_option( 'edu_wa_provider', 'disabled' ) ) {
			return false;
		}

		$to = self::normalize_number( $to );
		if ( '' === $to || '' === $template_name ) {
			return false;
		}

		return self::send_template_meta( $to, $template_name, $params, $lang );
	}

	/* -----------------------------------------------------------------------
	 * Drivers de envío privados
	 * -------------------------------------------------------------------- */

	/**
	 * Twilio WhatsApp API.
	 * Docs: https://www.twilio.com/docs/whatsapp/quickstart
	 */
	private static function send_twilio( string $to, string $message ): bool {
		$sid   = (string) get_option( 'edu_wa_twilio_sid', '' );
		$token = (string) get_option( 'edu_wa_twilio_token', '' );
		$from  = (string) get_option( 'edu_wa_twilio_from', '' );

		if ( ! $sid || ! $token || ! $from ) {
			return false;
		}

		$response = wp_remote_post(
			"https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'From' => 'whatsapp:' . $from,
					'To'   => 'whatsapp:' . $to,
					'Body' => $message,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Meta WhatsApp Business Cloud API.
	 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api/messages/text-messages
	 * Nota: requiere que el destinatario haya iniciado una conversación en las últimas 24 h
	 *       o usar plantillas aprobadas por Meta.
	 */
	private static function send_meta( string $to, string $message ): bool {
		$phone_id = (string) get_option( 'edu_wa_meta_phone_id', '' );
		$token    = (string) get_option( 'edu_wa_meta_token', '' );

		if ( ! $phone_id || ! $token ) {
			return false;
		}

		// Meta espera el número sin el signo '+'.
		$to_meta = ltrim( $to, '+' );

		$response = wp_remote_post(
			"https://graph.facebook.com/v19.0/{$phone_id}/messages",
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'recipient_type'    => 'individual',
						'to'                => $to_meta,
						'type'              => 'text',
						'text'              => array(
							'preview_url' => false,
							'body'        => $message,
						),
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Envía una plantilla aprobada via Meta Cloud API.
	 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api/messages/template-messages
	 */
	private static function send_template_meta( string $to, string $template_name, array $params, string $lang ): bool {
		$phone_id = (string) get_option( 'edu_wa_meta_phone_id', '' );
		$token    = (string) get_option( 'edu_wa_meta_token', '' );

		if ( ! $phone_id || ! $token ) {
			return false;
		}

		$to_meta    = ltrim( $to, '+' );
		$parameters = array_map(
			static function ( $value ) {
				return array( 'type' => 'text', 'text' => (string) $value );
			},
			array_values( $params )
		);

		$payload = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to_meta,
			'type'              => 'template',
			'template'          => array(
				'name'       => $template_name,
				'language'   => array( 'code' => $lang ),
				'components' => array(
					array(
						'type'       => 'body',
						'parameters' => $parameters,
					),
				),
			),
		);

		$response = wp_remote_post(
			"https://graph.facebook.com/v19.0/{$phone_id}/messages",
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/* -----------------------------------------------------------------------
	 * Tests de conexión privados
	 * -------------------------------------------------------------------- */

	private static function test_twilio(): array {
		$sid   = (string) get_option( 'edu_wa_twilio_sid', '' );
		$token = (string) get_option( 'edu_wa_twilio_token', '' );
		$from  = (string) get_option( 'edu_wa_twilio_from', '' );

		if ( ! $sid || ! $token || ! $from ) {
			return array( 'ok' => false, 'message' => __( 'Completa SID, Token y número de origen Twilio.', 'sistema-educativo' ) );
		}

		$response = wp_remote_get(
			"https://api.twilio.com/2010-04-01/Accounts/{$sid}.json",
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array( 'ok' => true, 'message' => __( 'Conexión Twilio exitosa ✓', 'sistema-educativo' ) );
		}

		return array(
			'ok'      => false,
			/* translators: %d: código HTTP de respuesta */
			'message' => sprintf( __( 'Twilio respondió HTTP %d. Verifica tus credenciales.', 'sistema-educativo' ), $code ),
		);
	}

	private static function test_meta(): array {
		$phone_id = (string) get_option( 'edu_wa_meta_phone_id', '' );
		$token    = (string) get_option( 'edu_wa_meta_token', '' );

		if ( ! $phone_id || ! $token ) {
			return array( 'ok' => false, 'message' => __( 'Completa Phone Number ID y Access Token de Meta.', 'sistema-educativo' ) );
		}

		$response = wp_remote_get(
			"https://graph.facebook.com/v19.0/{$phone_id}?fields=display_phone_number,verified_name",
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$num  = $body['display_phone_number'] ?? '';
			/* translators: %s: número de teléfono verificado */
			return array( 'ok' => true, 'message' => sprintf( __( 'Conexión Meta exitosa ✓ Número: %s', 'sistema-educativo' ), $num ) );
		}

		return array(
			'ok'      => false,
			/* translators: %d: código HTTP de respuesta */
			'message' => sprintf( __( 'Meta respondió HTTP %d. Verifica tu token y Phone Number ID.', 'sistema-educativo' ), $code ),
		);
	}
}
