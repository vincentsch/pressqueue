<?php
/**
 * WP-CLI decision command.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

use PressQueue\Security\Capabilities;
use PressQueue\WpCli\Query\DecisionQueryService;

/**
 * Handles decision read commands.
 */
final class DecisionCommand extends AbstractCommand {
	/**
	 * List decisions.
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
			'decisions.list',
			$assoc_args,
			function () use ( $assoc_args ): array {
				$this->require_capability( Capabilities::VIEW_DECISIONS );
				list( $page, $per_page ) = $this->pagination_args( $assoc_args );

				return ( new DecisionQueryService() )->list_decisions( $assoc_args, $page, $per_page );
			}
		);
	}

	/**
	 * Show one decision.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function show( array $args, array $assoc_args ): int {
		return $this->run_json(
			'decisions.show',
			$assoc_args,
			function () use ( $args ): array {
				$this->require_capability( Capabilities::VIEW_DECISIONS );

				return ( new DecisionQueryService() )->show( $this->positional( $args, 0, 'decision_public_id' ) );
			}
		);
	}
}
