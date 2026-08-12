<?php
/**
 * Contexto de institución y permisos.
 *
 * Resuelve qué institución gestiona el usuario actual y centraliza
 * los checks de capability con bypass para Superadmin Editorial (rol administrator).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Context {

	private static $cached_institution_id = null;

	public static function current_user_id() {
		return get_current_user_id();
	}

	/**
	 * El rol administrator es el Superadmin Editorial: gestiona todas las instituciones.
	 */
	public static function is_superadmin_editorial() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Institución activa para el usuario actual.
	 * - Superadmin: la que tiene seleccionada en user meta `edu_current_institution_id`.
	 * - edu_rector u otros roles edu_*: la institución asignada en user meta `edu_institution_id`.
	 * Devuelve 0 si no hay institución válida o si la guardada fue eliminada.
	 */
	public static function current_institution_id() {
		if ( self::$cached_institution_id !== null ) {
			return self::$cached_institution_id;
		}

		$uid = self::current_user_id();
		if ( ! $uid ) {
			return self::$cached_institution_id = 0;
		}

		$id = self::is_superadmin_editorial()
			? (int) get_user_meta( $uid, 'edu_current_institution_id', true )
			: (int) get_user_meta( $uid, 'edu_institution_id', true );

		if ( ! $id ) {
			return self::$cached_institution_id = 0;
		}

		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}edu_institutions WHERE id = %d",
			$id
		) );

		return self::$cached_institution_id = ( $exists ? $id : 0 );
	}

	public static function set_current_institution_id( $id ) {
		if ( ! self::is_superadmin_editorial() ) {
			return false;
		}
		self::$cached_institution_id = null;
		return update_user_meta( self::current_user_id(), 'edu_current_institution_id', (int) $id );
	}

	/**
	 * Fija la institución solo para esta request, sin persistirla.
	 *
	 * La usa la API cuando el Superadmin Editorial manda la cabecera
	 * X-Edu-Institution: elegir institución desde la app no debe cambiar la
	 * que tiene seleccionada en wp-admin.
	 *
	 * @param int $id ID de institución (ya validado por quien llama).
	 */
	public static function override_institution_id( $id ) {
		self::$cached_institution_id = (int) $id;
	}

	/**
	 * Capability con bypass para Superadmin Editorial.
	 * El administrator de WP no tiene caps `edu_*` (decisión del producto)
	 * pero sí puede operar todo el sistema vía `manage_options`.
	 */
	public static function can( $cap ) {
		return current_user_can( 'manage_options' ) || current_user_can( $cap );
	}

	public static function can_access_institution( $institution_id ) {
		$institution_id = (int) $institution_id;
		if ( ! $institution_id ) {
			return false;
		}
		if ( self::is_superadmin_editorial() ) {
			return true;
		}
		return self::current_institution_id() === $institution_id;
	}

	/**
	 * Instituciones que el usuario actual puede ver.
	 */
	public static function visible_institutions() {
		global $wpdb;
		$table = $wpdb->prefix . 'edu_institutions';

		if ( self::is_superadmin_editorial() ) {
			return $wpdb->get_results( "SELECT id, name FROM $table ORDER BY name" );
		}

		$bound = self::current_institution_id();
		if ( ! $bound ) {
			return array();
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM $table WHERE id = %d", $bound ) );
	}
}
