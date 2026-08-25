<?php
/**
 * WP-CLI channel query service.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli\Query;

use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;

/**
 * Reads destination channels.
 */
final class ChannelQueryService extends AbstractQueryService {
	/**
	 * List channels.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $page Page.
	 * @param int                 $per_page Per page.
	 * @return array{data:array<string,mixed>,pagination:array<string,int>}
	 */
	public function list_channels( array $filters, int $page, int $per_page ): array {
		$table = $this->database->table( Schema::CHANNELS );
		$where = array( '1=1' );
		$args  = array();

		$connection = $this->identifier_filter( $filters['connection'] ?? null, 'Connection' );
		if ( null !== $connection ) {
			PublicIds::assert( $connection );
			$where[] = 'connection_public_id = %s';
			$args[]  = $connection;
		}

		$service = $this->identifier_filter( $filters['service'] ?? null, 'Service' );
		if ( null !== $service ) {
			$where[] = 'service = %s';
			$args[]  = $service;
		}

		$availability = $this->identifier_filter( $filters['availability'] ?? null, 'Availability' );
		if ( null !== $availability ) {
			StorageEnums::assert_channel_availability( $availability );
			$where[] = 'availability = %s';
			$args[]  = $availability;
		}

		$enabled = $this->bool_filter( $filters['enabled'] ?? null, 'Enabled' );
		if ( null !== $enabled ) {
			$where[] = 'enabled = %d';
			$args[]  = $enabled ? 1 : 0;
		}

		$sql_where = implode( ' AND ', $where );
		$total     = (int) $this->database->get_var( 'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $sql_where, $args );
		$rows      = $this->database->get_results(
			'SELECT * FROM `' . $table . '` WHERE ' . $sql_where . ' ORDER BY service ASC, public_id ASC LIMIT %d OFFSET %d',
			array_merge( $args, array( $per_page, $this->offset( $page, $per_page ) ) )
		);

		return $this->list_result( array_map( array( RecordFormatter::class, 'channel_row' ), $rows ), $page, $per_page, $total );
	}
}
