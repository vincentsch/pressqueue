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
	 * Active transaction depth.
	 *
	 * @var int
	 */
	private int $transaction_depth = 0;

	/**
	 * After-commit callbacks keyed by transaction depth.
	 *
	 * @var array<int,array<int,callable>>
	 */
	private array $after_commit_callbacks = array();

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
		if ( function_exists( 'add_filter' ) && ! has_filter( 'pre_get_table_charset', array( self::class, 'table_charset' ) ) ) {
			add_filter( 'pre_get_table_charset', array( self::class, 'table_charset' ), 10, 2 );
		}
	}

	/**
	 * Tell WordPress the text charset of our tables with ASCII identity columns.
	 *
	 * @param mixed  $charset Existing filter value.
	 * @param string $table Table name.
	 * @return mixed
	 */
	public static function table_charset( $charset, string $table ) {
		if ( ! self::has_global_database() ) {
			return $charset;
		}
		$wpdb = $GLOBALS['wpdb'];
		foreach ( Schema::table_suffixes() as $suffix ) {
			if ( $table === $wpdb->prefix . 'pressqueue_' . $suffix ) {
				// Let WordPress populate per-column metadata before overriding the
				// mixed ASCII/UTF-8 whole-query charset. Column validation still uses
				// its actual database collation.
				remove_filter( 'pre_get_table_charset', array( self::class, 'table_charset' ), 10 );
				try {
					$column_charset = $wpdb->get_col_charset( $table, 'public_id' );
				} finally {
					add_filter( 'pre_get_table_charset', array( self::class, 'table_charset' ), 10, 2 );
				}
				return is_wp_error( $column_charset ) ? $column_charset : $wpdb->charset;
			}
		}
		return $charset;
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
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Values are prepared and identifiers are internal.
		$result = $this->quiet_query( fn () => $this->wpdb->query( $prepared ) );

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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Values are bound by the prepare() wrapper above; identifiers are validated internally.
		$row = $this->quiet_query( fn () => $this->wpdb->get_row( $prepared ) );
		$this->assert_read_succeeded();

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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Values are bound by the prepare() wrapper above; identifiers are validated internally.
		$value = $this->quiet_query( fn () => $this->wpdb->get_var( $prepared ) );
		$this->assert_read_succeeded();

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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Values are bound by the prepare() wrapper above; identifiers are validated internally.
		$rows = $this->quiet_query( fn () => $this->wpdb->get_results( $prepared ) );
		$this->assert_read_succeeded();

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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Values are bound by the prepare() wrapper above; identifiers are validated internally.
		$rows = $this->quiet_query( fn () => $this->wpdb->get_results( $prepared, ARRAY_A ) );
		$this->assert_read_succeeded();

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
	 * @param callable():T      $callback Transactional work.
	 * @param array<int,string> $table_suffixes PressQueue table suffixes touched by the transaction.
	 * @return T
	 */
	public function transaction( callable $callback, array $table_suffixes = array() ) {
		$this->assert_transaction_capable_tables( $table_suffixes );
		$depth = $this->transaction_depth + 1;

		if ( 1 === $depth ) {
			$this->query( 'START TRANSACTION' );
		} else {
			$this->query( 'SAVEPOINT pressqueue_sp_' . $depth );
		}

		$this->transaction_depth = $depth;

		try {
			$result = $callback();

			if ( 1 === $depth ) {
				$this->query( 'COMMIT' );
				$callbacks                    = $this->after_commit_callbacks[1] ?? array();
				$this->after_commit_callbacks = array();
				$this->transaction_depth      = 0;
				$this->dispatch_after_commit_callbacks( $callbacks );
			} else {
				$this->query( 'RELEASE SAVEPOINT pressqueue_sp_' . $depth );
				$callbacks = $this->after_commit_callbacks[ $depth ] ?? array();
				unset( $this->after_commit_callbacks[ $depth ] );
				$this->after_commit_callbacks[ $depth - 1 ] = array_merge( $this->after_commit_callbacks[ $depth - 1 ] ?? array(), $callbacks );
				$this->transaction_depth                    = $depth - 1;
			}

			return $result;
		} catch ( Throwable $throwable ) {
			try {
				if ( 1 === $depth ) {
					$this->query( 'ROLLBACK' );
					$this->after_commit_callbacks = array();
					$this->transaction_depth      = 0;
				} else {
					$this->query( 'ROLLBACK TO SAVEPOINT pressqueue_sp_' . $depth );
					$this->query( 'RELEASE SAVEPOINT pressqueue_sp_' . $depth );
					unset( $this->after_commit_callbacks[ $depth ] );
					$this->transaction_depth = $depth - 1;
				}
			} catch ( Throwable $rollback_failure ) {
				unset( $rollback_failure );

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained exceptions are not rendered directly.
				throw new StorageException( 'Database rollback failed after a PressQueue transition error.', 0, $throwable );
			}

			throw $throwable;
		}
	}

	/**
	 * Queue work to run only after the outermost successful commit.
	 *
	 * @param callable $callback Callback.
	 */
	public function after_commit( callable $callback ): void {
		if ( 0 === $this->transaction_depth ) {
			$this->dispatch_after_commit_callbacks( array( $callback ) );
			return;
		}

		$this->after_commit_callbacks[ $this->transaction_depth ][] = $callback;
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
	 * Keep raw SQL and stored payloads out of WordPress error output and logs.
	 *
	 * @template T
	 * @param callable():T $callback Query operation.
	 * @return T
	 */
	private function quiet_query( callable $callback ) {
		$previous = $this->wpdb->suppress_errors( true );
		try {
			return $callback();
		} finally {
			$this->wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Refuse to interpret a failed read as missing configuration or history.
	 */
	private function assert_read_succeeded(): void {
		if ( '' !== (string) $this->wpdb->last_error ) {
			throw new StorageException( 'PressQueue could not read its stored data.' );
		}
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
	 * Assert that every touched PressQueue table uses a transaction-capable engine.
	 *
	 * @param array<int,string> $table_suffixes PressQueue table suffixes.
	 */
	private function assert_transaction_capable_tables( array $table_suffixes ): void {
		if ( array() === $table_suffixes ) {
			return;
		}

		$tables = array();
		foreach ( $table_suffixes as $suffix ) {
			$tables[] = $this->table( $suffix );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $tables ), '%s' ) );
		$rows         = $this->get_results_array(
			'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
			$tables
		);
		$engines      = array();

		foreach ( $rows as $row ) {
			if ( isset( $row['TABLE_NAME'], $row['ENGINE'] ) ) {
				$engines[ (string) $row['TABLE_NAME'] ] = strtoupper( (string) $row['ENGINE'] );
			}
		}

		foreach ( $tables as $table ) {
			if ( ! isset( $engines[ $table ] ) ) {
				throw new StorageUnavailableException( 'PressQueue transition table is missing.' );
			}

			if ( 'INNODB' !== $engines[ $table ] ) {
				throw new StorageUnavailableException( 'PressQueue transition table does not support transactions.' );
			}
		}
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

	/**
	 * Dispatch best-effort after-commit callbacks.
	 *
	 * @param array<int,callable> $callbacks Callbacks.
	 */
	private function dispatch_after_commit_callbacks( array $callbacks ): void {
		foreach ( $callbacks as $callback ) {
			try {
				call_user_func( $callback );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
			}
		}
	}
}
