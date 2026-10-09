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

	public function test_post_controls_save_with_the_post_instead_of_a_separate_button(): void {
		$asset = file_get_contents( dirname( __DIR__, 2 ) . '/assets/editor/post-controls.js' );

		self::assertIsString( $asset );
		self::assertStringContainsString( "'editor.preSavePost'", $asset );
		self::assertStringContainsString( 'options.isAutosave', $asset );
		self::assertStringContainsString( 'applyFiltersAsync', $asset );
		self::assertStringContainsString( 'base_control_bundle_hash', $asset );
		self::assertStringContainsString( 'undoIgnore: true', $asset );
		self::assertStringContainsString( "'Social sharing'", $asset );
		self::assertStringContainsString( "'Share this post'", $asset );
		self::assertStringNotContainsString( 'Save Controls', $asset );
		self::assertStringNotContainsString( 'Attachment IDs', $asset );
		self::assertStringNotContainsString( 'UTC offset', $asset );
	}

	public function test_post_controls_enqueue_editor_hooks_styles_and_classic_behavior(): void {
		$controller = file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/PostControlsController.php' );

		self::assertIsString( $controller );
		self::assertStringContainsString( "'wp-hooks'", $controller );
		self::assertStringContainsString( "'wp-block-editor'", $controller );
		self::assertStringContainsString( 'assets/editor/post-controls.css', $controller );
		self::assertStringContainsString( 'assets/editor/post-controls-classic.js', $controller );
		self::assertStringContainsString( "'pre_post_update'", $controller );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/assets/editor/post-controls.css' );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/assets/editor/post-controls-classic.js' );
	}

	public function test_post_control_styles_stay_scoped_to_the_editor_panel(): void {
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/editor/post-controls.css' );

		self::assertIsString( $css );
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		preg_match_all( '/(^|})\s*([^{}@]+)\{/', $css, $matches );
		self::assertNotEmpty( $matches[2] );
		foreach ( $matches[2] as $selector_list ) {
			foreach ( explode( ',', $selector_list ) as $selector ) {
				self::assertStringStartsWith( '.pq-editor', trim( $selector ) );
			}
		}
	}

	public function test_post_control_assets_avoid_em_dashes(): void {
		foreach ( array( 'assets/editor/post-controls.js', 'assets/editor/post-controls-classic.js', 'assets/editor/post-controls.css', 'src/Admin/PostControlsController.php', 'src/Admin/PostSharingSummary.php', 'src/Editorial/NewPostDefaults.php' ) as $file ) {
			$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
			self::assertIsString( $contents );
			self::assertStringNotContainsString( "\u{2014}", $contents, $file );
		}
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
