<?php
/**
 * Privacy retention runner.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Privacy;

use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Database;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaStatus;
use Throwable;

/**
 * Deletes or redacts eligible resolved local records in bounded chunks.
 */
final class RetentionService {
	private const CHUNK_SIZE = 100;

	/**
	 * Database wrapper.
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * Settings repository.
	 *
	 * @var PrivacySettings
	 */
	private PrivacySettings $settings;

	/**
	 * State store.
	 *
	 * @var RetentionStateStore
	 */
	private RetentionStateStore $state_store;

	/**
	 * Constructor.
	 *
	 * @param Database|null            $database Database.
	 * @param PrivacySettings|null     $settings Settings.
	 * @param RetentionStateStore|null $state_store State store.
	 */
	public function __construct( ?Database $database = null, ?PrivacySettings $settings = null, ?RetentionStateStore $state_store = null ) {
		$this->database    = $database ?? Database::from_globals();
		$this->settings    = $settings ?? new PrivacySettings();
		$this->state_store = $state_store ?? new RetentionStateStore();
	}

	/**
	 * Return retention families in dependency order.
	 *
	 * @return array<int,string>
	 */
	public static function families(): array {
		return array(
			'delivery_events',
			'batch_items',
			'deliveries',
			'decisions',
			'batches',
			'provider_diagnostics',
		);
	}

	/**
	 * Run one bounded retention pass.
	 *
	 * @return array<string,mixed>
	 */
	public function run(): array {
		if ( RuntimeDisabledLatch::is_closed() ) {
			return array(
				'schema_version' => 1,
				'status'         => 'runtime_disabled',
			);
		}

		if ( ! ( new SchemaStatus( $this->database ) )->is_ready_for_planning() ) {
			return array(
				'schema_version' => 1,
				'status'         => 'storage_not_ready',
			);
		}

		$settings = $this->settings->read();
		if ( ! $settings['retention_enabled'] ) {
			return array(
				'schema_version' => 1,
				'status'         => 'retention_disabled',
			);
		}

		$run = $this->state_store->acquire( $settings );
		if ( null === $run ) {
			return array(
				'schema_version' => 1,
				'status'         => 'already_running',
			);
		}

		try {
			$result = $this->process_run( $run );
			return $result;
		} catch ( Throwable $throwable ) {
			try {
				$this->state_store->fail( $run, $throwable );
			} catch ( Throwable $state_failure ) {
				unset( $state_failure );
			}

			return array(
				'failure'        => SafeOperatorError::failure_marker( $throwable ),
				'schema_version' => 1,
				'status'         => 'failed',
			);
		}
	}

	/**
	 * Process an acquired run.
	 *
	 * @param array<string,mixed> $run Active run.
	 * @return array<string,mixed>
	 */
	private function process_run( array $run ): array {
		$families = self::families();
		$cursor   = is_array( $run['cursor'] ?? null ) ? $run['cursor'] : array(
			'family'        => $families[0],
			'public_id'     => null,
			'timestamp_utc' => null,
		);
		$counters = is_array( $run['counters'] ?? null ) ? $run['counters'] : $this->state_store->empty_counters();
		$cutoff   = (string) $run['cutoff_utc'];
		$index    = array_search( (string) ( $cursor['family'] ?? $families[0] ), $families, true );

		if ( false === $index ) {
			$index = 0;
		}

		$family_count = count( $families );
		for ( $position = $index; $position < $family_count; ++$position ) {
			$family        = $families[ $position ];
			$cursor_family = isset( $cursor['family'] ) ? (string) $cursor['family'] : '';
			$family_cursor = $cursor_family === $family ? $cursor : array(
				'family'        => $family,
				'public_id'     => null,
				'timestamp_utc' => null,
			);
			$result        = $this->process_family( $family, $cutoff, $family_cursor );

			foreach ( $result['counters'] as $name => $value ) {
				$counters[ $family ][ $name ] = (int) ( $counters[ $family ][ $name ] ?? 0 ) + (int) $value;
			}

			$run['counters'] = $counters;

			if ( $result['more'] ) {
				$run['cursor'] = $result['cursor'];
				$this->state_store->progress( $run, $result['cursor'], $counters );

				return array(
					'counters'       => $counters,
					'cursor'         => $result['cursor'],
					'schema_version' => 1,
					'status'         => 'partial',
				);
			}

			$run['cursor'] = array(
				'family'        => $families[ $position + 1 ] ?? $family,
				'public_id'     => null,
				'timestamp_utc' => null,
			);
			$this->state_store->progress( $run, $run['cursor'], $counters );
		}

		$this->state_store->complete( $run );

		return array(
			'counters'       => $counters,
			'schema_version' => 1,
			'status'         => 'complete',
		);
	}

	/**
	 * Process one family.
	 *
	 * @param string              $family Family.
	 * @param string              $cutoff Cutoff UTC.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array{counters:array<string,int>,cursor:array<string,mixed>,more:bool}
	 */
	private function process_family( string $family, string $cutoff, array $cursor ): array {
		if ( 'delivery_events' === $family ) {
			return $this->delete_delivery_events( $cutoff, $cursor );
		}

		if ( 'batch_items' === $family ) {
			return $this->delete_batch_items( $cutoff, $cursor );
		}

		if ( 'deliveries' === $family ) {
			return $this->delete_deliveries( $cutoff, $cursor );
		}

		if ( 'decisions' === $family ) {
			return $this->delete_decisions( $cutoff, $cursor );
		}

		if ( 'batches' === $family ) {
			return $this->delete_batches( $cutoff, $cursor );
		}

		return $this->redact_provider_diagnostics( $cutoff, $cursor );
	}

	/**
	 * Delete eligible delivery events.
	 *
	 * @param string              $cutoff Cutoff UTC.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array{counters:array<string,int>,cursor:array<string,mixed>,more:bool}
	 */
	private function delete_delivery_events( string $cutoff, array $cursor ): array {
		$events     = $this->database->table( Schema::DELIVERY_EVENTS );
		$deliveries = $this->database->table( Schema::DELIVERIES );
		$args       = array( $cutoff, $cutoff );
		$condition  = $this->cursor_condition( 'e.created_at', 'e.public_id', $cursor, $args );
		$rows       = $this->database->get_results_array(
			"SELECT e.public_id, e.created_at AS retention_time
				FROM `$events` e
				INNER JOIN `$deliveries` d ON d.public_id = e.delivery_public_id
				WHERE " . $this->resolved_delivery_sql( 'd' ) . "
					AND COALESCE(d.state_changed_at, d.updated_at, d.created_at) < %s
					AND e.created_at < %s
					$condition
				ORDER BY e.created_at ASC, e.public_id ASC
				LIMIT %d",
			array_merge( $args, array( self::CHUNK_SIZE ) )
		);

		return $this->delete_public_id_rows( Schema::DELIVERY_EVENTS, $rows, 'delivery_events' );
	}

	/**
	 * Delete eligible batch items before delivery rows they reference.
	 *
	 * @param string              $cutoff Cutoff UTC.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array{counters:array<string,int>,cursor:array<string,mixed>,more:bool}
	 */
	private function delete_batch_items( string $cutoff, array $cursor ): array {
		$items      = $this->database->table( Schema::BATCH_ITEMS );
		$batches    = $this->database->table( Schema::BATCHES );
		$deliveries = $this->database->table( Schema::DELIVERIES );
		$args       = array( $cutoff, $cutoff, $cutoff );
		$condition  = $this->cursor_condition( 'i.updated_at', 'i.public_id', $cursor, $args );
		$rows       = $this->database->get_results_array(
			"SELECT i.public_id, i.updated_at AS retention_time
				FROM `$items` i
				INNER JOIN `$batches` b ON b.public_id = i.batch_public_id
				LEFT JOIN `$deliveries` d ON d.public_id = i.delivery_public_id
				WHERE b.status IN ('cancelled', 'expired', 'handed_off')
					AND COALESCE(b.completed_at, b.updated_at, b.created_at) < %s
					AND i.updated_at < %s
					AND (
						i.delivery_public_id IS NULL
						OR d.public_id IS NULL
						OR (" . $this->resolved_delivery_sql( 'd' ) . " AND COALESCE(d.state_changed_at, d.updated_at, d.created_at) < %s)
					)
					$condition
				ORDER BY i.updated_at ASC, i.public_id ASC
				LIMIT %d",
			array_merge( $args, array( self::CHUNK_SIZE ) )
		);

		return $this->delete_public_id_rows( Schema::BATCH_ITEMS, $rows, 'batch_items' );
	}

	/**
	 * Delete eligible resolved deliveries after dependent rows are gone.
	 *
	 * @param string              $cutoff Cutoff UTC.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array{counters:array<string,int>,cursor:array<string,mixed>,more:bool}
	 */
	private function delete_deliveries( string $cutoff, array $cursor ): array {
		$deliveries = $this->database->table( Schema::DELIVERIES );
		$events     = $this->database->table( Schema::DELIVERY_EVENTS );
		$items      = $this->database->table( Schema::BATCH_ITEMS );
		$args       = array( $cutoff );
		$condition  = $this->cursor_condition( 'COALESCE(d.state_changed_at, d.updated_at, d.created_at)', 'd.public_id', $cursor, $args );
		$rows       = $this->database->get_results_array(
			"SELECT d.public_id, COALESCE(d.state_changed_at, d.updated_at, d.created_at) AS retention_time
				FROM `$deliveries` d
				WHERE d.state <> 'accepted' AND " . $this->resolved_delivery_sql( 'd' ) . "
					AND COALESCE(d.state_changed_at, d.updated_at, d.created_at) < %s
					AND NOT EXISTS (SELECT 1 FROM `$events` e WHERE e.delivery_public_id = d.public_id)
					AND NOT EXISTS (
						SELECT 1 FROM `$items` i
						WHERE i.delivery_public_id = d.public_id OR i.duplicate_of_delivery_public_id = d.public_id
					)
					$condition
				ORDER BY retention_time ASC, d.public_id ASC
				LIMIT %d",
			array_merge( $args, array( self::CHUNK_SIZE ) )
		);

		$result   = $this->delete_public_id_rows( Schema::DELIVERIES, $rows, 'deliveries' );
		$marker   = CanonicalJson::encode_object(
			array(
				'schema_version' => 1,
				'redacted'       => 'retention',
			)
		);
		$redacted = $this->database->transaction(
			function () use ( $deliveries, $marker, $cutoff ): int {
				$ids = $this->database->get_results_array(
					"SELECT public_id, decision_public_id FROM `$deliveries` WHERE state = 'accepted' AND COALESCE(state_changed_at, updated_at, created_at) < %s AND payload_preview_json <> %s ORDER BY id ASC LIMIT %d",
					array( $cutoff, $marker, self::CHUNK_SIZE )
				);
				if ( array() === $ids ) {
					return 0;
				}
				$public_ids   = array_column( $ids, 'public_id' );
				$decision_ids = array_column( $ids, 'decision_public_id' );
				$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
				$changed      = $this->database->query(
					"UPDATE `$deliveries` SET payload_preview_json = %s, media_snapshot_json = NULL, receipt_json = NULL, error_detail_json = NULL, reconciliation_note = NULL WHERE public_id IN ($placeholders) AND state = 'accepted' AND payload_preview_json <> %s",
					array_merge( array( $marker ), $public_ids, array( $marker ) )
				);
				$decisions    = $this->database->table( Schema::DECISIONS );
				$this->database->query(
					"UPDATE `$decisions` SET snapshot_json = %s, reasons_json = %s WHERE public_id IN ($placeholders) AND snapshot_json <> %s",
					array_merge( array( $marker, '[]' ), $decision_ids, array( $marker ) )
				);
				return $changed;
			},
			array( Schema::DELIVERIES, Schema::DECISIONS )
		);

		$result['counters']['changed']  += $redacted;
		$result['counters']['redacted'] += $redacted;
		$result['more']                  = $result['more'] || $redacted >= self::CHUNK_SIZE;
		return $result;
	}

	/**
	 * Delete orphaned eligible decisions.
	 *
	 * @param string              $cutoff Cutoff UTC.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array{counters:array<string,int>,cursor:array<string,mixed>,more:bool}
	 */
	private function delete_decisions( string $cutoff, array $cursor ): array {
		$decisions  = $this->database->table( Schema::DECISIONS );
		$deliveries = $this->database->table( Schema::DELIVERIES );
		$items      = $this->database->table( Schema::BATCH_ITEMS );
		$args       = array( $cutoff );
		$condition  = $this->cursor_condition( 'd.created_at', 'd.public_id', $cursor, $args );
		$rows       = $this->database->get_results_array(
			"SELECT d.public_id, d.created_at AS retention_time
				FROM `$decisions` d
				WHERE d.created_at < %s
					AND NOT EXISTS (SELECT 1 FROM `$deliveries` v WHERE v.decision_public_id = d.public_id)
					AND NOT EXISTS (SELECT 1 FROM `$items` i WHERE i.decision_public_id = d.public_id)
					$condition
				ORDER BY d.created_at ASC, d.public_id ASC
				LIMIT %d",
			array_merge( $args, array( self::CHUNK_SIZE ) )
		);

		return $this->delete_public_id_rows( Schema::DECISIONS, $rows, 'decisions' );
	}

	/**
	 * Delete terminal batches after items are gone.
	 *
	 * @param string              $cutoff Cutoff UTC.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array{counters:array<string,int>,cursor:array<string,mixed>,more:bool}
	 */
	private function delete_batches( string $cutoff, array $cursor ): array {
		$batches   = $this->database->table( Schema::BATCHES );
		$items     = $this->database->table( Schema::BATCH_ITEMS );
		$args      = array( $cutoff );
		$condition = $this->cursor_condition( 'COALESCE(b.completed_at, b.updated_at, b.created_at)', 'b.public_id', $cursor, $args );
		$rows      = $this->database->get_results_array(
			"SELECT b.public_id, COALESCE(b.completed_at, b.updated_at, b.created_at) AS retention_time
				FROM `$batches` b
				WHERE b.status IN ('cancelled', 'expired', 'handed_off')
					AND COALESCE(b.completed_at, b.updated_at, b.created_at) < %s
					AND NOT EXISTS (SELECT 1 FROM `$items` i WHERE i.batch_public_id = b.public_id)
					$condition
				ORDER BY retention_time ASC, b.public_id ASC
				LIMIT %d",
			array_merge( $args, array( self::CHUNK_SIZE ) )
		);

		return $this->delete_public_id_rows( Schema::BATCHES, $rows, 'batches' );
	}

	/**
	 * Redact retained provider diagnostics.
	 *
	 * @param string              $cutoff Cutoff UTC.
	 * @param array<string,mixed> $cursor Cursor.
	 * @return array{counters:array<string,int>,cursor:array<string,mixed>,more:bool}
	 */
	private function redact_provider_diagnostics( string $cutoff, array $cursor ): array {
		unset( $cursor );

		$marker      = ExportRedactor::retention_marker_json();
		$deliveries  = $this->database->table( Schema::DELIVERIES );
		$events      = $this->database->table( Schema::DELIVERY_EVENTS );
		$connections = $this->database->table( Schema::CONNECTIONS );
		$changed     = 0;

		$changed += $this->database->query(
			"UPDATE `$deliveries` d
				SET error_detail_json = %s
				WHERE error_detail_json IS NOT NULL
					AND error_detail_json <> %s
					AND " . $this->resolved_delivery_sql( 'd' ) . '
					AND COALESCE(d.state_changed_at, d.updated_at, d.created_at) < %s
				ORDER BY COALESCE(d.state_changed_at, d.updated_at, d.created_at) ASC, d.public_id ASC
				LIMIT %d',
			array( $marker, $marker, $cutoff, self::CHUNK_SIZE )
		);

		if ( $changed < self::CHUNK_SIZE ) {
			$remaining = self::CHUNK_SIZE - $changed;
			$rows      = $this->database->get_results_array(
				"SELECT e.public_id
					FROM `$events` e
					INNER JOIN `$deliveries` d ON d.public_id = e.delivery_public_id
					WHERE e.detail_json <> %s
						AND " . $this->resolved_delivery_sql( 'd' ) . '
						AND COALESCE(d.state_changed_at, d.updated_at, d.created_at) < %s
					ORDER BY e.created_at ASC, e.public_id ASC
					LIMIT %d',
				array( $marker, $cutoff, $remaining )
			);

			$changed += $this->update_json_field_by_public_ids(
				Schema::DELIVERY_EVENTS,
				'detail_json',
				$marker,
				array_map(
					static function ( array $row ): string {
						return (string) $row['public_id'];
					},
					$rows
				)
			);
		}

		if ( $changed < self::CHUNK_SIZE ) {
			$changed += $this->database->query(
				"UPDATE `$connections`
					SET last_error_json = %s
					WHERE last_error_json IS NOT NULL
						AND last_error_json <> %s
						AND connection_status = 'verified'
						AND updated_at < %s
					ORDER BY updated_at ASC, public_id ASC
					LIMIT %d",
				array( $marker, $marker, $cutoff, self::CHUNK_SIZE - $changed )
			);
		}

		return array(
			'counters' => array(
				'changed'  => $changed,
				'deleted'  => 0,
				'redacted' => $changed,
				'scanned'  => $changed,
			),
			'cursor'   => array(
				'family'        => 'provider_diagnostics',
				'public_id'     => null,
				'timestamp_utc' => null,
			),
			'more'     => $changed >= self::CHUNK_SIZE,
		);
	}

	/**
	 * Delete rows by public ID and return counters.
	 *
	 * @param string                         $suffix Table suffix.
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string                         $family Family.
	 * @return array{counters:array<string,int>,cursor:array<string,mixed>,more:bool}
	 */
	private function delete_public_id_rows( string $suffix, array $rows, string $family ): array {
		$deleted = 0;
		if ( array() !== $rows ) {
			$table        = $this->database->table( $suffix );
			$placeholders = implode( ', ', array_fill( 0, count( $rows ), '%s' ) );
			$public_ids   = array_map(
				static function ( array $row ): string {
					return (string) $row['public_id'];
				},
				$rows
			);
			$deleted      = $this->database->transaction(
				function () use ( $table, $placeholders, $public_ids ): int {
					return $this->database->query(
						"DELETE FROM `$table` WHERE public_id IN ($placeholders)",
						$public_ids
					);
				},
				array( $suffix )
			);
		}

		$last = end( $rows );

		return array(
			'counters' => array(
				'changed'  => $deleted,
				'deleted'  => $deleted,
				'redacted' => 0,
				'scanned'  => count( $rows ),
			),
			'cursor'   => array(
				'family'        => $family,
				'public_id'     => is_array( $last ) ? (string) $last['public_id'] : null,
				'timestamp_utc' => is_array( $last ) ? (string) $last['retention_time'] : null,
			),
			'more'     => count( $rows ) >= self::CHUNK_SIZE,
		);
	}

	/**
	 * Update a JSON field for public IDs.
	 *
	 * @param string            $suffix Table suffix.
	 * @param string            $field Field.
	 * @param string            $value JSON value.
	 * @param array<int,string> $public_ids Public IDs.
	 * @return int
	 */
	private function update_json_field_by_public_ids( string $suffix, string $field, string $value, array $public_ids ): int {
		if ( array() === $public_ids ) {
			return 0;
		}

		$table        = $this->database->table( $suffix );
		$placeholders = implode( ', ', array_fill( 0, count( $public_ids ), '%s' ) );

		return $this->database->query(
			"UPDATE `$table` SET `$field` = %s WHERE public_id IN ($placeholders)",
			array_merge( array( $value ), $public_ids )
		);
	}

	/**
	 * Build a cursor condition.
	 *
	 * @param string              $time_expression SQL time expression.
	 * @param string              $public_id_expression SQL public ID expression.
	 * @param array<string,mixed> $cursor Cursor.
	 * @param array<int,mixed>    $args Query args.
	 * @return string
	 */
	private function cursor_condition( string $time_expression, string $public_id_expression, array $cursor, array &$args ): string {
		if ( empty( $cursor['timestamp_utc'] ) || empty( $cursor['public_id'] ) || ! is_string( $cursor['timestamp_utc'] ) || ! is_string( $cursor['public_id'] ) ) {
			return '';
		}

		$args[] = $cursor['timestamp_utc'];
		$args[] = $cursor['timestamp_utc'];
		$args[] = $cursor['public_id'];

		return " AND ($time_expression > %s OR ($time_expression = %s AND $public_id_expression > %s))";
	}

	/**
	 * Return SQL for resolved deliveries.
	 *
	 * @param string $alias Table alias.
	 * @return string
	 */
	private function resolved_delivery_sql( string $alias ): string {
		return "($alias.state IN ('accepted', 'dry_run', 'cancelled') OR ($alias.state = 'ambiguous' AND $alias.reconciliation_status IN ('confirmed_remote_id', 'cancelled')))";
	}
}
