<?php
/**
 * Delivery transition repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Provider\Dto\ProviderDeliveryResult;
use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Applies delivery state changes and appends events atomically.
 */
final class DeliveryTransitionRepository extends AbstractRepository {
	private const FORMATS = array(
		'bound_provider'                   => '%s',
		'bound_connection_public_id'       => '%s',
		'bound_channel_public_id'          => '%s',
		'bound_remote_account_id'          => '%s',
		'bound_remote_organization_id'     => '%s',
		'bound_remote_channel_id'          => '%s',
		'bound_capability_schema_version'  => '%d',
		'bound_capability_hash'            => '%s',
		'bound_capability_catalog_version' => '%s',
		'state'                            => '%s',
		'queued_at'                        => '%s',
		'last_attempt_at'                  => '%s',
		'attempt_count'                    => '%d',
		'automatic_attempt_count'          => '%d',
		'next_attempt_at'                  => '%s',
		'lease_owner'                      => '%s',
		'lease_expires_at'                 => '%s',
		'action_id'                        => '%d',
		'provider_result'                  => '%s',
		'retry_safety'                     => '%s',
		'receipt_json'                     => '%s',
		'remote_id'                        => '%s',
		'remote_receipt_key'               => '%s',
		'error_class'                      => '%s',
		'error_detail_json'                => '%s',
		'reconciliation_status'            => '%s',
		'reconciled_at'                    => '%s',
		'reconciled_by'                    => '%d',
		'reconciliation_note'              => '%s',
		'state_changed_at'                 => '%s',
		'updated_at'                       => '%s',
		'public_id'                        => '%s',
		'lease_owner_where'                => '%s',
	);

	/**
	 * Event repository.
	 *
	 * @var DeliveryEventRepository
	 */
	private DeliveryEventRepository $events;

	/**
	 * Constructor.
	 *
	 * @param \PressQueue\Storage\Database|null          $database Database wrapper.
	 * @param \PressQueue\Storage\PublicIdGenerator|null $public_ids Public ID generator.
	 */
	public function __construct( ?\PressQueue\Storage\Database $database = null, ?\PressQueue\Storage\PublicIdGenerator $public_ids = null ) {
		parent::__construct( $database, $public_ids );

		$this->events = new DeliveryEventRepository( $this->database, $this->public_ids );
	}

	/**
	 * Bind a legacy proposed delivery to the current verified destination.
	 *
	 * @param DeliveryRecord      $delivery Delivery.
	 * @param ConnectionRecord    $connection Connection.
	 * @param ChannelRecord       $channel Channel.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery or null when the compare-and-set lost.
	 */
	public function bind_destination( DeliveryRecord $delivery, ConnectionRecord $connection, ChannelRecord $channel, array $detail = array() ): ?DeliveryRecord {
		$this->assert_destination_matches_delivery( $delivery, $connection, $channel );
		$capability_schema = (int) ( $channel->capabilities['schema_version'] ?? 0 );
		$capability_hash   = 2 === $capability_schema ? DeliveryCapability::hash( $channel->capabilities ) : null;
		$catalog_version   = DeliveryCapability::catalog_version( $channel->capabilities );
		$now               = StorageValidator::now();

		return $this->transactional_delivery_update(
			$delivery->public_id,
			static function ( object $row ) use ( $connection, $channel, $capability_schema, $capability_hash, $catalog_version, $now ): ?array {
				if ( 'proposed' !== (string) $row->state ) {
					return null;
				}

				if ( null !== $row->bound_provider ) {
					return null;
				}

				return array(
					'bound_provider'                   => (string) $row->provider,
					'bound_connection_public_id'       => $connection->public_id,
					'bound_channel_public_id'          => $channel->public_id,
					'bound_remote_account_id'          => $connection->remote_account_id,
					'bound_remote_organization_id'     => $connection->remote_organization_id,
					'bound_remote_channel_id'          => $channel->remote_channel_id,
					'bound_capability_schema_version'  => $capability_schema,
					'bound_capability_hash'            => $capability_hash,
					'bound_capability_catalog_version' => $catalog_version,
					'updated_at'                       => $now,
				);
			},
			'destination_bound',
			'queue_sweep',
			$detail
		);
	}

	/**
	 * Append a destination-bound event for an already-bound delivery.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 */
	public function record_destination_bound( string $delivery_public_id, array $detail ): void {
		$this->append_event_only( $delivery_public_id, 'destination_bound', 'queue_sweep', $detail );
	}

	/**
	 * Fail a proposed delivery whose current destination cannot be proven.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted failure detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function fail_destination_binding( string $delivery_public_id, array $detail ): ?DeliveryRecord {
		return $this->fail_proposed_preflight( $delivery_public_id, 'destination_binding_failed', 'not_retryable', $detail );
	}

	/**
	 * Fail a proposed delivery before queueing.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param string              $error_class Error class.
	 * @param string              $retry_safety Retry safety.
	 * @param array<string,mixed> $detail Redacted failure detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function fail_proposed_preflight( string $delivery_public_id, string $error_class, string $retry_safety, array $detail ): ?DeliveryRecord {
		StorageValidator::assert_text_key( 'Error class', $error_class );
		StorageEnums::assert_retry_safety( $retry_safety );

		return $this->state_transition(
			$delivery_public_id,
			array( 'proposed' ),
			'failed',
			array(
				'action_id'         => null,
				'error_class'       => $error_class,
				'error_detail_json' => $this->detail_json( $detail, Schema::ERROR_JSON_MAX_BYTES ),
				'lease_expires_at'  => null,
				'lease_owner'       => null,
				'next_attempt_at'   => null,
				'retry_safety'      => $retry_safety,
			),
			'failed',
			'queue_sweep',
			$detail,
			null,
			$retry_safety
		);
	}

	/**
	 * Queue an eligible proposed delivery.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function queue_proposed( string $delivery_public_id, array $detail = array() ): ?DeliveryRecord {
		return $this->queue_from_states( $delivery_public_id, array( 'proposed' ), 'queue_sweep', $detail );
	}

	/**
	 * Move a due retry back to queued.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @param string              $actor_type Actor type.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function queue_due_retry( string $delivery_public_id, array $detail = array(), string $actor_type = 'queue_sweep' ): ?DeliveryRecord {
		StorageEnums::assert_delivery_actor_type( $actor_type );

		return $this->transactional_delivery_update(
			$delivery_public_id,
			function ( object $row ): ?array {
				$now = StorageValidator::now();

				if ( 'retrying' !== (string) $row->state || null === $row->next_attempt_at || (string) $row->next_attempt_at > $now ) {
					return null;
				}

				return $this->queue_values( $row, $now );
			},
			'queued',
			$actor_type,
			$detail,
			null,
			null,
			'queued'
		);
	}

	/**
	 * Recover an expired lease that has not started provider submission.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function recover_lease_to_queue( string $delivery_public_id, array $detail ): ?DeliveryRecord {
		return $this->state_transition(
			$delivery_public_id,
			array( 'leased' ),
			'queued',
			array(
				'action_id'        => null,
				'lease_expires_at' => null,
				'lease_owner'      => null,
				'next_attempt_at'  => null,
			),
			'lease_recovered',
			'recovery',
			$detail
		);
	}

	/**
	 * Recover an expired lease whose provider submission may have begun.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function recover_lease_to_ambiguous( string $delivery_public_id, array $detail ): ?DeliveryRecord {
		return $this->state_transition(
			$delivery_public_id,
			array( 'leased' ),
			'ambiguous',
			array(
				'action_id'         => null,
				'error_class'       => 'expired_lease_after_submission',
				'error_detail_json' => $this->detail_json( $detail, Schema::ERROR_JSON_MAX_BYTES ),
				'lease_expires_at'  => null,
				'lease_owner'       => null,
				'next_attempt_at'   => null,
				'provider_result'   => 'ambiguous',
				'retry_safety'      => 'reconcile_first',
			),
			'lease_ambiguous',
			'recovery',
			$detail,
			'ambiguous',
			'reconcile_first'
		);
	}

	/**
	 * Acquire a delivery lease.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @param string $owner Lease owner.
	 * @param string $expires_at Lease expiry.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function acquire_lease( string $delivery_public_id, string $owner, string $expires_at ): ?DeliveryRecord {
		PublicIds::assert( $delivery_public_id );
		StorageValidator::assert_lease_owner( $owner );
		StorageValidator::assert_utc_timestamp( 'Lease expiry', $expires_at );

		return $this->transactional_delivery_update(
			$delivery_public_id,
			static function ( object $row ) use ( $owner, $expires_at ): ?array {
				$now = StorageValidator::now();

				if ( 'queued' !== (string) $row->state ) {
					return null;
				}

				if ( null !== $row->lease_owner && null !== $row->lease_expires_at && (string) $row->lease_expires_at > $now ) {
					return null;
				}

				return array(
					'lease_expires_at' => $expires_at,
					'lease_owner'      => $owner,
					'state'            => 'leased',
					'state_changed_at' => $now,
					'updated_at'       => $now,
				);
			},
			'leased',
			'worker',
			array( 'lease_expires_at' => $expires_at ),
			null,
			null,
			'leased'
		);
	}

	/**
	 * Move a leased delivery to held.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param string              $owner Lease owner.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function hold_leased( string $delivery_public_id, string $owner, array $detail ): ?DeliveryRecord {
		return $this->leased_transition(
			$delivery_public_id,
			$owner,
			'held',
			array(
				'action_id'        => null,
				'lease_expires_at' => null,
				'lease_owner'      => null,
				'next_attempt_at'  => null,
			),
			'held',
			$detail
		);
	}

	/**
	 * Fail a leased delivery before provider submission.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param string              $owner Lease owner.
	 * @param string              $error_class Error class.
	 * @param string              $retry_safety Retry safety.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function fail_leased_preflight( string $delivery_public_id, string $owner, string $error_class, string $retry_safety, array $detail ): ?DeliveryRecord {
		StorageEnums::assert_retry_safety( $retry_safety );
		StorageValidator::assert_text_key( 'Error class', $error_class );

		return $this->leased_transition(
			$delivery_public_id,
			$owner,
			'failed',
			array(
				'action_id'         => null,
				'error_class'       => $error_class,
				'error_detail_json' => $this->detail_json( $detail, Schema::ERROR_JSON_MAX_BYTES ),
				'lease_expires_at'  => null,
				'lease_owner'       => null,
				'next_attempt_at'   => null,
				'retry_safety'      => $retry_safety,
			),
			'failed',
			$detail,
			null,
			$retry_safety
		);
	}

	/**
	 * Record submission start and increment attempt counters before HTTP.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @param string $owner Lease owner.
	 * @param bool   $automatic Whether this is an automatic worker attempt.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function submission_started( string $delivery_public_id, string $owner, bool $automatic = true ): ?DeliveryRecord {
		StorageValidator::assert_lease_owner( $owner );

		return $this->transactional_delivery_update(
			$delivery_public_id,
			static function ( object $row ) use ( $owner, $automatic ): ?array {
				if ( 'leased' !== (string) $row->state || (string) $row->lease_owner !== $owner || null === $row->lease_expires_at || (string) $row->lease_expires_at <= StorageValidator::now() ) {
					return null;
				}

				$now               = StorageValidator::now();
				$attempt_count     = (int) $row->attempt_count + 1;
				$automatic_attempt = (int) $row->automatic_attempt_count + ( $automatic ? 1 : 0 );

				return array(
					'attempt_count'           => $attempt_count,
					'automatic_attempt_count' => $automatic_attempt,
					'last_attempt_at'         => $now,
					'updated_at'              => $now,
				);
			},
			'submission_started',
			'worker',
			array( 'automatic' => $automatic ),
			null,
			null,
			null,
			static function ( object $row ): int {
				return (int) $row->attempt_count + 1;
			}
		);
	}

	/**
	 * Store a provider result and move the delivery to its next state.
	 *
	 * @param string                 $delivery_public_id Delivery public ID.
	 * @param string                 $owner Lease owner.
	 * @param ProviderDeliveryResult $result Provider result.
	 * @param string|null            $next_attempt_at Retry timestamp.
	 * @param bool                   $budget_exhausted Whether automatic budget is exhausted.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function complete_provider_result( string $delivery_public_id, string $owner, ProviderDeliveryResult $result, ?string $next_attempt_at = null, bool $budget_exhausted = false ): ?DeliveryRecord {
		StorageValidator::assert_lease_owner( $owner );

		if ( null !== $next_attempt_at ) {
			StorageValidator::assert_utc_timestamp( 'Next attempt timestamp', $next_attempt_at );
		}

		if ( 'accepted' === $result->provider_result ) {
			if ( null === $result->remote_id ) {
				throw new ValidationException( 'Accepted provider results require a remote ID.' );
			}

			$receipt_json = null === $result->receipt ? null : $result->receipt->json();
			if ( null !== $receipt_json ) {
				CanonicalJson::assert_maximum_size( $receipt_json, Schema::RECEIPT_JSON_MAX_BYTES );
			}

			return $this->leased_transition(
				$delivery_public_id,
				$owner,
				'accepted',
				array(
					'action_id'          => null,
					'error_class'        => null,
					'error_detail_json'  => null,
					'lease_expires_at'   => null,
					'lease_owner'        => null,
					'next_attempt_at'    => null,
					'provider_result'    => 'accepted',
					'receipt_json'       => $receipt_json,
					'remote_id'          => $result->remote_id,
					'remote_receipt_key' => HashKeys::remote_receipt_key( $result->provider, $result->connection_public_id, $result->remote_id ),
					'retry_safety'       => 'not_retryable',
				),
				'provider_result',
				$result->redacted_detail,
				'accepted',
				'not_retryable'
			);
		}

		if ( 'ambiguous' === $result->provider_result ) {
			return $this->leased_transition(
				$delivery_public_id,
				$owner,
				'ambiguous',
				array(
					'action_id'         => null,
					'error_class'       => $result->error_class,
					'error_detail_json' => $this->nullable_detail_json( $result->error_detail, Schema::ERROR_JSON_MAX_BYTES ),
					'lease_expires_at'  => null,
					'lease_owner'       => null,
					'next_attempt_at'   => null,
					'provider_result'   => 'ambiguous',
					'retry_safety'      => 'reconcile_first',
				),
				'provider_result',
				$result->redacted_detail,
				'ambiguous',
				'reconcile_first'
			);
		}

		if ( in_array( $result->provider_result, array( 'retryable_failure', 'rate_limited' ), true ) && ! $budget_exhausted && null !== $next_attempt_at ) {
			return $this->leased_transition(
				$delivery_public_id,
				$owner,
				'retrying',
				array(
					'action_id'         => null,
					'error_class'       => $result->error_class,
					'error_detail_json' => $this->nullable_detail_json( $result->error_detail, Schema::ERROR_JSON_MAX_BYTES ),
					'lease_expires_at'  => null,
					'lease_owner'       => null,
					'next_attempt_at'   => $next_attempt_at,
					'provider_result'   => $result->provider_result,
					'retry_safety'      => 'automatic_allowed',
				),
				'retry_scheduled',
				$result->redacted_detail,
				$result->provider_result,
				'automatic_allowed'
			);
		}

		$retry_safety = $budget_exhausted ? 'manual_allowed' : $result->retry_safety;

		return $this->leased_transition(
			$delivery_public_id,
			$owner,
			'failed',
			array(
				'action_id'         => null,
				'error_class'       => $result->error_class,
				'error_detail_json' => $this->nullable_detail_json( $result->error_detail, Schema::ERROR_JSON_MAX_BYTES ),
				'lease_expires_at'  => null,
				'lease_owner'       => null,
				'next_attempt_at'   => null,
				'provider_result'   => $result->provider_result,
				'retry_safety'      => $retry_safety,
			),
			'failed',
			array_merge( $result->redacted_detail, array( 'budget_exhausted' => $budget_exhausted ) ),
			$result->provider_result,
			$retry_safety
		);
	}

	/**
	 * Record an Action Scheduler action ID after enqueue.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param int                 $action_id Action Scheduler action ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return bool Whether the action ID was recorded.
	 */
	public function record_action_id( string $delivery_public_id, int $action_id, array $detail = array() ): bool {
		StorageValidator::assert_unsigned_id( 'Action ID', $action_id );

		$updated = $this->transactional_delivery_update(
			$delivery_public_id,
			static function ( object $row ) use ( $action_id ): ?array {
				if ( ! in_array( (string) $row->state, array( 'queued', 'retrying' ), true ) || null !== $row->action_id ) {
					return null;
				}

				return array(
					'action_id'  => $action_id,
					'updated_at' => StorageValidator::now(),
				);
			},
			'scheduler_action_scheduled',
			'scheduler',
			$detail,
			null,
			null,
			null,
			null,
			$action_id
		);

		return null !== $updated;
	}

	/**
	 * Append a redacted scheduler enqueue failure event.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 */
	public function record_scheduler_enqueue_failed( string $delivery_public_id, array $detail ): void {
		$this->append_event_only( $delivery_public_id, 'scheduler_enqueue_failed', 'scheduler', $detail );
	}

	/**
	 * Append a redacted scheduler unavailable event.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 */
	public function record_scheduler_unavailable( string $delivery_public_id, array $detail ): void {
		$this->append_event_only( $delivery_public_id, 'scheduler_unavailable', 'scheduler', $detail );
	}

	/**
	 * Append a redacted race repair event.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param int                 $action_id Action ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 */
	public function record_scheduler_race_repaired( string $delivery_public_id, int $action_id, array $detail ): void {
		$this->append_event_only( $delivery_public_id, 'scheduler_race_repaired', 'scheduler', $detail, null, null, $action_id );
	}

	/**
	 * Clear a stale queued action ID after the scheduler no longer has it.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function clear_missing_action_id( string $delivery_public_id, array $detail ): ?DeliveryRecord {
		return $this->transactional_delivery_update(
			$delivery_public_id,
			static function ( object $row ): ?array {
				if ( 'queued' !== (string) $row->state || null === $row->action_id ) {
					return null;
				}

				return array(
					'action_id'  => null,
					'updated_at' => StorageValidator::now(),
				);
			},
			'scheduler_race_repaired',
			'scheduler',
			$detail
		);
	}

	/**
	 * Append a refused transition event.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @param int|null            $actor_user_id Actor user ID.
	 */
	public function record_refused_transition( string $delivery_public_id, array $detail, ?int $actor_user_id = null ): void {
		if ( null !== $actor_user_id ) {
			StorageValidator::assert_unsigned_id( 'Actor user ID', $actor_user_id, true );
		}

		$this->append_event_only( $delivery_public_id, 'refused_transition', 'operator', $detail, null, null, null, $actor_user_id );
	}

	/**
	 * Cancel pending or failed local work.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function cancel_not_leased( string $delivery_public_id, ?int $actor_user_id, array $detail ): ?DeliveryRecord {
		if ( null !== $actor_user_id ) {
			StorageValidator::assert_unsigned_id( 'Actor user ID', $actor_user_id, true );
		}

		return $this->state_transition(
			$delivery_public_id,
			array( 'proposed', 'held', 'queued', 'retrying', 'failed' ),
			'cancelled',
			array(
				'action_id'        => null,
				'lease_expires_at' => null,
				'lease_owner'      => null,
				'next_attempt_at'  => null,
			),
			'cancelled',
			'operator',
			$detail,
			null,
			null,
			$actor_user_id
		);
	}

	/**
	 * Resume a held live delivery.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function resume_held( string $delivery_public_id, ?int $actor_user_id, array $detail ): ?DeliveryRecord {
		if ( null !== $actor_user_id ) {
			StorageValidator::assert_unsigned_id( 'Actor user ID', $actor_user_id, true );
		}

		return $this->transactional_delivery_update(
			$delivery_public_id,
			function ( object $row ): ?array {
				if ( 'held' !== (string) $row->state || 'live' !== (string) $row->delivery_mode || ! $this->row_has_bound_identity( $row ) ) {
					return null;
				}

				return $this->queue_values( $row, StorageValidator::now() );
			},
			'resumed',
			'operator',
			$detail,
			null,
			null,
			'queued',
			null,
			null,
			$actor_user_id
		);
	}

	/**
	 * Retry a failed delivery that is marked manual-safe.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function manual_retry_failed( string $delivery_public_id, ?int $actor_user_id, array $detail ): ?DeliveryRecord {
		if ( null !== $actor_user_id ) {
			StorageValidator::assert_unsigned_id( 'Actor user ID', $actor_user_id, true );
		}

		return $this->transactional_delivery_update(
			$delivery_public_id,
			function ( object $row ): ?array {
				if ( 'failed' !== (string) $row->state || 'manual_allowed' !== (string) $row->retry_safety || 'live' !== (string) $row->delivery_mode || ! $this->row_has_bound_identity( $row ) ) {
					return null;
				}

				return $this->queue_values( $row, StorageValidator::now() );
			},
			'manual_retry',
			'operator',
			$detail,
			null,
			null,
			'queued',
			null,
			null,
			$actor_user_id
		);
	}

	/**
	 * Retry an ambiguous delivery after the operator confirms it was not accepted.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function manual_retry_confirmed_not_accepted( string $delivery_public_id, ?int $actor_user_id, array $detail ): ?DeliveryRecord {
		if ( null !== $actor_user_id ) {
			StorageValidator::assert_unsigned_id( 'Actor user ID', $actor_user_id, true );
		}

		return $this->transactional_delivery_update(
			$delivery_public_id,
			function ( object $row ): ?array {
				if (
					'ambiguous' !== (string) $row->state
					|| 'confirmed_not_accepted' !== (string) $row->reconciliation_status
					|| 'manual_allowed' !== (string) $row->retry_safety
					|| 'live' !== (string) $row->delivery_mode
					|| ! $this->row_has_bound_identity( $row )
				) {
					return null;
				}

				return array_merge(
					$this->queue_values( $row, StorageValidator::now() ),
					array(
						'action_id'             => null,
						'error_detail_json'     => null,
						'receipt_json'          => null,
						'reconciled_at'         => null,
						'reconciled_by'         => null,
						'reconciliation_note'   => null,
						'reconciliation_status' => 'none',
						'remote_id'             => null,
						'remote_receipt_key'    => null,
					)
				);
			},
			'manual_retry',
			'operator',
			$detail,
			null,
			null,
			'queued',
			null,
			null,
			$actor_user_id
		);
	}

	/**
	 * Reconcile an ambiguous delivery by confirming the remote post ID.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param string              $remote_id Remote provider post ID.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @param string|null         $note Sanitized evidence note.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function reconcile_confirmed_remote_id( string $delivery_public_id, string $remote_id, ?int $actor_user_id, ?string $note, array $detail ): ?DeliveryRecord {
		StorageValidator::assert_remote_identifier( 'Remote ID', $remote_id );

		if ( null !== $actor_user_id ) {
			StorageValidator::assert_unsigned_id( 'Actor user ID', $actor_user_id, true );
		}

		return $this->transactional_delivery_update(
			$delivery_public_id,
			function ( object $row ) use ( $remote_id, $actor_user_id, $note ): ?array {
				if ( 'ambiguous' !== (string) $row->state ) {
					return null;
				}

				$provider             = null === $row->bound_provider ? (string) $row->provider : (string) $row->bound_provider;
				$connection_public_id = null === $row->bound_connection_public_id ? (string) $row->connection_public_id : (string) $row->bound_connection_public_id;
				$receipt_key          = HashKeys::remote_receipt_key( $provider, $connection_public_id, $remote_id );

				if ( $this->remote_receipt_key_exists_locked( $receipt_key, (string) $row->public_id ) ) {
					throw new ValidationException( 'Remote receipt has already been confirmed for this connection.' );
				}

				$now = StorageValidator::now();

				return array(
					'action_id'             => null,
					'lease_expires_at'      => null,
					'lease_owner'           => null,
					'next_attempt_at'       => null,
					'provider_result'       => 'ambiguous',
					'reconciled_at'         => $now,
					'reconciled_by'         => $actor_user_id,
					'reconciliation_note'   => $note,
					'reconciliation_status' => 'confirmed_remote_id',
					'remote_id'             => $remote_id,
					'remote_receipt_key'    => $receipt_key,
					'retry_safety'          => 'not_retryable',
					'state'                 => 'accepted',
					'state_changed_at'      => $now,
					'updated_at'            => $now,
				);
			},
			'reconciled',
			'operator',
			array_merge(
				$detail,
				array(
					'outcome'   => 'confirmed_remote_id',
					'remote_id' => $remote_id,
				)
			),
			'ambiguous',
			'not_retryable',
			'accepted',
			null,
			null,
			$actor_user_id
		);
	}

	/**
	 * Reconcile an ambiguous delivery as not accepted by the provider.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @param string|null         $note Sanitized evidence note.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function reconcile_confirmed_not_accepted( string $delivery_public_id, ?int $actor_user_id, ?string $note, array $detail ): ?DeliveryRecord {
		if ( null !== $actor_user_id ) {
			StorageValidator::assert_unsigned_id( 'Actor user ID', $actor_user_id, true );
		}

		return $this->transactional_delivery_update(
			$delivery_public_id,
			static function ( object $row ) use ( $actor_user_id, $note ): ?array {
				if ( 'ambiguous' !== (string) $row->state || 'draft_not_confirmed' === (string) $row->error_class ) {
					return null;
				}

				return array(
					'action_id'             => null,
					'next_attempt_at'       => null,
					'provider_result'       => 'ambiguous',
					'reconciled_at'         => StorageValidator::now(),
					'reconciled_by'         => $actor_user_id,
					'reconciliation_note'   => $note,
					'reconciliation_status' => 'confirmed_not_accepted',
					'remote_id'             => null,
					'remote_receipt_key'    => null,
					'retry_safety'          => 'manual_allowed',
					'updated_at'            => StorageValidator::now(),
				);
			},
			'reconciled',
			'operator',
			array_merge( $detail, array( 'outcome' => 'confirmed_not_accepted' ) ),
			'ambiguous',
			'manual_allowed',
			'ambiguous',
			null,
			null,
			$actor_user_id
		);
	}

	/**
	 * Reconcile an ambiguous delivery by cancelling the local work.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @param string|null         $note Sanitized evidence note.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	public function reconcile_cancelled( string $delivery_public_id, ?int $actor_user_id, ?string $note, array $detail ): ?DeliveryRecord {
		if ( null !== $actor_user_id ) {
			StorageValidator::assert_unsigned_id( 'Actor user ID', $actor_user_id, true );
		}

		return $this->state_transition(
			$delivery_public_id,
			array( 'ambiguous' ),
			'cancelled',
			array(
				'action_id'             => null,
				'lease_expires_at'      => null,
				'lease_owner'           => null,
				'next_attempt_at'       => null,
				'provider_result'       => 'ambiguous',
				'reconciled_at'         => StorageValidator::now(),
				'reconciled_by'         => $actor_user_id,
				'reconciliation_note'   => $note,
				'reconciliation_status' => 'cancelled',
				'retry_safety'          => 'not_retryable',
			),
			'reconciled',
			'operator',
			array_merge( $detail, array( 'outcome' => 'cancelled' ) ),
			'ambiguous',
			'not_retryable',
			$actor_user_id
		);
	}

	/**
	 * Queue from an allowed source state.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<int,string>   $from_states Source states.
	 * @param string              $actor_type Actor type.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	private function queue_from_states( string $delivery_public_id, array $from_states, string $actor_type, array $detail ): ?DeliveryRecord {
		return $this->transactional_delivery_update(
			$delivery_public_id,
			function ( object $row ) use ( $from_states ): ?array {
				if ( ! in_array( (string) $row->state, $from_states, true ) ) {
					return null;
				}

				if ( 'live' !== (string) $row->delivery_mode || ! $this->row_has_bound_identity( $row ) ) {
					return null;
				}

				return $this->queue_values( $row, StorageValidator::now() );
			},
			'queued',
			$actor_type,
			$detail,
			null,
			null,
			'queued'
		);
	}

	/**
	 * Return queue projection values.
	 *
	 * @param object $row Delivery row.
	 * @param string $now Current timestamp.
	 * @return array<string,mixed>
	 */
	private function queue_values( object $row, string $now ): array {
		return array(
			'action_id'        => null,
			'lease_expires_at' => null,
			'lease_owner'      => null,
			'next_attempt_at'  => null,
			'queued_at'        => null === $row->queued_at ? $now : (string) $row->queued_at,
			'state'            => 'queued',
			'state_changed_at' => $now,
			'updated_at'       => $now,
		);
	}

	/**
	 * Apply a leased state transition.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param string              $owner Lease owner.
	 * @param string              $to_state Target state.
	 * @param array<string,mixed> $values Projection values.
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @param string|null         $provider_result Provider result.
	 * @param string|null         $retry_safety Retry safety.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	private function leased_transition( string $delivery_public_id, string $owner, string $to_state, array $values, string $event_type, array $detail, ?string $provider_result = null, ?string $retry_safety = null ): ?DeliveryRecord {
		StorageValidator::assert_lease_owner( $owner );

		return $this->transactional_delivery_update(
			$delivery_public_id,
			static function ( object $row ) use ( $owner, $to_state, $values ): ?array {
				if ( 'leased' !== (string) $row->state || (string) $row->lease_owner !== $owner ) {
					return null;
				}

				$now = StorageValidator::now();

				return array_merge(
					$values,
					array(
						'state'            => $to_state,
						'state_changed_at' => $now,
						'updated_at'       => $now,
					)
				);
			},
			$event_type,
			'worker',
			$detail,
			$provider_result,
			$retry_safety,
			$to_state
		);
	}

	/**
	 * Apply a state transition from one of the allowed sources.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param array<int,string>   $from_states Source states.
	 * @param string              $to_state Target state.
	 * @param array<string,mixed> $values Projection values.
	 * @param string              $event_type Event type.
	 * @param string              $actor_type Actor type.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @param string|null         $provider_result Provider result.
	 * @param string|null         $retry_safety Retry safety.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	private function state_transition( string $delivery_public_id, array $from_states, string $to_state, array $values, string $event_type, string $actor_type, array $detail, ?string $provider_result = null, ?string $retry_safety = null, ?int $actor_user_id = null ): ?DeliveryRecord {
		StorageEnums::assert_delivery_state( $to_state );

		return $this->transactional_delivery_update(
			$delivery_public_id,
			function ( object $row ) use ( $from_states, $to_state, $values, $actor_type ): ?array {
				if ( ! in_array( (string) $row->state, $from_states, true ) ) {
					return null;
				}

				$now = StorageValidator::now();
				if ( 'recovery' === $actor_type ) {
					if ( null === $row->lease_expires_at || (string) $row->lease_expires_at > $now ) {
						return null;
					}
					$started = $this->events->has_current_lease_submission( (string) $row->public_id );
					if ( ( 'ambiguous' === $to_state ) !== $started ) {
						return null;
					}
				}

				return array_merge(
					$values,
					array(
						'state'            => $to_state,
						'state_changed_at' => $now,
						'updated_at'       => $now,
					)
				);
			},
			$event_type,
			$actor_type,
			$detail,
			$provider_result,
			$retry_safety,
			$to_state,
			null,
			null,
			$actor_user_id
		);
	}

	/**
	 * Run one delivery projection update and event append in a checked transaction.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param callable            $value_factory Factory receiving the locked row.
	 * @param string              $event_type Event type.
	 * @param string              $actor_type Actor type.
	 * @param array<string,mixed> $detail Redacted event detail.
	 * @param string|null         $provider_result Provider result.
	 * @param string|null         $retry_safety Retry safety.
	 * @param string|null         $to_state Override target state.
	 * @param callable|null       $attempt_factory Attempt number factory.
	 * @param int|null            $action_id Action ID.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @return DeliveryRecord|null Updated delivery.
	 */
	private function transactional_delivery_update(
		string $delivery_public_id,
		callable $value_factory,
		string $event_type,
		string $actor_type,
		array $detail,
		?string $provider_result = null,
		?string $retry_safety = null,
		?string $to_state = null,
		?callable $attempt_factory = null,
		?int $action_id = null,
		?int $actor_user_id = null
	): ?DeliveryRecord {
		PublicIds::assert( $delivery_public_id );
		StorageEnums::assert_delivery_event_type( $event_type );
		StorageEnums::assert_delivery_actor_type( $actor_type );

		$updated_public_id = $this->database->transaction(
			function () use ( $delivery_public_id, $value_factory, $event_type, $actor_type, $detail, $provider_result, $retry_safety, $to_state, $attempt_factory, $action_id, $actor_user_id ): ?string {
				$row = $this->locked_delivery_row( $delivery_public_id );
				if ( null === $row ) {
					return null;
				}

				$values = $value_factory( $row );
				if ( null === $values ) {
					return null;
				}

				$from_state = (string) $row->state;
				$target     = $to_state ?? ( isset( $values['state'] ) ? (string) $values['state'] : $from_state );
				$this->database->update_rows(
					$this->deliveries_table(),
					$values,
					array( 'public_id' => $delivery_public_id ),
					self::FORMATS
				);

				$attempt_number = null === $attempt_factory ? null : (int) $attempt_factory( $row );
				$this->events->append(
					array(
						'action_id'          => $action_id,
						'actor_type'         => $actor_type,
						'actor_user_id'      => $actor_user_id,
						'attempt_number'     => $attempt_number,
						'delivery_id'        => (int) $row->id,
						'delivery_public_id' => $delivery_public_id,
						'detail'             => $detail,
						'event_type'         => $event_type,
						'from_state'         => $from_state,
						'provider_result'    => $provider_result,
						'retry_safety'       => $retry_safety,
						'to_state'           => $target,
					)
				);

				return $delivery_public_id;
			},
			array( Schema::DELIVERIES, Schema::DELIVERY_EVENTS )
		);

		return null === $updated_public_id ? null : ( new DeliveryRepository( $this->database, $this->public_ids ) )->find_by_public_id( $updated_public_id );
	}

	/**
	 * Append an event without changing projection state.
	 *
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param string              $event_type Event type.
	 * @param string              $actor_type Actor type.
	 * @param array<string,mixed> $detail Redacted detail.
	 * @param string|null         $provider_result Provider result.
	 * @param string|null         $retry_safety Retry safety.
	 * @param int|null            $action_id Action ID.
	 * @param int|null            $actor_user_id Actor user ID.
	 */
	private function append_event_only( string $delivery_public_id, string $event_type, string $actor_type, array $detail, ?string $provider_result = null, ?string $retry_safety = null, ?int $action_id = null, ?int $actor_user_id = null ): void {
		PublicIds::assert( $delivery_public_id );

		$this->database->transaction(
			function () use ( $delivery_public_id, $event_type, $actor_type, $detail, $provider_result, $retry_safety, $action_id, $actor_user_id ): void {
				$row = $this->locked_delivery_row( $delivery_public_id );
				if ( null === $row ) {
					throw new StorageException( 'Delivery does not exist.' );
				}

				$this->events->append(
					array(
						'action_id'          => $action_id,
						'actor_type'         => $actor_type,
						'actor_user_id'      => $actor_user_id,
						'delivery_id'        => (int) $row->id,
						'delivery_public_id' => $delivery_public_id,
						'detail'             => $detail,
						'event_type'         => $event_type,
						'from_state'         => (string) $row->state,
						'provider_result'    => $provider_result,
						'retry_safety'       => $retry_safety,
						'to_state'           => (string) $row->state,
					)
				);
			},
			array( Schema::DELIVERIES, Schema::DELIVERY_EVENTS )
		);
	}

	/**
	 * Check duplicate receipt keys before an operator confirmation is written.
	 *
	 * @param string $remote_receipt_key Receipt uniqueness key.
	 * @param string $exclude_public_id Delivery public ID to ignore.
	 * @return bool
	 */
	private function remote_receipt_key_exists_locked( string $remote_receipt_key, string $exclude_public_id ): bool {
		StorageValidator::assert_hash( $remote_receipt_key );
		PublicIds::assert( $exclude_public_id );

		$row = $this->database->get_row(
			'SELECT public_id FROM `' . $this->deliveries_table() . '` USE INDEX (remote_receipt_key) WHERE remote_receipt_key = %s AND public_id <> %s FOR UPDATE',
			array( $remote_receipt_key, $exclude_public_id )
		);

		return null !== $row;
	}

	/**
	 * Return a locked delivery row.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return object|null
	 */
	private function locked_delivery_row( string $delivery_public_id ): ?object {
		return $this->database->get_row(
			'SELECT * FROM `' . $this->deliveries_table() . '` WHERE public_id = %s FOR UPDATE',
			array( $delivery_public_id )
		);
	}

	/**
	 * Check whether a row has all immutable destination fields.
	 *
	 * @param object $row Delivery row.
	 * @return bool
	 */
	private function row_has_bound_identity( object $row ): bool {
		return null !== $row->bound_provider
			&& null !== $row->bound_connection_public_id
			&& null !== $row->bound_channel_public_id
			&& null !== $row->bound_remote_account_id
			&& null !== $row->bound_remote_organization_id
			&& null !== $row->bound_remote_channel_id
			&& null !== $row->bound_capability_schema_version;
	}

	/**
	 * Assert destination records match the delivery and each other.
	 *
	 * @param DeliveryRecord   $delivery Delivery.
	 * @param ConnectionRecord $connection Connection.
	 * @param ChannelRecord    $channel Channel.
	 */
	private function assert_destination_matches_delivery( DeliveryRecord $delivery, ConnectionRecord $connection, ChannelRecord $channel ): void {
		if (
			$delivery->provider !== $connection->provider
			|| $delivery->provider !== $channel->provider
			|| $delivery->connection_public_id !== $connection->public_id
			|| $delivery->channel_public_id !== $channel->public_id
			|| $delivery->remote_channel_id !== $channel->remote_channel_id
			|| $channel->connection_public_id !== $connection->public_id
		) {
			throw new ValidationException( 'Delivery destination identity is inconsistent.' );
		}
	}

	/**
	 * Encode a required detail object.
	 *
	 * @param array<string,mixed> $detail Detail.
	 * @param int                 $maximum_bytes Maximum size.
	 * @return string
	 */
	private function detail_json( array $detail, int $maximum_bytes ): string {
		$json = CanonicalJson::encode_object( $detail );
		CanonicalJson::assert_maximum_size( $json, $maximum_bytes );

		return $json;
	}

	/**
	 * Encode a nullable detail object.
	 *
	 * @param array<string,mixed>|null $detail Detail.
	 * @param int                      $maximum_bytes Maximum size.
	 * @return string|null
	 */
	private function nullable_detail_json( ?array $detail, int $maximum_bytes ): ?string {
		return null === $detail ? null : $this->detail_json( $detail, $maximum_bytes );
	}
}
