<?php
/**
 * Adaptador HTTP: comunicados.
 *
 * La lógica vive en Edu_Announcement_Service. Aquí solo se verifica el nonce,
 * se traduce $_POST y se redirige.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Announcement_Controller {

	/* ─────────────────────────────────────────────────────────────────────
	 * Enviar
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_send() {
		check_admin_referer( 'edu_send_announcement' );

		$result = Edu_Announcement_Service::send(
			array(
				'scope'             => isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'grade',
				'target_grade_id'   => isset( $_POST['target_grade_id'] ) ? (int) $_POST['target_grade_id'] : 0,
				'target_student_id' => isset( $_POST['target_student_id'] ) ? (int) $_POST['target_student_id'] : 0,
				'title'             => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio aplica sanitize_text_field().
				'body'              => isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio aplica wp_kses_post().
				'send_email'        => ! empty( $_POST['send_email'] ),
				'send_whatsapp'     => ! empty( $_POST['send_whatsapp'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			// Los errores del formulario vuelven al formulario, no al listado.
			self::handle_error( $result, array( 'action' => 'new' ) );
		}

		self::redirect(
			array(
				'status' => 'sent',
				'id'     => $result['id'],
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Acuse de recibo (portal)
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_acknowledge() {
		$announcement_id = isset( $_POST['announcement_id'] ) ? (int) $_POST['announcement_id'] : 0;
		$nonce           = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'edu_acknowledge_' . $announcement_id ) ) {
			wp_die( esc_html__( 'Enlace inválido.', 'sistema-educativo' ) );
		}

		$result = Edu_Announcement_Service::acknowledge( $announcement_id );

		if ( is_wp_error( $result ) && 'not_authenticated' === $result->get_error_code() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'sistema-educativo' ) );
		}

		$page = get_option( 'edu_comunicados_page_id' );
		$url  = $page
			? add_query_arg( array( 'edu_ack' => $announcement_id ), get_permalink( $page ) )
			: add_query_arg( array( 'edu_ack' => $announcement_id ), home_url() );

		wp_safe_redirect( $url );
		exit;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Eliminar
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_announcement' );

		$result = Edu_Announcement_Service::delete( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result );
		}

		self::redirect( array( 'status' => 'deleted' ) );
	}

	/* ─── Helpers de transporte ─────────────────────────────────────────── */

	/**
	 * @param WP_Error $error Error del servicio.
	 * @param array    $extra Argumentos extra de la redirección.
	 */
	private static function handle_error( WP_Error $error, array $extra = array() ) {
		$code = $error->get_error_code();

		if ( in_array( $code, array( 'forbidden', 'no_institution' ), true ) ) {
			wp_die( esc_html( $error->get_error_message() ) );
		}

		self::redirect(
			$extra + array(
				'status' => 'error',
				'code'   => $code,
			)
		);
	}

	private static function redirect( $args = array() ) {
		if ( ! empty( $_POST['_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce ya se verificó en el handler.
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fe   = array(
				'edu_tab'    => 'comunicados',
				'edu_status' => $args['status'] ?? '',
			);
			if ( ! empty( $args['id'] ) ) {
				$fe['edu_id'] = $args['id'];
			}
			wp_safe_redirect( add_query_arg( $fe, $base ) );
			exit;
		}

		$args = array_merge( array( 'page' => 'edu-comunicados' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
