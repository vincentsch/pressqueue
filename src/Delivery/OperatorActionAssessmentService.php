<?php
/**
 * Zero-write delivery action assessments.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Security\CredentialEnvelope;
use PressQueue\Storage\Database;
use PressQueue\Storage\DeliveryPayloadDocuments;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Evaluates delivery operator actions without side effects.
 */
final class OperatorActionAssessmentService {
	/**
	 * Delivery repository.
	 *
	 * @var DeliveryRepository
	 */
	private DeliveryRepository $deliveries;

	/**
	 * Connection repository.
	 *
	 * @var ConnectionRepository
	 */
	private ConnectionRepository $connections;

	/**
	 * Channel repository.
	 *
	 * @var ChannelRepository
	 */
	private ChannelRepository $channels;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Credential envelope service.
	 *
	 * @var CredentialEnvelope
	 */
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
	 * @param Database|null $database Database.
	 * @param callable|null $credential_checker Optional credential checker for tests.
	 */
	public function __construct( ?Database $database = null, ?callable $credential_checker = null ) {
		$database                  = $database ?? Database::from_globals();
		$this->deliveries          = new DeliveryRepository( $database );
		$this->connections         = new ConnectionRepository( $database );
		$this->channels            = new ChannelRepository( $database );
		$this->settings            = new SettingsRepository();
		$this->credential_envelope = new CredentialEnvelope();
		$this->credential_checker  = $credential_checker;
	}

	/**
	 * Assess a cancellation.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return OperatorActionAssessment
	 */
	public function assess_cancel( string $delivery_public_id ): OperatorActionAssessment {
		$delivery = $this->deliveries->find_by_public_id( $delivery_public_id );
		if ( null === $delivery ) {
			return OperatorActionAssessment::blocked( array( 'not_found' ) );
		}

		if ( ! in_array( $delivery->state, array( 'proposed', 'held', 'queued', 'retrying', 'failed' ), true ) ) {
			return OperatorActionAssessment::blocked( array( 'cancel_not_allowed' ), $delivery->public_id, $delivery->state );
		}

		return OperatorActionAssessment::would_mutate( $delivery->public_id, $delivery->state, 'cancelled' );
	}

	/**
	 * Assess a held delivery resume.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return OperatorActionAssessment
	 */
	public function assess_resume( string $delivery_public_id ): OperatorActionAssessment {
		$delivery = $this->deliveries->find_by_public_id( $delivery_public_id );
		if ( null === $delivery ) {
			return OperatorActionAssessment::blocked( array( 'not_found' ) );
		}

		if ( 'held' !== $delivery->state ) {
			return OperatorActionAssessment::blocked( array( 'resume_not_allowed' ), $delivery->public_id, $delivery->state );
		}

		$blocks = $this->queue_gate_blocks( $delivery, 'resume' );
		if ( array() !== $blocks ) {
			return OperatorActionAssessment::blocked( $blocks, $delivery->public_id, $delivery->state );
		}

		return OperatorActionAssessment::would_mutate( $delivery->public_id, $delivery->state, 'queued' );
	}

	/**
	 * Assess a manual retry.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return OperatorActionAssessment
	 */
	public function assess_retry( string $delivery_public_id ): OperatorActionAssessment {
		$delivery = $this->deliveries->find_by_public_id( $delivery_public_id );
		if ( null === $delivery ) {
			return OperatorActionAssessment::blocked( array( 'not_found' ) );
		}

		if ( 'failed' === $delivery->state && 'manual_allowed' === $delivery->retry_safety ) {
			$blocks = $this->queue_gate_blocks( $delivery, 'manual_retry' );
			return array() === $blocks
				? OperatorActionAssessment::would_mutate( $delivery->public_id, $delivery->state, 'queued' )
				: OperatorActionAssessment::blocked( $blocks, $delivery->public_id, $delivery->state );
		}

		if ( 'ambiguous' === $delivery->state && 'confirmed_not_accepted' === $delivery->reconciliation_status && 'manual_allowed' === $delivery->retry_safety ) {
			$blocks = $this->queue_gate_blocks( $delivery, 'manual_retry' );
			return array() === $blocks
				? OperatorActionAssessment::would_mutate( $delivery->public_id, $delivery->state, 'queued' )
				: OperatorActionAssessment::blocked( $blocks, $delivery->public_id, $delivery->state );
		}

		return OperatorActionAssessment::blocked( array( 'manual_retry_not_allowed' ), $delivery->public_id, $delivery->state );
	}

	/**
	 * Assess reconciliation of an ambiguous delivery.
	 *
	 * @param string      $delivery_public_id Delivery public ID.
	 * @param string      $outcome Outcome.
	 * @param string|null $remote_id Remote provider post ID.
	 * @return OperatorActionAssessment
	 */
	public function assess_reconciliation( string $delivery_public_id, string $outcome, ?string $remote_id ): OperatorActionAssessment {
		$delivery = $this->deliveries->find_by_public_id( $delivery_public_id );
		if ( null === $delivery ) {
			return OperatorActionAssessment::blocked( array( 'not_found' ) );
		}

		if ( 'ambiguous' !== $delivery->state ) {
			return OperatorActionAssessment::blocked( array( 'reconciliation_not_allowed' ), $delivery->public_id, $delivery->state );
		}

		switch ( $outcome ) {
			case 'confirmed_remote_id':
				if ( null === $remote_id || '' === trim( $remote_id ) ) {
					throw new ValidationException( 'Remote ID is required for this reconciliation outcome.' );
				}
				StorageValidator::assert_remote_identifier( 'Remote ID', $remote_id );
				return OperatorActionAssessment::would_mutate( $delivery->public_id, $delivery->state, 'accepted' );

			case 'confirmed_not_accepted':
				return OperatorActionAssessment::would_mutate( $delivery->public_id, $delivery->state, 'ambiguous' );

			case 'cancelled':
				return OperatorActionAssessment::would_mutate( $delivery->public_id, $delivery->state, 'cancelled' );

			default:
				throw new ValidationException( 'Reconciliation outcome is not supported.' );
		}
	}

	/**
	 * Return fail-closed blocks for actions that queue provider work.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param string         $action Operator action.
	 * @return array<int,string>
	 */
	private function queue_gate_blocks( DeliveryRecord $delivery, string $action ): array {
		$blocks = array();

		if ( $this->settings->is_kill_switch_enabled() ) {
			$blocks[] = $action . '_kill_switch_enabled';
		}

		if ( 'live' !== $delivery->delivery_mode || ! $this->has_bound_identity( $delivery ) ) {
			$blocks[] = $action . '_immutable_delivery_incompatible';
		}

		$compatibility = $this->submission_compatibility( $delivery );
		if ( null !== $compatibility ) {
			$blocks[] = $compatibility;
		}

		$connection = null === $delivery->bound_connection_public_id ? null : $this->connections->find_by_public_id( $delivery->bound_connection_public_id );
		$channel    = null === $delivery->bound_channel_public_id ? null : $this->channels->find_by_public_id( $delivery->bound_channel_public_id );

		if ( null === $connection || null === $channel || ! $this->destination_matches_bound_identity( $delivery, $connection, $channel ) ) {
			$blocks[] = 'capability_mismatch';
		}

		if ( null === $connection || ! $this->credential_is_decryptable( $connection ) ) {
			$blocks[] = $action . '_credential_unavailable';
		}

		return array_values( array_unique( $blocks ) );
	}

	/**
	 * Check whether immutable destination identity is complete.
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
	 * Return fail-closed submission compatibility error, if any.
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
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return false;
		}
	}
}
