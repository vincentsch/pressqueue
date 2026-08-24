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
		'id'                   => '%d',
		'public_id'            => '%s',
		'decision_id'          => '%d',
		'decision_public_id'   => '%s',
		'event_key'            => '%s',
		'delivery_key'         => '%s',
		'delivery_mode'        => '%s',
		'provider'             => '%s',
		'connection_id'        => '%d',
		'connection_public_id' => '%s',
		'channel_id'           => '%d',
		'channel_public_id'    => '%s',
		'remote_channel_id'    => '%s',
		'rule_public_id'       => '%s',
		'rule_version'         => '%d',
		'state'                => '%s',
		'schedule_slot'        => '%s',
		'payload_preview_json' => '%s',
		'payload_hash'         => '%s',
		'created_at'           => '%s',
		'updated_at'           => '%s',
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
						'public_id'            => $public_id,
						'decision_id'          => $prepared['decision_id'],
						'decision_public_id'   => $prepared['decision_public_id'],
						'event_key'            => $prepared['event_key'],
						'delivery_key'         => $prepared['delivery_key'],
						'delivery_mode'        => $prepared['delivery_mode'],
						'provider'             => $prepared['provider'],
						'connection_id'        => $prepared['connection_id'],
						'connection_public_id' => $prepared['connection_public_id'],
						'channel_id'           => $prepared['channel_id'],
						'channel_public_id'    => $prepared['channel_public_id'],
						'remote_channel_id'    => $prepared['remote_channel_id'],
						'rule_public_id'       => $prepared['rule_public_id'],
						'rule_version'         => $prepared['rule_version'],
						'state'                => $prepared['state'],
						'schedule_slot'        => $prepared['schedule_slot'],
						'payload_preview_json' => $prepared['payload_preview_json'],
						'payload_hash'         => $prepared['payload_hash'],
						'created_at'           => $now,
						'updated_at'           => $now,
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
				'rule_public_id'       => $decision->rule_public_id,
				'rule_version'         => $decision->rule_version,
				'schedule_slot'        => $schedule_slot,
				'trigger'              => $decision->trigger,
			)
		);

		return array(
			'decision_id'          => $decision->id,
			'decision_public_id'   => $decision->public_id,
			'event_key'            => $decision->event_key,
			'delivery_key'         => HashKeys::delivery_key( $delivery_mode, $provider, $connection->public_id, $channel->public_id, $remote_channel_id, $decision->post_id, $decision->revision_fingerprint, $decision->trigger, $decision->rule_public_id, $decision->rule_version, $schedule_slot ),
			'delivery_mode'        => $delivery_mode,
			'provider'             => $provider,
			'connection_id'        => $connection->id,
			'connection_public_id' => $connection->public_id,
			'channel_id'           => $channel->id,
			'channel_public_id'    => $channel->public_id,
			'remote_channel_id'    => $remote_channel_id,
			'rule_public_id'       => $decision->rule_public_id,
			'rule_version'         => $decision->rule_version,
			'state'                => $state,
			'schedule_slot'        => $schedule_slot,
			'payload_preview'      => $payload_preview,
			'payload_preview_json' => $payload_preview_json,
			'payload_hash'         => $payload_hash,
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
	 * Hydrate a delivery record.
	 *
	 * @param object $row Database row.
	 * @return DeliveryRecord
	 */
	private function hydrate( object $row ): DeliveryRecord {
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
			StorageValidator::parse_required_date_time( (string) $row->updated_at )
		);
	}
}
