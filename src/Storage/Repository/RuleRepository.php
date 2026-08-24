<?php
/**
 * Rule repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\RuleDocuments;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Persists rule definitions and optimistic edits.
 */
final class RuleRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                          => '%d',
		'public_id'                   => '%s',
		'name'                        => '%s',
		'effect'                      => '%s',
		'priority'                    => '%d',
		'triggers_json'               => '%s',
		'condition_tree_json'         => '%s',
		'inline_template_json'        => '%s',
		'destination_set_json'        => '%s',
		'custom_field_allowlist_json' => '%s',
		'schedule_policy'             => '%s',
		'enabled'                     => '%d',
		'last_previewed_version'      => '%d',
		'version'                     => '%d',
		'lock_version'                => '%d',
		'created_at'                  => '%s',
		'updated_at'                  => '%s',
	);

	/**
	 * Create a disabled-by-default rule.
	 *
	 * @param array<string,mixed> $input Rule values.
	 * @return RuleRecord
	 */
	public function create( array $input ): RuleRecord {
		$documents = $this->prepare_documents( $input );
		$enabled   = (bool) ( $input['enabled'] ?? false );
		$now       = StorageValidator::now();
		$table     = $this->rules_table();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'                   => $public_id,
						'name'                        => $documents['name'],
						'effect'                      => $documents['effect'],
						'priority'                    => $documents['priority'],
						'triggers_json'               => $documents['triggers_json'],
						'condition_tree_json'         => $documents['condition_tree_json'],
						'inline_template_json'        => $documents['inline_template_json'],
						'destination_set_json'        => $documents['destination_set_json'],
						'custom_field_allowlist_json' => $documents['custom_field_allowlist_json'],
						'schedule_policy'             => $documents['schedule_policy'],
						'enabled'                     => $enabled ? 1 : 0,
						'last_previewed_version'      => null,
						'version'                     => 1,
						'lock_version'                => 1,
						'created_at'                  => $now,
						'updated_at'                  => $now,
					),
					self::FORMATS
				);

				$record = $this->find_by_public_id( $public_id );

				if ( null === $record ) {
					throw new StorageException( 'Created rule could not be loaded.' );
				}

				return $record;
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue rule public ID.' );
	}

	/**
	 * Update a rule using optimistic concurrency.
	 *
	 * Behavior changes increment rule version. Enable and disable changes do not.
	 *
	 * @param string              $public_id Public ID.
	 * @param int                 $expected_lock_version Expected lock version.
	 * @param array<string,mixed> $changes Changed values.
	 * @return RuleRecord
	 */
	public function update( string $public_id, int $expected_lock_version, array $changes ): RuleRecord {
		$current = $this->find_by_public_id( $public_id );

		if ( null === $current ) {
			throw new StorageException( 'Rule does not exist.' );
		}

		if ( $current->lock_version !== $expected_lock_version ) {
			throw new StaleRecordException( 'Rule lock version is stale.' );
		}

		$merged = array(
			'name'                   => $changes['name'] ?? $current->name,
			'effect'                 => $changes['effect'] ?? $current->effect,
			'priority'               => $changes['priority'] ?? $current->priority,
			'triggers'               => $changes['triggers'] ?? $current->triggers['triggers'],
			'condition_tree'         => $changes['condition_tree'] ?? $current->condition_tree,
			'inline_template'        => $changes['inline_template'] ?? $current->inline_template,
			'destination_set'        => $changes['destination_set'] ?? $current->destination_set,
			'custom_field_allowlist' => $changes['custom_field_allowlist'] ?? $current->custom_field_allowlist,
			'schedule_policy'        => $changes['schedule_policy'] ?? $current->schedule_policy,
		);

		$documents = $this->prepare_documents( $merged );
		$enabled   = array_key_exists( 'enabled', $changes ) ? (bool) $changes['enabled'] : $current->enabled;

		$current_documents = $this->prepare_documents(
			array(
				'name'                   => $current->name,
				'effect'                 => $current->effect,
				'priority'               => $current->priority,
				'triggers'               => $current->triggers['triggers'],
				'condition_tree'         => $current->condition_tree,
				'inline_template'        => $current->inline_template,
				'destination_set'        => $current->destination_set,
				'custom_field_allowlist' => $current->custom_field_allowlist,
				'schedule_policy'        => $current->schedule_policy,
			)
		);

		$behavior_changed = $this->behavior_changed( $current_documents, $documents );
		$display_changed  = $current->name !== $documents['name'];
		$enabled_changed  = $current->enabled !== $enabled;

		if ( ! $behavior_changed && ! $display_changed && ! $enabled_changed ) {
			return $current;
		}

		$new_version      = $behavior_changed ? $current->version + 1 : $current->version;
		$new_lock_version = $current->lock_version + 1;

		$updated = $this->database->update_rows(
			$this->rules_table(),
			array(
				'name'                        => $documents['name'],
				'effect'                      => $documents['effect'],
				'priority'                    => $documents['priority'],
				'triggers_json'               => $documents['triggers_json'],
				'condition_tree_json'         => $documents['condition_tree_json'],
				'inline_template_json'        => $documents['inline_template_json'],
				'destination_set_json'        => $documents['destination_set_json'],
				'custom_field_allowlist_json' => $documents['custom_field_allowlist_json'],
				'schedule_policy'             => $documents['schedule_policy'],
				'enabled'                     => $enabled ? 1 : 0,
				'version'                     => $new_version,
				'lock_version'                => $new_lock_version,
				'updated_at'                  => StorageValidator::now(),
			),
			array(
				'public_id'    => $current->public_id,
				'lock_version' => $expected_lock_version,
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StaleRecordException( 'Rule lock version is stale.' );
		}

		$record = $this->find_by_public_id( $public_id );

		if ( null === $record ) {
			throw new StorageException( 'Updated rule could not be loaded.' );
		}

		return $record;
	}

	/**
	 * Find a rule by public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return RuleRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?RuleRecord {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->rules_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return enabled rules ordered by planning precedence.
	 *
	 * @return array<int,RuleRecord>
	 */
	public function find_enabled(): array {
		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->rules_table() . '` WHERE enabled = 1 ORDER BY priority ASC, public_id ASC'
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Prepare canonical rule fields for storage.
	 *
	 * @param array<string,mixed> $input Rule values.
	 * @return array<string,mixed>
	 */
	private function prepare_documents( array $input ): array {
		$name = (string) ( $input['name'] ?? '' );
		StorageValidator::assert_label( 'Rule name', $name );

		$effect = (string) ( $input['effect'] ?? '' );
		StorageEnums::assert_rule_effect( $effect );

		$priority = (int) ( $input['priority'] ?? 100 );

		if ( ! isset( $input['custom_field_allowlist'] ) || ! is_array( $input['custom_field_allowlist'] ) || ! array_is_list( $input['custom_field_allowlist'] ) ) {
			throw new ValidationException( 'Rule custom field allowlist must be a list.' );
		}

		$allowlist = RuleDocuments::custom_field_allowlist( $input['custom_field_allowlist'] );

		if ( ! isset( $input['triggers'] ) || ! is_array( $input['triggers'] ) || ! array_is_list( $input['triggers'] ) ) {
			throw new ValidationException( 'Rule triggers must be a list.' );
		}

		$triggers = RuleDocuments::triggers( $input['triggers'] );

		if ( ! isset( $input['condition_tree'] ) || ! is_array( $input['condition_tree'] ) ) {
			throw new ValidationException( 'Rule condition tree must be an object.' );
		}

		$condition_tree = RuleDocuments::condition_tree( $input['condition_tree'], $allowlist );

		if ( ! isset( $input['inline_template'] ) || ! is_array( $input['inline_template'] ) ) {
			throw new ValidationException( 'Rule inline template must be an object.' );
		}

		$inline_template = $input['inline_template'];
		if ( ! isset( $inline_template['custom_field_allowlist'] ) ) {
			$inline_template['custom_field_allowlist'] = $allowlist;
		}

		$inline_template = RuleDocuments::inline_template( $inline_template );

		if ( $inline_template['custom_field_allowlist'] !== $allowlist ) {
			throw new ValidationException( 'Inline template allowlist must match the rule allowlist.' );
		}

		if ( ! isset( $input['destination_set'] ) || ! is_array( $input['destination_set'] ) ) {
			throw new ValidationException( 'Rule destination set must be an object.' );
		}

		$destination_set = RuleDocuments::destination_set( $effect, $input['destination_set'] );

		$schedule_policy = (string) ( $input['schedule_policy'] ?? 'immediate' );
		StorageEnums::assert( 'Schedule policy', $schedule_policy, StorageEnums::SCHEDULE_POLICIES );

		$triggers_json = CanonicalJson::encode_object( $triggers );

		return array(
			'name'                        => $name,
			'effect'                      => $effect,
			'priority'                    => $priority,
			'triggers'                    => $triggers,
			'condition_tree'              => $condition_tree,
			'inline_template'             => $inline_template,
			'destination_set'             => $destination_set,
			'custom_field_allowlist'      => $allowlist,
			'schedule_policy'             => $schedule_policy,
			'triggers_json'               => $triggers_json,
			'condition_tree_json'         => RuleDocuments::encode_condition_tree( $condition_tree ),
			'inline_template_json'        => RuleDocuments::encode_inline_template( $inline_template ),
			'destination_set_json'        => RuleDocuments::encode_destination_set( $destination_set ),
			'custom_field_allowlist_json' => CanonicalJson::encode_list( $allowlist ),
		);
	}

	/**
	 * Compare behavior-bearing fields.
	 *
	 * @param array<string,mixed> $current Current values.
	 * @param array<string,mixed> $next New values.
	 * @return bool
	 */
	private function behavior_changed( array $current, array $next ): bool {
		foreach ( array( 'effect', 'priority', 'triggers_json', 'condition_tree_json', 'inline_template_json', 'destination_set_json', 'custom_field_allowlist_json', 'schedule_policy' ) as $field ) {
			if ( $current[ $field ] !== $next[ $field ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hydrate a rule record.
	 *
	 * @param object $row Database row.
	 * @return RuleRecord
	 */
	private function hydrate( object $row ): RuleRecord {
		return new RuleRecord(
			(int) $row->id,
			(string) $row->public_id,
			(string) $row->name,
			(string) $row->effect,
			(int) $row->priority,
			CanonicalJson::decode_object( (string) $row->triggers_json ),
			CanonicalJson::decode_object( (string) $row->condition_tree_json ),
			CanonicalJson::decode_object( (string) $row->inline_template_json ),
			CanonicalJson::decode_object( (string) $row->destination_set_json ),
			CanonicalJson::decode_list( (string) $row->custom_field_allowlist_json ),
			(string) $row->schedule_policy,
			'1' === (string) $row->enabled,
			null === $row->last_previewed_version ? null : (int) $row->last_previewed_version,
			(int) $row->version,
			(int) $row->lock_version,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at )
		);
	}
}
