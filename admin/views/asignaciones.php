<?php
/**
 * Vista: ABM de asignaciones académicas (docente ↔ grado ↔ materia ↔ período).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_manage_assignments' ) ) {
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
$ta  = $wpdb->prefix . 'edu_teacher_assignments';
$tt  = $wpdb->prefix . 'edu_teachers';
$tg  = $wpdb->prefix . 'edu_grades';
$tsu = $wpdb->prefix . 'edu_subjects';
$tp  = $wpdb->prefix . 'edu_periods';
$u   = $wpdb->users;
$um  = $wpdb->usermeta;

// Catálogos para selectores y filtros.
$teachers = $wpdb->get_results( $wpdb->prepare(
	"SELECT t.id, u.display_name
	 FROM $tt t
	 INNER JOIN $u u ON u.ID = t.user_id
	 INNER JOIN $um m ON m.user_id = u.ID AND m.meta_key = 'edu_institution_id' AND m.meta_value = %d
	 WHERE t.is_active = 1
	 ORDER BY u.display_name",
	$current_institution_id
) );
$grades   = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY level, sub_level, name, paralelo", $current_institution_id ) );
$subjects = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM $tsu WHERE institution_id = %d ORDER BY name", $current_institution_id ) );
$periods  = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, is_active FROM $tp WHERE institution_id = %d ORDER BY start_date DESC", $current_institution_id ) );

$missing = array();
if ( empty( $teachers ) ) { $missing[] = array( __( 'docentes activos', 'sistema-educativo' ), 'edu-docentes&action=new' ); }
if ( empty( $grades ) )   { $missing[] = array( __( 'grados', 'sistema-educativo' ),           'edu-grados&action=new' ); }
if ( empty( $subjects ) ) { $missing[] = array( __( 'materias', 'sistema-educativo' ),         'edu-materias' ); }
if ( empty( $periods ) )  { $missing[] = array( __( 'períodos lectivos', 'sistema-educativo' ),'edu-periodos&action=new' ); }

$assignment = null;
if ( 'edit' === $action && $edit_id ) {
	$assignment = $wpdb->get_row( $wpdb->prepare( "SELECT a.* FROM $ta a INNER JOIN $tg g ON g.id = a.grade_id WHERE a.id = %d AND g.institution_id = %d", $edit_id, $current_institution_id ) );
	if ( ! $assignment ) {
		wp_die( esc_html__( 'Asignación no encontrada.', 'sistema-educativo' ) );
	}
}

$status      = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$status_code = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';

$f_teacher = isset( $_GET['teacher_id'] ) ? (int) $_GET['teacher_id'] : 0;
$f_grade   = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;
$f_period  = isset( $_GET['period_id'] ) ? (int) $_GET['period_id'] : 0;
?>
	<h1>
		<?php if ( 'list' === $action ) : ?>
			<?php esc_html_e( 'Asignaciones académicas', 'sistema-educativo' ); ?>
			<?php if ( empty( $missing ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-asignaciones&action=new' ) ); ?>"><?php esc_html_e( 'Nueva asignación', 'sistema-educativo' ); ?></a>
			<?php endif; ?>
		<?php else : ?>
			<?php echo $assignment ? esc_html__( 'Editar asignación', 'sistema-educativo' ) : esc_html__( 'Nueva asignación', 'sistema-educativo' ); ?>
		<?php endif; ?>
	</h1>

	<?php if ( ! empty( $missing ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Antes de crear asignaciones necesitas:', 'sistema-educativo' ); ?>
			<?php
			$links = array();
			foreach ( $missing as $m ) {
				$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . $m[1] ) ) . '">' . esc_html( $m[0] ) . '</a>';
			}
			echo wp_kses_post( implode( ', ', $links ) );
			?>.
		</p></div>
	<?php endif; ?>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Asignación guardada.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'deleted' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Asignación eliminada.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$messages = array(
				'invalid_teacher' => __( 'El docente seleccionado no pertenece a esta institución.', 'sistema-educativo' ),
				'invalid_grade'   => __( 'El grado seleccionado no pertenece a esta institución.', 'sistema-educativo' ),
				'invalid_subject' => __( 'La materia seleccionada no pertenece a esta institución.', 'sistema-educativo' ),
				'invalid_period'  => __( 'El período seleccionado no pertenece a esta institución.', 'sistema-educativo' ),
				'duplicate'       => __( 'Ya existe esta asignación (docente + grado + materia + período).', 'sistema-educativo' ),
				'not_found'       => __( 'Asignación no encontrada.', 'sistema-educativo' ),
			);
			echo esc_html( $messages[ $status_code ] ?? __( 'Error guardando la asignación.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( 'list' === $action ) : ?>
		<form method="get" class="edu-filter-bar">
			<input type="hidden" name="page" value="edu-asignaciones">
			<label><?php esc_html_e( 'Docente:', 'sistema-educativo' ); ?></label>
			<select name="teacher_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
				<?php foreach ( $teachers as $t ) : ?>
					<option value="<?php echo esc_attr( $t->id ); ?>" <?php selected( $f_teacher, (int) $t->id ); ?>><?php echo esc_html( $t->display_name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label><?php esc_html_e( 'Grado:', 'sistema-educativo' ); ?></label>
			<select name="grade_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
				<?php foreach ( $grades as $g ) : ?>
					<option value="<?php echo esc_attr( $g->id ); ?>" <?php selected( $f_grade, (int) $g->id ); ?>><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></option>
				<?php endforeach; ?>
			</select>
			<label><?php esc_html_e( 'Período:', 'sistema-educativo' ); ?></label>
			<select name="period_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'Todos', 'sistema-educativo' ); ?></option>
				<?php foreach ( $periods as $p ) : ?>
					<option value="<?php echo esc_attr( $p->id ); ?>" <?php selected( $f_period, (int) $p->id ); ?>><?php echo esc_html( $p->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>

		<?php
		$sql  = "SELECT a.*, u.display_name AS teacher_name, g.name AS grade_name, g.paralelo, s.name AS subject_name, p.name AS period_name
		         FROM $ta a
		         INNER JOIN $tt t ON t.id = a.teacher_id
		         INNER JOIN $u u ON u.ID = t.user_id
		         INNER JOIN $tg g ON g.id = a.grade_id
		         INNER JOIN $tsu s ON s.id = a.subject_id
		         INNER JOIN $tp p ON p.id = a.period_id
		         WHERE g.institution_id = %d";
		$args = array( $current_institution_id );
		if ( $f_teacher ) { $sql .= ' AND a.teacher_id = %d'; $args[] = $f_teacher; }
		if ( $f_grade )   { $sql .= ' AND a.grade_id = %d';   $args[] = $f_grade; }
		if ( $f_period )  { $sql .= ' AND a.period_id = %d';  $args[] = $f_period; }
		$sql .= ' ORDER BY p.start_date DESC, u.display_name, g.name, s.name';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Docente', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Período', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Sin asignaciones con esos filtros.', 'sistema-educativo' ); ?></td></tr>
				<?php else : foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->teacher_name ); ?></strong></td>
						<td><?php echo esc_html( $r->grade_name . ' ' . $r->paralelo ); ?></td>
						<td><?php echo esc_html( $r->subject_name ); ?></td>
						<td><?php echo esc_html( $r->period_name ); ?></td>
						<td>
							<?php if ( (int) $r->is_active === 1 ) : ?>
								<span class="edu-status-active">● <?php esc_html_e( 'Activa', 'sistema-educativo' ); ?></span>
							<?php else : ?>
								<span class="edu-status-inactive">○ <?php esc_html_e( 'Inactiva', 'sistema-educativo' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=edu-asignaciones&action=edit&id=' . $r->id ) ); ?>"><?php esc_html_e( 'Editar', 'sistema-educativo' ); ?></a>
							|
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-inline-form" onsubmit="return confirm('<?php echo esc_attr__( '¿Eliminar esta asignación?', 'sistema-educativo' ); ?>');">
								<?php wp_nonce_field( 'edu_delete_assignment' ); ?>
								<input type="hidden" name="action" value="edu_delete_assignment">
								<input type="hidden" name="id" value="<?php echo esc_attr( $r->id ); ?>">
								<button type="submit" class="edu-link-button edu-link-danger"><?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	<?php elseif ( empty( $missing ) ) : ?>
		<?php
		$cur_teacher = (int) ( $assignment->teacher_id ?? 0 );
		$cur_grade   = (int) ( $assignment->grade_id ?? 0 );
		$cur_subject = (int) ( $assignment->subject_id ?? 0 );
		$cur_period  = (int) ( $assignment->period_id ?? 0 );
		$cur_active  = $assignment ? (int) $assignment->is_active : 1;
		// Default período: el activo de la institución si no hay valor previo.
		if ( ! $cur_period ) {
			foreach ( $periods as $p ) {
				if ( (int) $p->is_active === 1 ) { $cur_period = (int) $p->id; break; }
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="edu-form">
			<?php wp_nonce_field( 'edu_save_assignment' ); ?>
			<input type="hidden" name="action" value="edu_save_assignment">
			<?php if ( $assignment ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( $assignment->id ); ?>"><?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="edu-teacher"><?php esc_html_e( 'Docente', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td>
						<select id="edu-teacher" name="teacher_id" required>
							<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $teachers as $t ) : ?>
								<option value="<?php echo esc_attr( $t->id ); ?>" <?php selected( $cur_teacher, (int) $t->id ); ?>><?php echo esc_html( $t->display_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="edu-grade"><?php esc_html_e( 'Grado / Paralelo', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td>
						<select id="edu-grade" name="grade_id" required>
							<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $grades as $g ) : ?>
								<option value="<?php echo esc_attr( $g->id ); ?>" <?php selected( $cur_grade, (int) $g->id ); ?>><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="edu-subject"><?php esc_html_e( 'Materia', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td>
						<select id="edu-subject" name="subject_id" required>
							<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $subjects as $s ) : ?>
								<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( $cur_subject, (int) $s->id ); ?>><?php echo esc_html( $s->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="edu-period"><?php esc_html_e( 'Período lectivo', 'sistema-educativo' ); ?> <span class="required">*</span></label></th>
					<td>
						<select id="edu-period" name="period_id" required>
							<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $periods as $p ) : ?>
								<option value="<?php echo esc_attr( $p->id ); ?>" <?php selected( $cur_period, (int) $p->id ); ?>>
									<?php echo esc_html( $p->name ); ?><?php if ( (int) $p->is_active === 1 ) : ?> · <?php esc_html_e( 'Activo', 'sistema-educativo' ); ?><?php endif; ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
					<td><label><input type="checkbox" name="is_active" value="1" <?php checked( $cur_active, 1 ); ?>> <?php esc_html_e( 'Asignación activa', 'sistema-educativo' ); ?></label></td>
				</tr>
			</table>

			<?php submit_button( $assignment ? __( 'Guardar cambios', 'sistema-educativo' ) : __( 'Crear asignación', 'sistema-educativo' ) ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=edu-asignaciones' ) ); ?>"><?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?></a>
		</form>
	<?php endif; ?>
</div>
