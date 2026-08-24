<?php
/**
 * ULID public ID generator.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Generates uppercase Crockford Base32 ULIDs.
 */
final class UlidPublicIdGenerator implements PublicIdGenerator {
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Generate one public ID.
	 *
	 * @return string Uppercase ULID.
	 */
	public function generate(): string {
		$time       = (int) floor( microtime( true ) * 1000 );
		$timestamp  = '';
		$randomness = $this->encode_randomness( random_bytes( 10 ) );

		for ( $index = 0; $index < 10; ++$index ) {
			$timestamp = self::ALPHABET[ $time % 32 ] . $timestamp;
			$time      = intdiv( $time, 32 );
		}

		return $timestamp . $randomness;
	}

	/**
	 * Encode 80 random bits as 16 Crockford Base32 characters.
	 *
	 * @param string $bytes Ten random bytes.
	 * @return string
	 */
	private function encode_randomness( string $bytes ): string {
		$output = '';
		$buffer = 0;
		$bits   = 0;
		$length = strlen( $bytes );

		for ( $index = 0; $index < $length; ++$index ) {
			$buffer = ( $buffer << 8 ) | ord( $bytes[ $index ] );
			$bits  += 8;

			while ( $bits >= 5 ) {
				$bits   -= 5;
				$output .= self::ALPHABET[ ( $buffer >> $bits ) & 31 ];
			}
		}

		return $output;
	}
}
