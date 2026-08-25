<?php
/**
 * Batch cancellation workflow.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor wires service dependencies.

use PressQueue\Delivery\OperatorActionService;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Cancels local unqueued batch work.
 */
final class BatchCancellationService {
	private BatchRepository $batches;
	private BatchItemRepository $items;
	private OperatorActionService $operators;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database.
	 */
	public function __construct( ?Database $database = null ) {
		$database        = $database ?? Database::from_globals();
		$this->batches   = new BatchRepository( $database );
		$this->items     = new BatchItemRepository( $database );
		$this->operators = new OperatorActionService( $database );
	}

	/**
	 * Cancel a batch and eligible local delivery rows.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param int    $actor_user_id Actor user ID.
	 */
	public function cancel( string $batch_public_id, int $actor_user_id ): void {
		$this->require_capability();

		$batch = $this->batches->find_by_public_id( $batch_public_id );
		if ( null === $batch ) {
			throw new ValidationException( 'Batch does not exist.' );
		}

		if ( ! in_array( $batch->status, array( 'previewed', 'confirming', 'queueing' ), true ) ) {
			return;
		}

		$this->batches->transition(
			$batch->public_id,
			$batch->lock_version,
			array( 'previewed', 'confirming', 'queueing' ),
			'cancelled',
			array( 'completed_at' => StorageValidator::now() )
		);
		$this->items->cancel_unqueued( $batch->public_id );

		foreach ( $this->items->find_by_batch( $batch->public_id ) as $item ) {
			if ( null === $item->delivery_public_id ) {
				continue;
			}

			try {
				$this->operators->cancel( $item->delivery_public_id, $actor_user_id );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
			}
		}
	}

	/**
	 * Require batch operation capability.
	 */
	private function require_capability(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( Capabilities::OPERATE_BATCHES ) ) {
			throw new ValidationException( 'You are not allowed to operate PressQueue batches.' );
		}
	}
}
