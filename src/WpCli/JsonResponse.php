<?php
/**
 * WP-CLI JSON responses.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

/**
 * Emits stable JSON envelopes for command automation.
 */
final class JsonResponse {
	/**
	 * Return a success envelope.
	 *
	 * @param string                 $command Command key.
	 * @param array<string,mixed>    $data Data.
	 * @param array<string,int>|null $pagination Pagination.
	 * @return array<string,mixed>
	 */
	public static function success_envelope( string $command, array $data, ?array $pagination = null ): array {
		return array(
			'command'        => $command,
			'data'           => $data,
			'pagination'     => $pagination,
			'schema_version' => 1,
		);
	}

	/**
	 * Return an error envelope.
	 *
	 * @param string                          $command Command key.
	 * @param string                          $code Error code.
	 * @param string                          $message Safe message.
	 * @param int                             $status Exit status.
	 * @param array<int,array<string,string>> $field_errors Field errors.
	 * @return array<string,mixed>
	 */
	public static function error_envelope( string $command, string $code, string $message, int $status, array $field_errors = array() ): array {
		return array(
			'command'        => $command,
			'error'          => array(
				'code'         => $code,
				'field_errors' => array_values( $field_errors ),
				'message'      => $message,
				'status'       => $status,
			),
			'schema_version' => 1,
		);
	}

	/**
	 * Emit a success envelope.
	 *
	 * @param string                 $command Command key.
	 * @param array<string,mixed>    $data Data.
	 * @param array<string,int>|null $pagination Pagination.
	 * @return int
	 */
	public static function success( string $command, array $data, ?array $pagination = null ): int {
		self::line( self::success_envelope( $command, $data, $pagination ) );

		return ExitCodes::SUCCESS;
	}

	/**
	 * Emit an error envelope and halt WP-CLI when possible.
	 *
	 * @param string           $command Command key.
	 * @param CommandException $exception Exception.
	 * @return int
	 */
	public static function error( string $command, CommandException $exception ): int {
		self::line( self::error_envelope( $command, $exception->error_code(), $exception->getMessage(), $exception->status(), $exception->field_errors() ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::halt( $exception->status() );
		}

		return $exception->status();
	}

	/**
	 * Emit one JSON line.
	 *
	 * @param array<string,mixed> $document Document.
	 */
	private static function line( array $document ): void {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $document, JSON_UNESCAPED_SLASHES )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() may not be loaded in isolated CLI contexts.
			: json_encode( $document, JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) ) {
			$json = '{"schema_version":1,"error":{"code":"internal_error","message":"JSON encoding failed.","status":5,"field_errors":[]}}';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::line( $json );
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded JSON is the command transport.
		echo $json . "\n";
	}
}
