<?php
/**
 * Core plugin lifecycle entry point.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue;

use PressQueue\Admin\AdminScreens;
use PressQueue\Admin\AttentionNotice;
use PressQueue\Admin\PostListColumn;
use PressQueue\Batch\BatchQueueService;
use PressQueue\Planning\EventCollector;
use PressQueue\Privacy\PersonalDataEraser;
use PressQueue\Privacy\UninstallPolicy;
use PressQueue\Privacy\PersonalDataExporter;
use PressQueue\Privacy\PrivacySettings;
use PressQueue\Privacy\RetentionStateStore;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Database;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\SettingsRepository;
use Throwable;
use PressQueue\WpCli\CommandRegistrar;

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
		self::guard_runtime_state();

		do_action( 'pressqueue_bootstrap_loaded' );
	}

	/**
	 * Run activation extension points.
	 *
	 * @param bool $network_wide Whether activation was requested network-wide.
	 */
	public static function activate( bool $network_wide ): void {
		self::register_hooks();

		RuntimeDisabledLatch::ensure_default_closed();
		RuntimeDisabledLatch::close();
		do_action( 'pressqueue_before_activation', $network_wide );
		do_action( 'pressqueue_schema_upgrade', $network_wide );
		do_action( 'pressqueue_capability_registration', $network_wide );
		SettingsRepository::ensure_defaults_for_current_site();
		if ( function_exists( 'update_option' ) ) {
			update_option( SettingsRepository::ACTIVATED_AT_OPTION, (string) time(), true );
		}
		PrivacySettings::ensure_defaults_for_current_site();
		RetentionStateStore::ensure_defaults_for_current_site();
		self::assert_runtime_state_valid();
		RuntimeDisabledLatch::open();
		do_action( 'pressqueue_after_activation', $network_wide );
	}

	/**
	 * Run deactivation extension points.
	 *
	 * @param bool $network_wide Whether deactivation was requested network-wide.
	 */
	public static function deactivate( bool $network_wide ): void {
		RuntimeDisabledLatch::close();
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
		PostListColumn::register_hooks();
		AttentionNotice::register_hooks();
		PersonalDataExporter::register_hooks();
		PersonalDataEraser::register_hooks();
		add_filter( 'wpmu_drop_tables', array( UninstallPolicy::class, 'site_deletion_tables' ), 10, 2 );
		Capabilities::register_hooks();
		ActionSchedulerQueue::register_hooks();
		CommandRegistrar::register();

		if ( Database::has_global_database() ) {
			add_action( 'pressqueue_schema_upgrade', array( SchemaMigrator::class, 'migrate_current_site' ), 10, 1 );
			add_action( 'pressqueue_capability_registration', array( Capabilities::class, 'grant_to_administrators' ), 10, 1 );
			add_action( 'init', array( self::class, 'maybe_upgrade' ), 1 );
			add_action( 'admin_init', array( self::class, 'guard_runtime_state' ) );
			add_action( 'admin_init', array( self::class, 'repair_schema_if_needed' ) );
			add_action( 'admin_init', array( BatchQueueService::class, 'recover_queueing_batches' ) );
			add_action( 'pressqueue_after_activation', array( BatchQueueService::class, 'recover_queueing_batches' ), 10, 0 );
			add_action( 'admin_post_pressqueue_recover_migration_lock', array( SchemaMigrator::class, 'handle_lock_recovery' ) );
		}
	}

	/**
	 * Run additive storage and capability upgrades before publishing hooks.
	 */
	public static function maybe_upgrade(): void {
		if ( ! Database::has_global_database() ) {
			return;
		}

		try {
			RuntimeDisabledLatch::ensure_default_closed();
			$status = new SchemaStatus();

			if ( $status->installed_version() < \PressQueue\Storage\Schema::TARGET_VERSION && ! $status->has_active_migration_lock() && ! $status->has_migration_failed() ) {
				RuntimeDisabledLatch::close();
				do_action( 'pressqueue_schema_upgrade', false );
				$status = new SchemaStatus();
			}

			do_action( 'pressqueue_capability_registration', false );
			SettingsRepository::ensure_defaults_for_current_site();
			PrivacySettings::ensure_defaults_for_current_site();
			RetentionStateStore::ensure_defaults_for_current_site();

			if ( $status->installed_version() >= \PressQueue\Storage\Schema::TARGET_VERSION && ! $status->has_active_migration_lock() && ! $status->has_migration_failed() ) {
				try {
					self::assert_runtime_state_valid();
					RuntimeDisabledLatch::open();
					return;
				} catch ( Throwable $throwable ) {
					unset( $throwable );
				}
			}

			RuntimeDisabledLatch::close();
			self::guard_runtime_state();
		} catch ( Throwable $throwable ) {
			RuntimeDisabledLatch::close();
			if ( function_exists( 'pressqueue_register_admin_notice' ) ) {
				pressqueue_register_admin_notice( __( 'PressQueue could not finish its database upgrade. Delivery remains disabled. Check the migration status in PressQueue settings.', 'pressqueue' ) );
			}
		}
	}

	/**
	 * Repair physical schema drift when an administrator visits WordPress.
	 */
	public static function repair_schema_if_needed(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Database::has_global_database() ) {
			return;
		}
		try {
			$status = new SchemaStatus();
			if ( $status->has_active_migration_lock() || $status->has_migration_failed() || $status->is_current() ) {
				return;
			}
			RuntimeDisabledLatch::close();
			do_action( 'pressqueue_schema_upgrade', false );
			self::maybe_upgrade();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			RuntimeDisabledLatch::close();
		}
	}

	/**
	 * Close runtime delivery when privacy state is malformed.
	 */
	public static function guard_runtime_state(): void {
		$failure = get_option( 'pressqueue_last_planning_failure', null );
		if ( is_array( $failure ) && function_exists( 'pressqueue_register_admin_notice' ) ) {
			/* translators: %d: WordPress post ID. */
			pressqueue_register_admin_notice( sprintf( __( 'PressQueue could not finish planning post #%d. Open that post and preview it before sending a manual batch. Temporary failures are retried up to five times.', 'pressqueue' ), (int) ( $failure['post_id'] ?? 0 ) ) );
		}
		try {
			self::assert_runtime_state_valid();
		} catch ( Throwable $throwable ) {
			RuntimeDisabledLatch::close();

			if ( function_exists( 'pressqueue_register_admin_notice' ) ) {
				pressqueue_register_admin_notice(
					SafeOperatorError::message(
						$throwable,
						__( 'Stored PressQueue runtime or privacy state is invalid. Runtime delivery remains disabled until the stored option is repaired.', 'pressqueue' )
					)
				);
			}
		}
	}

	/**
	 * Validate runtime latch and privacy option shapes.
	 */
	private static function assert_runtime_state_valid(): void {
		if ( RuntimeDisabledLatch::is_malformed() ) {
			throw new \PressQueue\Storage\ValidationException( 'PressQueue runtime latch storage is invalid.' );
		}

		( new PrivacySettings() )->read();
		( new RetentionStateStore() )->read();
	}
}
