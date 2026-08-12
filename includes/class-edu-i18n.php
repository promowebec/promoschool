<?php
/**
 * Carga del textdomain del plugin.
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_I18n {

	public static function load_textdomain() {
		load_plugin_textdomain(
			'sistema-educativo',
			false,
			dirname( EDU_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
