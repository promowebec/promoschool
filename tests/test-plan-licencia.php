<?php
/**
 * Plan contratado: la licencia decide qué módulos existen.
 *
 *   1. La firma: una licencia válida se acepta; cambiarle un carácter, firmarla
 *      con otra clave o usarla en otro dominio la invalida.
 *   2. El script de la agencia y el plugin hablan el mismo formato.
 *   3. Sin licencia: una instalación heredada conserva sus módulos y una nueva
 *      queda solo con el núcleo, en Edu_Modules y en la API (404).
 *   4. `entregas` es un módulo aparte: apagado, el estudiante no puede subir
 *      nada aunque llame al servicio directamente; y se apaga con `tareas`.
 *
 * Las claves de esta prueba se generan en memoria y se descartan: la clave real
 * de PromoWebEc no participa ni hace falta.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'Plan contratado y licencias' );

global $wpdb;
$p = $wpdb->prefix . 'edu_';

/** Firma un payload igual que tools/emitir-licencia.php. */
$firmar = function ( array $payload, $secreta ) {
	$cuerpo = Edu_Plan::PREFIJO . '.' . Edu_Plan::b64url_encode( wp_json_encode( $payload ) );

	return $cuerpo . '.' . Edu_Plan::b64url_encode( sodium_crypto_sign_detached( $cuerpo, $secreta ) );
};

$par     = sodium_crypto_sign_keypair();
$secreta = sodium_crypto_sign_secretkey( $par );
$publica = base64_encode( sodium_crypto_sign_publickey( $par ) );

$payload = array(
	'lid'     => 'PS-TEST',
	'cliente' => 'Institución de prueba',
	'hosts'   => array( 'colegio.test', '*.staging.test' ),
	'edicion' => 'base',
	'modulos' => array( 'tareas', 'comunicados' ),
	'limites' => array( 'estudiantes' => 1500 ),
);

$licencia = $firmar( $payload, $secreta );

/* ── 1. Firma y dominio ───────────────────────────────────────────────────── */

$ok = Edu_Plan::resolver( $licencia, $publica, 'colegio.test', 'nuevo' );
edu_assert( 'licencia válida → modo licencia', 'licencia' === $ok['modo'], $ok['error'] );
edu_assert( 'trae los módulos firmados', $ok['licencia'] && array( 'tareas', 'comunicados' ) === $ok['licencia']['modulos'] );
edu_assert( 'trae la capacidad', $ok['licencia'] && 1500 === $ok['licencia']['limites']['estudiantes'] );

edu_assert( 'www. se ignora', 'licencia' === Edu_Plan::resolver( $licencia, $publica, 'www.colegio.test', 'nuevo' )['modo'] );
edu_assert( 'comodín cubre subdominios', 'licencia' === Edu_Plan::resolver( $licencia, $publica, 'demo.staging.test', 'nuevo' )['modo'] );

$otro_dominio = Edu_Plan::resolver( $licencia, $publica, 'otro-colegio.test', 'nuevo' );
edu_assert( 'otro dominio → no vale', 'nuevo' === $otro_dominio['modo'] && '' !== $otro_dominio['error'] );

// Cambiar el payload para sumar un módulo: la firma ya no corresponde.
$partes        = explode( '.', $licencia );
$falso         = $payload;
$falso['modulos'][] = 'pagos';
$partes[1]     = Edu_Plan::b64url_encode( wp_json_encode( $falso ) );
$alterada      = implode( '.', $partes );

edu_assert_error( 'payload alterado → firma inválida', Edu_Plan::verificar( $alterada, $publica ), 'invalid_signature' );

$otra_clave = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
edu_assert_error( 'firmada con otra clave → inválida', Edu_Plan::verificar( $licencia, $otra_clave ), 'invalid_signature' );
edu_assert_error( 'texto cualquiera → formato inválido', Edu_Plan::verificar( 'hola', $publica ), 'invalid_license' );
edu_assert_error( 'sin clave pública → no valida', Edu_Plan::verificar( $licencia, '' ), 'no_public_key' );

edu_assert( 'licencia inválida en heredado → sigue heredado', 'heredado' === Edu_Plan::resolver( $alterada, $publica, 'colegio.test', 'heredado' )['modo'] );
edu_assert( 'sin licencia, heredado', 'heredado' === Edu_Plan::resolver( '', $publica, 'colegio.test', 'heredado' )['modo'] );
edu_assert( 'sin licencia, nuevo', 'nuevo' === Edu_Plan::resolver( '', $publica, 'colegio.test', 'nuevo' )['modo'] );

/* ── 2. El script de la agencia emite licencias que el plugin acepta ─────── */

$tmp_clave = wp_tempnam( 'edu-lic-test' );
file_put_contents( $tmp_clave, base64_encode( $secreta ) );
edu_test_cleanup(
	function () use ( $tmp_clave ) {
		@unlink( $tmp_clave );
	}
);

/*
 * El script corre fuera de WordPress, sin el sodium_compat que trae el núcleo:
 * necesita la extensión de verdad. Si este proceso la recibió por `-d` (Local en
 * Windows), el hijo arranca sin ella y hay que reponérsela.
 */
$php_hijo = escapeshellarg( PHP_BINARY );
exec( $php_hijo . ' -r "echo extension_loaded(\'sodium\') ? 1 : 0;" 2>&1', $sonda_sodium );
if ( '1' !== trim( implode( '', $sonda_sodium ) ) && extension_loaded( 'sodium' ) ) {
	$php_hijo .= ' -d ' . escapeshellarg( 'extension_dir=' . ini_get( 'extension_dir' ) ) . ' -d extension=sodium';
}

$cmd = $php_hijo . ' ' . escapeshellarg( EDU_PLUGIN_DIR . 'tools/emitir-licencia.php' )
	. ' --clave=' . escapeshellarg( $tmp_clave )
	. ' --cliente=' . escapeshellarg( 'Colegio Script' )
	. ' --hosts=colegio.test --edicion=base --agregar=entregas --estudiantes=1500 --actualizaciones-hasta=2027-11-30 2>&1';

exec( $cmd, $salida, $codigo );
$emitida = '';
foreach ( $salida as $linea ) {
	if ( 0 === strpos( $linea, 'PS1.' ) ) {
		$emitida = trim( $linea );
	}
}

edu_assert( 'el script termina bien', 0 === $codigo, 'código ' . $codigo );
$del_script = Edu_Plan::resolver( $emitida, $publica, 'colegio.test', 'nuevo' );
edu_assert( 'el plugin acepta la licencia del script', 'licencia' === $del_script['modo'], $del_script['error'] );
edu_assert(
	'edición base + entregas',
	$del_script['licencia'] && in_array( 'entregas', $del_script['licencia']['modulos'], true )
		&& in_array( 'boletines', $del_script['licencia']['modulos'], true )
		&& ! in_array( 'pagos', $del_script['licencia']['modulos'], true )
);

exec(
	$php_hijo . ' ' . escapeshellarg( EDU_PLUGIN_DIR . 'tools/emitir-licencia.php' )
		. ' --clave=' . escapeshellarg( $tmp_clave ) . ' --cliente=X --hosts=x.test --edicion=base --modulos=entregas 2>&1',
	$salida_mala,
	$codigo_malo
);
edu_assert( 'el script rechaza entregas sin tareas', 0 !== $codigo_malo );

/* ── 3. Sin licencia: heredado conserva, nuevo solo núcleo ───────────────── */

$inicial_previo  = get_option( Edu_Plan::OPTION_INICIAL );
$licencia_previa = get_option( Edu_Plan::OPTION_LICENCIA );
$modulos_previos = get_option( Edu_Modules::OPTION );

$restaurar = function ( $nombre, $valor ) {
	if ( false === $valor ) {
		delete_option( $nombre );
	} else {
		update_option( $nombre, $valor );
	}
};

edu_test_cleanup(
	function () use ( $restaurar, $inicial_previo, $licencia_previa, $modulos_previos ) {
		$restaurar( Edu_Plan::OPTION_INICIAL, $inicial_previo );
		$restaurar( Edu_Plan::OPTION_LICENCIA, $licencia_previa );
		$restaurar( Edu_Modules::OPTION, $modulos_previos );
		Edu_Plan::reset();
	}
);

$poner = function ( $nombre, $valor ) {
	update_option( $nombre, $valor );
	wp_cache_delete( $nombre, 'options' );
	Edu_Plan::reset();
};

delete_option( Edu_Plan::OPTION_LICENCIA );
delete_option( Edu_Modules::OPTION );

// Esta base ya tiene instituciones: al registrar el estado inicial, es heredada.
delete_option( Edu_Plan::OPTION_INICIAL );
Edu_Plan::reset();
Edu_Plan::registrar_estado_inicial();
$hay_instituciones = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}institutions" ) > 0;
edu_assert(
	'instalación con datos → heredada',
	( $hay_instituciones ? 'heredado' : 'nuevo' ) === get_option( Edu_Plan::OPTION_INICIAL )
);

$poner( Edu_Plan::OPTION_INICIAL, 'heredado' );
edu_assert( 'heredado: tareas activo', Edu_Modules::is_active( 'tareas' ) );
edu_assert( 'heredado: entregas activo (no se le quita a giro22)', Edu_Modules::is_active( 'entregas' ) );
edu_assert( 'heredado: pagos en el plan', Edu_Modules::in_plan( 'pagos' ) );

$poner( Edu_Plan::OPTION_INICIAL, 'nuevo' );
edu_assert( 'nuevo: tareas apagado', ! Edu_Modules::is_active( 'tareas' ) );
edu_assert( 'nuevo: comunicados apagado', ! Edu_Modules::is_active( 'comunicados' ) );
edu_assert( 'nuevo: el núcleo sigue activo', Edu_Modules::is_active( 'calificaciones' ) );

// Un filtro puede apagar, pero no encender lo que el plan no incluye.
$forzar = function ( $activo, $modulo ) {
	return 'pagos' === $modulo ? true : $activo;
};
add_filter( 'edu_module_active', $forzar, 10, 2 );
edu_assert( 'nuevo: un filtro no enciende pagos', ! Edu_Modules::is_active( 'pagos' ) );
remove_filter( 'edu_module_active', $forzar, 10 );

$admin_id = edu_test_admin_id();
if ( $admin_id ) {
	edu_test_as( $admin_id );

	$me = rest_do_request( new WP_REST_Request( 'GET', '/edu/v1/me' ) );
	$md = $me->get_data();
	edu_assert(
		'/me informa entregas y tareas apagados',
		200 === $me->get_status() && isset( $md['modules']['entregas'], $md['modules']['tareas'] )
			&& false === $md['modules']['entregas'] && false === $md['modules']['tareas'],
		'status ' . $me->get_status()
	);
}

/* ── 4. Entregas: módulo aparte, validado en el servicio ─────────────────── */

$poner( Edu_Plan::OPTION_INICIAL, 'heredado' );

$estudiante = $wpdb->get_row(
	"SELECT s.id, s.user_id, g.institution_id
	 FROM {$p}students s JOIN {$p}grades g ON g.id = s.grade_id
	 WHERE s.status = 'active' AND s.user_id > 0 LIMIT 1"
);

if ( ! $estudiante ) {
	edu_test_skip( 'no hay un estudiante con usuario para probar entregas' );
}

$poner( Edu_Modules::OPTION, array( 'entregas' => 0 ) );
edu_assert( 'entregas apagado en Ajustes', ! Edu_Modules::is_active( 'entregas' ) );
edu_assert( 'tareas sigue activo', Edu_Modules::is_active( 'tareas' ) );

edu_test_as( (int) $estudiante->user_id, (int) $estudiante->institution_id );

$intento = Edu_Submission_Service::submit( array( 'assignment_id' => 999999999, 'comment' => '', 'files' => array() ) );
edu_assert_error( 'sin entregas: submit lo rechaza el servicio', $intento, 'module_disabled' );

$mejora = Edu_Submission_Service::submit_recovery( array( 'assignment_id' => 999999999, 'comment' => '', 'files' => array() ) );
edu_assert_error( 'sin entregas: la mejora también', $mejora, 'module_disabled' );

$api = rest_do_request( new WP_REST_Request( 'POST', '/edu/v1/assignments/999999999/submissions' ) );
edu_assert( 'sin entregas: la API responde 404', 404 === $api->get_status(), 'status ' . $api->get_status() );

$poner( Edu_Modules::OPTION, array( 'entregas' => 1 ) );
$con = Edu_Submission_Service::submit( array( 'assignment_id' => 999999999, 'comment' => '', 'files' => array() ) );
edu_assert(
	'con entregas: el servicio ya no lo frena por módulo',
	! is_wp_error( $con ) || 'module_disabled' !== $con->get_error_code(),
	is_wp_error( $con ) ? $con->get_error_code() : 'ok'
);

$poner( Edu_Modules::OPTION, array( 'tareas' => 0, 'entregas' => 1 ) );
edu_assert( 'apagar tareas apaga entregas', ! Edu_Modules::is_active( 'entregas' ) );

edu_test_finish();
