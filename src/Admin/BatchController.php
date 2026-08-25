<?php
/**
 * Batch administration.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Batch\BatchCancellationService;
use PressQueue\Batch\BatchConfirmationService;
use PressQueue\Batch\BatchPreviewService;
use PressQueue\Batch\BatchQueueService;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Renders and mutates manual batch previews.
 */
final class BatchController {
	public const SLUG = 'pressqueue-batches';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_pressqueue_preview_batch', array( self::class, 'handle_preview' ) );
		add_action( 'admin_post_pressqueue_confirm_batch', array( self::class, 'handle_confirm' ) );
		add_action( 'admin_post_pressqueue_cancel_batch', array( self::class, 'handle_cancel' ) );
		add_action( 'admin_post_pressqueue_recover_batch', array( self::class, 'handle_recover' ) );
	}

	/**
	 * Register menu page.
	 *
	 * @param string $parent_slug Parent slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'PressQueue Batches', 'pressqueue' ),
			__( 'Batches', 'pressqueue' ),
			Capabilities::OPERATE_BATCHES,
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Render batch list or detail.
	 */
	public static function render_page(): void {
		self::require_capability();

		try {
			BatchQueueService::recover_queueing_batches();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}

		$batch_public_id = self::query_text( 'batch_public_id', 26 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PressQueue Batches', 'pressqueue' ) . '</h1>';
		self::render_notice();

		if ( '' !== $batch_public_id ) {
			self::render_detail( $batch_public_id );
		} else {
			self::render_preview_form();
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * Handle preview request.
	 */
	public static function handle_preview(): void {
		self::require_capability();
		check_admin_referer( 'pressqueue_preview_batch' );

		try {
			$batch = ( new BatchPreviewService() )->preview(
				self::post_text( 'batch_type', 'bulk' ),
				self::post_json_object( 'filters_json' ),
				self::post_json_object( 'batch_limits_json' ),
				self::current_user_id()
			);
			self::redirect( 'batch_previewed', null, $batch->public_id );
		} catch ( Throwable $throwable ) {
			self::redirect( 'batch_error', $throwable->getMessage() );
		}
	}

	/**
	 * Handle confirmation request.
	 */
	public static function handle_confirm(): void {
		self::require_capability();
		check_admin_referer( 'pressqueue_confirm_batch' );

		try {
			$batch = ( new BatchConfirmationService() )->confirm( self::post_text( 'batch_public_id' ), self::post_text( 'preview_snapshot_hash' ), self::current_user_id() );
			self::redirect( 'batch_confirmed', null, $batch->public_id );
		} catch ( Throwable $throwable ) {
			self::redirect( 'batch_error', $throwable->getMessage(), self::post_text( 'batch_public_id' ) );
		}
	}

	/**
	 * Handle cancellation request.
	 */
	public static function handle_cancel(): void {
		self::require_capability();
		check_admin_referer( 'pressqueue_cancel_batch' );

		try {
			$batch_public_id = self::post_text( 'batch_public_id' );
			( new BatchCancellationService() )->cancel( $batch_public_id, self::current_user_id() );
			self::redirect( 'batch_cancelled', null, $batch_public_id );
		} catch ( Throwable $throwable ) {
			self::redirect( 'batch_error', $throwable->getMessage(), self::post_text( 'batch_public_id' ) );
		}
	}

	/**
	 * Handle queue action recovery request.
	 */
	public static function handle_recover(): void {
		self::require_capability();
		check_admin_referer( 'pressqueue_recover_batch' );

		try {
			$batch_public_id = self::post_text( 'batch_public_id' );
			$batch           = ( new BatchRepository() )->find_by_public_id( $batch_public_id );
			if ( null === $batch ) {
				throw new ValidationException( 'Batch does not exist.' );
			}
			( new BatchQueueService() )->ensure_queue_action( $batch->public_id, $batch->preview_snapshot_hash );
			self::redirect( 'batch_recovered', null, $batch->public_id );
		} catch ( Throwable $throwable ) {
			self::redirect( 'batch_error', $throwable->getMessage(), self::post_text( 'batch_public_id' ) );
		}
	}

	/**
	 * Render the create-preview form.
	 */
	private static function render_preview_form(): void {
		echo '<h2>' . esc_html__( 'Create Preview', 'pressqueue' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pressqueue_preview_batch' );
		echo '<input type="hidden" name="action" value="pressqueue_preview_batch" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pressqueue-batch-type">' . esc_html__( 'Batch type', 'pressqueue' ) . '</label></th><td><select id="pressqueue-batch-type" name="batch_type">';
		foreach ( array( 'bulk', 'repost' ) as $type ) {
			echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $type ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-batch-filters">' . esc_html__( 'Filters JSON', 'pressqueue' ) . '</label></th><td><textarea id="pressqueue-batch-filters" name="filters_json" class="large-text code" rows="10">' . esc_textarea( self::pretty_json( self::default_filters() ) ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-batch-limits">' . esc_html__( 'Limits JSON', 'pressqueue' ) . '</label></th><td><textarea id="pressqueue-batch-limits" name="batch_limits_json" class="large-text code" rows="8">' . esc_textarea( self::pretty_json( self::default_limits() ) ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Preview Batch', 'pressqueue' ) );
		echo '</form>';
	}

	/**
	 * Render recent batches.
	 */
	private static function render_list(): void {
		$repository = new BatchRepository();
		$batches    = array_map(
			static function ( $batch ) use ( $repository ) {
				return $repository->expire_if_due( $batch );
			},
			$repository->find_recent( 50, 0 )
		);

		echo '<h2>' . esc_html__( 'Recent Batches', 'pressqueue' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Batch', 'pressqueue' ), __( 'Status', 'pressqueue' ), __( 'Created', 'pressqueue' ), __( 'Confirmed', 'pressqueue' ), __( 'Expiry', 'pressqueue' ), __( 'Counts', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $batches ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No batches have been previewed.', 'pressqueue' ) . '</td></tr>';
		}

		foreach ( $batches as $batch ) {
			$url = add_query_arg(
				array(
					'batch_public_id' => $batch->public_id,
					'page'            => self::SLUG,
				),
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '"><code>' . esc_html( $batch->public_id ) . '</code></a><br />' . esc_html( $batch->batch_type ) . '</td>';
			echo '<td><code>' . esc_html( $batch->status ) . '</code></td>';
			echo '<td>' . esc_html( $batch->created_at->format( 'Y-m-d H:i:s' ) ) . '<br />' . esc_html( (string) $batch->created_by ) . '</td>';
			echo '<td>' . esc_html( null === $batch->confirmed_at ? '' : $batch->confirmed_at->format( 'Y-m-d H:i:s' ) ) . '<br />' . esc_html( null === $batch->confirmed_by ? '' : (string) $batch->confirmed_by ) . '</td>';
			echo '<td>' . esc_html( $batch->preview_expires_at->format( 'Y-m-d H:i:s' ) ) . '</td>';
			echo '<td><pre>' . esc_html( self::pretty_json( $batch->preview_summary['counts'] ?? array() ) ) . '</pre></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render one batch detail.
	 *
	 * @param string $batch_public_id Batch public ID.
	 */
	private static function render_detail( string $batch_public_id ): void {
		$repository = new BatchRepository();
		$batch      = $repository->find_by_public_id( $batch_public_id );
		if ( null === $batch ) {
			echo '<p>' . esc_html__( 'Batch does not exist.', 'pressqueue' ) . '</p>';
			return;
		}
		$batch = $repository->expire_if_due( $batch );

		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Back to Batches', 'pressqueue' ) . '</a></p>';
		self::render_actions( $batch );
		echo '<h2>' . esc_html__( 'Batch Summary', 'pressqueue' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		$rows = array(
			__( 'Batch public ID', 'pressqueue' )       => $batch->public_id,
			__( 'Type', 'pressqueue' )                  => $batch->batch_type,
			__( 'Status', 'pressqueue' )                => $batch->status,
			__( 'Creator', 'pressqueue' )               => (string) $batch->created_by,
			__( 'Confirmer', 'pressqueue' )             => null === $batch->confirmed_by ? '' : (string) $batch->confirmed_by,
			__( 'Created at', 'pressqueue' )            => $batch->created_at->format( 'Y-m-d H:i:s' ),
			__( 'Confirmed at', 'pressqueue' )          => null === $batch->confirmed_at ? '' : $batch->confirmed_at->format( 'Y-m-d H:i:s' ),
			__( 'Preview expires at', 'pressqueue' )    => $batch->preview_expires_at->format( 'Y-m-d H:i:s' ),
			__( 'Preview snapshot hash', 'pressqueue' ) => $batch->preview_snapshot_hash,
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Counts', 'pressqueue' ) . '</th><td><pre>' . esc_html( self::pretty_json( $batch->preview_summary['counts'] ?? array() ) ) . '</pre></td></tr>';
		echo '</tbody></table>';

		self::render_items( $batch->public_id );
	}

	/**
	 * Render available batch actions.
	 *
	 * @param \PressQueue\Storage\Record\BatchRecord $batch Batch.
	 */
	private static function render_actions( $batch ): void {
		echo '<h2>' . esc_html__( 'Actions', 'pressqueue' ) . '</h2>';
		if ( 'previewed' === $batch->status ) {
			self::action_form( 'pressqueue_confirm_batch', 'pressqueue_confirm_batch', __( 'Confirm Batch', 'pressqueue' ), $batch );
		}

		if ( in_array( $batch->status, array( 'previewed', 'confirming', 'queueing' ), true ) ) {
			self::action_form( 'pressqueue_cancel_batch', 'pressqueue_cancel_batch', __( 'Cancel Batch', 'pressqueue' ), $batch );
		}

		if ( 'queueing' === $batch->status ) {
			self::action_form( 'pressqueue_recover_batch', 'pressqueue_recover_batch', __( 'Recover Queue Action', 'pressqueue' ), $batch );
		}
	}

	/**
	 * Render a POST action form.
	 *
	 * @param string                                 $action Action.
	 * @param string                                 $nonce Nonce.
	 * @param string                                 $label Button label.
	 * @param \PressQueue\Storage\Record\BatchRecord $batch Batch.
	 */
	private static function action_form( string $action, string $nonce, string $label, $batch ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:.25em .5em .25em 0;">';
		wp_nonce_field( $nonce );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="batch_public_id" value="' . esc_attr( $batch->public_id ) . '" />';
		echo '<input type="hidden" name="preview_snapshot_hash" value="' . esc_attr( $batch->preview_snapshot_hash ) . '" />';
		submit_button( $label, 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render batch item rows.
	 *
	 * @param string $batch_public_id Batch public ID.
	 */
	private static function render_items( string $batch_public_id ): void {
		$items = ( new BatchItemRepository() )->find_by_batch( $batch_public_id );

		echo '<h2>' . esc_html__( 'Items', 'pressqueue' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Item', 'pressqueue' ), __( 'Post', 'pressqueue' ), __( 'Destination', 'pressqueue' ), __( 'Message', 'pressqueue' ), __( 'Media', 'pressqueue' ), __( 'Schedule', 'pressqueue' ), __( 'Delivery', 'pressqueue' ), __( 'Reason', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $items as $item ) {
			$preview       = $item->preview_item;
			$post          = isset( $preview['post'] ) && is_array( $preview['post'] ) ? $preview['post'] : array();
			$delivery      = isset( $preview['delivery'] ) && is_array( $preview['delivery'] ) ? $preview['delivery'] : array();
			$payload       = isset( $delivery['payload_preview'] ) && is_array( $delivery['payload_preview'] ) ? $delivery['payload_preview'] : array();
			$can_read_post = function_exists( 'current_user_can' ) ? current_user_can( 'edit_post', $item->post_id ) : true;

			echo '<tr>';
			echo '<td><code>' . esc_html( $item->public_id ) . '</code><br /><code>' . esc_html( $item->state ) . '</code><br /><code>' . esc_html( $item->intent_occurrence_key ) . '</code></td>';
			if ( $can_read_post ) {
				echo '<td>' . esc_html( (string) ( $post['title'] ?? '' ) ) . '<br /><code>' . esc_html( (string) $item->post_id ) . '</code><br />' . esc_html( (string) ( $post['canonical_url'] ?? '' ) ) . '<br />' . esc_html( (string) ( $post['excerpt'] ?? '' ) ) . '</td>';
			} else {
				echo '<td>' . esc_html__( 'Restricted post', 'pressqueue' ) . '<br /><code>' . esc_html( (string) $item->post_id ) . '</code></td>';
			}
			echo '<td><code>' . esc_html( $item->channel_public_id ?? 'global' ) . '</code><br />' . esc_html( (string) ( $payload['channel_label'] ?? '' ) ) . '<br /><code>' . esc_html( (string) ( $item->inclusion_rule_public_id ?? '' ) ) . '</code><br />' . esc_html( null === $item->inclusion_rule_version ? '' : 'v' . (string) $item->inclusion_rule_version ) . '</td>';
			if ( $can_read_post ) {
				echo '<td><pre>' . esc_html( (string) ( $payload['final_encoded_text'] ?? $payload['message'] ?? '' ) ) . '</pre></td>';
				echo '<td><pre>' . esc_html( self::pretty_json( self::media_summary( $payload ) ) ) . '</pre></td>';
			} else {
				echo '<td>' . esc_html__( 'Restricted post', 'pressqueue' ) . '</td>';
				echo '<td>' . esc_html__( 'Restricted post', 'pressqueue' ) . '</td>';
			}
			echo '<td><code>' . esc_html( (string) ( $payload['schedule_slot'] ?? '' ) ) . '</code><br />' . esc_html( null === $item->scheduled_for_utc ? '' : $item->scheduled_for_utc->format( 'Y-m-d H:i:s' ) ) . '</td>';
			echo '<td>';
			echo esc_html__( 'Decision', 'pressqueue' ) . ': <code>' . esc_html( (string) ( $item->decision_public_id ?? '' ) ) . '</code><br />';
			echo esc_html__( 'Delivery key', 'pressqueue' ) . ': <code>' . esc_html( (string) ( $delivery['delivery_key'] ?? '' ) ) . '</code><br />';
			echo esc_html__( 'Payload hash', 'pressqueue' ) . ': <code>' . esc_html( (string) ( $delivery['payload_hash'] ?? '' ) ) . '</code><br />';
			if ( null !== $item->delivery_public_id && current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
				$url = add_query_arg(
					array(
						'delivery_public_id' => $item->delivery_public_id,
						'page'               => DeliveryDetailController::SLUG,
					),
					admin_url( 'admin.php' )
				);
				echo '<a href="' . esc_url( $url ) . '"><code>' . esc_html( $item->delivery_public_id ) . '</code></a>';
			} elseif ( null !== $item->delivery_public_id ) {
				echo '<code>' . esc_html( $item->delivery_public_id ) . '</code>';
			}
			if ( null !== $item->duplicate_of_delivery_public_id ) {
				echo '<br />' . esc_html__( 'Duplicate', 'pressqueue' ) . ': <code>' . esc_html( $item->duplicate_of_delivery_public_id ) . '</code>';
			}
			echo '</td>';
			echo '<td><pre>' . esc_html( self::pretty_json( self::redacted_reason( $item->reason, $can_read_post ) ) ) . '</pre></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Return a redacted media summary for display.
	 *
	 * @param array<string,mixed> $payload Payload preview.
	 * @return array<string,mixed>
	 */
	private static function media_summary( array $payload ): array {
		$media = isset( $payload['media_snapshot'] ) && is_array( $payload['media_snapshot'] ) ? $payload['media_snapshot'] : null;
		if ( null === $media ) {
			return array(
				'mode'           => 'none',
				'schema_version' => 1,
			);
		}

		unset( $media['url'], $media['signed_url'], $media['request'], $media['response'] );

		return $media;
	}

	/**
	 * Redact reason detail when post authority is missing.
	 *
	 * @param array<string,mixed> $reason Reason.
	 * @param bool                $can_read_post Whether full post facts may display.
	 * @return array<string,mixed>
	 */
	private static function redacted_reason( array $reason, bool $can_read_post ): array {
		if ( $can_read_post ) {
			return $reason;
		}

		unset( $reason['planner_reasons'] );
		$reason['redacted'] = true;

		return $reason;
	}

	/**
	 * Render notice from query args.
	 */
	private static function render_notice(): void {
		$message  = self::query_text( 'pressqueue_message', 64 );
		$messages = array(
			'batch_cancelled' => __( 'Batch cancelled.', 'pressqueue' ),
			'batch_confirmed' => __( 'Batch confirmed for local handoff.', 'pressqueue' ),
			'batch_error'     => __( 'Batch action failed.', 'pressqueue' ),
			'batch_previewed' => __( 'Batch preview is ready.', 'pressqueue' ),
			'batch_recovered' => __( 'Batch queue action recovered.', 'pressqueue' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		$type = str_contains( $message, 'error' ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $messages[ $message ] );
		$detail = self::query_text( 'pressqueue_detail', 191 );
		if ( '' !== $detail ) {
			echo ' ' . esc_html( $detail );
		}
		echo '</p></div>';
	}

	/**
	 * Redirect to the batch page.
	 *
	 * @param string      $message Message.
	 * @param string|null $detail Detail.
	 * @param string|null $batch_public_id Batch public ID.
	 */
	private static function redirect( string $message, ?string $detail = null, ?string $batch_public_id = null ): void {
		$args = array(
			'page'               => self::SLUG,
			'pressqueue_message' => $message,
		);

		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = $detail;
		}

		if ( null !== $batch_public_id && '' !== $batch_public_id ) {
			$args['batch_public_id'] = $batch_public_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Decode a POST JSON object.
	 *
	 * @param string $key Key.
	 * @return array<string,mixed>
	 */
	private static function post_json_object( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by caller and JSON is decoded structurally.
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		if ( ! is_string( $raw ) ) {
			throw new ValidationException( 'JSON field is invalid.' );
		}

		$value = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new ValidationException( 'JSON field must contain an object.' );
		}

		return $value;
	}

	/**
	 * Return POST text.
	 *
	 * @param string $key Key.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function post_text( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check nonces before reading mutations.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * Return bounded query text.
	 *
	 * @param string $key Key.
	 * @param int    $maximum Maximum length.
	 * @return string
	 */
	private static function query_text( string $key, int $maximum ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display query args.
		$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';

		return strlen( $value ) > $maximum ? substr( $value, 0, $maximum ) : $value;
	}

	/**
	 * Return current user ID.
	 *
	 * @return int
	 */
	private static function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Pretty JSON helper.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function pretty_json( $value ): string {
		$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : CanonicalJson::encode( $value );
	}

	/**
	 * Default filters.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_filters(): array {
		return array(
			'post_statuses'  => array( 'publish' ),
			'post_types'     => array( 'post' ),
			'schema_version' => 1,
		);
	}

	/**
	 * Default limits.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_limits(): array {
		return array(
			'candidate_limit'        => 20,
			'destination_item_limit' => 100,
			'schedule'               => array(
				'mode'           => 'inherit',
				'schema_version' => 1,
			),
			'schema_version'         => 1,
		);
	}

	/**
	 * Require batch operation capability.
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( Capabilities::OPERATE_BATCHES ) ) {
			wp_die( esc_html__( 'You are not allowed to operate PressQueue batches.', 'pressqueue' ) );
		}
	}
}
