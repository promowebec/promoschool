<?php
/**
 * Controller: ABM de instituciones.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Institution_Controller {

	public static function handle_save() {
		check_admin_referer( 'edu_save_institution' );

		if ( ! Edu_Context::can( 'edu_view_all' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'edu_institutions';

		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( '' === $name ) {
			self::redirect( array(
				'action' => $id ? 'edit' : 'new',
				'id'     => $id,
				'status' => 'error',
				'code'   => 'name_required',
			) );
		}

		// edu_rector solo puede editar su propia institución; no puede crear nuevas.
		if ( ! Edu_Context::is_superadmin_editorial() ) {
			$bound = Edu_Context::current_institution_id();
			if ( ! $bound || $id !== $bound ) {
				wp_die( esc_html__( 'Solo puedes editar tu propia institución.', 'sistema-educativo' ) );
			}
		}

		$regime = in_array( $_POST['regime'] ?? '', array( 'sierra', 'costa' ), true ) ? $_POST['regime'] : 'sierra';

		$data = array(
			'name'     => $name,
			'ruc'      => sanitize_text_field( wp_unslash( $_POST['ruc'] ?? '' ) ),
			'address'  => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
			'phone'    => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'email'    => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'logo_url' => esc_url_raw( wp_unslash( $_POST['logo_url'] ?? '' ) ),
			'regime'   => $regime,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $id > 0 ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d", $id ) );
			if ( ! $exists ) {
				self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
			}
			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$saved_id = $id;
		} else {
			$wpdb->insert( $table, $data, $formats );
			$saved_id = (int) $wpdb->insert_id;
			// Auto-seleccionar como institución activa para el superadmin que la creó.
			if ( Edu_Context::is_superadmin_editorial() ) {
				Edu_Context::set_current_institution_id( $saved_id );
			}
		}

		self::redirect( array( 'action' => 'edit', 'id' => $saved_id, 'status' => 'updated' ) );
	}

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_institution' );

		if ( ! Edu_Context::is_superadmin_editorial() ) {
			wp_die( esc_html__( 'Solo el Superadmin Editorial puede eliminar instituciones.', 'sistema-educativo' ) );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			self::redirect( array( 'status' => 'error', 'code' => 'invalid_id' ) );
		}

		global $wpdb;
		$p = $wpdb->prefix . 'edu_';

		// Cascada manual (dbDelta no aplica FKs).
		$period_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}periods WHERE institution_id = %d", $id ) );
		if ( ! empty( $period_ids ) ) {
			$in = implode( ',', array_map( 'intval', $period_ids ) );
			$wpdb->query( "DELETE FROM {$p}trimesters WHERE period_id IN ($in)" );
			$wpdb->query( "DELETE FROM {$p}periods WHERE id IN ($in)" );
		}
		$wpdb->delete( "{$p}grades", array( 'institution_id' => $id ), array( '%d' ) );
		$wpdb->delete( "{$p}subjects", array( 'institution_id' => $id ), array( '%d' ) );
		$wpdb->delete( "{$p}institutions", array( 'id' => $id ), array( '%d' ) );

		if ( Edu_Context::current_institution_id() === $id ) {
			Edu_Context::set_current_institution_id( 0 );
		}

		self::redirect( array( 'status' => 'deleted' ) );
	}

	public static function handle_set_active() {
		check_admin_referer( 'edu_set_active_institution' );

		if ( ! Edu_Context::is_superadmin_editorial() ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$id = isset( $_POST['institution_id'] ) ? (int) $_POST['institution_id'] : 0;
		Edu_Context::set_current_institution_id( $id );

		$referer  = isset( $_POST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) ) : '';
		$redirect = $referer ? $referer : admin_url( 'admin.php?page=edu-inicio' );
		wp_safe_redirect( $redirect );
		exit;
	}

	private static function redirect( $args = array() ) {
		$args = array_merge( array( 'page' => 'edu-institucion' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
