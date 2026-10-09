<?php
/**
 * Batch local queue handoff.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor wires service dependencies.

use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\Planner;
use PressQueue\Planning\PlanningResult;
use PressQueue\Planning\SchedulePolicy;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\BatchItemRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use Throwable;

/**
 * Creates or reuses local delivery rows for confirmed batch items.
 */
final class BatchQueueService {
	private Database $database;
	private BatchRepository $batches;
	private BatchItemRepository $items;
	private DeliveryRepository $deliveries;
	private DuplicateHistoryService $duplicates;
	private RuleRepository $rules;
	private EventNormalizer $normalizer;
	private Planner $planner;
	private ActionSchedulerQueue $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Database|null             $database Database.
	 * @param ActionSchedulerQueue|null $scheduler Scheduler.
	 */
	public function __construct( ?Database $database = null, ?ActionSchedulerQueue $scheduler = null ) {
		$this->database   = $database ?? Database::from_globals();
		$this->batches    = new BatchRepository( $this->database );
		$this->items      = new BatchItemRepository( $this->database );
		$this->deliveries = new DeliveryRepository( $this->database );
		$this->duplicates = new DuplicateHistoryService( $this->database );
		$this->rules      = new RuleRepository( $this->database );
		$this->normalizer = new EventNormalizer();
		$this->planner    = new Planner( $this->database );
		$this->scheduler  = $scheduler ?? new ActionSchedulerQueue();
	}

	/**
	 * Recover missing queue actions for queueing batches.
	 */
	public static function recover_queueing_batches(): void {
		if ( ! Database::has_global_database() ) {
			return;
		}

		if ( RuntimeDisabledLatch::is_closed() ) {
			return;
		}

		try {
			$status = new SchemaStatus();
			if ( $status->installed_version() < Schema::TARGET_VERSION || $status->has_active_migration_lock() || $status->has_migration_failed() ) {
				return;
			}
			$service = new self();
			foreach ( $service->batches->find_queueing() as $batch ) {
				try {
					$service->ensure_queue_action( $batch->public_id, $batch->preview_snapshot_hash );
				} catch ( \Throwable $exception ) {
					unset( $exception );
					// A later repair pass can retry this batch without breaking the site.
				}
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Ensure a queue action exists for a batch.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Snapshot hash.
	 */
	public function ensure_queue_action( string $batch_public_id, string $preview_snapshot_hash ): void {
		RuntimeDisabledLatch::assert_open_for( 'batch queue recovery' );

		if ( ! $this->scheduler->is_ready() || $this->scheduler->has_batch_queue_action( $batch_public_id, $preview_snapshot_hash ) ) {
			return;
		}

		$this->scheduler->schedule_batch_queue( $batch_public_id, $preview_snapshot_hash );
	}

	/**
	 * Process one bounded queue handoff chunk.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Snapshot hash.
	 */
	public function process( string $batch_public_id, string $preview_snapshot_hash ): void {
		if ( RuntimeDisabledLatch::is_closed() ) {
			return;
		}

		$batch = $this->batches->find_by_public_id( $batch_public_id );
		if ( null === $batch || 'queueing' !== $batch->status || $batch->preview_snapshot_hash !== $preview_snapshot_hash ) {
			return;
		}

		if ( ! $this->confirmer_has_authority( $batch->confirmed_by ) ) {
			$this->fail_reserved_items( $batch->public_id, 'confirmer_lost_authority', 'The confirming user no longer has batch operation authority.' );
			$this->complete_if_no_reserved_items( $batch->public_id );
			return;
		}

		$chunk = $this->items->find_reserved_chunk( $batch->public_id, BatchLimitService::QUEUE_CHUNK_SIZE );
		if ( array() === $chunk ) {
			$this->complete_if_no_reserved_items( $batch->public_id );
			return;
		}

		foreach ( $chunk as $item ) {
			$this->handoff_item( $item );
		}

		if ( array() === $this->items->find_reserved_chunk( $batch->public_id, 1 ) ) {
			$this->complete_if_no_reserved_items( $batch->public_id );
			return;
		}

		$this->scheduler->schedule_batch_queue_continuation( $batch->public_id, $batch->preview_snapshot_hash );
	}

	/**
	 * Create or reuse one local delivery row.
	 *
	 * @param BatchItemRecord $item Item.
	 */
	private function handoff_item( BatchItemRecord $item ): void {
		try {
			if ( null !== $this->deliveries->find_by_intent_occurrence_key( $item->intent_occurrence_key ) ) {
				$this->link_existing_delivery( $item );
				return;
			}

			if ( null === $item->channel_public_id ) {
				$this->items->mark_terminal( $item, 'policy_error', $this->reason( 'batch_item_has_no_destination', 'Reserved batch item has no destination channel.' ) );
				return;
			}

			$event = $this->runtime_event( $item );
			if ( null === $event ) {
				$this->items->mark_terminal( $item, 'policy_error', $this->reason( 'batch_runtime_event_unavailable', 'Current post state cannot be planned for this batch item.' ) );
				return;
			}

			$schedule         = $this->reviewed_schedule( $item );
			$gate             = $this->planner->preview_runtime_destination( $event, $item->channel_public_id, $schedule );
			$reviewed_preview = $this->matching_delivery( $gate, $item );

			if ( null === $reviewed_preview ) {
				$this->items->mark_terminal( $item, $this->terminal_state_from_result( $gate ), $this->reason( 'batch_runtime_gate_blocked', 'Current runtime policy no longer allows this batch item.' ) );
				return;
			}

			if ( ! $this->runtime_delivery_matches_review( $item, $reviewed_preview ) ) {
				$this->items->mark_terminal( $item, 'failed', $this->reason( 'batch_runtime_preview_changed', 'Current runtime planning no longer matches the reviewed batch payload.' ) );
				return;
			}

			$this->handoff_reviewed_delivery( $item, $event, $schedule );
		} catch ( Throwable $throwable ) {
			$current = $this->items->find_by_public_id( $item->public_id );
			if ( null === $current || 'reserved' !== $current->state ) {
				return;
			}

			$this->items->mark_terminal(
				$current,
				'failed',
				array(
					'code'           => 'batch_handoff_failed',
					'message'        => SafeOperatorError::message( $throwable ),
					'schema_version' => 1,
				)
			);
		}
	}

	/**
	 * Link an existing local delivery row after locking the item.
	 *
	 * @param BatchItemRecord $item Item.
	 */
	private function link_existing_delivery( BatchItemRecord $item ): void {
		$this->database->transaction(
			function () use ( $item ): void {
				$locked = $this->items->lock_reserved_for_handoff( $item );
				if ( null === $locked ) {
					return;
				}

				$existing = $this->deliveries->find_by_intent_occurrence_key( $locked->intent_occurrence_key );
				if ( null === $existing ) {
					return;
				}

				if ( ! $this->existing_delivery_matches_review( $locked, $existing->delivery_key, $existing->payload_hash ) ) {
					$this->items->mark_terminal(
						$locked,
						'failed',
						$this->reason( 'batch_existing_delivery_changed', 'Existing local delivery for this occurrence does not match the reviewed batch payload.' )
					);
					return;
				}

				$this->mark_queued_or_fail(
					$locked,
					$existing->decision_public_id,
					$existing->public_id,
					null === $existing->scheduled_for_utc ? null : $existing->scheduled_for_utc->format( 'Y-m-d H:i:s' )
				);
			},
			array( Schema::BATCH_ITEMS, Schema::DELIVERIES )
		);
	}

	/**
	 * Store and link a reviewed delivery after locking the item.
	 *
	 * @param BatchItemRecord                      $item Item.
	 * @param \PressQueue\Planning\NormalizedEvent $event Runtime event.
	 * @param SchedulePolicy                       $schedule Reviewed schedule.
	 */
	private function handoff_reviewed_delivery( BatchItemRecord $item, \PressQueue\Planning\NormalizedEvent $event, SchedulePolicy $schedule ): void {
		$this->database->transaction(
			function () use ( $item, $event, $schedule ): void {
				// Keep the channel-before-item lock order used by confirmation.
				if ( 1 !== $this->database->query( 'SELECT public_id FROM `' . $this->database->table( Schema::CHANNELS ) . '` WHERE public_id = %s FOR UPDATE', array( $item->channel_public_id ) ) ) {
					throw new StorageException( 'Destination could not be locked for batch handoff.' );
				}
				$locked = $this->items->lock_reserved_for_handoff( $item );
				if ( null === $locked ) {
					return;
				}

				$existing = $this->deliveries->find_by_intent_occurrence_key( $locked->intent_occurrence_key );
				if ( null !== $existing ) {
					if ( ! $this->existing_delivery_matches_review( $locked, $existing->delivery_key, $existing->payload_hash ) ) {
						$this->items->mark_terminal(
							$locked,
							'failed',
							$this->reason( 'batch_existing_delivery_changed', 'Existing local delivery for this occurrence does not match the reviewed batch payload.' )
						);
						return;
					}

					$this->mark_queued_or_fail(
						$locked,
						$existing->decision_public_id,
						$existing->public_id,
						null === $existing->scheduled_for_utc ? null : $existing->scheduled_for_utc->format( 'Y-m-d H:i:s' )
					);
					return;
				}

				if ( null === $locked->channel_public_id ) {
					$this->items->mark_terminal( $locked, 'policy_error', $this->reason( 'batch_item_has_no_destination', 'Reserved batch item has no destination channel.' ) );
					return;
				}

				if ( 'bulk' === $event->trigger && 'live' === ( $locked->preview_item['delivery']['delivery_mode'] ?? null ) && null !== $this->duplicates->blocking_bulk_duplicate( $locked->post_id, $locked->channel_public_id, $locked->batch_public_id, 'live', true ) ) {
					$this->items->mark_terminal( $locked, 'duplicate', $this->reason( 'duplicate_delivery', 'Existing live delivery or another confirmed reservation blocks this bulk destination item.' ) );
					return;
				}

				$runtime  = $this->planner->plan_runtime_destination( $event, $locked->channel_public_id, $schedule );
				$delivery = $this->matching_delivery( $runtime, $locked );
				if ( null === $delivery || ! isset( $delivery['stored_record'] ) || ! $delivery['stored_record'] instanceof DeliveryRecord ) {
					$this->items->mark_terminal( $locked, $this->terminal_state_from_result( $runtime ), $this->reason( 'batch_runtime_handoff_blocked', 'Runtime planner did not create a local delivery row for this batch item.' ) );
					return;
				}

				if ( ! $this->runtime_delivery_matches_review( $locked, $delivery ) ) {
					throw new StorageException( 'Runtime planning no longer matches the reviewed batch payload.' );
				}

				$record = $delivery['stored_record'];
				$this->mark_queued_or_fail(
					$locked,
					$record->decision_public_id,
					$record->public_id,
					null === $record->scheduled_for_utc ? null : $record->scheduled_for_utc->format( 'Y-m-d H:i:s' )
				);
			},
			array( Schema::CHANNELS, Schema::BATCHES, Schema::BATCH_ITEMS, Schema::DECISIONS, Schema::DELIVERIES )
		);
	}

	/**
	 * Mark a locked item queued or roll back the handoff transaction.
	 *
	 * @param BatchItemRecord $item Item.
	 * @param string          $decision_public_id Decision public ID.
	 * @param string          $delivery_public_id Delivery public ID.
	 * @param string|null     $scheduled_for_utc Scheduled UTC time.
	 */
	private function mark_queued_or_fail( BatchItemRecord $item, string $decision_public_id, string $delivery_public_id, ?string $scheduled_for_utc ): void {
		if ( null !== $this->items->mark_queued( $item, $decision_public_id, $delivery_public_id, $scheduled_for_utc ) ) {
			return;
		}

		throw new StorageException( 'Batch item changed before the delivery link could be stored.' );
	}

	/**
	 * Rehydrate the reviewed schedule stored in the batch preview.
	 *
	 * @param BatchItemRecord $item Item.
	 * @return SchedulePolicy
	 */
	private function reviewed_schedule( BatchItemRecord $item ): SchedulePolicy {
		$delivery = isset( $item->preview_item['delivery'] ) && is_array( $item->preview_item['delivery'] ) ? $item->preview_item['delivery'] : array();
		$payload  = isset( $delivery['payload_preview'] ) && is_array( $delivery['payload_preview'] ) ? $delivery['payload_preview'] : array();
		if ( array() === $payload ) {
			throw new StorageException( 'Reviewed delivery schedule is missing.' );
		}

		$slot = isset( $payload['schedule_slot'] ) && is_string( $payload['schedule_slot'] ) ? $payload['schedule_slot'] : 'immediate';
		StorageEnums::assert_schedule_slot( $slot );

		$scheduled_for_utc = null;
		if ( array_key_exists( 'scheduled_for_utc', $payload ) && null !== $payload['scheduled_for_utc'] && '' !== $payload['scheduled_for_utc'] ) {
			if ( ! is_string( $payload['scheduled_for_utc'] ) ) {
				throw new StorageException( 'Reviewed delivery schedule time is invalid.' );
			}

			StorageValidator::assert_utc_timestamp( 'Scheduled UTC time', $payload['scheduled_for_utc'] );
			$scheduled_for_utc = StorageValidator::parse_date_time( $payload['scheduled_for_utc'] );
		}

		if ( 'explicit_time' === $slot && null === $scheduled_for_utc ) {
			throw new StorageException( 'Reviewed explicit schedule is incomplete.' );
		}

		if ( 'explicit_time' !== $slot && null !== $scheduled_for_utc ) {
			throw new StorageException( 'Reviewed non-explicit schedule cannot carry a UTC time.' );
		}

		$timezone = null;
		if ( array_key_exists( 'schedule_timezone', $payload ) && null !== $payload['schedule_timezone'] && '' !== $payload['schedule_timezone'] ) {
			if ( ! is_string( $payload['schedule_timezone'] ) ) {
				throw new StorageException( 'Reviewed delivery schedule timezone is invalid.' );
			}

			$timezone = $payload['schedule_timezone'];
		}

		return new SchedulePolicy(
			$slot,
			$scheduled_for_utc,
			$timezone,
			array(
				'kind' => 'batch_review',
			)
		);
	}

	/**
	 * Build a current runtime event carrying the reviewed occurrence.
	 *
	 * @param BatchItemRecord $item Item.
	 * @return \PressQueue\Planning\NormalizedEvent|null
	 */
	private function runtime_event( BatchItemRecord $item ): ?\PressQueue\Planning\NormalizedEvent {
		$trigger = isset( $item->preview_item['event']['trigger'] ) && is_string( $item->preview_item['event']['trigger'] ) ? $item->preview_item['event']['trigger'] : '';
		$rules   = $this->rules->find_enabled_for_trigger( $trigger );

		return $this->normalizer->from_post_id(
			$item->post_id,
			'runtime',
			'batch_queue',
			$trigger,
			$this->normalizer->metadata_allowlist_for_rules( $rules ),
			$item->intent_occurrence_key
		);
	}

	/**
	 * Return the runtime delivery for this batch item, if any.
	 *
	 * @param PlanningResult  $result Planning result.
	 * @param BatchItemRecord $item Item.
	 * @return array<string,mixed>|null
	 */
	private function matching_delivery( PlanningResult $result, BatchItemRecord $item ): ?array {
		foreach ( $result->deliveries as $delivery ) {
			if ( ( $delivery['channel_public_id'] ?? null ) === $item->channel_public_id ) {
				return $delivery;
			}
		}

		return null;
	}

	/**
	 * Check whether a runtime delivery still matches the reviewed payload.
	 *
	 * @param BatchItemRecord     $item Item.
	 * @param array<string,mixed> $delivery Runtime delivery.
	 * @return bool
	 */
	private function runtime_delivery_matches_review( BatchItemRecord $item, array $delivery ): bool {
		$reviewed = isset( $item->preview_item['delivery'] ) && is_array( $item->preview_item['delivery'] ) ? $item->preview_item['delivery'] : array();

		return ( $reviewed['delivery_key'] ?? null ) === ( $delivery['delivery_key'] ?? null )
			&& ( $reviewed['payload_hash'] ?? null ) === ( $delivery['payload_hash'] ?? null );
	}

	/**
	 * Check whether an already-created occurrence row still matches review.
	 *
	 * @param BatchItemRecord $item Item.
	 * @param string          $delivery_key Delivery key.
	 * @param string          $payload_hash Payload hash.
	 * @return bool
	 */
	private function existing_delivery_matches_review( BatchItemRecord $item, string $delivery_key, string $payload_hash ): bool {
		$reviewed = isset( $item->preview_item['delivery'] ) && is_array( $item->preview_item['delivery'] ) ? $item->preview_item['delivery'] : array();

		return ( $reviewed['delivery_key'] ?? null ) === $delivery_key
			&& ( $reviewed['payload_hash'] ?? null ) === $payload_hash;
	}

	/**
	 * Choose a terminal state from current planner output.
	 *
	 * @param PlanningResult $result Planning result.
	 * @return string
	 */
	private function terminal_state_from_result( PlanningResult $result ): string {
		foreach ( $result->decisions as $decision ) {
			if ( 'excluded' === ( $decision['result'] ?? null ) ) {
				return 'excluded';
			}
		}

		return 'policy_error';
	}

	/**
	 * Build a stored reason document.
	 *
	 * @param string $code Code.
	 * @param string $message Message.
	 * @return array<string,mixed>
	 */
	private function reason( string $code, string $message ): array {
		return array(
			'code'           => $code,
			'message'        => $message,
			'schema_version' => 1,
		);
	}

	/**
	 * Fail remaining reserved items with the same reason.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $code Reason code.
	 * @param string $message Message.
	 */
	private function fail_reserved_items( string $batch_public_id, string $code, string $message ): void {
		do {
			$chunk = $this->items->find_reserved_chunk( $batch_public_id, BatchLimitService::QUEUE_CHUNK_SIZE );
			foreach ( $chunk as $item ) {
				$this->items->mark_terminal(
					$item,
					'failed',
					array(
						'code'           => $code,
						'message'        => $message,
						'schema_version' => 1,
					)
				);
			}
		} while ( array() !== $chunk );
	}

	/**
	 * Complete the batch if all reserved work is gone.
	 *
	 * @param string $batch_public_id Batch public ID.
	 */
	private function complete_if_no_reserved_items( string $batch_public_id ): void {
		$batch = $this->batches->find_by_public_id( $batch_public_id );
		if ( null === $batch || 'queueing' !== $batch->status || array() !== $this->items->find_reserved_chunk( $batch_public_id, 1 ) ) {
			return;
		}

		$this->batches->transition(
			$batch->public_id,
			$batch->lock_version,
			array( 'queueing' ),
			'handed_off',
			array( 'completed_at' => StorageValidator::now() )
		);
	}

	/**
	 * Check whether the confirmer still exists and has batch authority.
	 *
	 * @param int|null $user_id User ID.
	 * @return bool
	 */
	private function confirmer_has_authority( ?int $user_id ): bool {
		if ( null === $user_id || $user_id < 1 ) {
			return false;
		}

		if ( function_exists( 'get_user_by' ) && false === get_user_by( 'id', $user_id ) ) {
			return false;
		}

		if ( function_exists( 'user_can' ) ) {
			return user_can( $user_id, Capabilities::OPERATE_BATCHES );
		}

		return true;
	}
}
