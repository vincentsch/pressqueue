<?php
/**
 * Storage migration runner.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use PressQueue\Admin\AdminPostBoundary;
use PressQueue\Security\SafeOperatorError;
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
				} elseif ( 2 === $next_version ) {
					$this->migrate_to_version_2();
				} elseif ( 3 === $next_version ) {
					$this->migrate_to_version_3();
				} elseif ( 4 === $next_version ) {
					$this->migrate_to_version_4();
				} elseif ( 5 === $next_version ) {
					$this->migrate_to_version_5();
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
		AdminPostBoundary::check( 'pressqueue_recover_migration_lock', 'manage_options', __( 'You are not allowed to recover PressQueue migrations.', 'pressqueue' ) );

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
	 * Add Buffer connection and discovery metadata.
	 */
	private function migrate_to_version_2(): void {
		$this->validate_existing_delivery_payloads_if_present();
		$this->create_base_tables();

		$connections = $this->database->table( Schema::CONNECTIONS );
		$channels    = $this->database->table( Schema::CHANNELS );

		$this->maybe_add_column( $connections, 'credential_envelope_json', 'credential_envelope_json LONGTEXT NULL AFTER account_json' );
		$this->maybe_add_column( $connections, 'credential_envelope_hash', 'credential_envelope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER credential_envelope_json' );
		$this->maybe_add_column( $connections, 'remote_account_id', 'remote_account_id VARCHAR(191) NULL AFTER credential_envelope_hash' );
		$this->maybe_add_column( $connections, 'remote_organization_id', 'remote_organization_id VARCHAR(191) NULL AFTER remote_account_id' );
		$this->maybe_add_column( $connections, 'verified_identity_hash', 'verified_identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER remote_organization_id' );
		$this->maybe_add_column( $connections, 'connection_status', "connection_status VARCHAR(32) NOT NULL DEFAULT 'disconnected' AFTER verified_identity_hash" );
		$this->maybe_add_column( $connections, 'sync_lease_owner', 'sync_lease_owner CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER connection_status' );
		$this->maybe_add_column( $connections, 'sync_lease_expires_at', 'sync_lease_expires_at DATETIME NULL AFTER sync_lease_owner' );
		$this->maybe_add_column( $connections, 'credential_verified_at', 'credential_verified_at DATETIME NULL AFTER sync_lease_expires_at' );
		$this->maybe_add_column( $connections, 'credential_rotated_at', 'credential_rotated_at DATETIME NULL AFTER credential_verified_at' );
		$this->maybe_add_column( $connections, 'last_sync_started_at', 'last_sync_started_at DATETIME NULL AFTER credential_rotated_at' );
		$this->maybe_add_column( $connections, 'last_sync_completed_at', 'last_sync_completed_at DATETIME NULL AFTER last_sync_started_at' );
		$this->maybe_add_column( $connections, 'last_error_json', 'last_error_json LONGTEXT NULL AFTER last_sync_completed_at' );

		$this->backfill_connections_for_version_2( $connections );

		$this->maybe_add_index( $connections, 'verified_identity_hash', 'UNIQUE KEY verified_identity_hash (verified_identity_hash)' );
		$this->maybe_add_index( $connections, 'connection_status', 'KEY connection_status (connection_status)' );
		$this->maybe_add_index( $connections, 'remote_account', 'KEY remote_account (provider, remote_account_id)' );
		$this->maybe_add_index( $connections, 'remote_organization', 'KEY remote_organization (provider, remote_organization_id)' );
		$this->maybe_add_index( $connections, 'sync_lease_expires_at', 'KEY sync_lease_expires_at (sync_lease_expires_at)' );

		$this->maybe_add_column( $channels, 'remote_organization_id', "remote_organization_id VARCHAR(191) NOT NULL DEFAULT '' AFTER provider" );
		$this->maybe_add_column( $channels, 'availability', "availability VARCHAR(32) NOT NULL DEFAULT 'unverified' AFTER capabilities_json" );
		$this->maybe_add_column( $channels, 'last_seen_at', 'last_seen_at DATETIME NULL AFTER last_synced_at' );
		$this->maybe_add_column( $channels, 'availability_checked_at', 'availability_checked_at DATETIME NULL AFTER last_seen_at' );

		$this->backfill_channels_for_version_2( $channels );
		$this->drop_index_if_exists( $channels, 'connection_remote_channel' );
		$this->maybe_add_index( $channels, 'connection_org_remote_channel', 'UNIQUE KEY connection_org_remote_channel (connection_public_id, remote_organization_id, remote_channel_id)' );
		$this->maybe_add_index( $channels, 'remote_organization', 'KEY remote_organization (connection_public_id, remote_organization_id)' );
		$this->maybe_add_index( $channels, 'availability', 'KEY availability (availability)' );
		$this->maybe_add_index( $channels, 'connection_availability', 'KEY connection_availability (connection_public_id, availability)' );
	}

	/**
	 * Add delivery queue projection and event ledger storage.
	 */
	private function migrate_to_version_3(): void {
		$deliveries = $this->database->table( Schema::DELIVERIES );
		$events     = $this->database->table( Schema::DELIVERY_EVENTS );

		$this->validate_existing_delivery_payloads_if_present();
		$this->create_base_tables();
		$this->validate_legacy_delivery_payloads( $deliveries );

		$this->maybe_add_column( $deliveries, 'bound_provider', 'bound_provider VARCHAR(32) NULL AFTER updated_at' );
		$this->maybe_add_column( $deliveries, 'bound_connection_public_id', 'bound_connection_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER bound_provider' );
		$this->maybe_add_column( $deliveries, 'bound_channel_public_id', 'bound_channel_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER bound_connection_public_id' );
		$this->maybe_add_column( $deliveries, 'bound_remote_account_id', 'bound_remote_account_id VARCHAR(191) NULL AFTER bound_channel_public_id' );
		$this->maybe_add_column( $deliveries, 'bound_remote_organization_id', 'bound_remote_organization_id VARCHAR(191) NULL AFTER bound_remote_account_id' );
		$this->maybe_add_column( $deliveries, 'bound_remote_channel_id', 'bound_remote_channel_id VARCHAR(191) NULL AFTER bound_remote_organization_id' );
		$this->maybe_add_column( $deliveries, 'bound_capability_schema_version', 'bound_capability_schema_version SMALLINT UNSIGNED NULL AFTER bound_remote_channel_id' );
		$this->maybe_add_column( $deliveries, 'queued_at', 'queued_at DATETIME NULL AFTER bound_capability_schema_version' );
		$this->maybe_add_column( $deliveries, 'last_attempt_at', 'last_attempt_at DATETIME NULL AFTER queued_at' );
		$this->maybe_add_column( $deliveries, 'attempt_count', 'attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_attempt_at' );
		$this->maybe_add_column( $deliveries, 'automatic_attempt_count', 'automatic_attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER attempt_count' );
		$this->maybe_add_column( $deliveries, 'next_attempt_at', 'next_attempt_at DATETIME NULL AFTER automatic_attempt_count' );
		$this->maybe_add_column( $deliveries, 'lease_owner', 'lease_owner CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER next_attempt_at' );
		$this->maybe_add_column( $deliveries, 'lease_expires_at', 'lease_expires_at DATETIME NULL AFTER lease_owner' );
		$this->maybe_add_column( $deliveries, 'action_id', 'action_id BIGINT UNSIGNED NULL AFTER lease_expires_at' );
		$this->maybe_add_column( $deliveries, 'provider_result', 'provider_result VARCHAR(32) NULL AFTER action_id' );
		$this->maybe_add_column( $deliveries, 'retry_safety', "retry_safety VARCHAR(32) NOT NULL DEFAULT 'not_retryable' AFTER provider_result" );
		$this->maybe_add_column( $deliveries, 'receipt_json', 'receipt_json LONGTEXT NULL AFTER retry_safety' );
		$this->maybe_add_column( $deliveries, 'remote_id', 'remote_id VARCHAR(191) NULL AFTER receipt_json' );
		$this->maybe_add_column( $deliveries, 'remote_receipt_key', 'remote_receipt_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER remote_id' );
		$this->maybe_add_column( $deliveries, 'error_class', 'error_class VARCHAR(64) NULL AFTER remote_receipt_key' );
		$this->maybe_add_column( $deliveries, 'error_detail_json', 'error_detail_json LONGTEXT NULL AFTER error_class' );
		$this->maybe_add_column( $deliveries, 'reconciliation_status', "reconciliation_status VARCHAR(32) NOT NULL DEFAULT 'none' AFTER error_detail_json" );
		$this->maybe_add_column( $deliveries, 'reconciled_at', 'reconciled_at DATETIME NULL AFTER reconciliation_status' );
		$this->maybe_add_column( $deliveries, 'reconciled_by', 'reconciled_by BIGINT UNSIGNED NULL AFTER reconciled_at' );
		$this->maybe_add_column( $deliveries, 'reconciliation_note', 'reconciliation_note VARCHAR(500) NULL AFTER reconciled_by' );
		$this->maybe_add_column( $deliveries, 'payload_schema_version', 'payload_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER reconciliation_note' );
		$this->maybe_add_column( $deliveries, 'adapter_encoding_version', 'adapter_encoding_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER payload_schema_version' );
		$this->maybe_add_column( $deliveries, 'state_changed_at', 'state_changed_at DATETIME NULL AFTER adapter_encoding_version' );

		$this->database->query(
			"UPDATE `$deliveries`
				SET state_changed_at = updated_at
				WHERE state_changed_at IS NULL"
		);

		$this->maybe_add_index( $deliveries, 'connection_public_id', 'KEY connection_public_id (connection_public_id)' );
		$this->maybe_add_index( $deliveries, 'state_next_attempt', 'KEY state_next_attempt (state, next_attempt_at)' );
		$this->maybe_add_index( $deliveries, 'lease_expires_at', 'KEY lease_expires_at (lease_expires_at)' );
		$this->maybe_add_index( $deliveries, 'action_id', 'KEY action_id (action_id)' );
		$this->maybe_add_index( $deliveries, 'remote_id', 'KEY remote_id (remote_id)' );
		$this->maybe_add_index( $deliveries, 'remote_receipt_key', 'UNIQUE KEY remote_receipt_key (remote_receipt_key)' );
		$this->maybe_add_index( $deliveries, 'reconciliation_status', 'KEY reconciliation_status (reconciliation_status)' );
		$this->maybe_add_index( $deliveries, 'state_changed_id', 'KEY state_changed_id (state_changed_at, id)' );

		if ( $events !== $this->database->get_var( 'SHOW TABLES LIKE %s', array( $events ) ) ) {
			$this->create_base_tables();
		}
	}

	/**
	 * Add reusable templates, rule snapshots, schedule and media provenance.
	 */
	private function migrate_to_version_4(): void {
		$rules      = $this->database->table( Schema::RULES );
		$deliveries = $this->database->table( Schema::DELIVERIES );

		$this->validate_existing_delivery_payloads_if_present();
		$this->create_base_tables();

		$this->maybe_add_column( $rules, 'source_template_public_id', 'source_template_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER inline_template_json' );
		$this->maybe_add_column( $rules, 'source_template_version', 'source_template_version INT UNSIGNED NULL AFTER source_template_public_id' );
		$this->maybe_add_column( $rules, 'template_set_json', 'template_set_json LONGTEXT NULL AFTER source_template_version' );
		$this->maybe_add_column( $rules, 'schedule_policy_json', 'schedule_policy_json LONGTEXT NULL AFTER schedule_policy' );
		$this->maybe_add_column( $rules, 'media_policy_json', 'media_policy_json LONGTEXT NULL AFTER schedule_policy_json' );
		$this->maybe_add_index( $rules, 'source_template_version', 'KEY source_template_version (source_template_public_id, source_template_version)' );

		$this->maybe_add_column( $deliveries, 'scheduled_for_utc', 'scheduled_for_utc DATETIME NULL AFTER schedule_slot' );
		$this->maybe_add_column( $deliveries, 'schedule_timezone', 'schedule_timezone VARCHAR(64) NULL AFTER scheduled_for_utc' );
		$this->maybe_add_column( $deliveries, 'bound_capability_hash', 'bound_capability_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER bound_capability_schema_version' );
		$this->maybe_add_column( $deliveries, 'bound_capability_catalog_version', 'bound_capability_catalog_version VARCHAR(64) NULL AFTER bound_capability_hash' );
		$this->maybe_add_column( $deliveries, 'media_snapshot_json', 'media_snapshot_json LONGTEXT NULL AFTER bound_capability_catalog_version' );
		$this->maybe_add_column( $deliveries, 'media_identity_hash', 'media_identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER media_snapshot_json' );
		$this->maybe_add_index( $deliveries, 'scheduled_for_utc', 'KEY scheduled_for_utc (scheduled_for_utc)' );
	}

	/**
	 * Add batch storage and occurrence projections.
	 */
	private function migrate_to_version_5(): void {
		$decisions  = $this->database->table( Schema::DECISIONS );
		$deliveries = $this->database->table( Schema::DELIVERIES );

		$this->validate_existing_delivery_payloads_if_present();
		$this->create_base_tables();

		$this->maybe_add_column( $decisions, 'intent_occurrence_key', 'intent_occurrence_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER decision_key' );
		$this->maybe_add_index( $decisions, 'intent_occurrence_key', 'KEY intent_occurrence_key (intent_occurrence_key)' );

		$this->maybe_add_column( $deliveries, 'intent_occurrence_key', 'intent_occurrence_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER delivery_key' );
		$this->maybe_add_index( $deliveries, 'intent_occurrence_key', 'KEY intent_occurrence_key (intent_occurrence_key)' );
	}

	/**
	 * Backfill legacy connection rows into the disconnected state.
	 *
	 * @param string $table Table name.
	 */
	private function backfill_connections_for_version_2( string $table ): void {
		$this->database->query(
			"UPDATE `$table`
				SET enabled = 0,
					connection_status = 'disconnected',
					verified_identity_hash = NULL,
					sync_lease_owner = NULL,
					sync_lease_expires_at = NULL
				WHERE credential_envelope_json IS NULL OR credential_envelope_json = ''"
		);
	}

	/**
	 * Backfill legacy channel rows with empty organization identity.
	 *
	 * @param string $table Table name.
	 */
	private function backfill_channels_for_version_2( string $table ): void {
		$this->database->query(
			"UPDATE `$table`
				SET enabled = 0,
					availability = 'unverified'
				WHERE remote_organization_id = ''"
		);
	}

	/**
	 * Validate existing payload previews before marking them as schema version 1.
	 *
	 * @param string $table Deliveries table.
	 */
	private function validate_legacy_delivery_payloads( string $table ): void {
		$columns = $this->columns( $table );

		if ( ! isset( $columns['payload_preview_json'], $columns['payload_hash'] ) ) {
			return;
		}

		$rows = $this->database->get_results_array(
			"SELECT id, payload_preview_json, payload_hash FROM `$table`"
		);

		foreach ( $rows as $row ) {
			$payload = CanonicalJson::decode_object( (string) $row['payload_preview_json'] );

			if (
				! isset( $payload['message'], $payload['canonical_url'] )
				|| ! is_string( $payload['message'] )
				|| ! is_string( $payload['canonical_url'] )
			) {
				throw new StorageException( 'Legacy delivery payload is not compatible with payload schema version 1.' );
			}

			StorageValidator::assert_hash( (string) $row['payload_hash'] );
		}
	}

	/**
	 * Validate existing delivery payloads before schema expansion assigns versions.
	 */
	private function validate_existing_delivery_payloads_if_present(): void {
		$deliveries = $this->database->table( Schema::DELIVERIES );

		if ( $deliveries !== $this->database->get_var( 'SHOW TABLES LIKE %s', array( $deliveries ) ) ) {
			return;
		}

		$this->validate_legacy_delivery_payloads( $deliveries );
	}

	/**
	 * Add a column when it does not exist.
	 *
	 * @param string $table Table name.
	 * @param string $column Column name.
	 * @param string $definition Column definition.
	 */
	private function maybe_add_column( string $table, string $column, string $definition ): void {
		if ( isset( $this->columns( $table )[ $column ] ) ) {
			return;
		}

		$this->database->query( "ALTER TABLE `$table` ADD COLUMN $definition" );
	}

	/**
	 * Add an index when it does not exist.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 * @param string $definition Index definition.
	 */
	private function maybe_add_index( string $table, string $index, string $definition ): void {
		if ( isset( $this->indexes( $table )[ $index ] ) ) {
			return;
		}

		$this->database->query( "ALTER TABLE `$table` ADD $definition" );
	}

	/**
	 * Drop a legacy index when it exists.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 */
	private function drop_index_if_exists( string $table, string $index ): void {
		if ( ! isset( $this->indexes( $table )[ $index ] ) ) {
			return;
		}

		$this->database->query( "ALTER TABLE `$table` DROP INDEX `$index`" );
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
			SafeOperatorError::failure_marker( $throwable ),
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
