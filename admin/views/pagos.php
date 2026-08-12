<?php
/**
 * Vista admin: Módulo de Pagos — configuración + dashboard.
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
$current_url    = admin_url( 'admin.php?page=edu-pagos' );

// Mensajes de estado.
$status_msg   = isset( $_GET['status'] )   ? sanitize_key( $_GET['status'] ) : '';
$changed      = isset( $_GET['changed'] )  ? (int) $_GET['changed'] : 0;
$pay_link     = isset( $_GET['pay_link'] ) ? esc_url( rawurldecode( wp_unslash( $_GET['pay_link'] ) ) ) : '';
$ph_test      = isset( $_GET['ph_test'] )     ? sanitize_key( $_GET['ph_test'] ) : '';
$ph_test_msg  = isset( $_GET['ph_test_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ph_test_msg'] ) ) ) : '';

// Sub-tab activo (acepta ?sub= o ?pago_sub= para compatibilidad frontend).
$sub = isset( $_GET['pago_sub'] ) ? sanitize_key( $_GET['pago_sub'] ) : ( isset( $_GET['sub'] ) ? sanitize_key( $_GET['sub'] ) : 'dashboard' );
$valid_subs = array( 'dashboard', 'config' );
if ( ! in_array( $sub, $valid_subs, true ) ) {
	$sub = 'dashboard';
}

// Períodos de la institución.
$periodos = array();
if ( $institution_id ) {
	$periodos = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, is_active FROM {$p}periods WHERE institution_id = %d ORDER BY is_active DESC, start_date DESC",
		$institution_id
	) );
}

// Período seleccionado (default: activo).
$period_id = isset( $_GET['pago_period'] ) ? (int) $_GET['pago_period'] : 0;
if ( ! $period_id ) {
	foreach ( $periodos as $per ) {
		if ( $per->is_active ) { $period_id = (int) $per->id; break; }
	}
	if ( ! $period_id && ! empty( $periodos ) ) {
		$period_id = (int) $periodos[0]->id;
	}
}

// Grados para filtro.
$grades = array();
if ( $institution_id ) {
	$grades = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, paralelo FROM {$p}grades WHERE institution_id = %d ORDER BY name, paralelo",
		$institution_id
	) );
}

$grade_filter  = isset( $_GET['pago_grade'] )  ? (int) $_GET['pago_grade']             : 0;
$status_filter = isset( $_GET['pago_status'] ) ? sanitize_key( $_GET['pago_status'] )  : 'all';
$month_filter  = isset( $_GET['pago_month'] )  ? sanitize_text_field( wp_unslash( $_GET['pago_month'] ) ) : '';

// Credenciales Payphone.
$ph_token    = get_option( 'edu_payphone_token', '' );
$ph_store_id = get_option( 'edu_payphone_store_id', '' );

// Configuración de pagos para el período seleccionado.
$configs_raw = array();
if ( $institution_id && $period_id ) {
	$configs_raw = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$p}payment_config WHERE institution_id = %d AND period_id = %d ORDER BY grade_id ASC",
		$institution_id, $period_id
	) );
}
$configs = array();
foreach ( $configs_raw as $c ) {
	$key            = null === $c->grade_id ? 'all' : (int) $c->grade_id;
	$configs[ $key ] = $c;
}

// Dashboard: pagos del período seleccionado.
$pagos = array();
if ( $institution_id && $period_id && 'dashboard' === $sub ) {
	$where_grade  = $grade_filter  ? $wpdb->prepare( ' AND g.id = %d', $grade_filter )  : '';
	$where_status = ( 'all' !== $status_filter && $status_filter ) ? $wpdb->prepare( ' AND pay.status = %s', $status_filter ) : '';
	$where_month  = $month_filter ? $wpdb->prepare( ' AND pay.month = %s', $month_filter ) : '';

	$pagos = $wpdb->get_results( $wpdb->prepare(
		"SELECT pay.id, pay.month, pay.amount, pay.due_date, pay.status, pay.paid_at,
		        pay.payment_method, pay.payment_ref, pay.payphone_transaction_id,
		        COALESCE(um_fn.meta_value,'') AS first_name,
		        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
		        g.name AS grade_name, g.paralelo
		 FROM {$p}payments pay
		 INNER JOIN {$p}students st ON st.id = pay.student_id
		 INNER JOIN {$p}grades g    ON g.id  = st.grade_id
		 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
		 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = u.ID AND um_fn.meta_key = 'first_name'
		 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = u.ID AND um_ln.meta_key = 'last_name'
		 WHERE pay.period_id = %d
		   AND g.institution_id = %d
		   $where_grade $where_status $where_month
		 ORDER BY last_name, first_name, pay.month",
		$period_id, $institution_id
	) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$status_colors = array(
	'pending' => array( 'bg' => '#dbeafe', 'color' => '#1d4ed8' ),
	'paid'    => array( 'bg' => '#dcfce7', 'color' => '#15803d' ),
	'overdue' => array( 'bg' => '#fee2e2', 'color' => '#dc2626' ),
	'waived'  => array( 'bg' => '#f3f4f6', 'color' => '#6b7280' ),
);

$meses_disponibles = array();
if ( $institution_id && $period_id ) {
	$meses_disponibles = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT pay.month FROM {$p}payments pay
		 INNER JOIN {$p}students st ON st.id = pay.student_id
		 INNER JOIN {$p}grades g ON g.id = st.grade_id
		 WHERE pay.period_id = %d AND g.institution_id = %d
		 ORDER BY pay.month",
		$period_id, $institution_id
	) );
}
?>
<div class="wrap">
<h1><?php esc_html_e( 'Módulo de Pagos', 'sistema-educativo' ); ?></h1>

<?php if ( 'updated' === $status_msg ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración guardada.', 'sistema-educativo' ); ?></p></div>
<?php elseif ( 'paid' === $status_msg ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pago registrado correctamente.', 'sistema-educativo' ); ?></p></div>
<?php elseif ( 'waived' === $status_msg ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pago exonerado.', 'sistema-educativo' ); ?></p></div>
<?php elseif ( 'suspended' === $status_msg ) : ?>
	<div class="notice notice-success is-dismissible">
		<p><?php printf( esc_html__( '%d cuenta(s) suspendidas por mora.', 'sistema-educativo' ), $changed ); ?></p>
	</div>
<?php elseif ( 'link_ok' === $status_msg && $pay_link ) : ?>
	<div class="notice notice-success">
		<p>
			<?php esc_html_e( 'Link de pago generado:', 'sistema-educativo' ); ?>
			<code style="user-select:all;"><?php echo esc_html( $pay_link ); ?></code>
			<button onclick="navigator.clipboard.writeText(<?php echo esc_js( $pay_link ); ?>)" class="button button-small" style="margin-left:8px;">
				<?php esc_html_e( 'Copiar', 'sistema-educativo' ); ?>
			</button>
		</p>
	</div>
<?php elseif ( 'error' === $status_msg ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Ocurrió un error. Intenta de nuevo.', 'sistema-educativo' ); ?></p></div>
<?php endif; ?>

<?php if ( 'ok' === $ph_test ) : ?>
	<div class="notice notice-success is-dismissible"><p>✅ <?php echo esc_html( $ph_test_msg ); ?></p></div>
<?php elseif ( 'error' === $ph_test && $ph_test_msg ) : ?>
	<div class="notice notice-error is-dismissible">
		<p>❌ <strong><?php esc_html_e( 'Fallo de conexión Payphone:', 'sistema-educativo' ); ?></strong></p>
		<pre style="background:#fff;padding:8px;overflow:auto;white-space:pre-wrap;font-size:12px;"><?php echo esc_html( $ph_test_msg ); ?></pre>
	</div>
<?php endif; ?>

<?php
// Diagnóstico: mostrar último webhook recibido de Payphone.
$last_wh = get_option( 'edu_debug_last_webhook' );
if ( $last_wh && 'config' === $sub ) :
?>
<div class="notice notice-info" style="font-size:12px;">
	<p><strong>🔍 Último webhook recibido de Payphone:</strong> <?php echo esc_html( $last_wh['time'] ?? '' ); ?></p>
	<pre style="background:#f8fafc;padding:8px;overflow:auto;max-height:120px;font-size:11px;white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( $last_wh['body'] ?? $last_wh['raw'] ?? '(vacío)', JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
</div>
<?php endif; ?>

<?php if ( ! $institution_id ) : ?>
	<div class="notice notice-warning"><p><?php esc_html_e( 'No hay institución activa.', 'sistema-educativo' ); ?></p></div>
<?php else : ?>

<!-- ── Sub-navegación ── -->
<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
	<a href="<?php echo esc_url( add_query_arg( 'sub', 'dashboard', $current_url ) ); ?>"
	   class="nav-tab <?php echo 'dashboard' === $sub ? 'nav-tab-active' : ''; ?>">
		<?php esc_html_e( 'Dashboard', 'sistema-educativo' ); ?>
	</a>
	<a href="<?php echo esc_url( add_query_arg( 'sub', 'config', $current_url ) ); ?>"
	   class="nav-tab <?php echo 'config' === $sub ? 'nav-tab-active' : ''; ?>">
		<?php esc_html_e( 'Configuración', 'sistema-educativo' ); ?>
	</a>
</nav>

<?php if ( 'config' === $sub ) : ?>
<!-- ============================================================
     CONFIGURACIÓN
     ============================================================ -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
	<input type="hidden" name="action" value="edu_save_payment_config">
	<?php wp_nonce_field( 'edu_save_payment_config' ); ?>

	<!-- Payphone -->
	<h2><?php esc_html_e( 'Credenciales Payphone', 'sistema-educativo' ); ?></h2>

	<!-- Cuadro de referencia para configurar en Payphone -->
	<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:16px 20px; margin-bottom:20px; max-width:660px;">
		<p style="margin:0 0 10px; font-weight:600; color:#1e40af;">📋 Datos para registrar en Payphone (pay.payphonetodoesposible.com)</p>
		<table style="width:100%; font-size:13px; border-collapse:collapse;">
			<tr>
				<td style="padding:4px 8px 4px 0; color:#374151; width:160px; font-weight:500;"><?php esc_html_e( 'Tipo de aplicación', 'sistema-educativo' ); ?></td>
				<td><code style="background:#dbeafe; padding:2px 8px; border-radius:4px; font-weight:700; color:#1d4ed8;">WEB</code></td>
			</tr>
			<tr>
				<td style="padding:4px 8px 4px 0; color:#374151; font-weight:500;"><?php esc_html_e( 'Dominio Web', 'sistema-educativo' ); ?></td>
				<td>
					<code id="edu-ph-domain" style="background:#f1f5f9; padding:2px 8px; border-radius:4px;"><?php echo esc_html( home_url() ); ?></code>
					<button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( home_url() ); ?>');this.textContent='✓'" class="button button-small" style="margin-left:6px;">Copiar</button>
				</td>
			</tr>
			<tr>
				<td style="padding:4px 8px 4px 0; color:#374151; font-weight:500;"><?php esc_html_e( 'URL de Respuesta', 'sistema-educativo' ); ?></td>
				<td>
					<?php $webhook_url = rest_url( 'edu/v1/payphone/webhook' ); ?>
					<code id="edu-ph-webhook" style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:12px; word-break:break-all;"><?php echo esc_html( $webhook_url ); ?></code>
					<button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $webhook_url ); ?>');this.textContent='✓'" class="button button-small" style="margin-left:6px;">Copiar</button>
				</td>
			</tr>
			<tr>
				<td style="padding:4px 8px 4px 0; color:#374151; font-weight:500;"><?php esc_html_e( 'URL de Cancelación', 'sistema-educativo' ); ?></td>
				<td>
					<?php
					$padre_page_id  = (int) get_option( 'edu_portal_padre_page_id', 0 );
					$cancel_url_pay = $padre_page_id ? add_query_arg( 'edu_tab', 'pagos', get_permalink( $padre_page_id ) ) : home_url( '/' );
					?>
					<code id="edu-ph-cancel" style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:12px; word-break:break-all;"><?php echo esc_html( $cancel_url_pay ); ?></code>
					<button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $cancel_url_pay ); ?>');this.textContent='✓'" class="button button-small" style="margin-left:6px;">Copiar</button>
					<span style="font-size:11px; color:#6b7280; display:block; margin-top:2px;"><?php esc_html_e( 'Configura esta URL en el portal Payphone para que aparezca el botón de retorno y cancelación.', 'sistema-educativo' ); ?></span>
				</td>
			</tr>
		</table>
	</div>

	<p class="description"><?php esc_html_e( 'Una vez creada la aplicación WEB en Payphone, copia aquí el Token y el Store ID.', 'sistema-educativo' ); ?></p>
	<table class="form-table">
		<tr>
			<th><label for="edu_payphone_token"><?php esc_html_e( 'Bearer Token', 'sistema-educativo' ); ?></label></th>
			<td>
				<input type="password" id="edu_payphone_token" name="edu_payphone_token"
				       value="<?php echo esc_attr( $ph_token ); ?>" class="regular-text">
				<?php if ( $ph_token ) : ?>
					<span style="color:#16a34a; font-size:12px; margin-left:8px;">✓ <?php esc_html_e( 'Configurado', 'sistema-educativo' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><label for="edu_payphone_store_id"><?php esc_html_e( 'Store ID (Botón)', 'sistema-educativo' ); ?></label></th>
			<td>
				<input type="text" id="edu_payphone_store_id" name="edu_payphone_store_id"
				       value="<?php echo esc_attr( $ph_store_id ); ?>" class="regular-text">
				<p class="description"><?php esc_html_e( 'Número entero que aparece como "ID" en tu aplicación Payphone (ej: 12345).', 'sistema-educativo' ); ?></p>
			</td>
		</tr>
	</table>

	<div style="margin:12px 0 24px 162px;">
		<button type="button" id="edu-btn-test-payphone" class="button button-secondary">
			🔌 <?php esc_html_e( 'Probar conexión con Payphone', 'sistema-educativo' ); ?>
		</button>
		<span style="font-size:12px; color:#6b7280; margin-left:10px;">
			<?php esc_html_e( 'Guarda primero y luego prueba.', 'sistema-educativo' ); ?>
		</span>
	</div>

	<!-- Configuración de pensiones por grado -->
	<h2 style="margin-top:28px;"><?php esc_html_e( 'Pensiones mensuales', 'sistema-educativo' ); ?></h2>

	<?php if ( empty( $periodos ) ) : ?>
		<p class="description"><?php esc_html_e( 'No hay períodos creados.', 'sistema-educativo' ); ?></p>
	<?php else : ?>
	<table class="form-table">
		<tr>
			<th><label for="config_period_id"><?php esc_html_e( 'Período', 'sistema-educativo' ); ?></label></th>
			<td>
				<select id="config_period_id" name="config_period_id" onchange="window.location='<?php echo esc_url( add_query_arg( array( 'page' => 'edu-pagos', 'sub' => 'config' ), admin_url( 'admin.php' ) ) ); ?>&pago_period='+this.value">
					<?php foreach ( $periodos as $per ) : ?>
						<option value="<?php echo (int) $per->id; ?>" <?php selected( $period_id, (int) $per->id ); ?>>
							<?php echo esc_html( $per->name . ( $per->is_active ? ' (activo)' : '' ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>

	<?php if ( $period_id ) : ?>
	<p class="description" style="margin-bottom:8px;">
		<?php esc_html_e( 'Configura el monto de pensión por grado. Usa "Todos los grados" como valor base cuando no quieras diferenciar.', 'sistema-educativo' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Pensión mensual ($)', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Matrícula ($)', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Día vencimiento', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Días de gracia', 'sistema-educativo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<!-- Fila "todos los grados" -->
			<?php
			$c_all = $configs['all'] ?? null;
			?>
			<tr>
				<td><strong><?php esc_html_e( 'Todos los grados', 'sistema-educativo' ); ?></strong></td>
				<td><input type="number" name="cfg[all][monthly_amount]" value="<?php echo esc_attr( $c_all ? $c_all->monthly_amount : '' ); ?>" step="0.01" min="0" style="width:90px;"></td>
				<td><input type="number" name="cfg[all][matricula_amount]" value="<?php echo esc_attr( $c_all ? $c_all->matricula_amount : '' ); ?>" step="0.01" min="0" style="width:90px;"></td>
				<td><input type="number" name="cfg[all][due_day]" value="<?php echo esc_attr( $c_all ? $c_all->due_day : '5' ); ?>" min="1" max="28" style="width:60px;"></td>
				<td><input type="number" name="cfg[all][grace_days]" value="<?php echo esc_attr( $c_all ? $c_all->grace_days : '5' ); ?>" min="0" max="30" style="width:60px;"></td>
			</tr>
			<?php foreach ( $grades as $g ) :
				$c = $configs[ (int) $g->id ] ?? null;
			?>
			<tr>
				<td><?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?></td>
				<td><input type="number" name="cfg[<?php echo (int) $g->id; ?>][monthly_amount]" value="<?php echo esc_attr( $c ? $c->monthly_amount : '' ); ?>" step="0.01" min="0" style="width:90px;" placeholder="—"></td>
				<td><input type="number" name="cfg[<?php echo (int) $g->id; ?>][matricula_amount]" value="<?php echo esc_attr( $c ? $c->matricula_amount : '' ); ?>" step="0.01" min="0" style="width:90px;" placeholder="—"></td>
				<td><input type="number" name="cfg[<?php echo (int) $g->id; ?>][due_day]" value="<?php echo esc_attr( $c ? $c->due_day : '' ); ?>" min="1" max="28" style="width:60px;" placeholder="—"></td>
				<td><input type="number" name="cfg[<?php echo (int) $g->id; ?>][grace_days]" value="<?php echo esc_attr( $c ? $c->grace_days : '' ); ?>" min="0" max="30" style="width:60px;" placeholder="—"></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
	<?php endif; ?>

	<?php submit_button( __( 'Guardar configuración', 'sistema-educativo' ) ); ?>
</form>

<!-- Formulario de test Payphone — separado del form principal para evitar form anidado -->
<form id="edu-test-ph-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
	<input type="hidden" name="action" value="edu_test_payphone_connection">
	<?php wp_nonce_field( 'edu_test_payphone_connection' ); ?>
</form>
<script>
(function() {
	var btn = document.getElementById('edu-btn-test-payphone');
	if ( btn ) {
		btn.addEventListener('click', function() {
			document.getElementById('edu-test-ph-form').submit();
		});
	}
}());
</script>

<?php else : ?>
<!-- ============================================================
     DASHBOARD
     ============================================================ -->

<!-- Filtros -->
<form method="GET" action="<?php echo esc_url( $current_url ); ?>" style="margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
	<input type="hidden" name="page" value="edu-pagos">
	<input type="hidden" name="sub" value="dashboard">

	<?php if ( ! empty( $periodos ) ) : ?>
	<select name="pago_period" onchange="this.form.submit()">
		<?php foreach ( $periodos as $per ) : ?>
			<option value="<?php echo (int) $per->id; ?>" <?php selected( $period_id, (int) $per->id ); ?>>
				<?php echo esc_html( $per->name . ( $per->is_active ? ' ✓' : '' ) ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php endif; ?>

	<select name="pago_grade">
		<option value="0"><?php esc_html_e( 'Todos los grados', 'sistema-educativo' ); ?></option>
		<?php foreach ( $grades as $g ) : ?>
			<option value="<?php echo (int) $g->id; ?>" <?php selected( $grade_filter, (int) $g->id ); ?>>
				<?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<select name="pago_month">
		<option value=""><?php esc_html_e( 'Todos los meses', 'sistema-educativo' ); ?></option>
		<?php foreach ( $meses_disponibles as $m ) : ?>
			<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month_filter, $m ); ?>>
				<?php echo esc_html( Edu_Payment_Manager::month_label( $m ) ); ?>
			</option>
		<?php endforeach; ?>
	</select>

	<select name="pago_status">
		<option value="all"     <?php selected( $status_filter, 'all' ); ?>><?php esc_html_e( 'Todos los estados', 'sistema-educativo' ); ?></option>
		<option value="pending" <?php selected( $status_filter, 'pending' ); ?>><?php esc_html_e( 'Pendiente', 'sistema-educativo' ); ?></option>
		<option value="paid"    <?php selected( $status_filter, 'paid' ); ?>><?php esc_html_e( 'Pagado', 'sistema-educativo' ); ?></option>
		<option value="overdue" <?php selected( $status_filter, 'overdue' ); ?>><?php esc_html_e( 'Vencido', 'sistema-educativo' ); ?></option>
		<option value="waived"  <?php selected( $status_filter, 'waived' ); ?>><?php esc_html_e( 'Exonerado', 'sistema-educativo' ); ?></option>
	</select>

	<?php submit_button( __( 'Filtrar', 'sistema-educativo' ), 'secondary', '', false ); ?>
</form>

<!-- Generar cuotas del mes actual -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-left:4px;">
	<input type="hidden" name="action" value="edu_generate_monthly_payments">
	<input type="hidden" name="period_id" value="<?php echo (int) $period_id; ?>">
	<?php wp_nonce_field( 'edu_generate_monthly_payments' ); ?>
	<button type="submit" class="button"
	        onclick="return confirm('<?php echo esc_js( __( '¿Generar cuotas del mes en curso para todos los estudiantes activos?', 'sistema-educativo' ) ); ?>')">
		⚡ <?php esc_html_e( 'Generar cuotas del mes', 'sistema-educativo' ); ?>
	</button>
</form>

<!-- Acciones masivas: suspender morosos -->
<?php if ( $period_id ) : ?>
<div style="margin-bottom:16px; padding:12px 16px; background:#fef9c3; border:1px solid #fde047; border-radius:8px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
	<span style="font-size:13px; color:#78350f;">
		<?php esc_html_e( 'Suspender cuentas con mora de más de:', 'sistema-educativo' ); ?>
	</span>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; align-items:center;">
		<input type="hidden" name="action" value="edu_suspend_overdue_payments">
		<input type="hidden" name="period_id" value="<?php echo (int) $period_id; ?>">
		<?php wp_nonce_field( 'edu_suspend_overdue_payments' ); ?>
		<input type="number" name="days_threshold" value="30" min="1" max="365" style="width:60px; padding:4px 6px;">
		<span style="font-size:13px;"><?php esc_html_e( 'días', 'sistema-educativo' ); ?></span>
		<button type="submit" class="button button-small"
		        onclick="return confirm('<?php echo esc_js( __( '¿Suspender todas las cuentas con mora mayor al umbral?', 'sistema-educativo' ) ); ?>')">
			🚫 <?php esc_html_e( 'Suspender morosos', 'sistema-educativo' ); ?>
		</button>
	</form>
</div>
<?php endif; ?>

<!-- Tabla de pagos -->
<?php if ( empty( $pagos ) ) : ?>
	<p style="color:#9ca3af;"><?php esc_html_e( 'No hay registros de pago con los filtros seleccionados.', 'sistema-educativo' ); ?></p>
<?php else : ?>
<table class="wp-list-table widefat fixed striped" style="border-radius:6px; overflow:hidden;">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
			<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
			<th><?php esc_html_e( 'Mes', 'sistema-educativo' ); ?></th>
			<th><?php esc_html_e( 'Monto', 'sistema-educativo' ); ?></th>
			<th><?php esc_html_e( 'Vencimiento', 'sistema-educativo' ); ?></th>
			<th><?php esc_html_e( 'Estado', 'sistema-educativo' ); ?></th>
			<th><?php esc_html_e( 'Pagado', 'sistema-educativo' ); ?></th>
			<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $pagos as $pay ) :
		$sc      = $status_colors[ $pay->status ] ?? array( 'bg' => '#f3f4f6', 'color' => '#374151' );
		$name    = trim( $pay->first_name . ' ' . $pay->last_name ) ?: '—';
		$is_open = in_array( $pay->status, array( 'pending', 'overdue' ), true );
	?>
	<tr>
		<td><strong><?php echo esc_html( $name ); ?></strong></td>
		<td style="font-size:12px;"><?php echo esc_html( $pay->grade_name . ' ' . $pay->paralelo ); ?></td>
		<td><?php echo esc_html( Edu_Payment_Manager::month_label( $pay->month ) ); ?></td>
		<td style="font-weight:600;">$<?php echo esc_html( number_format( (float) $pay->amount, 2 ) ); ?></td>
		<td style="font-size:12px;"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $pay->due_date ) ) ); ?></td>
		<td>
			<span style="font-size:11px; font-weight:600; color:<?php echo esc_attr( $sc['color'] ); ?>; background:<?php echo esc_attr( $sc['bg'] ); ?>; padding:2px 10px; border-radius:99px;">
				<?php echo esc_html( Edu_Payment_Manager::status_label( $pay->status ) ); ?>
			</span>
		</td>
		<td style="font-size:12px; color:#64748b;">
			<?php echo $pay->paid_at ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $pay->paid_at ) ) ) : '—'; ?>
			<?php if ( $pay->payment_method ) : ?>
				<br><span style="font-size:10px;"><?php echo esc_html( $pay->payment_method ); ?></span>
			<?php endif; ?>
		</td>
		<td style="display:flex; gap:4px; flex-wrap:wrap;">
			<?php if ( $is_open ) : ?>
			<!-- Registrar manual -->
			<button class="button button-small"
			        onclick="document.getElementById('manual-form-<?php echo (int) $pay->id; ?>').style.display='block'; this.style.display='none'">
				✏️ <?php esc_html_e( 'Manual', 'sistema-educativo' ); ?>
			</button>
			<!-- Generar link -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="edu_generate_payment_link">
				<input type="hidden" name="payment_id" value="<?php echo (int) $pay->id; ?>">
				<?php wp_nonce_field( 'edu_generate_payment_link' ); ?>
				<button type="submit" class="button button-small">🔗 <?php esc_html_e( 'Link', 'sistema-educativo' ); ?></button>
			</form>
			<!-- Exonerar -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="edu_waive_payment">
				<input type="hidden" name="payment_id" value="<?php echo (int) $pay->id; ?>">
				<?php wp_nonce_field( 'edu_waive_payment' ); ?>
				<button type="submit" class="button button-small"
				        onclick="return confirm('<?php echo esc_js( __( '¿Exonerar este pago?', 'sistema-educativo' ) ); ?>')">
					⚪ <?php esc_html_e( 'Exonerar', 'sistema-educativo' ); ?>
				</button>
			</form>
			<?php endif; ?>
		</td>
	</tr>
	<?php if ( $is_open ) : ?>
	<tr id="manual-form-<?php echo (int) $pay->id; ?>" style="display:none; background:#f8fafc;">
		<td colspan="8" style="padding:12px 16px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
				<input type="hidden" name="action" value="edu_register_manual_payment">
				<input type="hidden" name="payment_id" value="<?php echo (int) $pay->id; ?>">
				<?php wp_nonce_field( 'edu_register_manual_payment' ); ?>
				<select name="payment_method">
					<option value="manual"><?php esc_html_e( 'Efectivo / Depósito', 'sistema-educativo' ); ?></option>
					<option value="transfer"><?php esc_html_e( 'Transferencia', 'sistema-educativo' ); ?></option>
					<option value="check"><?php esc_html_e( 'Cheque', 'sistema-educativo' ); ?></option>
					<option value="payphone"><?php esc_html_e( 'Payphone', 'sistema-educativo' ); ?></option>
				</select>
				<input type="text" name="payment_ref" placeholder="<?php echo esc_attr__( 'N° transacción / comprobante', 'sistema-educativo' ); ?>" style="width:220px; padding:4px 8px;">
				<button type="submit" class="button button-primary button-small">
					<?php esc_html_e( 'Confirmar pago', 'sistema-educativo' ); ?>
				</button>
				<button type="button" class="button button-small"
				        onclick="document.getElementById('manual-form-<?php echo (int) $pay->id; ?>').style.display='none'">
					<?php esc_html_e( 'Cancelar', 'sistema-educativo' ); ?>
				</button>
			</form>
		</td>
	</tr>
	<?php endif; ?>
	<?php endforeach; ?>
	</tbody>
</table>
<p style="font-size:12px;color:#6b7280;margin-top:8px;">
	<?php printf( esc_html__( '%d registro(s)', 'sistema-educativo' ), count( $pagos ) ); ?>
</p>
<?php endif; ?>

<?php endif; // end dashboard/config sub ?>

<?php endif; // end institution check ?>
</div>
