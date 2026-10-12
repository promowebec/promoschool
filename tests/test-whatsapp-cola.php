<?php
/**
 * Cola de WhatsApp: los mensajes salen por lotes, con tope diario.
 *
 *   1. Encolar no envía nada; un número vacío no entra.
 *   2. Cada pasada envía como mucho un lote, en orden de llegada.
 *   3. El tope diario de la licencia se respeta; sin licencia no hay tope.
 *   4. Un comunicado registra al destinatario solo cuando el envío salió.
 *   5. Reintentos: tres fallos y el mensaje queda fallido; tres fallos
 *      seguidos cortan el lote sin gastar los intentos del resto.
 *   6. Lo pendiente más de 72 h caduca; no hay dos procesadores a la vez.
 *   7. Los avisos (faltas) se encolan en vez de enviarse en la petición.
 *
 * WAHA simulado con `pre_http_request`: ningún mensaje sale. Los números son
 * ficticios.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'Cola de WhatsApp por lotes' );

global $wpdb;
$t  = $wpdb->prefix . 'edu_wa_queue';
$tr = $wpdb->prefix . 'edu_announcement_recipients';

if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
	edu_assert( 'la migración creó wp_edu_wa_queue', false );
	edu_test_finish();
	exit;
}

$max_cola = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$t}" );
$max_dest = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$tr}" );

$opciones = array( 'edu_wa_provider', 'edu_wa_waha_url', 'edu_wa_waha_key', 'edu_wa_waha_session', 'edu_wa_notify_asistencia', Edu_Whatsapp_Queue::LOCK );
$antes    = array();
foreach ( $opciones as $opcion ) {
	$antes[ $opcion ] = get_option( $opcion, null );
}
edu_test_cleanup(
	function () use ( $antes, $wpdb, $t, $tr, $max_cola, $max_dest ) {
		foreach ( $antes as $opcion => $valor ) {
			null === $valor ? delete_option( $opcion ) : update_option( $opcion, $valor );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id > %d", $max_cola ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tr} WHERE id > %d AND channel = 'whatsapp'", $max_dest ) );
		Edu_Plan::reset();
	}
);

// Lo que ya hubiera pendiente en la cola de este sitio no participa: se aparta
// y al terminar vuelve a quedar pendiente.
$apartados = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$t} WHERE status = 'pending'" ) );
if ( $apartados ) {
	$wpdb->query( "UPDATE {$t} SET status = 'expired' WHERE id IN (" . implode( ',', $apartados ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids pasados por intval.
	edu_test_cleanup(
		function () use ( $wpdb, $t, $apartados ) {
			$wpdb->query( "UPDATE {$t} SET status = 'pending' WHERE id IN (" . implode( ',', $apartados ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	);
}

update_option( 'edu_wa_provider', 'waha' );
update_option( 'edu_wa_waha_url', 'https://waha.prueba.test' );
update_option( 'edu_wa_waha_key', 'k' );
update_option( 'edu_wa_waha_session', 'colegio-prueba' );
delete_option( Edu_Whatsapp_Queue::LOCK );

$lote = 5;
add_filter(
	'edu_wa_cola_config',
	function ( $cfg ) use ( &$lote ) {
		return array_merge( $cfg, array( 'lote' => $lote, 'pausa_min' => 0, 'pausa_max' => 0 ) );
	}
);

// WAHA simulado: los números de $rechazar devuelven 422.
$enviados = array();
$rechazar = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$enviados, &$rechazar ) {
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$num  = (string) ( $body['chatId'] ?? '' );
		$code = in_array( $num, $rechazar, true ) ? 422 : 201;
		if ( 201 === $code ) {
			$enviados[] = $num;
		}
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

/** Inyecta un plan con tope diario (o sin licencia) en la caché de Edu_Plan. */
$par     = sodium_crypto_sign_keypair();
$plan_con = function ( $tope ) use ( $par ) {
	$payload = array(
		'lid'     => 'PS-TEST-COLA',
		'cliente' => 'Prueba',
		'hosts'   => array( Edu_Plan::host_actual() ),
		'edicion' => 'completa',
		'modulos' => array_keys( Edu_Modules::catalog() ),
		'limites' => $tope ? array( 'whatsapp_dia' => $tope ) : array(),
	);
	$cuerpo  = Edu_Plan::PREFIJO . '.' . Edu_Plan::b64url_encode( wp_json_encode( $payload ) );
	$lic     = $cuerpo . '.' . Edu_Plan::b64url_encode( sodium_crypto_sign_detached( $cuerpo, sodium_crypto_sign_secretkey( $par ) ) );
	$estado  = Edu_Plan::resolver( $lic, base64_encode( sodium_crypto_sign_publickey( $par ) ), Edu_Plan::host_actual(), 'heredado' );
	$prop    = new ReflectionProperty( 'Edu_Plan', 'estado' );
	$prop->setAccessible( true );
	$prop->setValue( null, $estado );
};

$estado_de = function ( $id ) use ( $wpdb, $t ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT status, attempts FROM {$t} WHERE id = %d", $id ) );
};

/* ── 1. Encolar ─────────────────────────────────────────────────────────── */

$plan_con( 0 );
edu_assert( 'sin tope en la licencia: tope 0', 0 === Edu_Whatsapp_Queue::tope_diario() );
edu_assert( 'número vacío no entra', 0 === Edu_Whatsapp_Queue::encolar( array( 'to' => '', 'message' => 'x' ) ) );

$ids = array();
for ( $i = 1; $i <= 7; $i++ ) {
	$ids[] = Edu_Whatsapp_Queue::encolar( array( 'to' => '09990001' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ), 'message' => "Mensaje $i", 'tipo' => 'nota' ) );
}
edu_assert( 'encolar no envía', array() === $enviados && 7 === count( array_filter( $ids ) ) );
$fila = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $ids[0] ) );
edu_assert( 'el número se guarda normalizado', '+593999000101' === $fila->to_number, $fila->to_number );

/* ── 2. Lotes ───────────────────────────────────────────────────────────── */

$r = Edu_Whatsapp_Queue::procesar();
edu_assert( 'una pasada envía solo el lote (5)', 5 === $r['enviados'] && 5 === count( $enviados ), wp_json_encode( $r ) );
edu_assert( 'en orden de llegada', '593999000101@c.us' === $enviados[0] && '593999000105@c.us' === $enviados[4] );
edu_assert( 'quedan 2 pendientes', 'pending' === $estado_de( $ids[5] )->status && 'pending' === $estado_de( $ids[6] )->status );
edu_assert( 'los enviados quedan sent', 'sent' === $estado_de( $ids[0] )->status );

$r = Edu_Whatsapp_Queue::procesar();
edu_assert( 'la siguiente pasada envía el resto', 2 === $r['enviados'] && 7 === count( $enviados ) );

/* ── 3. Tope diario de la licencia ──────────────────────────────────────── */

$hoy = Edu_Whatsapp_Queue::enviados_hoy();
$plan_con( $hoy + 3 );
edu_assert( 'la licencia fija el tope', $hoy + 3 === Edu_Whatsapp_Queue::tope_diario() );

$ids = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$ids[] = Edu_Whatsapp_Queue::encolar( array( 'to' => '09990002' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ), 'message' => "Tope $i" ) );
}
$enviados = array();
$r        = Edu_Whatsapp_Queue::procesar();
edu_assert( 'con tope, solo salen los que faltan para llegar (3)', 3 === $r['enviados'], wp_json_encode( $r ) );
$r = Edu_Whatsapp_Queue::procesar();
edu_assert( 'tope alcanzado: no sale nada más hoy', 0 === $r['enviados'] && 'tope_diario' === $r['motivo'], wp_json_encode( $r ) );
edu_assert( 'lo que no salió sigue pendiente para mañana', 'pending' === $estado_de( $ids[5] )->status );
$resumen = Edu_Whatsapp_Queue::resumen();
edu_assert( 'el resumen de Ajustes informa el tope', $hoy + 3 === $resumen['tope'] && $resumen['pendientes'] >= 3 );

$plan_con( 0 );
$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'expired' WHERE id > %d AND status = 'pending'", $max_cola ) );

/* ── 4. Comunicados: destinatario registrado al enviar ──────────────────── */

$padre = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}edu_parents WHERE user_id > 0 LIMIT 1" );
if ( $padre ) {
	$id = Edu_Whatsapp_Queue::encolar( array( 'to' => '0999000301', 'message' => 'Comunicado', 'tipo' => 'comunicado', 'announcement_id' => 999999, 'user_id' => $padre ) );
	$antes_envio = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tr} WHERE announcement_id = 999999 AND id > %d", $max_dest ) );
	Edu_Whatsapp_Queue::procesar();
	$despues = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tr} WHERE announcement_id = 999999 AND user_id = %d AND channel = 'whatsapp' AND id > %d", $padre, $max_dest ) );
	edu_assert( 'comunicado: destinatario registrado solo tras enviar', 0 === $antes_envio && 1 === $despues );
}

/* ── 5. Reintentos y corte por fallos seguidos ──────────────────────────── */

$rechazar = array( '593999000401@c.us', '593999000402@c.us', '593999000403@c.us' );
$ids      = array();
foreach ( array( '0999000401', '0999000402', '0999000403', '0999000404' ) as $n ) {
	$ids[] = Edu_Whatsapp_Queue::encolar( array( 'to' => $n, 'message' => 'Falla' ) );
}
$r = Edu_Whatsapp_Queue::procesar();
edu_assert( 'tres fallos seguidos cortan el lote', 'fallos_seguidos' === $r['motivo'] && 3 === $r['fallidos'], wp_json_encode( $r ) );
edu_assert( 'el cuarto no gastó intento', 0 === (int) $estado_de( $ids[3] )->attempts );
edu_assert( 'tras un fallo sigue pendiente', 'pending' === $estado_de( $ids[0] )->status && 1 === (int) $estado_de( $ids[0] )->attempts );

Edu_Whatsapp_Queue::procesar();
Edu_Whatsapp_Queue::procesar();
edu_assert( 'al tercer intento queda fallido', 'failed' === $estado_de( $ids[0] )->status && 3 === (int) $estado_de( $ids[0] )->attempts );

$rechazar = array();
Edu_Whatsapp_Queue::procesar();
edu_assert( 'el que no fallaba termina enviándose', 'sent' === $estado_de( $ids[3] )->status );

/* ── 6. Caducidad y candado ─────────────────────────────────────────────── */

$id = Edu_Whatsapp_Queue::encolar( array( 'to' => '0999000501', 'message' => 'Viejo' ) );
$wpdb->update( $t, array( 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 73 * HOUR_IN_SECONDS ) ), array( 'id' => $id ) );
$enviados = array();
Edu_Whatsapp_Queue::procesar();
edu_assert( 'más de 72 h en cola: caduca sin enviarse', 'expired' === $estado_de( $id )->status && array() === $enviados );

$id = Edu_Whatsapp_Queue::encolar( array( 'to' => '0999000502', 'message' => 'Con candado' ) );
add_option( Edu_Whatsapp_Queue::LOCK, time(), '', false );
$r = Edu_Whatsapp_Queue::procesar();
edu_assert( 'con otro procesador activo no se envía', 'ocupado' === $r['motivo'] && 'pending' === $estado_de( $id )->status );
update_option( Edu_Whatsapp_Queue::LOCK, time() - 600, false );
$r = Edu_Whatsapp_Queue::procesar();
edu_assert( 'un candado de un proceso muerto se recupera', 1 === $r['enviados'] );
edu_assert( 'el candado se libera al terminar', null === get_option( Edu_Whatsapp_Queue::LOCK, null ) );

/* ── 7. Los avisos se encolan ───────────────────────────────────────────── */

$alumno = (int) $wpdb->get_var(
	"SELECT ps.student_id FROM {$wpdb->prefix}edu_parent_student ps
	 JOIN {$wpdb->prefix}edu_parents pa ON pa.id = ps.parent_id
	 WHERE pa.whatsapp IS NOT NULL AND pa.whatsapp <> '' LIMIT 1"
);
if ( $alumno ) {
	update_option( 'edu_wa_notify_asistencia', 1 );
	$enviados = array();
	$antes_n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE id > %d AND tipo = 'asistencia'", $max_cola ) );
	Edu_Whatsapp_Notifier::on_attendance_absence( $alumno, '2026-10-12', 'falta_injustificada' );
	$despues_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE id > %d AND tipo = 'asistencia'", $max_cola ) );
	edu_assert( 'una falta encola el aviso y no envía en la petición', $despues_n > $antes_n && array() === $enviados );
} else {
	echo "   (sin estudiante con representante con WhatsApp: se omite la comprobación 7)\n";
}

edu_test_finish();
