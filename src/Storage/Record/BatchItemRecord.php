<?php
/**
 * Batch item storage record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

use DateTimeImmutable;

/**
 * Represents one post and destination candidate inside a manual batch.
 */
final class BatchItemRecord {
	public int $id;
	public string $public_id;
	public int $batch_id;
	public string $batch_public_id;
	public string $item_key;
	public string $intent_occurrence_key;
	public int $position;
	public int $post_id;
	public ?string $channel_public_id;
	public string $state;
	public string $semantic_preview_hash;
	public ?string $decision_public_id;
	public ?string $delivery_public_id;
	public ?string $duplicate_of_delivery_public_id;
	public ?string $inclusion_rule_public_id;
	public ?int $inclusion_rule_version;
	public ?DateTimeImmutable $scheduled_for_utc;
	/** @var array<string,mixed> */
	public array $preview_item;
	/** @var array<string,mixed> */
	public array $reason;
	public int $lock_version;
	public DateTimeImmutable $created_at;
	public DateTimeImmutable $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int                    $id Internal ID.
	 * @param string                 $public_id Public ID.
	 * @param int                    $batch_id Batch internal ID.
	 * @param string                 $batch_public_id Batch public ID.
	 * @param string                 $item_key Unique item key.
	 * @param string                 $intent_occurrence_key Occurrence key.
	 * @param int                    $position Frozen display and queue position.
	 * @param int                    $post_id WordPress post ID.
	 * @param string|null            $channel_public_id Destination channel public ID.
	 * @param string                 $state Item state.
	 * @param string                 $semantic_preview_hash Semantic preview hash.
	 * @param string|null            $decision_public_id Linked decision public ID.
	 * @param string|null            $delivery_public_id Linked delivery public ID.
	 * @param string|null            $duplicate_of_delivery_public_id Blocking delivery public ID.
	 * @param string|null            $inclusion_rule_public_id Inclusion rule public ID.
	 * @param int|null               $inclusion_rule_version Inclusion rule version.
	 * @param DateTimeImmutable|null $scheduled_for_utc Frozen schedule time.
	 * @param array<string,mixed>    $preview_item Frozen preview item.
	 * @param array<string,mixed>    $reason Reason document.
	 * @param int                    $lock_version Optimistic lock version.
	 * @param DateTimeImmutable      $created_at Creation time.
	 * @param DateTimeImmutable      $updated_at Update time.
	 */
	public function __construct(
		int $id,
		string $public_id,
		int $batch_id,
		string $batch_public_id,
		string $item_key,
		string $intent_occurrence_key,
		int $position,
		int $post_id,
		?string $channel_public_id,
		string $state,
		string $semantic_preview_hash,
		?string $decision_public_id,
		?string $delivery_public_id,
		?string $duplicate_of_delivery_public_id,
		?string $inclusion_rule_public_id,
		?int $inclusion_rule_version,
		?DateTimeImmutable $scheduled_for_utc,
		array $preview_item,
		array $reason,
		int $lock_version,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at
	) {
		$this->id                              = $id;
		$this->public_id                       = $public_id;
		$this->batch_id                        = $batch_id;
		$this->batch_public_id                 = $batch_public_id;
		$this->item_key                        = $item_key;
		$this->intent_occurrence_key           = $intent_occurrence_key;
		$this->position                        = $position;
		$this->post_id                         = $post_id;
		$this->channel_public_id               = $channel_public_id;
		$this->state                           = $state;
		$this->semantic_preview_hash           = $semantic_preview_hash;
		$this->decision_public_id              = $decision_public_id;
		$this->delivery_public_id              = $delivery_public_id;
		$this->duplicate_of_delivery_public_id = $duplicate_of_delivery_public_id;
		$this->inclusion_rule_public_id        = $inclusion_rule_public_id;
		$this->inclusion_rule_version          = $inclusion_rule_version;
		$this->scheduled_for_utc               = $scheduled_for_utc;
		$this->preview_item                    = $preview_item;
		$this->reason                          = $reason;
		$this->lock_version                    = $lock_version;
		$this->created_at                      = $created_at;
		$this->updated_at                      = $updated_at;
	}
}
