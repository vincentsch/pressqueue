<?php
/**
 * Delivery event repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\ObservationPayloads;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\DeliveryEventRecord;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Appends and reads delivery events.
 */
final class DeliveryEventRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                 => '%d',
		'public_id'          => '%s',
		'delivery_id'        => '%d',
		'delivery_public_id' => '%s',
		'event_type'         => '%s',
		'from_state'         => '%s',
		'to_state'           => '%s',
		'attempt_number'     => '%d',
		'action_id'          => '%d',
		'actor_type'         => '%s',
		'actor_user_id'      => '%d',
		'provider_result'    => '%s',
		'retry_safety'       => '%s',
		'detail_json'        => '%s',
		'created_at'         => '%s',
	);

	/**
	 * Append one event.
	 *
	 * @param array<string,mixed> $input Event values.
	 * @return DeliveryEventRecord
	 */
	public function append( array $input ): DeliveryEventRecord {
		$prepared = $this->prepare_event( $input );
		$table    = $this->delivery_events_table();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'          => $public_id,
						'delivery_id'        => $prepared['delivery_id'],
						'delivery_public_id' => $prepared['delivery_public_id'],
						'event_type'         => $prepared['event_type'],
						'from_state'         => $prepared['from_state'],
						'to_state'           => $prepared['to_state'],
						'attempt_number'     => $prepared['attempt_number'],
						'action_id'          => $prepared['action_id'],
						'actor_type'         => $prepared['actor_type'],
						'actor_user_id'      => $prepared['actor_user_id'],
						'provider_result'    => $prepared['provider_result'],
						'retry_safety'       => $prepared['retry_safety'],
						'detail_json'        => $prepared['detail_json'],
						'created_at'         => $prepared['created_at'],
					),
					self::FORMATS
				);

				$record = $this->find_by_public_id( $public_id );
				if ( null === $record ) {
					throw new StorageException( 'Created delivery event could not be loaded.' );
				}

				if ( function_exists( 'do_action' ) ) {
					$delivery_key = $this->database->get_var(
						'SELECT delivery_key FROM `' . $this->deliveries_table() . '` WHERE public_id = %s',
						array( $record->delivery_public_id )
					);
					$payload      = ObservationPayloads::delivery_event( $record, $delivery_key );
					$this->database->after_commit(
						static function () use ( $payload ): void {
							do_action( 'pressqueue_delivery_event_recorded', $payload );
						}
					);
				}

				return $record;
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue delivery event public ID.' );
	}

	/**
	 * Find an event by public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return DeliveryEventRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?DeliveryEventRecord {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->delivery_events_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return events for a delivery.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return array<int,DeliveryEventRecord>
	 */
	public function find_by_delivery_public_id( string $delivery_public_id ): array {
		PublicIds::assert( $delivery_public_id );

		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->delivery_events_table() . '` WHERE delivery_public_id = %s ORDER BY created_at ASC, id ASC',
			array( $delivery_public_id )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return events for a delivery using the delivery-created index.
	 *
	 * @param int $delivery_id Internal delivery ID.
	 * @return array<int,DeliveryEventRecord>
	 */
	public function find_by_delivery_id( int $delivery_id ): array {
		StorageValidator::assert_unsigned_id( 'Delivery ID', $delivery_id );

		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->delivery_events_table() . '` USE INDEX (delivery_created) WHERE delivery_id = %d ORDER BY created_at ASC, id ASC',
			array( $delivery_id )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return the scheduler event that created a worker action.
	 *
	 * @param int $delivery_id Internal delivery ID.
	 * @param int $action_id Action Scheduler action ID.
	 * @return DeliveryEventRecord|null
	 */
	public function find_scheduled_action_event( int $delivery_id, int $action_id ): ?DeliveryEventRecord {
		StorageValidator::assert_unsigned_id( 'Delivery ID', $delivery_id );
		StorageValidator::assert_unsigned_id( 'Action ID', $action_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->delivery_events_table() . '` USE INDEX (action_id) WHERE action_id = %d AND delivery_id = %d AND event_type = %s ORDER BY created_at DESC, id DESC LIMIT 1',
			array( $action_id, $delivery_id, 'scheduler_action_scheduled' )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Check whether an attempt has a submission-started event.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @param int    $attempt_number Attempt number.
	 * @return bool
	 */
	public function has_submission_started( string $delivery_public_id, int $attempt_number ): bool {
		PublicIds::assert( $delivery_public_id );
		StorageValidator::assert_unsigned_id( 'Attempt number', $attempt_number );

		$count = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $this->delivery_events_table() . '` WHERE delivery_public_id = %s AND event_type = %s AND attempt_number = %d',
			array( $delivery_public_id, 'submission_started', $attempt_number )
		);

		return (int) $count > 0;
	}

	/**
	 * Check whether a delivery has an event type.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @param string $event_type Event type.
	 * @return bool
	 */
	public function has_event_type( string $delivery_public_id, string $event_type ): bool {
		PublicIds::assert( $delivery_public_id );
		StorageEnums::assert_delivery_event_type( $event_type );

		$count = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $this->delivery_events_table() . '` WHERE delivery_public_id = %s AND event_type = %s',
			array( $delivery_public_id, $event_type )
		);

		return (int) $count > 0;
	}

	/**
	 * Prepare event values.
	 *
	 * @param array<string,mixed> $input Event input.
	 * @return array<string,mixed>
	 */
	private function prepare_event( array $input ): array {
		$delivery_id = (int) ( $input['delivery_id'] ?? 0 );
		StorageValidator::assert_unsigned_id( 'Delivery ID', $delivery_id );

		$delivery_public_id = (string) ( $input['delivery_public_id'] ?? '' );
		PublicIds::assert( $delivery_public_id );

		$event_type = (string) ( $input['event_type'] ?? '' );
		StorageEnums::assert_delivery_event_type( $event_type );

		$from_state = $input['from_state'] ?? null;
		if ( null !== $from_state ) {
			$from_state = (string) $from_state;
			StorageEnums::assert_delivery_state( $from_state );
		}

		$to_state = $input['to_state'] ?? null;
		if ( null !== $to_state ) {
			$to_state = (string) $to_state;
			StorageEnums::assert_delivery_state( $to_state );
		}

		$attempt_number = $input['attempt_number'] ?? null;
		if ( null !== $attempt_number ) {
			$attempt_number = (int) $attempt_number;
			StorageValidator::assert_unsigned_id( 'Attempt number', $attempt_number );
		}

		$action_id = $input['action_id'] ?? null;
		if ( null !== $action_id ) {
			$action_id = (int) $action_id;
			StorageValidator::assert_unsigned_id( 'Action ID', $action_id );
		}

		$actor_type = (string) ( $input['actor_type'] ?? 'system' );
		StorageEnums::assert_delivery_actor_type( $actor_type );

		$actor_user_id = $input['actor_user_id'] ?? null;
		if ( null !== $actor_user_id ) {
			$actor_user_id = (int) $actor_user_id;
			StorageValidator::assert_unsigned_id( 'Actor user ID', $actor_user_id, true );
		}

		$provider_result = $input['provider_result'] ?? null;
		if ( null !== $provider_result ) {
			$provider_result = (string) $provider_result;
			StorageEnums::assert_provider_result( $provider_result );
		}

		$retry_safety = $input['retry_safety'] ?? null;
		if ( null !== $retry_safety ) {
			$retry_safety = (string) $retry_safety;
			StorageEnums::assert_retry_safety( $retry_safety );
		}

		$detail = $input['detail'] ?? array();
		if ( ! is_array( $detail ) || array_is_list( $detail ) ) {
			throw new ValidationException( 'Delivery event detail must be an object.' );
		}

		$detail_json = CanonicalJson::encode_object( $detail );
		CanonicalJson::assert_maximum_size( $detail_json, Schema::EVENT_JSON_MAX_BYTES );

		return array(
			'delivery_id'        => $delivery_id,
			'delivery_public_id' => $delivery_public_id,
			'event_type'         => $event_type,
			'from_state'         => $from_state,
			'to_state'           => $to_state,
			'attempt_number'     => $attempt_number,
			'action_id'          => $action_id,
			'actor_type'         => $actor_type,
			'actor_user_id'      => $actor_user_id,
			'provider_result'    => $provider_result,
			'retry_safety'       => $retry_safety,
			'detail_json'        => $detail_json,
			'created_at'         => $input['created_at'] ?? StorageValidator::now(),
		);
	}

	/**
	 * Return the delivery events table.
	 *
	 * @return string
	 */
	private function delivery_events_table(): string {
		return $this->table( Schema::DELIVERY_EVENTS );
	}

	/**
	 * Hydrate an event record.
	 *
	 * @param object $row Database row.
	 * @return DeliveryEventRecord
	 */
	public function hydrate_record( object $row ): DeliveryEventRecord {
		return new DeliveryEventRecord(
			(int) $row->id,
			(string) $row->public_id,
			(int) $row->delivery_id,
			(string) $row->delivery_public_id,
			(string) $row->event_type,
			null === $row->from_state ? null : (string) $row->from_state,
			null === $row->to_state ? null : (string) $row->to_state,
			null === $row->attempt_number ? null : (int) $row->attempt_number,
			null === $row->action_id ? null : (int) $row->action_id,
			(string) $row->actor_type,
			null === $row->actor_user_id ? null : (int) $row->actor_user_id,
			null === $row->provider_result ? null : (string) $row->provider_result,
			null === $row->retry_safety ? null : (string) $row->retry_safety,
			CanonicalJson::decode_object( (string) $row->detail_json ),
			StorageValidator::parse_required_date_time( (string) $row->created_at )
		);
	}

	/**
	 * Hydrate an event record.
	 *
	 * @param object $row Database row.
	 * @return DeliveryEventRecord
	 */
	private function hydrate( object $row ): DeliveryEventRecord {
		return $this->hydrate_record( $row );
	}
}
