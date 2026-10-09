<?php
/**
 * General settings: sharing mode and how new posts are treated.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\SettingsRepository;

/**
 * Renders and saves the Settings screen.
 */
final class SettingsController {
	/**
	 * Pages a settings form may return to.
	 */
	private const RETURN_PAGES = array( Ui::PAGE_OVERVIEW, Ui::PAGE_SETTINGS, Ui::PAGE_ACTIVITY, Ui::PAGE_RULES, Ui::PAGE_CHANNELS );

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_post_pressqueue_save_settings', array( self::class, 'handle_save' ) );
		}
	}

	/**
	 * Render the settings screen.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PressQueue settings.', 'pressqueue' ) );
		}

		$repository = new SettingsRepository();
		$mode       = $repository->sharing_mode();
		$new_posts  = $repository->new_post_sharing();
		$modes      = Labels::modes();

		Ui::page_start( __( 'Settings', 'pressqueue' ) );
		self::render_tabs( 'general' );
		self::render_notice();

		Ui::form_start( 'pressqueue_save_settings', 'pressqueue_save_settings', array( 'redirect_page' => Ui::PAGE_SETTINGS ) );
		echo '<div class="pq-stack">';

		echo '<div class="pq-card"><div class="pq-section">';
		echo '<h3 id="pq-mode-title">' . esc_html__( 'Sharing mode', 'pressqueue' ) . '</h3>';
		echo '<div class="pq-choices" role="radiogroup" aria-labelledby="pq-mode-title">';
		foreach ( array( 'test', 'live', 'paused' ) as $value ) {
			$help = $modes[ $value ]['help'];
			if ( 'test' === $value ) {
				$help .= ' ' . __( 'Start here.', 'pressqueue' );
			}
			echo '<label class="pq-choice"><input type="radio" name="sharing_mode" value="' . esc_attr( $value ) . '" ' . checked( $mode, $value, false ) . ' />';
			echo '<span><b><span class="pq-dot pq-dot--' . esc_attr( $value ) . '" aria-hidden="true"></span>' . esc_html( $modes[ $value ]['label'] ) . '</b><span class="pq-choice-help">' . esc_html( $help ) . '</span></span></label>';
		}
		echo '</div>';
		echo '<p class="pq-help">' . esc_html__( 'Switching from test mode to Live only affects posts published from now on. Test runs are never sent.', 'pressqueue' ) . '</p>';
		echo '</div></div>';

		echo '<div class="pq-card"><div class="pq-section">';
		echo '<h3>' . esc_html__( 'New posts', 'pressqueue' ) . '</h3>';
		echo '<div class="pq-checks">';
		echo '<label><input type="radio" name="new_post_sharing" value="automatic" ' . checked( $new_posts, 'automatic', false ) . ' /><span><span class="pq-check-title">' . esc_html__( 'Share automatically when a rule matches', 'pressqueue' ) . '</span><span class="pq-muted">' . esc_html__( 'Writers can switch sharing off for a single post in the editor.', 'pressqueue' ) . '</span></span></label>';
		echo '<label><input type="radio" name="new_post_sharing" value="opt_in" ' . checked( $new_posts, 'opt_in', false ) . ' /><span><span class="pq-check-title">' . esc_html__( 'Only share posts where "Share this post" is switched on', 'pressqueue' ) . '</span><span class="pq-muted">' . esc_html__( 'New posts start with sharing switched off. Good for sites where only some posts belong on social media. Existing posts are not changed.', 'pressqueue' ) . '</span></span></label>';
		echo '</div></div></div>';

		echo '<div class="pq-card"><div class="pq-section">';
		echo '<h3>' . esc_html__( 'Who can use PressQueue', 'pressqueue' ) . '</h3>';
		echo '<p class="pq-muted" style="margin:0">' . esc_html__( 'Administrators can use everything. Other roles need PressQueue capabilities, which a role editor plugin or WP-CLI can grant. Writers who can edit a post but lack the post controls capability do not see the sharing panel.', 'pressqueue' ) . '</p>';
		echo '</div></div>';

		echo '<div><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'pressqueue' ) . '</button></div>';
		echo '</div>';
		Ui::form_end();

		Ui::page_end();
	}

	/**
	 * Render settings tabs.
	 *
	 * @param string $current Current tab.
	 */
	public static function render_tabs( string $current ): void {
		$tabs = array(
			'general' => array( __( 'General', 'pressqueue' ), Ui::url( Ui::PAGE_SETTINGS ) ),
		);

		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			$tabs['privacy'] = array( __( 'Data and privacy', 'pressqueue' ), Ui::url( Ui::PAGE_PRIVACY ) );
		}

		Ui::tabs( $tabs, $current );
	}

	/**
	 * Render a one-click mode switch button.
	 *
	 * @param string $mode Target mode.
	 * @param string $label Button label.
	 * @param string $css_class Button classes.
	 * @param string $return_page Page to return to.
	 */
	public static function render_mode_button( string $mode, string $label, string $css_class, string $return_page ): void {
		$confirm = 'live' === $mode ? __( 'Posts that match your rules will be sent to Buffer from now on. Go live?', 'pressqueue' ) : '';
		Ui::button_form(
			'pressqueue_save_settings',
			'pressqueue_save_settings',
			array(
				'sharing_mode'  => $mode,
				'redirect_page' => $return_page,
			),
			$label,
			$css_class,
			$confirm
		);
	}

	/**
	 * Save settings.
	 */
	public static function handle_save(): void {
		AdminPostBoundary::check( 'pressqueue_save_settings', Capabilities::MANAGE_SETTINGS, __( 'You are not allowed to manage PressQueue settings.', 'pressqueue' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$page = isset( $_POST['redirect_page'] ) ? sanitize_key( wp_unslash( $_POST['redirect_page'] ) ) : Ui::PAGE_SETTINGS;
		$page = in_array( $page, self::RETURN_PAGES, true ) ? $page : Ui::PAGE_SETTINGS;

		try {
			$repository = new SettingsRepository();
			$before     = $repository->sharing_mode();

			if ( isset( $_POST['sharing_mode'] ) ) {
				$repository->set_sharing_mode( sanitize_key( wp_unslash( $_POST['sharing_mode'] ) ) );
			} elseif ( isset( $_POST['dry_run_present'] ) || isset( $_POST['dry_run_enabled'] ) || isset( $_POST['kill_switch_enabled'] ) ) {
				$repository->set_dry_run_enabled( isset( $_POST['dry_run_enabled'] ) );
				$repository->set_kill_switch_enabled( isset( $_POST['kill_switch_enabled'] ) );
			}

			if ( isset( $_POST['new_post_sharing'] ) ) {
				$repository->set_new_post_sharing( sanitize_key( wp_unslash( $_POST['new_post_sharing'] ) ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$after   = $repository->sharing_mode();
			$message = 'settings_saved';
			if ( $before !== $after ) {
				$message = 'mode_' . $after;
				if ( 'paused' === $before && 'paused' !== $after && ( new ActivitySummary() )->count_in_states( array( 'held' ) ) > 0 ) {
					$message = 'resumed_with_held';
				}
			}

			self::redirect( $page, $message );
		} catch ( \Throwable $throwable ) {
			self::redirect( $page, 'settings_error', SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Render a notice for settings results.
	 */
	public static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only query arg.
		$message = isset( $_GET['pressqueue_settings'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_settings'] ) ) : '';

		$messages = array(
			'settings_saved'    => array( 'success', __( 'Settings saved.', 'pressqueue' ) ),
			'mode_live'         => array( 'success', __( 'You are live. Posts that match a rule are now sent to Buffer.', 'pressqueue' ) ),
			'mode_test'         => array( 'success', __( 'Test mode is on. PressQueue records what it would share and sends nothing.', 'pressqueue' ) ),
			'mode_paused'       => array( 'warning', __( 'PressQueue is paused. Nothing is sent until you resume.', 'pressqueue' ) ),
			'resumed_with_held' => array( 'warning', __( 'Sharing resumed. Shares held during the pause are still on hold. Open Activity to resume or cancel them.', 'pressqueue' ) ),
			'settings_error'    => array( 'error', __( 'Settings could not be saved.', 'pressqueue' ) ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		$detail = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only query arg.
		if ( 'settings_error' === $message && isset( $_GET['pressqueue_detail'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only query arg.
			$detail = SafeOperatorError::notice_detail( sanitize_text_field( wp_unslash( $_GET['pressqueue_detail'] ) ) );
		}

		Ui::notice( $messages[ $message ][0], $messages[ $message ][1], $detail );
	}

	/**
	 * Redirect after a save.
	 *
	 * @param string      $page Page slug.
	 * @param string      $message Message key.
	 * @param string|null $detail Optional detail.
	 */
	private static function redirect( string $page, string $message, ?string $detail = null ): void {
		$args = array( 'pressqueue_settings' => $message );
		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( Ui::url( $page, $args ) );
		exit;
	}
}
