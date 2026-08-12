<?php
/**
 * Clase auxiliar de auditoría del sistema.
 *
 * Centraliza la escritura en wp_edu_audit. Cualquier controlador puede llamar
 * Edu_Audit::log() para registrar una acción con contexto completo.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Audit {

	/* ─── Constantes de tipo de acción ──────────────────────────────────── */
	const NOTA_INGRESADA      = 'nota_ingresada';
	const EXAMEN_GUARDADO     = 'examen_guardado';
	const PARCIAL_CERRADO     = 'parcial_cerrado';
	const TRIMESTRE_CERRADO   = 'trimestre_cerrado';
	const TAREA_CREADA        = 'tarea_creada';
	const TAREA_EDITADA       = 'tarea_editada';
	const TAREA_PUBLICADA     = 'tarea_publicada';
	const TAREA_CERRADA       = 'tarea_cerrada';
	const TAREA_ELIMINADA     = 'tarea_eliminada';
	const ENTREGA_CALIFICADA      = 'entrega_calificada';
	const ASISTENCIA_GUARDADA     = 'asistencia_guardada';
	const COMUNICADO_ENVIADO      = 'comunicado_enviado';
	const RECUPERACION_GUARDADA   = 'recuperacion_guardada';
	const CUENTA_SUSPENDIDA       = 'cuenta_suspendida';
	const CUENTA_ACTIVADA         = 'cuenta_activada';

	/**
	 * Registra una entrada en el log de auditoría.
	 *
	 * @param string      $action       Tipo de acción (usar constantes de esta clase).
	 * @param string      $entity_type  Tipo de entidad ('nota', 'tarea', 'asistencia', …).
	 * @param int|null    $entity_id    ID de la entidad afectada.
	 * @param mixed|null  $old_value    Valor anterior (string, número o array → JSON).
	 * @param mixed|null  $new_value    Valor nuevo  (string, número o array → JSON).
	 */
	public static function log( $action, $entity_type, $entity_id = null, $old_value = null, $new_value = null ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'edu_audit',
			array(
				'user_id'     => get_current_user_id(),
				'action'      => substr( sanitize_key( $action ), 0, 50 ),
				'entity_type' => substr( sanitize_key( $entity_type ), 0, 50 ),
				'entity_id'   => $entity_id ? (int) $entity_id : null,
				'old_value'   => self::encode_value( $old_value ),
				'new_value'   => self::encode_value( $new_value ),
				'ip_address'  => self::get_ip(),
				'user_agent'  => self::get_ua(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/* ─── Helpers internos ──────────────────────────────────────────────── */

	private static function encode_value( $value ) {
		if ( $value === null ) {
			return null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value ) ?: null;
		}
		return (string) $value;
	}

	private static function get_ip() {
		$candidates = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return substr( $ip, 0, 45 );
				}
			}
		}
		return null;
	}

	private static function get_ua() {
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
		}
		return null;
	}

	/* ─── Etiquetas legibles ────────────────────────────────────────────── */

	public static function action_label( $action ) {
		$labels = array(
			self::NOTA_INGRESADA      => __( 'Nota ingresada',      'sistema-educativo' ),
			self::EXAMEN_GUARDADO     => __( 'Examen guardado',     'sistema-educativo' ),
			self::PARCIAL_CERRADO     => __( 'Parcial cerrado',     'sistema-educativo' ),
			self::TRIMESTRE_CERRADO   => __( 'Trimestre cerrado',   'sistema-educativo' ),
			self::TAREA_CREADA        => __( 'Tarea creada',        'sistema-educativo' ),
			self::TAREA_EDITADA       => __( 'Tarea editada',       'sistema-educativo' ),
			self::TAREA_PUBLICADA     => __( 'Tarea publicada',     'sistema-educativo' ),
			self::TAREA_CERRADA       => __( 'Tarea cerrada',       'sistema-educativo' ),
			self::TAREA_ELIMINADA     => __( 'Tarea eliminada',     'sistema-educativo' ),
			self::ENTREGA_CALIFICADA  => __( 'Entrega calificada',  'sistema-educativo' ),
			self::ASISTENCIA_GUARDADA   => __( 'Asistencia guardada',   'sistema-educativo' ),
			self::COMUNICADO_ENVIADO    => __( 'Comunicado enviado',    'sistema-educativo' ),
			self::RECUPERACION_GUARDADA => __( 'Recuperación guardada', 'sistema-educativo' ),
			self::CUENTA_SUSPENDIDA     => __( 'Cuenta suspendida',     'sistema-educativo' ),
			self::CUENTA_ACTIVADA       => __( 'Cuenta activada',       'sistema-educativo' ),
		);
		return $labels[ $action ] ?? esc_html( $action );
	}

	public static function action_color( $action ) {
		$colors = array(
			self::NOTA_INGRESADA      => '#7c3aed',
			self::EXAMEN_GUARDADO     => '#7c3aed',
			self::PARCIAL_CERRADO     => '#b45309',
			self::TRIMESTRE_CERRADO   => '#dc2626',
			self::TAREA_CREADA        => '#1d4ed8',
			self::TAREA_EDITADA       => '#0369a1',
			self::TAREA_PUBLICADA     => '#16a34a',
			self::TAREA_CERRADA       => '#6b7280',
			self::TAREA_ELIMINADA     => '#dc2626',
			self::ENTREGA_CALIFICADA  => '#7c3aed',
			self::ASISTENCIA_GUARDADA   => '#059669',
			self::COMUNICADO_ENVIADO    => '#0369a1',
			self::RECUPERACION_GUARDADA => '#d97706',
			self::CUENTA_SUSPENDIDA     => '#dc2626',
			self::CUENTA_ACTIVADA       => '#16a34a',
		);
		return $colors[ $action ] ?? '#374151';
	}
}
