<?php
/**
 * Vista admin: Gestión de cuentas — activar/suspender padres y estudiantes.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! Edu_Context::can( 'edu_view_all' ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

global $wpdb;
$p              = $wpdb->prefix . 'edu_';
$institution_id = Edu_Context::current_institution_id();
$current_url    = admin_url( 'admin.php?page=edu-cuentas' );

// ── Filtros GET ──────────────────────────────────────────────────────────────
$role_filter   = isset( $_GET['edu_role_filter'] )   ? sanitize_key( $_GET['edu_role_filter'] )   : 'all';
$grade_filter  = isset( $_GET['edu_grade_filter'] )  ? (int) $_GET['edu_grade_filter']            : 0;
$status_filter = isset( $_GET['edu_status_filter'] ) ? sanitize_key( $_GET['edu_status_filter'] ) : 'all';

// ── Grados para el selector de filtro ───────────────────────────────────────
$grades = array();
if ( $institution_id ) {
	$grades = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, paralelo FROM {$p}grades WHERE institution_id = %d ORDER BY name, paralelo",
		$institution_id
	) );
}

// ── Construir lista de usuarios ──────────────────────────────────────────────
$users = array();

if ( $institution_id ) {
	// Estudiantes.
	if ( 'all' === $role_filter || 'estudiante' === $role_filter ) {
		$where_grade = $grade_filter
			? $wpdb->prepare( ' AND g.id = %d', $grade_filter )
			: '';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT u.ID AS user_id, u.user_email,
			        COALESCE(um_fn.meta_value, '')           AS first_name,
			        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
			        COALESCE(um_st.meta_value, 'active')     AS account_status,
			        'estudiante'                              AS rol,
			        g.name                                    AS grade_name,
			        g.paralelo                                AS paralelo
			 FROM {$p}students s
			 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
			 INNER JOIN {$p}grades g     ON g.id = s.grade_id
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = u.ID AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = u.ID AND um_ln.meta_key = 'last_name'
			 LEFT JOIN {$wpdb->usermeta} um_st ON um_st.user_id = u.ID AND um_st.meta_key = 'edu_account_status'
			 WHERE g.institution_id = %d $where_grade
			 ORDER BY last_name, first_name",
			$institution_id
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$users = array_merge( $users, (array) $rows );
	}

	// Padres.
	if ( 'all' === $role_filter || 'padre' === $role_filter ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT u.ID AS user_id, u.user_email,
			        COALESCE(um_fn.meta_value, '')            AS first_name,
			        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
			        COALESCE(um_st.meta_value, 'active')      AS account_status,
			        'padre'                                    AS rol,
			        NULL                                       AS grade_name,
			        NULL                                       AS paralelo
			 FROM {$p}parents par
			 INNER JOIN {$wpdb->users} u ON u.ID = par.user_id
			 INNER JOIN {$p}parent_student ps ON ps.parent_id = par.id
			 INNER JOIN {$p}students st ON st.id = ps.student_id
			 INNER JOIN {$p}grades g   ON g.id = st.grade_id
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = u.ID AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = u.ID AND um_ln.meta_key = 'last_name'
			 LEFT JOIN {$wpdb->usermeta} um_st ON um_st.user_id = u.ID AND um_st.meta_key = 'edu_account_status'
			 WHERE g.institution_id = %d
			 ORDER BY last_name, first_name",
			$institution_id
		) );
		$users = array_merge( $users, (array) $rows );
	}
}

// Filtro de estado.
if ( 'all' !== $status_filter && '' !== $status_filter ) {
	$users = array_values( array_filter( $users, function ( $u ) use ( $status_filter ) {
		return $u->account_status === $status_filter;
	} ) );
}

// Mensajes de resultado.
$status_msg = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$changed    = isset( $_GET['changed'] ) ? (int) $_GET['changed'] : 0;
$code       = isset( $_GET['code'] )    ? sanitize_key( $_GET['code'] )   : '';

$status_colors = array(
	'active'    => '#16a34a',
	'suspended' => '#dc2626',
);
?>
<div class="wrap">
<h1><?php esc_html_e( 'Gestión de cuentas', 'sistema-educativo' ); ?></h1>

<?php if ( 'updated' === $status_msg ) : ?>
	<div class="notice notice-success is-dismissible">
		<p><?php printf( esc_html__( '%d cuenta(s) actualizada(s).', 'sistema-educativo' ), $changed ); ?></p>
	</div>
<?php elseif ( 'error' === $status_msg ) : ?>
	<div class="notice notice-error is-dismissible">
		<p><?php printf( esc_html__( 'Error: %s', 'sistema-educativo' ), esc_html( $code ) ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! $institution_id ) : ?>
	<div class="notice notice-warning"><p><?php esc_html_e( 'No hay institución activa.', 'sistema-educativo' ); ?></p></div>
<?php else : ?>

<!-- ── Filtros ── -->
<form method="GET" action="<?php echo esc_url( $current_url ); ?>" style="margin-bottom:16px;">
	<input type="hidden" name="page" value="edu-cuentas">
	<select name="edu_role_filter">
		<option value="all"        <?php selected( $role_filter, 'all' ); ?>><?php esc_html_e( 'Todos los roles',   'sistema-educativo' ); ?></option>
		<option value="estudiante" <?php selected( $role_filter, 'estudiante' ); ?>><?php esc_html_e( 'Estudiantes', 'sistema-educativo' ); ?></option>
		<option value="padre"      <?php selected( $role_filter, 'padre' ); ?>><?php esc_html_e( 'Representantes', 'sistema-educativo' ); ?></option>
	</select>

	<?php if ( 'padre' !== $role_filter && ! empty( $grades ) ) : ?>
	<select name="edu_grade_filter">
		<option value="0"><?php esc_html_e( 'Todos los grados', 'sistema-educativo' ); ?></option>
		<?php foreach ( $grades as $g ) : ?>
		<option value="<?php echo (int) $g->id; ?>" <?php selected( $grade_filter, (int) $g->id ); ?>>
			<?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?>
		</option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>

	<select name="edu_status_filter">
		<option value="all"       <?php selected( $status_filter, 'all' ); ?>><?php esc_html_e( 'Todos los estados', 'sistema-educativo' ); ?></option>
		<option value="active"    <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Activos',     'sistema-educativo' ); ?></option>
		<option value="suspended" <?php selected( $status_filter, 'suspended' ); ?>><?php esc_html_e( 'Suspendidos', 'sistema-educativo' ); ?></option>
	</select>

	<?php submit_button( __( 'Filtrar', 'sistema-educativo' ), 'secondary', '', false ); ?>
</form>

<!-- ── Tabla con acciones masivas ── -->
<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="edu_bulk_account_status">
	<input type="hidden" name="_redirect" value="<?php echo esc_url( add_query_arg( array( 'edu_role_filter' => $role_filter, 'edu_grade_filter' => $grade_filter, 'edu_status_filter' => $status_filter ), $current_url ) ); ?>">
	<?php wp_nonce_field( 'edu_bulk_account_status' ); ?>

	<div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
		<select name="bulk_action">
			<option value=""><?php esc_html_e( 'Acción masiva', 'sistema-educativo' ); ?></option>
			<option value="activate"><?php esc_html_e( '✅ Activar seleccionados',   'sistema-educativo' ); ?></option>
			<option value="suspend"><?php esc_html_e( '🚫 Suspender seleccionados', 'sistema-educativo' ); ?></option>
		</select>
		<?php submit_button( __( 'Aplicar', 'sistema-educativo' ), 'secondary', '', false ); ?>
		<span style="color:#6b7280; font-size:13px;">
			<?php printf( esc_html__( '%d usuario(s)', 'sistema-educativo' ), count( $users ) ); ?>
		</span>
	</div>

	<table class="wp-list-table widefat fixed striped" style="border-radius:6px; overflow:hidden;">
		<thead>
			<tr>
				<th style="width:32px;"><input type="checkbox" id="edu-select-all"></th>
				<th><?php esc_html_e( 'Nombre', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Email',  'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Rol',    'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Grado',  'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Acción', 'sistema-educativo' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $users ) ) : ?>
			<tr><td colspan="7" style="text-align:center; color:#9ca3af; padding:24px;">
				<?php esc_html_e( 'No se encontraron usuarios con los filtros seleccionados.', 'sistema-educativo' ); ?>
			</td></tr>
		<?php else : ?>
			<?php foreach ( $users as $u ) :
				$is_active    = ( 'suspended' !== $u->account_status );
				$new_status   = $is_active ? 'suspended' : 'active';
				$badge_color  = $is_active ? '#16a34a' : '#dc2626';
				$badge_bg     = $is_active ? '#dcfce7' : '#fee2e2';
				$badge_label  = $is_active
					? __( 'Activo',     'sistema-educativo' )
					: __( 'Suspendido', 'sistema-educativo' );
				$btn_label    = $is_active
					? __( '🚫 Suspender', 'sistema-educativo' )
					: __( '✅ Activar',   'sistema-educativo' );
				$btn_class    = $is_active ? 'button-link-delete' : 'button button-small';
				$full_name    = trim( $u->first_name . ' ' . $u->last_name ) ?: $u->user_email;
				$rol_label    = 'padre' === $u->rol
					? __( 'Representante', 'sistema-educativo' )
					: __( 'Estudiante',   'sistema-educativo' );
				$rol_color    = 'padre' === $u->rol ? '#7c3aed' : '#1d4ed8';
			?>
			<tr>
				<td><input type="checkbox" name="user_ids[]" value="<?php echo (int) $u->user_id; ?>"></td>
				<td><strong><?php echo esc_html( $full_name ); ?></strong></td>
				<td style="color:#6b7280; font-size:12px;"><?php echo esc_html( $u->user_email ); ?></td>
				<td>
					<span style="font-size:11px; font-weight:600; color:<?php echo esc_attr( $rol_color ); ?>;">
						<?php echo esc_html( $rol_label ); ?>
					</span>
				</td>
				<td style="font-size:12px; color:#374151;">
					<?php echo $u->grade_name ? esc_html( $u->grade_name . ' ' . $u->paralelo ) : '—'; ?>
				</td>
				<td>
					<span style="font-size:11px; font-weight:600; color:<?php echo esc_attr( $badge_color ); ?>; background:<?php echo esc_attr( $badge_bg ); ?>; padding:2px 10px; border-radius:99px;">
						<?php echo esc_html( $badge_label ); ?>
					</span>
					<?php if ( 'padre' === $u->rol && 'suspended' === $u->account_status ) : ?>
						<br><span style="font-size:10px; color:#9ca3af;"><?php esc_html_e( '(hijos también suspendidos)', 'sistema-educativo' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action"     value="edu_toggle_account_status">
						<input type="hidden" name="user_id"    value="<?php echo (int) $u->user_id; ?>">
						<input type="hidden" name="new_status" value="<?php echo esc_attr( $new_status ); ?>">
						<input type="hidden" name="_redirect"  value="<?php echo esc_url( add_query_arg( array( 'edu_role_filter' => $role_filter, 'edu_grade_filter' => $grade_filter, 'edu_status_filter' => $status_filter ), $current_url ) ); ?>">
						<?php wp_nonce_field( 'edu_toggle_account_status' ); ?>
						<button type="submit" class="<?php echo esc_attr( $btn_class ); ?>"
							<?php if ( $is_active ) : ?>
								onclick="return confirm('<?php echo esc_js( __( '¿Suspender esta cuenta? Si es representante, sus estudiantes también serán suspendidos.', 'sistema-educativo' ) ); ?>')"
							<?php endif; ?>>
							<?php echo esc_html( $btn_label ); ?>
						</button>
					</form>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</form>

<p style="font-size:12px; color:#6b7280; margin-top:8px;">
	&#9432; <?php esc_html_e( 'Suspender un representante suspende automáticamente todos sus estudiantes vinculados.', 'sistema-educativo' ); ?>
</p>

<?php endif; ?>
</div>

<script>
(function(){
	var cb = document.getElementById('edu-select-all');
	if (!cb) return;
	cb.addEventListener('change', function(){
		document.querySelectorAll('input[name="user_ids[]"]').forEach(function(c){ c.checked = cb.checked; });
	});
})();
</script>
