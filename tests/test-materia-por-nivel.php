<?php
/**
 * Materias propias por nivel (1.18.0).
 *
 * Una materia propia se ofrece en todos los niveles (sub_levels NULL) o solo
 * en uno: la pantalla de pensum de un grado solo muestra las del nivel del
 * grado. Una materia ya asignada a un grado sigue apareciendo ahí aunque se
 * le cambie el nivel, para que no se pierda de vista.
 *
 * Crea su propio colegio y lo borra al terminar.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

global $wpdb;
$p = $wpdb->prefix . 'edu_';

$admin = edu_test_admin_id();
if ( ! $admin ) {
	edu_test_skip( 'no hay administrador' );
}

/* ── Fixture ─────────────────────────────────────────────────────────────── */

$sufijo = wp_generate_password( 6, false );

$wpdb->insert( $p . 'institutions', array( 'name' => 'Colegio Niveles ' . $sufijo, 'regime' => 'sierra' ) );
$inst = (int) $wpdb->insert_id;

$wpdb->insert( $p . 'grades', array( 'institution_id' => $inst, 'level' => 'basica', 'sub_level' => 'elemental', 'name' => 'Elemental', 'paralelo' => 'A' ) );
$g_elemental = (int) $wpdb->insert_id;
$wpdb->insert( $p . 'grades', array( 'institution_id' => $inst, 'level' => 'bachillerato', 'sub_level' => 'bg', 'name' => 'BGU', 'paralelo' => 'A' ) );
$g_bg = (int) $wpdb->insert_id;

edu_test_cleanup(
	function () use ( $wpdb, $p, $inst, $g_elemental, $g_bg ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}grade_subjects WHERE grade_id IN (%d, %d)", $g_elemental, $g_bg ) );
		$wpdb->delete( $p . 'grades', array( 'institution_id' => $inst ) );
		$wpdb->delete( $p . 'subjects', array( 'institution_id' => $inst ) );
		$wpdb->delete( $p . 'institutions', array( 'id' => $inst ) );
	}
);

edu_test_as( $admin, $inst );

/** HTML de la pantalla de pensum de un grado. */
function edu_test_pensum_html( $grade_id ) {
	$_GET['grade_id'] = $grade_id;
	ob_start();
	require EDU_PLUGIN_DIR . 'admin/views/pensum.php';
	return ob_get_clean();
}

/* ── Esquema ─────────────────────────────────────────────────────────────── */

edu_test_case( 'Esquema' );

edu_assert( 'edu_db_version al día', get_option( 'edu_db_version' ) === EDU_DB_VERSION, get_option( 'edu_db_version' ) );
edu_assert( 'columna subjects.sub_levels existe', (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$p}subjects LIKE 'sub_levels'" ) );

/* ── Servicio ────────────────────────────────────────────────────────────── */

edu_test_case( 'Guardar materia propia' );

$todos = Edu_Curriculum_Service::save_subject( array( 'name' => 'Robótica ' . $sufijo ) );
edu_assert_ok( 'todos los niveles se crea', $todos );
edu_assert( 'sin nivel = NULL en la base', null === $wpdb->get_var( $wpdb->prepare( "SELECT sub_levels FROM {$p}subjects WHERE id = %d", $todos['id'] ) ) );

$solo_bg = Edu_Curriculum_Service::save_subject( array( 'name' => 'Contabilidad ' . $sufijo, 'sub_levels' => array( 'bg' ) ) );
edu_assert_ok( 'solo BG se crea', $solo_bg );
edu_assert( 'guarda el nivel', 'bg' === $wpdb->get_var( $wpdb->prepare( "SELECT sub_levels FROM {$p}subjects WHERE id = %d", $solo_bg['id'] ) ) );

edu_assert_error( 'nivel inventado se rechaza', Edu_Curriculum_Service::save_subject( array( 'name' => 'X', 'sub_levels' => array( 'universidad' ) ) ), 'invalid_sub_level' );
edu_assert_error( '«solo un nivel» sin elegir se rechaza', Edu_Curriculum_Service::save_subject( array( 'name' => 'X', 'sub_levels' => array( '' ) ) ), 'invalid_sub_level' );
edu_assert_error( 'nombre vacío se rechaza', Edu_Curriculum_Service::save_subject( array( 'name' => '  ' ) ), 'name_required' );
edu_assert_error( 'editar materia ajena da not_found', Edu_Curriculum_Service::save_subject( array( 'id' => 999999999, 'name' => 'X' ) ), 'not_found' );

/* ── Pantalla de pensum ─────────────────────────────────────────────────── */

edu_test_case( 'Pensum filtra por nivel' );

$html = edu_test_pensum_html( $g_elemental );
edu_assert( 'Elemental muestra la de todos los niveles', false !== strpos( $html, 'Robótica ' . $sufijo ) );
edu_assert( 'Elemental NO muestra la de solo BG', false === strpos( $html, 'Contabilidad ' . $sufijo ) );

$html = edu_test_pensum_html( $g_bg );
edu_assert( 'BG muestra la de todos los niveles', false !== strpos( $html, 'Robótica ' . $sufijo ) );
edu_assert( 'BG muestra la de solo BG', false !== strpos( $html, 'Contabilidad ' . $sufijo ) );

edu_test_case( 'Cambiar el nivel no esconde lo ya asignado' );

$wpdb->insert( $p . 'grade_subjects', array( 'grade_id' => $g_elemental, 'subject_id' => $todos['id'] ) );
$r = Edu_Curriculum_Service::save_subject( array( 'id' => $todos['id'], 'name' => 'Robótica ' . $sufijo, 'sub_levels' => 'bg' ) );
edu_assert_ok( 'pasa de todos a solo BG', $r );

$html = edu_test_pensum_html( $g_elemental );
edu_assert( 'sigue visible en Elemental (ya asignada)', false !== strpos( $html, 'Robótica ' . $sufijo ) );

$r = Edu_Curriculum_Service::save_subject( array( 'id' => $todos['id'], 'name' => 'Robótica ' . $sufijo, 'sub_levels' => array() ) );
edu_assert( 'volver a todos los niveles deja NULL', ! is_wp_error( $r ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT sub_levels FROM {$p}subjects WHERE id = %d", $todos['id'] ) ) );

/* ── API ─────────────────────────────────────────────────────────────────── */

edu_test_case( 'API: GET /subjects expone sub_levels' );

$lista = Edu_Catalog_Service::subjects();
$por_id = array();
foreach ( $lista as $s ) {
	$por_id[ $s['id'] ] = $s;
}
edu_assert( 'todos los niveles → null', array_key_exists( 'sub_levels', $por_id[ $todos['id'] ] ?? array() ) && null === $por_id[ $todos['id'] ]['sub_levels'] );
edu_assert( 'solo BG → ["bg"]', array( 'bg' ) === ( $por_id[ $solo_bg['id'] ]['sub_levels'] ?? null ) );

/* ── Permisos ───────────────────────────────────────────────────────────── */

edu_test_case( 'Permisos' );

edu_test_as( 0, $inst );
edu_assert_error( 'anónimo no puede crear materias', Edu_Curriculum_Service::save_subject( array( 'name' => 'X' ) ), 'forbidden' );

edu_test_finish();
