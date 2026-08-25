<?php
/**
 * Shared repository behavior.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\Database;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIdGenerator;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\UlidPublicIdGenerator;

/**
 * Base class for prepared table repositories.
 */
abstract class AbstractRepository {
	/**
	 * Database wrapper.
	 *
	 * @var Database
	 */
	protected Database $database;

	/**
	 * Public ID generator.
	 *
	 * @var PublicIdGenerator
	 */
	protected PublicIdGenerator $public_ids;

	/**
	 * Constructor.
	 *
	 * @param Database|null          $database Database wrapper.
	 * @param PublicIdGenerator|null $public_ids Public ID generator.
	 */
	public function __construct( ?Database $database = null, ?PublicIdGenerator $public_ids = null ) {
		$this->database   = $database ?? Database::from_globals();
		$this->public_ids = $public_ids ?? new UlidPublicIdGenerator();
	}

	/**
	 * Generate a unique public ID for a table.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	protected function generate_unique_public_id( string $table ): string {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->public_ids->generate();
			PublicIds::assert( $public_id );

			if ( ! $this->public_id_exists( $table, $public_id ) ) {
				return $public_id;
			}
		}

		throw new PublicIdCollisionException( 'Could not generate a unique PressQueue public ID.' );
	}

	/**
	 * Check whether a table already contains a public ID.
	 *
	 * @param string $table Table name.
	 * @param string $public_id Public ID.
	 * @return bool
	 */
	protected function public_id_exists( string $table, string $public_id ): bool {
		$count = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $table . '` WHERE public_id = %s',
			array( $public_id )
		);

		return (int) $count > 0;
	}

	/**
	 * Return a concrete table name.
	 *
	 * @param string $suffix Schema suffix.
	 * @return string
	 */
	protected function table( string $suffix ): string {
		return $this->database->table( $suffix );
	}

	/**
	 * Return the connections table.
	 *
	 * @return string
	 */
	protected function connections_table(): string {
		return $this->table( Schema::CONNECTIONS );
	}

	/**
	 * Return the channels table.
	 *
	 * @return string
	 */
	protected function channels_table(): string {
		return $this->table( Schema::CHANNELS );
	}

	/**
	 * Return the rules table.
	 *
	 * @return string
	 */
	protected function rules_table(): string {
		return $this->table( Schema::RULES );
	}

	/**
	 * Return the templates table.
	 *
	 * @return string
	 */
	protected function templates_table(): string {
		return $this->table( Schema::TEMPLATES );
	}

	/**
	 * Return the decisions table.
	 *
	 * @return string
	 */
	protected function decisions_table(): string {
		return $this->table( Schema::DECISIONS );
	}

	/**
	 * Return the deliveries table.
	 *
	 * @return string
	 */
	protected function deliveries_table(): string {
		return $this->table( Schema::DELIVERIES );
	}

	/**
	 * Check whether a database failure was a duplicate key error.
	 *
	 * @param StorageException $exception Storage failure.
	 * @return bool
	 */
	protected function is_duplicate_error( StorageException $exception ): bool {
		return false !== stripos( $exception->getMessage(), 'duplicate' );
	}

	/**
	 * Check whether a duplicate failure names the public ID key.
	 *
	 * @param StorageException $exception Storage failure.
	 * @return bool
	 */
	protected function is_duplicate_public_id_error( StorageException $exception ): bool {
		return $this->is_duplicate_error( $exception )
			&& false !== stripos( $exception->getMessage(), 'public_id' );
	}
}
