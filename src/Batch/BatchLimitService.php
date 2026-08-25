<?php
/**
 * Batch limit and schedule validation.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Normalizes bounded batch limits and schedule overrides.
 */
final class BatchLimitService {
	public const DEFAULT_CANDIDATE_LIMIT      = 20;
	public const MAX_CANDIDATE_LIMIT          = 100;
	public const MAX_DESTINATION_ITEMS        = 100;
	public const PREVIEW_TTL_MINUTES          = 30;
	public const QUEUE_CHUNK_SIZE             = 10;
	public const MIN_STAGGER_INTERVAL_MINUTES = 5;
	public const MAX_STAGGER_INTERVAL_MINUTES = 1440;
	public const MAX_EXPLICIT_HORIZON_DAYS    = 365;

	/**
	 * Normalize a batch limit document.
	 *
	 * @param array<string,mixed> $limits Raw limits.
	 * @return array<string,mixed>
	 */
	public function normalize( array $limits ): array {
		$this->assert_known_keys( $limits, array( 'schema_version', 'candidate_limit', 'destination_item_limit', 'schedule' ) );

		$schema_version = (int) ( $limits['schema_version'] ?? 1 );
		if ( 1 !== $schema_version ) {
			throw new ValidationException( 'Batch limits schema version is unsupported.' );
		}

		$candidate_limit = (int) ( $limits['candidate_limit'] ?? self::DEFAULT_CANDIDATE_LIMIT );
		if ( $candidate_limit < 1 || $candidate_limit > self::MAX_CANDIDATE_LIMIT ) {
			throw new ValidationException( 'Batch candidate limit is out of range.' );
		}

		$destination_item_limit = (int) ( $limits['destination_item_limit'] ?? self::MAX_DESTINATION_ITEMS );
		if ( $destination_item_limit < 1 || $destination_item_limit > self::MAX_DESTINATION_ITEMS ) {
			throw new ValidationException( 'Batch destination item limit is out of range.' );
		}

		$schedule = $limits['schedule'] ?? array(
			'mode'           => 'inherit',
			'schema_version' => 1,
		);
		if ( ! is_array( $schedule ) || array_is_list( $schedule ) ) {
			throw new ValidationException( 'Batch schedule limits must be an object.' );
		}

		return array(
			'candidate_limit'        => $candidate_limit,
			'destination_item_limit' => $destination_item_limit,
			'schema_version'         => 1,
			'schedule'               => $this->normalize_schedule( $schedule ),
		);
	}

	/**
	 * Return the default preview expiry timestamp.
	 *
	 * @return string
	 */
	public function preview_expires_at(): string {
		return gmdate( 'Y-m-d H:i:s', time() + ( self::PREVIEW_TTL_MINUTES * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Return a stable hash for the queueable item order.
	 *
	 * @param array<int,array<string,mixed>> $items Item summaries.
	 * @return string
	 */
	public function item_order_hash( array $items ): string {
		$order = array();

		foreach ( $items as $item ) {
			$order[] = array(
				'channel_public_id'     => $item['channel_public_id'] ?? 'global',
				'post_id'               => (int) ( $item['post_id'] ?? 0 ),
				'semantic_preview_hash' => (string) ( $item['semantic_preview_hash'] ?? '' ),
			);
		}

		return CanonicalJson::hash( 'batch_item_order', array( 'items' => $order ) );
	}

	/**
	 * Refuse summaries that cannot be confirmed.
	 *
	 * @param array<string,mixed> $summary Preview summary.
	 */
	public function assert_confirmable_summary( array $summary ): void {
		if ( true === ( $summary['truncated'] ?? false ) ) {
			throw new ValidationException( 'Batch preview is truncated. Narrow the filters before confirming.' );
		}

		if ( true === ( $summary['invalid_multi_item_schedule'] ?? false ) ) {
			throw new ValidationException( 'Multi-item batches cannot use immediate or queue-next scheduling.' );
		}

		$counts    = isset( $summary['counts'] ) && is_array( $summary['counts'] ) ? $summary['counts'] : array();
		$queueable = (int) ( $counts['queueable'] ?? 0 );
		if ( $queueable > self::MAX_DESTINATION_ITEMS ) {
			throw new ValidationException( 'Batch contains too many destination items.' );
		}
	}

	/**
	 * Return schedule override facts for a queueable item.
	 *
	 * @param array<string,mixed> $limits Normalized limits.
	 * @param int                 $queueable_index Zero-based queueable item index.
	 * @param int                 $queueable_count Queueable item count.
	 * @return array<string,mixed>|null
	 */
	public function schedule_override_for_item( array $limits, int $queueable_index, int $queueable_count ): ?array {
		$schedule = $limits['schedule'] ?? array();
		if ( ! is_array( $schedule ) ) {
			return null;
		}

		$mode = (string) ( $schedule['mode'] ?? 'inherit' );
		if ( 'inherit' === $mode ) {
			return null;
		}

		if ( $queueable_count > 1 && in_array( $mode, array( 'immediate', 'queue_next' ), true ) ) {
			return array(
				'error' => 'batch_multi_item_schedule_not_allowed',
				'mode'  => $mode,
			);
		}

		if ( in_array( $mode, array( 'immediate', 'queue_next', 'queue_end' ), true ) ) {
			return array(
				'schedule_slot'     => $mode,
				'scheduled_for_utc' => null,
				'schedule_timezone' => null,
			);
		}

		if ( 'staggered_explicit_time' !== $mode ) {
			return null;
		}

		$first = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $schedule['first_due_at_utc'], new DateTimeZone( 'UTC' ) );
		if ( false === $first ) {
			throw new ValidationException( 'Staggered schedule start is invalid.' );
		}

		$due = $first->add( new DateInterval( 'PT' . ( (int) $schedule['interval_minutes'] * $queueable_index ) . 'M' ) );
		$now = time();
		if ( $due->getTimestamp() <= $now ) {
			throw new ValidationException( 'Staggered schedule cannot be in the past.' );
		}

		if ( $due->getTimestamp() > $now + ( self::MAX_EXPLICIT_HORIZON_DAYS * DAY_IN_SECONDS ) ) {
			throw new ValidationException( 'Staggered schedule exceeds the supported horizon.' );
		}

		return array(
			'schedule_slot'     => 'explicit_time',
			'scheduled_for_utc' => $due->format( 'Y-m-d H:i:s' ),
			'schedule_timezone' => (string) $schedule['timezone'],
		);
	}

	/**
	 * Normalize the schedule limit document.
	 *
	 * @param array<string,mixed> $schedule Raw schedule.
	 * @return array<string,mixed>
	 */
	private function normalize_schedule( array $schedule ): array {
		$this->assert_known_keys( $schedule, array( 'schema_version', 'mode', 'first_due_at_utc', 'interval_minutes', 'timezone', 'item_order_hash' ) );

		$schema_version = (int) ( $schedule['schema_version'] ?? 1 );
		if ( 1 !== $schema_version ) {
			throw new ValidationException( 'Batch schedule schema version is unsupported.' );
		}

		$mode = (string) ( $schedule['mode'] ?? 'inherit' );
		if ( ! in_array( $mode, array( 'inherit', 'immediate', 'queue_next', 'queue_end', 'staggered_explicit_time' ), true ) ) {
			throw new ValidationException( 'Batch schedule mode is unsupported.' );
		}

		if ( 'staggered_explicit_time' !== $mode ) {
			return array(
				'mode'           => $mode,
				'schema_version' => 1,
			);
		}

		if ( ! isset( $schedule['first_due_at_utc'], $schedule['interval_minutes'], $schedule['timezone'] ) ) {
			throw new ValidationException( 'Staggered schedule is incomplete.' );
		}

		$first_due_at_utc = (string) $schedule['first_due_at_utc'];
		StorageValidator::assert_utc_timestamp( 'First due UTC time', $first_due_at_utc );

		$first = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $first_due_at_utc, new DateTimeZone( 'UTC' ) );
		if ( false === $first ) {
			throw new ValidationException( 'Staggered schedule start is invalid.' );
		}

		$now = time();
		if ( $first->getTimestamp() <= $now ) {
			throw new ValidationException( 'Staggered schedule cannot be in the past.' );
		}

		if ( $first->getTimestamp() > $now + ( self::MAX_EXPLICIT_HORIZON_DAYS * DAY_IN_SECONDS ) ) {
			throw new ValidationException( 'Staggered schedule exceeds the supported horizon.' );
		}

		$interval = (int) $schedule['interval_minutes'];
		if ( $interval < self::MIN_STAGGER_INTERVAL_MINUTES || $interval > self::MAX_STAGGER_INTERVAL_MINUTES ) {
			throw new ValidationException( 'Staggered schedule interval is out of range.' );
		}

		$timezone = trim( (string) $schedule['timezone'] );
		if ( '' === $timezone || strlen( $timezone ) > 64 ) {
			throw new ValidationException( 'Staggered schedule timezone is invalid.' );
		}
		try {
			new DateTimeZone( $timezone );
		} catch ( Exception $exception ) {
			unset( $exception );
			throw new ValidationException( 'Staggered schedule timezone is invalid.' );
		}

		$normalized = array(
			'first_due_at_utc' => $first_due_at_utc,
			'interval_minutes' => $interval,
			'mode'             => 'staggered_explicit_time',
			'schema_version'   => 1,
			'timezone'         => $timezone,
		);

		if ( isset( $schedule['item_order_hash'] ) && is_string( $schedule['item_order_hash'] ) && '' !== $schedule['item_order_hash'] ) {
			StorageValidator::assert_hash( $schedule['item_order_hash'] );
			$normalized['item_order_hash'] = $schedule['item_order_hash'];
		}

		return $normalized;
	}

	/**
	 * Refuse unknown object keys.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param array<int,string>   $known Known keys.
	 */
	private function assert_known_keys( array $document, array $known ): void {
		$allowed = array_fill_keys( $known, true );
		foreach ( $document as $key => $_value ) {
			if ( ! isset( $allowed[ (string) $key ] ) ) {
				throw new ValidationException( 'Batch JSON contains an unsupported key.' );
			}
		}
	}
}
