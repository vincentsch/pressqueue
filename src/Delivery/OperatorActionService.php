<?php
/**
 * Operator delivery actions.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor wires service dependencies.

use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\CredentialEnvelope;
use PressQueue\Storage\Database;
use PressQueue\Storage\DeliveryPayloadDocuments;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\DeliveryTransitionRepository;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Applies safe local operator actions without provider mutation.
 */
final class OperatorActionService {
	private DeliveryRepository $deliveries;
	private DeliveryTransitionRepository $transitions;
	private ConnectionRepository $connections;
	private ChannelRepository $channels;
	private SettingsRepository $settings;
	private ActionSchedulerQueue $scheduler;
	private CredentialEnvelope $credential_envelope;

	/**
	 * Optional credential checker.
	 *
	 * @var callable|null
	 */
	private $credential_checker;

	/**
	 * Constructor.
	 *
	 * @param Database|null             $database Database.
	 * @param ActionSchedulerQueue|null $scheduler Scheduler.
	 * @param callable|null             $credential_checker Optional credential checker for tests.
	 */
	public function __construct( ?Database $database = null, ?ActionSchedulerQueue $scheduler = null, ?callable $credential_checker = null ) {
		$database                  = $database ?? Database::from_globals();
		$this->deliveries          = new DeliveryRepository( $database );
		$this->transitions         = new DeliveryTransitionRepository( $database );
		$this->connections         = new ConnectionRepository( $database );
		$this->channels            = new ChannelRepository( $database );
		$this->settings            = new SettingsRepository();
		$this->scheduler           = $scheduler ?? new ActionSchedulerQueue();
		$this->credential_envelope = new CredentialEnvelope();
		$this->credential_checker  = $credential_checker;
	}

	/**
	 * Cancel pending or failed local work.
	 *
	 * @param string   $delivery_public_id Delivery public ID.
	 * @param int|null $actor_user_id Actor user ID.
	 * @return DeliveryRecord
	 */
	public function cancel( string $delivery_public_id, ?int $actor_user_id ): DeliveryRecord {
		$delivery = $this->must_find( $delivery_public_id );

		if ( ! in_array( $delivery->state, array( 'proposed', 'held', 'queued', 'retrying', 'failed' ), true ) ) {
			$this->refuse( $delivery, $actor_user_id, 'cancel_not_allowed' );
		}

		$updated = $this->transitions->cancel_not_leased(
			$delivery->public_id,
			$actor_user_id,
			array(
				'classification' => 'operator_cancelled_local_delivery',
				'schema_version' => 1,
			)
		);

		if ( null === $updated ) {
			$this->refuse( $delivery, $actor_user_id, 'cancel_state_changed' );
		}

		$this->scheduler->unschedule_delivery( $delivery->public_id );

		return $updated;
	}

	/**
	 * Resume a held delivery through the existing worker queue.
	 *
	 * @param string   $delivery_public_id Delivery public ID.
	 * @param int|null $actor_user_id Actor user ID.
	 * @return DeliveryRecord
	 */
	public function resume( string $delivery_public_id, ?int $actor_user_id ): DeliveryRecord {
		$delivery = $this->must_find( $delivery_public_id );

		if ( 'held' !== $delivery->state ) {
			$this->refuse( $delivery, $actor_user_id, 'resume_not_allowed' );
		}

		$this->assert_queue_gates_open( $delivery, $actor_user_id, 'resume' );

		$updated = $this->transitions->resume_held(
			$delivery->public_id,
			$actor_user_id,
			array(
				'classification' => 'operator_resumed_delivery',
				'schema_version' => 1,
			)
		);

		if ( null === $updated ) {
			$this->refuse( $delivery, $actor_user_id, 'resume_state_changed' );
		}

		$this->schedule_worker( $updated, 'resume_action_scheduled' );

		return $updated;
	}

	/**
	 * Manually retry an eligible failed or reconciled ambiguous delivery.
	 *
	 * @param string   $delivery_public_id Delivery public ID.
	 * @param int|null $actor_user_id Actor user ID.
	 * @return DeliveryRecord
	 */
	public function retry( string $delivery_public_id, ?int $actor_user_id ): DeliveryRecord {
		$delivery = $this->must_find( $delivery_public_id );
		$updated  = null;

		if ( 'failed' === $delivery->state && 'manual_allowed' === $delivery->retry_safety ) {
			$this->assert_queue_gates_open( $delivery, $actor_user_id, 'manual_retry' );
			$updated = $this->transitions->manual_retry_failed(
				$delivery->public_id,
				$actor_user_id,
				array(
					'classification' => 'operator_manual_retry',
					'schema_version' => 1,
				)
			);
		} elseif ( 'ambiguous' === $delivery->state && 'confirmed_not_accepted' === $delivery->reconciliation_status && 'manual_allowed' === $delivery->retry_safety ) {
			$this->assert_queue_gates_open( $delivery, $actor_user_id, 'manual_retry' );
			$updated = $this->transitions->manual_retry_confirmed_not_accepted(
				$delivery->public_id,
				$actor_user_id,
				array(
					'classification' => 'operator_manual_retry_after_not_accepted',
					'schema_version' => 1,
				)
			);
		} else {
			$this->refuse( $delivery, $actor_user_id, 'manual_retry_not_allowed' );
		}

		if ( null === $updated ) {
			$this->refuse( $delivery, $actor_user_id, 'manual_retry_state_changed' );
		}

		$this->schedule_worker( $updated, 'manual_retry_action_scheduled' );

		return $updated;
	}

	/**
	 * Load a delivery or fail.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return DeliveryRecord
	 */
	private function must_find( string $delivery_public_id ): DeliveryRecord {
		$delivery = $this->deliveries->find_by_public_id( $delivery_public_id );

		if ( null === $delivery ) {
			throw new ValidationException( 'Delivery does not exist.' );
		}

		return $delivery;
	}

	/**
	 * Require all mutable gates before queueing operator work.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param int|null       $actor_user_id Actor user ID.
	 * @param string         $action Operator action.
	 */
	private function assert_queue_gates_open( DeliveryRecord $delivery, ?int $actor_user_id, string $action ): void {
		if ( $this->settings->is_kill_switch_enabled() ) {
			$this->refuse( $delivery, $actor_user_id, $action . '_kill_switch_enabled' );
		}

		if ( 'live' !== $delivery->delivery_mode || ! $this->has_bound_identity( $delivery ) ) {
			$this->refuse( $delivery, $actor_user_id, $action . '_immutable_delivery_incompatible' );
		}

		$compatibility = $this->submission_compatibility( $delivery );
		if ( null !== $compatibility ) {
			$this->refuse( $delivery, $actor_user_id, $compatibility );
		}

		$connection = $this->connections->find_by_public_id( (string) $delivery->bound_connection_public_id );
		$channel    = $this->channels->find_by_public_id( (string) $delivery->bound_channel_public_id );

		if ( null === $connection || null === $channel || ! $this->destination_matches_bound_identity( $delivery, $connection, $channel ) ) {
			$this->refuse( $delivery, $actor_user_id, 'capability_mismatch' );
		}

		if ( null === $connection || ! $this->credential_is_decryptable( $connection ) ) {
			$this->refuse( $delivery, $actor_user_id, $action . '_credential_unavailable' );
		}
	}

	/**
	 * Schedule worker action after a durable queue transition.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param string         $classification Event classification.
	 */
	private function schedule_worker( DeliveryRecord $delivery, string $classification ): void {
		try {
			$action_id = $this->scheduler->schedule_delivery( $delivery->public_id );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			$this->transitions->record_scheduler_enqueue_failed(
				$delivery->public_id,
				array(
					'classification' => 'operator_scheduler_enqueue_failed',
					'schema_version' => 1,
				)
			);
			return;
		}

		if ( ! $this->transitions->record_action_id(
			$delivery->public_id,
			$action_id,
			array(
				'classification' => $classification,
				'schema_version' => 1,
			)
		) ) {
			$this->scheduler->unschedule_delivery( $delivery->public_id );
			$this->transitions->record_scheduler_race_repaired(
				$delivery->public_id,
				$action_id,
				array(
					'classification' => 'operator_state_race_after_enqueue',
					'schema_version' => 1,
				)
			);
		}
	}

	/**
	 * Check whether immutable identity is complete.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return bool
	 */
	private function has_bound_identity( DeliveryRecord $delivery ): bool {
		return null !== $delivery->bound_provider
			&& null !== $delivery->bound_connection_public_id
			&& null !== $delivery->bound_channel_public_id
			&& null !== $delivery->bound_remote_account_id
			&& null !== $delivery->bound_remote_organization_id
			&& null !== $delivery->bound_remote_channel_id
			&& null !== $delivery->bound_capability_schema_version;
	}

	/**
	 * Return a fail-closed submission compatibility error, if any.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return string|null
	 */
	private function submission_compatibility( DeliveryRecord $delivery ): ?string {
		if ( 1 === $delivery->payload_schema_version && 1 === $delivery->adapter_encoding_version ) {
			return 'legacy_encoder_retired';
		}

		if ( 2 !== $delivery->payload_schema_version ) {
			return 'unsupported_payload_version';
		}

		if ( 2 !== $delivery->adapter_encoding_version ) {
			return 'unsupported_encoder_version';
		}

		try {
			$this->schema_two_facts( $delivery );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return 'payload_preview_invalid';
		}

		return null;
	}

	/**
	 * Recheck current destination records against immutable delivery identity.
	 *
	 * @param DeliveryRecord   $delivery Delivery.
	 * @param ConnectionRecord $connection Connection.
	 * @param ChannelRecord    $channel Channel.
	 * @return bool
	 */
	private function destination_matches_bound_identity( DeliveryRecord $delivery, ConnectionRecord $connection, ChannelRecord $channel ): bool {
		try {
			$facts = $this->schema_two_facts( $delivery );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return false;
		}

		return $connection->enabled
			&& 'verified' === $connection->connection_status
			&& $connection->has_credential
			&& null !== $connection->credential_envelope_hash
			&& null !== $connection->remote_account_id
			&& null !== $connection->remote_organization_id
			&& null !== $connection->verified_identity_hash
			&& null !== $connection->last_sync_completed_at
			&& $channel->enabled
			&& 'available' === $channel->availability
			&& $channel->connection_public_id === $connection->public_id
			&& $delivery->bound_provider === $connection->provider
			&& $delivery->bound_provider === $channel->provider
			&& $delivery->bound_connection_public_id === $connection->public_id
			&& $delivery->bound_channel_public_id === $channel->public_id
			&& $delivery->bound_remote_account_id === $connection->remote_account_id
			&& $delivery->bound_remote_organization_id === $connection->remote_organization_id
			&& $delivery->bound_remote_organization_id === $channel->remote_organization_id
			&& $delivery->bound_remote_channel_id === $channel->remote_channel_id
			&& 2 === $delivery->bound_capability_schema_version
			&& 2 === (int) ( $channel->capabilities['schema_version'] ?? 0 )
			&& DeliveryCapability::supports_payload( $channel->capabilities, $delivery->schedule_slot, $facts['final_encoded_text_length'], $facts['media_snapshot'] );
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
	 * Check whether the current credential can still be decrypted.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @return bool
	 */
	private function credential_is_decryptable( ConnectionRecord $connection ): bool {
		try {
			if ( null !== $this->credential_checker ) {
				return (bool) call_user_func( $this->credential_checker, $connection );
			}

			$json = $this->connections->credential_envelope_json( $connection->public_id );
			if ( null === $json || '' === $json ) {
				return false;
			}

			$credential = $this->credential_envelope->decrypt( $connection->provider, $connection->public_id, $this->credential_envelope->decode_from_storage( $json ) );
			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $credential );
			}

			unset( $credential );

			return true;
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return false;
		}
	}

	/**
	 * Record an authorized refused transition and throw a safe validation error.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param int|null       $actor_user_id Actor user ID.
	 * @param string         $classification Redacted classification.
	 * @throws StorageException When the event ledger cannot be written.
	 * @throws ValidationException Always throws.
	 */
	private function refuse( DeliveryRecord $delivery, ?int $actor_user_id, string $classification ): void {
		try {
			$this->transitions->record_refused_transition(
				$delivery->public_id,
				array(
					'classification' => $classification,
					'schema_version' => 1,
				),
				$actor_user_id
			);
		} catch ( StorageException $exception ) {
			throw $exception;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Classification is a redacted internal enum, not rendered directly.
		throw new ValidationException( 'Delivery transition refused: ' . $classification . '.' );
	}
}
