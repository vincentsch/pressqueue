<?php
/**
 * Batch action assessment.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

/**
 * Immutable zero-write batch action assessment.
 */
final class BatchActionAssessment {
	/**
	 * Constructor.
	 *
	 * @param bool                           $applied Whether the mutation has been applied.
	 * @param bool                           $would_mutate Whether the action would mutate storage.
	 * @param string|null                    $batch_public_id Batch public ID.
	 * @param string|null                    $preview_snapshot_hash Confirmable preview hash.
	 * @param array<string,int>              $affected_counts Affected count estimates.
	 * @param array<int,array<string,mixed>> $items Bounded items.
	 * @param array<int,int>                 $scheduled_action_ids Action Scheduler IDs created by an applied command.
	 * @param array<int,string>              $blocked_reasons Redacted refusal reasons.
	 * @param array<string,mixed>|null       $preview_summary Preview summary.
	 */
	public function __construct(
		public readonly bool $applied,
		public readonly bool $would_mutate,
		public readonly ?string $batch_public_id,
		public readonly ?string $preview_snapshot_hash,
		public readonly array $affected_counts,
		public readonly array $items,
		public readonly array $scheduled_action_ids,
		public readonly array $blocked_reasons = array(),
		public readonly ?array $preview_summary = null
	) {}

	/**
	 * Return the stable CLI document.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'affected_counts'       => $this->affected_counts,
			'applied'               => $this->applied,
			'batch_public_id'       => $this->batch_public_id,
			'blocked_reasons'       => $this->blocked_reasons,
			'items'                 => $this->items,
			'preview_summary'       => $this->preview_summary,
			'preview_snapshot_hash' => $this->preview_snapshot_hash,
			'scheduled_action_ids'  => $this->scheduled_action_ids,
			'would_mutate'          => $this->would_mutate,
		);
	}

	/**
	 * Return empty affected counts.
	 *
	 * @return array<string,int>
	 */
	public static function empty_counts(): array {
		return array(
			'batch_items'       => 0,
			'batches'           => 0,
			'deliveries'        => 0,
			'delivery_events'   => 0,
			'scheduled_actions' => 0,
		);
	}
}
