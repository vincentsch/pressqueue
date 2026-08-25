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

	public function test_admin_and_editor_assets_use_wordpress_i18n(): void {
		$condition_builder = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/condition-builder.js' );
		$post_controls     = file_get_contents( dirname( __DIR__, 2 ) . '/assets/editor/post-controls.js' );

		self::assertIsString( $condition_builder );
		self::assertIsString( $post_controls );
		self::assertStringContainsString( 'wp.i18n.__', $condition_builder );
		self::assertStringContainsString( 'wp.i18n', $post_controls );
		self::assertStringNotContainsString( 'role\', \'tree', $condition_builder );
		self::assertStringNotContainsString( 'role\', \'treeitem', $condition_builder );
	}

	public function test_condition_builder_has_labels_and_nested_children(): void {
		$asset = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/condition-builder.js' );

		self::assertIsString( $asset );
		self::assertStringContainsString( 'function nextControlId', $asset );
		self::assertStringContainsString( 'function controlLabel', $asset );
		self::assertStringContainsString( 'pressqueue-condition-builder__children', $asset );
		self::assertStringContainsString( 'label.setAttribute( \'for\', id )', $asset );
		self::assertStringNotContainsString( 'row.style.marginLeft', $asset );
	}
}
