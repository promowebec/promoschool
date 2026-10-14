<?php
/**
 * Controller: descarga de boletines PDF.
 *
 * Acciones:
 *   admin_post_edu_download_boletin       → descarga PDF de un estudiante
 *   admin_post_edu_download_boletines_zip → genera ZIP con todos los PDFs del grado
 *   admin_post_edu_download_certificado   → certificado de promoción de un estudiante
 *   admin_post_edu_download_certificados_zip → certificados de los promovidos del grado
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Boletin_Controller {

	/**
	 * Descarga el boletín PDF de un estudiante.
	 */
	public static function handle_download(): void {
		check_admin_referer( 'edu_download_boletin' );

		if ( ! ( Edu_Context::can( 'edu_generate_reports' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$student_id = isset( $_GET['student_id'] ) ? (int) $_GET['student_id'] : 0;
		$period_id  = isset( $_GET['period_id'] )  ? (int) $_GET['period_id']  : 0;

		if ( ! $student_id || ! $period_id ) {
			wp_die( esc_html__( 'Parámetros inválidos.', 'sistema-educativo' ) );
		}

		// El estudiante debe pertenecer a la institución activa del solicitante.
		if ( ! self::student_in_current_institution( $student_id ) ) {
			wp_die( esc_html__( 'Sin permiso para ver este boletín.', 'sistema-educativo' ) );
		}

		require_once EDU_PLUGIN_DIR . 'modules/boletines/class-edu-boletin-generator.php';
		( new Edu_Boletin_Generator() )->stream( $student_id, $period_id );
	}

	/**
	 * ¿Pertenece el estudiante a la institución activa? (superadmin editorial exento).
	 */
	private static function student_in_current_institution( int $student_id ): bool {
		if ( Edu_Context::is_superadmin_editorial() ) {
			return true;
		}
		global $wpdb;
		$inst = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT g.institution_id
			 FROM {$wpdb->prefix}edu_students s
			 INNER JOIN {$wpdb->prefix}edu_grades g ON g.id = s.grade_id
			 WHERE s.id = %d",
			$student_id
		) );
		return $inst && $inst === Edu_Context::current_institution_id();
	}

	/**
	 * Descarga el boletín de un hijo propio (portal padre).
	 * Verifica que student_id pertenezca a uno de los hijos del padre autenticado.
	 */
	public static function handle_download_padre(): void {
		check_admin_referer( 'edu_download_boletin_padre' );

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'sistema-educativo' ) );
		}

		$student_id = isset( $_GET['student_id'] ) ? (int) $_GET['student_id'] : 0;
		$period_id  = isset( $_GET['period_id'] )  ? (int) $_GET['period_id']  : 0;

		if ( ! $student_id || ! $period_id ) {
			wp_die( esc_html__( 'Parámetros inválidos.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		// Verificar que el estudiante sea hijo del padre autenticado.
		$parent = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$p}parents WHERE user_id = %d",
			get_current_user_id()
		) );

		if ( ! $parent ) {
			wp_die( esc_html__( 'No tienes registro de representante.', 'sistema-educativo' ) );
		}

		$es_hijo = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}parent_student
			 WHERE parent_id = %d AND student_id = %d",
			(int) $parent->id, $student_id
		) );

		if ( ! $es_hijo ) {
			wp_die( esc_html__( 'No tienes permiso para ver este boletín.', 'sistema-educativo' ) );
		}

		require_once EDU_PLUGIN_DIR . 'modules/boletines/class-edu-boletin-generator.php';
		( new Edu_Boletin_Generator() )->stream( $student_id, $period_id );
	}

	/**
	 * Genera un ZIP con los boletines de todos los estudiantes de un grado.
	 */
	public static function handle_download_zip(): void {
		check_admin_referer( 'edu_download_boletin' );

		if ( ! ( Edu_Context::can( 'edu_generate_reports' ) || Edu_Context::can( 'edu_view_all' ) ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$grade_id  = isset( $_GET['grade_id'] )  ? (int) $_GET['grade_id']  : 0;
		$period_id = isset( $_GET['period_id'] ) ? (int) $_GET['period_id'] : 0;

		if ( ! $grade_id || ! $period_id ) {
			wp_die( esc_html__( 'Parámetros inválidos.', 'sistema-educativo' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive no disponible en este servidor.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		// El grado debe pertenecer a la institución activa del solicitante.
		if ( ! Edu_Context::is_superadmin_editorial() ) {
			$grade_inst = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT institution_id FROM {$p}grades WHERE id = %d",
				$grade_id
			) );
			if ( ! $grade_inst || $grade_inst !== Edu_Context::current_institution_id() ) {
				wp_die( esc_html__( 'Sin permiso para este grado.', 'sistema-educativo' ) );
			}
		}

		$students = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM {$p}students WHERE grade_id = %d ORDER BY id",
			$grade_id
		) );

		if ( empty( $students ) ) {
			wp_die( esc_html__( 'No hay estudiantes en este grado.', 'sistema-educativo' ) );
		}

		// Un PDF por estudiante: en grados grandes puede superar el max_execution_time.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		wp_raise_memory_limit( 'admin' );

		require_once EDU_PLUGIN_DIR . 'modules/boletines/class-edu-boletin-generator.php';
		$gen      = new Edu_Boletin_Generator();
		$pdf_paths = array();

		foreach ( $students as $student ) {
			try {
				$pdf_paths[] = $gen->save( (int) $student->id, $period_id );
			} catch ( \Throwable $e ) {
				// Si un boletín falla, continúa con el siguiente.
				continue;
			}
		}

		if ( empty( $pdf_paths ) ) {
			wp_die( esc_html__( 'No se pudo generar ningún boletín.', 'sistema-educativo' ) );
		}

		// Crear ZIP en temp.
		$zip_path = sys_get_temp_dir() . '/boletines_grado_' . $grade_id . '_' . time() . '.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		foreach ( $pdf_paths as $path ) {
			if ( file_exists( $path ) ) {
				$zip->addFile( $path, basename( $path ) );
			}
		}
		$zip->close();

		// Enviar ZIP al navegador.
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="boletines_grado_' . $grade_id . '.zip"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		header( 'Pragma: no-cache' );
		readfile( $zip_path );

		// Limpiar archivos temporales.
		unlink( $zip_path );
		foreach ( $pdf_paths as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		exit;
	}

	/* ─── Certificado de promoción ─────────────────────────────────────── */

	/**
	 * Descarga el certificado de promoción de un estudiante.
	 * Permiso, alcance y derecho al certificado los decide el servicio.
	 */
	public static function handle_download_certificado(): void {
		check_admin_referer( 'edu_download_boletin' );

		$d = Edu_Certificate_Service::emitir(
			isset( $_GET['student_id'] ) ? (int) $_GET['student_id'] : 0,
			isset( $_GET['period_id'] ) ? (int) $_GET['period_id'] : 0
		);
		if ( is_wp_error( $d ) ) {
			wp_die( esc_html( $d->get_error_message() ), '', array( 'response' => (int) ( $d->get_error_data()['status'] ?? 400 ), 'back_link' => true ) );
		}

		require_once EDU_PLUGIN_DIR . 'modules/boletines/class-edu-certificado-generator.php';
		( new Edu_Certificado_Generator() )->stream( $d );
	}

	/**
	 * ZIP con los certificados de los estudiantes promovidos del grado.
	 * Los que no tienen derecho se saltan; si ninguno lo tiene, se avisa.
	 */
	public static function handle_download_certificados_zip(): void {
		check_admin_referer( 'edu_download_boletin' );

		$grade_id  = isset( $_GET['grade_id'] ) ? (int) $_GET['grade_id'] : 0;
		$period_id = isset( $_GET['period_id'] ) ? (int) $_GET['period_id'] : 0;

		$estado = Edu_Certificate_Service::estado_grado( $grade_id, $period_id );
		if ( is_wp_error( $estado ) ) {
			wp_die( esc_html( $estado->get_error_message() ), '', array( 'back_link' => true ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZipArchive no disponible en este servidor.', 'sistema-educativo' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'admin' );

		require_once EDU_PLUGIN_DIR . 'modules/boletines/class-edu-certificado-generator.php';
		$gen   = new Edu_Certificado_Generator();
		$rutas = array();

		foreach ( $estado as $fila ) {
			if ( ! $fila['elegible'] ) {
				continue;
			}
			$d = Edu_Certificate_Service::emitir( $fila['student_id'], $period_id );
			if ( ! is_wp_error( $d ) ) {
				$rutas[] = $gen->save( $d );
			}
		}

		if ( ! $rutas ) {
			wp_die( esc_html__( 'Ningún estudiante de este grado cumple aún las condiciones del certificado de promoción.', 'sistema-educativo' ), '', array( 'back_link' => true ) );
		}

		$zip_path = get_temp_dir() . 'certificados_grado_' . $grade_id . '_' . time() . '.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $rutas as $ruta ) {
			$zip->addFile( $ruta, basename( $ruta ) );
		}
		$zip->close();

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="certificados_grado_' . $grade_id . '.zip"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		header( 'Pragma: no-cache' );
		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		wp_delete_file( $zip_path );
		foreach ( $rutas as $ruta ) {
			wp_delete_file( $ruta );
		}
		exit;
	}
}
