<?php
/**
 * Connection repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Persists provider connection metadata.
 */
final class ConnectionRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                       => '%d',
		'public_id'                => '%s',
		'provider'                 => '%s',
		'label'                    => '%s',
		'account_json'             => '%s',
		'credential_envelope_json' => '%s',
		'credential_envelope_hash' => '%s',
		'remote_account_id'        => '%s',
		'remote_organization_id'   => '%s',
		'verified_identity_hash'   => '%s',
		'connection_status'        => '%s',
		'sync_lease_owner'         => '%s',
		'sync_lease_expires_at'    => '%s',
		'credential_verified_at'   => '%s',
		'credential_rotated_at'    => '%s',
		'last_sync_started_at'     => '%s',
		'last_sync_completed_at'   => '%s',
		'last_error_json'          => '%s',
		'enabled'                  => '%d',
		'created_at'               => '%s',
		'updated_at'               => '%s',
	);

	/**
	 * Create a local connection record.
	 *
	 * @param string              $provider Provider key.
	 * @param string              $label Display label.
	 * @param array<string,mixed> $account Account metadata.
	 * @param bool                $enabled Whether the connection starts enabled.
	 * @return ConnectionRecord
	 */
	public function create( string $provider, string $label, array $account = array(), bool $enabled = false ): ConnectionRecord {
		return $this->insert_connection(
			$provider,
			$label,
			$account,
			null,
			null,
			null,
			null,
			$enabled ? 'verified' : 'disconnected',
			$enabled,
			null
		);
	}

	/**
	 * Create a disabled connection with an encrypted credential envelope.
	 *
	 * @param string              $provider Provider key.
	 * @param string              $label Display label.
	 * @param string              $credential_envelope_json Encrypted credential envelope JSON.
	 * @param string              $credential_envelope_hash Envelope hash.
	 * @param array<string,mixed> $account Account metadata.
	 * @return ConnectionRecord
	 */
	public function create_with_credential( string $provider, string $label, string $credential_envelope_json, string $credential_envelope_hash, array $account = array() ): ConnectionRecord {
		StorageValidator::assert_hash( $credential_envelope_hash );
		CanonicalJson::assert_maximum_size( $credential_envelope_json, Schema::PROVIDER_JSON_MAX_BYTES );

		return $this->insert_connection(
			$provider,
			$label,
			$account,
			$credential_envelope_json,
			$credential_envelope_hash,
			null,
			null,
			'unverified',
			false,
			StorageValidator::now()
		);
	}

	/**
	 * Create a disabled connection after preparing a credential for its public ID.
	 *
	 * @param string              $provider Provider key.
	 * @param string              $label Display label.
	 * @param callable            $credential_factory Credential document factory.
	 * @param array<string,mixed> $account Account metadata.
	 * @phpstan-param callable(string):array{json:string,hash:string} $credential_factory
	 * @return ConnectionRecord
	 */
	public function create_with_credential_factory( string $provider, string $label, callable $credential_factory, array $account = array() ): ConnectionRecord {
		StorageValidator::assert_text_key( 'Provider', $provider );
		StorageValidator::assert_label( 'Connection label', $label );
		$table        = $this->connections_table();
		$account_json = $this->account_json( $account );

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id  = $this->generate_unique_public_id( $table );
			$credential = $credential_factory( $public_id );
			$json       = (string) $credential['json'];
			$hash       = (string) $credential['hash'];
			StorageValidator::assert_hash( $hash );
			CanonicalJson::assert_maximum_size( $json, Schema::PROVIDER_JSON_MAX_BYTES );

			try {
				return $this->insert_connection_row(
					$public_id,
					$provider,
					$label,
					$account_json,
					$json,
					$hash,
					null,
					null,
					null,
					'unverified',
					false,
					StorageValidator::now()
				);
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue connection public ID.' );
	}

	/**
	 * Attach the first encrypted credential to a disconnected row.
	 *
	 * @param string $public_id Public ID.
	 * @param string $credential_envelope_json Encrypted credential envelope JSON.
	 * @param string $credential_envelope_hash Envelope hash.
	 * @return ConnectionRecord
	 */
	public function store_initial_credential( string $public_id, string $credential_envelope_json, string $credential_envelope_hash ): ConnectionRecord {
		PublicIds::assert( $public_id );
		StorageValidator::assert_hash( $credential_envelope_hash );
		CanonicalJson::assert_maximum_size( $credential_envelope_json, Schema::PROVIDER_JSON_MAX_BYTES );
		$now = StorageValidator::now();

		$updated = $this->database->update_rows(
			$this->connections_table(),
			array(
				'credential_envelope_json' => $credential_envelope_json,
				'credential_envelope_hash' => $credential_envelope_hash,
				'connection_status'        => 'unverified',
				'credential_rotated_at'    => $now,
				'updated_at'               => $now,
			),
			array(
				'public_id'                => $public_id,
				'credential_envelope_hash' => null,
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StaleRecordException( 'Connection already has a credential.' );
		}

		return $this->must_find( $public_id );
	}

	/**
	 * Find a connection by public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return ConnectionRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?ConnectionRecord {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->connections_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return all connections for administration.
	 *
	 * @return array<int,ConnectionRecord>
	 */
	public function find_all(): array {
		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->connections_table() . '` ORDER BY provider ASC, label ASC, public_id ASC'
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return a credential envelope JSON document for backend use.
	 *
	 * @param string $public_id Public ID.
	 * @return string|null
	 */
	public function credential_envelope_json( string $public_id ): ?string {
		PublicIds::assert( $public_id );

		return $this->database->get_var(
			'SELECT credential_envelope_json FROM `' . $this->connections_table() . '` WHERE public_id = %s',
			array( $public_id )
		);
	}

	/**
	 * Store verified account metadata without selecting an organization.
	 *
	 * @param string              $public_id Public ID.
	 * @param string              $remote_account_id Remote account ID.
	 * @param array<string,mixed> $account Account metadata.
	 * @return ConnectionRecord
	 */
	public function store_verified_account( string $public_id, string $remote_account_id, array $account ): ConnectionRecord {
		PublicIds::assert( $public_id );
		StorageValidator::assert_remote_identifier( 'Remote account ID', $remote_account_id );
		$record = $this->must_find( $public_id );

		if ( null !== $record->remote_account_id && $record->remote_account_id !== $remote_account_id ) {
			throw new ValidationException( 'Verified credential belongs to a different provider account.' );
		}

		$account_json = $this->account_json( $account );
		$now          = StorageValidator::now();
		$values       = array(
			'account_json'           => $account_json,
			'remote_account_id'      => $remote_account_id,
			'credential_verified_at' => $now,
			'last_error_json'        => null,
			'updated_at'             => $now,
		);

		if ( ! $this->has_verified_identity( $record ) || ! $this->account_contains_organization( $account, $record->remote_organization_id ) ) {
			$values['verified_identity_hash'] = null;
			$values['connection_status']      = 'unverified';
			$values['enabled']                = 0;
		}

		$this->database->update_rows(
			$this->connections_table(),
			$values,
			array( 'public_id' => $public_id ),
			self::FORMATS
		);

		return $this->must_find( $public_id );
	}

	/**
	 * Store the selected organization and require a later explicit enable.
	 *
	 * @param string $public_id Public ID.
	 * @param string $remote_organization_id Remote organization ID.
	 * @return ConnectionRecord
	 */
	public function select_organization_unverified( string $public_id, string $remote_organization_id ): ConnectionRecord {
		PublicIds::assert( $public_id );
		StorageValidator::assert_remote_identifier( 'Remote organization ID', $remote_organization_id );
		$now = StorageValidator::now();

		$this->database->update_rows(
			$this->connections_table(),
			array(
				'remote_organization_id' => $remote_organization_id,
				'verified_identity_hash' => null,
				'connection_status'      => 'unverified',
				'enabled'                => 0,
				'updated_at'             => $now,
			),
			array( 'public_id' => $public_id ),
			self::FORMATS
		);

		return $this->must_find( $public_id );
	}

	/**
	 * Mark a complete sync as verified.
	 *
	 * @param string              $public_id Public ID.
	 * @param string              $lease_owner Sync lease owner.
	 * @param string              $remote_account_id Remote account ID.
	 * @param string              $remote_organization_id Remote organization ID.
	 * @param array<string,mixed> $account Account metadata.
	 * @param bool                $enabled Whether to leave the connection enabled.
	 * @return ConnectionRecord
	 */
	public function complete_sync_success( string $public_id, string $lease_owner, string $remote_account_id, string $remote_organization_id, array $account, bool $enabled = false ): ConnectionRecord {
		PublicIds::assert( $public_id );
		StorageValidator::assert_lease_owner( $lease_owner );
		StorageValidator::assert_remote_identifier( 'Remote account ID', $remote_account_id );
		StorageValidator::assert_remote_identifier( 'Remote organization ID', $remote_organization_id );
		$record = $this->must_find( $public_id );
		$now    = StorageValidator::now();
		$hash   = HashKeys::verified_identity_hash( $record->provider, $remote_account_id, $remote_organization_id );

		$values = array(
			'account_json'           => $this->account_json( $account ),
			'remote_account_id'      => $remote_account_id,
			'remote_organization_id' => $remote_organization_id,
			'verified_identity_hash' => $hash,
			'connection_status'      => 'verified',
			'sync_lease_owner'       => null,
			'sync_lease_expires_at'  => null,
			'credential_verified_at' => $now,
			'last_sync_completed_at' => $now,
			'last_error_json'        => null,
			'updated_at'             => $now,
		);

		if ( ! $enabled ) {
			$values['enabled'] = 0;
		}

		$updated = $this->database->update_rows(
			$this->connections_table(),
			$values,
			array(
				'public_id'        => $public_id,
				'sync_lease_owner' => $lease_owner,
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StaleRecordException( 'Connection sync lease is no longer held.' );
		}

		return $this->must_find( $public_id );
	}

	/**
	 * Store a redacted sync failure and release the lease.
	 *
	 * @param string              $public_id Public ID.
	 * @param string              $lease_owner Sync lease owner.
	 * @param array<string,mixed> $error Redacted error document.
	 */
	public function complete_sync_failure( string $public_id, string $lease_owner, array $error ): void {
		PublicIds::assert( $public_id );
		StorageValidator::assert_lease_owner( $lease_owner );
		$error_json = $this->error_json( $error );
		$now        = StorageValidator::now();

		$updated = $this->database->update_rows(
			$this->connections_table(),
			array(
				'sync_lease_owner'      => null,
				'sync_lease_expires_at' => null,
				'last_error_json'       => $error_json,
				'updated_at'            => $now,
			),
			array(
				'public_id'        => $public_id,
				'sync_lease_owner' => $lease_owner,
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StaleRecordException( 'Connection sync lease is no longer held.' );
		}
	}

	/**
	 * Acquire a sync lease.
	 *
	 * @param string $public_id Public ID.
	 * @param int    $ttl_seconds Lease lifetime.
	 * @return string|null Lease owner token.
	 */
	public function acquire_sync_lease( string $public_id, int $ttl_seconds = 120 ): ?string {
		PublicIds::assert( $public_id );

		if ( $ttl_seconds < 1 || $ttl_seconds > 3600 ) {
			throw new ValidationException( 'Sync lease TTL is out of range.' );
		}

		$owner      = bin2hex( random_bytes( 16 ) );
		$now        = StorageValidator::now();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds );
		$updated    = $this->database->query(
			'UPDATE `' . $this->connections_table() . '`
				SET sync_lease_owner = %s,
					sync_lease_expires_at = %s,
					last_sync_started_at = %s,
					last_error_json = NULL,
					updated_at = %s
				WHERE public_id = %s
					AND (sync_lease_owner IS NULL OR sync_lease_expires_at IS NULL OR sync_lease_expires_at <= %s)',
			array( $owner, $expires_at, $now, $now, $public_id, $now )
		);

		return $updated > 0 ? $owner : null;
	}

	/**
	 * Enable a verified connection.
	 *
	 * @param string $public_id Public ID.
	 * @return ConnectionRecord
	 */
	public function enable( string $public_id ): ConnectionRecord {
		$record = $this->must_find( $public_id );

		if (
			'verified' !== $record->connection_status
			|| null === $record->credential_envelope_hash
			|| null === $record->remote_account_id
			|| null === $record->remote_organization_id
			|| null === $record->verified_identity_hash
			|| null === $record->last_sync_completed_at
		) {
			throw new ValidationException( 'Connection must be verified and synchronized before it can be enabled.' );
		}

		$now = StorageValidator::now();
		$this->database->update_rows(
			$this->connections_table(),
			array(
				'enabled'    => 1,
				'updated_at' => $now,
			),
			array(
				'public_id'              => $record->public_id,
				'connection_status'      => 'verified',
				'verified_identity_hash' => $record->verified_identity_hash,
			),
			self::FORMATS
		);

		return $this->must_find( $public_id );
	}

	/**
	 * Disable a connection.
	 *
	 * @param string $public_id Public ID.
	 * @return ConnectionRecord
	 */
	public function disable( string $public_id ): ConnectionRecord {
		PublicIds::assert( $public_id );
		$now = StorageValidator::now();

		$this->database->update_rows(
			$this->connections_table(),
			array(
				'enabled'    => 0,
				'updated_at' => $now,
			),
			array( 'public_id' => $public_id ),
			self::FORMATS
		);

		return $this->must_find( $public_id );
	}

	/**
	 * Disconnect and erase the stored credential envelope.
	 *
	 * @param string $public_id Public ID.
	 * @return ConnectionRecord
	 */
	public function disconnect( string $public_id ): ConnectionRecord {
		PublicIds::assert( $public_id );
		$now = StorageValidator::now();

		$this->database->update_rows(
			$this->connections_table(),
			array(
				'credential_envelope_json' => null,
				'credential_envelope_hash' => null,
				'verified_identity_hash'   => null,
				'connection_status'        => 'disconnected',
				'sync_lease_owner'         => null,
				'sync_lease_expires_at'    => null,
				'credential_rotated_at'    => $now,
				'enabled'                  => 0,
				'updated_at'               => $now,
			),
			array( 'public_id' => $public_id ),
			self::FORMATS
		);

		return $this->must_find( $public_id );
	}

	/**
	 * Mark credentials invalid after a provider preflight failure.
	 *
	 * @param string              $public_id Public ID.
	 * @param array<string,mixed> $error Redacted error document.
	 * @return ConnectionRecord
	 */
	public function mark_credential_invalid( string $public_id, array $error ): ConnectionRecord {
		PublicIds::assert( $public_id );
		$now = StorageValidator::now();

		$this->database->update_rows(
			$this->connections_table(),
			array(
				'verified_identity_hash' => null,
				'connection_status'      => 'credential_invalid',
				'last_error_json'        => $this->error_json( $error ),
				'enabled'                => 0,
				'updated_at'             => $now,
			),
			array( 'public_id' => $public_id ),
			self::FORMATS
		);

		return $this->must_find( $public_id );
	}

	/**
	 * Replace an existing credential envelope with compare and set.
	 *
	 * @param string              $public_id Public ID.
	 * @param string              $expected_rotated_at Expected credential rotation timestamp.
	 * @param string              $expected_envelope_hash Expected encrypted envelope hash.
	 * @param string              $credential_envelope_json New encrypted credential envelope JSON.
	 * @param string              $credential_envelope_hash New envelope hash.
	 * @param string              $remote_account_id Verified remote account ID.
	 * @param array<string,mixed> $account Account metadata.
	 * @param bool                $preserve_verification Whether the current selected organization is still accessible.
	 * @return ConnectionRecord
	 */
	public function replace_credential_if_current(
		string $public_id,
		string $expected_rotated_at,
		string $expected_envelope_hash,
		string $credential_envelope_json,
		string $credential_envelope_hash,
		string $remote_account_id,
		array $account,
		bool $preserve_verification
	): ConnectionRecord {
		$record = $this->must_find( $public_id );
		StorageValidator::assert_utc_timestamp( 'Expected credential rotation timestamp', $expected_rotated_at );
		StorageValidator::assert_hash( $expected_envelope_hash );
		StorageValidator::assert_hash( $credential_envelope_hash );
		StorageValidator::assert_remote_identifier( 'Remote account ID', $remote_account_id );
		CanonicalJson::assert_maximum_size( $credential_envelope_json, Schema::PROVIDER_JSON_MAX_BYTES );

		if (
			null === $record->credential_rotated_at
			|| null === $record->credential_envelope_hash
			|| $record->credential_rotated_at->format( 'Y-m-d H:i:s' ) !== $expected_rotated_at
			|| $record->credential_envelope_hash !== $expected_envelope_hash
		) {
			throw new StaleRecordException( 'Connection credential changed before rotation completed.' );
		}

		if ( null !== $record->remote_account_id && $record->remote_account_id !== $remote_account_id ) {
			throw new ValidationException( 'Replacement credential belongs to a different provider account.' );
		}

		$preserve_verified_identity = $preserve_verification && $this->has_verified_identity( $record );
		$now                        = StorageValidator::now();
		$values                     = array(
			'account_json'             => $this->account_json( $account ),
			'credential_envelope_json' => $credential_envelope_json,
			'credential_envelope_hash' => $credential_envelope_hash,
			'remote_account_id'        => $remote_account_id,
			'verified_identity_hash'   => $preserve_verified_identity ? $record->verified_identity_hash : null,
			'connection_status'        => $preserve_verified_identity ? 'verified' : 'unverified',
			'credential_verified_at'   => $now,
			'credential_rotated_at'    => $now,
			'last_error_json'          => null,
			'updated_at'               => $now,
		);

		if ( ! $preserve_verified_identity ) {
			$values['enabled'] = 0;
		}

		$updated = $this->database->update_rows(
			$this->connections_table(),
			$values,
			array(
				'public_id'                => $public_id,
				'credential_envelope_hash' => $expected_envelope_hash,
				'credential_rotated_at'    => $expected_rotated_at,
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StaleRecordException( 'Connection credential changed before rotation completed.' );
		}

		return $this->must_find( $public_id );
	}

	/**
	 * Check whether a connection exists.
	 *
	 * @param string $public_id Public ID.
	 * @return bool
	 */
	public function exists( string $public_id ): bool {
		PublicIds::assert( $public_id );

		$count = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $this->connections_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return (int) $count > 0;
	}

	/**
	 * Check whether a connection exists, is enabled, and is verified.
	 *
	 * @param string $public_id Public ID.
	 * @return bool
	 */
	public function is_enabled( string $public_id ): bool {
		PublicIds::assert( $public_id );

		$count = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $this->connections_table() . '` WHERE public_id = %s AND enabled = 1 AND connection_status = %s',
			array( $public_id, 'verified' )
		);

		return (int) $count > 0;
	}

	/**
	 * Create one connection row.
	 *
	 * @param string              $provider Provider key.
	 * @param string              $label Display label.
	 * @param array<string,mixed> $account Account metadata.
	 * @param string|null         $credential_envelope_json Credential envelope JSON.
	 * @param string|null         $credential_envelope_hash Credential envelope hash.
	 * @param string|null         $remote_account_id Remote account ID.
	 * @param string|null         $remote_organization_id Remote organization ID.
	 * @param string              $connection_status Connection status.
	 * @param bool                $enabled Whether the connection is enabled.
	 * @param string|null         $credential_rotated_at Credential rotation timestamp.
	 * @return ConnectionRecord
	 */
	private function insert_connection(
		string $provider,
		string $label,
		array $account,
		?string $credential_envelope_json,
		?string $credential_envelope_hash,
		?string $remote_account_id,
		?string $remote_organization_id,
		string $connection_status,
		bool $enabled,
		?string $credential_rotated_at
	): ConnectionRecord {
		StorageValidator::assert_text_key( 'Provider', $provider );
		StorageValidator::assert_label( 'Connection label', $label );
		StorageEnums::assert_connection_status( $connection_status );

		if ( null !== $credential_envelope_json ) {
			CanonicalJson::assert_maximum_size( $credential_envelope_json, Schema::PROVIDER_JSON_MAX_BYTES );
		}

		if ( null !== $credential_envelope_hash ) {
			StorageValidator::assert_hash( $credential_envelope_hash );
		}

		if ( null !== $remote_account_id ) {
			StorageValidator::assert_remote_identifier( 'Remote account ID', $remote_account_id );
		}

		if ( null !== $remote_organization_id ) {
			StorageValidator::assert_remote_identifier( 'Remote organization ID', $remote_organization_id );
		}

		$table        = $this->connections_table();
		$now          = StorageValidator::now();
		$account_json = $this->account_json( $account );

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				return $this->insert_connection_row(
					$public_id,
					$provider,
					$label,
					$account_json,
					$credential_envelope_json,
					$credential_envelope_hash,
					$remote_account_id,
					$remote_organization_id,
					null,
					$connection_status,
					$enabled,
					$credential_rotated_at,
					$now
				);
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue connection public ID.' );
	}

	/**
	 * Insert one prepared connection row.
	 *
	 * @param string      $public_id Public ID.
	 * @param string      $provider Provider key.
	 * @param string      $label Display label.
	 * @param string      $account_json Account JSON.
	 * @param string|null $credential_envelope_json Credential envelope JSON.
	 * @param string|null $credential_envelope_hash Credential envelope hash.
	 * @param string|null $remote_account_id Remote account ID.
	 * @param string|null $remote_organization_id Remote organization ID.
	 * @param string|null $verified_identity_hash Verified identity hash.
	 * @param string      $connection_status Connection status.
	 * @param bool        $enabled Whether the connection is enabled.
	 * @param string|null $credential_rotated_at Credential rotation timestamp.
	 * @param string|null $now Creation timestamp.
	 * @return ConnectionRecord
	 */
	private function insert_connection_row(
		string $public_id,
		string $provider,
		string $label,
		string $account_json,
		?string $credential_envelope_json,
		?string $credential_envelope_hash,
		?string $remote_account_id,
		?string $remote_organization_id,
		?string $verified_identity_hash,
		string $connection_status,
		bool $enabled,
		?string $credential_rotated_at,
		?string $now = null
	): ConnectionRecord {
		$now = $now ?? StorageValidator::now();

		$this->database->insert_row(
			$this->connections_table(),
			array(
				'public_id'                => $public_id,
				'provider'                 => $provider,
				'label'                    => $label,
				'account_json'             => $account_json,
				'credential_envelope_json' => $credential_envelope_json,
				'credential_envelope_hash' => $credential_envelope_hash,
				'remote_account_id'        => $remote_account_id,
				'remote_organization_id'   => $remote_organization_id,
				'verified_identity_hash'   => $verified_identity_hash,
				'connection_status'        => $connection_status,
				'sync_lease_owner'         => null,
				'sync_lease_expires_at'    => null,
				'credential_verified_at'   => null,
				'credential_rotated_at'    => $credential_rotated_at,
				'last_sync_started_at'     => null,
				'last_sync_completed_at'   => null,
				'last_error_json'          => null,
				'enabled'                  => $enabled ? 1 : 0,
				'created_at'               => $now,
				'updated_at'               => $now,
			),
			self::FORMATS
		);

		return $this->must_find( $public_id );
	}

	/**
	 * Return canonical account JSON.
	 *
	 * @param array<string,mixed> $account Account metadata.
	 * @return string
	 */
	private function account_json( array $account ): string {
		$account_json = CanonicalJson::encode_object( $account );
		CanonicalJson::assert_maximum_size( $account_json, Schema::ACCOUNT_JSON_MAX_BYTES );

		return $account_json;
	}

	/**
	 * Return canonical redacted error JSON.
	 *
	 * @param array<string,mixed> $error Error data.
	 * @return string
	 */
	private function error_json( array $error ): string {
		$error_json = CanonicalJson::encode_object( $error );
		CanonicalJson::assert_maximum_size( $error_json, Schema::PROVIDER_JSON_MAX_BYTES );

		return $error_json;
	}

	/**
	 * Check whether a connection currently has a complete verified identity.
	 *
	 * @param ConnectionRecord $record Connection.
	 * @return bool
	 */
	private function has_verified_identity( ConnectionRecord $record ): bool {
		return 'verified' === $record->connection_status
			&& null !== $record->credential_envelope_hash
			&& null !== $record->remote_account_id
			&& null !== $record->remote_organization_id
			&& null !== $record->verified_identity_hash
			&& null !== $record->last_sync_completed_at;
	}

	/**
	 * Check whether an account document includes an organization.
	 *
	 * @param array<string,mixed> $account Account document.
	 * @param string|null         $remote_organization_id Remote organization ID.
	 * @return bool
	 */
	private function account_contains_organization( array $account, ?string $remote_organization_id ): bool {
		if ( null === $remote_organization_id ) {
			return false;
		}

		$organizations = $account['organizations'] ?? array();
		if ( ! is_array( $organizations ) ) {
			return false;
		}

		foreach ( $organizations as $organization ) {
			if ( is_array( $organization ) && ( $organization['remote_organization_id'] ?? null ) === $remote_organization_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load a connection or fail.
	 *
	 * @param string $public_id Public ID.
	 * @return ConnectionRecord
	 */
	private function must_find( string $public_id ): ConnectionRecord {
		$record = $this->find_by_public_id( $public_id );

		if ( null === $record ) {
			throw new StorageException( 'Connection does not exist.' );
		}

		return $record;
	}

	/**
	 * Hydrate a connection record.
	 *
	 * @param object $row Database row.
	 * @return ConnectionRecord
	 */
	private function hydrate( object $row ): ConnectionRecord {
		$last_error = null === $row->last_error_json ? null : CanonicalJson::decode_object( (string) $row->last_error_json );
		$status     = (string) $row->connection_status;
		StorageEnums::assert_connection_status( $status );

		return new ConnectionRecord(
			(int) $row->id,
			(string) $row->public_id,
			(string) $row->provider,
			(string) $row->label,
			CanonicalJson::decode_object( (string) $row->account_json ),
			null !== $row->credential_envelope_json && '' !== (string) $row->credential_envelope_json,
			null === $row->credential_envelope_hash ? null : (string) $row->credential_envelope_hash,
			null === $row->remote_account_id ? null : (string) $row->remote_account_id,
			null === $row->remote_organization_id ? null : (string) $row->remote_organization_id,
			null === $row->verified_identity_hash ? null : (string) $row->verified_identity_hash,
			$status,
			null === $row->sync_lease_owner ? null : (string) $row->sync_lease_owner,
			StorageValidator::parse_date_time( null === $row->sync_lease_expires_at ? null : (string) $row->sync_lease_expires_at ),
			StorageValidator::parse_date_time( null === $row->credential_verified_at ? null : (string) $row->credential_verified_at ),
			StorageValidator::parse_date_time( null === $row->credential_rotated_at ? null : (string) $row->credential_rotated_at ),
			StorageValidator::parse_date_time( null === $row->last_sync_started_at ? null : (string) $row->last_sync_started_at ),
			StorageValidator::parse_date_time( null === $row->last_sync_completed_at ? null : (string) $row->last_sync_completed_at ),
			$last_error,
			'1' === (string) $row->enabled,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at )
		);
	}
}
