<?php
/**
 * Run WordPress Plugin Check with warnings treated as failures.
 *
 * @package PressQueue\Tools
 */

declare(strict_types=1);

require_once __DIR__ . '/plugin-check-results.php';

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This check must run from the command line.\n" );
	exit( 1 );
}

$root              = dirname( __DIR__ );
$wp_path           = getenv( 'WP_PATH' );
$plugin_check_path = getenv( 'PLUGIN_CHECK_PATH' );
$wp_cli_cache      = wp_cli_cache_dir();

if ( false === $wp_path || '' === $wp_path ) {
	fwrite( STDERR, "WP_PATH must point to a disposable WordPress install for Plugin Check.\n" );
	exit( 1 );
}

if ( false === $plugin_check_path || '' === $plugin_check_path ) {
	$plugin_check_path = rtrim( $wp_path, '/\\' ) . '/wp-content/plugins/plugin-check/cli.php';
}

if ( ! file_exists( $plugin_check_path ) ) {
	fwrite( STDERR, "Plugin Check CLI file not found at " . $plugin_check_path . ".\n" );
	exit( 1 );
}

$command = array_merge(
	resolve_wp_cli_command( $wp_cli_cache ),
	array(
		'plugin',
		'activate',
		'plugin-check',
		'--path=' . $wp_path,
	)
);

run_wp_cli_command( $command, $root );

$target = getenv( 'PLUGIN_CHECK_TARGET' );

if ( false === $target || '' === $target ) {
	$target = 'pressqueue';
}

if ( 'pressqueue' !== $target && ! file_exists( $target ) ) {
	fwrite( STDERR, "Plugin Check target not found at " . $target . ". Run composer run package:smoke first.\n" );
	exit( 1 );
}

$command = array_merge(
	resolve_wp_cli_command( $wp_cli_cache ),
	array(
		'plugin',
		'check',
		$target,
		'--path=' . $wp_path,
		'--require=' . $plugin_check_path,
		'--format=strict-json',
		'--fields=code,type,severity,message,file,line',
	)
);

$result = run_wp_cli_command( $command, $root, true );
$stdout = $result['stdout'];
$stderr = $result['stderr'];
$status = $result['status'];

$results = json_decode( $stdout, true );

if ( ! is_array( $results ) ) {
	if ( 0 === $status && pressqueue_plugin_check_output_is_success( $stdout . $stderr ) ) {
		fwrite( STDOUT, "Plugin Check passed with zero errors and zero warnings.\n" );
		exit( 0 );
	}

	fwrite( STDERR, "Plugin Check did not return valid JSON.\n" . $stdout . $stderr );
	exit( 1 );
}

$failures = pressqueue_collect_plugin_check_failures( $results );

if ( array() !== $failures ) {
	fwrite( STDERR, wp_json_encode_for_tool( $failures ) . "\n" );
	exit( 1 );
}

if ( 0 !== $status ) {
	fwrite( STDERR, $stdout . $stderr );
	exit( $status );
}

fwrite( STDOUT, "Plugin Check passed with zero errors and zero warnings.\n" );

/**
 * Encode JSON for CLI output.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_for_tool( $value ): string {
	$encoded = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( false === $encoded ) {
		return '[]';
	}

	return $encoded;
}

/**
 * Return the WP-CLI download cache directory.
 *
 * @return string
 */
function wp_cli_cache_dir(): string {
	$override = getenv( 'PRESSQUEUE_WP_CLI_CACHE_DIR' );

	if ( false !== $override && '' !== $override ) {
		return rtrim( $override, '/\\' );
	}

	return rtrim( sys_get_temp_dir(), '/\\' ) . '/pressqueue-wp-cli';
}

/**
 * Run a WP-CLI command and capture output.
 *
 * @param array<int,string> $command Command.
 * @param string            $cwd Working directory.
 * @param bool              $allow_failure Whether non-zero status should be returned.
 * @return array{stdout:string,stderr:string,status:int}
 */
function run_wp_cli_command( array $command, string $cwd, bool $allow_failure = false ): array {
	$process = proc_open(
		implode( ' ', array_map( 'escapeshellarg', $command ) ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd
	);

	if ( ! is_resource( $process ) ) {
		fwrite( STDERR, "Unable to start WP-CLI.\n" );
		exit( 1 );
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );

	fclose( $pipes[1] );
	fclose( $pipes[2] );

	$status = proc_close( $process );

	if ( 0 !== $status && ! $allow_failure ) {
		fwrite( STDERR, $stdout . $stderr );
		exit( $status );
	}

	return array(
		'stdout' => $stdout,
		'stderr' => $stderr,
		'status' => $status,
	);
}

/**
 * Resolve a standalone WP-CLI command.
 *
 * @param string $scratch Scratch directory.
 * @return array<int,string>
 */
function resolve_wp_cli_command( string $scratch ): array {
	$wp_binary = getenv( 'WP_CLI_BIN' );

	if ( false !== $wp_binary && '' !== $wp_binary ) {
		if ( ! file_exists( $wp_binary ) ) {
			throw new RuntimeException( 'WP-CLI binary not found at ' . $wp_binary );
		}

		return array( $wp_binary );
	}

	if ( ! is_dir( $scratch ) && ! mkdir( $scratch, 0775, true ) && ! is_dir( $scratch ) ) {
		throw new RuntimeException( 'Unable to create scratch directory: ' . $scratch );
	}

	$phar = $scratch . '/wp-cli-2.12.0.phar';

	if ( ! file_exists( $phar ) ) {
		$url = 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar';

		if ( false === copy( $url, $phar ) ) {
			throw new RuntimeException( 'Unable to download WP-CLI Phar.' );
		}
	}

	return array( PHP_BINARY, $phar );
}
