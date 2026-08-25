<?php
/**
 * Post control authorization tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Admin\PostControlsController;
use PressQueue\Admin\PostPreviewController;
use PressQueue\Editorial\PostControlAuthorization;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Security\Capabilities;
use WP_Post;
use WP_REST_Request;

use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Admin\PostControlsController
 * @covers \PressQueue\Admin\PostPreviewController
 * @covers \PressQueue\Editorial\PostControlAuthorization
 * @covers \PressQueue\Security\Capabilities
 */
final class PostControlAuthorizationTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
		$GLOBALS['pressqueue_test_posts'][42] = new WP_Post( array( 'ID' => 42 ) );
	}

	public function test_capability_schema_version_grants_administrators_only(): void {
		Capabilities::grant_to_administrators( false );

		self::assertTrue( $GLOBALS['pressqueue_test_roles']['administrator']->has_cap( Capabilities::EDIT_POST_CONTROLS ) );
		self::assertTrue( $GLOBALS['pressqueue_test_roles']['administrator']->has_cap( Capabilities::OPERATE_BATCHES ) );
		self::assertFalse( $GLOBALS['pressqueue_test_roles']['editor']->has_cap( Capabilities::EDIT_POST_CONTROLS ) );
		self::assertFalse( $GLOBALS['pressqueue_test_roles']['editor']->has_cap( Capabilities::OPERATE_BATCHES ) );
		self::assertSame( 4, $GLOBALS['pressqueue_test_options'][ Capabilities::VERSION_OPTION ] );
	}

	public function test_raw_control_meta_is_registered_outside_core_rest(): void {
		PostControlsController::register_post_meta();

		$args = $GLOBALS['pressqueue_test_registered_meta']['post']['_pressqueue_controls'];

		self::assertFalse( $args['show_in_rest'] );
		self::assertSame( 'string', $args['type'] );
	}

	public function test_direct_control_metadata_writes_require_control_authorization(): void {
		self::assertFalse( PostControlsController::guard_control_meta_write( null, 42, PostControlMetadata::META_KEY ) );
		self::assertNull( PostControlsController::guard_control_meta_write( null, 42, '_unrelated_meta' ) );

		$GLOBALS['pressqueue_test_current_user_caps']['edit_post'] = true;
		self::assertFalse( PostControlsController::guard_control_meta_write( null, 42, PostControlMetadata::META_KEY ) );

		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::EDIT_POST_CONTROLS ] = true;
		self::assertNull( PostControlsController::guard_control_meta_write( null, 42, PostControlMetadata::META_KEY ) );
	}

	public function test_malformed_put_body_returns_structured_bad_request(): void {
		$GLOBALS['pressqueue_test_current_user_caps']['edit_post']                        = true;
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::EDIT_POST_CONTROLS ] = true;

		$response = PostControlsController::handle_put_controls( new WP_REST_Request( array( 'post_id' => 42 ), array( 'not-an-object' ) ) );
		$data     = $response->get_data();

		self::assertSame( 400, $response->get_status() );
		self::assertIsArray( $data );
		self::assertSame( 'invalid_controls', $data['code'] );
		self::assertSame( 'REST request body must be a JSON object.', $data['message'] );
	}

	public function test_malformed_preview_body_returns_structured_bad_request(): void {
		$GLOBALS['pressqueue_test_current_user_caps']['edit_post']                        = true;
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::EDIT_POST_CONTROLS ] = true;

		$response = PostPreviewController::handle_preview( new WP_REST_Request( array( 'post_id' => 42 ), array( 'not-an-object' ) ) );
		$data     = $response->get_data();

		self::assertSame( 400, $response->get_status() );
		self::assertIsArray( $data );
		self::assertSame( 'invalid_preview', $data['code'] );
		self::assertSame( 'REST request body must be a JSON object.', $data['message'] );
	}

	public function test_route_authorization_requires_pressqueue_capability_and_post_edit_access(): void {
		$request = new WP_REST_Request( array( 'post_id' => 42 ) );

		$GLOBALS['pressqueue_test_current_user_caps']['edit_post'] = true;
		$without_cap = PostControlsController::rest_permission_callback( $request );
		self::assertInstanceOf( \WP_Error::class, $without_cap );
		self::assertSame( 'forbidden', $without_cap->get_error_code() );

		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::EDIT_POST_CONTROLS ] = true;
		$allowed = PostControlsController::rest_permission_callback( $request );
		self::assertTrue( $allowed );
	}

	public function test_public_or_internal_post_type_does_not_bypass_control_authorization(): void {
		$GLOBALS['pressqueue_test_posts'][99]                      = new WP_Post(
			array(
				'ID'        => 99,
				'post_type' => 'internal',
			)
		);
		$GLOBALS['pressqueue_test_current_user_caps']['edit_post'] = true;
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::EDIT_POST_CONTROLS ] = true;

		self::assertFalse( PostControlAuthorization::is_supported_post( 99 ) );

		$request = new WP_REST_Request( array( 'post_id' => 99 ) );
		$error   = PostControlsController::rest_permission_callback( $request );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'invalid_post_type', $error->get_error_code() );
	}

	public function test_missing_post_returns_not_found_before_control_facts_are_loaded(): void {
		$GLOBALS['pressqueue_test_current_user_caps']['edit_post']                        = true;
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::EDIT_POST_CONTROLS ] = true;

		$error = PostControlsController::rest_permission_callback( new WP_REST_Request( array( 'post_id' => 404 ) ) );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'not_found', $error->get_error_code() );
	}
}
