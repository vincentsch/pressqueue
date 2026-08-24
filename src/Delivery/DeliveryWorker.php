<?php
/**
 * Delivery worker.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor wires service dependencies.

use PressQueue\Provider\DeliveryProvider;
use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Provider\Dto\ProviderDeliveryResult;
use PressQueue\Provider\ProviderRegistry;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\CredentialEnvelope;
use PressQueue\Security\CredentialEnvelopeException;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\DeliveryTransitionRepository;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Executes one queued delivery.
 */
final class DeliveryWorker {
	private DeliveryRepository $deliveries;
	private DeliveryTransitionRepository $transitions;
	private ConnectionRepository $connections;
	private ChannelRepository $channels;
	private SettingsRepository $settings;
	private ActionSchedulerQueue $scheduler;
	private SchemaStatus $schema_status;
	private ProviderRegistry $providers;
	private CredentialEnvelope $credential_envelope;
	private LeaseService $leases;
	private RetryPolicy $retry_policy;
	private ResultClassifier $classifier;

	/**
	 * Optional credential resolver.
	 *
	 * @var callable|null
	 */
	private $credential_resolver;

	/**
	 * Constructor.
	 *
	 * @param Database|null             $database Database wrapper.
	 * @param ProviderRegistry|null     $providers Provider registry.
	 * @param ActionSchedulerQueue|null $scheduler Scheduler queue.
	 * @param callable|null             $credential_resolver Test credential resolver.
	 */
	public function __construct( ?Database $database = null, ?ProviderRegistry $providers = null, ?ActionSchedulerQueue $scheduler = null, ?callable $credential_resolver = null ) {
		$database                  = $database ?? Database::from_globals();
		$this->deliveries          = new DeliveryRepository( $database );
		$this->transitions         = new DeliveryTransitionRepository( $database );
		$this->connections         = new ConnectionRepository( $database );
		$this->channels            = new ChannelRepository( $database );
		$this->settings            = new SettingsRepository();
		$this->scheduler           = $scheduler ?? new ActionSchedulerQueue();
		$this->schema_status       = new SchemaStatus( $database );
		$this->providers           = $providers ?? ProviderRegistry::defaults();
		$this->credential_envelope = new CredentialEnvelope();
		$this->leases              = new LeaseService();
		$this->retry_policy        = new RetryPolicy();
		$this->classifier          = new ResultClassifier();
		$this->credential_resolver = $credential_resolver;
	}

	/**
	 * Run a worker for one delivery public ID.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 */
	public function run( string $delivery_public_id ): void {
		if ( ! $this->schema_status->is_ready_for_planning() ) {
			return;
		}

		$delivery = $this->deliveries->find_by_public_id( $delivery_public_id );
		if ( null === $delivery || ! in_array( $delivery->state, array( 'queued', 'retrying' ), true ) ) {
			return;
		}

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

		if ( 'retrying' === $delivery->state ) {
			if ( null === $delivery->next_attempt_at || $delivery->next_attempt_at->getTimestamp() > time() ) {
				return;
			}

			$delivery = $this->transitions->queue_due_retry(
				$delivery->public_id,
				array(
					'classification' => 'retry_worker_due',
					'schema_version' => 1,
				),
				'worker'
			);

			if ( null === $delivery ) {
				return;
			}
		}

		$owner  = $this->leases->owner();
		$leased = $this->transitions->acquire_lease( $delivery->public_id, $owner, $this->leases->expires_at() );

		if ( null === $leased ) {
			return;
		}

		$submission_started = false;
		$request            = null;

		try {
			$preflight = $this->preflight( $leased );

			if ( $preflight['hold'] ) {
				$this->transitions->hold_leased(
					$leased->public_id,
					$owner,
					array(
						'classification' => 'kill_switch_enabled',
						'schema_version' => 1,
					)
				);
				return;
			}

			if ( ! $preflight['ok'] ) {
				$this->transitions->fail_leased_preflight(
					$leased->public_id,
					$owner,
					$preflight['error_class'],
					$preflight['retry_safety'],
					$preflight['detail']
				);
				return;
			}

			$started = $this->transitions->submission_started( $leased->public_id, $owner, true );
			if ( null === $started ) {
				return;
			}

			$submission_started = true;
			$request            = $this->request_from_delivery( $started );
			$result             = $preflight['provider']->submit( $request, $preflight['credential'] );
			$this->store_result( $started, $owner, $result );
		} catch ( Throwable $throwable ) {
			$this->handle_exception( $leased, $owner, $request, $submission_started, $throwable );
		}
	}

	/**
	 * Recheck mutable gates while the delivery lease is held.
	 *
	 * @param DeliveryRecord $delivery Leased delivery.
	 * @return array<string,mixed>
	 */
	private function preflight( DeliveryRecord $delivery ): array {
		if ( $this->settings->is_kill_switch_enabled() ) {
			return array(
				'hold' => true,
				'ok'   => false,
			);
		}

		if ( ! $this->has_bound_identity( $delivery ) ) {
			return $this->preflight_failure( 'immutable_identity_missing', 'not_retryable' );
		}

		if ( 1 !== $delivery->payload_schema_version ) {
			return $this->preflight_failure( 'unsupported_payload_schema_version', 'not_retryable' );
		}

		if ( 1 !== $delivery->adapter_encoding_version ) {
			return $this->preflight_failure( 'unsupported_adapter_encoding_version', 'not_retryable' );
		}

		if ( ! isset( $delivery->payload_preview['message'], $delivery->payload_preview['canonical_url'] ) || ! is_string( $delivery->payload_preview['message'] ) || ! is_string( $delivery->payload_preview['canonical_url'] ) ) {
			return $this->preflight_failure( 'payload_preview_invalid', 'not_retryable' );
		}

		$connection = $this->connections->find_by_public_id( (string) $delivery->bound_connection_public_id );
		$channel    = $this->channels->find_by_public_id( (string) $delivery->bound_channel_public_id );

		if ( null === $connection || null === $channel || ! $this->destination_matches_bound_identity( $delivery, $connection, $channel ) ) {
			return $this->preflight_failure( 'destination_identity_mismatch', 'manual_allowed' );
		}

		try {
			$provider = $this->providers->delivery_provider( (string) $delivery->bound_provider );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return $this->preflight_failure( 'provider_not_registered', 'not_retryable' );
		}

		try {
			$credential = $this->credential_for_connection( $connection );
		} catch ( CredentialEnvelopeException | StorageException | ValidationException $exception ) {
			unset( $exception );

			return $this->preflight_failure( 'credential_unavailable', 'not_retryable' );
		}

		return array(
			'credential' => $credential,
			'hold'       => false,
			'ok'         => true,
			'provider'   => $provider,
		);
	}

	/**
	 * Store a provider result and schedule an allowed retry.
	 *
	 * @param DeliveryRecord         $delivery Delivery after submission_started.
	 * @param string                 $owner Lease owner.
	 * @param ProviderDeliveryResult $result Result.
	 */
	private function store_result( DeliveryRecord $delivery, string $owner, ProviderDeliveryResult $result ): void {
		if ( 'invalid_credential' === $result->error_class && null !== $delivery->bound_connection_public_id ) {
			$this->connections->mark_credential_invalid(
				$delivery->bound_connection_public_id,
				array(
					'classification' => 'invalid_credential',
					'schema_version' => 1,
				)
			);
		}

		$next_attempt_at  = $this->retry_policy->next_attempt_at( $delivery, $result );
		$budget_exhausted = in_array( $result->provider_result, array( 'retryable_failure', 'rate_limited' ), true )
			&& null === $next_attempt_at;

		$updated = $this->transitions->complete_provider_result( $delivery->public_id, $owner, $result, $next_attempt_at, $budget_exhausted );

		if ( null !== $updated && 'retrying' === $updated->state && null !== $updated->next_attempt_at ) {
			$this->schedule_retry( $updated );
		}
	}

	/**
	 * Schedule a retry worker outside the transition transaction.
	 *
	 * @param DeliveryRecord $delivery Retrying delivery.
	 */
	private function schedule_retry( DeliveryRecord $delivery ): void {
		$timestamp = $delivery->next_attempt_at ? $delivery->next_attempt_at->getTimestamp() : time();

		try {
			$action_id = $this->scheduler->schedule_delivery( $delivery->public_id, $timestamp );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			$this->transitions->record_scheduler_enqueue_failed(
				$delivery->public_id,
				array(
					'classification' => 'retry_enqueue_failed',
					'schema_version' => 1,
				)
			);
			return;
		}

		if ( ! $this->transitions->record_action_id(
			$delivery->public_id,
			$action_id,
			array(
				'classification' => 'retry_action_scheduled',
				'schema_version' => 1,
			)
		) ) {
			$this->scheduler->unschedule_delivery( $delivery->public_id );
			$this->transitions->record_scheduler_race_repaired(
				$delivery->public_id,
				$action_id,
				array(
					'classification' => 'retry_state_race_after_enqueue',
					'schema_version' => 1,
				)
			);
		}
	}

	/**
	 * Convert unexpected worker exceptions into fail-closed delivery state.
	 *
	 * @param DeliveryRecord               $leased Leased delivery.
	 * @param string                       $owner Lease owner.
	 * @param ProviderDeliveryRequest|null $request Request if available.
	 * @param bool                         $submission_started Whether submission started.
	 * @param Throwable                    $throwable Failure.
	 */
	private function handle_exception( DeliveryRecord $leased, string $owner, ?ProviderDeliveryRequest $request, bool $submission_started, Throwable $throwable ): void {
		if ( null === $request ) {
			$this->transitions->fail_leased_preflight(
				$leased->public_id,
				$owner,
				'worker_exception_before_submission_started',
				'automatic_allowed',
				array(
					'classification' => 'worker_exception_before_submission_started',
					'schema_version' => 1,
				)
			);
			return;
		}

		$result = $this->classifier->worker_exception( $request, $throwable, $submission_started );
		$this->store_result( $leased, $owner, $result );
	}

	/**
	 * Build a provider request from immutable delivery storage.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return ProviderDeliveryRequest
	 */
	private function request_from_delivery( DeliveryRecord $delivery ): ProviderDeliveryRequest {
		if ( ! $this->has_bound_identity( $delivery ) ) {
			throw new ValidationException( 'Delivery bound identity is incomplete.' );
		}

		return new ProviderDeliveryRequest(
			(string) $delivery->bound_provider,
			$delivery->public_id,
			$delivery->delivery_key,
			$delivery->attempt_count,
			(string) $delivery->bound_connection_public_id,
			(string) $delivery->bound_remote_account_id,
			(string) $delivery->bound_remote_organization_id,
			(string) $delivery->bound_remote_channel_id,
			$delivery->payload_schema_version,
			$delivery->adapter_encoding_version,
			(string) $delivery->payload_preview['message'],
			(string) $delivery->payload_preview['canonical_url'],
			array(
				'delivery_public_id' => $delivery->public_id,
				'provider'           => $delivery->bound_provider,
			)
		);
	}

	/**
	 * Resolve a credential for submission.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @return string
	 */
	private function credential_for_connection( ConnectionRecord $connection ): string {
		$envelope_json = $this->connections->credential_envelope_json( $connection->public_id );
		if ( null === $envelope_json || '' === $envelope_json ) {
			throw new StorageException( 'Connection credential envelope is missing.' );
		}

		if ( null !== $this->credential_resolver ) {
			return (string) call_user_func( $this->credential_resolver, $connection, $envelope_json );
		}

		$envelope = $this->credential_envelope->decode_from_storage( $envelope_json );

		return $this->credential_envelope->decrypt( $connection->provider, $connection->public_id, $envelope );
	}

	/**
	 * Check whether a delivery has all bound identity fields.
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
	 * Check mutable destination gates against the immutable binding.
	 *
	 * @param DeliveryRecord   $delivery Delivery.
	 * @param ConnectionRecord $connection Connection.
	 * @param ChannelRecord    $channel Channel.
	 * @return bool
	 */
	private function destination_matches_bound_identity( DeliveryRecord $delivery, ConnectionRecord $connection, ChannelRecord $channel ): bool {
		$delivery_capability = $channel->capabilities['delivery'] ?? null;

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
			&& (int) ( $channel->capabilities['schema_version'] ?? 0 ) === $delivery->bound_capability_schema_version
			&& is_array( $delivery_capability )
			&& true === ( $delivery_capability['text'] ?? false )
			&& isset( $delivery_capability['schedule_slots'] )
			&& is_array( $delivery_capability['schedule_slots'] )
			&& in_array( 'immediate', $delivery_capability['schedule_slots'], true );
	}

	/**
	 * Build a preflight failure response.
	 *
	 * @param string $error_class Error class.
	 * @param string $retry_safety Retry safety.
	 * @return array<string,mixed>
	 */
	private function preflight_failure( string $error_class, string $retry_safety ): array {
		return array(
			'detail'       => array(
				'classification' => $error_class,
				'schema_version' => 1,
			),
			'error_class'  => $error_class,
			'hold'         => false,
			'ok'           => false,
			'retry_safety' => $retry_safety,
		);
	}
}
