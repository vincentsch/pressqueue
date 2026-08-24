<?php
/**
 * Minimal WordPress function stubs for isolated unit tests.
 *
 * @package PressQueue\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

$GLOBALS['wp_version']                         = $GLOBALS['wp_version'] ?? '6.8.8';
$GLOBALS['pressqueue_test_actions']            = array();
$GLOBALS['pressqueue_test_hooks']              = array();
$GLOBALS['pressqueue_test_http_requests']      = array();
$GLOBALS['pressqueue_test_http_post_response'] = null;
$GLOBALS['pressqueue_test_deactivated']        = array();
$GLOBALS['pressqueue_test_is_multisite']       = false;
$GLOBALS['pressqueue_test_loaded_textdomains'] = array();
$GLOBALS['pressqueue_test_options']            = array();
$GLOBALS['pressqueue_test_option_autoloads']   = array();
$GLOBALS['pressqueue_test_roles']              = array();
$GLOBALS['pressqueue_test_current_user_caps']  = array();
$GLOBALS['pressqueue_test_valid_nonces']       = array();
$GLOBALS['pressqueue_test_as_next_action_id']  = 1000;
$GLOBALS['pressqueue_test_as_supports']        = array();
$GLOBALS['pressqueue_test_as_actions']         = array();

/**
 * Reset test hook state.
 */
function pressqueue_test_reset_wordpress_stubs(): void {
	$GLOBALS['pressqueue_test_actions']            = array();
	$GLOBALS['pressqueue_test_hooks']              = array();
	$GLOBALS['pressqueue_test_http_requests']      = array();
	$GLOBALS['pressqueue_test_http_post_response'] = null;
	$GLOBALS['pressqueue_test_deactivated']        = array();
	$GLOBALS['pressqueue_test_is_multisite']       = false;
	$GLOBALS['pressqueue_test_loaded_textdomains'] = array();
	$GLOBALS['pressqueue_test_options']            = array();
	$GLOBALS['pressqueue_test_option_autoloads']   = array();
	$GLOBALS['pressqueue_test_roles']              = array(
		'administrator' => new PressQueue_Test_Role(),
		'editor'        => new PressQueue_Test_Role(),
	);
	$GLOBALS['pressqueue_test_current_user_caps']  = array();
	$GLOBALS['pressqueue_test_valid_nonces']       = array();
	$GLOBALS['pressqueue_test_as_next_action_id']  = 1000;
	$GLOBALS['pressqueue_test_as_supports']        = array();
	$GLOBALS['pressqueue_test_as_actions']         = array();
	$GLOBALS['pressqueue_runtime_notice']          = null;
	$GLOBALS['wp_version']                         = '6.8.8';
}

/**
 * Register an action callback.
 *
 * @param string   $hook_name Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $accepted_args Accepted arguments.
 */
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ): void {
	$GLOBALS['pressqueue_test_hooks'][ $hook_name ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);
}

/**
 * Register a filter callback.
 *
 * @param string   $hook_name Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $accepted_args Accepted arguments.
 */
function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ): void {
	add_action( $hook_name, $callback, $priority, $accepted_args );
}

/**
 * Run action callbacks.
 *
 * @param string $hook_name Hook name.
 * @param mixed  ...$args Hook arguments.
 */
function do_action( $hook_name, ...$args ): void {
	$GLOBALS['pressqueue_test_actions'][] = array(
		'hook' => $hook_name,
		'args' => $args,
	);

	if ( empty( $GLOBALS['pressqueue_test_hooks'][ $hook_name ] ) ) {
		return;
	}

	ksort( $GLOBALS['pressqueue_test_hooks'][ $hook_name ] );

	foreach ( $GLOBALS['pressqueue_test_hooks'][ $hook_name ] as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			call_user_func_array(
				$registered['callback'],
				array_slice( $args, 0, $registered['accepted_args'] )
			);
		}
	}
}

/**
 * Check whether an action has fired.
 *
 * @param string $hook_name Hook name.
 * @return int
 */
function did_action( $hook_name ): int {
	$count = 0;

	foreach ( $GLOBALS['pressqueue_test_actions'] as $action ) {
		if ( $hook_name === $action['hook'] ) {
			++$count;
		}
	}

	return $count;
}

/**
 * Register an activation hook.
 *
 * @param string   $file File path.
 * @param callable $callback Callback.
 */
function register_activation_hook( $file, $callback ): void {
	add_action( 'activate_' . plugin_basename( $file ), $callback, 10, 1 );
}

/**
 * Register a deactivation hook.
 *
 * @param string   $file File path.
 * @param callable $callback Callback.
 */
function register_deactivation_hook( $file, $callback ): void {
	add_action( 'deactivate_' . plugin_basename( $file ), $callback, 10, 1 );
}

/**
 * Return a plugin directory path.
 *
 * @param string $file File path.
 * @return string
 */
function plugin_dir_path( $file ): string {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

/**
 * Return a plugin basename.
 *
 * @param string $file File path.
 * @return string
 */
function plugin_basename( $file ): string {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

/**
 * Multisite flag.
 *
 * @return bool
 */
function is_multisite(): bool {
	return (bool) $GLOBALS['pressqueue_test_is_multisite'];
}

/**
 * Translation stub.
 *
 * @param string $text Text.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

/**
 * Escape HTML for tests.
 *
 * @param string $text Text.
 * @return string
 */
function esc_html( $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escaped translation stub.
 *
 * @param string $text Text.
 * @param string $domain Text domain.
 * @return string
 */
function esc_html__( $text, $domain = 'default' ): string {
	return esc_html( __( $text, $domain ) );
}

/**
 * Escape an attribute for tests.
 *
 * @param string $text Text.
 * @return string
 */
function esc_attr( $text ): string {
	return esc_html( $text );
}

/**
 * Escape a URL for tests.
 *
 * @param string $url URL.
 * @return string
 */
function esc_url( $url ): string {
	return esc_html( $url );
}

/**
 * Sanitize a text field for tests.
 *
 * @param mixed $value Input value.
 * @return string
 */
function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

/**
 * Sanitize a key for tests.
 *
 * @param mixed $value Input value.
 * @return string
 */
function sanitize_key( $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $value ) ?? '' );
}

/**
 * Strip slashes from request data for tests.
 *
 * @param mixed $value Input value.
 * @return mixed
 */
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * Check the current user's test capabilities.
 *
 * @param string $capability Capability.
 * @param mixed  ...$args Additional arguments.
 * @return bool
 */
function current_user_can( $capability, ...$args ): bool {
	unset( $args );

	return ! empty( $GLOBALS['pressqueue_test_current_user_caps'][ $capability ] );
}

/**
 * Check a test nonce for administrator mutations.
 *
 * @param int|string $action Nonce action.
 * @param string     $query_arg Request field.
 * @return int
 */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ): int {
	$nonce = isset( $_REQUEST[ $query_arg ] ) ? wp_unslash( $_REQUEST[ $query_arg ] ) : '';

	if ( ! is_string( $nonce ) ) {
		wp_die( 'Nonce check failed.' );
	}

	$expected = $GLOBALS['pressqueue_test_valid_nonces'][ (string) $action ] ?? null;
	if ( is_string( $expected ) && hash_equals( $expected, $nonce ) ) {
		return 1;
	}

	wp_die( 'Nonce check failed.' );
}

/**
 * Deactivation recorder.
 *
 * @param string $plugin Plugin basename.
 */
function deactivate_plugins( $plugin ): void {
	$GLOBALS['pressqueue_test_deactivated'][] = $plugin;
}

/**
 * Test wp_die implementation.
 *
 * @param string $message Message.
 * @throws RuntimeException Always throws.
 */
function wp_die( $message ): void {
	throw new RuntimeException( $message );
}

/**
 * Text domain recorder.
 *
 * @param string $domain Domain.
 * @param bool   $deprecated Deprecated argument.
 * @param string $path Relative language path.
 * @return bool
 */
function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ): bool {
	unset( $deprecated );

	$GLOBALS['pressqueue_test_loaded_textdomains'][] = array(
		'domain' => $domain,
		'path'   => $path,
	);

	return true;
}

/**
 * Add an option.
 *
 * @param string $option Option name.
 * @param mixed  $value Option value.
 * @param string $deprecated Deprecated value.
 * @param mixed  $autoload Autoload flag.
 * @return bool
 */
function add_option( $option, $value = '', $deprecated = '', $autoload = null ): bool {
	unset( $deprecated );

	if ( array_key_exists( $option, $GLOBALS['pressqueue_test_options'] ) ) {
		return false;
	}

	$GLOBALS['pressqueue_test_options'][ $option ]          = $value;
	$GLOBALS['pressqueue_test_option_autoloads'][ $option ] = $autoload;

	return true;
}

/**
 * Read an option.
 *
 * @param string $option Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_option( $option, $default = false ) {
	return array_key_exists( $option, $GLOBALS['pressqueue_test_options'] )
		? $GLOBALS['pressqueue_test_options'][ $option ]
		: $default;
}

/**
 * Update an option.
 *
 * @param string $option Option name.
 * @param mixed  $value Option value.
 * @param mixed  $autoload Autoload flag.
 * @return bool
 */
function update_option( $option, $value, $autoload = null ): bool {
	$GLOBALS['pressqueue_test_options'][ $option ] = $value;

	if ( null !== $autoload ) {
		$GLOBALS['pressqueue_test_option_autoloads'][ $option ] = $autoload;
	}

	return true;
}

/**
 * Delete an option.
 *
 * @param string $option Option name.
 * @return bool
 */
function delete_option( $option ): bool {
	$existed = array_key_exists( $option, $GLOBALS['pressqueue_test_options'] );

	unset( $GLOBALS['pressqueue_test_options'][ $option ], $GLOBALS['pressqueue_test_option_autoloads'][ $option ] );

	return $existed;
}

/**
 * Return a test role.
 *
 * @param string $role Role name.
 * @return PressQueue_Test_Role|null
 */
function get_role( $role ): ?PressQueue_Test_Role {
	return $GLOBALS['pressqueue_test_roles'][ $role ] ?? null;
}

/**
 * Test role implementation.
 */
class PressQueue_Test_Role {
	/**
	 * Granted capabilities.
	 *
	 * @var array<string,bool>
	 */
	public array $capabilities = array();

	/**
	 * Grant a capability.
	 *
	 * @param string $capability Capability.
	 */
	public function add_cap( $capability ): void {
		$this->capabilities[ $capability ] = true;
	}

	/**
	 * Check a capability.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	public function has_cap( $capability ): bool {
		return ! empty( $this->capabilities[ $capability ] );
	}
}

/**
 * HTTP request stub that makes accidental external access visible.
 *
 * @param string $url URL.
 * @return never
 */
function wp_remote_get( $url ) {
	$GLOBALS['pressqueue_test_http_requests'][] = $url;

	throw new RuntimeException( 'External HTTP request attempted: ' . $url );
}

/**
 * HTTP post stub that makes accidental external access visible.
 *
 * @param string              $url URL.
 * @param array<string,mixed> $args Request arguments.
 * @return mixed
 */
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['pressqueue_test_http_requests'][] = array(
		'args'   => $args,
		'method' => 'POST',
		'url'    => $url,
	);

	if ( null !== $GLOBALS['pressqueue_test_http_post_response'] ) {
		return $GLOBALS['pressqueue_test_http_post_response'];
	}

	throw new RuntimeException( 'External HTTP request attempted: ' . $url );
}

/**
 * Minimal WordPress error object for transport tests.
 */
class WP_Error {
	/**
	 * Error code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Constructor.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 */
	public function __construct( string $code, string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Return the first error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * Return the first error message.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}

/**
 * Check whether a value is a WordPress error.
 *
 * @param mixed $thing Value.
 * @return bool
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/**
 * Action Scheduler feature support stub.
 *
 * @param string $feature Feature name.
 * @return bool
 */
function as_supports( $feature ): bool {
	return ! empty( $GLOBALS['pressqueue_test_as_supports'][ $feature ] );
}

/**
 * Return the next scheduled action ID for matching arguments.
 *
 * @param string           $hook Hook name.
 * @param array<int,mixed> $args Arguments.
 * @param string           $group Group.
 * @return int|false
 */
function as_next_scheduled_action( $hook, $args = array(), $group = '' ) {
	foreach ( $GLOBALS['pressqueue_test_as_actions'] as $action ) {
		if ( $hook === $action['hook'] && $args === $action['args'] && $group === $action['group'] ) {
			return $action['id'];
		}
	}

	return false;
}

/**
 * Schedule a recurring action.
 *
 * @param int              $timestamp Timestamp.
 * @param int              $interval Interval.
 * @param string           $hook Hook name.
 * @param array<int,mixed> $args Arguments.
 * @param string           $group Group.
 * @param bool             $unique Unique flag.
 * @return int
 */
function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '', $unique = false ): int {
	if ( $unique ) {
		$existing = as_next_scheduled_action( $hook, $args, $group );
		if ( false !== $existing ) {
			return (int) $existing;
		}
	}

	$id = $GLOBALS['pressqueue_test_as_next_action_id']++;
	$GLOBALS['pressqueue_test_as_actions'][] = array(
		'args'      => $args,
		'group'     => $group,
		'hook'      => $hook,
		'id'        => $id,
		'interval'  => $interval,
		'timestamp' => $timestamp,
		'type'      => 'recurring',
	);

	return $id;
}

/**
 * Schedule a single action.
 *
 * @param int              $timestamp Timestamp.
 * @param string           $hook Hook name.
 * @param array<int,mixed> $args Arguments.
 * @param string           $group Group.
 * @param bool             $unique Unique flag.
 * @return int
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false ): int {
	if ( $unique ) {
		$existing = as_next_scheduled_action( $hook, $args, $group );
		if ( false !== $existing ) {
			return (int) $existing;
		}
	}

	$id = $GLOBALS['pressqueue_test_as_next_action_id']++;
	$GLOBALS['pressqueue_test_as_actions'][] = array(
		'args'      => $args,
		'group'     => $group,
		'hook'      => $hook,
		'id'        => $id,
		'timestamp' => $timestamp,
		'type'      => 'single',
	);

	return $id;
}

/**
 * Unschedule one matching action.
 *
 * @param string           $hook Hook name.
 * @param array<int,mixed> $args Arguments.
 * @param string           $group Group.
 */
function as_unschedule_action( $hook, $args = array(), $group = '' ): void {
	foreach ( $GLOBALS['pressqueue_test_as_actions'] as $index => $action ) {
		if ( $hook === $action['hook'] && $args === $action['args'] && $group === $action['group'] ) {
			unset( $GLOBALS['pressqueue_test_as_actions'][ $index ] );
			$GLOBALS['pressqueue_test_as_actions'] = array_values( $GLOBALS['pressqueue_test_as_actions'] );
			return;
		}
	}
}

/**
 * Unschedule all matching actions.
 *
 * @param string           $hook Hook name.
 * @param array<int,mixed> $args Arguments.
 * @param string           $group Group.
 */
function as_unschedule_all_actions( $hook = '', $args = array(), $group = '' ): void {
	$GLOBALS['pressqueue_test_as_actions'] = array_values(
		array_filter(
			$GLOBALS['pressqueue_test_as_actions'],
			static function ( array $action ) use ( $hook, $args, $group ): bool {
				if ( '' !== $hook && $hook !== $action['hook'] ) {
					return true;
				}

				if ( array() !== $args && $args !== $action['args'] ) {
					return true;
				}

				return '' !== $group && $group !== $action['group'];
			}
		)
	);
}
