<?php
/**
 * Decision storage record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

use DateTimeImmutable;

/**
 * Represents one immutable decision.
 */
final class DecisionRecord {
	public int $id;
	public string $public_id;
	public string $event_key;
	public string $decision_key;
	public ?string $intent_occurrence_key;
	public string $scope_type;
	public ?string $scope_public_id;
	public int $post_id;
	public string $post_type;
	public string $post_status;
	public int $author_id;
	public string $revision_fingerprint;
	public string $trigger;
	public string $event_mode;
	public ?int $rule_id;
	public ?string $rule_public_id;
	public ?int $rule_version;
	public string $result;
	/** @var array<int,mixed> */
	public array $reasons;
	/** @var array<string,mixed> */
	public array $snapshot;
	public string $snapshot_hash;
	public DateTimeImmutable $created_at;

	/**
	 * Constructor.
	 *
	 * @param int                 $id Internal ID.
	 * @param string              $public_id Public ID.
	 * @param string              $event_key Event key.
	 * @param string              $decision_key Decision key.
	 * @param string|null         $intent_occurrence_key Manual batch occurrence key.
	 * @param string              $scope_type Scope type.
	 * @param string|null         $scope_public_id Scope public ID.
	 * @param int                 $post_id WordPress post ID.
	 * @param string              $post_type Post type.
	 * @param string              $post_status Post status.
	 * @param int                 $author_id Author ID.
	 * @param string              $revision_fingerprint Revision fingerprint.
	 * @param string              $trigger Rule trigger.
	 * @param string              $event_mode Event mode.
	 * @param int|null            $rule_id Rule internal ID.
	 * @param string|null         $rule_public_id Rule public ID.
	 * @param int|null            $rule_version Rule version.
	 * @param string              $result Decision result.
	 * @param array<int,mixed>    $reasons Reasons document.
	 * @param array<string,mixed> $snapshot Snapshot document.
	 * @param string              $snapshot_hash Snapshot hash.
	 * @param DateTimeImmutable   $created_at Creation timestamp.
	 */
	public function __construct(
		int $id,
		string $public_id,
		string $event_key,
		string $decision_key,
		?string $intent_occurrence_key,
		string $scope_type,
		?string $scope_public_id,
		int $post_id,
		string $post_type,
		string $post_status,
		int $author_id,
		string $revision_fingerprint,
		string $trigger,
		string $event_mode,
		?int $rule_id,
		?string $rule_public_id,
		?int $rule_version,
		string $result,
		array $reasons,
		array $snapshot,
		string $snapshot_hash,
		DateTimeImmutable $created_at
	) {
		$this->id                    = $id;
		$this->public_id             = $public_id;
		$this->event_key             = $event_key;
		$this->decision_key          = $decision_key;
		$this->intent_occurrence_key = $intent_occurrence_key;
		$this->scope_type            = $scope_type;
		$this->scope_public_id       = $scope_public_id;
		$this->post_id               = $post_id;
		$this->post_type             = $post_type;
		$this->post_status           = $post_status;
		$this->author_id             = $author_id;
		$this->revision_fingerprint  = $revision_fingerprint;
		$this->trigger               = $trigger;
		$this->event_mode            = $event_mode;
		$this->rule_id               = $rule_id;
		$this->rule_public_id        = $rule_public_id;
		$this->rule_version          = $rule_version;
		$this->result                = $result;
		$this->reasons               = $reasons;
		$this->snapshot              = $snapshot;
		$this->snapshot_hash         = $snapshot_hash;
		$this->created_at            = $created_at;
	}
}
