<?php
/**
 * Channel administration controller.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\ValidationException;

/**
 * Handles explicit channel enablement.
 */
final class ChannelController {
	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_pressqueue_enable_channel', array( self::class, 'handle_enable_channel' ) );
		add_action( 'admin_post_pressqueue_disable_channel', array( self::class, 'handle_disable_channel' ) );
	}

	/**
	 * Render one channel action.
	 *
	 * @param ChannelRecord $channel Channel.
	 */
	public static function render_channel_action( ChannelRecord $channel ): void {
		$action = $channel->enabled ? 'pressqueue_disable_channel' : 'pressqueue_enable_channel';
		$label  = $channel->enabled ? __( 'Disable Channel', 'pressqueue' ) : __( 'Enable Channel', 'pressqueue' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="channel_public_id" value="' . esc_attr( $channel->public_id ) . '" />';
		echo '<input type="hidden" name="updated_at" value="' . esc_attr( $channel->updated_at->format( 'Y-m-d H:i:s' ) ) . '" />';
		submit_button( $label, 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle enable.
	 */
	public static function handle_enable_channel(): void {
		self::handle_set_enabled( true, 'pressqueue_enable_channel' );
	}

	/**
	 * Handle disable.
	 */
	public static function handle_disable_channel(): void {
		self::handle_set_enabled( false, 'pressqueue_disable_channel' );
	}

	/**
	 * Handle channel enablement.
	 *
	 * @param bool   $enabled Whether to enable.
	 * @param string $nonce_action Nonce action.
	 */
	private static function handle_set_enabled( bool $enabled, string $nonce_action ): void {
		self::mutation_boundary( $nonce_action );

		try {
			( new ChannelRepository() )->set_enabled_if_current( self::post_text( 'channel_public_id' ), $enabled, self::post_text( 'updated_at' ) );
			self::redirect( $enabled ? 'channel_enabled' : 'channel_disabled' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'connection_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Assert mutation request requirements.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function mutation_boundary( string $nonce_action ): void {
		AdminPostBoundary::check( $nonce_action, Capabilities::MANAGE_CONNECTIONS, __( 'You are not allowed to manage PressQueue channels.', 'pressqueue' ) );
	}

	/**
	 * Return a POST text field.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function post_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked by caller.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Redirect to connections.
	 *
	 * @param string      $message Message key.
	 * @param string|null $detail Optional detail.
	 */
	private static function redirect( string $message, ?string $detail = null ): void {
		$args = array(
			'page'               => ConnectionController::SLUG,
			'pressqueue_message' => $message,
		);

		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Return a redacted exception detail.
	 *
	 * @param \Throwable $throwable Throwable.
	 * @return string
	 */
	private static function safe_error_detail( \Throwable $throwable ): string {
		return SafeOperatorError::redirect_detail( $throwable );
	}
}
