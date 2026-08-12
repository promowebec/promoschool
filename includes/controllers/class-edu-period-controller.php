<?php
/**
 * Controller: ABM de períodos lectivos y generación automática de trimestres.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Period_Controller {

	public static function handle_save() {
		check_admin_referer( 'edu_save_period' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		global $wpdb;
		$tp = $wpdb->prefix . 'edu_periods';
		$tt = $wpdb->prefix . 'edu_trimesters';

		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( '' === $name ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'name_required' ) );
		}

		$regime     = in_array( $_POST['regime'] ?? '', array( 'sierra', 'costa' ), true ) ? $_POST['regime'] : 'sierra';
		$start_date = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) );
		$end_date   = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) );

		if ( ! self::is_valid_date_range( $start_date, $end_date ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'invalid_dates' ) );
		}

		$working_days   = max( 1, min( 365, (int) ( $_POST['working_days'] ?? 200 ) ) );
		$num_trimesters = max( 1, min( 5, (int) ( $_POST['num_trimesters'] ?? 3 ) ) );

		// Duplicado por nombre dentro de la misma institución.
		$dup_sql = $wpdb->prepare( "SELECT id FROM $tp WHERE institution_id = %d AND name = %s", $institution_id, $name );
		if ( $id ) {
			$dup_sql .= $wpdb->prepare( ' AND id <> %d', $id );
		}
		if ( $wpdb->get_var( $dup_sql ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'duplicate' ) );
		}

		$data = array(
			'institution_id' => $institution_id,
			'name'           => $name,
			'regime'         => $regime,
			'start_date'     => $start_date,
			'end_date'       => $end_date,
			'working_days'   => $working_days,
			'num_trimesters' => $num_trimesters,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%d', '%d' );

		if ( $id > 0 ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tp WHERE id = %d AND institution_id = %d", $id, $institution_id ) );
			if ( ! $existing ) {
				self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
			}
			$wpdb->update( $tp, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$saved_id = $id;

			// Regenerar trimestres si cambiaron fechas o cantidad.
			$regen = ( (int) $existing->num_trimesters !== $num_trimesters )
				|| ( $existing->start_date !== $start_date )
				|| ( $existing->end_date !== $end_date );
			if ( $regen ) {
				$wpdb->delete( $tt, array( 'period_id' => $id ), array( '%d' ) );
				self::generate_trimesters( $saved_id, $start_date, $end_date, $num_trimesters );
			}
		} else {
			$wpdb->insert( $tp, $data, $formats );
			$saved_id = (int) $wpdb->insert_id;
			self::generate_trimesters( $saved_id, $start_date, $end_date, $num_trimesters );
		}

		self::redirect( array( 'action' => 'edit', 'id' => $saved_id, 'status' => 'updated' ) );
	}

	public static function handle_activate() {
		check_admin_referer( 'edu_activate_period' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$tp = $wpdb->prefix . 'edu_periods';

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE id = %d AND institution_id = %d", $id, $institution_id ) );
		if ( ! $exists ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}

		// Período activo único POR institución: desactivar otros antes.
		$wpdb->update( $tp, array( 'is_active' => 0 ), array( 'institution_id' => $institution_id ), array( '%d' ), array( '%d' ) );
		$wpdb->update( $tp, array( 'is_active' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );

		self::redirect( array( 'status' => 'activated' ) );
	}

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_period' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$tp = $wpdb->prefix . 'edu_periods';
		$tt = $wpdb->prefix . 'edu_trimesters';

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE id = %d AND institution_id = %d", $id, $institution_id ) );
		if ( ! $exists ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}

		$wpdb->delete( $tt, array( 'period_id' => $id ), array( '%d' ) );
		$wpdb->delete( $tp, array( 'id' => $id ), array( '%d' ) );

		self::redirect( array( 'status' => 'deleted' ) );
	}

	private static function guard() {
		if ( ! Edu_Context::can( 'edu_manage_grades' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}
		if ( ! Edu_Context::current_institution_id() ) {
			wp_die( esc_html__( 'No hay institución activa.', 'sistema-educativo' ) );
		}
	}

	private static function is_valid_date_range( $start, $end ) {
		if ( '' === $start || '' === $end ) {
			return false;
		}
		$s = strtotime( $start );
		$e = strtotime( $end );
		if ( ! $s || ! $e ) {
			return false;
		}
		return $e > $s;
	}

	/**
	 * Divide el rango de fechas en N trimestres de duración similar.
	 * El último trimestre absorbe los días residuales.
	 */
	private static function generate_trimesters( $period_id, $start_date, $end_date, $num ) {
		global $wpdb;
		$tt = $wpdb->prefix . 'edu_trimesters';

		$start      = new DateTime( $start_date );
		$end        = new DateTime( $end_date );
		$total_days = $start->diff( $end )->days + 1;
		$per_tri    = (int) floor( $total_days / max( 1, $num ) );

		$cursor = clone $start;
		for ( $i = 1; $i <= $num; $i++ ) {
			$t_start = clone $cursor;
			if ( $i < $num ) {
				$t_end = clone $cursor;
				$t_end->modify( '+' . ( $per_tri - 1 ) . ' days' );
				$cursor = clone $t_end;
				$cursor->modify( '+1 day' );
			} else {
				$t_end = clone $end;
			}
			$wpdb->insert(
				$tt,
				array(
					'period_id'  => $period_id,
					'number'     => $i,
					'start_date' => $t_start->format( 'Y-m-d' ),
					'end_date'   => $t_end->format( 'Y-m-d' ),
					'is_closed'  => 0,
				),
				array( '%d', '%d', '%s', '%s', '%d' )
			);
		}
	}

	private static function redirect( $args = array() ) {
		$args = array_merge( array( 'page' => 'edu-periodos' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
