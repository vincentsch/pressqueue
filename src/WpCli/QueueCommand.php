<?php
/**
 * WP-CLI queue command.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

use PressQueue\Security\Capabilities;
use PressQueue\WpCli\Query\StatusQueryService;

/**
 * Handles queue inspection.
 */
final class QueueCommand extends AbstractCommand {
	/**
	 * Show queue status.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function status( array $args, array $assoc_args ): int {
		unset( $args );

		return $this->run_json(
			'queue.status',
			$assoc_args,
			function (): array {
				$this->require_capability( Capabilities::VIEW_DELIVERIES );

				return ( new StatusQueryService() )->status()['queue'];
			}
		);
	}
}
