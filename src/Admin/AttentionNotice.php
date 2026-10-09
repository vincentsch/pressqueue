<?php
/**
 * Dashboard notice when shares need attention.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;

/**
 * Tells site owners about failed or uncertain shares outside the PressQueue screens.
 */
final class AttentionNotice {
	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_notices', array( self::class, 'render' ) );
		}
	}

	/**
	 * Render the notice on the dashboard and post lists only.
	 */
	public static function render(): void {
		if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || ! in_array( $screen->base, array( 'dashboard', 'edit' ), true ) ) {
			return;
		}

		try {
			$summary   = new ActivitySummary();
			$attention = $summary->count_in_states( array( 'failed', 'ambiguous' ) );
			$mode      = Ui::mode();
			$held      = 'paused' === $mode ? $summary->count_in_states( array( 'held' ) ) : 0;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return;
		}

		if ( $attention > 0 ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'PressQueue:', 'pressqueue' ) . '</strong> ';
			/* translators: %d: number of shares. */
			echo esc_html( sprintf( _n( '%d share needs your attention.', '%d shares need your attention.', $attention, 'pressqueue' ), $attention ) );
			echo ' <a href="' . esc_url( Ui::url( Ui::PAGE_ACTIVITY, array( 'group' => 'attention' ) ) ) . '">' . esc_html__( 'Review them', 'pressqueue' ) . '</a></p></div>';
		}

		if ( 'paused' === $mode && 'dashboard' === $screen->base ) {
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'PressQueue is paused.', 'pressqueue' ) . '</strong> ';
			if ( $held > 0 ) {
				/* translators: %d: number of shares. */
				echo esc_html( sprintf( _n( '%d share is on hold.', '%d shares are on hold.', $held, 'pressqueue' ), $held ) ) . ' ';
			}
			echo '<a href="' . esc_url( Ui::url( Ui::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Open PressQueue', 'pressqueue' ) . '</a></p></div>';
		}
	}
}
