<?php
/**
 * Public ID generator contract.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Generates storage public IDs.
 */
interface PublicIdGenerator {
	/**
	 * Generate one public ID.
	 *
	 * @return string Uppercase ULID.
	 */
	public function generate(): string;
}
