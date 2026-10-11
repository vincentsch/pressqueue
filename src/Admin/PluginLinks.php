<?php
/**
 * Plugin list links and the one-time welcome notice.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;

/**
 * Points new users at the setup checklist.
 */
final class PluginLinks {
	public const WELCOME_TRANSIENT = 'pressqueue_welcome_notice';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_filter' ) || ! defined( 'PRESSQUEUE_BASENAME' ) ) {
			return;
		}

		add_filter( 'plugin_action_links_' . PRESSQUEUE_BASENAME, array( self::class, 'action_links' ) );
		add_action( 'admin_notices', array( self::class, 'render_welcome' ) );
	}

	/**
	 * Remember to greet the administrator once after activation.
	 */
	public static function queue_welcome(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::WELCOME_TRANSIENT, '1', DAY_IN_SECONDS );
		}
	}

	/**
	 * Add "Get started" and "Settings" to the plugin row.
	 *
	 * @param array<int|string,string> $links Existing links.
	 * @return array<int|string,string>
	 */
	public static function action_links( array $links ): array {
		if ( ! current_user_can( Capabilities::ACCESS_ADMIN ) ) {
			return $links;
		}

		$extra = array( 'pressqueue-start' => '<a href="' . esc_url( Ui::url( Ui::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Get started', 'pressqueue' ) . '</a>' );
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			$extra['pressqueue-settings'] = '<a href="' . esc_url( Ui::url( Ui::PAGE_SETTINGS ) ) . '">' . esc_html__( 'Settings', 'pressqueue' ) . '</a>';
		}

		return array_merge( $extra, $links );
	}

	/**
	 * Show the welcome notice once, on the Plugins screen.
	 */
	public static function render_welcome(): void {
		if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( Capabilities::ACCESS_ADMIN ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || 'plugins' !== $screen->base || '1' !== get_transient( self::WELCOME_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::WELCOME_TRANSIENT );
		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'PressQueue is ready to set up.', 'pressqueue' ) . '</strong> ';
		echo esc_html__( 'Connect Buffer, pick your channels and create a rule. Nothing is shared until you go live.', 'pressqueue' );
		echo ' <a href="' . esc_url( Ui::url( Ui::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Get started', 'pressqueue' ) . '</a></p></div>';
	}
}
