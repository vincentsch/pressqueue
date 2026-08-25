<?php
/**
 * WP-CLI command registration.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

/**
 * Registers PressQueue WP-CLI commands.
 */
final class CommandRegistrar {
	/**
	 * Register commands when WP-CLI is active.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'pressqueue status', array( new StatusCommand(), '__invoke' ) );
		\WP_CLI::add_command( 'pressqueue connections', ConnectionCommand::class );
		\WP_CLI::add_command( 'pressqueue channels', ChannelCommand::class );
		\WP_CLI::add_command( 'pressqueue rules', RuleCommand::class );
		\WP_CLI::add_command( 'pressqueue preview', PreviewCommand::class );
		\WP_CLI::add_command( 'pressqueue decisions', DecisionCommand::class );
		\WP_CLI::add_command( 'pressqueue deliveries', DeliveryCommand::class );
		\WP_CLI::add_command( 'pressqueue queue', QueueCommand::class );
		\WP_CLI::add_command( 'pressqueue batches', BatchCommand::class );
	}
}
