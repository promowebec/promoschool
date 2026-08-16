<?php
/**
 * El plugin arranca y registra lo que dice registrar.
 *
 * Es la prueba más barata y la que más veces ha valido la pena: detecta un
 * `require_once` que falta, una clase renombrada o una ruta que dejó de
 * registrarse, que son los errores que rompen el sitio entero.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'Arranque del plugin' );

edu_assert( 'el plugin carga', defined( 'EDU_VERSION' ), 'v' . ( defined( 'EDU_VERSION' ) ? EDU_VERSION : '?' ) );

// El esquema en la base debe ir al día con el código: si no, `maybe_migrate()`
// no ha corrido y las consultas van contra columnas que no existen.
edu_assert(
	'edu_db_version coincide con EDU_DB_VERSION',
	get_option( 'edu_db_version' ) === EDU_DB_VERSION,
	get_option( 'edu_db_version' ) . ' vs ' . EDU_DB_VERSION
);

/* ── Clases que tienen que existir ───────────────────────────────────────── */

$clases = array(
	'Edu_Service',
	'Edu_Context',
	'Edu_Audit',
	'Edu_Modules',
	'Edu_Spa',
	'Edu_Gradebook_Service',
	'Edu_Score_Service',
	'Edu_Submission_Service',
	'Edu_Payment_Service',
	'Edu_Report_Service',
	'Edu_Attendance_Service',
	'Edu_Announcement_Service',
	'Edu_Assignment_Service',
	'Edu_File_Service',
	'Edu_Api',
	'Edu_Api_Write_Routes',
	'Edu_Api_Report_Routes',
	'Edu_Grade_Calculator',
	'Edu_Qualitativa_Helper',
);

$faltan = array_values( array_filter( $clases, static fn( $c ) => ! class_exists( $c ) ) );
edu_assert( count( $clases ) . ' clases clave existen', ! $faltan, $faltan ? 'faltan: ' . implode( ', ', $faltan ) : '' );

/* ── Shortcodes ──────────────────────────────────────────────────────────── */

$shortcodes = array( 'edu_app', 'edu_portal_rector', 'edu_portal_docente', 'edu_portal_estudiante', 'edu_portal_padre' );
$sin        = array_values( array_filter( $shortcodes, static fn( $s ) => ! shortcode_exists( $s ) ) );
edu_assert( 'los 5 shortcodes se registran', ! $sin, $sin ? 'faltan: ' . implode( ', ', $sin ) : '' );

/* ── Rutas REST ──────────────────────────────────────────────────────────── */

do_action( 'rest_api_init' );
$rutas = array_keys( rest_get_server()->get_routes() );
$edu   = array_filter( $rutas, static fn( $r ) => 0 === strpos( $r, '/edu/v1' ) );

// El índice del namespace cuenta como una entrada más de las rutas propias.
edu_assert( 'edu/v1 registra sus rutas', count( $edu ) > 50, count( $edu ) . ' patrones' );

$imprescindibles = array(
	'/edu/v1/gradebook',
	'/edu/v1/gradebook/scores',
	'/edu/v1/students/(?P<id>\d+)/scores',
	'/edu/v1/students/(?P<id>\d+)/component-breakdown',
	'/edu/v1/assignments/(?P<id>\d+)/submissions',
	'/edu/v1/submissions/(?P<id>\d+)/grade',
	'/edu/v1/submissions/(?P<id>\d+)/return',
	'/edu/v1/files/(?P<id>\d+)/link',
);

$sin_ruta = array_values( array_filter( $imprescindibles, static fn( $r ) => ! in_array( $r, $rutas, true ) ) );
edu_assert( 'las rutas críticas están', ! $sin_ruta, $sin_ruta ? 'faltan: ' . implode( ' ', $sin_ruta ) : '' );

/* ── Handlers de wp-admin ────────────────────────────────────────────────── */

/*
 * Cada formulario del backend cuelga de un `admin_post_*`. Si un método se movió
 * a un servicio y el hook se quedó apuntando al viejo, la pantalla revienta al
 * guardar — y eso no se ve hasta que alguien intenta usarla.
 */
global $wp_filter;
$total = 0;
$rotos = array();

foreach ( $wp_filter as $hook => $obj ) {
	if ( 0 !== strpos( $hook, 'admin_post' ) ) {
		continue;
	}

	foreach ( $obj->callbacks as $cbs ) {
		foreach ( $cbs as $cb ) {
			$fn = $cb['function'];

			if ( ! is_array( $fn ) || ! is_string( $fn[0] ) || 0 !== strpos( $fn[0], 'Edu_' ) ) {
				continue;
			}

			$total++;

			if ( ! class_exists( $fn[0] ) || ! method_exists( $fn[0], $fn[1] ) || ! is_callable( $fn ) ) {
				$rotos[] = "$hook → {$fn[0]}::{$fn[1]}";
			}
		}
	}
}

edu_assert( "los $total handlers admin_post resuelven", ! $rotos, $rotos ? implode( ' | ', $rotos ) : '' );

/* ── Contrato de los servicios ───────────────────────────────────────────── */

/*
 * CLAUDE.md: los servicios exponen métodos `public static`. Un método de
 * instancia ahí es señal de que alguien se salió del patrón.
 */
$no_estaticos = array();
foreach ( glob( EDU_PLUGIN_DIR . 'includes/services/*.php' ) as $archivo ) {
	$clase = 'Edu_' . str_replace(
		' ',
		'_',
		ucwords( str_replace( '-', ' ', substr( basename( $archivo, '.php' ), strlen( 'class-edu-' ) ) ) )
	);

	if ( ! class_exists( $clase ) ) {
		continue;
	}

	$rc = new ReflectionClass( $clase );
	foreach ( $rc->getMethods( ReflectionMethod::IS_PUBLIC ) as $m ) {
		if ( $m->class === $clase && ! $m->isStatic() ) {
			$no_estaticos[] = "$clase::{$m->name}()";
		}
	}
}

edu_assert( 'los servicios solo exponen métodos estáticos', ! $no_estaticos, implode( ', ', $no_estaticos ) );

edu_test_finish();
