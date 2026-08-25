<?php
/**
 * Rule storage record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

use DateTimeImmutable;

/**
 * Represents one persisted rule version.
 */
final class RuleRecord {
	public int $id;
	public string $public_id;
	public string $name;
	public string $effect;
	public int $priority;
	/** @var array<string,mixed> */
	public array $triggers;
	/** @var array<string,mixed> */
	public array $condition_tree;
	/** @var array<string,mixed> */
	public array $inline_template;
	public ?string $source_template_public_id;
	public ?int $source_template_version;
	/** @var array<string,mixed>|null */
	public ?array $template_set;
	public bool $has_template_snapshot;
	/** @var array<string,mixed> */
	public array $destination_set;
	/** @var array<int,string> */
	public array $custom_field_allowlist;
	public string $schedule_policy;
	/** @var array<string,mixed> */
	public array $schedule_policy_document;
	/** @var array<string,mixed> */
	public array $media_policy;
	public bool $enabled;
	public ?int $last_previewed_version;
	public int $version;
	public int $lock_version;
	public DateTimeImmutable $created_at;
	public DateTimeImmutable $updated_at;
	public ?string $invalid_reason;

	/**
	 * Constructor.
	 *
	 * @param int                      $id Internal ID.
	 * @param string                   $public_id Public ID.
	 * @param string                   $name Display name.
	 * @param string                   $effect Rule effect.
	 * @param int                      $priority Rule priority.
	 * @param array<string,mixed>      $triggers Trigger document.
	 * @param array<string,mixed>      $condition_tree Condition tree document.
	 * @param array<string,mixed>      $inline_template Template document.
	 * @param string|null              $source_template_public_id Source template public ID.
	 * @param int|null                 $source_template_version Source template version.
	 * @param array<string,mixed>|null $template_set Effective template set.
	 * @param array<string,mixed>      $destination_set Destination document.
	 * @param array<int,string>        $custom_field_allowlist Custom field allowlist.
	 * @param string                   $schedule_policy Schedule policy.
	 * @param array<string,mixed>      $schedule_policy_document Schedule policy document.
	 * @param array<string,mixed>      $media_policy Media policy document.
	 * @param bool                     $enabled Whether the rule is enabled.
	 * @param int|null                 $last_previewed_version Last previewed version.
	 * @param int                      $version Rule version.
	 * @param int                      $lock_version Optimistic lock version.
	 * @param DateTimeImmutable        $created_at Creation timestamp.
	 * @param DateTimeImmutable        $updated_at Update timestamp.
	 * @param string|null              $invalid_reason Stored validation failure.
	 * @param bool|null                $has_template_snapshot Whether a template snapshot is stored.
	 */
	public function __construct(
		int $id,
		string $public_id,
		string $name,
		string $effect,
		int $priority,
		array $triggers,
		array $condition_tree,
		array $inline_template,
		?string $source_template_public_id,
		?int $source_template_version,
		?array $template_set,
		array $destination_set,
		array $custom_field_allowlist,
		string $schedule_policy,
		array $schedule_policy_document,
		array $media_policy,
		bool $enabled,
		?int $last_previewed_version,
		int $version,
		int $lock_version,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at,
		?string $invalid_reason = null,
		?bool $has_template_snapshot = null
	) {
		$this->id                        = $id;
		$this->public_id                 = $public_id;
		$this->name                      = $name;
		$this->effect                    = $effect;
		$this->priority                  = $priority;
		$this->triggers                  = $triggers;
		$this->condition_tree            = $condition_tree;
		$this->inline_template           = $inline_template;
		$this->source_template_public_id = $source_template_public_id;
		$this->source_template_version   = $source_template_version;
		$this->template_set              = $template_set;
		$this->has_template_snapshot     = $has_template_snapshot ?? ( null !== $template_set );
		$this->destination_set           = $destination_set;
		$this->custom_field_allowlist    = $custom_field_allowlist;
		$this->schedule_policy           = $schedule_policy;
		$this->schedule_policy_document  = $schedule_policy_document;
		$this->media_policy              = $media_policy;
		$this->enabled                   = $enabled;
		$this->last_previewed_version    = $last_previewed_version;
		$this->version                   = $version;
		$this->lock_version              = $lock_version;
		$this->created_at                = $created_at;
		$this->updated_at                = $updated_at;
		$this->invalid_reason            = $invalid_reason;
	}
}
