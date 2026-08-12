<?php
/**
 * Desinstalación del plugin Sistema Educativo Integral.
 *
 * Elimina roles personalizados y opciones del plugin.
 * Por seguridad, NO borra las tablas por defecto (contienen datos académicos
 * de menores). Para borrarlas, define en wp-config.php antes de desinstalar:
 *
 *     define( 'EDU_DROP_TABLES_ON_UNINSTALL', true );
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-edu-roles.php';

Edu_Roles::remove_roles();

// Crons programados por el plugin.
wp_clear_scheduled_hook( 'edu_payment_daily_cron' );
wp_unschedule_hook( 'edu_wa_send_announcement' );

// Borrar TODAS las opciones y transients con prefijo edu_ (credenciales
// Payphone/WhatsApp, páginas de portales, versiones, diagnósticos, etc.).
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'edu\\_%'
	    OR option_name LIKE '\\_transient\\_edu\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_edu\\_%'"
);

// User meta del plugin (p.ej. edu_account_status).
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'edu\\_%'" );

if ( defined( 'EDU_DROP_TABLES_ON_UNINSTALL' ) && EDU_DROP_TABLES_ON_UNINSTALL ) {
	global $wpdb;

	// Orden inverso de dependencias para que un futuro motor con FKs no tropiece.
	$tables = array(
		'edu_payments',
		'edu_payment_config',
		'edu_audit',
		'edu_announcement_recipients',
		'edu_announcements',
		'edu_announcement_templates',
		'edu_attendance',
		'edu_year_scores',
		'edu_trimester_scores',
		'edu_parcial_scores',
		'edu_grades_log',
		'edu_grade_components',
		'edu_rubric_scores',
		'edu_rubrics',
		'edu_submission_files',
		'edu_submissions',
		'edu_assignment_files',
		'edu_assignments',
		'edu_teacher_assignments',
		'edu_parent_student',
		'edu_parents',
		'edu_students',
		'edu_teachers',
		'edu_grade_subjects',
		'edu_subjects',
		'edu_subjects_catalog',
		'edu_grades',
		'edu_trimesters',
		'edu_periods',
		'edu_institutions',
	);

	foreach ( $tables as $tbl ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$tbl}" );
	}
}
