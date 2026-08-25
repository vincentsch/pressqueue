<?php
/**
 * WP-CLI rule command.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

use PressQueue\Security\Capabilities;
use PressQueue\WpCli\Query\RuleQueryService;

/**
 * Handles rule read commands.
 */
final class RuleCommand extends AbstractCommand {
	/**
	 * List rules.
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
			'rules.list',
			$assoc_args,
			function () use ( $assoc_args ): array {
				$this->require_capability( Capabilities::MANAGE_RULES );
				list( $page, $per_page ) = $this->pagination_args( $assoc_args );

				return ( new RuleQueryService() )->list_rules( $assoc_args, $page, $per_page );
			}
		);
	}

	/**
	 * Show one rule.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function show( array $args, array $assoc_args ): int {
		return $this->run_json(
			'rules.show',
			$assoc_args,
			function () use ( $args ): array {
				$this->require_capability( Capabilities::MANAGE_RULES );

				return ( new RuleQueryService() )->show( $this->positional( $args, 0, 'rule_public_id' ) );
			}
		);
	}
}
