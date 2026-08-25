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
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\BatchItemRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\SchemaStatus;
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

		if ( ! ( new SchemaStatus() )->is_ready_for_planning() ) {
			return;
		}

		$service = new self();
		foreach ( $service->batches->find_queueing() as $batch ) {
			$service->ensure_queue_action( $batch->public_id, $batch->preview_snapshot_hash );
		}
	}

	/**
	 * Ensure a queue action exists for a batch.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Snapshot hash.
	 */
	public function ensure_queue_action( string $batch_public_id, string $preview_snapshot_hash ): void {
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
			$existing = $this->deliveries->find_by_intent_occurrence_key( $item->intent_occurrence_key );
			if ( null !== $existing ) {
				if ( ! $this->existing_delivery_matches_review( $item, $existing->delivery_key, $existing->payload_hash ) ) {
					$this->items->mark_terminal(
						$item,
						'failed',
						$this->reason( 'batch_existing_delivery_changed', 'Existing local delivery for this occurrence does not match the reviewed batch payload.' )
					);
					return;
				}

				$this->items->mark_queued( $item, $existing->decision_public_id, $existing->public_id, null === $existing->scheduled_for_utc ? null : $existing->scheduled_for_utc->format( 'Y-m-d H:i:s' ) );
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

			$gate             = $this->planner->preview_runtime_destination( $event, $item->channel_public_id );
			$reviewed_preview = $this->matching_delivery( $gate, $item );

			if ( null === $reviewed_preview ) {
				$this->items->mark_terminal( $item, $this->terminal_state_from_result( $gate ), $this->reason( 'batch_runtime_gate_blocked', 'Current runtime policy no longer allows this batch item.' ) );
				return;
			}

			if ( ! $this->runtime_delivery_matches_review( $item, $reviewed_preview ) ) {
				$this->items->mark_terminal( $item, 'failed', $this->reason( 'batch_runtime_preview_changed', 'Current runtime planning no longer matches the reviewed batch payload.' ) );
				return;
			}

			$runtime  = $this->planner->plan_runtime_destination( $event, $item->channel_public_id );
			$delivery = $this->matching_delivery( $runtime, $item );
			if ( null === $delivery || ! isset( $delivery['stored_record'] ) || ! $delivery['stored_record'] instanceof DeliveryRecord ) {
				$this->items->mark_terminal( $item, $this->terminal_state_from_result( $runtime ), $this->reason( 'batch_runtime_handoff_blocked', 'Runtime planner did not create a local delivery row for this batch item.' ) );
				return;
			}

			$record = $delivery['stored_record'];
			$this->items->mark_queued( $item, $record->decision_public_id, $record->public_id, null === $record->scheduled_for_utc ? null : $record->scheduled_for_utc->format( 'Y-m-d H:i:s' ) );
		} catch ( Throwable $throwable ) {
			$existing = $this->deliveries->find_by_intent_occurrence_key( $item->intent_occurrence_key );
			if ( null !== $existing ) {
				if ( ! $this->existing_delivery_matches_review( $item, $existing->delivery_key, $existing->payload_hash ) ) {
					$this->items->mark_terminal(
						$item,
						'failed',
						$this->reason( 'batch_existing_delivery_changed', 'Existing local delivery for this occurrence does not match the reviewed batch payload.' )
					);
					return;
				}

				$this->items->mark_queued( $item, $existing->decision_public_id, $existing->public_id, null === $existing->scheduled_for_utc ? null : $existing->scheduled_for_utc->format( 'Y-m-d H:i:s' ) );
				return;
			}

			$this->items->mark_terminal(
				$item,
				'failed',
				array(
					'code'           => 'batch_handoff_failed',
					'message'        => $throwable->getMessage(),
					'schema_version' => 1,
				)
			);
		}
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
