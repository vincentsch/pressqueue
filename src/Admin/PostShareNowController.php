<?php
/**
 * "Share now" for published posts in the post editor.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Batch\BatchLimitService;
use PressQueue\Batch\PostShareNowService;
use PressQueue\Editorial\PostControlAuthorization;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\BatchItemRecord;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Shows what Share now would send, and sends it only after the person confirms.
 *
 * Both editors call the same three REST routes: preview, confirm and cancel. The work
 * itself is done by the Share old posts services through PostShareNowService.
 */
final class PostShareNowController {
	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		}
	}

	/**
	 * Register the REST routes.
	 */
	public static function register_routes(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		$routes = array(
			'/posts/(?P<post_id>\d+)/share-now/preview' => 'handle_preview',
			'/posts/(?P<post_id>\d+)/share-now'         => 'handle_confirm',
			'/posts/(?P<post_id>\d+)/share-now/cancel'  => 'handle_cancel',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				PostControlsController::REST_NAMESPACE,
				$route,
				array(
					'callback'            => array( self::class, $callback ),
					'methods'             => 'POST',
					'permission_callback' => array( self::class, 'permission_callback' ),
				)
			);
		}
	}

	/**
	 * Permission callback: the Share old posts permission plus the right to edit the post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission_callback( \WP_REST_Request $request ) {
		$post_id = max( 0, (int) $request['post_id'] );

		if ( function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
			return self::rest_error( 'unauthenticated', 'Authentication is required.', 401 );
		}

		if ( ! function_exists( 'get_post' ) || ! ( get_post( $post_id ) instanceof \WP_Post ) ) {
			return self::rest_error( 'not_found', 'Post does not exist.', 404 );
		}

		if ( ! PostControlAuthorization::is_supported_post( $post_id ) ) {
			return self::rest_error( 'invalid_post_type', 'PressQueue controls are not supported for this post type.', 400 );
		}

		if ( ! PostShareNowService::current_user_can_share( $post_id ) ) {
			return self::rest_error( 'forbidden', 'You are not allowed to share this post now.', 403 );
		}

		return true;
	}

	/**
	 * Create a preview of what would be sent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = max( 0, (int) $request['post_id'] );

		try {
			$batch = ( new PostShareNowService() )->preview( $post_id, self::current_user_id() );

			return new \WP_REST_Response( array( 'share_now' => self::preview_display( $batch ) ), 200 );
		} catch ( Throwable $throwable ) {
			return self::failure( $throwable );
		}
	}

	/**
	 * Confirm a preview and send it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_confirm( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = max( 0, (int) $request['post_id'] );

		try {
			$params = self::params( $request );
			( new PostShareNowService() )->confirm( $post_id, $params['batch_public_id'], $params['preview_snapshot_hash'], self::current_user_id() );

			return new \WP_REST_Response( array( 'activity' => PostSharingSummary::activity( $post_id ) ), 200 );
		} catch ( Throwable $throwable ) {
			return self::failure( $throwable );
		}
	}

	/**
	 * Cancel a preview that was not confirmed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_cancel( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = max( 0, (int) $request['post_id'] );

		try {
			$params = self::params( $request );
			( new PostShareNowService() )->cancel( $post_id, $params['batch_public_id'], self::current_user_id() );

			return new \WP_REST_Response( array( 'cancelled' => true ), 200 );
		} catch ( Throwable $throwable ) {
			return self::failure( $throwable );
		}
	}

	/**
	 * Describe a stored preview in ready-made strings for both editors.
	 *
	 * @param BatchRecord $batch Preview batch.
	 * @return array<string,mixed>
	 */
	public static function preview_display( BatchRecord $batch ): array {
		$summary    = new ActivitySummary();
		$deliveries = array();
		$skipped    = array();
		$problem    = '';

		foreach ( ( new BatchItemRepository() )->find_by_batch( $batch->public_id ) as $item ) {
			$delivery = $item->preview_item['delivery'] ?? null;
			if ( 'previewed' === $item->state && is_array( $delivery ) ) {
				$deliveries[] = $delivery;
				continue;
			}

			if ( null === $item->channel_public_id ) {
				$problem = self::no_rule_takes_part( $item )
					? __( 'No rule takes part in Share old posts yet. Tick "I pick old posts in Share old posts" in a rule, then try again.', 'pressqueue' )
					: BatchController::item_reason( $item, true );
				continue;
			}

			$channel   = $summary->channel( $item->channel_public_id );
			$payload   = is_array( $delivery['payload_preview'] ?? null ) ? $delivery['payload_preview'] : array();
			$skipped[] = array_merge(
				PostSharingSummary::channel_identity(
					$item->channel_public_id,
					null === $channel ? (string) ( $payload['service'] ?? '' ) : $channel->service,
					null === $channel ? __( 'Removed channel', 'pressqueue' ) : ActivitySummary::channel_display_name( $channel )
				),
				array( 'reason' => BatchController::item_reason( $item, true ) )
			);
		}

		$shares = array();
		if ( array() !== $deliveries ) {
			foreach ( PreviewPresenter::summarize( array( 'deliveries' => $deliveries ) )['shares'] as $share ) {
				$shares[] = array_merge(
					PostSharingSummary::channel_identity( (string) $share['channel_public_id'], (string) $share['service'], (string) $share['channel_name'] ),
					array(
						'image'     => self::image_label( $share ),
						'image_url' => (string) ( $share['image_url'] ?? '' ),
						'text'      => (string) $share['text'],
						'when'      => PreviewPresenter::timing_text( $share ),
					)
				);
			}
		}

		$single_timing = __( '"Post right away" and "Next free queue slot" only work for one channel at a time.', 'pressqueue' );
		if ( true === ( $batch->preview_summary['invalid_multi_item_schedule'] ?? false ) ) {
			$problem = $single_timing . ' ' . __( 'Pick another timing under Customize for this post, update the post, then try again.', 'pressqueue' );
		} elseif ( true === ( $batch->preview_summary['truncated'] ?? false ) ) {
			/* translators: %d: maximum number of shares at once. */
			$problem = sprintf( __( 'This post would create more than %d shares, which is too many at once.', 'pressqueue' ), BatchLimitService::MAX_DESTINATION_ITEMS );
		} elseif ( '' === $problem && array() === $shares ) {
			$problem = array() === $skipped ? __( 'No rule shares this post right now.', 'pressqueue' ) : __( 'Nothing would be sent.', 'pressqueue' );
		}

		$note = 'queue_end' === ( $batch->batch_limits['schedule']['mode'] ?? '' ) && count( $shares ) > 1
			? $single_timing . ' ' . __( 'So each share goes to the end of its Buffer queue.', 'pressqueue' )
			: '';

		return array(
			'batch_public_id'       => $batch->public_id,
			'can_confirm'           => array() !== $shares && '' === $problem && 'previewed' === $batch->status,
			'heading'               => self::heading( count( $shares ) ),
			'note'                  => $note,
			'preview_snapshot_hash' => $batch->preview_snapshot_hash,
			'problem'               => $problem,
			'shares'                => $shares,
			'skipped'               => $skipped,
		);
	}

	/**
	 * Describe what a share carries besides its text.
	 *
	 * @param array<string,mixed> $share Share from PreviewPresenter.
	 * @return string
	 */
	private static function image_label( array $share ): string {
		if ( (int) $share['image_count'] <= 0 ) {
			return __( 'Text and link', 'pressqueue' );
		}

		return 'social_card' === ( $share['image_mode'] ?? '' ) ? __( 'With the social image', 'pressqueue' ) : __( 'With image', 'pressqueue' );
	}

	/**
	 * Render the Share now box in the classic editor.
	 *
	 * The box stays hidden until its script runs, because Share now needs it.
	 *
	 * @param \WP_Post            $post Post.
	 * @param bool                $is_on Whether "Share this post" is switched on in the saved post.
	 * @param array<string,mixed> $activity Activity summary of the post.
	 */
	public static function render_classic( \WP_Post $post, bool $is_on, array $activity ): void {
		$post_id = (int) $post->ID;
		$items   = isset( $activity['items'] ) && is_array( $activity['items'] ) ? $activity['items'] : array();

		if ( array() !== $items || 'publish' !== (string) $post->post_status || '' !== (string) $post->post_password || ! PostShareNowService::current_user_can_share( $post_id ) ) {
			return;
		}

		echo '<div class="pq-ed-share-now" data-pq-share-now data-post-id="' . esc_attr( (string) $post_id ) . '" data-saved-on="' . ( $is_on ? '1' : '0' ) . '" hidden>';
		echo '<div class="pq-ed-share-now-start" data-pq-when="on"' . ( $is_on ? '' : ' hidden' ) . '>';
		echo '<p><button type="button" class="button" data-pq-share-now-start>' . esc_html__( 'Share now', 'pressqueue' ) . '</button></p>';
		echo '<p class="pq-ed-help" data-pq-share-now-help>' . esc_html__( 'You see what would be sent before anything goes out.', 'pressqueue' ) . '</p>';
		echo '<p class="pq-ed-help" data-pq-share-now-unsaved hidden>' . esc_html__( 'Update the post first to share it now.', 'pressqueue' ) . '</p>';
		echo '<div class="pq-ed-share-now-result" data-pq-share-now-result aria-live="polite"></div>';
		echo '</div>';
		echo '<p class="pq-ed-help" data-pq-when="off"' . ( $is_on ? ' hidden' : '' ) . '>' . esc_html__( 'Switch sharing on and update the post to share it now.', 'pressqueue' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Return the line shown above the shares.
	 *
	 * @param int $count Number of shares.
	 * @return string
	 */
	private static function heading( int $count ): string {
		try {
			$mode = ( new SettingsRepository() )->sharing_mode();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$mode = 'test';
		}

		if ( 'test' === $mode ) {
			return __( 'Test mode is on, so nothing goes to Buffer. PressQueue would record this as a test run:', 'pressqueue' );
		}

		if ( 'paused' === $mode ) {
			return __( 'Sharing is paused. This waits until sharing resumes:', 'pressqueue' );
		}

		/* translators: %d: number of channels. */
		return sprintf( _n( 'PressQueue sends this to Buffer for %d channel:', 'PressQueue sends this to Buffer for %d channels:', $count, 'pressqueue' ), $count );
	}

	/**
	 * Check whether a skipped post item only says that no rule takes part in Share old posts.
	 *
	 * @param BatchItemRecord $item Item.
	 * @return bool
	 */
	private static function no_rule_takes_part( BatchItemRecord $item ): bool {
		$reasons = isset( $item->reason['planner_reasons'] ) && is_array( $item->reason['planner_reasons'] ) ? $item->reason['planner_reasons'] : array();

		foreach ( $reasons as $reason ) {
			if ( is_array( $reason ) && 'no_enabled_rules' === ( $reason['code'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Turn a failure into a short message for the editor.
	 *
	 * Internal messages are only matched, never shown.
	 *
	 * @param Throwable $throwable Failure.
	 * @return \WP_REST_Response
	 */
	private static function failure( Throwable $throwable ): \WP_REST_Response {
		$message = $throwable instanceof ValidationException ? $throwable->getMessage() : '';

		if ( PostShareNowService::SHARING_OFF === $message ) {
			return self::error_response( 'sharing_off', __( 'Sharing is switched off for this post, so it cannot be shared now. Switch it on and update the post first.', 'pressqueue' ), 409 );
		}

		if ( PostShareNowService::NOT_PUBLISHED === $message ) {
			return self::error_response( 'not_published', __( 'Only published posts can be shared now.', 'pressqueue' ), 409 );
		}

		$messages = array(
			'busy'            => __( 'Something changed at the same moment. Try again.', 'pressqueue' ),
			'changed'         => __( 'The post, a rule or a channel changed after the preview. Click Share now again to see what would be sent.', 'pressqueue' ),
			'missing'         => __( 'This preview is gone. Click Share now again.', 'pressqueue' ),
			'not_confirmable' => __( 'This preview expired or was already used. Click Share now again.', 'pressqueue' ),
			'permission'      => __( 'You are not allowed to share this post now.', 'pressqueue' ),
			'post_gone'       => __( 'This post changed or is no longer published. Reload the page.', 'pressqueue' ),
			'runtime'         => __( 'Background sharing is stopped, so nothing can be shared right now. Open Settings, then Data and privacy, to see why.', 'pressqueue' ),
			'single_timing'   => __( '"Post right away" and "Next free queue slot" only work for one channel at a time. Pick another timing under Customize for this post, update the post, then try again.', 'pressqueue' ),
			'too_many'        => __( 'This post would create too many shares at once.', 'pressqueue' ),
		);
		$code     = BatchController::error_code( $throwable );

		if ( isset( $messages[ $code ] ) ) {
			return self::error_response( $code, $messages[ $code ], 'permission' === $code ? 403 : 409 );
		}

		return self::error_response( 'share_now_failed', __( 'PressQueue could not do that. Try again. If it keeps failing, check Activity.', 'pressqueue' ), 500 );
	}

	/**
	 * Read the preview identity from a JSON request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{batch_public_id:string,preview_snapshot_hash:string}
	 */
	private static function params( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new ValidationException( 'REST request body must be a JSON object.' );
		}

		$batch = is_string( $params['batch_public_id'] ?? null ) ? $params['batch_public_id'] : '';
		$hash  = is_string( $params['preview_snapshot_hash'] ?? null ) ? $params['preview_snapshot_hash'] : '';

		return array(
			'batch_public_id'       => PublicIds::is_valid( $batch ) ? $batch : '',
			'preview_snapshot_hash' => $hash,
		);
	}

	/**
	 * Return the current user ID.
	 *
	 * @return int
	 */
	private static function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Build a REST error response.
	 *
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param int    $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private static function error_response( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'         => $code,
				'field_errors' => array(),
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
