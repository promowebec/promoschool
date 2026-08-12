<?php
/**
 * Generador de boletines PDF con mPDF.
 *
 * Uso:
 *   $gen = new Edu_Boletin_Generator();
 *   $gen->stream( $student_id, $period_id );   // envía PDF al navegador
 *   $path = $gen->save( $student_id, $period_id ); // guarda en disco y devuelve ruta
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Boletin_Generator {

	/** Carpeta temporal donde se guardan PDFs para el ZIP bulk. */
	private string $tmp_dir;

	public function __construct() {
		$upload        = wp_upload_dir();
		$this->tmp_dir = trailingslashit( $upload['basedir'] ) . 'edu-privado/boletines/';
		if ( ! is_dir( $this->tmp_dir ) ) {
			wp_mkdir_p( $this->tmp_dir );
			// Proteger directorio.
			file_put_contents( $this->tmp_dir . '.htaccess', "deny from all\n" );
		}
	}

	/**
	 * Envía el PDF directamente al navegador (descarga).
	 */
	public function stream( int $student_id, int $period_id ): void {
		$html     = $this->build_html( $student_id, $period_id );
		$filename = $this->filename( $student_id, $period_id );

		$mpdf = $this->make_mpdf();
		$mpdf->WriteHTML( $html );
		$mpdf->Output( $filename, 'D' );
		exit;
	}

	/**
	 * Guarda el PDF en disco y devuelve la ruta absoluta.
	 */
	public function save( int $student_id, int $period_id ): string {
		$html = $this->build_html( $student_id, $period_id );
		$path = $this->tmp_dir . $this->filename( $student_id, $period_id );

		$mpdf = $this->make_mpdf();
		$mpdf->WriteHTML( $html );
		$mpdf->Output( $path, 'F' );

		return $path;
	}

	// -------------------------------------------------------------------------
	// Privados
	// -------------------------------------------------------------------------

	private function make_mpdf(): \Mpdf\Mpdf {
		$autoload = EDU_PLUGIN_DIR . 'vendor/autoload.php';
		if ( ! file_exists( $autoload ) ) {
			wp_die( esc_html__( 'mPDF no instalado. Ejecuta "composer install" en el directorio del plugin.', 'sistema-educativo' ) );
		}
		require_once $autoload;

		return new \Mpdf\Mpdf( array(
			'mode'          => 'utf-8',
			'format'        => 'A4',
			'margin_top'    => 15,
			'margin_bottom' => 15,
			'margin_left'   => 15,
			'margin_right'  => 15,
			'tempDir'       => sys_get_temp_dir(),
		) );
	}

	private function filename( int $student_id, int $period_id ): string {
		return sprintf( 'boletin_%d_%d.pdf', $student_id, $period_id );
	}

	/**
	 * Construye el HTML completo del boletín.
	 */
	private function build_html( int $student_id, int $period_id ): string {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		// ---- Datos del estudiante ----
		$student = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.id, s.user_id, g.name AS grade_name, g.paralelo, g.level, g.sub_level
			 FROM {$p}students s
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 WHERE s.id = %d",
			$student_id
		) );
		if ( ! $student ) {
			wp_die( esc_html__( 'Estudiante no encontrado.', 'sistema-educativo' ) );
		}

		$grade_sub_level      = (string) $student->sub_level;
		$usa_sumativa_boletin = in_array( $grade_sub_level, array( 'media', 'superior', 'bg', 'bt' ), true );

		$user       = get_userdata( (int) $student->user_id );
		$first_name = $user ? ( $user->first_name ?: '' ) : '';
		$last_name  = $user ? ( $user->last_name  ?: '' ) : '';
		$full_name  = $user ? trim( $first_name . ' ' . $last_name ) : '';
		if ( ! $full_name && $user ) {
			$full_name = $user->display_name;
		}

		// ---- Período e institución ----
		$period = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.*, i.name AS inst_name, i.logo_url, i.address, i.phone, i.ruc
			 FROM {$p}periods p
			 INNER JOIN {$p}institutions i ON i.id = p.institution_id
			 WHERE p.id = %d",
			$period_id
		) );
		if ( ! $period ) {
			wp_die( esc_html__( 'Período no encontrado.', 'sistema-educativo' ) );
		}

		// ---- Trimestres del período ----
		$trimesters = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, number FROM {$p}trimesters WHERE period_id = %d ORDER BY number",
			$period_id
		) );

		// ---- Materias del grado ----
		$subjects = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.name FROM {$p}grade_subjects gs
			 INNER JOIN {$p}subjects s ON s.id = gs.subject_id
			 WHERE gs.grade_id = (SELECT grade_id FROM {$p}students WHERE id = %d)
			 ORDER BY s.name",
			$student_id
		) );

		// ---- Notas por trimestre/materia ----
		// Indexado: [subject_id][trimester_number] = row
		$trim_scores = array();
		if ( $trimesters ) {
			$trim_ids  = array_column( $trimesters, 'id' );
			$trim_ph   = implode( ',', array_fill( 0, count( $trim_ids ), '%d' ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT ts.subject_id, t.number AS trim_num,
				        ts.parcial1_score, ts.parcial2_score,
				        ts.final_exam_score, ts.proyecto_score, ts.computed_score
				 FROM {$p}trimester_scores ts
				 INNER JOIN {$p}trimesters t ON t.id = ts.trimester_id
				 WHERE ts.student_id = %d AND ts.trimester_id IN ($trim_ph)",
				array_merge( array( $student_id ), $trim_ids )
			) );
			foreach ( $rows as $r ) {
				$trim_scores[ (int) $r->subject_id ][ (int) $r->trim_num ] = $r;
			}
		}

		// ---- Notas anuales ----
		$year_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT subject_id, average, status, supletorio_score, remedial_score, gracia_score
			 FROM {$p}year_scores
			 WHERE student_id = %d AND period_id = %d",
			$student_id, $period_id
		) );
		$year_scores = array();
		foreach ( $year_rows as $r ) {
			$year_scores[ (int) $r->subject_id ] = $r;
		}

		// ---- Asistencia del año ----
		$att = $wpdb->get_row( $wpdb->prepare(
			"SELECT
			  COUNT(*) AS total,
			  SUM(status = 'presente') AS presentes,
			  SUM(status = 'atraso') AS atrasos,
			  SUM(status = 'falta_justificada') AS faltas_j,
			  SUM(status = 'falta_injustificada') AS faltas_i
			 FROM {$p}attendance
			 WHERE student_id = %d AND subject_id IS NULL
			   AND date BETWEEN %s AND %s",
			$student_id, $period->start_date, $period->end_date
		) );

		// ---- Promedio general ----
		$promedios = array_filter( array_column( $year_rows, 'average' ) );
		$prom_gral = $promedios ? round( array_sum( $promedios ) / count( $promedios ), 2 ) : null;

		$status_labels = array(
			'en_curso'  => 'En curso',
			'aprobado'  => 'APROBADO',
			'supletorio'=> 'SUPLETORIO',
			'remedial'  => 'REMEDIAL',
			'gracia'    => 'GRACIA',
			'reprobado' => 'REPROBADO',
		);
		$status_colors = array(
			'aprobado'  => '#15803d',
			'supletorio'=> '#b45309',
			'remedial'  => '#b45309',
			'gracia'    => '#9a3412',
			'reprobado' => '#b91c1c',
			'en_curso'  => '#1d4ed8',
		);

		// ---- Determinar estado global ----
		$estado_global = 'en_curso';
		if ( $year_scores ) {
			$estados = array_column( $year_scores, 'status' );
			if ( in_array( 'reprobado', $estados, true ) ) {
				$estado_global = 'reprobado';
			} elseif ( in_array( 'gracia', $estados, true ) ) {
				$estado_global = 'gracia';
			} elseif ( in_array( 'remedial', $estados, true ) ) {
				$estado_global = 'remedial';
			} elseif ( in_array( 'supletorio', $estados, true ) ) {
				$estado_global = 'supletorio';
			} elseif ( count( array_filter( $estados, fn( $s ) => 'aprobado' === $s ) ) === count( $estados ) ) {
				$estado_global = 'aprobado';
			}
		}

		$estado_label = $status_labels[ $estado_global ] ?? $estado_global;
		$estado_color = $status_colors[ $estado_global ] ?? '#374151';

		// ---- Construir HTML ----
		$num_trims = count( $trimesters );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; color: #111; }

  .header { display:table; width:100%; margin-bottom:12px; border-bottom:2px solid #1d4ed8; padding-bottom:10px; }
  .header-logo { display:table-cell; width:70px; vertical-align:middle; }
  .header-logo img { max-width:60px; max-height:60px; }
  .header-info { display:table-cell; vertical-align:middle; padding-left:10px; }
  .header-info .inst-name { font-size:13pt; font-weight:bold; color:#1d4ed8; }
  .header-info .doc-title { font-size:10pt; font-weight:bold; margin-top:3px; }
  .header-right { display:table-cell; text-align:right; vertical-align:middle; font-size:8pt; color:#555; }

  .student-box { background:#f0f6ff; border:1px solid #bfdbfe; border-radius:4px; padding:8px 12px; margin-bottom:12px; }
  .student-box table { width:100%; }
  .student-box td { padding:2px 6px; font-size:9pt; }
  .student-box .label { color:#555; font-size:8pt; }
  .student-box .value { font-weight:bold; }

  h2 { font-size:10pt; color:#1d4ed8; margin:14px 0 6px; border-bottom:1px solid #dbeafe; padding-bottom:3px; }

  .notas-table { width:100%; border-collapse:collapse; font-size:8pt; margin-bottom:10px; }
  .notas-table th { background:#1d4ed8; color:#fff; padding:5px 6px; text-align:center; }
  .notas-table th.left { text-align:left; }
  .notas-table td { padding:4px 6px; border-bottom:1px solid #e5e7eb; }
  .notas-table td.center { text-align:center; }
  .notas-table tr:nth-child(even) td { background:#f9fafb; }
  .notas-table .nota-final { font-weight:bold; }
  .nota-ok  { color:#15803d; font-weight:bold; }
  .nota-sup { color:#b45309; font-weight:bold; }
  .nota-bad { color:#b91c1c; font-weight:bold; }
  .nota-nd  { color:#9ca3af; }

  .resumen-box { display:table; width:100%; margin:12px 0; }
  .resumen-cell { display:table-cell; width:50%; vertical-align:top; padding-right:10px; }
  .resumen-cell:last-child { padding-right:0; }

  .stat-mini { background:#f9fafb; border:1px solid #e5e7eb; border-radius:4px; padding:8px 10px; margin-bottom:8px; }
  .stat-mini .stat-label { font-size:7.5pt; color:#6b7280; text-transform:uppercase; }
  .stat-mini .stat-value { font-size:16pt; font-weight:bold; color:#1d4ed8; }

  .estado-badge { display:inline-block; padding:4px 14px; border-radius:20px; font-size:11pt; font-weight:bold; color:#fff; }

  .footer { margin-top:20px; padding-top:8px; border-top:1px solid #e5e7eb; font-size:7.5pt; color:#9ca3af; text-align:center; }

  .att-table { width:100%; border-collapse:collapse; font-size:8pt; }
  .att-table th { background:#f3f4f6; color:#374151; padding:5px 8px; text-align:center; border:1px solid #e5e7eb; }
  .att-table td { padding:5px 8px; text-align:center; border:1px solid #e5e7eb; }
</style>
</head>
<body>

<!-- CABECERA -->
<div class="header">
  <div class="header-logo">
    <?php if ( ! empty( $period->logo_url ) ) : ?>
      <img src="<?php echo esc_attr( $period->logo_url ); ?>">
    <?php endif; ?>
  </div>
  <div class="header-info">
    <div class="inst-name"><?php echo esc_html( $period->inst_name ); ?></div>
    <div class="doc-title">BOLETÍN DE CALIFICACIONES</div>
    <div style="font-size:8pt; color:#555; margin-top:2px;">Período: <?php echo esc_html( $period->name ); ?></div>
  </div>
  <div class="header-right">
    <?php if ( $period->ruc ) : ?>RUC: <?php echo esc_html( $period->ruc ); ?><br><?php endif; ?>
    <?php if ( $period->address ) : ?><?php echo esc_html( $period->address ); ?><br><?php endif; ?>
    <?php if ( $period->phone ) : ?>Tel: <?php echo esc_html( $period->phone ); ?><?php endif; ?>
  </div>
</div>

<!-- DATOS DEL ESTUDIANTE -->
<div class="student-box">
  <table>
    <tr>
      <td style="width:55%">
        <span class="label">Estudiante:</span><br>
        <span class="value" style="font-size:11pt;"><?php echo esc_html( $full_name ); ?></span>
      </td>
      <td style="width:25%">
        <span class="label">Grado / Paralelo:</span><br>
        <span class="value"><?php echo esc_html( $student->grade_name . ' · ' . $student->paralelo ); ?></span>
      </td>
      <td style="width:20%">
        <span class="label">Nivel:</span><br>
        <span class="value"><?php echo esc_html( ucfirst( $student->level ) ); ?></span>
      </td>
    </tr>
  </table>
</div>

<!-- TABLA DE CALIFICACIONES -->
<h2>Calificaciones por Materia</h2>
<table class="notas-table">
  <thead>
    <tr>
      <th class="left" style="width:20%">Materia</th>
      <?php foreach ( $trimesters as $trim ) : ?>
        <th colspan="<?php echo $usa_sumativa_boletin ? 5 : 4; ?>">Trimestre <?php echo (int) $trim->number; ?></th>
      <?php endforeach; ?>
      <th>Promedio<br>Anual</th>
      <th>Cuali.</th>
      <th>Estado</th>
    </tr>
    <tr>
      <th class="left"></th>
      <?php foreach ( $trimesters as $trim ) : ?>
        <th>P1</th><th>P2</th>
        <?php if ( $usa_sumativa_boletin ) : ?><th>Exam</th><th>Proy</th><?php else : ?><th>Exam</th><?php endif; ?>
        <th>Nota T<?php echo (int) $trim->number; ?></th>
      <?php endforeach; ?>
      <th></th><th></th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ( $subjects as $subj ) :
    $subj_id  = (int) $subj->id;
    $yr       = $year_scores[ $subj_id ] ?? null;
    $avg      = $yr ? (float) $yr->average : null;
    $est_subj = $yr ? ( $status_labels[ $yr->status ] ?? $yr->status ) : '—';
    $est_color = $yr ? ( $status_colors[ $yr->status ] ?? '#374151' ) : '#9ca3af';
  ?>
    <tr>
      <td><?php echo esc_html( $subj->name ); ?></td>
      <?php foreach ( $trimesters as $trim ) :
        $tn     = (int) $trim->number;
        $ts     = $trim_scores[ $subj_id ][ $tn ] ?? null;
        $nota_t = $ts ? (float) $ts->computed_score : null;
        $cual_t = ( null !== $nota_t && $nota_t > 0 ) ? Edu_Qualitativa_Helper::codigo( $nota_t ) : '';
        $cual_color_t = $cual_t ? Edu_Qualitativa_Helper::color( $cual_t ) : '#9ca3af';
      ?>
        <td class="center <?php echo $ts ? '' : 'nota-nd'; ?>">
          <?php echo $ts ? esc_html( number_format( (float) $ts->parcial1_score, 2 ) ) : '—'; ?>
        </td>
        <td class="center <?php echo $ts ? '' : 'nota-nd'; ?>">
          <?php echo $ts ? esc_html( number_format( (float) $ts->parcial2_score, 2 ) ) : '—'; ?>
        </td>
        <td class="center <?php echo $ts ? '' : 'nota-nd'; ?>">
          <?php echo $ts ? esc_html( number_format( (float) $ts->final_exam_score, 2 ) ) : '—'; ?>
        </td>
        <?php if ( $usa_sumativa_boletin ) : ?>
        <td class="center <?php echo $ts ? '' : 'nota-nd'; ?>">
          <?php echo $ts ? esc_html( number_format( (float) $ts->proyecto_score, 2 ) ) : '—'; ?>
        </td>
        <?php endif; ?>
        <td class="center nota-final <?php echo null !== $nota_t ? ( $nota_t >= 7 ? 'nota-ok' : 'nota-bad' ) : 'nota-nd'; ?>">
          <?php echo null !== $nota_t ? esc_html( number_format( $nota_t, 2 ) ) : '—'; ?>
          <?php if ( $cual_t ) : ?>
            <br><span style="font-size:6.5pt; color:<?php echo esc_attr( $cual_color_t ); ?>; font-weight:bold;"><?php echo esc_html( $cual_t ); ?></span>
          <?php endif; ?>
        </td>
      <?php endforeach; ?>
      <td class="center nota-final <?php echo null !== $avg ? ( $avg >= 7 ? 'nota-ok' : ( $avg >= 5 ? 'nota-sup' : 'nota-bad' ) ) : 'nota-nd'; ?>">
        <?php echo null !== $avg ? esc_html( number_format( $avg, 2 ) ) : '—'; ?>
      </td>
      <td class="center">
        <?php if ( null !== $avg && $avg > 0 ) :
          $cual_a = Edu_Qualitativa_Helper::codigo( $avg );
        ?>
          <span style="color:<?php echo esc_attr( Edu_Qualitativa_Helper::color( $cual_a ) ); ?>; font-weight:bold; font-size:8pt;"><?php echo esc_html( $cual_a ); ?></span>
        <?php else : ?>
          <span class="nota-nd">—</span>
        <?php endif; ?>
      </td>
      <td class="center" style="color:<?php echo esc_attr( $est_color ); ?>; font-weight:bold; font-size:7.5pt;">
        <?php echo esc_html( $est_subj ); ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<!-- RESUMEN -->
<div class="resumen-box">
  <div class="resumen-cell">
    <h2>Resumen General</h2>
    <div class="stat-mini">
      <div class="stat-label">Promedio General</div>
      <div class="stat-value" style="color:<?php echo null !== $prom_gral ? ( $prom_gral >= 7 ? '#15803d' : ( $prom_gral >= 5 ? '#b45309' : '#b91c1c' ) ) : '#9ca3af'; ?>;">
        <?php echo null !== $prom_gral ? esc_html( number_format( $prom_gral, 2 ) ) : '—'; ?>
        <?php if ( null !== $prom_gral && $prom_gral > 0 ) :
          $cual_gral = Edu_Qualitativa_Helper::codigo( $prom_gral );
        ?>
          <span style="font-size:10pt; color:<?php echo esc_attr( Edu_Qualitativa_Helper::color( $cual_gral ) ); ?>; margin-left:6px;"><?php echo esc_html( $cual_gral ); ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div style="margin-top:8px;">
      <span class="label" style="font-size:8.5pt;">Estado Final:</span><br>
      <span class="estado-badge" style="background:<?php echo esc_attr( $estado_color ); ?>; margin-top:4px; display:inline-block;">
        <?php echo esc_html( $estado_label ); ?>
      </span>
    </div>
  </div>
  <div class="resumen-cell">
    <h2>Asistencia del Año</h2>
    <table class="att-table">
      <tr>
        <th>Presentes</th>
        <th>Atrasos</th>
        <th>Faltas J.</th>
        <th>Faltas I.</th>
        <th>Total</th>
      </tr>
      <tr>
        <td style="color:#15803d; font-weight:bold;"><?php echo (int) ( $att->presentes ?? 0 ); ?></td>
        <td style="color:#b45309; font-weight:bold;"><?php echo (int) ( $att->atrasos ?? 0 ); ?></td>
        <td style="color:#1d4ed8; font-weight:bold;"><?php echo (int) ( $att->faltas_j ?? 0 ); ?></td>
        <td style="color:#b91c1c; font-weight:bold;"><?php echo (int) ( $att->faltas_i ?? 0 ); ?></td>
        <td><?php echo (int) ( $att->total ?? 0 ); ?></td>
      </tr>
    </table>
    <?php
    $total_att = (int) ( $att->total ?? 0 );
    $pct_att   = $total_att > 0 ? round( ( (int) ( $att->presentes ?? 0 ) + (int) ( $att->atrasos ?? 0 ) ) / $total_att * 100 ) : 100;
    $att_color = $pct_att >= 90 ? '#15803d' : ( $pct_att >= 75 ? '#b45309' : '#b91c1c' );
    ?>
    <div style="margin-top:6px; font-size:8pt;">
      Porcentaje de asistencia: <strong style="color:<?php echo esc_attr( $att_color ); ?>;"><?php echo $pct_att; ?>%</strong>
    </div>
  </div>
</div>

<!-- FIRMAS -->
<table style="width:100%; margin-top:30px; font-size:8pt; color:#555;">
  <tr>
    <td style="text-align:center; width:33%; border-top:1px solid #999; padding-top:6px;">
      Director/Rector
    </td>
    <td style="width:33%;"></td>
    <td style="text-align:center; width:33%; border-top:1px solid #999; padding-top:6px;">
      Docente Tutor
    </td>
  </tr>
</table>

<!-- PIE -->
<div class="footer">
  Generado el <?php echo esc_html( date_i18n( 'd \d\e F \d\e Y, H:i', current_time( 'timestamp' ) ) ); ?>
  · <?php echo esc_html( $period->inst_name ); ?>
</div>

</body>
</html>
		<?php
		return ob_get_clean();
	}
}
