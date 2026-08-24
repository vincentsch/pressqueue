<?php
/**
 * Storage migration runner.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use Throwable;

/**
 * Runs additive, forward-only migrations.
 */
final class SchemaMigrator {
	/**
	 * Database wrapper.
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * Schema status reader.
	 *
	 * @var SchemaStatus
	 */
	private SchemaStatus $status;

	/**
	 * Constructor.
	 *
	 * @param Database          $database Database wrapper.
	 * @param SchemaStatus|null $status Schema status reader.
	 */
	public function __construct( Database $database, ?SchemaStatus $status = null ) {
		$this->database = $database;
		$this->status   = $status ?? new SchemaStatus( $database );
	}

	/**
	 * Default activation and upgrade callback.
	 *
	 * @param bool $network_wide Whether activation was requested network-wide.
	 */
	public static function migrate_current_site( bool $network_wide = false ): void {
		unset( $network_wide );

		if ( ! Database::has_global_database() ) {
			return;
		}

		( new self( Database::from_globals() ) )->migrate();
	}

	/**
	 * Run all missing migration steps.
	 */
	public function migrate(): void {
		if ( $this->is_current_schema_complete() ) {
			$this->clear_failure();
			return;
		}

		$owner = $this->acquire_lock();

		try {
			$version = $this->status->installed_version();

			if ( $version >= Schema::TARGET_VERSION ) {
				$this->create_base_tables();
				$this->assert_expected_schema();
				$this->clear_failure();
				return;
			}

			while ( $version < Schema::TARGET_VERSION ) {
				$next_version = $version + 1;

				if ( 1 === $next_version ) {
					$this->create_base_tables();
				}

				$this->assert_expected_schema();
				$this->set_version( $next_version );
				$version = $next_version;
			}

			$this->clear_failure();
		} catch ( Throwable $throwable ) {
			$this->mark_failure( $throwable );
			throw $throwable;
		} finally {
			$this->release_lock( $owner );
		}
	}

	/**
	 * Recover a stale migration lock.
	 *
	 * @return bool Whether a lock was deleted.
	 */
	public function recover_stale_lock(): bool {
		$lock = $this->status->lock();

		if ( null === $lock || ! $this->status->is_lock_stale( $lock ) ) {
			return false;
		}

		return $this->delete_lock( $lock );
	}

	/**
	 * Handle the nonce-protected administrator lock recovery action.
	 */
	public static function handle_lock_recovery(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to recover PressQueue migrations.', 'pressqueue' ) );
		}

		check_admin_referer( 'pressqueue_recover_migration_lock' );

		$recovered = false;
		if ( Database::has_global_database() ) {
			$recovered = ( new self( Database::from_globals() ) )->recover_stale_lock();
		}

		$referer = wp_get_referer();
		$target  = $referer ? $referer : admin_url();
		$target  = add_query_arg( 'pressqueue_migration_lock_recovered', $recovered ? '1' : '0', $target );

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Create or update the base tables.
	 */
	private function create_base_tables(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			throw new StorageException( 'WordPress path is not available.' );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::create_table_sql( $this->database ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Assert that required tables, columns, and indexes exist.
	 */
	public function assert_expected_schema(): void {
		foreach ( Schema::expected_shape() as $suffix => $shape ) {
			$table = $this->database->table( $suffix );

			if ( $table !== $this->database->get_var( 'SHOW TABLES LIKE %s', array( $table ) ) ) {
				throw new StorageException( 'PressQueue table is missing.' );
			}

			$columns    = $this->columns( $table );
			$collations = $this->column_collations( $table );
			foreach ( $shape['columns'] as $column => $definition ) {
				if ( ! isset( $columns[ $column ] ) ) {
					throw new StorageException( 'PressQueue table column is missing.' );
				}

				$this->assert_column( $columns[ $column ], $collations[ $column ] ?? array(), $definition );
			}

			$indexes = $this->indexes( $table );
			foreach ( $shape['indexes'] as $index_name => $index ) {
				if (
					! isset( $indexes[ $index_name ] )
					|| $indexes[ $index_name ]['columns'] !== $index['columns']
					|| $indexes[ $index_name ]['unique'] !== $index['unique']
				) {
					throw new StorageException( 'PressQueue table index is missing.' );
				}
			}
		}
	}

	/**
	 * Acquire the migration lock or fail closed.
	 *
	 * @return string Owner token.
	 */
	private function acquire_lock(): string {
		if ( ! function_exists( 'add_option' ) ) {
			throw new StorageException( 'WordPress options API is not available.' );
		}

		$lock = $this->status->lock();
		if ( null !== $lock && $this->status->is_lock_stale( $lock ) ) {
			$this->delete_lock( $lock );
		}

		$owner = bin2hex( random_bytes( 16 ) );
		$added = add_option(
			Schema::MIGRATION_LOCK_OPTION,
			array(
				'owner'      => $owner,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			'',
			false
		);

		if ( ! $added ) {
			throw new StorageUnavailableException( 'PressQueue storage migration is already locked.' );
		}

		return $owner;
	}

	/**
	 * Release the migration lock when this process owns it.
	 *
	 * @param string $owner Owner token.
	 */
	private function release_lock( string $owner ): void {
		$lock = $this->status->lock();

		if ( null !== $lock && $owner === $lock['owner'] ) {
			$this->delete_lock( $lock );
		}
	}

	/**
	 * Store the installed schema version as a non-autoloaded option.
	 *
	 * @param int $version Installed version.
	 */
	private function set_version( int $version ): void {
		if ( false === get_option( Schema::VERSION_OPTION, false ) ) {
			add_option( Schema::VERSION_OPTION, $version, '', false );
			return;
		}

		update_option( Schema::VERSION_OPTION, $version, false );
	}

	/**
	 * Store a migration failure marker as a non-autoloaded option.
	 *
	 * @param Throwable $throwable Failure.
	 */
	private function mark_failure( Throwable $throwable ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		update_option(
			Schema::MIGRATION_FAILED_OPTION,
			array(
				'failed_at' => gmdate( 'Y-m-d H:i:s' ),
				'message'   => $throwable->getMessage(),
			),
			false
		);
	}

	/**
	 * Delete any migration failure marker.
	 */
	private function clear_failure(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( Schema::MIGRATION_FAILED_OPTION );
		}
	}

	/**
	 * Check whether the current version marker and physical schema both match.
	 *
	 * @return bool
	 */
	private function is_current_schema_complete(): bool {
		if ( $this->status->installed_version() < Schema::TARGET_VERSION ) {
			return false;
		}

		try {
			$this->assert_expected_schema();
			return true;
		} catch ( StorageException | ValidationException $exception ) {
			unset( $exception );

			return false;
		}
	}

	/**
	 * Delete a migration lock only when the stored owner token is unchanged.
	 *
	 * @param array{owner:string,created_at:string} $lock Lock value.
	 * @return bool
	 */
	private function delete_lock( array $lock ): bool {
		return $this->database->delete_option_if_value( Schema::MIGRATION_LOCK_OPTION, $lock );
	}

	/**
	 * Return table columns by name.
	 *
	 * @param string $table Table name.
	 * @return array<string,array<string,mixed>>
	 */
	private function columns( string $table ): array {
		$rows    = $this->database->get_results_array( 'DESCRIBE `' . $table . '`' );
		$columns = array();

		foreach ( $rows as $row ) {
			if ( isset( $row['Field'] ) ) {
				$columns[ (string) $row['Field'] ] = $row;
			}
		}

		return $columns;
	}

	/**
	 * Return table column charset and collation data by column name.
	 *
	 * @param string $table Table name.
	 * @return array<string,array<string,mixed>>
	 */
	private function column_collations( string $table ): array {
		$rows = $this->database->get_results_array(
			'SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			array( $table )
		);

		$columns = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['COLUMN_NAME'] ) ) {
				$columns[ (string) $row['COLUMN_NAME'] ] = $row;
			}
		}

		return $columns;
	}

	/**
	 * Return table indexes by name.
	 *
	 * @param string $table Table name.
	 * @return array<string,array{columns:array<int,string>,unique:bool}>
	 */
	private function indexes( string $table ): array {
		$rows    = $this->database->get_results_array( 'SHOW INDEX FROM `' . $table . '`' );
		$indexes = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $row['Key_name'], $row['Column_name'], $row['Seq_in_index'], $row['Non_unique'] ) ) {
				continue;
			}

			$name                                     = (string) $row['Key_name'];
			$sequence                                 = (int) $row['Seq_in_index'];
			$indexes[ $name ]['columns'][ $sequence ] = (string) $row['Column_name'];
			$indexes[ $name ]['unique']               = 0 === (int) $row['Non_unique'];
		}

		foreach ( $indexes as $name => $index ) {
			ksort( $index['columns'] );
			$indexes[ $name ]['columns'] = array_values( $index['columns'] );
		}

		return $indexes;
	}

	/**
	 * Assert one column definition.
	 *
	 * @param array<string,mixed> $column Column metadata from DESCRIBE.
	 * @param array<string,mixed> $collation Column metadata from information_schema.
	 * @param array<string,mixed> $expected Expected metadata.
	 */
	private function assert_column( array $column, array $collation, array $expected ): void {
		if ( $this->normalize_column_type( (string) ( $column['Type'] ?? '' ) ) !== $expected['type'] ) {
			throw new StorageException( 'PressQueue table column type is invalid.' );
		}

		if ( ( 'YES' === (string) ( $column['Null'] ?? '' ) ) !== $expected['nullable'] ) {
			throw new StorageException( 'PressQueue table column nullability is invalid.' );
		}

		$default = array_key_exists( 'Default', $column ) && null !== $column['Default']
			? (string) $column['Default']
			: null;
		if ( $default !== $expected['default'] ) {
			throw new StorageException( 'PressQueue table column default is invalid.' );
		}

		if ( strtolower( (string) ( $column['Extra'] ?? '' ) ) !== $expected['extra'] ) {
			throw new StorageException( 'PressQueue table column extra metadata is invalid.' );
		}

		if ( null !== $expected['charset'] && (string) ( $collation['CHARACTER_SET_NAME'] ?? '' ) !== $expected['charset'] ) {
			throw new StorageException( 'PressQueue table column character set is invalid.' );
		}

		if ( null !== $expected['collation'] && (string) ( $collation['COLLATION_NAME'] ?? '' ) !== $expected['collation'] ) {
			throw new StorageException( 'PressQueue table column collation is invalid.' );
		}
	}

	/**
	 * Normalize database column type output across supported MySQL variants.
	 *
	 * @param string $type Raw DESCRIBE type.
	 * @return string
	 */
	private function normalize_column_type( string $type ): string {
		$type = strtolower( $type );
		$type = preg_replace( '/\b(bigint|int|tinyint)\(\d+\)/', '$1', $type );

		return preg_replace( '/\s+/', ' ', trim( (string) $type ) );
	}
}
