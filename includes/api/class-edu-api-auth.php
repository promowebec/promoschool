<?php
/**
 * Autenticación de la API edu/v1.
 *
 * Implementa el esquema del contrato (docs/API_CONTRATO_V1.md §4):
 *   - Access token JWT de vida corta (15 min), firmado con Edu_Api_Jwt.
 *   - Refresh token opaco y rotatorio (30 días), guardado HASHEADO en usermeta.
 *   - Revocación masiva vía usermeta.edu_token_version.
 *   - Rate limit del login: 5 intentos fallidos por (usuario + IP) cada 15 min.
 *
 * El login pasa por wp_authenticate() a propósito: así siguen corriendo los
 * filtros existentes, incluido el bloqueo de cuentas suspendidas registrado en
 * sistema-educativo.php (filtro 'authenticate', prioridad 30).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api_Auth {

	/** Vida del access token, en segundos (15 minutos). */
	const ACCESS_TTL = 900;

	/** Vida del refresh token, en segundos (30 días). */
	const REFRESH_TTL = 2592000;

	/** Máximo de refresh tokens vivos por usuario (dispositivos recordados). */
	const MAX_DEVICES = 10;

	/** Intentos fallidos de login tolerados dentro de la ventana. */
	const MAX_LOGIN_ATTEMPTS = 5;

	/** Ventana del rate limit, en segundos. */
	const LOGIN_WINDOW = 900;

	/** Meta donde viven los refresh tokens hasheados. */
	const META_REFRESH = 'edu_refresh_tokens';

	/** Meta con la versión de token del usuario (subirla revoca todo). */
	const META_TOKEN_VERSION = 'edu_token_version';

	/** Error de autenticación de esta request, para rest_authentication_errors. */
	private static $auth_error = null;

	/** Evita recursión: determine_current_user se dispara dentro de wp_authenticate. */
	private static $resolving = false;

	/** Cache del usuario resuelto por Bearer en esta request. */
	private static $resolved_user_id = null;

	/* ─────────────────────────────────────────────────────────────────────
	 * Login
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Autentica por usuario y contraseña, y emite el par de tokens.
	 *
	 * @param string $username Usuario o email.
	 * @param string $password Contraseña.
	 * @param string $device_label Etiqueta del dispositivo (opcional).
	 * @return array|WP_Error
	 */
	public static function login( $username, $password, $device_label = '' ) {
		$username = trim( (string) $username );

		if ( '' === $username || '' === (string) $password ) {
			return new WP_Error(
				'edu_invalid_credentials',
				__( 'Usuario o contraseña incorrectos.', 'sistema-educativo' ),
				array( 'status' => 401 )
			);
		}

		$limit = self::check_rate_limit( $username );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		// wp_authenticate() aplica los filtros del sitio, incluido el de cuentas
		// suspendidas y la compatibilidad con Ultimate Member.
		self::$resolving = true;
		$user            = wp_authenticate( $username, $password );
		self::$resolving = false;

		if ( is_wp_error( $user ) ) {
			// Cuenta suspendida: 403 y sin contar como intento fallido de
			// contraseña — el cliente no debe reintentar con otras credenciales.
			if ( in_array( 'account_suspended', $user->get_error_codes(), true ) ) {
				Edu_Audit::log( 'api_login_bloqueado', 'api', null, null, array( 'usuario' => $username, 'motivo' => 'suspendida' ) );
				return new WP_Error(
					'edu_account_suspended',
					__( 'Tu cuenta está suspendida. Contacta a la institución.', 'sistema-educativo' ),
					array( 'status' => 403 )
				);
			}

			self::register_failed_attempt( $username );

			// Nunca revelar si falló el usuario o la contraseña.
			return new WP_Error(
				'edu_invalid_credentials',
				__( 'Usuario o contraseña incorrectos.', 'sistema-educativo' ),
				array( 'status' => 401 )
			);
		}

		if ( ! self::user_may_use_api( $user ) ) {
			return new WP_Error(
				'edu_not_allowed',
				__( 'Esta cuenta no tiene acceso al sistema educativo.', 'sistema-educativo' ),
				array( 'status' => 403 )
			);
		}

		self::clear_rate_limit( $username );

		$tokens = self::issue_tokens( $user, $device_label );

		// La auditoría necesita saber quién es: en un login por API todavía no
		// hay usuario actual establecido.
		wp_set_current_user( $user->ID );
		Edu_Audit::log( 'api_login', 'api', $user->ID, null, array( 'canal' => 'api', 'dispositivo' => $device_label ) );

		return $tokens;
	}

	/**
	 * ¿Esta cuenta puede usar la API? Solo roles edu_* o el Superadmin Editorial.
	 *
	 * @param WP_User $user Usuario.
	 * @return bool
	 */
	public static function user_may_use_api( WP_User $user ) {
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		foreach ( (array) $user->roles as $role ) {
			if ( 0 === strpos( (string) $role, 'edu_' ) ) {
				return true;
			}
		}
		return false;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Emisión, refresco y revocación
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Emite un access token nuevo y un refresh token nuevo.
	 *
	 * @param WP_User $user Usuario autenticado.
	 * @param string  $device_label Etiqueta del dispositivo.
	 * @return array
	 */
	public static function issue_tokens( WP_User $user, $device_label = '' ) {
		$now = time();

		$claims = array(
			'iss'   => Edu_Api_Jwt::issuer(),
			'sub'   => (int) $user->ID,
			'iat'   => $now,
			'exp'   => $now + self::ACCESS_TTL,
			'ver'   => self::token_version( $user->ID ),
			'inst'  => (int) get_user_meta( $user->ID, 'edu_institution_id', true ),
			'roles' => array_values( (array) $user->roles ),
		);

		$refresh = self::create_refresh_token( $user->ID, $device_label );

		return array(
			'access_token'       => Edu_Api_Jwt::encode( $claims ),
			'token_type'         => 'Bearer',
			'expires_in'         => self::ACCESS_TTL,
			'refresh_token'      => $refresh,
			'refresh_expires_in' => self::REFRESH_TTL,
		);
	}

	/**
	 * Canjea un refresh token por un par nuevo. El token usado se invalida
	 * (rotación): si alguien lo reutiliza después, ya no sirve.
	 *
	 * @param string $refresh_token Token recibido.
	 * @return array|WP_Error
	 */
	public static function refresh( $refresh_token ) {
		$parsed = self::parse_refresh_token( $refresh_token );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		list( $user_id, $secret ) = $parsed;

		$stored = self::get_refresh_store( $user_id );
		$hash   = hash( 'sha256', $secret );
		$found  = null;

		foreach ( $stored as $index => $entry ) {
			if ( isset( $entry['hash'] ) && hash_equals( (string) $entry['hash'], $hash ) ) {
				$found = $index;
				break;
			}
		}

		if ( null === $found ) {
			return new WP_Error(
				'edu_token_invalid',
				__( 'El refresh token no es válido o ya fue usado.', 'sistema-educativo' ),
				array( 'status' => 401 )
			);
		}

		$entry = $stored[ $found ];

		if ( (int) $entry['expires_at'] < time() ) {
			unset( $stored[ $found ] );
			self::save_refresh_store( $user_id, $stored );
			return new WP_Error(
				'edu_token_expired',
				__( 'La sesión expiró. Vuelve a iniciar sesión.', 'sistema-educativo' ),
				array( 'status' => 401 )
			);
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'edu_token_invalid', __( 'Sesión inválida.', 'sistema-educativo' ), array( 'status' => 401 ) );
		}

		if ( 'suspended' === get_user_meta( $user_id, 'edu_account_status', true ) ) {
			return new WP_Error(
				'edu_account_suspended',
				__( 'Tu cuenta está suspendida. Contacta a la institución.', 'sistema-educativo' ),
				array( 'status' => 403 )
			);
		}

		if ( ! self::user_may_use_api( $user ) ) {
			return new WP_Error( 'edu_not_allowed', __( 'Esta cuenta no tiene acceso al sistema educativo.', 'sistema-educativo' ), array( 'status' => 403 ) );
		}

		// Rotación: se descarta el usado antes de emitir el nuevo.
		unset( $stored[ $found ] );
		self::save_refresh_store( $user_id, $stored );

		wp_set_current_user( $user_id );

		return self::issue_tokens( $user, isset( $entry['device_label'] ) ? $entry['device_label'] : '' );
	}

	/**
	 * Revoca un refresh token puntual, o todas las sesiones del usuario.
	 *
	 * Revocar todo sube edu_token_version, con lo que también dejan de valer
	 * los access tokens que todavía no expiraron.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $refresh_token Token a revocar (ignorado si $all).
	 * @param bool   $all Revocar todas las sesiones.
	 * @return true
	 */
	public static function revoke( $user_id, $refresh_token = '', $all = false ) {
		$user_id = (int) $user_id;

		if ( $all ) {
			self::save_refresh_store( $user_id, array() );
			self::bump_token_version( $user_id );
			return true;
		}

		$parsed = self::parse_refresh_token( $refresh_token );
		if ( is_wp_error( $parsed ) ) {
			// Revocar algo que no existe no es un error para el cliente.
			return true;
		}

		list( $token_user_id, $secret ) = $parsed;
		if ( $token_user_id !== $user_id ) {
			return true;
		}

		$stored = self::get_refresh_store( $user_id );
		$hash   = hash( 'sha256', $secret );

		foreach ( $stored as $index => $entry ) {
			if ( isset( $entry['hash'] ) && hash_equals( (string) $entry['hash'], $hash ) ) {
				unset( $stored[ $index ] );
				break;
			}
		}

		self::save_refresh_store( $user_id, $stored );
		return true;
	}

	/**
	 * Sube la versión de token: invalida de golpe todos los access tokens
	 * vivos del usuario. Se llama al suspender la cuenta y al cambiar la
	 * contraseña.
	 *
	 * @param int $user_id Usuario.
	 * @return int Nueva versión.
	 */
	public static function bump_token_version( $user_id ) {
		$version = self::token_version( $user_id ) + 1;
		update_user_meta( (int) $user_id, self::META_TOKEN_VERSION, $version );
		return $version;
	}

	public static function token_version( $user_id ) {
		return (int) get_user_meta( (int) $user_id, self::META_TOKEN_VERSION, true );
	}

	/**
	 * Sesiones activas del usuario (para mostrarlas en la app o en el perfil).
	 *
	 * @param int $user_id Usuario.
	 * @return array
	 */
	public static function list_sessions( $user_id ) {
		$out = array();
		foreach ( self::get_refresh_store( $user_id ) as $entry ) {
			$out[] = array(
				'device_label' => isset( $entry['device_label'] ) ? $entry['device_label'] : '',
				'created_at'   => Edu_Api::date( isset( $entry['created_at'] ) ? $entry['created_at'] : 0 ),
				'last_used_at' => Edu_Api::date( isset( $entry['last_used_at'] ) ? $entry['last_used_at'] : 0 ),
				'expires_at'   => Edu_Api::date( isset( $entry['expires_at'] ) ? $entry['expires_at'] : 0 ),
			);
		}
		return $out;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Resolución del usuario en cada request (filtros de WordPress)
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Filtro determine_current_user: resuelve el usuario a partir del Bearer.
	 *
	 * Solo actúa si viene una cabecera Authorization: Bearer. Si no, deja
	 * intacta la autenticación por cookie, para no tocar wp-admin ni los
	 * portales shortcode.
	 *
	 * @param int|false $user_id Usuario ya determinado por WordPress.
	 * @return int|false
	 */
	public static function determine_current_user( $user_id ) {
		if ( self::$resolving || ! empty( $user_id ) ) {
			return $user_id;
		}

		$token = self::read_bearer_token();
		if ( ! $token ) {
			return $user_id;
		}

		if ( null !== self::$resolved_user_id ) {
			return self::$resolved_user_id;
		}

		self::$resolving = true;
		$resolved        = self::user_id_from_token( $token );
		self::$resolving = false;

		if ( is_wp_error( $resolved ) ) {
			self::$auth_error      = $resolved;
			self::$resolved_user_id = false;
			return $user_id;
		}

		self::$resolved_user_id = $resolved;
		return $resolved;
	}

	/** Rutas de edu/v1 que se sirven sin sesión. */
	const PUBLIC_ROUTES = array(
		'/auth/token',
		'/auth/refresh',
		'/payphone/webhook',
	);

	/**
	 * Filtro rest_authentication_errors: reporta el error del Bearer y exige
	 * sesión en las rutas privadas de edu/v1.
	 *
	 * Lo segundo hace falta porque WordPress valida los parámetros obligatorios
	 * de la ruta ANTES de llamar al permission_callback: sin este corte, una
	 * petición sin token a /gradebook respondía 400 (falta grade_id) en vez de
	 * 401. Cortar aquí garantiza el 401 y además no revela qué parámetros
	 * espera la ruta.
	 *
	 * @param WP_Error|null|true $result Resultado previo.
	 * @return WP_Error|null|true
	 */
	public static function rest_authentication_errors( $result ) {
		if ( null !== $result && false !== $result ) {
			return $result;
		}

		if ( self::$auth_error instanceof WP_Error ) {
			return self::$auth_error;
		}

		if ( is_user_logged_in() ) {
			return $result;
		}

		$route = self::current_rest_route();
		$ns    = '/' . Edu_Api::API_NAMESPACE;

		// Fuera de edu/v1 no opinamos; el índice del namespace queda abierto
		// para que la app pueda descubrir la API.
		if ( '' === $route || 0 !== strpos( $route, $ns ) || untrailingslashit( $route ) === $ns ) {
			return $result;
		}

		// El preflight del navegador viaja sin credenciales.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return $result;
		}

		foreach ( self::PUBLIC_ROUTES as $public ) {
			if ( 0 === strpos( $route, $ns . $public ) ) {
				return $result;
			}
		}

		return new WP_Error(
			'edu_not_authenticated',
			__( 'Necesitas iniciar sesión.', 'sistema-educativo' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Ruta REST que se está sirviendo, tal como la resolvió WordPress.
	 *
	 * @return string
	 */
	private static function current_rest_route() {
		if ( isset( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return (string) $GLOBALS['wp']->query_vars['rest_route'];
		}

		if ( ! empty( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se lee la ruta solicitada.
			return (string) sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return '';
	}

	/**
	 * Valida un access token y devuelve el ID de usuario.
	 *
	 * @param string $token Access token.
	 * @return int|WP_Error
	 */
	public static function user_id_from_token( $token ) {
		$claims = Edu_Api_Jwt::decode( $token );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$user_id = isset( $claims['sub'] ) ? (int) $claims['sub'] : 0;
		if ( ! $user_id ) {
			return new WP_Error( 'edu_token_invalid', __( 'El token no identifica a ningún usuario.', 'sistema-educativo' ), array( 'status' => 401 ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'edu_token_invalid', __( 'El usuario del token ya no existe.', 'sistema-educativo' ), array( 'status' => 401 ) );
		}

		// Revocación masiva: la versión del token debe coincidir con la del usuario.
		$claim_version = isset( $claims['ver'] ) ? (int) $claims['ver'] : 0;
		if ( $claim_version !== self::token_version( $user_id ) ) {
			return new WP_Error(
				'edu_token_invalid',
				__( 'La sesión fue cerrada. Vuelve a iniciar sesión.', 'sistema-educativo' ),
				array( 'status' => 401 )
			);
		}

		if ( 'suspended' === get_user_meta( $user_id, 'edu_account_status', true ) ) {
			return new WP_Error(
				'edu_account_suspended',
				__( 'Tu cuenta está suspendida. Contacta a la institución.', 'sistema-educativo' ),
				array( 'status' => 403 )
			);
		}

		return $user_id;
	}

	/**
	 * ¿La request actual viene autenticada con Bearer? Lo usa la capa REST
	 * para saber si debe exigir nonce (cookie) o no (token).
	 *
	 * @return bool
	 */
	public static function is_bearer_request() {
		return (bool) self::read_bearer_token();
	}

	/**
	 * Lee el token de la cabecera Authorization.
	 *
	 * Apache suele descartar esta cabecera: hace falta `CGIPassAuth On` o la
	 * regla RewriteRule que la copia a REDIRECT_HTTP_AUTHORIZATION. Por eso se
	 * revisan las tres variantes habituales.
	 *
	 * @return string Token, o cadena vacía.
	 */
	public static function read_bearer_token() {
		$header = '';

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$header = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				break;
			}
		}

		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) ) {
					$header = sanitize_text_field( (string) $value );
					break;
				}
			}
		}

		if ( '' === $header || 0 !== stripos( $header, 'bearer ' ) ) {
			return '';
		}

		return trim( substr( $header, 7 ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Refresh tokens: almacenamiento
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Crea un refresh token, guarda su hash y devuelve el token en claro.
	 *
	 * Formato: "<user_id>.<secreto>" — el prefijo permite localizar al usuario
	 * sin recorrer toda la tabla de usermeta.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $device_label Etiqueta del dispositivo.
	 * @return string
	 */
	private static function create_refresh_token( $user_id, $device_label = '' ) {
		$secret = bin2hex( random_bytes( 32 ) );
		$now    = time();

		$stored   = self::get_refresh_store( $user_id );
		$stored[] = array(
			'hash'         => hash( 'sha256', $secret ),
			'created_at'   => $now,
			'last_used_at' => $now,
			'expires_at'   => $now + self::REFRESH_TTL,
			'device_label' => substr( sanitize_text_field( (string) $device_label ), 0, 60 ),
			'ip'           => self::client_ip(),
		);

		// Solo se recuerdan los últimos MAX_DEVICES dispositivos.
		if ( count( $stored ) > self::MAX_DEVICES ) {
			usort(
				$stored,
				static function ( $a, $b ) {
					return (int) $a['created_at'] <=> (int) $b['created_at'];
				}
			);
			$stored = array_slice( $stored, -self::MAX_DEVICES );
		}

		self::save_refresh_store( $user_id, $stored );

		return $user_id . '.' . $secret;
	}

	/**
	 * Separa "<user_id>.<secreto>".
	 *
	 * @param string $token Refresh token.
	 * @return array|WP_Error array( int $user_id, string $secret )
	 */
	private static function parse_refresh_token( $token ) {
		$token = is_string( $token ) ? trim( $token ) : '';
		$parts = explode( '.', $token, 2 );

		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || '' === $parts[1] ) {
			return new WP_Error(
				'edu_token_invalid',
				__( 'El refresh token no es válido.', 'sistema-educativo' ),
				array( 'status' => 401 )
			);
		}

		return array( (int) $parts[0], $parts[1] );
	}

	/**
	 * Lee los refresh tokens del usuario, descartando los vencidos.
	 *
	 * @param int $user_id Usuario.
	 * @return array
	 */
	private static function get_refresh_store( $user_id ) {
		$stored = get_user_meta( (int) $user_id, self::META_REFRESH, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$now   = time();
		$alive = array();

		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && isset( $entry['hash'], $entry['expires_at'] ) && (int) $entry['expires_at'] >= $now ) {
				$alive[] = $entry;
			}
		}

		return $alive;
	}

	private static function save_refresh_store( $user_id, array $entries ) {
		$entries = array_values( $entries );

		if ( empty( $entries ) ) {
			delete_user_meta( (int) $user_id, self::META_REFRESH );
			return;
		}

		update_user_meta( (int) $user_id, self::META_REFRESH, $entries );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Rate limit del login
	 * ────────────────────────────────────────────────────────────────── */

	private static function rate_limit_key( $username ) {
		return 'edu_api_login_' . md5( strtolower( $username ) . '|' . self::client_ip() );
	}

	private static function check_rate_limit( $username ) {
		$attempts = (int) get_transient( self::rate_limit_key( $username ) );

		if ( $attempts >= self::MAX_LOGIN_ATTEMPTS ) {
			return new WP_Error(
				'edu_rate_limited',
				__( 'Demasiados intentos fallidos. Espera unos minutos antes de volver a intentar.', 'sistema-educativo' ),
				array(
					'status'      => 429,
					'retry_after' => self::LOGIN_WINDOW,
				)
			);
		}

		return true;
	}

	private static function register_failed_attempt( $username ) {
		$key      = self::rate_limit_key( $username );
		$attempts = (int) get_transient( $key );
		set_transient( $key, $attempts + 1, self::LOGIN_WINDOW );
	}

	private static function clear_rate_limit( $username ) {
		delete_transient( self::rate_limit_key( $username ) );
	}

	private static function client_ip() {
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return substr( $ip, 0, 45 );
				}
			}
		}
		return '0.0.0.0';
	}
}
