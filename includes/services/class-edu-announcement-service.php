<?php
/**
 * Servicio: comunicados.
 *
 * Envío con resolución de destinatarios (estudiantes + sus representantes),
 * acuse de recibo y borrado.
 *
 * El envío masivo por WhatsApp se difiere a cron desde el hook
 * `edu_announcement_sent`; aquí solo se marca el canal.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Announcement_Service {

	/**
	 * Envía un comunicado.
	 *
	 * @param array $input {
	 *     @type string $scope             student | grade | institution
	 *     @type int    $target_grade_id
	 *     @type int    $target_student_id
	 *     @type string $title
	 *     @type string $body
	 *     @type bool   $send_email
	 *     @type bool   $send_whatsapp
	 * }
	 * @return array|WP_Error
	 */
	public static function send( array $input ) {
		$cap = Edu_Service::require_cap( array( 'edu_grade_students', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$institution_id = Edu_Service::require_institution();
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$scope             = sanitize_key( (string) ( $input['scope'] ?? 'grade' ) );
		$target_grade_id   = isset( $input['target_grade_id'] ) ? (int) $input['target_grade_id'] : 0;
		$target_student_id = isset( $input['target_student_id'] ) ? (int) $input['target_student_id'] : 0;
		$title             = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$body              = wp_kses_post( (string) ( $input['body'] ?? '' ) );
		$send_email        = ! empty( $input['send_email'] );
		$send_whatsapp     = ! empty( $input['send_whatsapp'] )
			&& 'disabled' !== (string) get_option( 'edu_wa_provider', 'disabled' );

		// Solo el rectorado puede escribir a toda la institución.
		$allowed_scopes = Edu_Context::can( 'edu_view_all' )
			? array( 'student', 'grade', 'institution' )
			: array( 'student', 'grade' );

		if ( ! in_array( $scope, $allowed_scopes, true ) ) {
			return Edu_Service::error(
				'scope_not_allowed',
				__( 'No puedes enviar comunicados con ese alcance.', 'sistema-educativo' ),
				403
			);
		}

		if ( '' === $title ) {
			return Edu_Service::error( 'title_required', __( 'El comunicado necesita un título.', 'sistema-educativo' ), 400 );
		}

		if ( in_array( $scope, array( 'grade', 'student' ), true ) && $target_grade_id ) {
			$grade_scope = Edu_Service::check_scope( array( 'grade_id' => $target_grade_id ) );
			if ( is_wp_error( $grade_scope ) ) {
				return $grade_scope;
			}

			// Un docente solo escribe a los grados que dicta.
			if ( ! Edu_Service::sees_whole_institution() && ! in_array( $target_grade_id, Edu_Service::own_grade_ids(), true ) ) {
				return Edu_Service::out_of_scope();
			}
		}

		if ( 'student' === $scope && $target_student_id ) {
			$can_see = Edu_Service::can_view_student( $target_student_id );
			if ( is_wp_error( $can_see ) ) {
				return $can_see;
			}
		}

		global $wpdb;
		$ta = $wpdb->prefix . 'edu_announcements';
		$tr = $wpdb->prefix . 'edu_announcement_recipients';

		$channels = array( 'portal' );
		if ( $send_email ) {
			$channels[] = 'email';
		}
		if ( $send_whatsapp ) {
			$channels[] = 'whatsapp';
		}

		$wpdb->insert(
			$ta,
			array(
				'sender_user_id'    => get_current_user_id(),
				'scope'             => $scope,
				'target_grade_id'   => $target_grade_id ?: null,
				'target_student_id' => $target_student_id ?: null,
				'title'             => $title,
				'body'              => $body,
				'channels'          => implode( ',', $channels ),
				'sent_at'           => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		$announcement_id = (int) $wpdb->insert_id;

		if ( ! $announcement_id ) {
			return Edu_Service::error( 'db_error', __( 'No se pudo guardar el comunicado.', 'sistema-educativo' ), 500 );
		}

		$user_ids = self::resolve_recipients( $scope, $target_grade_id, $target_student_id, $institution_id );
		$now      = current_time( 'mysql' );
		$emailed  = 0;

		foreach ( $user_ids as $uid ) {
			$wpdb->insert(
				$tr,
				array(
					'announcement_id' => $announcement_id,
					'user_id'         => $uid,
					'channel'         => 'portal',
					'sent_at'         => $now,
				),
				array( '%d', '%d', '%s', '%s' )
			);

			if ( ! $send_email ) {
				continue;
			}

			$user = get_userdata( $uid );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			$sent = wp_mail(
				$user->user_email,
				wp_strip_all_tags( $title ),
				self::build_email_body( $title, $body ),
				array( 'Content-Type: text/html; charset=UTF-8' )
			);

			if ( $sent ) {
				$emailed++;
			}

			$wpdb->insert(
				$tr,
				array(
					'announcement_id' => $announcement_id,
					'user_id'         => $uid,
					'channel'         => 'email',
					'sent_at'         => $sent ? $now : null,
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}

		/**
		 * Comunicado enviado. El notificador de WhatsApp lo difiere a cron.
		 */
		do_action( 'edu_announcement_sent', $announcement_id );

		Edu_Audit::log(
			Edu_Audit::COMUNICADO_ENVIADO,
			'announcement',
			$announcement_id,
			null,
			array(
				'titulo'        => $title,
				'alcance'       => $scope,
				'grado_id'      => $target_grade_id ?: null,
				'estudiante_id' => $target_student_id ?: null,
				'destinatarios' => count( $user_ids ),
			)
		);

		return array(
			'id'           => $announcement_id,
			'recipients'   => count( $user_ids ),
			'emailed'      => $emailed,
			'channels'     => $channels,
		);
	}

	/**
	 * Marca un comunicado como leído por el usuario actual.
	 *
	 * @param int $announcement_id Comunicado.
	 * @return array|WP_Error
	 */
	public static function acknowledge( $announcement_id ) {
		if ( ! is_user_logged_in() ) {
			return Edu_Service::error( 'not_authenticated', __( 'Debes iniciar sesión.', 'sistema-educativo' ), 401 );
		}

		$announcement_id = (int) $announcement_id;

		global $wpdb;
		$tr = $wpdb->prefix . 'edu_announcement_recipients';

		// Solo se puede acusar recibo de lo que a uno le llegó.
		$is_recipient = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $tr WHERE announcement_id = %d AND user_id = %d",
				$announcement_id,
				get_current_user_id()
			)
		);

		if ( ! $is_recipient ) {
			return Edu_Service::not_found( __( 'Este comunicado no está dirigido a ti.', 'sistema-educativo' ) );
		}

		$wpdb->update(
			$tr,
			array( 'read_at' => current_time( 'mysql' ) ),
			array(
				'announcement_id' => $announcement_id,
				'user_id'         => get_current_user_id(),
				'channel'         => 'portal',
				'read_at'         => null,
			),
			array( '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);

		return array(
			'id'      => $announcement_id,
			'read_at' => Edu_Api::date( current_time( 'mysql' ) ),
		);
	}

	/**
	 * Elimina un comunicado.
	 *
	 * @param int $announcement_id Comunicado.
	 * @return array|WP_Error
	 */
	public static function delete( $announcement_id ) {
		$cap = Edu_Service::require_cap( array( 'edu_grade_students', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$announcement_id = (int) $announcement_id;

		global $wpdb;
		$ta = $wpdb->prefix . 'edu_announcements';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, sender_user_id, target_grade_id FROM $ta WHERE id = %d", $announcement_id )
		);

		if ( ! $row ) {
			return Edu_Service::not_found( __( 'El comunicado no existe.', 'sistema-educativo' ) );
		}

		// Quien no ve toda la institución solo puede borrar lo que él envió.
		if ( ! Edu_Service::sees_whole_institution() && (int) $row->sender_user_id !== get_current_user_id() ) {
			return Edu_Service::out_of_scope();
		}

		// El rector solo borra comunicados de su propia institución.
		if ( Edu_Service::sees_whole_institution() && $row->target_grade_id ) {
			$scope = Edu_Service::check_scope( array( 'grade_id' => (int) $row->target_grade_id ) );
			if ( is_wp_error( $scope ) ) {
				return $scope;
			}
		}

		$wpdb->delete( $ta, array( 'id' => $announcement_id ), array( '%d' ) );

		return array(
			'id'      => $announcement_id,
			'deleted' => true,
		);
	}

	/* ─── Internos ──────────────────────────────────────────────────────── */

	/**
	 * Usuarios a notificar: los estudiantes del alcance y sus representantes.
	 *
	 * @param string $scope          student | grade | institution.
	 * @param int    $grade_id       Grado destino.
	 * @param int    $student_id     Estudiante destino.
	 * @param int    $institution_id Institución activa.
	 * @return int[]
	 */
	private static function resolve_recipients( $scope, $grade_id, $student_id, $institution_id ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$students = array();

		if ( 'student' === $scope && $student_id ) {
			$students = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, user_id FROM {$p}students WHERE id = %d", (int) $student_id )
			);
		} elseif ( 'grade' === $scope && $grade_id ) {
			$students = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, user_id FROM {$p}students WHERE grade_id = %d AND status = 'active'", (int) $grade_id )
			);
		} elseif ( 'institution' === $scope ) {
			$students = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT st.id, st.user_id
					 FROM {$p}students st
					 INNER JOIN {$p}grades g ON g.id = st.grade_id
					 WHERE g.institution_id = %d AND st.status = 'active'",
					(int) $institution_id
				)
			);
		}

		if ( empty( $students ) ) {
			return array();
		}

		$user_ids    = array();
		$student_ids = array();

		foreach ( (array) $students as $student ) {
			$user_ids[]    = (int) $student->user_id;
			$student_ids[] = (int) $student->id;
		}

		// Representantes de todos ellos, en una sola consulta.
		$in      = implode( ',', array_map( 'intval', $student_ids ) );
		$parents = $wpdb->get_col(
			"SELECT DISTINCT pa.user_id
			 FROM {$p}parent_student ps
			 INNER JOIN {$p}parents pa ON pa.id = ps.parent_id
			 WHERE ps.student_id IN ($in)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$user_ids = array_merge( $user_ids, array_map( 'intval', (array) $parents ) );

		return array_values( array_unique( array_filter( $user_ids ) ) );
	}

	private static function build_email_body( $title, $body ) {
		return '<html><body style="font-family:sans-serif;max-width:600px;margin:auto;">'
			. '<h2>' . esc_html( $title ) . '</h2>'
			. '<div>' . wp_kses_post( $body ) . '</div>'
			. '<hr><small>' . esc_html( get_bloginfo( 'name' ) ) . '</small>'
			. '</body></html>';
	}
}
