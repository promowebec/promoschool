<?php
/**
 * Helper: módulos activables/desactivables del sistema.
 *
 * El administrador puede habilitar o deshabilitar módulos completos desde
 * Ajustes. Un módulo desactivado desaparece del menú admin, de los tabs de
 * los portales y no registra sus handlers/hooks/cron en el bootstrap.
 *
 * Los estados se guardan en wp_options.edu_modules como array clave => 0|1.
 * Un módulo ausente del array se considera ACTIVO (retrocompatibilidad con
 * instalaciones que se actualizan).
 *
 * Otros plugins (ej. flipbook) pueden intervenir con el filtro
 * 'edu_module_active' ( bool $activo, string $modulo ).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Modules {

	const OPTION = 'edu_modules';

	/**
	 * Catálogo de módulos desactivables. Lo que no está aquí es núcleo
	 * (calificaciones, personas, pensum, auditoría) y no se puede apagar.
	 *
	 * @return array<string, array{label:string, desc:string}>
	 */
	public static function catalog(): array {
		return array(
			'tareas'      => array(
				'label' => __( 'Tareas', 'sistema-educativo' ),
				'desc'  => __( 'El docente publica tareas, lecciones y pruebas y registra sus notas. Estudiantes y representantes las consultan.', 'sistema-educativo' ),
			),
			'entregas'    => array(
				'label'    => __( 'Entregas del estudiante', 'sistema-educativo' ),
				'desc'     => __( 'El estudiante sube sus trabajos con archivos y entrega mejoras. Requiere el módulo Tareas.', 'sistema-educativo' ),
				'requiere' => 'tareas',
			),
			'comunicados' => array(
				'label' => __( 'Comunicados', 'sistema-educativo' ),
				'desc'  => __( 'Comunicados institucionales y por grado con acuse de recibo.', 'sistema-educativo' ),
			),
			'asistencia'  => array(
				'label' => __( 'Asistencia', 'sistema-educativo' ),
				'desc'  => __( 'Registro de asistencia diaria y por materia.', 'sistema-educativo' ),
			),
			'boletines'   => array(
				'label' => __( 'Boletines PDF', 'sistema-educativo' ),
				'desc'  => __( 'Generación de boletines en PDF (individual y ZIP por grado).', 'sistema-educativo' ),
			),
			'pagos'       => array(
				'label' => __( 'Pagos (Payphone)', 'sistema-educativo' ),
				'desc'  => __( 'Pensiones y matrículas: generación mensual, cobro en línea, links de pago y morosidad.', 'sistema-educativo' ),
			),
			'whatsapp'    => array(
				'label' => __( 'Notificaciones WhatsApp', 'sistema-educativo' ),
				'desc'  => __( 'Envío de comunicados, notas, pagos vencidos y faltas por WhatsApp.', 'sistema-educativo' ),
			),
			'cuentas'     => array(
				'label' => __( 'Gestión de cuentas', 'sistema-educativo' ),
				'desc'  => __( 'Suspensión/activación de cuentas de usuarios (con cascade padre → hijos).', 'sistema-educativo' ),
			),
			'exportes'    => array(
				'label' => __( 'Exportes Mineduc', 'sistema-educativo' ),
				'desc'  => __( 'Descarga de reportes Excel compatibles con SIME/AMIE: acta consolidada, nómina, distributivo docente y asistencia acumulada.', 'sistema-educativo' ),
			),
			'pwa'         => array(
				'label' => __( 'App móvil (PWA)', 'sistema-educativo' ),
				'desc'  => __( 'Manifest y service worker para instalar los portales como app en el celular.', 'sistema-educativo' ),
			),
			'textos'      => array(
				'label' => __( 'Mis textos (Flipbook)', 'sistema-educativo' ),
				'desc'  => __( 'Tab "Mis textos" en los portales. Requiere además el plugin de Flipbook activo.', 'sistema-educativo' ),
			),
		);
	}

	/**
	 * ¿Está activo el módulo?
	 *
	 * Lo que no está en el catálogo es núcleo y siempre está activo. Un módulo
	 * del catálogo necesita dos cosas: que el plan contratado lo incluya
	 * (Edu_Plan) y que Ajustes no lo haya apagado. Ajustes solo puede apagar.
	 */
	public static function is_active( string $modulo ): bool {
		$catalogo = self::catalog();
		if ( ! isset( $catalogo[ $modulo ] ) ) {
			$activo = true;
		} else {
			$saved  = (array) get_option( self::OPTION, array() );
			$activo = ! array_key_exists( $modulo, $saved ) || ! empty( $saved[ $modulo ] );

			// Un módulo que depende de otro se apaga con él.
			if ( $activo && ! empty( $catalogo[ $modulo ]['requiere'] ) ) {
				$activo = self::is_active( $catalogo[ $modulo ]['requiere'] );
			}
		}

		// El tab de textos solo tiene sentido si el plugin flipbook registró su shortcode.
		if ( 'textos' === $modulo && $activo ) {
			$activo = shortcode_exists( 'mis_textos' );
		}

		/**
		 * Permite a otros plugins apagar un módulo.
		 *
		 * Corre ANTES del plan: puede apagar, pero no encender un módulo que el
		 * plan contratado no incluye.
		 *
		 * @param bool   $activo Estado calculado.
		 * @param string $modulo Clave del módulo.
		 */
		$activo = (bool) apply_filters( 'edu_module_active', $activo, $modulo );

		return $activo && ( ! isset( $catalogo[ $modulo ] ) || Edu_Plan::permite( $modulo ) );
	}

	/**
	 * ¿El plan contratado incluye este módulo? Es lo que decide si Ajustes lo
	 * muestra como interruptor o como «No incluido en su plan».
	 */
	public static function in_plan( string $modulo ): bool {
		return Edu_Plan::permite( $modulo );
	}

	/**
	 * Filtra una lista de tabs de portal dejando solo los de módulos activos.
	 * Los tabs cuyo nombre no coincide con un módulo del catálogo se conservan.
	 *
	 * @param string[] $tabs Claves de tab (coinciden con las claves de módulo).
	 * @return string[]
	 */
	public static function filter_tabs( array $tabs ): array {
		return array_values( array_filter( $tabs, array( __CLASS__, 'is_active' ) ) );
	}

	/**
	 * Filtra un array de sidenav (clave de tab => datos) por módulos activos.
	 *
	 * @param array $sidenav Ítems del menú lateral del portal.
	 * @return array
	 */
	public static function filter_sidenav( array $sidenav ): array {
		return array_filter( $sidenav, array( __CLASS__, 'is_active' ), ARRAY_FILTER_USE_KEY );
	}
}
