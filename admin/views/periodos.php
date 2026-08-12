<?php
/**
 * Vista: ABM de períodos lectivos y sus trimestres.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_manage_grades' ) ) {
	wp_die( esc_html__( 'No tienes permiso.', 'sistema-educativo' ) );
}

echo '<div class="wrap edu-wrap">';

require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php';

$current_institution_id = Edu_Context::current_institution_id();
if ( ! $current_institution_id ) {
	echo '</div>';
	return;
}

$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
$edit_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

global $wpdb;
$tp = $wpdb->prefix . 'edu_periods';
$tt = $wpdb->prefix . 'edu_trimesters';
$ti = $wpdb->prefix . 'edu_institutions';

$institution = $wpdb->get_row( $wpdb->prepare( "SELECT regime FROM $ti WHERE id = %d", $current_institution_id ) );

$period     = null;
$trimesters = array();
if ( 'edit' === $action && $edit_id ) {
	$period = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tp WHERE id = %d AND institution_id = %d", $edit_id, $current_institution_id ) );
	if ( ! $period ) {
		wp_die( esc_html__( 'Período no encontrado.', 'sistema-educativo' ) );
	}
	$trimesters = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tt WHERE period_id = %d ORDER BY number", $edit_id ) );
}

$status      = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$status_code = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
?>
	<h1>
		<?php if ( 'new' === $action ) : ?>
			<?php esc_html_e( 'Nuevo período lectivo', 'sistema-educativo' ); ?>
		<?php elseif ( 'edit' === $action ) : ?>
			<?php esc_html_e( 'Editar período lectivo', 'sistema-educativo' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Períodos lectivos', 'sistema-educativo' ); ?>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-periodos&action=new' ) ); ?>"><?php esc_html_e( 'Nuevo', 'sistema-educativo' ); ?></a>
		<?php endif; ?>
	</h1>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Período guardado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'deleted' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Período eliminado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'activated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Período activado. Los demás quedaron inactivos.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$messages = array(
				'name_required' => __( 'El nombre del período es obligatorio.', 'sistema-educativo' ),
				'invalid_dates' => __( 'Fechas inválidas o la fecha de fin no es posterior al inicio.', 'sistema-educativo' ),
				'duplicate'     => __( 'Ya existe un período con ese nombre en esta institución.', 'sistema-educativo' ),
				'not_found'     => __( 'Período no encontrado.', 'sistema-educativo' ),
			);
			echo esc_html( $messages[ $status_code ] ?? __( 'Error guardando el período.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'list' === $action ) : ?>
		<?php $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tp WHERE institution_id = %d ORDER BY start_date DESC", $current_institution_id ) ); ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Régimen', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Inicio', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Fin', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Días', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Trimestres', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'Aún no hay períodos. Crea el primero.', 'sistema-educativo' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
						<td><?php echo esc_html( ucfirst( $r->regime ) ); ?></td>
						<td><?php echo esc_html( $r->start_date ); ?></td>
						<td><?php echo esc_html( $r->end_date ); ?></td>
						<td><?php echo esc_html( $r->working_days ); ?></td>
						<td><?php echo esc_html( $r->num_trimesters ); ?></td>
						<td>
							<?php if ( (int) $r->is_active === 1 ) : ?>
								<span class="edu-status-active">● <?php esc_html_e( 'Activo', 'sistema-educativo' ); ?></span>
							<?php else : ?>
								<span class="edu-status-inactive">○ <?php esc_html_e( 'Inactivo', 'sistema-educativo' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=edu-periodos&action=edit&id=' . $r->id ) ); ?>"><?php esc_html_e( 'Editar', 'sistema-educativo' ); ?></a>
							<?php if ( (int) $r->is_active !== 1 ) : ?>
								|
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form">
									<?php wp_nonce_field( 'edu_activate_period' ); ?>
									<input type="hidden" name="action" value="edu_activate_period">
									<input type="hidden" name="id" value="<?php echo esc_attr( $r->id ); ?>">
									<button type="submit" class="edu-link-button"><?php esc_html_e( 'Activar', 'sistema-educativo' ); ?></button>
								</form>
							<?php endif; ?>
							|
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form" onsubmit="return confirm('<?php echo esc_attr__( '¿Eliminar este período y sus trimestres?', 'sistema-educativo' ); ?>');">
								<?php wp_nonce_field( 'edu_delete_period' ); ?>
								<input type="hidden" name="action" value="edu_delete_period">
								<input type="hidden" name="id" value="<?php echo esc_attr( $r->id ); ?>">
								<button type="submit" class="edu-link-button edu-link-danger"><?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php else : ?>
		<?php
		$default_regime = $period->regime ?? ( $institution->regime ?? 'sierra' );
		$default_name   = $period->name ?? '';
		$default_start  = $period->start_date ?? ( 'sierra' === $default_regime ? '2026-09-01' : '2026-05-01' );
		$default_end    = $period->end_date ?? ( 'sierra' === $default_regime ? '2027-07-10' : '2027-02-10' );
		$default_work   = $period->working_days ?? 200;
		$default_numtri = $period->num_trimesters ?? 3;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-form">
			<?php wp_nonce_field( 'edu_save_period' ); ?>
			<input type="hidden" name="action" value="edu_save_period">
			<?php if ( $period ) : ?>
				<input type="hidden" name="id" value="<?php echo esc_attr( $period->id ); ?>">
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label><?php esc_html_e( 'Régimen escolar', 'sistema-educativo' ); ?></label></th>
					<td>
						<label><input type="radio" name="regime" value="sierra" <?php checked( $default_regime, 'sierra' ); ?>> <strong><?php esc_html_e( 'Sierra-Amazonía', 'sistema-educativo' ); ?></strong> <span class="description">(sep → jul)</span></label>
						&nbsp;&nbsp;
						<label><input type="radio" name="regime" value="costa" <?php checked( $default_regime, 'costa' ); ?>> <strong><?php esc_html_e( 'Costa-Galápagos', 'sistema-educativo' ); ?></strong> <span class="description">(may → feb)</span></label>
					</td>
				</tr>
				<tr>
					<th><label for="edu-name"><?php esc_html_e( 'Nombre del período', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td><input id="edu-name" name="name" type="text" class="regular-text" required maxlength="50" placeholder="<?php esc_attr_e( 'Ej: 2026 – 2027', 'sistema-educativo' ); ?>" value="<?php echo esc_attr( $default_name ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-start"><?php esc_html_e( 'Fecha de inicio', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td><input id="edu-start" name="start_date" type="date" required value="<?php echo esc_attr( $default_start ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-end"><?php esc_html_e( 'Fecha de fin', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td><input id="edu-end" name="end_date" type="date" required value="<?php echo esc_attr( $default_end ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-working"><?php esc_html_e( 'Días laborables', 'sistema-educativo' ); ?></label></th>
					<td>
						<input id="edu-working" name="working_days" type="number" min="1" max="365" value="<?php echo esc_attr( $default_work ); ?>">
						<span class="description"><?php esc_html_e( 'Estándar Mineduc: 200 días.', 'sistema-educativo' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="edu-numtri"><?php esc_html_e( 'Número de trimestres', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-numtri" name="num_trimesters" type="number" min="1" max="5" value="<?php echo esc_attr( $default_numtri ); ?>"></td>
				</tr>
			</table>

			<?php if ( ! empty( $trimesters ) ) : ?>
				<h2><?php esc_html_e( 'Trimestres del período', 'sistema-educativo' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Se generan automáticamente al crear el período dividiendo el rango por igual. El ajuste fino por trimestre llega en Fase 1.5.', 'sistema-educativo' ); ?></p>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Trimestre', 'sistema-educativo' ); ?></th>
						<th><?php esc_html_e( 'Inicio', 'sistema-educativo' ); ?></th>
						<th><?php esc_html_e( 'Fin', 'sistema-educativo' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $trimesters as $t ) : ?>
							<tr>
								<td><?php printf( esc_html__( '%d° Trimestre', 'sistema-educativo' ), (int) $t->number ); ?></td>
								<td><?php echo esc_html( $t->start_date ); ?></td>
								<td><?php echo esc_html( $t->end_date ); ?></td>
								<td><?php echo $t->is_closed ? esc_html__( 'Cerrado', 'sistema-educativo' ) : esc_html__( 'Abierto', 'sistema-educativo' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( 'new' === $action ) : ?>
				<p class="description"><?php esc_html_e( 'Al guardar se crearán automáticamente N trimestres dividiendo el rango de fechas.', 'sistema-educativo' ); ?></p>
			<?php endif; ?>

			<?php submit_button( $period ? __( 'Guardar cambios', 'sistema-educativo' ) : __( 'Crear período', 'sistema-educativo' ) ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-periodos' ) ); ?>"><?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?></a>
		</form>
	<?php endif; ?>
</div>
