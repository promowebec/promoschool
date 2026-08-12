<?php
/**
 * Helper: equivalencia cualitativa de notas.
 *
 * Según Instructivo de Evaluación Estudiantil 2025 (MinEduc Ecuador),
 * Tablas 7 y 11: el sistema redondea el promedio decimal al entero más
 * cercano y aplica la escala A+…E-.
 *
 * La escala de letras es idéntica para Elemental, Media, Superior y
 * Bachillerato. Las etiquetas RGLOEI difieren por subnivel:
 *   - elemental      → "Destreza o aprendizaje alcanzado / en proceso / iniciado"
 *   - media/superior/bg/bt → "Alcanza / Está próximo / No alcanza"
 *
 * Inicial y Preparatoria usan evaluación cualitativa directa (sin nota
 * numérica), por lo tanto este helper no aplica para esos subniveles.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Qualitativa_Helper {

	/**
	 * Mapa nota entera → código cualitativo.
	 * Clave: entero 1–10. Valor: código A+, A-, B+, B-, C+, C-, D+, D-, E+, E-.
	 */
	private static $mapa = array(
		10 => 'A+',
		9  => 'A-',
		8  => 'B+',
		7  => 'B-',
		6  => 'C+',
		5  => 'C-',
		4  => 'D+',
		3  => 'D-',
		2  => 'E+',
		1  => 'E-',
	);

	/**
	 * Descripción breve del grupo RGLOEI por subnivel y código.
	 * Clave externa: 'elemental' | 'media_bach' (cubre media, superior, bg, bt).
	 */
	private static $etiquetas_rgloei = array(
		'elemental' => array(
			'A+' => 'Destreza o aprendizaje alcanzado',
			'A-' => 'Destreza o aprendizaje alcanzado',
			'B+' => 'Destreza o aprendizaje en proceso de desarrollo',
			'B-' => 'Destreza o aprendizaje en proceso de desarrollo',
			'C+' => 'Destreza o aprendizaje en proceso de desarrollo',
			'C-' => 'Destreza o aprendizaje en proceso de desarrollo',
			'D+' => 'Destreza o aprendizaje iniciado',
			'D-' => 'Destreza o aprendizaje iniciado',
			'E+' => 'Destreza o aprendizaje iniciado',
			'E-' => 'Destreza o aprendizaje iniciado',
		),
		'media_bach' => array(
			'A+' => 'Domina los aprendizajes requeridos',
			'A-' => 'Domina los aprendizajes requeridos',
			'B+' => 'Alcanza los aprendizajes requeridos',
			'B-' => 'Alcanza los aprendizajes requeridos',
			'C+' => 'Está próximo a alcanzar los aprendizajes',
			'C-' => 'Está próximo a alcanzar los aprendizajes',
			'D+' => 'No alcanza los aprendizajes requeridos',
			'D-' => 'No alcanza los aprendizajes requeridos',
			'E+' => 'No alcanza los aprendizajes requeridos',
			'E-' => 'No alcanza los aprendizajes requeridos',
		),
	);

	/**
	 * Convierte una nota numérica (1.00–10.00) al código cualitativo.
	 *
	 * El PDF indica que el sistema redondea el promedio al entero más
	 * cercano para determinar la equivalencia (8.13 → 8 → B+).
	 *
	 * @param  float  $nota  Nota entre 1.00 y 10.00.
	 * @return string        Código cualitativo (A+, A-, B+, …, E-) o '—' si fuera rango.
	 */
	public static function codigo( float $nota ): string {
		$entero = (int) round( $nota );
		$entero = max( 1, min( 10, $entero ) );
		return self::$mapa[ $entero ] ?? '—';
	}

	/**
	 * Devuelve el código cualitativo con su etiqueta RGLOEI según subnivel.
	 *
	 * @param  float  $nota      Nota numérica.
	 * @param  string $sub_level sub_level del grado (elemental, media, superior, bg, bt…).
	 * @return array  {
	 *   'codigo'   => string,   // p.ej. 'B+'
	 *   'etiqueta' => string,   // descripción RGLOEI según subnivel
	 * }
	 */
	public static function equivalencia( float $nota, string $sub_level = '' ): array {
		$codigo    = self::codigo( $nota );
		$grupo     = in_array( $sub_level, array( 'media', 'superior', 'bg', 'bt' ), true )
		             ? 'media_bach'
		             : 'elemental';
		$etiqueta  = self::$etiquetas_rgloei[ $grupo ][ $codigo ] ?? '';

		return array(
			'codigo'   => $codigo,
			'etiqueta' => $etiqueta,
		);
	}

	/**
	 * Devuelve el color CSS asociado al código cualitativo.
	 * Útil para colorear celdas en vistas y boletines.
	 *
	 * @param  string $codigo  A+, A-, B+, B-, C+, C-, D+, D-, E+, E-.
	 * @return string          Color hexadecimal.
	 */
	public static function color( string $codigo ): string {
		$colores = array(
			'A+' => '#15803d',
			'A-' => '#16a34a',
			'B+' => '#1d4ed8',
			'B-' => '#2563eb',
			'C+' => '#d97706',
			'C-' => '#f59e0b',
			'D+' => '#dc2626',
			'D-' => '#b91c1c',
			'E+' => '#7f1d1d',
			'E-' => '#450a0a',
		);
		return $colores[ $codigo ] ?? '#374151';
	}

	/**
	 * Badge HTML listo para usar en vistas PHP.
	 * Ejemplo: <span class="edu-badge" style="background:#15803d">A+</span>
	 *
	 * @param  float  $nota
	 * @param  string $sub_level
	 * @return string  HTML escapado.
	 */
	public static function badge( float $nota, string $sub_level = '' ): string {
		$codigo = self::codigo( $nota );
		$color  = self::color( $codigo );
		return sprintf(
			'<span class="edu-cualitativa-badge" style="background:%s; color:#fff; font-size:11px; font-weight:700; padding:2px 7px; border-radius:4px; display:inline-block;">%s</span>',
			esc_attr( $color ),
			esc_html( $codigo )
		);
	}
}
