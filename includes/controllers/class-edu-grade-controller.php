<?php
/**
 * Controller: ABM de grados y paralelos.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Grade_Controller {

	private static $valid_levels    = array( 'inicial', 'basica', 'bachillerato' );
	private static $valid_sublevels = array( 'inicial_1', 'inicial_2', 'preparatoria', 'elemental', 'media', 'superior', 'bg', 'bt' );

	public static function handle_save() {
		check_admin_referer( 'edu_save_grade' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$t = $wpdb->prefix . 'edu_grades';

		$name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$paralelo      = sanitize_text_field( wp_unslash( $_POST['paralelo'] ?? '' ) );
		$level         = sanitize_key( $_POST['level'] ?? '' );
		$sub_level     = sanitize_key( $_POST['sub_level'] ?? '' );
		$specialty     = sanitize_text_field( wp_unslash( $_POST['specialty'] ?? '' ) );
		$tutor_user_id = isset( $_POST['tutor_user_id'] ) ? (int) $_POST['tutor_user_id'] : 0;

		// Validar tutor: debe ser docente de esta institución (o vacío = sin tutor).
		if ( $tutor_user_id > 0 ) {
			$tt          = $wpdb->prefix . 'edu_teachers';
			$is_teacher  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tt WHERE user_id = %d", $tutor_user_id ) );
			$bound       = (int) get_user_meta( $tutor_user_id, 'edu_institution_id', true );
			if ( ! $is_teacher || ( ! Edu_Context::is_superadmin_editorial() && $bound !== $institution_id ) ) {
				self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'invalid_tutor' ) );
			}
		} else {
			$tutor_user_id = 0;
		}

		if ( '' === $name ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'name_required' ) );
		}
		if ( ! in_array( $paralelo, array( 'A', 'B', 'C', 'D' ), true ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'paralelo_required' ) );
		}
		if ( ! in_array( $level, self::$valid_levels, true ) || ! in_array( $sub_level, self::$valid_sublevels, true ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'invalid_sublevel' ) );
		}
		if ( 'bt' !== $sub_level ) {
			$specialty = '';
		}

		// Duplicado por (institución, subnivel, nombre, paralelo).
		$dup_sql = $wpdb->prepare(
			"SELECT id FROM $t WHERE institution_id = %d AND sub_level = %s AND name = %s AND paralelo = %s",
			$institution_id, $sub_level, $name, $paralelo
		);
		if ( $id ) {
			$dup_sql .= $wpdb->prepare( ' AND id <> %d', $id );
		}
		if ( $wpdb->get_var( $dup_sql ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'duplicate' ) );
		}

		$data = array(
			'institution_id' => $institution_id,
			'level'          => $level,
			'sub_level'      => $sub_level,
			'name'           => $name,
			'paralelo'       => $paralelo,
			'specialty'      => $specialty ?: null,
			'tutor_user_id'  => $tutor_user_id ?: null,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' );

		if ( $id > 0 ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d AND institution_id = %d", $id, $institution_id ) );
			if ( ! $exists ) {
				self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
			}
			$wpdb->update( $t, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$saved_id = $id;
		} else {
			$wpdb->insert( $t, $data, $formats );
			$saved_id = (int) $wpdb->insert_id;
		}

		self::redirect( array( 'action' => 'edit', 'id' => $saved_id, 'status' => 'updated' ) );
	}

	/** Mapa subnivel → nivel (evita que el usuario tenga que indicar ambos). */
	private static $sublevel_to_level = array(
		'inicial_1'    => 'inicial',
		'inicial_2'    => 'inicial',
		'preparatoria' => 'basica',
		'elemental'    => 'basica',
		'media'        => 'basica',
		'superior'     => 'basica',
		'bg'           => 'bachillerato',
		'bt'           => 'bachillerato',
	);

	/**
	 * Descarga la plantilla CSV para importación masiva de grados.
	 */
	public static function handle_download_template(): void {
		check_admin_referer( 'edu_download_grades_template' );
		self::guard();

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="plantilla_grados.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM → Excel detecta UTF-8 automáticamente.

		fputcsv( $out, array( 'nombre_grado', 'subnivel', 'paralelo', 'especialidad' ) );

		// Filas de ejemplo con cada subnivel.
		$ejemplos = array(
			array( 'Inicial 1', 'inicial_1', 'A', '' ),
			array( 'Inicial 2', 'inicial_2', 'A', '' ),
			array( '1ro EGB', 'preparatoria', 'A', '' ),
			array( '2do EGB', 'elemental', 'A', '' ),
			array( '3ro EGB', 'elemental', 'B', '' ),
			array( '4to EGB', 'elemental', 'A', '' ),
			array( '5to EGB', 'media', 'A', '' ),
			array( '6to EGB', 'media', 'B', '' ),
			array( '7mo EGB', 'media', 'A', '' ),
			array( '8vo EGB', 'superior', 'A', '' ),
			array( '9no EGB', 'superior', 'B', '' ),
			array( '10mo EGB', 'superior', 'A', '' ),
			array( '1ro BG', 'bg', 'A', '' ),
			array( '2do BG', 'bg', 'B', '' ),
			array( '3ro BG', 'bg', 'A', '' ),
			array( '1ro BT', 'bt', 'A', 'Informática' ),
			array( '2do BT', 'bt', 'A', 'Contabilidad' ),
			array( '3ro BT', 'bt', 'A', 'Electrónica' ),
		);
		foreach ( $ejemplos as $fila ) {
			fputcsv( $out, $fila );
		}

		// Hoja de referencia de valores válidos al final del CSV.
		fputcsv( $out, array() );
		fputcsv( $out, array( '--- VALORES VÁLIDOS ---', '', '', '' ) );
		fputcsv( $out, array( 'subnivel', 'inicial_1 · inicial_2 · preparatoria · elemental · media · superior · bg · bt', '', '' ) );
		fputcsv( $out, array( 'paralelo', 'A · B · C · D', '', '' ) );
		fputcsv( $out, array( 'especialidad', 'Solo obligatorio si subnivel = bt. Dejar vacío para los demás.', '', '' ) );

		fclose( $out );
		exit;
	}

	/**
	 * Procesa el CSV subido e importa los grados/paralelos.
	 * Guarda el resultado en un transient para mostrarlo en la vista.
	 */
	public static function handle_import_csv(): void {
		check_admin_referer( 'edu_import_grades_csv' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();

		if ( empty( $_FILES['grades_csv']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['grades_csv']['error'] ) {
			self::save_import_result( array( 'error_fatal' => __( 'No se recibió ningún archivo.', 'sistema-educativo' ) ) );
			self::redirect( array( 'status' => 'imported' ) );
		}

		$ext = strtolower( pathinfo( sanitize_file_name( $_FILES['grades_csv']['name'] ), PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			self::save_import_result( array( 'error_fatal' => __( 'Solo se aceptan archivos .csv.', 'sistema-educativo' ) ) );
			self::redirect( array( 'status' => 'imported' ) );
		}

		global $wpdb;
		$t = $wpdb->prefix . 'edu_grades';

		$inserted = 0;
		$skipped  = 0;
		$errors   = array();
		$row_num  = 0;

		$handle = fopen( $_FILES['grades_csv']['tmp_name'], 'r' );

		// Quitar BOM si está presente.
		$bom = fread( $handle, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}

		fgetcsv( $handle ); // Saltar cabecera.

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$row_num++;

			// Ignorar filas de referencia o vacías.
			$primera = trim( $row[0] ?? '' );
			if ( '' === $primera || str_starts_with( $primera, '---' ) || str_starts_with( $primera, 'subnivel' ) || str_starts_with( $primera, 'paralelo' ) || str_starts_with( $primera, 'especialidad' ) ) {
				continue;
			}

			if ( count( $row ) < 3 ) {
				$errors[] = sprintf( __( 'Fila %d: faltan columnas (se necesitan al menos 3).', 'sistema-educativo' ), $row_num );
				continue;
			}

			$name      = sanitize_text_field( trim( $row[0] ) );
			$sub_level = sanitize_key( strtolower( trim( $row[1] ) ) );
			$paralelo  = strtoupper( sanitize_text_field( trim( $row[2] ) ) );
			$specialty = isset( $row[3] ) ? sanitize_text_field( trim( $row[3] ) ) : '';

			if ( '' === $name ) {
				$errors[] = sprintf( __( 'Fila %d: nombre_grado vacío.', 'sistema-educativo' ), $row_num );
				continue;
			}
			if ( ! isset( self::$sublevel_to_level[ $sub_level ] ) ) {
				$errors[] = sprintf(
					/* translators: 1: fila, 2: valor inválido */
					__( 'Fila %1$d: subnivel "%2$s" no reconocido.', 'sistema-educativo' ),
					$row_num, $sub_level
				);
				continue;
			}
			if ( ! in_array( $paralelo, array( 'A', 'B', 'C', 'D' ), true ) ) {
				$errors[] = sprintf(
					__( 'Fila %1$d: paralelo "%2$s" inválido (use A, B, C o D).', 'sistema-educativo' ),
					$row_num, $paralelo
				);
				continue;
			}

			$level = self::$sublevel_to_level[ $sub_level ];
			if ( 'bt' !== $sub_level ) {
				$specialty = '';
			}

			// Verificar duplicado.
			$existe = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $t WHERE institution_id = %d AND sub_level = %s AND name = %s AND paralelo = %s",
				$institution_id, $sub_level, $name, $paralelo
			) );
			if ( $existe ) {
				$skipped++;
				continue;
			}

			$wpdb->insert(
				$t,
				array(
					'institution_id' => $institution_id,
					'level'          => $level,
					'sub_level'      => $sub_level,
					'name'           => $name,
					'paralelo'       => $paralelo,
					'specialty'      => $specialty ?: null,
					'tutor_user_id'  => null,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
			$inserted++;
		}
		fclose( $handle );

		self::save_import_result( array(
			'inserted' => $inserted,
			'skipped'  => $skipped,
			'errors'   => $errors,
		) );
		self::redirect( array( 'status' => 'imported' ) );
	}

	private static function save_import_result( array $data ): void {
		set_transient( 'edu_import_grades_' . get_current_user_id(), $data, 60 );
	}

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_grade' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$t = $wpdb->prefix . 'edu_grades';

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d AND institution_id = %d", $id, $institution_id ) );
		if ( ! $exists ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}
		// El pensum del grado se borra a mano: dbDelta() descarta las FOREIGN
		// KEY, así que el ON DELETE CASCADE del esquema no existe en la base.
		$wpdb->delete( $wpdb->prefix . 'edu_grade_subjects', array( 'grade_id' => $id ), array( '%d' ) );
		$wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );

		self::redirect( array( 'status' => 'deleted' ) );
	}

	private static function guard() {
		if ( ! Edu_Context::can( 'edu_manage_grades' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}
		if ( ! Edu_Context::current_institution_id() ) {
			wp_die( esc_html__( 'No hay institución activa.', 'sistema-educativo' ) );
		}
	}

	private static function redirect( $args = array() ) {
		$args = array_merge( array( 'page' => 'edu-grados' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
