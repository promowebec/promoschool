<?php
/**
 * Adaptador HTTP: pensum (wp_edu_grade_subjects) y componentes evaluables
 * (wp_edu_grade_components).
 *
 * La lógica vive en Edu_Curriculum_Service. Aquí solo se verifica el nonce, se
 * traduce $_POST y se redirige.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Curriculum_Controller {

	/* ─────────────────────────────────────────────────────────────────────
	 * Pensum
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_save_pensum() {
		check_admin_referer( 'edu_save_pensum' );

		$grade_id = isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0;

		$result = Edu_Curriculum_Service::save_pensum(
			array(
				'grade_id'   => $grade_id,
				'subjects'   => isset( $_POST['subjects'] ) ? (array) wp_unslash( $_POST['subjects'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio castea cada ID.
				'hours_week' => isset( $_POST['hours_week'] ) ? (array) wp_unslash( $_POST['hours_week'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			)
		);

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result, array( __CLASS__, 'redirect_pensum' ) );
		}

		self::redirect_pensum(
			array(
				'grade_id' => $grade_id,
				'status'   => 'updated',
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Componentes evaluables
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_save_components() {
		check_admin_referer( 'edu_save_components' );

		$context = array(
			'subject_id'   => isset( $_POST['subject_id'] ) ? (int) $_POST['subject_id'] : 0,
			'trimester_id' => isset( $_POST['trimester_id'] ) ? (int) $_POST['trimester_id'] : 0,
			'parcial_num'  => isset( $_POST['parcial_num'] ) ? (int) $_POST['parcial_num'] : 0,
		);

		$ids     = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio castea y sanea cada fila.
		$names   = isset( $_POST['names'] ) ? (array) wp_unslash( $_POST['names'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$weights = isset( $_POST['weights'] ) ? (array) wp_unslash( $_POST['weights'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$rows = array();
		foreach ( $names as $idx => $name ) {
			$rows[] = array(
				'id'     => isset( $ids[ $idx ] ) ? (int) $ids[ $idx ] : 0,
				'name'   => $name,
				'weight' => $weights[ $idx ] ?? '',
			);
		}

		$result = Edu_Curriculum_Service::save_components( $context + array( 'rows' => $rows ) );

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result, array( __CLASS__, 'redirect_components' ) );
		}

		$args = $context;

		if ( $result['protegidos'] > 0 ) {
			$args['status']     = 'warning';
			$args['code']       = 'has_grades';
			$args['protegidos'] = $result['protegidos'];
		} elseif ( abs( $result['weight_sum'] - 1.0 ) > 0.01 ) {
			// Aviso informativo: el cálculo renormaliza, pero se muestra la suma.
			$args['status']    = 'warning';
			$args['code']      = 'weight_sum';
			$args['weightsum'] = number_format( $result['weight_sum'], 2, '.', '' );
		} else {
			$args['status'] = 'updated';
		}

		self::redirect_components( $args );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Compatibilidad
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Resuelve o crea el componente evaluable de una actividad.
	 *
	 * @deprecated Usar Edu_Curriculum_Service::resolve_or_create_component().
	 *             Se mantiene porque lo llaman el controller de tareas y las
	 *             integraciones externas previstas (Flipbook, H5P).
	 *
	 * @param int    $component_id ID existente (0 = ninguno).
	 * @param string $nuevo_nombre Nombre a crear ('' = no crear).
	 * @param float  $nuevo_peso   Peso del nuevo.
	 * @param int    $subject_id   Materia.
	 * @param int    $trimester_id Trimestre.
	 * @param int    $parcial_num  Parcial.
	 * @return int|null
	 */
	public static function resolve_or_create_component( $component_id, $nuevo_nombre, $nuevo_peso, $subject_id, $trimester_id, $parcial_num ) {
		return Edu_Curriculum_Service::resolve_or_create_component(
			$component_id,
			$nuevo_nombre,
			$nuevo_peso,
			$subject_id,
			$trimester_id,
			$parcial_num
		);
	}

	/* ─── Helpers de transporte ─────────────────────────────────────────── */

	/**
	 * Traduce el error del servicio a la respuesta que ya esperaban las vistas.
	 *
	 * @param WP_Error $error       Error del servicio.
	 * @param callable $redirect_cb Función de redirección de la pantalla.
	 */
	private static function handle_error( WP_Error $error, $redirect_cb ) {
		$code = $error->get_error_code();

		if ( in_array( $code, array( 'forbidden', 'no_institution' ), true ) ) {
			wp_die( esc_html( $error->get_error_message() ) );
		}

		call_user_func(
			$redirect_cb,
			array(
				'status' => 'error',
				'code'   => $code,
			)
		);
	}

	private static function redirect_pensum( $args = array() ) {
		$args = array_merge( array( 'page' => 'edu-pensum' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function redirect_components( $args = array() ) {
		$args = array_merge( array( 'page' => 'edu-componentes' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
