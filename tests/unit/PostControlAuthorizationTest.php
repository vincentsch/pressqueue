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
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;
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

		$saves = new \ReflectionProperty( PostControlsController::class, 'classic_saves' );
		$saves->setAccessible( true );
		$saves->setValue( null, array() );
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tearDown();
	}

	public function test_capability_schema_version_grants_administrators_only(): void {
		Capabilities::grant_to_administrators( false );

		self::assertTrue( $GLOBALS['pressqueue_test_roles']['administrator']->has_cap( Capabilities::EDIT_POST_CONTROLS ) );
		self::assertTrue( $GLOBALS['pressqueue_test_roles']['administrator']->has_cap( Capabilities::OPERATE_BATCHES ) );
		self::assertTrue( $GLOBALS['pressqueue_test_roles']['administrator']->has_cap( Capabilities::EXPORT_DATA ) );
		self::assertFalse( $GLOBALS['pressqueue_test_roles']['editor']->has_cap( Capabilities::EDIT_POST_CONTROLS ) );
		self::assertFalse( $GLOBALS['pressqueue_test_roles']['editor']->has_cap( Capabilities::OPERATE_BATCHES ) );
		self::assertFalse( $GLOBALS['pressqueue_test_roles']['editor']->has_cap( Capabilities::EXPORT_DATA ) );
		self::assertSame( 5, $GLOBALS['pressqueue_test_options'][ Capabilities::VERSION_OPTION ] );
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

	public function test_activity_route_is_read_only_and_uses_the_controls_permission_model(): void {
		PostControlsController::register_routes();

		$routes   = $GLOBALS['pressqueue_test_rest_routes'];
		$activity = $routes['pressqueue/v1/posts/(?P<post_id>\d+)/activity'];
		$controls = $routes['pressqueue/v1/posts/(?P<post_id>\d+)/controls'];

		self::assertSame( 'GET', $activity['methods'] );
		self::assertSame( array( PostControlsController::class, 'rest_permission_callback' ), $activity['permission_callback'] );
		self::assertSame( $controls[0]['permission_callback'], $activity['permission_callback'] );
	}

	public function test_activity_handler_refuses_users_without_control_access(): void {
		$GLOBALS['pressqueue_test_current_user_caps']['edit_post'] = true;

		$response = PostControlsController::handle_get_activity( new WP_REST_Request( array( 'post_id' => 42 ) ) );

		self::assertSame( 403, $response->get_status() );
	}

	public function test_classic_box_is_titled_social_sharing_and_hides_controls_without_access(): void {
		PostControlsController::register_meta_boxes( 'post', $GLOBALS['pressqueue_test_posts'][42] );

		self::assertSame( 'pressqueue-post-controls', $GLOBALS['pressqueue_test_meta_boxes'][0]['id'] );
		self::assertSame( 'Social sharing', $GLOBALS['pressqueue_test_meta_boxes'][0]['title'] );

		$GLOBALS['pressqueue_test_current_user_caps']['edit_post'] = true;
		ob_start();
		PostControlsController::render_meta_box( $GLOBALS['pressqueue_test_posts'][42] );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'You do not have permission to change social sharing for this post.', $html );
		self::assertStringNotContainsString( 'pressqueue_share', $html );
		self::assertStringNotContainsString( 'pressqueue_controls_nonce', $html );
	}

	public function test_share_form_maps_the_switch_to_a_policy(): void {
		$inherit = PostControlMetadata::default_bundle();
		$include = array_merge( $inherit, array( 'policy' => 'include' ) );

		self::assertSame( 'inherit', PostControlsController::bundle_from_share_form( array( 'pressqueue_share' => '1' ), $inherit )['policy'] );
		self::assertSame( 'exclude', PostControlsController::bundle_from_share_form( array(), $inherit )['policy'] );
		self::assertSame( 'include', PostControlsController::bundle_from_share_form( array( 'pressqueue_share' => '1' ), $include )['policy'] );
		self::assertSame( 'exclude', PostControlsController::bundle_from_share_form( array(), $include )['policy'] );
	}

	public function test_share_form_only_restricts_channels_when_one_is_left_out(): void {
		$current = PostControlMetadata::default_bundle();
		$listed  = array( '01M4GEP5AENBCT7C65XXZATDTQ', '01M4GEP5AG08N89H8610KY62BP' );

		$all = PostControlsController::bundle_from_share_form(
			array(
				'pressqueue_share'              => '1',
				'pressqueue_channel_listed'     => $listed,
				'pressqueue_channel_public_ids' => $listed,
			),
			$current
		);
		self::assertSame( array( 'mode' => 'inherit' ), $all['channels'] );

		$one = PostControlsController::bundle_from_share_form(
			array(
				'pressqueue_share'              => '1',
				'pressqueue_channel_listed'     => $listed,
				'pressqueue_channel_public_ids' => array( $listed[1], 'not-listed' ),
			),
			$current
		);
		self::assertSame(
			array(
				'channel_public_ids' => array( $listed[1] ),
				'mode'               => 'override',
			),
			$one['channels']
		);
	}

	public function test_share_form_keeps_a_stored_restriction_until_it_is_reset(): void {
		$channel_a = '01M4GEP5AENBCT7C65XXZATDTQ';
		$channel_b = '01M4GEP5AG08N89H8610KY62BP';
		$current   = PostControlMetadata::normalize_bundle(
			array_merge(
				PostControlMetadata::default_bundle(),
				array(
					'channels' => array(
						'mode'               => 'override',
						'channel_public_ids' => array( $channel_a ),
					),
				)
			)
		);

		// Channel B is switched off, so the form only lists A, ticked. Saving must not widen the restriction.
		$unchanged = PostControlsController::bundle_from_share_form(
			array(
				'pressqueue_share'              => '1',
				'pressqueue_channel_listed'     => array( $channel_a ),
				'pressqueue_channel_public_ids' => array( $channel_a ),
			),
			$current
		);
		self::assertSame( 'override', $unchanged['channels']['mode'] );
		self::assertSame( array( $channel_a ), $unchanged['channels']['channel_public_ids'] );

		// A stored channel the form did not list survives a save that changes the listed ones.
		$stored_both = PostControlMetadata::normalize_bundle(
			array_merge(
				PostControlMetadata::default_bundle(),
				array(
					'channels' => array(
						'mode'               => 'override',
						'channel_public_ids' => array( $channel_a, $channel_b ),
					),
				)
			)
		);
		$partly      = PostControlsController::bundle_from_share_form(
			array(
				'pressqueue_share'              => '1',
				'pressqueue_channel_listed'     => array( $channel_a ),
				'pressqueue_channel_public_ids' => array(),
			),
			$stored_both
		);
		self::assertSame( array( $channel_b ), $partly['channels']['channel_public_ids'] );

		$reset = PostControlsController::bundle_from_share_form(
			array(
				'pressqueue_share'              => '1',
				'pressqueue_channel_listed'     => array( $channel_a ),
				'pressqueue_channel_public_ids' => array( $channel_a ),
				'pressqueue_channel_reset'      => '1',
			),
			$current
		);
		self::assertSame( array( 'mode' => 'inherit' ), $reset['channels'] );
	}

	public function test_share_form_maps_message_timing_and_images(): void {
		$current = PostControlMetadata::default_bundle();
		$bundle  = PostControlsController::bundle_from_share_form(
			array(
				'pressqueue_share'                => '1',
				'pressqueue_message_value'        => 'Fresh rye today',
				'pressqueue_schedule_mode'        => 'explicit_time',
				'pressqueue_schedule_local_date'  => '2026-10-20',
				'pressqueue_schedule_local_time'  => '09:30',
				'pressqueue_schedule_timezone'    => 'Europe/Berlin',
				'pressqueue_media_mode'           => 'post_media',
				'pressqueue_media_attachment_ids' => '5,6',
			),
			$current
		);

		self::assertSame(
			array(
				'mode'  => 'override',
				'value' => 'Fresh rye today',
			),
			$bundle['message']
		);
		self::assertSame( 'explicit_time', $bundle['schedule']['mode'] );
		self::assertSame( 'Europe/Berlin', $bundle['schedule']['timezone'] );
		self::assertSame( array( 5, 6 ), $bundle['media']['post_media']['attachment_ids'] );

		$blank = PostControlsController::bundle_from_share_form(
			array(
				'pressqueue_share'         => '1',
				'pressqueue_message_value' => "  \n ",
			),
			$current
		);
		self::assertSame( array( 'mode' => 'inherit' ), $blank['message'] );
	}

	public function test_share_form_rejects_incomplete_timing_and_empty_image_choices(): void {
		$current = PostControlMetadata::default_bundle();

		foreach (
			array(
				array(
					'pressqueue_schedule_mode'       => 'explicit_time',
					'pressqueue_schedule_local_time' => '09:30',
					'pressqueue_schedule_timezone'   => 'Europe/Berlin',
				),
				array(
					'pressqueue_media_mode'           => 'post_media',
					'pressqueue_media_attachment_ids' => '',
				),
			) as $input
		) {
			try {
				PostControlsController::bundle_from_share_form( array_merge( array( 'pressqueue_share' => '1' ), $input ), $current );
				self::fail( 'Incomplete input must be rejected.' );
			} catch ( ValidationException $exception ) {
				self::assertNotSame( '', PostControlsController::control_error_message( $exception ) );
			}
		}
	}

	public function test_control_errors_explain_how_to_fix_them(): void {
		self::assertStringContainsString( 'Reload the page', PostControlsController::control_error_message( new StaleRecordException( 'x' ) ) );
		self::assertStringContainsString( 'Pick a valid date', PostControlsController::control_error_message( new ValidationException( 'PressQueue explicit schedule date is invalid.' ) ) );
		self::assertStringContainsString( 'images', PostControlsController::control_error_message( new ValidationException( 'Selected media attachment is outside the authority boundary.' ) ) );
		self::assertSame( 'REST request body must be a JSON object.', PostControlsController::control_error_message( new ValidationException( 'REST request body must be a JSON object.' ) ) );
		self::assertSame( 'PressQueue controls could not be saved.', PostControlsController::control_error_message( new ValidationException( 'Internal detail that must stay hidden.' ) ) );
	}

	public function test_classic_save_runs_before_the_post_update_and_stops_it_on_failure(): void {
		$this->grant_control_access();
		$this->classic_request(
			array(
				'pressqueue_schedule_mode' => 'explicit_time',
				'pressqueue_share'         => '1',
			)
		);

		try {
			PostControlsController::handle_classic_pre_update( 42, array() );
			self::fail( 'A failed controls save must stop the request before WordPress writes the post.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'The post was not saved', $exception->getMessage() );
		}

		self::assertFalse( metadata_exists( 'post', 42, PostControlMetadata::META_KEY ) );
	}

	public function test_classic_save_stores_the_switch_once_per_request(): void {
		$this->grant_control_access();
		$this->classic_request( array() );

		PostControlsController::handle_classic_pre_update( 42, array() );
		self::assertSame( 'exclude', PostControlMetadata::read_for_post( 42 )['policy'] );

		// The save_post fallback must not save again with the now stale base hash.
		PostControlsController::handle_classic_save( 42, $GLOBALS['pressqueue_test_posts'][42], true );
		self::assertSame( 'exclude', PostControlMetadata::read_for_post( 42 )['policy'] );
	}

	public function test_classic_save_ignores_other_posts_and_requests_without_the_box(): void {
		$this->grant_control_access();
		PostControlsController::handle_classic_pre_update( 42, array() );
		self::assertFalse( metadata_exists( 'post', 42, PostControlMetadata::META_KEY ) );

		$this->classic_request( array() );
		$GLOBALS['pressqueue_test_posts'][43] = new WP_Post( array( 'ID' => 43 ) );
		PostControlsController::handle_classic_pre_update( 43, array() );
		self::assertFalse( metadata_exists( 'post', 43, PostControlMetadata::META_KEY ) );
	}

	/**
	 * Grant the current user access to post controls.
	 */
	private function grant_control_access(): void {
		$GLOBALS['pressqueue_test_current_user_caps']['edit_post']                        = true;
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::EDIT_POST_CONTROLS ] = true;
	}

	/**
	 * Simulate a classic editor POST for post 42.
	 *
	 * @param array<string,mixed> $fields Share form fields.
	 */
	private function classic_request( array $fields ): void {
		$GLOBALS['pressqueue_test_valid_nonces']['pressqueue_save_post_controls_42'] = 'nonce-42';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge(
			array(
				'post_ID'                             => '42',
				'pressqueue_controls_present'         => '1',
				'pressqueue_share_form'               => '1',
				'pressqueue_controls_nonce'           => 'nonce-42',
				'pressqueue_base_control_bundle_hash' => PostControlMetadata::bundle_hash( PostControlMetadata::read_for_post( 42 ) ),
			),
			$fields
		);
		$_REQUEST                  = $_POST;
	}

	public function test_missing_post_returns_not_found_before_control_facts_are_loaded(): void {
		$GLOBALS['pressqueue_test_current_user_caps']['edit_post']                        = true;
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::EDIT_POST_CONTROLS ] = true;

		$error = PostControlsController::rest_permission_callback( new WP_REST_Request( array( 'post_id' => 404 ) ) );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'not_found', $error->get_error_code() );
	}
}
