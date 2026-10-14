<?php
/**
 * Generador del certificado de promoción (PDF, mPDF).
 *
 * Solo dibuja: recibe los datos ya validados por
 * Edu_Certificate_Service::emitir() y no consulta la base.
 *
 * Uso:
 *   $d = Edu_Certificate_Service::emitir( $student_id, $period_id );
 *   ( new Edu_Certificado_Generator() )->stream( $d );
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Certificado_Generator {

	/** Carpeta temporal para el ZIP del grado. */
	private string $tmp_dir;

	public function __construct() {
		$this->tmp_dir = Edu_File_Service::ensure_protected_dir() . 'certificados/tmp/';
		if ( ! is_dir( $this->tmp_dir ) ) {
			wp_mkdir_p( $this->tmp_dir );
		}
	}

	/** Envía el PDF al navegador y termina. */
	public function stream( array $d ): void {
		$mpdf = $this->make_mpdf( ! empty( $d['muestra'] ) );
		$mpdf->WriteHTML( $this->html( $d ) );
		$mpdf->Output( $this->filename( $d ), 'D' );
		exit;
	}

	/** Guarda el PDF en la carpeta temporal privada y devuelve la ruta. */
	public function save( array $d ): string {
		$ruta = $this->tmp_dir . $this->filename( $d );

		$mpdf = $this->make_mpdf( ! empty( $d['muestra'] ) );
		$mpdf->WriteHTML( $this->html( $d ) );
		$mpdf->Output( $ruta, 'F' );

		return $ruta;
	}

	private function make_mpdf( bool $muestra = false ): \Mpdf\Mpdf {
		require_once EDU_PLUGIN_DIR . 'vendor/autoload.php';

		$mpdf = new \Mpdf\Mpdf( array(
			'mode'          => 'utf-8',
			'format'        => 'A4',
			'margin_top'    => 18,
			'margin_bottom' => 18,
			'margin_left'   => 20,
			'margin_right'  => 20,
			'tempDir'       => sys_get_temp_dir(),
		) );

		// La vista previa de la plantilla no debe poder pasar por un certificado.
		if ( $muestra ) {
			$mpdf->SetWatermarkText( 'MUESTRA', 0.12 );
			$mpdf->showWatermarkText = true;
		}

		return $mpdf;
	}

	private function filename( array $d ): string {
		if ( ! empty( $d['muestra'] ) ) {
			return 'certificado_muestra.pdf';
		}
		return sprintf( 'certificado_promocion_%d_%d.pdf', (int) $d['student_id'], (int) $d['period_id'] );
	}

	/**
	 * Texto de la plantilla con sus marcadores reemplazados. El texto se
	 * escapa entero; los datos del estudiante van en negrita.
	 */
	private function texto( string $plantilla, array $vars ): string {
		$html = esc_html( $plantilla );
		foreach ( $vars as $k => $v ) {
			$valor = esc_html( (string) $v );
			if ( in_array( $k, array( 'estudiante', 'grado', 'grado_siguiente' ), true ) ) {
				$valor = '<strong>' . $valor . '</strong>';
			}
			$html = str_replace( '{' . $k . '}', $valor, $html );
		}

		return nl2br( $html );
	}

	/** Etiqueta según el sexo registrado; sin dato, forma doble. */
	private function genero( array $d, string $masculino, string $femenino, string $doble ): string {
		if ( 'F' === $d['sexo'] ) {
			return $femenino;
		}
		return 'M' === $d['sexo'] ? $masculino : $doble;
	}

	/**
	 * Etiqueta <img> para una imagen local. mPDF la lee del disco: la firma
	 * nunca se expone por URL.
	 */
	private function img( string $ruta, string $estilo ): string {
		return $ruta && file_exists( $ruta )
			? '<img src="' . esc_attr( $ruta ) . '" style="' . esc_attr( $estilo ) . '">'
			: '';
	}

	private function html( array $d ): string {
		$regimen = 'costa' === $d['regimen'] ? 'Costa-Galápagos' : 'Sierra-Amazonía';
		$pl      = $d['plantilla'] ?? Edu_Certificate_Service::plantilla_defecto();
		$color   = $pl['color'] ?: '#1e3a8a';
		$vars    = array(
			'institucion'     => $d['institucion'],
			'el_estudiante'   => $this->genero( $d, 'el estudiante', 'la estudiante', 'el/la estudiante' ),
			'estudiante'      => $d['estudiante'],
			'grado'           => $d['grado'],
			'paralelo'        => $d['paralelo'],
			'periodo'         => $d['periodo'],
			'promovido'       => $this->genero( $d, 'promovido', 'promovida', 'promovido/a' ),
			'grado_siguiente' => $d['grado_siguiente'],
			'ciudad'          => $d['ciudad'],
			'fecha'           => $d['fecha'],
		);
		$letras = ! empty( $pl['mostrar_letras'] );
		$cuali  = ! empty( $pl['mostrar_cualitativa'] );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #111; }
  .cab { width:100%; border-bottom:2px solid <?php echo esc_html( $color ); ?>; padding-bottom:8px; }
  .cab td { vertical-align:middle; }
  .inst { font-size:14pt; font-weight:bold; color:<?php echo esc_html( $color ); ?>; }
  .sub { font-size:8.5pt; color:#444; }
  h1 { text-align:center; font-size:17pt; letter-spacing:2px; color:<?php echo esc_html( $color ); ?>; margin:22px 0 4px; }
  .anio { text-align:center; font-size:10pt; color:#444; margin-bottom:18px; }
  p.cuerpo { text-align:justify; line-height:1.6; margin:0 0 12px; }
  table.notas { width:100%; border-collapse:collapse; font-size:9pt; margin:6px 0 14px; }
  table.notas th { background:<?php echo esc_html( $color ); ?>; color:#fff; padding:6px; border:1px solid <?php echo esc_html( $color ); ?>; }
  table.notas td { padding:5px 6px; border:1px solid #9ca3af; }
  table.notas td.c { text-align:center; }
  table.notas tr.total td { font-weight:bold; background:#eef2ff; }
  .firmas { width:100%; margin-top:40px; }
  .firmas td { width:33%; text-align:center; vertical-align:bottom; font-size:9pt; }
  .linea { border-top:1px solid #111; padding-top:4px; margin:0 12px; }
  .cargo { font-weight:bold; }
  .pie { margin-top:26px; font-size:7pt; color:#9ca3af; text-align:center; }
</style>
</head>
<body>

<table class="cab">
  <tr>
    <td style="width:70px;">
      <?php if ( $d['logo_url'] ) : ?><img src="<?php echo esc_attr( $d['logo_url'] ); ?>" style="max-width:62px; max-height:62px;"><?php endif; ?>
    </td>
    <td>
      <div class="inst"><?php echo esc_html( mb_strtoupper( $d['institucion'] ) ); ?></div>
      <div class="sub">
        <?php if ( $d['amie'] ) : ?>Código AMIE: <?php echo esc_html( $d['amie'] ); ?> · <?php endif; ?>
        Régimen <?php echo esc_html( $regimen ); ?>
      </div>
    </td>
  </tr>
</table>

<h1><?php echo esc_html( $pl['titulo'] ); ?></h1>
<div class="anio">Año lectivo <?php echo esc_html( $d['periodo'] ); ?></div>

<p class="cuerpo"><?php echo $this->texto( $pl['introduccion'], $vars ); // phpcs:ignore WordPress.Security.EscapeOutput -- texto() escapa. ?></p>

<table class="notas">
  <thead>
    <tr>
      <th style="text-align:left;">Asignatura</th>
      <th style="width:13%;">Número</th>
      <?php if ( $letras ) : ?><th style="width:32%;">Letras</th><?php endif; ?>
      <?php if ( $cuali ) : ?><th style="width:11%;">Cualitativa</th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ( $d['materias'] as $m ) : ?>
    <tr>
      <td>
        <?php echo esc_html( $m['materia'] ); ?>
        <?php if ( $m['observacion'] ) : ?><br><span style="font-size:7.5pt; color:#555;"><?php echo esc_html( $m['observacion'] ); ?></span><?php endif; ?>
      </td>
      <td class="c"><?php echo esc_html( number_format( $m['nota'], 2 ) ); ?></td>
      <?php if ( $letras ) : ?><td class="c"><?php echo esc_html( $m['letras'] ); ?></td><?php endif; ?>
      <?php if ( $cuali ) : ?><td class="c"><?php echo esc_html( $m['cualitativa'] ); ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
    <tr class="total">
      <td>PROMEDIO GENERAL</td>
      <td class="c"><?php echo esc_html( number_format( $d['promedio'], 2 ) ); ?></td>
      <?php if ( $letras ) : ?><td class="c"><?php echo esc_html( $d['promedio_letras'] ); ?></td><?php endif; ?>
      <?php if ( $cuali ) : ?><td class="c"><?php echo esc_html( $d['promedio_cualitativa'] ); ?></td><?php endif; ?>
    </tr>
  </tbody>
</table>

<p class="cuerpo"><?php echo $this->texto( $pl['promocion'], $vars ); // phpcs:ignore WordPress.Security.EscapeOutput -- texto() escapa. ?></p>
<p class="cuerpo"><?php echo $this->texto( $pl['cierre'], $vars ); // phpcs:ignore WordPress.Security.EscapeOutput -- texto() escapa. ?></p>
<p class="cuerpo" style="text-align:right;">
  <?php echo esc_html( ( $d['ciudad'] ? $d['ciudad'] . ', ' : '' ) . $d['fecha'] ); ?>
</p>

<table class="firmas">
  <tr>
    <td><?php echo $this->img( $d['firma_rector'], 'max-height:60px; max-width:170px;' ); // phpcs:ignore WordPress.Security.EscapeOutput -- img() escapa. ?></td>
    <td rowspan="2" style="vertical-align:middle;"><?php echo $this->img( $d['sello'], 'max-height:95px; max-width:95px;' ); // phpcs:ignore WordPress.Security.EscapeOutput -- img() escapa. ?></td>
    <td><?php echo $this->img( $d['firma_secretario'], 'max-height:60px; max-width:170px;' ); // phpcs:ignore WordPress.Security.EscapeOutput -- img() escapa. ?></td>
  </tr>
  <tr>
    <td>
      <div class="linea">
        <?php echo esc_html( $d['rector'] ?: ' ' ); ?><br>
        <span class="cargo"><?php echo esc_html( $pl['cargo_rector'] ); ?></span>
      </div>
    </td>
    <td>
      <div class="linea">
        <?php echo esc_html( $d['secretario'] ?: ' ' ); ?><br>
        <span class="cargo"><?php echo esc_html( $pl['cargo_secretario'] ); ?></span>
      </div>
    </td>
  </tr>
</table>

<div class="pie">Generado por PromoSchool el <?php echo esc_html( $d['fecha'] ); ?></div>

</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
