<?php
/**
 * Channel administration controller.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Repository\ChannelRepository;

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
	 * Render the on/off switch for one channel.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @param string        $label Accessible label, for example "Post to Larkfield Bakery on LinkedIn".
	 * @param string        $confirm Optional question asked before switching the channel off.
	 */
	public static function render_channel_action( ChannelRecord $channel, string $label = '', string $confirm = '' ): void {
		if ( '' === $label ) {
			/* translators: 1: channel name, 2: social network name. */
			$label = sprintf( __( 'Post to %1$s on %2$s', 'pressqueue' ), ActivitySummary::channel_display_name( $channel ), Labels::service( $channel->service ) );
		}

		Ui::toggle_form(
			'pressqueue_enable_channel',
			'pressqueue_disable_channel',
			array(
				'channel_public_id' => $channel->public_id,
				'updated_at'        => $channel->updated_at->format( 'Y-m-d H:i:s' ),
			),
			$channel->enabled,
			$label,
			$channel->enabled && '' !== $confirm ? array( 'confirm' => $confirm ) : array()
		);
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

		$public_id = self::post_text( 'channel_public_id' );
		$result    = PublicIds::is_valid( $public_id ) ? array( 'channel' => $public_id ) : array();

		try {
			$channel = ( new ChannelRepository() )->set_enabled_if_current( $public_id, $enabled, self::post_text( 'updated_at' ) );
			$result  = array(
				'message' => $enabled ? 'channel_enabled' : 'channel_disabled',
				'channel' => $channel->public_id,
			);
		} catch ( \Throwable $throwable ) {
			$result = array_merge( $result, ConnectionController::error_result( $throwable ) );
		}

		ConnectionController::redirect_to_page( $result );
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
}
