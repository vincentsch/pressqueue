<?php
/**
 * Storage readiness status.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads schema, migration, and planning readiness state.
 */
final class SchemaStatus {
	/**
	 * Database wrapper.
	 *
	 * @var Database|null
	 */
	private ?Database $database;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database wrapper.
	 */
	public function __construct( ?Database $database = null ) {
		$this->database = $database;
	}

	/**
	 * Get the installed schema version.
	 *
	 * @return int
	 */
	public function installed_version(): int {
		if ( ! function_exists( 'get_option' ) ) {
			return 0;
		}

		return max( 0, (int) get_option( Schema::VERSION_OPTION, 0 ) );
	}

	/**
	 * Check whether the current schema version is installed.
	 *
	 * @return bool
	 */
	public function is_current(): bool {
		return $this->installed_version() >= Schema::TARGET_VERSION
			&& $this->has_expected_schema();
	}

	/**
	 * Check whether a non-stale migration lock is active.
	 *
	 * @return bool
	 */
	public function has_active_migration_lock(): bool {
		$lock = $this->lock();

		return null !== $lock && ! $this->is_lock_stale( $lock );
	}

	/**
	 * Check whether a migration failure marker exists.
	 *
	 * @return bool
	 */
	public function has_migration_failed(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		return false !== get_option( Schema::MIGRATION_FAILED_OPTION, false );
	}

	/**
	 * Check whether runtime planning can proceed.
	 *
	 * @return bool
	 */
	public function is_ready_for_planning(): bool {
		return $this->is_current()
			&& ! $this->has_active_migration_lock()
			&& ! $this->has_migration_failed();
	}

	/**
	 * Fail closed if runtime planning cannot proceed.
	 */
	public function assert_ready_for_planning(): void {
		if ( $this->is_ready_for_planning() ) {
			return;
		}

		if ( ! $this->is_current() ) {
			throw new StorageUnavailableException( 'PressQueue storage schema is not current.' );
		}

		if ( $this->has_active_migration_lock() ) {
			throw new StorageUnavailableException( 'PressQueue storage migration is currently locked.' );
		}

		throw new StorageUnavailableException( 'PressQueue storage migration previously failed.' );
	}

	/**
	 * Read the current migration lock.
	 *
	 * @return array{owner:string,created_at:string}|null
	 */
	public function lock(): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$lock = get_option( Schema::MIGRATION_LOCK_OPTION, false );

		if (
			! is_array( $lock )
			|| empty( $lock['owner'] )
			|| empty( $lock['created_at'] )
			|| ! is_string( $lock['owner'] )
			|| ! is_string( $lock['created_at'] )
		) {
			return null;
		}

		return array(
			'owner'      => $lock['owner'],
			'created_at' => $lock['created_at'],
		);
	}

	/**
	 * Check whether a lock is older than the allowed TTL.
	 *
	 * @param array{owner:string,created_at:string} $lock Lock value.
	 * @return bool
	 */
	public function is_lock_stale( array $lock ): bool {
		$created_at = DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s',
			$lock['created_at'],
			new DateTimeZone( 'UTC' )
		);

		if ( false === $created_at ) {
			return true;
		}

		return ( time() - $created_at->getTimestamp() ) > Schema::LOCK_TTL_SECONDS;
	}

	/**
	 * Check whether the physical table shape matches the current schema.
	 *
	 * @return bool
	 */
	private function has_expected_schema(): bool {
		$database = $this->database();

		if ( null === $database ) {
			return false;
		}

		try {
			( new SchemaMigrator( $database, $this ) )->assert_expected_schema();
			return true;
		} catch ( StorageException | ValidationException $exception ) {
			unset( $exception );

			return false;
		}
	}

	/**
	 * Return a database wrapper when WordPress has one available.
	 *
	 * @return Database|null
	 */
	private function database(): ?Database {
		if ( null !== $this->database ) {
			return $this->database;
		}

		if ( ! Database::has_global_database() ) {
			return null;
		}

		$this->database = Database::from_globals();

		return $this->database;
	}
}
