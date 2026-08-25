<?php
/**
 * Batch repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Persists manual batch preview and handoff records.
 */
final class BatchRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                    => '%d',
		'public_id'             => '%s',
		'batch_type'            => '%s',
		'status'                => '%s',
		'created_by'            => '%d',
		'confirmed_by'          => '%d',
		'filters_json'          => '%s',
		'preview_summary_json'  => '%s',
		'preview_snapshot_hash' => '%s',
		'batch_limits_json'     => '%s',
		'preview_expires_at'    => '%s',
		'started_at'            => '%s',
		'confirmed_at'          => '%s',
		'completed_at'          => '%s',
		'lock_version'          => '%d',
		'created_at'            => '%s',
		'updated_at'            => '%s',
	);

	/**
	 * Create a preview batch shell.
	 *
	 * @param string              $batch_type Batch type.
	 * @param int                 $created_by Creator user ID.
	 * @param array<string,mixed> $filters Filter document.
	 * @param array<string,mixed> $batch_limits Limit document.
	 * @param string              $preview_expires_at Preview expiry.
	 * @return BatchRecord
	 */
	public function create_preview( string $batch_type, int $created_by, array $filters, array $batch_limits, string $preview_expires_at ): BatchRecord {
		StorageEnums::assert_batch_type( $batch_type );
		StorageValidator::assert_unsigned_id( 'Creator user ID', $created_by, true );
		StorageValidator::assert_utc_timestamp( 'Preview expiry', $preview_expires_at );

		$filters_json = CanonicalJson::encode_object( $filters );
		CanonicalJson::assert_maximum_size( $filters_json, Schema::BATCH_JSON_MAX_BYTES );

		$limits_json = CanonicalJson::encode_object( $batch_limits );
		CanonicalJson::assert_maximum_size( $limits_json, Schema::BATCH_JSON_MAX_BYTES );

		$summary       = array(
			'counts'         => array(),
			'schema_version' => 1,
			'status'         => 'building',
		);
		$summary_json  = CanonicalJson::encode_object( $summary );
		$snapshot_hash = HashKeys::snapshot_hash( $summary );
		$now           = StorageValidator::now();
		$table         = $this->batches_table();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'             => $public_id,
						'batch_type'            => $batch_type,
						'status'                => 'previewed',
						'created_by'            => $created_by,
						'confirmed_by'          => null,
						'filters_json'          => $filters_json,
						'preview_summary_json'  => $summary_json,
						'preview_snapshot_hash' => $snapshot_hash,
						'batch_limits_json'     => $limits_json,
						'preview_expires_at'    => $preview_expires_at,
						'started_at'            => null,
						'confirmed_at'          => null,
						'completed_at'          => null,
						'lock_version'          => 1,
						'created_at'            => $now,
						'updated_at'            => $now,
					),
					self::FORMATS
				);

				$record = $this->find_by_public_id( $public_id );
				if ( null === $record ) {
					throw new StorageException( 'Created batch could not be loaded.' );
				}

				return $record;
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue batch public ID.' );
	}

	/**
	 * Store the final preview summary and snapshot hash.
	 *
	 * @param string                   $public_id Batch public ID.
	 * @param int                      $expected_lock_version Expected lock version.
	 * @param array<string,mixed>      $summary Summary document.
	 * @param string                   $preview_snapshot_hash Snapshot hash.
	 * @param array<string,mixed>|null $batch_limits Final batch limits.
	 * @return BatchRecord
	 */
	public function finalize_preview( string $public_id, int $expected_lock_version, array $summary, string $preview_snapshot_hash, ?array $batch_limits = null ): BatchRecord {
		PublicIds::assert( $public_id );
		StorageValidator::assert_hash( $preview_snapshot_hash );

		$summary_json = CanonicalJson::encode_object( $summary );
		CanonicalJson::assert_maximum_size( $summary_json, Schema::BATCH_JSON_MAX_BYTES );
		$values = array(
			'preview_summary_json'  => $summary_json,
			'preview_snapshot_hash' => $preview_snapshot_hash,
			'lock_version'          => $expected_lock_version + 1,
			'updated_at'            => StorageValidator::now(),
		);

		if ( null !== $batch_limits ) {
			$limits_json = CanonicalJson::encode_object( $batch_limits );
			CanonicalJson::assert_maximum_size( $limits_json, Schema::BATCH_JSON_MAX_BYTES );
			$values['batch_limits_json'] = $limits_json;
		}

		$updated = $this->database->update_rows(
			$this->batches_table(),
			$values,
			array(
				'public_id'    => $public_id,
				'lock_version' => $expected_lock_version,
				'status'       => 'previewed',
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StorageException( 'Batch preview could not be finalized.' );
		}

		$record = $this->find_by_public_id( $public_id );
		if ( null === $record ) {
			throw new StorageException( 'Finalized batch could not be loaded.' );
		}

		return $record;
	}

	/**
	 * Find a batch by public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return BatchRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?BatchRecord {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->batches_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return recent batches for administration.
	 *
	 * @param int $limit Limit.
	 * @param int $offset Offset.
	 * @return array<int,BatchRecord>
	 */
	public function find_recent( int $limit = 50, int $offset = 0 ): array {
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->batches_table() . '` ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
			array( $limit, $offset )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Count stored batches.
	 *
	 * @return int
	 */
	public function count_all(): int {
		return (int) $this->database->get_var( 'SELECT COUNT(*) FROM `' . $this->batches_table() . '`' );
	}

	/**
	 * Return queueing batches needing recovery.
	 *
	 * @param int $limit Limit.
	 * @return array<int,BatchRecord>
	 */
	public function find_queueing( int $limit = 25 ): array {
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $this->database->get_results(
			'SELECT * FROM `' . $this->batches_table() . '` WHERE status = %s ORDER BY confirmed_at ASC, id ASC LIMIT %d',
			array( 'queueing', $limit )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Transition a batch status with optimistic locking.
	 *
	 * @param string              $public_id Public ID.
	 * @param int                 $expected_lock_version Expected lock version.
	 * @param array<int,string>   $from_statuses Allowed source statuses.
	 * @param string              $to_status Target status.
	 * @param array<string,mixed> $values Additional projection values.
	 * @return BatchRecord|null Updated record.
	 */
	public function transition( string $public_id, int $expected_lock_version, array $from_statuses, string $to_status, array $values = array() ): ?BatchRecord {
		PublicIds::assert( $public_id );
		StorageEnums::assert_batch_status( $to_status );
		foreach ( $from_statuses as $status ) {
			StorageEnums::assert_batch_status( $status );
		}

		if ( array() === $from_statuses ) {
			throw new ValidationException( 'Batch transition needs a source status.' );
		}

		$values = array_merge(
			$values,
			array(
				'status'       => $to_status,
				'lock_version' => $expected_lock_version + 1,
				'updated_at'   => StorageValidator::now(),
			)
		);

		$placeholders = implode( ', ', array_fill( 0, count( $from_statuses ), '%s' ) );
		$sets         = array();
		$args         = array();

		foreach ( $values as $field => $value ) {
			if ( null === $value ) {
				$sets[] = '`' . $field . '` = NULL';
				continue;
			}

			$format = self::FORMATS[ $field ] ?? '%s';
			$sets[] = '`' . $field . '` = ' . $format;
			$args[] = '%d' === $format ? (int) $value : $value;
		}

		$args[] = $public_id;
		$args[] = $expected_lock_version;
		$args   = array_merge( $args, $from_statuses );

		$updated = $this->database->query(
			'UPDATE `' . $this->batches_table() . '` SET ' . implode( ', ', $sets ) . ' WHERE public_id = %s AND lock_version = %d AND status IN (' . $placeholders . ')',
			$args
		);

		return 0 === $updated ? null : $this->find_by_public_id( $public_id );
	}

	/**
	 * Expire a preview if its expiry has passed.
	 *
	 * @param BatchRecord $batch Batch.
	 * @return BatchRecord
	 */
	public function expire_if_due( BatchRecord $batch ): BatchRecord {
		if ( 'previewed' !== $batch->status || $batch->preview_expires_at->getTimestamp() > time() ) {
			return $batch;
		}

		return $this->transition(
			$batch->public_id,
			$batch->lock_version,
			array( 'previewed' ),
			'expired',
			array( 'completed_at' => StorageValidator::now() )
		) ?? $batch;
	}

	/**
	 * Hydrate a batch.
	 *
	 * @param object $row Database row.
	 * @return BatchRecord
	 */
	private function hydrate( object $row ): BatchRecord {
		StorageEnums::assert_batch_type( (string) $row->batch_type );
		StorageEnums::assert_batch_status( (string) $row->status );
		StorageValidator::assert_hash( (string) $row->preview_snapshot_hash );

		return new BatchRecord(
			(int) $row->id,
			(string) $row->public_id,
			(string) $row->batch_type,
			(string) $row->status,
			(int) $row->created_by,
			null === $row->confirmed_by ? null : (int) $row->confirmed_by,
			CanonicalJson::decode_object( (string) $row->filters_json ),
			CanonicalJson::decode_object( (string) $row->preview_summary_json ),
			(string) $row->preview_snapshot_hash,
			CanonicalJson::decode_object( (string) $row->batch_limits_json ),
			StorageValidator::parse_required_date_time( (string) $row->preview_expires_at ),
			StorageValidator::parse_date_time( null === $row->started_at ? null : (string) $row->started_at ),
			StorageValidator::parse_date_time( null === $row->confirmed_at ? null : (string) $row->confirmed_at ),
			StorageValidator::parse_date_time( null === $row->completed_at ? null : (string) $row->completed_at ),
			(int) $row->lock_version,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at )
		);
	}
}
