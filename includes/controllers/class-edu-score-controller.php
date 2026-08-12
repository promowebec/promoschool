<?php
/**
 * Adaptador HTTP: captura batch de notas desde los formularios de wp-admin y
 * del portal del docente.
 *
 * Toda la lógica vive en Edu_Score_Service. Aquí solo se hace lo propio del
 * transporte: verificar el nonce, traducir $_POST a la entrada del servicio y
 * convertir el resultado en una redirección.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Score_Controller {

	public static function handle_save_scores() {
		check_admin_referer( 'edu_save_scores' );

		$context = array(
			'grade_id'     => isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0,
			'subject_id'   => isset( $_POST['subject_id'] ) ? (int) $_POST['subject_id'] : 0,
			'trimester_id' => isset( $_POST['trimester_id'] ) ? (int) $_POST['trimester_id'] : 0,
			'parcial_num'  => isset( $_POST['parcial_num'] ) ? (int) $_POST['parcial_num'] : 0,
		);

		$matrix = isset( $_POST['scores'] ) ? (array) wp_unslash( $_POST['scores'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio valida y castea cada celda.

		$result = Edu_Score_Service::save_batch(
			$context + array( 'scores' => Edu_Score_Service::flatten_matrix( $matrix ) )
		);

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result, $context );
		}

		self::redirect(
			$context + array(
				'status' => 'updated',
				'saved'  => $result['saved'],
			)
		);
	}

	/* ─── Helpers de transporte ─────────────────────────────────────────── */

	/**
	 * Traduce el error del servicio a la respuesta que ya esperaban las vistas.
	 *
	 * @param WP_Error $error   Error del servicio.
	 * @param array    $context IDs del formulario.
	 */
	private static function handle_error( WP_Error $error, array $context ) {
		$code = $error->get_error_code();

		if ( in_array( $code, array( 'forbidden', 'no_institution' ), true ) ) {
			wp_die( esc_html( $error->get_error_message() ) );
		}

		$args = array(
			'status' => 'error',
			'code'   => $code,
		);

		// Este error conserva el contexto en la URL para no perder los filtros
		// de la pantalla; los demás vuelven a la vista limpia, como siempre.
		if ( 'no_components' === $code ) {
			$args += $context;
		}

		self::redirect( $args );
	}

	private static function redirect( $args = array() ) {
		if ( ! empty( $_POST['_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce ya se verificó en el handler.
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fe   = array(
				'edu_tab'    => 'calificaciones',
				'edu_status' => $args['status'] ?? '',
			);
			if ( ! empty( $args['grade_id'] ) ) {
				$fe['edu_grade'] = $args['grade_id'];
			}
			if ( ! empty( $args['subject_id'] ) ) {
				$fe['edu_subject'] = $args['subject_id'];
			}
			if ( ! empty( $args['trimester_id'] ) ) {
				$fe['edu_trim'] = $args['trimester_id'];
			}
			if ( ! empty( $args['parcial_num'] ) ) {
				$fe['edu_parcial'] = $args['parcial_num'];
			}
			wp_safe_redirect( add_query_arg( $fe, $base ) );
			exit;
		}

		$args = array_merge( array( 'page' => 'edu-calificaciones' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
