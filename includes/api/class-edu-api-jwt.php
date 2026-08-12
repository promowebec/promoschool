<?php
/**
 * JWT propio (HS256) para la API edu/v1.
 *
 * Implementación mínima a mano con hash_hmac(): el proyecto no agrega
 * dependencias de Composer. Cubre exactamente lo que necesita el contrato
 * (docs/API_CONTRATO_V1.md §4.1): firmar y verificar un access token corto.
 *
 * No pretende ser una librería JWT completa — soporta un solo algoritmo
 * (HS256) y rechaza cualquier otro, incluido "none".
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api_Jwt {

	/** Único algoritmo aceptado. */
	const ALG = 'HS256';

	/** Tolerancia de reloj entre servidor y cliente, en segundos. */
	const LEEWAY = 60;

	/** Opción donde vive el secreto de firma. */
	const SECRET_OPTION = 'edu_api_secret';

	/**
	 * Firma un conjunto de claims y devuelve el token compacto.
	 *
	 * @param array $claims Claims a incluir (sub, exp, iat, ver, …).
	 * @return string Token en formato header.payload.firma
	 */
	public static function encode( array $claims ) {
		$header = array(
			'typ' => 'JWT',
			'alg' => self::ALG,
		);

		$h64 = self::b64url_encode( (string) wp_json_encode( $header ) );
		$p64 = self::b64url_encode( (string) wp_json_encode( $claims ) );
		$s64 = self::b64url_encode( self::sign( $h64 . '.' . $p64 ) );

		return $h64 . '.' . $p64 . '.' . $s64;
	}

	/**
	 * Verifica firma y vigencia, y devuelve los claims.
	 *
	 * @param string $token Token recibido del cliente.
	 * @return array|WP_Error Claims validados, o error 401.
	 */
	public static function decode( $token ) {
		$token = is_string( $token ) ? trim( $token ) : '';
		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) ) {
			return self::invalid( __( 'El token no tiene el formato esperado.', 'sistema-educativo' ) );
		}

		list( $h64, $p64, $s64 ) = $parts;

		$header = json_decode( (string) self::b64url_decode( $h64 ), true );
		if ( ! is_array( $header ) || ! isset( $header['alg'] ) || self::ALG !== $header['alg'] ) {
			// Rechaza explícitamente alg=none y cualquier algoritmo distinto.
			return self::invalid( __( 'Algoritmo de firma no admitido.', 'sistema-educativo' ) );
		}

		$expected = self::sign( $h64 . '.' . $p64 );
		$given    = self::b64url_decode( $s64 );

		if ( ! is_string( $given ) || ! hash_equals( $expected, $given ) ) {
			return self::invalid( __( 'La firma del token no es válida.', 'sistema-educativo' ) );
		}

		$claims = json_decode( (string) self::b64url_decode( $p64 ), true );
		if ( ! is_array( $claims ) ) {
			return self::invalid( __( 'El contenido del token no es legible.', 'sistema-educativo' ) );
		}

		$now = time();

		if ( ! isset( $claims['exp'] ) || ( (int) $claims['exp'] + self::LEEWAY ) < $now ) {
			return new WP_Error(
				'edu_token_expired',
				__( 'El token expiró. Renuévalo con el refresh token.', 'sistema-educativo' ),
				array( 'status' => 401 )
			);
		}

		if ( isset( $claims['iat'] ) && ( (int) $claims['iat'] - self::LEEWAY ) > $now ) {
			return self::invalid( __( 'El token fue emitido en el futuro.', 'sistema-educativo' ) );
		}

		if ( isset( $claims['iss'] ) && $claims['iss'] !== self::issuer() ) {
			return self::invalid( __( 'El token fue emitido para otro sitio.', 'sistema-educativo' ) );
		}

		return $claims;
	}

	/**
	 * Emisor del token: la home del sitio. Evita que un token de una
	 * institución sirva en otra instalación que comparta el secreto.
	 */
	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * Secreto de firma. Se genera en la primera llamada si no existe, para que
	 * instalaciones ya activadas no necesiten reactivar el plugin.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( self::SECRET_OPTION, '' );

		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		return $secret;
	}

	/**
	 * Rota el secreto. Invalida TODOS los access tokens y las URLs firmadas
	 * de descarga emitidas hasta ahora.
	 *
	 * @return string El nuevo secreto.
	 */
	public static function rotate_secret() {
		$secret = wp_generate_password( 64, true, true );
		update_option( self::SECRET_OPTION, $secret, false );
		return $secret;
	}

	/* ─── Internos ──────────────────────────────────────────────────────── */

	private static function sign( $input ) {
		return hash_hmac( 'sha256', $input, self::secret(), true );
	}

	private static function invalid( $message ) {
		return new WP_Error( 'edu_token_invalid', $message, array( 'status' => 401 ) );
	}

	public static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function b64url_decode( $data ) {
		$padded = strtr( (string) $data, '-_', '+/' );
		$remain = strlen( $padded ) % 4;
		if ( $remain ) {
			$padded .= str_repeat( '=', 4 - $remain );
		}
		return base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
