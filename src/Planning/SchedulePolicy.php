<?php
/**
 * Resolved schedule policy.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use DateTimeImmutable;

/**
 * Immutable resolved schedule facts for one destination.
 */
final class SchedulePolicy {
	/**
	 * Provider-neutral schedule slot.
	 *
	 * @var string
	 */
	public string $slot;

	/**
	 * Explicit scheduled UTC instant.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $scheduled_for_utc;

	/**
	 * Source timezone label.
	 *
	 * @var string|null
	 */
	public ?string $timezone;

	/**
	 * Source schedule facts.
	 *
	 * @var array<string,mixed>
	 */
	public array $source;

	/**
	 * Constructor.
	 *
	 * @param string                 $slot Provider-neutral schedule slot.
	 * @param DateTimeImmutable|null $scheduled_for_utc Explicit due time.
	 * @param string|null            $timezone Source timezone.
	 * @param array<string,mixed>    $source Source facts.
	 */
	public function __construct( string $slot, ?DateTimeImmutable $scheduled_for_utc = null, ?string $timezone = null, array $source = array() ) {
		$this->slot              = $slot;
		$this->scheduled_for_utc = $scheduled_for_utc;
		$this->timezone          = $timezone;
		$this->source            = $source;
	}

	/**
	 * Return storage datetime.
	 *
	 * @return string|null
	 */
	public function scheduled_for_storage(): ?string {
		return null === $this->scheduled_for_utc ? null : $this->scheduled_for_utc->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Return ISO 8601 UTC with milliseconds for Buffer.
	 *
	 * @return string|null
	 */
	public function scheduled_for_provider(): ?string {
		return null === $this->scheduled_for_utc ? null : $this->scheduled_for_utc->format( 'Y-m-d\TH:i:s.000\Z' );
	}

	/**
	 * Return payload document fields.
	 *
	 * @return array<string,mixed>
	 */
	public function to_payload(): array {
		return array(
			'schedule_slot'     => $this->slot,
			'scheduled_for_utc' => $this->scheduled_for_storage(),
			'schedule_timezone' => $this->timezone,
			'source'            => $this->source,
		);
	}
}
