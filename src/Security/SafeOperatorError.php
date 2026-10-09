<?php
/**
 * Safe operator-facing error details.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Security;

use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageUnavailableException;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Converts internal failures to bounded public diagnostics.
 */
final class SafeOperatorError {
	/**
	 * Exact exception messages that are safe to expose to operators.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_MESSAGES = array(
		'Batch changed before cancellation completed.',
		'Batch does not exist.',
		'Batch item changed.',
		'Batch item set changed.',
		'Batch preview changed. Create a fresh preview before confirming.',
		'Batch preview is no longer confirmable.',
		'Batch preview snapshot does not match the reviewed snapshot.',
		'Connection already has a credential.',
		'Connection changed before synchronization completed.',
		'Connection credential changed before rotation completed.',
		'Connection disappeared before synchronization.',
		'Connection must be verified and synchronized before it can be enabled.',
		'Connection synchronization is already running.',
		'Connection sync lease is no longer held.',
		'Date filters must use valid site-local dates.',
		'Date filters must use YYYY-MM-DD.',
		'Delivery action confirmation is required.',
		'Delivery changed before the operation completed.',
		'Delivery does not exist.',
		'Delivery is not eligible for this operation.',
		'Due retry filter must be 1 or 0.',
		'Export cursor is invalid.',
		'Export cursor table is invalid.',
		'Export JSON could not be encoded.',
		'ID filters must contain only digits.',
		'One export row exceeds the byte limit.',
		'Post cannot be previewed for this trigger.',
		'PressQueue runtime is disabled.',
		'Privacy setting is invalid.',
		'Privacy settings must be an object.',
		'Privacy settings schema version is unsupported.',
		'Reconciliation outcome is not supported.',
		'Remote ID is required for this reconciliation outcome.',
		'REST request body must be a JSON object.',
		'Retention active run is invalid.',
		'Retention active run must be an object.',
		'Retention completion is invalid.',
		'Retention completion must be an object.',
		'Retention counter is invalid.',
		'Retention counters are invalid.',
		'Retention counters must be an object.',
		'Retention cursor family is invalid.',
		'Retention cursor must be an object.',
		'Retention days must be a number.',
		'Retention days must be between 30 and 3650.',
		'Retention lease is no longer held.',
		'Retention state must be an object.',
		'Retention state schema version is unsupported.',
		'Rule does not exist.',
		'Rule must be previewed before it can be enabled.',
		'Rule version changed before preview.',
		'Rule version is stale.',
		'Template lock version is stale.',
		'Template JSON field is invalid.',
		'Template JSON field must contain an object.',
		'You are not allowed to operate PressQueue batches.',
		'You are not allowed to preview this post.',
	);

	/**
	 * Prefixes for bounded classification messages produced by PressQueue.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_MESSAGE_PREFIXES = array(
		'Delivery reconciliation refused: ',
		'Delivery transition refused: ',
	);

	/**
	 * Classify a failure without exposing raw exception text.
	 *
	 * @param Throwable $throwable Failure.
	 * @return string
	 */
	public static function classification( Throwable $throwable ): string {
		if ( $throwable instanceof StaleRecordException ) {
			return 'stale_record';
		}

		if ( $throwable instanceof StorageUnavailableException ) {
			return 'storage_unavailable';
		}

		if ( $throwable instanceof ValidationException ) {
			return 'validation_failed';
		}

		if ( $throwable instanceof StorageException ) {
			return 'storage_error';
		}

		return 'operator_error';
	}

	/**
	 * Return a safe message for notices and REST errors.
	 *
	 * @param Throwable $throwable Failure.
	 * @param string    $fallback Fallback message.
	 * @return string
	 */
	public static function message( Throwable $throwable, string $fallback = '' ): string {
		if ( $throwable instanceof ValidationException || $throwable instanceof StaleRecordException ) {
			$message = trim( $throwable->getMessage() );
			if ( '' !== $message && self::is_allowed_message( $message ) ) {
				return $message;
			}
		}

		if ( '' !== $fallback ) {
			return $fallback;
		}

		return 'The operation could not be completed. Review the stored status and try again.';
	}

	/**
	 * Return safe field errors.
	 *
	 * @param Throwable $throwable Failure.
	 * @param string    $field Field name.
	 * @return array<string,string>
	 */
	public static function field_errors( Throwable $throwable, string $field ): array {
		return array(
			$field => self::message( $throwable ),
		);
	}

	/**
	 * Return a failure marker safe for option or row storage.
	 *
	 * @param Throwable $throwable Failure.
	 * @return array<string,mixed>
	 */
	public static function failure_marker( Throwable $throwable ): array {
		return array(
			'classification' => self::classification( $throwable ),
			'failed_at'      => gmdate( 'Y-m-d H:i:s' ),
			'schema_version' => 1,
		);
	}

	/**
	 * Return a safe redirect detail for admin notices.
	 *
	 * @param Throwable $throwable Failure.
	 * @return string
	 */
	public static function redirect_detail( Throwable $throwable ): string {
		return self::message(
			$throwable,
			'Failure classification: ' . self::classification( $throwable ) . '.'
		);
	}

	/**
	 * Only display fixed diagnostics supplied through a redirect URL.
	 *
	 * @param string $detail Requested notice detail.
	 * @return string
	 */
	public static function notice_detail( string $detail ): string {
		if ( in_array( $detail, self::ALLOWED_MESSAGES, true ) || 1 === preg_match( '/^Failure classification: (stale_record|storage_unavailable|validation_failed|storage_error|operator_error)\\.$/', $detail ) ) {
			return $detail;
		}
		return '';
	}

	/**
	 * Return a safe activation failure notice.
	 *
	 * @param Throwable $throwable Failure.
	 * @return string
	 */
	public static function activation_message( Throwable $throwable ): string {
		return sprintf(
			'PressQueue could not complete activation setup. Failure classification: %s.',
			self::classification( $throwable )
		);
	}

	/**
	 * Check whether a validation message is in the public allowlist.
	 *
	 * @param string $message Message.
	 * @return bool
	 */
	private static function is_allowed_message( string $message ): bool {
		if ( in_array( $message, self::ALLOWED_MESSAGES, true ) ) {
			return true;
		}

		foreach ( self::ALLOWED_MESSAGE_PREFIXES as $prefix ) {
			if ( str_starts_with( $message, $prefix ) && 1 === preg_match( '/^[A-Za-z0-9 _:.\\-]+$/', $message ) && strlen( $message ) <= 160 ) {
				return true;
			}
		}

		return false;
	}
}
