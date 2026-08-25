<?php
/**
 * Delivery queue sweep.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor wires service dependencies.

use PressQueue\Provider\ProviderRegistry;
use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Storage\Database;
use PressQueue\Storage\DeliveryPayloadDocuments;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\DeliveryEventRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\DeliveryTransitionRepository;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Recovers and schedules durable delivery outbox rows.
 */
final class QueueSweep {
	private const BATCH_SIZE = 25;

	private DeliveryRepository $deliveries;
	private DeliveryTransitionRepository $transitions;
	private DeliveryEventRepository $events;
	private ConnectionRepository $connections;
	private ChannelRepository $channels;
	private ActionSchedulerQueue $scheduler;
	private SchemaStatus $schema_status;
	private ProviderRegistry $providers;

	/**
	 * Constructor.
	 *
	 * @param Database|null             $database Database wrapper.
	 * @param ActionSchedulerQueue|null $scheduler Scheduler queue.
	 * @param ProviderRegistry|null     $providers Provider registry.
	 */
	public function __construct( ?Database $database = null, ?ActionSchedulerQueue $scheduler = null, ?ProviderRegistry $providers = null ) {
		$database            = $database ?? Database::from_globals();
		$this->deliveries    = new DeliveryRepository( $database );
		$this->transitions   = new DeliveryTransitionRepository( $database );
		$this->events        = new DeliveryEventRepository( $database );
		$this->connections   = new ConnectionRepository( $database );
		$this->channels      = new ChannelRepository( $database );
		$this->scheduler     = $scheduler ?? new ActionSchedulerQueue();
		$this->schema_status = new SchemaStatus( $database );
		$this->providers     = $providers ?? ProviderRegistry::defaults();
	}

	/**
	 * Process due rows.
	 *
	 * @return int Number of rows attempted.
	 */
	public function run(): int {
		if ( ! $this->schema_status->is_ready_for_planning() ) {
			return 0;
		}

		$rows      = $this->deliveries->find_due_for_sweep( self::BATCH_SIZE );
		$attempted = 0;

		foreach ( $rows as $delivery ) {
			++$attempted;

			try {
				$this->process_delivery( $delivery );
			} catch ( Throwable $throwable ) {
				$this->record_repairable_failure( $delivery, $throwable );
			}
		}

		return $attempted;
	}

	/**
	 * Process one delivery row.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private function process_delivery( DeliveryRecord $delivery ): void {
		if ( ! $this->scheduler->is_ready() ) {
			$this->transitions->record_scheduler_unavailable(
				$delivery->public_id,
				array(
					'classification' => 'scheduler_not_ready',
					'schema_version' => 1,
				)
			);
			return;
		}

		if ( 'proposed' === $delivery->state ) {
			$this->process_proposed( $delivery );
			return;
		}

		if ( 'retrying' === $delivery->state ) {
			$queued = $this->transitions->queue_due_retry(
				$delivery->public_id,
				array(
					'classification' => 'retry_due',
					'schema_version' => 1,
				)
			);
			if ( null !== $queued ) {
				$this->schedule_worker( $queued );
			}
			return;
		}

		if ( 'queued' === $delivery->state ) {
			$this->process_queued( $delivery );
			return;
		}

		if ( 'leased' === $delivery->state ) {
			$this->process_expired_lease( $delivery );
		}
	}

	/**
	 * Validate, bind, queue, and schedule a proposed delivery.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private function process_proposed( DeliveryRecord $delivery ): void {
		$compatibility = $this->delivery_submission_compatibility( $delivery );
		if ( null !== $compatibility ) {
			$this->transitions->fail_proposed_preflight(
				$delivery->public_id,
				$compatibility['error_class'],
				$compatibility['retry_safety'],
				$compatibility['detail']
			);
			return;
		}

		$connection = $this->connections->find_by_public_id( $delivery->connection_public_id );
		$channel    = $this->channels->find_by_public_id( $delivery->channel_public_id );

		if ( ! $this->destination_allows_delivery( $delivery, $connection, $channel ) ) {
			$this->transitions->fail_destination_binding(
				$delivery->public_id,
				array(
					'classification' => 'destination_binding_failed',
					'schema_version' => 1,
				)
			);
			return;
		}

		if ( null === $connection || null === $channel ) {
			return;
		}

		if ( null === $delivery->bound_provider ) {
			$bound = $this->transitions->bind_destination(
				$delivery,
				$connection,
				$channel,
				array(
					'classification' => 'destination_bound',
					'schema_version' => 1,
				)
			);

			if ( null === $bound ) {
				return;
			}

			$delivery = $bound;
		} elseif ( ! $this->events->has_event_type( $delivery->public_id, 'destination_bound' ) ) {
			$this->transitions->record_destination_bound(
				$delivery->public_id,
				array(
					'classification' => 'destination_already_bound',
					'schema_version' => 1,
				)
			);
		}

		$queued = $this->transitions->queue_proposed(
			$delivery->public_id,
			array(
				'classification' => 'eligible_delivery_queued',
				'schema_version' => 1,
			)
		);

		if ( null !== $queued ) {
			$this->schedule_worker( $queued );
		}
	}

	/**
	 * Repair missing queued actions.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private function process_queued( DeliveryRecord $delivery ): void {
		if ( null !== $delivery->action_id && $this->scheduler->has_delivery_action( $delivery->public_id ) ) {
			return;
		}

		if ( null !== $delivery->action_id ) {
			$delivery = $this->transitions->clear_missing_action_id(
				$delivery->public_id,
				array(
					'classification' => 'missing_worker_action',
					'schema_version' => 1,
				)
			) ?? $delivery;
		}

		$this->schedule_worker( $delivery );
	}

	/**
	 * Recover expired leases according to the event ledger.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private function process_expired_lease( DeliveryRecord $delivery ): void {
		if ( $this->scheduler->is_action_claimed( $delivery->action_id ) ) {
			return;
		}

		$has_submission = $delivery->attempt_count > 0 && $this->events->has_submission_started( $delivery->public_id, $delivery->attempt_count );
		if ( $has_submission ) {
			$this->transitions->recover_lease_to_ambiguous(
				$delivery->public_id,
				array(
					'classification' => 'expired_lease_after_submission_started',
					'schema_version' => 1,
				)
			);
			return;
		}

		$queued = $this->transitions->recover_lease_to_queue(
			$delivery->public_id,
			array(
				'classification' => 'expired_lease_before_submission_started',
				'schema_version' => 1,
			)
		);

		if ( null !== $queued ) {
			$this->schedule_worker( $queued );
		}
	}

	/**
	 * Schedule a worker action and record the resulting action ID.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private function schedule_worker( DeliveryRecord $delivery ): void {
		try {
			$action_id = $this->scheduler->schedule_delivery( $delivery->public_id );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			$this->transitions->record_scheduler_enqueue_failed(
				$delivery->public_id,
				array(
					'classification' => 'scheduler_enqueue_failed',
					'schema_version' => 1,
				)
			);
			return;
		}

		$recorded = $this->transitions->record_action_id(
			$delivery->public_id,
			$action_id,
			array(
				'classification' => 'worker_action_scheduled',
				'schema_version' => 1,
			)
		);

		if ( ! $recorded ) {
			$this->scheduler->unschedule_delivery( $delivery->public_id );
			$this->transitions->record_scheduler_race_repaired(
				$delivery->public_id,
				$action_id,
				array(
					'classification' => 'cancel_or_state_race_after_enqueue',
					'schema_version' => 1,
				)
			);
		}
	}

	/**
	 * Check destination gates for proposed rows.
	 *
	 * @param DeliveryRecord        $delivery Delivery.
	 * @param ConnectionRecord|null $connection Connection.
	 * @param ChannelRecord|null    $channel Channel.
	 * @return bool
	 */
	private function destination_allows_delivery( DeliveryRecord $delivery, ?ConnectionRecord $connection, ?ChannelRecord $channel ): bool {
		if ( null === $connection || null === $channel ) {
			return false;
		}

		try {
			$this->providers->delivery_provider( $delivery->provider );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return false;
		}

		try {
			$facts = $this->schema_two_facts( $delivery );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return false;
		}

		$capability_ok = DeliveryCapability::supports_payload( $channel->capabilities, $delivery->schedule_slot, $facts['final_encoded_text_length'], $facts['media_snapshot'] );

		$current_ok = 'live' === $delivery->delivery_mode
			&& $delivery->provider === $connection->provider
			&& $delivery->provider === $channel->provider
			&& $delivery->connection_public_id === $connection->public_id
			&& $delivery->channel_public_id === $channel->public_id
			&& $channel->connection_public_id === $connection->public_id
			&& $delivery->remote_channel_id === $channel->remote_channel_id
			&& $connection->enabled
			&& 'verified' === $connection->connection_status
			&& $connection->has_credential
			&& null !== $connection->credential_envelope_hash
			&& null !== $connection->remote_account_id
			&& null !== $connection->remote_organization_id
			&& null !== $connection->verified_identity_hash
			&& null !== $connection->last_sync_completed_at
			&& $channel->enabled
			&& 'available' === $channel->availability
			&& $channel->remote_organization_id === $connection->remote_organization_id
			&& $capability_ok;

		if ( ! $current_ok ) {
			return false;
		}

		if ( null === $delivery->bound_provider ) {
			return true;
		}

		return $delivery->bound_provider === $delivery->provider
			&& $delivery->bound_connection_public_id === $connection->public_id
			&& $delivery->bound_channel_public_id === $channel->public_id
			&& $delivery->bound_remote_account_id === $connection->remote_account_id
			&& $delivery->bound_remote_organization_id === $connection->remote_organization_id
			&& $delivery->bound_remote_channel_id === $channel->remote_channel_id
			&& (int) ( $channel->capabilities['schema_version'] ?? 0 ) === $delivery->bound_capability_schema_version
			&& DeliveryCapability::supports_payload( $channel->capabilities, $delivery->schedule_slot, $facts['final_encoded_text_length'], $facts['media_snapshot'] );
	}

	/**
	 * Return a fail-closed compatibility issue before queueing.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return array{error_class:string,retry_safety:string,detail:array<string,mixed>}|null
	 */
	private function delivery_submission_compatibility( DeliveryRecord $delivery ): ?array {
		if ( 1 === $delivery->payload_schema_version && 1 === $delivery->adapter_encoding_version ) {
			return array(
				'detail'       => array(
					'classification' => 'legacy_encoder_retired',
					'schema_version' => 1,
				),
				'error_class'  => 'legacy_encoder_retired',
				'retry_safety' => 'not_retryable',
			);
		}

		if ( 2 !== $delivery->payload_schema_version ) {
			return array(
				'detail'       => array(
					'classification' => 'unsupported_payload_version',
					'schema_version' => 1,
				),
				'error_class'  => 'unsupported_payload_version',
				'retry_safety' => 'not_retryable',
			);
		}

		if ( 2 !== $delivery->adapter_encoding_version ) {
			return array(
				'detail'       => array(
					'classification' => 'unsupported_encoder_version',
					'schema_version' => 1,
				),
				'error_class'  => 'unsupported_encoder_version',
				'retry_safety' => 'not_retryable',
			);
		}

		try {
			$this->schema_two_facts( $delivery );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return array(
				'detail'       => array(
					'classification' => 'payload_preview_invalid',
					'schema_version' => 1,
				),
				'error_class'  => 'payload_preview_invalid',
				'retry_safety' => 'not_retryable',
			);
		}

		return null;
	}

	/**
	 * Validate schema version 2 payload facts.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return array{final_encoded_text:string,final_encoded_text_length:int,media_identity_hash:string|null,media_snapshot:array<string,mixed>,scheduled_for_utc:string|null,schedule_timezone:string|null}
	 */
	private function schema_two_facts( DeliveryRecord $delivery ): array {
		return DeliveryPayloadDocuments::schema_two_facts(
			$delivery->payload_preview,
			$delivery->schedule_slot,
			null === $delivery->scheduled_for_utc ? null : $delivery->scheduled_for_utc->format( 'Y-m-d H:i:s' ),
			$delivery->schedule_timezone,
			$delivery->media_identity_hash,
			$delivery->bound_capability_hash
		);
	}

	/**
	 * Append a redacted operational failure without throwing out of the sweep.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param Throwable      $throwable Failure.
	 */
	private function record_repairable_failure( DeliveryRecord $delivery, Throwable $throwable ): void {
		unset( $throwable );

		try {
			$this->transitions->record_scheduler_enqueue_failed(
				$delivery->public_id,
				array(
					'classification' => 'queue_sweep_exception',
					'schema_version' => 1,
				)
			);
		} catch ( StorageException $exception ) {
			unset( $exception );
		}
	}
}
