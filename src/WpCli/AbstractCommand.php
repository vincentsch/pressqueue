<?php
/**
 * Shared WP-CLI command behavior.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command exceptions are serialized by JSON envelopes.

use PressQueue\Security\Capabilities;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageUnavailableException;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Base class for JSON-only command implementations.
 */
abstract class AbstractCommand {
	/**
	 * Execute a command and emit a JSON envelope.
	 *
	 * @param string              $command Command key.
	 * @param array<string,mixed> $assoc_args Associative args.
	 * @param callable            $callback Callback.
	 * @return int
	 */
	protected function run_json( string $command, array $assoc_args, callable $callback ): int {
		try {
			$this->assert_json_format( $assoc_args );
			$result = $callback();
			if ( isset( $result['pagination'] ) ) {
				return JsonResponse::success( $command, $result['data'], $result['pagination'] );
			}

			return JsonResponse::success( $command, $result, null );
		} catch ( CommandException $exception ) {
			return JsonResponse::error( $command, $exception );
		} catch ( StaleRecordException $exception ) {
			unset( $exception );
			return JsonResponse::error( $command, new CommandException( 'stale_state', 'The record changed before the command could complete.', ExitCodes::REFUSED ) );
		} catch ( StorageUnavailableException $exception ) {
			unset( $exception );
			return JsonResponse::error( $command, new CommandException( 'storage_failure', 'PressQueue storage is unavailable.', ExitCodes::INTERNAL ) );
		} catch ( ValidationException $exception ) {
			if ( false !== stripos( $exception->getMessage(), 'does not exist' ) ) {
				return JsonResponse::error( $command, new CommandException( 'not_found', 'The requested PressQueue record does not exist.', ExitCodes::REFUSED ) );
			}

			if ( false !== stripos( $exception->getMessage(), 'not readable' ) ) {
				return JsonResponse::error( $command, new CommandException( 'not_authorized', 'The WP-CLI user is not allowed to read the requested post.', ExitCodes::UNAUTHORIZED ) );
			}

			return JsonResponse::error( $command, new CommandException( 'invalid_arguments', $exception->getMessage(), ExitCodes::INVALID ) );
		} catch ( StorageException $exception ) {
			unset( $exception );
			return JsonResponse::error( $command, new CommandException( 'storage_failure', 'PressQueue storage failed.', ExitCodes::INTERNAL ) );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return JsonResponse::error( $command, new CommandException( 'internal_error', 'PressQueue command failed.', ExitCodes::INTERNAL ) );
		}
	}

	/**
	 * Require an authenticated user with a capability.
	 *
	 * @param string $capability Capability.
	 */
	protected function require_capability( string $capability ): void {
		if ( function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
			throw new CommandException( 'not_authenticated', 'WP-CLI user context is required.', ExitCodes::UNAUTHORIZED );
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( $capability ) ) {
			throw new CommandException( 'not_authorized', 'The WP-CLI user is not allowed to run this PressQueue command.', ExitCodes::UNAUTHORIZED );
		}
	}

	/**
	 * Return the current user ID.
	 *
	 * @return int
	 */
	protected function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Read a positional argument.
	 *
	 * @param array<int,mixed> $args Args.
	 * @param int              $index Index.
	 * @param string           $name Name.
	 * @return string
	 */
	protected function positional( array $args, int $index, string $name ): string {
		if ( ! isset( $args[ $index ] ) || '' === trim( (string) $args[ $index ] ) ) {
			throw new CommandException( 'invalid_arguments', $name . ' is required.', ExitCodes::INVALID );
		}

		return trim( (string) $args[ $index ] );
	}

	/**
	 * Return a positive integer positional argument.
	 *
	 * @param array<int,mixed> $args Args.
	 * @param int              $index Index.
	 * @param string           $name Name.
	 * @return int
	 */
	protected function positive_int_positional( array $args, int $index, string $name ): int {
		$value = $this->positional( $args, $index, $name );
		if ( ! ctype_digit( $value ) || (int) $value <= 0 ) {
			throw new CommandException( 'invalid_arguments', $name . ' must be a positive integer.', ExitCodes::INVALID );
		}

		return (int) $value;
	}

	/**
	 * Return the `--apply` flag.
	 *
	 * @param array<string,mixed> $assoc_args Args.
	 * @return bool
	 */
	protected function apply_requested( array $assoc_args ): bool {
		return array_key_exists( 'apply', $assoc_args ) && false !== $assoc_args['apply'];
	}

	/**
	 * Return pagination arguments.
	 *
	 * @param array<string,mixed> $assoc_args Args.
	 * @return array{0:int,1:int}
	 */
	protected function pagination_args( array $assoc_args ): array {
		$page     = isset( $assoc_args['page'] ) ? (int) $assoc_args['page'] : 1;
		$per_page = isset( $assoc_args['per-page'] ) ? (int) $assoc_args['per-page'] : 50;

		if ( $page < 1 ) {
			throw new CommandException( 'invalid_arguments', '--page must be at least 1.', ExitCodes::INVALID );
		}

		if ( $per_page < 1 || $per_page > 100 ) {
			throw new CommandException( 'invalid_arguments', '--per-page must be 1 through 100.', ExitCodes::INVALID );
		}

		return array( $page, $per_page );
	}

	/**
	 * Decode a bounded JSON object option.
	 *
	 * @param array<string,mixed> $assoc_args Args.
	 * @param string              $key Key without leading dashes.
	 * @param int                 $max_bytes Maximum size.
	 * @return array<string,mixed>
	 */
	protected function json_object_arg( array $assoc_args, string $key, int $max_bytes = 65536 ): array {
		if ( ! isset( $assoc_args[ $key ] ) || '' === trim( (string) $assoc_args[ $key ] ) ) {
			return array();
		}

		$json = (string) $assoc_args[ $key ];
		if ( strlen( $json ) > $max_bytes ) {
			throw new CommandException( 'schema_validation_failed', '--' . $key . ' exceeds the maximum size.', ExitCodes::INVALID );
		}

		try {
			$value = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			throw new CommandException( 'malformed_json', '--' . $key . ' must be valid JSON.', ExitCodes::INVALID );
		}

		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new CommandException( 'schema_validation_failed', '--' . $key . ' must be a JSON object.', ExitCodes::INVALID );
		}

		return $value;
	}

	/**
	 * Sanitize a plain evidence note.
	 *
	 * @param string|null $note Note.
	 * @return string|null
	 */
	protected function note_arg( ?string $note ): ?string {
		if ( null === $note ) {
			return null;
		}

		$note = trim( $note );
		if ( '' === $note ) {
			return null;
		}

		if ( function_exists( 'sanitize_textarea_field' ) ) {
			$note = sanitize_textarea_field( $note );
		} else {
			$note = trim( preg_replace( '/<[^>]*>/', '', $note ) ?? '' );
		}

		return substr( $note, 0, 500 );
	}

	/**
	 * Map a blocked assessment to an applied-command error.
	 *
	 * @param array<int,string> $reasons Reasons.
	 * @return CommandException
	 */
	protected function refused_from_reasons( array $reasons ): CommandException {
		if ( in_array( 'not_found', $reasons, true ) ) {
			return new CommandException( 'not_found', 'The requested PressQueue record does not exist.', ExitCodes::REFUSED );
		}

		return new CommandException( 'policy_refused', 'The requested PressQueue action is not currently allowed.', ExitCodes::REFUSED );
	}

	/**
	 * Require stable JSON format.
	 *
	 * @param array<string,mixed> $assoc_args Args.
	 */
	private function assert_json_format( array $assoc_args ): void {
		if ( isset( $assoc_args['format'] ) && 'json' !== (string) $assoc_args['format'] ) {
			throw new CommandException( 'invalid_arguments', 'Only --format=json is supported by the stable automation contract.', ExitCodes::INVALID );
		}
	}
}
