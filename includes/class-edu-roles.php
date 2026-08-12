<?php
/**
 * Roles personalizados del sistema educativo.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Roles {

	/**
	 * Versión de la definición de roles. Si cambia, maybe_sync_roles() re-aplica
	 * register_roles() para refrescar capabilities de roles ya creados.
	 */
	const ROLES_VERSION = '1.3.0';

	/**
	 * Definición de los 4 roles del dominio educativo y sus capabilities.
	 * Cada rol incluye `read` para que pueda iniciar sesión en el admin de WP.
	 */
	public static function get_roles() {
		return array(
			'edu_rector'     => array(
				'label' => __( 'Rector', 'sistema-educativo' ),
				'caps'  => array(
					'read'                                 => true,
					'edu_view_all'                         => true,
					'edu_manage_grades'                    => true,
					'edu_manage_subjects'                  => true,
					'edu_manage_teachers'                  => true,
					'edu_manage_students'                  => true,
					'edu_manage_parents'                   => true,
					'edu_manage_assignments'               => true,
					'edu_manage_curriculum'                => true,
					'edu_view_audit'                       => true,
					'edu_generate_reports'                 => true,
					'edu_close_partial'                    => true,
					'edu_send_institutional_announcements' => true,
					// El rector puede hacer todo lo que un docente puede hacer.
					'edu_grade_students'                   => true,
					'edu_create_assignment'                => true,
					'edu_take_attendance'                  => true,
					'edu_send_grade_announcement'          => true,
					'edu_view_own_grades'                  => true,
				),
			),
			'edu_docente'    => array(
				'label' => __( 'Docente', 'sistema-educativo' ),
				'caps'  => array(
					'read'                        => true,
					'edu_grade_students'          => true,
					'edu_create_assignment'       => true,
					'edu_take_attendance'         => true,
					'edu_send_grade_announcement' => true,
					'edu_view_own_grades'         => true,
				),
			),
			'edu_estudiante' => array(
				'label' => __( 'Estudiante', 'sistema-educativo' ),
				'caps'  => array(
					'read'                  => true,
					'edu_submit_assignment' => true,
					'edu_view_own_grades'   => true,
					'edu_view_assignments'  => true,
				),
			),
			'edu_padre'      => array(
				'label' => __( 'Padre / Representante', 'sistema-educativo' ),
				'caps'  => array(
					'read'                         => true,
					'edu_view_child_grades'        => true,
					'edu_view_child_attendance'    => true,
					'edu_read_announcements'       => true,
					'edu_acknowledge_announcement' => true,
				),
			),
		);
	}

	/**
	 * Registra los roles. Hace remove_role primero para refrescar capabilities
	 * cuando esta lista cambie en futuras versiones del plugin.
	 */
	public static function register_roles() {
		foreach ( self::get_roles() as $slug => $data ) {
			remove_role( $slug );
			add_role( $slug, $data['label'], $data['caps'] );
		}
	}

	public static function remove_roles() {
		foreach ( array_keys( self::get_roles() ) as $slug ) {
			remove_role( $slug );
		}
	}

	/**
	 * Re-aplica register_roles() cuando ROLES_VERSION cambia entre versiones del plugin.
	 * Llamado en admin_init para mantener las caps de roles sincronizadas sin requerir
	 * desactivar/reactivar el plugin.
	 */
	public static function maybe_sync_roles() {
		$current = get_option( 'edu_roles_version' );
		if ( $current === self::ROLES_VERSION ) {
			return;
		}
		self::register_roles();
		update_option( 'edu_roles_version', self::ROLES_VERSION );
	}
}
