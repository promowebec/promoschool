<?php
/**
 * Extiende el perfil de usuario de WordPress con el campo "Institución".
 *
 * Permite al Superadmin Editorial asignar o cambiar la institución
 * de cualquier usuario (rector, docente, estudiante, padre) directamente
 * desde Admin → Usuarios → Editar usuario, sin tocar la base de datos.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_User_Profile {

	/**
	 * Registra los hooks en el perfil.
	 */
	public static function register() {
		// Mostrar el campo en el perfil ajeno (admin editando otro usuario).
		add_action( 'edit_user_profile', array( __CLASS__, 'render_field' ) );
		// Mostrar en el propio perfil (por si el rector edita el suyo).
		add_action( 'show_user_profile', array( __CLASS__, 'render_field' ) );

		// Guardar al actualizar perfil ajeno.
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_field' ) );
		// Guardar al actualizar el propio perfil.
		add_action( 'personal_options_update', array( __CLASS__, 'save_field' ) );
	}

	/**
	 * Renderiza la sección "Sistema Educativo" en el formulario de perfil.
	 *
	 * @param WP_User $user Usuario que se está editando.
	 */
	public static function render_field( WP_User $user ) {
		// Solo el superadmin puede cambiar la institución de otros usuarios.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$institutions = $wpdb->get_results(
			"SELECT id, name FROM {$wpdb->prefix}edu_institutions ORDER BY name"
		);

		if ( empty( $institutions ) ) {
			return;
		}

		$current = (int) get_user_meta( $user->ID, 'edu_institution_id', true );
		$role    = self::edu_role_label( $user );
		?>
		<h2><?php esc_html_e( 'Sistema Educativo', 'sistema-educativo' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th>
					<label for="edu_institution_id">
						<?php esc_html_e( 'Institución asignada', 'sistema-educativo' ); ?>
					</label>
				</th>
				<td>
					<?php wp_nonce_field( 'edu_save_user_institution_' . $user->ID, '_edu_institution_nonce' ); ?>
					<select name="edu_institution_id" id="edu_institution_id">
						<option value="0"><?php esc_html_e( '— Sin asignar —', 'sistema-educativo' ); ?></option>
						<?php foreach ( $institutions as $inst ) : ?>
							<option value="<?php echo (int) $inst->id; ?>" <?php selected( $current, (int) $inst->id ); ?>>
								<?php echo esc_html( $inst->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php if ( $role ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: nombre del rol educativo */
								esc_html__( 'Rol educativo: %s', 'sistema-educativo' ),
								'<strong>' . esc_html( $role ) . '</strong>'
							);
							?>
						</p>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'La institución determina qué datos ve y gestiona este usuario dentro del plugin.', 'sistema-educativo' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Guarda el campo al enviar el formulario de perfil.
	 *
	 * @param int $user_id ID del usuario que se está guardando.
	 */
	public static function save_field( int $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_POST['_edu_institution_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_edu_institution_nonce'] ) ),
				'edu_save_user_institution_' . $user_id
			)
		) {
			return;
		}

		$institution_id = isset( $_POST['edu_institution_id'] ) ? (int) $_POST['edu_institution_id'] : 0;

		if ( $institution_id ) {
			update_user_meta( $user_id, 'edu_institution_id', $institution_id );
		} else {
			delete_user_meta( $user_id, 'edu_institution_id' );
		}
	}

	/**
	 * Devuelve la etiqueta legible del rol educativo del usuario, si tiene uno.
	 *
	 * @param WP_User $user
	 * @return string
	 */
	private static function edu_role_label( WP_User $user ): string {
		$map = array(
			'edu_rector'     => __( 'Rector', 'sistema-educativo' ),
			'edu_docente'    => __( 'Docente', 'sistema-educativo' ),
			'edu_estudiante' => __( 'Estudiante', 'sistema-educativo' ),
			'edu_padre'      => __( 'Representante', 'sistema-educativo' ),
		);
		foreach ( $map as $role => $label ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return $label;
			}
		}
		return '';
	}
}
