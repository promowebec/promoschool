<?php
/**
 * Endpoints de sesión: /auth/token, /auth/refresh, /auth/revoke.
 *
 * Son los únicos endpoints públicos de la API junto con el webhook de
 * Payphone. Contrato: docs/API_CONTRATO_V1.md §7.1.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api_Auth_Routes {

	public static function register_routes() {
		$ns = Edu_Api::API_NAMESPACE;

		register_rest_route(
			$ns,
			'/auth/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'username'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'password'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'device_label' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/auth/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'refresh' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'refresh_token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/auth/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'revoke' ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
				'args'                => array(
					'refresh_token' => array(
						'type'     => 'string',
						'required' => false,
						'default'  => '',
					),
					'all'           => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
				),
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────── */

	/**
	 * POST /auth/token — login con usuario y contraseña.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function token( WP_REST_Request $request ) {
		$blocked = self::require_https();
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}

		$tokens = Edu_Api_Auth::login(
			(string) $request->get_param( 'username' ),
			(string) $request->get_param( 'password' ),
			(string) $request->get_param( 'device_label' )
		);

		if ( is_wp_error( $tokens ) ) {
			return self::with_retry_after( $tokens );
		}

		// El usuario actual quedó establecido por Edu_Api_Auth::login().
		$tokens['user'] = Edu_Api_Me_Routes::build_me( wp_get_current_user(), $request );

		return new WP_REST_Response( $tokens, 200 );
	}

	/**
	 * POST /auth/refresh — canjea el refresh token por un par nuevo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function refresh( WP_REST_Request $request ) {
		$blocked = self::require_https();
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}

		$tokens = Edu_Api_Auth::refresh( (string) $request->get_param( 'refresh_token' ) );

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$tokens['user'] = Edu_Api_Me_Routes::build_me( wp_get_current_user(), $request );

		return new WP_REST_Response( $tokens, 200 );
	}

	/**
	 * POST /auth/revoke — cierra la sesión actual, o todas con ?all=true.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function revoke( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$all     = (bool) $request->get_param( 'all' );

		Edu_Api_Auth::revoke( $user_id, (string) $request->get_param( 'refresh_token' ), $all );

		return new WP_REST_Response(
			array(
				'revoked' => true,
				'all'     => $all,
			),
			200
		);
	}

	/* ─────────────────────────────────────────────────────────────────── */

	/**
	 * Exige HTTPS en producción: por aquí viajan credenciales de menores.
	 *
	 * En entornos local/development se permite HTTP para poder desarrollar
	 * con Local sin certificado.
	 *
	 * @return true|WP_Error
	 */
	private static function require_https() {
		if ( is_ssl() ) {
			return true;
		}

		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		if ( in_array( $environment, array( 'local', 'development' ), true ) ) {
			return true;
		}

		return Edu_Api::error(
			'edu_https_required',
			__( 'La autenticación requiere HTTPS.', 'sistema-educativo' ),
			403
		);
	}

	/**
	 * Traslada retry_after a la cabecera Retry-After del 429.
	 *
	 * @param WP_Error $error Error original.
	 * @return WP_Error
	 */
	private static function with_retry_after( WP_Error $error ) {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['retry_after'] ) && ! headers_sent() ) {
			header( 'Retry-After: ' . (int) $data['retry_after'] );
		}

		return $error;
	}
}
