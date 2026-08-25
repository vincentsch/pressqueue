<?php
/**
 * Shared WP-CLI query helpers.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli\Query;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Query validation exceptions are serialized by command envelopes.

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Storage\Database;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Base class for bounded, exact CLI queries.
 */
abstract class AbstractQueryService {
	/**
	 * Database wrapper.
	 *
	 * @var Database
	 */
	protected Database $database;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database.
	 */
	public function __construct( ?Database $database = null ) {
		$this->database = $database ?? Database::from_globals();
	}

	/**
	 * Return list pagination metadata.
	 *
	 * @param int $page Page.
	 * @param int $per_page Per page.
	 * @param int $total_items Total items.
	 * @return array<string,int>
	 */
	protected function pagination( int $page, int $per_page, int $total_items ): array {
		return array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total_items' => $total_items,
			'total_pages' => 0 === $total_items ? 0 : (int) ceil( $total_items / $per_page ),
		);
	}

	/**
	 * Return an SQL offset.
	 *
	 * @param int $page Page.
	 * @param int $per_page Per page.
	 * @return int
	 */
	protected function offset( int $page, int $per_page ): int {
		return ( $page - 1 ) * $per_page;
	}

	/**
	 * Validate a bounded exact identifier filter.
	 *
	 * @param mixed  $value Value.
	 * @param string $name Field name.
	 * @return string|null
	 */
	protected function identifier_filter( $value, string $name ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( ! is_scalar( $value ) ) {
			throw new ValidationException( $name . ' filter must be a scalar value.' );
		}

		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 191 ) {
			throw new ValidationException( $name . ' filter is out of range.' );
		}

		return $value;
	}

	/**
	 * Validate a boolean 0 or 1 filter.
	 *
	 * @param mixed  $value Value.
	 * @param string $name Field name.
	 * @return bool|null
	 */
	protected function bool_filter( $value, string $name ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( '0' === (string) $value ) {
			return false;
		}

		if ( '1' === (string) $value ) {
			return true;
		}

		throw new ValidationException( $name . ' filter must be 0 or 1.' );
	}

	/**
	 * Validate a positive integer filter.
	 *
	 * @param mixed  $value Value.
	 * @param string $name Field name.
	 * @return int|null
	 */
	protected function positive_int_filter( $value, string $name ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( ! is_numeric( $value ) || (int) $value <= 0 || (string) (int) $value !== (string) $value ) {
			throw new ValidationException( $name . ' filter must be a positive integer.' );
		}

		return (int) $value;
	}

	/**
	 * Add UTC range predicates.
	 *
	 * @param array<int,string> $where WHERE fragments.
	 * @param array<int,mixed>  $args Placeholder args.
	 * @param string            $column Column name.
	 * @param mixed             $from From value.
	 * @param mixed             $to To value.
	 */
	protected function apply_utc_range( array &$where, array &$args, string $column, $from, $to ): void {
		$from = $this->utc_filter( $from, 'from' );
		$to   = $this->utc_filter( $to, 'to' );

		if ( null !== $from && null !== $to && $from >= $to ) {
			throw new ValidationException( 'UTC range end must be after the start.' );
		}

		if ( null !== $from ) {
			$where[] = $column . ' >= %s';
			$args[]  = $from;
		}

		if ( null !== $to ) {
			$where[] = $column . ' < %s';
			$args[]  = $to;
		}
	}

	/**
	 * Validate a UTC DATETIME or RFC 3339 UTC timestamp.
	 *
	 * @param mixed  $value Value.
	 * @param string $name Name.
	 * @return string|null
	 */
	protected function utc_filter( $value, string $name ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			throw new ValidationException( $name . ' timestamp must be a string.' );
		}

		$value = trim( $value );
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			StorageValidator::assert_utc_timestamp( $name, $value );
			return $value;
		}

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
			if ( false !== $date ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}

		throw new ValidationException( $name . ' timestamp must be UTC.' );
	}

	/**
	 * Build a list result.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param int                            $page Page.
	 * @param int                            $per_page Per page.
	 * @param int                            $total_items Total.
	 * @return array{data:array<string,mixed>,pagination:array<string,int>}
	 */
	protected function list_result( array $items, int $page, int $per_page, int $total_items ): array {
		return array(
			'data'       => array( 'items' => $items ),
			'pagination' => $this->pagination( $page, $per_page, $total_items ),
		);
	}
}
