<?php
/**
 * Post editor controls for PressQueue.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Editorial\NewPostDefaults;
use PressQueue\Editorial\PostControlAuthorization;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Registers and renders post-level policy and channel controls.
 */
final class PostControlsController {
	public const REST_NAMESPACE = 'pressqueue/v1';

	/**
	 * Classic control saves already handled in this request.
	 *
	 * @var array<int,bool>
	 */
	private static array $classic_saves = array();

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'init', array( self::class, 'register_post_meta' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'add_meta_boxes', array( self::class, 'register_meta_boxes' ), 10, 2 );
		add_action( 'pre_post_update', array( self::class, 'handle_classic_pre_update' ), 10, 2 );
		add_action( 'save_post', array( self::class, 'handle_classic_save' ), 15, 3 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_block_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_classic_assets' ) );
		NewPostDefaults::register_hooks();

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'add_post_metadata', array( self::class, 'guard_control_meta_write' ), 10, 5 );
			add_filter( 'update_post_metadata', array( self::class, 'guard_control_meta_write' ), 10, 5 );
			add_filter( 'delete_post_metadata', array( self::class, 'guard_control_meta_write' ), 10, 5 );
		}
	}

	/**
	 * Register raw metadata outside core REST.
	 */
	public static function register_post_meta(): void {
		if ( ! function_exists( 'register_meta' ) ) {
			return;
		}

		register_meta(
			'post',
			PostControlMetadata::META_KEY,
			array(
				'auth_callback'     => '__return_false',
				'sanitize_callback' => array( self::class, 'sanitize_registered_meta' ),
				'show_in_rest'      => false,
				'single'            => true,
				'type'              => 'string',
			)
		);
	}

	/**
	 * Sanitize directly written metadata by requiring a valid canonical bundle.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_registered_meta( $value ): string {
		return PostControlMetadata::encoded( PostControlMetadata::normalize_bundle( $value ) );
	}

	/**
	 * Short-circuit direct metadata writes when the current user cannot edit controls.
	 *
	 * @param mixed  $check Existing metadata short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Metadata key.
	 * @param mixed  $meta_value Raw metadata value.
	 * @param mixed  $prev_value Previous metadata value or delete-all flag.
	 * @return mixed
	 */
	public static function guard_control_meta_write( $check, int $object_id, string $meta_key, $meta_value = null, $prev_value = null ) {
		unset( $meta_value, $prev_value );

		if ( null !== $check || PostControlMetadata::META_KEY !== $meta_key ) {
			return $check;
		}

		if ( PostControlAuthorization::is_supported_post( $object_id ) && PostControlAuthorization::current_user_can_access( $object_id ) ) {
			return null;
		}

		return false;
	}

	/**
	 * Register custom REST routes.
	 */
	public static function register_routes(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<post_id>\d+)/controls',
			array(
				array(
					'callback'            => array( self::class, 'handle_get_controls' ),
					'methods'             => 'GET',
					'permission_callback' => array( self::class, 'rest_permission_callback' ),
				),
				array(
					'callback'            => array( self::class, 'handle_put_controls' ),
					'methods'             => 'PUT',
					'permission_callback' => array( self::class, 'rest_permission_callback' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<post_id>\d+)/activity',
			array(
				'callback'            => array( self::class, 'handle_get_activity' ),
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'rest_permission_callback' ),
			)
		);
	}

	/**
	 * REST permission callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function rest_permission_callback( \WP_REST_Request $request ) {
		return self::rest_permission_for_post( self::post_id_from_rest_request( $request ) );
	}

	/**
	 * Handle GET controls.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_get_controls( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = self::post_id_from_rest_request( $request );

		try {
			PostControlAuthorization::require_current_user_access( $post_id );

			return self::rest_response( self::controls_response( $post_id ), 200 );
		} catch ( \Throwable $throwable ) {
			return self::error_response( 'forbidden', SafeOperatorError::message( $throwable, __( 'You are not allowed to read PressQueue controls for this post.', 'pressqueue' ) ), 403 );
		}
	}

	/**
	 * Handle GET activity: what happened to the post per channel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_get_activity( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = self::post_id_from_rest_request( $request );

		try {
			PostControlAuthorization::require_current_user_access( $post_id );

			return self::rest_response( array( 'activity' => PostSharingSummary::activity( $post_id ) ), 200 );
		} catch ( \Throwable $throwable ) {
			return self::error_response( 'forbidden', SafeOperatorError::message( $throwable, __( 'You are not allowed to read PressQueue activity for this post.', 'pressqueue' ) ), 403 );
		}
	}

	/**
	 * Handle PUT controls.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_put_controls( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = self::post_id_from_rest_request( $request );

		try {
			$params = self::request_json_object( $request );

			PostControlAuthorization::require_current_user_access( $post_id );

			if ( ! isset( $params['base_control_bundle_hash'] ) || ! is_string( $params['base_control_bundle_hash'] ) ) {
				throw new ValidationException( 'A base control bundle hash is required.' );
			}

			StorageValidator::assert_hash( $params['base_control_bundle_hash'] );

			if ( ! isset( $params['controls'] ) || ! is_array( $params['controls'] ) || array_is_list( $params['controls'] ) ) {
				throw new ValidationException( 'A complete PressQueue control bundle is required.' );
			}

			$controls = PostControlMetadata::normalize_bundle( self::without_unfinished_schedule( $params['controls'] ) );
			self::assert_media_control_authority( $post_id, $controls );
			PostControlMetadata::replace_for_post( $post_id, $controls, $params['base_control_bundle_hash'] );

			return self::rest_response( self::controls_response( $post_id ), 200 );
		} catch ( StaleRecordException $exception ) {
			return self::error_response( 'conflict', SafeOperatorError::message( $exception ), 409 );
		} catch ( ValidationException $exception ) {
			return self::error_response( 'invalid_controls', self::control_error_message( $exception ), 400, SafeOperatorError::field_errors( $exception, 'controls' ) );
		} catch ( \Throwable $throwable ) {
			return self::error_response( 'forbidden', SafeOperatorError::message( $throwable, __( 'PressQueue controls could not be saved.', 'pressqueue' ) ), 403 );
		}
	}

	/**
	 * Register the classic editor fallback box.
	 *
	 * @param string   $post_type Post type.
	 * @param \WP_Post $post Post.
	 */
	public static function register_meta_boxes( string $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post || ! PostControlAuthorization::is_supported_post_type( $post_type ) ) {
			return;
		}

		if ( ! function_exists( 'add_meta_box' ) ) {
			return;
		}

		add_meta_box(
			'pressqueue-post-controls',
			__( 'Social sharing', 'pressqueue' ),
			array( self::class, 'render_meta_box' ),
			$post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Render classic editor controls.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_meta_box( $post ): void {
		if ( ! $post instanceof \WP_Post || ! PostControlAuthorization::current_user_can_access( (int) $post->ID ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to change social sharing for this post.', 'pressqueue' ) . '</p>';
			return;
		}

		$post_id   = (int) $post->ID;
		$bundle    = PostControlMetadata::read_for_post( $post_id );
		$is_on     = 'exclude' !== $bundle['policy'];
		$published = 'publish' === (string) $post->post_status;
		$context   = PostSharingSummary::editor_context( $post_id );
		$on_bundle = $bundle;

		if ( ! $is_on ) {
			$on_bundle['policy'] = 'inherit';
		}

		$display  = '' === (string) $post->post_password && 'private' !== (string) $post->post_status ? PostSharingSummary::saved_post_preview( $post, $on_bundle ) : null;
		$kept_off = is_array( $display ) ? (string) ( $display['kept_off'] ?? '' ) : '';

		echo '<div class="pq-editor pq-editor--classic' . ( '' === $kept_off ? '' : ' is-kept-off' ) . '" data-pq-classic>';
		wp_nonce_field( 'pressqueue_save_post_controls_' . $post_id, 'pressqueue_controls_nonce' );
		echo '<input type="hidden" name="pressqueue_controls_present" value="1" />';
		echo '<input type="hidden" name="pressqueue_share_form" value="1" />';
		echo '<input type="hidden" name="pressqueue_base_control_bundle_hash" value="' . esc_attr( PostControlMetadata::bundle_hash( $bundle ) ) . '" />';

		if ( '' !== $kept_off ) {
			// A "never share" rule matches: the switch shows off and cannot be changed, while the
			// stored choice is carried through unchanged (a disabled checkbox is not submitted).
			if ( $is_on ) {
				echo '<input type="hidden" name="pressqueue_share" value="1" />';
			}
			echo '<p class="pq-ed-switch"><label for="pressqueue-share"><input type="checkbox" id="pressqueue-share" disabled aria-describedby="pressqueue-kept-off" /> ' . esc_html__( 'Share this post', 'pressqueue' ) . '</label></p>';
			echo '<p class="pq-ed-kept-off" id="pressqueue-kept-off">' . esc_html( $kept_off ) . '</p>';
		} else {
			echo '<p class="pq-ed-switch"><label for="pressqueue-share"><input type="checkbox" id="pressqueue-share" name="pressqueue_share" value="1" data-pq-share ' . checked( $is_on, true, false ) . ' /> ' . esc_html__( 'Share this post', 'pressqueue' ) . '</label></p>';
		}

		if ( $published ) {
			self::render_classic_activity( PostSharingSummary::activity( $post_id ), '' !== $kept_off );
		}

		if ( '' === $kept_off ) {
			echo '<div class="pq-ed-when-on" data-pq-when="on"' . ( $is_on ? '' : ' hidden' ) . '>';
			self::render_classic_summary( $post, $display, $published );
			echo '</div>';

			echo '<p class="pq-ed-muted pq-ed-when-off" data-pq-when="off"' . ( $is_on ? ' hidden' : '' ) . '>';
			echo esc_html(
				$published
					? __( 'Sharing is off for this post. Updates and Share old posts leave it out.', 'pressqueue' )
					: __( 'This post will not be shared. You can switch it on any time before publishing.', 'pressqueue' )
			);
			echo '</p>';

			if ( ! $published && '' !== (string) $context['opt_in_hint'] ) {
				echo '<p class="pq-ed-hint">' . esc_html( (string) $context['opt_in_hint'] ) . '</p>';
			}
		}

		if ( '' !== (string) $context['mode_note'] ) {
			echo '<p class="pq-ed-note pq-ed-note--' . esc_attr( (string) $context['sharing_mode'] ) . '">' . esc_html( (string) $context['mode_note'] ) . '</p>';
		}

		// The customize fields stay in the form while hidden, so stored choices are saved unchanged.
		self::render_classic_customize( $bundle, $display, $context, '' !== $kept_off );
		echo '</div>';
	}

	/**
	 * Render the classic summary of where the post goes.
	 *
	 * @param \WP_Post                 $post Post.
	 * @param array<string,mixed>|null $display Preview display.
	 * @param bool                     $published Whether the post is published.
	 */
	private static function render_classic_summary( \WP_Post $post, ?array $display, bool $published ): void {
		if ( '' !== (string) $post->post_password ) {
			echo '<p class="pq-ed-muted">' . esc_html__( 'Password-protected posts are not shared.', 'pressqueue' ) . '</p>';
			return;
		}

		if ( 'private' === (string) $post->post_status ) {
			echo '<p class="pq-ed-muted">' . esc_html__( 'Private posts are not shared.', 'pressqueue' ) . '</p>';
			return;
		}

		if ( null === $display ) {
			echo '<p class="pq-ed-muted">' . esc_html__( 'PressQueue checks this post against your sharing rules when you publish it.', 'pressqueue' ) . '</p>';
			return;
		}

		if ( empty( $display['will_share'] ) ) {
			if ( $published ) {
				echo '<p class="pq-ed-muted">' . esc_html__( 'Updating this post does not share it again.', 'pressqueue' ) . '</p>';
				return;
			}

			echo '<div class="pq-ed-goes pq-ed-goes--none"><p><strong>' . esc_html__( 'Not shared when you publish.', 'pressqueue' ) . '</strong> ' . esc_html( (string) $display['reason'] ) . '</p>';
			echo '<p class="pq-ed-small pq-ed-muted">' . esc_html__( 'Based on the last saved version of this post.', 'pressqueue' ) . '</p></div>';
			return;
		}

		echo '<div class="pq-ed-goes">';
		echo '<p class="pq-ed-small pq-ed-muted">' . esc_html( (string) $display['heading'] ) . '</p>';
		echo '<ul class="pq-ed-channels">';
		foreach ( (array) $display['channels'] as $channel ) {
			if ( ! is_array( $channel ) ) {
				continue;
			}
			echo '<li>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_name returns escaped markup.
			echo Ui::channel_name( (string) $channel['service'], (string) $channel['name'] );
			if ( '' === (string) $display['details'] ) {
				echo '<span class="pq-ed-small pq-ed-muted pq-ed-channel-detail">' . esc_html( trim( (string) $channel['timing'] . ' · ' . (string) $channel['image'], ' ·' ) ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
		if ( '' !== (string) $display['details'] ) {
			echo '<p class="pq-ed-small pq-ed-muted">' . esc_html( (string) $display['details'] ) . '</p>';
		}
		foreach ( (array) ( $display['left_out'] ?? array() ) as $channel ) {
			if ( is_array( $channel ) ) {
				/* translators: 1: channel name, 2: reason. */
				echo '<p class="pq-ed-small pq-ed-muted pq-ed-left-out">' . esc_html( sprintf( __( 'Not sent to %1$s: %2$s', 'pressqueue' ), (string) $channel['name'], (string) $channel['reason'] ) ) . '</p>';
			}
		}
		echo '<p class="pq-ed-small pq-ed-muted">' . esc_html__( 'Based on the last saved version of this post.', 'pressqueue' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render what happened to a published post.
	 *
	 * @param array<string,mixed> $activity Activity summary.
	 * @param bool                $kept_off Whether a "never share" rule already explains why nothing is shared.
	 */
	private static function render_classic_activity( array $activity, bool $kept_off = false ): void {
		$items = isset( $activity['items'] ) && is_array( $activity['items'] ) ? $activity['items'] : array();

		if ( array() === $items && $kept_off ) {
			return;
		}

		echo '<div class="pq-ed-activity">';
		if ( array() !== $items ) {
			echo '<ul class="pq-ed-channels">';
			foreach ( $items as $item ) {
				echo '<li class="pq-ed-activity-row">';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_name returns escaped markup.
				echo Ui::channel_name( (string) $item['service'], (string) $item['name'] );
				echo '<span class="pq-ed-activity-state">';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill returns escaped markup.
				echo Ui::pill( (string) $item['tone'], (string) $item['label'], (string) $item['help'] );
				echo ' <span class="pq-ed-small pq-ed-muted" title="' . esc_attr( (string) $item['time_full'] ) . '">' . esc_html( (string) $item['time'] ) . '</span>';
				echo '</span>';
				if ( '' !== (string) ( $item['reason'] ?? '' ) ) {
					echo '<span class="pq-ed-small pq-ed-activity-reason">' . esc_html( (string) $item['reason'] ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		} elseif ( '' !== (string) $activity['not_shared_title'] ) {
			echo '<p><strong>' . esc_html( (string) $activity['not_shared_title'] ) . '</strong> ' . esc_html( (string) $activity['not_shared'] ) . '</p>';
		} else {
			echo '<p class="pq-ed-muted">' . esc_html__( 'PressQueue has not shared this post.', 'pressqueue' ) . '</p>';
		}

		if ( '' !== (string) $activity['url'] && function_exists( 'current_user_can' ) && current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			echo '<p class="pq-ed-small"><a href="' . esc_url( (string) $activity['url'] ) . '">' . esc_html__( 'See in Activity', 'pressqueue' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Render the classic "Customize for this post" section.
	 *
	 * @param array<string,mixed>      $bundle Stored controls.
	 * @param array<string,mixed>|null $display Preview display.
	 * @param array<string,mixed>      $context Editor context.
	 * @param bool                     $hidden Whether to keep the section in the form but out of sight.
	 */
	private static function render_classic_customize( array $bundle, ?array $display, array $context, bool $hidden = false ): void {
		$message   = is_array( $bundle['message'] ?? null ) ? $bundle['message'] : array( 'mode' => 'inherit' );
		$schedule  = is_array( $bundle['schedule'] ?? null ) ? $bundle['schedule'] : array( 'mode' => 'inherit' );
		$media     = is_array( $bundle['media'] ?? null ) ? $bundle['media'] : array( 'mode' => 'inherit' );
		$channels  = is_array( $bundle['channels'] ?? null ) ? $bundle['channels'] : array( 'mode' => 'inherit' );
		$customize = 'inherit' !== ( $message['mode'] ?? 'inherit' ) || 'inherit' !== ( $schedule['mode'] ?? 'inherit' ) || 'inherit' !== ( $media['mode'] ?? 'inherit' ) || 'inherit' !== ( $channels['mode'] ?? 'inherit' );
		$rule      = is_array( $display['rule'] ?? null ) ? $display['rule'] : array();
		$info      = is_array( $display['message'] ?? null ) ? $display['message'] : array();

		echo '<details class="pq-ed-more"' . ( $customize ? ' open' : '' ) . ( $hidden ? ' hidden' : '' ) . '><summary>' . esc_html__( 'Customize for this post', 'pressqueue' ) . '</summary><div class="pq-ed-more-body">';

		// Message.
		$value = 'override' === ( $message['mode'] ?? '' ) ? (string) ( $message['value'] ?? '' ) : '';
		echo '<p class="pq-ed-field"><label for="pressqueue-message">' . esc_html__( 'Message', 'pressqueue' ) . '</label>';
		echo '<textarea id="pressqueue-message" name="pressqueue_message_value" rows="3" class="widefat" data-pq-message data-limit="' . esc_attr( (string) (int) ( $info['limit'] ?? 0 ) ) . '" data-limit-service="' . esc_attr( (string) ( $info['limit_service'] ?? '' ) ) . '" data-url-length="' . esc_attr( (string) (int) ( $info['url_length'] ?? 0 ) ) . '" placeholder="' . esc_attr( (string) ( $info['rule_message'] ?? '' ) ) . '" aria-describedby="pressqueue-message-help">' . esc_textarea( $value ) . '</textarea>';
		echo '<span class="pq-ed-help" id="pressqueue-message-help">' . esc_html__( 'Leave empty to use the rule\'s message.', 'pressqueue' ) . ' <span data-pq-count aria-live="polite"></span></span></p>';

		// Channels.
		$listed   = self::listed_channels( $context, $channels );
		$selected = 'override' === ( $channels['mode'] ?? '' ) && is_array( $channels['channel_public_ids'] ?? null ) ? $channels['channel_public_ids'] : null;
		if ( array() !== $listed ) {
			echo '<fieldset class="pq-ed-field"><legend>' . esc_html__( 'Channels', 'pressqueue' ) . '</legend>';
			foreach ( $listed as $channel ) {
				$checked = null === $selected || in_array( $channel['public_id'], $selected, true );
				echo '<input type="hidden" name="pressqueue_channel_listed[]" value="' . esc_attr( (string) $channel['public_id'] ) . '" />';
				echo '<label class="pq-ed-check"><input type="checkbox" name="pressqueue_channel_public_ids[]" value="' . esc_attr( (string) $channel['public_id'] ) . '" ' . checked( $checked, true, false ) . ' /> ';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_name returns escaped markup.
				echo Ui::channel_name( (string) $channel['service'], (string) $channel['name'] );
				echo '</label>';
			}
			echo '<span class="pq-ed-help">' . esc_html__( 'Untick a channel to leave it out for this post.', 'pressqueue' ) . '</span>';
			echo '</fieldset>';
		}
		if ( null !== $selected ) {
			// A stored restriction only goes away when the writer asks for it explicitly, even when no channel is listed.
			echo '<p class="pq-ed-field"><label class="pq-ed-check"><input type="checkbox" name="pressqueue_channel_reset" value="1" /> ' . esc_html__( 'Use the channels from the rule again', 'pressqueue' ) . '</label></p>';
		}

		// Timing.
		$schedule_mode = (string) ( $schedule['mode'] ?? 'inherit' );
		echo '<p class="pq-ed-field"><label for="pressqueue-schedule-mode">' . esc_html__( 'Timing', 'pressqueue' ) . '</label>';
		echo '<select id="pressqueue-schedule-mode" name="pressqueue_schedule_mode" class="widefat" data-pq-toggle-for="schedule">';
		foreach ( self::schedule_options() as $option_value => $option_label ) {
			echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( $schedule_mode, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select>';
		self::render_rule_help( (string) ( $rule['timing'] ?? '' ), 'schedule' );
		echo '</p>';

		$timezone = (string) ( $schedule['timezone'] ?? $context['timezone'] );
		echo '<div class="pq-ed-field pq-ed-datetime" data-pq-show-for="schedule:explicit_time">';
		echo '<p class="pq-ed-field-row"><label>' . esc_html__( 'Date', 'pressqueue' ) . '<input type="date" name="pressqueue_schedule_local_date" value="' . esc_attr( (string) ( $schedule['local_date'] ?? '' ) ) . '" /></label>';
		echo '<label>' . esc_html__( 'Time', 'pressqueue' ) . '<input type="time" name="pressqueue_schedule_local_time" value="' . esc_attr( (string) ( $schedule['local_time'] ?? '' ) ) . '" /></label></p>';
		echo '<p><label for="pressqueue-schedule-timezone">' . esc_html__( 'Time zone', 'pressqueue' ) . '</label>';
		echo '<select id="pressqueue-schedule-timezone" name="pressqueue_schedule_timezone" class="widefat">';
		foreach ( self::timezone_options( (string) $context['timezone'], (string) $context['timezone_label'], $timezone ) as $zone_value => $zone_label ) {
			echo '<option value="' . esc_attr( $zone_value ) . '" ' . selected( $timezone, $zone_value, false ) . '>' . esc_html( $zone_label ) . '</option>';
		}
		echo '</select></p>';
		if ( isset( $schedule['utc_offset'] ) && is_string( $schedule['utc_offset'] ) ) {
			echo '<input type="hidden" name="pressqueue_schedule_utc_offset" value="' . esc_attr( $schedule['utc_offset'] ) . '" />';
		}
		echo '</div>';

		// Image.
		$media_mode = (string) ( $media['mode'] ?? 'inherit' );
		$ids        = 'post_media' === $media_mode && is_array( $media['post_media']['attachment_ids'] ?? null ) ? array_map( 'intval', $media['post_media']['attachment_ids'] ) : array();
		echo '<p class="pq-ed-field"><label for="pressqueue-media-mode">' . esc_html__( 'Image', 'pressqueue' ) . '</label>';
		echo '<select id="pressqueue-media-mode" name="pressqueue_media_mode" class="widefat" data-pq-toggle-for="media">';
		foreach ( self::media_options( $media_mode ) as $option_value => $option_label ) {
			echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( $media_mode, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select>';
		self::render_rule_help( (string) ( $rule['image'] ?? '' ), 'media' );
		echo '</p>';
		echo '<div class="pq-ed-field" data-pq-show-for="media:post_media">';
		echo '<input type="hidden" name="pressqueue_media_attachment_ids" value="' . esc_attr( implode( ',', $ids ) ) . '" data-pq-media-ids />';
		echo '<ul class="pq-ed-thumbs" data-pq-media-list>';
		foreach ( $ids as $attachment_id ) {
			$thumb = function_exists( 'wp_get_attachment_image' ) ? wp_get_attachment_image( $attachment_id, 'thumbnail' ) : '';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image returns escaped markup.
			echo '<li>' . ( '' === $thumb ? esc_html( '#' . $attachment_id ) : $thumb ) . '</li>';
		}
		echo '</ul>';
		echo '<button type="button" class="button" data-pq-media-pick>' . esc_html__( 'Choose images', 'pressqueue' ) . '</button>';
		echo '<span class="pq-ed-help">' . esc_html__( 'Up to four images from the media library.', 'pressqueue' ) . '</span>';
		echo '</div>';

		echo '</div></details>';
	}

	/**
	 * Return the channels listed under "Channels".
	 *
	 * @param array<string,mixed> $context Editor context.
	 * @param array<string,mixed> $channels Stored channel controls.
	 * @return array<int,array<string,mixed>>
	 */
	private static function listed_channels( array $context, array $channels ): array {
		$stored = 'override' === ( $channels['mode'] ?? '' ) && is_array( $channels['channel_public_ids'] ?? null ) ? $channels['channel_public_ids'] : array();
		$listed = array();

		foreach ( (array) $context['channels'] as $channel ) {
			if ( ! is_array( $channel ) ) {
				continue;
			}

			if ( ( ! empty( $channel['in_rules'] ) && ! empty( $channel['usable'] ) ) || in_array( $channel['public_id'], $stored, true ) ) {
				$listed[] = $channel;
			}
		}

		return $listed;
	}

	/**
	 * Render the "From the rule" hint under a select while it inherits.
	 *
	 * @param string $text Rule setting in words.
	 * @param string $group Toggle group of the select.
	 */
	private static function render_rule_help( string $text, string $group ): void {
		if ( '' === $text ) {
			return;
		}

		/* translators: %s: setting used by the matching rule, for example "Add to the end of the Buffer queue". */
		echo '<span class="pq-ed-help" data-pq-show-for="' . esc_attr( $group . ':inherit' ) . '">' . esc_html( sprintf( __( 'From the rule: %s', 'pressqueue' ), $text ) ) . '</span>';
	}

	/**
	 * Return timing choices.
	 *
	 * @return array<string,string>
	 */
	public static function schedule_options(): array {
		$labels = Labels::schedules();

		return array(
			'inherit'       => __( 'Use the rule', 'pressqueue' ),
			'queue_end'     => $labels['queue_end']['label'],
			'queue_next'    => $labels['queue_next']['label'],
			'immediate'     => $labels['immediate']['label'],
			'draft'         => $labels['draft']['label'],
			'explicit_time' => __( 'Pick a date and time', 'pressqueue' ),
		);
	}

	/**
	 * Return image choices.
	 *
	 * @param string $current Current media mode.
	 * @return array<string,string>
	 */
	public static function media_options( string $current = '' ): array {
		$options = array(
			'inherit'                 => __( 'Use the rule', 'pressqueue' ),
			'none'                    => __( 'No image', 'pressqueue' ),
			'featured_image_optional' => __( 'Featured image', 'pressqueue' ),
		);

		if ( 'featured_image' === $current ) {
			$options['featured_image'] = __( 'Featured image, hold the post back without one', 'pressqueue' );
		}

		$options['post_media'] = __( 'Choose images', 'pressqueue' );

		return $options;
	}

	/**
	 * Return time zone choices with the site time zone first.
	 *
	 * @param string $site_zone Site time zone.
	 * @param string $site_label Site time zone as shown in WordPress settings.
	 * @param string $current Current value.
	 * @return array<string,string>
	 */
	private static function timezone_options( string $site_zone, string $site_label, string $current ): array {
		/* translators: %s: site time zone, for example Europe/Berlin. */
		$options = array( $site_zone => sprintf( __( 'Site time zone (%s)', 'pressqueue' ), '' === $site_label ? $site_zone : $site_label ) );

		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}

		foreach ( timezone_identifiers_list() as $zone ) {
			if ( ! isset( $options[ $zone ] ) ) {
				$options[ $zone ] = str_replace( '_', ' ', $zone );
			}
		}

		return $options;
	}

	/**
	 * Save classic editor controls before WordPress writes the post.
	 *
	 * Running here, before the status transition, means a failed save stops the
	 * post update, so a post is never published with an unsaved "off" switch.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $data Post data about to be written.
	 */
	public static function handle_classic_pre_update( $post_id, $data = null ): void {
		unset( $data );
		self::save_classic_controls( (int) $post_id );
	}

	/**
	 * Save classic editor controls after the post was written (fallback for new posts).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 * @param bool     $update Whether this was an update.
	 */
	public static function handle_classic_save( int $post_id, $post, bool $update ): void {
		unset( $update );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		self::save_classic_controls( $post_id );
	}

	/**
	 * Save the classic form for one post once per request.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function save_classic_controls( int $post_id ): void {
		if ( ! isset( $_POST['pressqueue_controls_present'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only identifies the originating form; its nonce is verified below.
		$form_post_id = isset( $_POST['post_ID'] ) ? max( 0, (int) $_POST['post_ID'] ) : 0;
		if ( $post_id <= 0 || $form_post_id !== $post_id || isset( self::$classic_saves[ $post_id ] ) ) {
			return;
		}

		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}

		try {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request method is normalized on the next line.
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
			if ( 'POST' !== strtoupper( $method ) ) {
				throw new ValidationException( 'PressQueue changes require POST.' );
			}

			PostControlAuthorization::require_current_user_access( $post_id );
			check_admin_referer( 'pressqueue_save_post_controls_' . $post_id, 'pressqueue_controls_nonce' );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
			$input = wp_unslash( $_POST );
			if ( ! is_array( $input ) ) {
				throw new ValidationException( 'PressQueue control input is invalid.' );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
			$base_hash = isset( $_POST['pressqueue_base_control_bundle_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['pressqueue_base_control_bundle_hash'] ) ) : '';
			StorageValidator::assert_hash( $base_hash );

			$controls = isset( $input['pressqueue_share_form'] )
				? self::bundle_from_share_form( $input, PostControlMetadata::read_for_post( $post_id ) )
				: PostControlMetadata::bundle_from_form( $input );
			self::assert_media_control_authority( $post_id, $controls );
			self::$classic_saves[ $post_id ] = true;
			PostControlMetadata::replace_for_post( $post_id, $controls, $base_hash );
		} catch ( \Throwable $throwable ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die(
					esc_html(
						sprintf(
							/* translators: %s: reason the sharing settings could not be saved. */
							__( 'The post was not saved because its social sharing settings could not be saved: %s Go back, check the Social sharing box, and save again.', 'pressqueue' ),
							self::control_error_message( $throwable )
						)
					),
					esc_html__( 'Social sharing settings not saved', 'pressqueue' ),
					array(
						'back_link' => true,
						'response'  => $throwable instanceof StaleRecordException ? 409 : 400,
					)
				);
			}
		}
	}

	/**
	 * Explain a failed controls save in words that say how to fix it.
	 *
	 * Internal validation messages are only matched, never shown.
	 *
	 * @param \Throwable $throwable Failure.
	 * @return string
	 */
	public static function control_error_message( \Throwable $throwable ): string {
		if ( $throwable instanceof StaleRecordException ) {
			return __( 'Someone changed the sharing settings for this post while you were editing it. Reload the page to see them.', 'pressqueue' );
		}

		if ( $throwable instanceof ValidationException ) {
			$message = $throwable->getMessage();
			$known   = array(
				'explicit schedule' => __( 'Pick a valid date, time and time zone for sharing, or choose another timing.', 'pressqueue' ),
				'media'             => __( 'One of the chosen images cannot be used. Choose up to four images from the media library, or pick another image setting.', 'pressqueue' ),
				'message override'  => __( 'The custom message must be 2,000 characters or fewer.', 'pressqueue' ),
				'channel'           => __( 'One of the chosen channels cannot be used. Reload the page and pick the channels again.', 'pressqueue' ),
			);

			foreach ( $known as $fragment => $text ) {
				if ( false !== stripos( $message, $fragment ) ) {
					return $text;
				}
			}
		}

		return SafeOperatorError::message( $throwable, __( 'PressQueue controls could not be saved.', 'pressqueue' ) );
	}

	/**
	 * Build a control bundle from the simple classic form.
	 *
	 * @param array<string,mixed> $input Unslashed request input.
	 * @param array<string,mixed> $current Currently stored controls.
	 * @return array<string,mixed>
	 */
	public static function bundle_from_share_form( array $input, array $current ): array {
		$policy = ! empty( $input['pressqueue_share'] )
			? ( 'include' === ( $current['policy'] ?? '' ) ? 'include' : 'inherit' )
			: 'exclude';

		$listed  = self::string_list( $input['pressqueue_channel_listed'] ?? array() );
		$checked = array_values( array_intersect( self::string_list( $input['pressqueue_channel_public_ids'] ?? array() ), $listed ) );
		$form    = array(
			'pressqueue_channel_mode' => 'inherit',
			'pressqueue_media_mode'   => self::scalar_input( $input, 'pressqueue_media_mode', 'inherit' ),
			'pressqueue_message_mode' => 'inherit',
			'pressqueue_policy'       => $policy,
		);

		$stored        = is_array( $current['channels'] ?? null ) ? $current['channels'] : array( 'mode' => 'inherit' );
		$stored_ids    = 'override' === ( $stored['mode'] ?? '' ) && is_array( $stored['channel_public_ids'] ?? null ) ? self::string_list( $stored['channel_public_ids'] ) : null;
		$reset_request = ! empty( $input['pressqueue_channel_reset'] );

		if ( null !== $stored_ids && ! $reset_request ) {
			// Keep an existing restriction. Channels the form did not list (for example ones that are
			// switched off right now) stay as they were; listed ones follow the checkboxes.
			$hidden                                = array_values( array_diff( $stored_ids, $listed ) );
			$form['pressqueue_channel_mode']       = 'override';
			$form['pressqueue_channel_public_ids'] = array_values( array_unique( array_merge( $hidden, $checked ) ) );
		} elseif ( null === $stored_ids && array() !== $listed && count( $checked ) < count( array_unique( $listed ) ) ) {
			$form['pressqueue_channel_mode']       = 'override';
			$form['pressqueue_channel_public_ids'] = array_values( array_unique( $checked ) );
		}

		$message = isset( $input['pressqueue_message_value'] ) && is_string( $input['pressqueue_message_value'] ) ? $input['pressqueue_message_value'] : '';
		if ( function_exists( 'sanitize_textarea_field' ) ) {
			$message = sanitize_textarea_field( $message );
		}
		if ( '' !== trim( $message ) ) {
			$form['pressqueue_message_mode']  = 'override';
			$form['pressqueue_message_value'] = $message;
		}

		$schedule_mode = self::scalar_input( $input, 'pressqueue_schedule_mode', 'inherit' );
		if (
			'exclude' === $policy
			&& 'explicit_time' === $schedule_mode
			&& ( '' === self::scalar_input( $input, 'pressqueue_schedule_local_date', '' ) || '' === self::scalar_input( $input, 'pressqueue_schedule_local_time', '' ) )
		) {
			// A half-filled date and time cannot stop a post from being saved while sharing is off.
			$schedule_mode = 'inherit';
		}

		$form['pressqueue_schedule_mode'] = $schedule_mode;
		if ( 'explicit_time' === $schedule_mode ) {
			foreach ( array( 'local_date', 'local_time', 'timezone' ) as $key ) {
				$form[ 'pressqueue_schedule_' . $key ] = self::scalar_input( $input, 'pressqueue_schedule_' . $key, '' );
			}

			$stored = is_array( $current['schedule'] ?? null ) ? $current['schedule'] : array();
			$offset = self::scalar_input( $input, 'pressqueue_schedule_utc_offset', '' );
			if (
				'' !== $offset
				&& ( $stored['local_date'] ?? null ) === $form['pressqueue_schedule_local_date']
				&& ( $stored['local_time'] ?? null ) === $form['pressqueue_schedule_local_time']
				&& ( $stored['timezone'] ?? null ) === $form['pressqueue_schedule_timezone']
			) {
				$form['pressqueue_schedule_utc_offset'] = $offset;
			}
		}

		if ( 'post_media' === $form['pressqueue_media_mode'] ) {
			$form['pressqueue_media_attachment_ids'] = self::scalar_input( $input, 'pressqueue_media_attachment_ids', '' );
			if ( 'exclude' !== $policy && '' === trim( $form['pressqueue_media_attachment_ids'], ', ' ) ) {
				throw new ValidationException( 'PressQueue post media selection is empty.' );
			}
		}

		return PostControlMetadata::bundle_from_form( $form );
	}

	/**
	 * Return a sanitized scalar form value.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $key Key.
	 * @param string              $fallback Fallback.
	 * @return string
	 */
	private static function scalar_input( array $input, string $key, string $fallback ): string {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : $fallback;
	}

	/**
	 * Drop a half-filled explicit date and time while sharing is switched off.
	 *
	 * The date and time only matter when the post is shared, so an unfinished one must not
	 * block saving or publishing. Any other value is left for normal validation.
	 *
	 * @param array<string,mixed> $controls Raw control bundle.
	 * @return array<string,mixed>
	 */
	public static function without_unfinished_schedule( array $controls ): array {
		$schedule = $controls['schedule'] ?? null;

		if ( 'exclude' !== ( $controls['policy'] ?? null ) || ! is_array( $schedule ) || 'explicit_time' !== ( $schedule['mode'] ?? null ) ) {
			return $controls;
		}

		foreach ( array( 'local_date', 'local_time' ) as $key ) {
			if ( ! isset( $schedule[ $key ] ) || '' === $schedule[ $key ] ) {
				$controls['schedule'] = array( 'mode' => 'inherit' );
				break;
			}
		}

		return $controls;
	}

	/**
	 * Return a sanitized list of strings.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,string>
	 */
	private static function string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$output = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$output[] = sanitize_text_field( $item );
			}
		}

		return $output;
	}

	/**
	 * Enqueue the block editor controls.
	 */
	public static function enqueue_block_editor_assets(): void {
		if ( ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'plugins_url' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'post' !== $screen->base || ! PostControlAuthorization::is_supported_post_type( $screen->post_type ) ) {
			return;
		}

		$path = defined( 'PRESSQUEUE_DIR' ) ? PRESSQUEUE_DIR . 'assets/editor/post-controls.js' : '';
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_script(
			'pressqueue-post-controls',
			plugins_url( 'assets/editor/post-controls.js', PRESSQUEUE_FILE ),
			array( 'wp-api-fetch', 'wp-block-editor', 'wp-components', 'wp-core-data', 'wp-data', 'wp-date', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-plugins' ),
			Ui::asset_version( 'assets/editor/post-controls.js' ),
			true
		);

		if ( function_exists( 'wp_add_inline_script' ) && function_exists( 'wp_json_encode' ) ) {
			wp_add_inline_script( 'pressqueue-post-controls', 'window.pressqueuePostControls = ' . wp_json_encode( self::script_config() ) . ';', 'before' );
		}

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'pressqueue-post-controls', 'pressqueue', defined( 'PRESSQUEUE_DIR' ) ? PRESSQUEUE_DIR . 'languages' : '' );
		}

		self::enqueue_style();
	}

	/**
	 * Enqueue classic editor styles and behavior where the meta box can appear.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public static function enqueue_classic_assets( $hook_suffix = '' ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! PostControlAuthorization::is_supported_post_type( (string) $screen->post_type ) ) {
			return;
		}

		if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_script(
			'pressqueue-post-controls-classic',
			plugins_url( 'assets/editor/post-controls-classic.js', PRESSQUEUE_FILE ),
			array( 'wp-i18n' ),
			Ui::asset_version( 'assets/editor/post-controls-classic.js' ),
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'pressqueue-post-controls-classic', 'pressqueue', defined( 'PRESSQUEUE_DIR' ) ? PRESSQUEUE_DIR . 'languages' : '' );
		}

		self::enqueue_style();
	}

	/**
	 * Enqueue the editor stylesheet.
	 */
	private static function enqueue_style(): void {
		if ( ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		wp_enqueue_style( 'pressqueue-post-controls', plugins_url( 'assets/editor/post-controls.css', PRESSQUEUE_FILE ), array(), Ui::asset_version( 'assets/editor/post-controls.css' ) );
	}

	/**
	 * Return the configuration handed to the block editor script.
	 *
	 * @return array<string,mixed>
	 */
	private static function script_config(): array {
		$post    = function_exists( 'get_post' ) ? get_post() : null;
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$media   = array();

		foreach ( Labels::media_modes() as $mode => $label ) {
			$media[ $mode ] = $label['label'];
		}

		return array(
			'canAccess'   => $post_id > 0 ? PostControlAuthorization::current_user_can_access( $post_id ) : ( function_exists( 'current_user_can' ) && current_user_can( Capabilities::EDIT_POST_CONTROLS ) ),
			'canViewLog'  => function_exists( 'current_user_can' ) && current_user_can( Capabilities::VIEW_DELIVERIES ),
			'mediaLabels' => $media,
			'schedules'   => self::schedule_options(),
			'images'      => self::media_options( 'featured_image' ),
		);
	}

	/**
	 * Build a REST controls response.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function controls_response( int $post_id ): array {
		$bundle = PostControlMetadata::read_for_post( $post_id );

		return array(
			'control_bundle_hash'    => PostControlMetadata::bundle_hash( $bundle ),
			'controls'               => $bundle,
			'editor'                 => PostSharingSummary::editor_context( $post_id ),
			'effective_facts'        => array(
				'channels'                    => self::channel_summaries(),
				'inherited_policy'            => 'inherit',
				'requested_channel_ids'       => $bundle['channels']['channel_public_ids'] ?? array(),
				'rule_relevant_metadata_keys' => self::rule_metadata_allowlist(),
				'rule_relevant_taxonomies'    => self::rule_taxonomies(),
				'selected_rule_destinations'  => self::selected_rule_destinations(),
				'supported_media_modes'       => array( 'inherit', 'none', 'featured_image', 'featured_image_optional', 'post_media' ),
				'supported_schedule_modes'    => array( 'inherit', 'immediate', 'queue_next', 'queue_end', 'explicit_time', 'draft' ),
			),
			'supported_next_actions' => array(
				'preview' => PostControlAuthorization::current_user_can_access( $post_id ),
				'replace' => PostControlAuthorization::current_user_can_access( $post_id ),
			),
		);
	}

	/**
	 * Check REST access for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error
	 */
	private static function rest_permission_for_post( int $post_id ) {
		if ( function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
			return self::rest_error( 'unauthenticated', 'Authentication is required.', 401 );
		}

		if ( ! function_exists( 'get_post' ) || ! ( get_post( $post_id ) instanceof \WP_Post ) ) {
			return self::rest_error( 'not_found', 'Post does not exist.', 404 );
		}

		if ( ! PostControlAuthorization::is_supported_post( $post_id ) ) {
			return self::rest_error( 'invalid_post_type', 'PressQueue controls are not supported for this post type.', 400 );
		}

		if ( ! PostControlAuthorization::current_user_can_access( $post_id ) ) {
			return self::rest_error( 'forbidden', 'You are not allowed to edit PressQueue controls for this post.', 403 );
		}

		return true;
	}

	/**
	 * Validate selected attachment IDs against the current editor's authority.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $bundle Normalized controls.
	 */
	private static function assert_media_control_authority( int $post_id, array $bundle ): void {
		$media = isset( $bundle['media'] ) && is_array( $bundle['media'] ) ? $bundle['media'] : array();
		if ( 'post_media' !== ( $media['mode'] ?? null ) ) {
			return;
		}

		$ids = isset( $media['post_media']['attachment_ids'] ) && is_array( $media['post_media']['attachment_ids'] ) ? $media['post_media']['attachment_ids'] : array();
		foreach ( $ids as $attachment_id ) {
			$attachment = function_exists( 'get_post' ) ? get_post( (int) $attachment_id ) : null;
			if ( ! $attachment instanceof \WP_Post || 'attachment' !== (string) $attachment->post_type ) {
				throw new ValidationException( 'Selected media attachment does not exist.' );
			}

			if ( current_user_can( 'edit_post', (int) $attachment_id ) ) {
				continue;
			}

			if ( (int) $attachment->post_parent !== $post_id ) {
				throw new ValidationException( 'Selected media attachment is outside the authority boundary.' );
			}
		}
	}

	/**
	 * Return a post ID from a REST request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int
	 */
	private static function post_id_from_rest_request( \WP_REST_Request $request ): int {
		return max( 0, (int) $request['post_id'] );
	}

	/**
	 * Return a JSON object from a REST request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private static function request_json_object( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new ValidationException( 'REST request body must be a JSON object.' );
		}

		return $params;
	}

	/**
	 * Return safe channel summaries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function channel_summaries(): array {
		if ( ! self::storage_ready() ) {
			return array();
		}

		try {
			return array_map(
				static function ( ChannelRecord $channel ): array {
					return array(
						'availability' => $channel->availability,
						'enabled'      => $channel->enabled,
						'label'        => self::channel_label( $channel ),
						'provider'     => $channel->provider,
						'public_id'    => $channel->public_id,
						'service'      => $channel->service,
					);
				},
				( new ChannelRepository() )->find_all()
			);
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array();
		}
	}

	/**
	 * Return currently selected destinations from enabled rules.
	 *
	 * @return array<int,string>
	 */
	private static function selected_rule_destinations(): array {
		if ( ! self::storage_ready() ) {
			return array();
		}

		$ids = array();

		try {
			foreach ( ( new RuleRepository() )->find_enabled() as $rule ) {
				if ( ! isset( $rule->destination_set['destinations'] ) || ! is_array( $rule->destination_set['destinations'] ) ) {
					continue;
				}

				foreach ( $rule->destination_set['destinations'] as $destination ) {
					if ( is_array( $destination ) && isset( $destination['channel_public_id'] ) && is_string( $destination['channel_public_id'] ) ) {
						$ids[] = $destination['channel_public_id'];
					}
				}
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array();
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_STRING );

		return $ids;
	}

	/**
	 * Return metadata keys used by enabled rules.
	 *
	 * @return array<int,string>
	 */
	private static function rule_metadata_allowlist(): array {
		if ( ! self::storage_ready() ) {
			return array();
		}

		$keys = array();

		try {
			foreach ( ( new RuleRepository() )->find_enabled() as $rule ) {
				$keys = array_merge( $keys, $rule->custom_field_allowlist );
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array();
		}

		$keys = array_values( array_unique( $keys ) );
		sort( $keys, SORT_STRING );

		return $keys;
	}

	/**
	 * Return taxonomy controls used by enabled rules.
	 *
	 * @return array<int,array{taxonomy:string,rest_base:string}>
	 */
	private static function rule_taxonomies(): array {
		if ( ! self::storage_ready() ) {
			return array();
		}

		$taxonomies = array();

		try {
			foreach ( ( new RuleRepository() )->find_enabled() as $rule ) {
				foreach ( self::taxonomy_names_from_condition_tree( $rule->condition_tree ) as $taxonomy ) {
					$taxonomies[ $taxonomy ] = self::taxonomy_rest_base( $taxonomy );
				}
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array();
		}

		ksort( $taxonomies, SORT_STRING );

		$output = array();
		foreach ( $taxonomies as $taxonomy => $rest_base ) {
			$output[] = array(
				'rest_base' => $rest_base,
				'taxonomy'  => $taxonomy,
			);
		}

		return $output;
	}

	/**
	 * Return taxonomy names from a condition tree.
	 *
	 * @param array<string,mixed> $node Condition node.
	 * @return array<int,string>
	 */
	private static function taxonomy_names_from_condition_tree( array $node ): array {
		if ( ( $node['type'] ?? null ) === 'condition' && ( $node['condition'] ?? null ) === 'taxonomy' ) {
			$value = $node['value'] ?? null;
			if ( is_array( $value ) && isset( $value['taxonomy'] ) && is_string( $value['taxonomy'] ) ) {
				return array( $value['taxonomy'] );
			}
		}

		if ( ( $node['type'] ?? null ) !== 'group' || ! isset( $node['children'] ) || ! is_array( $node['children'] ) ) {
			return array();
		}

		$taxonomies = array();
		foreach ( $node['children'] as $child ) {
			if ( is_array( $child ) ) {
				$taxonomies = array_merge( $taxonomies, self::taxonomy_names_from_condition_tree( $child ) );
			}
		}

		$taxonomies = array_values( array_unique( $taxonomies ) );
		sort( $taxonomies, SORT_STRING );

		return $taxonomies;
	}

	/**
	 * Return a taxonomy REST base.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private static function taxonomy_rest_base( string $taxonomy ): string {
		if ( function_exists( 'get_taxonomy' ) ) {
			$object = get_taxonomy( $taxonomy );
			if ( is_object( $object ) ) {
				$rest_base = $object->rest_base;
				if ( is_string( $rest_base ) && '' !== $rest_base ) {
					return $rest_base;
				}
			}
		}

		return $taxonomy;
	}

	/**
	 * Check whether storage-backed facts are available.
	 *
	 * @return bool
	 */
	private static function storage_ready(): bool {
		return Database::has_global_database() && ( new SchemaStatus() )->is_ready_for_planning();
	}

	/**
	 * Return a safe channel label.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @return string
	 */
	private static function channel_label( ChannelRecord $channel ): string {
		foreach ( array( 'label', 'name', 'title' ) as $key ) {
			if ( isset( $channel->display[ $key ] ) && is_string( $channel->display[ $key ] ) && '' !== trim( $channel->display[ $key ] ) ) {
				return $channel->display[ $key ];
			}
		}

		return $channel->public_id;
	}

	/**
	 * Build a REST response.
	 *
	 * @param array<string,mixed> $body Body.
	 * @param int                 $status Status.
	 * @return \WP_REST_Response
	 */
	private static function rest_response( array $body, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( $body, $status );
	}

	/**
	 * Build a REST error response.
	 *
	 * @param string              $code Code.
	 * @param string              $message Message.
	 * @param int                 $status HTTP status.
	 * @param array<string,mixed> $field_errors Field errors.
	 * @return \WP_REST_Response
	 */
	private static function error_response( string $code, string $message, int $status, array $field_errors = array() ): \WP_REST_Response {
		return self::rest_response(
			array(
				'code'         => $code,
				'field_errors' => $field_errors,
				'message'      => $message,
			),
			$status
		);
	}

	/**
	 * Build a REST permission error.
	 *
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param int    $status Status.
	 * @return \WP_Error
	 */
	private static function rest_error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'field_errors' => array(),
				'status'       => $status,
			)
		);
	}
}
