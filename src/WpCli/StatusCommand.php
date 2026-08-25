<?php
/**
 * WP-CLI status command.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

use PressQueue\Security\Capabilities;
use PressQueue\WpCli\Query\StatusQueryService;

/**
 * Handles `wp pressqueue status`.
 */
final class StatusCommand extends AbstractCommand {
	/**
	 * Invoke the command.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function __invoke( array $args, array $assoc_args ): int {
		unset( $args );

		return $this->run_json(
			'status',
			$assoc_args,
			function (): array {
				$this->require_capability( Capabilities::MANAGE_SETTINGS );

				return ( new StatusQueryService() )->status();
			}
		);
	}
}
