<?php
/**
 * Controller: ABM de representantes (wp_edu_parents) + vínculos con estudiantes
 * (wp_edu_parent_student).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Parent_Controller {

	private static $valid_relationships = array( 'padre', 'madre', 'tutor', 'otro' );

	public static function handle_save() {
		check_admin_referer( 'edu_save_parent' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$tp  = $wpdb->prefix . 'edu_parents';
		$tps = $wpdb->prefix . 'edu_parent_student';
		$ts  = $wpdb->prefix . 'edu_students';
		$tg  = $wpdb->prefix . 'edu_grades';

		$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$cedula     = sanitize_text_field( wp_unslash( $_POST['cedula'] ?? '' ) );
		$phone      = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$whatsapp   = sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) );
		$occupation = sanitize_text_field( wp_unslash( $_POST['occupation'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'email_invalid' ) );
		}
		if ( '' === $first_name || '' === $last_name ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'name_required' ) );
		}
		if ( $cedula ) {
			$dup_sql = $wpdb->prepare( "SELECT id FROM $tp WHERE cedula = %s", $cedula );
			if ( $id ) {
				$dup_sql .= $wpdb->prepare( ' AND id <> %d', $id );
			}
			if ( $wpdb->get_var( $dup_sql ) ) {
				self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'cedula_duplicate' ) );
			}
		}

		if ( $id > 0 ) {
			$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tp WHERE id = %d", $id ) );
			if ( ! $parent ) {
				self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
			}
			if ( ! self::user_belongs_to_institution( $parent->user_id, $institution_id ) ) {
				wp_die( esc_html__( 'Sin permiso para editar este representante.', 'sistema-educativo' ) );
			}
			$user_id = (int) $parent->user_id;

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
				$existing->add_role( 'edu_padre' );
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
					'role'         => 'edu_padre',
				) );
				if ( is_wp_error( $user_id ) ) {
					self::redirect( array( 'action' => 'new', 'status' => 'error', 'code' => 'user_create_failed' ) );
				}
			}

			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE user_id = %d", $user_id ) ) ) {
				self::redirect( array( 'action' => 'new', 'status' => 'error', 'code' => 'already_parent' ) );
			}
		}

		update_user_meta( $user_id, 'edu_institution_id', $institution_id );

		$data    = array(
			'user_id'    => $user_id,
			'cedula'     => $cedula ?: null,
			'phone'      => $phone ?: null,
			'whatsapp'   => $whatsapp ?: null,
			'occupation' => $occupation ?: null,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s' );

		if ( $id > 0 ) {
			$wpdb->update( $tp, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$parent_id = $id;
		} else {
			$wpdb->insert( $tp, $data, $formats );
			$parent_id = (int) $wpdb->insert_id;
		}

		// Reemplazar vínculos parent_student.
		$wpdb->delete( $tps, array( 'parent_id' => $parent_id ), array( '%d' ) );

		$student_ids   = isset( $_POST['student_ids'] ) ? (array) $_POST['student_ids'] : array();
		$relationships = isset( $_POST['relationships'] ) ? (array) $_POST['relationships'] : array();
		$primary_flags = isset( $_POST['is_primary'] ) ? (array) $_POST['is_primary'] : array();
		$seen_students = array();

		foreach ( $student_ids as $idx => $sid_raw ) {
			$sid = (int) $sid_raw;
			if ( ! $sid || in_array( $sid, $seen_students, true ) ) {
				continue;
			}
			// Validar que el estudiante pertenece a la institución actual (vía grade).
			$grade_inst = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT g.institution_id FROM $ts s INNER JOIN $tg g ON g.id = s.grade_id WHERE s.id = %d",
				$sid
			) );
			if ( ! $grade_inst || ( ! Edu_Context::is_superadmin_editorial() && $grade_inst !== $institution_id ) ) {
				continue;
			}
			$rel = sanitize_key( $relationships[ $idx ] ?? '' );
			if ( ! in_array( $rel, self::$valid_relationships, true ) ) {
				$rel = 'otro';
			}
			$is_primary = ! empty( $primary_flags[ $idx ] ) ? 1 : 0;

			$wpdb->insert(
				$tps,
				array(
					'parent_id'    => $parent_id,
					'student_id'   => $sid,
					'relationship' => $rel,
					'is_primary'   => $is_primary,
				),
				array( '%d', '%d', '%s', '%d' )
			);
			$seen_students[] = $sid;
		}

		self::redirect( array( 'action' => 'edit', 'id' => $parent_id, 'status' => 'updated' ) );
	}

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_parent' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$delete_wp_user = ! empty( $_POST['delete_wp_user'] );

		global $wpdb;
		$tp  = $wpdb->prefix . 'edu_parents';
		$tps = $wpdb->prefix . 'edu_parent_student';

		$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tp WHERE id = %d", $id ) );
		if ( ! $parent ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}
		if ( ! self::user_belongs_to_institution( $parent->user_id, $institution_id ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$user = get_user_by( 'id', $parent->user_id );
		if ( $user ) {
			$user->remove_role( 'edu_padre' );
		}
		delete_user_meta( $parent->user_id, 'edu_institution_id' );

		$wpdb->delete( $tps, array( 'parent_id' => $id ), array( '%d' ) );
		$wpdb->delete( $tp, array( 'id' => $id ), array( '%d' ) );

		if ( $delete_wp_user ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $parent->user_id );
		}

		self::redirect( array( 'status' => 'deleted' ) );
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
		$username = $base ?: 'representante';
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i++;
		}
		return $username;
	}

	public static function handle_download_template(): void {
		check_admin_referer( 'edu_download_parents_template' );
		self::guard();

		global $wpdb;
		$institution_id = Edu_Context::current_institution_id();
		$inst_name      = $wpdb->get_var( $wpdb->prepare(
			"SELECT name FROM {$wpdb->prefix}edu_institutions WHERE id = %d",
			$institution_id
		) );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="plantilla_representantes.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( '# Plantilla importación de representantes' ) );
		fputcsv( $out, array( '# Institución: ' . ( $inst_name ?? '' ) ) );
		fputcsv( $out, array( '# Contraseña inicial: cédula del representante (el usuario debe cambiarla después)' ) );
		fputcsv( $out, array() );
		fputcsv( $out, array( 'cedula', 'nombres', 'apellidos', 'email', 'telefono', 'whatsapp', 'cedula_estudiante', 'parentesco' ) );

		fputcsv( $out, array( '0987654321', 'María Elena', 'González López', 'mgonzalez@ejemplo.com', '0987654321', '+593987654321', '1712345678', 'madre' ) );
		fputcsv( $out, array( '0912345678', 'Carlos Alberto', 'Pérez Mora', 'cperez@ejemplo.com', '0912345678', '+593912345678', '1798765432', 'padre' ) );

		fputcsv( $out, array() );
		fputcsv( $out, array( '--- NOTAS ---', '', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'cedula', 'Obligatorio. Se usa como login y contraseña inicial.', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'email', 'Opcional. Si está vacío se genera uno automático.', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'cedula_estudiante', 'Obligatorio. Cédula del estudiante ya registrado para vincularlo.', '', '', '', '', '', '' ) );
		fputcsv( $out, array( 'parentesco', 'padre · madre · tutor · otro (si está vacío se usa "padre")', '', '', '', '', '', '' ) );

		fclose( $out );
		exit;
	}

	public static function handle_import_csv(): void {
		check_admin_referer( 'edu_import_parents_csv' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();

		if ( empty( $_FILES['parents_csv']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['parents_csv']['error'] ) {
			Edu_Import_Helper::guardar_resultado( 'parents', array( 'error_fatal' => __( 'No se recibió ningún archivo.', 'sistema-educativo' ) ) );
			self::redirect( array( 'status' => 'imported' ) );
		}

		$ext = strtolower( pathinfo( sanitize_file_name( $_FILES['parents_csv']['name'] ), PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			Edu_Import_Helper::guardar_resultado( 'parents', array( 'error_fatal' => __( 'Solo se aceptan archivos .csv.', 'sistema-educativo' ) ) );
			self::redirect( array( 'status' => 'imported' ) );
		}

		global $wpdb;
		$tp  = $wpdb->prefix . 'edu_parents';
		$tps = $wpdb->prefix . 'edu_parent_student';
		$ts  = $wpdb->prefix . 'edu_students';

		$valid_relationships = array( 'padre', 'madre', 'tutor', 'otro' );

		$inserted   = 0;
		$skipped    = 0;
		$vinculados = 0;
		$errors     = array();
		$row_num    = 0;

		$handle = Edu_Import_Helper::abrir_csv( $_FILES['parents_csv']['tmp_name'] );
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

			$cedula            = sanitize_text_field( trim( $row[0] ) );
			$nombres           = sanitize_text_field( trim( $row[1] ) );
			$apellidos         = sanitize_text_field( trim( $row[2] ) );
			$email             = sanitize_email( trim( $row[3] ) );
			$telefono          = sanitize_text_field( trim( $row[4] ) );
			$whatsapp_val      = sanitize_text_field( trim( $row[5] ) );
			$cedula_estudiante = sanitize_text_field( trim( $row[6] ) );
			$parentesco        = isset( $row[7] ) ? sanitize_key( strtolower( trim( $row[7] ) ) ) : '';

			if ( '' === $cedula ) {
				$errors[] = sprintf( __( 'Fila %d: cédula vacía.', 'sistema-educativo' ), $row_num );
				continue;
			}
			if ( '' === $nombres || '' === $apellidos ) {
				$errors[] = sprintf( __( 'Fila %d: nombres o apellidos vacíos.', 'sistema-educativo' ), $row_num );
				continue;
			}
			if ( '' === $cedula_estudiante ) {
				$errors[] = sprintf( __( 'Fila %d: cedula_estudiante es obligatoria.', 'sistema-educativo' ), $row_num );
				continue;
			}
			if ( ! in_array( $parentesco, $valid_relationships, true ) ) {
				$parentesco = 'padre';
			}

			// Resolver estudiante por cédula.
			$student_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ts WHERE cedula = %s", $cedula_estudiante ) );
			if ( ! $student_id ) {
				$errors[] = sprintf(
					/* translators: 1: fila, 2: cédula */
					__( 'Fila %1$d: no se encontró estudiante con cédula "%2$s".', 'sistema-educativo' ),
					$row_num, $cedula_estudiante
				);
				continue;
			}

			// Verificar si ya existe representante con esa cédula.
			$parent_row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $tp WHERE cedula = %s", $cedula ) );

			if ( $parent_row ) {
				$parent_id = (int) $parent_row->id;
				$skipped++;
			} else {
				$user_id = Edu_Import_Helper::crear_usuario( $cedula, $nombres, $apellidos, $email, 'edu_padre' );
				if ( is_wp_error( $user_id ) ) {
					$errors[] = sprintf( __( 'Fila %d: %s', 'sistema-educativo' ), $row_num, $user_id->get_error_message() );
					continue;
				}
				update_user_meta( $user_id, 'edu_institution_id', $institution_id );

				$existing_parent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE user_id = %d", $user_id ) );
				if ( $existing_parent ) {
					$parent_id = $existing_parent;
					$skipped++;
				} else {
					$wpdb->insert(
						$tp,
						array(
							'user_id'    => $user_id,
							'cedula'     => $cedula,
							'phone'      => $telefono ?: null,
							'whatsapp'   => $whatsapp_val ?: null,
							'occupation' => null,
						),
						array( '%d', '%s', '%s', '%s', '%s' )
					);
					$parent_id = (int) $wpdb->insert_id;
					$inserted++;
				}
			}

			// Crear vínculo representante-estudiante si no existe.
			$vinculo_existe = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $tps WHERE parent_id = %d AND student_id = %d",
				$parent_id, $student_id
			) );
			if ( ! $vinculo_existe ) {
				$wpdb->insert(
					$tps,
					array(
						'parent_id'    => $parent_id,
						'student_id'   => $student_id,
						'relationship' => $parentesco,
						'is_primary'   => 1,
					),
					array( '%d', '%d', '%s', '%d' )
				);
				$vinculados++;
			}
		}
		fclose( $handle );

		Edu_Import_Helper::guardar_resultado( 'parents', array(
			'inserted'   => $inserted,
			'skipped'    => $skipped,
			'vinculados' => $vinculados,
			'errors'     => $errors,
		) );
		self::redirect( array( 'status' => 'imported' ) );
	}

	private static function guard() {
		if ( ! Edu_Context::can( 'edu_manage_parents' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}
		if ( ! Edu_Context::current_institution_id() ) {
			wp_die( esc_html__( 'No hay institución activa.', 'sistema-educativo' ) );
		}
	}

	private static function redirect( $args = array() ) {
		$args = array_merge( array( 'page' => 'edu-padres' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
