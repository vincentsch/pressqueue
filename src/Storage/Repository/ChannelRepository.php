<?php
/**
 * Channel repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Provider\Dto\ProviderChannel;
use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DestinationStatus;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Persists local remote-destination metadata.
 */
final class ChannelRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                      => '%d',
		'public_id'               => '%s',
		'connection_id'           => '%d',
		'connection_public_id'    => '%s',
		'provider'                => '%s',
		'remote_organization_id'  => '%s',
		'remote_channel_id'       => '%s',
		'service'                 => '%s',
		'display_json'            => '%s',
		'capabilities_json'       => '%s',
		'availability'            => '%s',
		'enabled'                 => '%d',
		'last_synced_at'          => '%s',
		'last_seen_at'            => '%s',
		'availability_checked_at' => '%s',
		'created_at'              => '%s',
		'updated_at'              => '%s',
	);

	/**
	 * Create a disabled-by-default channel.
	 *
	 * @param string              $connection_public_id Connection public ID.
	 * @param string              $provider Provider key.
	 * @param string              $remote_channel_id Remote channel ID.
	 * @param string              $service Service key.
	 * @param array<string,mixed> $display Display metadata.
	 * @param array<string,mixed> $capabilities Capability metadata.
	 * @param bool                $enabled Whether the channel starts enabled.
	 * @param string|null         $last_synced_at Last sync timestamp.
	 * @param string              $remote_organization_id Remote organization ID.
	 * @param string              $availability Availability state.
	 * @return ChannelRecord
	 */
	public function create(
		string $connection_public_id,
		string $provider,
		string $remote_channel_id,
		string $service,
		array $display = array(),
		array $capabilities = array(),
		bool $enabled = false,
		?string $last_synced_at = null,
		string $remote_organization_id = '',
		string $availability = 'unverified'
	): ChannelRecord {
		return $this->insert_channel(
			$this->connection_for_channel( $connection_public_id, $provider ),
			$remote_organization_id,
			$remote_channel_id,
			$service,
			$display,
			$capabilities,
			$availability,
			$enabled,
			$last_synced_at,
			null,
			null
		);
	}

	/**
	 * Synchronize provider channels for one lease holder.
	 *
	 * @param ConnectionRecord           $connection Connection.
	 * @param string                     $lease_owner Sync lease owner.
	 * @param array<int,ProviderChannel> $provider_channels Provider channel DTOs.
	 * @return array<int,ChannelRecord>
	 */
	public function synchronize_provider_channels( ConnectionRecord $connection, string $lease_owner, array $provider_channels ): array {
		StorageValidator::assert_lease_owner( $lease_owner );

		if ( $connection->sync_lease_owner !== $lease_owner || ! $this->connection_holds_sync_lease( $connection->public_id, $lease_owner ) ) {
			throw new StaleRecordException( 'Connection sync lease is no longer held.' );
		}

		if ( null === $connection->remote_organization_id ) {
			throw new ValidationException( 'Connection must have a selected organization before channel sync.' );
		}

		$now     = StorageValidator::now();
		$seen    = array();
		$records = array();

		foreach ( $provider_channels as $provider_channel ) {
			if ( ! $provider_channel instanceof ProviderChannel ) {
				throw new ValidationException( 'Provider channel list contains an unsupported item.' );
			}

			if ( $provider_channel->provider !== $connection->provider ) {
				throw new ValidationException( 'Provider channel belongs to a different provider.' );
			}

			if ( $provider_channel->remote_organization_id === $connection->remote_organization_id ) {
				$seen[ $provider_channel->remote_channel_id ] = true;
			}

			$records[] = $this->ensure_provider_channel( $connection, $lease_owner, $provider_channel, $now );
		}

		$this->mark_missing_channels( $connection->public_id, $lease_owner, $connection->remote_organization_id, array_keys( $seen ), $now );

		return $records;
	}

	/**
	 * Mark every channel for an organization as missing.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @param string           $lease_owner Sync lease owner.
	 * @param string           $remote_organization_id Remote organization ID.
	 */
	public function mark_organization_channels_missing( ConnectionRecord $connection, string $lease_owner, string $remote_organization_id ): void {
		StorageValidator::assert_lease_owner( $lease_owner );
		StorageValidator::assert_remote_identifier( 'Remote organization ID', $remote_organization_id );
		$now = StorageValidator::now();

		$updated = $this->database->query(
			'UPDATE `' . $this->channels_table() . '`
				SET availability = %s,
					enabled = 0,
					availability_checked_at = %s,
					updated_at = %s
				WHERE connection_public_id = %s
					AND remote_organization_id = %s
					AND EXISTS (
						SELECT 1 FROM `' . $this->connections_table() . '`
						WHERE public_id = %s AND sync_lease_owner = %s
					)',
			array( 'missing', $now, $now, $connection->public_id, $remote_organization_id, $connection->public_id, $lease_owner )
		);

		if ( 0 === $updated && ! $this->connection_holds_sync_lease( $connection->public_id, $lease_owner ) ) {
			throw new StaleRecordException( 'Connection sync lease is no longer held.' );
		}
	}

	/**
	 * Find a channel by public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return ChannelRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?ChannelRecord {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->channels_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Find a channel by organization-scoped remote identity.
	 *
	 * @param string $connection_public_id Connection public ID.
	 * @param string $remote_organization_id Remote organization ID.
	 * @param string $remote_channel_id Remote channel ID.
	 * @return ChannelRecord|null
	 */
	public function find_by_remote_identity( string $connection_public_id, string $remote_organization_id, string $remote_channel_id ): ?ChannelRecord {
		PublicIds::assert( $connection_public_id );
		StorageValidator::assert_remote_identifier( 'Remote organization ID', $remote_organization_id );
		StorageValidator::assert_remote_identifier( 'Remote channel ID', $remote_channel_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->channels_table() . '` WHERE connection_public_id = %s AND remote_organization_id = %s AND remote_channel_id = %s',
			array( $connection_public_id, $remote_organization_id, $remote_channel_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return channels for a connection.
	 *
	 * @param string $connection_public_id Connection public ID.
	 * @return array<int,ChannelRecord>
	 */
	public function find_by_connection( string $connection_public_id ): array {
		PublicIds::assert( $connection_public_id );

		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->channels_table() . '` WHERE connection_public_id = %s ORDER BY service ASC, remote_organization_id ASC, remote_channel_id ASC',
			array( $connection_public_id )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return all local channels ordered for administration.
	 *
	 * @return array<int,ChannelRecord>
	 */
	public function find_all(): array {
		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->channels_table() . '` ORDER BY provider ASC, service ASC, remote_organization_id ASC, public_id ASC'
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Enable or disable a channel with optimistic concurrency.
	 *
	 * @param string $public_id Public ID.
	 * @param bool   $enabled Whether to enable.
	 * @param string $expected_updated_at Expected updated timestamp.
	 * @return ChannelRecord
	 */
	public function set_enabled_if_current( string $public_id, bool $enabled, string $expected_updated_at ): ChannelRecord {
		$channel = $this->must_find( $public_id );
		StorageValidator::assert_utc_timestamp( 'Expected update timestamp', $expected_updated_at );

		if ( $enabled && ! $this->can_enable( $channel ) ) {
			throw new ValidationException( 'Channel must be available with schema version 2 delivery capability before it can be enabled.' );
		}

		$now     = StorageValidator::now();
		$updated = $this->database->update_rows(
			$this->channels_table(),
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => $now,
			),
			array(
				'public_id'  => $public_id,
				'updated_at' => $expected_updated_at,
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StaleRecordException( 'Channel changed before the enablement action completed.' );
		}

		return $this->must_find( $public_id );
	}

	/**
	 * Find existence and enabled status for a destination.
	 *
	 * @param string $channel_public_id Channel public ID.
	 * @return DestinationStatus
	 */
	public function destination_status( string $channel_public_id ): DestinationStatus {
		PublicIds::assert( $channel_public_id );

		$row = $this->database->get_row(
			'SELECT ch.public_id AS channel_public_id,
					ch.connection_public_id,
					ch.provider,
					ch.remote_channel_id,
					ch.capabilities_json,
					ch.availability,
					ch.enabled AS channel_enabled,
					conn.id AS connection_exists_id,
					conn.enabled AS connection_enabled,
					conn.connection_status
				FROM `' . $this->channels_table() . '` ch
				LEFT JOIN `' . $this->connections_table() . '` conn
					ON conn.id = ch.connection_id AND conn.public_id = ch.connection_public_id
				WHERE ch.public_id = %s',
			array( $channel_public_id )
		);

		if ( ! $row ) {
			return new DestinationStatus( false, false, false, false, null, null, null, null, null, $channel_public_id, null );
		}

		return new DestinationStatus(
			true,
			'1' === (string) $row->channel_enabled,
			null !== $row->connection_exists_id,
			'1' === (string) $row->connection_enabled,
			null === $row->connection_status ? null : (string) $row->connection_status,
			(string) $row->availability,
			CanonicalJson::decode_object( (string) $row->capabilities_json ),
			(string) $row->provider,
			(string) $row->connection_public_id,
			(string) $row->channel_public_id,
			(string) $row->remote_channel_id
		);
	}

	/**
	 * Insert or update one provider channel.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @param string           $lease_owner Sync lease owner.
	 * @param ProviderChannel  $provider_channel Provider channel.
	 * @param string           $now Current timestamp.
	 * @return ChannelRecord
	 */
	private function ensure_provider_channel( ConnectionRecord $connection, string $lease_owner, ProviderChannel $provider_channel, string $now ): ChannelRecord {
		$existing = $this->find_by_remote_identity(
			$connection->public_id,
			$provider_channel->remote_organization_id,
			$provider_channel->remote_channel_id
		);

		if ( null === $existing ) {
			return $this->insert_channel_for_lease(
				$connection,
				$lease_owner,
				$provider_channel->remote_organization_id,
				$provider_channel->remote_channel_id,
				$provider_channel->service,
				$provider_channel->display_document(),
				$provider_channel->capability_document,
				$provider_channel->availability,
				false,
				$now,
				$now,
				$now
			);
		}

		$this->update_channel_for_lease( $connection->public_id, $lease_owner, $existing->public_id, $provider_channel, $now );

		return $this->must_find( $existing->public_id );
	}

	/**
	 * Insert one provider channel only while the sync lease is still held.
	 *
	 * @param ConnectionRecord    $connection Connection.
	 * @param string              $lease_owner Sync lease owner.
	 * @param string              $remote_organization_id Remote organization ID.
	 * @param string              $remote_channel_id Remote channel ID.
	 * @param string              $service Service key.
	 * @param array<string,mixed> $display Display metadata.
	 * @param array<string,mixed> $capabilities Capability metadata.
	 * @param string              $availability Availability state.
	 * @param bool                $enabled Whether the channel is enabled.
	 * @param string|null         $last_synced_at Last sync timestamp.
	 * @param string|null         $last_seen_at Last seen timestamp.
	 * @param string|null         $availability_checked_at Availability check timestamp.
	 * @return ChannelRecord
	 */
	private function insert_channel_for_lease(
		ConnectionRecord $connection,
		string $lease_owner,
		string $remote_organization_id,
		string $remote_channel_id,
		string $service,
		array $display,
		array $capabilities,
		string $availability,
		bool $enabled,
		?string $last_synced_at,
		?string $last_seen_at,
		?string $availability_checked_at
	): ChannelRecord {
		if ( '' !== $remote_organization_id ) {
			StorageValidator::assert_remote_identifier( 'Remote organization ID', $remote_organization_id );
		}

		StorageValidator::assert_lease_owner( $lease_owner );
		StorageValidator::assert_remote_identifier( 'Remote channel ID', $remote_channel_id );
		StorageValidator::assert_text_key( 'Service', $service );
		StorageEnums::assert_channel_availability( $availability );

		foreach ( array( $last_synced_at, $last_seen_at, $availability_checked_at ) as $timestamp ) {
			if ( null !== $timestamp ) {
				StorageValidator::assert_utc_timestamp( 'Channel timestamp', $timestamp );
			}
		}

		$table             = $this->channels_table();
		$connections_table = $this->connections_table();
		$display_json      = $this->provider_json( $display );
		$capabilities_json = $this->provider_json( $capabilities );
		$now               = StorageValidator::now();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$inserted = $this->database->query(
					"INSERT INTO `$table` (
						public_id,
						connection_id,
						connection_public_id,
						provider,
						remote_organization_id,
						remote_channel_id,
						service,
						display_json,
						capabilities_json,
						availability,
						enabled,
						last_synced_at,
						last_seen_at,
						availability_checked_at,
						created_at,
						updated_at
					)
					SELECT
						%s,
						conn.id,
						conn.public_id,
						conn.provider,
						%s,
						%s,
						%s,
						%s,
						%s,
						%s,
						%d,
						%s,
						%s,
						%s,
						%s,
						%s
					FROM `$connections_table` conn
					WHERE conn.public_id = %s AND conn.sync_lease_owner = %s",
					array(
						$public_id,
						$remote_organization_id,
						$remote_channel_id,
						$service,
						$display_json,
						$capabilities_json,
						$availability,
						$enabled ? 1 : 0,
						$last_synced_at,
						$last_seen_at,
						$availability_checked_at,
						$now,
						$now,
						$connection->public_id,
						$lease_owner,
					)
				);

				if ( 0 === $inserted ) {
					throw new StaleRecordException( 'Connection sync lease is no longer held.' );
				}

				return $this->must_find( $public_id );
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue channel public ID.' );
	}

	/**
	 * Update one existing channel only while the sync lease is still held.
	 *
	 * @param string          $connection_public_id Connection public ID.
	 * @param string          $lease_owner Sync lease owner.
	 * @param string          $channel_public_id Channel public ID.
	 * @param ProviderChannel $provider_channel Provider channel.
	 * @param string          $now Current timestamp.
	 */
	private function update_channel_for_lease( string $connection_public_id, string $lease_owner, string $channel_public_id, ProviderChannel $provider_channel, string $now ): void {
		$sets = array(
			'service = %s',
			'display_json = %s',
			'capabilities_json = %s',
			'availability = %s',
		);
		$args = array(
			$provider_channel->service,
			$this->provider_json( $provider_channel->display_document() ),
			$this->provider_json( $provider_channel->capability_document ),
			$provider_channel->availability,
		);

		if ( 'available' !== $provider_channel->availability ) {
			$sets[] = 'enabled = %d';
			$args[] = 0;
		}

		$sets[] = 'last_synced_at = %s';
		$sets[] = 'last_seen_at = %s';
		$sets[] = 'availability_checked_at = %s';
		$sets[] = 'updated_at = %s';
		array_push( $args, $now, $now, $now, $now, $channel_public_id, $connection_public_id, $lease_owner );

		$updated = $this->database->query(
			'UPDATE `' . $this->channels_table() . '`
				SET ' . implode( ', ', $sets ) . '
				WHERE public_id = %s
					AND EXISTS (
						SELECT 1 FROM `' . $this->connections_table() . '`
						WHERE public_id = %s AND sync_lease_owner = %s
					)',
			$args
		);

		if ( 0 === $updated && ! $this->connection_holds_sync_lease( $connection_public_id, $lease_owner ) ) {
			throw new StaleRecordException( 'Connection sync lease is no longer held.' );
		}
	}

	/**
	 * Create one channel row.
	 *
	 * @param ConnectionRecord    $connection Connection.
	 * @param string              $remote_organization_id Remote organization ID.
	 * @param string              $remote_channel_id Remote channel ID.
	 * @param string              $service Service key.
	 * @param array<string,mixed> $display Display metadata.
	 * @param array<string,mixed> $capabilities Capability metadata.
	 * @param string              $availability Availability state.
	 * @param bool                $enabled Whether the channel is enabled.
	 * @param string|null         $last_synced_at Last sync timestamp.
	 * @param string|null         $last_seen_at Last seen timestamp.
	 * @param string|null         $availability_checked_at Availability check timestamp.
	 * @return ChannelRecord
	 */
	private function insert_channel(
		ConnectionRecord $connection,
		string $remote_organization_id,
		string $remote_channel_id,
		string $service,
		array $display,
		array $capabilities,
		string $availability,
		bool $enabled,
		?string $last_synced_at,
		?string $last_seen_at,
		?string $availability_checked_at
	): ChannelRecord {
		if ( '' !== $remote_organization_id ) {
			StorageValidator::assert_remote_identifier( 'Remote organization ID', $remote_organization_id );
		}

		StorageValidator::assert_remote_identifier( 'Remote channel ID', $remote_channel_id );
		StorageValidator::assert_text_key( 'Service', $service );
		StorageEnums::assert_channel_availability( $availability );

		foreach ( array( $last_synced_at, $last_seen_at, $availability_checked_at ) as $timestamp ) {
			if ( null !== $timestamp ) {
				StorageValidator::assert_utc_timestamp( 'Channel timestamp', $timestamp );
			}
		}

		$table = $this->channels_table();
		$now   = StorageValidator::now();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'               => $public_id,
						'connection_id'           => $connection->id,
						'connection_public_id'    => $connection->public_id,
						'provider'                => $connection->provider,
						'remote_organization_id'  => $remote_organization_id,
						'remote_channel_id'       => $remote_channel_id,
						'service'                 => $service,
						'display_json'            => $this->provider_json( $display ),
						'capabilities_json'       => $this->provider_json( $capabilities ),
						'availability'            => $availability,
						'enabled'                 => $enabled ? 1 : 0,
						'last_synced_at'          => $last_synced_at,
						'last_seen_at'            => $last_seen_at,
						'availability_checked_at' => $availability_checked_at,
						'created_at'              => $now,
						'updated_at'              => $now,
					),
					self::FORMATS
				);

				return $this->must_find( $public_id );
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue channel public ID.' );
	}

	/**
	 * Mark omitted channels as missing after a successful sync.
	 *
	 * @param string            $connection_public_id Connection public ID.
	 * @param string            $lease_owner Sync lease owner.
	 * @param string            $remote_organization_id Remote organization ID.
	 * @param array<int,string> $seen_remote_channel_ids Seen channel IDs.
	 * @param string            $now Current timestamp.
	 */
	private function mark_missing_channels( string $connection_public_id, string $lease_owner, string $remote_organization_id, array $seen_remote_channel_ids, string $now ): void {
		if ( array() === $seen_remote_channel_ids ) {
			$updated = $this->database->query(
				'UPDATE `' . $this->channels_table() . '`
					SET availability = %s,
						enabled = 0,
						availability_checked_at = %s,
						updated_at = %s
					WHERE connection_public_id = %s
						AND remote_organization_id = %s
						AND EXISTS (
							SELECT 1 FROM `' . $this->connections_table() . '`
							WHERE public_id = %s AND sync_lease_owner = %s
						)',
				array( 'missing', $now, $now, $connection_public_id, $remote_organization_id, $connection_public_id, $lease_owner )
			);
			if ( 0 === $updated && ! $this->connection_holds_sync_lease( $connection_public_id, $lease_owner ) ) {
				throw new StaleRecordException( 'Connection sync lease is no longer held.' );
			}

			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $seen_remote_channel_ids ), '%s' ) );
		$args         = array_merge(
			array( 'missing', $now, $now, $connection_public_id, $remote_organization_id ),
			$seen_remote_channel_ids,
			array( $connection_public_id, $lease_owner )
		);

		$updated = $this->database->query(
			'UPDATE `' . $this->channels_table() . '`
				SET availability = %s,
					enabled = 0,
					availability_checked_at = %s,
					updated_at = %s
				WHERE connection_public_id = %s
					AND remote_organization_id = %s
					AND remote_channel_id NOT IN (' . $placeholders . ')
					AND EXISTS (
						SELECT 1 FROM `' . $this->connections_table() . '`
						WHERE public_id = %s AND sync_lease_owner = %s
					)',
			$args
		);

		if ( 0 === $updated && ! $this->connection_holds_sync_lease( $connection_public_id, $lease_owner ) ) {
			throw new StaleRecordException( 'Connection sync lease is no longer held.' );
		}
	}

	/**
	 * Check whether a channel can be enabled.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @return bool
	 */
	private function can_enable( ChannelRecord $channel ): bool {
		return 'available' === $channel->availability
			&& DeliveryCapability::can_enable( $channel->capabilities );
	}

	/**
	 * Check whether the connection still holds the sync lease.
	 *
	 * @param string $connection_public_id Connection public ID.
	 * @param string $lease_owner Sync lease owner.
	 * @return bool
	 */
	private function connection_holds_sync_lease( string $connection_public_id, string $lease_owner ): bool {
		PublicIds::assert( $connection_public_id );
		StorageValidator::assert_lease_owner( $lease_owner );

		$count = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $this->connections_table() . '` WHERE public_id = %s AND sync_lease_owner = %s',
			array( $connection_public_id, $lease_owner )
		);

		return (int) $count > 0;
	}

	/**
	 * Load and validate a connection for channel creation.
	 *
	 * @param string $connection_public_id Connection public ID.
	 * @param string $provider Provider key.
	 * @return ConnectionRecord
	 */
	private function connection_for_channel( string $connection_public_id, string $provider ): ConnectionRecord {
		PublicIds::assert( $connection_public_id );
		StorageValidator::assert_text_key( 'Provider', $provider );

		$connection = ( new ConnectionRepository( $this->database, $this->public_ids ) )->find_by_public_id( $connection_public_id );

		if ( null === $connection ) {
			throw new StorageException( 'Channel connection does not exist.' );
		}

		if ( $connection->provider !== $provider ) {
			throw new StorageException( 'Channel provider must match its connection provider.' );
		}

		return $connection;
	}

	/**
	 * Return canonical provider JSON.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return string
	 */
	private function provider_json( array $document ): string {
		$json = CanonicalJson::encode_object( $document );
		CanonicalJson::assert_maximum_size( $json, Schema::PROVIDER_JSON_MAX_BYTES );

		return $json;
	}

	/**
	 * Load a channel or fail.
	 *
	 * @param string $public_id Public ID.
	 * @return ChannelRecord
	 */
	private function must_find( string $public_id ): ChannelRecord {
		$record = $this->find_by_public_id( $public_id );

		if ( null === $record ) {
			throw new StorageException( 'Channel does not exist.' );
		}

		return $record;
	}

	/**
	 * Hydrate a channel record.
	 *
	 * @param object $row Database row.
	 * @return ChannelRecord
	 */
	private function hydrate( object $row ): ChannelRecord {
		$availability = (string) $row->availability;
		StorageEnums::assert_channel_availability( $availability );

		return new ChannelRecord(
			(int) $row->id,
			(string) $row->public_id,
			(int) $row->connection_id,
			(string) $row->connection_public_id,
			(string) $row->provider,
			(string) $row->remote_organization_id,
			(string) $row->remote_channel_id,
			(string) $row->service,
			CanonicalJson::decode_object( (string) $row->display_json ),
			CanonicalJson::decode_object( (string) $row->capabilities_json ),
			$availability,
			'1' === (string) $row->enabled,
			StorageValidator::parse_date_time( null === $row->last_synced_at ? null : (string) $row->last_synced_at ),
			StorageValidator::parse_date_time( null === $row->last_seen_at ? null : (string) $row->last_seen_at ),
			StorageValidator::parse_date_time( null === $row->availability_checked_at ? null : (string) $row->availability_checked_at ),
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at )
		);
	}
}
