<?php
/**
 * Controller: ABM de materias (propias) y adopción desde el catálogo Mineduc.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Subject_Controller {

	public static function handle_save() {
		check_admin_referer( 'edu_save_subject' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$t = $wpdb->prefix . 'edu_subjects';

		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		$area = sanitize_text_field( wp_unslash( $_POST['area'] ?? '' ) );

		if ( '' === $name ) {
			self::redirect( array( 'action' => $id ? 'edit' : 'new', 'id' => $id, 'status' => 'error', 'code' => 'name_required' ) );
		}

		$data = array(
			'institution_id' => $institution_id,
			'name'           => $name,
			'code'           => $code ?: null,
			'area'           => $area ?: null,
			'is_custom'      => 1,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%d' );

		if ( $id > 0 ) {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM $t WHERE id = %d AND institution_id = %d AND is_custom = 1",
				$id, $institution_id
			) );
			if ( ! $existing ) {
				self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
			}
			$wpdb->update( $t, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		} else {
			$wpdb->insert( $t, $data, $formats );
		}

		self::redirect( array( 'status' => 'updated' ) );
	}

	public static function handle_delete() {
		check_admin_referer( 'edu_delete_subject' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		global $wpdb;
		$t = $wpdb->prefix . 'edu_subjects';

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d AND institution_id = %d", $id, $institution_id ) );
		if ( ! $exists ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}
		$wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );

		self::redirect( array( 'status' => 'deleted' ) );
	}

	/**
	 * Adopta una materia del catálogo Mineduc para la institución activa.
	 * Crea una fila en wp_edu_subjects con catalog_id apuntando a la del catálogo.
	 */
	public static function handle_adopt() {
		check_admin_referer( 'edu_adopt_subject' );
		self::guard();

		$institution_id = Edu_Context::current_institution_id();
		$catalog_id     = isset( $_POST['catalog_id'] ) ? (int) $_POST['catalog_id'] : 0;
		if ( ! $catalog_id ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}

		global $wpdb;
		$tsc = $wpdb->prefix . 'edu_subjects_catalog';
		$ts  = $wpdb->prefix . 'edu_subjects';

		$catalog = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tsc WHERE id = %d", $catalog_id ) );
		if ( ! $catalog ) {
			self::redirect( array( 'status' => 'error', 'code' => 'not_found' ) );
		}

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $ts WHERE institution_id = %d AND catalog_id = %d",
			$institution_id, $catalog_id
		) );
		if ( $existing ) {
			self::redirect( array( 'status' => 'error', 'code' => 'duplicate' ) );
		}

		$wpdb->insert(
			$ts,
			array(
				'institution_id' => $institution_id,
				'catalog_id'     => $catalog_id,
				'name'           => $catalog->name,
				'area'           => $catalog->area ?: null,
				'is_custom'      => 0,
			),
			array( '%d', '%d', '%s', '%s', '%d' )
		);

		self::redirect( array( 'status' => 'adopted' ) );
	}

	/**
	 * Re-inserta las 18 materias Mineduc con INSERT IGNORE.
	 * Útil si el catálogo se eliminó manualmente o si añadimos materias
	 * en una futura versión del plugin.
	 */
	public static function handle_repopulate() {
		check_admin_referer( 'edu_repopulate_catalog' );
		if ( ! Edu_Context::is_superadmin_editorial() && ! Edu_Context::can( 'edu_manage_subjects' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}

		global $wpdb;
		$tsc = $wpdb->prefix . 'edu_subjects_catalog';

		$subjects = array(
			array( 'Currículo Integrador por ámbitos de aprendizaje', 'preparatoria', 'Integradora' ),
			array( 'Lengua y Literatura', 'elemental,media,superior,bg', 'Lengua' ),
			array( 'Matemática', 'elemental,media,superior,bg', 'Ciencias exactas' ),
			array( 'Estudios Sociales', 'elemental,media,superior', 'Ciencias sociales' ),
			array( 'Ciencias Naturales', 'elemental,media,superior', 'Ciencias naturales' ),
			array( 'Inglés', 'elemental,media,superior,bg', 'Lengua extranjera' ),
			array( 'Educación Cultural y Artística', 'preparatoria,elemental,media,superior,bg', 'Artística' ),
			array( 'Educación Física', 'preparatoria,elemental,media,superior,bg', 'Física' ),
			array( 'Acompañamiento integral en el aula', 'elemental,media,superior,bg', 'Tutoría' ),
			array( 'Animación a la lectura', 'elemental,media,superior', 'Lengua' ),
			array( 'Orientación vocacional y profesional', 'superior', 'Tutoría' ),
			array( 'Física', 'bg', 'Ciencias naturales' ),
			array( 'Química', 'bg', 'Ciencias naturales' ),
			array( 'Biología', 'bg', 'Ciencias naturales' ),
			array( 'Historia', 'bg', 'Ciencias sociales' ),
			array( 'Educación para la ciudadanía', 'bg', 'Ciencias sociales' ),
			array( 'Filosofía', 'bg', 'Ciencias sociales' ),
			array( 'Emprendimiento y Gestión', 'bg', 'Económica' ),
		);

		foreach ( $subjects as $row ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO $tsc (name, sub_levels, area, is_active, source) VALUES (%s, %s, %s, 1, 'mineduc')",
				$row[0], $row[1], $row[2]
			) );
		}

		self::redirect( array( 'status' => 'repopulated' ) );
	}

	private static function guard() {
		if ( ! Edu_Context::can( 'edu_manage_subjects' ) ) {
			wp_die( esc_html__( 'Sin permiso.', 'sistema-educativo' ) );
		}
		if ( ! Edu_Context::current_institution_id() ) {
			wp_die( esc_html__( 'No hay institución activa.', 'sistema-educativo' ) );
		}
	}

	private static function redirect( $args = array() ) {
		$args = array_merge( array( 'page' => 'edu-materias' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
