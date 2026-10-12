<?php
/**
 * Abstracción de envío WhatsApp.
 *
 * Soporta tres proveedores:
 *   - Twilio WhatsApp API
 *   - Meta WhatsApp Business Cloud API
 *   - WAHA (WhatsApp HTTP API, https://waha.devlike.pro): el servidor que
 *     hostea la agencia, el mismo del CRM. Cada colegio usa su propia sesión
 *     (su número, emparejado por QR desde Ajustes → «Conectar número»).
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

		if ( 'waha' === $provider ) {
			return self::send_waha( $to, $message );
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

		if ( 'waha' === $provider ) {
			return self::test_waha();
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
	 * Sesión WAHA: el panel «Conectar número» de Ajustes
	 *
	 * Mismo ciclo que el conector del CRM (crm_agencia, WahaProvider): el
	 * servidor WAHA no tiene un panel a la vista, así que emparejar, consultar
	 * y desconectar el número del colegio se hace desde aquí. Devuelven array
	 * o WP_Error, sin echo: el adaptador es el AJAX de Ajustes.
	 * -------------------------------------------------------------------- */

	/** Estados de WAHA en los que la sesión ya está arrancada. */
	const WAHA_ARRANCADA = array( 'STARTING', 'SCAN_QR_CODE', 'SCAN_QR', 'WORKING' );

	/**
	 * Estado de la sesión del colegio.
	 *
	 * @return array{estado: string, numero: string}|WP_Error estado es el de
	 *         WAHA (WORKING, SCAN_QR_CODE, STOPPED, FAILED…) o NO_EXISTE.
	 */
	public static function waha_estado() {
		$session = self::waha_sesion();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$response = self::waha_request( 'GET', '/api/sessions/' . rawurlencode( $session ) );
		$code     = self::waha_code( $response );

		if ( is_wp_error( $code ) ) {
			return $code;
		}
		if ( 404 === $code ) {
			return array( 'estado' => 'NO_EXISTE', 'numero' => '' );
		}
		if ( 200 !== $code ) {
			return self::waha_error_http( $code );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();
		$me   = is_array( $body['me'] ?? null ) ? $body['me'] : array();
		$num  = preg_replace( '/\D/', '', (string) strtok( (string) ( $me['id'] ?? '' ), '@' ) );

		return array(
			'estado' => (string) ( $body['status'] ?? '' ),
			'numero' => $num ? '+' . $num : '',
		);
	}

	/**
	 * Crea la sesión si no existe y la arranca. Después se pide el QR.
	 *
	 * WAHA solo arranca sesiones detenidas: una FAILED o a medio arrancar
	 * rebota el /start, así que primero se detiene (lo aprendió el CRM).
	 *
	 * @return array|WP_Error El estado tras arrancar.
	 */
	public static function waha_conectar() {
		$session = self::waha_sesion();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		// Sin webhook: PromoSchool solo envía, no recibe mensajes.
		$creada = self::waha_code(
			self::waha_request( 'POST', '/api/sessions', array( 'name' => $session, 'start' => true ) )
		);
		if ( is_wp_error( $creada ) ) {
			return $creada;
		}
		if ( 401 === $creada || 403 === $creada ) {
			return self::waha_error_http( $creada );
		}

		if ( $creada >= 300 ) {
			// Ya existía: se reinicia, salvo que ya esté conectada.
			$estado = self::waha_estado();
			if ( is_wp_error( $estado ) || 'WORKING' === $estado['estado'] ) {
				return $estado;
			}
			$base = '/api/sessions/' . rawurlencode( $session );
			if ( 'STOPPED' !== $estado['estado'] ) {
				self::waha_request( 'POST', $base . '/stop' );
			}
			$code = self::waha_code( self::waha_request( 'POST', $base . '/start' ) );
			if ( is_wp_error( $code ) ) {
				return $code;
			}
		}

		$estado = self::waha_estado();
		if ( ! is_wp_error( $estado ) && ! in_array( $estado['estado'], self::WAHA_ARRANCADA, true ) ) {
			return new WP_Error(
				'waha_no_arranca',
				/* translators: %s: estado según WAHA */
				sprintf( __( 'WAHA no pudo arrancar la sesión (estado: %s). Pruebe «Volver a vincular».', 'sistema-educativo' ), $estado['estado'] ? $estado['estado'] : '?' )
			);
		}

		return $estado;
	}

	/**
	 * QR de emparejamiento como data URI, listo para un <img>.
	 *
	 * @return array{qr: string}|WP_Error
	 */
	public static function waha_qr() {
		$session = self::waha_sesion();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$response = self::waha_request( 'GET', '/api/' . rawurlencode( $session ) . '/auth/qr', null, 15, array( 'Accept' => 'image/png' ) );
		$code     = self::waha_code( $response );
		$no_listo = new WP_Error( 'waha_sin_qr', __( 'El QR todavía no está listo. Se vuelve a intentar en unos segundos.', 'sistema-educativo' ) );

		if ( is_wp_error( $code ) ) {
			return $code;
		}
		if ( 200 !== $code ) {
			return $no_listo;
		}

		$tipo = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body = (string) wp_remote_retrieve_body( $response );

		// Algunas versiones contestan JSON {mimetype, data} aunque se pida PNG.
		if ( false !== stripos( $tipo, 'json' ) ) {
			$json = json_decode( $body, true );
			if ( ! is_array( $json ) || empty( $json['data'] ) ) {
				return $no_listo;
			}
			$mime = preg_match( '#^image/[a-z]+$#', (string) ( $json['mimetype'] ?? '' ) ) ? $json['mimetype'] : 'image/png';
			return array( 'qr' => 'data:' . $mime . ';base64,' . preg_replace( '#[^A-Za-z0-9+/=]#', '', (string) $json['data'] ) );
		}

		if ( '' === $body ) {
			return $no_listo;
		}

		return array( 'qr' => 'data:image/png;base64,' . base64_encode( $body ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Desempareja el número y deja la sesión detenida.
	 *
	 * @return array|WP_Error
	 */
	public static function waha_desconectar() {
		$session = self::waha_sesion();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$base = '/api/sessions/' . rawurlencode( $session );
		$code = self::waha_code( self::waha_request( 'POST', $base . '/logout' ) );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		self::waha_request( 'POST', $base . '/stop' );

		return self::waha_estado();
	}

	/**
	 * Borra la sesión y la crea de cero, con QR nuevo. Es para la sesión que
	 * vuelve a FAILED apenas se arranca: lo roto son sus datos de emparejamiento.
	 *
	 * @return array|WP_Error
	 */
	public static function waha_revincular() {
		$session = self::waha_sesion();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$base = '/api/sessions/' . rawurlencode( $session );
		// Cada paso puede sobrar (ya deslogueada, ya detenida): cuenta el final.
		self::waha_request( 'POST', $base . '/logout' );
		self::waha_request( 'POST', $base . '/stop' );
		self::waha_request( 'DELETE', $base );

		return self::waha_conectar();
	}

	/** La sesión configurada, o error si falta la URL o la sesión. */
	private static function waha_sesion() {
		$session = (string) get_option( 'edu_wa_waha_session', '' );

		if ( '' === (string) get_option( 'edu_wa_waha_url', '' ) || '' === $session ) {
			return new WP_Error( 'waha_sin_config', __( 'Guarde primero la URL del servidor WAHA y el nombre de la sesión.', 'sistema-educativo' ) );
		}

		return $session;
	}

	/** Código HTTP de una respuesta, o el WP_Error de conexión. */
	private static function waha_code( $response ) {
		if ( is_wp_error( $response ) ) {
			/* translators: %s: detalle del error de conexión */
			return new WP_Error( 'waha_conexion', sprintf( __( 'No se pudo conectar con el servidor WAHA: %s', 'sistema-educativo' ), $response->get_error_message() ) );
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	private static function waha_error_http( int $code ): WP_Error {
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'waha_key', __( 'WAHA rechazó la API key.', 'sistema-educativo' ) );
		}

		/* translators: %d: código HTTP de respuesta */
		return new WP_Error( 'waha_http', sprintf( __( 'WAHA respondió HTTP %d.', 'sistema-educativo' ), $code ) );
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
	 * WAHA. Docs: https://waha.devlike.pro/docs/how-to/send-messages/
	 *
	 * El timeout es largo a propósito: WhatsApp demora la primera vez que se le
	 * escribe a un contacto, y en el CRM un envío con 15 s quedaba marcado como
	 * fallido aunque el mensaje llegaba igual minutos después.
	 */
	private static function send_waha( string $to, string $message ): bool {
		$session = (string) get_option( 'edu_wa_waha_session', '' );

		if ( '' === $session ) {
			return false;
		}

		$response = self::waha_request(
			'POST',
			'/api/sendText',
			array(
				'session' => $session,
				// '+593999123456' → '593999123456@c.us' (chatId de WAHA).
				'chatId'  => ltrim( $to, '+' ) . '@c.us',
				'text'    => $message,
			),
			60
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Petición al servidor WAHA con su API key. Devuelve la respuesta de
	 * wp_remote_request() o un WP_Error si falta la URL.
	 *
	 * @param string     $method  Método HTTP.
	 * @param string     $path    Ruta desde la raíz del servidor ('/api/...').
	 * @param array|null $body    Cuerpo JSON.
	 * @param int        $timeout Segundos.
	 * @param array      $extra_headers Cabeceras adicionales (p. ej. Accept).
	 * @return array|WP_Error
	 */
	private static function waha_request( string $method, string $path, ?array $body = null, int $timeout = 10, array $extra_headers = array() ) {
		$base = untrailingslashit( (string) get_option( 'edu_wa_waha_url', '' ) );
		$key  = (string) get_option( 'edu_wa_waha_key', '' );

		if ( '' === $base ) {
			return new WP_Error( 'waha_no_url', __( 'Falta la URL del servidor WAHA.', 'sistema-educativo' ) );
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $key ) {
			$headers['X-Api-Key'] = $key;
		}
		$headers = array_merge( $headers, $extra_headers );

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		return wp_remote_request( $base . $path, $args );
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

	/**
	 * Consulta la sesión del colegio: tiene que existir y estar en WORKING
	 * (número emparejado). No envía ningún mensaje.
	 */
	private static function test_waha(): array {
		$session = (string) get_option( 'edu_wa_waha_session', '' );

		if ( '' === (string) get_option( 'edu_wa_waha_url', '' ) || '' === $session ) {
			return array( 'ok' => false, 'message' => __( 'Completa la URL del servidor WAHA y el nombre de la sesión.', 'sistema-educativo' ) );
		}

		$response = self::waha_request( 'GET', '/api/sessions/' . rawurlencode( $session ) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return array( 'ok' => false, 'message' => __( 'WAHA rechazó la API key.', 'sistema-educativo' ) );
		}

		if ( 404 === $code ) {
			/* translators: %s: nombre de la sesión */
			return array( 'ok' => false, 'message' => sprintf( __( 'La sesión «%s» no existe en el servidor WAHA. Use «Conectar número» en esta misma pantalla.', 'sistema-educativo' ), $session ) );
		}

		if ( 200 !== $code ) {
			/* translators: %d: código HTTP de respuesta */
			return array( 'ok' => false, 'message' => sprintf( __( 'WAHA respondió HTTP %d.', 'sistema-educativo' ), $code ) );
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$status = is_array( $body ) ? (string) ( $body['status'] ?? '' ) : '';

		if ( 'WORKING' !== $status ) {
			/* translators: %s: estado de la sesión según WAHA (SCAN_QR_CODE, STOPPED, FAILED…) */
			return array( 'ok' => false, 'message' => sprintf( __( 'La sesión existe pero no está conectada (estado: %s). Use «Conectar número» en esta misma pantalla.', 'sistema-educativo' ), $status ?: '?' ) );
		}

		// 'me.id' = '593991234567@c.us'.
		$me  = is_array( $body['me'] ?? null ) ? $body['me'] : array();
		$num = preg_replace( '/\D/', '', strtok( (string) ( $me['id'] ?? '' ), '@' ) );

		/* translators: %s: número emparejado */
		return array( 'ok' => true, 'message' => sprintf( __( 'Conexión WAHA exitosa ✓ Número: %s', 'sistema-educativo' ), $num ? '+' . $num : '—' ) );
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
