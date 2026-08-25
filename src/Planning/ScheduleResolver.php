<?php
/**
 * Schedule resolution.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\RuleDocuments;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * Resolves provider-neutral schedule controls.
 */
final class ScheduleResolver {
	/**
	 * Current UTC clock.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable|null $now Current UTC clock.
	 */
	public function __construct( ?DateTimeImmutable $now = null ) {
		$this->now = ( $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Resolve a rule and post-control schedule.
	 *
	 * @param RuleRecord          $rule Rule.
	 * @param array<string,mixed> $control_bundle Control bundle.
	 * @return SchedulePolicy
	 */
	public function resolve( RuleRecord $rule, array $control_bundle ): SchedulePolicy {
		$control = isset( $control_bundle['schedule'] ) && is_array( $control_bundle['schedule'] )
			? $control_bundle['schedule']
			: array( 'mode' => 'inherit' );

		$mode = isset( $control['mode'] ) && is_string( $control['mode'] ) ? $control['mode'] : 'inherit';
		if ( 'inherit' === $mode ) {
			$policy = RuleDocuments::schedule_policy( $rule->schedule_policy_document );
			$mode   = (string) $policy['mode'];

			return new SchedulePolicy(
				$mode,
				null,
				null,
				array(
					'kind'   => 'rule',
					'policy' => $policy,
				)
			);
		}

		StorageEnums::assert_schedule_slot( $mode );

		if ( 'explicit_time' !== $mode ) {
			return new SchedulePolicy(
				$mode,
				null,
				null,
				array(
					'kind'    => 'post_control',
					'control' => $control,
				)
			);
		}

		return $this->explicit_time( $control );
	}

	/**
	 * Resolve an explicit local time.
	 *
	 * @param array<string,mixed> $control Schedule control.
	 * @return SchedulePolicy
	 */
	private function explicit_time( array $control ): SchedulePolicy {
		foreach ( array( 'local_date', 'local_time', 'timezone' ) as $key ) {
			if ( ! isset( $control[ $key ] ) || ! is_string( $control[ $key ] ) ) {
				throw new ValidationException( 'Explicit schedule is incomplete.' );
			}
		}

		$timezone = new DateTimeZone( $control['timezone'] );
		$local    = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $control['local_date'] . ' ' . $control['local_time'] . ':00', $timezone );

		if ( false === $local || $local->format( 'Y-m-d H:i' ) !== $control['local_date'] . ' ' . $control['local_time'] ) {
			throw new ValidationException( 'Explicit schedule local time does not exist.' );
		}

		$offsets = $this->possible_offsets( $control['local_date'], $control['local_time'], $timezone );
		$offset  = isset( $control['utc_offset'] ) && is_string( $control['utc_offset'] ) ? $control['utc_offset'] : null;

		if ( count( $offsets ) > 1 ) {
			if ( null === $offset || ! in_array( $offset, $offsets, true ) ) {
				throw new ValidationException( 'Explicit schedule needs the selected UTC offset.' );
			}

			$utc = $this->utc_from_local_and_offset( $control['local_date'], $control['local_time'], $offset );
		} else {
			if ( null !== $offset ) {
				throw new ValidationException( 'Explicit schedule UTC offset is only allowed for ambiguous times.' );
			}

			$utc = $local->setTimezone( new DateTimeZone( 'UTC' ) );
		}

		if ( $utc->getTimestamp() <= $this->now->getTimestamp() ) {
			throw new ValidationException( 'Explicit schedule cannot be in the past.' );
		}

		if ( $utc->getTimestamp() > $this->now->modify( '+365 days' )->getTimestamp() ) {
			throw new ValidationException( 'Explicit schedule exceeds the allowed horizon.' );
		}

		return new SchedulePolicy(
			'explicit_time',
			$utc,
			$control['timezone'],
			array(
				'control' => $control,
				'kind'    => 'post_control',
			)
		);
	}

	/**
	 * Return possible offsets for a wall time.
	 *
	 * @param string       $date Local date.
	 * @param string       $time Local time.
	 * @param DateTimeZone $timezone Timezone.
	 * @return array<int,string>
	 */
	private function possible_offsets( string $date, string $time, DateTimeZone $timezone ): array {
		$local_timestamp = strtotime( $date . ' ' . $time . ':00 UTC' );
		if ( false === $local_timestamp ) {
			throw new ValidationException( 'Explicit schedule local time is invalid.' );
		}

		$transitions = $timezone->getTransitions( $local_timestamp - 86400, $local_timestamp + 86400 );
		$offsets     = array();

		foreach ( $transitions as $transition ) {
			if ( ! isset( $transition['offset'] ) ) {
				continue;
			}

			$offset = $this->format_offset( (int) $transition['offset'] );
			$utc    = $this->utc_from_local_and_offset( $date, $time, $offset );
			if ( $utc->setTimezone( $timezone )->format( 'Y-m-d H:i' ) === $date . ' ' . $time ) {
				$offsets[] = $offset;
			}
		}

		if ( array() === $offsets ) {
			$probe     = new DateTimeImmutable( $date . ' ' . $time . ':00', $timezone );
			$offsets[] = $this->format_offset( $probe->getOffset() );
		}

		$offsets = array_values( array_unique( $offsets ) );
		sort( $offsets, SORT_STRING );

		return $offsets;
	}

	/**
	 * Convert local time and explicit offset to UTC.
	 *
	 * @param string $date Local date.
	 * @param string $time Local time.
	 * @param string $offset Offset.
	 * @return DateTimeImmutable
	 */
	private function utc_from_local_and_offset( string $date, string $time, string $offset ): DateTimeImmutable {
		$timestamp = strtotime( $date . ' ' . $time . ':00 UTC' );
		if ( false === $timestamp ) {
			throw new ValidationException( 'Explicit schedule local time is invalid.' );
		}

		return ( new DateTimeImmutable( '@' . ( $timestamp - $this->offset_seconds( $offset ) ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Format offset seconds as +HH:MM or -HH:MM.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private function format_offset( int $seconds ): string {
		$sign    = $seconds < 0 ? '-' : '+';
		$seconds = abs( $seconds );

		return sprintf( '%s%02d:%02d', $sign, intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
	}

	/**
	 * Parse offset seconds.
	 *
	 * @param string $offset Offset.
	 * @return int
	 */
	private function offset_seconds( string $offset ): int {
		if ( 1 !== preg_match( '/^([+-])(\d{2}):(\d{2})$/', $offset, $matches ) ) {
			throw new ValidationException( 'Explicit schedule UTC offset is invalid.' );
		}

		$seconds = ( (int) $matches[2] * 3600 ) + ( (int) $matches[3] * 60 );

		return '-' === $matches[1] ? -$seconds : $seconds;
	}
}
