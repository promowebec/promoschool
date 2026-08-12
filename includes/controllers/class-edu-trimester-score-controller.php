<?php
/**
 * Adaptador HTTP: examen final, cierre de parcial y cierre de trimestre.
 *
 * La lógica vive en Edu_Trimester_Score_Service. Aquí solo se verifica el
 * nonce, se traduce $_POST y se redirige.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Trimester_Score_Controller {

	/* ─────────────────────────────────────────────────────────────────────
	 * Examen final y proyecto
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_save_exam() {
		check_admin_referer( 'edu_save_exam' );

		$context = array(
			'grade_id'     => isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0,
			'subject_id'   => isset( $_POST['subject_id'] ) ? (int) $_POST['subject_id'] : 0,
			'trimester_id' => isset( $_POST['trimester_id'] ) ? (int) $_POST['trimester_id'] : 0,
		);

		$exams     = isset( $_POST['exam'] ) ? (array) wp_unslash( $_POST['exam'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio castea cada nota.
		$proyectos = isset( $_POST['proyecto'] ) ? (array) wp_unslash( $_POST['proyecto'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = Edu_Trimester_Score_Service::save_exam(
			$context + array( 'students' => self::build_students( $exams, $proyectos ) )
		);

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result, array( __CLASS__, 'redirect_exam' ), array() );
		}

		// El aviso de error de base de datos que ya mostraba la pantalla.
		if ( ! empty( $result['db_error'] ) ) {
			set_transient( 'edu_exam_db_error', $result['db_error'], 60 );
		}

		self::redirect_exam(
			$context + array(
				'status' => 'updated',
				'saved'  => $result['saved'],
			)
		);
	}

	/**
	 * Une los arrays exam[sid] y proyecto[sid] en la lista que espera el
	 * servicio, respetando qué campos vinieron realmente en el formulario.
	 *
	 * @param array $exams     Mapa student_id => nota de examen.
	 * @param array $proyectos Mapa student_id => nota de proyecto.
	 * @return array
	 */
	private static function build_students( array $exams, array $proyectos ) {
		$sids = array_unique(
			array_map( 'intval', array_merge( array_keys( $exams ), array_keys( $proyectos ) ) )
		);

		$students = array();

		foreach ( $sids as $sid ) {
			$row = array( 'student_id' => $sid );

			if ( array_key_exists( $sid, $exams ) ) {
				$row['exam'] = $exams[ $sid ];
			}
			if ( array_key_exists( $sid, $proyectos ) ) {
				$row['proyecto'] = $proyectos[ $sid ];
			}

			$students[] = $row;
		}

		return $students;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Cierre de parcial
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_close_parcial() {
		check_admin_referer( 'edu_close_parcial' );

		$context = array(
			'grade_id'     => isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0,
			'subject_id'   => isset( $_POST['subject_id'] ) ? (int) $_POST['subject_id'] : 0,
			'trimester_id' => isset( $_POST['trimester_id'] ) ? (int) $_POST['trimester_id'] : 0,
			'parcial_num'  => isset( $_POST['parcial_num'] ) ? (int) $_POST['parcial_num'] : 0,
		);

		$result = Edu_Trimester_Score_Service::close_parcial( $context );

		if ( is_wp_error( $result ) ) {
			// no_students conserva el contexto para no perder los filtros.
			self::handle_error(
				$result,
				array( __CLASS__, 'redirect_cierres' ),
				array( 'no_students' => $context )
			);
		}

		self::redirect_cierres(
			$context + array( 'status' => 'parcial_closed' )
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Cierre de trimestre
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_close_trimester() {
		check_admin_referer( 'edu_close_trimester' );

		$context = array(
			'grade_id'     => isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0,
			'subject_id'   => isset( $_POST['subject_id'] ) ? (int) $_POST['subject_id'] : 0,
			'trimester_id' => isset( $_POST['trimester_id'] ) ? (int) $_POST['trimester_id'] : 0,
		);

		$result = Edu_Trimester_Score_Service::close_trimester( $context );

		if ( is_wp_error( $result ) ) {
			self::handle_error(
				$result,
				array( __CLASS__, 'redirect_cierres' ),
				array(
					'no_students'   => $context,
					'parcials_open' => $context,
				)
			);
		}

		self::redirect_cierres(
			$context + array( 'status' => 'trimester_closed' )
		);
	}

	/* ─── Helpers de transporte ─────────────────────────────────────────── */

	/**
	 * Traduce el error del servicio a la respuesta que ya esperaban las vistas.
	 *
	 * @param WP_Error $error        Error del servicio.
	 * @param callable $redirect_cb  Función de redirección de la pantalla.
	 * @param array    $with_context Códigos que conservan el contexto en la URL,
	 *                               como code => array de IDs.
	 */
	private static function handle_error( WP_Error $error, $redirect_cb, array $with_context ) {
		$code = $error->get_error_code();

		if ( in_array( $code, array( 'forbidden', 'no_institution' ), true ) ) {
			wp_die( esc_html( $error->get_error_message() ) );
		}

		$args = array(
			'status' => 'error',
			'code'   => $code,
		);

		if ( isset( $with_context[ $code ] ) ) {
			$args += $with_context[ $code ];
		}

		call_user_func( $redirect_cb, $args );
	}

	private static function redirect_exam( $args = array() ) {
		if ( ! empty( $_POST['_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce ya se verificó en el handler.
			wp_safe_redirect( esc_url_raw( wp_unslash( $_POST['_redirect'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			exit;
		}

		$args = array_merge( array( 'page' => 'edu-examen-final' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function redirect_cierres( $args = array() ) {
		if ( ! empty( $_POST['_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce ya se verificó en el handler.
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fe   = array(
				'edu_tab'    => 'cierres',
				'edu_status' => $args['status'] ?? '',
			);
			if ( ! empty( $args['code'] ) ) {
				$fe['edu_code'] = $args['code'];
			}
			if ( ! empty( $args['grade_id'] ) ) {
				$fe['edu_grade'] = $args['grade_id'];
			}
			if ( ! empty( $args['subject_id'] ) ) {
				$fe['edu_subj'] = $args['subject_id'];
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

		$args = array_merge( array( 'page' => 'edu-cierres' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
