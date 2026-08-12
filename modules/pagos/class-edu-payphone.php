<?php
/**
 * Integración con Payphone — gateway de pago de Ecuador.
 *
 * Payphone API v2:
 *   POST /api/button/Charge        → crea intención de pago, devuelve paymentUrl.
 *   POST /api/button/V2/Confirm    → confirma transacción tras retorno del usuario.
 *   responseUrl (webhook)          → Payphone notifica cuando el pago se procesa.
 *
 * Credenciales en wp_options:
 *   edu_payphone_token    → Bearer token de la cuenta Payphone.
 *   edu_payphone_store_id → ID de la tienda / botón de pago.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Payphone {

	const API_BASE   = 'https://pay.payphonetodoesposible.com/api';
	const LINKS_PATH = '/Links';

	/**
	 * Genera un clientTransactionId único de máximo 15 caracteres.
	 * Formato: "ep" + payment_id (máx 7 dígitos) + 6 chars aleatorios.
	 * El sufijo aleatorio evita que un tercero prediga el ID de otra transacción.
	 */
	public static function make_txn_id( int $payment_id ): string {
		$pid = (string) $payment_id;
		// "ep" (2) + payment_id (máx 7) + sufijo aleatorio (6) = máx 15 chars.
		$tail = strtolower( wp_generate_password( 6, false, false ) );
		return 'ep' . substr( $pid, 0, 7 ) . $tail;
	}

	/**
	 * Crea un link de pago (API Link) y devuelve la URL corta de Payphone.
	 *
	 * Endpoint: POST /api/Links
	 * Respuesta: URL corta directa como string (ej: https://payp.page.link/aYu55)
	 *
	 * Nota: responseUrl y cancellationUrl se configuran en el portal de Payphone,
	 * NO se pasan por API en el tipo WEB.
	 *
	 * @param float  $amount         Monto en USD.
	 * @param string $client_txn_id  Máx 15 caracteres. Usar make_txn_id().
	 * @param string $reference      Descripción del pago (máx 100 chars).
	 * @return string|\WP_Error      URL de pago o WP_Error.
	 */
	public static function create_payment(
		float $amount,
		string $client_txn_id,
		string $reference
	): string|\WP_Error {
		$token    = trim( get_option( 'edu_payphone_token', '' ) );
		$store_id = trim( get_option( 'edu_payphone_store_id', '' ) );

		if ( ! $token || ! $store_id ) {
			return new \WP_Error(
				'payphone_not_configured',
				__( 'Payphone no está configurado. Contacta al administrador.', 'sistema-educativo' )
			);
		}

		// clientTransactionId: máx 15 caracteres.
		$client_txn_id = substr( $client_txn_id, 0, 15 );

		// Payphone trabaja en centavos (enteros).
		$cents = (int) round( $amount * 100 );

		$body = array(
			'amount'              => $cents,
			'amountWithoutTax'    => $cents,
			'amountWithTax'       => 0,
			'tax'                 => 0,
			'service'             => 0,
			'tip'                 => 0,
			'currency'            => 'USD',
			'storeId'             => $store_id,
			'reference'           => substr( $reference, 0, 100 ),
			'clientTransactionId' => $client_txn_id,
			'oneTime'             => true,
			'expireIn'            => 0,
		);

		$response = wp_remote_post(
			self::API_BASE . self::LINKS_PATH,
			array(
				'headers' => array(
					'Authorization' => 'bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		// Respuesta exitosa: URL directa como string (ej: "https://payp.page.link/aYu55").
		if ( 200 === $code && ! empty( $raw ) ) {
			$url = trim( $raw, " \t\n\r\0\x0B\"" );
			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return $url;
			}
		}

		// Error: Payphone devuelve JSON con message y errorCode.
		$data   = json_decode( $raw, true );
		$detail = $data ? ( ' | ' . wp_json_encode( $data ) ) : ( ' | Body: ' . substr( $raw, 0, 300 ) );
		$msg    = ( $data['message'] ?? '' ) ?: ( 'Error HTTP ' . $code );
		return new \WP_Error( 'payphone_api_error', $msg . $detail );
	}

	/**
	 * Prueba las credenciales haciendo una llamada mínima a Payphone.
	 * Devuelve array con 'ok' (bool) y 'message' (string).
	 */
	public static function test_connection(): array {
		$token    = trim( get_option( 'edu_payphone_token', '' ) );
		$store_id = trim( get_option( 'edu_payphone_store_id', '' ) );

		if ( ! $token || ! $store_id ) {
			return array( 'ok' => false, 'message' => 'Token o Store ID vacío en la configuración.' );
		}

		// Body correcto para /api/Links:
		// - storeId como string
		// - clientTransactionId máx 15 chars: "edt" (3) + últimos 10 del timestamp = 13 chars
		// - sin responseUrl ni cancellationUrl (se configuran en portal Payphone)
		$txn_id = 'edt' . substr( (string) time(), -10 );  // 13 chars.
		$body = array(
			'amount'              => 100,  // $1.00 de prueba.
			'amountWithoutTax'    => 100,
			'amountWithTax'       => 0,
			'tax'                 => 0,
			'service'             => 0,
			'tip'                 => 0,
			'currency'            => 'USD',
			'storeId'             => $store_id,
			'reference'           => 'test conexion',
			'clientTransactionId' => $txn_id,
			'oneTime'             => true,
			'expireIn'            => 0,
		);

		$endpoint = self::API_BASE . self::LINKS_PATH;

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => 'WordPress no pudo conectar: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 401 === $code ) {
			return array( 'ok' => false, 'message' => 'Token inválido (HTTP 401). Verifica que copiaste bien el Bearer Token desde Payphone.' );
		}
		if ( 403 === $code ) {
			return array( 'ok' => false, 'message' => 'Sin acceso (HTTP 403). El token no tiene permisos sobre este Store ID.' );
		}

		// 200 = Payphone devuelve la URL del link directamente como string.
		if ( 200 === $code ) {
			$url = trim( $raw, " \t\n\r\0\x0B\"" );
			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return array( 'ok' => true, 'message' => 'Conexión exitosa. Link de prueba generado: ' . $url );
			}
			return array( 'ok' => true, 'message' => 'Credenciales válidas (HTTP 200). Respuesta: ' . substr( $raw, 0, 200 ) );
		}

		// 400 con JSON = credenciales OK pero validación del body fallida (normal en test).
		if ( 400 === $code && $data !== null ) {
			$api_msg = $data['message'] ?? '';
			// Si el error es de validación (no de auth), las credenciales son buenas.
			if ( isset( $data['errorCode'] ) && (int) $data['errorCode'] !== 401 ) {
				return array( 'ok' => true, 'message' => 'Credenciales válidas. Payphone rechazó el payload de test (normal): ' . $api_msg );
			}
			return array( 'ok' => false, 'message' => 'HTTP 400 | ' . $api_msg . ' | ' . wp_json_encode( $data ) );
		}

		return array( 'ok' => false, 'message' => 'URL: ' . $endpoint . ' | HTTP ' . $code . ' | ' . substr( $raw, 0, 400 ) );
	}

	/**
	 * Confirma una transacción consultando directamente a Payphone.
	 * Se llama desde la return_url para validar que el pago fue exitoso
	 * antes de actualizar la base de datos.
	 *
	 * @param string $client_txn_id   clientTransactionId que envió Payphone en la redirección.
	 * @param int    $transaction_id  transactionId que envió Payphone en la redirección.
	 * @return array|\WP_Error        Datos de la transacción o WP_Error.
	 */
	public static function confirm_payment( string $client_txn_id, int $transaction_id ): array|\WP_Error {
		$token = get_option( 'edu_payphone_token', '' );

		if ( ! $token ) {
			return new \WP_Error( 'payphone_not_configured', 'Payphone no configurado.' );
		}

		$response = wp_remote_post(
			self::API_BASE . '/button/V2/Confirm',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'id'                  => $transaction_id,
					'clientTransactionId' => $client_txn_id,
				) ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $data ?: new \WP_Error( 'payphone_confirm_empty', 'Respuesta vacía de Payphone.' );
	}
}
