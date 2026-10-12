<?php
/**
 * Plan contratado: qué módulos puede usar esta instalación.
 *
 * El colegio no elige sus módulos: los elige la licencia que emite PromoWebEc.
 * La licencia es un texto firmado con Ed25519 (libsodium, incluido en PHP): el
 * plugin solo trae la clave PÚBLICA, así que puede comprobar una licencia pero
 * nadie puede fabricar una. Cambiar una coma invalida la firma.
 *
 *   PS1.<payload en base64url>.<firma en base64url>
 *
 * El payload es un JSON con:
 *
 *   lid                    Identificador de la licencia.
 *   cliente                Nombre de la institución.
 *   hosts                  Dominios donde vale (producción, staging…).
 *   edicion                Nombre comercial: base, particular, fiscal, editorial…
 *   modulos                Claves del catálogo de Edu_Modules que incluye.
 *   limites                Capacidad contratada, p. ej. {"estudiantes":1500}.
 *   actualizaciones_hasta  Fecha (Y-m-d) hasta la que recibe actualizaciones.
 *   emitida                Fecha de emisión.
 *
 * Las ediciones NO viven aquí: el plugin solo entiende de módulos. Una edición
 * es una plantilla de módulos en el panel de la agencia, así que crear una
 * nueva no exige publicar una versión del plugin.
 *
 * Sin licencia válida hay dos casos, y se decide UNA vez por instalación:
 *
 *   heredado  La instalación ya existía antes del plan (giro22, entornos de
 *             desarrollo). Conserva el comportamiento anterior: todos los
 *             módulos disponibles, y Ajustes decide. Actualizar no le apaga nada.
 *   nuevo     Instalación hecha después. Solo el núcleo académico, hasta que se
 *             pegue una licencia.
 *
 * Vencer `actualizaciones_hasta` NO apaga módulos: el sistema se vende con pago
 * único. Esa fecha solo la usará el actualizador para dejar de ofrecer versiones.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Plan {

	const OPTION_LICENCIA = 'edu_plan_licencia';
	const OPTION_INICIAL  = 'edu_plan_inicial';
	const PREFIJO         = 'PS1';

	/**
	 * Clave pública de PromoWebEc (Ed25519, base64).
	 *
	 * Se genera con `php tools/emitir-licencia.php --generar-claves`. La privada
	 * se queda en la PC de la agencia y nunca entra al repositorio. Mientras esté
	 * vacía ninguna licencia valida y el plugin se comporta según el estado
	 * inicial (heredado o nuevo).
	 *
	 * No se puede sobrescribir desde wp-config ni con un filtro a propósito: si
	 * se pudiera, cualquiera firmaría sus propias licencias.
	 */
	const CLAVE_PUBLICA = 'qP6WaKC9KEGlBx4tFviT+emWRMg3OAMD3BWjoEsDGRI=';

	/** @var array|null Estado resuelto en esta petición. */
	private static $estado = null;

	/**
	 * Estado del plan en esta instalación.
	 *
	 * @return array {
	 *     @type string     $modo      licencia | heredado | nuevo
	 *     @type array|null $licencia  Payload de la licencia válida.
	 *     @type string     $error     Motivo por el que la licencia guardada no vale.
	 * }
	 */
	public static function estado() {
		if ( null === self::$estado ) {
			self::$estado = self::resolver(
				(string) get_option( self::OPTION_LICENCIA, '' ),
				self::CLAVE_PUBLICA,
				self::host_actual(),
				(string) get_option( self::OPTION_INICIAL, 'heredado' )
			);
		}

		return self::$estado;
	}

	/** Olvida el estado calculado (tras guardar una licencia, o en pruebas). */
	public static function reset() {
		self::$estado = null;
	}

	/**
	 * ¿El plan incluye este módulo del catálogo?
	 *
	 * @param string $modulo Clave del catálogo de Edu_Modules.
	 */
	public static function permite( $modulo ) {
		$estado = self::estado();

		if ( 'licencia' === $estado['modo'] ) {
			return in_array( $modulo, $estado['licencia']['modulos'], true );
		}

		return 'heredado' === $estado['modo'];
	}

	/**
	 * Decide el estado a partir de sus entradas. Función pura: es la que se
	 * prueba, sin tocar opciones ni la clave real.
	 *
	 * @param string $licencia      Texto de la licencia guardada ('' si no hay).
	 * @param string $clave_publica Clave pública en base64.
	 * @param string $host          Dominio de esta instalación.
	 * @param string $inicial       heredado | nuevo.
	 * @return array
	 */
	public static function resolver( $licencia, $clave_publica, $host, $inicial ) {
		$sin_licencia = 'nuevo' === $inicial ? 'nuevo' : 'heredado';
		$licencia     = trim( (string) $licencia );

		if ( '' === $licencia ) {
			return array( 'modo' => $sin_licencia, 'licencia' => null, 'error' => '' );
		}

		$payload = self::verificar( $licencia, $clave_publica );

		if ( is_wp_error( $payload ) ) {
			return array( 'modo' => $sin_licencia, 'licencia' => null, 'error' => $payload->get_error_message() );
		}

		if ( ! self::host_permitido( $host, $payload['hosts'] ) ) {
			return array(
				'modo'     => $sin_licencia,
				'licencia' => null,
				'error'    => sprintf(
					/* translators: %s: dominio de la instalación. */
					__( 'La licencia no corresponde a este dominio (%s).', 'sistema-educativo' ),
					$host
				),
			);
		}

		return array( 'modo' => 'licencia', 'licencia' => $payload, 'error' => '' );
	}

	/**
	 * Comprueba firma y formato. No mira el dominio: eso es de resolver().
	 *
	 * @param string $licencia      Texto completo.
	 * @param string $clave_publica Clave pública en base64.
	 * @return array|WP_Error Payload normalizado.
	 */
	public static function verificar( $licencia, $clave_publica ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return Edu_Service::error( 'sodium_missing', __( 'El servidor no tiene libsodium; no se pueden verificar licencias.', 'sistema-educativo' ), 500 );
		}

		$clave = base64_decode( (string) $clave_publica, true );
		if ( false === $clave || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $clave ) ) {
			return Edu_Service::error( 'no_public_key', __( 'Esta versión del plugin no trae clave para verificar licencias.', 'sistema-educativo' ), 500 );
		}

		$partes = explode( '.', trim( (string) $licencia ) );
		if ( 3 !== count( $partes ) || self::PREFIJO !== $partes[0] ) {
			return Edu_Service::error( 'invalid_license', __( 'El texto no tiene el formato de una licencia PromoSchool.', 'sistema-educativo' ), 400 );
		}

		$firma = self::b64url_decode( $partes[2] );
		if ( false === $firma || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $firma ) ) {
			return Edu_Service::error( 'invalid_license', __( 'La firma de la licencia está dañada.', 'sistema-educativo' ), 400 );
		}

		// Se firma prefijo + payload: así la firma también ata la versión del formato.
		if ( ! sodium_crypto_sign_verify_detached( $firma, $partes[0] . '.' . $partes[1], $clave ) ) {
			return Edu_Service::error( 'invalid_signature', __( 'La firma de la licencia no es válida.', 'sistema-educativo' ), 400 );
		}

		$json    = self::b64url_decode( $partes[1] );
		$payload = false === $json ? null : json_decode( $json, true );

		if ( ! is_array( $payload ) || empty( $payload['hosts'] ) || ! isset( $payload['modulos'] ) ) {
			return Edu_Service::error( 'invalid_license', __( 'La licencia no trae dominios o módulos.', 'sistema-educativo' ), 400 );
		}

		return array(
			'lid'                   => sanitize_text_field( (string) ( $payload['lid'] ?? '' ) ),
			'cliente'               => sanitize_text_field( (string) ( $payload['cliente'] ?? '' ) ),
			'hosts'                 => array_values( array_map( 'strtolower', array_map( 'strval', (array) $payload['hosts'] ) ) ),
			'edicion'               => sanitize_key( (string) ( $payload['edicion'] ?? '' ) ),
			'modulos'               => array_values( array_map( 'sanitize_key', (array) $payload['modulos'] ) ),
			'limites'               => array_map( 'absint', (array) ( $payload['limites'] ?? array() ) ),
			'actualizaciones_hasta' => sanitize_text_field( (string) ( $payload['actualizaciones_hasta'] ?? '' ) ),
			'emitida'               => sanitize_text_field( (string) ( $payload['emitida'] ?? '' ) ),
		);
	}

	/**
	 * Guarda una licencia nueva. Solo el administrador de WordPress, que es
	 * PromoWebEc: el colegio recibe el rol de rector, no este.
	 *
	 * Una licencia que no valida no se guarda: así un error al pegarla no deja
	 * a la institución sin módulos.
	 *
	 * @param array $input { @type string $licencia }
	 * @return array|WP_Error
	 */
	public static function guardar_licencia( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Edu_Service::error( 'forbidden', __( 'Solo el administrador puede cambiar la licencia.', 'sistema-educativo' ), 403 );
		}

		$texto  = trim( preg_replace( '/\s+/', '', (string) ( $input['licencia'] ?? '' ) ) );
		$estado = self::resolver( $texto, self::CLAVE_PUBLICA, self::host_actual(), 'nuevo' );

		if ( 'licencia' !== $estado['modo'] ) {
			return Edu_Service::error( 'invalid_license', $estado['error'] ? $estado['error'] : __( 'Licencia no válida.', 'sistema-educativo' ), 400 );
		}

		$anterior = self::estado();

		update_option( self::OPTION_LICENCIA, $texto, true );
		self::reset();

		Edu_Audit::log(
			Edu_Audit::LICENCIA_ACTUALIZADA,
			'licencia',
			null,
			self::resumen_auditoria( $anterior ),
			self::resumen_auditoria( $estado )
		);

		return array(
			'modo'     => 'licencia',
			'licencia' => $estado['licencia'],
		);
	}

	/**
	 * Quita la licencia: la instalación vuelve a su estado inicial.
	 *
	 * @return array|WP_Error
	 */
	public static function quitar_licencia() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Edu_Service::error( 'forbidden', __( 'Solo el administrador puede cambiar la licencia.', 'sistema-educativo' ), 403 );
		}

		$anterior = self::estado();

		delete_option( self::OPTION_LICENCIA );
		self::reset();

		Edu_Audit::log( Edu_Audit::LICENCIA_ACTUALIZADA, 'licencia', null, self::resumen_auditoria( $anterior ), self::resumen_auditoria( self::estado() ) );

		return array( 'modo' => self::estado()['modo'] );
	}

	/**
	 * Fija, una sola vez, si esta instalación es heredada o nueva.
	 *
	 * Heredada = ya tenía instituciones cargadas cuando llegó esta versión. Si
	 * la consulta falla no se decide nada: marcar «nuevo» por un error de base
	 * le apagaría los módulos a un colegio en producción.
	 */
	public static function registrar_estado_inicial() {
		if ( false !== get_option( self::OPTION_INICIAL ) ) {
			return;
		}

		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$total    = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}edu_institutions" );
		$wpdb->suppress_errors( $suppress );

		if ( null === $total ) {
			return;
		}

		update_option( self::OPTION_INICIAL, (int) $total > 0 ? 'heredado' : 'nuevo', true );
		self::reset();
	}

	/* ─── Capacidad ─────────────────────────────────────────────────────── */

	/** Estudiantes activos en todo el sitio: la licencia es por instalación. */
	public static function estudiantes_activos() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}edu_students WHERE status = 'active'" );
	}

	/**
	 * Si la instalación supera la capacidad contratada. Solo avisa: nunca se
	 * bloquea una matrícula a mitad de año.
	 *
	 * @return array|null { contratados, activos } o null si no se excede.
	 */
	public static function exceso_capacidad() {
		$estado = self::estado();
		$limite = 'licencia' === $estado['modo'] ? (int) ( $estado['licencia']['limites']['estudiantes'] ?? 0 ) : 0;

		if ( $limite <= 0 ) {
			return null;
		}

		$activos = self::estudiantes_activos();

		return $activos > $limite ? array( 'contratados' => $limite, 'activos' => $activos ) : null;
	}

	/* ─── Utilidades ────────────────────────────────────────────────────── */

	/** Dominio de la instalación, sin puerto ni www. */
	public static function host_actual() {
		return self::normalizar_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * @param string   $host   Dominio actual.
	 * @param string[] $hosts  Dominios de la licencia. `*.colegio.edu.ec` cubre subdominios.
	 */
	public static function host_permitido( $host, array $hosts ) {
		$host = self::normalizar_host( $host );

		foreach ( $hosts as $permitido ) {
			$permitido = self::normalizar_host( $permitido );

			if ( $permitido === $host ) {
				return true;
			}

			if ( 0 === strpos( $permitido, '*.' ) && substr( $host, -strlen( $permitido ) + 1 ) === substr( $permitido, 1 ) ) {
				return true;
			}
		}

		return false;
	}

	private static function normalizar_host( $host ) {
		$host = strtolower( trim( (string) $host ) );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	public static function b64url_encode( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( $texto ) {
		$texto = strtr( (string) $texto, '-_', '+/' );

		return base64_decode( $texto . str_repeat( '=', ( 4 - strlen( $texto ) % 4 ) % 4 ), true );
	}

	private static function resumen_auditoria( array $estado ) {
		return array(
			'modo'    => $estado['modo'],
			'lid'     => $estado['licencia']['lid'] ?? '',
			'edicion' => $estado['licencia']['edicion'] ?? '',
			'modulos' => $estado['licencia']['modulos'] ?? array(),
		);
	}
}
