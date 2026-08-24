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
				'last_previewed_version'      => $behavior_changed ? null : $current->last_previewed_version,
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

		return $row ? $this->safe_hydrate( $row ) : null;
	}

	/**
	 * Return all rules ordered for administration.
	 *
	 * @return array<int,RuleRecord>
	 */
	public function find_all(): array {
		$rows = $this->database->get_results(
			'SELECT * FROM `' . $this->rules_table() . '` ORDER BY priority ASC, public_id ASC'
		);

		return array_map( array( $this, 'safe_hydrate' ), $rows );
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

		return array_map( array( $this, 'safe_hydrate' ), $rows );
	}

	/**
	 * Return enabled rules that include a trigger.
	 *
	 * @param string $trigger Trigger.
	 * @return array<int,RuleRecord>
	 */
	public function find_enabled_for_trigger( string $trigger ): array {
		StorageEnums::assert_rule_trigger( $trigger );

		return array_values(
			array_filter(
				$this->find_enabled(),
				static function ( RuleRecord $rule ) use ( $trigger ): bool {
					return in_array( $trigger, $rule->triggers['triggers'] ?? array(), true );
				}
			)
		);
	}

	/**
	 * Mark one exact rule version as previewed.
	 *
	 * @param string $public_id Rule public ID.
	 * @param int    $expected_lock_version Expected lock version.
	 * @param int    $expected_version Expected rule version.
	 * @return RuleRecord
	 */
	public function mark_version_previewed( string $public_id, int $expected_lock_version, int $expected_version ): RuleRecord {
		$current = $this->find_by_public_id( $public_id );

		if ( null === $current ) {
			throw new StorageException( 'Rule does not exist.' );
		}

		if ( $current->lock_version !== $expected_lock_version || $current->version !== $expected_version ) {
			throw new StaleRecordException( 'Rule version is stale.' );
		}

		$updated = $this->database->update_rows(
			$this->rules_table(),
			array(
				'last_previewed_version' => $expected_version,
				'updated_at'             => StorageValidator::now(),
			),
			array(
				'public_id'    => $current->public_id,
				'lock_version' => $expected_lock_version,
				'version'      => $expected_version,
			),
			self::FORMATS
		);

		if ( 0 === $updated ) {
			throw new StaleRecordException( 'Rule version is stale.' );
		}

		$record = $this->find_by_public_id( $public_id );

		if ( null === $record ) {
			throw new StorageException( 'Previewed rule could not be loaded.' );
		}

		return $record;
	}

	/**
	 * Enable a rule only after the current version has been previewed.
	 *
	 * @param string $public_id Rule public ID.
	 * @param int    $expected_lock_version Expected lock version.
	 * @return RuleRecord
	 */
	public function enable_after_preview( string $public_id, int $expected_lock_version ): RuleRecord {
		$current = $this->find_by_public_id( $public_id );

		if ( null === $current ) {
			throw new StorageException( 'Rule does not exist.' );
		}

		if ( $current->lock_version !== $expected_lock_version ) {
			throw new StaleRecordException( 'Rule lock version is stale.' );
		}

		if ( $current->last_previewed_version !== $current->version ) {
			throw new ValidationException( 'Rule must be previewed before it can be enabled.' );
		}

		if ( $current->enabled ) {
			return $current;
		}

		$updated = $this->database->update_rows(
			$this->rules_table(),
			array(
				'enabled'      => 1,
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
			throw new StaleRecordException( 'Rule lock version is stale.' );
		}

		$record = $this->find_by_public_id( $public_id );

		if ( null === $record ) {
			throw new StorageException( 'Enabled rule could not be loaded.' );
		}

		return $record;
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

	/**
	 * Hydrate a rule while preserving malformed stored rows for fail-closed planning.
	 *
	 * @param object $row Database row.
	 * @return RuleRecord
	 */
	private function safe_hydrate( object $row ): RuleRecord {
		try {
			return $this->hydrate( $row );
		} catch ( ValidationException $exception ) {
			return $this->hydrate_malformed( $row, $exception->getMessage() );
		}
	}

	/**
	 * Hydrate a malformed stored rule with valid placeholders and an error marker.
	 *
	 * @param object $row Database row.
	 * @param string $reason Validation failure.
	 * @return RuleRecord
	 */
	private function hydrate_malformed( object $row, string $reason ): RuleRecord {
		$triggers  = $this->safe_triggers( (string) $row->triggers_json );
		$effect    = (string) $row->effect;
		$allowlist = $this->safe_custom_field_allowlist( (string) $row->custom_field_allowlist_json );

		if ( ! in_array( $effect, StorageEnums::RULE_EFFECTS, true ) ) {
			$effect = 'include';
		}

		$destination_set = $this->safe_destination_set( $effect, (string) $row->destination_set_json );

		return new RuleRecord(
			(int) $row->id,
			(string) $row->public_id,
			'' === (string) $row->name ? 'Malformed rule' : (string) $row->name,
			$effect,
			(int) $row->priority,
			$triggers,
			array(
				'children'       => array(
					array(
						'condition' => 'post_type',
						'operator'  => 'equals',
						'type'      => 'condition',
						'value'     => 'post',
					),
				),
				'operator'       => 'ALL',
				'schema_version' => 1,
				'type'           => 'group',
			),
			array(
				'custom_field_allowlist' => $allowlist,
				'message'                => '{{ title }}',
				'schema_version'         => 1,
			),
			$destination_set,
			$allowlist,
			'immediate',
			'1' === (string) $row->enabled,
			null === $row->last_previewed_version ? null : (int) $row->last_previewed_version,
			(int) $row->version,
			(int) $row->lock_version,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at ),
			$reason
		);
	}

	/**
	 * Decode triggers or match every runtime trigger when the trigger document is malformed.
	 *
	 * @param string $json Stored triggers JSON.
	 * @return array<string,mixed>
	 */
	private function safe_triggers( string $json ): array {
		try {
			$triggers = CanonicalJson::decode_object( $json );
			RuleDocuments::triggers( $triggers['triggers'] ?? array() );

			return $triggers;
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return array(
				'schema_version' => 1,
				'triggers'       => StorageEnums::RULE_TRIGGERS,
			);
		}
	}

	/**
	 * Decode a custom field allowlist or fall back to no custom fields.
	 *
	 * @param string $json Stored allowlist JSON.
	 * @return array<int,string>
	 */
	private function safe_custom_field_allowlist( string $json ): array {
		try {
			return RuleDocuments::custom_field_allowlist( CanonicalJson::decode_list( $json ) );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return array();
		}
	}

	/**
	 * Decode a destination set or fall back to the closed shape for the rule effect.
	 *
	 * @param string $effect Rule effect.
	 * @param string $json Stored destination set JSON.
	 * @return array<string,mixed>
	 */
	private function safe_destination_set( string $effect, string $json ): array {
		try {
			return RuleDocuments::destination_set( $effect, CanonicalJson::decode_object( $json ) );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return 'include' === $effect ? array(
				'destinations'   => array(),
				'schema_version' => 1,
			) : array(
				'schema_version' => 1,
				'scope'          => 'all',
			);
		}
	}
}
