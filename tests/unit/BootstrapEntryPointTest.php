<?php
/**
 * Plugin entrypoint tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function did_action;
use function do_action;
use function pressqueue_test_reset_wordpress_stubs;

/**
 * @coversNothing
 */
final class BootstrapEntryPointTest extends TestCase {
	public function test_main_file_bootstraps_without_http_requests(): void {
		pressqueue_test_reset_wordpress_stubs();

		require_once dirname( __DIR__, 2 ) . '/pressqueue.php';

		self::assertSame( 1, did_action( 'pressqueue_bootstrap_loaded' ) );
		self::assertSame( array(), $GLOBALS['pressqueue_test_http_requests'] );
	}

	/**
	 * @depends test_main_file_bootstraps_without_http_requests
	 */
	public function test_activation_hook_runs_single_site_activation(): void {
		do_action( 'activate_' . \plugin_basename( dirname( __DIR__, 2 ) . '/pressqueue.php' ), false );

		self::assertSame( 1, did_action( 'pressqueue_after_activation' ) );
		self::assertSame( array(), $GLOBALS['pressqueue_test_deactivated'] );
	}
}
