<?php
/**
 * Public ID helpers.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Validates public storage identifiers.
 */
final class PublicIds {
	private const PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

	/**
	 * Check whether a value is an uppercase ULID.
	 *
	 * @param string $value Candidate value.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( self::PATTERN, $value );
	}

	/**
	 * Assert that a value is an uppercase ULID.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert( string $value ): void {
		if ( ! self::is_valid( $value ) ) {
			throw new ValidationException( 'Public IDs must be uppercase 26 character ULIDs.' );
		}
	}
}
