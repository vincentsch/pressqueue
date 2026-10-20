<?php
/**
 * Notices when PressQueue itself has a problem.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;

/**
 * Explains problems found while PressQueue loads, on the Dashboard and the PressQueue screens.
 *
 * Problems that stop sharing stay until they are fixed and also show on the Plugins screen.
 * A post that could not be checked is a warning that can be dismissed.
 */
final class RuntimeNotice {
	public const UPGRADE_FAILED = 'upgrade_failed';
	public const STATE_INVALID  = 'state_invalid';

	/**
	 * Option where planning stores the last post it could not check.
	 */
	public const PLANNING_FAILURE_OPTION = 'pressqueue_last_planning_failure';

	/**
	 * Automatic planning retries, as scheduled by the event collector.
	 */
	private const PLANNING_RETRIES = 5;

	/**
	 * Problems found during this request.
	 *
	 * @var array<string,bool>
	 */
	private static array $problems = array();

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_notices', array( self::class, 'render' ) );
		}
	}

	/**
	 * Remember a problem that stops sharing.
	 *
	 * @param string $problem One of the class constants.
	 */
	public static function add( string $problem ): void {
		if ( in_array( $problem, array( self::UPGRADE_FAILED, self::STATE_INVALID ), true ) ) {
			self::$problems[ $problem ] = true;
		}
	}

	/**
	 * Forget the problems found so far.
	 */
	public static function reset(): void {
		self::$problems = array();
	}

	/**
	 * Render the notices that belong on the current screen.
	 */
	public static function render(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		self::render_for( null === $screen ? '' : (string) $screen->base, Ui::is_pressqueue_screen() );
	}

	/**
	 * Render the notices for a screen.
	 *
	 * @param string $screen_base WordPress screen base, such as dashboard or plugins.
	 * @param bool   $pressqueue_screen Whether this is one of PressQueue's own screens.
	 */
	public static function render_for( string $screen_base, bool $pressqueue_screen ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dashboard = 'dashboard' === $screen_base || $pressqueue_screen;

		if ( $dashboard || 'plugins' === $screen_base ) {
			if ( isset( self::$problems[ self::UPGRADE_FAILED ] ) ) {
				self::render_critical( __( 'PressQueue could not finish updating its database, so it is not sharing posts right now.', 'pressqueue' ), __( 'See what to do', 'pressqueue' ) );
			}

			if ( isset( self::$problems[ self::STATE_INVALID ] ) ) {
				self::render_critical( __( 'PressQueue stopped sharing posts because some of its saved settings are damaged. Open Data and privacy and save the settings there to start sharing again.', 'pressqueue' ), __( 'Open Data and privacy', 'pressqueue' ) );
			}
		}

		if ( $dashboard ) {
			self::render_planning_failure();
		}
	}

	/**
	 * Render a notice that stays until the problem is fixed.
	 *
	 * @param string $text Plain sentence.
	 * @param string $link_label Label of the link to the Data and privacy screen.
	 */
	private static function render_critical( string $text, string $link_label ): void {
		echo '<div class="notice notice-error"><p>' . esc_html( $text );
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo ' <a href="' . esc_url( Ui::url( Ui::PAGE_PRIVACY ) ) . '">' . esc_html( $link_label ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Render the warning about a post that could not be checked against the rules.
	 */
	private static function render_planning_failure(): void {
		$failure = get_option( self::PLANNING_FAILURE_OPTION, null );
		if ( ! is_array( $failure ) ) {
			return;
		}

		$post_id = (int) ( $failure['post_id'] ?? 0 );
		$title   = Ui::post_title( $post_id );

		if ( (int) ( $failure['retry_count'] ?? 0 ) >= self::PLANNING_RETRIES ) {
			/* translators: %s: post title. */
			$text = sprintf( __( 'PressQueue could not check "%s" against your sharing rules, even after trying again five times. To share it, open the post and use Share now in the Social sharing panel.', 'pressqueue' ), $title );
		} else {
			/* translators: %s: post title. */
			$text = sprintf( __( 'PressQueue could not check "%s" against your sharing rules. It tries again by itself, up to five times. If the post is still not shared after that, open it and use Share now in the Social sharing panel.', 'pressqueue' ), $title );
		}

		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $text );
		$link = $post_id > 0 && function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $post_id ) : null;
		if ( is_string( $link ) && '' !== $link ) {
			echo ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Open the post', 'pressqueue' ) . '</a>';
		}
		echo '</p></div>';
	}
}
