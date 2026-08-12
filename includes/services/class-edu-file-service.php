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
