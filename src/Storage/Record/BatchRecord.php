<?php
/**
 * Batch storage record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

use DateTimeImmutable;

/**
 * Represents one manual batch preview or handoff.
 */
final class BatchRecord {
	public int $id;
	public string $public_id;
	public string $batch_type;
	public string $status;
	public int $created_by;
	public ?int $confirmed_by;
	/** @var array<string,mixed> */
	public array $filters;
	/** @var array<string,mixed> */
	public array $preview_summary;
	public string $preview_snapshot_hash;
	/** @var array<string,mixed> */
	public array $batch_limits;
	public DateTimeImmutable $preview_expires_at;
	public ?DateTimeImmutable $started_at;
	public ?DateTimeImmutable $confirmed_at;
	public ?DateTimeImmutable $completed_at;
	public int $lock_version;
	public DateTimeImmutable $created_at;
	public DateTimeImmutable $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int                    $id Internal ID.
	 * @param string                 $public_id Public ID.
	 * @param string                 $batch_type Batch type.
	 * @param string                 $status Batch status.
	 * @param int                    $created_by Creator user ID.
	 * @param int|null               $confirmed_by Confirmer user ID.
	 * @param array<string,mixed>    $filters Filter document.
	 * @param array<string,mixed>    $preview_summary Preview summary.
	 * @param string                 $preview_snapshot_hash Preview snapshot hash.
	 * @param array<string,mixed>    $batch_limits Batch limits.
	 * @param DateTimeImmutable      $preview_expires_at Preview expiry.
	 * @param DateTimeImmutable|null $started_at Start time.
	 * @param DateTimeImmutable|null $confirmed_at Confirmation time.
	 * @param DateTimeImmutable|null $completed_at Completion time.
	 * @param int                    $lock_version Optimistic lock version.
	 * @param DateTimeImmutable      $created_at Creation time.
	 * @param DateTimeImmutable      $updated_at Update time.
	 */
	public function __construct(
		int $id,
		string $public_id,
		string $batch_type,
		string $status,
		int $created_by,
		?int $confirmed_by,
		array $filters,
		array $preview_summary,
		string $preview_snapshot_hash,
		array $batch_limits,
		DateTimeImmutable $preview_expires_at,
		?DateTimeImmutable $started_at,
		?DateTimeImmutable $confirmed_at,
		?DateTimeImmutable $completed_at,
		int $lock_version,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at
	) {
		$this->id                    = $id;
		$this->public_id             = $public_id;
		$this->batch_type            = $batch_type;
		$this->status                = $status;
		$this->created_by            = $created_by;
		$this->confirmed_by          = $confirmed_by;
		$this->filters               = $filters;
		$this->preview_summary       = $preview_summary;
		$this->preview_snapshot_hash = $preview_snapshot_hash;
		$this->batch_limits          = $batch_limits;
		$this->preview_expires_at    = $preview_expires_at;
		$this->started_at            = $started_at;
		$this->confirmed_at          = $confirmed_at;
		$this->completed_at          = $completed_at;
		$this->lock_version          = $lock_version;
		$this->created_at            = $created_at;
		$this->updated_at            = $updated_at;
	}
}
