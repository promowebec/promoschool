<?php
/**
 * Cola de WhatsApp: todo mensaje entra aquí y sale por lotes.
 *
 * Por qué existe: enviar en línea bloqueaba la petición del docente (cerrar un
 * trimestre o pasar lista disparaba un envío por representante) y el comunicado
 * institucional era un solo loop dentro de un cron, que con cientos de
 * destinatarios supera el max_execution_time. Con WAHA, además, un número que
 * manda cientos de mensajes seguidos arriesga el bloqueo de WhatsApp.
 *
 * Reglas:
 *   - Un cron cada minuto envía un lote con presupuesto de tiempo.
 *   - Con WAHA hay pausa aleatoria entre mensajes; Twilio y Meta no la necesitan.
 *   - Tope diario: lo fija la licencia (`limites.whatsapp_dia`). Sin tope en la
 *     licencia no hay tope. Lo que no entra hoy sale mañana, en orden.
 *   - Un mensaje pendiente más de 72 h caduca: un aviso de falta de hace una
 *     semana ya no sirve.
 *   - Tres intentos por mensaje. Tres fallos seguidos cortan el lote (la sesión
 *     casi seguro está caída) sin gastar los intentos del resto.
 *   - Las filas enviadas, fallidas o caducadas se borran a los 30 días: guardan
 *     teléfonos y nombres de estudiantes.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Whatsapp_Queue {

	const CRON        = 'edu_wa_cola';
	const SCHEDULE    = 'edu_cada_minuto';
	const LOCK        = 'edu_wa_cola_lock';
	const INTENTOS    = 3;
	const CADUCA_HORAS = 72;
	const PURGA_DIAS  = 30;

	private static function tabla() {
		global $wpdb;

		return $wpdb->prefix . 'edu_wa_queue';
	}

	/**
	 * Parámetros del procesamiento. Filtrables (`edu_wa_cola_config`) para
	 * ajustarlos por servidor sin tocar código.
	 *
	 * @return array{lote: int, segundos: int, pausa_min: int, pausa_max: int}
	 */
	public static function config() {
		$waha = 'waha' === (string) get_option( 'edu_wa_provider', 'disabled' );

		return (array) apply_filters(
			'edu_wa_cola_config',
			array(
				'lote'      => 20,
				'segundos'  => 45,
				'pausa_min' => $waha ? 4 : 0,
				'pausa_max' => $waha ? 9 : 0,
			)
		);
	}

	/** Tope diario contratado. 0 = sin tope. */
	public static function tope_diario() {
		return Edu_Plan::limite( 'whatsapp_dia' );
	}

	/* ─── Encolar ───────────────────────────────────────────────────────── */

	/**
	 * Agrega un mensaje a la cola. No envía nada.
	 *
	 * @param array $item {
	 *     @type string   $to              Número (se normaliza).
	 *     @type string   $message         Texto libre.
	 *     @type string   $tipo            comunicado | nota | pago | asistencia.
	 *     @type string   $tpl_name        Plantilla Meta (opcional).
	 *     @type string[] $tpl_params      Parámetros de la plantilla.
	 *     @type string   $tpl_lang        Idioma de la plantilla.
	 *     @type int      $announcement_id Comunicado de origen (opcional).
	 *     @type int      $user_id         Destinatario (opcional).
	 * }
	 * @return int Id de la fila, o 0 si el número no sirve.
	 */
	public static function encolar( array $item ) {
		global $wpdb;

		$to = Edu_Whatsapp::normalize_number( (string) ( $item['to'] ?? '' ) );
		if ( '' === $to || '' === (string) ( $item['message'] ?? '' ) ) {
			return 0;
		}

		$wpdb->insert(
			self::tabla(),
			array(
				'to_number'       => $to,
				'message'         => (string) $item['message'],
				'tipo'            => sanitize_key( $item['tipo'] ?? '' ),
				'tpl_name'        => (string) ( $item['tpl_name'] ?? '' ),
				'tpl_params'      => wp_json_encode( array_values( (array) ( $item['tpl_params'] ?? array() ) ) ),
				'tpl_lang'        => (string) ( $item['tpl_lang'] ?? 'es' ),
				'announcement_id' => ! empty( $item['announcement_id'] ) ? (int) $item['announcement_id'] : null,
				'user_id'         => ! empty( $item['user_id'] ) ? (int) $item['user_id'] : null,
				'status'          => 'pending',
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/* ─── Procesar ──────────────────────────────────────────────────────── */

	/**
	 * Envía un lote. Lo llama el cron; también se puede llamar a mano.
	 *
	 * @return array{enviados: int, fallidos: int, motivo: string}
	 */
	public static function procesar() {
		global $wpdb;
		$t       = self::tabla();
		$resumen = array( 'enviados' => 0, 'fallidos' => 0, 'motivo' => '' );

		// Un solo procesador a la vez: wp-cron puede dispararse en paralelo.
		// add_option falla si ya existe; un candado de más de 5 min es de un
		// proceso que murió y se recupera.
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK ) < 300 ) {
				$resumen['motivo'] = 'ocupado';
				return $resumen;
			}
			update_option( self::LOCK, time(), false );
		}

		try {
			$ahora = current_time( 'mysql' );

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$t} SET status = 'expired' WHERE status = 'pending' AND created_at < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( $ahora ) - self::CADUCA_HORAS * HOUR_IN_SECONDS )
			) );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$t} WHERE status <> 'pending' AND created_at < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( $ahora ) - self::PURGA_DIAS * DAY_IN_SECONDS )
			) );

			if ( 'disabled' === (string) get_option( 'edu_wa_provider', 'disabled' ) ) {
				$resumen['motivo'] = 'sin_proveedor';
				return $resumen;
			}

			$cfg   = self::config();
			$lote  = max( 1, (int) $cfg['lote'] );
			$tope  = self::tope_diario();
			if ( $tope > 0 ) {
				$lote = min( $lote, $tope - self::enviados_hoy() );
				if ( $lote <= 0 ) {
					$resumen['motivo'] = 'tope_diario';
					return $resumen;
				}
			}

			$filas = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$t} WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
				$lote
			) );

			$inicio    = microtime( true );
			$seguidos  = 0;
			$primero   = true;

			foreach ( $filas as $fila ) {
				if ( microtime( true ) - $inicio > (int) $cfg['segundos'] ) {
					$resumen['motivo'] = 'tiempo';
					break;
				}

				if ( ! $primero && (int) $cfg['pausa_max'] > 0 ) {
					sleep( wp_rand( (int) $cfg['pausa_min'], (int) $cfg['pausa_max'] ) );
				}
				$primero = false;

				if ( self::enviar_fila( $fila ) ) {
					$seguidos = 0;
					$resumen['enviados']++;
					continue;
				}

				$resumen['fallidos']++;
				if ( ++$seguidos >= 3 ) {
					$resumen['motivo'] = 'fallos_seguidos';
					break;
				}
			}
		} finally {
			delete_option( self::LOCK );
		}

		return $resumen;
	}

	/** Envía una fila y deja su estado. */
	private static function enviar_fila( $fila ) {
		global $wpdb;

		$ok = Edu_Whatsapp_Notifier::dispatch(
			$fila->to_number,
			(string) $fila->tpl_name,
			(array) json_decode( (string) $fila->tpl_params, true ),
			(string) $fila->message,
			(string) $fila->tpl_lang
		);

		$intentos = (int) $fila->attempts + 1;

		if ( $ok ) {
			$wpdb->update(
				self::tabla(),
				array( 'status' => 'sent', 'attempts' => $intentos, 'sent_at' => current_time( 'mysql' ) ),
				array( 'id' => (int) $fila->id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);

			if ( $fila->announcement_id && $fila->user_id ) {
				$wpdb->insert(
					$wpdb->prefix . 'edu_announcement_recipients',
					array(
						'announcement_id' => (int) $fila->announcement_id,
						'user_id'         => (int) $fila->user_id,
						'channel'         => 'whatsapp',
						'sent_at'         => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s' )
				);
			}

			return true;
		}

		$wpdb->update(
			self::tabla(),
			array(
				'status'   => $intentos >= self::INTENTOS ? 'failed' : 'pending',
				'attempts' => $intentos,
			),
			array( 'id' => (int) $fila->id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		return false;
	}

	/* ─── Estado ────────────────────────────────────────────────────────── */

	/** Mensajes enviados desde la medianoche (hora del sitio). */
	public static function enviados_hoy() {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::tabla() . " WHERE status = 'sent' AND sent_at >= %s",
			current_time( 'Y-m-d' ) . ' 00:00:00'
		) );
	}

	/**
	 * Para la pantalla de Ajustes.
	 *
	 * @return array{pendientes: int, enviados_hoy: int, fallidos: int, caducados: int, tope: int}
	 */
	public static function resumen() {
		global $wpdb;
		$t     = self::tabla();
		$desde = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 7 * DAY_IN_SECONDS );

		return array(
			'pendientes'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'pending'" ),
			'enviados_hoy' => self::enviados_hoy(),
			'fallidos'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'failed' AND created_at >= %s", $desde ) ),
			'caducados'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'expired' AND created_at >= %s", $desde ) ),
			'tope'         => self::tope_diario(),
		);
	}

	/* ─── Cron ──────────────────────────────────────────────────────────── */

	/** Filtro cron_schedules: intervalo de un minuto. */
	public static function schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Cada minuto (cola de WhatsApp)', 'sistema-educativo' ),
		);

		return $schedules;
	}

	public static function programar() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON );
		}
	}

	public static function desprogramar() {
		wp_unschedule_hook( self::CRON );
	}
}
