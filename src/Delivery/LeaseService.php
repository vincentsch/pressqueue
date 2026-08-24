<?php
/**
 * Delivery lease service.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

/**
 * Creates worker lease ownership values.
 */
final class LeaseService {
	public const TTL_SECONDS = 120;

	/**
	 * Create a random lease owner token.
	 *
	 * @return string
	 */
	public function owner(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Return the UTC expiry timestamp for a new lease.
	 *
	 * @return string
	 */
	public function expires_at(): string {
		return gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS );
	}
}
