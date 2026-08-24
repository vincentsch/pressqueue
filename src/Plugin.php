<?php
/**
 * Core plugin lifecycle entry point.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue;

/**
 * Coordinates bootstrap and lifecycle extension points.
 */
final class Plugin {
	/**
	 * Register runtime hooks.
	 */
	public static function bootstrap(): void {
		do_action( 'pressqueue_bootstrap_loaded' );
	}

	/**
	 * Run activation extension points.
	 *
	 * @param bool $network_wide Whether activation was requested network-wide.
	 */
	public static function activate( bool $network_wide ): void {
		do_action( 'pressqueue_before_activation', $network_wide );
		do_action( 'pressqueue_schema_upgrade', $network_wide );
		do_action( 'pressqueue_capability_registration', $network_wide );
		do_action( 'pressqueue_after_activation', $network_wide );
	}

	/**
	 * Run deactivation extension points.
	 *
	 * @param bool $network_wide Whether deactivation was requested network-wide.
	 */
	public static function deactivate( bool $network_wide ): void {
		do_action( 'pressqueue_before_deactivation', $network_wide );
		do_action( 'pressqueue_after_deactivation', $network_wide );
	}
}
