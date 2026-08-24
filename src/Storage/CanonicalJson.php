<?php
/**
 * Canonical JSON and hash helpers.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use JsonException;
use stdClass;

/**
 * Encodes deterministic JSON documents and SHA-256 hashes.
 */
final class CanonicalJson {
	private const HASH_SCHEMA_VERSION = 1;
	private const JSON_FLAGS          = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

	/**
	 * Encode a JSON value with deterministic object key ordering.
	 *
	 * Empty PHP lists are encoded as [].
	 *
	 * @param mixed $value JSON-compatible value.
	 * @return string
	 */
	public static function encode( $value ): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical storage needs JSON_THROW_ON_ERROR.
			return json_encode( self::normalize( $value ), self::JSON_FLAGS );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception causes are not rendered directly.
			throw new ValidationException( 'JSON document could not be encoded canonically.', 0, $exception );
		}
	}

	/**
	 * Encode an object document with {} for an empty object.
	 *
	 * @param array<string,mixed> $value Object data.
	 * @return string
	 */
	public static function encode_object( array $value ): string {
		if ( array() === $value ) {
			return '{}';
		}

		return self::encode( $value );
	}

	/**
	 * Encode a list document with [] for an empty list.
	 *
	 * @param array<int,mixed> $value List data.
	 * @return string
	 */
	public static function encode_list( array $value ): string {
		return self::encode( array_values( $value ) );
	}

	/**
	 * Decode a JSON object document.
	 *
	 * @param string $json JSON document.
	 * @return array<string,mixed>
	 */
	public static function decode_object( string $json ): array {
		if ( ! self::has_root_token( $json, '{' ) ) {
			throw new ValidationException( 'JSON document must be an object.' );
		}

		$value = self::decode( $json );

		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new ValidationException( 'JSON document must be an object.' );
		}

		return $value;
	}

	/**
	 * Decode a JSON list document.
	 *
	 * @param string $json JSON document.
	 * @return array<int,mixed>
	 */
	public static function decode_list( string $json ): array {
		if ( ! self::has_root_token( $json, '[' ) ) {
			throw new ValidationException( 'JSON document must be a list.' );
		}

		$value = self::decode( $json );

		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new ValidationException( 'JSON document must be a list.' );
		}

		return $value;
	}

	/**
	 * Hash canonical data with the project hash envelope.
	 *
	 * @param string              $kind Hash kind.
	 * @param array<string,mixed> $data Hash data.
	 * @return string Lowercase SHA-256 hex output.
	 */
	public static function hash( string $kind, array $data ): string {
		if ( 1 !== preg_match( '/^[a-z0-9_.-]+$/', $kind ) ) {
			throw new ValidationException( 'Hash kind contains invalid characters.' );
		}

		return hash(
			'sha256',
			self::encode_object(
				array(
					'hash_schema_version' => self::HASH_SCHEMA_VERSION,
					'kind'                => $kind,
					'data'                => $data,
				)
			)
		);
	}

	/**
	 * Assert that encoded JSON stays within the expected size.
	 *
	 * @param string $json JSON document.
	 * @param int    $maximum_bytes Maximum byte length.
	 */
	public static function assert_maximum_size( string $json, int $maximum_bytes ): void {
		if ( strlen( $json ) > $maximum_bytes ) {
			throw new ValidationException( 'JSON document exceeds the allowed storage size.' );
		}
	}

	/**
	 * Decode any JSON value.
	 *
	 * @param string $json JSON document.
	 * @return mixed
	 */
	private static function decode( string $json ) {
		try {
			return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception causes are not rendered directly.
			throw new ValidationException( 'JSON document could not be decoded.', 0, $exception );
		}
	}

	/**
	 * Check the first non-whitespace character in a JSON document.
	 *
	 * @param string $json JSON document.
	 * @param string $token Expected root token.
	 * @return bool
	 */
	private static function has_root_token( string $json, string $token ): bool {
		$trimmed = ltrim( $json );

		return '' !== $trimmed && $token === $trimmed[0];
	}

	/**
	 * Normalize values for deterministic JSON encoding.
	 *
	 * @param mixed $value JSON-compatible value.
	 * @return mixed
	 */
	private static function normalize( $value ) {
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				return array_map( array( self::class, 'normalize' ), $value );
			}

			ksort( $value, SORT_STRING );

			foreach ( $value as $key => $item ) {
				if ( ! is_string( $key ) ) {
					throw new ValidationException( 'JSON object keys must be strings.' );
				}

				$value[ $key ] = self::normalize( $item );
			}

			return $value;
		}

		if ( $value instanceof stdClass ) {
			$properties = get_object_vars( $value );
			ksort( $properties, SORT_STRING );

			$object = new stdClass();
			foreach ( $properties as $key => $item ) {
				$object->{$key} = self::normalize( $item );
			}

			return $object;
		}

		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
			return $value;
		}

		throw new ValidationException( 'JSON document contains an unsupported value.' );
	}
}
