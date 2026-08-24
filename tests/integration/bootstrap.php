<?php
/**
 * WordPress integration test bootstrap.
 *
 * @package PressQueue\Tests
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $_tests_dir || '' === $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_functions = rtrim( $_tests_dir, '/\\' ) . '/includes/functions.php';

if ( ! file_exists( $_functions ) ) {
	fwrite(
		STDERR,
		"WordPress test suite not found. Set WP_TESTS_DIR or run tools/install-wp-tests.sh.\n"
	);
	exit( 1 );
}

require_once $_functions;

tests_add_filter(
	'pre_http_request',
	static function (): void {
		throw new RuntimeException( 'PressQueue bootstrap attempted an external HTTP request.' );
	},
	10,
	3
);

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/pressqueue.php';
	}
);

require rtrim( $_tests_dir, '/\\' ) . '/includes/bootstrap.php';
