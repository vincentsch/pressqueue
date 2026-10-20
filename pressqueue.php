<?php
/**
 * Plugin Name: PressQueue
 * Plugin URI: https://www.pressqueue.com
 * Description: Share your WordPress posts through Buffer, with rules for which posts go where, message previews and an image made from each title.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author: Vincent Schmalbach
 * Author URI: https://www.vincentschmalbach.com
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: pressqueue
 * Domain Path: /languages
 *
 * @package PressQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PRESSQUEUE_VERSION' ) ) {
	define( 'PRESSQUEUE_VERSION', '0.1.0' );
}

if ( ! defined( 'PRESSQUEUE_MINIMUM_PHP' ) ) {
	define( 'PRESSQUEUE_MINIMUM_PHP', '8.1' );
}

if ( ! defined( 'PRESSQUEUE_MINIMUM_WORDPRESS' ) ) {
	define( 'PRESSQUEUE_MINIMUM_WORDPRESS', '6.8' );
}

if ( ! defined( 'PRESSQUEUE_FILE' ) ) {
	define( 'PRESSQUEUE_FILE', __FILE__ );
}

if ( ! defined( 'PRESSQUEUE_DIR' ) ) {
	define( 'PRESSQUEUE_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'PRESSQUEUE_BASENAME' ) ) {
	define( 'PRESSQUEUE_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * Register an administrator-facing requirement notice.
 *
 * @param string $message Notice text.
 */
function pressqueue_register_admin_notice( $message ): void {
	$GLOBALS['pressqueue_runtime_notice'] = $message;

	add_action( 'admin_notices', 'pressqueue_render_admin_notice' );
	add_action( 'network_admin_notices', 'pressqueue_render_admin_notice' );
}

/**
 * Render a stored requirement notice.
 */
function pressqueue_render_admin_notice(): void {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $GLOBALS['pressqueue_runtime_notice'] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $GLOBALS['pressqueue_runtime_notice'] )
	);
}

/**
 * Check the runtime requirements that must pass before autoloading.
 *
 * @param bool $network_wide Whether activation was requested network-wide.
 * @return true|string True when supported, otherwise the exact notice text.
 */
function pressqueue_check_runtime_requirements( $network_wide ) {
	if ( version_compare( PHP_VERSION, PRESSQUEUE_MINIMUM_PHP, '<' ) ) {
		return __( 'PressQueue requires PHP 8.1 or newer.', 'pressqueue' );
	}

	global $wp_version;

	if (
		! isset( $wp_version )
		|| version_compare( $wp_version, PRESSQUEUE_MINIMUM_WORDPRESS, '<' )
	) {
		return __( 'PressQueue requires WordPress 6.8 or newer.', 'pressqueue' );
	}

	if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
		return __( 'PressQueue does not support network activation yet. Activate it per site.', 'pressqueue' );
	}

	return true;
}

/**
 * Sanitize a raw activation request value.
 *
 * @param mixed $value Raw request value.
 * @return mixed Sanitized request value.
 */
function pressqueue_sanitize_activation_request_value( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'pressqueue_sanitize_activation_request_value', $value );
	}

	return sanitize_text_field( $value );
}

/**
 * Read an activation request value before WordPress calls activation hooks.
 *
 * @param string $key Request key.
 * @return mixed|null
 */
function pressqueue_get_activation_request_value( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Runtime guard only reads activation context before WordPress calls hooks.
	if ( isset( $_POST[ $key ] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Runtime guard sanitizes this value through pressqueue_sanitize_activation_request_value().
		return pressqueue_sanitize_activation_request_value( wp_unslash( $_POST[ $key ] ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Runtime guard only reads activation context before WordPress calls hooks.
	if ( isset( $_GET[ $key ] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Runtime guard sanitizes this value through pressqueue_sanitize_activation_request_value().
		return pressqueue_sanitize_activation_request_value( wp_unslash( $_GET[ $key ] ) );
	}

	return null;
}

/**
 * Check whether a request value names this plugin.
 *
 * @param string $key Request key.
 * @return bool Whether the request value contains PressQueue's basename.
 */
function pressqueue_activation_request_has_plugin( $key ): bool {
	$value = pressqueue_get_activation_request_value( $key );

	if ( is_array( $value ) ) {
		return in_array( PRESSQUEUE_BASENAME, $value, true );
	}

	return PRESSQUEUE_BASENAME === $value;
}

/**
 * Detect a multisite network activation request during plugin inclusion.
 *
 * @return bool Whether the current request is trying to network-activate PressQueue.
 */
function pressqueue_is_network_activation_request(): bool {
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		return false;
	}

	if ( function_exists( 'is_network_admin' ) && ! is_network_admin() ) {
		return false;
	}

	$action = pressqueue_get_activation_request_value( 'action' );

	if ( is_array( $action ) ) {
		$action = reset( $action );
	}

	if ( 'activate' === $action && pressqueue_activation_request_has_plugin( 'plugin' ) ) {
		return true;
	}

	if ( 'activate-selected' === $action && pressqueue_activation_request_has_plugin( 'checked' ) ) {
		return true;
	}

	$network_wide = pressqueue_get_activation_request_value( 'networkwide' );

	return pressqueue_activation_request_has_plugin( 'plugin' )
		&& ( '1' === (string) $network_wide || true === $network_wide );
}

/**
 * Mark activation as failed and leave the plugin inactive.
 *
 * @param string $message Exact administrator-facing failure message.
 */
function pressqueue_fail_activation( $message ): void {
	if ( function_exists( 'deactivate_plugins' ) ) {
		deactivate_plugins( PRESSQUEUE_BASENAME );
	}

	unset( $_GET['activate'] );

	if ( function_exists( 'wp_die' ) ) {
		wp_die( esc_html( $message ) );
	}
}

/**
 * Load Composer's autoloader after runtime guards pass.
 *
 * @return bool Whether autoloading is available.
 */
function pressqueue_load_autoloader(): bool {
	$autoload = PRESSQUEUE_DIR . 'vendor/autoload.php';

	if ( ! file_exists( $autoload ) ) {
		pressqueue_register_admin_notice(
			__( 'PressQueue could not load its Composer autoloader. Run composer install before activating.', 'pressqueue' )
		);

		return false;
	}

	require_once $autoload;

	return true;
}

/**
 * Load the bundled Action Scheduler registration file after Composer autoloads.
 */
function pressqueue_load_action_scheduler(): void {
	if ( class_exists( 'PressQueue\Scheduler\ActionSchedulerLoader' ) ) {
		PressQueue\Scheduler\ActionSchedulerLoader::load();
	}
}

/**
 * Activation hook.
 *
 * @param bool $network_wide Whether activation was requested network-wide.
 */
function pressqueue_activate( $network_wide ): void {
	$requirements = pressqueue_check_runtime_requirements( $network_wide );

	if ( true !== $requirements ) {
		pressqueue_fail_activation( $requirements );
		return;
	}

	if ( ! pressqueue_load_autoloader() ) {
		pressqueue_fail_activation(
			__( 'PressQueue could not load its Composer autoloader. Run composer install before activating.', 'pressqueue' )
		);
		return;
	}

	pressqueue_load_action_scheduler();

	try {
		PressQueue\Plugin::activate( (bool) $network_wide );
	} catch ( \Throwable $throwable ) {
		pressqueue_fail_activation(
			class_exists( 'PressQueue\Security\SafeOperatorError' )
				? PressQueue\Security\SafeOperatorError::activation_message( $throwable )
				: __( 'PressQueue could not complete activation setup.', 'pressqueue' )
		);
	}
}

/**
 * Deactivation hook.
 *
 * @param bool $network_wide Whether deactivation was requested network-wide.
 */
function pressqueue_deactivate( $network_wide ): void {
	if ( ! pressqueue_load_autoloader() ) {
		return;
	}

	pressqueue_load_action_scheduler();

	PressQueue\Plugin::deactivate( (bool) $network_wide );
}

register_activation_hook( __FILE__, 'pressqueue_activate' );
register_deactivation_hook( __FILE__, 'pressqueue_deactivate' );

$pressqueue_network_activation = pressqueue_is_network_activation_request();
$pressqueue_requirements       = pressqueue_check_runtime_requirements( $pressqueue_network_activation );

if ( true !== $pressqueue_requirements ) {
	if ( $pressqueue_network_activation ) {
		pressqueue_fail_activation( $pressqueue_requirements );
		return;
	}

	pressqueue_register_admin_notice( $pressqueue_requirements );
	return;
}

if ( ! pressqueue_load_autoloader() ) {
	return;
}

pressqueue_load_action_scheduler();

PressQueue\Plugin::bootstrap();
