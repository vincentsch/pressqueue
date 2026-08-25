<?php
/**
 * Development tooling contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Contract tests inspect local files.

/**
 * Test development tooling contracts.
 *
 * @coversNothing
 */
final class ToolingContractsTest extends TestCase {
	/**
	 * The matrix runner declares required cells and durable result paths.
	 */
	public function test_local_matrix_declares_required_cells_and_durable_results(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/local-matrix.php' );

		self::assertIsString( $tool );
		self::assertStringContainsString( '--network-alias', $tool );
		self::assertStringContainsString( "'db'", $tool );
		self::assertStringContainsString( 'composer run package:smoke', $tool );
		self::assertStringContainsString( 'composer run plugin-check', $tool );
		self::assertStringContainsString( 'tools/runtime-guard-smoke.php', $tool );
		self::assertStringContainsString( 'pressqueue_matrix_record_result', $tool );
		self::assertStringContainsString( "'/matrix/'", $tool );
		self::assertStringContainsString( "'-php'", $tool );
	}

	/**
	 * The package smoke runner covers package activation and lifecycle checks.
	 */
	public function test_package_smoke_covers_activation_scripts_uninstall_and_multisite(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/package-smoke.php' );

		self::assertIsString( $tool );
		self::assertStringContainsString( 'assert_scripts_enqueue', $tool );
		self::assertStringContainsString( 'pressqueue-condition-builder', $tool );
		self::assertStringContainsString( 'pressqueue-post-controls', $tool );
		self::assertStringContainsString( 'assert_preserving_uninstall', $tool );
		self::assertStringContainsString( 'assert_deleting_uninstall', $tool );
		self::assertStringContainsString( 'PACKAGE_SMOKE_MULTISITE_PATH', $tool );
		self::assertStringContainsString( 'write_package_smoke_evidence', $tool );
		self::assertStringContainsString( 'PRESSQUEUE_PACKAGE_EVIDENCE_DIR', $tool );
		self::assertStringContainsString( "'/manifest.json'", $tool );
		self::assertStringContainsString( "'/checksum.sha256'", $tool );
		self::assertStringContainsString( "'/smoke.json'", $tool );
	}

	/**
	 * The Plugin Check runner pins the checked tool version.
	 */
	public function test_plugin_check_runner_pins_plugin_check_version(): void {
		$tool = file_get_contents( dirname( __DIR__, 2 ) . '/tools/run-plugin-check.php' );

		self::assertIsString( $tool );
		self::assertStringContainsString( "PRESSQUEUE_PLUGIN_CHECK_VERSION = '2.1.0'", $tool );
		self::assertStringContainsString( "'--version=' . PRESSQUEUE_PLUGIN_CHECK_VERSION", $tool );
	}
}
