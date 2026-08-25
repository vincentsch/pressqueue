<?php
/**
 * WP-CLI preview command.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command exceptions are serialized by JSON envelopes.

use PressQueue\Security\Capabilities;
use PressQueue\WpCli\Query\PreviewQueryService;

/**
 * Handles nondurable preview commands.
 */
final class PreviewCommand extends AbstractCommand {
	/**
	 * Preview one stored post through enabled rules.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function post( array $args, array $assoc_args ): int {
		return $this->run_json(
			'preview.post',
			$assoc_args,
			function () use ( $args, $assoc_args ): array {
				$this->require_capability( Capabilities::VIEW_DECISIONS );
				$trigger = isset( $assoc_args['trigger'] ) ? (string) $assoc_args['trigger'] : 'update';

				return ( new PreviewQueryService() )->post_preview( $this->positive_int_positional( $args, 0, 'post_id' ), $trigger );
			}
		);
	}

	/**
	 * Preview one rule against one stored post.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function rule( array $args, array $assoc_args ): int {
		return $this->run_json(
			'preview.rule',
			$assoc_args,
			function () use ( $args, $assoc_args ): array {
				$this->require_capability( Capabilities::MANAGE_RULES );
				if ( ! isset( $assoc_args['post-id'] ) ) {
					throw new CommandException( 'invalid_arguments', '--post-id is required.', ExitCodes::INVALID );
				}

				$post_id = (int) $assoc_args['post-id'];
				if ( $post_id <= 0 || (string) $post_id !== (string) $assoc_args['post-id'] ) {
					throw new CommandException( 'invalid_arguments', '--post-id must be a positive integer.', ExitCodes::INVALID );
				}

				$trigger = isset( $assoc_args['trigger'] ) ? (string) $assoc_args['trigger'] : 'update';

				return ( new PreviewQueryService() )->rule_preview( $this->positional( $args, 0, 'rule_public_id' ), $post_id, $trigger );
			}
		);
	}
}
