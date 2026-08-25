<?php
/**
 * WP-CLI command exception.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

use RuntimeException;

/**
 * Carries a stable JSON error code and exit status.
 */
final class CommandException extends RuntimeException {
	/**
	 * Stable machine error code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Field errors.
	 *
	 * @var array<int,array<string,string>>
	 */
	private array $field_errors;

	/**
	 * Constructor.
	 *
	 * @param string                          $error_code Error code.
	 * @param string                          $message Safe message.
	 * @param int                             $status Exit status.
	 * @param array<int,array<string,string>> $field_errors Field errors.
	 */
	public function __construct( string $error_code, string $message, int $status, array $field_errors = array() ) {
		parent::__construct( $message, $status );

		$this->error_code   = $error_code;
		$this->field_errors = $this->normalize_field_errors( $field_errors );
	}

	/**
	 * Return the machine error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Return the exit status.
	 *
	 * @return int
	 */
	public function status(): int {
		return (int) $this->getCode();
	}

	/**
	 * Return field errors.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function field_errors(): array {
		return $this->field_errors;
	}

	/**
	 * Normalize field error entries.
	 *
	 * @param array<int,array<string,string>> $field_errors Field errors.
	 * @return array<int,array<string,string>>
	 */
	private function normalize_field_errors( array $field_errors ): array {
		$normalized = array();

		foreach ( $field_errors as $error ) {
			$path    = isset( $error['path'] ) ? $error['path'] : ( $error['field'] ?? '' );
			$code    = isset( $error['code'] ) ? $error['code'] : 'invalid';
			$message = $error['message'] ?? '';

			if ( '' === $path || '' === $message ) {
				continue;
			}

			$normalized[] = array(
				'code'    => $code,
				'message' => $message,
				'path'    => $path,
			);
		}

		return $normalized;
	}
}
