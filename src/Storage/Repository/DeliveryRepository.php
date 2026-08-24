<?php
/**
 * Delivery repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Persists immutable delivery preview rows.
 */
final class DeliveryRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                              => '%d',
		'public_id'                       => '%s',
		'decision_id'                     => '%d',
		'decision_public_id'              => '%s',
		'event_key'                       => '%s',
		'delivery_key'                    => '%s',
		'delivery_mode'                   => '%s',
		'provider'                        => '%s',
		'connection_id'                   => '%d',
		'connection_public_id'            => '%s',
		'channel_id'                      => '%d',
		'channel_public_id'               => '%s',
		'remote_channel_id'               => '%s',
		'rule_public_id'                  => '%s',
		'rule_version'                    => '%d',
		'state'                           => '%s',
		'schedule_slot'                   => '%s',
		'payload_preview_json'            => '%s',
		'payload_hash'                    => '%s',
		'created_at'                      => '%s',
		'updated_at'                      => '%s',
		'bound_provider'                  => '%s',
		'bound_connection_public_id'      => '%s',
		'bound_channel_public_id'         => '%s',
		'bound_remote_account_id'         => '%s',
		'bound_remote_organization_id'    => '%s',
		'bound_remote_channel_id'         => '%s',
		'bound_capability_schema_version' => '%d',
		'queued_at'                       => '%s',
		'last_attempt_at'                 => '%s',
		'attempt_count'                   => '%d',
		'automatic_attempt_count'         => '%d',
		'next_attempt_at'                 => '%s',
		'lease_owner'                     => '%s',
		'lease_expires_at'                => '%s',
		'action_id'                       => '%d',
		'provider_result'                 => '%s',
		'retry_safety'                    => '%s',
		'receipt_json'                    => '%s',
		'remote_id'                       => '%s',
		'remote_receipt_key'              => '%s',
		'error_class'                     => '%s',
		'error_detail_json'               => '%s',
		'reconciliation_status'           => '%s',
		'reconciled_at'                   => '%s',
		'reconciled_by'                   => '%d',
		'reconciliation_note'             => '%s',
		'payload_schema_version'          => '%d',
		'adapter_encoding_version'        => '%d',
		'state_changed_at'                => '%s',
	);

	/**
	 * Create an immutable delivery preview.
	 *
	 * @param array<string,mixed> $input Delivery values.
	 * @return DeliveryRecord
	 */
	public function create( array $input ): DeliveryRecord {
		$prepared = $this->prepare_delivery( $input );
		$table    = $this->deliveries_table();
		$now      = StorageValidator::now();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'                       => $public_id,
						'decision_id'                     => $prepared['decision_id'],
						'decision_public_id'              => $prepared['decision_public_id'],
						'event_key'                       => $prepared['event_key'],
						'delivery_key'                    => $prepared['delivery_key'],
						'delivery_mode'                   => $prepared['delivery_mode'],
						'provider'                        => $prepared['provider'],
						'connection_id'                   => $prepared['connection_id'],
						'connection_public_id'            => $prepared['connection_public_id'],
						'channel_id'                      => $prepared['channel_id'],
						'channel_public_id'               => $prepared['channel_public_id'],
						'remote_channel_id'               => $prepared['remote_channel_id'],
						'rule_public_id'                  => $prepared['rule_public_id'],
						'rule_version'                    => $prepared['rule_version'],
						'state'                           => $prepared['state'],
						'schedule_slot'                   => $prepared['schedule_slot'],
						'payload_preview_json'            => $prepared['payload_preview_json'],
						'payload_hash'                    => $prepared['payload_hash'],
						'created_at'                      => $now,
						'updated_at'                      => $now,
						'bound_provider'                  => $prepared['bound_provider'],
						'bound_connection_public_id'      => $prepared['bound_connection_public_id'],
						'bound_channel_public_id'         => $prepared['bound_channel_public_id'],
						'bound_remote_account_id'         => $prepared['bound_remote_account_id'],
						'bound_remote_organization_id'    => $prepared['bound_remote_organization_id'],
						'bound_remote_channel_id'         => $prepared['bound_remote_channel_id'],
						'bound_capability_schema_version' => $prepared['bound_capability_schema_version'],
						'queued_at'                       => null,
						'last_attempt_at'                 => null,
						'attempt_count'                   => 0,
						'automatic_attempt_count'         => 0,
						'next_attempt_at'                 => null,
						'lease_owner'                     => null,
						'lease_expires_at'                => null,
						'action_id'                       => null,
						'provider_result'                 => null,
						'retry_safety'                    => 'not_retryable',
						'receipt_json'                    => null,
						'remote_id'                       => null,
						'remote_receipt_key'              => null,
						'error_class'                     => null,
						'error_detail_json'               => null,
						'reconciliation_status'           => 'none',
						'reconciled_at'                   => null,
						'reconciled_by'                   => null,
						'reconciliation_note'             => null,
						'payload_schema_version'          => 1,
						'adapter_encoding_version'        => 1,
						'state_changed_at'                => $now,
					),
					self::FORMATS
				);

				$record = $this->find_by_public_id( $public_id );

				if ( null === $record ) {
					throw new StorageException( 'Created delivery could not be loaded.' );
				}

				return $record;
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue delivery public ID.' );
	}

	/**
	 * Find a delivery by public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return DeliveryRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?DeliveryRecord {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->deliveries_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Find a delivery by delivery key.
	 *
	 * @param string $delivery_key Delivery key.
	 * @return DeliveryRecord|null
	 */
	public function find_by_delivery_key( string $delivery_key ): ?DeliveryRecord {
		StorageValidator::assert_hash( $delivery_key );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->deliveries_table() . '` WHERE delivery_key = %s',
			array( $delivery_key )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Check whether a remote receipt key already belongs to another delivery.
	 *
	 * @param string      $remote_receipt_key Receipt uniqueness key.
	 * @param string|null $exclude_public_id Optional delivery to ignore.
	 * @return bool
	 */
	public function remote_receipt_key_exists( string $remote_receipt_key, ?string $exclude_public_id = null ): bool {
		StorageValidator::assert_hash( $remote_receipt_key );

		if ( null !== $exclude_public_id ) {
			PublicIds::assert( $exclude_public_id );
		}

		$where = 'remote_receipt_key = %s';
		$args  = array( $remote_receipt_key );

		if ( null !== $exclude_public_id ) {
			$where .= ' AND public_id <> %s';
			$args[] = $exclude_public_id;
		}

		$count = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $this->deliveries_table() . '` USE INDEX (remote_receipt_key) WHERE ' . $where,
			$args
		);

		return (int) $count > 0;
	}

	/**
	 * Return deliveries for an event.
	 *
	 * @param string $event_key Event key.
	 * @return array<int,DeliveryRecord>
	 */
	public function find_by_event_key( string $event_key ): array {
		StorageValidator::assert_hash( $event_key );

		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->deliveries_table() . '` WHERE event_key = %s ORDER BY id ASC',
			array( $event_key )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return deliveries due for recovery or scheduling.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,DeliveryRecord>
	 */
	public function find_due_for_sweep( int $limit = 25 ): array {
		if ( $limit < 1 || $limit > 100 ) {
			throw new ValidationException( 'Sweep batch size is out of range.' );
		}

		$now  = StorageValidator::now();
		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->deliveries_table() . '`
				WHERE
					(delivery_mode = %s AND state = %s)
					OR (state = %s AND next_attempt_at IS NOT NULL AND next_attempt_at <= %s)
					OR (state = %s AND action_id IS NULL)
					OR (state = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s)
				ORDER BY
					COALESCE(next_attempt_at, queued_at, state_changed_at, updated_at) ASC,
					state_changed_at ASC,
					id ASC
				LIMIT %d',
			array( 'live', 'proposed', 'retrying', $now, 'queued', 'leased', $now, $limit )
		);

		$remaining = $limit - count( $rows );
		if ( $remaining > 0 ) {
			$rows = array_merge(
				$rows,
				$this->database->get_results(
					'SELECT * FROM `' . $this->deliveries_table() . '`
						WHERE state = %s AND action_id IS NOT NULL
						ORDER BY
							COALESCE(queued_at, state_changed_at, updated_at) ASC,
							state_changed_at ASC,
							id ASC
						LIMIT %d',
					array( 'queued', $remaining )
				)
			);
		}

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Prepare delivery fields.
	 *
	 * @param array<string,mixed> $input Delivery values.
	 * @return array<string,mixed>
	 */
	private function prepare_delivery( array $input ): array {
		$decision_public_id = (string) ( $input['decision_public_id'] ?? '' );
		PublicIds::assert( $decision_public_id );

		$decision = ( new DecisionRepository( $this->database, $this->public_ids ) )->find_by_public_id( $decision_public_id );

		if ( null === $decision ) {
			throw new StorageException( 'Delivery decision does not exist.' );
		}

		if ( 'included' !== $decision->result || null === $decision->rule_public_id || null === $decision->rule_version ) {
			throw new ValidationException( 'Deliveries require an included rule-scoped decision.' );
		}

		$delivery_mode = (string) ( $input['delivery_mode'] ?? '' );
		StorageEnums::assert_delivery_mode( $delivery_mode );

		$state = (string) ( $input['state'] ?? '' );
		StorageEnums::assert_delivery_state( $state );
		$this->assert_mode_state( $delivery_mode, $state );

		$schedule_slot = (string) ( $input['schedule_slot'] ?? 'immediate' );
		StorageEnums::assert( 'Schedule slot', $schedule_slot, StorageEnums::SCHEDULE_POLICIES );

		$provider = (string) ( $input['provider'] ?? '' );
		StorageValidator::assert_text_key( 'Provider', $provider );

		$connection_public_id = (string) ( $input['connection_public_id'] ?? '' );
		$channel_public_id    = (string) ( $input['channel_public_id'] ?? '' );
		PublicIds::assert( $connection_public_id );
		PublicIds::assert( $channel_public_id );

		$connection = ( new ConnectionRepository( $this->database, $this->public_ids ) )->find_by_public_id( $connection_public_id );
		$channel    = ( new ChannelRepository( $this->database, $this->public_ids ) )->find_by_public_id( $channel_public_id );

		if ( null === $connection || null === $channel ) {
			throw new StorageException( 'Delivery connection and channel must exist.' );
		}

		if ( $connection->public_id !== $channel->connection_public_id || $provider !== $connection->provider || $provider !== $channel->provider ) {
			throw new ValidationException( 'Delivery destination identity is inconsistent.' );
		}

		if ( ! $this->connection_allows_delivery( $connection ) ) {
			throw new ValidationException( 'Delivery connection must be enabled and verified.' );
		}

		if (
			! $channel->enabled
			|| 'available' !== $channel->availability
			|| $channel->remote_organization_id !== $connection->remote_organization_id
			|| ! $this->channel_capability_allows_delivery( $channel->capabilities )
		) {
			throw new ValidationException( 'Delivery channel must be enabled and available.' );
		}

		$remote_channel_id = (string) ( $input['remote_channel_id'] ?? $channel->remote_channel_id );
		if ( $remote_channel_id !== $channel->remote_channel_id ) {
			throw new ValidationException( 'Delivery remote channel ID must match the stored channel.' );
		}

		if ( ! isset( $input['payload_preview'] ) || ! is_array( $input['payload_preview'] ) || array_is_list( $input['payload_preview'] ) ) {
			throw new ValidationException( 'Delivery payload preview must be an object.' );
		}

		$payload_preview = $input['payload_preview'];

		if ( ! isset( $payload_preview['message'], $payload_preview['canonical_url'] ) || ! is_string( $payload_preview['message'] ) || ! is_string( $payload_preview['canonical_url'] ) ) {
			throw new ValidationException( 'Delivery payload preview needs message and canonical URL fields.' );
		}

		$payload_preview_json = CanonicalJson::encode_object( $payload_preview );
		CanonicalJson::assert_maximum_size( $payload_preview_json, 65536 );

		$payload_hash = HashKeys::payload_hash(
			array(
				'canonical_url'        => $payload_preview['canonical_url'],
				'channel_public_id'    => $channel->public_id,
				'connection_public_id' => $connection->public_id,
				'message'              => $payload_preview['message'],
				'provider'             => $provider,
				'remote_channel_id'    => $remote_channel_id,
				'revision_fingerprint' => $decision->revision_fingerprint,
				'rule_public_id'       => $decision->rule_public_id,
				'rule_version'         => $decision->rule_version,
				'schedule_slot'        => $schedule_slot,
				'trigger'              => $decision->trigger,
			)
		);

		return array(
			'decision_id'                     => $decision->id,
			'decision_public_id'              => $decision->public_id,
			'event_key'                       => $decision->event_key,
			'delivery_key'                    => HashKeys::delivery_key( $delivery_mode, $provider, $connection->public_id, $channel->public_id, $remote_channel_id, $decision->post_id, $decision->revision_fingerprint, $decision->trigger, $decision->rule_public_id, $decision->rule_version, $schedule_slot ),
			'delivery_mode'                   => $delivery_mode,
			'provider'                        => $provider,
			'connection_id'                   => $connection->id,
			'connection_public_id'            => $connection->public_id,
			'channel_id'                      => $channel->id,
			'channel_public_id'               => $channel->public_id,
			'remote_channel_id'               => $remote_channel_id,
			'bound_provider'                  => 'live' === $delivery_mode ? $provider : null,
			'bound_connection_public_id'      => 'live' === $delivery_mode ? $connection->public_id : null,
			'bound_channel_public_id'         => 'live' === $delivery_mode ? $channel->public_id : null,
			'bound_remote_account_id'         => 'live' === $delivery_mode ? $connection->remote_account_id : null,
			'bound_remote_organization_id'    => 'live' === $delivery_mode ? $connection->remote_organization_id : null,
			'bound_remote_channel_id'         => 'live' === $delivery_mode ? $remote_channel_id : null,
			'bound_capability_schema_version' => 'live' === $delivery_mode ? (int) ( $channel->capabilities['schema_version'] ?? 0 ) : null,
			'rule_public_id'                  => $decision->rule_public_id,
			'rule_version'                    => $decision->rule_version,
			'state'                           => $state,
			'schedule_slot'                   => $schedule_slot,
			'payload_preview'                 => $payload_preview,
			'payload_preview_json'            => $payload_preview_json,
			'payload_hash'                    => $payload_hash,
		);
	}

	/**
	 * Assert delivery mode and state consistency.
	 *
	 * @param string $delivery_mode Delivery mode.
	 * @param string $state Delivery state.
	 */
	private function assert_mode_state( string $delivery_mode, string $state ): void {
		if ( 'dry_run' === $delivery_mode && 'dry_run' === $state ) {
			return;
		}

		if ( 'live' === $delivery_mode && in_array( $state, array( 'proposed', 'held' ), true ) ) {
			return;
		}

		throw new ValidationException( 'Delivery mode and state are inconsistent.' );
	}

	/**
	 * Check whether a channel capability document supports text immediate delivery.
	 *
	 * @param array<string,mixed> $capabilities Capability document.
	 * @return bool
	 */
	private function channel_capability_allows_delivery( array $capabilities ): bool {
		$delivery = $capabilities['delivery'] ?? null;

		return 1 === (int) ( $capabilities['schema_version'] ?? 0 )
			&& is_array( $delivery )
			&& true === ( $delivery['text'] ?? false )
			&& isset( $delivery['schedule_slots'] )
			&& is_array( $delivery['schedule_slots'] )
			&& in_array( 'immediate', $delivery['schedule_slots'], true );
	}

	/**
	 * Check whether a connection has every verified delivery prerequisite.
	 *
	 * @param \PressQueue\Storage\Record\ConnectionRecord $connection Connection.
	 * @return bool
	 */
	private function connection_allows_delivery( ConnectionRecord $connection ): bool {
		return $connection->enabled
			&& 'verified' === $connection->connection_status
			&& $connection->has_credential
			&& null !== $connection->credential_envelope_hash
			&& null !== $connection->remote_account_id
			&& null !== $connection->remote_organization_id
			&& null !== $connection->verified_identity_hash
			&& null !== $connection->last_sync_completed_at;
	}

	/**
	 * Hydrate a delivery record.
	 *
	 * @param object $row Database row.
	 * @return DeliveryRecord
	 */
	public function hydrate_record( object $row ): DeliveryRecord {
		return new DeliveryRecord(
			(int) $row->id,
			(string) $row->public_id,
			(int) $row->decision_id,
			(string) $row->decision_public_id,
			(string) $row->event_key,
			(string) $row->delivery_key,
			(string) $row->delivery_mode,
			(string) $row->provider,
			(int) $row->connection_id,
			(string) $row->connection_public_id,
			(int) $row->channel_id,
			(string) $row->channel_public_id,
			(string) $row->remote_channel_id,
			(string) $row->rule_public_id,
			(int) $row->rule_version,
			(string) $row->state,
			(string) $row->schedule_slot,
			CanonicalJson::decode_object( (string) $row->payload_preview_json ),
			(string) $row->payload_hash,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at ),
			null === $row->bound_provider ? null : (string) $row->bound_provider,
			null === $row->bound_connection_public_id ? null : (string) $row->bound_connection_public_id,
			null === $row->bound_channel_public_id ? null : (string) $row->bound_channel_public_id,
			null === $row->bound_remote_account_id ? null : (string) $row->bound_remote_account_id,
			null === $row->bound_remote_organization_id ? null : (string) $row->bound_remote_organization_id,
			null === $row->bound_remote_channel_id ? null : (string) $row->bound_remote_channel_id,
			null === $row->bound_capability_schema_version ? null : (int) $row->bound_capability_schema_version,
			StorageValidator::parse_date_time( null === $row->queued_at ? null : (string) $row->queued_at ),
			StorageValidator::parse_date_time( null === $row->last_attempt_at ? null : (string) $row->last_attempt_at ),
			(int) $row->attempt_count,
			(int) $row->automatic_attempt_count,
			StorageValidator::parse_date_time( null === $row->next_attempt_at ? null : (string) $row->next_attempt_at ),
			null === $row->lease_owner ? null : (string) $row->lease_owner,
			StorageValidator::parse_date_time( null === $row->lease_expires_at ? null : (string) $row->lease_expires_at ),
			null === $row->action_id ? null : (int) $row->action_id,
			null === $row->provider_result ? null : (string) $row->provider_result,
			(string) $row->retry_safety,
			null === $row->receipt_json ? null : CanonicalJson::decode_object( (string) $row->receipt_json ),
			null === $row->remote_id ? null : (string) $row->remote_id,
			null === $row->remote_receipt_key ? null : (string) $row->remote_receipt_key,
			null === $row->error_class ? null : (string) $row->error_class,
			null === $row->error_detail_json ? null : CanonicalJson::decode_object( (string) $row->error_detail_json ),
			(string) $row->reconciliation_status,
			StorageValidator::parse_date_time( null === $row->reconciled_at ? null : (string) $row->reconciled_at ),
			null === $row->reconciled_by ? null : (int) $row->reconciled_by,
			null === $row->reconciliation_note ? null : (string) $row->reconciliation_note,
			(int) $row->payload_schema_version,
			(int) $row->adapter_encoding_version,
			StorageValidator::parse_date_time( null === $row->state_changed_at ? null : (string) $row->state_changed_at )
		);
	}

	/**
	 * Hydrate a delivery record.
	 *
	 * @param object $row Database row.
	 * @return DeliveryRecord
	 */
	private function hydrate( object $row ): DeliveryRecord {
		return $this->hydrate_record( $row );
	}
}
