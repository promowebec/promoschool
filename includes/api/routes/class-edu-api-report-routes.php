<?php
/**
 * Endpoints de pagos, reportes y dashboards (etapa 1e).
 *
 * Contrato: docs/API_CONTRATO_V1.md §7.8 y §7.9.
 *
 * Los binarios no se devuelven en base64: se entrega una URL firmada de vida
 * corta que el navegador puede abrir directamente (§10).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api_Report_Routes {

	public static function register_routes() {
		$ns = Edu_Api::API_NAMESPACE;

		/* ── Pagos ───────────────────────────────────────────────────── */

		register_rest_route(
			$ns,
			'/payment-config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_config' ),
					'permission_callback' => Edu_Api::require_cap( 'edu_view_all' ),
					'args'                => array(
						'period_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'save_config' ),
					'permission_callback' => Edu_Api::require_cap( 'edu_view_all' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/payments/generate-monthly',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'generate_monthly' ),
				'permission_callback' => Edu_Api::require_cap( 'edu_view_all' ),
			)
		);

		register_rest_route(
			$ns,
			'/payments/suspend-overdue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'suspend_overdue' ),
				'permission_callback' => Edu_Api::require_cap( 'edu_view_all' ),
			)
		);

		foreach ( array( 'manual', 'waive', 'link' ) as $action ) {
			register_rest_route(
				$ns,
				'/payments/(?P<id>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'payment_' . $action ),
					'permission_callback' => Edu_Api::require_cap( 'edu_view_all' ),
				)
			);
		}

		/* ── Reportes ────────────────────────────────────────────────── */

		register_rest_route(
			$ns,
			'/reports/boletin',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'boletin' ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
				'args'                => array(
					'student_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'period_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/reports/mineduc/(?P<tipo>[a-z\-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'mineduc' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_generate_reports', 'edu_view_all' ) ),
				'args'                => array(
					'period_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'grade_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		/* ── Dashboards ──────────────────────────────────────────────── */

		register_rest_route(
			$ns,
			'/dashboard/rector',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'dashboard_rector' ),
				'permission_callback' => Edu_Api::require_cap( 'edu_view_all' ),
				'args'                => array(
					'period_id'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'trimester_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/dashboard/docente',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'dashboard_docente' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
			)
		);

		register_rest_route(
			$ns,
			'/dashboard/teacher-panel',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'teacher_panel' ),
				'permission_callback' => Edu_Api::require_cap( 'edu_view_all' ),
				'args'                => array(
					'period_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		/* ── Descarga firmada ────────────────────────────────────────── */

		register_rest_route(
			$ns,
			'/files/(?P<id>\d+)/link',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'attachment_link' ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
				'args'                => array(
					'id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'type' => array(
						'type'    => 'string',
						'enum'    => array( 'assignment', 'submission' ),
						'default' => 'assignment',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/files/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'download' ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
				'args'                => array(
					'token' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	/* ─── Pagos ─────────────────────────────────────────────────────────── */

	public static function get_config( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'pagos' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Payment_Service::get_config( (int) $request->get_param( 'period_id' ) ) );
	}

	public static function save_config( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'pagos' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Payment_Service::save_config(
				array(
					'period_id' => (int) $request->get_param( 'period_id' ),
					'config'    => (array) ( $request->get_param( 'config' ) ?? array() ),
				)
			)
		);
	}

	public static function generate_monthly( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'pagos' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Payment_Service::generate_monthly( (int) $request->get_param( 'period_id' ) ) );
	}

	public static function payment_manual( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'pagos' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Payment_Service::register_manual(
				array(
					'payment_id'     => (int) $request['id'],
					'payment_method' => (string) $request->get_param( 'payment_method' ),
					'payment_ref'    => (string) $request->get_param( 'payment_ref' ),
				)
			)
		);
	}

	public static function payment_waive( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'pagos' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Payment_Service::waive( (int) $request['id'] ) );
	}

	public static function payment_link( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'pagos' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Payment_Service::generate_link( (int) $request['id'] ) );
	}

	public static function suspend_overdue( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'pagos' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Payment_Service::suspend_overdue(
				array(
					'period_id'      => (int) $request->get_param( 'period_id' ),
					'days_threshold' => (int) $request->get_param( 'days_threshold' ),
				)
			)
		);
	}

	/* ─── Reportes ──────────────────────────────────────────────────────── */

	public static function boletin( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Report_Service::boletin_url(
				(int) $request->get_param( 'student_id' ),
				(int) $request->get_param( 'period_id' )
			)
		);
	}

	public static function mineduc( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Report_Service::mineduc_url(
				sanitize_key( (string) $request['tipo'] ),
				array(
					'period_id' => (int) $request->get_param( 'period_id' ),
					'grade_id'  => (int) $request->get_param( 'grade_id' ),
				)
			)
		);
	}

	/* ─── Dashboards ────────────────────────────────────────────────────── */

	public static function dashboard_rector( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Report_Service::dashboard_rector(
				array(
					'period_id'    => (int) $request->get_param( 'period_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
				)
			)
		);
	}

	public static function dashboard_docente( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service( Edu_Report_Service::dashboard_docente() );
	}

	public static function teacher_panel( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Report_Service::teacher_panel( array( 'period_id' => (int) $request->get_param( 'period_id' ) ) )
		);
	}

	/* ─── Descarga firmada ──────────────────────────────────────────────── */

	/**
	 * Enlace de descarga de un adjunto de tarea o de entrega.
	 *
	 * El permiso lo decide el servicio a partir del padre (la tarea o la
	 * entrega), no del archivo suelto, y se vuelve a comprobar al descargar.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function attachment_link( WP_REST_Request $request ) {
		return Edu_Api::from_service(
			Edu_File_Service::attachment_link(
				(string) $request->get_param( 'type' ),
				(int) $request->get_param( 'id' )
			)
		);
	}

	/**
	 * Sirve el binario correspondiente a un token firmado.
	 *
	 * Reutiliza los generadores existentes (mPDF para el boletín,
	 * Edu_Xlsx_Writer para los exportes), que escriben directo a la salida.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public static function download( WP_REST_Request $request ) {
		$claims = Edu_File_Service::verify_signed_url( (string) $request->get_param( 'token' ) );

		if ( is_wp_error( $claims ) ) {
			return Edu_Api::from_service( $claims );
		}

		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		$args = (array) ( $claims['args'] ?? array() );

		switch ( $claims['kind'] ) {
			case 'attachment':
				// Se revalida igual que el boletín: entre la emisión y la
				// descarga el usuario pudo perder el alcance sobre la tarea.
				$found = Edu_File_Service::locate_attachment(
					(string) ( $args['type'] ?? '' ),
					(int) ( $args['file_id'] ?? 0 )
				);

				if ( is_wp_error( $found ) ) {
					return Edu_Api::from_service( $found );
				}

				Edu_File_Service::stream( $found['path'], $found['file_name'] );
				return;

			case 'boletin':
				// Se revalida el permiso: el token puede haberse emitido antes
				// de que cambiara el alcance del usuario.
				$allowed = Edu_Service::can_view_student( (int) ( $args['student_id'] ?? 0 ) );
				if ( is_wp_error( $allowed ) ) {
					return Edu_Api::from_service( $allowed );
				}

				require_once EDU_PLUGIN_DIR . 'modules/boletines/class-edu-boletin-generator.php';
				( new Edu_Boletin_Generator() )->stream(
					(int) $args['student_id'],
					(int) ( $args['period_id'] ?? 0 )
				);
				return;

			case 'mineduc':
				$cap = Edu_Service::require_cap( array( 'edu_generate_reports', 'edu_view_all' ) );
				if ( is_wp_error( $cap ) ) {
					return Edu_Api::from_service( $cap );
				}

				$period_id = (int) ( $args['period_id'] ?? 0 );
				$grade_id  = (int) ( $args['grade_id'] ?? 0 );

				switch ( (string) ( $args['tipo'] ?? '' ) ) {
					case 'acta-consolidada':
						Edu_Mineduc_Exporter::acta_consolidada( $period_id, $grade_id );
						return;
					case 'nomina-amie':
						Edu_Mineduc_Exporter::nomina_amie( $period_id, $grade_id );
						return;
					case 'distributivo-docente':
						Edu_Mineduc_Exporter::distributivo_docente( $period_id );
						return;
					case 'asistencia-acumulada':
						Edu_Mineduc_Exporter::asistencia_acumulada( $period_id, $grade_id );
						return;
				}

				return Edu_Api::error( 'edu_not_found', __( 'Tipo de exporte desconocido.', 'sistema-educativo' ), 404 );
		}

		return Edu_Api::error( 'edu_not_found', __( 'Recurso desconocido.', 'sistema-educativo' ), 404 );
	}

	/* ─── Helpers ───────────────────────────────────────────────────────── */

	private static function ready( WP_REST_Request $request, $module ) {
		$active = Edu_Api::require_module( $module );
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		$institution = Edu_Api::resolve_institution( $request );

		return is_wp_error( $institution ) ? $institution : true;
	}
}
