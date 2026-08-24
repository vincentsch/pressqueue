<?php
/**
 * Canonical post-level PressQueue control metadata.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Editorial;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;

/**
 * Validates, hashes, reads, and replaces the canonical post control bundle.
 */
final class PostControlMetadata {
	public const META_KEY        = '_pressqueue_controls';
	public const LEGACY_META_KEY = '_pressqueue_policy';
	public const SCHEMA_VERSION  = 1;
	public const MAX_BYTES       = 16384;

	private const POLICY_VALUES = array( 'inherit', 'include', 'exclude' );
	private const CHANNEL_MODES = array( 'inherit', 'override' );

	/**
	 * Return the canonical inherited bundle.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_bundle(): array {
		return array(
			'channels'       => array(
				'mode' => 'inherit',
			),
			'policy'         => 'inherit',
			'schema_version' => self::SCHEMA_VERSION,
		);
	}

	/**
	 * Normalize a stored bundle or an explicit null value.
	 *
	 * Missing canonical metadata and explicit inherit normalize identically.
	 *
	 * @param mixed $value Stored value.
	 * @return array<string,mixed>
	 */
	public static function normalize_nullable_bundle( $value ): array {
		if ( null === $value || '' === $value || array() === $value ) {
			return self::default_bundle();
		}

		return self::normalize_bundle( $value );
	}

	/**
	 * Normalize and validate a complete bundle.
	 *
	 * @param mixed $value Candidate bundle, either a decoded object or JSON string.
	 * @return array<string,mixed>
	 */
	public static function normalize_bundle( $value ): array {
		$bundle = self::decode_bundle_value( $value );

		self::assert_object_keys( $bundle, array( 'schema_version', 'policy', 'channels' ), 'Control bundle' );

		if ( ( $bundle['schema_version'] ?? null ) !== self::SCHEMA_VERSION ) {
			throw new ValidationException( 'PressQueue controls must use schema version 1.' );
		}

		if ( ! isset( $bundle['policy'] ) || ! is_string( $bundle['policy'] ) ) {
			throw new ValidationException( 'PressQueue policy is required.' );
		}

		$policy = strtolower( $bundle['policy'] );
		if ( ! in_array( $policy, self::POLICY_VALUES, true ) ) {
			throw new ValidationException( 'PressQueue policy is unsupported.' );
		}

		if ( ! isset( $bundle['channels'] ) || ! is_array( $bundle['channels'] ) || array_is_list( $bundle['channels'] ) ) {
			throw new ValidationException( 'PressQueue channels must be an object.' );
		}

		$channels = self::normalize_channels( $bundle['channels'] );

		$normalized = array(
			'channels'       => $channels,
			'policy'         => $policy,
			'schema_version' => self::SCHEMA_VERSION,
		);

		self::assert_encoded_size( $normalized );

		return $normalized;
	}

	/**
	 * Encode a canonical bundle.
	 *
	 * @param array<string,mixed> $bundle Bundle.
	 * @return string
	 */
	public static function encoded( array $bundle ): string {
		$normalized = self::normalize_bundle( $bundle );

		return CanonicalJson::encode_object( $normalized );
	}

	/**
	 * Calculate the stable control bundle hash.
	 *
	 * @param array<string,mixed> $bundle Bundle.
	 * @return string
	 */
	public static function bundle_hash( array $bundle ): string {
		return CanonicalJson::hash( 'post_control_bundle', self::normalize_bundle( $bundle ) );
	}

	/**
	 * Read canonical controls for a post, falling back to the legacy policy key.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function read_for_post( int $post_id ): array {
		if ( self::canonical_meta_exists( $post_id ) ) {
			return self::normalize_nullable_bundle( get_post_meta( $post_id, self::META_KEY, true ) );
		}

		$bundle = self::default_bundle();
		if ( function_exists( 'get_post_meta' ) ) {
			$legacy = get_post_meta( $post_id, self::LEGACY_META_KEY, true );

			if ( is_string( $legacy ) && in_array( $legacy, self::POLICY_VALUES, true ) ) {
				$bundle['policy'] = $legacy;
			}
		}

		return $bundle;
	}

	/**
	 * Replace a post's canonical controls if the base hash is current.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $bundle Replacement bundle.
	 * @param string              $base_hash Expected current bundle hash.
	 * @return array<string,mixed>
	 */
	public static function replace_for_post( int $post_id, array $bundle, string $base_hash ): array {
		$normalized   = self::normalize_bundle( $bundle );
		$current      = self::read_for_post( $post_id );
		$current_hash = self::bundle_hash( $current );

		if ( ! hash_equals( $current_hash, $base_hash ) ) {
			throw new StaleRecordException( 'PressQueue controls changed before replacement.' );
		}

		if ( ! function_exists( 'update_post_meta' ) ) {
			throw new ValidationException( 'Post metadata storage is unavailable.' );
		}

		update_post_meta( $post_id, self::META_KEY, self::encoded( $normalized ) );

		return $normalized;
	}

	/**
	 * Return virtual metadata facts used by the P01 condition vocabulary.
	 *
	 * @param array<string,mixed> $bundle Control bundle.
	 * @return array<string,string>
	 */
	public static function metadata_facts( array $bundle ): array {
		$normalized = self::normalize_bundle( $bundle );

		return array(
			self::LEGACY_META_KEY => (string) $normalized['policy'],
		);
	}

	/**
	 * Check whether a metadata key belongs to PressQueue post controls.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	public static function is_control_meta_key( string $key ): bool {
		return in_array( $key, array( self::META_KEY, self::LEGACY_META_KEY ), true );
	}

	/**
	 * Build a bundle from the classic editor form fields.
	 *
	 * @param array<string,mixed> $input Request input.
	 * @return array<string,mixed>
	 */
	public static function bundle_from_form( array $input ): array {
		$channels = array(
			'mode' => isset( $input['pressqueue_channel_mode'] ) ? (string) $input['pressqueue_channel_mode'] : '',
		);

		if ( 'override' === $channels['mode'] ) {
			$ids = $input['pressqueue_channel_public_ids'] ?? array();

			if ( ! is_array( $ids ) ) {
				throw new ValidationException( 'PressQueue channel override must be a list.' );
			}

			$channels['channel_public_ids'] = array_values( $ids );
		}

		return self::normalize_bundle(
			array(
				'channels'       => $channels,
				'policy'         => isset( $input['pressqueue_policy'] ) ? (string) $input['pressqueue_policy'] : '',
				'schema_version' => self::SCHEMA_VERSION,
			)
		);
	}

	/**
	 * Decode a candidate bundle into an object-shaped array.
	 *
	 * @param mixed $value Candidate value.
	 * @return array<string,mixed>
	 */
	private static function decode_bundle_value( $value ): array {
		if ( is_string( $value ) ) {
			CanonicalJson::assert_maximum_size( $value, self::MAX_BYTES );
			return CanonicalJson::decode_object( $value );
		}

		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new ValidationException( 'PressQueue controls must be a JSON object.' );
		}

		return $value;
	}

	/**
	 * Normalize channels.
	 *
	 * @param array<string,mixed> $channels Channels document.
	 * @return array<string,mixed>
	 */
	private static function normalize_channels( array $channels ): array {
		if ( ! isset( $channels['mode'] ) || ! is_string( $channels['mode'] ) ) {
			throw new ValidationException( 'PressQueue channel mode is required.' );
		}

		$mode = strtolower( $channels['mode'] );
		if ( ! in_array( $mode, self::CHANNEL_MODES, true ) ) {
			throw new ValidationException( 'PressQueue channel mode is unsupported.' );
		}

		if ( 'inherit' === $mode ) {
			self::assert_object_keys( $channels, array( 'mode' ), 'PressQueue channels' );
			return array( 'mode' => 'inherit' );
		}

		self::assert_object_keys( $channels, array( 'mode', 'channel_public_ids' ), 'PressQueue channels' );

		if ( ! isset( $channels['channel_public_ids'] ) || ! is_array( $channels['channel_public_ids'] ) || ! array_is_list( $channels['channel_public_ids'] ) ) {
			throw new ValidationException( 'PressQueue channel override must be a list.' );
		}

		$seen = array();
		$ids  = array();

		foreach ( $channels['channel_public_ids'] as $channel_public_id ) {
			if ( ! is_string( $channel_public_id ) ) {
				throw new ValidationException( 'PressQueue channel override contains an invalid public ID.' );
			}

			PublicIds::assert( $channel_public_id );

			if ( isset( $seen[ $channel_public_id ] ) ) {
				throw new ValidationException( 'PressQueue channel override contains duplicate public IDs.' );
			}

			$seen[ $channel_public_id ] = true;
			$ids[]                      = $channel_public_id;
		}

		sort( $ids, SORT_STRING );

		return array(
			'channel_public_ids' => $ids,
			'mode'               => 'override',
		);
	}

	/**
	 * Assert exact object keys.
	 *
	 * @param array<string,mixed> $value Object value.
	 * @param array<int,string>   $allowed Allowed keys.
	 * @param string              $label Label.
	 */
	private static function assert_object_keys( array $value, array $allowed, string $label ): void {
		unset( $label );

		$unknown = array_diff( array_keys( $value ), $allowed );
		$missing = array_diff( $allowed, array_keys( $value ) );

		if ( array() !== $unknown || array() !== $missing ) {
			throw new ValidationException( 'PressQueue controls contain unsupported or missing fields.' );
		}
	}

	/**
	 * Assert that a normalized bundle fits in post metadata.
	 *
	 * @param array<string,mixed> $bundle Normalized bundle.
	 */
	private static function assert_encoded_size( array $bundle ): void {
		CanonicalJson::assert_maximum_size( CanonicalJson::encode_object( $bundle ), self::MAX_BYTES );
	}

	/**
	 * Check canonical metadata existence without treating an empty fallback as stored.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function canonical_meta_exists( int $post_id ): bool {
		if ( function_exists( 'metadata_exists' ) ) {
			return metadata_exists( 'post', $post_id, self::META_KEY );
		}

		if ( ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		$value = get_post_meta( $post_id, self::META_KEY, true );

		return null !== $value && '' !== $value;
	}
}
