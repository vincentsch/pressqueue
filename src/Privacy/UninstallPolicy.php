<?php
/**
 * Uninstall cleanup policy.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Privacy;

use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Planning\EventNormalizer;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Schema;
use Throwable;

/**
 * Removes local plugin data only when the stored uninstall setting permits it.
 */
final class UninstallPolicy {
	/**
	 * Include plugin tables when WordPress permanently deletes a network site.
	 *
	 * @param array<int,string> $tables Existing site tables.
	 * @param int               $site_id Deleted site ID.
	 * @return array<int,string>
	 */
	public static function site_deletion_tables( array $tables, int $site_id ): array {
		$wpdb   = $GLOBALS['wpdb'];
		$prefix = $wpdb->get_blog_prefix( $site_id );
		foreach ( Schema::table_suffixes() as $suffix ) {
			$tables[] = $prefix . 'pressqueue_' . $suffix;
		}
		return array_values( array_unique( $tables ) );
	}

	/**
	 * Run uninstall cleanup for one site or every site in a multisite network.
	 */
	public static function uninstall(): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::unschedule_actions();
					self::remove_role_capabilities();
					self::delete_current_site_if_enabled();
				} finally {
					restore_current_blog();
				}
			}

			return;
		}

		self::unschedule_actions();
		self::remove_role_capabilities();
		self::delete_current_site_if_enabled();
	}

	/**
	 * Delete current-site data only when the setting is enabled.
	 */
	private static function delete_current_site_if_enabled(): void {
		try {
			if ( ! ( new PrivacySettings() )->delete_data_on_uninstall() ) {
				return;
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return;
		}

		self::delete_post_meta();
		self::delete_options_and_transients();
		self::drop_plugin_tables();
	}

	/**
	 * Unschedule PressQueue Action Scheduler actions.
	 */
	private static function unschedule_actions(): void {
		if ( class_exists( ActionSchedulerQueue::class ) ) {
			ActionSchedulerQueue::unschedule_all();
		}
	}

	/**
	 * Remove plugin capabilities from all roles, including delegated editor roles.
	 */
	private static function remove_role_capabilities(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		$role_names = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : array( 'administrator' );
		foreach ( $role_names as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role || ! method_exists( $role, 'remove_cap' ) ) {
				continue;
			}

			foreach ( Capabilities::names() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	/**
	 * Delete known plugin post metadata.
	 */
	private static function delete_post_meta(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! isset( $wpdb->postmeta ) ) {
			return;
		}

		$keys         = self::post_meta_keys();
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup must remove plugin-owned metadata.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only placeholder tokens are composed; all identifiers and values are bound.
				'DELETE FROM %i WHERE meta_key IN (' . $placeholders . ')',
				array_merge( array( $wpdb->postmeta ), $keys )
			)
		);
	}

	/**
	 * Delete plugin options and transients.
	 */
	private static function delete_options_and_transients(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! isset( $wpdb->options ) ) {
			return;
		}

		$like = $wpdb->esc_like( 'pressqueue_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup must remove plugin-owned options.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				array( $wpdb->options, $like, $wpdb->esc_like( '_transient_pressqueue_' ) . '%', $wpdb->esc_like( '_transient_timeout_pressqueue_' ) . '%' )
			)
		);
	}

	/**
	 * Drop PressQueue-owned tables.
	 */
	private static function drop_plugin_tables(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return;
		}

		foreach ( array_reverse( Schema::table_suffixes() ) as $suffix ) {
			if ( 1 !== preg_match( '/^[a-z_]+$/', $suffix ) ) {
				continue;
			}

			$table = $wpdb->prefix . 'pressqueue_' . $suffix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup must remove plugin-owned tables when enabled.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * Return known plugin post meta keys.
	 *
	 * @return array<int,string>
	 */
	private static function post_meta_keys(): array {
		return array(
			PostControlMetadata::META_KEY,
			PostControlMetadata::LEGACY_META_KEY,
			EventNormalizer::META_FIRST_PUBLISH_SEEN_AT,
			EventNormalizer::META_LAST_RUNTIME_FINGERPRINT,
			EventNormalizer::META_LAST_TRIGGER_FINGERPRINT,
			EventNormalizer::META_LAST_DELIVERY_REVISION_FINGERPRINT,
		);
	}
}
