<?php
/**
 * Vista: Boletines PDF.
 *
 * Rector/admin selecciona período + grado → lista de estudiantes con enlace
 * de descarga individual y botón de descarga masiva (ZIP).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( Edu_Context::can( 'edu_generate_reports' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
	wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
}

$institution_id = Edu_Context::current_institution_id();
if ( ! $institution_id ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Boletines', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución activa.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

global $wpdb;
$p = $wpdb->prefix . 'edu_';

// ---- Períodos de la institución ----
$periods = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, is_active FROM {$p}periods WHERE institution_id = %d ORDER BY start_date DESC",
	$institution_id
) );

// ---- Grados de la institución ----
$grades = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, paralelo FROM {$p}grades WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
	$institution_id
) );

$period_sel = isset( $_GET['period_id'] ) ? (int) $_GET['period_id'] : 0;
$grade_sel  = isset( $_GET['grade_id'] )  ? (int) $_GET['grade_id']  : 0;

// Auto-seleccionar período activo.
if ( ! $period_sel ) {
	foreach ( $periods as $per ) {
		if ( $per->is_active ) {
			$period_sel = (int) $per->id;
			break;
		}
	}
}

// ---- Estudiantes del grado con estado anual ----
$students = array();
if ( $grade_sel && $period_sel ) {
	$students = $wpdb->get_results( $wpdb->prepare(
		"SELECT s.id,
		        COALESCE(um_fn.meta_value, '') AS first_name,
		        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
		        (SELECT COUNT(DISTINCT ys.subject_id)
		           FROM {$p}year_scores ys
		           WHERE ys.student_id = s.id AND ys.period_id = %d) AS materias_con_nota,
		        (SELECT COUNT(DISTINCT ys2.subject_id)
		           FROM {$p}year_scores ys2
		           WHERE ys2.student_id = s.id AND ys2.period_id = %d AND ys2.status = 'aprobado') AS materias_aprobadas,
		        (SELECT ys3.status
		           FROM {$p}year_scores ys3
		           WHERE ys3.student_id = s.id AND ys3.period_id = %d
		           ORDER BY FIELD(ys3.status,'reprobado','gracia','remedial','supletorio','en_curso','aprobado') ASC
		           LIMIT 1) AS estado_global
		 FROM {$p}students s
		 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
		 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
		 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
		 WHERE s.grade_id = %d
		 ORDER BY last_name, first_name",
		$period_sel, $period_sel, $period_sel, $grade_sel
	) );
}

$nonce       = wp_create_nonce( 'edu_download_boletin' );
$base_dl     = admin_url( 'admin-post.php' );

$status_labels = array(
	'en_curso'   => 'En curso',
	'aprobado'   => 'Aprobado',
	'supletorio' => 'Supletorio',
	'remedial'   => 'Remedial',
	'gracia'     => 'Gracia',
	'reprobado'  => 'Reprobado',
);
$status_colors = array(
	'aprobado'   => '#dcfce7|#15803d',
	'supletorio' => '#fef9c3|#854d0e',
	'remedial'   => '#ffedd5|#9a3412',
	'gracia'     => '#fee2e2|#9a3412',
	'reprobado'  => '#fee2e2|#b91c1c',
	'en_curso'   => '#dbeafe|#1e40af',
);

// Verificar si mPDF está instalado.
$mpdf_ok = file_exists( EDU_PLUGIN_DIR . 'vendor/autoload.php' );
?>
<div class="wrap edu-wrap">
	<h1><?php esc_html_e( 'Boletines de Calificaciones', 'sistema-educativo' ); ?></h1>
	<?php require EDU_PLUGIN_DIR . 'admin/views/_institution-switcher.php'; ?>

	<?php if ( ! $mpdf_ok ) : ?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'mPDF no instalado.', 'sistema-educativo' ); ?></strong>
			<?php esc_html_e( 'Ejecuta el siguiente comando en la carpeta del plugin:', 'sistema-educativo' ); ?>
			<code style="display:block; margin-top:6px; padding:6px 10px; background:#f6f7f7; border:1px solid #ddd;">
				composer install
			</code>
		</p>
	</div>
	<?php endif; ?>

	<!-- Filtros -->
	<div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end; margin-bottom:20px; background:#fff; padding:16px; border:1px solid #ddd; border-radius:4px;">
		<label>
			<strong><?php esc_html_e( 'Período:', 'sistema-educativo' ); ?></strong><br>
			<select id="edu-bol-period" onchange="eduReloadBol()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $periods as $per ) : ?>
					<option value="<?php echo (int) $per->id; ?>" <?php selected( $period_sel, (int) $per->id ); ?>>
						<?php echo esc_html( $per->name . ( $per->is_active ? ' ★' : '' ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label>
			<strong><?php esc_html_e( 'Grado:', 'sistema-educativo' ); ?></strong><br>
			<select id="edu-bol-grade" onchange="eduReloadBol()">
				<option value="0"><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
				<?php foreach ( $grades as $g ) : ?>
					<option value="<?php echo (int) $g->id; ?>" <?php selected( $grade_sel, (int) $g->id ); ?>>
						<?php echo esc_html( $g->name . ' ' . $g->paralelo ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
	</div>

	<?php if ( ! $period_sel || ! $grade_sel ) : ?>
		<p class="description"><?php esc_html_e( 'Selecciona un período y grado para ver los estudiantes.', 'sistema-educativo' ); ?></p>

	<?php elseif ( empty( $students ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'No hay estudiantes en este grado.', 'sistema-educativo' ); ?></p></div>

	<?php else : ?>

	<!-- Descarga masiva -->
	<?php if ( $mpdf_ok ) : ?>
	<div style="margin-bottom:16px;">
		<a href="<?php echo esc_url( add_query_arg( array(
			'action'    => 'edu_download_boletines_zip',
			'grade_id'  => $grade_sel,
			'period_id' => $period_sel,
			'_wpnonce'  => $nonce,
		), $base_dl ) ); ?>"
		   class="button button-primary">
			&#11015; <?php esc_html_e( 'Descargar todos (ZIP)', 'sistema-educativo' ); ?>
		</a>
		<span style="margin-left:10px; color:#666; font-size:13px;">
			<?php printf( esc_html__( '%d estudiante(s)', 'sistema-educativo' ), count( $students ) ); ?>
		</span>
	</div>
	<?php endif; ?>

	<!-- Lista de estudiantes -->
	<table class="widefat striped" style="max-width:700px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Estudiante', 'sistema-educativo' ); ?></th>
				<th style="text-align:center;"><?php esc_html_e( 'Materias con nota', 'sistema-educativo' ); ?></th>
				<th style="text-align:center;"><?php esc_html_e( 'Estado global', 'sistema-educativo' ); ?></th>
				<th style="text-align:center;"><?php esc_html_e( 'Boletín PDF', 'sistema-educativo' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $students as $st ) :
			$estado    = $st->estado_global ?: 'en_curso';
			$colors    = explode( '|', $status_colors[ $estado ] ?? '#dbeafe|#1e40af' );
			$bg_color  = $colors[0];
			$tx_color  = $colors[1] ?? '#111';
			$label     = $status_labels[ $estado ] ?? $estado;
			$dl_url    = add_query_arg( array(
				'action'     => 'edu_download_boletin',
				'student_id' => (int) $st->id,
				'period_id'  => $period_sel,
				'_wpnonce'   => $nonce,
			), $base_dl );
		?>
			<tr>
				<td><strong><?php echo esc_html( $st->last_name . ', ' . $st->first_name ); ?></strong></td>
				<td style="text-align:center;">
					<?php echo (int) $st->materias_aprobadas; ?> / <?php echo (int) $st->materias_con_nota; ?>
				</td>
				<td style="text-align:center;">
					<span style="background:<?php echo esc_attr( $bg_color ); ?>; color:<?php echo esc_attr( $tx_color ); ?>; padding:2px 10px; border-radius:99px; font-size:11px; font-weight:600;">
						<?php echo esc_html( $label ); ?>
					</span>
				</td>
				<td style="text-align:center;">
					<?php if ( $mpdf_ok ) : ?>
					<a href="<?php echo esc_url( $dl_url ); ?>" class="button button-small" target="_blank">
						&#128196; PDF
					</a>
					<?php else : ?>
					<span style="color:#999; font-size:12px;"><?php esc_html_e( 'mPDF requerido', 'sistema-educativo' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php endif; ?>
</div>

<script>
function eduReloadBol() {
	var url = new URL( window.location.href );
	url.searchParams.set( 'page',      'edu-boletines' );
	url.searchParams.set( 'period_id', document.getElementById('edu-bol-period').value );
	url.searchParams.set( 'grade_id',  document.getElementById('edu-bol-grade').value );
	window.location.href = url.toString();
}
</script>
