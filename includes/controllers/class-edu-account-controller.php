<?php
/**
 * Controller: activar / suspender cuentas de padres y estudiantes.
 *
 * Suspender un padre desactiva automáticamente todos sus hijos vinculados.
 * Reactivar un padre reactiva a todos sus hijos.
 * Cada cambio queda registrado en wp_edu_audit.
 *
 * Acciones:
 *   admin_post_edu_toggle_account_status  → handle_toggle()
 *   admin_post_edu_bulk_account_status    → handle_bulk()
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Account_Controller {

	/* -----------------------------------------------------------------------
	 * Acciones públicas
	 * -------------------------------------------------------------------- */

	/**
	 * Cambia el estado de una única cuenta.
	 * POST: user_id, new_status (active|suspended), _wpnonce, _redirect (opcional)
	 */
	public static function handle_toggle() {
		check_admin_referer( 'edu_toggle_account_status' );
		self::guard();

		$user_id    = isset( $_POST['user_id'] )    ? (int) $_POST['user_id']                  : 0;
		$new_status = isset( $_POST['new_status'] ) ? sanitize_key( $_POST['new_status'] )     : '';

		if ( ! $user_id || ! in_array( $new_status, array( 'active', 'suspended' ), true ) ) {
			self::redirect( array( 'status' => 'error', 'code' => 'invalid_params' ) );
		}

		$result = self::set_status( $user_id, $new_status );

		self::redirect( array(
			'status'  => $result ? 'updated' : 'error',
			'code'    => $result ? '' : 'db_error',
			'changed' => $result,
		) );
	}

	/**
	 * Cambia el estado de múltiples cuentas de una vez.
	 * POST: user_ids[] (array de IDs), bulk_action (activate|suspend), _wpnonce, _redirect (opcional)
	 */
	public static function handle_bulk() {
		check_admin_referer( 'edu_bulk_account_status' );
		self::guard();

		$user_ids    = isset( $_POST['user_ids'] ) ? array_map( 'intval', (array) $_POST['user_ids'] ) : array();
		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';

		if ( empty( $user_ids ) || ! in_array( $bulk_action, array( 'activate', 'suspend' ), true ) ) {
			self::redirect( array( 'status' => 'error', 'code' => 'nothing_selected' ) );
		}

		$new_status = ( 'activate' === $bulk_action ) ? 'active' : 'suspended';
		$changed    = 0;

		foreach ( $user_ids as $uid ) {
			if ( $uid > 0 && self::set_status( $uid, $new_status ) ) {
				$changed++;
			}
		}

		self::redirect( array( 'status' => 'updated', 'changed' => $changed ) );
	}

	/* -----------------------------------------------------------------------
	 * Lógica central
	 * -------------------------------------------------------------------- */

	/**
	 * Aplica el nuevo estado a un usuario y en cascada a sus hijos si es padre.
	 *
	 * @param int    $user_id    ID del usuario WordPress.
	 * @param string $new_status 'active' | 'suspended'
	 * @return bool  true si el cambio fue efectivo (estado anterior era diferente).
	 */
	public static function set_status( int $user_id, string $new_status ): bool {
		$old_status = get_user_meta( $user_id, 'edu_account_status', true ) ?: 'active';

		if ( $old_status === $new_status ) {
			return false;
		}

		update_user_meta( $user_id, 'edu_account_status', $new_status );

		$action = ( 'suspended' === $new_status ) ? Edu_Audit::CUENTA_SUSPENDIDA : Edu_Audit::CUENTA_ACTIVADA;
		Edu_Audit::log( $action, 'usuario', $user_id, $old_status, $new_status );

		if ( 'suspended' === $new_status ) {
			/**
			 * Cuenta suspendida. La API lo usa para revocar los tokens vivos
			 * sin esperar a que expiren.
			 *
			 * @param int $user_id ID del usuario suspendido.
			 */
			do_action( 'edu_account_suspended', $user_id );
		}

		// Cascada: si el usuario es padre, actualizar todos sus hijos.
		$user = get_userdata( $user_id );
		if ( $user && in_array( 'edu_padre', (array) $user->roles, true ) ) {
			self::cascade_children( $user_id, $new_status );
		}

		return true;
	}

	/**
	 * Propaga el estado a todos los estudiantes vinculados a un padre.
	 *
	 * @param int    $parent_user_id  wp_users.ID del padre.
	 * @param string $new_status      'active' | 'suspended'
	 */
	private static function cascade_children( int $parent_user_id, string $new_status ): void {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$parent_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}parents WHERE user_id = %d",
			$parent_user_id
		) );

		if ( ! $parent_id ) {
			return;
		}

		$student_user_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT s.user_id
			 FROM {$p}parent_student ps
			 INNER JOIN {$p}students s ON s.id = ps.student_id
			 WHERE ps.parent_id = %d",
			$parent_id
		) );

		foreach ( $student_user_ids as $sid ) {
			$sid = (int) $sid;
			if ( ! $sid ) {
				continue;
			}
			$old = get_user_meta( $sid, 'edu_account_status', true ) ?: 'active';
			if ( $old === $new_status ) {
				continue;
			}
			update_user_meta( $sid, 'edu_account_status', $new_status );
			$act = ( 'suspended' === $new_status ) ? Edu_Audit::CUENTA_SUSPENDIDA : Edu_Audit::CUENTA_ACTIVADA;
			Edu_Audit::log( $act, 'usuario', $sid, $old, $new_status );

			if ( 'suspended' === $new_status ) {
				/** This action is documented in includes/controllers/class-edu-account-controller.php */
				do_action( 'edu_account_suspended', $sid );
			}
		}
	}

	/* -----------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------- */

	private static function guard(): void {
		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}
	}

	private static function redirect( array $args = array() ): void {
		$base = ! empty( $_POST['_redirect'] )
			? esc_url_raw( wp_unslash( $_POST['_redirect'] ) )
			: admin_url( 'admin.php?page=edu-cuentas' );

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}
}
