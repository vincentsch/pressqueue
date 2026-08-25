<?php
/**
 * WP-CLI delivery query service.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli\Query;

use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Repository\DeliveryEventRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * Reads delivery records and events.
 */
final class DeliveryQueryService extends AbstractQueryService {
	/**
	 * List deliveries.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $page Page.
	 * @param int                 $per_page Per page.
	 * @return array{data:array<string,mixed>,pagination:array<string,int>}
	 */
	public function list_deliveries( array $filters, int $page, int $per_page ): array {
		$table          = $this->database->table( Schema::DELIVERIES );
		$decision_table = $this->database->table( Schema::DECISIONS );
		$join           = '';
		$where          = array( '1=1' );
		$args           = array();

		$state = $this->identifier_filter( $filters['state'] ?? null, 'State' );
		if ( null !== $state ) {
			StorageEnums::assert_delivery_state( $state );
			$where[] = 'd.state = %s';
			$args[]  = $state;
		}

		$connection = $this->identifier_filter( $filters['connection'] ?? null, 'Connection' );
		if ( null !== $connection ) {
			PublicIds::assert( $connection );
			$where[] = 'd.connection_public_id = %s';
			$args[]  = $connection;
		}

		$channel = $this->identifier_filter( $filters['channel'] ?? null, 'Channel' );
		if ( null !== $channel ) {
			PublicIds::assert( $channel );
			$where[] = 'd.channel_public_id = %s';
			$args[]  = $channel;
		}

		$post_id = $this->positive_int_filter( $filters['post-id'] ?? null, 'Post ID' );
		if ( null !== $post_id ) {
			$join    = ' INNER JOIN `' . $decision_table . '` dec ON dec.public_id = d.decision_public_id';
			$where[] = 'dec.post_id = %d';
			$args[]  = $post_id;
		}

		$due_retry = $this->bool_filter( $filters['due-retry'] ?? null, 'Due retry' );
		if ( true === $due_retry ) {
			$where[] = 'd.state = %s AND d.next_attempt_at IS NOT NULL AND d.next_attempt_at <= %s';
			$args[]  = 'retrying';
			$args[]  = gmdate( 'Y-m-d H:i:s' );
		} elseif ( false === $due_retry ) {
			$where[] = 'NOT (d.state = %s AND d.next_attempt_at IS NOT NULL AND d.next_attempt_at <= %s)';
			$args[]  = 'retrying';
			$args[]  = gmdate( 'Y-m-d H:i:s' );
		}

		$id = $this->identifier_filter( $filters['id'] ?? null, 'ID' );
		if ( null !== $id ) {
			$where[] = '(d.public_id = %s OR d.delivery_key = %s OR d.remote_id = %s)';
			$args[]  = $id;
			$args[]  = $id;
			$args[]  = $id;
		}

		$this->apply_utc_range( $where, $args, 'd.state_changed_at', $filters['changed-from-utc'] ?? null, $filters['changed-to-utc'] ?? null );

		$sql_where = implode( ' AND ', $where );
		$total     = (int) $this->database->get_var( 'SELECT COUNT(*) FROM `' . $table . '` d' . $join . ' WHERE ' . $sql_where, $args );
		$rows      = $this->database->get_results(
			'SELECT d.* FROM `' . $table . '` d' . $join . ' WHERE ' . $sql_where . ' ORDER BY d.state_changed_at DESC, d.public_id DESC LIMIT %d OFFSET %d',
			array_merge( $args, array( $per_page, $this->offset( $page, $per_page ) ) )
		);
		$repo      = new DeliveryRepository( $this->database );

		return $this->list_result(
			array_map(
				static function ( object $row ) use ( $repo ): array {
					return RecordFormatter::delivery_record( $repo->hydrate_record( $row ) );
				},
				$rows
			),
			$page,
			$per_page,
			$total
		);
	}

	/**
	 * Show one delivery and bounded events.
	 *
	 * @param string $public_id Public ID.
	 * @param int    $per_page Event limit.
	 * @return array<string,mixed>
	 */
	public function show( string $public_id, int $per_page = 50 ): array {
		PublicIds::assert( $public_id );
		$delivery = ( new DeliveryRepository( $this->database ) )->find_by_public_id( $public_id );
		if ( null === $delivery ) {
			throw new ValidationException( 'Delivery does not exist.' );
		}

		$event_table = $this->database->table( Schema::DELIVERY_EVENTS );
		$rows        = $this->database->get_results(
			'SELECT * FROM `' . $event_table . '` WHERE delivery_public_id = %s ORDER BY created_at ASC, public_id ASC LIMIT %d',
			array( $public_id, max( 1, min( 100, $per_page ) ) )
		);

		return RecordFormatter::delivery_record( $delivery, array_map( array( RecordFormatter::class, 'delivery_event_row' ), $rows ) );
	}

	/**
	 * Return the newest event public ID for a delivery.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return string|null
	 */
	public function newest_event_public_id( string $delivery_public_id ): ?string {
		$events = ( new DeliveryEventRepository( $this->database ) )->find_by_delivery_public_id( $delivery_public_id );
		if ( array() === $events ) {
			return null;
		}

		$last = $events[ count( $events ) - 1 ];
		return $last->public_id;
	}
}
