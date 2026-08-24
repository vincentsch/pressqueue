<?php
/**
 * Common storage validators.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Validates scalar values before persistence.
 */
final class StorageValidator {
	private const HASH_PATTERN      = '/^[a-f0-9]{64}$/';
	private const TEXT_KEY_PATTERN  = '/^[A-Za-z0-9_.:-]+$/';
	private const FIELD_KEY_PATTERN = '/^[A-Za-z0-9_.:-]{1,191}$/';

	/**
	 * Assert a lowercase SHA-256 hex value.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_hash( string $value ): void {
		if ( 1 !== preg_match( self::HASH_PATTERN, $value ) ) {
			throw new ValidationException( 'Hash values must be lowercase SHA-256 hex strings.' );
		}
	}

	/**
	 * Assert a provider or service key.
	 *
	 * @param string $name Value name.
	 * @param string $value Candidate value.
	 * @param int    $maximum_length Maximum length.
	 */
	public static function assert_text_key( string $name, string $value, int $maximum_length = 64 ): void {
		unset( $name );

		if ( '' === $value || strlen( $value ) > $maximum_length || 1 !== preg_match( self::TEXT_KEY_PATTERN, $value ) ) {
			throw new ValidationException( 'Text key contains invalid characters.' );
		}
	}

	/**
	 * Assert a custom field key.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_field_key( string $value ): void {
		if ( 1 !== preg_match( self::FIELD_KEY_PATTERN, $value ) ) {
			throw new ValidationException( 'Custom field allowlist contains an invalid key.' );
		}
	}

	/**
	 * Assert a non-empty bounded label.
	 *
	 * @param string $name Value name.
	 * @param string $value Candidate value.
	 * @param int    $maximum_length Maximum length.
	 */
	public static function assert_label( string $name, string $value, int $maximum_length = 191 ): void {
		unset( $name );

		if ( '' === trim( $value ) || strlen( $value ) > $maximum_length ) {
			throw new ValidationException( 'Label must be a non-empty bounded string.' );
		}
	}

	/**
	 * Assert an opaque provider identifier.
	 *
	 * @param string $name Value name.
	 * @param string $value Candidate value.
	 * @param int    $maximum_length Maximum length.
	 */
	public static function assert_remote_identifier( string $name, string $value, int $maximum_length = 191 ): void {
		unset( $name );

		if ( '' === trim( $value ) || strlen( $value ) > $maximum_length || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new ValidationException( 'Remote identifier must be a bounded printable string.' );
		}
	}

	/**
	 * Assert a sync lease owner token.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_lease_owner( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $value ) ) {
			throw new ValidationException( 'Lease owner must be a 16 byte lowercase hex token.' );
		}
	}

	/**
	 * Assert a non-negative internal ID.
	 *
	 * @param string $name Value name.
	 * @param int    $value Candidate value.
	 * @param bool   $allow_zero Whether zero is allowed.
	 */
	public static function assert_unsigned_id( string $name, int $value, bool $allow_zero = false ): void {
		unset( $name );

		if ( $allow_zero ? $value < 0 : $value <= 0 ) {
			throw new ValidationException( 'ID must be an unsigned database value.' );
		}
	}

	/**
	 * Assert a WordPress post type or status.
	 *
	 * @param string $name Value name.
	 * @param string $value Candidate value.
	 */
	public static function assert_wordpress_slug( string $name, string $value ): void {
		unset( $name );

		if ( '' === $value || strlen( $value ) > 20 || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			throw new ValidationException( 'WordPress slug is not supported.' );
		}
	}

	/**
	 * Assert a UTC timestamp string.
	 *
	 * @param string $name Value name.
	 * @param string $value Candidate value.
	 */
	public static function assert_utc_timestamp( string $name, string $value ): void {
		unset( $name );

		if ( false === DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) ) ) {
			throw new ValidationException( 'Timestamp must be a UTC DATETIME value.' );
		}
	}

	/**
	 * Return the current UTC DATETIME value.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Parse a UTC DATETIME storage value.
	 *
	 * @param string|null $value Stored timestamp.
	 * @return DateTimeImmutable|null
	 */
	public static function parse_date_time( ?string $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );

		if ( false === $datetime ) {
			throw new ValidationException( 'Stored timestamp is not a UTC DATETIME value.' );
		}

		return $datetime;
	}

	/**
	 * Parse a required UTC DATETIME storage value.
	 *
	 * @param string $value Stored timestamp.
	 * @return DateTimeImmutable
	 */
	public static function parse_required_date_time( string $value ): DateTimeImmutable {
		$datetime = self::parse_date_time( $value );

		if ( null === $datetime ) {
			throw new ValidationException( 'Required timestamp is empty.' );
		}

		return $datetime;
	}

	/**
	 * Normalize a scalar string list.
	 *
	 * @param array<int,mixed> $values Candidate values.
	 * @return array<int,string>
	 */
	public static function normalize_string_list( array $values ): array {
		$normalized = array();

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				throw new ValidationException( 'String lists may contain only non-empty strings.' );
			}

			$normalized[] = $value;
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_STRING );

		return $normalized;
	}

	/**
	 * Validate condition result values wherever a document names them.
	 *
	 * @param mixed $value Document value.
	 */
	public static function assert_nested_condition_results( $value ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( 'condition_result' === $key ) {
					if ( ! is_string( $item ) ) {
						throw new ValidationException( 'Condition result must be a supported string value.' );
					}

					StorageEnums::assert_condition_result( $item );
				}

				self::assert_nested_condition_results( $item );
			}
		}
	}
}
