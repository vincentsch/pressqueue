<?php
/**
 * Action Scheduler queue wrapper.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Scheduler;

use PressQueue\Delivery\DeliveryWorker;
use PressQueue\Delivery\QueueSweep;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\StorageUnavailableException;

/**
 * Wraps Action Scheduler calls behind PressQueue checks.
 */
class ActionSchedulerQueue {
	public const GROUP                  = 'pressqueue';
	public const SWEEP_HOOK             = 'pressqueue_queue_sweep';
	public const WORKER_HOOK            = 'pressqueue_deliver_delivery';
	public const SWEEP_INTERVAL_SECONDS = 300;

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
		add_action( 'action_scheduler_init', array( self::class, 'after_scheduler_init' ), 10, 0 );

		self::register_recurring_repair_hook();
	}

	/**
	 * Repair recurring sweep scheduling after Action Scheduler initializes.
	 */
	public static function after_scheduler_init(): void {
		self::register_recurring_repair_hook();
		self::ensure_recurring_sweep();
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
		add_action( 'action_scheduler_ensure_recurring_actions', array( self::class, 'ensure_recurring_sweep' ), 10, 0 );
	}

	/**
	 * Ensure the recurring queue sweep exists.
	 */
	public static function ensure_recurring_sweep(): void {
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
		$this->assert_ready();

		$action_id = as_schedule_single_action( $timestamp ?? time(), self::WORKER_HOOK, array( $delivery_public_id ), self::GROUP, true );
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
