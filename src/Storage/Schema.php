<?php
/**
 * Database schema definitions.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Defines the current storage schema.
 */
final class Schema {
	public const TARGET_VERSION          = 2;
	public const VERSION_OPTION          = 'pressqueue_schema_version';
	public const MIGRATION_LOCK_OPTION   = 'pressqueue_migration_lock';
	public const MIGRATION_FAILED_OPTION = 'pressqueue_migration_failed';
	public const LOCK_TTL_SECONDS        = 900;
	public const ACCOUNT_JSON_MAX_BYTES  = 65536;
	public const PROVIDER_JSON_MAX_BYTES = 16384;

	public const CONNECTIONS = 'connections';
	public const CHANNELS    = 'channels';
	public const RULES       = 'rules';
	public const DECISIONS   = 'decisions';
	public const DELIVERIES  = 'deliveries';

	/**
	 * Return all PressQueue table suffixes.
	 *
	 * @return array<int,string>
	 */
	public static function table_suffixes(): array {
		return array(
			self::CONNECTIONS,
			self::CHANNELS,
			self::RULES,
			self::DECISIONS,
			self::DELIVERIES,
		);
	}

	/**
	 * Return SQL statements for the current table set.
	 *
	 * @param Database $database Database wrapper.
	 * @return array<int,string>
	 */
	public static function create_table_sql( Database $database ): array {
		$collate     = $database->charset_collate();
		$connections = $database->table( self::CONNECTIONS );
		$channels    = $database->table( self::CHANNELS );
		$rules       = $database->table( self::RULES );
		$decisions   = $database->table( self::DECISIONS );
		$deliveries  = $database->table( self::DELIVERIES );

		return array(
			"CREATE TABLE $connections (
				id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
				public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				provider VARCHAR(64) NOT NULL,
				label VARCHAR(191) NOT NULL,
				account_json LONGTEXT NOT NULL,
				credential_envelope_json LONGTEXT NULL,
				credential_envelope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
				remote_account_id VARCHAR(191) NULL,
				remote_organization_id VARCHAR(191) NULL,
				verified_identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
				connection_status VARCHAR(32) NOT NULL DEFAULT 'disconnected',
				sync_lease_owner CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
				sync_lease_expires_at DATETIME NULL,
				credential_verified_at DATETIME NULL,
				credential_rotated_at DATETIME NULL,
				last_sync_started_at DATETIME NULL,
				last_sync_completed_at DATETIME NULL,
				last_error_json LONGTEXT NULL,
				enabled TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY verified_identity_hash (verified_identity_hash),
				KEY provider (provider),
				KEY enabled (enabled),
				KEY connection_status (connection_status),
				KEY remote_account (provider, remote_account_id),
				KEY remote_organization (provider, remote_organization_id),
				KEY sync_lease_expires_at (sync_lease_expires_at)
			) $collate;",
			"CREATE TABLE $channels (
				id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
				public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				connection_id BIGINT UNSIGNED NOT NULL,
				connection_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				provider VARCHAR(64) NOT NULL,
				remote_organization_id VARCHAR(191) NOT NULL DEFAULT '',
				remote_channel_id VARCHAR(191) NOT NULL,
				service VARCHAR(64) NOT NULL,
				display_json LONGTEXT NOT NULL,
				capabilities_json LONGTEXT NOT NULL,
				availability VARCHAR(32) NOT NULL DEFAULT 'unverified',
				enabled TINYINT(1) NOT NULL DEFAULT 0,
				last_synced_at DATETIME NULL,
				last_seen_at DATETIME NULL,
				availability_checked_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY connection_org_remote_channel (connection_public_id, remote_organization_id, remote_channel_id),
				KEY connection_id (connection_id),
				KEY provider (provider),
				KEY service (service),
				KEY enabled (enabled),
				KEY remote_organization (connection_public_id, remote_organization_id),
				KEY availability (availability),
				KEY connection_availability (connection_public_id, availability)
			) $collate;",
			"CREATE TABLE $rules (
				id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
				public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				name VARCHAR(191) NOT NULL,
				effect VARCHAR(16) NOT NULL,
				priority INT NOT NULL DEFAULT 100,
				triggers_json LONGTEXT NOT NULL,
				condition_tree_json LONGTEXT NOT NULL,
				inline_template_json LONGTEXT NOT NULL,
				destination_set_json LONGTEXT NOT NULL,
				custom_field_allowlist_json LONGTEXT NOT NULL,
				schedule_policy VARCHAR(32) NOT NULL DEFAULT 'immediate',
				enabled TINYINT(1) NOT NULL DEFAULT 0,
				last_previewed_version INT UNSIGNED NULL,
				version INT UNSIGNED NOT NULL DEFAULT 1,
				lock_version INT UNSIGNED NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY enabled_priority_public_id (enabled, priority, public_id),
				KEY effect (effect),
				KEY version (version)
			) $collate;",
			"CREATE TABLE $decisions (
				id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
				public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				decision_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				scope_type VARCHAR(16) NOT NULL,
				scope_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
				post_id BIGINT UNSIGNED NOT NULL,
				post_type VARCHAR(20) NOT NULL,
				post_status VARCHAR(20) NOT NULL,
				author_id BIGINT UNSIGNED NOT NULL,
				revision_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				`trigger` VARCHAR(32) NOT NULL,
				event_mode VARCHAR(16) NOT NULL,
				rule_id BIGINT UNSIGNED NULL,
				rule_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
				rule_version INT UNSIGNED NULL,
				result VARCHAR(32) NOT NULL,
				reasons_json LONGTEXT NOT NULL,
				snapshot_json LONGTEXT NOT NULL,
				snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY decision_key (decision_key),
				KEY event_key (event_key),
				KEY post_id (post_id),
				KEY scope (scope_type, scope_public_id),
				KEY result (result),
				KEY rule_id (rule_id),
				KEY rule_public_id (rule_public_id),
				KEY created_at (created_at)
			) $collate;",
			"CREATE TABLE $deliveries (
				id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
				public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				decision_id BIGINT UNSIGNED NOT NULL,
				decision_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				delivery_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				delivery_mode VARCHAR(16) NOT NULL,
				provider VARCHAR(64) NOT NULL,
				connection_id BIGINT UNSIGNED NOT NULL,
				connection_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				channel_id BIGINT UNSIGNED NOT NULL,
				channel_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				remote_channel_id VARCHAR(191) NOT NULL,
				rule_public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				rule_version INT UNSIGNED NOT NULL,
				state VARCHAR(32) NOT NULL,
				schedule_slot VARCHAR(64) NOT NULL DEFAULT 'immediate',
				payload_preview_json LONGTEXT NOT NULL,
				payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY delivery_key (delivery_key),
				KEY decision_id (decision_id),
				KEY decision_public_id (decision_public_id),
				KEY event_key (event_key),
				KEY connection_id (connection_id),
				KEY channel_id (channel_id),
				KEY channel_public_id (channel_public_id),
				KEY state (state)
			) $collate;",
		);
	}

	/**
	 * Return required column and index definitions for completion checks.
	 *
	 * @return array<string,array{columns:array<string,array{type:string,nullable:bool,default:string|null,extra:string,charset:string|null,collation:string|null}>,indexes:array<string,array{columns:array<int,string>,unique:bool}>}>
	 */
	public static function expected_shape(): array {
		return array(
			self::CONNECTIONS => array(
				'columns' => array(
					'id'                       => self::column( 'bigint unsigned', false, null, 'auto_increment' ),
					'public_id'                => self::ascii_column( 'char(26)' ),
					'provider'                 => self::column( 'varchar(64)' ),
					'label'                    => self::column( 'varchar(191)' ),
					'account_json'             => self::column( 'longtext' ),
					'credential_envelope_json' => self::column( 'longtext', true ),
					'credential_envelope_hash' => self::ascii_column( 'char(64)', true ),
					'remote_account_id'        => self::column( 'varchar(191)', true ),
					'remote_organization_id'   => self::column( 'varchar(191)', true ),
					'verified_identity_hash'   => self::ascii_column( 'char(64)', true ),
					'connection_status'        => self::column( 'varchar(32)', false, 'disconnected' ),
					'sync_lease_owner'         => self::ascii_column( 'char(32)', true ),
					'sync_lease_expires_at'    => self::column( 'datetime', true ),
					'credential_verified_at'   => self::column( 'datetime', true ),
					'credential_rotated_at'    => self::column( 'datetime', true ),
					'last_sync_started_at'     => self::column( 'datetime', true ),
					'last_sync_completed_at'   => self::column( 'datetime', true ),
					'last_error_json'          => self::column( 'longtext', true ),
					'enabled'                  => self::column( 'tinyint', false, '0' ),
					'created_at'               => self::column( 'datetime' ),
					'updated_at'               => self::column( 'datetime' ),
				),
				'indexes' => array(
					'PRIMARY'                => self::index( array( 'id' ), true ),
					'public_id'              => self::index( array( 'public_id' ), true ),
					'verified_identity_hash' => self::index( array( 'verified_identity_hash' ), true ),
					'provider'               => self::index( array( 'provider' ) ),
					'enabled'                => self::index( array( 'enabled' ) ),
					'connection_status'      => self::index( array( 'connection_status' ) ),
					'remote_account'         => self::index( array( 'provider', 'remote_account_id' ) ),
					'remote_organization'    => self::index( array( 'provider', 'remote_organization_id' ) ),
					'sync_lease_expires_at'  => self::index( array( 'sync_lease_expires_at' ) ),
				),
			),
			self::CHANNELS    => array(
				'columns' => array(
					'id'                      => self::column( 'bigint unsigned', false, null, 'auto_increment' ),
					'public_id'               => self::ascii_column( 'char(26)' ),
					'connection_id'           => self::column( 'bigint unsigned' ),
					'connection_public_id'    => self::ascii_column( 'char(26)' ),
					'provider'                => self::column( 'varchar(64)' ),
					'remote_organization_id'  => self::column( 'varchar(191)', false, '' ),
					'remote_channel_id'       => self::column( 'varchar(191)' ),
					'service'                 => self::column( 'varchar(64)' ),
					'display_json'            => self::column( 'longtext' ),
					'capabilities_json'       => self::column( 'longtext' ),
					'availability'            => self::column( 'varchar(32)', false, 'unverified' ),
					'enabled'                 => self::column( 'tinyint', false, '0' ),
					'last_synced_at'          => self::column( 'datetime', true ),
					'last_seen_at'            => self::column( 'datetime', true ),
					'availability_checked_at' => self::column( 'datetime', true ),
					'created_at'              => self::column( 'datetime' ),
					'updated_at'              => self::column( 'datetime' ),
				),
				'indexes' => array(
					'PRIMARY'                       => self::index( array( 'id' ), true ),
					'public_id'                     => self::index( array( 'public_id' ), true ),
					'connection_org_remote_channel' => self::index( array( 'connection_public_id', 'remote_organization_id', 'remote_channel_id' ), true ),
					'connection_id'                 => self::index( array( 'connection_id' ) ),
					'provider'                      => self::index( array( 'provider' ) ),
					'service'                       => self::index( array( 'service' ) ),
					'enabled'                       => self::index( array( 'enabled' ) ),
					'remote_organization'           => self::index( array( 'connection_public_id', 'remote_organization_id' ) ),
					'availability'                  => self::index( array( 'availability' ) ),
					'connection_availability'       => self::index( array( 'connection_public_id', 'availability' ) ),
				),
			),
			self::RULES       => array(
				'columns' => array(
					'id'                          => self::column( 'bigint unsigned', false, null, 'auto_increment' ),
					'public_id'                   => self::ascii_column( 'char(26)' ),
					'name'                        => self::column( 'varchar(191)' ),
					'effect'                      => self::column( 'varchar(16)' ),
					'priority'                    => self::column( 'int', false, '100' ),
					'triggers_json'               => self::column( 'longtext' ),
					'condition_tree_json'         => self::column( 'longtext' ),
					'inline_template_json'        => self::column( 'longtext' ),
					'destination_set_json'        => self::column( 'longtext' ),
					'custom_field_allowlist_json' => self::column( 'longtext' ),
					'schedule_policy'             => self::column( 'varchar(32)', false, 'immediate' ),
					'enabled'                     => self::column( 'tinyint', false, '0' ),
					'last_previewed_version'      => self::column( 'int unsigned', true ),
					'version'                     => self::column( 'int unsigned', false, '1' ),
					'lock_version'                => self::column( 'int unsigned', false, '1' ),
					'created_at'                  => self::column( 'datetime' ),
					'updated_at'                  => self::column( 'datetime' ),
				),
				'indexes' => array(
					'PRIMARY'                    => self::index( array( 'id' ), true ),
					'public_id'                  => self::index( array( 'public_id' ), true ),
					'enabled_priority_public_id' => self::index( array( 'enabled', 'priority', 'public_id' ) ),
					'effect'                     => self::index( array( 'effect' ) ),
					'version'                    => self::index( array( 'version' ) ),
				),
			),
			self::DECISIONS   => array(
				'columns' => array(
					'id'                   => self::column( 'bigint unsigned', false, null, 'auto_increment' ),
					'public_id'            => self::ascii_column( 'char(26)' ),
					'event_key'            => self::ascii_column( 'char(64)' ),
					'decision_key'         => self::ascii_column( 'char(64)' ),
					'scope_type'           => self::column( 'varchar(16)' ),
					'scope_public_id'      => self::ascii_column( 'char(26)', true ),
					'post_id'              => self::column( 'bigint unsigned' ),
					'post_type'            => self::column( 'varchar(20)' ),
					'post_status'          => self::column( 'varchar(20)' ),
					'author_id'            => self::column( 'bigint unsigned' ),
					'revision_fingerprint' => self::ascii_column( 'char(64)' ),
					'trigger'              => self::column( 'varchar(32)' ),
					'event_mode'           => self::column( 'varchar(16)' ),
					'rule_id'              => self::column( 'bigint unsigned', true ),
					'rule_public_id'       => self::ascii_column( 'char(26)', true ),
					'rule_version'         => self::column( 'int unsigned', true ),
					'result'               => self::column( 'varchar(32)' ),
					'reasons_json'         => self::column( 'longtext' ),
					'snapshot_json'        => self::column( 'longtext' ),
					'snapshot_hash'        => self::ascii_column( 'char(64)' ),
					'created_at'           => self::column( 'datetime' ),
				),
				'indexes' => array(
					'PRIMARY'        => self::index( array( 'id' ), true ),
					'public_id'      => self::index( array( 'public_id' ), true ),
					'decision_key'   => self::index( array( 'decision_key' ), true ),
					'event_key'      => self::index( array( 'event_key' ) ),
					'post_id'        => self::index( array( 'post_id' ) ),
					'scope'          => self::index( array( 'scope_type', 'scope_public_id' ) ),
					'result'         => self::index( array( 'result' ) ),
					'rule_id'        => self::index( array( 'rule_id' ) ),
					'rule_public_id' => self::index( array( 'rule_public_id' ) ),
					'created_at'     => self::index( array( 'created_at' ) ),
				),
			),
			self::DELIVERIES  => array(
				'columns' => array(
					'id'                   => self::column( 'bigint unsigned', false, null, 'auto_increment' ),
					'public_id'            => self::ascii_column( 'char(26)' ),
					'decision_id'          => self::column( 'bigint unsigned' ),
					'decision_public_id'   => self::ascii_column( 'char(26)' ),
					'event_key'            => self::ascii_column( 'char(64)' ),
					'delivery_key'         => self::ascii_column( 'char(64)' ),
					'delivery_mode'        => self::column( 'varchar(16)' ),
					'provider'             => self::column( 'varchar(64)' ),
					'connection_id'        => self::column( 'bigint unsigned' ),
					'connection_public_id' => self::ascii_column( 'char(26)' ),
					'channel_id'           => self::column( 'bigint unsigned' ),
					'channel_public_id'    => self::ascii_column( 'char(26)' ),
					'remote_channel_id'    => self::column( 'varchar(191)' ),
					'rule_public_id'       => self::ascii_column( 'char(26)' ),
					'rule_version'         => self::column( 'int unsigned' ),
					'state'                => self::column( 'varchar(32)' ),
					'schedule_slot'        => self::column( 'varchar(64)', false, 'immediate' ),
					'payload_preview_json' => self::column( 'longtext' ),
					'payload_hash'         => self::ascii_column( 'char(64)' ),
					'created_at'           => self::column( 'datetime' ),
					'updated_at'           => self::column( 'datetime' ),
				),
				'indexes' => array(
					'PRIMARY'            => self::index( array( 'id' ), true ),
					'public_id'          => self::index( array( 'public_id' ), true ),
					'delivery_key'       => self::index( array( 'delivery_key' ), true ),
					'decision_id'        => self::index( array( 'decision_id' ) ),
					'decision_public_id' => self::index( array( 'decision_public_id' ) ),
					'event_key'          => self::index( array( 'event_key' ) ),
					'connection_id'      => self::index( array( 'connection_id' ) ),
					'channel_id'         => self::index( array( 'channel_id' ) ),
					'channel_public_id'  => self::index( array( 'channel_public_id' ) ),
					'state'              => self::index( array( 'state' ) ),
				),
			),
		);
	}

	/**
	 * Build an expected column definition.
	 *
	 * @param string      $type Column type.
	 * @param bool        $nullable Whether NULL is allowed.
	 * @param string|null $default_value Expected default.
	 * @param string      $extra Expected extra metadata.
	 * @param string|null $charset Expected character set.
	 * @param string|null $collation Expected collation.
	 * @return array{type:string,nullable:bool,default:string|null,extra:string,charset:string|null,collation:string|null}
	 */
	private static function column( string $type, bool $nullable = false, ?string $default_value = null, string $extra = '', ?string $charset = null, ?string $collation = null ): array {
		return array(
			'type'      => $type,
			'nullable'  => $nullable,
			'default'   => $default_value,
			'extra'     => $extra,
			'charset'   => $charset,
			'collation' => $collation,
		);
	}

	/**
	 * Build an expected ascii binary column definition.
	 *
	 * @param string $type Column type.
	 * @param bool   $nullable Whether NULL is allowed.
	 * @return array{type:string,nullable:bool,default:string|null,extra:string,charset:string|null,collation:string|null}
	 */
	private static function ascii_column( string $type, bool $nullable = false ): array {
		return self::column( $type, $nullable, null, '', 'ascii', 'ascii_bin' );
	}

	/**
	 * Build an expected index definition.
	 *
	 * @param array<int,string> $columns Indexed columns.
	 * @param bool              $unique Whether the index must be unique.
	 * @return array{columns:array<int,string>,unique:bool}
	 */
	private static function index( array $columns, bool $unique = false ): array {
		return array(
			'columns' => $columns,
			'unique'  => $unique,
		);
	}
}
