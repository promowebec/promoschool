<?php
/**
 * Escritor XLSX mínimo sin dependencias externas.
 *
 * Genera archivos .xlsx válidos (SpreadsheetML) usando ZipArchive + XML
 * con cadenas inline. Suficiente para los exportes Mineduc (tablas planas
 * con encabezados en negrita); no soporta fórmulas ni celdas combinadas.
 *
 * Estilos disponibles (índice de cellXfs en styles.xml):
 *   0 = normal · 1 = negrita · 2 = título (negrita 14) · 3 = número 0.00
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Xlsx_Writer {

	const STYLE_NORMAL = 0;
	const STYLE_BOLD   = 1;
	const STYLE_TITLE  = 2;
	const STYLE_NUM    = 3;

	/** @var array[] Filas: cada una es un array de celdas ['v'=>mixed,'s'=>int]. */
	private array $rows = array();

	/** @var string Nombre de la hoja. */
	private string $sheet_name;

	public function __construct( string $sheet_name = 'Reporte' ) {
		// Excel limita el nombre de hoja a 31 chars y prohíbe : \ / ? * [ ].
		$this->sheet_name = mb_substr( str_replace( array( ':', '\\', '/', '?', '*', '[', ']' ), ' ', $sheet_name ), 0, 31 );
	}

	/**
	 * Agrega una fila. Cada celda puede ser un escalar (estilo por defecto)
	 * o un array ['v' => valor, 's' => estilo].
	 *
	 * @param array $cells Celdas de la fila.
	 * @param int   $style Estilo por defecto para las celdas escalares.
	 */
	public function add_row( array $cells, int $style = self::STYLE_NORMAL ): void {
		$row = array();
		foreach ( $cells as $cell ) {
			if ( is_array( $cell ) ) {
				$row[] = array( 'v' => $cell['v'] ?? '', 's' => (int) ( $cell['s'] ?? $style ) );
			} else {
				$row[] = array( 'v' => $cell, 's' => $style );
			}
		}
		$this->rows[] = $row;
	}

	/** Fila vacía (separador visual). */
	public function add_blank_row(): void {
		$this->rows[] = array();
	}

	/**
	 * Envía el .xlsx al navegador y termina la ejecución.
	 *
	 * @param string $filename Nombre de archivo sugerido (sin ruta).
	 */
	public function send( string $filename ): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive no disponible en este servidor.', 'sistema-educativo' ) );
		}

		// wp_tempnam() vive en wp-admin/includes/file.php y no siempre está cargado.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( 'edu-xlsx' );
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::OVERWRITE );

		$zip->addFromString( '[Content_Types].xml', $this->xml_content_types() );
		$zip->addFromString( '_rels/.rels', $this->xml_rels_root() );
		$zip->addFromString( 'xl/workbook.xml', $this->xml_workbook() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->xml_rels_workbook() );
		$zip->addFromString( 'xl/styles.xml', $this->xml_styles() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $this->xml_sheet() );
		$zip->close();

		$filename = sanitize_file_name( $filename );

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		unlink( $tmp );   // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		exit;
	}

	// ------------------------------------------------------------------
	// Partes XML del paquete
	// ------------------------------------------------------------------

	private function xml_content_types(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';
	}

	private function xml_rels_root(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	private function xml_workbook(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="' . $this->esc( $this->sheet_name ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	private function xml_rels_workbook(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	private function xml_styles(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00"/></numFmts>'
			. '<fonts count="3">'
			. '<font><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="14"/><name val="Calibri"/></font>'
			. '</fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="4">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			. '</cellXfs>'
			. '</styleSheet>';
	}

	private function xml_sheet(): string {
		// Anchos de columna aproximados según el contenido más largo.
		$widths = array();
		foreach ( $this->rows as $row ) {
			foreach ( $row as $i => $cell ) {
				$len = mb_strlen( (string) $cell['v'] );
				if ( $len > ( $widths[ $i ] ?? 0 ) ) {
					$widths[ $i ] = $len;
				}
			}
		}

		$cols = '';
		if ( $widths ) {
			$cols = '<cols>';
			foreach ( $widths as $i => $len ) {
				$w     = min( max( $len + 2, 6 ), 45 );
				$cols .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . $w . '" customWidth="1"/>';
			}
			$cols .= '</cols>';
		}

		$sheet_data = '<sheetData>';
		foreach ( $this->rows as $r => $row ) {
			$num         = $r + 1;
			$sheet_data .= '<row r="' . $num . '">';
			foreach ( $row as $i => $cell ) {
				$ref = self::col_letter( $i ) . $num;
				$v   = $cell['v'];
				$s   = $cell['s'];
				if ( is_int( $v ) || is_float( $v ) ) {
					// Solo los tipos nativos int/float son numéricos; las cadenas
					// (cédulas, teléfonos) se conservan siempre como texto.
					$sheet_data .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $this->esc( (string) $v ) . '</v></c>';
				} elseif ( '' !== (string) $v ) {
					$sheet_data .= '<c r="' . $ref . '" t="inlineStr" s="' . $s . '"><is><t xml:space="preserve">' . $this->esc( (string) $v ) . '</t></is></c>';
				}
			}
			$sheet_data .= '</row>';
		}
		$sheet_data .= '</sheetData>';

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. $cols . $sheet_data . '</worksheet>';
	}

	/** Índice de columna (0-based) → letra Excel (A, B, ..., AA, AB...). */
	public static function col_letter( int $index ): string {
		$letter = '';
		$index++;
		while ( $index > 0 ) {
			$mod    = ( $index - 1 ) % 26;
			$letter = chr( 65 + $mod ) . $letter;
			$index  = (int) ( ( $index - $mod - 1 ) / 26 );
		}
		return $letter;
	}

	private function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
