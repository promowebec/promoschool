<?php
/**
 * Controller: ABM de docentes (wp_edu_teachers + WP user con rol edu_docente).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Teacher_Controller {

	public static function handle_save() {
		check_admin_referer( 'edu_save_teacher' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$t = $wpdb->prefix . 'edu_teachers';

		$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$cedula     = sanitize_text_field( wp_unslash( $_POST['cedula'] ?? '' ) );
		$phone      = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$title      = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$hire_date  = sanitize_text_field( wp_unslash( $_POST['hire_date'] ?? '' ) );
		$is_active  = ! empty( $_POST['is_active'] ) ? 1 : 0;

		if ( ! is_email( $email ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'email_invalid' ) );
		}
		if ( '' === $first_name || '' === $last_name ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'name_required' ) );
		}

		if ( $id > 0 ) {
			$teacher = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
			if ( ! $teacher ) {
				self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
			}
			if ( ! self::user_belongs_to_institution( $teacher->user_id, $institution_id ) ) {
				wp_die( esc_html__( 'Sin permiso para editar este docente.', 'sistema-educativo' ) );
			}
			$user_id = (int) $teacher->user_id;

			// Cédula única (excluyendo el propio registro).
			if ( $cedula && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE cedula = %s AND id <> %d", $cedula, $id ) ) ) {
				self::redirect( array( 'action' => 'edit', 'id' => $id, 'status' => 'error', 'code' => 'cedula_duplicate' ) );
			}

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
			// Cédula única.
			if ( $cedula && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE cedula = %s", $cedula ) ) ) {
				self::redirect( array( 'action' => 'new', 'status' => 'error', 'code' => 'cedula_duplicate' ) );
			}

			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				$user_id = (int) $existing->ID;
				$existing->add_role( 'edu_docente' );
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
					'role'         => 'edu_docente',
				) );
				if ( is_wp_error( $user_id ) ) {
					self::redirect( array( 'action' => 'new', 'status' => 'error', 'code' => 'user_create_failed' ) );
				}
			}

			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE user_id = %d", $user_id ) ) ) {
				self::redirect( array( 'action' => 'new', 'status' => 'error', 'code' => 'already_teacher' ) );
			}
		}

		update_user_meta( $user_id, 'edu_institution_id', $institution_id );

		$data = array(
			'user_id'   => $user_id,
			'cedula'    => $cedula ?: null,
			'phone'     => $phone ?: null,
			'title'     => $title ?: null,
			'hire_date' => $hire_date ?: null,
			'is_active' => $is_active,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%d' );

		if ( $id > 0 ) {
			$wpdb->update( $t, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$saved_id = $id;
		} else {
			$wpdb->insert( $t, $data, $formats );
			$saved_id = (int) $wpdb->insert_id;
		}

		self::redirect( array( 'action' => 'edit', 'id' => $saved_id, 'status' => 'updated' ) );
	}

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_teacher' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$delete_wp_user = ! empty( $_POST['delete_wp_user'] );

		global $wpdb;
		$t = $wpdb->prefix . 'edu_teachers';

		$teacher = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
		if ( ! $teacher ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}
		if ( ! self::user_belongs_to_institution( $teacher->user_id, $institution_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$user = get_user_by( 'id', $teacher->user_id );
		if ( $user ) {
			$user->remove_role( 'edu_docente' );
		}
		delete_user_meta( $teacher->user_id, 'edu_institution_id' );

		$wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );

		if ( $delete_wp_user ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $teacher->user_id );
		}

		self::redirect( array( 'status' => 'deleted' ) );
	}

	// -------------------------------------------------------------------------
	// Importación masiva CSV
	// -------------------------------------------------------------------------

	public static function handle_download_template(): void {
		check_admin_referer( 'edu_download_teachers_template' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$inst    = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$p}institutions WHERE id = %d", $institution_id ) );
		$period  = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$p}periods WHERE institution_id = %d AND is_active = 1 LIMIT 1", $institution_id ) );
		$grados  = $wpdb->get_results( $wpdb->prepare( "SELECT name, paralelo FROM {$p}grades WHERE institution_id = %d ORDER BY sub_level, name, paralelo LIMIT 15", $institution_id ) );
		$materias = $wpdb->get_results( "SELECT name FROM {$p}subjects ORDER BY name" );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="plantilla_docentes.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( '# Institución: ' . ( $inst->name ?? '' ) . ' (ID: ' . $institution_id . ')' ) );
		fputcsv( $out, array( '# Período activo: ' . ( $period->name ?? '(ninguno — crea un período activo primero)' ) ) );
		fputcsv( $out, array( '# IMPORTANTE: una fila por asignación. El mismo docente en varias materias/grados = varias filas con la misma cédula.' ) );
		fputcsv( $out, array( '# Contraseña inicial = cédula del docente.' ) );
		fputcsv( $out, array( 'cedula', 'nombres', 'apellidos', 'email', 'telefono', 'titulo', 'grado', 'paralelo', 'materia' ) );

		$gej = ! empty( $grados ) ? $grados[0]->name   : '5to EGB';
		$pej = ! empty( $grados ) ? $grados[0]->paralelo : 'A';
		$mej = ! empty( $materias ) ? $materias[0]->name : 'Matemática';
		$mej2 = count( $materias ) > 1 ? $materias[1]->name : 'Lengua y Literatura';

		fputcsv( $out, array( '1712345678', 'Juan Carlos', 'Pérez López',  'jperez@escuela.edu.ec',  '0991234567', 'Lcdo.', $gej, $pej, $mej ) );
		fputcsv( $out, array( '1712345678', 'Juan Carlos', 'Pérez López',  'jperez@escuela.edu.ec',  '',           '',      $gej, $pej, $mej2 ) );
		fputcsv( $out, array( '1787654321', 'María Elena', 'García Mora',  'mgarcia@escuela.edu.ec', '0987654321', 'Mgtr.', $gej, $pej, $mej ) );

		fputcsv( $out, array() );
		fputcsv( $out, array( '--- GRADOS REGISTRADOS EN ESTA INSTITUCIÓN ---' ) );
		foreach ( $grados as $g ) {
			fputcsv( $out, array( '', $g->name, $g->paralelo ) );
		}
		fputcsv( $out, array( '--- MATERIAS DISPONIBLES (nombres exactos) ---' ) );
		foreach ( $materias as $m ) {
			fputcsv( $out, array( '', $m->name ) );
		}
		fputcsv( $out, array( '--- TÍTULO: Lcdo. · Lcda. · Ing. · Dr. · Dra. · MSc. · PhD. ---' ) );

		fclose( $out );
		exit;
	}

	public static function handle_import_csv(): void {
		check_admin_referer( 'edu_import_teachers_csv' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();

		if ( empty( $_FILES['teachers_csv']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['teachers_csv']['error'] ) {
			Edu_Import_Helper::guardar_resultado( 'teachers', array( 'error_fatal' => __( 'No se recibió ningún archivo.', 'sistema-educativo' ) ) );
			self::redirect( array( 'status' => 'imported' ) );
		}

		$ext = strtolower( pathinfo( sanitize_file_name( $_FILES['teachers_csv']['name'] ), PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			Edu_Import_Helper::guardar_resultado( 'teachers', array( 'error_fatal' => __( 'Solo se aceptan archivos .csv.', 'sistema-educativo' ) ) );
			self::redirect( array( 'status' => 'imported' ) );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$period_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$p}periods WHERE institution_id = %d AND is_active = 1 LIMIT 1",
			$institution_id
		) );

		$inserted     = 0;
		$asignaciones = 0;
		$skipped      = 0;
		$errors       = array();
		$row_num      = 0;
		$cedula_cache = array(); // cedula → teacher_id

		$handle = Edu_Import_Helper::abrir_csv( $_FILES['teachers_csv']['tmp_name'] );
		fgetcsv( $handle ); // cabecera

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$row_num++;
			if ( Edu_Import_Helper::es_fila_meta( $row ) ) {
				continue;
			}
			if ( count( $row ) < 9 ) {
				$errors[] = sprintf( __( 'Fila %d: necesita 9 columnas.', 'sistema-educativo' ), $row_num );
				continue;
			}

			$cedula    = sanitize_text_field( trim( $row[0] ) );
			$nombres   = sanitize_text_field( trim( $row[1] ) );
			$apellidos = sanitize_text_field( trim( $row[2] ) );
			$email     = sanitize_email( trim( $row[3] ) );
			$telefono  = sanitize_text_field( trim( $row[4] ) );
			$titulo    = sanitize_text_field( trim( $row[5] ) );
			$grado_n   = sanitize_text_field( trim( $row[6] ) );
			$paralelo  = strtoupper( sanitize_text_field( trim( $row[7] ) ) );
			$materia_n = sanitize_text_field( trim( $row[8] ) );

			if ( ! $cedula || ! $nombres || ! $apellidos ) {
				$errors[] = sprintf( __( 'Fila %d: cédula, nombres y apellidos son obligatorios.', 'sistema-educativo' ), $row_num );
				continue;
			}

			// Crear o reutilizar docente.
			if ( ! isset( $cedula_cache[ $cedula ] ) ) {
				$existing_t = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$p}teachers WHERE cedula = %s", $cedula ) );

				if ( $existing_t ) {
					$teacher_id = (int) $existing_t->id;
				} else {
					$user_id = Edu_Import_Helper::crear_usuario( $cedula, $nombres, $apellidos, $email, 'edu_docente' );
					if ( is_wp_error( $user_id ) ) {
						$errors[] = sprintf( __( 'Fila %1$d: %2$s', 'sistema-educativo' ), $row_num, $user_id->get_error_message() );
						continue;
					}
					update_user_meta( $user_id, 'edu_institution_id', $institution_id );

					$exists_by_uid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}teachers WHERE user_id = %d", $user_id ) );
					if ( $exists_by_uid ) {
						$teacher_id = $exists_by_uid;
					} else {
						$wpdb->insert(
							"{$p}teachers",
							array(
								'user_id'   => $user_id,
								'cedula'    => $cedula,
								'phone'     => $telefono ?: null,
								'title'     => $titulo ?: null,
								'hire_date' => current_time( 'Y-m-d' ),
								'is_active' => 1,
							),
							array( '%d', '%s', '%s', '%s', '%s', '%d' )
						);
						$teacher_id = (int) $wpdb->insert_id;
						$inserted++;
					}
				}
				$cedula_cache[ $cedula ] = $teacher_id;
			} else {
				$teacher_id = $cedula_cache[ $cedula ];
			}

			// Resolver grade_id.
			$grade_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$p}grades WHERE institution_id = %d AND name = %s AND paralelo = %s",
				$institution_id, $grado_n, $paralelo
			) );
			if ( ! $grade_id ) {
				$errors[] = sprintf( __( 'Fila %1$d: grado "%2$s %3$s" no encontrado.', 'sistema-educativo' ), $row_num, $grado_n, $paralelo );
				$skipped++;
				continue;
			}

			// Resolver subject_id.
			$subject_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$p}subjects WHERE name = %s",
				$materia_n
			) );
			if ( ! $subject_id ) {
				$errors[] = sprintf( __( 'Fila %1$d: materia "%2$s" no encontrada.', 'sistema-educativo' ), $row_num, $materia_n );
				$skipped++;
				continue;
			}

			// Período activo requerido.
			if ( ! $period_id ) {
				$errors[] = sprintf( __( 'Fila %d: sin período activo — asignación no creada.', 'sistema-educativo' ), $row_num );
				$skipped++;
				continue;
			}

			// Crear asignación (omitir duplicado).
			$dup = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$p}teacher_assignments WHERE teacher_id=%d AND grade_id=%d AND subject_id=%d AND period_id=%d",
				$teacher_id, $grade_id, $subject_id, $period_id
			) );
			if ( $dup ) {
				$skipped++;
				continue;
			}

			$wpdb->insert(
				"{$p}teacher_assignments",
				array(
					'teacher_id' => $teacher_id,
					'grade_id'   => $grade_id,
					'subject_id' => $subject_id,
					'period_id'  => $period_id,
					'is_active'  => 1,
				),
				array( '%d', '%d', '%d', '%d', '%d' )
			);
			$asignaciones++;
		}
		fclose( $handle );

		Edu_Import_Helper::guardar_resultado( 'teachers', array(
			'inserted'     => $inserted,
			'asignaciones' => $asignaciones,
			'skipped'      => $skipped,
			'errors'       => $errors,
		) );
		self::redirect( array( 'status' => 'imported' ) );
	}

	private static function user_belongs_to_institution( $user_id, $institution_id ) {
		if ( Edu_Context::is_superadmin_editorial() ) {
			return true;
		}
		$bound = (int) get_user_meta( $user_id, 'edu_institution_id', true );
		return $bound === (int) $institution_id;
	}

	private static function generate_username( $email ) {
		$base     = sanitize_user( strstr( $email, '@', true ), true );
		$username = $base ?: 'docente';
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i++;
		}
		return $username;
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
		$args = array_merge( array( 'page' => 'edu-docentes' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
