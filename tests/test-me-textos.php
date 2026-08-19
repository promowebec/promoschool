<?php
/**
 * GET /me/texts — los textos del usuario en sesión.
 *
 * El endpoint no decide quién ve qué: reenvía lo que produce `[mis_textos]`,
 * que en cada sitio lo registra quien corresponda. Lo que sí es suyo y hay que
 * sostener es el contrato —`html` y `vacio`—, que exija sesión, que respete el
 * módulo `textos` y que responda 404 cuando no hay ningún proveedor, en vez de
 * romper.
 *
 * @package SistemaEducativo
 */

require __DIR__ . '/bootstrap.php';

edu_test_case( 'GET /me/texts' );

$admin_id = edu_test_admin_id();

if ( ! $admin_id ) {
	edu_test_skip( 'no hay administrador' );
}

/** Lanza la petición REST dentro del proceso. */
$pedir = function () {
	return rest_do_request( new WP_REST_Request( 'GET', '/edu/v1/me/texts' ) );
};

/* ── La ruta existe ───────────────────────────────────────────────────────── */

$rutas = rest_get_server()->get_routes();
edu_assert( 'la ruta está registrada', isset( $rutas['/edu/v1/me/texts'] ) );

/* ── Sin sesión ───────────────────────────────────────────────────────────── */

edu_test_as( 0 );

$sin_sesion = $pedir();
edu_assert(
	'sin sesión no responde 200',
	200 !== $sin_sesion->get_status(),
	'status ' . $sin_sesion->get_status()
);

/* ── Con sesión ───────────────────────────────────────────────────────────── */

edu_test_as( $admin_id );

$hay_proveedor = shortcode_exists( 'mis_textos' );
$modulo_activo = Edu_Modules::is_active( 'textos' );

$respuesta = $pedir();
$datos     = $respuesta->get_data();

if ( ! $hay_proveedor || ! $modulo_activo ) {
	// Sin quien registre el shortcode, el contrato es 404 — no un 500 ni un
	// 200 con el cuerpo vacío, que es lo que dejaría a la app pintando nada
	// sin poder explicar por qué.
	edu_assert(
		'sin proveedor de textos responde 404',
		404 === $respuesta->get_status(),
		'status ' . $respuesta->get_status()
	);

	edu_assert(
		'y dice cuál es el motivo',
		is_array( $datos ) && isset( $datos['code'] )
			&& in_array( $datos['code'], array( 'edu_no_texts_provider', 'edu_module_disabled' ), true ),
		is_array( $datos ) && isset( $datos['code'] ) ? $datos['code'] : '?'
	);
} else {
	edu_assert( 'con sesión responde 200', 200 === $respuesta->get_status(), 'status ' . $respuesta->get_status() );
	edu_assert( 'devuelve la clave html', is_array( $datos ) && array_key_exists( 'html', $datos ) );
	edu_assert( 'devuelve la clave vacio', is_array( $datos ) && array_key_exists( 'vacio', $datos ) );
	edu_assert( 'vacio es un booleano', is_array( $datos ) && is_bool( $datos['vacio'] ) );

	// `vacio` es lo que la app usa para decidir si pinta el estado vacío en vez
	// de una tarjeta en blanco. Tiene que ser coherente con el HTML de verdad,
	// descontando el <style> que el shortcode trae en línea.
	if ( is_array( $datos ) ) {
		$texto_visible = trim( wp_strip_all_tags( (string) $datos['html'] ) );

		edu_assert(
			'vacio concuerda con el contenido real del html',
			$datos['vacio'] === ( '' === $texto_visible ),
			$datos['vacio'] ? 'dice vacío' : 'dice con contenido'
		);
	}

	// El HTML se inyecta con v-html en la app: si trajera un <script>, se
	// insertaría sin ejecutarse, pero es señal de que el proveedor no sirve
	// para este camino y hay que saberlo.
	if ( is_array( $datos ) && ! $datos['vacio'] ) {
		edu_assert(
			'el html no trae scripts',
			false === stripos( (string) $datos['html'], '<script' )
		);
	}
}

/* ── El módulo apagado responde 404, no 403 ───────────────────────────────── */

$modulos_previos = get_option( 'edu_modules' );

edu_test_cleanup(
	function () use ( $modulos_previos ) {
		if ( false === $modulos_previos ) {
			delete_option( 'edu_modules' );
		} else {
			update_option( 'edu_modules', $modulos_previos );
		}
	}
);

$apagados          = is_array( $modulos_previos ) ? $modulos_previos : array();
$apagados['textos'] = 0;
update_option( 'edu_modules', $apagados );
wp_cache_delete( 'edu_modules', 'options' );

$apagado = $pedir();

edu_assert(
	'con el módulo apagado responde 404, no 403',
	404 === $apagado->get_status(),
	'status ' . $apagado->get_status()
);

edu_test_finish();
