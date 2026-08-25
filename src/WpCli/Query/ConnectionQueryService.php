<?php
/**
 * WP-CLI connection query service.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli\Query;

use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * Reads provider connections without credentials.
 */
final class ConnectionQueryService extends AbstractQueryService {
	/**
	 * List connections.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $page Page.
	 * @param int                 $per_page Per page.
	 * @return array{data:array<string,mixed>,pagination:array<string,int>}
	 */
	public function list_connections( array $filters, int $page, int $per_page ): array {
		$table = $this->database->table( Schema::CONNECTIONS );
		$where = array( '1=1' );
		$args  = array();

		$provider = $this->identifier_filter( $filters['provider'] ?? null, 'Provider' );
		if ( null !== $provider ) {
			$where[] = 'provider = %s';
			$args[]  = $provider;
		}

		$status = $this->identifier_filter( $filters['status'] ?? null, 'Status' );
		if ( null !== $status ) {
			StorageEnums::assert_connection_status( $status );
			$where[] = 'connection_status = %s';
			$args[]  = $status;
		}

		$enabled = $this->bool_filter( $filters['enabled'] ?? null, 'Enabled' );
		if ( null !== $enabled ) {
			$where[] = 'enabled = %d';
			$args[]  = $enabled ? 1 : 0;
		}

		$sql_where = implode( ' AND ', $where );
		$total     = (int) $this->database->get_var( 'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $sql_where, $args );
		$rows      = $this->database->get_results(
			'SELECT * FROM `' . $table . '` WHERE ' . $sql_where . ' ORDER BY updated_at DESC, public_id ASC LIMIT %d OFFSET %d',
			array_merge( $args, array( $per_page, $this->offset( $page, $per_page ) ) )
		);

		return $this->list_result( array_map( array( RecordFormatter::class, 'connection_row' ), $rows ), $page, $per_page, $total );
	}

	/**
	 * Show one connection.
	 *
	 * @param string $public_id Public ID.
	 * @return array<string,mixed>
	 */
	public function show( string $public_id ): array {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->database->table( Schema::CONNECTIONS ) . '` WHERE public_id = %s',
			array( $public_id )
		);

		if ( null === $row ) {
			throw new ValidationException( 'Connection does not exist.' );
		}

		return RecordFormatter::connection_row( $row );
	}
}
