<?php
/**
 * Delivery retry policy.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Provider\Dto\ProviderDeliveryResult;
use PressQueue\Storage\Record\DeliveryRecord;

/**
 * Calculates bounded automatic retries.
 */
final class RetryPolicy {
	public const AUTOMATIC_ATTEMPT_LIMIT = 5;
	private const INITIAL_DELAY_SECONDS  = 60;
	private const MAX_DELAY_SECONDS      = 21600;
	private const MAX_RATE_LIMIT_SECONDS = 2592000;
	private const JITTER_RATIO           = 0.2;

	/**
	 * Optional jitter provider.
	 *
	 * @var callable|null
	 */
	private $jitter_provider;

	/**
	 * Constructor.
	 *
	 * @param callable|null $jitter_provider Optional deterministic jitter provider.
	 */
	public function __construct( ?callable $jitter_provider = null ) {
		$this->jitter_provider = $jitter_provider;
	}

	/**
	 * Check whether another automatic provider submission is allowed.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return bool
	 */
	public function has_automatic_budget( DeliveryRecord $delivery ): bool {
		return $delivery->automatic_attempt_count < self::AUTOMATIC_ATTEMPT_LIMIT;
	}

	/**
	 * Return the next automatic retry timestamp, if allowed.
	 *
	 * @param DeliveryRecord         $delivery Delivery after submission_started.
	 * @param ProviderDeliveryResult $result Provider result.
	 * @return string|null UTC DATETIME.
	 */
	public function next_attempt_at( DeliveryRecord $delivery, ProviderDeliveryResult $result ): ?string {
		if ( ! in_array( $result->provider_result, array( 'retryable_failure', 'rate_limited' ), true ) || ! $this->has_automatic_budget( $delivery ) ) {
			return null;
		}

		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		if ( 'rate_limited' === $result->provider_result && null !== $result->retry_after_utc ) {
			$delay = $result->retry_after_utc->getTimestamp() - $now->getTimestamp();
			if ( $delay >= self::INITIAL_DELAY_SECONDS && $delay <= self::MAX_RATE_LIMIT_SECONDS ) {
				return $result->retry_after_utc->format( 'Y-m-d H:i:s' );
			}
		}

		$base_delay = min(
			self::MAX_DELAY_SECONDS,
			self::INITIAL_DELAY_SECONDS * ( 2 ** max( 0, $delivery->automatic_attempt_count - 1 ) )
		);
		$jitter     = $this->jitter_seconds( $delivery, $result, $base_delay );
		$delay      = max( 1, $base_delay + $jitter );

		return $now->modify( '+' . $delay . ' seconds' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Calculate bounded jitter.
	 *
	 * @param DeliveryRecord         $delivery Delivery.
	 * @param ProviderDeliveryResult $result Provider result.
	 * @param int                    $base_delay Base delay.
	 * @return int Jitter seconds.
	 */
	private function jitter_seconds( DeliveryRecord $delivery, ProviderDeliveryResult $result, int $base_delay ): int {
		$maximum = (int) floor( $base_delay * self::JITTER_RATIO );

		if ( 0 === $maximum ) {
			return 0;
		}

		if ( null !== $this->jitter_provider ) {
			$value = (int) call_user_func( $this->jitter_provider, $delivery, $result, $base_delay );

			return max( -$maximum, min( $maximum, $value ) );
		}

		$hash   = hash( 'sha256', $delivery->delivery_key . '|' . (string) $delivery->automatic_attempt_count . '|' . $result->provider_result );
		$bucket = hexdec( substr( $hash, 0, 8 ) ) % ( ( $maximum * 2 ) + 1 );

		return $bucket - $maximum;
	}
}
