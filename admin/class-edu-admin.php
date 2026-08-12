<?php
/**
 * Bootstrap del admin: registra menú, submenús y enqueue de assets.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Admin {

	const MENU_SLUG = 'edu-inicio';

	public function register_menus() {
		if ( ! self::user_has_admin_access() ) {
			return;
		}

		add_menu_page(
			__( 'Sistema Educativo', 'sistema-educativo' ),
			__( 'Sistema Educativo', 'sistema-educativo' ),
			'read',
			self::MENU_SLUG,
			array( $this, 'render_inicio' ),
			'dashicons-welcome-learn-more',
			30
		);

		/*
		 * Cada entrada: [ slug, etiqueta, método_render, capability_requerida ]
		 *
		 * WordPress oculta automáticamente los submenús cuya capability
		 * el usuario no posee — no se necesita lógica adicional de filtrado.
		 *
		 * Rector tiene todas las caps (incluidas las de docente).
		 * Docente solo ve los ítems que corresponden a sus caps.
		 * Estudiante y padre no llegan aquí (bloqueados en user_has_admin_access).
		 */
		$pages = array(
			// Visible para todos los que tienen acceso al admin del plugin.
			array( 'edu-inicio',       __( 'Inicio',                   'sistema-educativo' ), 'render_inicio',       'read' ),

			// Solo rector / administrador.
			array( 'edu-institucion',  __( 'Institución',              'sistema-educativo' ), 'render_institucion',  'edu_view_all' ),
			array( 'edu-periodos',     __( 'Períodos lectivos',        'sistema-educativo' ), 'render_periodos',     'edu_view_all' ),
			array( 'edu-grados',       __( 'Grados y paralelos',       'sistema-educativo' ), 'render_grados',       'edu_view_all' ),
			array( 'edu-materias',     __( 'Materias',                 'sistema-educativo' ), 'render_materias',     'edu_view_all' ),
			array( 'edu-docentes',     __( 'Docentes',                 'sistema-educativo' ), 'render_docentes',     'edu_manage_teachers' ),
			array( 'edu-estudiantes',  __( 'Estudiantes',              'sistema-educativo' ), 'render_estudiantes',  'edu_manage_students' ),
			array( 'edu-padres',       __( 'Representantes',           'sistema-educativo' ), 'render_padres',       'edu_manage_parents' ),
			array( 'edu-asignaciones', __( 'Asignaciones académicas',  'sistema-educativo' ), 'render_asignaciones', 'edu_manage_assignments' ),

			// Rector + docente.
			array( 'edu-comunicados',    __( 'Comunicados',            'sistema-educativo' ), 'render_comunicados',    'edu_send_grade_announcement' ),
			array( 'edu-pensum',         __( 'Pensum',                 'sistema-educativo' ), 'render_pensum',         'edu_grade_students' ),
			array( 'edu-componentes',    __( 'Componentes evaluables', 'sistema-educativo' ), 'render_componentes',    'edu_grade_students' ),
			array( 'edu-tareas',         __( 'Tareas y actividades',   'sistema-educativo' ), 'render_tareas',         'edu_create_assignment' ),
			array( 'edu-calificaciones', __( 'Calificaciones',         'sistema-educativo' ), 'render_calificaciones', 'edu_grade_students' ),
			array( 'edu-examen-final',   __( 'Examen final',           'sistema-educativo' ), 'render_examen_final',   'edu_grade_students' ),
			array( 'edu-asistencia',     __( 'Asistencia',             'sistema-educativo' ), 'render_asistencia',     'edu_take_attendance' ),

			// Solo rector.
			array( 'edu-panel-docentes', __( 'Panel de docentes', 'sistema-educativo' ), 'render_panel_docentes', 'edu_view_all' ),
			array( 'edu-cierres',      __( 'Cierres',       'sistema-educativo' ), 'render_cierres',      'edu_close_partial' ),
			array( 'edu-resumen-anual',__( 'Resumen anual', 'sistema-educativo' ), 'render_resumen_anual','edu_generate_reports' ),
			array( 'edu-boletines',    __( 'Boletines PDF', 'sistema-educativo' ), 'render_boletines',    'edu_generate_reports' ),
			array( 'edu-exportes',     __( 'Exportes Mineduc', 'sistema-educativo' ), 'render_exportes_mineduc', 'edu_generate_reports' ),
			array( 'edu-cuentas',      __( 'Cuentas',       'sistema-educativo' ), 'render_cuentas',      'edu_view_all' ),
			array( 'edu-pagos',        __( 'Pagos',         'sistema-educativo' ), 'render_pagos',        'edu_view_all' ),
			array( 'edu-auditoria',    __( 'Auditoría',     'sistema-educativo' ), 'render_auditoria',    'edu_view_audit' ),
			array( 'edu-ajustes',      __( 'Ajustes',       'sistema-educativo' ), 'render_ajustes',      'edu_view_all' ),
		);

		// Ocultar ítems de módulos desactivados (Ajustes > Módulos del sistema).
		$modulo_por_slug = array(
			'edu-tareas'      => 'tareas',
			'edu-comunicados' => 'comunicados',
			'edu-asistencia'  => 'asistencia',
			'edu-boletines'   => 'boletines',
			'edu-exportes'    => 'exportes',
			'edu-pagos'       => 'pagos',
			'edu-cuentas'     => 'cuentas',
		);
		$pages = array_filter( $pages, function ( $page ) use ( $modulo_por_slug ) {
			return ! isset( $modulo_por_slug[ $page[0] ] ) || Edu_Modules::is_active( $modulo_por_slug[ $page[0] ] );
		} );

		$is_superadmin = current_user_can( 'manage_options' );

		foreach ( $pages as $page ) {
			// Superadmin ve todo; los demás usan la cap específica del ítem.
			$cap = $is_superadmin ? 'manage_options' : $page[3];
			add_submenu_page(
				self::MENU_SLUG,
				$page[1],
				$page[1],
				$cap,
				$page[0],
				array( $this, $page[2] )
			);
		}
	}

	/**
	 * Redirige a estudiantes y padres fuera del admin — su acceso es solo el portal frontend.
	 */
	public function redirect_non_admin_roles() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		// admin-post.php procesa formularios de cualquier usuario autenticado; no redirigir.
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}
		$user  = wp_get_current_user();
		$roles = (array) $user->roles;
		if ( ! empty( array_intersect( array( 'edu_estudiante', 'edu_padre' ), $roles ) ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}
	}

	private static function user_has_admin_access() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$user  = wp_get_current_user();
		$roles = (array) $user->roles;
		// Estudiante y padre usan solo los portales frontend.
		return ! empty( array_intersect( array( 'edu_rector', 'edu_docente' ), $roles ) );
	}

	public function render_inicio() {
		require EDU_PLUGIN_DIR . 'admin/views/inicio.php';
	}

	public function render_institucion() {
		require EDU_PLUGIN_DIR . 'admin/views/institucion.php';
	}

	public function render_periodos() {
		require EDU_PLUGIN_DIR . 'admin/views/periodos.php';
	}

	public function render_grados() {
		require EDU_PLUGIN_DIR . 'admin/views/grados.php';
	}

	public function render_materias() {
		require EDU_PLUGIN_DIR . 'admin/views/materias.php';
	}

	public function render_docentes() {
		require EDU_PLUGIN_DIR . 'admin/views/docentes.php';
	}

	public function render_estudiantes() {
		require EDU_PLUGIN_DIR . 'admin/views/estudiantes.php';
	}

	public function render_padres() {
		require EDU_PLUGIN_DIR . 'admin/views/padres.php';
	}

	public function render_asignaciones() {
		require EDU_PLUGIN_DIR . 'admin/views/asignaciones.php';
	}

	public function render_tareas() {
		require EDU_PLUGIN_DIR . 'admin/views/tareas.php';
	}

	public function render_comunicados() {
		require EDU_PLUGIN_DIR . 'admin/views/comunicados.php';
	}

	public function render_pensum() {
		require EDU_PLUGIN_DIR . 'admin/views/pensum.php';
	}

	public function render_componentes() {
		require EDU_PLUGIN_DIR . 'admin/views/componentes.php';
	}

	public function render_calificaciones() {
		require EDU_PLUGIN_DIR . 'admin/views/calificaciones.php';
	}

	public function render_examen_final() {
		require EDU_PLUGIN_DIR . 'admin/views/examen-final.php';
	}

	public function render_panel_docentes() {
		require EDU_PLUGIN_DIR . 'admin/views/panel-docentes.php';
	}

	public function render_cierres() {
		require EDU_PLUGIN_DIR . 'admin/views/cierres.php';
	}

	public function render_resumen_anual() {
		require EDU_PLUGIN_DIR . 'admin/views/resumen-anual.php';
	}

	public function render_asistencia() {
		require EDU_PLUGIN_DIR . 'admin/views/asistencia.php';
	}

	public function render_boletines() {
		require EDU_PLUGIN_DIR . 'admin/views/boletines.php';
	}

	public function render_exportes_mineduc() {
		require EDU_PLUGIN_DIR . 'admin/views/exportes-mineduc.php';
	}

	public function render_auditoria() {
		require EDU_PLUGIN_DIR . 'admin/views/auditoria.php';
	}

	public function render_cuentas() {
		require EDU_PLUGIN_DIR . 'admin/views/cuentas.php';
	}

	public function render_pagos() {
		require EDU_PLUGIN_DIR . 'admin/views/pagos.php';
	}

	public function render_ajustes() {
		require EDU_PLUGIN_DIR . 'admin/views/ajustes.php';
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'edu-' ) ) {
			return;
		}
		wp_enqueue_style( 'edu-admin', EDU_PLUGIN_URL . 'admin/css/admin.css', array(), EDU_VERSION );
		wp_enqueue_script( 'edu-admin', EDU_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery' ), EDU_VERSION, true );
	}
}
