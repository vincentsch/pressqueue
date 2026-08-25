<?php
/**
 * Development tooling contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ToolingContractsTest extends TestCase {
	public function test_local_matrix_declares_required_cells_and_durable_results(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/local-matrix.php' );

		self::assertIsString( $tool );
		self::assertStringContainsString( '--network-alias', $tool );
		self::assertStringContainsString( "'db'", $tool );
		self::assertStringContainsString( 'composer run package:smoke', $tool );
		self::assertStringContainsString( 'composer run plugin-check', $tool );
		self::assertStringContainsString( 'tools/runtime-guard-smoke.php', $tool );
		self::assertStringContainsString( 'pressqueue_matrix_record_result', $tool );
	}

	public function test_package_smoke_covers_activation_scripts_uninstall_and_multisite(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/package-smoke.php' );

		self::assertIsString( $tool );
		self::assertStringContainsString( 'assert_scripts_enqueue', $tool );
		self::assertStringContainsString( 'pressqueue-condition-builder', $tool );
		self::assertStringContainsString( 'pressqueue-post-controls', $tool );
		self::assertStringContainsString( 'assert_preserving_uninstall', $tool );
		self::assertStringContainsString( 'assert_deleting_uninstall', $tool );
		self::assertStringContainsString( 'PACKAGE_SMOKE_MULTISITE_PATH', $tool );
	}

	public function test_plugin_check_runner_pins_plugin_check_version(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/run-plugin-check.php' );

		self::assertIsString( $tool );
		self::assertStringContainsString( "PRESSQUEUE_PLUGIN_CHECK_VERSION = '2.1.0'", $tool );
		self::assertStringContainsString( "'--version=' . PRESSQUEUE_PLUGIN_CHECK_VERSION", $tool );
	}
}
