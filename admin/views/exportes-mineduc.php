<?php
/**
 * Vista: Exportes Mineduc (Fase 7D).
 *
 * Rector/admin elige tipo de reporte + período (+ grado si aplica) y descarga
 * un archivo Excel .xlsx compatible con SIME/AMIE.
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
	echo '<div class="wrap"><h1>' . esc_html__( 'Exportes Mineduc', 'sistema-educativo' ) . '</h1>';
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'Seleccione una institución activa.', 'sistema-educativo' ) . '</p></div></div>';
	return;
}

global $wpdb;
$p = $wpdb->prefix . 'edu_';

$periods = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, is_active FROM {$p}periods WHERE institution_id = %d ORDER BY start_date DESC",
	$institution_id
) );

$grades = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, name, paralelo FROM {$p}grades WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
	$institution_id
) );

$reportes = array(
	'acta'         => array(
		'label'        => __( 'Acta consolidada de calificaciones', 'sistema-educativo' ),
		'desc'         => __( 'Una fila por estudiante; por cada materia: T1, T2, T3, promedio anual y estado.', 'sistema-educativo' ),
		'needs_grade'  => true,
	),
	'nomina'       => array(
		'label'        => __( 'Nómina de estudiantes (AMIE)', 'sistema-educativo' ),
		'desc'         => __( 'Cédula, apellidos, nombres, fecha de nacimiento, sexo, dirección y datos del representante. Sexo y dirección se registran en la ficha del estudiante.', 'sistema-educativo' ),
		'needs_grade'  => true,
	),
	'distributivo' => array(
		'label'        => __( 'Distributivo docente', 'sistema-educativo' ),
		'desc'         => __( 'Asignaciones académicas activas del período: docente, materia, grado, paralelo y horas semanales.', 'sistema-educativo' ),
		'needs_grade'  => false,
	),
	'asistencia'   => array(
		'label'        => __( 'Asistencia acumulada', 'sistema-educativo' ),
		'desc'         => __( 'Días asistidos, faltas justificadas/injustificadas, atrasos y % de asistencia sobre los días laborables del período.', 'sistema-educativo' ),
		'needs_grade'  => true,
	),
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Exportes Mineduc', 'sistema-educativo' ); ?></h1>
	<p><?php esc_html_e( 'Genere archivos Excel (.xlsx) en formato compatible con SIME/AMIE. La descarga es directa; no se almacena nada en el servidor.', 'sistema-educativo' ); ?></p>

	<?php if ( empty( $periods ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'No hay períodos lectivos registrados. Cree uno primero.', 'sistema-educativo' ); ?></p></div>
	<?php else : ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="edu-exportes-form">
		<input type="hidden" name="action" value="edu_export_mineduc" />
		<?php wp_nonce_field( 'edu_export_mineduc' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Tipo de reporte', 'sistema-educativo' ); ?></th>
				<td>
					<fieldset>
						<?php $primero = true; foreach ( $reportes as $clave => $rep ) : ?>
							<label style="display:block;margin-bottom:10px;">
								<input type="radio" name="report_type" value="<?php echo esc_attr( $clave ); ?>"
									data-needs-grade="<?php echo $rep['needs_grade'] ? '1' : '0'; ?>"
									<?php checked( $primero ); ?> />
								<strong><?php echo esc_html( $rep['label'] ); ?></strong>
								<br /><span class="description" style="margin-left:24px;"><?php echo esc_html( $rep['desc'] ); ?></span>
							</label>
						<?php $primero = false; endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="edu-exp-period"><?php esc_html_e( 'Período lectivo', 'sistema-educativo' ); ?></label></th>
				<td>
					<select name="period_id" id="edu-exp-period" required>
						<?php foreach ( $periods as $per ) : ?>
							<option value="<?php echo (int) $per->id; ?>" <?php selected( (bool) $per->is_active ); ?>>
								<?php echo esc_html( $per->name . ( $per->is_active ? ' — ' . __( 'activo', 'sistema-educativo' ) : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr id="edu-exp-grade-row">
				<th scope="row"><label for="edu-exp-grade"><?php esc_html_e( 'Grado y paralelo', 'sistema-educativo' ); ?></label></th>
				<td>
					<select name="grade_id" id="edu-exp-grade">
						<option value=""><?php esc_html_e( '— Seleccionar —', 'sistema-educativo' ); ?></option>
						<?php foreach ( $grades as $g ) : ?>
							<option value="<?php echo (int) $g->id; ?>">
								<?php echo esc_html( $g->name . ' "' . $g->paralelo . '"' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span>
				<?php esc_html_e( 'Descargar Excel', 'sistema-educativo' ); ?>
			</button>
		</p>
	</form>

	<script>
	( function () {
		var form     = document.getElementById( 'edu-exportes-form' );
		var gradeRow = document.getElementById( 'edu-exp-grade-row' );
		var gradeSel = document.getElementById( 'edu-exp-grade' );

		function refrescar() {
			var radio = form.querySelector( 'input[name="report_type"]:checked' );
			var necesitaGrado = radio && radio.dataset.needsGrade === '1';
			gradeRow.style.display = necesitaGrado ? '' : 'none';
			gradeSel.required      = necesitaGrado;
		}

		form.querySelectorAll( 'input[name="report_type"]' ).forEach( function ( r ) {
			r.addEventListener( 'change', refrescar );
		} );
		refrescar();
	} )();
	</script>

	<?php endif; ?>
</div>
