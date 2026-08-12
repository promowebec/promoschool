<?php
/**
 * Endpoints del usuario autenticado: /me y /me/password.
 *
 * GET /me es la primera llamada que hace la app: con una sola respuesta ya
 * sabe quién entró, qué puede hacer, en qué institución está, qué módulos
 * tiene encendidos y qué navegación pintar.
 *
 * Contrato: docs/API_CONTRATO_V1.md §7.1 y §9.1.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Api_Me_Routes {

	public static function register_routes() {
		$ns = Edu_Api::API_NAMESPACE;

		register_rest_route(
			$ns,
			'/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'me' ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
			)
		);

		register_rest_route(
			$ns,
			'/me/password',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'change_password' ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
				'args'                => array(
					'current_password' => array(
						'type'     => 'string',
						'required' => true,
					),
					'new_password'     => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/me/sessions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'sessions' ),
				'permission_callback' => array( 'Edu_Api', 'require_login' ),
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────── */

	/**
	 * GET /me
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function me( WP_REST_Request $request ) {
		return new WP_REST_Response( self::build_me( wp_get_current_user(), $request ), 200 );
	}

	/**
	 * PUT /me/password — cambia la contraseña y cierra las demás sesiones.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function change_password( WP_REST_Request $request ) {
		$user    = wp_get_current_user();
		$current = (string) $request->get_param( 'current_password' );
		$new     = (string) $request->get_param( 'new_password' );

		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return Edu_Api::invalid_params(
				array( 'current_password' => __( 'La contraseña actual no es correcta.', 'sistema-educativo' ) )
			);
		}

		if ( strlen( $new ) < 8 ) {
			return Edu_Api::invalid_params(
				array( 'new_password' => __( 'La contraseña nueva debe tener al menos 8 caracteres.', 'sistema-educativo' ) )
			);
		}

		// wp_set_password() dispara profile_update, que revoca las sesiones de
		// la API (Edu_Api::on_profile_update).
		wp_set_password( $new, $user->ID );

		Edu_Audit::log( 'api_password_cambiada', 'usuario', $user->ID, null, array( 'canal' => 'api' ) );

		return new WP_REST_Response(
			array(
				'updated' => true,
				'message' => __( 'Contraseña actualizada. Vuelve a iniciar sesión.', 'sistema-educativo' ),
			),
			200
		);
	}

	/**
	 * GET /me/sessions — dispositivos con sesión abierta.
	 *
	 * @return WP_REST_Response
	 */
	public static function sessions() {
		return new WP_REST_Response( Edu_Api_Auth::list_sessions( get_current_user_id() ), 200 );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Construcción del objeto Usuario
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Arma el objeto que describe al usuario autenticado.
	 *
	 * Lo reutiliza /auth/token para no obligar a la app a hacer dos llamadas
	 * seguidas al iniciar sesión.
	 *
	 * @param WP_User              $user    Usuario.
	 * @param WP_REST_Request|null $request Request (para la cabecera de institución).
	 * @return array
	 */
	public static function build_me( WP_User $user, $request = null ) {
		$institution_id = Edu_Api::resolve_institution( $request );
		$institution_id = is_wp_error( $institution_id ) ? 0 : (int) $institution_id;

		return array(
			'id'             => (int) $user->ID,
			'email'          => $user->user_email,
			'username'       => $user->user_login,
			'nombres'        => get_user_meta( $user->ID, 'first_name', true ),
			'apellidos'      => get_user_meta( $user->ID, 'last_name', true ),
			'display_name'   => $user->display_name,
			'roles'          => array_values( (array) $user->roles ),
			'capabilities'   => self::edu_capabilities( $user ),
			'account_status' => get_user_meta( $user->ID, 'edu_account_status', true ) ?: 'active',
			'institution'    => self::institution( $institution_id ),
			'active_period'  => self::active_period( $institution_id ),
			'modules'        => self::modules(),
			'profile'        => self::profile( $user, $institution_id ),
		);
	}

	/**
	 * Capabilities relevantes del sistema: las edu_* más manage_options.
	 *
	 * @param WP_User $user Usuario.
	 * @return string[]
	 */
	private static function edu_capabilities( WP_User $user ) {
		$caps = array();

		foreach ( (array) $user->allcaps as $cap => $granted ) {
			if ( ! $granted ) {
				continue;
			}
			if ( 0 === strpos( (string) $cap, 'edu_' ) || 'manage_options' === $cap ) {
				$caps[] = (string) $cap;
			}
		}

		sort( $caps );

		return $caps;
	}

	/**
	 * Institución activa del usuario.
	 *
	 * @param int $institution_id ID.
	 * @return array|null
	 */
	private static function institution( $institution_id ) {
		if ( ! $institution_id ) {
			return null;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, logo_url, regime FROM {$wpdb->prefix}edu_institutions WHERE id = %d",
				$institution_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'       => (int) $row->id,
			'name'     => $row->name,
			'logo_url' => $row->logo_url ? esc_url_raw( $row->logo_url ) : null,
			'regime'   => $row->regime,
		);
	}

	/**
	 * Período lectivo activo de la institución, con sus trimestres.
	 *
	 * @param int $institution_id ID.
	 * @return array|null
	 */
	private static function active_period( $institution_id ) {
		if ( ! $institution_id ) {
			return null;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, regime, start_date, end_date, working_days, num_trimesters
				 FROM {$p}periods
				 WHERE institution_id = %d AND is_active = 1
				 ORDER BY id DESC LIMIT 1",
				$institution_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$trimesters = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, number, start_date, end_date, is_closed
				 FROM {$p}trimesters
				 WHERE period_id = %d
				 ORDER BY number",
				(int) $row->id
			)
		);

		return array(
			'id'             => (int) $row->id,
			'name'           => $row->name,
			'regime'         => $row->regime,
			'start_date'     => $row->start_date,
			'end_date'       => $row->end_date,
			'working_days'   => (int) $row->working_days,
			'num_trimesters' => (int) $row->num_trimesters,
			'trimesters'     => array_map(
				static function ( $t ) {
					return array(
						'id'         => (int) $t->id,
						'number'     => (int) $t->number,
						'start_date' => $t->start_date,
						'end_date'   => $t->end_date,
						'is_closed'  => Edu_Api::boolean( $t->is_closed ),
					);
				},
				(array) $trimesters
			),
		);
	}

	/**
	 * Mapa de módulos activos, para que la app oculte la navegación en vez de
	 * descubrirlo con un 404.
	 *
	 * @return array<string, bool>
	 */
	private static function modules() {
		$out = array();

		foreach ( array_keys( Edu_Modules::catalog() ) as $slug ) {
			$out[ $slug ] = Edu_Modules::is_active( $slug );
		}

		return $out;
	}

	/**
	 * Perfil según el tipo de usuario. Se incluyen todos los bloques que
	 * apliquen: un rector puede ser además docente.
	 *
	 * @param WP_User $user           Usuario.
	 * @param int     $institution_id Institución resuelta.
	 * @return array
	 */
	private static function profile( WP_User $user, $institution_id ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$profile = array(
			'type'        => 'rector',
			'teacher_id'  => null,
			'assignments' => array(),
			'student_id'  => null,
			'grade'       => null,
			'parent_id'   => null,
			'children'    => array(),
		);

		$roles = (array) $user->roles;

		/* Docente ------------------------------------------------------- */
		$teacher_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$p}teachers WHERE user_id = %d", $user->ID )
		);

		if ( $teacher_id ) {
			$profile['teacher_id']  = $teacher_id;
			$profile['assignments'] = self::teacher_assignments( $teacher_id );
		}

		/* Estudiante ---------------------------------------------------- */
		$student = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.id, s.grade_id, s.status, g.name AS grade_name, g.paralelo, g.sub_level, g.level, g.specialty
				 FROM {$p}students s
				 INNER JOIN {$p}grades g ON g.id = s.grade_id
				 WHERE s.user_id = %d",
				$user->ID
			)
		);

		if ( $student ) {
			$profile['student_id'] = (int) $student->id;
			$profile['grade']      = array(
				'id'        => (int) $student->grade_id,
				'name'      => $student->grade_name,
				'paralelo'  => $student->paralelo,
				'level'     => $student->level,
				'sub_level' => $student->sub_level,
				'specialty' => $student->specialty,
			);
		}

		/* Representante -------------------------------------------------- */
		$parent_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$p}parents WHERE user_id = %d", $user->ID )
		);

		if ( $parent_id ) {
			$profile['parent_id'] = $parent_id;
			$profile['children']  = self::parent_children( $parent_id );
		}

		/* Tipo primario -------------------------------------------------- */
		if ( Edu_Context::is_superadmin_editorial() ) {
			$profile['type'] = 'superadmin';
		} elseif ( in_array( 'edu_rector', $roles, true ) ) {
			$profile['type'] = 'rector';
		} elseif ( in_array( 'edu_docente', $roles, true ) || $teacher_id ) {
			$profile['type'] = 'teacher';
		} elseif ( in_array( 'edu_estudiante', $roles, true ) || $profile['student_id'] ) {
			$profile['type'] = 'student';
		} elseif ( in_array( 'edu_padre', $roles, true ) || $parent_id ) {
			$profile['type'] = 'parent';
		}

		unset( $institution_id );

		return $profile;
	}

	/**
	 * Asignaciones académicas activas del docente.
	 *
	 * @param int $teacher_id ID en wp_edu_teachers.
	 * @return array
	 */
	private static function teacher_assignments( $teacher_id ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ta.id, ta.grade_id, ta.subject_id, ta.period_id,
				        g.name AS grade_name, g.paralelo, g.sub_level,
				        s.name AS subject_name
				 FROM {$p}teacher_assignments ta
				 INNER JOIN {$p}grades g   ON g.id = ta.grade_id
				 INNER JOIN {$p}subjects s ON s.id = ta.subject_id
				 WHERE ta.teacher_id = %d AND ta.is_active = 1
				 ORDER BY g.name, s.name",
				$teacher_id
			)
		);

		return array_map(
			static function ( $r ) {
				return array(
					'id'           => (int) $r->id,
					'grade_id'     => (int) $r->grade_id,
					'grade_name'   => trim( $r->grade_name . ' ' . $r->paralelo ),
					'sub_level'    => $r->sub_level,
					'subject_id'   => (int) $r->subject_id,
					'subject_name' => $r->subject_name,
					'period_id'    => (int) $r->period_id,
				);
			},
			(array) $rows
		);
	}

	/**
	 * Hijos vinculados al representante.
	 *
	 * Los nombres viven en wp_usermeta, no en wp_edu_students.
	 *
	 * @param int $parent_id ID en wp_edu_parents.
	 * @return array
	 */
	private static function parent_children( $parent_id ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.user_id, s.grade_id, s.status,
				        g.name AS grade_name, g.paralelo, g.sub_level,
				        ps.relationship, ps.is_primary,
				        COALESCE(um_fn.meta_value, '') AS first_name,
				        COALESCE(um_ln.meta_value, u.display_name) AS last_name
				 FROM {$p}parent_student ps
				 INNER JOIN {$p}students s ON s.id = ps.student_id
				 INNER JOIN {$p}grades g   ON g.id = s.grade_id
				 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
				 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
				 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
				 WHERE ps.parent_id = %d
				 ORDER BY last_name, first_name",
				$parent_id
			)
		);

		return array_map(
			static function ( $r ) {
				return array(
					'student_id'   => (int) $r->id,
					'nombres'      => $r->first_name,
					'apellidos'    => $r->last_name,
					'status'       => $r->status,
					'relationship' => $r->relationship,
					'is_primary'   => Edu_Api::boolean( $r->is_primary ),
					'grade'        => array(
						'id'        => (int) $r->grade_id,
						'name'      => $r->grade_name,
						'paralelo'  => $r->paralelo,
						'sub_level' => $r->sub_level,
					),
				);
			},
			(array) $rows
		);
	}
}
