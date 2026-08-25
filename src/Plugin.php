<?php
/**
 * Core plugin lifecycle entry point.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue;

use PressQueue\Admin\AdminScreens;
use PressQueue\Batch\BatchQueueService;
use PressQueue\Planning\EventCollector;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\SettingsRepository;

/**
 * Coordinates bootstrap and lifecycle extension points.
 */
final class Plugin {
	/**
	 * Whether internal hooks have been registered.
	 *
	 * @var bool
	 */
	private static bool $hooks_registered = false;

	/**
	 * Register runtime hooks.
	 */
	public static function bootstrap(): void {
		self::register_hooks();

		do_action( 'pressqueue_bootstrap_loaded' );
	}

	/**
	 * Run activation extension points.
	 *
	 * @param bool $network_wide Whether activation was requested network-wide.
	 */
	public static function activate( bool $network_wide ): void {
		self::register_hooks();

		do_action( 'pressqueue_before_activation', $network_wide );
		do_action( 'pressqueue_schema_upgrade', $network_wide );
		do_action( 'pressqueue_capability_registration', $network_wide );
		SettingsRepository::ensure_defaults_for_current_site();
		do_action( 'pressqueue_after_activation', $network_wide );
	}

	/**
	 * Run deactivation extension points.
	 *
	 * @param bool $network_wide Whether deactivation was requested network-wide.
	 */
	public static function deactivate( bool $network_wide ): void {
		do_action( 'pressqueue_before_deactivation', $network_wide );
		ActionSchedulerQueue::unschedule_all();
		do_action( 'pressqueue_after_deactivation', $network_wide );
	}

	/**
	 * Register internal lifecycle handlers.
	 */
	private static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}

		self::$hooks_registered = true;

		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		EventCollector::register_hooks();
		AdminScreens::register_hooks();
		Capabilities::register_hooks();
		ActionSchedulerQueue::register_hooks();

		if ( Database::has_global_database() ) {
			add_action( 'pressqueue_schema_upgrade', array( SchemaMigrator::class, 'migrate_current_site' ), 10, 1 );
			add_action( 'pressqueue_capability_registration', array( Capabilities::class, 'grant_to_administrators' ), 10, 1 );
			add_action( 'admin_init', array( self::class, 'maybe_upgrade' ) );
			add_action( 'admin_init', array( BatchQueueService::class, 'recover_queueing_batches' ) );
			add_action( 'pressqueue_after_activation', array( BatchQueueService::class, 'recover_queueing_batches' ), 10, 0 );
			add_action( 'admin_post_pressqueue_recover_migration_lock', array( SchemaMigrator::class, 'handle_lock_recovery' ) );
		}
	}

	/**
	 * Run additive storage and capability upgrades from the administrator area.
	 */
	public static function maybe_upgrade(): void {
		if ( ! Database::has_global_database() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = new SchemaStatus();

		if ( ! $status->is_current() && ! $status->has_active_migration_lock() && ! $status->has_migration_failed() ) {
			do_action( 'pressqueue_schema_upgrade', false );
		}

		do_action( 'pressqueue_capability_registration', false );
		SettingsRepository::ensure_defaults_for_current_site();
	}
}
