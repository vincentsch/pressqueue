<?php
/**
 * Action Scheduler loader.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Scheduler;

/**
 * Loads and checks Action Scheduler.
 */
final class ActionSchedulerLoader {
	/**
	 * Load the bundled Action Scheduler registration file.
	 *
	 * @return bool Whether the file was available.
	 */
	public static function load(): bool {
		if ( ! defined( 'PRESSQUEUE_DIR' ) ) {
			return false;
		}

		foreach ( array( 'add_action', 'did_action', 'doing_action', 'do_action' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return false;
			}
		}

		$loader = PRESSQUEUE_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
		if ( ! file_exists( $loader ) ) {
			return false;
		}

		require_once $loader;

		return true;
	}

	/**
	 * Check whether Action Scheduler APIs can be used safely.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		if (
			! function_exists( 'did_action' )
			|| did_action( 'action_scheduler_init' ) < 1
			|| ! function_exists( 'as_schedule_single_action' )
			|| ! function_exists( 'as_schedule_recurring_action' )
			|| ! function_exists( 'as_next_scheduled_action' )
			|| ! function_exists( 'as_unschedule_action' )
			|| ! function_exists( 'as_unschedule_all_actions' )
		) {
			return false;
		}

		if ( class_exists( 'ActionScheduler_DataController' ) && ! \ActionScheduler_DataController::is_migration_complete() ) {
			return false;
		}

		return true;
	}
}
