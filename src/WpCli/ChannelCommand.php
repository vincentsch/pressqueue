<?php
/**
 * WP-CLI channel command.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command exceptions are serialized by JSON envelopes.

use PressQueue\Security\Capabilities;
use PressQueue\WpCli\Query\ChannelQueryService;

/**
 * Handles channel read commands.
 */
final class ChannelCommand extends AbstractCommand {
	/**
	 * List channels.
	 *
	 * @subcommand list
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function list_( array $args, array $assoc_args ): int {
		unset( $args );

		return $this->run_json(
			'channels.list',
			$assoc_args,
			function () use ( $assoc_args ): array {
				$this->require_capability( Capabilities::MANAGE_CONNECTIONS );
				if ( ! isset( $assoc_args['connection'] ) || '' === trim( (string) $assoc_args['connection'] ) ) {
					throw new CommandException( 'invalid_arguments', '--connection is required.', ExitCodes::INVALID );
				}
				list( $page, $per_page ) = $this->pagination_args( $assoc_args );

				return ( new ChannelQueryService() )->list_channels( $assoc_args, $page, $per_page );
			}
		);
	}
}
