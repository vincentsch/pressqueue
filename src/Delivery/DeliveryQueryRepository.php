<?php
/**
 * Delivery log query repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Storage\Database;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Runs focused delivery log queries over the current projection.
 */
final class DeliveryQueryRepository {
	public const DEFAULT_PAGE_SIZE = 50;
	public const MAX_PAGE_SIZE     = 100;

	private const DELIVERY_INDEX_HINT = 'USE INDEX (state_changed_id, connection_public_id, channel_public_id, remote_id, delivery_key, intent_occurrence_key, public_id)';

	/**
	 * Database wrapper.
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * Delivery repository.
	 *
	 * @var DeliveryRepository
	 */
	private DeliveryRepository $deliveries;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database wrapper.
	 */
	public function __construct( ?Database $database = null ) {
		$this->database   = $database ?? Database::from_globals();
		$this->deliveries = new DeliveryRepository( $this->database );
	}

	/**
	 * Query delivery records.
	 *
	 * @param array<string,mixed> $filters Read-only filters.
	 * @param int                 $page Page number.
	 * @param int                 $page_size Page size.
	 * @return array{rows:array<int,DeliveryRecord>,total:int,page:int,page_size:int,offset:int,filters:array<string,mixed>}
	 */
	public function query( array $filters = array(), int $page = 1, int $page_size = self::DEFAULT_PAGE_SIZE ): array {
		$page      = max( 1, $page );
		$page_size = max( 1, min( self::MAX_PAGE_SIZE, $page_size ) );
		$offset    = ( $page - 1 ) * $page_size;
		$prepared  = $this->prepare_filters( $filters );
		$table     = $this->database->table( Schema::DELIVERIES );
		$where     = array() === $prepared['clauses'] ? '' : ' WHERE ' . implode( ' AND ', $prepared['clauses'] );
		$args      = $prepared['args'];

		$total = (int) $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $table . '` ' . self::DELIVERY_INDEX_HINT . $where,
			$args
		);

		$rows = $this->database->get_results(
			'SELECT * FROM `' . $table . '` ' . self::DELIVERY_INDEX_HINT . $where . ' ORDER BY state_changed_at DESC, id DESC LIMIT %d OFFSET %d',
			array_merge( $args, array( $page_size, $offset ) )
		);

		return array(
			'filters'   => $prepared['normalized'],
			'offset'    => $offset,
			'page'      => $page,
			'page_size' => $page_size,
			'rows'      => array_map( array( $this->deliveries, 'hydrate_record' ), $rows ),
			'total'     => $total,
		);
	}

	/**
	 * Normalize and validate list filters.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array{clauses:array<int,string>,args:array<int,mixed>,normalized:array<string,mixed>}
	 */
	private function prepare_filters( array $filters ): array {
		$clauses    = array();
		$args       = array();
		$normalized = array();

		$state = $this->optional_string( $filters, 'state' );
		if ( null !== $state ) {
			StorageEnums::assert_delivery_state( $state );
			$clauses[]           = 'state = %s';
			$args[]              = $state;
			$normalized['state'] = $state;
		}

		$destination_public_id = $this->optional_string( $filters, 'destination_public_id' );
		if ( null !== $destination_public_id ) {
			PublicIds::assert( $destination_public_id );
			$clauses[]                           = '(connection_public_id = %s OR channel_public_id = %s)';
			$args[]                              = $destination_public_id;
			$args[]                              = $destination_public_id;
			$normalized['destination_public_id'] = $destination_public_id;
		}

		$connection_public_id = $this->optional_string( $filters, 'connection_public_id' );
		if ( null !== $connection_public_id ) {
			PublicIds::assert( $connection_public_id );
			$clauses[]                          = 'connection_public_id = %s';
			$args[]                             = $connection_public_id;
			$normalized['connection_public_id'] = $connection_public_id;
		}

		$channel_public_id = $this->optional_string( $filters, 'channel_public_id' );
		if ( null !== $channel_public_id ) {
			PublicIds::assert( $channel_public_id );
			$clauses[]                       = 'channel_public_id = %s';
			$args[]                          = $channel_public_id;
			$normalized['channel_public_id'] = $channel_public_id;
		}

		$intent_occurrence_key = $this->optional_string( $filters, 'intent_occurrence_key' );
		if ( null !== $intent_occurrence_key ) {
			StorageValidator::assert_hash( $intent_occurrence_key );
			$clauses[]                           = 'intent_occurrence_key = %s';
			$args[]                              = $intent_occurrence_key;
			$normalized['intent_occurrence_key'] = $intent_occurrence_key;
		}

		$post_id = $this->optional_int( $filters, 'post_id' );
		if ( null !== $post_id ) {
			StorageValidator::assert_unsigned_id( 'Post ID', $post_id );
			$clauses[]             = 'decision_id IN (SELECT id FROM `' . $this->database->table( Schema::DECISIONS ) . '` WHERE post_id = %d)';
			$args[]                = $post_id;
			$normalized['post_id'] = $post_id;
		}

		$from = $this->optional_string( $filters, 'state_changed_from' );
		if ( null !== $from ) {
			$from_utc                             = $this->local_date_boundary_utc( $from, false );
			$clauses[]                            = 'state_changed_at >= %s';
			$args[]                               = $from_utc;
			$normalized['state_changed_from']     = $from;
			$normalized['state_changed_from_utc'] = $from_utc;
		}

		$to = $this->optional_string( $filters, 'state_changed_to' );
		if ( null !== $to ) {
			$to_utc                             = $this->local_date_boundary_utc( $to, true );
			$clauses[]                          = 'state_changed_at < %s';
			$args[]                             = $to_utc;
			$normalized['state_changed_to']     = $to;
			$normalized['state_changed_to_utc'] = $to_utc;
		}

		$due_retry = $this->optional_due_retry( $filters );
		if ( null !== $due_retry ) {
			$now = StorageValidator::now();
			if ( $due_retry ) {
				$clauses[] = '(state = %s AND next_attempt_at IS NOT NULL AND next_attempt_at <= %s)';
				$args[]    = 'retrying';
				$args[]    = $now;
			} else {
				$clauses[] = 'NOT (state = %s AND next_attempt_at IS NOT NULL AND next_attempt_at <= %s)';
				$args[]    = 'retrying';
				$args[]    = $now;
			}
			$normalized['due_retry'] = $due_retry ? '1' : '0';
		}

		$identifier = $this->optional_identifier( $filters );
		if ( null !== $identifier ) {
			$clauses[]                = '(public_id = %s OR delivery_key = %s OR intent_occurrence_key = %s OR (remote_id = %s AND BINARY remote_id = BINARY %s))';
			$args[]                   = $identifier;
			$args[]                   = $identifier;
			$args[]                   = $identifier;
			$args[]                   = $identifier;
			$args[]                   = $identifier;
			$normalized['identifier'] = $identifier;
		}

		return array(
			'args'       => $args,
			'clauses'    => $clauses,
			'normalized' => $normalized,
		);
	}

	/**
	 * Return an optional trimmed string.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param string              $key Filter key.
	 * @return string|null
	 */
	private function optional_string( array $filters, string $key ): ?string {
		if ( ! array_key_exists( $key, $filters ) || null === $filters[ $key ] ) {
			return null;
		}

		$value = trim( (string) $filters[ $key ] );

		return '' === $value ? null : $value;
	}

	/**
	 * Return an optional positive integer.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param string              $key Filter key.
	 * @return int|null
	 */
	private function optional_int( array $filters, string $key ): ?int {
		$value = $this->optional_string( $filters, $key );

		if ( null === $value ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\d+$/', $value ) ) {
			throw new ValidationException( 'ID filters must contain only digits.' );
		}

		return (int) $value;
	}

	/**
	 * Return an optional due-retry flag.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return bool|null
	 */
	private function optional_due_retry( array $filters ): ?bool {
		$value = $this->optional_string( $filters, 'due_retry' );

		if ( null === $value ) {
			return null;
		}

		if ( '1' === $value ) {
			return true;
		}

		if ( '0' === $value ) {
			return false;
		}

		throw new ValidationException( 'Due retry filter must be 1 or 0.' );
	}

	/**
	 * Return an exact identifier filter.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return string|null
	 */
	private function optional_identifier( array $filters ): ?string {
		$value = $this->optional_string( $filters, 'identifier' );

		if ( null === $value ) {
			return null;
		}

		if ( strlen( $value ) > 191 ) {
			$value = substr( $value, 0, 191 );
		}

		return $value;
	}

	/**
	 * Convert a site-local date to a UTC storage boundary.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @param bool   $next_day Whether to return the following local day.
	 * @return string
	 */
	private function local_date_boundary_utc( string $date, bool $next_day ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			throw new ValidationException( 'Date filters must use YYYY-MM-DD.' );
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$local    = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' 00:00:00', $timezone );

		if ( false === $local || $local->format( 'Y-m-d' ) !== $date ) {
			throw new ValidationException( 'Date filters must use valid site-local dates.' );
		}

		if ( $next_day ) {
			$local = $local->modify( '+1 day' );
		}

		return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
