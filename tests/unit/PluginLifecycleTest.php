<?php
/**
 * Plugin lifecycle tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Privacy\PrivacySettings;
use PressQueue\Privacy\RetentionStateStore;
use PressQueue\Plugin;
use PressQueue\Runtime\RuntimeDisabledLatch;

use function did_action;
use function do_action;
use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Plugin
 */
final class PluginLifecycleTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
	}

	public function test_bootstrap_fires_extension_point(): void {
		Plugin::bootstrap();

		self::assertSame( 1, did_action( 'pressqueue_bootstrap_loaded' ) );
	}

	public function test_activation_exposes_lifecycle_hooks_without_granting_capabilities(): void {
		Plugin::activate( false );

		$hooks = array_column( $GLOBALS['pressqueue_test_actions'], 'hook' );

		self::assertSame(
			array(
				'pressqueue_before_activation',
				'pressqueue_schema_upgrade',
				'pressqueue_capability_registration',
				'pressqueue_after_activation',
			),
			$hooks
		);
		self::assertFalse( function_exists( 'add_cap' ) );
		self::assertTrue( RuntimeDisabledLatch::is_open() );
		self::assertSame( PrivacySettings::defaults(), get_option( PrivacySettings::OPTION ) );
		self::assertSame( RetentionStateStore::defaults(), get_option( RetentionStateStore::OPTION ) );
	}

	public function test_deactivation_exposes_lifecycle_hooks(): void {
		Plugin::deactivate( false );

		$hooks = array_column( $GLOBALS['pressqueue_test_actions'], 'hook' );

		self::assertSame(
			array(
				'pressqueue_before_deactivation',
				'pressqueue_after_deactivation',
			),
			$hooks
		);
		self::assertTrue( RuntimeDisabledLatch::is_closed() );
	}
}
