<?php
/**
 * Servicio: almacenamiento de archivos privados.
 *
 * Los adjuntos de tareas y las entregas de estudiantes viven en
 * wp-content/uploads/edu-privado/, fuera del alcance directo del navegador, y
 * solo se sirven a través de un handler que verifica la propiedad.
 *
 * Lo usan tanto las tareas como las entregas, de ahí que sea un servicio
 * aparte.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_File_Service {

	/** Subcarpeta dentro de uploads. */
	const UPLOAD_SUBDIR = 'edu-privado';

	/** Tamaño máximo por archivo: 10 MB. */
	const MAX_FILE_SIZE = 10485760;

	/** Extensiones admitidas y su MIME. */
	const ALLOWED_TYPES = array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'ppt'  => 'application/vnd.ms-powerpoint',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'zip'  => 'application/zip',
	);

	/**
	 * Crea, si faltan, el .htaccess "deny all" y el index.php vacío en la raíz
	 * de uploads/edu-privado/.
	 *
	 * En Apache bloquea el acceso directo por URL. En Nginx hace falta además
	 * una regla en el server block:
	 *   location ^~ /wp-content/uploads/edu-privado/ { deny all; }
	 *
	 * @return string Ruta base del directorio protegido.
	 */
	public static function ensure_protected_dir() {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR . '/';

		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}

		if ( ! file_exists( $base . '.htaccess' ) ) {
			@file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
				$base . '.htaccess',
				"# Archivos privados del sistema educativo — solo vía handler PHP.\n" .
				"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n" .
				"<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
			);
		}

		if ( ! file_exists( $base . 'index.php' ) ) {
			@file_put_contents( $base . 'index.php', "<?php\n// Silencio es salud.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}

		return $base;
	}

	/**
	 * Guarda los archivos subidos y devuelve las filas a insertar.
	 *
	 * Se salta en silencio lo que no cumpla (igual que antes): error de subida,
	 * exceso de tamaño, extensión no admitida o fallo al mover.
	 *
	 * @param string $folder      Subcarpeta destino: 'assignments/12' o 'submissions/34'.
	 * @param array  $files_array Estructura de $_FILES para un campo múltiple.
	 * @return array Lista de array( file_url, file_name, file_type, file_size ).
	 */
	public static function store_uploads( $folder, array $files_array ) {
		$upload_dir = wp_upload_dir();
		self::ensure_protected_dir();

		$target_dir = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR . '/' . trim( $folder, '/' ) . '/';

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		$stored = array();
		$count  = isset( $files_array['name'] ) && is_array( $files_array['name'] ) ? count( $files_array['name'] ) : 0;

		for ( $i = 0; $i < $count; $i++ ) {
			if ( UPLOAD_ERR_OK !== $files_array['error'][ $i ] ) {
				continue;
			}

			if ( $files_array['size'][ $i ] > self::MAX_FILE_SIZE ) {
				continue;
			}

			$orig_name = sanitize_file_name( $files_array['name'][ $i ] );
			$ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );

			if ( ! array_key_exists( $ext, self::ALLOWED_TYPES ) ) {
				continue;
			}

			$unique_name = wp_unique_filename( $target_dir, $orig_name );
			$target_path = $target_dir . $unique_name;

			if ( ! move_uploaded_file( $files_array['tmp_name'][ $i ], $target_path ) ) {
				continue;
			}

			$stored[] = array(
				'file_url'  => trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR . '/' . trim( $folder, '/' ) . '/' . $unique_name,
				'file_name' => $orig_name,
				'file_type' => $ext,
				'file_size' => (int) $files_array['size'][ $i ],
			);
		}

		return $stored;
	}

	/**
	 * Ruta física a partir de la URL guardada.
	 *
	 * @param string $url URL almacenada.
	 * @return string
	 */
	public static function url_to_path( $url ) {
		$upload_dir = wp_upload_dir();

		return str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], (string) $url );
	}

	/**
	 * Borra el archivo físico de una fila ya leída.
	 *
	 * @param string $url URL almacenada.
	 * @return bool
	 */
	public static function delete_physical( $url ) {
		$path = self::url_to_path( $url );

		if ( $path && file_exists( $path ) ) {
			return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		return false;
	}

	/**
	 * Enlace de descarga firmado con nonce, para wp-admin y los portales.
	 *
	 * @param int    $file_id ID del archivo.
	 * @param string $action  Acción de admin-post ('edu_download_file' o 'edu_download_sub_file').
	 * @param string $param   Nombre del parámetro del ID.
	 * @return string
	 */
	public static function download_url( $file_id, $action = 'edu_download_file', $param = 'file_id' ) {
		return add_query_arg(
			array(
				'action'   => $action,
				$param     => (int) $file_id,
				'_wpnonce' => wp_create_nonce( $action . '_' . (int) $file_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * URLs firmadas para descargas desde la app (contrato §10)
	 *
	 * El navegador no puede enviar la cabecera Authorization al abrir una
	 * descarga en una pestaña nueva, así que la API entrega una URL con un
	 * token HMAC de vida corta atado al usuario que la pidió.
	 * ────────────────────────────────────────────────────────────────── */

	/** Vida de una URL firmada, en segundos. */
	const SIGNED_TTL = 300;

	/**
	 * Emite una URL firmada para un recurso binario.
	 *
	 * @param string $kind    Tipo de recurso: 'boletin', 'boletines-zip', 'mineduc', 'attachment'.
	 * @param array  $payload Parámetros del recurso (IDs ya validados).
	 * @return array{url:string, expires_at:string}
	 */
	public static function signed_url( $kind, array $payload ) {
		$expires = time() + self::SIGNED_TTL;

		$claims = array(
			'kind' => (string) $kind,
			'args' => $payload,
			'uid'  => get_current_user_id(),
			'exp'  => $expires,
		);

		$data  = Edu_Api_Jwt::b64url_encode( (string) wp_json_encode( $claims ) );
		$token = $data . '.' . Edu_Api_Jwt::b64url_encode( self::sign_payload( $data ) );

		/*
		 * El nonce viaja en la URL a propósito. El enlace se abre en una
		 * pestaña nueva (window.open), y una navegación normal no puede poner
		 * la cabecera X-WP-Nonce: sin nonce, WordPress descarta la cookie en
		 * REST (rest_cookie_check_errors hace wp_set_current_user( 0 )) y toda
		 * descarga respondía 401 aunque la sesión estuviera abierta.
		 *
		 * Llevarlo aquí mantiene la garantía de que el enlace es personal: sin
		 * la cookie de quien lo pidió el usuario sigue siendo 0 y la
		 * comprobación de uid de verify_signed_url() falla igual.
		 */
		return array(
			'url'        => add_query_arg(
				array(
					'token'    => $token,
					'_wpnonce' => wp_create_nonce( 'wp_rest' ),
				),
				rest_url( Edu_Api::API_NAMESPACE . '/files/download' )
			),
			'expires_at' => Edu_Api::date( $expires ),
		);
	}

	/**
	 * Localiza un adjunto y decide si el usuario actual puede bajarlo.
	 *
	 * Un solo sitio para las dos familias de adjuntos, porque el permiso lo
	 * decide el padre —la tarea o la entrega—, nunca el archivo suelto. Lo usan
	 * tanto la emisión del enlace como la descarga: el alcance del usuario
	 * puede haber cambiado entre una cosa y la otra.
	 *
	 * @param string $type    'assignment' o 'submission'.
	 * @param int    $file_id ID del archivo.
	 * @return array{path:string, file_name:string}|WP_Error
	 */
	public static function locate_attachment( $type, $file_id ) {
		global $wpdb;

		$p       = $wpdb->prefix . 'edu_';
		$type    = (string) $type;
		$file_id = (int) $file_id;

		if ( ! in_array( $type, array( 'assignment', 'submission' ), true ) ) {
			return Edu_Service::error( 'invalid_scope', __( 'Tipo de adjunto desconocido.', 'sistema-educativo' ), 400 );
		}

		if ( 'assignment' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}assignment_files WHERE id = %d", $file_id ) );

			if ( ! $file ) {
				return Edu_Service::not_found( __( 'El archivo no existe.', 'sistema-educativo' ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}assignments WHERE id = %d", (int) $file->assignment_id ) );

			$permitido = $parent && Edu_Assignment_Service::can_access( $parent );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}submission_files WHERE id = %d", $file_id ) );

			if ( ! $file ) {
				return Edu_Service::not_found( __( 'El archivo no existe.', 'sistema-educativo' ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$parent = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT s.student_id, a.id AS assignment_id, a.teacher_id, a.grade_id, a.subject_id
					 FROM {$p}submissions s
					 INNER JOIN {$p}assignments a ON a.id = s.assignment_id
					 WHERE s.id = %d",
					(int) $file->submission_id
				)
			);

			$permitido = $parent && Edu_Submission_Service::can_download( $parent );
		}

		if ( ! $permitido ) {
			return Edu_Service::error( 'forbidden', __( 'Sin permiso para descargar este archivo.', 'sistema-educativo' ), 403 );
		}

		$path = self::url_to_path( $file->file_url );

		if ( ! $path || ! file_exists( $path ) ) {
			return Edu_Service::not_found( __( 'El archivo ya no está disponible.', 'sistema-educativo' ) );
		}

		return array(
			'path'      => $path,
			'file_name' => (string) $file->file_name,
		);
	}

	/**
	 * Emite el enlace de descarga de un adjunto, tras validar el permiso.
	 *
	 * @param string $type    'assignment' o 'submission'.
	 * @param int    $file_id ID del archivo.
	 * @return array|WP_Error
	 */
	public static function attachment_link( $type, $file_id ) {
		$found = self::locate_attachment( $type, $file_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		return self::signed_url(
			'attachment',
			array(
				'type'    => (string) $type,
				'file_id' => (int) $file_id,
			)
		);
	}

	/**
	 * Verifica un token de descarga y devuelve sus claims.
	 *
	 * El token identifica a quien lo pidió: se comprueba que sea el mismo
	 * usuario, de modo que compartir el enlace no sirve de nada.
	 *
	 * @param string $token Token recibido.
	 * @return array|WP_Error
	 */
	public static function verify_signed_url( $token ) {
		$parts = explode( '.', (string) $token );

		if ( 2 !== count( $parts ) ) {
			return Edu_Service::error( 'invalid_token', __( 'Enlace de descarga inválido.', 'sistema-educativo' ), 403 );
		}

		list( $data, $signature ) = $parts;

		$expected = self::sign_payload( $data );
		$given    = Edu_Api_Jwt::b64url_decode( $signature );

		if ( ! is_string( $given ) || ! hash_equals( $expected, $given ) ) {
			return Edu_Service::error( 'invalid_token', __( 'Enlace de descarga inválido.', 'sistema-educativo' ), 403 );
		}

		$claims = json_decode( (string) Edu_Api_Jwt::b64url_decode( $data ), true );

		if ( ! is_array( $claims ) || empty( $claims['exp'] ) ) {
			return Edu_Service::error( 'invalid_token', __( 'Enlace de descarga inválido.', 'sistema-educativo' ), 403 );
		}

		if ( (int) $claims['exp'] < time() ) {
			return Edu_Service::error( 'expired_token', __( 'El enlace de descarga expiró.', 'sistema-educativo' ), 403 );
		}

		if ( (int) $claims['uid'] !== get_current_user_id() ) {
			return Edu_Service::error( 'invalid_token', __( 'Este enlace fue emitido para otra cuenta.', 'sistema-educativo' ), 403 );
		}

		return $claims;
	}

	private static function sign_payload( $data ) {
		return hash_hmac( 'sha256', 'edu-download|' . $data, Edu_Api_Jwt::secret(), true );
	}

	/**
	 * Envía el archivo al navegador. Termina la ejecución.
	 *
	 * @param string $path      Ruta física.
	 * @param string $file_name Nombre visible.
	 */
	public static function stream( $path, $file_name ) {
		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : '';
		$mime = $mime ?: 'application/octet-stream';

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $file_name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
