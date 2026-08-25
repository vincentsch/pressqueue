<?php
/**
 * Export cursor value object.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Privacy;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\ValidationException;

/**
 * Encodes and decodes full-site export cursors.
 */
final class ExportCursor {
	/**
	 * Table suffix.
	 *
	 * @var string
	 */
	public string $table;

	/**
	 * Last UTC timestamp.
	 *
	 * @var string
	 */
	public string $timestamp_utc;

	/**
	 * Last public ID.
	 *
	 * @var string
	 */
	public string $public_id;

	/**
	 * Constructor.
	 *
	 * @param string $table Table suffix.
	 * @param string $timestamp_utc Timestamp.
	 * @param string $public_id Public ID.
	 */
	public function __construct( string $table, string $timestamp_utc, string $public_id ) {
		$this->table         = $table;
		$this->timestamp_utc = self::mysql_timestamp( $timestamp_utc );
		$this->public_id     = $public_id;
	}

	/**
	 * Parse a cursor string.
	 *
	 * @param string|null $cursor Cursor.
	 * @return self|null
	 */
	public static function from_string( ?string $cursor ): ?self {
		if ( null === $cursor || '' === $cursor ) {
			return null;
		}

		if ( str_starts_with( trim( $cursor ), '{' ) ) {
			$decoded = $cursor;
		} else {
			$encoded  = strtr( $cursor, '-_', '+/' );
			$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Cursors are URL-safe encoded JSON, not executable code.
			$decoded = base64_decode( $encoded, true );
			if ( ! is_string( $decoded ) ) {
				throw new ValidationException( 'Export cursor is invalid.' );
			}
		}

		$value = CanonicalJson::decode_object( $decoded );

		if (
			! isset( $value['table'], $value['public_id'] )
			|| ! is_string( $value['table'] )
			|| ! is_string( $value['public_id'] )
		) {
			throw new ValidationException( 'Export cursor is invalid.' );
		}

		$timestamp = $value['timestamp_utc'] ?? $value['timestamp'] ?? null;
		if ( ! is_string( $timestamp ) || '' === $timestamp ) {
			throw new ValidationException( 'Export cursor is invalid.' );
		}

		return new self( $value['table'], $timestamp, $value['public_id'] );
	}

	/**
	 * Convert the cursor to an encoded string.
	 *
	 * @return string
	 */
	public function to_string(): string {
		$json = CanonicalJson::encode_object(
			array(
				'public_id'     => $this->public_id,
				'table'         => $this->table,
				'timestamp_utc' => self::rfc3339( $this->timestamp_utc ),
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Cursors are URL-safe encoded JSON, not executable code.
		return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	}

	/**
	 * Return a public representation.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'public_id'     => $this->public_id,
			'table'         => $this->table,
			'timestamp_utc' => self::rfc3339( $this->timestamp_utc ),
		);
	}

	/**
	 * Normalize a UTC timestamp to the storage query format.
	 *
	 * @param string $timestamp Timestamp.
	 * @return string
	 */
	private static function mysql_timestamp( string $timestamp ): string {
		$parsed = 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $timestamp )
			? strtotime( $timestamp . ' UTC' )
			: strtotime( $timestamp );

		if ( false !== $parsed ) {
			return gmdate( 'Y-m-d H:i:s', $parsed );
		}

		return $timestamp;
	}

	/**
	 * Format a UTC timestamp for the public cursor object.
	 *
	 * @param string $timestamp Timestamp.
	 * @return string
	 */
	private static function rfc3339( string $timestamp ): string {
		$parsed = strtotime( $timestamp . ' UTC' );

		if ( false === $parsed ) {
			return $timestamp;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', $parsed );
	}
}
