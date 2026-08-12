<?php
/**
 * Shortcode [edu_mis_comunicados] — portal del padre / estudiante.
 *
 * Muestra los comunicados recibidos y permite confirmar lectura (acuse de recibo).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Shortcode_Comunicados {

	public static function register() {
		add_shortcode( 'edu_mis_comunicados', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Debes iniciar sesión para ver tus comunicados.', 'sistema-educativo' ) . '</p>';
		}

		$uid = get_current_user_id();

		global $wpdb;
		$ta = $wpdb->prefix . 'edu_announcements';
		$tr = $wpdb->prefix . 'edu_announcement_recipients';

		$edu_ack = isset( $_GET['edu_ack'] ) ? (int) $_GET['edu_ack'] : 0;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.id, a.title, a.body, a.sent_at, a.channels, a.sender_user_id,
			        u.display_name AS sender_name,
			        r.id AS recipient_id, r.read_at
			 FROM $tr r
			 INNER JOIN $ta a ON a.id = r.announcement_id
			 INNER JOIN {$wpdb->users} u ON u.ID = a.sender_user_id
			 WHERE r.user_id = %d AND r.channel = 'portal'
			 ORDER BY a.sent_at DESC",
			$uid
		) );

		if ( empty( $rows ) ) {
			return '<div class="edu-comunicados"><p>' . esc_html__( 'No tienes comunicados.', 'sistema-educativo' ) . '</p></div>';
		}

		ob_start();
		?>
		<div class="edu-comunicados">

		<?php if ( $edu_ack ) : ?>
			<div class="edu-notice edu-notice-success">
				<?php esc_html_e( 'Acuse de recibo confirmado.', 'sistema-educativo' ); ?>
			</div>
		<?php endif; ?>

		<?php foreach ( $rows as $row ) :
			$is_read  = ! empty( $row->read_at );
			$is_open  = ( (int) $row->id === $edu_ack ) || ! $is_read;

			// Etiqueta del rol del remitente.
			$sender_user  = get_userdata( (int) $row->sender_user_id );
			$sender_roles = $sender_user ? (array) $sender_user->roles : array();
			$role_map     = array(
				'edu_rector'     => __( 'Rector', 'sistema-educativo' ),
				'edu_docente'    => __( 'Docente', 'sistema-educativo' ),
				'administrator'  => __( 'Administrador', 'sistema-educativo' ),
			);
			$sender_role = '';
			foreach ( $role_map as $role => $label ) {
				if ( in_array( $role, $sender_roles, true ) ) {
					$sender_role = $label;
					break;
				}
			}
		?>
			<div class="edu-comunicado-card" style="border:1px solid <?php echo $is_read ? '#ddd' : '#0073aa'; ?>; border-radius:6px; margin-bottom:12px; overflow:hidden;">
				<div class="edu-comunicado-header" style="padding:12px 16px; background:<?php echo $is_read ? '#f9f9f9' : '#f0f8ff'; ?>; display:flex; justify-content:space-between; align-items:center; cursor:pointer;"
					onclick="(function(el){var b=el.closest('.edu-comunicado-card').querySelector('.edu-comunicado-body');b.style.display=b.style.display==='none'?'block':'none';})(this)">
					<div>
						<?php if ( ! $is_read ) : ?>
							<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#0073aa;margin-right:6px;"></span>
						<?php endif; ?>
						<strong><?php echo esc_html( $row->title ); ?></strong>
						<br>
						<small style="color:#555;">
							<?php esc_html_e( 'De:', 'sistema-educativo' ); ?>
							<strong><?php echo esc_html( $row->sender_name ); ?></strong>
							<?php if ( $sender_role ) : ?>
								<span style="margin-left:4px; padding:1px 6px; background:#e0e0e0; border-radius:3px; font-size:11px;">
									<?php echo esc_html( $sender_role ); ?>
								</span>
							<?php endif; ?>
						</small>
					</div>
					<div style="display:flex; gap:12px; align-items:center;">
						<small style="color:#666;"><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $row->sent_at ) ) ); ?></small>
						<?php if ( $is_read ) : ?>
							<span style="font-size:12px; color:green; font-weight:600;">
								✓ <?php esc_html_e( 'Leído', 'sistema-educativo' ); ?>
							</span>
						<?php else : ?>
							<span style="font-size:12px; color:#0073aa; font-weight:600;">
								<?php esc_html_e( 'Nuevo', 'sistema-educativo' ); ?>
							</span>
						<?php endif; ?>
					</div>
				</div>

				<div class="edu-comunicado-body" style="padding:16px; display:<?php echo $is_open ? 'block' : 'none'; ?>;">
					<div style="margin-bottom:16px;">
						<?php echo wp_kses_post( $row->body ); ?>
					</div>

					<?php if ( ! $is_read ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="border-top:1px solid #eee; padding-top:12px;">
						<?php wp_nonce_field( 'edu_acknowledge_' . (int) $row->id ); ?>
						<input type="hidden" name="action"          value="edu_acknowledge_announcement">
						<input type="hidden" name="announcement_id" value="<?php echo (int) $row->id; ?>">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Confirmar lectura (acuse de recibo)', 'sistema-educativo' ); ?>
						</button>
					</form>
					<?php else : ?>
						<p style="color:green; font-size:13px; border-top:1px solid #eee; padding-top:12px; margin:0;">
							✓ <?php esc_html_e( 'Acuse confirmado el', 'sistema-educativo' ); ?>
							<?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $row->read_at ) ) ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
