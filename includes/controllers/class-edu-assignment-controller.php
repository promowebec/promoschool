<?php
/**
 * Controller: ABM de asignaciones académicas (wp_edu_teacher_assignments).
 *
 * Vincula docente ↔ grado ↔ materia ↔ período. UNIQUE en los 4 campos.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Assignment_Controller {

	public static function handle_save() {
		check_admin_referer( 'edu_save_assignment' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		$teacher_id  = isset( $_POST['teacher_id'] ) ? (int) $_POST['teacher_id'] : 0;
		$grade_id    = isset( $_POST['grade_id'] ) ? (int) $_POST['grade_id'] : 0;
		$subject_id  = isset( $_POST['subject_id'] ) ? (int) $_POST['subject_id'] : 0;
		$period_id   = isset( $_POST['period_id'] ) ? (int) $_POST['period_id'] : 0;
		$is_active   = ! empty( $_POST['is_active'] ) ? 1 : 0;

		global $wpdb;
		$ta  = $wpdb->prefix . 'edu_teacher_assignments';
		$tt  = $wpdb->prefix . 'edu_teachers';
		$tg  = $wpdb->prefix . 'edu_grades';
		$tsu = $wpdb->prefix . 'edu_subjects';
		$tp  = $wpdb->prefix . 'edu_periods';

		// Validar que cada referencia pertenece a la institución actual.
		if ( ! self::teacher_belongs( $teacher_id, $institution_id ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'invalid_teacher' ) );
		}
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tg WHERE id = %d AND institution_id = %d", $grade_id, $institution_id ) ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'invalid_grade' ) );
		}
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tsu WHERE id = %d AND institution_id = %d", $subject_id, $institution_id ) ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'invalid_subject' ) );
		}
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE id = %d AND institution_id = %d", $period_id, $institution_id ) ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'invalid_period' ) );
		}

		// Duplicado por (teacher, grade, subject, period).
		$dup_sql = $wpdb->prepare(
			"SELECT id FROM $ta WHERE teacher_id = %d AND grade_id = %d AND subject_id = %d AND period_id = %d",
			$teacher_id, $grade_id, $subject_id, $period_id
		);
		if ( $id ) {
			$dup_sql .= $wpdb->prepare( ' AND id <> %d', $id );
		}
		if ( $wpdb->get_var( $dup_sql ) ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'duplicate' ) );
		}

		$data    = array(
			'teacher_id' => $teacher_id,
			'grade_id'   => $grade_id,
			'subject_id' => $subject_id,
			'period_id'  => $period_id,
			'is_active'  => $is_active,
		);
		$formats = array( '%d', '%d', '%d', '%d', '%d' );

		if ( $id > 0 ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ta WHERE id = %d", $id ) );
			if ( ! $exists ) {
				self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
			}
			$wpdb->update( $ta, $data, array( 'id' => $id ), $formats, array( '%d' ) );
			$saved_id = $id;
		} else {
			$wpdb->insert( $ta, $data, $formats );
			$saved_id = (int) $wpdb->insert_id;
		}

		self::redirect( array( 'action' => 'edit', 'id' => $saved_id, 'status' => 'updated' ) );
	}

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_assignment' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$ta = $wpdb->prefix . 'edu_teacher_assignments';
		$tg = $wpdb->prefix . 'edu_grades';

		// Verificar scoping vía el grado.
		$assign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id = %d", $id ) );
		if ( ! $assign ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}
		$grade_inst = (int) $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM $tg WHERE id = %d", $assign->grade_id ) );
		if ( ! Edu_Context::is_superadmin_editorial() && $grade_inst !== $institution_id ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		$wpdb->delete( $ta, array( 'id' => $id ), array( '%d' ) );

		self::redirect( array( 'status' => 'deleted' ) );
	}

	private static function teacher_belongs( $teacher_id, $institution_id ) {
		global $wpdb;
		$tt = $wpdb->prefix . 'edu_teachers';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id FROM $tt WHERE id = %d", $teacher_id ) );
		if ( ! $row ) {
			return false;
		}
		if ( Edu_Context::is_superadmin_editorial() ) {
			return true;
		}
		$bound = (int) get_user_meta( $row->user_id, 'edu_institution_id', true );
		return $bound === (int) $institution_id;
	}

	private static function guard() {
		if ( ! Edu_Context::can( 'edu_manage_assignments' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}
		if ( ! Edu_Context::current_institution_id() ) {
			wp_die( esc_html__( 'No hay institución activa.', 'sistema-educativo' ) );
		}
	}

	private static function redirect( $args = array() ) {
		$args = array_merge( array( 'page' => 'edu-asignaciones' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
