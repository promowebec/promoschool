<?php
/**
 * Vista: ABM de componentes evaluables por (materia + trimestre + parcial).
 *
 * Tres selectors → tabla editable de filas (nombre + peso). Guardado por ID
 * (upsert) vía Edu_Curriculum_Controller::handle_save_components() para
 * preservar los vínculos con wp_edu_grades_log.
 *
 * Acceso:
 * - Rector/admin (edu_manage_curriculum): gestiona todo el set; sus filas son
 *   institucionales (created_by = 0).
 * - Docente (edu_grade_students): solo materias de sus asignaciones activas;
 *   ve las filas institucionales en solo-lectura y gestiona las propias.
 *
 * Los pesos no necesitan sumar 1.00: el cálculo del parcial renormaliza
 * dividiendo por la suma de pesos con nota (Edu_Grade_Calculator).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$puede_todo = Edu_Context::can( 'edu_manage_curriculum' );
$es_docente = ! $puede_todo && current_user_can( 'edu_grade_students' );

if ( ! $puede_todo && ! $es_docente ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

global $wpdb;
$ts  = $wpdb->prefix . 'edu_subjects';
$tt  = $wpdb->prefix . 'edu_trimesters';
$tp  = $wpdb->prefix . 'edu_periods';
$tc  = $wpdb->prefix . 'edu_grade_components';
$tl  = $wpdb->prefix . 'edu_grades_log';
$tta = $wpdb->prefix . 'edu_teacher_assignments';
$uid = get_current_user_id();

$institution_id = Edu_Context::current_institution_id();
$teacher_id     = 0;

if ( $es_docente ) {
	$teacher_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}edu_teachers WHERE user_id = %d",
		$uid
	) );
	if ( ! $teacher_id ) {
		echo '<div class="wrap"><h1>' . esc_html__( 'Componentes evaluables', 'sistema-educativo' ) . '</h1>';
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Tu usuario no está vinculado a un registro de docente.', 'sistema-educativo' ) . '</p></div></div>';
		return;
	}
} elseif ( ! $institution_id ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Componentes evaluables', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución para gestionar componentes.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

$subject_id   = isset( $_GET['subject_id'] ) ? (int) $_GET['subject_id'] : 0;
$trimester_id = isset( $_GET['trimester_id'] ) ? (int) $_GET['trimester_id'] : 0;
$parcial_num  = isset( $_GET['parcial_num'] ) ? (int) $_GET['parcial_num'] : 0;

if ( $es_docente ) {
	// Solo materias y trimestres de las asignaciones activas del docente.
	$subjects = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT s.id, s.name, s.code
		 FROM $ts s
		 INNER JOIN $tta ta ON ta.subject_id = s.id
		 WHERE ta.teacher_id = %d AND ta.is_active = 1
		 ORDER BY s.name",
		$teacher_id
	) );
	$trimesters = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT t.id, t.number, p.name AS period_name, p.start_date
		 FROM $tt t
		 INNER JOIN $tp p ON p.id = t.period_id
		 INNER JOIN $tta ta ON ta.period_id = p.id
		 WHERE ta.teacher_id = %d AND ta.is_active = 1
		 ORDER BY p.start_date DESC, t.number",
		$teacher_id
	) );
	// La materia elegida debe ser suya.
	if ( $subject_id && ! in_array( $subject_id, array_map( static fn( $s ) => (int) $s->id, $subjects ), true ) ) {
		$subject_id = 0;
	}
} else {
	$subjects = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, code FROM $ts WHERE institution_id = %d ORDER BY name",
		$institution_id
	) );
	$trimesters = $wpdb->get_results( $wpdb->prepare(
		"SELECT t.id, t.number, p.name AS period_name
		 FROM $tt t
		 INNER JOIN $tp p ON p.id = t.period_id
		 WHERE p.institution_id = %d
		 ORDER BY p.start_date DESC, t.number",
		$institution_id
	) );
}

$status     = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$code       = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';
$weight_sum = isset( $_GET['weightsum'] ) ? sanitize_text_field( wp_unslash( $_GET['weightsum'] ) ) : '';
$protegidos = isset( $_GET['protegidos'] ) ? (int) $_GET['protegidos'] : 0;

$selected = ( $subject_id && $trimester_id && in_array( $parcial_num, array( 1, 2 ), true ) );
$rows     = array();
if ( $selected ) {
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT c.id, c.name, c.weight, c.created_by,
		        (SELECT COUNT(*) FROM $tl l WHERE l.component_id = c.id) AS n_notas
		 FROM $tc c
		 WHERE c.subject_id = %d AND c.trimester_id = %d AND c.parcial_num = %d
		 ORDER BY c.created_by, c.id",
		$subject_id, $trimester_id, $parcial_num
	) );
}

/**
 * Etiqueta de origen de un componente.
 */
$edu_origen_label = function ( $created_by ) {
	if ( ! (int) $created_by ) {
		return __( 'Institucional', 'sistema-educativo' );
	}
	$u = get_userdata( (int) $created_by );
	return $u ? $u->display_name : __( 'Docente', 'sistema-educativo' );
};
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Componentes evaluables', 'sistema-educativo' ); ?></h1>
	<?php if ( ! $es_docente ) : ?>
		<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>
	<?php endif; ?>

	<?php if ( 'updated' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Componentes guardados.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'warning' === $status && 'weight_sum' === $code ) : ?>
		<div class="notice notice-warning"><p>
			<?php
			/* translators: %s: suma actual de pesos */
			echo esc_html( sprintf( __( 'Componentes guardados. La suma de pesos es %s: el cálculo del parcial renormaliza automáticamente para que equivalga al 100%%.', 'sistema-educativo' ), $weight_sum ) );
			?>
		</p></div>
	<?php elseif ( 'warning' === $status && 'has_grades' === $code ) : ?>
		<div class="notice notice-warning"><p>
			<?php
			/* translators: %d: número de componentes conservados */
			echo esc_html( sprintf( _n( 'Componentes guardados, pero %d componente no se eliminó porque ya tiene notas registradas.', 'Componentes guardados, pero %d componentes no se eliminaron porque ya tienen notas registradas.', $protegidos, 'sistema-educativo' ), $protegidos ) );
			?>
		</p></div>
	<?php elseif ( 'error' === $status ) : ?>
		<div class="notice notice-error"><p>
			<?php
			$msgs = array(
				'invalid_subject'   => __( 'Materia inválida.', 'sistema-educativo' ),
				'invalid_trimester' => __( 'Trimestre inválido.', 'sistema-educativo' ),
				'invalid_parcial'   => __( 'Parcial inválido.', 'sistema-educativo' ),
			);
			echo esc_html( $msgs[ $code ] ?? __( 'Error al guardar.', 'sistema-educativo' ) );
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( $es_docente ) : ?>
		<p class="description" style="max-width:720px;">
			<?php esc_html_e( 'Los componentes institucionales los define la dirección y no se pueden modificar. Puedes agregar tus propios componentes de evaluación: los pesos se renormalizan automáticamente para que el parcial siempre equivalga al 100%.', 'sistema-educativo' ); ?>
		</p>
	<?php endif; ?>

	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'No es obligatorio pasar por aquí: al crear una tarea puedes escribir el nombre del componente y se crea solo. Esta pantalla sirve para revisar el set completo del parcial y para ajustar pesos.', 'sistema-educativo' ); ?>
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
		<input type="hidden" name="page" value="edu-componentes">

		<label><strong><?php esc_html_e( 'Materia:', 'sistema-educativo' ); ?></strong>
			<select name="subject_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $subjects as $s ) : ?>
					<option value="<?php echo (int) $s->id; ?>" <?php selected( $subject_id, (int) $s->id ); ?>>
						<?php echo esc_html( $s->name . ( $s->code ? ' (' . $s->code . ')' : '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="margin-left:12px;"><strong><?php esc_html_e( 'Trimestre:', 'sistema-educativo' ); ?></strong>
			<select name="trimester_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $trimesters as $t ) : ?>
					<option value="<?php echo (int) $t->id; ?>" <?php selected( $trimester_id, (int) $t->id ); ?>>
						<?php echo esc_html( $t->period_name . ' · Trimestre ' . $t->number ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="margin-left:12px;"><strong><?php esc_html_e( 'Parcial:', 'sistema-educativo' ); ?></strong>
			<select name="parcial_num" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<option value="1" <?php selected( $parcial_num, 1 ); ?>><?php esc_html_e( 'Parcial 1', 'sistema-educativo' ); ?></option>
				<option value="2" <?php selected( $parcial_num, 2 ); ?>><?php esc_html_e( 'Parcial 2', 'sistema-educativo' ); ?></option>
			</select>
		</label>
	</form>

	<?php if ( $selected ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="edu-components-form">
		<?php wp_nonce_field( 'edu_save_components' ); ?>
		<input type="hidden" name="action" value="edu_save_components">
		<input type="hidden" name="subject_id"   value="<?php echo (int) $subject_id; ?>">
		<input type="hidden" name="trimester_id" value="<?php echo (int) $trimester_id; ?>">
		<input type="hidden" name="parcial_num"  value="<?php echo (int) $parcial_num; ?>">

		<table class="widefat striped" id="edu-components-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Componente', 'sistema-educativo' ); ?></th>
					<th style="width:140px;"><?php esc_html_e( 'Peso (0.00 - 1.00)', 'sistema-educativo' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Origen', 'sistema-educativo' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Notas', 'sistema-educativo' ); ?></th>
					<th style="width:80px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$hay_editables = false;
				foreach ( $rows as $r ) :
					$editable = $puede_todo || (int) $r->created_by === $uid;
					$hay_editables = $hay_editables || $editable;
					$origen = $edu_origen_label( $r->created_by );
				?>
					<?php if ( $editable ) : ?>
					<tr class="edu-component-row">
						<td>
							<input type="hidden" name="ids[]" value="<?php echo (int) $r->id; ?>">
							<input type="text" name="names[]" value="<?php echo esc_attr( $r->name ); ?>" class="regular-text">
						</td>
						<td><input type="number" name="weights[]" value="<?php echo esc_attr( number_format( (float) $r->weight, 2, '.', '' ) ); ?>" step="0.01" min="0" max="1" class="edu-weight" style="width:100px;"></td>
						<td><?php echo (int) $r->created_by ? esc_html( $origen ) : '<strong>' . esc_html( $origen ) . '</strong>'; ?></td>
						<td>
							<?php if ( (int) $r->n_notas > 0 ) : ?>
								<span title="<?php esc_attr_e( 'No se puede eliminar: tiene notas registradas.', 'sistema-educativo' ); ?>">🔒 <?php echo (int) $r->n_notas; ?></span>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td>
							<?php if ( (int) $r->n_notas === 0 ) : ?>
								<button type="button" class="button edu-remove-row"><?php esc_html_e( 'Quitar', 'sistema-educativo' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
					<?php else : ?>
					<tr class="edu-component-row-fixed" style="background:#f8fafc;">
						<td style="padding-left:12px;"><?php echo esc_html( $r->name ); ?></td>
						<td><span class="edu-weight-fixed" data-weight="<?php echo esc_attr( number_format( (float) $r->weight, 2, '.', '' ) ); ?>"><?php echo esc_html( number_format( (float) $r->weight, 2, '.', '' ) ); ?></span></td>
						<td><strong><?php echo esc_html( $origen ); ?></strong></td>
						<td><?php echo (int) $r->n_notas > 0 ? (int) $r->n_notas : '—'; ?></td>
						<td><span class="description"><?php esc_html_e( 'Solo lectura', 'sistema-educativo' ); ?></span></td>
					</tr>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php if ( empty( $rows ) ) : ?>
					<tr class="edu-component-row">
						<td>
							<input type="hidden" name="ids[]" value="0">
							<input type="text" name="names[]" value="" class="regular-text" placeholder="<?php esc_attr_e( 'Ej. Tareas', 'sistema-educativo' ); ?>">
						</td>
						<td><input type="number" name="weights[]" value="1.00" step="0.01" min="0.01" max="1" class="edu-weight" style="width:100px;"></td>
						<td><?php echo $puede_todo ? '<strong>' . esc_html__( 'Institucional', 'sistema-educativo' ) . '</strong>' : esc_html( wp_get_current_user()->display_name ); ?></td>
						<td>—</td>
						<td><button type="button" class="button edu-remove-row"><?php esc_html_e( 'Quitar', 'sistema-educativo' ); ?></button></td>
					</tr>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<tr>
					<th style="text-align:right;"><?php esc_html_e( 'Suma de pesos:', 'sistema-educativo' ); ?></th>
					<th><span id="edu-weight-sum">0.00</span></th>
					<th colspan="3" class="description"><?php esc_html_e( 'Solo importa la proporción entre los pesos: dejarlos todos en 1.00 significa que todos los componentes pesan igual. El cálculo renormaliza automáticamente.', 'sistema-educativo' ); ?></th>
				</tr>
			</tfoot>
		</table>

		<p>
			<button type="button" class="button" id="edu-add-row">+ <?php esc_html_e( 'Agregar componente', 'sistema-educativo' ); ?></button>
		</p>
		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar componentes', 'sistema-educativo' ); ?></button>
		</p>

		<template id="edu-component-template">
			<tr class="edu-component-row">
				<td>
					<input type="hidden" name="ids[]" value="0">
					<input type="text" name="names[]" value="" class="regular-text">
				</td>
				<td><input type="number" name="weights[]" value="1.00" step="0.01" min="0.01" max="1" class="edu-weight" style="width:100px;"></td>
				<td><?php echo $puede_todo ? '<strong>' . esc_html__( 'Institucional', 'sistema-educativo' ) . '</strong>' : esc_html( wp_get_current_user()->display_name ); ?></td>
				<td>—</td>
				<td><button type="button" class="button edu-remove-row"><?php esc_html_e( 'Quitar', 'sistema-educativo' ); ?></button></td>
			</tr>
		</template>
	</form>

	<script>
	(function(){
		var tbody = document.querySelector('#edu-components-table tbody');
		var tpl   = document.getElementById('edu-component-template');
		var addBtn = document.getElementById('edu-add-row');
		var sumEl = document.getElementById('edu-weight-sum');

		function recalc() {
			var s = 0;
			tbody.querySelectorAll('.edu-weight').forEach(function(i){
				var v = parseFloat(i.value);
				if (!isNaN(v)) s += v;
			});
			tbody.querySelectorAll('.edu-weight-fixed').forEach(function(el){
				var v = parseFloat(el.dataset.weight);
				if (!isNaN(v)) s += v;
			});
			sumEl.textContent = s.toFixed(2);
			sumEl.style.color = Math.abs(s - 1) < 0.01 ? 'green' : '#b45309';
		}

		addBtn.addEventListener('click', function(){
			tbody.appendChild(tpl.content.cloneNode(true));
			recalc();
		});

		tbody.addEventListener('click', function(e){
			if (e.target.classList.contains('edu-remove-row')) {
				e.target.closest('tr').remove();
				recalc();
			}
		});

		tbody.addEventListener('input', function(e){
			if (e.target.classList.contains('edu-weight')) recalc();
		});

		recalc();
	})();
	</script>
	<?php endif; ?>
</div>
