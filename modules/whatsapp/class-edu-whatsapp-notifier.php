<?php
/**
 * Dispatcher de notificaciones WhatsApp.
 *
 * Lógica de envío:
 *   - Si el proveedor es 'meta' Y hay plantilla configurada para el evento
 *     → usa send_template() (funciona sin ventana de 24h, envío proactivo).
 *   - En cualquier otro caso (Twilio, o Meta sin plantilla configurada)
 *     → usa send() con texto libre (requiere ventana de 24h con Meta).
 *
 * Hooks escuchados:
 *   - edu_announcement_sent($announcement_id)
 *   - edu_trimester_closed($student_id, $subject_id, $trimester_id)
 *   - edu_payment_overdue($payment_id, $student_id)
 *   - edu_attendance_absence($student_id, $date, $status)
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Whatsapp_Notifier {

	/* -----------------------------------------------------------------------
	 * Handlers de hooks
	 * -------------------------------------------------------------------- */

	/**
	 * Hook: edu_announcement_sent
	 * Difiere el envío masivo a wp-cron: un comunicado institucional puede
	 * implicar cientos de peticiones a la API de WhatsApp y bloquearía la
	 * petición HTTP del rector si se enviara en línea.
	 */
	public static function on_announcement_sent( int $announcement_id ): void {
		if ( 'disabled' === (string) get_option( 'edu_wa_provider', 'disabled' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( 'edu_wa_send_announcement', array( $announcement_id ) ) ) {
			wp_schedule_single_event( time(), 'edu_wa_send_announcement', array( $announcement_id ) );
		}
	}

	/**
	 * Callback del evento cron edu_wa_send_announcement: hace el envío real.
	 */
	public static function process_announcement( int $announcement_id ): void {
		if ( 'disabled' === (string) get_option( 'edu_wa_provider', 'disabled' ) ) {
			return;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$ann = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$p}announcements WHERE id = %d",
			$announcement_id
		) );

		if ( ! $ann ) {
			return;
		}

		$channels = array_map( 'trim', explode( ',', (string) $ann->channels ) );
		if ( ! in_array( 'whatsapp', $channels, true ) ) {
			return;
		}

		$parents = self::get_parents_for_announcement( $ann );
		if ( empty( $parents ) ) {
			return;
		}

		$site        = get_bloginfo( 'name' );
		$tpl_name    = (string) get_option( 'edu_wa_tpl_comunicado', '' );
		$lang        = (string) get_option( 'edu_wa_tpl_lang', 'es' );
		$titulo      = wp_strip_all_tags( $ann->title );
		$cuerpo      = wp_strip_all_tags( $ann->body );
		$fallback    = "📢 *{$titulo}*\n\n{$cuerpo}\n\n— {$site}";

		$now = current_time( 'mysql' );

		foreach ( $parents as $parent ) {
			if ( empty( $parent->whatsapp ) ) {
				continue;
			}

			$sent = self::dispatch(
				$parent->whatsapp,
				$tpl_name,
				array( $titulo, $cuerpo, $site ),
				$fallback,
				$lang
			);

			if ( $sent && $parent->user_id ) {
				$wpdb->insert(
					"{$p}announcement_recipients",
					array(
						'announcement_id' => $announcement_id,
						'user_id'         => (int) $parent->user_id,
						'channel'         => 'whatsapp',
						'sent_at'         => $now,
					),
					array( '%d', '%d', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * Hook: edu_trimester_closed (prioridad 20, después del cálculo)
	 * Notifica al representante la nota del trimestre.
	 */
	public static function on_trimester_closed( int $student_id, int $subject_id, int $trimester_id ): void {
		if ( ! (int) get_option( 'edu_wa_notify_notas', 0 ) ) {
			return;
		}
		if ( 'disabled' === (string) get_option( 'edu_wa_provider', 'disabled' ) ) {
			return;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
			   COALESCE(um_fn.meta_value,'') AS first_name,
			   COALESCE(um_ln.meta_value,'') AS last_name,
			   ts.computed_score,
			   su.name  AS subject_name,
			   tr.number AS trimester_number
			 FROM {$p}students st
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = st.user_id AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = st.user_id AND um_ln.meta_key = 'last_name'
			 LEFT JOIN {$p}trimester_scores ts
			   ON ts.student_id = st.id AND ts.subject_id = %d AND ts.trimester_id = %d
			 LEFT JOIN {$p}subjects su ON su.id = %d
			 LEFT JOIN {$p}trimesters tr ON tr.id = %d
			 WHERE st.id = %d",
			$subject_id, $trimester_id, $subject_id, $trimester_id, $student_id
		) );

		if ( ! $row || null === $row->computed_score ) {
			return;
		}

		$parents = self::get_parents_for_student( $student_id );
		if ( empty( $parents ) ) {
			return;
		}

		$site     = get_bloginfo( 'name' );
		$nombre   = trim( $row->first_name . ' ' . $row->last_name ) ?: "Estudiante #{$student_id}";
		$materia  = wp_strip_all_tags( $row->subject_name );
		$trim_num = (string) (int) $row->trimester_number;
		$nota     = number_format( (float) $row->computed_score, 2 );

		$tpl_name = (string) get_option( 'edu_wa_tpl_nota', '' );
		$lang     = (string) get_option( 'edu_wa_tpl_lang', 'es' );
		$fallback = "📊 Nota de trimestre — {$site}\n\n👤 {$nombre}\n📚 {$materia}\n🗓️ Trimestre {$trim_num}\n✅ Nota: *{$nota} / 10*\n\n— {$site}";

		// Parámetros: {{1}}=institución {{2}}=estudiante {{3}}=materia {{4}}=trimestre {{5}}=nota
		$tpl_params = array( $site, $nombre, $materia, $trim_num, $nota );

		foreach ( $parents as $parent ) {
			if ( ! empty( $parent->whatsapp ) ) {
				self::dispatch( $parent->whatsapp, $tpl_name, $tpl_params, $fallback, $lang );
			}
		}
	}

	/**
	 * Hook: edu_payment_overdue($payment_id, $student_id)
	 * Notifica al representante que un pago está vencido.
	 */
	public static function on_payment_overdue( int $payment_id, int $student_id ): void {
		if ( ! (int) get_option( 'edu_wa_notify_pagos', 0 ) ) {
			return;
		}
		if ( 'disabled' === (string) get_option( 'edu_wa_provider', 'disabled' ) ) {
			return;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT amount, month, concept, due_date FROM {$p}payments WHERE id = %d",
			$payment_id
		) );

		if ( ! $payment ) {
			return;
		}

		$parents = self::get_parents_for_student( $student_id );
		if ( empty( $parents ) ) {
			return;
		}

		$site     = get_bloginfo( 'name' );
		$nombre   = self::get_student_name( $student_id );
		$concept  = 'pension' === $payment->concept ? 'Pensión' : 'Matrícula';
		$valor    = number_format( (float) $payment->amount, 2 );
		$mes      = (string) $payment->month;
		$vencio   = (string) $payment->due_date;

		$tpl_name = (string) get_option( 'edu_wa_tpl_pago', '' );
		$lang     = (string) get_option( 'edu_wa_tpl_lang', 'es' );
		$fallback = "⚠️ Pago vencido — {$site}\n\n👤 {$nombre}\n💰 {$concept} {$mes}\n💵 Valor: \${$valor}\n📅 Venció: {$vencio}\n\nPor favor regularice su pago lo antes posible.\n\n— {$site}";

		// Parámetros: {{1}}=institución {{2}}=estudiante {{3}}=concepto {{4}}=mes {{5}}=valor {{6}}=vencimiento
		$tpl_params = array( $site, $nombre, $concept, $mes, $valor, $vencio );

		foreach ( $parents as $parent ) {
			if ( ! empty( $parent->whatsapp ) ) {
				self::dispatch( $parent->whatsapp, $tpl_name, $tpl_params, $fallback, $lang );
			}
		}
	}

	/**
	 * Hook: edu_attendance_absence($student_id, $date, $status)
	 * Notifica al representante cuando el estudiante tiene una falta.
	 */
	public static function on_attendance_absence( int $student_id, string $date, string $status ): void {
		if ( ! (int) get_option( 'edu_wa_notify_asistencia', 0 ) ) {
			return;
		}
		if ( 'disabled' === (string) get_option( 'edu_wa_provider', 'disabled' ) ) {
			return;
		}
		if ( ! in_array( $status, array( 'falta_justificada', 'falta_injustificada' ), true ) ) {
			return;
		}

		$parents = self::get_parents_for_student( $student_id );
		if ( empty( $parents ) ) {
			return;
		}

		$site    = get_bloginfo( 'name' );
		$nombre  = self::get_student_name( $student_id );
		$tipo    = 'falta_justificada' === $status ? 'Falta justificada' : 'Falta injustificada';

		$tpl_name = (string) get_option( 'edu_wa_tpl_asistencia', '' );
		$lang     = (string) get_option( 'edu_wa_tpl_lang', 'es' );
		$fallback = "🏫 Asistencia — {$site}\n\n👤 {$nombre}\n📅 {$date}\n❌ {$tipo}\n\nSi tiene alguna consulta, contáctenos.\n\n— {$site}";

		// Parámetros: {{1}}=institución {{2}}=estudiante {{3}}=fecha {{4}}=tipo
		$tpl_params = array( $site, $nombre, $date, $tipo );

		foreach ( $parents as $parent ) {
			if ( ! empty( $parent->whatsapp ) ) {
				self::dispatch( $parent->whatsapp, $tpl_name, $tpl_params, $fallback, $lang );
			}
		}
	}

	/* -----------------------------------------------------------------------
	 * Dispatcher central
	 * -------------------------------------------------------------------- */

	/**
	 * Elige entre plantilla (Meta proactivo) o texto libre (Twilio / ventana 24h Meta).
	 *
	 * @param string   $to            Número del destinatario.
	 * @param string   $tpl_name      Nombre de la plantilla (vacío = sin plantilla configurada).
	 * @param string[] $tpl_params    Parámetros {{1}}, {{2}}, … de la plantilla.
	 * @param string   $fallback_text Texto libre para cuando no hay plantilla.
	 * @param string   $lang          Código de idioma para la plantilla.
	 */
	private static function dispatch(
		string $to,
		string $tpl_name,
		array $tpl_params,
		string $fallback_text,
		string $lang = 'es'
	): bool {
		$provider = (string) get_option( 'edu_wa_provider', 'disabled' );

		if ( 'meta' === $provider && '' !== $tpl_name ) {
			return Edu_Whatsapp::send_template( $to, $tpl_name, $tpl_params, $lang );
		}

		return Edu_Whatsapp::send( $to, $fallback_text );
	}

	/* -----------------------------------------------------------------------
	 * Helpers privados
	 * -------------------------------------------------------------------- */

	private static function get_parents_for_student( int $student_id ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT pa.user_id, pa.whatsapp
			 FROM {$p}parents pa
			 INNER JOIN {$p}parent_student ps ON ps.parent_id = pa.id
			 WHERE ps.student_id = %d
			   AND pa.whatsapp IS NOT NULL AND pa.whatsapp != ''",
			$student_id
		) );
	}

	private static function get_parents_for_announcement( object $ann ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		if ( 'institution' === $ann->scope ) {
			return (array) $wpdb->get_results(
				"SELECT DISTINCT pa.user_id, pa.whatsapp
				 FROM {$p}parents pa
				 INNER JOIN {$p}parent_student ps ON ps.parent_id = pa.id
				 WHERE pa.whatsapp IS NOT NULL AND pa.whatsapp != ''"
			);
		}

		if ( 'grade' === $ann->scope && $ann->target_grade_id ) {
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT pa.user_id, pa.whatsapp
				 FROM {$p}parents pa
				 INNER JOIN {$p}parent_student ps ON ps.parent_id = pa.id
				 INNER JOIN {$p}students st ON st.id = ps.student_id
				 WHERE st.grade_id = %d
				   AND pa.whatsapp IS NOT NULL AND pa.whatsapp != ''",
				(int) $ann->target_grade_id
			) );
		}

		if ( 'student' === $ann->scope && $ann->target_student_id ) {
			return self::get_parents_for_student( (int) $ann->target_student_id );
		}

		return array();
	}

	private static function get_student_name( int $student_id ): string {
		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
			   COALESCE(um_fn.meta_value,'') AS first_name,
			   COALESCE(um_ln.meta_value,'') AS last_name
			 FROM {$p}students st
			 LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = st.user_id AND um_fn.meta_key = 'first_name'
			 LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = st.user_id AND um_ln.meta_key = 'last_name'
			 WHERE st.id = %d",
			$student_id
		) );

		if ( ! $row ) {
			/* translators: %d: ID del estudiante */
			return sprintf( __( 'Estudiante #%d', 'sistema-educativo' ), $student_id );
		}

		$nombre = trim( $row->first_name . ' ' . $row->last_name );
		/* translators: %d: ID del estudiante */
		return $nombre ?: sprintf( __( 'Estudiante #%d', 'sistema-educativo' ), $student_id );
	}
}
