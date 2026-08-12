<?php
/**
 * Endpoints de lectura: estructura institucional y personas.
 *
 * Contrato: docs/API_CONTRATO_V1.md §7.2 y §7.3.
 * La lógica vive en Edu_Catalog_Service y Edu_People_Service.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api_Catalog_Routes {

	public static function register_routes() {
		$ns = Edu_Api::API_NAMESPACE;

		/* ── Estructura institucional ────────────────────────────────── */

		self::get( $ns, '/institutions', 'institutions' );
		self::get( $ns, '/institutions/(?P<id>\d+)', 'institution' );

		self::get(
			$ns,
			'/periods',
			'periods',
			array(
				'is_active' => array( 'type' => 'boolean' ),
			)
		);

		self::get( $ns, '/periods/(?P<id>\d+)/trimesters', 'trimesters' );

		self::get(
			$ns,
			'/grades',
			'grades',
			array(
				'level'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				'sub_level' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			)
		);

		self::get( $ns, '/grades/(?P<id>\d+)', 'grade' );
		self::get( $ns, '/grades/(?P<id>\d+)/pensum', 'pensum' );

		self::get(
			$ns,
			'/subjects-catalog',
			'subjects_catalog',
			array(
				'sub_level' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			)
		);

		self::get(
			$ns,
			'/subjects',
			'subjects',
			array(
				'grade_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			)
		);

		/* ── Personas ────────────────────────────────────────────────── */

		self::get(
			$ns,
			'/teachers',
			'teachers',
			array(
				'is_active' => array( 'type' => 'boolean' ),
				'search'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			)
		);

		self::get(
			$ns,
			'/students',
			'students',
			array_merge(
				Edu_Api::pagination_args(),
				array(
					'grade_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'status'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'search'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				)
			)
		);

		self::get( $ns, '/students/(?P<id>\d+)', 'student' );

		self::get(
			$ns,
			'/parents',
			'parents',
			array(
				'search' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			)
		);

		self::get(
			$ns,
			'/teacher-assignments',
			'teacher_assignments',
			array(
				'teacher_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'grade_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'subject_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'period_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'is_active'  => array( 'type' => 'boolean' ),
			)
		);
	}

	/**
	 * Atajo para registrar un GET autenticado de este grupo.
	 *
	 * @param string $ns       Namespace.
	 * @param string $route    Ruta.
	 * @param string $callback Método de esta clase.
	 * @param array  $args     Argumentos aceptados.
	 */
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
	 * Resuelve la institución antes de cada lectura.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private static function scope( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );

		return is_wp_error( $institution ) ? $institution : true;
	}

	/* ─── Callbacks ─────────────────────────────────────────────────────── */

	public static function institutions( WP_REST_Request $request ) {
		// No exige institución activa: es justamente la lista para elegirla.
		if ( Edu_Context::is_superadmin_editorial() ) {
			$requested = (int) $request->get_header( Edu_Api::INSTITUTION_HEADER );
			if ( $requested ) {
				Edu_Api::resolve_institution( $request );
			}
		}

		return Edu_Api::from_service( Edu_Catalog_Service::institutions() );
	}

	public static function institution( WP_REST_Request $request ) {
		return Edu_Api::from_service( Edu_Catalog_Service::institution( (int) $request['id'] ) );
	}

	public static function periods( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$args = array();
		if ( null !== $request->get_param( 'is_active' ) ) {
			$args['is_active'] = (bool) $request->get_param( 'is_active' );
		}

		return Edu_Api::from_service( Edu_Catalog_Service::periods( $args ) );
	}

	public static function trimesters( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service( Edu_Catalog_Service::trimesters( (int) $request['id'] ) );
	}

	public static function grades( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service(
			Edu_Catalog_Service::grades(
				array(
					'level'     => $request->get_param( 'level' ),
					'sub_level' => $request->get_param( 'sub_level' ),
				)
			)
		);
	}

	public static function grade( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service( Edu_Catalog_Service::grade( (int) $request['id'] ) );
	}

	public static function pensum( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service( Edu_Catalog_Service::pensum( (int) $request['id'] ) );
	}

	public static function subjects_catalog( WP_REST_Request $request ) {
		return Edu_Api::from_service(
			Edu_Catalog_Service::subjects_catalog( array( 'sub_level' => $request->get_param( 'sub_level' ) ) )
		);
	}

	public static function subjects( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service(
			Edu_Catalog_Service::subjects( array( 'grade_id' => (int) $request->get_param( 'grade_id' ) ) )
		);
	}

	public static function teachers( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$args = array( 'search' => $request->get_param( 'search' ) );
		if ( null !== $request->get_param( 'is_active' ) ) {
			$args['is_active'] = (bool) $request->get_param( 'is_active' );
		}

		return Edu_Api::from_service( Edu_People_Service::teachers( $args ) );
	}

	public static function students( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$per_page = (int) $request->get_param( 'per_page' );

		return Edu_Api::from_service_collection(
			Edu_People_Service::students(
				array(
					'grade_id' => (int) $request->get_param( 'grade_id' ),
					'status'   => $request->get_param( 'status' ),
					'search'   => $request->get_param( 'search' ),
					'page'     => (int) $request->get_param( 'page' ),
					'per_page' => $per_page,
				)
			),
			$per_page
		);
	}

	public static function student( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service( Edu_People_Service::student( (int) $request['id'] ) );
	}

	public static function parents( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service(
			Edu_People_Service::parents( array( 'search' => $request->get_param( 'search' ) ) )
		);
	}

	public static function teacher_assignments( WP_REST_Request $request ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$args = array(
			'teacher_id' => (int) $request->get_param( 'teacher_id' ),
			'grade_id'   => (int) $request->get_param( 'grade_id' ),
			'subject_id' => (int) $request->get_param( 'subject_id' ),
			'period_id'  => (int) $request->get_param( 'period_id' ),
		);

		if ( null !== $request->get_param( 'is_active' ) ) {
			$args['is_active'] = (bool) $request->get_param( 'is_active' );
		}

		return Edu_Api::from_service( Edu_People_Service::teacher_assignments( $args ) );
	}
}
