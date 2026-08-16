<?php
/**
 * Arranque común de las pruebas.
 *
 * Este proyecto no usa PHPUnit ni Composer para desarrollo: las pruebas son
 * scripts PHP que arrancan WordPress, crean su propio fixture y lo borran al
 * terminar. Esto es solo el andamio compartido.
 *
 * Regla que ninguna prueba puede romper: **nada queda escrito en la base al
 * terminar**. Se prueba contra datos reales de un entorno de desarrollo, así que
 * cada fixture se registra con `edu_test_cleanup()` y se deshace en el shutdown,
 * pase lo que pase — incluido un fatal a mitad.
 *
 * @package SistemaEducativo
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "Las pruebas solo corren por linea de comandos.\n" );
}

/*
 * DB_HOST tiene que definirse ANTES de wp-load: el wp-config de un entorno Local
 * trae un host que no resuelve desde CLI. En un servidor normal no hace falta.
 */
foreach ( $argv as $edu_arg ) {
	if ( 0 === strpos( $edu_arg, '--db-host=' ) ) {
		define( 'DB_HOST', substr( $edu_arg, 10 ) );
		break;
	}
}

require dirname( __DIR__, 4 ) . '/wp-load.php';

require_once EDU_PLUGIN_DIR . 'modules/calificaciones/class-edu-grade-calculator.php';

$GLOBALS['edu_test'] = array(
	'nombre'  => '',
	'ok'      => 0,
	'fallos'  => 0,
	'saltada' => '',
);

/** Título de la prueba en curso. */
function edu_test_case( $nombre ) {
	$GLOBALS['edu_test']['nombre'] = $nombre;
	echo "\n── $nombre\n";
}

/**
 * Una comprobación.
 *
 * @param string $etiqueta Qué se esperaba.
 * @param bool   $ok       Resultado.
 * @param string $extra    Dato que ayuda a diagnosticar cuando falla.
 */
function edu_assert( $etiqueta, $ok, $extra = '' ) {
	if ( $ok ) {
		$GLOBALS['edu_test']['ok']++;
	} else {
		$GLOBALS['edu_test']['fallos']++;
	}

	printf( "   %-52s %s %s\n", $etiqueta, $ok ? 'ok  ' : 'FALLA', $extra );

	return $ok;
}

/** Compara un WP_Error con el código esperado. Los servicios no llevan prefijo. */
function edu_assert_error( $etiqueta, $resultado, $codigo_esperado ) {
	$es_error = is_wp_error( $resultado );
	$obtenido = $es_error ? $resultado->get_error_code() : 'sin error';

	return edu_assert( $etiqueta, $es_error && $codigo_esperado === $obtenido, $obtenido );
}

function edu_assert_ok( $etiqueta, $resultado ) {
	return edu_assert(
		$etiqueta,
		! is_wp_error( $resultado ),
		is_wp_error( $resultado ) ? $resultado->get_error_code() : ''
	);
}

/** Marca la prueba como no aplicable: no hay datos para ejecutarla. */
function edu_test_skip( $motivo ) {
	$GLOBALS['edu_test']['saltada'] = $motivo;
	echo "   (saltada: $motivo)\n";
	edu_test_finish();
	exit( 0 );
}

/** Actúa como un usuario concreto dentro de una institución. */
function edu_test_as( $user_id, $institution_id = 0 ) {
	wp_set_current_user( (int) $user_id );
	Edu_Service::reset_identity();

	if ( $institution_id ) {
		Edu_Context::override_institution_id( (int) $institution_id );
	}
}

/** Registra algo que hay que deshacer al terminar, pase lo que pase. */
function edu_test_cleanup( callable $fn ) {
	register_shutdown_function( $fn );
}

/** Resumen final. El código de salida es lo que lee el runner. */
function edu_test_finish() {
	$t = $GLOBALS['edu_test'];

	if ( $t['saltada'] ) {
		echo "   → SALTADA\n";
		return;
	}

	printf( "   → %d ok, %d fallas\n", $t['ok'], $t['fallos'] );
}

register_shutdown_function(
	function () {
		$t = $GLOBALS['edu_test'];
		if ( $t['fallos'] > 0 ) {
			// El runner distingue fallo de prueba (1) de fatal de PHP (255).
			exit( 1 );
		}
	}
);

/* ── Buscadores de fixture ─────────────────────────────────────────────────
 * Las pruebas corren contra la base de desarrollo, cuyo contenido varía. En vez
 * de dar por hecho que existe el estudiante 3, se busca un caso que sirva y, si
 * no lo hay, la prueba se salta en vez de fallar en falso.
 */

/** Un (componente, estudiante) de un parcial abierto. */
function edu_test_caso_abierto() {
	global $wpdb;
	$p = $wpdb->prefix . 'edu_';

	return $wpdb->get_row(
		"SELECT c.id AS component_id, c.subject_id, c.trimester_id, c.parcial_num,
		        s.id AS student_id, s.user_id, s.grade_id,
		        su.institution_id
		 FROM {$p}grade_components c
		 JOIN {$p}subjects su ON su.id = c.subject_id
		 JOIN {$p}grades g ON g.institution_id = su.institution_id
		 JOIN {$p}students s ON s.grade_id = g.id AND s.status = 'active'
		 LEFT JOIN {$p}parcial_scores ps ON ps.student_id = s.id AND ps.subject_id = c.subject_id
		      AND ps.trimester_id = c.trimester_id AND ps.parcial_num = c.parcial_num
		 WHERE COALESCE(ps.is_closed, 0) = 0
		 LIMIT 1"
	);
}

/** El primer administrador del sitio. */
function edu_test_admin_id() {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );

	return $admin ? (int) $admin[0]->ID : 0;
}
