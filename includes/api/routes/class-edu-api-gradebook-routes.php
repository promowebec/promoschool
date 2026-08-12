<?php
/**
 * Endpoints de lectura: calificaciones.
 *
 * Contrato: docs/API_CONTRATO_V1.md §7.4 y §8.1.
 * La lógica vive en Edu_Gradebook_Service.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api_Gradebook_Routes {

	public static function register_routes() {
		$ns = Edu_Api::API_NAMESPACE;

		register_rest_route(
			$ns,
			'/components',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'components' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
				'args'                => array(
					'subject_id'   => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'trimester_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'parcial_num'  => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/gradebook',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'gradebook' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
				'args'                => array(
					'grade_id'     => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'subject_id'   => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'trimester_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'parcial_num'  => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/trimester-scores',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'trimester_scores' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
				'args'                => array(
					'grade_id'     => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'subject_id'   => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'trimester_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/year-scores',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'year_scores' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
				'args'                => array(
					'grade_id'   => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'period_id'  => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'subject_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/students/(?P<id>\d+)/scores',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'student_scores' ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
				'args'                => array(
					'period_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/* ─── Callbacks ─────────────────────────────────────────────────────── */

	public static function components( WP_REST_Request $request ) {
		$scope = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service(
			Edu_Gradebook_Service::components(
				array(
					'subject_id'   => (int) $request->get_param( 'subject_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
					'parcial_num'  => (int) $request->get_param( 'parcial_num' ),
				)
			)
		);
	}

	public static function gradebook( WP_REST_Request $request ) {
		$scope = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service(
			Edu_Gradebook_Service::gradebook(
				array(
					'grade_id'     => (int) $request->get_param( 'grade_id' ),
					'subject_id'   => (int) $request->get_param( 'subject_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
					'parcial_num'  => (int) $request->get_param( 'parcial_num' ),
				)
			)
		);
	}

	public static function trimester_scores( WP_REST_Request $request ) {
		$scope = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service(
			Edu_Gradebook_Service::trimester_scores(
				array(
					'grade_id'     => (int) $request->get_param( 'grade_id' ),
					'subject_id'   => (int) $request->get_param( 'subject_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
				)
			)
		);
	}

	public static function year_scores( WP_REST_Request $request ) {
		$scope = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service(
			Edu_Gradebook_Service::year_scores(
				array(
					'grade_id'   => (int) $request->get_param( 'grade_id' ),
					'period_id'  => (int) $request->get_param( 'period_id' ),
					'subject_id' => (int) $request->get_param( 'subject_id' ),
				)
			)
		);
	}

	public static function student_scores( WP_REST_Request $request ) {
		$scope = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return Edu_Api::from_service(
			Edu_Gradebook_Service::student_scores( (int) $request['id'], (int) $request->get_param( 'period_id' ) )
		);
	}
}
