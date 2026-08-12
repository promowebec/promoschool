<?php
/**
 * Partial: selector de institución activa.
 *
 * - Superadmin Editorial: dropdown que cambia user meta `edu_current_institution_id`.
 * - edu_rector u otros: muestra el nombre de la institución asignada (no editable).
 * - Si no hay institución activa, muestra un aviso.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_super     = Edu_Context::is_superadmin_editorial();
$current_id   = Edu_Context::current_institution_id();
$institutions = Edu_Context::visible_institutions();
?>
<div class="edu-institution-switcher">
	<?php if ( $is_super && ! empty( $institutions ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-switcher-form">
			<?php wp_nonce_field( 'edu_set_active_institution' ); ?>
			<input type="hidden" name="action" value="edu_set_active_institution">
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ); ?>">
			<label class="edu-switcher-label"><?php esc_html_e( '🏫 Institución activa:', 'sistema-educativo' ); ?></label>
			<select name="institution_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $institutions as $inst ) : ?>
					<option value="<?php echo esc_attr( $inst->id ); ?>" <?php selected( $current_id, (int) $inst->id ); ?>>
						<?php echo esc_html( $inst->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $current_id ) : ?>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-institucion&action=edit&id=' . $current_id ) ); ?>"><?php esc_html_e( 'Editar', 'sistema-educativo' ); ?></a>
			<?php endif; ?>
		</form>
	<?php elseif ( ! $is_super && $current_id ) : ?>
		<?php
		$current = null;
		foreach ( $institutions as $i ) {
			if ( (int) $i->id === $current_id ) {
				$current = $i;
				break;
			}
		}
		?>
		<?php if ( $current ) : ?>
			<span class="edu-switcher-label"><?php echo esc_html( '🏫 ' . $current->name ); ?></span>
		<?php endif; ?>
	<?php endif; ?>
</div>

<?php if ( ! $current_id && $is_super ) : ?>
	<div class="notice notice-warning"><p>
		<?php
		printf(
			wp_kses_post( __( 'No hay institución activa. <a href="%s">Crea una</a> o selecciona arriba si ya existe.', 'sistema-educativo' ) ),
			esc_url( admin_url( 'admin.php?page=edu-institucion&action=new' ) )
		);
		?>
	</p></div>
<?php elseif ( ! $current_id && ! $is_super ) : ?>
	<div class="notice notice-error"><p>
		<?php esc_html_e( 'No tienes una institución asignada. Contacta al Superadmin Editorial.', 'sistema-educativo' ); ?>
	</p></div>
<?php endif; ?>
