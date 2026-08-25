<?php
/**
 * Decision repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Repository;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\PublicIdCollisionException;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Persists immutable rule decisions.
 */
final class DecisionRepository extends AbstractRepository {
	private const FORMATS = array(
		'id'                    => '%d',
		'public_id'             => '%s',
		'event_key'             => '%s',
		'decision_key'          => '%s',
		'intent_occurrence_key' => '%s',
		'scope_type'            => '%s',
		'scope_public_id'       => '%s',
		'post_id'               => '%d',
		'post_type'             => '%s',
		'post_status'           => '%s',
		'author_id'             => '%d',
		'revision_fingerprint'  => '%s',
		'trigger'               => '%s',
		'event_mode'            => '%s',
		'rule_id'               => '%d',
		'rule_public_id'        => '%s',
		'rule_version'          => '%d',
		'result'                => '%s',
		'reasons_json'          => '%s',
		'snapshot_json'         => '%s',
		'snapshot_hash'         => '%s',
		'created_at'            => '%s',
	);

	/**
	 * Create an immutable decision.
	 *
	 * @param array<string,mixed> $input Decision values.
	 * @return DecisionRecord
	 */
	public function create( array $input ): DecisionRecord {
		$prepared = $this->prepare_decision( $input );
		$table    = $this->decisions_table();

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$public_id = $this->generate_unique_public_id( $table );

			try {
				$this->database->insert_row(
					$table,
					array(
						'public_id'             => $public_id,
						'event_key'             => $prepared['event_key'],
						'decision_key'          => $prepared['decision_key'],
						'intent_occurrence_key' => $prepared['intent_occurrence_key'],
						'scope_type'            => $prepared['scope_type'],
						'scope_public_id'       => $prepared['scope_public_id'],
						'post_id'               => $prepared['post_id'],
						'post_type'             => $prepared['post_type'],
						'post_status'           => $prepared['post_status'],
						'author_id'             => $prepared['author_id'],
						'revision_fingerprint'  => $prepared['revision_fingerprint'],
						'trigger'               => $prepared['trigger'],
						'event_mode'            => $prepared['event_mode'],
						'rule_id'               => $prepared['rule_id'],
						'rule_public_id'        => $prepared['rule_public_id'],
						'rule_version'          => $prepared['rule_version'],
						'result'                => $prepared['result'],
						'reasons_json'          => $prepared['reasons_json'],
						'snapshot_json'         => $prepared['snapshot_json'],
						'snapshot_hash'         => $prepared['snapshot_hash'],
						'created_at'            => StorageValidator::now(),
					),
					self::FORMATS
				);

				$record = $this->find_by_public_id( $public_id );

				if ( null === $record ) {
					throw new StorageException( 'Created decision could not be loaded.' );
				}

				return $record;
			} catch ( StorageException $exception ) {
				if ( $this->is_duplicate_public_id_error( $exception ) ) {
					continue;
				}

				throw $exception;
			}
		}

		throw new PublicIdCollisionException( 'Could not persist a unique PressQueue decision public ID.' );
	}

	/**
	 * Find a decision by public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return DecisionRecord|null
	 */
	public function find_by_public_id( string $public_id ): ?DecisionRecord {
		PublicIds::assert( $public_id );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->decisions_table() . '` WHERE public_id = %s',
			array( $public_id )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Find a decision by decision key.
	 *
	 * @param string $decision_key Decision key.
	 * @return DecisionRecord|null
	 */
	public function find_by_decision_key( string $decision_key ): ?DecisionRecord {
		StorageValidator::assert_hash( $decision_key );

		$row = $this->database->get_row(
			'SELECT * FROM `' . $this->decisions_table() . '` WHERE decision_key = %s',
			array( $decision_key )
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return recent decisions ordered by creation.
	 *
	 * @param int $limit Limit.
	 * @param int $offset Offset.
	 * @return array<int,DecisionRecord>
	 */
	public function find_recent( int $limit = 50, int $offset = 0 ): array {
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$rows   = $this->database->get_results(
			'SELECT * FROM `' . $this->decisions_table() . '` ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
			array( $limit, $offset )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Count stored decisions.
	 *
	 * @return int
	 */
	public function count_all(): int {
		return (int) $this->database->get_var( 'SELECT COUNT(*) FROM `' . $this->decisions_table() . '`' );
	}

	/**
	 * Prepare decision fields.
	 *
	 * @param array<string,mixed> $input Decision values.
	 * @return array<string,mixed>
	 */
	private function prepare_decision( array $input ): array {
		$post_id   = (int) ( $input['post_id'] ?? 0 );
		$author_id = (int) ( $input['author_id'] ?? 0 );

		StorageValidator::assert_unsigned_id( 'Post ID', $post_id );
		StorageValidator::assert_unsigned_id( 'Author ID', $author_id, true );

		$post_type   = (string) ( $input['post_type'] ?? '' );
		$post_status = (string) ( $input['post_status'] ?? '' );
		StorageValidator::assert_wordpress_slug( 'Post type', $post_type );
		StorageValidator::assert_wordpress_slug( 'Post status', $post_status );

		$revision_fingerprint = (string) ( $input['revision_fingerprint'] ?? '' );
		StorageValidator::assert_hash( $revision_fingerprint );

		$trigger = (string) ( $input['trigger'] ?? '' );
		StorageEnums::assert_rule_trigger( $trigger );

		$event_mode = (string) ( $input['event_mode'] ?? '' );
		StorageEnums::assert_event_mode( $event_mode );

		$result = (string) ( $input['result'] ?? '' );
		StorageEnums::assert_decision_result( $result );

		$scope_type = (string) ( $input['scope_type'] ?? 'global' );
		StorageEnums::assert_scope_type( $scope_type );

		$scope_public_id = null;
		if ( 'channel' === $scope_type ) {
			$scope_public_id = (string) ( $input['scope_public_id'] ?? '' );
			PublicIds::assert( $scope_public_id );
		} elseif ( array_key_exists( 'scope_public_id', $input ) && null !== $input['scope_public_id'] ) {
			throw new ValidationException( 'Global decisions cannot have a scope public ID.' );
		}

		$rule_id        = $this->nullable_int( $input, 'rule_id' );
		$rule_public_id = $this->nullable_string( $input, 'rule_public_id' );
		$rule_version   = $this->nullable_int( $input, 'rule_version' );

		$this->assert_rule_scope( $rule_id, $rule_public_id, $rule_version );

		$event_fields = array(
			'author_id'            => $author_id,
			'event_mode'           => $event_mode,
			'post_id'              => $post_id,
			'post_status'          => $post_status,
			'post_type'            => $post_type,
			'revision_fingerprint' => $revision_fingerprint,
			'trigger'              => $trigger,
		);

		$intent_occurrence_key = null;
		if ( array_key_exists( 'intent_occurrence_key', $input ) && null !== $input['intent_occurrence_key'] && '' !== $input['intent_occurrence_key'] ) {
			$intent_occurrence_key = (string) $input['intent_occurrence_key'];
			StorageValidator::assert_hash( $intent_occurrence_key );
			$event_fields['intent_occurrence_key'] = $intent_occurrence_key;
		}

		$event_key = isset( $input['event_key'] ) ? (string) $input['event_key'] : HashKeys::event_key( $event_fields );
		StorageValidator::assert_hash( $event_key );

		if ( ! isset( $input['reasons'] ) || ! is_array( $input['reasons'] ) || ! array_is_list( $input['reasons'] ) ) {
			throw new ValidationException( 'Decision reasons must be a list.' );
		}

		$reasons = $this->canonical_reason_list( $input['reasons'] );
		StorageValidator::assert_nested_condition_results( $reasons );
		$reasons_json = CanonicalJson::encode_list( $reasons );
		CanonicalJson::assert_maximum_size( $reasons_json, 65536 );

		if ( ! isset( $input['snapshot'] ) || ! is_array( $input['snapshot'] ) || array_is_list( $input['snapshot'] ) ) {
			throw new ValidationException( 'Decision snapshot must be an object.' );
		}

		$snapshot = $input['snapshot'];
		StorageValidator::assert_nested_condition_results( $snapshot );
		$snapshot_json = CanonicalJson::encode_object( $snapshot );
		CanonicalJson::assert_maximum_size( $snapshot_json, 131072 );

		$snapshot_hash = HashKeys::snapshot_hash( $snapshot );

		return array(
			'event_key'             => $event_key,
			'decision_key'          => HashKeys::decision_key( $event_fields, $event_key, $scope_type, $scope_public_id, $rule_public_id, $rule_version, $result ),
			'intent_occurrence_key' => $intent_occurrence_key,
			'scope_type'            => $scope_type,
			'scope_public_id'       => $scope_public_id,
			'post_id'               => $post_id,
			'post_type'             => $post_type,
			'post_status'           => $post_status,
			'author_id'             => $author_id,
			'revision_fingerprint'  => $revision_fingerprint,
			'trigger'               => $trigger,
			'event_mode'            => $event_mode,
			'rule_id'               => $rule_id,
			'rule_public_id'        => $rule_public_id,
			'rule_version'          => $rule_version,
			'result'                => $result,
			'reasons'               => $reasons,
			'reasons_json'          => $reasons_json,
			'snapshot'              => $snapshot,
			'snapshot_json'         => $snapshot_json,
			'snapshot_hash'         => $snapshot_hash,
		);
	}

	/**
	 * Assert event-level or rule-scoped decision rule fields.
	 *
	 * @param int|null    $rule_id Rule internal ID.
	 * @param string|null $rule_public_id Rule public ID.
	 * @param int|null    $rule_version Rule version.
	 */
	private function assert_rule_scope( ?int $rule_id, ?string $rule_public_id, ?int $rule_version ): void {
		$values = array( $rule_id, $rule_public_id, $rule_version );
		$filled = array_filter(
			$values,
			static function ( $value ): bool {
				return null !== $value;
			}
		);

		if ( array() === $filled ) {
			return;
		}

		if ( 3 !== count( $filled ) ) {
			throw new ValidationException( 'Rule-scoped decisions need all rule identity fields.' );
		}

		StorageValidator::assert_unsigned_id( 'Rule ID', (int) $rule_id );
		PublicIds::assert( (string) $rule_public_id );
		StorageValidator::assert_unsigned_id( 'Rule version', (int) $rule_version );
	}

	/**
	 * Read a nullable integer field.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $key Field key.
	 * @return int|null
	 */
	private function nullable_int( array $input, string $key ): ?int {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}

		return (int) $input[ $key ];
	}

	/**
	 * Read a nullable string field.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $key Field key.
	 * @return string|null
	 */
	private function nullable_string( array $input, string $key ): ?string {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}

		return (string) $input[ $key ];
	}

	/**
	 * Sort reasons deterministically.
	 *
	 * @param array<int,mixed> $reasons Reasons.
	 * @return array<int,mixed>
	 */
	private function canonical_reason_list( array $reasons ): array {
		usort(
			$reasons,
			static function ( $left, $right ): int {
				return strcmp( CanonicalJson::encode( $left ), CanonicalJson::encode( $right ) );
			}
		);

		return $reasons;
	}

	/**
	 * Hydrate a decision record.
	 *
	 * @param object $row Database row.
	 * @return DecisionRecord
	 */
	private function hydrate( object $row ): DecisionRecord {
		return new DecisionRecord(
			(int) $row->id,
			(string) $row->public_id,
			(string) $row->event_key,
			(string) $row->decision_key,
			property_exists( $row, 'intent_occurrence_key' ) && null !== $row->intent_occurrence_key ? (string) $row->intent_occurrence_key : null,
			(string) $row->scope_type,
			null === $row->scope_public_id ? null : (string) $row->scope_public_id,
			(int) $row->post_id,
			(string) $row->post_type,
			(string) $row->post_status,
			(int) $row->author_id,
			(string) $row->revision_fingerprint,
			(string) $row->trigger,
			(string) $row->event_mode,
			null === $row->rule_id ? null : (int) $row->rule_id,
			null === $row->rule_public_id ? null : (string) $row->rule_public_id,
			null === $row->rule_version ? null : (int) $row->rule_version,
			(string) $row->result,
			CanonicalJson::decode_list( (string) $row->reasons_json ),
			CanonicalJson::decode_object( (string) $row->snapshot_json ),
			(string) $row->snapshot_hash,
			StorageValidator::parse_required_date_time( (string) $row->created_at )
		);
	}
}
