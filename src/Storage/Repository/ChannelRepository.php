<?php
/**
 * Channel repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\DestinationStatus;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;

/**
 * Persists local remote-destination metadata.
 */
final class ChannelRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                   => '%d',
		'public_id'            => '%s',
		'connection_id'        => '%d',
		'connection_public_id' => '%s',
		'provider'             => '%s',
		'remote_channel_id'    => '%s',
		'service'              => '%s',
		'display_json'         => '%s',
		'capabilities_json'    => '%s',
		'enabled'              => '%d',
		'last_synced_at'       => '%s',
		'created_at'           => '%s',
		'updated_at'           => '%s',
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
		?string $last_synced_at = null
	): ChannelRecord {
		PublicIds::assert( $connection_public_id );
		StorageValidator::assert_text_key( 'Provider', $provider );
		StorageValidator::assert_text_key( 'Remote channel ID', $remote_channel_id, 191 );
		StorageValidator::assert_text_key( 'Service', $service );

		if ( null !== $last_synced_at ) {
			StorageValidator::assert_utc_timestamp( 'Last synced timestamp', $last_synced_at );
		}

		$connection = ( new ConnectionRepository( $this->database, $this->public_ids ) )->find_by_public_id( $connection_public_id );

		if ( null === $connection ) {
			throw new StorageException( 'Channel connection does not exist.' );
		}

		if ( $connection->provider !== $provider ) {
			throw new StorageException( 'Channel provider must match its connection provider.' );
		}

		$display_json = CanonicalJson::encode_object( $display );
		CanonicalJson::assert_maximum_size( $display_json, 65536 );

		$capabilities_json = CanonicalJson::encode_object( $capabilities );
		CanonicalJson::assert_maximum_size( $capabilities_json, 65536 );

		$table = $this->channels_table();
		$now   = StorageValidator::now();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'            => $public_id,
						'connection_id'        => $connection->id,
						'connection_public_id' => $connection->public_id,
						'provider'             => $provider,
						'remote_channel_id'    => $remote_channel_id,
						'service'              => $service,
						'display_json'         => $display_json,
						'capabilities_json'    => $capabilities_json,
						'enabled'              => $enabled ? 1 : 0,
						'last_synced_at'       => $last_synced_at,
						'created_at'           => $now,
						'updated_at'           => $now,
					),
					self::FORMATS
				);

				$record = $this->find_by_public_id( $public_id );

				if ( null === $record ) {
					throw new StorageException( 'Created channel could not be loaded.' );
				}

				return $record;
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
	 * Return all local channels ordered for administration.
	 *
	 * @return array<int,ChannelRecord>
	 */
	public function find_all(): array {
		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->channels_table() . '` ORDER BY provider ASC, service ASC, public_id ASC'
		);

		return array_map( array( $this, 'hydrate' ), $rows );
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
			'SELECT ch.public_id AS channel_public_id, ch.connection_public_id, ch.provider, ch.remote_channel_id, ch.enabled AS channel_enabled, conn.id AS connection_exists_id, conn.enabled AS connection_enabled
				FROM `' . $this->channels_table() . '` ch
				LEFT JOIN `' . $this->connections_table() . '` conn
					ON conn.id = ch.connection_id AND conn.public_id = ch.connection_public_id
				WHERE ch.public_id = %s',
			array( $channel_public_id )
		);

		if ( ! $row ) {
			return new DestinationStatus( false, false, false, false, null, null, $channel_public_id, null );
		}

		return new DestinationStatus(
			true,
			'1' === (string) $row->channel_enabled,
			null !== $row->connection_exists_id,
			'1' === (string) $row->connection_enabled,
			(string) $row->provider,
			(string) $row->connection_public_id,
			(string) $row->channel_public_id,
			(string) $row->remote_channel_id
		);
	}

	/**
	 * Hydrate a channel record.
	 *
	 * @param object $row Database row.
	 * @return ChannelRecord
	 */
	private function hydrate( object $row ): ChannelRecord {
		return new ChannelRecord(
			(int) $row->id,
			(string) $row->public_id,
			(int) $row->connection_id,
			(string) $row->connection_public_id,
			(string) $row->provider,
			(string) $row->remote_channel_id,
			(string) $row->service,
			CanonicalJson::decode_object( (string) $row->display_json ),
			CanonicalJson::decode_object( (string) $row->capabilities_json ),
			'1' === (string) $row->enabled,
			StorageValidator::parse_date_time( null === $row->last_synced_at ? null : (string) $row->last_synced_at ),
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at )
		);
	}
}
