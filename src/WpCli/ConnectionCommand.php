<?php
/**
 * WP-CLI connection command.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

use PressQueue\Security\Capabilities;
use PressQueue\WpCli\Query\ConnectionQueryService;

/**
 * Handles connection read commands.
 */
final class ConnectionCommand extends AbstractCommand {
	/**
	 * List connections.
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
			'connections.list',
			$assoc_args,
			function () use ( $assoc_args ): array {
				$this->require_capability( Capabilities::MANAGE_CONNECTIONS );
				list( $page, $per_page ) = $this->pagination_args( $assoc_args );

				return ( new ConnectionQueryService() )->list_connections( $assoc_args, $page, $per_page );
			}
		);
	}

	/**
	 * Show a connection.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function show( array $args, array $assoc_args ): int {
		return $this->run_json(
			'connections.show',
			$assoc_args,
			function () use ( $args ): array {
				$this->require_capability( Capabilities::MANAGE_CONNECTIONS );

				return ( new ConnectionQueryService() )->show( $this->positional( $args, 0, 'connection_public_id' ) );
			}
		);
	}
}
