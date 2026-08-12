<?php
/**
 * Vista: ABM de grados y paralelos.
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
$tg = $wpdb->prefix . 'edu_grades';
$tt = $wpdb->prefix . 'edu_teachers';
$u  = $wpdb->users;
$um = $wpdb->usermeta;

// Docentes activos de la institución actual (para el selector de tutor).
$tutors = $wpdb->get_results( $wpdb->prepare(
	"SELECT u.ID AS user_id, u.display_name
	 FROM $tt t
	 INNER JOIN $u u ON u.ID = t.user_id
	 INNER JOIN $um m ON m.user_id = u.ID AND m.meta_key = 'edu_institution_id' AND m.meta_value = %d
	 WHERE t.is_active = 1
	 ORDER BY u.display_name",
	$current_institution_id
) );

$grade = null;
if ( 'edit' === $action && $edit_id ) {
	$grade = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tg WHERE id = %d AND institution_id = %d", $edit_id, $current_institution_id ) );
	if ( ! $grade ) {
		wp_die( esc_html__( 'Grado no encontrado.', 'sistema-educativo' ) );
	}
}

$status           = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$status_code      = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
$sub_level_filter = isset( $_GET['sub_level'] ) ? sanitize_key( $_GET['sub_level'] ) : '';

$sub_levels = array(
	'inicial_1'    => array( 'label' => __( 'Inicial 1', 'sistema-educativo' ),                  'level' => 'inicial',      'class' => 'edu-tag-pink' ),
	'inicial_2'    => array( 'label' => __( 'Inicial 2', 'sistema-educativo' ),                  'level' => 'inicial',      'class' => 'edu-tag-pink' ),
	'preparatoria' => array( 'label' => __( 'EGB Preparatoria', 'sistema-educativo' ),           'level' => 'basica',       'class' => 'edu-tag-amber' ),
	'elemental'    => array( 'label' => __( 'EGB Elemental', 'sistema-educativo' ),              'level' => 'basica',       'class' => 'edu-tag-cyan' ),
	'media'        => array( 'label' => __( 'EGB Media', 'sistema-educativo' ),                  'level' => 'basica',       'class' => 'edu-tag-blue' ),
	'superior'     => array( 'label' => __( 'EGB Superior', 'sistema-educativo' ),               'level' => 'basica',       'class' => 'edu-tag-purple' ),
	'bg'           => array( 'label' => __( 'Bachillerato General (BG)', 'sistema-educativo' ),  'level' => 'bachillerato', 'class' => 'edu-tag-emerald' ),
	'bt'           => array( 'label' => __( 'Bachillerato Técnico (BT)', 'sistema-educativo' ),  'level' => 'bachillerato', 'class' => 'edu-tag-orange' ),
);
?>
	<h1>
		<?php if ( 'list' === $action ) : ?>
			<?php esc_html_e( 'Grados y paralelos', 'sistema-educativo' ); ?>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-grados&action=new' ) ); ?>"><?php esc_html_e( 'Nuevo grado/paralelo', 'sistema-educativo' ); ?></a>
		<?php else : ?>
			<?php echo $grade ? esc_html__( 'Editar grado/paralelo', 'sistema-educativo' ) : esc_html__( 'Nuevo grado/paralelo', 'sistema-educativo' ); ?>
		<?php endif; ?>
	</h1>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Grado guardado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'deleted' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Grado eliminado.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$messages = array(
				'name_required'      => __( 'El nombre del grado es obligatorio.', 'sistema-educativo' ),
				'paralelo_required'  => __( 'Selecciona un paralelo (A, B, C o D).', 'sistema-educativo' ),
				'invalid_sublevel'   => __( 'Subnivel inválido.', 'sistema-educativo' ),
				'duplicate'          => __( 'Ya existe un grado/paralelo con esos datos en esta institución.', 'sistema-educativo' ),
				'not_found'          => __( 'Grado no encontrado.', 'sistema-educativo' ),
				'invalid_tutor'      => __( 'El tutor seleccionado no es un docente válido de esta institución.', 'sistema-educativo' ),
			);
			echo esc_html( $messages[ $status_code ] ?? __( 'Error guardando el grado.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'list' === $action ) : ?>

		<?php
		// Resultado de importación previa (transient).
		$import_result = get_transient( 'edu_import_grades_' . get_current_user_id() );
		if ( false !== $import_result ) {
			delete_transient( 'edu_import_grades_' . get_current_user_id() );
			if ( ! empty( $import_result['error_fatal'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Error en importación:', 'sistema-educativo' ); ?></strong> <?php echo esc_html( $import_result['error_fatal'] ); ?></p></div>
			<?php elseif ( isset( $import_result['inserted'] ) ) :
				$ins = (int) $import_result['inserted'];
				$ski = (int) $import_result['skipped'];
				$err = $import_result['errors'] ?? array();
			?>
				<div class="notice notice-success is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Importación completada.', 'sistema-educativo' ); ?></strong>
						<?php printf( esc_html__( '%d grado(s) importado(s).', 'sistema-educativo' ), $ins ); ?>
						<?php if ( $ski ) : ?>
							<?php printf( esc_html__( ' %d omitido(s) por duplicado.', 'sistema-educativo' ), $ski ); ?>
						<?php endif; ?>
					</p>
					<?php if ( ! empty( $err ) ) : ?>
					<ul style="margin-top:4px; padding-left:20px; list-style:disc;">
						<?php foreach ( $err as $e ) : ?>
							<li><?php echo esc_html( $e ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>
			<?php endif;
		}
		?>

		<form method="get" class="edu-filter-bar">
			<input type="hidden" name="page" value="edu-grados">
			<label><?php esc_html_e( 'Filtrar por subnivel:', 'sistema-educativo' ); ?></label>
			<select name="sub_level" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
				<?php foreach ( $sub_levels as $sl => $info ) : ?>
					<option value="<?php echo esc_attr( $sl ); ?>" <?php selected( $sub_level_filter, $sl ); ?>>
						<?php echo esc_html( $info['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</form>

		<?php
		$where  = 'WHERE institution_id = %d';
		$params = array( $current_institution_id );
		if ( $sub_level_filter && isset( $sub_levels[ $sub_level_filter ] ) ) {
			$where   .= ' AND sub_level = %s';
			$params[] = $sub_level_filter;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT g.*, u.display_name AS tutor_name FROM $tg g LEFT JOIN $u u ON u.ID = g.tutor_user_id $where ORDER BY g.level, g.sub_level, g.name, g.paralelo", $params ) );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Subnivel', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Grado/Curso', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Paralelo', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Especialidad (BT)', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Tutor', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Aún no hay grados. Crea el primero.', 'sistema-educativo' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) :
					$sli = $sub_levels[ $r->sub_level ] ?? array( 'label' => $r->sub_level, 'class' => '' );
				?>
					<tr>
						<td><span class="edu-tag <?php echo esc_attr( $sli['class'] ); ?>"><?php echo esc_html( $sli['label'] ); ?></span></td>
						<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
						<td><?php echo esc_html( $r->paralelo ); ?></td>
						<td><?php echo $r->specialty ? esc_html( $r->specialty ) : '—'; ?></td>
						<td><?php echo $r->tutor_name ? esc_html( $r->tutor_name ) : '—'; ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=edu-grados&action=edit&id=' . $r->id ) ); ?>"><?php esc_html_e( 'Editar', 'sistema-educativo' ); ?></a>
							|
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form" onsubmit="return confirm('<?php echo esc_attr__( '¿Eliminar este grado/paralelo?', 'sistema-educativo' ); ?>');">
								<?php wp_nonce_field( 'edu_delete_grade' ); ?>
								<input type="hidden" name="action" value="edu_delete_grade">
								<input type="hidden" name="id" value="<?php echo esc_attr( $r->id ); ?>">
								<button type="submit" class="edu-link-button edu-link-danger"><?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>

		<p class="description"><?php esc_html_e( 'El contador de Estudiantes llegará junto al ABM de matrícula extendida.', 'sistema-educativo' ); ?></p>

		<!-- ===== IMPORTACIÓN MASIVA ===== -->
		<div style="margin-top:28px;">
			<button type="button"
			        onclick="(function(b){var p=document.getElementById('edu-import-panel');p.style.display=p.style.display==='none'?'block':'none';b.textContent=p.style.display==='none'?'▶ Importar grados desde Excel/CSV':'▼ Importar grados desde Excel/CSV';})(this)"
			        class="button"
			        style="font-size:13px;">
				▶ <?php esc_html_e( 'Importar grados desde Excel/CSV', 'sistema-educativo' ); ?>
			</button>

			<div id="edu-import-panel" style="display:none; margin-top:16px; padding:20px 24px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; max-width:720px;">

				<h3 style="margin-top:0;"><?php esc_html_e( 'Importación masiva de grados/paralelos', 'sistema-educativo' ); ?></h3>

				<!-- Formato del archivo -->
				<div style="background:#f6f7f7; border:1px solid #ddd; border-radius:4px; padding:14px 16px; margin-bottom:18px; font-size:13px;">
					<strong><?php esc_html_e( 'Formato requerido (CSV o XLSX guardado como CSV):', 'sistema-educativo' ); ?></strong>
					<table style="margin-top:10px; border-collapse:collapse; width:100%;">
						<thead>
							<tr style="background:#e0e0e0;">
								<th style="padding:5px 10px; border:1px solid #ccc; text-align:left;"><?php esc_html_e( 'Columna', 'sistema-educativo' ); ?></th>
								<th style="padding:5px 10px; border:1px solid #ccc; text-align:left;"><?php esc_html_e( 'Obligatorio', 'sistema-educativo' ); ?></th>
								<th style="padding:5px 10px; border:1px solid #ccc; text-align:left;"><?php esc_html_e( 'Valores válidos / Ejemplo', 'sistema-educativo' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td style="padding:5px 10px; border:1px solid #ccc;"><code>nombre_grado</code></td>
								<td style="padding:5px 10px; border:1px solid #ccc; text-align:center;">✔</td>
								<td style="padding:5px 10px; border:1px solid #ccc;">5to EGB, 1ro BG, 2do BT Informática…</td>
							</tr>
							<tr style="background:#fafafa;">
								<td style="padding:5px 10px; border:1px solid #ccc;"><code>subnivel</code></td>
								<td style="padding:5px 10px; border:1px solid #ccc; text-align:center;">✔</td>
								<td style="padding:5px 10px; border:1px solid #ccc;">
									<code>inicial_1</code> · <code>inicial_2</code> · <code>preparatoria</code> · <code>elemental</code> · <code>media</code> · <code>superior</code> · <code>bg</code> · <code>bt</code>
								</td>
							</tr>
							<tr>
								<td style="padding:5px 10px; border:1px solid #ccc;"><code>paralelo</code></td>
								<td style="padding:5px 10px; border:1px solid #ccc; text-align:center;">✔</td>
								<td style="padding:5px 10px; border:1px solid #ccc;"><code>A</code> · <code>B</code> · <code>C</code> · <code>D</code></td>
							</tr>
							<tr style="background:#fafafa;">
								<td style="padding:5px 10px; border:1px solid #ccc;"><code>especialidad</code></td>
								<td style="padding:5px 10px; border:1px solid #ccc; text-align:center;">—</td>
								<td style="padding:5px 10px; border:1px solid #ccc;"><?php esc_html_e( 'Solo para subnivel bt. Dejar vacío si no aplica.', 'sistema-educativo' ); ?></td>
							</tr>
						</tbody>
					</table>
					<p style="margin:10px 0 0; color:#555;">
						<?php esc_html_e( '• El nivel (inicial/basica/bachillerato) se detecta automáticamente desde el subnivel.', 'sistema-educativo' ); ?><br>
						<?php esc_html_e( '• Los grados duplicados (misma institución + subnivel + nombre + paralelo) se omiten sin error.', 'sistema-educativo' ); ?><br>
						<?php esc_html_e( '• El tutor puede asignarse individualmente después de la importación.', 'sistema-educativo' ); ?>
					</p>
				</div>

				<!-- Descargar plantilla -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
					<?php wp_nonce_field( 'edu_download_grades_template' ); ?>
					<input type="hidden" name="action" value="edu_download_grades_template">
					<button type="submit" class="button button-secondary">
						⬇ <?php esc_html_e( 'Descargar plantilla CSV de ejemplo', 'sistema-educativo' ); ?>
					</button>
					<span style="margin-left:8px; color:#666; font-size:12px;">
						<?php esc_html_e( 'Abre en Excel, completa los datos y guarda como CSV UTF-8.', 'sistema-educativo' ); ?>
					</span>
				</form>

				<hr style="margin:16px 0;">

				<!-- Subir archivo -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'edu_import_grades_csv' ); ?>
					<input type="hidden" name="action" value="edu_import_grades_csv">

					<label style="display:block; margin-bottom:8px; font-weight:600;">
						<?php esc_html_e( 'Seleccionar archivo CSV:', 'sistema-educativo' ); ?>
					</label>
					<input type="file" name="grades_csv" accept=".csv" required style="display:block; margin-bottom:12px;">

					<button type="submit" class="button button-primary">
						⬆ <?php esc_html_e( 'Importar grados', 'sistema-educativo' ); ?>
					</button>
				</form>

			</div>
		</div>
		<!-- ===== FIN IMPORTACIÓN ===== -->

	<?php else : ?>
		<?php
		$cur_level = $grade->level ?? 'basica';
		$cur_sub   = $grade->sub_level ?? 'media';
		$cur_name  = $grade->name ?? '';
		$cur_par   = $grade->paralelo ?? '';
		$cur_spec  = $grade->specialty ?? '';
		$cur_tutor = (int) ( $grade->tutor_user_id ?? 0 );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-form">
			<?php wp_nonce_field( 'edu_save_grade' ); ?>
			<input type="hidden" name="action" value="edu_save_grade">
			<?php if ( $grade ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( $grade->id ); ?>"><?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="edu-level"><?php esc_html_e( 'Nivel', 'sistema-educativo' ); ?></label></th>
					<td>
						<select id="edu-level" name="level">
							<option value="inicial" <?php selected( $cur_level, 'inicial' ); ?>><?php esc_html_e( 'Educación Inicial', 'sistema-educativo' ); ?></option>
							<option value="basica" <?php selected( $cur_level, 'basica' ); ?>><?php esc_html_e( 'Educación General Básica', 'sistema-educativo' ); ?></option>
							<option value="bachillerato" <?php selected( $cur_level, 'bachillerato' ); ?>><?php esc_html_e( 'Bachillerato', 'sistema-educativo' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="edu-sublevel"><?php esc_html_e( 'Subnivel', 'sistema-educativo' ); ?></label></th>
					<td>
						<select id="edu-sublevel" name="sub_level">
							<?php foreach ( $sub_levels as $sl => $info ) : ?>
								<option value="<?php echo esc_attr( $sl ); ?>" data-level="<?php echo esc_attr( $info['level'] ); ?>" <?php selected( $cur_sub, $sl ); ?>>
									<?php echo esc_html( $info['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="edu-name"><?php esc_html_e( 'Grado/Curso', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td>
						<input id="edu-name" name="name" type="text" class="regular-text" required maxlength="50" placeholder="<?php esc_attr_e( 'Ej: 5to EGB, 1ro BG, 1ro BT Informática', 'sistema-educativo' ); ?>" value="<?php echo esc_attr( $cur_name ); ?>" list="edu-grade-suggestions">
						<datalist id="edu-grade-suggestions">
							<option value="1ro EGB"><option value="2do EGB"><option value="3ro EGB"><option value="4to EGB">
							<option value="5to EGB"><option value="6to EGB"><option value="7mo EGB"><option value="8vo EGB">
							<option value="9no EGB"><option value="10mo EGB"><option value="1ro BG"><option value="2do BG">
							<option value="3ro BG"><option value="1ro BT"><option value="2do BT"><option value="3ro BT">
						</datalist>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Paralelo', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td class="edu-paralelo-radios">
						<?php foreach ( array( 'A', 'B', 'C', 'D' ) as $par ) : ?>
							<label><input type="radio" name="paralelo" value="<?php echo esc_attr( $par ); ?>" required <?php checked( $cur_par, $par ); ?>> <?php echo esc_html( $par ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr id="edu-row-specialty"<?php echo 'bt' === $cur_sub ? '' : ' style="display:none"'; ?>>
					<th><label for="edu-specialty"><?php esc_html_e( 'Especialidad (solo BT)', 'sistema-educativo' ); ?></label></th>
					<td>
						<input id="edu-specialty" name="specialty" type="text" class="regular-text" maxlength="100" placeholder="<?php esc_attr_e( 'Informática, Contabilidad, Electrónica…', 'sistema-educativo' ); ?>" value="<?php echo esc_attr( $cur_spec ); ?>">
					</td>
				</tr>
				<tr>
					<th><label for="edu-tutor"><?php esc_html_e( 'Tutor (docente)', 'sistema-educativo' ); ?></label></th>
					<td>
						<select id="edu-tutor" name="tutor_user_id">
							<option value="0"><?php esc_html_e( '— Sin tutor asignado —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $tutors as $tut ) : ?>
								<option value="<?php echo esc_attr( $tut->user_id ); ?>" <?php selected( $cur_tutor, (int) $tut->user_id ); ?>>
									<?php echo esc_html( $tut->display_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php if ( empty( $tutors ) ) : ?>
								<?php
								printf(
									wp_kses_post( __( 'No hay docentes activos en esta institución. <a href="%s">Crea uno</a> y vuelve aquí.', 'sistema-educativo' ) ),
									esc_url( admin_url( 'admin.php?page=edu-docentes&action=new' ) )
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'Un docente puede ser tutor de varios grados/paralelos.', 'sistema-educativo' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( $grade ? __( 'Guardar cambios', 'sistema-educativo' ) : __( 'Crear grado', 'sistema-educativo' ) ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-grados' ) ); ?>"><?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?></a>
		</form>
	<?php endif; ?>
</div>
