<?php
/**
 * Desactivación del plugin.
 *
 * Limpia eventos cron y rewrite rules. NO toca roles ni tablas:
 * la eliminación definitiva es responsabilidad de uninstall.php.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( 'edu_payment_daily_cron' );
		wp_unschedule_hook( 'edu_wa_send_announcement' );

		flush_rewrite_rules();
	}
}
