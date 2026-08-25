<?php
/**
 * Post editor controls for PressQueue.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Editorial\PostControlAuthorization;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Security\Capabilities;
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
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'init', array( self::class, 'register_post_meta' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'add_meta_boxes', array( self::class, 'register_meta_boxes' ), 10, 2 );
		add_action( 'save_post', array( self::class, 'handle_classic_save' ), 15, 3 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_block_editor_assets' ) );

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
			return self::error_response( 'forbidden', $throwable->getMessage(), 403 );
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

			$controls = PostControlMetadata::normalize_bundle( $params['controls'] );
			self::assert_media_control_authority( $post_id, $controls );
			PostControlMetadata::replace_for_post( $post_id, $controls, $params['base_control_bundle_hash'] );

			return self::rest_response( self::controls_response( $post_id ), 200 );
		} catch ( StaleRecordException $exception ) {
			return self::error_response( 'conflict', $exception->getMessage(), 409 );
		} catch ( ValidationException $exception ) {
			return self::error_response( 'invalid_controls', $exception->getMessage(), 400, array( 'controls' => $exception->getMessage() ) );
		} catch ( \Throwable $throwable ) {
			return self::error_response( 'forbidden', $throwable->getMessage(), 403 );
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
			__( 'PressQueue', 'pressqueue' ),
			array( self::class, 'render_meta_box' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Render classic editor controls.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_meta_box( $post ): void {
		if ( ! $post instanceof \WP_Post || ! PostControlAuthorization::current_user_can_access( (int) $post->ID ) ) {
			echo '<p>' . esc_html__( 'PressQueue controls are unavailable for this user.', 'pressqueue' ) . '</p>';
			return;
		}

		$bundle   = PostControlMetadata::read_for_post( (int) $post->ID );
		$channels = self::channel_summaries();
		$selected = $bundle['channels']['channel_public_ids'] ?? array();
		$selected = is_array( $selected ) ? $selected : array();

		wp_nonce_field( 'pressqueue_save_post_controls', 'pressqueue_controls_nonce' );
		echo '<input type="hidden" name="pressqueue_controls_present" value="1" />';
		echo '<input type="hidden" name="pressqueue_base_control_bundle_hash" value="' . esc_attr( PostControlMetadata::bundle_hash( $bundle ) ) . '" />';

		echo '<p><label for="pressqueue-policy">' . esc_html__( 'Policy', 'pressqueue' ) . '</label><br />';
		echo '<select id="pressqueue-policy" name="pressqueue_policy">';
		foreach ( array( 'inherit', 'include', 'exclude' ) as $policy ) {
			echo '<option value="' . esc_attr( $policy ) . '" ' . selected( $bundle['policy'], $policy, false ) . '>' . esc_html( ucfirst( $policy ) ) . '</option>';
		}
		echo '</select></p>';

		$mode = (string) ( $bundle['channels']['mode'] ?? 'inherit' );
		echo '<p><label for="pressqueue-channel-mode">' . esc_html__( 'Destinations', 'pressqueue' ) . '</label><br />';
		echo '<select id="pressqueue-channel-mode" name="pressqueue_channel_mode">';
		echo '<option value="inherit" ' . selected( $mode, 'inherit', false ) . '>' . esc_html__( 'Inherit', 'pressqueue' ) . '</option>';
		echo '<option value="override" ' . selected( $mode, 'override', false ) . '>' . esc_html__( 'Restrict channels', 'pressqueue' ) . '</option>';
		echo '</select></p>';

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Channel restrictions', 'pressqueue' ) . '</legend>';
		if ( array() === $channels ) {
			echo '<p>' . esc_html__( 'No local destinations are available.', 'pressqueue' ) . '</p>';
		}

		foreach ( $channels as $channel ) {
			$checked = in_array( $channel['public_id'], $selected, true );
			echo '<label><input type="checkbox" name="pressqueue_channel_public_ids[]" value="' . esc_attr( $channel['public_id'] ) . '" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $channel['label'] ) . '</label><br />';
		}
		echo '</fieldset>';

		$message      = is_array( $bundle['message'] ?? null ) ? $bundle['message'] : array( 'mode' => 'inherit' );
		$message_mode = (string) ( $message['mode'] ?? 'inherit' );
		echo '<p><label for="pressqueue-message-mode">' . esc_html__( 'Message', 'pressqueue' ) . '</label><br />';
		echo '<select id="pressqueue-message-mode" name="pressqueue_message_mode">';
		echo '<option value="inherit" ' . selected( $message_mode, 'inherit', false ) . '>' . esc_html__( 'Inherit', 'pressqueue' ) . '</option>';
		echo '<option value="override" ' . selected( $message_mode, 'override', false ) . '>' . esc_html__( 'Override', 'pressqueue' ) . '</option>';
		echo '</select></p>';
		echo '<p><textarea name="pressqueue_message_value" class="widefat" rows="3">' . esc_textarea( (string) ( $message['value'] ?? '' ) ) . '</textarea></p>';

		$schedule      = is_array( $bundle['schedule'] ?? null ) ? $bundle['schedule'] : array( 'mode' => 'inherit' );
		$schedule_mode = (string) ( $schedule['mode'] ?? 'inherit' );
		echo '<p><label for="pressqueue-schedule-mode">' . esc_html__( 'Schedule', 'pressqueue' ) . '</label><br />';
		echo '<select id="pressqueue-schedule-mode" name="pressqueue_schedule_mode">';
		foreach ( array( 'inherit', 'immediate', 'queue_next', 'queue_end', 'explicit_time' ) as $schedule_option ) {
			echo '<option value="' . esc_attr( $schedule_option ) . '" ' . selected( $schedule_mode, $schedule_option, false ) . '>' . esc_html( $schedule_option ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label>' . esc_html__( 'Local date', 'pressqueue' ) . '<br /><input name="pressqueue_schedule_local_date" type="date" value="' . esc_attr( (string) ( $schedule['local_date'] ?? '' ) ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'Local time', 'pressqueue' ) . '<br /><input name="pressqueue_schedule_local_time" type="time" value="' . esc_attr( (string) ( $schedule['local_time'] ?? '' ) ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'Timezone', 'pressqueue' ) . '<br /><input name="pressqueue_schedule_timezone" type="text" class="widefat" value="' . esc_attr( (string) ( $schedule['timezone'] ?? '' ) ) . '" placeholder="Europe/Berlin" /></label></p>';
		echo '<p><label>' . esc_html__( 'UTC offset', 'pressqueue' ) . '<br /><input name="pressqueue_schedule_utc_offset" type="text" class="widefat" value="' . esc_attr( (string) ( $schedule['utc_offset'] ?? '' ) ) . '" placeholder="+01:00" /></label></p>';

		$media      = is_array( $bundle['media'] ?? null ) ? $bundle['media'] : array( 'mode' => 'inherit' );
		$media_mode = (string) ( $media['mode'] ?? 'inherit' );
		$media_ids  = '';
		if ( isset( $media['post_media']['attachment_ids'] ) && is_array( $media['post_media']['attachment_ids'] ) ) {
			$media_ids = implode( ',', array_map( 'strval', $media['post_media']['attachment_ids'] ) );
		}

		echo '<p><label for="pressqueue-media-mode">' . esc_html__( 'Media', 'pressqueue' ) . '</label><br />';
		echo '<select id="pressqueue-media-mode" name="pressqueue_media_mode">';
		foreach ( array( 'inherit', 'none', 'featured_image', 'post_media' ) as $media_option ) {
			echo '<option value="' . esc_attr( $media_option ) . '" ' . selected( $media_mode, $media_option, false ) . '>' . esc_html( $media_option ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label>' . esc_html__( 'Attachment IDs', 'pressqueue' ) . '<br /><input name="pressqueue_media_attachment_ids" type="text" class="widefat" value="' . esc_attr( $media_ids ) . '" /></label></p>';
	}

	/**
	 * Save classic editor controls.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 * @param bool     $update Whether this was an update.
	 */
	public static function handle_classic_save( int $post_id, $post, bool $update ): void {
		unset( $update );

		if ( ! $post instanceof \WP_Post || ! isset( $_POST['pressqueue_controls_present'] ) ) {
			return;
		}

		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}

		try {
			PostControlAuthorization::require_current_user_access( $post_id );
			check_admin_referer( 'pressqueue_save_post_controls', 'pressqueue_controls_nonce' );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
			$input = wp_unslash( $_POST );
			if ( ! is_array( $input ) ) {
				throw new ValidationException( 'PressQueue control input is invalid.' );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
			$base_hash = isset( $_POST['pressqueue_base_control_bundle_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['pressqueue_base_control_bundle_hash'] ) ) : '';
			StorageValidator::assert_hash( $base_hash );

			$controls = PostControlMetadata::bundle_from_form( $input );
			self::assert_media_control_authority( $post_id, $controls );
			PostControlMetadata::replace_for_post( $post_id, $controls, $base_hash );
		} catch ( \Throwable $throwable ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html( $throwable->getMessage() ) );
			}
		}
	}

	/**
	 * Enqueue the block editor controls.
	 */
	public static function enqueue_block_editor_assets(): void {
		if ( ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'plugins_url' ) ) {
			return;
		}

		$path = defined( 'PRESSQUEUE_DIR' ) ? PRESSQUEUE_DIR . 'assets/editor/post-controls.js' : '';
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_script(
			'pressqueue-post-controls',
			plugins_url( 'assets/editor/post-controls.js', PRESSQUEUE_FILE ),
			array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-plugins' ),
			defined( 'PRESSQUEUE_VERSION' ) ? PRESSQUEUE_VERSION : '0.1.0-dev',
			true
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
			'effective_facts'        => array(
				'channels'                    => self::channel_summaries(),
				'inherited_policy'            => 'inherit',
				'requested_channel_ids'       => $bundle['channels']['channel_public_ids'] ?? array(),
				'rule_relevant_metadata_keys' => self::rule_metadata_allowlist(),
				'rule_relevant_taxonomies'    => self::rule_taxonomies(),
				'selected_rule_destinations'  => self::selected_rule_destinations(),
				'supported_media_modes'       => array( 'inherit', 'none', 'featured_image', 'post_media' ),
				'supported_schedule_modes'    => array( 'inherit', 'immediate', 'queue_next', 'queue_end', 'explicit_time' ),
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
