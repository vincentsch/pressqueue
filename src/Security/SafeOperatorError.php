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
	 * Fixed English label stored with planning reasons and batch items.
	 *
	 * Stored documents are compared when work is repeated, so this text never changes with the language.
	 */
	private const STORED_FALLBACK = 'The operation could not be completed. Review the stored status and try again.';

	/**
	 * Return a safe message for notices and REST errors.
	 *
	 * @param Throwable $throwable Failure.
	 * @param string    $fallback Fallback message.
	 * @return string
	 */
	public static function message( Throwable $throwable, string $fallback = '' ): string {
		$allowed = self::allowed_message( $throwable );
		if ( null !== $allowed ) {
			return $allowed;
		}

		if ( '' !== $fallback ) {
			return $fallback;
		}

		return __( 'PressQueue could not finish this. Try again in a moment.', 'pressqueue' );
	}

	/**
	 * Return a safe label to store in planning reasons and batch items.
	 *
	 * Unlike message(), the fallback is never translated.
	 *
	 * @param Throwable $throwable Failure.
	 * @return string
	 */
	public static function stored_label( Throwable $throwable ): string {
		return self::allowed_message( $throwable ) ?? self::STORED_FALLBACK;
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
	 * Only display fixed diagnostics supplied through a redirect URL, in plain words.
	 *
	 * A failure classification becomes a sentence that says what to do. Allowed messages pass through.
	 *
	 * @param string $detail Requested notice detail.
	 * @return string
	 */
	public static function notice_detail( string $detail ): string {
		$detail = self::technical_detail( $detail );

		if ( 1 === preg_match( '/^Failure classification: ([a-z_]+)\\.$/', $detail, $matches ) ) {
			return self::classification_text( $matches[1] );
		}

		return $detail;
	}

	/**
	 * Only display fixed diagnostics supplied through a redirect URL, as they are.
	 *
	 * For "Technical details" boxes, where the exact code helps support.
	 *
	 * @param string $detail Requested notice detail.
	 * @return string
	 */
	public static function technical_detail( string $detail ): string {
		if ( in_array( $detail, self::ALLOWED_MESSAGES, true ) || 1 === preg_match( '/^Failure classification: (stale_record|storage_unavailable|validation_failed|storage_error|operator_error)\\.$/', $detail ) ) {
			return $detail;
		}
		return '';
	}

	/**
	 * Return a safe activation failure notice.
	 *
	 * The classification stays as a fixed code people can pass on to support.
	 *
	 * @param Throwable $throwable Failure.
	 * @return string
	 */
	public static function activation_message( Throwable $throwable ): string {
		return sprintf(
			/* translators: %s: fixed error code such as storage_error. */
			__( 'PressQueue could not finish setting up, so it was not activated. Try activating it again. If it still fails, contact PressQueue support and mention the error code %s.', 'pressqueue' ),
			self::classification( $throwable )
		);
	}

	/**
	 * Explain a failure classification in plain words.
	 *
	 * @param string $classification Classification code.
	 * @return string
	 */
	private static function classification_text( string $classification ): string {
		switch ( $classification ) {
			case 'stale_record':
				return __( 'Something changed in another window. Reload the page and try again.', 'pressqueue' );
			case 'storage_unavailable':
				return __( 'PressQueue could not reach its data. Reload the page in a minute and try again.', 'pressqueue' );
			case 'storage_error':
				return __( 'PressQueue could not save the change. Try again in a minute.', 'pressqueue' );
			case 'validation_failed':
			case 'operator_error':
				return __( 'Reload the page and try again.', 'pressqueue' );
		}

		return '';
	}

	/**
	 * Return the exception message when it is on the public allowlist.
	 *
	 * @param Throwable $throwable Failure.
	 * @return string|null
	 */
	private static function allowed_message( Throwable $throwable ): ?string {
		if ( $throwable instanceof ValidationException || $throwable instanceof StaleRecordException ) {
			$message = trim( $throwable->getMessage() );
			if ( '' !== $message && self::is_allowed_message( $message ) ) {
				return $message;
			}
		}

		return null;
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
