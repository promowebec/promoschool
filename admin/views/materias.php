<?php
/**
 * Vista: pensum de materias agrupado por subnivel.
 *
 * Une el catálogo Mineduc (global) con `wp_edu_subjects` (institución actual).
 * Cada materia del catálogo se puede "Adoptar" → crea fila en subjects con
 * `catalog_id` apuntando a la del catálogo y `is_custom = 0`.
 * Las materias propias (`is_custom = 1`) aparecen en un bloque separado.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_manage_subjects' ) ) {
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
$tsc = $wpdb->prefix . 'edu_subjects_catalog';
$ts  = $wpdb->prefix . 'edu_subjects';

$subject = null;
if ( 'edit' === $action && $edit_id ) {
	$subject = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ts WHERE id = %d AND institution_id = %d", $edit_id, $current_institution_id ) );
	if ( ! $subject ) {
		wp_die( esc_html__( 'Materia no encontrada.', 'sistema-educativo' ) );
	}
}

$status      = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$status_code = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';

$sub_levels = array(
	'preparatoria'     => array(
		'label' => __( 'EGB Preparatoria', 'sistema-educativo' ),
		'class' => 'edu-block-amber',
		'hint'  => __( '1ro EGB', 'sistema-educativo' ),
		'match' => array( 'preparatoria' ),
	),
	'elemental_media'  => array(
		'label' => __( 'EGB Elemental y EGB Media', 'sistema-educativo' ),
		'class' => 'edu-block-blue',
		'hint'  => __( '2do a 7mo EGB', 'sistema-educativo' ),
		'match' => array( 'elemental', 'media' ),
	),
	'superior'         => array(
		'label' => __( 'EGB Superior', 'sistema-educativo' ),
		'class' => 'edu-block-purple',
		'hint'  => __( '8vo a 10mo EGB', 'sistema-educativo' ),
		'match' => array( 'superior' ),
	),
	'bg'               => array(
		'label' => __( 'Bachillerato General (BG)', 'sistema-educativo' ),
		'class' => 'edu-block-emerald',
		'hint'  => __( '1ro a 3ro BG', 'sistema-educativo' ),
		'match' => array( 'bg' ),
	),
	'bt'               => array(
		'label' => __( 'Bachillerato Técnico (BT)', 'sistema-educativo' ),
		'class' => 'edu-block-orange',
		'hint'  => __( 'tronco común + módulos', 'sistema-educativo' ),
		'match' => array( 'bt' ),
	),
);
?>
	<h1>
		<?php if ( 'list' === $action ) : ?>
			<?php esc_html_e( 'Materias del pensum', 'sistema-educativo' ); ?>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-materias&action=new' ) ); ?>"><?php esc_html_e( 'Nueva materia propia', 'sistema-educativo' ); ?></a>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form">
				<?php wp_nonce_field( 'edu_repopulate_catalog' ); ?>
				<input type="hidden" name="action" value="edu_repopulate_catalog">
				<button type="submit" class="page-title-action"><?php esc_html_e( 'Repoblar catálogo Mineduc', 'sistema-educativo' ); ?></button>
			</form>
		<?php else : ?>
			<?php echo $subject ? esc_html__( 'Editar materia propia', 'sistema-educativo' ) : esc_html__( 'Nueva materia propia', 'sistema-educativo' ); ?>
		<?php endif; ?>
	</h1>

	<?php if ( 'updated' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Materia guardada.', 'sistema-educativo' ); ?></p></div><?php endif; ?>
	<?php if ( 'adopted' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Materia adoptada del catálogo Mineduc.', 'sistema-educativo' ); ?></p></div><?php endif; ?>
	<?php if ( 'deleted' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Materia eliminada.', 'sistema-educativo' ); ?></p></div><?php endif; ?>
	<?php if ( 'repopulated' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Catálogo Mineduc reverificado (INSERT IGNORE: nada duplicado).', 'sistema-educativo' ); ?></p></div><?php endif; ?>
	<?php if ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$messages = array(
				'name_required' => __( 'El nombre es obligatorio.', 'sistema-educativo' ),
				'not_found'     => __( 'Materia no encontrada.', 'sistema-educativo' ),
				'duplicate'     => __( 'Esta materia ya está adoptada en la institución.', 'sistema-educativo' ),
			);
			echo esc_html( $messages[ $status_code ] ?? __( 'Error.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'list' === $action ) : ?>
		<?php
		$catalog              = $wpdb->get_results( "SELECT * FROM $tsc WHERE is_active = 1 ORDER BY name" );
		$institution_subjects = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $ts WHERE institution_id = %d ORDER BY name", $current_institution_id ) );

		$adopted_by_catalog = array();
		$custom_subjects    = array();
		foreach ( $institution_subjects as $s ) {
			if ( $s->is_custom ) {
				$custom_subjects[] = $s;
			} elseif ( $s->catalog_id ) {
				$adopted_by_catalog[ (int) $s->catalog_id ] = $s;
			}
		}
		?>

		<?php foreach ( $sub_levels as $sl_key => $sl_info ) :
			$match_keys       = $sl_info['match'];
			$matching_catalog = array_filter( $catalog, function ( $c ) use ( $match_keys ) {
				$cats = array_map( 'trim', explode( ',', $c->sub_levels ) );
				foreach ( $match_keys as $mk ) {
					if ( in_array( $mk, $cats, true ) ) {
						return true;
					}
				}
				return false;
			} );
		?>
			<div class="edu-block <?php echo esc_attr( $sl_info['class'] ); ?>">
				<div class="edu-block-header">
					<div>
						<strong><?php echo esc_html( $sl_info['label'] ); ?></strong>
						<span class="edu-block-hint"><?php echo esc_html( $sl_info['hint'] ); ?></span>
					</div>
					<span class="edu-block-count">
						<?php
						printf(
							esc_html( _n( '%d materia del currículo', '%d materias del currículo', count( $matching_catalog ), 'sistema-educativo' ) ),
							count( $matching_catalog )
						);
						?>
					</span>
				</div>
				<?php if ( empty( $matching_catalog ) ) : ?>
					<div class="edu-block-body">
						<p class="description">
							<?php if ( 'bt' === $sl_key ) : ?>
								<?php esc_html_e( 'Bachillerato Técnico comparte tronco común con BG. Los módulos técnicos por especialidad se cargan por grado en Fase 2.', 'sistema-educativo' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Sin materias del catálogo en este subnivel.', 'sistema-educativo' ); ?>
							<?php endif; ?>
						</p>
					</div>
				<?php else : ?>
					<table class="widefat">
						<tbody>
							<?php foreach ( $matching_catalog as $c ) :
								$adopted = isset( $adopted_by_catalog[ (int) $c->id ] );
							?>
								<tr>
									<td><?php echo esc_html( $c->name ); ?></td>
									<td class="edu-muted-cell"><?php echo esc_html( $c->area ?: '—' ); ?></td>
									<td class="edu-muted-cell"><span class="edu-source-badge"><?php esc_html_e( 'Mineduc', 'sistema-educativo' ); ?></span></td>
									<td class="edu-action-cell">
										<?php if ( $adopted ) : ?>
											<span class="edu-adopted">✓ <?php esc_html_e( 'Adoptada', 'sistema-educativo' ); ?></span>
										<?php else : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form">
												<?php wp_nonce_field( 'edu_adopt_subject' ); ?>
												<input type="hidden" name="action" value="edu_adopt_subject">
												<input type="hidden" name="catalog_id" value="<?php echo esc_attr( $c->id ); ?>">
												<button type="submit" class="button button-small"><?php esc_html_e( 'Adoptar', 'sistema-educativo' ); ?></button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<?php if ( ! empty( $custom_subjects ) ) : ?>
			<div class="edu-block edu-block-slate">
				<div class="edu-block-header">
					<div><strong><?php esc_html_e( 'Materias propias de la institución', 'sistema-educativo' ); ?></strong></div>
					<span class="edu-block-count">
						<?php printf( esc_html( _n( '%d materia propia', '%d materias propias', count( $custom_subjects ), 'sistema-educativo' ) ), count( $custom_subjects ) ); ?>
					</span>
				</div>
				<table class="widefat">
					<tbody>
					<?php foreach ( $custom_subjects as $s ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $s->name ); ?></strong></td>
							<td class="edu-muted-cell"><?php echo esc_html( $s->code ?: '' ); ?></td>
							<td class="edu-muted-cell"><?php echo esc_html( $s->area ?: '—' ); ?></td>
							<td class="edu-muted-cell"><span class="edu-source-badge edu-source-custom"><?php esc_html_e( 'Propia', 'sistema-educativo' ); ?></span></td>
							<td class="edu-action-cell">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=edu-materias&action=edit&id=' . $s->id ) ); ?>"><?php esc_html_e( 'Editar', 'sistema-educativo' ); ?></a>
								|
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form" onsubmit="return confirm('<?php echo esc_attr__( '¿Eliminar esta materia propia?', 'sistema-educativo' ); ?>');">
									<?php wp_nonce_field( 'edu_delete_subject' ); ?>
									<input type="hidden" name="action" value="edu_delete_subject">
									<input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>">
									<button type="submit" class="edu-link-button edu-link-danger"><?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'La asignación de materias al pensum de cada grado llega en Fase 2 con las calificaciones.', 'sistema-educativo' ); ?></p>

	<?php else : ?>
		<?php
		$cur_name = $subject->name ?? '';
		$cur_code = $subject->code ?? '';
		$cur_area = $subject->area ?? '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-form">
			<?php wp_nonce_field( 'edu_save_subject' ); ?>
			<input type="hidden" name="action" value="edu_save_subject">
			<?php if ( $subject ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( $subject->id ); ?>"><?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="edu-name"><?php esc_html_e( 'Nombre de la materia', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td><input id="edu-name" name="name" type="text" class="regular-text" required maxlength="150" placeholder="<?php esc_attr_e( 'Ej: Robótica Educativa', 'sistema-educativo' ); ?>" value="<?php echo esc_attr( $cur_name ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-code"><?php esc_html_e( 'Código (opcional)', 'sistema-educativo' ); ?></label></th>
					<td><input id="edu-code" name="code" type="text" maxlength="20" placeholder="ROB-101" value="<?php echo esc_attr( $cur_code ); ?>"></td>
				</tr>
				<tr>
					<th><label for="edu-area"><?php esc_html_e( 'Área', 'sistema-educativo' ); ?></label></th>
					<td>
						<input id="edu-area" name="area" type="text" maxlength="50" value="<?php echo esc_attr( $cur_area ); ?>" list="edu-area-list" placeholder="<?php esc_attr_e( 'Ej: Ciencias exactas, Técnica, Artística…', 'sistema-educativo' ); ?>">
						<datalist id="edu-area-list">
							<option value="Ciencias exactas"><option value="Lengua"><option value="Lengua extranjera">
							<option value="Ciencias sociales"><option value="Ciencias naturales"><option value="Artística">
							<option value="Física"><option value="Tutoría"><option value="Económica">
							<option value="Técnica"><option value="Integradora">
						</datalist>
					</td>
				</tr>
			</table>

			<p class="description"><?php esc_html_e( 'Las materias propias no llevan subnivel por ahora (la columna no existe en el schema). En Fase 2 se asignarán al pensum de los grados que correspondan.', 'sistema-educativo' ); ?></p>

			<?php submit_button( $subject ? __( 'Guardar cambios', 'sistema-educativo' ) : __( 'Crear materia', 'sistema-educativo' ) ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-materias' ) ); ?>"><?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?></a>
		</form>
	<?php endif; ?>
</div>
