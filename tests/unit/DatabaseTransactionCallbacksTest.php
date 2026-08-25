<?php
/**
 * Transaction callback tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Storage\Database;
use RuntimeException;

/**
 * @covers \PressQueue\Storage\Database
 */
final class DatabaseTransactionCallbacksTest extends TestCase {
	public function test_after_commit_callbacks_run_after_outer_commit(): void {
		$wpdb     = new TransactionCallbackWpdb();
		$database = new Database( $wpdb );
		$events   = array();

		$result = $database->transaction(
			function () use ( $database, &$events ): string {
				$database->after_commit(
					static function () use ( &$events ): void {
						$events[] = 'outer';
					}
				);
				$database->transaction(
					function () use ( $database, &$events ): void {
						$database->after_commit(
							static function () use ( &$events ): void {
								$events[] = 'inner';
							}
						);
					}
				);
				$events[] = 'before-commit';

				return 'done';
			}
		);

		self::assertSame( 'done', $result );
		self::assertSame( array( 'before-commit', 'outer', 'inner' ), $events );
		self::assertSame(
			array(
				'START TRANSACTION',
				'SAVEPOINT pressqueue_sp_2',
				'RELEASE SAVEPOINT pressqueue_sp_2',
				'COMMIT',
			),
			$wpdb->queries
		);
	}

	public function test_after_commit_callbacks_are_discarded_on_rollback(): void {
		$wpdb     = new TransactionCallbackWpdb();
		$database = new Database( $wpdb );
		$events   = array();

		try {
			$database->transaction(
				function () use ( $database, &$events ): void {
					$database->after_commit(
						static function () use ( &$events ): void {
							$events[] = 'rolled-back';
						}
					);

					throw new RuntimeException( 'fail' );
				}
			);
			self::fail( 'The transaction should throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'fail', $exception->getMessage() );
		}

		self::assertSame( array(), $events );
		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $wpdb->queries );
	}
}

/**
 * Database double that records transaction SQL.
 */
final class TransactionCallbackWpdb extends \wpdb {
	/**
	 * Recorded SQL statements.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();

	/**
	 * Prepare a query for the database wrapper.
	 *
	 * @param string           $query Query.
	 * @param array<int,mixed> $args Args.
	 * @return string
	 */
	public function prepare( $query, $args = array() ): string {
		unset( $args );

		return (string) $query;
	}

	/**
	 * Execute a query.
	 *
	 * @param string $query Query.
	 * @return int
	 */
	public function query( $query ): int {
		$this->queries[] = (string) $query;

		return 1;
	}

	/**
	 * Return no row.
	 *
	 * @param string $query Query.
	 * @return null
	 */
	public function get_row( $query ) {
		unset( $query );

		return null;
	}

	/**
	 * Return no scalar.
	 *
	 * @param string $query Query.
	 * @return null
	 */
	public function get_var( $query ) {
		unset( $query );

		return null;
	}

	/**
	 * Return no rows.
	 *
	 * @param string $query Query.
	 * @param mixed  $output Output mode.
	 * @return array<int,object>
	 */
	public function get_results( $query, $output = null ): array {
		unset( $query, $output );

		return array();
	}

	/**
	 * Return charset clause.
	 *
	 * @return string
	 */
	public function get_charset_collate(): string {
		return '';
	}
}
