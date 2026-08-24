<?php
/**
 * Editor asset contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class EditorAssetContractsTest extends TestCase {
	public function test_condition_builder_does_not_dispatch_change_during_internal_sync(): void {
		$asset = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/condition-builder.js' );

		self::assertIsString( $asset );
		self::assertStringContainsString( 'function writeTextarea', $asset );
		self::assertStringNotContainsString( 'dispatchEvent( new Event( \'change\'', $asset );
	}

	public function test_condition_builder_preserves_invalid_raw_json_with_error_state(): void {
		$asset = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/condition-builder.js' );

		self::assertIsString( $asset );
		self::assertStringContainsString( 'rawInvalid', $asset );
		self::assertStringContainsString( 'renderError', $asset );
		self::assertStringContainsString( 'Condition JSON is invalid', $asset );
	}
}
