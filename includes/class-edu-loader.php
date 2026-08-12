<?php
/**
 * Registro centralizado de hooks (inspirado en el boilerplate WPPB).
 *
 * @package SistemaEducativo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Edu_Loader {

	protected $actions = array();
	protected $filters = array();

	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
		$hooks[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return $hooks;
	}

	public function run() {
		foreach ( $this->filters as $h ) {
			add_filter( $h['hook'], array( $h['component'], $h['callback'] ), $h['priority'], $h['accepted_args'] );
		}
		foreach ( $this->actions as $h ) {
			add_action( $h['hook'], array( $h['component'], $h['callback'] ), $h['priority'], $h['accepted_args'] );
		}
	}
}
