<?php
/**
 * Retention state option store.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Privacy;

use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Stores resumable retention run state without adding schema tables.
 */
final class RetentionStateStore {
	public const OPTION            = 'pressqueue_retention_state';
	public const LEASE_TTL_SECONDS = 1800;

	/**
	 * Ensure non-autoloaded default state exists.
	 */
	public static function ensure_defaults_for_current_site(): void {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Return the empty state document.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'active_run'      => null,
			'last_completion' => null,
			'schema_version'  => 1,
		);
	}

	/**
	 * Read retention state.
	 *
	 * @return array<string,mixed>
	 */
	public function read(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return self::defaults();
		}

		$value = get_option( self::OPTION, null );
		if ( null === $value ) {
			return self::defaults();
		}

		return $this->normalize( $value );
	}

	/**
	 * Acquire or reclaim the active retention lease.
	 *
	 * @param array<string,mixed> $settings Privacy settings.
	 * @return array<string,mixed>|null
	 */
	public function acquire( array $settings ): ?array {
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$state = $this->read();
			$run   = is_array( $state['active_run'] ) ? $state['active_run'] : null;

			if ( null !== $run && ! $this->is_stale( $run ) ) {
				return null;
			}

			$next_run               = null === $run ? $this->new_run( $settings ) : $this->reclaimed_run( $run );
			$next                   = $state;
			$next['active_run']     = $next_run;
			$next['schema_version'] = 1;

			if ( $this->write_if_unchanged( $state, $next ) ) {
				return $next_run;
			}
		}

		return null;
	}

	/**
	 * Update active run progress.
	 *
	 * @param array<string,mixed>      $run Active run.
	 * @param array<string,mixed>|null $cursor Cursor.
	 * @param array<string,mixed>      $counters Counters.
	 */
	public function progress( array $run, ?array $cursor, array $counters ): void {
		$expected = $this->read();
		$this->assert_current_lease( $expected, $run );

		$active                     = is_array( $expected['active_run'] ) ? $expected['active_run'] : $run;
		$active['cursor']           = $this->normalize_cursor( $cursor );
		$active['counters']         = $this->normalize_counters( $counters );
		$active['heartbeat_at_utc'] = $this->now();
		$next                       = $expected;
		$next['active_run']         = $active;

		if ( ! $this->write_if_unchanged( $expected, $next ) ) {
			throw new StaleRecordException( 'Retention lease is no longer held.' );
		}
	}

	/**
	 * Mark an active run complete.
	 *
	 * @param array<string,mixed> $run Active run.
	 */
	public function complete( array $run ): void {
		$expected = $this->read();
		$this->assert_current_lease( $expected, $run );

		$active                  = is_array( $expected['active_run'] ) ? $expected['active_run'] : $run;
		$next                    = $expected;
		$next['active_run']      = null;
		$next['last_completion'] = $this->completion( $active, 'completed', array( 'classification' => 'retention_completed' ) );

		if ( ! $this->write_if_unchanged( $expected, $next ) ) {
			throw new StaleRecordException( 'Retention lease is no longer held.' );
		}
	}

	/**
	 * Mark an active run failed with classified details.
	 *
	 * @param array<string,mixed> $run Active run.
	 * @param Throwable           $throwable Failure.
	 */
	public function fail( array $run, Throwable $throwable ): void {
		$expected = $this->read();
		$this->assert_current_lease( $expected, $run );

		$active                  = is_array( $expected['active_run'] ) ? $expected['active_run'] : $run;
		$next                    = $expected;
		$next['active_run']      = null;
		$next['last_completion'] = $this->completion(
			$active,
			'failed',
			array( 'classification' => SafeOperatorError::classification( $throwable ) )
		);

		if ( ! $this->write_if_unchanged( $expected, $next ) ) {
			throw new StaleRecordException( 'Retention lease is no longer held.' );
		}
	}

	/**
	 * Return empty family counters.
	 *
	 * @return array<string,array<string,int>>
	 */
	public function empty_counters(): array {
		$counters = array();

		foreach ( RetentionService::families() as $family ) {
			$counters[ $family ] = array(
				'changed'  => 0,
				'deleted'  => 0,
				'redacted' => 0,
				'scanned'  => 0,
			);
		}

		return $counters;
	}

	/**
	 * Build a new active run.
	 *
	 * @param array<string,mixed> $settings Privacy settings.
	 * @return array<string,mixed>
	 */
	private function new_run( array $settings ): array {
		$now = $this->now();

		return array(
			'counters'         => $this->empty_counters(),
			'cursor'           => null,
			'cutoff_utc'       => gmdate( 'Y-m-d H:i:s', time() - ( (int) $settings['retention_days'] * DAY_IN_SECONDS ) ),
			'heartbeat_at_utc' => $now,
			'lease_id'         => self::lease_id(),
			'settings_version' => PrivacySettings::version( $settings ),
			'started_at_utc'   => $now,
		);
	}

	/**
	 * Reclaim a stale active run without losing progress.
	 *
	 * @param array<string,mixed> $run Previous run.
	 * @return array<string,mixed>
	 */
	private function reclaimed_run( array $run ): array {
		$run['heartbeat_at_utc'] = $this->now();
		$run['lease_id']         = self::lease_id();

		return $run;
	}

	/**
	 * Build a terminal completion document.
	 *
	 * @param array<string,mixed> $run Active run.
	 * @param string              $state State.
	 * @param array<string,mixed> $summary Summary.
	 * @return array<string,mixed>
	 */
	private function completion( array $run, string $state, array $summary ): array {
		$summary['schema_version'] = 1;

		return array(
			'completed_at_utc' => $this->now(),
			'counters'         => $this->normalize_counters( $run['counters'] ?? null ),
			'cutoff_utc'       => isset( $run['cutoff_utc'] ) && is_string( $run['cutoff_utc'] ) ? $run['cutoff_utc'] : null,
			'schema_version'   => 1,
			'settings_version' => isset( $run['settings_version'] ) && is_string( $run['settings_version'] ) ? $run['settings_version'] : null,
			'started_at_utc'   => isset( $run['started_at_utc'] ) && is_string( $run['started_at_utc'] ) ? $run['started_at_utc'] : null,
			'state'            => $state,
			'summary'          => $summary,
		);
	}

	/**
	 * Check whether an active run lease is stale.
	 *
	 * @param array<string,mixed> $run Active run.
	 * @return bool
	 */
	private function is_stale( array $run ): bool {
		$heartbeat = isset( $run['heartbeat_at_utc'] ) && is_string( $run['heartbeat_at_utc'] ) ? strtotime( $run['heartbeat_at_utc'] . ' UTC' ) : false;

		return ! is_int( $heartbeat ) || $heartbeat + self::LEASE_TTL_SECONDS < time();
	}

	/**
	 * Assert the caller still owns the active lease.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @param array<string,mixed> $run Caller run.
	 */
	private function assert_current_lease( array $state, array $run ): void {
		$active = is_array( $state['active_run'] ?? null ) ? $state['active_run'] : null;

		if (
			null === $active
			|| empty( $run['lease_id'] )
			|| ! is_string( $run['lease_id'] )
			|| (string) $active['lease_id'] !== $run['lease_id']
		) {
			throw new StaleRecordException( 'Retention lease is no longer held.' );
		}
	}

	/**
	 * Write state only when the stored option still matches the expected state.
	 *
	 * @param array<string,mixed> $expected Expected current state.
	 * @param array<string,mixed> $next Next state.
	 * @return bool
	 */
	private function write_if_unchanged( array $expected, array $next ): bool {
		$expected = $this->normalize( $expected );
		$next     = $this->normalize( $next );

		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		if ( null === get_option( self::OPTION, null ) ) {
			return function_exists( 'add_option' ) && add_option( self::OPTION, $next, '', false );
		}

		if ( $this->atomic_option_update( $expected, $next ) ) {
			return true;
		}

		$current = get_option( self::OPTION, null );
		try {
			if ( $this->normalize( $current ) !== $expected ) {
				return false;
			}
		} catch ( ValidationException $exception ) {
			unset( $exception );
			return false;
		}

		update_option( self::OPTION, $next, false );

		return true;
	}

	/**
	 * Attempt an option compare-and-write using the stored option value.
	 *
	 * @param array<string,mixed> $expected Expected current state.
	 * @param array<string,mixed> $next Next state.
	 * @return bool
	 */
	private function atomic_option_update( array $expected, array $next ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb instanceof \wpdb || ! function_exists( 'wp_cache_delete' ) ) {
			return false;
		}

		$prepared = $wpdb->prepare(
			'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s LIMIT 1',
			array(
				$wpdb->options,
				$this->serialize_option_value( $next ),
				self::OPTION,
				$this->serialize_option_value( $expected ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Retention lease updates need an option compare-and-write.
		$result = $wpdb->query( $prepared );

		if ( false === $result ) {
			throw new StorageException( 'Retention state could not be updated.' );
		}

		if ( (int) $result > 0 ) {
			wp_cache_delete( self::OPTION, 'options' );
			return true;
		}

		return false;
	}

	/**
	 * Normalize state shape.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,mixed>
	 */
	private function normalize( $value ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new ValidationException( 'Retention state must be an object.' );
		}

		if ( isset( $value['schema_version'] ) && 1 !== $value['schema_version'] && '1' !== $value['schema_version'] ) {
			throw new ValidationException( 'Retention state schema version is unsupported.' );
		}

		return array(
			'active_run'      => $this->normalize_nullable_run( $value['active_run'] ?? null ),
			'last_completion' => $this->normalize_nullable_completion( $value['last_completion'] ?? null ),
			'schema_version'  => 1,
		);
	}

	/**
	 * Normalize an active run or null.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,mixed>|null
	 */
	private function normalize_nullable_run( $value ): ?array {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new ValidationException( 'Retention active run must be an object.' );
		}

		$value = $this->upgrade_run_keys( $value );

		foreach ( array( 'cutoff_utc', 'heartbeat_at_utc', 'lease_id', 'settings_version', 'started_at_utc' ) as $field ) {
			if ( empty( $value[ $field ] ) || ! is_string( $value[ $field ] ) ) {
				throw new ValidationException( 'Retention active run is invalid.' );
			}
		}

		return array(
			'counters'         => $this->normalize_counters( $value['counters'] ?? null ),
			'cursor'           => $this->normalize_cursor( $value['cursor'] ?? null ),
			'cutoff_utc'       => $value['cutoff_utc'],
			'heartbeat_at_utc' => $value['heartbeat_at_utc'],
			'lease_id'         => $value['lease_id'],
			'settings_version' => $value['settings_version'],
			'started_at_utc'   => $value['started_at_utc'],
		);
	}

	/**
	 * Normalize a completion snapshot or null.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,mixed>|null
	 */
	private function normalize_nullable_completion( $value ): ?array {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new ValidationException( 'Retention completion must be an object.' );
		}

		$completed_at = $value['completed_at_utc'] ?? $value['completed_at'] ?? null;
		if ( ! is_string( $completed_at ) || '' === $completed_at ) {
			throw new ValidationException( 'Retention completion is invalid.' );
		}

		$state = isset( $value['state'] ) && is_string( $value['state'] ) ? $value['state'] : 'completed';
		if ( ! in_array( $state, array( 'completed', 'failed' ), true ) ) {
			throw new ValidationException( 'Retention completion is invalid.' );
		}

		$summary = $value['summary'] ?? array(
			'classification' => 'retention_completed',
			'schema_version' => 1,
		);

		return array(
			'completed_at_utc' => $completed_at,
			'counters'         => $this->normalize_counters( $value['counters'] ?? null ),
			'cutoff_utc'       => isset( $value['cutoff_utc'] ) && is_string( $value['cutoff_utc'] ) ? $value['cutoff_utc'] : null,
			'schema_version'   => 1,
			'settings_version' => isset( $value['settings_version'] ) && is_string( $value['settings_version'] ) ? $value['settings_version'] : null,
			'started_at_utc'   => isset( $value['started_at_utc'] ) && is_string( $value['started_at_utc'] ) ? $value['started_at_utc'] : ( isset( $value['started_at'] ) && is_string( $value['started_at'] ) ? $value['started_at'] : null ),
			'state'            => $state,
			'summary'          => $this->normalize_summary( $summary ),
		);
	}

	/**
	 * Normalize a retention cursor.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,mixed>|null
	 */
	private function normalize_cursor( $value ): ?array {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new ValidationException( 'Retention cursor must be an object.' );
		}

		if ( empty( $value['family'] ) || ! is_string( $value['family'] ) || ! in_array( $value['family'], RetentionService::families(), true ) ) {
			throw new ValidationException( 'Retention cursor family is invalid.' );
		}

		$timestamp = $value['timestamp_utc'] ?? $value['timestamp'] ?? null;

		return array(
			'family'        => $value['family'],
			'public_id'     => isset( $value['public_id'] ) && is_string( $value['public_id'] ) ? $value['public_id'] : null,
			'timestamp_utc' => is_string( $timestamp ) && '' !== $timestamp ? $timestamp : null,
		);
	}

	/**
	 * Normalize family counters.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,array<string,int>>
	 */
	private function normalize_counters( $value ): array {
		if ( null === $value ) {
			return $this->empty_counters();
		}

		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new ValidationException( 'Retention counters must be an object.' );
		}

		$counters = $this->empty_counters();
		foreach ( array_keys( $counters ) as $family ) {
			$family_counters = $value[ $family ] ?? array();
			if ( ! is_array( $family_counters ) || array_is_list( $family_counters ) ) {
				throw new ValidationException( 'Retention counters are invalid.' );
			}

			foreach ( array_keys( $counters[ $family ] ) as $counter ) {
				$counter_value = $family_counters[ $counter ] ?? 0;
				if ( is_string( $counter_value ) && 1 === preg_match( '/^[0-9]+$/', $counter_value ) ) {
					$counter_value = (int) $counter_value;
				}

				if ( ! is_int( $counter_value ) || $counter_value < 0 ) {
					throw new ValidationException( 'Retention counter is invalid.' );
				}

				$counters[ $family ][ $counter ] = $counter_value;
			}
		}

		return $counters;
	}

	/**
	 * Normalize a terminal summary.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,mixed>
	 */
	private function normalize_summary( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new ValidationException( 'Retention completion is invalid.' );
		}

		if ( empty( $value['classification'] ) || ! is_string( $value['classification'] ) ) {
			throw new ValidationException( 'Retention completion is invalid.' );
		}

		if ( 1 !== ( $value['schema_version'] ?? null ) && '1' !== ( $value['schema_version'] ?? null ) ) {
			throw new ValidationException( 'Retention completion is invalid.' );
		}

		return array(
			'classification' => $value['classification'],
			'schema_version' => 1,
		);
	}

	/**
	 * Upgrade keys from the pre-release state shape.
	 *
	 * @param array<string,mixed> $value Run.
	 * @return array<string,mixed>
	 */
	private function upgrade_run_keys( array $value ): array {
		if ( isset( $value['heartbeat_at'] ) && ! isset( $value['heartbeat_at_utc'] ) ) {
			$value['heartbeat_at_utc'] = $value['heartbeat_at'];
		}

		if ( isset( $value['lease_owner'] ) && ! isset( $value['lease_id'] ) ) {
			$value['lease_id'] = $value['lease_owner'];
		}

		if ( isset( $value['started_at'] ) && ! isset( $value['started_at_utc'] ) ) {
			$value['started_at_utc'] = $value['started_at'];
		}

		return $value;
	}

	/**
	 * Serialize values like WordPress options.
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
	 * Return the current UTC datetime.
	 *
	 * @return string
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Create a lease ID.
	 *
	 * @return string
	 */
	private static function lease_id(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return str_pad( dechex( time() ), 32, '0', STR_PAD_LEFT );
		}
	}
}
