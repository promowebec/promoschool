<?php
/**
 * Vista: comunicados con acuse de recibo.
 *
 * action=list → tabla de comunicados enviados con estado de lectura.
 * action=new  → formulario de redacción y envío.
 * action=detail → destinatarios y estado de lectura de un comunicado.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( Edu_Context::can( 'edu_grade_students' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

$institution_id = Edu_Context::current_institution_id();
if ( ! $institution_id ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Comunicados', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$code   = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : '';

global $wpdb;
$ta  = $wpdb->prefix . 'edu_announcements';
$tr  = $wpdb->prefix . 'edu_announcement_recipients';
$tg  = $wpdb->prefix . 'edu_grades';
$tst = $wpdb->prefix . 'edu_students';

// -------------------------------------------------------------------------
// DETALLE: lista de destinatarios y estado de lectura
// -------------------------------------------------------------------------
if ( 'detail' === $action && $id ) {
	$ann = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id = %d", $id ) );
	if ( ! $ann ) {
		echo '<div class="wrap"><p>' . esc_html__( 'Comunicado no encontrado.', 'sistema-educativo' ) . '</p></div>';
		return;
	}

	$recipients = $wpdb->get_results( $wpdb->prepare(
		"SELECT r.*, u.display_name, u.user_email
		 FROM $tr r
		 INNER JOIN {$wpdb->users} u ON u.ID = r.user_id
		 WHERE r.announcement_id = %d AND r.channel = 'portal'
		 ORDER BY r.read_at IS NULL DESC, u.display_name",
		$id
	) );

	$total  = count( $recipients );
	$leidos = count( array_filter( $recipients, fn( $r ) => ! empty( $r->read_at ) ) );
	?>
	<div class="wrap edu-wrap">
		<h1>
			<?php esc_html_e( 'Detalle del comunicado', 'sistema-educativo' ); ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-comunicados' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( '← Volver', 'sistema-educativo' ); ?>
			</a>
		</h1>

		<h2><?php echo esc_html( $ann->title ); ?></h2>
		<div style="background:#f9f9f9;border:1px solid #ddd;padding:12px 16px;margin-bottom:16px;max-width:720px;">
			<?php echo wp_kses_post( $ann->body ); ?>
		</div>
		<p>
			<strong><?php esc_html_e( 'Enviado:', 'sistema-educativo' ); ?></strong>
			<?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $ann->sent_at ) ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php esc_html_e( 'Acuses:', 'sistema-educativo' ); ?></strong>
			<span style="color:<?php echo $leidos === $total ? 'green' : '#996600'; ?>">
				<?php echo (int) $leidos; ?>/<?php echo (int) $total; ?>
			</span>
		</p>

		<?php if ( $recipients ) : ?>
		<table class="widefat striped" style="max-width:720px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Destinatario', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Email', 'sistema-educativo' ); ?></th>
					<th><?php esc_html_e( 'Acuse de recibo', 'sistema-educativo' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $recipients as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r->display_name ); ?></td>
					<td><?php echo esc_html( $r->user_email ); ?></td>
					<td>
						<?php if ( $r->read_at ) : ?>
							<span style="color:green;">✓ <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $r->read_at ) ) ); ?></span>
						<?php else : ?>
							<span style="color:#999;"><?php esc_html_e( 'Pendiente', 'sistema-educativo' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<?php
	return;
}

// -------------------------------------------------------------------------
// FORMULARIO: nuevo comunicado
// -------------------------------------------------------------------------
if ( 'new' === $action ) {
	// Scopes permitidos según rol.
	$can_institution = Edu_Context::can( 'edu_view_all' ); // solo rector/superadmin
	$allowed_scopes  = $can_institution
		? array( 'grade', 'student', 'institution' )
		: array( 'grade', 'student' );

	$grade_sel  = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;
	$scope_sel  = isset( $_GET['scope'] ) ? sanitize_key( $_GET['scope'] ) : 'grade';
	if ( ! in_array( $scope_sel, $allowed_scopes, true ) ) {
		$scope_sel = 'grade';
	}

	$grades = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, paralelo FROM $tg WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
		$institution_id
	) );

	$students = array();
	if ( $grade_sel ) {
		$students = $wpdb->get_results( $wpdb->prepare(
			"SELECT st.id, u.display_name FROM $tst st
			 INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
			 WHERE st.grade_id = %d AND st.status = 'active'
			 ORDER BY u.display_name",
			$grade_sel
		) );
	}

	$scope_labels_form = array(
		'grade'       => __( 'Todo un grado', 'sistema-educativo' ),
		'student'     => __( 'Estudiante específico', 'sistema-educativo' ),
		'institution' => __( 'Toda la institución', 'sistema-educativo' ),
	);
	?>
	<div class="wrap edu-wrap">
		<h1>
			<?php esc_html_e( 'Nuevo comunicado', 'sistema-educativo' ); ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-comunicados' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( '← Volver', 'sistema-educativo' ); ?>
			</a>
		</h1>
		<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

		<?php if ( 'error' === $status ) : ?>
			<div class="notice notice-error"><p>
			<?php
			$msgs = array(
				'title_required' => __( 'El asunto es obligatorio.', 'sistema-educativo' ),
				'invalid_scope'  => __( 'El grado no pertenece a la institución actual.', 'sistema-educativo' ),
				'scope_not_allowed' => __( 'No tienes permiso para ese alcance.', 'sistema-educativo' ),
			);
			echo esc_html( $msgs[ $code ] ?? __( 'Error.', 'sistema-educativo' ) );
			?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px;">
			<?php wp_nonce_field( 'edu_send_announcement' ); ?>
			<input type="hidden" name="action" value="edu_send_announcement">

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Destinatarios', 'sistema-educativo' ); ?></th>
					<td>
						<select name="scope" id="edu-scope-sel">
							<?php foreach ( $allowed_scopes as $sc ) : ?>
								<option value="<?php echo esc_attr( $sc ); ?>" <?php selected( $scope_sel, $sc ); ?>>
									<?php echo esc_html( $scope_labels_form[ $sc ] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr id="edu-row-grade">
					<th><?php esc_html_e( 'Grado', 'sistema-educativo' ); ?></th>
					<td>
						<select name="target_grade_id" id="edu-grade-com">
							<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
							<?php foreach ( $grades as $g ) : ?>
								<option value="<?php echo (int) $g->id; ?>" <?php selected( $grade_sel, (int) $g->id ); ?>>
									<?php echo esc_html( $g->name . ' · ' . $g->paralelo ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr id="edu-row-student">
					<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
					<td>
						<?php if ( $grade_sel && $students ) : ?>
							<select name="target_student_id">
								<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
								<?php foreach ( $students as $st ) : ?>
									<option value="<?php echo (int) $st->id; ?>"><?php echo esc_html( $st->display_name ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php elseif ( $grade_sel && empty( $students ) ) : ?>
							<p class="description"><?php esc_html_e( 'El grado no tiene estudiantes activos.', 'sistema-educativo' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Primero selecciona un grado arriba.', 'sistema-educativo' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Asunto', 'sistema-educativo' ); ?></th>
					<td>
						<input type="text" name="title" class="regular-text" required>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Mensaje', 'sistema-educativo' ); ?></th>
					<td>
						<textarea name="body" rows="8" class="large-text"></textarea>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Enviar también por email', 'sistema-educativo' ); ?></th>
					<td>
						<input type="checkbox" name="send_email" value="1" checked>
						<p class="description"><?php esc_html_e( 'Siempre aparece en el portal. El email es adicional.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
				<?php if ( Edu_Modules::is_active( 'whatsapp' ) && 'disabled' !== (string) get_option( 'edu_wa_provider', 'disabled' ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Enviar por WhatsApp', 'sistema-educativo' ); ?></th>
					<td>
						<input type="checkbox" name="send_whatsapp" value="1">
						<p class="description"><?php esc_html_e( 'Envía un mensaje de WhatsApp a los representantes con número registrado.', 'sistema-educativo' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Enviar comunicado', 'sistema-educativo' ); ?>
				</button>
			</p>
		</form>
	</div>
	<script>
	(function(){
		var scopeSel = document.getElementById('edu-scope-sel');
		var gradeSel = document.getElementById('edu-grade-com');

		function toggleRows(scope) {
			var showGrade   = (scope === 'grade' || scope === 'student');
			var showStudent = (scope === 'student');
			document.getElementById('edu-row-grade').style.display   = showGrade   ? '' : 'none';
			document.getElementById('edu-row-student').style.display = showStudent ? '' : 'none';
		}

		// Al cambiar scope: ocultar/mostrar filas y limpiar grade si se va a institución.
		scopeSel.addEventListener('change', function(){
			toggleRows(this.value);
		});

		// Al cambiar grado: recargar página manteniendo el scope seleccionado.
		gradeSel.addEventListener('change', function(){
			var url = new URL(window.location.href);
			url.searchParams.set('grade_id', this.value);
			url.searchParams.set('scope', scopeSel.value);
			url.searchParams.set('action', 'new');
			window.location.href = url.toString();
		});

		// Inicializar estado al cargar.
		toggleRows(scopeSel.value);
	})();
	</script>
	<?php
	return;
}

// -------------------------------------------------------------------------
// LISTA
// -------------------------------------------------------------------------
$announcements = $wpdb->get_results( $wpdb->prepare(
	"SELECT a.*,
	        u.display_name AS sender_name,
	        g.name AS grade_name, g.paralelo,
	        ( SELECT COUNT(*) FROM $tr r WHERE r.announcement_id = a.id AND r.channel = 'portal' ) AS total_recipients,
	        ( SELECT COUNT(*) FROM $tr r WHERE r.announcement_id = a.id AND r.channel = 'portal' AND r.read_at IS NOT NULL ) AS total_read
	 FROM $ta a
	 INNER JOIN {$wpdb->users} u ON u.ID = a.sender_user_id
	 LEFT JOIN $tg g ON g.id = a.target_grade_id
	 WHERE a.sender_user_id IN (
	   SELECT ID FROM {$wpdb->users} u2
	   INNER JOIN {$wpdb->usermeta} um ON um.user_id = u2.ID AND um.meta_key = 'edu_institution_id' AND um.meta_value = %d
	   UNION
	   SELECT ID FROM {$wpdb->users} WHERE ID IN ( SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'edu_current_institution_id' AND meta_value = %d )
	 )
	 ORDER BY a.sent_at DESC
	 LIMIT 100",
	$institution_id, $institution_id
) );

$scope_labels = array(
	'student'     => __( 'Estudiante', 'sistema-educativo' ),
	'grade'       => __( 'Grado', 'sistema-educativo' ),
	'multi_grade' => __( 'Varios grados', 'sistema-educativo' ),
	'institution' => __( 'Institución', 'sistema-educativo' ),
);
?>
<div class="wrap edu-wrap">
	<h1>
		<?php esc_html_e( 'Comunicados', 'sistema-educativo' ); ?>
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-comunicados', 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
			<?php esc_html_e( '+ Nuevo comunicado', 'sistema-educativo' ); ?>
		</a>
	</h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( 'sent' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Comunicado enviado correctamente.', 'sistema-educativo' ); ?></p></div>
	<?php elseif ( 'deleted' === $status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Comunicado eliminado.', 'sistema-educativo' ); ?></p></div>
	<?php endif; ?>

	<?php if ( empty( $announcements ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'No hay comunicados enviados.', 'sistema-educativo' ); ?></p></div>
	<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Asunto', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Alcance', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Enviado por', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Fecha', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Acuses', 'sistema-educativo' ); ?></th>
				<th><?php esc_html_e( 'Acciones', 'sistema-educativo' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $announcements as $a ) :
			$pct_color = ( (int) $a->total_recipients > 0 && (int) $a->total_read === (int) $a->total_recipients ) ? 'green' : '#996600';
		?>
			<tr>
				<td><strong><?php echo esc_html( $a->title ); ?></strong></td>
				<td>
					<?php echo esc_html( $scope_labels[ $a->scope ] ?? $a->scope ); ?>
					<?php if ( $a->grade_name ) : ?>
						<br><small><?php echo esc_html( $a->grade_name . ' ' . $a->paralelo ); ?></small>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $a->sender_name ); ?></td>
				<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $a->sent_at ) ) ); ?></td>
				<td>
					<span style="color:<?php echo esc_attr( $pct_color ); ?>; font-weight:600;">
						<?php echo (int) $a->total_read; ?>/<?php echo (int) $a->total_recipients; ?>
					</span>
				</td>
				<td style="white-space:nowrap;">
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'edu-comunicados', 'action' => 'detail', 'id' => $a->id ), admin_url( 'admin.php' ) ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Ver acuses', 'sistema-educativo' ); ?>
					</a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-left:4px;">
						<?php wp_nonce_field( 'edu_delete_announcement' ); ?>
						<input type="hidden" name="action" value="edu_delete_announcement">
						<input type="hidden" name="id" value="<?php echo (int) $a->id; ?>">
						<button type="submit" class="button button-small" style="color:#b32d2e;"
							onclick="return confirm('<?php esc_attr_e( '¿Eliminar este comunicado?', 'sistema-educativo' ); ?>')">
							<?php esc_html_e( 'Eliminar', 'sistema-educativo' ); ?>
						</button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
