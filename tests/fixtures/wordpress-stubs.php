<?php
/**
 * Minimal WordPress function stubs for isolated unit tests.
 *
 * @package PressQueue\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Minimal database base class for unit-test doubles.
	 */
	class wpdb {
		/**
		 * Table prefix.
		 *
		 * @var string
		 */
		public string $prefix = 'wp_';

		/**
		 * Options table.
		 *
		 * @var string
		 */
		public string $options = 'wp_options';

		/**
		 * Postmeta table.
		 *
		 * @var string
		 */
		public string $postmeta = 'wp_postmeta';

		/**
		 * Last error.
		 *
		 * @var string
		 */
		public string $last_error = '';

		/**
		 * Insert ID.
		 *
		 * @var int
		 */
		public int $insert_id = 0;

		/**
		 * Queries executed against the stub.
		 *
		 * @var array<int,string>
		 */
		public array $queries = array();

		/**
		 * Prepare SQL for test doubles.
		 *
		 * @param string $query Query.
		 * @param mixed  ...$args Args.
		 * @return string
		 */
		public function prepare( $query, $args = array() ): string {
			if ( ! is_array( $args ) ) {
				$args = array_slice( func_get_args(), 1 );
			}

			foreach ( $args as $arg ) {
				preg_match( '/%([sdi])/', (string) $query, $matches );
				$placeholder = $matches[1] ?? 's';
				if ( 'i' === $placeholder ) {
					$replacement = '`' . str_replace( '`', '', (string) $arg ) . '`';
				} else {
					$replacement = is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
				}

				$query = preg_replace( '/%[sdi]/', $replacement, (string) $query, 1 ) ?? (string) $query;
			}

			return (string) $query;
		}

		/**
		 * Record a query.
		 *
		 * @param string $query Query.
		 * @return int
		 */
		public function query( $query ): int {
			$this->queries[] = (string) $query;

			return 1;
		}

		/**
		 * Escape a LIKE fragment.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		public function esc_like( $text ): string {
			return addcslashes( (string) $text, '_%\\' );
		}
	}
}

$GLOBALS['wp_version']                          = $GLOBALS['wp_version'] ?? '6.8.8';
$GLOBALS['pressqueue_test_actions']             = array();
$GLOBALS['pressqueue_test_hooks']               = array();
$GLOBALS['pressqueue_test_http_requests']       = array();
$GLOBALS['pressqueue_test_http_post_response']  = null;
$GLOBALS['pressqueue_test_deactivated']         = array();
$GLOBALS['pressqueue_test_is_multisite']        = false;
$GLOBALS['pressqueue_test_loaded_textdomains']  = array();
$GLOBALS['pressqueue_test_options']             = array();
$GLOBALS['pressqueue_test_option_autoloads']    = array();
$GLOBALS['pressqueue_test_roles']               = array();
$GLOBALS['pressqueue_test_current_user_caps']   = array();
$GLOBALS['pressqueue_test_current_user_id']     = 1;
$GLOBALS['pressqueue_test_valid_nonces']        = array();
$GLOBALS['pressqueue_test_as_next_action_id']   = 1000;
$GLOBALS['pressqueue_test_as_supports']         = array();
$GLOBALS['pressqueue_test_as_actions']          = array();
$GLOBALS['pressqueue_test_posts']               = array();
$GLOBALS['pressqueue_test_post_meta']           = array();
$GLOBALS['pressqueue_test_attachment_urls']     = array();
$GLOBALS['pressqueue_test_attachment_metadata'] = array();
$GLOBALS['pressqueue_test_featured_images']     = array();
$GLOBALS['pressqueue_test_transients']          = array();
$GLOBALS['pressqueue_test_registered_meta']     = array();
$GLOBALS['pressqueue_test_rest_routes']         = array();
$GLOBALS['pressqueue_test_meta_boxes']          = array();
$GLOBALS['pressqueue_test_logged_in']           = true;
$GLOBALS['pressqueue_test_wp_cli_commands']     = array();
$GLOBALS['pressqueue_test_wp_cli_halt_status']  = null;
$GLOBALS['pressqueue_test_wp_cli_lines']        = array();
$GLOBALS['pressqueue_test_redirects']           = array();
$GLOBALS['pressqueue_test_scripts']             = array();
$GLOBALS['pressqueue_test_script_translations'] = array();
$GLOBALS['pressqueue_test_users']               = array();
$GLOBALS['pressqueue_test_sites']               = array( 1 );
$GLOBALS['pressqueue_test_current_blog_id']     = 1;
$GLOBALS['pressqueue_test_timezone']            = 'UTC';

/**
 * Reset test hook state.
 */
function pressqueue_test_reset_wordpress_stubs(): void {
	$GLOBALS['pressqueue_test_actions']             = array();
	$GLOBALS['pressqueue_test_hooks']               = array();
	$GLOBALS['pressqueue_test_http_requests']       = array();
	$GLOBALS['pressqueue_test_http_post_response']  = null;
	$GLOBALS['pressqueue_test_deactivated']         = array();
	$GLOBALS['pressqueue_test_is_multisite']        = false;
	$GLOBALS['pressqueue_test_loaded_textdomains']  = array();
	$GLOBALS['pressqueue_test_options']             = array();
	$GLOBALS['pressqueue_test_option_autoloads']    = array();
	$GLOBALS['pressqueue_test_roles']               = array(
		'administrator' => new PressQueue_Test_Role(),
		'editor'        => new PressQueue_Test_Role(),
	);
	$GLOBALS['pressqueue_test_current_user_caps']   = array();
	$GLOBALS['pressqueue_test_current_user_id']     = 1;
	$GLOBALS['pressqueue_test_valid_nonces']        = array();
	$GLOBALS['pressqueue_test_as_next_action_id']   = 1000;
	$GLOBALS['pressqueue_test_as_supports']         = array();
	$GLOBALS['pressqueue_test_as_actions']          = array();
	$GLOBALS['pressqueue_test_posts']               = array();
	$GLOBALS['pressqueue_test_post_meta']           = array();
	$GLOBALS['pressqueue_test_attachment_urls']     = array();
	$GLOBALS['pressqueue_test_attachment_metadata'] = array();
	$GLOBALS['pressqueue_test_featured_images']     = array();
	$GLOBALS['pressqueue_test_transients']          = array();
	$GLOBALS['pressqueue_test_registered_meta']     = array();
	$GLOBALS['pressqueue_test_rest_routes']         = array();
	$GLOBALS['pressqueue_test_meta_boxes']          = array();
	$GLOBALS['pressqueue_test_logged_in']           = true;
	$GLOBALS['pressqueue_runtime_notice']           = null;
	$GLOBALS['pressqueue_test_wp_cli_commands']     = array();
	$GLOBALS['pressqueue_test_wp_cli_halt_status']  = null;
	$GLOBALS['pressqueue_test_wp_cli_lines']        = array();
	$GLOBALS['pressqueue_test_redirects']           = array();
	$GLOBALS['pressqueue_test_scripts']             = array();
	$GLOBALS['pressqueue_test_script_translations'] = array();
	$GLOBALS['pressqueue_test_users']               = array(
		1 => new WP_User( 1, 'user@example.test', 'Test User' ),
	);
	$GLOBALS['pressqueue_test_sites']               = array( 1 );
	$GLOBALS['pressqueue_test_current_blog_id']     = 1;
	$GLOBALS['pressqueue_test_timezone']            = 'UTC';
	$GLOBALS['wp_version']                          = '6.8.8';
	unset( $GLOBALS['wpdb'] );
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
 * Run filter callbacks.
 *
 * @param string $hook_name Hook name.
 * @param mixed  $value Initial value.
 * @param mixed  ...$args Additional arguments.
 * @return mixed
 */
function apply_filters( $hook_name, $value, ...$args ) {
	if ( empty( $GLOBALS['pressqueue_test_hooks'][ $hook_name ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['pressqueue_test_hooks'][ $hook_name ] );

	foreach ( $GLOBALS['pressqueue_test_hooks'][ $hook_name ] as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			$callback_args = array_merge( array( $value ), $args );
			$value         = call_user_func_array(
				$registered['callback'],
				array_slice( $callback_args, 0, $registered['accepted_args'] )
			);
		}
	}

	return $value;
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
 * Return an admin URL.
 *
 * @param string $path Path.
 * @return string
 */
function admin_url( $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

/**
 * Return a site URL.
 *
 * @param string $path Path.
 * @return string
 */
function site_url( $path = '' ): string {
	return 'https://example.test/' . ltrim( (string) $path, '/' );
}

/**
 * Return a home URL.
 *
 * @param string $path Path.
 * @return string
 */
function home_url( $path = '' ): string {
	return site_url( $path );
}

/**
 * Return the configured site timezone.
 *
 * @return DateTimeZone
 */
function wp_timezone(): DateTimeZone {
	return new DateTimeZone( (string) $GLOBALS['pressqueue_test_timezone'] );
}

/**
 * Format a timestamp in the configured site timezone.
 *
 * @param string            $format Format.
 * @param int|null          $timestamp Timestamp.
 * @param DateTimeZone|null $timezone Timezone.
 * @return string
 */
function wp_date( $format, $timestamp = null, $timezone = null ): string {
	$timestamp = null === $timestamp ? time() : (int) $timestamp;
	$timezone  = $timezone instanceof DateTimeZone ? $timezone : wp_timezone();

	return ( new DateTimeImmutable( '@' . (string) $timestamp ) )->setTimezone( $timezone )->format( (string) $format );
}

/**
 * Add query args to a URL.
 *
 * @param string|array<string,mixed> $key Key or args.
 * @param mixed                      $value Value or URL.
 * @param string|null                $url URL.
 * @return string
 */
function add_query_arg( $key, $value = null, $url = null ): string {
	if ( is_array( $key ) ) {
		$args = $key;
		$url  = is_string( $value ) ? $value : '';
	} else {
		$args = array( (string) $key => $value );
		$url  = (string) $url;
	}

	$separator = str_contains( $url, '?' ) ? '&' : '?';

	return $url . $separator . http_build_query( $args );
}

/**
 * Record a safe redirect.
 *
 * @param string $url URL.
 */
function wp_safe_redirect( $url ): void {
	$GLOBALS['pressqueue_test_redirects'][] = (string) $url;
}

/**
 * Return a test referer URL.
 *
 * @return string|false
 */
function wp_get_referer() {
	return false;
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
 * Return the test login state.
 *
 * @return bool
 */
function is_user_logged_in(): bool {
	return (bool) $GLOBALS['pressqueue_test_logged_in'];
}

/**
 * Return the current test user ID.
 *
 * @return int
 */
function get_current_user_id(): int {
	return (int) $GLOBALS['pressqueue_test_current_user_id'];
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
 * Escape textarea content.
 *
 * @param string $text Text.
 * @return string
 */
function esc_textarea( $text ): string {
	return esc_html( $text );
}

/**
 * Escaped attribute translation stub.
 *
 * @param string $text Text.
 * @param string $domain Text domain.
 * @return string
 */
function esc_attr__( $text, $domain = 'default' ): string {
	return esc_attr( __( $text, $domain ) );
}

/**
 * Return selected attribute markup.
 *
 * @param mixed $selected Selected value.
 * @param mixed $current Current value.
 * @param bool  $display Whether to echo.
 * @return string
 */
function selected( $selected, $current = true, $display = true ): string {
	$result = $selected === $current ? 'selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

/**
 * Return checked attribute markup.
 *
 * @param mixed $checked Checked value.
 * @param mixed $current Current value.
 * @param bool  $display Whether to echo.
 * @return string
 */
function checked( $checked, $current = true, $display = true ): string {
	$result = $checked === $current ? 'checked="checked"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

/**
 * Emit a nonce field in tests.
 *
 * @param string $action Action.
 * @param string $name Field name.
 */
function wp_nonce_field( $action = -1, $name = '_wpnonce' ): void {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $action ) . '" />';
}

/**
 * Render a test submit button.
 *
 * @param string $text Text.
 * @param string $type Type.
 * @param string $name Name.
 * @param bool   $wrap Whether to wrap.
 * @param array<string,string> $attributes Attributes.
 */
function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $attributes = array() ): void {
	unset( $type, $wrap );

	$attribute_markup = '';
	foreach ( $attributes as $key => $value ) {
		$attribute_markup .= ' ' . esc_attr( (string) $key ) . '="' . esc_attr( (string) $value ) . '"';
	}

	echo '<button type="submit" name="' . esc_attr( (string) $name ) . '"' . $attribute_markup . '>' . esc_html( (string) $text ) . '</button>';
}

/**
 * Build a plugin asset URL.
 *
 * @param string $path Path.
 * @param string $plugin Plugin file.
 * @return string
 */
function plugins_url( $path = '', $plugin = '' ): string {
	unset( $plugin );

	return 'https://example.test/wp-content/plugins/pressqueue/' . ltrim( (string) $path, '/' );
}

/**
 * Record script enqueue calls.
 *
 * @param string            $handle Handle.
 * @param string            $src Source URL.
 * @param array<int,string> $deps Dependencies.
 * @param string|bool|null  $ver Version.
 * @param bool              $in_footer In footer.
 */
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ): void {
	$GLOBALS['pressqueue_test_scripts'][ (string) $handle ] = array(
		'deps'      => $deps,
		'in_footer' => $in_footer,
		'src'       => $src,
		'ver'       => $ver,
	);
}

/**
 * Record script translations.
 *
 * @param string $handle Handle.
 * @param string $domain Domain.
 * @param string $path Language path.
 * @return bool
 */
function wp_set_script_translations( $handle, $domain = 'default', $path = '' ): bool {
	$GLOBALS['pressqueue_test_script_translations'][ (string) $handle ] = array(
		'domain' => $domain,
		'path'   => $path,
	);

	return true;
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
 * Encode JSON like WordPress.
 *
 * @param mixed $value Value.
 * @param int   $flags JSON flags.
 * @param int   $depth JSON depth.
 * @return string|false
 */
function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
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
 * Delete an option cache entry.
 *
 * @param string $key Cache key.
 * @param string $group Cache group.
 * @return bool
 */
function wp_cache_delete( $key, $group = '' ): bool {
	unset( $key, $group );

	return true;
}

/**
 * Serialize values like WordPress options.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function maybe_serialize( $value ) {
	if ( is_array( $value ) || is_object( $value ) ) {
		return serialize( $value );
	}

	return $value;
}

/**
 * Store a test transient.
 *
 * @param string $transient Transient name.
 * @param mixed  $value Transient value.
 * @param int    $expiration Expiration seconds.
 * @return bool
 */
function set_transient( $transient, $value, $expiration = 0 ): bool {
	$GLOBALS['pressqueue_test_transients'][ (string) $transient ] = array(
		'expiration' => (int) $expiration,
		'value'      => $value,
	);

	return true;
}

/**
 * Read a test transient.
 *
 * @param string $transient Transient name.
 * @return mixed
 */
function get_transient( $transient ) {
	return array_key_exists( (string) $transient, $GLOBALS['pressqueue_test_transients'] )
		? $GLOBALS['pressqueue_test_transients'][ (string) $transient ]['value']
		: false;
}

/**
 * Delete a test transient.
 *
 * @param string $transient Transient name.
 * @return bool
 */
function delete_transient( $transient ): bool {
	$transient = (string) $transient;
	$existed   = array_key_exists( $transient, $GLOBALS['pressqueue_test_transients'] );

	unset( $GLOBALS['pressqueue_test_transients'][ $transient ] );

	return $existed;
}

/**
 * Minimal WordPress post object.
 */
class WP_Post {
	public int $ID                   = 0;
	public string $post_type         = 'post';
	public string $post_status       = 'publish';
	public int $post_author          = 1;
	public string $post_title        = '';
	public string $post_excerpt      = '';
	public string $post_content      = '';
	public string $post_date         = '2026-08-24 12:00:00';
	public string $post_date_gmt     = '2026-08-24 12:00:00';
	public string $post_modified     = '2026-08-24 12:00:00';
	public string $post_modified_gmt = '2026-08-24 12:00:00';
	public string $post_mime_type    = '';
	public int $post_parent          = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|object $data Post data.
	 */
	public function __construct( $data = array() ) {
		foreach ( (array) $data as $key => $value ) {
			if ( property_exists( $this, (string) $key ) ) {
				$this->{$key} = $value;
			}
		}
	}
}

/**
 * Minimal WordPress user object.
 */
class WP_User {
	public int $ID = 0;
	public string $user_email = '';
	public string $display_name = '';

	/**
	 * Constructor.
	 *
	 * @param int    $id User ID.
	 * @param string $email Email address.
	 * @param string $display_name Display name.
	 */
	public function __construct( int $id = 1, string $email = 'user@example.test', string $display_name = 'Test User' ) {
		$this->ID           = $id;
		$this->user_email   = $email;
		$this->display_name = $display_name;
	}
}

/**
 * Return a test post.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|null
 */
function get_post( $post_id ): ?WP_Post {
	return $GLOBALS['pressqueue_test_posts'][ (int) $post_id ] ?? null;
}

/**
 * Read test post meta.
 *
 * @param int    $post_id Post ID.
 * @param string $key Meta key.
 * @param bool   $single Whether to return one value.
 * @return mixed
 */
function get_post_meta( $post_id, $key = '', $single = false ) {
	$meta = $GLOBALS['pressqueue_test_post_meta'][ (int) $post_id ] ?? array();

	if ( '' === $key ) {
		return $meta;
	}

	if ( ! array_key_exists( $key, $meta ) ) {
		return $single ? '' : array();
	}

	return $single ? end( $meta[ $key ] ) : $meta[ $key ];
}

/**
 * Update test post meta.
 *
 * @param int    $post_id Post ID.
 * @param string $key Meta key.
 * @param mixed  $value Value.
 * @return bool
 */
function update_post_meta( $post_id, $key, $value ): bool {
	$GLOBALS['pressqueue_test_post_meta'][ (int) $post_id ][ (string) $key ] = array( $value );

	return true;
}

/**
 * Check test metadata existence.
 *
 * @param string $type Object type.
 * @param int    $object_id Object ID.
 * @param string $key Meta key.
 * @return bool
 */
function metadata_exists( $type, $object_id, $key ): bool {
	return 'post' === $type && array_key_exists( (string) $key, $GLOBALS['pressqueue_test_post_meta'][ (int) $object_id ] ?? array() );
}

/**
 * Return a permalink for a test post.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function get_permalink( $post ): string {
	return 'https://example.test/?p=' . (int) $post->ID;
}

/**
 * Return a test featured image ID.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function get_post_thumbnail_id( $post_id ): int {
	return (int) ( $GLOBALS['pressqueue_test_featured_images'][ (int) $post_id ] ?? 0 );
}

/**
 * Return a test attachment URL.
 *
 * @param int $attachment_id Attachment ID.
 * @return string|false
 */
function wp_get_attachment_url( $attachment_id ) {
	return $GLOBALS['pressqueue_test_attachment_urls'][ (int) $attachment_id ] ?? false;
}

/**
 * Return test attachment metadata.
 *
 * @param int $attachment_id Attachment ID.
 * @return array<string,mixed>|false
 */
function wp_get_attachment_metadata( $attachment_id ) {
	return $GLOBALS['pressqueue_test_attachment_metadata'][ (int) $attachment_id ] ?? false;
}

/**
 * Return test attachment MIME type.
 *
 * @param int $attachment_id Attachment ID.
 * @return string|false
 */
function get_post_mime_type( $attachment_id ) {
	$post = get_post( (int) $attachment_id );

	return $post instanceof WP_Post && '' !== $post->post_mime_type ? $post->post_mime_type : false;
}

/**
 * Return test taxonomies.
 *
 * @param string $post_type Post type.
 * @return array<int,string>
 */
function get_object_taxonomies( $post_type ): array {
	unset( $post_type );
	return array();
}

/**
 * Return no test terms.
 *
 * @param int                 $post_id Post ID.
 * @param array<int,string>   $taxonomies Taxonomies.
 * @param array<string,mixed> $args Args.
 * @return array<int,mixed>
 */
function wp_get_object_terms( $post_id, $taxonomies, $args = array() ): array {
	unset( $post_id, $taxonomies, $args );
	return array();
}

/**
 * Return no ancestors.
 *
 * @param int    $object_id Object ID.
 * @param string $object_type Object type.
 * @param string $resource_type Resource type.
 * @return array<int,int>
 */
function get_ancestors( $object_id, $object_type = '', $resource_type = '' ): array {
	unset( $object_id, $object_type, $resource_type );
	return array();
}

/**
 * Return a user object.
 *
 * @param int $user_id User ID.
 * @return WP_User|false
 */
function get_userdata( $user_id ) {
	if ( isset( $GLOBALS['pressqueue_test_users'][ (int) $user_id ] ) ) {
		return $GLOBALS['pressqueue_test_users'][ (int) $user_id ];
	}

	return $user_id > 0 ? new WP_User( (int) $user_id, 'user' . (int) $user_id . '@example.test', 'Test User' ) : false;
}

/**
 * Return a user by field.
 *
 * @param string $field Field.
 * @param mixed  $value Value.
 * @return WP_User|false
 */
function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['pressqueue_test_users'] as $user ) {
		if ( 'id' === $field && (int) $value === (int) $user->ID ) {
			return $user;
		}

		if ( 'email' === $field && (string) $value === (string) $user->user_email ) {
			return $user;
		}
	}

	return false;
}

/**
 * Check a user's capability.
 *
 * @param int|string $user_id User ID.
 * @param string     $capability Capability.
 * @return bool
 */
function user_can( $user_id, $capability ): bool {
	unset( $user_id );

	return current_user_can( $capability );
}

/**
 * Return test site IDs.
 *
 * @param array<string,mixed> $args Args.
 * @return array<int,int>
 */
function get_sites( $args = array() ): array {
	unset( $args );

	return $GLOBALS['pressqueue_test_sites'];
}

/**
 * Switch to a test blog.
 *
 * @param int $site_id Site ID.
 * @return bool
 */
function switch_to_blog( $site_id ): bool {
	$GLOBALS['pressqueue_test_current_blog_id'] = (int) $site_id;

	return true;
}

/**
 * Restore the current test blog.
 *
 * @return bool
 */
function restore_current_blog(): bool {
	$GLOBALS['pressqueue_test_current_blog_id'] = 1;

	return true;
}

/**
 * Return test user meta.
 *
 * @param int    $user_id User ID.
 * @param string $key Meta key.
 * @param bool   $single Whether single.
 * @return mixed
 */
function get_user_meta( $user_id, $key = '', $single = false ) {
	unset( $user_id, $key );
	return $single ? '' : array();
}

/**
 * Return a test post type object.
 *
 * @param string $post_type Post type.
 * @return object|null
 */
function get_post_type_object( $post_type ): ?object {
	if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
		return (object) array(
			'public'  => true,
			'show_ui' => true,
		);
	}

	if ( 'internal' === $post_type ) {
		return (object) array(
			'public'  => false,
			'show_ui' => true,
		);
	}

	return null;
}

/**
 * Check post type existence.
 *
 * @param string $post_type Post type.
 * @return bool
 */
function post_type_exists( $post_type ): bool {
	return null !== get_post_type_object( $post_type );
}

/**
 * Return post statuses.
 *
 * @return array<string,string>
 */
function get_post_stati(): array {
	return array(
		'draft'   => 'draft',
		'future'  => 'future',
		'pending' => 'pending',
		'private' => 'private',
		'publish' => 'publish',
	);
}

/**
 * Autosave check.
 *
 * @param int $post_id Post ID.
 * @return false|int
 */
function wp_is_post_autosave( $post_id ) {
	unset( $post_id );
	return false;
}

/**
 * Revision check.
 *
 * @param int $post_id Post ID.
 * @return false|int
 */
function wp_is_post_revision( $post_id ) {
	unset( $post_id );
	return false;
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
 * Register metadata in tests.
 *
 * @param string              $object_type Object type.
 * @param string              $meta_key Meta key.
 * @param array<string,mixed> $args Args.
 * @return bool
 */
function register_meta( $object_type, $meta_key, $args ): bool {
	$GLOBALS['pressqueue_test_registered_meta'][ $object_type ][ $meta_key ] = $args;

	return true;
}

/**
 * Register a REST route in tests.
 *
 * @param string              $namespace Namespace.
 * @param string              $route Route.
 * @param array<string,mixed> $args Args.
 * @return bool
 */
function register_rest_route( $namespace, $route, $args ): bool {
	$GLOBALS['pressqueue_test_rest_routes'][ $namespace . $route ] = $args;

	return true;
}

/**
 * Register a meta box in tests.
 *
 * @param string          $id ID.
 * @param string          $title Title.
 * @param callable|string $callback Callback.
 * @param string          $screen Screen.
 * @param string          $context Context.
 * @param string          $priority Priority.
 */
function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default' ): void {
	$GLOBALS['pressqueue_test_meta_boxes'][] = array(
		'callback' => $callback,
		'context'  => $context,
		'id'       => $id,
		'priority' => $priority,
		'screen'   => $screen,
		'title'    => $title,
	);
}

/**
 * Minimal REST request object.
 */
class WP_REST_Request implements ArrayAccess {
	/**
	 * Route params.
	 *
	 * @var array<string,mixed>
	 */
	private array $params;

	/**
	 * JSON params.
	 *
	 * @var mixed
	 */
	private $json_params;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $params Params.
	 * @param mixed               $json_params JSON params.
	 */
	public function __construct( array $params = array(), $json_params = array() ) {
		$this->params      = $params;
		$this->json_params = $json_params;
	}

	/**
	 * Return JSON params.
	 *
	 * @return mixed
	 */
	public function get_json_params() {
		return $this->json_params;
	}

	public function offsetExists( $offset ): bool {
		return array_key_exists( (string) $offset, $this->params );
	}

	public function offsetGet( $offset ) {
		return $this->params[ (string) $offset ] ?? null;
	}

	public function offsetSet( $offset, $value ): void {
		$this->params[ (string) $offset ] = $value;
	}

	public function offsetUnset( $offset ): void {
		unset( $this->params[ (string) $offset ] );
	}
}

/**
 * Minimal REST response object.
 */
class WP_REST_Response {
	private $data;
	private int $status;

	/**
	 * Constructor.
	 *
	 * @param mixed $data Data.
	 * @param int   $status Status.
	 */
	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	/**
	 * Return response data.
	 *
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Return status.
	 *
	 * @return int
	 */
	public function get_status(): int {
		return $this->status;
	}
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
	 * Remove a capability.
	 *
	 * @param string $capability Capability.
	 */
	public function remove_cap( $capability ): void {
		unset( $this->capabilities[ $capability ] );
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
 * Generic HTTP request stub that makes accidental external access visible.
 *
 * @param string              $url URL.
 * @param array<string,mixed> $args Request arguments.
 * @return mixed
 */
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['pressqueue_test_http_requests'][] = array(
		'args'   => $args,
		'method' => $args['method'] ?? 'GET',
		'url'    => $url,
	);

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
	 * Error data.
	 *
	 * @var mixed
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param mixed  $data Error data.
	 */
	public function __construct( string $code, string $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
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

	/**
	 * Return error data.
	 *
	 * @return mixed
	 */
	public function get_error_data() {
		return $this->data;
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
 * Check current action state.
 *
 * @param string|null $hook_name Hook name.
 * @return bool
 */
function doing_action( $hook_name = null ): bool {
	unset( $hook_name );
	return false;
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

	$id                                      = $GLOBALS['pressqueue_test_as_next_action_id']++;
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

	$id                                      = $GLOBALS['pressqueue_test_as_next_action_id']++;
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

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP-CLI class stub.
	 */
	class WP_CLI {
		/**
		 * Record a command registration.
		 *
		 * @param string $name Command name.
		 * @param mixed  $callable Command callback.
		 */
		public static function add_command( $name, $callable ): void {
			$GLOBALS['pressqueue_test_wp_cli_commands'][ (string) $name ] = $callable;
		}

		/**
		 * Record a stdout line.
		 *
		 * @param string $line Line.
		 */
		public static function line( $line ): void {
			$GLOBALS['pressqueue_test_wp_cli_lines'][] = (string) $line;
		}

		/**
		 * Record a halt status.
		 *
		 * @param int $status Status.
		 */
		public static function halt( $status ): void {
			$GLOBALS['pressqueue_test_wp_cli_halt_status'] = (int) $status;
		}
	}
}
