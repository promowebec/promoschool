<?php
/**
 * Endpoints de lectura: tareas, asistencia, comunicados, pagos y auditoría.
 *
 * Cada grupo respeta su módulo activable: si está apagado, la ruta responde
 * 404 edu_module_disabled (contrato §6).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api_Activity_Routes {

	public static function register_routes() {
		$ns = Edu_Api::API_NAMESPACE;

		/* ── Tareas ──────────────────────────────────────────────────── */

		self::get(
			$ns,
			'/assignments',
			'assignments',
			array(
				'grade_id'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'subject_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'trimester_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'parcial_num'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'status'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				'type'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			)
		);

		self::get( $ns, '/assignments/(?P<id>\d+)', 'assignment' );
		self::get( $ns, '/assignments/(?P<id>\d+)/submissions', 'submissions' );

		/* ── Asistencia ──────────────────────────────────────────────── */

		self::get(
			$ns,
			'/attendance',
			'attendance',
			array(
				'grade_id'   => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				'date'       => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'subject_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			)
		);

		self::get(
			$ns,
			'/students/(?P<id>\d+)/attendance',
			'student_attendance',
			array(
				'from' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'to'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			)
		);

		/* ── Comunicados ─────────────────────────────────────────────── */

		self::get(
			$ns,
			'/announcements',
			'announcements',
			array(
				'scope'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				'grade_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			)
		);

		self::get(
			$ns,
			'/me/announcements',
			'my_announcements',
			array(
				'unread' => array( 'type' => 'boolean' ),
			)
		);

		/* ── Pagos ───────────────────────────────────────────────────── */

		self::get(
			$ns,
			'/payments',
			'payments',
			array(
				'student_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'period_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'status'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				'concept'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				'month'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			)
		);

		/* ── Auditoría ───────────────────────────────────────────────── */

		self::get(
			$ns,
			'/audit',
			'audit',
			array_merge(
				Edu_Api::pagination_args(),
				array(
					'entity_type' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'action'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'user_id'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'from'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'to'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				)
			)
		);
	}

	private static function get( $ns, $route, $callback, array $args = array() ) {
		register_rest_route(
			$ns,
			$route,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Módulo activo + institución resuelta.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $module  Clave del módulo.
	 * @return true|WP_Error
	 */
	private static function ready( WP_REST_Request $request, $module ) {
		$active = Edu_Api::require_module( $module );
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		$institution = Edu_Api::resolve_institution( $request );

		return is_wp_error( $institution ) ? $institution : true;
	}

	/* ─── Tareas ────────────────────────────────────────────────────────── */

	public static function assignments( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Activity_Service::assignments(
				array(
					'grade_id'     => (int) $request->get_param( 'grade_id' ),
					'subject_id'   => (int) $request->get_param( 'subject_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
					'parcial_num'  => (int) $request->get_param( 'parcial_num' ),
					'status'       => $request->get_param( 'status' ),
					'type'         => $request->get_param( 'type' ),
				)
			)
		);
	}

	public static function assignment( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Activity_Service::assignment( (int) $request['id'] ) );
	}

	public static function submissions( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Activity_Service::submissions( (int) $request['id'] ) );
	}

	/* ─── Asistencia ────────────────────────────────────────────────────── */

	public static function attendance( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'asistencia' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Activity_Service::attendance(
				array(
					'grade_id'   => (int) $request->get_param( 'grade_id' ),
					'date'       => $request->get_param( 'date' ),
					'subject_id' => (int) $request->get_param( 'subject_id' ),
				)
			)
		);
	}

	public static function student_attendance( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'asistencia' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Activity_Service::student_attendance(
				(int) $request['id'],
				array(
					'from' => $request->get_param( 'from' ),
					'to'   => $request->get_param( 'to' ),
				)
			)
		);
	}

	/* ─── Comunicados ───────────────────────────────────────────────────── */

	public static function announcements( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'comunicados' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Activity_Service::announcements(
				array(
					'scope'    => $request->get_param( 'scope' ),
					'grade_id' => (int) $request->get_param( 'grade_id' ),
				)
			)
		);
	}

	public static function my_announcements( WP_REST_Request $request ) {
		$active = Edu_Api::require_module( 'comunicados' );
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		return Edu_Api::from_service(
			Edu_Activity_Service::my_announcements( array( 'unread' => (bool) $request->get_param( 'unread' ) ) )
		);
	}

	/* ─── Pagos ─────────────────────────────────────────────────────────── */

	public static function payments( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'pagos' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Activity_Service::payments(
				array(
					'student_id' => (int) $request->get_param( 'student_id' ),
					'period_id'  => (int) $request->get_param( 'period_id' ),
					'status'     => $request->get_param( 'status' ),
					'concept'    => $request->get_param( 'concept' ),
					'month'      => $request->get_param( 'month' ),
				)
			)
		);
	}

	/* ─── Auditoría ─────────────────────────────────────────────────────── */

	public static function audit( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		$per_page = (int) $request->get_param( 'per_page' );

		return Edu_Api::from_service_collection(
			Edu_Activity_Service::audit(
				array(
					'entity_type' => $request->get_param( 'entity_type' ),
					'action'      => $request->get_param( 'action' ),
					'user_id'     => (int) $request->get_param( 'user_id' ),
					'from'        => $request->get_param( 'from' ),
					'to'          => $request->get_param( 'to' ),
					'page'        => (int) $request->get_param( 'page' ),
					'per_page'    => $per_page,
				)
			),
			$per_page
		);
	}
}
