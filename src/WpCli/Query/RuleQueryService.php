<?php
/**
 * WP-CLI rule query service.
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
 * Reads rule definitions.
 */
final class RuleQueryService extends AbstractQueryService {
	/**
	 * List rules.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $page Page.
	 * @param int                 $per_page Per page.
	 * @return array{data:array<string,mixed>,pagination:array<string,int>}
	 */
	public function list_rules( array $filters, int $page, int $per_page ): array {
		$table = $this->database->table( Schema::RULES );
		$where = array( '1=1' );
		$args  = array();

		$effect = $this->identifier_filter( $filters['effect'] ?? null, 'Effect' );
		if ( null !== $effect ) {
			StorageEnums::assert_rule_effect( $effect );
			$where[] = 'effect = %s';
			$args[]  = $effect;
		}

		$enabled = $this->bool_filter( $filters['enabled'] ?? null, 'Enabled' );
		if ( null !== $enabled ) {
			$where[] = 'enabled = %d';
			$args[]  = $enabled ? 1 : 0;
		}

		$trigger = $this->identifier_filter( $filters['trigger'] ?? null, 'Trigger' );
		if ( null !== $trigger ) {
			StorageEnums::assert_rule_trigger( $trigger );
			$where[] = "JSON_CONTAINS(triggers_json, %s, '$.triggers')";
			$encoded = function_exists( 'wp_json_encode' )
				? wp_json_encode( $trigger, JSON_THROW_ON_ERROR )
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() may not be loaded in isolated query tests.
				: json_encode( $trigger, JSON_THROW_ON_ERROR );
			if ( ! is_string( $encoded ) ) {
				throw new ValidationException( 'Trigger filter could not be encoded.' );
			}

			$args[] = $encoded;
		}

		$sql_where = implode( ' AND ', $where );
		$total     = (int) $this->database->get_var( 'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $sql_where, $args );
		$rows      = $this->database->get_results(
			'SELECT * FROM `' . $table . '` WHERE ' . $sql_where . ' ORDER BY priority ASC, public_id ASC LIMIT %d OFFSET %d',
			array_merge( $args, array( $per_page, $this->offset( $page, $per_page ) ) )
		);

		return $this->list_result(
			array_map(
				static function ( object $row ): array {
					return RecordFormatter::rule_row( $row, false );
				},
				$rows
			),
			$page,
			$per_page,
			$total
		);
	}

	/**
	 * Show one rule.
	 *
	 * @param string $public_id Public ID.
	 * @return array<string,mixed>
	 */
	public function show( string $public_id ): array {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->database->table( Schema::RULES ) . '` WHERE public_id = %s',
			array( $public_id )
		);

		if ( null === $row ) {
			throw new ValidationException( 'Rule does not exist.' );
		}

		return RecordFormatter::rule_row( $row, true );
	}
}
