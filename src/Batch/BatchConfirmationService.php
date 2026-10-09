<?php
/**
 * Batch confirmation workflow.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor wires service dependencies.

use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\BatchItemRecord;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Confirms unchanged previews and reserves queueable items.
 */
final class BatchConfirmationService {
	private Database $database;
	private BatchRepository $batches;
	private BatchItemRepository $items;
	private BatchPreviewService $preview;
	private BatchLimitService $limits;
	private ActionSchedulerQueue $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Database|null             $database Database.
	 * @param ActionSchedulerQueue|null $scheduler Scheduler.
	 */
	public function __construct( ?Database $database = null, ?ActionSchedulerQueue $scheduler = null ) {
		$this->database  = $database ?? Database::from_globals();
		$this->batches   = new BatchRepository( $this->database );
		$this->items     = new BatchItemRepository( $this->database );
		$this->preview   = new BatchPreviewService( $this->database );
		$this->limits    = new BatchLimitService();
		$this->scheduler = $scheduler ?? new ActionSchedulerQueue();
	}

	/**
	 * Confirm a preview and schedule local handoff.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Reviewed snapshot hash.
	 * @param int    $actor_user_id Actor user ID.
	 * @return BatchRecord
	 */
	public function confirm( string $batch_public_id, string $preview_snapshot_hash, int $actor_user_id ): BatchRecord {
		$this->require_capability();
		RuntimeDisabledLatch::assert_open_for( 'batch confirmation' );
		StorageValidator::assert_hash( $preview_snapshot_hash );

		$batch = $this->batches->find_by_public_id( $batch_public_id );
		if ( null === $batch ) {
			throw new ValidationException( 'Batch does not exist.' );
		}

		$batch = $this->batches->expire_if_due( $batch );
		if ( 'previewed' !== $batch->status ) {
			throw new ValidationException( 'Batch preview is no longer confirmable.' );
		}

		if ( $batch->preview_snapshot_hash !== $preview_snapshot_hash ) {
			throw new ValidationException( 'Batch preview snapshot does not match the reviewed snapshot.' );
		}

		$this->limits->assert_confirmable_summary( $batch->preview_summary );
		$stored_items = $this->items->find_by_batch( $batch->public_id );
		$evaluation   = $this->preview->evaluate( $batch, null, null, $stored_items );
		if ( $evaluation['snapshot_hash'] !== $batch->preview_snapshot_hash ) {
			throw new ValidationException( 'Batch preview changed. Create a fresh preview before confirming.' );
		}

		$this->assert_items_unchanged( $stored_items, $evaluation['items'] );

		$confirmed = $this->database->transaction(
			function () use ( $batch, $actor_user_id, $stored_items ): BatchRecord {
				// Serialize confirmations on shared destinations before reserving.
				$channels = array();
				foreach ( $stored_items as $item ) {
					if ( null !== $item->channel_public_id ) {
						$channels[ $item->channel_public_id ] = true;
					}
				}
				ksort( $channels, SORT_STRING );
				foreach ( array_keys( $channels ) as $channel ) {
					$this->database->get_row( 'SELECT public_id FROM `' . $this->database->table( Schema::CHANNELS ) . '` WHERE public_id = %s FOR UPDATE', array( $channel ) );
				}
				$fresh = $this->preview->evaluate( $batch, null, null, $stored_items, false );
				if ( $fresh['snapshot_hash'] !== $batch->preview_snapshot_hash ) {
					throw new ValidationException( 'Batch preview changed before reservation.' );
				}
				$this->assert_items_unchanged( $stored_items, $fresh['items'] );
				$confirming = $this->batches->transition(
					$batch->public_id,
					$batch->lock_version,
					array( 'previewed' ),
					'confirming'
				);

				if ( null === $confirming ) {
					throw new StaleRecordException( 'Batch confirmation lost the status race.' );
				}

				foreach ( $this->items->find_by_batch( $batch->public_id ) as $item ) {
					if ( 'previewed' !== $item->state ) {
						continue;
					}

					$reserved = $this->items->reserve( $item );
					if ( null === $reserved ) {
						throw new StaleRecordException( 'Batch item reservation lost the status race.' );
					}
				}

				$queueing = $this->batches->transition(
					$confirming->public_id,
					$confirming->lock_version,
					array( 'confirming' ),
					'queueing',
					array(
						'confirmed_at' => StorageValidator::now(),
						'confirmed_by' => $actor_user_id,
						'started_at'   => StorageValidator::now(),
					)
				);

				if ( null === $queueing ) {
					throw new StaleRecordException( 'Batch queue handoff lost the status race.' );
				}

				return $queueing;
			},
			array( Schema::BATCHES, Schema::BATCH_ITEMS, Schema::CHANNELS )
		);

		try {
			$this->scheduler->schedule_batch_queue( $confirmed->public_id, $confirmed->preview_snapshot_hash );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}

		return $confirmed;
	}

	/**
	 * Compare stored rows with a fresh nondurable evaluation.
	 *
	 * @param array<int,BatchItemRecord>     $stored Stored items.
	 * @param array<int,array<string,mixed>> $fresh Fresh item inputs.
	 */
	private function assert_items_unchanged( array $stored, array $fresh ): void {
		if ( count( $stored ) !== count( $fresh ) ) {
			throw new ValidationException( 'Batch item set changed. Create a fresh preview before confirming.' );
		}

		foreach ( $stored as $index => $item ) {
			$candidate = $fresh[ $index ] ?? null;
			if (
				null === $candidate
				|| $item->position !== (int) $candidate['position']
				|| $item->post_id !== (int) $candidate['post_id']
				|| ( $candidate['channel_public_id'] ?? null ) !== $item->channel_public_id
				|| ( $candidate['intent_occurrence_key'] ?? null ) !== $item->intent_occurrence_key
				|| $item->state !== (string) $candidate['state']
				|| $item->semantic_preview_hash !== (string) $candidate['semantic_preview_hash']
				|| ( $item->preview_item['delivery']['delivery_key'] ?? null ) !== ( $candidate['preview_item']['delivery']['delivery_key'] ?? null )
				|| ( $item->preview_item['delivery']['payload_hash'] ?? null ) !== ( $candidate['preview_item']['delivery']['payload_hash'] ?? null )
			) {
				throw new ValidationException( 'Batch item changed. Create a fresh preview before confirming.' );
			}
		}
	}

	/**
	 * Require batch operation capability.
	 */
	private function require_capability(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( Capabilities::OPERATE_BATCHES ) ) {
			throw new ValidationException( 'You are not allowed to operate PressQueue batches.' );
		}
	}
}
