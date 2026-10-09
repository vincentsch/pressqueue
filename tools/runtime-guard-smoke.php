<?php
/**
 * Boundary runtime guard smoke checks.
 *
 * @package PressQueue\Tools
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This smoke check must run from the command line.\n" );
	exit( 1 );
}

$scenario = $argv[1] ?? '';
$root     = dirname( __DIR__ );

if ( ! in_array( $scenario, array( 'php', 'wordpress', 'network', 'package' ), true ) ) {
	fwrite( STDERR, "Usage: php tools/runtime-guard-smoke.php php|wordpress|network|package\n" );
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

$GLOBALS['pressqueue_smoke_actions']      = array();
$GLOBALS['pressqueue_smoke_fired_actions'] = array();
$GLOBALS['pressqueue_smoke_deactivated']  = array();
$GLOBALS['pressqueue_smoke_notices']      = array();
$GLOBALS['pressqueue_smoke_is_multisite'] = 'network' === $scenario;
$GLOBALS['pressqueue_smoke_is_network_admin'] = 'network' === $scenario;
$GLOBALS['wp_version']                    = 'wordpress' === $scenario ? '6.7.7' : '6.8.8';

if ( 'network' === $scenario ) {
	$_GET['action'] = 'activate';
	$_GET['plugin'] = 'pressqueue/pressqueue.php';
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['pressqueue_smoke_actions'][ $hook_name ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		add_action( $hook_name, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name ) {
		$GLOBALS['pressqueue_smoke_fired_actions'][] = $hook_name;

		if ( empty( $GLOBALS['pressqueue_smoke_actions'][ $hook_name ] ) ) {
			return;
		}

		$args = array_slice( func_get_args(), 1 );
		ksort( $GLOBALS['pressqueue_smoke_actions'][ $hook_name ] );

		foreach ( $GLOBALS['pressqueue_smoke_actions'][ $hook_name ] as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				call_user_func_array(
					$registered['callback'],
					array_slice( $args, 0, $registered['accepted_args'] )
				);
			}
		}
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return rtrim( dirname( $file ), '/\\' ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return 'pressqueue/' . basename( $file );
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {
		add_action( 'activate_' . plugin_basename( $file ), $callback, 10, 1 );
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {
		add_action( 'deactivate_' . plugin_basename( $file ), $callback, 10, 1 );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return (bool) $GLOBALS['pressqueue_smoke_is_multisite'];
	}
}

if ( ! function_exists( 'is_network_admin' ) ) {
	function is_network_admin() {
		return (bool) $GLOBALS['pressqueue_smoke_is_network_admin'];
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}

if ( ! function_exists( 'deactivate_plugins' ) ) {
	function deactivate_plugins( $plugin ) {
		$GLOBALS['pressqueue_smoke_deactivated'][] = $plugin;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message ) {
		throw new RuntimeException( html_entity_decode( $message, ENT_QUOTES, 'UTF-8' ) );
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain() {
		return true;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url ) {
		throw new RuntimeException( 'External HTTP request attempted: ' . $url );
	}
}

if ( 'php' === $scenario && version_compare( PHP_VERSION, '8.1', '>=' ) ) {
	fwrite( STDOUT, "PHP 8.0 boundary smoke requires PHP 8.0. Current PHP is " . PHP_VERSION . ".\n" );
	exit( 0 );
}

$expected = array(
	'php'       => 'PressQueue requires PHP 8.1 or newer.',
	'wordpress' => 'PressQueue requires WordPress 6.8 or newer.',
	'network'   => 'PressQueue does not support network activation yet. Activate it per site.',
	'package'   => '',
);

try {
	require $root . '/pressqueue.php';
} catch ( RuntimeException $exception ) {
	if ( 'network' !== $scenario ) {
		throw $exception;
	}

	if ( $expected[ $scenario ] !== $exception->getMessage() ) {
		fwrite( STDERR, "Unexpected notice: " . $exception->getMessage() . "\n" );
		exit( 1 );
	}

	if ( in_array( 'pressqueue_bootstrap_loaded', $GLOBALS['pressqueue_smoke_fired_actions'], true ) ) {
		fwrite( STDERR, "Network activation bootstrapped before refusal.\n" );
		exit( 1 );
	}

	if ( ! in_array( 'pressqueue/pressqueue.php', $GLOBALS['pressqueue_smoke_deactivated'], true ) ) {
		fwrite( STDERR, "The failed activation did not deactivate PressQueue.\n" );
		exit( 1 );
	}

	fwrite( STDOUT, $scenario . " boundary smoke passed.\n" );
	exit( 0 );
}

if ( 'package' === $scenario ) {
	if ( empty( $GLOBALS['pressqueue_smoke_actions']['activate_pressqueue/pressqueue.php'] ) ) {
		fwrite( STDERR, "Activation hook was not registered.\n" );
		exit( 1 );
	}

	fwrite( STDOUT, "Package bootstrap smoke passed.\n" );
	exit( 0 );
}

try {
	pressqueue_activate( 'network' === $scenario );
} catch ( RuntimeException $exception ) {
	if ( $expected[ $scenario ] !== $exception->getMessage() ) {
		fwrite( STDERR, "Unexpected notice: " . $exception->getMessage() . "\n" );
		exit( 1 );
	}

	if ( ! in_array( 'pressqueue/pressqueue.php', $GLOBALS['pressqueue_smoke_deactivated'], true ) ) {
		fwrite( STDERR, "The failed activation did not deactivate PressQueue.\n" );
		exit( 1 );
	}

	fwrite( STDOUT, $scenario . " boundary smoke passed.\n" );
	exit( 0 );
}

fwrite( STDERR, "The runtime guard did not reject the " . $scenario . " scenario.\n" );
exit( 1 );
