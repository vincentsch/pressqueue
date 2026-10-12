<?php
/**
 * Share one already published post now, from the post editor.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor wires service dependencies.

use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Runs Share now through the same preview, confirmation and queue services as Share old posts.
 *
 * A Share now preview is a one-post "bulk" batch. It gets the same rules, exclusions,
 * duplicate checks, idempotency keys and re-check before anything is queued. This class
 * only builds the one-post filter and limit documents and keeps a preview tied to its
 * post and to the person who asked for it.
 */
final class PostShareNowService {
	/**
	 * Message used when the post's own "Share this post" switch is off.
	 */
	public const SHARING_OFF = 'Sharing is switched off for this post.';

	/**
	 * Message used when the post is not a published, public post.
	 */
	public const NOT_PUBLISHED = 'Only published posts can be shared now.';

	private Database $database;
	private ?ActionSchedulerQueue $scheduler;
	private BatchRepository $batches;

	/**
	 * Constructor.
	 *
	 * @param Database|null             $database Database.
	 * @param ActionSchedulerQueue|null $scheduler Scheduler.
	 */
	public function __construct( ?Database $database = null, ?ActionSchedulerQueue $scheduler = null ) {
		$this->database  = $database ?? Database::from_globals();
		$this->scheduler = $scheduler;
		$this->batches   = new BatchRepository( $this->database );
	}

	/**
	 * Create a stored preview of what Share now would send.
	 *
	 * The rules' timing is used, including a timing picked for this post. Share old
	 * posts only allows "right away" and "next free slot" for a single share, so when
	 * the post goes to several channels with such a timing, each share goes to the end
	 * of its Buffer queue instead. The preview shows that before anything is sent. Rules
	 * that only save Buffer drafts keep their timing.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User asking for the preview.
	 * @return BatchRecord
	 */
	public function preview( int $post_id, int $user_id ): BatchRecord {
		self::require_capability();
		$post    = self::require_shareable_post( $post_id );
		$filters = self::filters( $post );
		$service = new BatchPreviewService( $this->database );
		$mode    = 'inherit';

		$assessment = $service->assess( 'bulk', $filters, self::limits( 'inherit' ), $user_id );
		if ( true === ( $assessment['summary']['invalid_multi_item_schedule'] ?? false ) && ! self::has_draft_share( $assessment['queueable_items'] ) ) {
			$mode = 'queue_end';
		}

		return $service->preview( 'bulk', $filters, self::limits( $mode ), $user_id );
	}

	/**
	 * Confirm a reviewed preview and hand its shares to the queue.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $batch_public_id Preview batch public ID.
	 * @param string $preview_snapshot_hash Reviewed snapshot hash.
	 * @param int    $user_id User confirming.
	 * @return BatchRecord
	 */
	public function confirm( int $post_id, string $batch_public_id, string $preview_snapshot_hash, int $user_id ): BatchRecord {
		self::require_capability();
		self::require_shareable_post( $post_id );
		$this->require_own_preview( $post_id, $batch_public_id, $user_id );

		$confirmed = ( new BatchConfirmationService( $this->database, $this->scheduler ) )->confirm( $batch_public_id, $preview_snapshot_hash, $user_id );

		// Add the shares now instead of waiting for the background queue, so the editor can
		// show them at once. This is the same step the scheduled queue action runs; whichever
		// runs second finds nothing left to do.
		try {
			( new BatchQueueService( $this->database, $this->scheduler ) )->process( $confirmed->public_id, $confirmed->preview_snapshot_hash );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}

		return $this->batches->find_by_public_id( $confirmed->public_id ) ?? $confirmed;
	}

	/**
	 * Cancel a preview that was not confirmed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $batch_public_id Preview batch public ID.
	 * @param int    $user_id User cancelling.
	 */
	public function cancel( int $post_id, string $batch_public_id, int $user_id ): void {
		self::require_capability();
		$batch = $this->require_own_preview( $post_id, $batch_public_id, $user_id );

		if ( 'previewed' === $batch->status ) {
			( new BatchCancellationService( $this->database ) )->cancel( $batch->public_id, $user_id );
		}
	}

	/**
	 * Return the one-post batch filters.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	public static function filters( \WP_Post $post ): array {
		return array(
			'post_ids'       => array( (int) $post->ID ),
			'post_statuses'  => array( 'publish' ),
			'post_types'     => array( (string) $post->post_type ),
			'schema_version' => 1,
		);
	}

	/**
	 * Return the one-post batch limits.
	 *
	 * @param string $mode Schedule mode: inherit or queue_end.
	 * @return array<string,mixed>
	 */
	public static function limits( string $mode ): array {
		return array(
			'candidate_limit'        => 1,
			'destination_item_limit' => BatchLimitService::MAX_DESTINATION_ITEMS,
			'schedule'               => array(
				'mode'           => $mode,
				'schema_version' => 1,
			),
			'schema_version'         => 1,
		);
	}

	/**
	 * Check whether the current user may use Share now on a post.
	 *
	 * It needs the same permission as Share old posts, plus the right to edit the post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function current_user_can_share( int $post_id ): bool {
		return function_exists( 'current_user_can' )
			&& current_user_can( Capabilities::OPERATE_BATCHES )
			&& current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Return the post when it is published, public and switched on for sharing.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post
	 */
	public static function require_shareable_post( int $post_id ): \WP_Post {
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'publish' !== (string) $post->post_status || '' !== (string) $post->post_password ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal message; the editor matches it and shows its own words.
			throw new ValidationException( self::NOT_PUBLISHED );
		}

		if ( 'exclude' === ( PostControlMetadata::read_for_post( $post_id )['policy'] ?? 'inherit' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal message; the editor matches it and shows its own words.
			throw new ValidationException( self::SHARING_OFF );
		}

		return $post;
	}

	/**
	 * Return a preview only when it is this user's Share now preview for this post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $batch_public_id Batch public ID.
	 * @param int    $user_id User ID.
	 * @return BatchRecord
	 */
	private function require_own_preview( int $post_id, string $batch_public_id, int $user_id ): BatchRecord {
		$batch    = '' === $batch_public_id ? null : $this->batches->find_by_public_id( $batch_public_id );
		$post_ids = null === $batch || ! is_array( $batch->filters['post_ids'] ?? null ) ? array() : array_map( 'intval', $batch->filters['post_ids'] );

		if ( null === $batch || 'bulk' !== $batch->batch_type || array( $post_id ) !== $post_ids || $batch->created_by !== $user_id ) {
			throw new ValidationException( 'Batch does not exist.' );
		}

		return $batch;
	}

	/**
	 * Check whether any share would be saved as a Buffer draft.
	 *
	 * @param array<int,array<string,mixed>> $items Queueable items.
	 * @return bool
	 */
	private static function has_draft_share( array $items ): bool {
		foreach ( $items as $item ) {
			if ( 'draft' === ( $item['preview_item']['delivery']['payload_preview']['schedule_slot'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Require the Share old posts permission.
	 */
	private static function require_capability(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( Capabilities::OPERATE_BATCHES ) ) {
			throw new ValidationException( 'You are not allowed to operate PressQueue batches.' );
		}
	}
}
