<?php
/**
 * Vista: Auditoría del sistema.
 *
 * Muestra el log completo de acciones con filtros por fecha, acción y usuario.
 * Visible solo para rector y administrador WP.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( Edu_Context::can( 'edu_view_audit' ) || current_user_can( 'manage_options' ) ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

global $wpdb;
$ta  = $wpdb->prefix . 'edu_audit';
$per_page = 50;
$page_num = isset( $_GET['audit_page'] ) ? max( 1, (int) $_GET['audit_page'] ) : 1;
$offset   = ( $page_num - 1 ) * $per_page;

// ── Filtros ──────────────────────────────────────────────────────────────────
$f_action   = isset( $_GET['f_action'] )   ? sanitize_key( $_GET['f_action'] )                        : '';
$f_user     = isset( $_GET['f_user'] )     ? (int) $_GET['f_user']                                    : 0;
$f_desde    = isset( $_GET['f_desde'] )    ? sanitize_text_field( wp_unslash( $_GET['f_desde'] ) )    : '';
$f_hasta    = isset( $_GET['f_hasta'] )    ? sanitize_text_field( wp_unslash( $_GET['f_hasta'] ) )    : '';
$f_entity   = isset( $_GET['f_entity'] )   ? sanitize_key( $_GET['f_entity'] )                        : '';

$where  = 'WHERE 1=1';
$params = array();

if ( $f_action ) {
	$where   .= ' AND a.action = %s';
	$params[] = $f_action;
}
if ( $f_user ) {
	$where   .= ' AND a.user_id = %d';
	$params[] = $f_user;
}
if ( $f_entity ) {
	$where   .= ' AND a.entity_type = %s';
	$params[] = $f_entity;
}
if ( $f_desde ) {
	$where   .= ' AND DATE(a.timestamp) >= %s';
	$params[] = $f_desde;
}
if ( $f_hasta ) {
	$where   .= ' AND DATE(a.timestamp) <= %s';
	$params[] = $f_hasta;
}

$base_sql = "FROM $ta a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id $where";
$total    = (int) ( $params
	? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $base_sql", ...$params ) )
	: $wpdb->get_var( "SELECT COUNT(*) $base_sql" ) );

$data_sql = "SELECT a.*, u.display_name AS user_name $base_sql ORDER BY a.timestamp DESC LIMIT %d OFFSET %d";
$p_all    = array_merge( $params, array( $per_page, $offset ) );
$rows     = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$p_all ) );

// Usuarios para filtro (solo los que tienen registros de auditoría).
$audit_users = $wpdb->get_results(
	"SELECT DISTINCT a.user_id, u.display_name
	 FROM $ta a INNER JOIN {$wpdb->users} u ON u.ID = a.user_id
	 ORDER BY u.display_name"
);

// Acciones únicas para el filtro.
$audit_actions = $wpdb->get_col( "SELECT DISTINCT action FROM $ta ORDER BY action" );

$total_pages = $total > 0 ? ceil( $total / $per_page ) : 1;

$action_icons = array(
	'nota_ingresada'      => '📝',
	'examen_guardado'     => '📋',
	'parcial_cerrado'     => '🔒',
	'trimestre_cerrado'   => '🔐',
	'tarea_creada'        => '➕',
	'tarea_editada'       => '✏️',
	'tarea_publicada'     => '📢',
	'tarea_cerrada'       => '✅',
	'tarea_eliminada'     => '🗑️',
	'entrega_calificada'  => '🏆',
	'asistencia_guardada' => '📅',
	'comunicado_enviado'  => '📨',
);

// URL base para filtros y paginación (sin audit_page ni los filtros actuales).
$base_url = add_query_arg( array( 'page' => 'edu-auditoria' ), admin_url( 'admin.php' ) );
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Auditoría del sistema', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<!-- Filtros -->
	<form method="get" style="background:#fff; border:1px solid #ddd; border-radius:4px; padding:16px; margin:16px 0; display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
		<input type="hidden" name="page" value="edu-auditoria">

		<label style="display:flex; flex-direction:column; gap:4px; min-width:150px;">
			<strong style="font-size:12px;"><?php esc_html_e( 'Acción', 'sistema-educativo' ); ?></strong>
			<select name="f_action">
				<option value=""><?php esc_html_e( '— Todas —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $audit_actions as $act ) : ?>
					<option value="<?php echo esc_attr( $act ); ?>" <?php selected( $f_action, $act ); ?>>
						<?php echo esc_html( Edu_Audit::action_label( $act ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="display:flex; flex-direction:column; gap:4px; min-width:160px;">
			<strong style="font-size:12px;"><?php esc_html_e( 'Usuario', 'sistema-educativo' ); ?></strong>
			<select name="f_user">
				<option value="0"><?php esc_html_e( '— Todos —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $audit_users as $u ) : ?>
					<option value="<?php echo (int) $u->user_id; ?>" <?php selected( $f_user, (int) $u->user_id ); ?>>
						<?php echo esc_html( $u->display_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="display:flex; flex-direction:column; gap:4px;">
			<strong style="font-size:12px;"><?php esc_html_e( 'Desde', 'sistema-educativo' ); ?></strong>
			<input type="date" name="f_desde" value="<?php echo esc_attr( $f_desde ); ?>">
		</label>

		<label style="display:flex; flex-direction:column; gap:4px;">
			<strong style="font-size:12px;"><?php esc_html_e( 'Hasta', 'sistema-educativo' ); ?></strong>
			<input type="date" name="f_hasta" value="<?php echo esc_attr( $f_hasta ); ?>">
		</label>

		<div style="display:flex; gap:8px; align-items:flex-end;">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Filtrar', 'sistema-educativo' ); ?></button>
			<?php if ( $f_action || $f_user || $f_desde || $f_hasta || $f_entity ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Limpiar', 'sistema-educativo' ); ?></a>
			<?php endif; ?>
		</div>
	</form>

	<!-- Contador -->
	<p style="color:#6b7280; font-size:13px; margin-bottom:12px;">
		<?php
		printf(
			/* translators: %1$d total, %2$d inicio, %3$d fin */
			esc_html__( '%1$d registros · mostrando %2$d – %3$d', 'sistema-educativo' ),
			$total,
			$total > 0 ? $offset + 1 : 0,
			min( $offset + $per_page, $total )
		);
		?>
	</p>

	<?php if ( empty( $rows ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'No hay registros para los filtros seleccionados.', 'sistema-educativo' ); ?></p></div>
	<?php else : ?>

	<div style="overflow-x:auto;">
	<table class="widefat striped" style="font-size:13px;">
		<thead>
			<tr>
				<th style="width:150px;"><?php esc_html_e( 'Fecha y hora', 'sistema-educativo' ); ?></th>
				<th style="width:160px;"><?php esc_html_e( 'Usuario', 'sistema-educativo' ); ?></th>
				<th style="width:175px;"><?php esc_html_e( 'Acción', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Descripción / Valor nuevo', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Valor anterior', 'sistema-educativo' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'IP', 'sistema-educativo' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $row ) :
			$icon  = $action_icons[ $row->action ] ?? '•';
			$color = Edu_Audit::action_color( $row->action );
			$dt    = strtotime( $row->timestamp );
		?>
			<tr>
				<td style="white-space:nowrap;">
					<strong><?php echo esc_html( date_i18n( 'd/m/Y', $dt ) ); ?></strong><br>
					<span style="color:#6b7280; font-size:11px;"><?php echo esc_html( date_i18n( 'H:i:s', $dt ) ); ?></span>
				</td>
				<td>
					<?php echo esc_html( $row->user_name ?: __( '(sistema)', 'sistema-educativo' ) ); ?>
					<br><small style="color:#9ca3af;">#<?php echo (int) $row->user_id; ?></small>
				</td>
				<td>
					<span style="display:inline-flex; align-items:center; gap:5px; background:<?php echo esc_attr( $color ); ?>18; color:<?php echo esc_attr( $color ); ?>; padding:2px 8px; border-radius:4px; font-size:12px; font-weight:600; white-space:nowrap;">
						<?php echo $icon; ?> <?php echo esc_html( Edu_Audit::action_label( $row->action ) ); ?>
					</span>
					<?php if ( $row->entity_type ) : ?>
					<br><small style="color:#9ca3af;"><?php echo esc_html( $row->entity_type ); ?><?php echo $row->entity_id ? ' #' . (int) $row->entity_id : ''; ?></small>
					<?php endif; ?>
				</td>
				<td style="max-width:320px; word-break:break-word;">
					<?php
					if ( $row->new_value !== null ) {
						$decoded = json_decode( $row->new_value, true );
						if ( is_array( $decoded ) ) {
							echo '<ul style="margin:0; padding-left:16px; font-size:12px;">';
							foreach ( $decoded as $k => $v ) {
								echo '<li><strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v ) . '</li>';
							}
							echo '</ul>';
						} else {
							echo esc_html( $row->new_value );
						}
					} else {
						echo '<span style="color:#d1d5db;">—</span>';
					}
					?>
				</td>
				<td style="max-width:220px; word-break:break-word; color:#6b7280; font-size:12px;">
					<?php
					if ( $row->old_value !== null ) {
						$decoded = json_decode( $row->old_value, true );
						if ( is_array( $decoded ) ) {
							echo '<ul style="margin:0; padding-left:16px;">';
							foreach ( $decoded as $k => $v ) {
								echo '<li><strong>' . esc_html( $k ) . ':</strong> ' . esc_html( $v ) . '</li>';
							}
							echo '</ul>';
						} else {
							echo esc_html( $row->old_value );
						}
					} else {
						echo '—';
					}
					?>
				</td>
				<td style="font-size:11px; color:#9ca3af;"><?php echo esc_html( $row->ip_address ?: '—' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<!-- Paginación -->
	<?php if ( $total_pages > 1 ) :
		$filter_args = array_filter( array(
			'page'     => 'edu-auditoria',
			'f_action' => $f_action,
			'f_user'   => $f_user ?: null,
			'f_desde'  => $f_desde,
			'f_hasta'  => $f_hasta,
			'f_entity' => $f_entity,
		) );
	?>
	<div style="display:flex; gap:6px; margin-top:16px; flex-wrap:wrap; align-items:center;">
		<?php if ( $page_num > 1 ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array_merge( $filter_args, array( 'audit_page' => $page_num - 1 ) ), admin_url( 'admin.php' ) ) ); ?>" class="button">&#8592; <?php esc_html_e( 'Anterior', 'sistema-educativo' ); ?></a>
		<?php endif; ?>

		<span style="padding:4px 10px; font-size:13px; color:#374151;">
			<?php printf( esc_html__( 'Página %1$d de %2$d', 'sistema-educativo' ), $page_num, $total_pages ); ?>
		</span>

		<?php if ( $page_num < $total_pages ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array_merge( $filter_args, array( 'audit_page' => $page_num + 1 ) ), admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Siguiente', 'sistema-educativo' ); ?> &#8594;</a>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php endif; ?>
</div>
