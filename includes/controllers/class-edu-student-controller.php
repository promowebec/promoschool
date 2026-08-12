<?php
/**
 * Controller: ABM de estudiantes (wp_edu_students + WP user con rol edu_estudiante).
 *
 * Scoping de institución: indirecto vía grade_id (cada grade pertenece a una institución).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Student_Controller {

	public static function handle_save() {
		check_admin_referer( 'edu_save_student' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$ts = $wpdb->prefix . 'edu_students';
		$tg = $wpdb->prefix . 'edu_grades';

		$email           = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$first_name      = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name       = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$cedula          = sanitize_text_field( wp_unslash( $_POST['cedula'] ?? '' ) );
		$grade_id        = isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0;
		$birth_date      = sanitize_text_field( wp_unslash( $_POST['birth_date'] ?? '' ) );
		$sexo            = strtoupper( sanitize_text_field( wp_unslash( $_POST['sexo'] ?? '' ) ) );
		if ( ! in_array( $sexo, array( 'M', 'F' ), true ) ) {
			$sexo = '';
		}
		$direccion       = sanitize_text_field( wp_unslash( $_POST['direccion'] ?? '' ) );
		$enrollment_date = sanitize_text_field( wp_unslash( $_POST['enrollment_date'] ?? '' ) );
		$photo_url       = esc_url_raw( wp_unslash( $_POST['photo_url'] ?? '' ) );
		$status          = sanitize_key( $_POST['status'] ?? 'active' );
		if ( ! in_array( $status, array( 'active', 'inactive', 'transferred', 'graduated' ), true ) ) {
			$status = 'active';
		}

		if ( ! is_email( $email ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'email_invalid' ) );
		}
		if ( '' === $first_name || '' === $last_name ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'name_required' ) );
		}

		// Validar que el grado existe y pertenece a la institución actual.
		$grade_inst = $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM $tg WHERE id = %d", $grade_id ) );
		if ( ! $grade_inst || (int) $grade_inst !== $institution_id ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'grade_invalid' ) );
		}

		// Cédula única.
		if ( $cedula ) {
			$dup_sql = $wpdb->prepare( "SELECT id FROM $ts WHERE cedula = %s", $cedula );
			if ( $id ) {
				$dup_sql .= $wpdb->prepare( ' AND id <> %d', $id );
			}
			if ( $wpdb->get_var( $dup_sql ) ) {
				self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'cedula_duplicate' ) );
			}
		}

		if ( $id > 0 ) {
			$student = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ts WHERE id = %d", $id ) );
			if ( ! $student ) {
				self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
			}
			if ( ! self::student_belongs_to_institution( $student, $institution_id ) ) {
				wp_die( esc_html__( 'Sin permiso para editar este estudiante.', 'sistema-educativo' ) );
			}
			$user_id = (int) $student->user_id;

			$update = wp_update_user( array(
				'ID'           => $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'user_email'   => $email,
			) );
			if ( is_wp_error( $update ) ) {
				self::redirect( array( 'action' => 'edit', 'id' => $id, 'status' => 'error', 'code' => 'user_update_failed' ) );
			}
		} else {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				$user_id = (int) $existing->ID;
				$existing->add_role( 'edu_estudiante' );
				wp_update_user( array(
					'ID'         => $user_id,
					'first_name' => $first_name,
					'last_name'  => $last_name,
				) );
			} else {
				$user_id = wp_insert_user( array(
					'user_login'   => self::generate_username( $email ),
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 16, true, true ),
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'display_name' => trim( $first_name . ' ' . $last_name ),
					'role'         => 'edu_estudiante',
				) );
				if ( is_wp_error( $user_id ) ) {
					self::redirect( array( 'action' => 'new', 'status' => 'error', 'code' => 'user_create_failed' ) );
				}
			}

			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ts WHERE user_id = %d", $user_id ) ) ) {
				self::redirect( array( 'action' => 'new', 'status' => 'error', 'code' => 'already_student' ) );
			}
		}

		$data = array(
			'user_id'         => $user_id,
			'grade_id'        => $grade_id,
			'cedula'          => $cedula ?: null,
			'birth_date'      => $birth_date ?: null,
			'sexo'            => $sexo ?: null,
			'direccion'       => $direccion ?: null,
			'enrollment_date' => $enrollment_date ?: null,
			'photo_url'       => $photo_url ?: null,
			'status'          => $status,
		);
		$formats = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $id > 0 ) {
			$wpdb->update( $ts, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$saved_id = $id;
		} else {
			$wpdb->insert( $ts, $data, $formats );
			$saved_id = (int) $wpdb->insert_id;
		}

		self::redirect( array( 'action' => 'edit', 'id' => $saved_id, 'status' => 'updated' ) );
	}

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_student' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$delete_wp_user = ! empty( $_POST['delete_wp_user'] );

		global $wpdb;
		$ts = $wpdb->prefix . 'edu_students';

		$student = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ts WHERE id = %d", $id ) );
		if ( ! $student ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}
		if ( ! self::student_belongs_to_institution( $student, $institution_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$user = get_user_by( 'id', $student->user_id );
		if ( $user ) {
			$user->remove_role( 'edu_estudiante' );
		}

		$wpdb->delete( $ts, array( 'id' => $id ), array( '%d' ) );

		if ( $delete_wp_user ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $student->user_id );
		}

		self::redirect( array( 'status' => 'deleted' ) );
	}

	private static function student_belongs_to_institution( $student, $institution_id ) {
		if ( Edu_Context::is_superadmin_editorial() ) {
			return true;
		}
		global $wpdb;
		$grade_inst = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT institution_id FROM {$wpdb->prefix}edu_grades WHERE id = %d",
			$student->grade_id
		) );
		return $grade_inst === (int) $institution_id;
	}

	private static function generate_username( $email ) {
		$base     = sanitize_user( strstr( $email, '@', true ), true );
		$username = $base ?: 'estudiante';
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i++;
		}
		return $username;
	}

	public static function handle_download_template(): void {
		check_admin_referer( 'edu_download_students_template' );
		self::guard();

		global $wpdb;
		$institution_id = Edu_Context::current_institution_id();
		$inst_name      = $wpdb->get_var( $wpdb->prepare(
			"SELECT name FROM {$wpdb->prefix}edu_institutions WHERE id = %d",
			$institution_id
		) );
		$tg    = $wpdb->prefix . 'edu_grades';
		$grades = $wpdb->get_results( $wpdb->prepare(
			"SELECT name, paralelo FROM $tg WHERE institution_id = %d ORDER BY sub_level, name, paralelo",
			$institution_id
		) );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="plantilla_estudiantes.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( '# Plantilla importación de estudiantes' ) );
		fputcsv( $out, array( '# Institución: ' . ( $inst_name ?? '' ) ) );
		fputcsv( $out, array( '# Contraseña inicial: cédula del estudiante (el usuario debe cambiarla después)' ) );
		fputcsv( $out, array() );
		fputcsv( $out, array( 'cedula', 'nombres', 'apellidos', 'email', 'fecha_nacimiento', 'grado', 'paralelo', 'cedula_padre', 'sexo', 'direccion' ) );

		$i = 0;
		if ( ! empty( $grades ) ) {
			foreach ( $grades as $g ) {
				if ( $i >= 3 ) {
					break;
				}
				fputcsv( $out, array(
					'17' . str_pad( (string) ( 1001 + $i ), 7, '0', STR_PAD_LEFT ),
					'Nombres' . ( $i + 1 ),
					'Apellidos' . ( $i + 1 ),
					'estudiante' . ( $i + 1 ) . '@ejemplo.com',
					'2010-0' . ( $i + 1 ) . '-15',
					$g->name,
					$g->paralelo,
					'09' . str_pad( (string) ( 8001 + $i ), 7, '0', STR_PAD_LEFT ),
					0 === $i % 2 ? 'M' : 'F',
					'Av. Ejemplo N' . ( $i + 1 ) . '-23 y Calle Uno',
				) );
				$i++;
			}
		}
		if ( 0 === $i ) {
			fputcsv( $out, array( '1712345678', 'Juan Carlos', 'Pérez Gómez', 'jperez@ejemplo.com', '2010-05-15', '1ro BG', 'A', '0987654321', 'M', 'Av. Ejemplo N1-23 y Calle Uno' ) );
		}

		fputcsv( $out, array() );
		fputcsv( $out, array( '--- NOTAS ---', '', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'cedula', 'Obligatorio. Se usa como login y contraseña inicial.', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'email', 'Opcional. Si está vacío se genera uno automático.', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'grado', 'Debe coincidir exactamente con el nombre del grado en el sistema.', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'paralelo', 'A, B, C o D', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'cedula_padre', 'Opcional. Cédula del representante ya registrado para vincularlo.', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'sexo', 'Opcional. M o F. Requerido por la nómina AMIE.', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'direccion', 'Opcional. Domicilio del estudiante. Requerido por la nómina AMIE.', '', '', '', '', '', '' ) );

		if ( ! empty( $grades ) ) {
			fputcsv( $out, array() );
			fputcsv( $out, array( '--- GRADOS DISPONIBLES EN ESTA INSTITUCIÓN ---', '', '', '', '', '', '', '' ) );
			foreach ( $grades as $g ) {
				fputcsv( $out, array( '', '', '', '', '', $g->name, $g->paralelo, '' ) );
			}
		}

		fclose( $out );
		exit;
	}

	public static function handle_import_csv(): void {
		check_admin_referer( 'edu_import_students_csv' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();

		if ( empty( $_FILES['students_csv']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['students_csv']['error'] ) {
			Edu_Import_Helper::guardar_resultado( 'students', array( 'error_fatal' => __( 'No se recibió ningún archivo.', 'sistema-educativo' ) ) );
			self::redirect( array( 'status' => 'imported' ) );
		}

		$ext = strtolower( pathinfo( sanitize_file_name( $_FILES['students_csv']['name'] ), PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			Edu_Import_Helper::guardar_resultado( 'students', array( 'error_fatal' => __( 'Solo se aceptan archivos .csv.', 'sistema-educativo' ) ) );
			self::redirect( array( 'status' => 'imported' ) );
		}

		global $wpdb;
		$ts  = $wpdb->prefix . 'edu_students';
		$tg  = $wpdb->prefix . 'edu_grades';
		$tp  = $wpdb->prefix . 'edu_parents';
		$tps = $wpdb->prefix . 'edu_parent_student';

		$inserted        = 0;
		$skipped         = 0;
		$vinculados      = 0;
		$errors          = array();
		$row_num         = 0;
		$enrollment_date = gmdate( 'Y-m-d' );

		$handle = Edu_Import_Helper::abrir_csv( $_FILES['students_csv']['tmp_name'] );
		fgetcsv( $handle ); // cabecera

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$row_num++;
			if ( Edu_Import_Helper::es_fila_meta( $row ) ) {
				continue;
			}
			if ( count( $row ) < 7 ) {
				$errors[] = sprintf( __( 'Fila %d: faltan columnas (se necesitan al menos 7).', 'sistema-educativo' ), $row_num );
				continue;
			}

			$cedula       = sanitize_text_field( trim( $row[0] ) );
			$nombres      = sanitize_text_field( trim( $row[1] ) );
			$apellidos    = sanitize_text_field( trim( $row[2] ) );
			$email        = sanitize_email( trim( $row[3] ) );
			$fecha_nac    = sanitize_text_field( trim( $row[4] ) );
			$grado_name   = sanitize_text_field( trim( $row[5] ) );
			$paralelo     = strtoupper( sanitize_text_field( trim( $row[6] ) ) );
			$cedula_padre = isset( $row[7] ) ? sanitize_text_field( trim( $row[7] ) ) : '';
			$sexo         = isset( $row[8] ) ? strtoupper( sanitize_text_field( trim( $row[8] ) ) ) : '';
			if ( ! in_array( $sexo, array( 'M', 'F' ), true ) ) {
				$sexo = '';
			}
			$direccion    = isset( $row[9] ) ? sanitize_text_field( trim( $row[9] ) ) : '';

			if ( '' === $cedula ) {
				$errors[] = sprintf( __( 'Fila %d: cédula vacía.', 'sistema-educativo' ), $row_num );
				continue;
			}
			if ( '' === $nombres || '' === $apellidos ) {
				$errors[] = sprintf( __( 'Fila %d: nombres o apellidos vacíos.', 'sistema-educativo' ), $row_num );
				continue;
			}

			$grade_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $tg WHERE institution_id = %d AND name = %s AND paralelo = %s",
				$institution_id, $grado_name, $paralelo
			) );
			if ( ! $grade_id ) {
				$errors[] = sprintf(
					/* translators: 1: fila, 2: grado, 3: paralelo */
					__( 'Fila %1$d: grado "%2$s %3$s" no encontrado en esta institución.', 'sistema-educativo' ),
					$row_num, $grado_name, $paralelo
				);
				continue;
			}

			// Omitir si la cédula ya está registrada como estudiante.
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ts WHERE cedula = %s", $cedula ) ) ) {
				$skipped++;
				continue;
			}

			$user_id = Edu_Import_Helper::crear_usuario( $cedula, $nombres, $apellidos, $email, 'edu_estudiante' );
			if ( is_wp_error( $user_id ) ) {
				$errors[] = sprintf( __( 'Fila %d: %s', 'sistema-educativo' ), $row_num, $user_id->get_error_message() );
				continue;
			}

			// Evitar duplicado si ese user_id ya es estudiante.
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ts WHERE user_id = %d", $user_id ) ) ) {
				$skipped++;
				continue;
			}

			$wpdb->insert(
				$ts,
				array(
					'user_id'         => $user_id,
					'grade_id'        => $grade_id,
					'cedula'          => $cedula,
					'birth_date'      => $fecha_nac ?: null,
					'sexo'            => $sexo ?: null,
					'direccion'       => $direccion ?: null,
					'enrollment_date' => $enrollment_date,
					'status'          => 'active',
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$student_id = (int) $wpdb->insert_id;
			$inserted++;

			// Vincular con representante si se proporcionó cédula.
			if ( $cedula_padre ) {
				$parent = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $tp WHERE cedula = %s", $cedula_padre ) );
				if ( $parent ) {
					$vinculo_existe = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM $tps WHERE parent_id = %d AND student_id = %d",
						(int) $parent->id, $student_id
					) );
					if ( ! $vinculo_existe ) {
						$wpdb->insert(
							$tps,
							array(
								'parent_id'    => (int) $parent->id,
								'student_id'   => $student_id,
								'relationship' => 'padre',
								'is_primary'   => 1,
							),
							array( '%d', '%d', '%s', '%d' )
						);
						$vinculados++;
					}
				}
			}
		}
		fclose( $handle );

		Edu_Import_Helper::guardar_resultado( 'students', array(
			'inserted'   => $inserted,
			'skipped'    => $skipped,
			'vinculados' => $vinculados,
			'errors'     => $errors,
		) );
		self::redirect( array( 'status' => 'imported' ) );
	}

	private static function guard() {
		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}
		if ( ! Edu_Context::current_institution_id() ) {
			wp_die( esc_html__( 'No hay institución activa.', 'sistema-educativo' ) );
		}
	}

	private static function redirect( $args = array() ) {
		$args = array_merge( array( 'page' => 'edu-estudiantes' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
