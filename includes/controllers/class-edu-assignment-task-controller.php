<?php
/**
 * Adaptador HTTP: tareas y sus adjuntos.
 *
 * La lógica vive en Edu_Assignment_Service y el almacenamiento de archivos en
 * Edu_File_Service. Aquí solo se verifica el nonce, se traduce $_POST/$_FILES
 * y se redirige o se sirve la descarga.
 *
 * Se conservan las constantes y varios métodos públicos como envoltorios
 * delegantes porque los usan el activator, las vistas y los portales.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Assignment_Task_Controller {

	/** @deprecated Usar Edu_File_Service::UPLOAD_SUBDIR. */
	const UPLOAD_SUBDIR = Edu_File_Service::UPLOAD_SUBDIR;

	/** @deprecated Usar Edu_File_Service::MAX_FILE_SIZE. */
	const MAX_FILE_SIZE = Edu_File_Service::MAX_FILE_SIZE;

	/** @deprecated Usar Edu_File_Service::ALLOWED_TYPES. */
	const ALLOWED_TYPES = Edu_File_Service::ALLOWED_TYPES;

	/** @deprecated Usar Edu_Assignment_Service::VALID_TYPES. */
	const VALID_TYPES = Edu_Assignment_Service::VALID_TYPES;

	/* ─────────────────────────────────────────────────────────────────────
	 * Guardar
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_save() {
		check_admin_referer( 'edu_save_assignment_task' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$result = Edu_Assignment_Service::save(
			array(
				'id'                   => $id,
				'grade_id'             => isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0,
				'subject_id'           => isset( $_POST['subject_id'] ) ? (int) $_POST['subject_id'] : 0,
				'trimester_id'         => isset( $_POST['trimester_id'] ) ? (int) $_POST['trimester_id'] : 0,
				'parcial_num'          => isset( $_POST['parcial_num'] ) ? (int) $_POST['parcial_num'] : 1,
				'title'                => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio sanea.
				'description'          => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio aplica wp_kses_post().
				'due_date'             => isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '',
				'max_score'            => isset( $_POST['max_score'] ) ? (float) $_POST['max_score'] : 10.00,
				'notify_parents'       => ! empty( $_POST['notify_parents'] ),
				'publish_now'          => ! empty( $_POST['publish_now'] ),
				'type'                 => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '',
				'component_id'         => isset( $_POST['component_id'] ) ? (int) $_POST['component_id'] : 0,
				'component_new_name'   => isset( $_POST['component_new_name'] ) && is_scalar( $_POST['component_new_name'] )
					? wp_unslash( (string) $_POST['component_new_name'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio sanea.
					: '',
				'component_new_weight' => isset( $_POST['component_new_weight'] ) && is_scalar( $_POST['component_new_weight'] )
					? (float) str_replace( ',', '.', (string) $_POST['component_new_weight'] )
					: 1.00,
				'files'                => isset( $_FILES['adjuntos'] ) ? $_FILES['adjuntos'] : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Edu_File_Service valida tamaño, extensión y origen.
				'delete_files'         => isset( $_POST['delete_files'] ) ? array_map( 'intval', (array) $_POST['delete_files'] ) : array(),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result, $id );
		}

		self::redirect(
			array(
				'action' => 'edit',
				'id'     => $result['id'],
				'status' => 'updated',
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Cambios de estado
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_publish() {
		check_admin_referer( 'edu_publish_assignment_task' );

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$result = Edu_Assignment_Service::publish( $id );

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result, $id, true );
		}

		self::redirect(
			array(
				'action' => 'edit',
				'id'     => $id,
				'status' => 'published',
			)
		);
	}

	public static function handle_close() {
		check_admin_referer( 'edu_close_assignment_task' );

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$result = Edu_Assignment_Service::close( $id );

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result, $id, true );
		}

		self::redirect(
			array(
				'action' => 'edit',
				'id'     => $id,
				'status' => 'closed',
			)
		);
	}

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_assignment_task' );

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$result = Edu_Assignment_Service::delete( $id );

		if ( is_wp_error( $result ) ) {
			self::handle_error( $result, $id, true );
		}

		self::redirect( array( 'status' => 'deleted' ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Descarga de adjuntos (enlace firmado con nonce)
	 * ────────────────────────────────────────────────────────────────── */

	public static function handle_download_file() {
		$file_id = isset( $_GET['file_id'] ) ? (int) $_GET['file_id'] : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'edu_download_file_' . $file_id ) ) {
			wp_die( esc_html__( 'Enlace inválido o expirado.', 'sistema-educativo' ) );
		}

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$taf = $wpdb->prefix . 'edu_assignment_files';

		$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $taf WHERE id = %d", $file_id ) );
		if ( ! $file ) {
			wp_die( esc_html__( 'Archivo no encontrado.', 'sistema-educativo' ) );
		}

		// El nonce solo firma el enlace; la autorización se comprueba aparte.
		$assignment = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}edu_assignments WHERE id = %d", (int) $file->assignment_id )
		);

		if ( ! $assignment || ! Edu_Assignment_Service::can_access( $assignment ) ) {
			wp_die( esc_html__( 'Sin permiso para descargar este archivo.', 'sistema-educativo' ) );
		}

		$path = Edu_File_Service::url_to_path( $file->file_url );
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Archivo no disponible.', 'sistema-educativo' ) );
		}

		Edu_File_Service::stream( $path, $file->file_name );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Compatibilidad — lo usan el activator, las vistas y los portales
	 * ────────────────────────────────────────────────────────────────── */

	/** @deprecated Usar Edu_File_Service::ensure_protected_dir(). */
	public static function ensure_protected_dir(): string {
		return Edu_File_Service::ensure_protected_dir();
	}

	/** @deprecated Usar Edu_File_Service::download_url(). */
	public static function get_download_url( $file_id ) {
		return Edu_File_Service::download_url( $file_id, 'edu_download_file', 'file_id' );
	}

	/** @deprecated Usar Edu_Assignment_Service::derive_type(). */
	public static function derive_type( string $texto, string $fallback = 'tarea' ): string {
		return Edu_Assignment_Service::derive_type( $texto, $fallback );
	}

	/** @deprecated Usar Edu_Assignment_Service::attach_files(). */
	public static function handle_file_uploads( $assignment_id, $files_array, $table = '' ) {
		unset( $table );
		return Edu_Assignment_Service::attach_files( $assignment_id, $files_array );
	}

	/* ─── Helpers de transporte ─────────────────────────────────────────── */

	/**
	 * @param WP_Error $error     Error del servicio.
	 * @param int      $id        Tarea en edición.
	 * @param bool     $bare      Si true, el error vuelve al listado sin contexto.
	 */
	private static function handle_error( WP_Error $error, $id = 0, $bare = false ) {
		$code = $error->get_error_code();

		if ( in_array( $code, array( 'forbidden', 'no_institution' ), true ) ) {
			wp_die( esc_html( $error->get_error_message() ) );
		}

		$args = array(
			'status' => 'error',
			'code'   => $code,
		);

		// Los errores del formulario vuelven al formulario; los de estado, al listado.
		if ( ! $bare && in_array( $code, array( 'title_required', 'already_closed' ), true ) ) {
			$args['action'] = $id ? 'edit' : 'new';
			$args['id']     = $id;
		}

		self::redirect( $args );
	}

	private static function redirect( $args = array() ) {
		if ( ! empty( $_POST['_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce ya se verificó en el handler.
			$base = esc_url_raw( wp_unslash( $_POST['_redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fe   = array(
				'edu_tab'    => 'tareas',
				'edu_status' => $args['status'] ?? '',
			);
			if ( ! empty( $args['id'] ) ) {
				$fe['edu_tarea_id'] = $args['id'];
			}
			wp_safe_redirect( add_query_arg( $fe, $base ) );
			exit;
		}

		$args = array_merge( array( 'page' => 'edu-tareas' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
