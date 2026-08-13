<?php
/**
 * Infraestructura de la API REST edu/v1.
 *
 * Registra las rutas, resuelve la autenticación por token, aplica CORS,
 * resuelve la institución de la request y expone los helpers de respuesta y
 * de error que usan todos los endpoints.
 *
 * Contrato completo: docs/API_CONTRATO_V1.md
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api {

	/** Namespace REST. */
	const API_NAMESPACE = 'edu/v1';

	/** Cabecera con la que el Superadmin Editorial elige institución. */
	const INSTITUTION_HEADER = 'X-Edu-Institution';

	/** Opción con la lista blanca de orígenes para CORS. */
	const ORIGINS_OPTION = 'edu_api_allowed_origins';

	/** Máximo de elementos por página. */
	const MAX_PER_PAGE = 100;

	/**
	 * Engancha todo lo de la API. Se llama desde edu_bootstrap().
	 */
	public static function register() {
		// La resolución del Bearer tiene que estar disponible antes de que
		// WordPress determine el usuario actual.
		add_filter( 'determine_current_user', array( 'Edu_Api_Auth', 'determine_current_user' ), 20 );
		add_filter( 'rest_authentication_errors', array( 'Edu_Api_Auth', 'rest_authentication_errors' ), 20 );

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// CORS propio, después del de WordPress (prioridad 10) para poder
		// sobrescribir sus cabeceras solo en nuestro namespace.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'send_cors_headers' ), 11, 4 );
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'allow_institution_header' ) );

		// Cerrar sesiones abiertas cuando la cuenta se suspende o cambia la clave.
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ) );
		add_action( 'edu_account_suspended', array( __CLASS__, 'on_account_suspended' ) );
	}

	/**
	 * Registra las rutas de todos los grupos de endpoints.
	 */
	public static function register_routes() {
		Edu_Api_Auth_Routes::register_routes();
		Edu_Api_Me_Routes::register_routes();
		Edu_Api_Catalog_Routes::register_routes();
		Edu_Api_Gradebook_Routes::register_routes();
		Edu_Api_Activity_Routes::register_routes();
		Edu_Api_Write_Routes::register_routes();
		Edu_Api_Report_Routes::register_routes();
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * CORS
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Envía las cabeceras CORS para las rutas de edu/v1.
	 *
	 * Con Bearer no hacen falta cookies, así que Allow-Credentials queda en
	 * false: elimina toda la superficie de CSRF.
	 *
	 * @param bool             $served  Si la request ya fue servida.
	 * @param WP_HTTP_Response $result  Respuesta.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Servidor.
	 * @return bool
	 */
	public static function send_cors_headers( $served, $result, $request, $server ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $served;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/' . self::API_NAMESPACE ) ) {
			return $served;
		}

		$origin = get_http_origin();
		if ( ! $origin ) {
			return $served;
		}

		if ( ! self::is_origin_allowed( $origin ) ) {
			// WordPress devuelve por defecto Allow-Origin a CUALQUIER origen en
			// REST (rest_send_cors_headers, prioridad 10), y encima con
			// Allow-Credentials: true. En edu/v1 eso no vale: si el origen no
			// está en la lista blanca se retiran esas cabeceras y el navegador
			// bloquea la lectura de la respuesta.
			header_remove( 'Access-Control-Allow-Origin' );
			header_remove( 'Access-Control-Allow-Credentials' );
			header_remove( 'Access-Control-Allow-Methods' );
			header_remove( 'Access-Control-Expose-Headers' );
			return $served;
		}

		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
		header( 'Access-Control-Allow-Credentials: false' );
		header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Edu-Institution, X-WP-Nonce' );
		header( 'Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, X-Edu-Institution' );
		header( 'Access-Control-Max-Age: 600' );
		header( 'Vary: Origin', false );

		return $served;
	}

	/**
	 * Permite la cabecera de institución en el preflight de WordPress.
	 *
	 * @param array $headers Cabeceras permitidas.
	 * @return array
	 */
	public static function allow_institution_header( $headers ) {
		$headers[] = self::INSTITUTION_HEADER;
		return $headers;
	}

	/**
	 * ¿El origen está en la lista blanca? Nunca se responde con '*'.
	 *
	 * @param string $origin Origen de la request.
	 * @return bool
	 */
	public static function is_origin_allowed( $origin ) {
		$origin = self::normalize_origin( $origin );

		// El propio sitio siempre está permitido (SPA servida en el mismo dominio).
		if ( $origin === self::normalize_origin( home_url() ) || $origin === self::normalize_origin( site_url() ) ) {
			return true;
		}

		foreach ( self::allowed_origins() as $allowed ) {
			if ( $origin === $allowed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lista blanca configurada en Ajustes (un origen por línea o separados por coma).
	 *
	 * @return string[]
	 */
	public static function allowed_origins() {
		$raw = (string) get_option( self::ORIGINS_OPTION, '' );
		$out = array();

		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $candidate ) {
			$candidate = self::normalize_origin( $candidate );
			if ( '' !== $candidate ) {
				$out[] = $candidate;
			}
		}

		return array_unique( $out );
	}

	private static function normalize_origin( $origin ) {
		$origin = strtolower( trim( (string) $origin ) );
		if ( '' === $origin ) {
			return '';
		}
		return untrailingslashit( $origin );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Permisos y alcance
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * permission_callback para rutas que solo exigen sesión válida.
	 *
	 * @return true|WP_Error
	 */
	public static function require_login() {
		if ( ! is_user_logged_in() ) {
			return self::error( 'edu_not_authenticated', __( 'Necesitas iniciar sesión.', 'sistema-educativo' ), 401 );
		}

		$user = wp_get_current_user();
		if ( ! Edu_Api_Auth::user_may_use_api( $user ) ) {
			return self::error( 'edu_not_allowed', __( 'Esta cuenta no tiene acceso al sistema educativo.', 'sistema-educativo' ), 403 );
		}

		return true;
	}

	/**
	 * Devuelve un permission_callback que exige una capability concreta.
	 *
	 * Uso: 'permission_callback' => Edu_Api::require_cap( 'edu_grade_students' )
	 *
	 * @param string|string[] $caps Capability o lista (basta con una).
	 * @return callable
	 */
	public static function require_cap( $caps ) {
		$caps = (array) $caps;

		return static function () use ( $caps ) {
			$logged = self::require_login();
			if ( is_wp_error( $logged ) ) {
				return $logged;
			}

			foreach ( $caps as $cap ) {
				if ( Edu_Context::can( $cap ) ) {
					return true;
				}
			}

			return self::error( 'edu_not_allowed', __( 'No tienes permiso para esta operación.', 'sistema-educativo' ), 403 );
		};
	}

	/**
	 * Verifica que el módulo esté activo. Si no, 404: para el cliente la ruta
	 * no existe en esta instalación.
	 *
	 * @param string $module Clave del módulo.
	 * @return true|WP_Error
	 */
	public static function require_module( $module ) {
		if ( ! Edu_Modules::is_active( $module ) ) {
			return self::error(
				'edu_module_disabled',
				__( 'Este módulo no está habilitado en esta institución.', 'sistema-educativo' ),
				404,
				array( 'module' => $module )
			);
		}

		return true;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Institución de la request
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Resuelve la institución de esta request.
	 *
	 * Los roles edu_* quedan atados a su institución y NO pueden cambiarla
	 * mandando la cabecera. Solo el Superadmin Editorial la elige.
	 *
	 * @param WP_REST_Request|null $request Request (para leer la cabecera).
	 * @return int|WP_Error
	 */
	public static function resolve_institution( $request = null ) {
		if ( Edu_Context::is_superadmin_editorial() && $request instanceof WP_REST_Request ) {
			$requested = (int) $request->get_header( self::INSTITUTION_HEADER );

			if ( $requested > 0 ) {
				global $wpdb;
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}edu_institutions WHERE id = %d", $requested )
				);

				if ( ! $exists ) {
					return self::error(
						'edu_not_found',
						__( 'La institución indicada no existe.', 'sistema-educativo' ),
						404,
						array( 'institution_id' => $requested )
					);
				}

				// Override solo en memoria: no toca la institución guardada del
				// usuario, que es la que usa el selector de wp-admin.
				Edu_Context::override_institution_id( $exists );
				return $exists;
			}
		}

		$institution_id = Edu_Context::current_institution_id();

		if ( ! $institution_id ) {
			return self::error(
				'edu_no_institution',
				__( 'No hay una institución activa para esta cuenta.', 'sistema-educativo' ),
				409
			);
		}

		return $institution_id;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Respuestas y errores
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Construye un WP_Error con el status HTTP que espera el contrato.
	 *
	 * @param string $code    Código del catálogo (§11 del contrato).
	 * @param string $message Mensaje legible.
	 * @param int    $status  Status HTTP.
	 * @param array  $details Datos extra para el cliente.
	 * @return WP_Error
	 */
	public static function error( $code, $message, $status = 400, $details = array() ) {
		$data = array( 'status' => (int) $status );

		if ( ! empty( $details ) ) {
			$data['details'] = $details;
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Traduce el resultado de un servicio a la respuesta de la API.
	 *
	 * Los servicios usan códigos sin prefijo ('invalid_scope', 'no_components')
	 * porque las vistas de wp-admin los leen así. La API les antepone `edu_`,
	 * como define el catálogo del contrato (§11).
	 *
	 * @param mixed $result Array de datos o WP_Error del servicio.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function from_service( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return new WP_REST_Response( $result, 200 );
		}

		$code = $result->get_error_code();

		if ( 0 !== strpos( (string) $code, 'edu_' ) ) {
			$code = 'edu_' . $code;
		}

		$data = $result->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			$data = array( 'status' => 400 );
		}

		return new WP_Error( $code, $result->get_error_message(), $data );
	}

	/**
	 * Igual que from_service(), pero para colecciones paginadas que el servicio
	 * devuelve como array( 'items' => …, 'total' => … ).
	 *
	 * @param mixed $result   Resultado del servicio.
	 * @param int   $per_page Tamaño de página pedido.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function from_service_collection( $result, $per_page ) {
		if ( is_wp_error( $result ) ) {
			return self::from_service( $result );
		}

		return self::collection( $result['items'], $result['total'], $per_page );
	}

	/**
	 * Error de validación de campos: 400 con el detalle por parámetro.
	 *
	 * @param array $params Mapa campo => mensaje.
	 * @return WP_Error
	 */
	public static function invalid_params( array $params ) {
		return new WP_Error(
			'edu_invalid_params',
			__( 'Parámetros inválidos.', 'sistema-educativo' ),
			array(
				'status' => 400,
				'params' => $params,
			)
		);
	}

	/**
	 * Respuesta de colección con las cabeceras de paginación de WP REST.
	 *
	 * @param array $items    Elementos de la página actual.
	 * @param int   $total    Total de elementos sin paginar.
	 * @param int   $per_page Tamaño de página.
	 * @return WP_REST_Response
	 */
	public static function collection( array $items, $total, $per_page ) {
		$per_page = max( 1, (int) $per_page );
		$response = new WP_REST_Response( array_values( $items ), 200 );

		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Argumentos estándar de paginación para register_rest_route().
	 *
	 * @return array
	 */
	public static function pagination_args() {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => self::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Formato
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Fecha y hora en ISO 8601 con el offset del sitio, o null.
	 *
	 * @param string|int|null $value Timestamp o fecha de MySQL.
	 * @return string|null
	 */
	public static function date( $value ) {
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
		if ( ! $timestamp ) {
			return null;
		}

		return wp_date( 'c', $timestamp );
	}

	/**
	 * Nota o monto como número con 2 decimales, o null si no hay valor.
	 *
	 * Importante: distingue "sin calificar" (null) de "cero", porque el motor
	 * de cálculo excluye los componentes sin nota en vez de contarlos como 0.
	 *
	 * @param mixed $value Valor crudo de la base de datos.
	 * @return float|null
	 */
	public static function decimal( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return round( (float) $value, 2 );
	}

	/**
	 * Booleano real a partir de los 0/1 que devuelve MySQL.
	 *
	 * @param mixed $value Valor crudo.
	 * @return bool
	 */
	public static function boolean( $value ) {
		return (bool) (int) $value;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Reacciones a cambios de la cuenta
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Si cambió la contraseña, se cierran las sesiones de la API.
	 *
	 * @param int     $user_id       Usuario.
	 * @param WP_User $old_user_data Datos previos.
	 */
	public static function on_profile_update( $user_id, $old_user_data ) {
		if ( ! $old_user_data instanceof WP_User ) {
			return;
		}

		$new_user = get_user_by( 'id', $user_id );
		if ( $new_user instanceof WP_User && $new_user->user_pass !== $old_user_data->user_pass ) {
			Edu_Api_Auth::revoke( $user_id, '', true );
		}
	}

	/**
	 * Reseteo de contraseña: mismo criterio.
	 *
	 * @param WP_User $user Usuario.
	 */
	public static function on_password_reset( $user ) {
		if ( $user instanceof WP_User ) {
			Edu_Api_Auth::revoke( $user->ID, '', true );
		}
	}

	/**
	 * Cuenta suspendida: se revocan todas sus sesiones de inmediato, sin
	 * esperar a que expire el access token.
	 *
	 * @param int $user_id Usuario suspendido.
	 */
	public static function on_account_suspended( $user_id ) {
		Edu_Api_Auth::revoke( (int) $user_id, '', true );
	}
}
