<?php
/**
 * WP-CLI decision query service.
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
 * Reads decision records.
 */
final class DecisionQueryService extends AbstractQueryService {
	/**
	 * List decisions.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $page Page.
	 * @param int                 $per_page Per page.
	 * @return array{data:array<string,mixed>,pagination:array<string,int>}
	 */
	public function list_decisions( array $filters, int $page, int $per_page ): array {
		$table = $this->database->table( Schema::DECISIONS );
		$where = array( '1=1' );
		$args  = array();

		$post_id = $this->positive_int_filter( $filters['post-id'] ?? null, 'Post ID' );
		if ( null !== $post_id ) {
			$where[] = 'post_id = %d';
			$args[]  = $post_id;
		}

		$result = $this->identifier_filter( $filters['result'] ?? null, 'Result' );
		if ( null !== $result ) {
			StorageEnums::assert_decision_result( $result );
			$where[] = 'result = %s';
			$args[]  = $result;
		}

		$trigger = $this->identifier_filter( $filters['trigger'] ?? null, 'Trigger' );
		if ( null !== $trigger ) {
			StorageEnums::assert_rule_trigger( $trigger );
			$where[] = '`trigger` = %s';
			$args[]  = $trigger;
		}

		$scope_public_id = $this->identifier_filter( $filters['scope-public-id'] ?? null, 'Scope public ID' );
		if ( null !== $scope_public_id ) {
			PublicIds::assert( $scope_public_id );
			$where[] = 'scope_public_id = %s';
			$args[]  = $scope_public_id;
		}

		$this->apply_utc_range( $where, $args, 'created_at', $filters['created-from-utc'] ?? null, $filters['created-to-utc'] ?? null );

		$sql_where = implode( ' AND ', $where );
		$total     = (int) $this->database->get_var( 'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $sql_where, $args );
		$rows      = $this->database->get_results(
			'SELECT * FROM `' . $table . '` WHERE ' . $sql_where . ' ORDER BY created_at DESC, public_id DESC LIMIT %d OFFSET %d',
			array_merge( $args, array( $per_page, $this->offset( $page, $per_page ) ) )
		);

		return $this->list_result( array_map( array( RecordFormatter::class, 'decision_row' ), $rows ), $page, $per_page, $total );
	}

	/**
	 * Show one decision.
	 *
	 * @param string $public_id Public ID.
	 * @return array<string,mixed>
	 */
	public function show( string $public_id ): array {
		PublicIds::assert( $public_id );
		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->database->table( Schema::DECISIONS ) . '` WHERE public_id = %s',
			array( $public_id )
		);

		if ( null === $row ) {
			throw new ValidationException( 'Decision does not exist.' );
		}

		return RecordFormatter::decision_row( $row );
	}
}
