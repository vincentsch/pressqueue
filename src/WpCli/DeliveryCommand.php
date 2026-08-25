<?php
/**
 * WP-CLI delivery command.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command exceptions are serialized by JSON envelopes.

use PressQueue\Delivery\OperatorActionAssessment;
use PressQueue\Delivery\OperatorActionAssessmentService;
use PressQueue\Delivery\OperatorActionService;
use PressQueue\Delivery\ReconciliationService;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\ValidationException;
use PressQueue\WpCli\Query\DeliveryQueryService;

/**
 * Handles delivery reads and local operator actions.
 */
final class DeliveryCommand extends AbstractCommand {
	/**
	 * List deliveries.
	 *
	 * @subcommand list
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function list_( array $args, array $assoc_args ): int {
		unset( $args );

		return $this->run_json(
			'deliveries.list',
			$assoc_args,
			function () use ( $assoc_args ): array {
				$this->require_capability( Capabilities::VIEW_DELIVERIES );
				list( $page, $per_page ) = $this->pagination_args( $assoc_args );

				return ( new DeliveryQueryService() )->list_deliveries( $assoc_args, $page, $per_page );
			}
		);
	}

	/**
	 * Show one delivery.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function show( array $args, array $assoc_args ): int {
		return $this->run_json(
			'deliveries.show',
			$assoc_args,
			function () use ( $args, $assoc_args ): array {
				$this->require_capability( Capabilities::VIEW_DELIVERIES );
				list( , $per_page ) = $this->pagination_args( $assoc_args );

				return ( new DeliveryQueryService() )->show( $this->positional( $args, 0, 'delivery_public_id' ), $per_page );
			}
		);
	}

	/**
	 * Retry a delivery.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function retry( array $args, array $assoc_args ): int {
		return $this->delivery_action( 'deliveries.retry', $args, $assoc_args, 'retry' );
	}

	/**
	 * Cancel a delivery.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function cancel( array $args, array $assoc_args ): int {
		return $this->delivery_action( 'deliveries.cancel', $args, $assoc_args, 'cancel' );
	}

	/**
	 * Resume a delivery.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function resume( array $args, array $assoc_args ): int {
		return $this->delivery_action( 'deliveries.resume', $args, $assoc_args, 'resume' );
	}

	/**
	 * Reconcile an ambiguous delivery.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function reconcile( array $args, array $assoc_args ): int {
		return $this->run_json(
			'deliveries.reconcile',
			$assoc_args,
			function () use ( $args, $assoc_args ): array {
				$this->require_capability( Capabilities::OPERATE_DELIVERIES );
				$delivery_public_id = $this->positional( $args, 0, 'delivery_public_id' );
				$outcome            = isset( $assoc_args['outcome'] ) ? (string) $assoc_args['outcome'] : '';
				$remote_id          = isset( $assoc_args['remote-id'] ) ? (string) $assoc_args['remote-id'] : null;
				$note               = $this->note_arg( isset( $assoc_args['note'] ) ? (string) $assoc_args['note'] : null );
				$assessment_service = new OperatorActionAssessmentService();
				$assessment         = $assessment_service->assess_reconciliation( $delivery_public_id, $outcome, $remote_id );

				if ( ! $this->apply_requested( $assoc_args ) ) {
					return $assessment->to_array();
				}

				if ( ! $assessment->would_mutate ) {
					throw $this->refused_from_reasons( $assessment->blocked_reasons );
				}

				try {
					$updated = ( new ReconciliationService() )->reconcile( $delivery_public_id, $outcome, $remote_id, $note, $this->current_user_id() );
				} catch ( ValidationException $exception ) {
					unset( $exception );
					throw new CommandException( 'policy_refused', 'The requested PressQueue action is not currently allowed.', ExitCodes::REFUSED );
				}

				return OperatorActionAssessment::applied(
					$updated->public_id,
					$assessment->old_state,
					$updated->state,
					( new DeliveryQueryService() )->newest_event_public_id( $updated->public_id )
				)->to_array();
			}
		);
	}

	/**
	 * Run a delivery action.
	 *
	 * @param string              $command Command key.
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @param string              $action Action.
	 * @return int
	 */
	private function delivery_action( string $command, array $args, array $assoc_args, string $action ): int {
		return $this->run_json(
			$command,
			$assoc_args,
			function () use ( $args, $assoc_args, $action ): array {
				$this->require_capability( Capabilities::OPERATE_DELIVERIES );
				$delivery_public_id = $this->positional( $args, 0, 'delivery_public_id' );
				$assessment_service = new OperatorActionAssessmentService();
				$assessment         = $this->assess_delivery_action( $assessment_service, $action, $delivery_public_id );

				if ( ! $this->apply_requested( $assoc_args ) ) {
					return $assessment->to_array();
				}

				if ( ! $assessment->would_mutate ) {
					throw $this->refused_from_reasons( $assessment->blocked_reasons );
				}

				$scheduler = new RecordingActionSchedulerQueue();
				$service   = new OperatorActionService( null, $scheduler );
				try {
					if ( 'cancel' === $action ) {
						$updated = $service->cancel( $delivery_public_id, $this->current_user_id() );
					} elseif ( 'resume' === $action ) {
						$updated = $service->resume( $delivery_public_id, $this->current_user_id() );
					} else {
						$updated = $service->retry( $delivery_public_id, $this->current_user_id() );
					}
				} catch ( ValidationException $exception ) {
					unset( $exception );
					throw new CommandException( 'policy_refused', 'The requested PressQueue action is not currently allowed.', ExitCodes::REFUSED );
				}

				$reloaded = ( new DeliveryRepository() )->find_by_public_id( $updated->public_id );

				return OperatorActionAssessment::applied(
					$updated->public_id,
					$assessment->old_state,
					$updated->state,
					( new DeliveryQueryService() )->newest_event_public_id( $updated->public_id ),
					null === $reloaded ? $updated->action_id : $reloaded->action_id
				)->to_array();
			}
		);
	}

	/**
	 * Assess one delivery action.
	 *
	 * @param OperatorActionAssessmentService $service Service.
	 * @param string                          $action Action.
	 * @param string                          $delivery_public_id Delivery public ID.
	 * @return OperatorActionAssessment
	 */
	private function assess_delivery_action( OperatorActionAssessmentService $service, string $action, string $delivery_public_id ): OperatorActionAssessment {
		if ( 'cancel' === $action ) {
			return $service->assess_cancel( $delivery_public_id );
		}

		if ( 'resume' === $action ) {
			return $service->assess_resume( $delivery_public_id );
		}

		return $service->assess_retry( $delivery_public_id );
	}
}
