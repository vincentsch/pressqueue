<?php
/**
 * Action Scheduler recording wrapper for WP-CLI.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

use PressQueue\Scheduler\ActionSchedulerQueue;

/**
 * Records Action Scheduler IDs created by applied CLI actions.
 */
final class RecordingActionSchedulerQueue extends ActionSchedulerQueue {
	/**
	 * Created action IDs.
	 *
	 * @var array<int,int>
	 */
	private array $scheduled_action_ids = array();

	/**
	 * Schedule one delivery action and record its ID.
	 *
	 * @param string   $delivery_public_id Delivery public ID.
	 * @param int|null $timestamp Timestamp.
	 * @return int
	 */
	public function schedule_delivery( string $delivery_public_id, ?int $timestamp = null ): int {
		$action_id                    = parent::schedule_delivery( $delivery_public_id, $timestamp );
		$this->scheduled_action_ids[] = $action_id;

		return $action_id;
	}

	/**
	 * Schedule one batch queue action and record its ID.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Snapshot hash.
	 * @return int
	 */
	public function schedule_batch_queue( string $batch_public_id, string $preview_snapshot_hash ): int {
		$action_id                    = parent::schedule_batch_queue( $batch_public_id, $preview_snapshot_hash );
		$this->scheduled_action_ids[] = $action_id;

		return $action_id;
	}

	/**
	 * Schedule one continuation action and record its ID.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Snapshot hash.
	 * @return int
	 */
	public function schedule_batch_queue_continuation( string $batch_public_id, string $preview_snapshot_hash ): int {
		$action_id                    = parent::schedule_batch_queue_continuation( $batch_public_id, $preview_snapshot_hash );
		$this->scheduled_action_ids[] = $action_id;

		return $action_id;
	}

	/**
	 * Return recorded action IDs.
	 *
	 * @return array<int,int>
	 */
	public function scheduled_action_ids(): array {
		return $this->scheduled_action_ids;
	}
}
