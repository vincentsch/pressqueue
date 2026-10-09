<?php
/**
 * Action Scheduler queue wrapper.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Scheduler;

use PressQueue\Batch\BatchQueueService;
use PressQueue\Delivery\DeliveryWorker;
use PressQueue\Delivery\QueueSweep;
use PressQueue\Privacy\RetentionService;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\StorageUnavailableException;

/**
 * Wraps Action Scheduler calls behind PressQueue checks.
 */
class ActionSchedulerQueue {
	public const GROUP                      = 'pressqueue';
	public const SWEEP_HOOK                 = 'pressqueue_queue_sweep';
	public const WORKER_HOOK                = 'pressqueue_deliver_delivery';
	public const BATCH_QUEUE_HOOK           = 'pressqueue_process_batch_queue';
	public const RETENTION_HOOK             = 'pressqueue_process_retention';
	public const SWEEP_INTERVAL_SECONDS     = 300;
	public const RETENTION_INTERVAL_SECONDS = 86400;

	/**
	 * Whether the recurring repair hook has been registered.
	 *
	 * @var bool
	 */
	private static bool $recurring_repair_hook_registered = false;

	/**
	 * Loader.
	 *
	 * @var ActionSchedulerLoader
	 */
	private ActionSchedulerLoader $loader;

	/**
	 * Constructor.
	 *
	 * @param ActionSchedulerLoader|null $loader Loader.
	 */
	public function __construct( ?ActionSchedulerLoader $loader = null ) {
		$this->loader = $loader ?? new ActionSchedulerLoader();
	}

	/**
	 * Register scheduler hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action(
			self::SWEEP_HOOK,
			static function (): void {
				( new QueueSweep() )->run();
			},
			10,
			0
		);
		add_action(
			self::WORKER_HOOK,
			static function ( string $delivery_public_id ): void {
				( new DeliveryWorker() )->run( $delivery_public_id );
			},
			10,
			1
		);
		add_action(
			self::BATCH_QUEUE_HOOK,
			static function ( string $batch_public_id, string $preview_snapshot_hash ): void {
				( new BatchQueueService() )->process( $batch_public_id, $preview_snapshot_hash );
			},
			10,
			2
		);
		add_action(
			self::RETENTION_HOOK,
			static function (): void {
				( new RetentionService() )->run();
			},
			10,
			0
		);
		add_action( 'action_scheduler_init', array( self::class, 'after_scheduler_init' ), 10, 0 );

		self::register_recurring_repair_hook();
	}

	/**
	 * Repair recurring sweep scheduling after Action Scheduler initializes.
	 */
	public static function after_scheduler_init(): void {
		self::register_recurring_repair_hook();
		if ( RuntimeDisabledLatch::is_closed() ) {
			return;
		}

		try {
			self::ensure_recurring_actions();
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
		BatchQueueService::recover_queueing_batches();
	}

	/**
	 * Register the Action Scheduler recurring action repair hook when supported.
	 */
	private static function register_recurring_repair_hook(): void {
		if (
			self::$recurring_repair_hook_registered
			|| ! function_exists( 'add_action' )
			|| ! function_exists( 'as_supports' )
			|| ! as_supports( 'ensure_recurring_actions_hook' )
		) {
			return;
		}

		self::$recurring_repair_hook_registered = true;
		add_action( 'action_scheduler_ensure_recurring_actions', array( self::class, 'ensure_recurring_actions' ), 10, 0 );
	}

	/**
	 * Ensure all recurring actions exist.
	 */
	public static function ensure_recurring_actions(): void {
		self::ensure_recurring_sweep();
		self::ensure_recurring_retention();
	}

	/**
	 * Ensure the recurring queue sweep exists.
	 */
	public static function ensure_recurring_sweep(): void {
		if ( RuntimeDisabledLatch::is_closed() ) {
			return;
		}

		if (
			! function_exists( 'as_next_scheduled_action' )
			|| ! function_exists( 'as_schedule_recurring_action' )
		) {
			return;
		}

		$next = as_next_scheduled_action( self::SWEEP_HOOK, array(), self::GROUP );
		if ( false !== $next ) {
			return;
		}

		as_schedule_recurring_action( time() + self::SWEEP_INTERVAL_SECONDS, self::SWEEP_INTERVAL_SECONDS, self::SWEEP_HOOK, array(), self::GROUP, true );
	}

	/**
	 * Ensure the recurring retention action exists.
	 */
	public static function ensure_recurring_retention(): void {
		if ( RuntimeDisabledLatch::is_closed() ) {
			return;
		}

		if (
			! function_exists( 'as_next_scheduled_action' )
			|| ! function_exists( 'as_schedule_recurring_action' )
		) {
			return;
		}

		$next = as_next_scheduled_action( self::RETENTION_HOOK, array(), self::GROUP );
		if ( false !== $next ) {
			return;
		}

		as_schedule_recurring_action( time() + self::RETENTION_INTERVAL_SECONDS, self::RETENTION_INTERVAL_SECONDS, self::RETENTION_HOOK, array(), self::GROUP, true );
	}

	/**
	 * Check whether scheduler APIs are ready for delivery work.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return $this->loader->is_ready();
	}

	/**
	 * Schedule one delivery worker action.
	 *
	 * @param string   $delivery_public_id Delivery public ID.
	 * @param int|null $timestamp Unix timestamp.
	 * @return int Action ID.
	 */
	public function schedule_delivery( string $delivery_public_id, ?int $timestamp = null ): int {
		PublicIds::assert( $delivery_public_id );
		RuntimeDisabledLatch::assert_open_for( 'delivery scheduling' );
		$this->assert_ready();

		$action_id = as_schedule_single_action( $timestamp ?? time(), self::WORKER_HOOK, array( $delivery_public_id ), self::GROUP, false );
		if ( ! is_numeric( $action_id ) || (int) $action_id <= 0 ) {
			throw new StorageUnavailableException( 'Action Scheduler did not create a delivery action.' );
		}

		return (int) $action_id;
	}

	/**
	 * Check whether a pending worker action exists.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return bool
	 */
	public function has_delivery_action( string $delivery_public_id ): bool {
		PublicIds::assert( $delivery_public_id );
		$this->assert_ready();

		return false !== as_next_scheduled_action( self::WORKER_HOOK, array( $delivery_public_id ), self::GROUP );
	}

	/**
	 * Schedule one batch queue handoff action.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Preview snapshot hash.
	 * @return int Action ID.
	 */
	public function schedule_batch_queue( string $batch_public_id, string $preview_snapshot_hash ): int {
		PublicIds::assert( $batch_public_id );
		StorageValidator::assert_hash( $preview_snapshot_hash );
		RuntimeDisabledLatch::assert_open_for( 'batch queue scheduling' );
		$this->assert_ready();

		$args      = array( $batch_public_id, $preview_snapshot_hash );
		$action_id = as_schedule_single_action( time(), self::BATCH_QUEUE_HOOK, $args, self::GROUP, false );
		if ( ! is_numeric( $action_id ) || (int) $action_id <= 0 ) {
			throw new StorageUnavailableException( 'Action Scheduler did not create a batch queue action.' );
		}

		return (int) $action_id;
	}

	/**
	 * Schedule the next batch queue handoff chunk.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Preview snapshot hash.
	 * @return int Action ID.
	 */
	public function schedule_batch_queue_continuation( string $batch_public_id, string $preview_snapshot_hash ): int {
		PublicIds::assert( $batch_public_id );
		StorageValidator::assert_hash( $preview_snapshot_hash );
		RuntimeDisabledLatch::assert_open_for( 'batch queue continuation' );
		$this->assert_ready();

		$args      = array( $batch_public_id, $preview_snapshot_hash );
		$action_id = as_schedule_single_action( time(), self::BATCH_QUEUE_HOOK, $args, self::GROUP, false );
		if ( ! is_numeric( $action_id ) || (int) $action_id <= 0 ) {
			throw new StorageUnavailableException( 'Action Scheduler did not create a batch queue action.' );
		}

		return (int) $action_id;
	}

	/**
	 * Check whether a pending batch queue action exists.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Preview snapshot hash.
	 * @return bool
	 */
	public function has_batch_queue_action( string $batch_public_id, string $preview_snapshot_hash ): bool {
		PublicIds::assert( $batch_public_id );
		StorageValidator::assert_hash( $preview_snapshot_hash );
		$this->assert_ready();

		return false !== as_next_scheduled_action( self::BATCH_QUEUE_HOOK, array( $batch_public_id, $preview_snapshot_hash ), self::GROUP );
	}

	/**
	 * Check whether the recurring queue sweep is scheduled.
	 *
	 * @return bool
	 */
	public function has_recurring_sweep(): bool {
		if ( ! $this->is_ready() ) {
			return false;
		}

		return false !== as_next_scheduled_action( self::SWEEP_HOOK, array(), self::GROUP );
	}

	/**
	 * Check whether the recurring retention action is scheduled.
	 *
	 * @return bool
	 */
	public function has_recurring_retention(): bool {
		if ( ! $this->is_ready() ) {
			return false;
		}

		return false !== as_next_scheduled_action( self::RETENTION_HOOK, array(), self::GROUP );
	}

	/**
	 * Unschedule matching worker action.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 */
	public function unschedule_delivery( string $delivery_public_id ): void {
		PublicIds::assert( $delivery_public_id );

		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::WORKER_HOOK, array( $delivery_public_id ), self::GROUP );
		}
	}

	/**
	 * Unschedule all PressQueue actions.
	 */
	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/**
	 * Check whether an Action Scheduler action is actively claimed.
	 *
	 * @param int|null $action_id Action ID.
	 * @return bool
	 */
	public function is_action_claimed( ?int $action_id ): bool {
		if ( null === $action_id || $action_id <= 0 || ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}

		try {
			$status = \ActionScheduler_Store::instance()->get_status( $action_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return false;
		}

		return \ActionScheduler_Store::STATUS_RUNNING === $status;
	}

	/**
	 * Fail closed when scheduler APIs are unavailable.
	 */
	private function assert_ready(): void {
		if ( ! $this->is_ready() ) {
			throw new StorageUnavailableException( 'Action Scheduler is not ready.' );
		}
	}
}
