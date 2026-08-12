<?php
/**
 * Exportes Mineduc (Fase 7D): archivos Excel .xlsx descargables desde el admin.
 *
 * 4 reportes:
 *   1. acta        — Acta consolidada de calificaciones (período + grado)
 *   2. nomina      — Nómina de estudiantes formato AMIE (período + grado)
 *   3. distributivo — Distributivo docente (período)
 *   4. asistencia  — Asistencia acumulada (período + grado)
 *
 * Acción: admin_post_edu_export_mineduc (gated por módulo 'exportes').
 * La descarga es directa (Content-Disposition: attachment), sin almacenar en servidor.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once EDU_PLUGIN_DIR . 'modules/reportes/class-edu-xlsx-writer.php';

class Edu_Mineduc_Exporter {

	/**
	 * Punto de entrada admin-post: valida nonce, permisos y despacha al reporte.
	 */
	public static function handle_export(): void {
		check_admin_referer( 'edu_export_mineduc' );

		if ( ! ( Edu_Context::can( 'edu_generate_reports' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$institution_id = Edu_Context::current_institution_id();
		if ( ! $institution_id ) {
			wp_die( esc_html__( 'Seleccione una institución activa.', 'sistema-educativo' ) );
		}

		$type      = isset( $_GET['report_type'] ) ? sanitize_key( wp_unslash( $_GET['report_type'] ) ) : '';
		$period_id = isset( $_GET['period_id'] ) ? (int) $_GET['period_id'] : 0;
		$grade_id  = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		// El período debe pertenecer a la institución activa.
		$period_ok = $period_id && (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}periods WHERE id = %d AND institution_id = %d",
			$period_id, $institution_id
		) );
		if ( ! $period_ok ) {
			wp_die( esc_html__( 'Período inválido.', 'sistema-educativo' ) );
		}

		// El grado (cuando aplica) debe pertenecer a la institución activa.
		if ( in_array( $type, array( 'acta', 'nomina', 'asistencia' ), true ) ) {
			$grade_ok = $grade_id && (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}grades WHERE id = %d AND institution_id = %d",
				$grade_id, $institution_id
			) );
			if ( ! $grade_ok ) {
				wp_die( esc_html__( 'Grado inválido.', 'sistema-educativo' ) );
			}
		}

		switch ( $type ) {
			case 'acta':
				self::acta_consolidada( $period_id, $grade_id );
				break;
			case 'nomina':
				self::nomina_amie( $period_id, $grade_id );
				break;
			case 'distributivo':
				self::distributivo_docente( $period_id );
				break;
			case 'asistencia':
				self::asistencia_acumulada( $period_id, $grade_id );
				break;
			default:
				wp_die( esc_html__( 'Tipo de reporte inválido.', 'sistema-educativo' ) );
		}
	}

	// ------------------------------------------------------------------
	// 1. Acta consolidada de calificaciones
	// ------------------------------------------------------------------

	public static function acta_consolidada( int $period_id, int $grade_id ): void {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$ctx      = self::contexto( $period_id, $grade_id );
		$students = self::estudiantes_grado( $grade_id );

		// Materias del pensum del grado.
		$subjects = $wpdb->get_results( $wpdb->prepare(
			"SELECT sub.id, sub.name
			 FROM {$p}grade_subjects gs
			 INNER JOIN {$p}subjects sub ON sub.id = gs.subject_id
			 WHERE gs.grade_id = %d
			 ORDER BY sub.name",
			$grade_id
		) );

		// Notas anuales de todos los estudiantes del grado en el período.
		$scores = $wpdb->get_results( $wpdb->prepare(
			"SELECT ys.student_id, ys.subject_id, ys.trim1, ys.trim2, ys.trim3, ys.average, ys.status
			 FROM {$p}year_scores ys
			 INNER JOIN {$p}students s ON s.id = ys.student_id
			 WHERE ys.period_id = %d AND s.grade_id = %d",
			$period_id, $grade_id
		) );
		$por_estudiante = array();
		foreach ( $scores as $sc ) {
			$por_estudiante[ (int) $sc->student_id ][ (int) $sc->subject_id ] = $sc;
		}

		$xlsx = new Edu_Xlsx_Writer( __( 'Acta consolidada', 'sistema-educativo' ) );
		self::encabezado( $xlsx, __( 'ACTA CONSOLIDADA DE CALIFICACIONES', 'sistema-educativo' ), $ctx );

		// Fila 1 de encabezado: nombre de la materia sobre su grupo de columnas.
		$fila_materias = array( '', '', '', '' );
		foreach ( $subjects as $sub ) {
			$fila_materias[] = $sub->name;
			$fila_materias[] = '';
			$fila_materias[] = '';
			$fila_materias[] = '';
			$fila_materias[] = '';
		}
		$xlsx->add_row( $fila_materias, Edu_Xlsx_Writer::STYLE_BOLD );

		// Fila 2 de encabezado: columnas por materia.
		$fila_cols = array(
			__( 'N°', 'sistema-educativo' ),
			__( 'Cédula', 'sistema-educativo' ),
			__( 'Apellidos', 'sistema-educativo' ),
			__( 'Nombres', 'sistema-educativo' ),
		);
		foreach ( $subjects as $sub ) {
			$fila_cols[] = __( 'T1', 'sistema-educativo' );
			$fila_cols[] = __( 'T2', 'sistema-educativo' );
			$fila_cols[] = __( 'T3', 'sistema-educativo' );
			$fila_cols[] = __( 'Prom.', 'sistema-educativo' );
			$fila_cols[] = __( 'Estado', 'sistema-educativo' );
		}
		$xlsx->add_row( $fila_cols, Edu_Xlsx_Writer::STYLE_BOLD );

		$n = 0;
		foreach ( $students as $st ) {
			$n++;
			$fila = array( $n, (string) $st->cedula, $st->last_name, $st->first_name );
			foreach ( $subjects as $sub ) {
				$sc = $por_estudiante[ (int) $st->id ][ (int) $sub->id ] ?? null;
				if ( $sc ) {
					$fila[] = array( 'v' => (float) $sc->trim1, 's' => Edu_Xlsx_Writer::STYLE_NUM );
					$fila[] = array( 'v' => (float) $sc->trim2, 's' => Edu_Xlsx_Writer::STYLE_NUM );
					$fila[] = array( 'v' => (float) $sc->trim3, 's' => Edu_Xlsx_Writer::STYLE_NUM );
					$fila[] = array( 'v' => (float) $sc->average, 's' => Edu_Xlsx_Writer::STYLE_NUM );
					$fila[] = self::estado_label( (string) $sc->status );
				} else {
					array_push( $fila, '', '', '', '', '' );
				}
			}
			$xlsx->add_row( $fila );
		}

		$xlsx->send( self::nombre_archivo( 'acta_consolidada', $ctx ) );
	}

	// ------------------------------------------------------------------
	// 2. Nómina de estudiantes (formato AMIE)
	// ------------------------------------------------------------------

	public static function nomina_amie( int $period_id, int $grade_id ): void {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$ctx = self::contexto( $period_id, $grade_id );

		// Un representante por estudiante: el primario o, en su defecto, el primero vinculado.
		$students = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.cedula, s.birth_date, s.sexo, s.direccion,
			        COALESCE(um_fn.meta_value, '') AS first_name,
			        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
			        pa.phone  AS rep_phone,
			        pa.cedula AS rep_cedula
			 FROM {$p}students s
			 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
			 LEFT JOIN {$p}parent_student ps ON ps.id = (
			     SELECT ps2.id FROM {$p}parent_student ps2
			     WHERE ps2.student_id = s.id
			     ORDER BY ps2.is_primary DESC, ps2.id ASC LIMIT 1 )
			 LEFT JOIN {$p}parents pa ON pa.id = ps.parent_id
			 WHERE s.grade_id = %d AND s.status = 'active'
			 ORDER BY last_name, first_name",
			$grade_id
		) );

		$xlsx = new Edu_Xlsx_Writer( __( 'Nómina AMIE', 'sistema-educativo' ) );
		self::encabezado( $xlsx, __( 'NÓMINA DE ESTUDIANTES (AMIE)', 'sistema-educativo' ), $ctx );

		$xlsx->add_row( array(
			__( 'N°', 'sistema-educativo' ),
			__( 'Cédula', 'sistema-educativo' ),
			__( 'Apellidos', 'sistema-educativo' ),
			__( 'Nombres', 'sistema-educativo' ),
			__( 'Fecha nacimiento', 'sistema-educativo' ),
			__( 'Sexo', 'sistema-educativo' ),
			__( 'Dirección', 'sistema-educativo' ),
			__( 'Teléfono representante', 'sistema-educativo' ),
			__( 'Cédula representante', 'sistema-educativo' ),
		), Edu_Xlsx_Writer::STYLE_BOLD );

		$n = 0;
		foreach ( $students as $st ) {
			$n++;
			$xlsx->add_row( array(
				$n,
				(string) $st->cedula,
				$st->last_name,
				$st->first_name,
				$st->birth_date ? mysql2date( 'd/m/Y', $st->birth_date ) : '',
				(string) $st->sexo,
				(string) $st->direccion,
				(string) $st->rep_phone,
				(string) $st->rep_cedula,
			) );
		}

		$xlsx->send( self::nombre_archivo( 'nomina_amie', $ctx ) );
	}

	// ------------------------------------------------------------------
	// 3. Distributivo docente
	// ------------------------------------------------------------------

	public static function distributivo_docente( int $period_id ): void {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$ctx = self::contexto( $period_id, 0 );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.cedula, t.title,
			        COALESCE(um_fn.meta_value, '') AS first_name,
			        COALESCE(um_ln.meta_value, u.display_name) AS last_name,
			        sub.name AS materia,
			        g.name AS grado, g.paralelo,
			        gs.hours_week
			 FROM {$p}teacher_assignments ta
			 INNER JOIN {$p}teachers t   ON t.id = ta.teacher_id
			 INNER JOIN {$wpdb->users} u ON u.ID = t.user_id
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = t.user_id AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = t.user_id AND um_ln.meta_key = 'last_name'
			 INNER JOIN {$p}subjects sub ON sub.id = ta.subject_id
			 INNER JOIN {$p}grades g     ON g.id = ta.grade_id
			 LEFT JOIN {$p}grade_subjects gs ON gs.grade_id = ta.grade_id AND gs.subject_id = ta.subject_id
			 WHERE ta.period_id = %d AND ta.is_active = 1 AND g.institution_id = %d
			 ORDER BY last_name, first_name, grado, g.paralelo, materia",
			$period_id, (int) $ctx['institution_id']
		) );

		$xlsx = new Edu_Xlsx_Writer( __( 'Distributivo docente', 'sistema-educativo' ) );
		self::encabezado( $xlsx, __( 'DISTRIBUTIVO DOCENTE', 'sistema-educativo' ), $ctx );

		$xlsx->add_row( array(
			__( 'N°', 'sistema-educativo' ),
			__( 'Cédula docente', 'sistema-educativo' ),
			__( 'Apellidos', 'sistema-educativo' ),
			__( 'Nombres', 'sistema-educativo' ),
			__( 'Título', 'sistema-educativo' ),
			__( 'Materia', 'sistema-educativo' ),
			__( 'Grado', 'sistema-educativo' ),
			__( 'Paralelo', 'sistema-educativo' ),
			__( 'Horas/semana', 'sistema-educativo' ),
		), Edu_Xlsx_Writer::STYLE_BOLD );

		$n = 0;
		foreach ( $rows as $r ) {
			$n++;
			$xlsx->add_row( array(
				$n,
				(string) $r->cedula,
				$r->last_name,
				$r->first_name,
				(string) $r->title,
				$r->materia,
				$r->grado,
				$r->paralelo,
				null !== $r->hours_week ? (int) $r->hours_week : '',
			) );
		}

		$xlsx->send( self::nombre_archivo( 'distributivo_docente', $ctx ) );
	}

	// ------------------------------------------------------------------
	// 4. Asistencia acumulada
	// ------------------------------------------------------------------

	public static function asistencia_acumulada( int $period_id, int $grade_id ): void {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$ctx      = self::contexto( $period_id, $grade_id );
		$students = self::estudiantes_grado( $grade_id );

		/*
		 * Un mismo día puede tener varias filas (asistencia general + por materia).
		 * Se deduplica por fecha tomando el estado más grave del día:
		 * falta_injustificada > falta_justificada > atraso > presente.
		 */
		$att = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.student_id, a.date,
			        MIN(FIELD(a.status,'falta_injustificada','falta_justificada','atraso','presente')) AS pri
			 FROM {$p}attendance a
			 INNER JOIN {$p}students s ON s.id = a.student_id
			 WHERE s.grade_id = %d AND a.date BETWEEN %s AND %s
			 GROUP BY a.student_id, a.date",
			$grade_id, $ctx['start_date'], $ctx['end_date']
		) );

		$estados = array( 1 => 'falta_injustificada', 2 => 'falta_justificada', 3 => 'atraso', 4 => 'presente' );
		$acum    = array();
		foreach ( $att as $row ) {
			$estado = $estados[ (int) $row->pri ] ?? 'presente';
			$sid    = (int) $row->student_id;
			if ( ! isset( $acum[ $sid ] ) ) {
				$acum[ $sid ] = array( 'presente' => 0, 'atraso' => 0, 'falta_justificada' => 0, 'falta_injustificada' => 0 );
			}
			$acum[ $sid ][ $estado ]++;
		}

		$working_days = max( 1, (int) $ctx['working_days'] );

		$xlsx = new Edu_Xlsx_Writer( __( 'Asistencia acumulada', 'sistema-educativo' ) );
		self::encabezado( $xlsx, __( 'ASISTENCIA ACUMULADA', 'sistema-educativo' ), $ctx );

		$xlsx->add_row( array(
			__( 'N°', 'sistema-educativo' ),
			__( 'Cédula', 'sistema-educativo' ),
			__( 'Apellidos', 'sistema-educativo' ),
			__( 'Nombres', 'sistema-educativo' ),
			__( 'Días asistidos', 'sistema-educativo' ),
			__( 'Faltas justificadas', 'sistema-educativo' ),
			__( 'Faltas injustificadas', 'sistema-educativo' ),
			__( 'Atrasos', 'sistema-educativo' ),
			__( 'Total días laborables', 'sistema-educativo' ),
			__( '% Asistencia', 'sistema-educativo' ),
		), Edu_Xlsx_Writer::STYLE_BOLD );

		$n = 0;
		foreach ( $students as $st ) {
			$n++;
			$a         = $acum[ (int) $st->id ] ?? array( 'presente' => 0, 'atraso' => 0, 'falta_justificada' => 0, 'falta_injustificada' => 0 );
			$asistidos = $a['presente'] + $a['atraso'];
			$xlsx->add_row( array(
				$n,
				(string) $st->cedula,
				$st->last_name,
				$st->first_name,
				$asistidos,
				$a['falta_justificada'],
				$a['falta_injustificada'],
				$a['atraso'],
				$working_days,
				array( 'v' => round( $asistidos / $working_days * 100, 2 ), 's' => Edu_Xlsx_Writer::STYLE_NUM ),
			) );
		}

		$xlsx->send( self::nombre_archivo( 'asistencia_acumulada', $ctx ) );
	}

	// ------------------------------------------------------------------
	// Auxiliares
	// ------------------------------------------------------------------

	/**
	 * Datos de contexto compartidos por todos los reportes.
	 *
	 * @return array{institution_id:int, inst_name:string, period_name:string, start_date:string, end_date:string, working_days:int, grade_label:string}
	 */
	private static function contexto( int $period_id, int $grade_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$period = $wpdb->get_row( $wpdb->prepare(
			"SELECT per.name, per.start_date, per.end_date, per.working_days,
			        i.id AS institution_id, i.name AS inst_name
			 FROM {$p}periods per
			 INNER JOIN {$p}institutions i ON i.id = per.institution_id
			 WHERE per.id = %d",
			$period_id
		) );

		$grade_label = '';
		if ( $grade_id ) {
			$grade = $wpdb->get_row( $wpdb->prepare(
				"SELECT name, paralelo FROM {$p}grades WHERE id = %d",
				$grade_id
			) );
			if ( $grade ) {
				$grade_label = $grade->name . ' "' . $grade->paralelo . '"';
			}
		}

		return array(
			'institution_id' => (int) $period->institution_id,
			'inst_name'      => (string) $period->inst_name,
			'period_name'    => (string) $period->name,
			'start_date'     => (string) $period->start_date,
			'end_date'       => (string) $period->end_date,
			'working_days'   => (int) $period->working_days,
			'grade_label'    => $grade_label,
		);
	}

	/** Estudiantes de un grado con cédula y nombres (usermeta), orden alfabético. */
	private static function estudiantes_grado( int $grade_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.cedula,
			        COALESCE(um_fn.meta_value, '') AS first_name,
			        COALESCE(um_ln.meta_value, u.display_name) AS last_name
			 FROM {$p}students s
			 INNER JOIN {$wpdb->users} u ON u.ID = s.user_id
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = s.user_id AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = s.user_id AND um_ln.meta_key = 'last_name'
			 WHERE s.grade_id = %d AND s.status = 'active'
			 ORDER BY last_name, first_name",
			$grade_id
		) );
	}

	/** Filas de encabezado comunes: institución, título, período/grado y fecha de generación. */
	private static function encabezado( Edu_Xlsx_Writer $xlsx, string $titulo, array $ctx ): void {
		$xlsx->add_row( array( $ctx['inst_name'] ), Edu_Xlsx_Writer::STYLE_TITLE );
		$xlsx->add_row( array( $titulo ), Edu_Xlsx_Writer::STYLE_BOLD );

		$linea = sprintf(
			/* translators: 1: nombre del período lectivo */
			__( 'Período lectivo: %s', 'sistema-educativo' ),
			$ctx['period_name']
		);
		if ( $ctx['grade_label'] ) {
			$linea .= ' · ' . sprintf(
				/* translators: 1: grado y paralelo */
				__( 'Grado: %s', 'sistema-educativo' ),
				$ctx['grade_label']
			);
		}
		$xlsx->add_row( array( $linea ) );
		$xlsx->add_row( array( sprintf(
			/* translators: 1: fecha de generación */
			__( 'Generado: %s', 'sistema-educativo' ),
			wp_date( 'd/m/Y H:i' )
		) ) );
		$xlsx->add_blank_row();
	}

	/** Nombre del archivo descargado: tipo_periodo[_grado].xlsx */
	private static function nombre_archivo( string $tipo, array $ctx ): string {
		$partes = array( $tipo, $ctx['period_name'] );
		if ( $ctx['grade_label'] ) {
			$partes[] = $ctx['grade_label'];
		}
		return sanitize_title( implode( '-', $partes ) ) . '.xlsx';
	}

	/** Etiqueta en español del estado anual. */
	private static function estado_label( string $status ): string {
		$map = array(
			'en_curso'   => __( 'En curso', 'sistema-educativo' ),
			'aprobado'   => __( 'Aprobado', 'sistema-educativo' ),
			'supletorio' => __( 'Supletorio', 'sistema-educativo' ),
			'remedial'   => __( 'Remedial', 'sistema-educativo' ),
			'gracia'     => __( 'Gracia', 'sistema-educativo' ),
			'reprobado'  => __( 'Reprobado', 'sistema-educativo' ),
		);
		return $map[ $status ] ?? $status;
	}
}
