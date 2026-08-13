<?php
/**
 * Endpoints de escritura: tareas, entregas, asistencia y comunicados.
 *
 * Contrato: docs/API_CONTRATO_V1.md §7.5, §7.6 y §7.7.
 * La lógica vive en los servicios; aquí solo se traduce la petición.
 *
 * Los adjuntos viajan como multipart/form-data en el campo `files[]`, igual
 * que en los formularios de wp-admin.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api_Write_Routes {

	public static function register_routes() {
		$ns = Edu_Api::API_NAMESPACE;

		/* ── Calificaciones ──────────────────────────────────────────── */

		register_rest_route(
			$ns,
			'/gradebook/scores',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_scores' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
			)
		);

		register_rest_route(
			$ns,
			'/components',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_component' ),
					'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'save_components' ),
					'permission_callback' => Edu_Api::require_cap( array( 'edu_manage_curriculum', 'edu_grade_students' ) ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/trimester-scores',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'save_exam' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
			)
		);

		foreach ( array( 'close-parcial' => 'close_parcial', 'close-trimester' => 'close_trimester' ) as $ruta => $metodo ) {
			register_rest_route(
				$ns,
				'/trimester-scores/' . $ruta,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $metodo ),
					'permission_callback' => Edu_Api::require_cap( 'edu_close_partial' ),
				)
			);
		}

		register_rest_route(
			$ns,
			'/grades/(?P<id>\d+)/pensum',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'save_pensum' ),
				'permission_callback' => Edu_Api::require_cap( 'edu_manage_curriculum' ),
			)
		);

		/* ── Tareas ──────────────────────────────────────────────────── */

		register_rest_route(
			$ns,
			'/assignments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_assignment' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_create_assignment', 'edu_view_all' ) ),
			)
		);

		register_rest_route(
			$ns,
			'/assignments/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_assignment' ),
					'permission_callback' => Edu_Api::require_cap( array( 'edu_create_assignment', 'edu_view_all' ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_assignment' ),
					'permission_callback' => Edu_Api::require_cap( array( 'edu_create_assignment', 'edu_view_all' ) ),
				),
			)
		);

		foreach ( array( 'publish', 'close' ) as $action ) {
			register_rest_route(
				$ns,
				'/assignments/(?P<id>\d+)/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $action . '_assignment' ),
					'permission_callback' => Edu_Api::require_cap( array( 'edu_create_assignment', 'edu_view_all' ) ),
				)
			);
		}

		register_rest_route(
			$ns,
			'/assignments/(?P<id>\d+)/recovery-settings',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'recovery_settings' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
			)
		);

		/* ── Entregas ────────────────────────────────────────────────── */

		register_rest_route(
			$ns,
			'/assignments/(?P<id>\d+)/submissions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => Edu_Api::require_cap( 'edu_submit_assignment' ),
			)
		);

		register_rest_route(
			$ns,
			'/assignments/(?P<id>\d+)/recovery',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit_recovery' ),
				'permission_callback' => Edu_Api::require_cap( 'edu_submit_assignment' ),
			)
		);

		register_rest_route(
			$ns,
			'/submissions/(?P<id>\d+)/grade',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'grade' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
			)
		);

		register_rest_route(
			$ns,
			'/submissions/(?P<id>\d+)/recovery-grade',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'grade_recovery' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
			)
		);

		/* ── Asistencia ──────────────────────────────────────────────── */

		register_rest_route(
			$ns,
			'/attendance',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'save_attendance' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_take_attendance', 'edu_view_all' ) ),
			)
		);

		/* ── Comunicados ─────────────────────────────────────────────── */

		register_rest_route(
			$ns,
			'/announcements',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'send_announcement' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_send_grade_announcement', 'edu_grade_students', 'edu_view_all' ) ),
			)
		);

		register_rest_route(
			$ns,
			'/announcements/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_announcement' ),
				'permission_callback' => Edu_Api::require_cap( array( 'edu_grade_students', 'edu_view_all' ) ),
			)
		);

		register_rest_route(
			$ns,
			'/announcements/(?P<id>\d+)/acknowledge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'acknowledge' ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Calificaciones
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * POST /gradebook/scores — captura batch de notas (contrato §8.2).
	 */
	public static function save_scores( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Score_Service::save_batch(
				array(
					'grade_id'     => (int) $request->get_param( 'grade_id' ),
					'subject_id'   => (int) $request->get_param( 'subject_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
					'parcial_num'  => (int) $request->get_param( 'parcial_num' ),
					'scores'       => (array) ( $request->get_param( 'scores' ) ?? array() ),
				)
			)
		);
	}

	/**
	 * POST /components — crea un componente al vuelo.
	 *
	 * Si ya existe uno con el mismo nombre en el set, se reutiliza en vez de
	 * duplicarlo: entonces responde 200 con `reused: true` en lugar de 201.
	 */
	public static function create_component( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		$subject_id   = (int) $request->get_param( 'subject_id' );
		$trimester_id = (int) $request->get_param( 'trimester_id' );
		$parcial_num  = (int) $request->get_param( 'parcial_num' );
		$nombre       = trim( (string) $request->get_param( 'name' ) );

		if ( '' === $nombre ) {
			return Edu_Api::invalid_params(
				array( 'name' => __( 'El componente necesita un nombre.', 'sistema-educativo' ) )
			);
		}

		global $wpdb;
		$tabla = $wpdb->prefix . 'edu_grade_components';

		$previo = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $tabla
				 WHERE subject_id = %d AND trimester_id = %d AND parcial_num = %d AND name = %s",
				$subject_id,
				$trimester_id,
				$parcial_num,
				sanitize_text_field( $nombre )
			)
		);

		$id = Edu_Curriculum_Service::resolve_or_create_component(
			0,
			$nombre,
			(float) ( $request->get_param( 'weight' ) ?: 1.00 ),
			$subject_id,
			$trimester_id,
			$parcial_num
		);

		if ( ! $id ) {
			return Edu_Api::error(
				'edu_out_of_scope',
				__( 'No puedes crear componentes en esta materia.', 'sistema-educativo' ),
				403
			);
		}

		$fila = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tabla WHERE id = %d", $id ) );

		return new WP_REST_Response(
			array(
				'id'           => (int) $id,
				'name'         => $fila ? $fila->name : $nombre,
				'weight'       => $fila ? Edu_Api::decimal( $fila->weight ) : 1.00,
				'subject_id'   => $subject_id,
				'trimester_id' => $trimester_id,
				'parcial_num'  => $parcial_num,
				'reused'       => (bool) $previo,
			),
			$previo ? 200 : 201
		);
	}

	/**
	 * PUT /components — guardado en bloque del set de componentes.
	 */
	public static function save_components( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Curriculum_Service::save_components(
				array(
					'subject_id'   => (int) $request->get_param( 'subject_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
					'parcial_num'  => (int) $request->get_param( 'parcial_num' ),
					'rows'         => (array) ( $request->get_param( 'rows' ) ?? array() ),
				)
			)
		);
	}

	/**
	 * PUT /trimester-scores — examen final y proyecto.
	 */
	public static function save_exam( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Trimester_Score_Service::save_exam(
				array(
					'grade_id'     => (int) $request->get_param( 'grade_id' ),
					'subject_id'   => (int) $request->get_param( 'subject_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
					'students'     => (array) ( $request->get_param( 'students' ) ?? array() ),
				)
			)
		);
	}

	public static function close_parcial( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Trimester_Score_Service::close_parcial(
				array(
					'grade_id'     => (int) $request->get_param( 'grade_id' ),
					'subject_id'   => (int) $request->get_param( 'subject_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
					'parcial_num'  => (int) $request->get_param( 'parcial_num' ),
				)
			)
		);
	}

	public static function close_trimester( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Trimester_Score_Service::close_trimester(
				array(
					'grade_id'     => (int) $request->get_param( 'grade_id' ),
					'subject_id'   => (int) $request->get_param( 'subject_id' ),
					'trimester_id' => (int) $request->get_param( 'trimester_id' ),
				)
			)
		);
	}

	/**
	 * PUT /grades/{id}/pensum — materias que se dictan en el grado.
	 */
	public static function save_pensum( WP_REST_Request $request ) {
		$institution = Edu_Api::resolve_institution( $request );
		if ( is_wp_error( $institution ) ) {
			return $institution;
		}

		return Edu_Api::from_service(
			Edu_Curriculum_Service::save_pensum(
				array(
					'grade_id'   => (int) $request['id'],
					'subjects'   => (array) ( $request->get_param( 'subjects' ) ?? array() ),
					'hours_week' => (array) ( $request->get_param( 'hours_week' ) ?? array() ),
				)
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Tareas
	 * ────────────────────────────────────────────────────────────────── */

	public static function create_assignment( WP_REST_Request $request ) {
		return self::save_assignment( $request, 0 );
	}

	public static function update_assignment( WP_REST_Request $request ) {
		return self::save_assignment( $request, (int) $request['id'] );
	}

	private static function save_assignment( WP_REST_Request $request, $id ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		// El campo unificado "Se evalúa como" del contrato §7.5.
		// En multipart llega como texto JSON; en application/json ya viene array.
		$component = self::decode_param( $request->get_param( 'component' ) );
		$mode      = isset( $component['mode'] ) ? sanitize_key( (string) $component['mode'] ) : 'none';

		$input = array(
			'id'                   => $id,
			'grade_id'             => (int) $request->get_param( 'grade_id' ),
			'subject_id'           => (int) $request->get_param( 'subject_id' ),
			'trimester_id'         => (int) $request->get_param( 'trimester_id' ),
			'parcial_num'          => (int) $request->get_param( 'parcial_num' ),
			'title'                => (string) $request->get_param( 'title' ),
			'description'          => (string) $request->get_param( 'description' ),
			'due_date'             => (string) $request->get_param( 'due_date' ),
			'max_score'            => null !== $request->get_param( 'max_score' ) ? (float) $request->get_param( 'max_score' ) : 10.00,
			'notify_parents'       => (bool) $request->get_param( 'notify_parents' ),
			'publish_now'          => (bool) $request->get_param( 'publish_now' ),
			'type'                 => (string) $request->get_param( 'type' ),
			'component_id'         => 'existing' === $mode ? (int) ( $component['id'] ?? 0 ) : 0,
			'component_new_name'   => 'create' === $mode ? (string) ( $component['name'] ?? '' ) : '',
			'component_new_weight' => isset( $component['weight'] ) ? (float) $component['weight'] : 1.00,
			'files'                => self::files( 'files' ),
			'delete_files'         => self::decode_param( $request->get_param( 'delete_files' ) ),
		);

		$result = Edu_Assignment_Service::save( $input );

		if ( is_wp_error( $result ) ) {
			return Edu_Api::from_service( $result );
		}

		$response = new WP_REST_Response( $result, $result['created'] ? 201 : 200 );

		if ( $result['created'] ) {
			$response->header( 'Location', rest_url( Edu_Api::API_NAMESPACE . '/assignments/' . $result['id'] ) );
		}

		return $response;
	}

	public static function publish_assignment( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Assignment_Service::publish( (int) $request['id'] ) );
	}

	public static function close_assignment( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Assignment_Service::close( (int) $request['id'] ) );
	}

	public static function delete_assignment( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Assignment_Service::delete( (int) $request['id'] ) );
	}

	public static function recovery_settings( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Submission_Service::save_recovery_settings(
				array(
					'assignment_id'     => (int) $request['id'],
					'allow_recovery'    => (bool) $request->get_param( 'allow_recovery' ),
					'recovery_due_date' => (string) $request->get_param( 'recovery_due_date' ),
				)
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Entregas
	 * ────────────────────────────────────────────────────────────────── */

	public static function submit( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = Edu_Submission_Service::submit(
			array(
				'assignment_id' => (int) $request['id'],
				'comment'       => (string) $request->get_param( 'comment' ),
				'files'         => self::files( 'files' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return Edu_Api::from_service( $result );
		}

		return new WP_REST_Response( $result, 201 );
	}

	public static function submit_recovery( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = Edu_Submission_Service::submit_recovery(
			array(
				'assignment_id' => (int) $request['id'],
				'comment'       => (string) $request->get_param( 'comment' ),
				'files'         => self::files( 'files' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return Edu_Api::from_service( $result );
		}

		return new WP_REST_Response( $result, 201 );
	}

	public static function grade( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Submission_Service::grade(
				array(
					'submission_id' => (int) $request['id'],
					'score'         => $request->get_param( 'score' ),
					'feedback'      => (string) $request->get_param( 'feedback' ),
				)
			)
		);
	}

	public static function grade_recovery( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'tareas' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Submission_Service::grade_recovery(
				array(
					'submission_id'     => (int) $request['id'],
					'recovery_score'    => $request->get_param( 'recovery_score' ),
					'recovery_feedback' => (string) $request->get_param( 'recovery_feedback' ),
				)
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Asistencia
	 * ────────────────────────────────────────────────────────────────── */

	public static function save_attendance( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'asistencia' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service(
			Edu_Attendance_Service::save(
				array(
					'grade_id'   => (int) $request->get_param( 'grade_id' ),
					'subject_id' => (int) $request->get_param( 'subject_id' ),
					'date'       => (string) $request->get_param( 'date' ),
					'students'   => (array) ( $request->get_param( 'students' ) ?? array() ),
				)
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Comunicados
	 * ────────────────────────────────────────────────────────────────── */

	public static function send_announcement( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'comunicados' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = Edu_Announcement_Service::send(
			array(
				'scope'             => (string) $request->get_param( 'scope' ),
				'target_grade_id'   => (int) $request->get_param( 'target_grade_id' ),
				'target_student_id' => (int) $request->get_param( 'target_student_id' ),
				'title'             => (string) $request->get_param( 'title' ),
				'body'              => (string) $request->get_param( 'body' ),
				'send_email'        => (bool) $request->get_param( 'send_email' ),
				'send_whatsapp'     => (bool) $request->get_param( 'send_whatsapp' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return Edu_Api::from_service( $result );
		}

		// 202: el envío por WhatsApp queda encolado en cron (contrato §7.7).
		return new WP_REST_Response( $result, 202 );
	}

	public static function delete_announcement( WP_REST_Request $request ) {
		$ready = self::ready( $request, 'comunicados' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Edu_Api::from_service( Edu_Announcement_Service::delete( (int) $request['id'] ) );
	}

	public static function acknowledge( WP_REST_Request $request ) {
		$active = Edu_Api::require_module( 'comunicados' );
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		return Edu_Api::from_service( Edu_Announcement_Service::acknowledge( (int) $request['id'] ) );
	}

	/* ─── Helpers ───────────────────────────────────────────────────────── */

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

	/**
	 * Normaliza un parámetro que puede llegar como array (JSON) o como cadena
	 * JSON (multipart/form-data).
	 *
	 * @param mixed $valor Valor crudo del parámetro.
	 * @return array
	 */
	private static function decode_param( $valor ) {
		if ( is_array( $valor ) ) {
			return $valor;
		}

		if ( is_string( $valor ) && '' !== $valor ) {
			$decodificado = json_decode( $valor, true );
			return is_array( $decodificado ) ? $decodificado : array();
		}

		return array();
	}

	/**
	 * Archivos subidos en un campo múltiple, en la forma que espera
	 * Edu_File_Service (la estructura nativa de $_FILES).
	 *
	 * Acepta tanto `files[]` (varios) como `files` (uno solo).
	 *
	 * @param string $field Nombre del campo.
	 * @return array
	 */
	private static function files( $field ) {
		if ( empty( $_FILES[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- autenticación por token; Edu_File_Service valida cada archivo.
			return array();
		}

		$files = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Campo simple: se normaliza a la forma múltiple.
		if ( ! is_array( $files['name'] ) ) {
			foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $key ) {
				$files[ $key ] = array( $files[ $key ] );
			}
		}

		return $files;
	}
}
