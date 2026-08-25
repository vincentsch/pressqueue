<?php
/**
 * Batch item repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\BatchItemRecord;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;

/**
 * Persists normalized batch item rows.
 */
final class BatchItemRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                              => '%d',
		'public_id'                       => '%s',
		'batch_id'                        => '%d',
		'batch_public_id'                 => '%s',
		'item_key'                        => '%s',
		'intent_occurrence_key'           => '%s',
		'position'                        => '%d',
		'post_id'                         => '%d',
		'channel_public_id'               => '%s',
		'state'                           => '%s',
		'semantic_preview_hash'           => '%s',
		'decision_public_id'              => '%s',
		'delivery_public_id'              => '%s',
		'duplicate_of_delivery_public_id' => '%s',
		'inclusion_rule_public_id'        => '%s',
		'inclusion_rule_version'          => '%d',
		'scheduled_for_utc'               => '%s',
		'preview_item_json'               => '%s',
		'reason_json'                     => '%s',
		'lock_version'                    => '%d',
		'created_at'                      => '%s',
		'updated_at'                      => '%s',
	);

	/**
	 * Allocate a unique public ID for a pending batch item.
	 *
	 * @return string
	 */
	public function new_public_id(): string {
		return $this->generate_unique_public_id( $this->batch_items_table() );
	}

	/**
	 * Create one batch item.
	 *
	 * @param BatchRecord         $batch Batch.
	 * @param array<string,mixed> $input Item fields.
	 * @return BatchItemRecord
	 */
	public function create( BatchRecord $batch, array $input ): BatchItemRecord {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		StorageValidator::assert_unsigned_id( 'Post ID', $post_id );

		$position = (int) ( $input['position'] ?? 0 );
		StorageValidator::assert_unsigned_id( 'Batch item position', $position, true );

		$channel_public_id = null;
		if ( array_key_exists( 'channel_public_id', $input ) && null !== $input['channel_public_id'] && '' !== $input['channel_public_id'] ) {
			$channel_public_id = (string) $input['channel_public_id'];
			PublicIds::assert( $channel_public_id );
		}

		$state = (string) ( $input['state'] ?? '' );
		StorageEnums::assert_batch_item_state( $state );

		$semantic_preview_hash = (string) ( $input['semantic_preview_hash'] ?? '' );
		StorageValidator::assert_hash( $semantic_preview_hash );

		$scheduled_for_utc = null;
		if ( array_key_exists( 'scheduled_for_utc', $input ) && null !== $input['scheduled_for_utc'] && '' !== $input['scheduled_for_utc'] ) {
			$scheduled_for_utc = (string) $input['scheduled_for_utc'];
			StorageValidator::assert_utc_timestamp( 'Scheduled UTC time', $scheduled_for_utc );
		}

		$preview_item = $this->object_document( $input, 'preview_item' );
		$reason       = $this->object_document( $input, 'reason' );
		$now          = StorageValidator::now();
		$table        = $this->batch_items_table();

		$public_id = isset( $input['public_id'] ) && is_string( $input['public_id'] ) && '' !== $input['public_id']
			? $input['public_id']
			: $this->generate_unique_public_id( $table );
		PublicIds::assert( $public_id );

		$expected_item_key = HashKeys::batch_item_key( $batch->public_id, $post_id, $channel_public_id, $semantic_preview_hash );
		$item_key          = isset( $input['item_key'] ) && is_string( $input['item_key'] ) && '' !== $input['item_key']
			? $input['item_key']
			: $expected_item_key;
		StorageValidator::assert_hash( $item_key );
		if ( $item_key !== $expected_item_key ) {
			throw new StorageException( 'Batch item key does not match its reviewed identity.' );
		}

		$occurrence_hash_input = isset( $input['occurrence_semantic_hash'] ) && is_string( $input['occurrence_semantic_hash'] ) ? $input['occurrence_semantic_hash'] : $semantic_preview_hash;
		StorageValidator::assert_hash( $occurrence_hash_input );
		$expected_occurrence_key = HashKeys::intent_occurrence_key( $batch->batch_type, $batch->public_id, $public_id, $post_id, $channel_public_id, $occurrence_hash_input );
		$intent_occurrence_key   = isset( $input['intent_occurrence_key'] ) && is_string( $input['intent_occurrence_key'] ) && '' !== $input['intent_occurrence_key']
			? $input['intent_occurrence_key']
			: $expected_occurrence_key;
		StorageValidator::assert_hash( $intent_occurrence_key );
		if ( $intent_occurrence_key !== $expected_occurrence_key ) {
			throw new StorageException( 'Batch item occurrence key does not match its reviewed identity.' );
		}

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$stored_preview_item = $this->with_occurrence( $preview_item, $intent_occurrence_key, $item_key, $public_id );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'                       => $public_id,
						'batch_id'                        => $batch->id,
						'batch_public_id'                 => $batch->public_id,
						'item_key'                        => $item_key,
						'intent_occurrence_key'           => $intent_occurrence_key,
						'position'                        => $position,
						'post_id'                         => $post_id,
						'channel_public_id'               => $channel_public_id,
						'state'                           => $state,
						'semantic_preview_hash'           => $semantic_preview_hash,
						'decision_public_id'              => $this->nullable_public_id( $input, 'decision_public_id' ),
						'delivery_public_id'              => $this->nullable_public_id( $input, 'delivery_public_id' ),
						'duplicate_of_delivery_public_id' => $this->nullable_public_id( $input, 'duplicate_of_delivery_public_id' ),
						'inclusion_rule_public_id'        => $this->nullable_public_id( $input, 'inclusion_rule_public_id' ),
						'inclusion_rule_version'          => $this->nullable_int( $input, 'inclusion_rule_version' ),
						'scheduled_for_utc'               => $scheduled_for_utc,
						'preview_item_json'               => $this->bounded_object_json( $stored_preview_item ),
						'reason_json'                     => $this->bounded_object_json( $reason ),
						'lock_version'                    => 1,
						'created_at'                      => $now,
						'updated_at'                      => $now,
					),
					self::FORMATS
				);

				$record = $this->find_by_public_id( $public_id );
				if ( null === $record ) {
					throw new StorageException( 'Created batch item could not be loaded.' );
				}

				return $record;
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue batch item public ID.' );
	}

	/**
	 * Find an item by public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return BatchItemRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?BatchItemRecord {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->batch_items_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return all items for one batch.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @return array<int,BatchItemRecord>
	 */
	public function find_by_batch( string $batch_public_id ): array {
		PublicIds::assert( $batch_public_id );

		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->batch_items_table() . '` WHERE batch_public_id = %s ORDER BY position ASC, id ASC',
			array( $batch_public_id )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return reserved items in queue order.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param int    $limit Limit.
	 * @return array<int,BatchItemRecord>
	 */
	public function find_reserved_chunk( string $batch_public_id, int $limit ): array {
		PublicIds::assert( $batch_public_id );
		$limit = max( 1, min( 10, $limit ) );

		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->batch_items_table() . '` WHERE batch_public_id = %s AND state = %s ORDER BY position ASC, id ASC LIMIT %d',
			array( $batch_public_id, 'reserved', $limit )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Lock a still-reserved item for queue handoff.
	 *
	 * @param BatchItemRecord $item Item from the reserved chunk.
	 * @return BatchItemRecord|null
	 */
	public function lock_reserved_for_handoff( BatchItemRecord $item ): ?BatchItemRecord {
		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->batch_items_table() . '` WHERE public_id = %s AND lock_version = %d AND state = %s FOR UPDATE',
			array( $item->public_id, $item->lock_version, 'reserved' )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Count items for one batch by state.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @return array<string,int>
	 */
	public function count_by_state( string $batch_public_id ): array {
		PublicIds::assert( $batch_public_id );

		$rows = $this->database->get_results_array(
			'SELECT state, COUNT(*) AS count FROM `' . $this->batch_items_table() . '` WHERE batch_public_id = %s GROUP BY state',
			array( $batch_public_id )
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['state'] ] = (int) $row['count'];
		}

		return $counts;
	}

	/**
	 * Reserve an unchanged preview item.
	 *
	 * @param BatchItemRecord $item Item.
	 * @return BatchItemRecord|null
	 */
	public function reserve( BatchItemRecord $item ): ?BatchItemRecord {
		return $this->transition( $item, array( 'previewed' ), 'reserved' );
	}

	/**
	 * Mark a reserved item queued.
	 *
	 * @param BatchItemRecord $item Item.
	 * @param string          $decision_public_id Decision public ID.
	 * @param string          $delivery_public_id Delivery public ID.
	 * @param string|null     $scheduled_for_utc Scheduled UTC time.
	 * @return BatchItemRecord|null
	 */
	public function mark_queued( BatchItemRecord $item, string $decision_public_id, string $delivery_public_id, ?string $scheduled_for_utc ): ?BatchItemRecord {
		PublicIds::assert( $decision_public_id );
		PublicIds::assert( $delivery_public_id );
		if ( null !== $scheduled_for_utc ) {
			StorageValidator::assert_utc_timestamp( 'Scheduled UTC time', $scheduled_for_utc );
		}

		return $this->transition(
			$item,
			array( 'reserved' ),
			'queued',
			array(
				'decision_public_id' => $decision_public_id,
				'delivery_public_id' => $delivery_public_id,
				'scheduled_for_utc'  => $scheduled_for_utc,
			)
		);
	}

	/**
	 * Mark an item with a terminal non-queued state.
	 *
	 * @param BatchItemRecord     $item Item.
	 * @param string              $state Target state.
	 * @param array<string,mixed> $reason Reason document.
	 * @return BatchItemRecord|null
	 */
	public function mark_terminal( BatchItemRecord $item, string $state, array $reason ): ?BatchItemRecord {
		StorageEnums::assert_batch_item_state( $state );

		return $this->transition(
			$item,
			array( 'previewed', 'reserved' ),
			$state,
			array( 'reason_json' => $this->bounded_object_json( $reason ) )
		);
	}

	/**
	 * Cancel previewed or reserved items for one batch.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @return int Affected rows.
	 */
	public function cancel_unqueued( string $batch_public_id ): int {
		PublicIds::assert( $batch_public_id );

		return $this->database->query(
			'UPDATE `' . $this->batch_items_table() . '` SET state = %s, lock_version = lock_version + 1, updated_at = %s WHERE batch_public_id = %s AND state IN (%s, %s)',
			array( 'cancelled', StorageValidator::now(), $batch_public_id, 'previewed', 'reserved' )
		);
	}

	/**
	 * Find unresolved repost work for a post and channel.
	 *
	 * @param int         $post_id Post ID.
	 * @param string      $channel_public_id Channel public ID.
	 * @param string|null $exclude_batch_public_id Batch to ignore.
	 * @return BatchItemRecord|null
	 */
	public function find_unresolved_repost_item( int $post_id, string $channel_public_id, ?string $exclude_batch_public_id = null ): ?BatchItemRecord {
		StorageValidator::assert_unsigned_id( 'Post ID', $post_id );
		PublicIds::assert( $channel_public_id );
		if ( null !== $exclude_batch_public_id ) {
			PublicIds::assert( $exclude_batch_public_id );
		}

		$where = 'i.post_id = %d AND i.channel_public_id = %s AND i.state IN (%s, %s, %s) AND b.batch_type = %s AND b.status IN (%s, %s, %s)';
		$args  = array( $post_id, $channel_public_id, 'previewed', 'reserved', 'queued', 'repost', 'previewed', 'confirming', 'queueing' );

		if ( null !== $exclude_batch_public_id ) {
			$where .= ' AND i.batch_public_id <> %s';
			$args[] = $exclude_batch_public_id;
		}

		$row = $this->database->get_row(
			'SELECT i.* FROM `' . $this->batch_items_table() . '` i INNER JOIN `' . $this->batches_table() . '` b ON b.public_id = i.batch_public_id WHERE ' . $where . ' ORDER BY i.created_at ASC, i.id ASC LIMIT 1',
			$args
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Transition one item.
	 *
	 * @param BatchItemRecord     $item Item.
	 * @param array<int,string>   $from_states Source states.
	 * @param string              $to_state Target state.
	 * @param array<string,mixed> $values Additional values.
	 * @return BatchItemRecord|null
	 */
	private function transition( BatchItemRecord $item, array $from_states, string $to_state, array $values = array() ): ?BatchItemRecord {
		StorageEnums::assert_batch_item_state( $to_state );
		foreach ( $from_states as $state ) {
			StorageEnums::assert_batch_item_state( $state );
		}

		$values = array_merge(
			$values,
			array(
				'state'        => $to_state,
				'lock_version' => $item->lock_version + 1,
				'updated_at'   => StorageValidator::now(),
			)
		);

		$sets = array();
		$args = array();
		foreach ( $values as $field => $value ) {
			if ( null === $value ) {
				$sets[] = '`' . $field . '` = NULL';
				continue;
			}

			$format = self::FORMATS[ $field ] ?? '%s';
			$sets[] = '`' . $field . '` = ' . $format;
			$args[] = '%d' === $format ? (int) $value : $value;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $from_states ), '%s' ) );
		$args[]       = $item->public_id;
		$args[]       = $item->lock_version;
		$args         = array_merge( $args, $from_states );

		$updated = $this->database->query(
			'UPDATE `' . $this->batch_items_table() . '` SET ' . implode( ', ', $sets ) . ' WHERE public_id = %s AND lock_version = %d AND state IN (' . $placeholders . ')',
			$args
		);

		return 0 === $updated ? null : $this->find_by_public_id( $item->public_id );
	}

	/**
	 * Return a nullable public ID from input.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $key Key.
	 * @return string|null
	 */
	private function nullable_public_id( array $input, string $key ): ?string {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] || '' === $input[ $key ] ) {
			return null;
		}

		$value = (string) $input[ $key ];
		PublicIds::assert( $value );

		return $value;
	}

	/**
	 * Return a nullable positive integer from input.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $key Key.
	 * @return int|null
	 */
	private function nullable_int( array $input, string $key ): ?int {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] || '' === $input[ $key ] ) {
			return null;
		}

		$value = (int) $input[ $key ];
		StorageValidator::assert_unsigned_id( $key, $value );

		return $value;
	}

	/**
	 * Return an object document from input.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $key Key.
	 * @return array<string,mixed>
	 */
	private function object_document( array $input, string $key ): array {
		if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) || array_is_list( $input[ $key ] ) ) {
			throw new StorageException( 'Batch item document is invalid.' );
		}

		return $input[ $key ];
	}

	/**
	 * Encode a bounded object.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return string
	 */
	private function bounded_object_json( array $document ): string {
		$json = CanonicalJson::encode_object( $document );
		CanonicalJson::assert_maximum_size( $json, Schema::BATCH_JSON_MAX_BYTES );

		return $json;
	}

	/**
	 * Add storage identity to the frozen preview item.
	 *
	 * @param array<string,mixed> $preview_item Preview item.
	 * @param string              $intent_occurrence_key Occurrence key.
	 * @param string              $item_key Item key.
	 * @param string              $batch_item_public_id Item public ID.
	 * @return array<string,mixed>
	 */
	private function with_occurrence( array $preview_item, string $intent_occurrence_key, string $item_key, string $batch_item_public_id ): array {
		$preview_item['batch_item_public_id']  = $batch_item_public_id;
		$preview_item['item_key']              = $item_key;
		$preview_item['intent_occurrence_key'] = $intent_occurrence_key;
		if ( isset( $preview_item['delivery'] ) && is_array( $preview_item['delivery'] ) ) {
			$preview_item['delivery']['intent_occurrence_key'] = $intent_occurrence_key;
			if ( isset( $preview_item['delivery']['payload_preview'] ) && is_array( $preview_item['delivery']['payload_preview'] ) ) {
				$preview_item['delivery']['payload_preview']['intent_occurrence_key'] = $intent_occurrence_key;
			}
		}

		return $preview_item;
	}

	/**
	 * Hydrate an item.
	 *
	 * @param object $row Database row.
	 * @return BatchItemRecord
	 */
	private function hydrate( object $row ): BatchItemRecord {
		StorageEnums::assert_batch_item_state( (string) $row->state );

		return new BatchItemRecord(
			(int) $row->id,
			(string) $row->public_id,
			(int) $row->batch_id,
			(string) $row->batch_public_id,
			(string) $row->item_key,
			(string) $row->intent_occurrence_key,
			(int) $row->position,
			(int) $row->post_id,
			null === $row->channel_public_id ? null : (string) $row->channel_public_id,
			(string) $row->state,
			(string) $row->semantic_preview_hash,
			null === $row->decision_public_id ? null : (string) $row->decision_public_id,
			null === $row->delivery_public_id ? null : (string) $row->delivery_public_id,
			null === $row->duplicate_of_delivery_public_id ? null : (string) $row->duplicate_of_delivery_public_id,
			null === $row->inclusion_rule_public_id ? null : (string) $row->inclusion_rule_public_id,
			null === $row->inclusion_rule_version ? null : (int) $row->inclusion_rule_version,
			StorageValidator::parse_date_time( null === $row->scheduled_for_utc ? null : (string) $row->scheduled_for_utc ),
			CanonicalJson::decode_object( (string) $row->preview_item_json ),
			CanonicalJson::decode_object( (string) $row->reason_json ),
			(int) $row->lock_version,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at )
		);
	}
}
