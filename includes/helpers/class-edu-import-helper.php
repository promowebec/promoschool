<?php
/**
 * Utilidades compartidas para importación masiva vía CSV.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Import_Helper {

	/**
	 * Abre un CSV y quita el BOM UTF-8 si está presente.
	 *
	 * @return resource
	 */
	public static function abrir_csv( string $path ) {
		$h   = fopen( $path, 'r' );
		$bom = fread( $h, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $h );
		}
		return $h;
	}

	/**
	 * Devuelve true si la fila es un comentario, línea de referencia o está vacía.
	 */
	public static function es_fila_meta( array $row ): bool {
		$f = trim( $row[0] ?? '' );
		return '' === $f
			|| str_starts_with( $f, '#' )
			|| str_starts_with( $f, '---' );
	}

	/**
	 * Crea o reutiliza un WP user identificado por cédula.
	 *
	 * - user_login = cédula
	 * - user_pass  = cédula  (contraseña inicial; el usuario debe cambiarla)
	 * - Si ya existe un user_login igual → reutiliza y añade rol.
	 * - Si el email existe → reutiliza y añade rol.
	 * - Si no existe → crea nuevo usuario.
	 *
	 * @return int|WP_Error  user_id en éxito, WP_Error en error.
	 */
	public static function crear_usuario(
		string $cedula,
		string $nombres,
		string $apellidos,
		string $email,
		string $rol
	): int|WP_Error {
		$display = trim( $nombres . ' ' . $apellidos );

		$existing = get_user_by( 'login', $cedula );
		if ( $existing ) {
			$existing->add_role( $rol );
			wp_update_user( array(
				'ID'           => $existing->ID,
				'first_name'   => $nombres,
				'last_name'    => $apellidos,
				'display_name' => $display,
			) );
			return $existing->ID;
		}

		if ( $email ) {
			$by_email = get_user_by( 'email', $email );
			if ( $by_email ) {
				$by_email->add_role( $rol );
				wp_update_user( array(
					'ID'           => $by_email->ID,
					'first_name'   => $nombres,
					'last_name'    => $apellidos,
					'display_name' => $display,
				) );
				return $by_email->ID;
			}
		}

		return wp_insert_user( array(
			'user_login'   => $cedula,
			'user_pass'    => $cedula,
			'user_email'   => $email ?: ( $cedula . '@nodisponible.edu.ec' ),
			'first_name'   => $nombres,
			'last_name'    => $apellidos,
			'display_name' => $display,
			'role'         => $rol,
		) );
	}

	/** Guarda el resultado de importación en un transient (60 s). */
	public static function guardar_resultado( string $clave, array $data ): void {
		set_transient( 'edu_import_' . $clave . '_' . get_current_user_id(), $data, 60 );
	}

	/** Recupera y borra el resultado de importación del transient. */
	public static function obtener_resultado( string $clave ): array|false {
		$key  = 'edu_import_' . $clave . '_' . get_current_user_id();
		$data = get_transient( $key );
		if ( false !== $data ) {
			delete_transient( $key );
		}
		return $data;
	}

	/** Renderiza el bloque de resultado de importación en una vista admin. */
	public static function render_resultado( array $res, string $entidad ): void {
		if ( ! empty( $res['error_fatal'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>'
				. '<strong>' . esc_html__( 'Error en importación:', 'sistema-educativo' ) . '</strong> '
				. esc_html( $res['error_fatal'] ) . '</p></div>';
			return;
		}

		$ins  = (int) ( $res['inserted']     ?? 0 );
		$ski  = (int) ( $res['skipped']      ?? 0 );
		$asig = (int) ( $res['asignaciones'] ?? 0 );
		$vinc = (int) ( $res['vinculados']   ?? 0 );
		$errs = $res['errors'] ?? array();

		echo '<div class="notice notice-success is-dismissible"><p>'
			. '<strong>' . esc_html__( 'Importación completada.', 'sistema-educativo' ) . '</strong> ';

		/* translators: %1$d: número de registros, %2$s: entidad */
		printf( esc_html__( '%1$d %2$s importado(s).', 'sistema-educativo' ), $ins, esc_html( $entidad ) );

		if ( $asig ) {
			echo ' ' . sprintf( esc_html__( '%d asignación(es) académica(s) creada(s).', 'sistema-educativo' ), $asig );
		}
		if ( $vinc ) {
			echo ' ' . sprintf( esc_html__( '%d vínculo(s) padre-estudiante creado(s).', 'sistema-educativo' ), $vinc );
		}
		if ( $ski ) {
			echo ' ' . sprintf( esc_html__( '%d omitido(s) (duplicado o faltante).', 'sistema-educativo' ), $ski );
		}
		echo '</p>';

		if ( ! empty( $errs ) ) {
			echo '<ul style="margin-top:4px;padding-left:20px;list-style:disc;">';
			foreach ( $errs as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Renderiza el panel colapsable de importación CSV reutilizable.
	 *
	 * @param string $titulo         Título del panel.
	 * @param string $nonce_template Nonce para descarga de plantilla.
	 * @param string $action_template  action de admin-post para plantilla.
	 * @param string $nonce_import   Nonce para importación.
	 * @param string $action_import  action de admin-post para importar.
	 * @param string $input_name     Name del <input type="file">.
	 * @param array  $columnas       [ [col, obligatorio, descripcion], ... ]
	 * @param array  $notas          Notas adicionales.
	 */
	public static function render_panel_importacion(
		string $titulo,
		string $nonce_template,
		string $action_template,
		string $nonce_import,
		string $action_import,
		string $input_name,
		array  $columnas,
		array  $notas = array()
	): void {
		$panel_id = 'edu-import-' . sanitize_key( $action_import );
		?>
		<div style="margin-top:28px;">
			<button type="button"
			        onclick="(function(b){var p=document.getElementById('<?php echo esc_js( $panel_id ); ?>');p.style.display=p.style.display==='none'?'block':'none';b.textContent=p.style.display==='none'?'▶ <?php echo esc_js( $titulo ); ?>':'▼ <?php echo esc_js( $titulo ); ?>';})(this)"
			        class="button"
			        style="font-size:13px;">
				▶ <?php echo esc_html( $titulo ); ?>
			</button>

			<div id="<?php echo esc_attr( $panel_id ); ?>" style="display:none;margin-top:16px;padding:20px 24px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;max-width:760px;">

				<h3 style="margin-top:0;"><?php echo esc_html( $titulo ); ?></h3>

				<div style="background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:14px 16px;margin-bottom:18px;font-size:13px;">
					<strong><?php esc_html_e( 'Formato requerido:', 'sistema-educativo' ); ?></strong>
					<table style="margin-top:10px;border-collapse:collapse;width:100%;">
						<thead>
							<tr style="background:#e0e0e0;">
								<th style="padding:5px 10px;border:1px solid #ccc;text-align:left;"><?php esc_html_e( 'Columna', 'sistema-educativo' ); ?></th>
								<th style="padding:5px 10px;border:1px solid #ccc;text-align:center;"><?php esc_html_e( 'Req.', 'sistema-educativo' ); ?></th>
								<th style="padding:5px 10px;border:1px solid #ccc;text-align:left;"><?php esc_html_e( 'Valores / Ejemplo', 'sistema-educativo' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $columnas as $i => $col ) : ?>
							<tr<?php echo $i % 2 ? ' style="background:#fafafa;"' : ''; ?>>
								<td style="padding:5px 10px;border:1px solid #ccc;"><code><?php echo esc_html( $col[0] ); ?></code></td>
								<td style="padding:5px 10px;border:1px solid #ccc;text-align:center;"><?php echo $col[1] ? '✔' : '—'; ?></td>
								<td style="padding:5px 10px;border:1px solid #ccc;"><?php echo wp_kses( $col[2], array( 'code' => array() ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( ! empty( $notas ) ) : ?>
					<ul style="margin:10px 0 0;padding-left:18px;color:#555;">
						<?php foreach ( $notas as $nota ) : ?>
							<li><?php echo esc_html( $nota ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
					<?php wp_nonce_field( $nonce_template ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( $action_template ); ?>">
					<button type="submit" class="button button-secondary">
						⬇ <?php esc_html_e( 'Descargar plantilla CSV de ejemplo', 'sistema-educativo' ); ?>
					</button>
					<span style="margin-left:8px;color:#666;font-size:12px;">
						<?php esc_html_e( 'Abre en Excel, completa los datos y guarda como CSV UTF-8.', 'sistema-educativo' ); ?>
					</span>
				</form>

				<hr style="margin:16px 0;">

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( $nonce_import ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( $action_import ); ?>">
					<label style="display:block;margin-bottom:8px;font-weight:600;">
						<?php esc_html_e( 'Seleccionar archivo CSV:', 'sistema-educativo' ); ?>
					</label>
					<input type="file" name="<?php echo esc_attr( $input_name ); ?>" accept=".csv" required style="display:block;margin-bottom:12px;">
					<button type="submit" class="button button-primary">
						⬆ <?php esc_html_e( 'Importar', 'sistema-educativo' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}
}
