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
		'source_template_public_id'   => '%s',
		'source_template_version'     => '%d',
		'template_set_json'           => '%s',
		'destination_set_json'        => '%s',
		'custom_field_allowlist_json' => '%s',
		'schedule_policy'             => '%s',
		'schedule_policy_json'        => '%s',
		'media_policy_json'           => '%s',
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
						'source_template_public_id'   => $documents['source_template_public_id'],
						'source_template_version'     => $documents['source_template_version'],
						'template_set_json'           => $documents['template_set_json'],
						'destination_set_json'        => $documents['destination_set_json'],
						'custom_field_allowlist_json' => $documents['custom_field_allowlist_json'],
						'schedule_policy'             => $documents['schedule_policy'],
						'schedule_policy_json'        => $documents['schedule_policy_json'],
						'media_policy_json'           => $documents['media_policy_json'],
						'enabled'                     => 0,
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

		$next_has_template_snapshot = $current->has_template_snapshot || array_key_exists( 'template_set', $changes );
		$merged                     = array(
			'name'                      => $changes['name'] ?? $current->name,
			'effect'                    => $changes['effect'] ?? $current->effect,
			'priority'                  => $changes['priority'] ?? $current->priority,
			'triggers'                  => $changes['triggers'] ?? $current->triggers['triggers'],
			'condition_tree'            => $changes['condition_tree'] ?? $current->condition_tree,
			'inline_template'           => $changes['inline_template'] ?? $current->inline_template,
			'source_template_public_id' => array_key_exists( 'source_template_public_id', $changes ) ? $changes['source_template_public_id'] : $current->source_template_public_id,
			'source_template_version'   => array_key_exists( 'source_template_version', $changes ) ? $changes['source_template_version'] : $current->source_template_version,
			'template_set'              => array_key_exists( 'template_set', $changes ) ? $changes['template_set'] : ( $current->has_template_snapshot ? $current->template_set : null ),
			'destination_set'           => $changes['destination_set'] ?? $current->destination_set,
			'custom_field_allowlist'    => $changes['custom_field_allowlist'] ?? $current->custom_field_allowlist,
			'schedule_policy'           => $changes['schedule_policy'] ?? $current->schedule_policy_document,
			'media_policy'              => $changes['media_policy'] ?? $current->media_policy,
		);

		$documents                  = $this->prepare_documents( $merged );
		$prepared_template_set_json = $documents['template_set_json'];
		if ( ! $next_has_template_snapshot ) {
			$documents['template_set_json'] = null;
		}

		$current_documents = $this->prepare_documents(
			array(
				'name'                      => $current->name,
				'effect'                    => $current->effect,
				'priority'                  => $current->priority,
				'triggers'                  => $current->triggers['triggers'],
				'condition_tree'            => $current->condition_tree,
				'inline_template'           => $current->inline_template,
				'source_template_public_id' => $current->source_template_public_id,
				'source_template_version'   => $current->source_template_version,
				'template_set'              => $current->template_set,
				'destination_set'           => $current->destination_set,
				'custom_field_allowlist'    => $current->custom_field_allowlist,
				'schedule_policy'           => $current->schedule_policy_document,
				'media_policy'              => $current->media_policy,
			)
		);
		if ( ! $current->has_template_snapshot ) {
			$current_documents['template_set_json'] = null;
		}

		$behavior_changed = $this->behavior_changed( $current_documents, $documents );
		if ( $behavior_changed && ! $next_has_template_snapshot ) {
			$next_has_template_snapshot             = true;
			$documents['template_set_json']         = $prepared_template_set_json;
			$documents['source_template_public_id'] = null;
			$documents['source_template_version']   = null;
		}

		$enabled         = $behavior_changed ? false : ( array_key_exists( 'enabled', $changes ) ? (bool) $changes['enabled'] : $current->enabled );
		$display_changed = $current->name !== $documents['name'];
		$enabled_changed = $current->enabled !== $enabled;

		if ( $enabled && ! $current->enabled && $current->last_previewed_version !== $current->version ) {
			throw new ValidationException( 'Rule must be previewed before it can be enabled.' );
		}

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
				'inline_template_json'        => RuleDocuments::encode_inline_template( $current->inline_template ),
				'source_template_public_id'   => $documents['source_template_public_id'],
				'source_template_version'     => $documents['source_template_version'],
				'template_set_json'           => $documents['template_set_json'],
				'destination_set_json'        => $documents['destination_set_json'],
				'custom_field_allowlist_json' => $documents['custom_field_allowlist_json'],
				'schedule_policy'             => $documents['schedule_policy'],
				'schedule_policy_json'        => $documents['schedule_policy_json'],
				'media_policy_json'           => $documents['media_policy_json'],
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
					return $rule->applies_to_trigger( $trigger );
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
	 * Explicitly copy a reusable template snapshot into a rule.
	 *
	 * @param string $rule_public_id Rule public ID.
	 * @param int    $expected_lock_version Expected rule lock version.
	 * @param string $template_public_id Template public ID.
	 * @return RuleRecord
	 */
	public function adopt_template_snapshot( string $rule_public_id, int $expected_lock_version, string $template_public_id ): RuleRecord {
		$template = ( new TemplateRepository( $this->database, $this->public_ids ) )->find_by_public_id( $template_public_id );

		if ( null === $template || ! $template->enabled ) {
			throw new ValidationException( 'Template is not selectable.' );
		}

		return $this->update(
			$rule_public_id,
			$expected_lock_version,
			array(
				'source_template_public_id' => $template->public_id,
				'source_template_version'   => $template->version,
				'template_set'              => $template->template_set,
			)
		);
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

		$template_set = null;
		if ( array_key_exists( 'template_set', $input ) && null !== $input['template_set'] ) {
			if ( ! is_array( $input['template_set'] ) || array_is_list( $input['template_set'] ) ) {
				throw new ValidationException( 'Rule template set must be an object.' );
			}

			$template_set = RuleDocuments::template_set( $input['template_set'], $allowlist );
		}

		if ( isset( $input['inline_template'] ) && is_array( $input['inline_template'] ) ) {
			$inline_template = $input['inline_template'];
		} else {
			if ( null === $template_set ) {
				throw new ValidationException( 'Rule template set must be an object.' );
			}

			$inline_template = array(
				'custom_field_allowlist' => $allowlist,
				'message'                => (string) $template_set['default_message'],
				'schema_version'         => 1,
			);
		}

		if ( ! isset( $inline_template['custom_field_allowlist'] ) ) {
			$inline_template['custom_field_allowlist'] = $allowlist;
		}

		$inline_template = RuleDocuments::inline_template( $inline_template );

		if ( $inline_template['custom_field_allowlist'] !== $allowlist ) {
			throw new ValidationException( 'Inline template allowlist must match the rule allowlist.' );
		}

		if ( null === $template_set ) {
			$template_set = RuleDocuments::template_set_from_inline( $inline_template, $allowlist );
		}

		$source_template_public_id = null;
		if ( array_key_exists( 'source_template_public_id', $input ) && null !== $input['source_template_public_id'] && '' !== $input['source_template_public_id'] ) {
			$source_template_public_id = (string) $input['source_template_public_id'];
			PublicIds::assert( $source_template_public_id );
		}

		$source_template_version = null;
		if ( array_key_exists( 'source_template_version', $input ) && null !== $input['source_template_version'] && '' !== $input['source_template_version'] ) {
			$source_template_version = (int) $input['source_template_version'];
			if ( $source_template_version <= 0 ) {
				throw new ValidationException( 'Source template version must be positive.' );
			}
		}

		if ( ( null === $source_template_public_id ) !== ( null === $source_template_version ) ) {
			throw new ValidationException( 'Template provenance requires both source ID and version.' );
		}

		if ( ! isset( $input['destination_set'] ) || ! is_array( $input['destination_set'] ) ) {
			throw new ValidationException( 'Rule destination set must be an object.' );
		}

		$destination_set = RuleDocuments::destination_set( $effect, $input['destination_set'] );

		$schedule_policy_document = RuleDocuments::schedule_policy( $input['schedule_policy'] ?? 'immediate' );
		$schedule_policy          = (string) $schedule_policy_document['mode'];
		$media_policy             = isset( $input['media_policy'] ) && is_array( $input['media_policy'] )
			? RuleDocuments::media_policy( $input['media_policy'] )
			: RuleDocuments::media_policy( null );

		$triggers_json = CanonicalJson::encode_object( $triggers );

		return array(
			'name'                        => $name,
			'effect'                      => $effect,
			'priority'                    => $priority,
			'triggers'                    => $triggers,
			'condition_tree'              => $condition_tree,
			'inline_template'             => $inline_template,
			'source_template_public_id'   => $source_template_public_id,
			'source_template_version'     => $source_template_version,
			'template_set'                => $template_set,
			'destination_set'             => $destination_set,
			'custom_field_allowlist'      => $allowlist,
			'schedule_policy'             => $schedule_policy,
			'schedule_policy_document'    => $schedule_policy_document,
			'media_policy'                => $media_policy,
			'triggers_json'               => $triggers_json,
			'condition_tree_json'         => RuleDocuments::encode_condition_tree( $condition_tree ),
			'inline_template_json'        => RuleDocuments::encode_inline_template( $inline_template ),
			'template_set_json'           => RuleDocuments::encode_template_set( $template_set ),
			'destination_set_json'        => RuleDocuments::encode_destination_set( $destination_set ),
			'custom_field_allowlist_json' => CanonicalJson::encode_list( $allowlist ),
			'schedule_policy_json'        => RuleDocuments::encode_schedule_policy( $schedule_policy_document ),
			'media_policy_json'           => RuleDocuments::encode_media_policy( $media_policy ),
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
		foreach ( array( 'effect', 'priority', 'triggers_json', 'condition_tree_json', 'inline_template_json', 'source_template_public_id', 'source_template_version', 'template_set_json', 'destination_set_json', 'custom_field_allowlist_json', 'schedule_policy', 'schedule_policy_json', 'media_policy_json' ) as $field ) {
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
		$allowlist                = CanonicalJson::decode_list( (string) $row->custom_field_allowlist_json );
		$inline_template          = CanonicalJson::decode_object( (string) $row->inline_template_json );
		$template_set_json        = property_exists( $row, 'template_set_json' ) ? $row->template_set_json : null;
		$has_template_snapshot    = null !== $template_set_json && '' !== (string) $template_set_json;
		$template_set             = ! $has_template_snapshot
			? RuleDocuments::template_set_from_inline( $inline_template, $allowlist )
			: RuleDocuments::template_set( CanonicalJson::decode_object( (string) $template_set_json ), $allowlist );
		$schedule_policy_document = property_exists( $row, 'schedule_policy_json' ) && null !== $row->schedule_policy_json && '' !== (string) $row->schedule_policy_json
			? RuleDocuments::schedule_policy( CanonicalJson::decode_object( (string) $row->schedule_policy_json ) )
			: RuleDocuments::schedule_policy( (string) $row->schedule_policy );
		$media_policy             = property_exists( $row, 'media_policy_json' ) && null !== $row->media_policy_json && '' !== (string) $row->media_policy_json
			? RuleDocuments::media_policy( CanonicalJson::decode_object( (string) $row->media_policy_json ) )
			: RuleDocuments::media_policy( null );

		return new RuleRecord(
			(int) $row->id,
			(string) $row->public_id,
			(string) $row->name,
			(string) $row->effect,
			(int) $row->priority,
			CanonicalJson::decode_object( (string) $row->triggers_json ),
			CanonicalJson::decode_object( (string) $row->condition_tree_json ),
			$inline_template,
			property_exists( $row, 'source_template_public_id' ) && null !== $row->source_template_public_id ? (string) $row->source_template_public_id : null,
			property_exists( $row, 'source_template_version' ) && null !== $row->source_template_version ? (int) $row->source_template_version : null,
			$template_set,
			CanonicalJson::decode_object( (string) $row->destination_set_json ),
			$allowlist,
			(string) $schedule_policy_document['mode'],
			$schedule_policy_document,
			$media_policy,
			'1' === (string) $row->enabled,
			null === $row->last_previewed_version ? null : (int) $row->last_previewed_version,
			(int) $row->version,
			(int) $row->lock_version,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at ),
			null,
			$has_template_snapshot
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
				'mode'           => 'ALL',
				'schema_version' => 1,
				'type'           => 'group',
			),
			array(
				'custom_field_allowlist' => $allowlist,
				'message'                => '{{ title }}',
				'schema_version'         => 1,
			),
			null,
			null,
			array(
				'default_message' => '{{ title }}',
				'schema_version'  => 1,
			),
			$destination_set,
			$allowlist,
			'immediate',
			array(
				'mode'           => 'immediate',
				'schema_version' => 1,
			),
			array(
				'mode'           => 'none',
				'schema_version' => 1,
			),
			'1' === (string) $row->enabled,
			null === $row->last_previewed_version ? null : (int) $row->last_previewed_version,
			(int) $row->version,
			(int) $row->lock_version,
			StorageValidator::parse_required_date_time( (string) $row->created_at ),
			StorageValidator::parse_required_date_time( (string) $row->updated_at ),
			$reason,
			property_exists( $row, 'template_set_json' ) && null !== $row->template_set_json && '' !== (string) $row->template_set_json
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
