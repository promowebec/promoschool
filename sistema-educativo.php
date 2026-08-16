<?php
/**
 * Plugin Name:       Sistema Educativo Integral
 * Plugin URI:        https://example.com/sistema-educativo
 * Description:       Gestión académica integral para unidades educativas en Ecuador: calificaciones (3 trimestres × 2 parciales 70% + examen 30%), tareas, lecciones, comunicados padres-docentes, asistencia, boletines y dashboard institucional.
 * Version:           1.12.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Cowork
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sistema-educativo
 * Domain Path:       /languages
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EDU_VERSION', '1.12.0' );
define( 'EDU_DB_VERSION', '1.0.9' );

define( 'EDU_PLUGIN_FILE', __FILE__ );
define( 'EDU_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'EDU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EDU_PLUGIN_DIR . 'includes/class-edu-loader.php';
require_once EDU_PLUGIN_DIR . 'includes/class-edu-i18n.php';
require_once EDU_PLUGIN_DIR . 'includes/class-edu-roles.php';
require_once EDU_PLUGIN_DIR . 'includes/class-edu-context.php';
require_once EDU_PLUGIN_DIR . 'includes/class-edu-activator.php';
require_once EDU_PLUGIN_DIR . 'includes/class-edu-audit.php';
require_once EDU_PLUGIN_DIR . 'includes/class-edu-pwa.php';
require_once EDU_PLUGIN_DIR . 'includes/class-edu-spa.php';
require_once EDU_PLUGIN_DIR . 'includes/class-edu-deactivator.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-institution-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-period-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-grade-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-subject-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/helpers/class-edu-import-helper.php';
require_once EDU_PLUGIN_DIR . 'includes/helpers/class-edu-qualitativa-helper.php';
require_once EDU_PLUGIN_DIR . 'includes/helpers/class-edu-modules-helper.php';

// Capa de servicios: lógica de negocio sin HTTP, compartida por los
// controllers de wp-admin y los endpoints REST (docs/API_CONTRATO_V1.md §2.3).
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-score-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-trimester-score-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-curriculum-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-catalog-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-people-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-gradebook-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-activity-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-file-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-assignment-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-submission-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-attendance-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-announcement-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-payment-service.php';
require_once EDU_PLUGIN_DIR . 'includes/services/class-edu-report-service.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-teacher-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-student-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-assignment-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-parent-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-curriculum-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-score-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-trimester-score-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-year-score-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-account-controller.php';
require_once EDU_PLUGIN_DIR . 'modules/calificaciones/class-edu-grade-calculator.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-settings-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-announcement-controller.php';
require_once EDU_PLUGIN_DIR . 'public/shortcodes/class-edu-shortcode-comunicados.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-assignment-task-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-submission-controller.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-attendance-controller.php';
require_once EDU_PLUGIN_DIR . 'public/shortcodes/class-edu-shortcode-tareas.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-boletin-controller.php';
require_once EDU_PLUGIN_DIR . 'public/shortcodes/class-edu-shortcode-rector.php';
require_once EDU_PLUGIN_DIR . 'public/shortcodes/class-edu-shortcode-docente.php';
require_once EDU_PLUGIN_DIR . 'public/shortcodes/class-edu-shortcode-estudiante.php';
require_once EDU_PLUGIN_DIR . 'public/shortcodes/class-edu-shortcode-padre.php';
require_once EDU_PLUGIN_DIR . 'modules/pagos/class-edu-payphone.php';
require_once EDU_PLUGIN_DIR . 'modules/pagos/class-edu-payment-manager.php';
require_once EDU_PLUGIN_DIR . 'includes/controllers/class-edu-payment-controller.php';
require_once EDU_PLUGIN_DIR . 'modules/reportes/class-edu-mineduc-exporter.php';
require_once EDU_PLUGIN_DIR . 'modules/whatsapp/class-edu-whatsapp.php';
require_once EDU_PLUGIN_DIR . 'modules/whatsapp/class-edu-whatsapp-notifier.php';

// API REST edu/v1 — contrato en docs/API_CONTRATO_V1.md.
require_once EDU_PLUGIN_DIR . 'includes/api/class-edu-api-jwt.php';
require_once EDU_PLUGIN_DIR . 'includes/api/class-edu-api-auth.php';
require_once EDU_PLUGIN_DIR . 'includes/api/class-edu-api.php';
require_once EDU_PLUGIN_DIR . 'includes/api/routes/class-edu-api-auth-routes.php';
require_once EDU_PLUGIN_DIR . 'includes/api/routes/class-edu-api-me-routes.php';
require_once EDU_PLUGIN_DIR . 'includes/api/routes/class-edu-api-catalog-routes.php';
require_once EDU_PLUGIN_DIR . 'includes/api/routes/class-edu-api-gradebook-routes.php';
require_once EDU_PLUGIN_DIR . 'includes/api/routes/class-edu-api-activity-routes.php';
require_once EDU_PLUGIN_DIR . 'includes/api/routes/class-edu-api-write-routes.php';
require_once EDU_PLUGIN_DIR . 'includes/api/routes/class-edu-api-report-routes.php';

if ( is_admin() ) {
	require_once EDU_PLUGIN_DIR . 'admin/class-edu-admin.php';
	require_once EDU_PLUGIN_DIR . 'admin/class-edu-user-profile.php';
	// Demo seed — ELIMINAR en producción real.
	if ( file_exists( EDU_PLUGIN_DIR . 'demo-seed.php' ) ) {
		require_once EDU_PLUGIN_DIR . 'demo-seed.php';
	}
}

register_activation_hook( __FILE__, array( 'Edu_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Edu_Deactivator', 'deactivate' ) );

/**
 * Arranca el plugin: textdomain, menú admin, controllers.
 */
function edu_bootstrap() {
	Edu_I18n::load_textdomain();
	Edu_Activator::maybe_migrate();

	// API REST: se registra siempre (el núcleo no es desactivable). Cada grupo
	// de rutas verifica su propio módulo con Edu_Api::require_module().
	Edu_Api::register();

	$loader = new Edu_Loader();

	if ( is_admin() ) {
		$admin = new Edu_Admin();
		$loader->add_action( 'admin_menu',           $admin, 'register_menus' );
		$loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue' );
		$loader->add_action( 'admin_init',           $admin, 'redirect_non_admin_roles' );
		$loader->add_action( 'admin_init',           'Edu_Roles', 'maybe_sync_roles' );
		Edu_User_Profile::register();
	}

	// Controllers (admin_post_*).
	$loader->add_action( 'admin_post_edu_save_institution', 'Edu_Institution_Controller', 'handle_save' );
	$loader->add_action( 'admin_post_edu_delete_institution', 'Edu_Institution_Controller', 'handle_delete' );
	$loader->add_action( 'admin_post_edu_set_active_institution', 'Edu_Institution_Controller', 'handle_set_active' );

	$loader->add_action( 'admin_post_edu_save_period', 'Edu_Period_Controller', 'handle_save' );
	$loader->add_action( 'admin_post_edu_delete_period', 'Edu_Period_Controller', 'handle_delete' );
	$loader->add_action( 'admin_post_edu_activate_period', 'Edu_Period_Controller', 'handle_activate' );

	$loader->add_action( 'admin_post_edu_save_grade',              'Edu_Grade_Controller', 'handle_save' );
	$loader->add_action( 'admin_post_edu_delete_grade',            'Edu_Grade_Controller', 'handle_delete' );
	$loader->add_action( 'admin_post_edu_download_grades_template','Edu_Grade_Controller', 'handle_download_template' );
	$loader->add_action( 'admin_post_edu_import_grades_csv',       'Edu_Grade_Controller', 'handle_import_csv' );

	$loader->add_action( 'admin_post_edu_save_subject', 'Edu_Subject_Controller', 'handle_save' );
	$loader->add_action( 'admin_post_edu_delete_subject', 'Edu_Subject_Controller', 'handle_delete' );
	$loader->add_action( 'admin_post_edu_adopt_subject', 'Edu_Subject_Controller', 'handle_adopt' );
	$loader->add_action( 'admin_post_edu_repopulate_catalog', 'Edu_Subject_Controller', 'handle_repopulate' );

	$loader->add_action( 'admin_post_edu_save_teacher',                'Edu_Teacher_Controller', 'handle_save' );
	$loader->add_action( 'admin_post_edu_delete_teacher',              'Edu_Teacher_Controller', 'handle_delete' );
	$loader->add_action( 'admin_post_edu_download_teachers_template',  'Edu_Teacher_Controller', 'handle_download_template' );
	$loader->add_action( 'admin_post_edu_import_teachers_csv',         'Edu_Teacher_Controller', 'handle_import_csv' );

	$loader->add_action( 'admin_post_edu_save_student',               'Edu_Student_Controller', 'handle_save' );
	$loader->add_action( 'admin_post_edu_delete_student',             'Edu_Student_Controller', 'handle_delete' );
	$loader->add_action( 'admin_post_edu_download_students_template', 'Edu_Student_Controller', 'handle_download_template' );
	$loader->add_action( 'admin_post_edu_import_students_csv',        'Edu_Student_Controller', 'handle_import_csv' );

	$loader->add_action( 'admin_post_edu_save_assignment', 'Edu_Assignment_Controller', 'handle_save' );
	$loader->add_action( 'admin_post_edu_delete_assignment', 'Edu_Assignment_Controller', 'handle_delete' );

	$loader->add_action( 'admin_post_edu_save_parent',               'Edu_Parent_Controller', 'handle_save' );
	$loader->add_action( 'admin_post_edu_delete_parent',             'Edu_Parent_Controller', 'handle_delete' );
	$loader->add_action( 'admin_post_edu_download_parents_template', 'Edu_Parent_Controller', 'handle_download_template' );
	$loader->add_action( 'admin_post_edu_import_parents_csv',        'Edu_Parent_Controller', 'handle_import_csv' );

	$loader->add_action( 'admin_post_edu_save_pensum', 'Edu_Curriculum_Controller', 'handle_save_pensum' );
	$loader->add_action( 'admin_post_edu_save_components', 'Edu_Curriculum_Controller', 'handle_save_components' );

	$loader->add_action( 'admin_post_edu_save_scores', 'Edu_Score_Controller', 'handle_save_scores' );

	$loader->add_action( 'admin_post_edu_save_exam', 'Edu_Trimester_Score_Controller', 'handle_save_exam' );
	$loader->add_action( 'admin_post_edu_close_parcial', 'Edu_Trimester_Score_Controller', 'handle_close_parcial' );
	$loader->add_action( 'admin_post_edu_close_trimester', 'Edu_Trimester_Score_Controller', 'handle_close_trimester' );

	$loader->add_action( 'admin_post_edu_save_recovery',         'Edu_Year_Score_Controller',  'handle_save_recovery' );
	$loader->add_action( 'admin_post_edu_toggle_account_status', 'Edu_Account_Controller',     'handle_toggle' );
	$loader->add_action( 'admin_post_edu_bulk_account_status',   'Edu_Account_Controller',     'handle_bulk' );
	$loader->add_action( 'admin_post_edu_save_settings',          'Edu_Settings_Controller',      'handle_save' );

	// Comunicados (módulo desactivable).
	if ( Edu_Modules::is_active( 'comunicados' ) ) {
		$loader->add_action( 'admin_post_edu_send_announcement',       'Edu_Announcement_Controller',  'handle_send' );
		$loader->add_action( 'admin_post_edu_delete_announcement',     'Edu_Announcement_Controller',  'handle_delete' );
		$loader->add_action( 'admin_post_edu_acknowledge_announcement','Edu_Announcement_Controller',  'handle_acknowledge' );
		$loader->add_action( 'admin_post_nopriv_edu_acknowledge_announcement', 'Edu_Announcement_Controller', 'handle_acknowledge' );
	}

	// Asistencia (módulo desactivable).
	if ( Edu_Modules::is_active( 'asistencia' ) ) {
		$loader->add_action( 'admin_post_edu_save_attendance', 'Edu_Attendance_Controller', 'handle_save' );
	}

	// Boletines (módulo desactivable).
	if ( Edu_Modules::is_active( 'boletines' ) ) {
		$loader->add_action( 'admin_post_edu_download_boletin',        'Edu_Boletin_Controller', 'handle_download' );
		$loader->add_action( 'admin_post_edu_download_boletines_zip',  'Edu_Boletin_Controller', 'handle_download_zip' );
		$loader->add_action( 'admin_post_edu_download_boletin_padre',  'Edu_Boletin_Controller', 'handle_download_padre' );
	}

	// Exportes Mineduc (módulo desactivable).
	if ( Edu_Modules::is_active( 'exportes' ) ) {
		$loader->add_action( 'admin_post_edu_export_mineduc', 'Edu_Mineduc_Exporter', 'handle_export' );
	}

	// Pagos (módulo desactivable).
	if ( Edu_Modules::is_active( 'pagos' ) ) {
		$loader->add_action( 'admin_post_edu_test_payphone_connection',  'Edu_Payment_Controller', 'handle_test_connection' );
		$loader->add_action( 'admin_post_edu_save_payment_config',       'Edu_Payment_Controller', 'handle_save_config' );
		$loader->add_action( 'admin_post_edu_generate_monthly_payments', 'Edu_Payment_Controller', 'handle_generate_monthly' );
		$loader->add_action( 'admin_post_edu_init_payment',              'Edu_Payment_Controller', 'handle_init_payment' );
		$loader->add_action( 'admin_post_edu_register_manual_payment',   'Edu_Payment_Controller', 'handle_register_manual' );
		$loader->add_action( 'admin_post_edu_generate_payment_link',     'Edu_Payment_Controller', 'handle_generate_link' );
		$loader->add_action( 'admin_post_edu_waive_payment',             'Edu_Payment_Controller', 'handle_waive' );
		$loader->add_action( 'admin_post_edu_suspend_overdue_payments',  'Edu_Payment_Controller', 'handle_suspend_overdue' );
		$loader->add_action( 'admin_post_nopriv_edu_pay_via_link',       'Edu_Payment_Controller', 'handle_pay_via_link' );
		$loader->add_action( 'admin_post_edu_pay_via_link',              'Edu_Payment_Controller', 'handle_pay_via_link' );
	}

	// Tareas y entregas (módulo desactivable).
	if ( Edu_Modules::is_active( 'tareas' ) ) {
		$loader->add_action( 'admin_post_edu_save_assignment_task',    'Edu_Assignment_Task_Controller', 'handle_save' );
		$loader->add_action( 'admin_post_edu_publish_assignment_task', 'Edu_Assignment_Task_Controller', 'handle_publish' );
		$loader->add_action( 'admin_post_edu_close_assignment_task',   'Edu_Assignment_Task_Controller', 'handle_close' );
		$loader->add_action( 'admin_post_edu_delete_assignment_task',  'Edu_Assignment_Task_Controller', 'handle_delete' );
		$loader->add_action( 'admin_post_edu_download_file',           'Edu_Assignment_Task_Controller', 'handle_download_file' );
		$loader->add_action( 'admin_post_edu_submit_assignment',            'Edu_Submission_Controller',      'handle_submit' );
		$loader->add_action( 'admin_post_nopriv_edu_submit_assignment',     'Edu_Submission_Controller',      'handle_submit' );
		$loader->add_action( 'admin_post_edu_grade_submission',             'Edu_Submission_Controller',      'handle_grade' );
		$loader->add_action( 'admin_post_edu_save_recovery_settings',       'Edu_Submission_Controller',      'handle_save_recovery_settings' );
		$loader->add_action( 'admin_post_edu_grade_recovery',               'Edu_Submission_Controller',      'handle_grade_recovery' );
		$loader->add_action( 'admin_post_edu_submit_recovery',              'Edu_Submission_Controller',      'handle_submit_recovery' );
		$loader->add_action( 'admin_post_nopriv_edu_submit_recovery',       'Edu_Submission_Controller',      'handle_submit_recovery' );
		$loader->add_action( 'admin_post_edu_download_sub_file',            'Edu_Submission_Controller',      'handle_download_submission_file' );
		$loader->add_action( 'admin_post_nopriv_edu_download_sub_file',     'Edu_Submission_Controller',      'handle_download_submission_file' );
		$loader->add_action( 'admin_post_nopriv_edu_download_file',         'Edu_Assignment_Task_Controller', 'handle_download_file' );
	}

	// Shortcodes portal (los 4 portales son núcleo; los sueltos siguen a su módulo).
	if ( Edu_Modules::is_active( 'tareas' ) ) {
		Edu_Shortcode_Tareas::register();
	}
	if ( Edu_Modules::is_active( 'comunicados' ) ) {
		Edu_Shortcode_Comunicados::register();
	}
	Edu_Shortcode_Rector::register();
	Edu_Shortcode_Docente::register();
	Edu_Shortcode_Estudiante::register();
	Edu_Shortcode_Padre::register();

	// App propia (Fase 2): shortcode [edu_app].
	Edu_Spa::register();

	// Hooks de cálculo en cadena.
	$loader->add_action( 'edu_grade_logged', 'Edu_Grade_Calculator', 'on_grade_logged', 10, 3 );
	$loader->add_action( 'edu_partial_closed', 'Edu_Grade_Calculator', 'on_partial_closed', 10, 4 );
	$loader->add_action( 'edu_trimester_closed', 'Edu_Grade_Calculator', 'on_trimester_closed', 10, 3 );

	// Notificaciones WhatsApp (prioridad 20 para correr después del cálculo).
	if ( Edu_Modules::is_active( 'whatsapp' ) ) {
		$loader->add_action( 'edu_announcement_sent', 'Edu_Whatsapp_Notifier', 'on_announcement_sent', 10, 1 );
		$loader->add_action( 'edu_wa_send_announcement', 'Edu_Whatsapp_Notifier', 'process_announcement', 10, 1 );
		$loader->add_action( 'edu_trimester_closed',  'Edu_Whatsapp_Notifier', 'on_trimester_closed',  20, 3 );
		$loader->add_action( 'edu_payment_overdue',   'Edu_Whatsapp_Notifier', 'on_payment_overdue',   10, 2 );
		$loader->add_action( 'edu_attendance_absence','Edu_Whatsapp_Notifier', 'on_attendance_absence',10, 3 );

		// AJAX: prueba de conexión WhatsApp.
		$loader->add_action( 'wp_ajax_edu_test_whatsapp', 'Edu_Settings_Controller', 'handle_test_whatsapp' );
	}

	if ( Edu_Modules::is_active( 'pagos' ) ) {
		// REST API: webhook de Payphone.
		$loader->add_action( 'rest_api_init', 'Edu_Payment_Controller', 'register_rest_routes' );

		// Cron diario de pagos.
		if ( ! wp_next_scheduled( 'edu_payment_daily_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'edu_payment_daily_cron' );
		}
		$loader->add_action( 'edu_payment_daily_cron', 'Edu_Payment_Manager', 'daily_cron' );

		// Link de pago público (?edu_pago_token=...).
		$loader->add_action( 'template_redirect', 'Edu_Payment_Manager', 'render_payment_link_page' );
	} else {
		// Módulo apagado: retirar el cron si quedó programado.
		$cron_ts = wp_next_scheduled( 'edu_payment_daily_cron' );
		if ( $cron_ts ) {
			wp_unschedule_event( $cron_ts, 'edu_payment_daily_cron' );
		}
	}

	$loader->run();
}
add_action( 'plugins_loaded', 'edu_bootstrap' );

// PWA: manifest + service worker (módulo desactivable).
if ( Edu_Modules::is_active( 'pwa' ) ) {
	Edu_PWA::register();
}

/**
 * Bloquear login si la cuenta está suspendida.
 *
 * Prioridad 30: corre DESPUÉS del check de contraseña de WP (prioridad 20).
 * Solo actúa si WP ya autenticó al usuario correctamente ($user es WP_User).
 * Devolver WP_Error aquí impide el login tanto en wp-login.php como en UM.
 */
add_filter( 'authenticate', function ( $user, $username, $password ) {
	if ( ! ( $user instanceof WP_User ) ) {
		return $user;
	}
	if ( 'suspended' === get_user_meta( $user->ID, 'edu_account_status', true ) ) {
		return new WP_Error(
			'account_suspended',
			__( 'Tu cuenta está suspendida. Contacta a la institución.', 'sistema-educativo' )
		);
	}
	return $user;
}, 30, 3 );

/**
 * Ultimate Member 2.x ignora WP_Error con códigos desconocidos y muestra
 * "contraseña incorrecta" hardcoded. Este filtro registra 'account_suspended'
 * como código de terceros: UM entonces usa $user->get_error_message() en vez
 * del mensaje hardcoded, mostrando el texto correcto de suspensión.
 *
 * Ref: um_custom_authenticate_error_codes en um-actions-login.php (UM 2.10.x).
 */
add_filter( 'um_custom_authenticate_error_codes', function ( $codes ) {
	$codes[] = 'account_suspended';
	return $codes;
} );

// Filtros de remitente de email (ajustes del plugin).
add_filter( 'wp_mail_from', function ( $email ) {
	$custom = get_option( 'edu_email_from_address', '' );
	return ( $custom && is_email( $custom ) ) ? $custom : $email;
} );
add_filter( 'wp_mail_from_name', function ( $name ) {
	$custom = get_option( 'edu_email_from_name', '' );
	return $custom ?: $name;
} );
