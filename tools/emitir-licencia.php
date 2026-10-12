<?php
/**
 * Emite licencias de plan para PromoSchool. Herramienta de la AGENCIA.
 *
 * No necesita WordPress: corre con cualquier PHP 7.2+ (trae libsodium).
 * No se sube a los servidores de los colegios.
 *
 * 1) Una sola vez, generar el par de claves:
 *
 *      php tools/emitir-licencia.php --generar-claves
 *
 *    Deja la clave privada en %USERPROFILE%\.promowebec\ (o --salida=DIR) e
 *    imprime la pública, que se pega en Edu_Plan::CLAVE_PUBLICA. La privada
 *    NUNCA entra al repositorio: quien la tenga puede emitir licencias. Si se
 *    pierde, hay que generar otra y publicar una versión del plugin con la
 *    nueva pública (las licencias anteriores dejan de valer).
 *
 * Si se perdió la pública:  php tools/emitir-licencia.php --mostrar-publica
 *
 * 2) Por cada colegio:
 *
 *      php tools/emitir-licencia.php --cliente="Unidad Educativa X" \
 *          --hosts=colegiox.edu.ec,staging.colegiox.edu.ec \
 *          --edicion=base --estudiantes=1500 --whatsapp-dia=50 --actualizaciones-hasta=2027-11-30
 *
 *    --edicion toma los módulos de la plantilla de abajo. --agregar=entregas,pagos
 *    suma módulos vendidos aparte; --modulos=... reemplaza la plantilla entera.
 *    --whatsapp-dia es el tope diario de mensajes de WhatsApp (sin él, sin tope).
 *
 * @package SistemaEducativo
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "Solo por linea de comandos.\n" );
}

if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
	fwrite( STDERR, "Este PHP no trae libsodium.\n" );
	exit( 1 );
}

/** Módulos que entiende el plugin (Edu_Modules::catalog()). */
const EDU_LIC_MODULOS = array( 'tareas', 'entregas', 'comunicados', 'asistencia', 'boletines', 'pagos', 'whatsapp', 'cuentas', 'exportes', 'pwa', 'textos' );

/**
 * Plantillas de edición. Son de la agencia, no del plugin: cambiarlas o crear
 * una nueva no exige publicar una versión.
 */
const EDU_LIC_EDICIONES = array(
	'base'       => array( 'tareas', 'comunicados', 'asistencia', 'boletines', 'whatsapp', 'cuentas', 'pwa' ),
	'particular' => array( 'tareas', 'comunicados', 'asistencia', 'boletines', 'whatsapp', 'cuentas', 'pwa', 'entregas', 'pagos' ),
	'fiscal'     => array( 'tareas', 'comunicados', 'asistencia', 'boletines', 'whatsapp', 'cuentas', 'pwa', 'exportes' ),
	'editorial'  => array( 'tareas', 'comunicados', 'asistencia', 'boletines', 'whatsapp', 'cuentas', 'pwa', 'entregas', 'textos' ),
	'completa'   => EDU_LIC_MODULOS,
);

$opts = getopt( '', array( 'generar-claves', 'mostrar-publica', 'salida:', 'clave:', 'cliente:', 'hosts:', 'edicion:', 'modulos:', 'agregar:', 'estudiantes:', 'whatsapp-dia:', 'actualizaciones-hasta:', 'lid:' ) );

$dir_defecto = rtrim( (string) ( getenv( 'USERPROFILE' ) ?: getenv( 'HOME' ) ), '\\/' ) . DIRECTORY_SEPARATOR . '.promowebec';

function edu_lic_b64url( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

function edu_lic_fallar( $mensaje ) {
	fwrite( STDERR, $mensaje . "\n" );
	exit( 1 );
}

/* ── Generar claves ─────────────────────────────────────────────────────── */

if ( isset( $opts['generar-claves'] ) ) {
	$dir     = isset( $opts['salida'] ) ? rtrim( $opts['salida'], '\\/' ) : $dir_defecto;
	$privada = $dir . DIRECTORY_SEPARATOR . 'promoschool-licencias.key';

	if ( file_exists( $privada ) ) {
		edu_lic_fallar( "Ya existe $privada. No se sobrescribe: borrarla invalidaría todas las licencias emitidas." );
	}

	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) {
		edu_lic_fallar( "No se pudo crear $dir" );
	}

	$par = sodium_crypto_sign_keypair();
	file_put_contents( $privada, base64_encode( sodium_crypto_sign_secretkey( $par ) ) );
	@chmod( $privada, 0600 );

	echo "Clave privada guardada en: $privada\n";
	echo "Haz una copia de respaldo fuera de esta PC (USB, gestor de contraseñas).\n\n";
	echo "Clave PUBLICA (pegar en Edu_Plan::CLAVE_PUBLICA):\n";
	echo base64_encode( sodium_crypto_sign_publickey( $par ) ) . "\n";
	exit( 0 );
}

/* ── Emitir licencia ────────────────────────────────────────────────────── */

$ruta_clave = isset( $opts['clave'] ) ? $opts['clave'] : $dir_defecto . DIRECTORY_SEPARATOR . 'promoschool-licencias.key';
$secreta    = is_readable( $ruta_clave ) ? base64_decode( trim( file_get_contents( $ruta_clave ) ), true ) : false;

if ( false === $secreta || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secreta ) ) {
	edu_lic_fallar( "No se pudo leer la clave privada en $ruta_clave (genérala con --generar-claves)." );
}

// Vuelve a mostrar la pública (la que va en Edu_Plan::CLAVE_PUBLICA) sin
// imprimir nunca la privada.
if ( isset( $opts['mostrar-publica'] ) ) {
	echo base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secreta ) ) . "\n";
	exit( 0 );
}

foreach ( array( 'cliente', 'hosts', 'edicion' ) as $obligatorio ) {
	if ( empty( $opts[ $obligatorio ] ) ) {
		edu_lic_fallar( "Falta --$obligatorio" );
	}
}

$edicion = strtolower( $opts['edicion'] );
$lista   = static function ( $texto ) {
	return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) $texto ) ) ) ) );
};

if ( isset( $opts['modulos'] ) ) {
	$modulos = $lista( $opts['modulos'] );
} elseif ( isset( EDU_LIC_EDICIONES[ $edicion ] ) ) {
	$modulos = EDU_LIC_EDICIONES[ $edicion ];
} else {
	edu_lic_fallar( "Edición '$edicion' sin plantilla. Usa una de: " . implode( ', ', array_keys( EDU_LIC_EDICIONES ) ) . ', o pasa --modulos=...' );
}

$modulos     = array_values( array_unique( array_merge( $modulos, $lista( $opts['agregar'] ?? '' ) ) ) );
$desconocido = array_diff( $modulos, EDU_LIC_MODULOS );

if ( $desconocido ) {
	edu_lic_fallar( 'Módulos desconocidos: ' . implode( ', ', $desconocido ) );
}

if ( in_array( 'entregas', $modulos, true ) && ! in_array( 'tareas', $modulos, true ) ) {
	edu_lic_fallar( "El módulo 'entregas' requiere 'tareas'." );
}

$hasta = $opts['actualizaciones-hasta'] ?? '';
if ( '' !== $hasta && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $hasta ) ) {
	edu_lic_fallar( '--actualizaciones-hasta debe ser AAAA-MM-DD' );
}

$payload = array(
	'lid'                   => $opts['lid'] ?? ( 'PS-' . gmdate( 'Ymd' ) . '-' . bin2hex( random_bytes( 3 ) ) ),
	'cliente'               => $opts['cliente'],
	'hosts'                 => $lista( $opts['hosts'] ),
	'edicion'               => $edicion,
	'modulos'               => $modulos,
	'limites'               => array_filter(
		array(
			'estudiantes'  => (int) ( $opts['estudiantes'] ?? 0 ),
			'whatsapp_dia' => (int) ( $opts['whatsapp-dia'] ?? 0 ),
		)
	),
	'actualizaciones_hasta' => $hasta,
	'emitida'               => gmdate( 'Y-m-d' ),
);

$cuerpo   = 'PS1.' . edu_lic_b64url( json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
$licencia = $cuerpo . '.' . edu_lic_b64url( sodium_crypto_sign_detached( $cuerpo, $secreta ) );

fwrite( STDERR, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\nLicencia (pegar en Ajustes → Plan contratado):\n" );
echo $licencia . "\n";
