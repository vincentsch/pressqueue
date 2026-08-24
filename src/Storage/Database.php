<?php
/**
 * WordPress database wrapper.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use Throwable;

/**
 * Provides prepared access to known PressQueue tables.
 */
final class Database {
	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Check whether a usable global database object exists.
	 *
	 * @return bool
	 */
	public static function has_global_database(): bool {
		return isset( $GLOBALS['wpdb'] )
			&& class_exists( 'wpdb' )
			&& $GLOBALS['wpdb'] instanceof \wpdb;
	}

	/**
	 * Create an instance from the global WordPress database.
	 *
	 * @return self
	 */
	public static function from_globals(): self {
		if ( ! self::has_global_database() ) {
			throw new StorageException( 'WordPress database is not available.' );
		}

		return new self( $GLOBALS['wpdb'] );
	}

	/**
	 * Return the concrete table name for a PressQueue table suffix.
	 *
	 * @param string $suffix Table suffix without the plugin prefix.
	 * @return string
	 */
	public function table( string $suffix ): string {
		if ( 1 !== preg_match( '/^[a-z_]+$/', $suffix ) ) {
			throw new ValidationException( 'Table suffix contains invalid characters.' );
		}

		return $this->wpdb->prefix . 'pressqueue_' . $suffix;
	}

	/**
	 * Return the WordPress table charset and collation clause.
	 *
	 * @return string
	 */
	public function charset_collate(): string {
		return $this->wpdb->get_charset_collate();
	}

	/**
	 * Run a prepared query.
	 *
	 * @param string           $sql SQL with placeholders.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return int Affected row count.
	 */
	public function query( string $sql, array $args = array() ): int {
		$prepared = $this->prepare( $sql, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL is prepared above.
		$result = $this->wpdb->query( $prepared );

		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Database exceptions are not rendered directly.
			throw new StorageException( $this->last_error() );
		}

		return (int) $result;
	}

	/**
	 * Fetch one row from a prepared query.
	 *
	 * @param string           $sql SQL with placeholders.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return object|null
	 */
	public function get_row( string $sql, array $args = array() ): ?object {
		$prepared = $this->prepare( $sql, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL is prepared above.
		$row = $this->wpdb->get_row( $prepared );

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Fetch one column value from a prepared query.
	 *
	 * @param string           $sql SQL with placeholders.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return string|null
	 */
	public function get_var( string $sql, array $args = array() ): ?string {
		$prepared = $this->prepare( $sql, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL is prepared above.
		$value = $this->wpdb->get_var( $prepared );

		if ( null === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Fetch rows from a prepared query.
	 *
	 * @param string           $sql SQL with placeholders.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return array<int,object>
	 */
	public function get_results( string $sql, array $args = array() ): array {
		$prepared = $this->prepare( $sql, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL is prepared above.
		$rows = $this->wpdb->get_results( $prepared );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch associative rows from a prepared query.
	 *
	 * @param string           $sql SQL with placeholders.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results_array( string $sql, array $args = array() ): array {
		$prepared = $this->prepare( $sql, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL is prepared above.
		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert one row using placeholders.
	 *
	 * @param string               $table Table name.
	 * @param array<string,mixed>  $values Field values.
	 * @param array<string,string> $formats Field formats.
	 * @return int Insert ID.
	 */
	public function insert_row( string $table, array $values, array $formats ): int {
		$this->assert_identifier( $table );

		$fields       = array();
		$placeholders = array();
		$args         = array();

		foreach ( $values as $field => $value ) {
			$this->assert_identifier( $field );

			$fields[] = '`' . $field . '`';

			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}

			$format         = $formats[ $field ] ?? '%s';
			$placeholders[] = $format;
			$args[]         = '%d' === $format ? (int) $value : $value;
		}

		$sql = sprintf(
			'INSERT INTO `%s` (%s) VALUES (%s)',
			$table,
			implode( ', ', $fields ),
			implode( ', ', $placeholders )
		);

		$this->query( $sql, $args );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update rows using placeholders.
	 *
	 * @param string               $table Table name.
	 * @param array<string,mixed>  $values Field values.
	 * @param array<string,mixed>  $where Where field values.
	 * @param array<string,string> $formats Field formats.
	 * @return int Affected row count.
	 */
	public function update_rows( string $table, array $values, array $where, array $formats ): int {
		$this->assert_identifier( $table );

		$sets = array();
		$args = array();

		foreach ( $values as $field => $value ) {
			$this->assert_identifier( $field );

			if ( null === $value ) {
				$sets[] = '`' . $field . '` = NULL';
				continue;
			}

			$format = $formats[ $field ] ?? '%s';
			$sets[] = '`' . $field . '` = ' . $format;
			$args[] = '%d' === $format ? (int) $value : $value;
		}

		$conditions = array();
		foreach ( $where as $field => $value ) {
			$this->assert_identifier( $field );

			if ( null === $value ) {
				$conditions[] = '`' . $field . '` IS NULL';
				continue;
			}

			$format       = $formats[ $field ] ?? '%s';
			$conditions[] = '`' . $field . '` = ' . $format;
			$args[]       = '%d' === $format ? (int) $value : $value;
		}

		$sql = sprintf(
			'UPDATE `%s` SET %s WHERE %s',
			$table,
			implode( ', ', $sets ),
			implode( ' AND ', $conditions )
		);

		return $this->query( $sql, $args );
	}

	/**
	 * Run work inside a database transaction.
	 *
	 * @template T
	 * @param callable():T $callback Transactional work.
	 * @return T
	 */
	public function transaction( callable $callback ) {
		$this->query( 'START TRANSACTION' );

		try {
			$result = $callback();
			$this->query( 'COMMIT' );

			return $result;
		} catch ( Throwable $throwable ) {
			$this->query( 'ROLLBACK' );

			throw $throwable;
		}
	}

	/**
	 * Return the last database error, if any.
	 *
	 * @return string
	 */
	public function last_error(): string {
		$error = (string) $this->wpdb->last_error;

		return '' === $error ? 'Database query failed.' : $error;
	}

	/**
	 * Delete an option only if its stored value still matches.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value Expected option value.
	 * @return bool
	 */
	public function delete_option_if_value( string $option, $value ): bool {
		$this->assert_identifier( $this->wpdb->options );

		$deleted = $this->query(
			'DELETE FROM `' . $this->wpdb->options . '` WHERE option_name = %s AND option_value = %s LIMIT 1',
			array( $option, $this->serialize_option_value( $value ) )
		);

		if ( $deleted > 0 && function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' );
		}

		return $deleted > 0;
	}

	/**
	 * Prepare SQL with arguments.
	 *
	 * @param string           $sql SQL with placeholders.
	 * @param array<int,mixed> $args Placeholder values.
	 * @return string
	 */
	private function prepare( string $sql, array $args ): string {
		if ( array() === $args ) {
			return $sql;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Repositories pass SQL with placeholders and separate values.
		$prepared = $this->wpdb->prepare( $sql, $args );

		if ( ! is_string( $prepared ) ) {
			throw new StorageException( 'Database query could not be prepared.' );
		}

		return $prepared;
	}

	/**
	 * Serialize an option value exactly as WordPress stores it.
	 *
	 * @param mixed $value Option value.
	 * @return string
	 */
	private function serialize_option_value( $value ): string {
		if ( function_exists( 'maybe_serialize' ) ) {
			return (string) maybe_serialize( $value );
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores option arrays with PHP serialization.
			return serialize( $value );
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		return (string) $value;
	}

	/**
	 * Assert that a table or field identifier is safe to quote.
	 *
	 * @param string $identifier Identifier.
	 */
	private function assert_identifier( string $identifier ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $identifier ) ) {
			throw new ValidationException( 'Database identifier contains invalid characters.' );
		}
	}
}
