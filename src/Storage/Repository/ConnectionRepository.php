<?php
/**
 * Connection repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;

/**
 * Persists provider connection metadata.
 */
final class ConnectionRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'           => '%d',
		'public_id'    => '%s',
		'provider'     => '%s',
		'label'        => '%s',
		'account_json' => '%s',
		'enabled'      => '%d',
		'created_at'   => '%s',
		'updated_at'   => '%s',
	);

	/**
	 * Create a disabled-by-default connection.
	 *
	 * @param string              $provider Provider key.
	 * @param string              $label Display label.
	 * @param array<string,mixed> $account Account metadata.
	 * @param bool                $enabled Whether the connection starts enabled.
	 * @return ConnectionRecord
	 */
	public function create( string $provider, string $label, array $account = array(), bool $enabled = false ): ConnectionRecord {
		StorageValidator::assert_text_key( 'Provider', $provider );
		StorageValidator::assert_label( 'Connection label', $label );

		$account_json = CanonicalJson::encode_object( $account );
		CanonicalJson::assert_maximum_size( $account_json, 65536 );

		$table = $this->connections_table();
		$now   = StorageValidator::now();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'    => $public_id,
						'provider'     => $provider,
						'label'        => $label,
						'account_json' => $account_json,
						'enabled'      => $enabled ? 1 : 0,
						'created_at'   => $now,
						'updated_at'   => $now,
					),
					self::FORMATS
				);

				$record = $this->find_by_public_id( $public_id );

				if ( null === $record ) {
					throw new StorageException( 'Created connection could not be loaded.' );
				}

				return $record;
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
	 * Check whether a connection exists and is enabled.
	 *
	 * @param string $public_id Public ID.
	 * @return bool
	 */
	public function is_enabled( string $public_id ): bool {
		PublicIds::assert( $public_id );

		$count = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $this->connections_table() . '` WHERE public_id = %s AND enabled = 1',
			array( $public_id )
		);

		return (int) $count > 0;
	}

	/**
	 * Hydrate a connection record.
	 *
	 * @param object $row Database row.
	 * @return ConnectionRecord
	 */
	private function hydrate( object $row ): ConnectionRecord {
		return new ConnectionRecord(
			(int) $row->id,
			(string) $row->public_id,
			(string) $row->provider,
			(string) $row->label,
			CanonicalJson::decode_object( (string) $row->account_json ),
			'1' === (string) $row->enabled,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at )
		);
	}
}
