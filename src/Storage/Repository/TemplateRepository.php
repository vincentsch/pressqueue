<?php
/**
 * Template repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\TemplateRecord;
use PressQueue\Storage\RuleDocuments;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Persists reusable template source records.
 */
final class TemplateRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                => '%d',
		'public_id'         => '%s',
		'name'              => '%s',
		'template_set_json' => '%s',
		'enabled'           => '%d',
		'version'           => '%d',
		'lock_version'      => '%d',
		'created_at'        => '%s',
		'updated_at'        => '%s',
	);

	/**
	 * Create a disabled reusable template.
	 *
	 * @param string              $name Display name.
	 * @param array<string,mixed> $template_set Template set.
	 * @return TemplateRecord
	 */
	public function create( string $name, array $template_set ): TemplateRecord {
		$prepared = $this->prepare( $name, $template_set );
		$table    = $this->templates_table();
		$now      = StorageValidator::now();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'         => $public_id,
						'name'              => $prepared['name'],
						'template_set_json' => $prepared['template_set_json'],
						'enabled'           => 0,
						'version'           => 1,
						'lock_version'      => 1,
						'created_at'        => $now,
						'updated_at'        => $now,
					),
					self::FORMATS
				);

				$record = $this->find_by_public_id( $public_id );
				if ( null === $record ) {
					throw new StorageException( 'Created template could not be loaded.' );
				}

				return $record;
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue template public ID.' );
	}

	/**
	 * Update name or behavior through optimistic locking.
	 *
	 * @param string              $public_id Public ID.
	 * @param int                 $expected_lock_version Expected lock version.
	 * @param array<string,mixed> $changes Changes.
	 * @return TemplateRecord
	 */
	public function update( string $public_id, int $expected_lock_version, array $changes ): TemplateRecord {
		$current = $this->must_find( $public_id );

		if ( $current->lock_version !== $expected_lock_version ) {
			throw new StaleRecordException( 'Template lock version is stale.' );
		}

		$name         = isset( $changes['name'] ) ? (string) $changes['name'] : $current->name;
		$template_set = isset( $changes['template_set'] ) && is_array( $changes['template_set'] ) ? $changes['template_set'] : $current->template_set;
		$prepared     = $this->prepare( $name, $template_set );
		$next_version = CanonicalJson::encode_object( $current->template_set ) === CanonicalJson::encode_object( $prepared['template_set'] )
			? $current->version
			: $current->version + 1;

		if ( $current->name === $prepared['name'] && $current->version === $next_version ) {
			return $current;
		}

		$updated = $this->database->update_rows(
			$this->templates_table(),
			array(
				'name'              => $prepared['name'],
				'template_set_json' => $prepared['template_set_json'],
				'version'           => $next_version,
				'lock_version'      => $current->lock_version + 1,
				'updated_at'        => StorageValidator::now(),
			),
			array(
				'public_id'    => $current->public_id,
				'lock_version' => $expected_lock_version,
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StaleRecordException( 'Template lock version is stale.' );
		}

		return $this->must_find( $public_id );
	}

	/**
	 * Enable or disable a template source.
	 *
	 * @param string $public_id Public ID.
	 * @param int    $expected_lock_version Expected lock version.
	 * @param bool   $enabled Whether selectable.
	 * @return TemplateRecord
	 */
	public function set_enabled( string $public_id, int $expected_lock_version, bool $enabled ): TemplateRecord {
		$current = $this->must_find( $public_id );

		if ( $current->lock_version !== $expected_lock_version ) {
			throw new StaleRecordException( 'Template lock version is stale.' );
		}

		if ( $current->enabled === $enabled ) {
			return $current;
		}

		$updated = $this->database->update_rows(
			$this->templates_table(),
			array(
				'enabled'      => $enabled ? 1 : 0,
				'lock_version' => $current->lock_version + 1,
				'updated_at'   => StorageValidator::now(),
			),
			array(
				'public_id'    => $current->public_id,
				'lock_version' => $expected_lock_version,
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StaleRecordException( 'Template lock version is stale.' );
		}

		return $this->must_find( $public_id );
	}

	/**
	 * Soft-delete a template from selection.
	 *
	 * @param string $public_id Public ID.
	 * @param int    $expected_lock_version Expected lock version.
	 * @return TemplateRecord
	 */
	public function delete_from_selection( string $public_id, int $expected_lock_version ): TemplateRecord {
		return $this->set_enabled( $public_id, $expected_lock_version, false );
	}

	/**
	 * Find one template.
	 *
	 * @param string $public_id Public ID.
	 * @return TemplateRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?TemplateRecord {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->templates_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return all templates ordered for administration.
	 *
	 * @return array<int,TemplateRecord>
	 */
	public function find_all(): array {
		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->templates_table() . '` ORDER BY enabled DESC, name ASC, updated_at DESC, public_id ASC'
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Return selectable templates.
	 *
	 * @return array<int,TemplateRecord>
	 */
	public function find_enabled(): array {
		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->templates_table() . '` WHERE enabled = 1 ORDER BY name ASC, public_id ASC'
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Prepare storage fields.
	 *
	 * @param string              $name Name.
	 * @param array<string,mixed> $template_set Template set.
	 * @return array{name:string,template_set:array<string,mixed>,template_set_json:string}
	 */
	private function prepare( string $name, array $template_set ): array {
		StorageValidator::assert_label( 'Template name', $name );
		$template_set = RuleDocuments::template_set( $template_set );

		return array(
			'name'              => $name,
			'template_set'      => $template_set,
			'template_set_json' => RuleDocuments::encode_template_set( $template_set ),
		);
	}

	/**
	 * Load a template or fail.
	 *
	 * @param string $public_id Public ID.
	 * @return TemplateRecord
	 */
	private function must_find( string $public_id ): TemplateRecord {
		$record = $this->find_by_public_id( $public_id );

		if ( null === $record ) {
			throw new StorageException( 'Template does not exist.' );
		}

		return $record;
	}

	/**
	 * Hydrate a record.
	 *
	 * @param object $row Database row.
	 * @return TemplateRecord
	 */
	private function hydrate( object $row ): TemplateRecord {
		return new TemplateRecord(
			(int) $row->id,
			(string) $row->public_id,
			(string) $row->name,
			RuleDocuments::template_set( CanonicalJson::decode_object( (string) $row->template_set_json ) ),
			'1' === (string) $row->enabled,
			(int) $row->version,
			(int) $row->lock_version,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at )
		);
	}
}
