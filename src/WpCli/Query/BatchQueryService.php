<?php
/**
 * WP-CLI batch query service.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli\Query;

use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * Reads batch previews and handoffs.
 */
final class BatchQueryService extends AbstractQueryService {
	/**
	 * List batches.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $page Page.
	 * @param int                 $per_page Per page.
	 * @return array{data:array<string,mixed>,pagination:array<string,int>}
	 */
	public function list_batches( array $filters, int $page, int $per_page ): array {
		$table = $this->database->table( Schema::BATCHES );
		$where = array( '1=1' );
		$args  = array();

		$type = $this->identifier_filter( $filters['type'] ?? null, 'Type' );
		if ( null !== $type ) {
			StorageEnums::assert_batch_type( $type );
			$where[] = 'batch_type = %s';
			$args[]  = $type;
		}

		$status = $this->identifier_filter( $filters['status'] ?? null, 'Status' );
		if ( null !== $status ) {
			StorageEnums::assert_batch_status( $status );
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$created_by = $this->positive_int_filter( $filters['created-by'] ?? null, 'Created by' );
		if ( null !== $created_by ) {
			$where[] = 'created_by = %d';
			$args[]  = $created_by;
		}

		$this->apply_utc_range( $where, $args, 'created_at', $filters['created-from-utc'] ?? null, $filters['created-to-utc'] ?? null );

		$sql_where = implode( ' AND ', $where );
		$total     = (int) $this->database->get_var( 'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $sql_where, $args );
		$rows      = $this->database->get_results(
			'SELECT * FROM `' . $table . '` WHERE ' . $sql_where . ' ORDER BY created_at DESC, public_id DESC LIMIT %d OFFSET %d',
			array_merge( $args, array( $per_page, $this->offset( $page, $per_page ) ) )
		);
		$repo      = new BatchRepository( $this->database );

		return $this->list_result(
			array_map(
				static function ( object $row ) use ( $repo ): array {
					$record = $repo->find_by_public_id( (string) $row->public_id );
					if ( null === $record ) {
						return array();
					}
					return RecordFormatter::batch_record( $record );
				},
				$rows
			),
			$page,
			$per_page,
			$total
		);
	}

	/**
	 * Show a batch with bounded items.
	 *
	 * @param string $public_id Public ID.
	 * @param int    $per_page Item limit.
	 * @return array<string,mixed>
	 */
	public function show( string $public_id, int $per_page = 50 ): array {
		PublicIds::assert( $public_id );
		$batch = ( new BatchRepository( $this->database ) )->find_by_public_id( $public_id );
		if ( null === $batch ) {
			throw new ValidationException( 'Batch does not exist.' );
		}

		$items = array_slice( ( new BatchItemRepository( $this->database ) )->find_by_batch( $batch->public_id ), 0, max( 1, min( 100, $per_page ) ) );

		return RecordFormatter::batch_record( $batch, array_map( array( RecordFormatter::class, 'batch_item_record' ), $items ) );
	}
}
