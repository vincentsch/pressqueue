<?php
/**
 * PressQueue capability registration.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Security;

/**
 * Grants initial PressQueue administration capabilities.
 */
final class Capabilities {
	public const ACCESS_ADMIN       = 'access_pressqueue_admin';
	public const MANAGE_SETTINGS    = 'manage_pressqueue_settings';
	public const MANAGE_RULES       = 'manage_pressqueue_rules';
	public const VIEW_DECISIONS     = 'view_pressqueue_decisions';
	public const MANAGE_CONNECTIONS = 'manage_pressqueue_connections';
	public const VIEW_DELIVERIES    = 'view_pressqueue_deliveries';
	public const OPERATE_DELIVERIES = 'operate_pressqueue_deliveries';
	public const EDIT_POST_CONTROLS = 'edit_pressqueue_post_controls';
	public const OPERATE_BATCHES    = 'operate_pressqueue_batches';
	public const EXPORT_DATA        = 'export_pressqueue_data';
	public const VERSION_OPTION     = 'pressqueue_capability_schema_version';
	public const TARGET_VERSION     = 5;

	/**
	 * Return the initial capability names.
	 *
	 * @return array<int,string>
	 */
	public static function names(): array {
		return array(
			self::MANAGE_SETTINGS,
			self::MANAGE_RULES,
			self::VIEW_DECISIONS,
			self::MANAGE_CONNECTIONS,
			self::VIEW_DELIVERIES,
			self::OPERATE_DELIVERIES,
			self::EDIT_POST_CONTROLS,
			self::OPERATE_BATCHES,
			self::EXPORT_DATA,
		);
	}

	/**
	 * Register capability filters.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'user_has_cap', array( self::class, 'map_access_capability' ), 10, 3 );
		}
	}

	/**
	 * Activation and upgrade callback.
	 *
	 * @param bool $network_wide Whether activation was requested network-wide.
	 */
	public static function grant_to_administrators( bool $network_wide = false ): void {
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
			$sites = get_sites( array( 'fields' => 'ids' ) );

			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::grant_to_current_site_administrator();
				restore_current_blog();
			}

			return;
		}

		self::grant_to_current_site_administrator();
	}

	/**
	 * Grant capabilities to the current site's administrator role.
	 */
	private static function grant_to_current_site_administrator(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		$stored_version = function_exists( 'get_option' ) ? (int) get_option( self::VERSION_OPTION, 0 ) : 0;
		if ( $stored_version >= self::TARGET_VERSION ) {
			return;
		}

		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		$introduced = array(
			self::MANAGE_SETTINGS    => 1,
			self::MANAGE_RULES       => 1,
			self::VIEW_DECISIONS     => 1,
			self::MANAGE_CONNECTIONS => 1,
			self::VIEW_DELIVERIES    => 2,
			self::OPERATE_DELIVERIES => 2,
			self::EDIT_POST_CONTROLS => 3,
			self::OPERATE_BATCHES    => 4,
			self::EXPORT_DATA        => 5,
		);
		foreach ( $introduced as $capability => $version ) {
			if ( $version > $stored_version ) {
				$role->add_cap( $capability );
			}
		}

		self::set_capability_schema_version();
	}

	/**
	 * Grant a virtual menu capability when the user has any real PressQueue capability.
	 *
	 * @param array<string,bool> $allcaps All primitive caps.
	 * @param array<int,string>  $caps Requested caps.
	 * @param array<int,mixed>   $args Capability arguments.
	 * @return array<string,bool>
	 */
	public static function map_access_capability( array $allcaps, array $caps, array $args ): array {
		unset( $caps );

		if ( ( $args[0] ?? null ) !== self::ACCESS_ADMIN ) {
			return $allcaps;
		}

		foreach ( self::names() as $capability ) {
			if ( ! empty( $allcaps[ $capability ] ) ) {
				$allcaps[ self::ACCESS_ADMIN ] = true;
				return $allcaps;
			}
		}

		return $allcaps;
	}

	/**
	 * Store the capability schema version as non-autoloaded.
	 */
	private static function set_capability_schema_version(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'add_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::TARGET_VERSION ) {
			return;
		}

		if ( false === get_option( self::VERSION_OPTION, false ) ) {
			add_option( self::VERSION_OPTION, self::TARGET_VERSION, '', false );
			return;
		}

		update_option( self::VERSION_OPTION, self::TARGET_VERSION, false );
	}
}
