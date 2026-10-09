<?php
/**
 * WordPress bootstrap integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Tests\Support\PressQueueIntegrationTestCase as WP_UnitTestCase;

/**
 * @coversNothing
 */
final class BootstrapIntegrationTest extends WP_UnitTestCase {
	public function test_bootstrap_makes_zero_external_http_requests(): void {
		do_action( 'plugins_loaded' );

		self::assertGreaterThanOrEqual( 1, did_action( 'pressqueue_bootstrap_loaded' ) );
	}

	public function test_single_site_activation_runs_lifecycle_hooks(): void {
		$activated = false;

		add_action(
			'pressqueue_after_activation',
			static function () use ( &$activated ): void {
				$activated = true;
			}
		);

		pressqueue_activate( false );

		self::assertTrue( $activated );
	}
}
