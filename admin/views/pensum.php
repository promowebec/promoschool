<?php
/**
 * Vista: ABM del pensum por grado.
 *
 * Selector de grado → lista de todas las materias de la institución con
 * checkbox "Incluir en pensum" y horas/semana. Guardado en bloque vía
 * Edu_Curriculum_Controller::handle_save_pensum().
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_manage_curriculum' ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

$institution_id = Edu_Context::current_institution_id();
if ( ! $institution_id ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Pensum', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución para gestionar el pensum.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

global $wpdb;
$tg  = $wpdb->prefix . 'edu_grades';
$ts  = $wpdb->prefix . 'edu_subjects';
$tgs = $wpdb->prefix . 'edu_grade_subjects';

$selected_grade = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;

$grades = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, sub_level, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
	$institution_id
) );

// Datos del grado seleccionado (necesitamos sub_level para filtrar materias).
$grade_info = null;
if ( $selected_grade ) {
	foreach ( $grades as $g ) {
		if ( (int) $g->id === $selected_grade ) {
			$grade_info = $g;
			break;
		}
	}
}

$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$code   = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Pensum por grado', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pensum actualizado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$msgs = array(
				'invalid_grade' => __( 'Grado inválido.', 'sistema-educativo' ),
			);
			echo esc_html( $msgs[ $code ] ?? __( 'Error al guardar.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
		<input type="hidden" name="page" value="edu-pensum">
		<label for="edu-grade-select"><strong><?php esc_html_e( 'Grado:', 'sistema-educativo' ); ?></strong></label>
		<select name="grade_id" id="edu-grade-select" onchange="this.form.submit()">
			<option value="0"><?php esc_html_e( '— Seleccionar grado —', 'sistema-educativo' ); ?></option>
			<?php foreach ( $grades as $g ) : ?>
				<option value="<?php echo (int) $g->id; ?>" <?php selected( $selected_grade, (int) $g->id ); ?>>
					<?php echo esc_html( sprintf( '%s · Paralelo %s', $g->name, $g->paralelo ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</form>

	<?php if ( $selected_grade && $grade_info ) :
		$tc = $wpdb->prefix . 'edu_subjects_catalog';

		/*
		 * Materias del subnivel: las que tienen catalog_id y ese catálogo incluye
		 * el sub_level del grado (FIND_IN_SET), o que ya estén asignadas al grado.
		 * Materias optativas: is_custom = TRUE (propias de la institución).
		 * is_elective = 0 → estándar del subnivel  |  1 → optativa
		 */
		$subjects = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.name, s.code, s.is_custom,
			        CASE
			            WHEN s.catalog_id IS NOT NULL AND FIND_IN_SET(%s, c.sub_levels) > 0 THEN 0
			            ELSE 1
			        END AS is_elective
			 FROM $ts s
			 LEFT JOIN $tc c ON c.id = s.catalog_id
			 WHERE s.institution_id = %d
			   AND (
			       ( s.catalog_id IS NOT NULL AND FIND_IN_SET(%s, c.sub_levels) > 0 )
			       OR s.is_custom = 1
			       OR s.id IN ( SELECT subject_id FROM $tgs WHERE grade_id = %d )
			   )
			 ORDER BY is_elective ASC, s.area, s.name",
			$grade_info->sub_level,
			$institution_id,
			$grade_info->sub_level,
			$selected_grade
		) );

		$current = $wpdb->get_results( $wpdb->prepare(
			"SELECT subject_id, hours_week FROM $tgs WHERE grade_id = %d",
			$selected_grade
		), OBJECT_K );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'edu_save_pensum' ); ?>
		<input type="hidden" name="action" value="edu_save_pensum">
		<input type="hidden" name="grade_id" value="<?php echo (int) $selected_grade; ?>">

		<?php if ( empty( $subjects ) ) : ?>
			<div class="notice notice-info"><p><?php esc_html_e( 'No hay materias configuradas para este subnivel. Crea materias optativas desde "Materias".', 'sistema-educativo' ); ?></p></div>
		<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:60px;"><?php esc_html_e( 'Incluir', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Código', 'sistema-educativo' ); ?></th>
					<th style="width:140px;"><?php esc_html_e( 'Horas/semana', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$showing_elective = false;
				foreach ( $subjects as $s ) :
					$checked    = isset( $current[ $s->id ] );
					$hw         = $checked ? (int) $current[ $s->id ]->hours_week : 5;
					$is_elective = (bool) $s->is_elective;

					// Encabezado separador al entrar en la sección optativa.
					if ( $is_elective && ! $showing_elective ) :
						$showing_elective = true;
						?>
						<tr>
							<td colspan="4" style="background:#f0f6ff;padding:8px 12px;font-weight:600;color:#2271b1;border-top:2px solid #2271b1;">
								<?php esc_html_e( 'Materias optativas (propias de la institución)', 'sistema-educativo' ); ?>
							</td>
						</tr>
						<?php
					elseif ( ! $is_elective && ! $showing_elective && $s === reset( $subjects ) ) :
						?>
						<tr>
							<td colspan="4" style="background:#f6f7f7;padding:8px 12px;font-weight:600;color:#3c434a;border-top:2px solid #8c8f94;">
								<?php
								$sublevel_labels = array(
									'inicial_1'    => __( 'Inicial 1', 'sistema-educativo' ),
									'inicial_2'    => __( 'Inicial 2', 'sistema-educativo' ),
									'preparatoria' => __( 'Preparatoria (1ro EGB)', 'sistema-educativo' ),
									'elemental'    => __( 'EGB Elemental (2do-4to)', 'sistema-educativo' ),
									'media'        => __( 'EGB Media (5to-7mo)', 'sistema-educativo' ),
									'superior'     => __( 'EGB Superior (8vo-10mo)', 'sistema-educativo' ),
									'bg'           => __( 'Bachillerato General Unificado', 'sistema-educativo' ),
									'bt'           => __( 'Bachillerato Técnico', 'sistema-educativo' ),
								);
								$label = $sublevel_labels[ $grade_info->sub_level ] ?? esc_html( $grade_info->sub_level );
								/* translators: %s: nombre del subnivel */
								printf( esc_html__( 'Materias del subnivel: %s', 'sistema-educativo' ), esc_html( $label ) );
								?>
							</td>
						</tr>
						<?php
					endif;
					?>
					<tr>
						<td><input type="checkbox" name="subjects[]" value="<?php echo (int) $s->id; ?>" <?php checked( $checked ); ?>></td>
						<td><?php echo esc_html( $s->name ); ?></td>
						<td><?php echo esc_html( $s->code ?? '' ); ?></td>
						<td><input type="number" min="1" max="40" name="hours_week[<?php echo (int) $s->id; ?>]" value="<?php echo (int) $hw; ?>" style="width:80px;"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar pensum', 'sistema-educativo' ); ?></button>
		</p>
		<?php endif; ?>
	</form>
	<?php elseif ( $selected_grade && ! $grade_info ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'Grado no encontrado en esta institución.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( empty( $grades ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'No hay grados creados para esta institución.', 'sistema-educativo' ); ?></p></div>
	<?php endif; ?>
</div>
