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
	public const MANAGE_SETTINGS = 'manage_pressqueue_settings';
	public const MANAGE_RULES    = 'manage_pressqueue_rules';
	public const VIEW_DECISIONS  = 'view_pressqueue_decisions';

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
		);
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

		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::names() as $capability ) {
			$role->add_cap( $capability );
		}
	}
}
