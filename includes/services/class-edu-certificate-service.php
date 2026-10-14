<?php
/**
 * Servicio: certificado de promoción.
 *
 * Decide quién tiene derecho al certificado y arma sus datos; el PDF lo dibuja
 * Edu_Certificado_Generator. Reglas (decisiones del usuario, oct. 2026):
 *
 *   - Lo emiten rector o secretaría (edu_generate_reports / edu_view_all) y el
 *     representante, solo de sus propios hijos. El estudiante y el docente no.
 *     La lista del grado (estado_grado) es solo para rector o secretaría.
 *   - Solo con el año terminado: cada materia calificada del grado tiene
 *     cerrados TODOS los trimestres del período. Se mira el cierre por
 *     estudiante (trimester_scores.is_closed) porque wp_edu_trimesters.is_closed
 *     no lo marca ningún flujo.
 *   - Solo si aprobó todas: year_scores.status = 'aprobado' en cada materia
 *     (incluye a quien aprobó en supletorio, remedial o gracia).
 *   - El grado al que se promueve lo escribe el rector en el grado
 *     (grades.next_grade_name). Sin él no hay certificado: 3ro de bachillerato
 *     no se promueve, se gradúa.
 *   - Firman rector y secretario/a, con firma y sello en imagen. Las imágenes
 *     viven en uploads/edu-privado/certificados/{institución}/ y entran al PDF
 *     por ruta de disco: nunca tienen URL pública desde la que copiarlas.
 *   - Sin comportamiento: PromoSchool todavía no lo registra.
 *
 * Una materia que nadie del grado tiene calificada (p. ej. Acompañamiento
 * integral, si el colegio no la evalúa) no cuenta; una que el resto del grado
 * sí tiene y este estudiante no, bloquea el certificado.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Certificate_Service {

	/** Imágenes que admite el certificado. */
	const IMAGENES = array( 'firma_rector', 'firma_secretario', 'sello' );

	/** Tamaño máximo de una imagen de firma o sello. */
	const MAX_IMAGEN = 1048576;

	/* ─────────────────────────────────────────────────────────────────────
	 * Firmas y sello
	 * ────────────────────────────────────────────────────────────────── */

	/** Carpeta privada de las imágenes de una institución. */
	private static function carpeta( $institution_id ) {
		return Edu_File_Service::ensure_protected_dir() . 'certificados/' . (int) $institution_id . '/';
	}

	/**
	 * Ruta de la imagen guardada, o '' si no hay.
	 *
	 * @param int    $institution_id Institución.
	 * @param string $tipo           firma_rector | firma_secretario | sello.
	 * @return string
	 */
	public static function imagen( $institution_id, $tipo ) {
		if ( ! in_array( $tipo, self::IMAGENES, true ) ) {
			return '';
		}

		foreach ( array( 'png', 'jpg' ) as $ext ) {
			$ruta = self::carpeta( $institution_id ) . $tipo . '.' . $ext;
			if ( file_exists( $ruta ) ) {
				return $ruta;
			}
		}

		return '';
	}

	/** Permiso para tocar las firmas de una institución. */
	private static function puede_configurar( $institution_id ) {
		$cap = Edu_Service::require_cap( 'edu_view_all' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		if ( ! Edu_Context::can_access_institution( (int) $institution_id ) ) {
			return Edu_Service::invalid_scope();
		}

		return true;
	}

	/**
	 * Guarda la imagen de firma o sello subida en un formulario.
	 *
	 * Solo PNG o JPG de verdad (se lee la cabecera, no la extensión) y de hasta
	 * 1 MB. Reemplaza la anterior.
	 *
	 * @param int    $institution_id Institución.
	 * @param string $tipo           firma_rector | firma_secretario | sello.
	 * @param array  $file           Entrada de $_FILES de un campo simple.
	 * @return array|WP_Error
	 */
	public static function guardar_imagen( $institution_id, $tipo, array $file ) {
		$ok = self::puede_configurar( $institution_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		if ( ! in_array( $tipo, self::IMAGENES, true ) ) {
			return Edu_Service::error( 'invalid_image_type', __( 'Tipo de imagen desconocido.', 'sistema-educativo' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) ) {
			return Edu_Service::error( 'upload_failed', __( 'No se recibió la imagen.', 'sistema-educativo' ) );
		}

		if ( (int) $file['size'] > self::MAX_IMAGEN ) {
			return Edu_Service::error( 'image_too_big', __( 'La imagen supera 1 MB.', 'sistema-educativo' ) );
		}

		$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$tipos = array( IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg' );
		if ( ! $info || ! isset( $tipos[ $info[2] ] ) ) {
			return Edu_Service::error( 'invalid_image', __( 'La firma y el sello deben ser imágenes PNG o JPG.', 'sistema-educativo' ) );
		}

		$carpeta = self::carpeta( $institution_id );
		if ( ! file_exists( $carpeta ) ) {
			wp_mkdir_p( $carpeta );
		}

		$anterior = self::imagen( $institution_id, $tipo );
		$destino  = $carpeta . $tipo . '.' . $tipos[ $info[2] ];

		if ( ! move_uploaded_file( $file['tmp_name'], $destino ) ) {
			return Edu_Service::error( 'upload_failed', __( 'No se pudo guardar la imagen.', 'sistema-educativo' ), 500 );
		}

		// Cambió de PNG a JPG o al revés: la vieja quedaría tapando a la nueva.
		if ( $anterior && $anterior !== $destino && file_exists( $anterior ) ) {
			wp_delete_file( $anterior );
		}

		Edu_Audit::log( Edu_Audit::CERTIFICADO_FIRMAS, 'institution', (int) $institution_id, null, array( 'imagen' => $tipo, 'accion' => 'subida' ) );

		return array( 'tipo' => $tipo );
	}

	/**
	 * Borra la imagen de firma o sello.
	 *
	 * @param int    $institution_id Institución.
	 * @param string $tipo           firma_rector | firma_secretario | sello.
	 * @return array|WP_Error
	 */
	public static function borrar_imagen( $institution_id, $tipo ) {
		$ok = self::puede_configurar( $institution_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$ruta = self::imagen( $institution_id, $tipo );
		if ( $ruta ) {
			wp_delete_file( $ruta );
			Edu_Audit::log( Edu_Audit::CERTIFICADO_FIRMAS, 'institution', (int) $institution_id, null, array( 'imagen' => $tipo, 'accion' => 'borrada' ) );
		}

		return array( 'tipo' => $tipo );
	}

	/**
	 * Vista previa para la pantalla de la institución: data URI, no URL.
	 *
	 * @param int    $institution_id Institución.
	 * @param string $tipo           firma_rector | firma_secretario | sello.
	 * @return string
	 */
	public static function vista_previa( $institution_id, $tipo ) {
		$ruta = self::imagen( $institution_id, $tipo );
		if ( ! $ruta ) {
			return '';
		}

		$mime = str_ends_with( $ruta, '.png' ) ? 'image/png' : 'image/jpeg';

		return 'data:' . $mime . ';base64,' . base64_encode( (string) file_get_contents( $ruta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Derecho al certificado
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Datos completos del certificado, o el motivo por el que no corresponde.
	 *
	 * @param int $student_id Estudiante.
	 * @param int $period_id  Período lectivo.
	 * @return array|WP_Error
	 */
	public static function datos( $student_id, $period_id ) {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		if ( ! Edu_Modules::is_active( 'boletines' ) ) {
			return Edu_Service::error( 'module_disabled', __( 'El módulo de boletines no está habilitado.', 'sistema-educativo' ), 404 );
		}

		$puede = self::puede_emitir( (int) $student_id );
		if ( is_wp_error( $puede ) ) {
			return $puede;
		}

		$st = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.id, s.user_id, s.sexo, s.grade_id,
			        g.institution_id, g.name AS grade_name, g.paralelo, g.specialty, g.next_grade_name
			 FROM {$p}students s
			 INNER JOIN {$p}grades g ON g.id = s.grade_id
			 WHERE s.id = %d",
			(int) $student_id
		) );
		if ( ! $st ) {
			return Edu_Service::not_found( __( 'El estudiante no existe.', 'sistema-educativo' ) );
		}

		if ( ! Edu_Context::is_superadmin_editorial() && (int) $st->institution_id !== Edu_Context::current_institution_id() ) {
			return Edu_Service::invalid_scope();
		}

		$period = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}periods WHERE id = %d", (int) $period_id ) );
		if ( ! $period ) {
			return Edu_Service::not_found( __( 'El período no existe.', 'sistema-educativo' ) );
		}
		if ( (int) $period->institution_id !== (int) $st->institution_id ) {
			return Edu_Service::invalid_scope();
		}

		if ( '' === trim( (string) $st->next_grade_name ) ) {
			return Edu_Service::error(
				'no_next_grade',
				__( 'El grado no tiene configurado el «grado siguiente». Configúrelo en Grados; el último año de bachillerato no emite promoción.', 'sistema-educativo' ),
				409
			);
		}

		$trim_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$p}trimesters WHERE period_id = %d",
			(int) $period_id
		) ) );
		if ( ! $trim_ids ) {
			return Edu_Service::error( 'year_open', __( 'El período no tiene trimestres registrados.', 'sistema-educativo' ), 409 );
		}
		$in_trims = implode( ',', $trim_ids );

		// Materias del grado que alguien del grado tiene calificadas este año.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in_trims son enteros.
		$materias = $wpdb->get_results( $wpdb->prepare(
			"SELECT sj.id, sj.name
			 FROM {$p}grade_subjects gs
			 INNER JOIN {$p}subjects sj ON sj.id = gs.subject_id
			 WHERE gs.grade_id = %d
			   AND EXISTS (
			       SELECT 1 FROM {$p}year_scores ys
			       INNER JOIN {$p}students s2 ON s2.id = ys.student_id
			       WHERE ys.subject_id = sj.id AND ys.period_id = %d AND s2.grade_id = gs.grade_id
			   )
			 ORDER BY sj.name",
			(int) $st->grade_id,
			(int) $period_id
		) );
		if ( ! $materias ) {
			return Edu_Service::error( 'year_open', __( 'El grado aún no tiene notas anuales.', 'sistema-educativo' ), 409 );
		}

		$anuales = array();
		foreach ( $wpdb->get_results( $wpdb->prepare(
			"SELECT subject_id, average, status, supletorio_score, remedial_score, gracia_score
			 FROM {$p}year_scores WHERE student_id = %d AND period_id = %d",
			(int) $student_id,
			(int) $period_id
		) ) as $r ) {
			$anuales[ (int) $r->subject_id ] = $r;
		}

		// Trimestres cerrados por materia para este estudiante.
		$cerrados = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in_trims son enteros.
		foreach ( $wpdb->get_results( $wpdb->prepare(
			"SELECT subject_id, COUNT(*) AS n FROM {$p}trimester_scores
			 WHERE student_id = %d AND is_closed = 1 AND trimester_id IN ($in_trims)
			 GROUP BY subject_id",
			(int) $student_id
		) ) as $r ) {
			$cerrados[ (int) $r->subject_id ] = (int) $r->n;
		}

		$pendientes = array();
		$reprobadas = array();
		foreach ( $materias as $m ) {
			$id = (int) $m->id;
			if ( ! isset( $anuales[ $id ] ) || ( $cerrados[ $id ] ?? 0 ) < count( $trim_ids ) ) {
				$pendientes[] = $m->name;
			} elseif ( 'aprobado' !== $anuales[ $id ]->status ) {
				$reprobadas[] = $m->name;
			}
		}

		if ( $pendientes ) {
			return Edu_Service::error(
				'year_open',
				/* translators: %s: materias */
				sprintf( __( 'El año no está cerrado en: %s.', 'sistema-educativo' ), implode( ', ', $pendientes ) ),
				409,
				array( 'subjects' => $pendientes )
			);
		}

		if ( $reprobadas ) {
			return Edu_Service::error(
				'not_promoted',
				/* translators: %s: materias */
				sprintf( __( 'No aprobó: %s.', 'sistema-educativo' ), implode( ', ', $reprobadas ) ),
				409,
				array( 'subjects' => $reprobadas )
			);
		}

		$filas = array();
		foreach ( $materias as $m ) {
			$y   = $anuales[ (int) $m->id ];
			$avg = (float) $y->average;

			$obs = '';
			foreach ( array( 'gracia_score' => __( 'Gracia', 'sistema-educativo' ), 'remedial_score' => __( 'Remedial', 'sistema-educativo' ), 'supletorio_score' => __( 'Supletorio', 'sistema-educativo' ) ) as $col => $etiqueta ) {
				if ( null !== $y->$col ) {
					$obs = $etiqueta . ': ' . number_format( (float) $y->$col, 2 );
					break;
				}
			}

			$filas[] = array(
				'materia'     => $m->name,
				'nota'        => $avg,
				'letras'      => self::en_letras( $avg ),
				'cualitativa' => Edu_Qualitativa_Helper::codigo( $avg ),
				'observacion' => $obs,
			);
		}

		$promedio = round( array_sum( array_column( $filas, 'nota' ) ) / count( $filas ), 2 );

		$inst = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}institutions WHERE id = %d", (int) $st->institution_id ) );
		$user = get_userdata( (int) $st->user_id );

		$apellidos = $user ? trim( (string) $user->last_name ) : '';
		$nombres   = $user ? trim( (string) $user->first_name ) : '';
		$nombre    = trim( $apellidos . ' ' . $nombres );
		if ( '' === $nombre && $user ) {
			$nombre = $user->display_name;
		}

		$iid = (int) $st->institution_id;

		return array(
			'student_id'     => (int) $st->id,
			'period_id'      => (int) $period->id,
			'institucion'    => (string) $inst->name,
			'amie'           => (string) $inst->amie_code,
			'logo_url'       => (string) $inst->logo_url,
			'ciudad'         => (string) $inst->city,
			'regimen'        => (string) $period->regime,
			'periodo'        => (string) $period->name,
			'estudiante'     => mb_strtoupper( $nombre ),
			'sexo'           => (string) $st->sexo,
			'grado'          => trim( $st->grade_name . ( $st->specialty ? ' — ' . $st->specialty : '' ) ),
			'paralelo'       => (string) $st->paralelo,
			'grado_siguiente' => (string) $st->next_grade_name,
			'materias'       => $filas,
			'promedio'       => $promedio,
			'promedio_letras' => self::en_letras( $promedio ),
			'promedio_cualitativa' => Edu_Qualitativa_Helper::codigo( $promedio ),
			'rector'         => (string) $inst->rector_name,
			'secretario'     => (string) $inst->secretary_name,
			'firma_rector'   => self::imagen( $iid, 'firma_rector' ),
			'firma_secretario' => self::imagen( $iid, 'firma_secretario' ),
			'sello'          => self::imagen( $iid, 'sello' ),
			'fecha'          => self::fecha_larga( current_time( 'Y-m-d' ) ),
		);
	}

	/**
	 * Rector o secretaría, o el representante de ese estudiante.
	 *
	 * @param int $student_id Estudiante.
	 * @return true|WP_Error
	 */
	private static function puede_emitir( $student_id ) {
		$cap = Edu_Service::require_cap( array( 'edu_generate_reports', 'edu_view_all' ) );
		if ( ! is_wp_error( $cap ) ) {
			return true;
		}

		$identity = Edu_Service::identity();
		if ( $identity['parent_id'] && in_array( (int) $student_id, Edu_Service::own_children_ids(), true ) ) {
			return true;
		}

		return $cap;
	}

	/**
	 * Estado de cada estudiante activo del grado, para las pantallas.
	 *
	 * @param int $grade_id  Grado.
	 * @param int $period_id Período.
	 * @return array|WP_Error Lista de { student_id, elegible, code, message }.
	 */
	public static function estado_grado( $grade_id, $period_id ) {
		if ( ! Edu_Modules::is_active( 'boletines' ) ) {
			return Edu_Service::error( 'module_disabled', __( 'El módulo de boletines no está habilitado.', 'sistema-educativo' ), 404 );
		}

		$cap = Edu_Service::require_cap( array( 'edu_generate_reports', 'edu_view_all' ) );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$scope = Edu_Service::check_scope( array( 'grade_id' => (int) $grade_id ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$lista = array();
		foreach ( Edu_Service::active_student_ids( (int) $grade_id ) as $sid ) {
			$d = self::datos( (int) $sid, (int) $period_id );

			$lista[] = array(
				'student_id' => (int) $sid,
				'elegible'   => ! is_wp_error( $d ),
				'code'       => is_wp_error( $d ) ? $d->get_error_code() : '',
				'message'    => is_wp_error( $d ) ? $d->get_error_message() : '',
			);
		}

		return $lista;
	}

	/**
	 * Datos listos para imprimir, dejando constancia en la auditoría.
	 *
	 * Lo llaman los dos adaptadores justo antes de generar el PDF.
	 *
	 * @param int $student_id Estudiante.
	 * @param int $period_id  Período.
	 * @return array|WP_Error
	 */
	public static function emitir( $student_id, $period_id ) {
		$d = self::datos( $student_id, $period_id );
		if ( is_wp_error( $d ) ) {
			return $d;
		}

		Edu_Audit::log(
			Edu_Audit::CERTIFICADO_EMITIDO,
			'student',
			(int) $student_id,
			null,
			array(
				'period_id'       => (int) $period_id,
				'grado_siguiente' => $d['grado_siguiente'],
				'promedio'        => $d['promedio'],
			)
		);

		return $d;
	}

	/**
	 * Valida y emite la URL firmada de descarga (API).
	 *
	 * @param int $student_id Estudiante.
	 * @param int $period_id  Período.
	 * @return array|WP_Error
	 */
	public static function url( $student_id, $period_id ) {
		$d = self::datos( $student_id, $period_id );
		if ( is_wp_error( $d ) ) {
			return $d;
		}

		return Edu_File_Service::signed_url(
			'certificado',
			array(
				'student_id' => (int) $student_id,
				'period_id'  => (int) $period_id,
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Formato
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Nota en letras: 8.50 → «OCHO COMA CINCUENTA», 7.05 → «SIETE COMA CERO
	 * CINCO», 10 → «DIEZ».
	 *
	 * @param float $nota Nota de 0 a 10.
	 * @return string
	 */
	public static function en_letras( $nota ) {
		$centesimas = (int) round( max( 0, min( 10, (float) $nota ) ) * 100 );
		$entero     = intdiv( $centesimas, 100 );
		$decimales  = $centesimas % 100;

		$texto = self::numero( $entero );
		if ( $decimales > 0 ) {
			$texto .= ' coma ' . ( $decimales < 10 ? 'cero ' : '' ) . self::numero( $decimales );
		}

		return mb_strtoupper( $texto );
	}

	/** Número de 0 a 99 en palabras. */
	private static function numero( $n ) {
		$bajos = array(
			'cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
			'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
			'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
		);
		if ( $n < 30 ) {
			return $bajos[ $n ];
		}

		$decenas = array( 3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa' );
		$d       = intdiv( $n, 10 );
		$u       = $n % 10;

		return $decenas[ $d ] . ( $u ? ' y ' . $bajos[ $u ] : '' );
	}

	/** «14 de octubre de 2026», sin depender del idioma del sitio. */
	private static function fecha_larga( $ymd ) {
		$meses = array( 1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		$t     = strtotime( $ymd );

		return (int) gmdate( 'j', $t ) . ' de ' . $meses[ (int) gmdate( 'n', $t ) ] . ' de ' . gmdate( 'Y', $t );
	}
}
