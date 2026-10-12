<?php
/**
 * WhatsApp por WAHA: el plugin habla el mismo idioma que el servidor del CRM.
 *
 *   1. El envío va a /api/sendText con la sesión del colegio, el chatId en
 *      formato WAHA y la API key en X-Api-Key.
 *   2. "Probar conexión" distingue lo que de verdad le pasa a la sesión:
 *      conectada (y con qué número), esperando QR, inexistente, key rechazada.
 *   3. Sin URL o sin sesión no se intenta enviar nada.
 *
 * No hace falta un WAHA real: las peticiones se interceptan con
 * `pre_http_request` y se responde como lo haría WAHA. Ningún mensaje sale.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'WhatsApp por WAHA' );

$opciones = array( 'edu_wa_provider', 'edu_wa_waha_url', 'edu_wa_waha_key', 'edu_wa_waha_session' );
$antes    = array();
foreach ( $opciones as $opcion ) {
	$antes[ $opcion ] = get_option( $opcion, null );
}
edu_test_cleanup(
	function () use ( $antes ) {
		foreach ( $antes as $opcion => $valor ) {
			null === $valor ? delete_option( $opcion ) : update_option( $opcion, $valor );
		}
	}
);

update_option( 'edu_wa_provider', 'waha' );
update_option( 'edu_wa_waha_url', 'https://waha.prueba.test/' );
update_option( 'edu_wa_waha_key', 'clave-de-prueba' );
update_option( 'edu_wa_waha_session', 'colegio-prueba' );

// WAHA simulado: guarda cada petición y contesta lo que diga $respuesta.
$peticiones = array();
$respuesta  = array( 201, array( 'id' => 'true_593999000111@c.us_ABC' ) );

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$peticiones, &$respuesta ) {
		$peticiones[] = array(
			'url'  => $url,
			'args' => $args,
		);

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $respuesta[1] ),
			'response' => array(
				'code'    => $respuesta[0],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

/* ── 1. Envío ───────────────────────────────────────────────────────────── */

$ok    = Edu_Whatsapp::send( '0999000111', 'Hola' );
$envio = end( $peticiones );
$body  = json_decode( (string) ( $envio['args']['body'] ?? '' ), true );

edu_assert( 'send() devuelve true si WAHA acepta', true === $ok );
edu_assert( 'va a /api/sendText sin doble barra', 'https://waha.prueba.test/api/sendText' === $envio['url'], $envio['url'] );
edu_assert( 'por POST', 'POST' === $envio['args']['method'] );
edu_assert( 'con la API key en X-Api-Key', 'clave-de-prueba' === ( $envio['args']['headers']['X-Api-Key'] ?? '' ) );
edu_assert( 'con la sesión del colegio', 'colegio-prueba' === ( $body['session'] ?? '' ) );
edu_assert( 'chatId en formato WAHA (593…@c.us)', '593999000111@c.us' === ( $body['chatId'] ?? '' ), (string) ( $body['chatId'] ?? '' ) );
edu_assert( 'el texto tal cual', 'Hola' === ( $body['text'] ?? '' ) );
edu_assert( 'timeout largo (primer contacto)', 60 === (int) $envio['args']['timeout'] );

$respuesta = array( 422, array( 'message' => 'Session status is not as expected' ) );
edu_assert( 'send() devuelve false si WAHA rechaza', false === Edu_Whatsapp::send( '0999000111', 'Hola' ) );

/* ── 2. Probar conexión ─────────────────────────────────────────────────── */

$respuesta = array(
	200,
	array(
		'name'   => 'colegio-prueba',
		'status' => 'WORKING',
		'me'     => array( 'id' => '593999000222@c.us' ),
	),
);
$r      = Edu_Whatsapp::test_connection();
$prueba = end( $peticiones );
edu_assert( 'conectada: ok', true === $r['ok'], $r['message'] );
edu_assert( 'conectada: informa el número emparejado', false !== strpos( $r['message'], '+593999000222' ), $r['message'] );
edu_assert( 'consulta GET /api/sessions/{sesión}', 'https://waha.prueba.test/api/sessions/colegio-prueba' === $prueba['url'] && 'GET' === $prueba['args']['method'], $prueba['url'] );
edu_assert( 'probar conexión no envía mensajes', false === strpos( $prueba['url'], 'send' ) );

$respuesta = array(
	200,
	array(
		'name'   => 'colegio-prueba',
		'status' => 'SCAN_QR_CODE',
	),
);
$r = Edu_Whatsapp::test_connection();
edu_assert( 'esperando QR: falla y lo dice', false === $r['ok'] && false !== strpos( $r['message'], 'SCAN_QR_CODE' ), $r['message'] );

$respuesta = array( 404, array( 'message' => 'Session not found' ) );
$r         = Edu_Whatsapp::test_connection();
edu_assert( 'sesión inexistente: falla y la nombra', false === $r['ok'] && false !== strpos( $r['message'], 'colegio-prueba' ), $r['message'] );

$respuesta = array( 401, array( 'message' => 'Unauthorized' ) );
$r         = Edu_Whatsapp::test_connection();
edu_assert( 'API key rechazada: falla', false === $r['ok'] && false !== strpos( $r['message'], 'API key' ), $r['message'] );

/* ── 3. Sin configuración no se llama a nadie ───────────────────────────── */

update_option( 'edu_wa_waha_session', '' );
$n = count( $peticiones );
edu_assert( 'sin sesión: send() false', false === Edu_Whatsapp::send( '0999000111', 'Hola' ) );
edu_assert( 'sin sesión: probar conexión pide completar', false === Edu_Whatsapp::test_connection()['ok'] );

update_option( 'edu_wa_waha_session', 'colegio-prueba' );
update_option( 'edu_wa_waha_url', '' );
edu_assert( 'sin URL: send() false', false === Edu_Whatsapp::send( '0999000111', 'Hola' ) );
edu_assert( 'sin URL ni sesión no hubo peticiones', count( $peticiones ) === $n );

/* ── 4. Panel «Conectar número»: ciclo de vida de la sesión ─────────────── */

update_option( 'edu_wa_waha_url', 'https://waha.prueba.test' );
update_option( 'edu_wa_waha_session', 'colegio-prueba' );

// WAHA simulado con estado: las sesiones se crean, arrancan y borran.
remove_all_filters( 'pre_http_request' );
$sesiones = array();
$llamadas = array();
$png      = "\x89PNG\r\n\x1a\nQR-DE-PRUEBA";

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$sesiones, &$llamadas, $png ) {
		$metodo     = $args['method'];
		$ruta       = (string) wp_parse_url( $url, PHP_URL_PATH );
		$llamadas[] = "$metodo $ruta";
		$responder  = function ( $code, $body, $tipo = 'application/json' ) {
			return array(
				'headers'  => array( 'content-type' => $tipo ),
				'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
				'response' => array( 'code' => $code, 'message' => '' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		if ( 'POST' === $metodo && '/api/sessions' === $ruta ) {
			$nombre = json_decode( $args['body'], true )['name'];
			if ( isset( $sesiones[ $nombre ] ) ) {
				return $responder( 422, array( 'message' => 'Session already exists' ) );
			}
			$sesiones[ $nombre ] = 'SCAN_QR_CODE';
			return $responder( 201, array( 'name' => $nombre ) );
		}

		if ( preg_match( '#^/api/([^/]+)/auth/qr$#', $ruta, $m ) ) {
			return 'SCAN_QR_CODE' === ( $sesiones[ $m[1] ] ?? '' )
				? $responder( 200, $png, 'image/png' )
				: $responder( 422, array( 'message' => 'not scanning' ) );
		}

		if ( preg_match( '#^/api/sessions/([^/]+)(?:/(start|stop|logout))?$#', $ruta, $m ) ) {
			$nombre = $m[1];
			$verbo  = $m[2] ?? '';
			if ( ! isset( $sesiones[ $nombre ] ) ) {
				return $responder( 404, array( 'message' => 'Session not found' ) );
			}
			if ( 'GET' === $metodo ) {
				$datos = array( 'name' => $nombre, 'status' => $sesiones[ $nombre ] );
				if ( 'WORKING' === $sesiones[ $nombre ] ) {
					$datos['me'] = array( 'id' => '593999000333@c.us' );
				}
				return $responder( 200, $datos );
			}
			if ( 'DELETE' === $metodo ) {
				unset( $sesiones[ $nombre ] );
				return $responder( 200, array() );
			}
			if ( 'start' === $verbo ) {
				if ( 'STOPPED' !== $sesiones[ $nombre ] ) {
					return $responder( 422, array( 'message' => 'not stopped' ) );
				}
				$sesiones[ $nombre ] = 'SCAN_QR_CODE';
			} else {
				$sesiones[ $nombre ] = 'STOPPED';
			}
			return $responder( 201, array() );
		}

		return $responder( 500, array( 'message' => 'ruta no simulada' ) );
	},
	10,
	3
);

$e = Edu_Whatsapp::waha_estado();
edu_assert( 'sesión nueva: estado NO_EXISTE', ! is_wp_error( $e ) && 'NO_EXISTE' === $e['estado'] );

$e = Edu_Whatsapp::waha_conectar();
edu_assert( 'conectar crea la sesión y queda esperando QR', ! is_wp_error( $e ) && 'SCAN_QR_CODE' === $e['estado'], is_wp_error( $e ) ? $e->get_error_message() : $e['estado'] );
edu_assert( 'la sesión se crea con el nombre del colegio', isset( $sesiones['colegio-prueba'] ) );

$q = Edu_Whatsapp::waha_qr();
edu_assert( 'el QR llega como data URI PNG', ! is_wp_error( $q ) && 'data:image/png;base64,' . base64_encode( $png ) === $q['qr'] );

// El colegio escanea.
$sesiones['colegio-prueba'] = 'WORKING';
$e = Edu_Whatsapp::waha_estado();
edu_assert( 'escaneado: WORKING con su número', ! is_wp_error( $e ) && 'WORKING' === $e['estado'] && '+593999000333' === $e['numero'] );
$q = Edu_Whatsapp::waha_qr();
edu_assert( 'conectado: ya no hay QR', is_wp_error( $q ) && 'waha_sin_qr' === $q->get_error_code() );

$llamadas = array();
$e        = Edu_Whatsapp::waha_conectar();
edu_assert( 'conectar sobre una conectada no la reinicia', ! is_wp_error( $e ) && 'WORKING' === $e['estado'] && ! in_array( 'POST /api/sessions/colegio-prueba/stop', $llamadas, true ) );

$e = Edu_Whatsapp::waha_desconectar();
edu_assert( 'desconectar la deja detenida', ! is_wp_error( $e ) && 'STOPPED' === $e['estado'] );

$e = Edu_Whatsapp::waha_conectar();
edu_assert( 'reconectar una detenida la arranca con QR', ! is_wp_error( $e ) && 'SCAN_QR_CODE' === $e['estado'] );

$sesiones['colegio-prueba'] = 'FAILED';
$llamadas                   = array();
$e                          = Edu_Whatsapp::waha_revincular();
edu_assert( 'volver a vincular borra y recrea con QR', ! is_wp_error( $e ) && 'SCAN_QR_CODE' === $e['estado'] && in_array( 'DELETE /api/sessions/colegio-prueba', $llamadas, true ) );

// El adaptador AJAX: permiso, nonce y auditoría.
global $wpdb;
$admin_id = edu_test_admin_id();
$max_id   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}edu_audit" );
edu_test_cleanup(
	function () use ( $wpdb, $max_id ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}edu_audit WHERE id > %d AND action = %s", $max_id, 'whatsapp_sesion' ) );
	}
);

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter(
	'wp_die_ajax_handler',
	function () {
		return function () {
			throw new Exception( 'fin' );
		};
	}
);
$ajax = function ( $accion, $user_id ) {
	wp_set_current_user( $user_id );
	$_POST = $_REQUEST = array(
		'action'   => 'edu_waha',
		'accion'   => $accion,
		'_wpnonce' => wp_create_nonce( 'edu_waha' ),
	);
	ob_start();
	try {
		Edu_Settings_Controller::handle_waha();
	} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
	}
	return json_decode( ob_get_clean(), true );
};

$sesiones['colegio-prueba'] = 'STOPPED';
$r = $ajax( 'conectar', $admin_id );
edu_assert( 'AJAX conectar: ok y estado', ! empty( $r['ok'] ) && 'SCAN_QR_CODE' === ( $r['estado'] ?? '' ), wp_json_encode( $r ) );
$auditado = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}edu_audit WHERE id > %d AND action = %s", $max_id, 'whatsapp_sesion' ) );
edu_assert( 'AJAX conectar: queda auditado', 1 === $auditado );

$r = $ajax( 'estado', $admin_id );
$auditado = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}edu_audit WHERE id > %d AND action = %s", $max_id, 'whatsapp_sesion' ) );
edu_assert( 'AJAX estado: no se audita (solo lectura)', ! empty( $r['ok'] ) && 1 === $auditado );

$r = $ajax( 'borrar_todo', $admin_id );
edu_assert( 'AJAX acción desconocida: rechazada', empty( $r['ok'] ) );

$sin_permiso = get_users( array( 'role__not_in' => array( 'administrator' ), 'number' => 1, 'fields' => 'ID' ) );
if ( $sin_permiso ) {
	$llamadas = array();
	$r        = $ajax( 'desconectar', (int) $sin_permiso[0] );
	edu_assert( 'AJAX sin manage_options: rechazado sin tocar WAHA', empty( $r['ok'] ) && array() === $llamadas );
}

$_POST = $_REQUEST = array();
wp_set_current_user( 0 );

edu_test_finish();
