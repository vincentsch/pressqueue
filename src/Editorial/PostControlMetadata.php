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
	public const SCHEMA_VERSION  = 2;
	public const MAX_BYTES       = 32768;

	private const POLICY_VALUES     = array( 'inherit', 'include', 'exclude' );
	private const CHANNEL_MODES     = array( 'inherit', 'override' );
	private const MESSAGE_MODES     = array( 'inherit', 'override' );
	private const SCHEDULE_MODES    = array( 'inherit', 'immediate', 'queue_next', 'queue_end', 'explicit_time' );
	private const MEDIA_MODES       = array( 'inherit', 'none', 'featured_image', 'post_media' );
	private const MESSAGE_MAX_CHARS = 2000;
	private const MESSAGE_MAX_BYTES = 16384;

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
			'media'          => array(
				'mode' => 'inherit',
			),
			'message'        => array(
				'mode' => 'inherit',
			),
			'policy'         => 'inherit',
			'schedule'       => array(
				'mode' => 'inherit',
			),
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

		$schema_version = $bundle['schema_version'] ?? null;
		if ( 1 === $schema_version ) {
			$bundle = self::normalize_legacy_v1_bundle( $bundle );
		} elseif ( self::SCHEMA_VERSION !== $schema_version ) {
			throw new ValidationException( 'PressQueue controls must use schema version 2.' );
		}

		self::assert_object_keys( $bundle, array( 'schema_version', 'policy', 'channels', 'message', 'schedule', 'media' ), 'Control bundle' );

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
		$message  = self::normalize_message( $bundle['message'] );
		$schedule = self::normalize_schedule( $bundle['schedule'] );
		$media    = self::normalize_media( $bundle['media'] );

		$normalized = array(
			'channels'       => $channels,
			'media'          => $media,
			'message'        => $message,
			'policy'         => $policy,
			'schedule'       => $schedule,
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

		$message = array(
			'mode' => isset( $input['pressqueue_message_mode'] ) ? (string) $input['pressqueue_message_mode'] : 'inherit',
		);

		if ( 'override' === $message['mode'] ) {
			$message['value'] = isset( $input['pressqueue_message_value'] ) ? (string) $input['pressqueue_message_value'] : '';
		}

		$schedule = array(
			'mode' => isset( $input['pressqueue_schedule_mode'] ) ? (string) $input['pressqueue_schedule_mode'] : 'inherit',
		);

		if ( 'explicit_time' === $schedule['mode'] ) {
			foreach ( array( 'local_date', 'local_time', 'timezone', 'utc_offset' ) as $key ) {
				$form_key = 'pressqueue_schedule_' . $key;
				if ( isset( $input[ $form_key ] ) && '' !== (string) $input[ $form_key ] ) {
					$schedule[ $key ] = (string) $input[ $form_key ];
				}
			}
		}

		$media = array(
			'mode' => isset( $input['pressqueue_media_mode'] ) ? (string) $input['pressqueue_media_mode'] : 'inherit',
		);

		if ( 'post_media' === $media['mode'] ) {
			$ids = $input['pressqueue_media_attachment_ids'] ?? array();
			if ( is_string( $ids ) ) {
				$ids = array_filter( array_map( 'trim', explode( ',', $ids ) ) );
			}

			if ( ! is_array( $ids ) ) {
				throw new ValidationException( 'PressQueue media attachments must be a list.' );
			}

			$media['post_media'] = array( 'attachment_ids' => array_values( $ids ) );
		}

		return self::normalize_bundle(
			array(
				'channels'       => $channels,
				'media'          => $media,
				'message'        => $message,
				'policy'         => isset( $input['pressqueue_policy'] ) ? (string) $input['pressqueue_policy'] : '',
				'schedule'       => $schedule,
				'schema_version' => self::SCHEMA_VERSION,
			)
		);
	}

	/**
	 * Normalize a schema version 1 bundle into schema version 2 inheritance.
	 *
	 * @param array<string,mixed> $bundle Version 1 bundle.
	 * @return array<string,mixed>
	 */
	private static function normalize_legacy_v1_bundle( array $bundle ): array {
		self::assert_object_keys( $bundle, array( 'schema_version', 'policy', 'channels' ), 'Control bundle' );

		return array(
			'channels'       => $bundle['channels'],
			'media'          => array( 'mode' => 'inherit' ),
			'message'        => array( 'mode' => 'inherit' ),
			'policy'         => $bundle['policy'],
			'schedule'       => array( 'mode' => 'inherit' ),
			'schema_version' => self::SCHEMA_VERSION,
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
	 * Normalize message controls.
	 *
	 * @param mixed $message Message control.
	 * @return array<string,mixed>
	 */
	private static function normalize_message( $message ): array {
		if ( ! is_array( $message ) || array_is_list( $message ) ) {
			throw new ValidationException( 'PressQueue message controls must be an object.' );
		}

		if ( ! isset( $message['mode'] ) || ! is_string( $message['mode'] ) ) {
			throw new ValidationException( 'PressQueue message mode is required.' );
		}

		$mode = strtolower( $message['mode'] );
		if ( ! in_array( $mode, self::MESSAGE_MODES, true ) ) {
			throw new ValidationException( 'PressQueue message mode is unsupported.' );
		}

		if ( 'inherit' === $mode ) {
			self::assert_object_keys( $message, array( 'mode' ), 'PressQueue message' );
			return array( 'mode' => 'inherit' );
		}

		self::assert_object_keys( $message, array( 'mode', 'value' ), 'PressQueue message' );

		if ( ! isset( $message['value'] ) || ! is_string( $message['value'] ) ) {
			throw new ValidationException( 'PressQueue message override is required.' );
		}

		if ( strlen( $message['value'] ) > self::MESSAGE_MAX_BYTES ) {
			throw new ValidationException( 'PressQueue message override exceeds the allowed storage size.' );
		}

		$value = self::normalize_plain_text( $message['value'] );
		if ( '' === $value || self::length( $value ) > self::MESSAGE_MAX_CHARS ) {
			throw new ValidationException( 'PressQueue message override must be a non-empty bounded string.' );
		}

		return array(
			'mode'  => 'override',
			'value' => $value,
		);
	}

	/**
	 * Normalize schedule controls.
	 *
	 * @param mixed $schedule Schedule control.
	 * @return array<string,mixed>
	 */
	private static function normalize_schedule( $schedule ): array {
		if ( ! is_array( $schedule ) || array_is_list( $schedule ) ) {
			throw new ValidationException( 'PressQueue schedule controls must be an object.' );
		}

		if ( ! isset( $schedule['mode'] ) || ! is_string( $schedule['mode'] ) ) {
			throw new ValidationException( 'PressQueue schedule mode is required.' );
		}

		$mode = strtolower( $schedule['mode'] );
		if ( ! in_array( $mode, self::SCHEDULE_MODES, true ) ) {
			throw new ValidationException( 'PressQueue schedule mode is unsupported.' );
		}

		if ( 'explicit_time' !== $mode ) {
			self::assert_object_keys( $schedule, array( 'mode' ), 'PressQueue schedule' );
			return array( 'mode' => $mode );
		}

		$allowed = array( 'mode', 'local_date', 'local_time', 'timezone', 'utc_offset' );
		foreach ( array_keys( $schedule ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new ValidationException( 'PressQueue schedule contains unsupported fields.' );
			}
		}

		foreach ( array( 'local_date', 'local_time', 'timezone' ) as $key ) {
			if ( ! isset( $schedule[ $key ] ) || ! is_string( $schedule[ $key ] ) ) {
				throw new ValidationException( 'PressQueue explicit schedule is incomplete.' );
			}
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $schedule['local_date'] ) ) {
			throw new ValidationException( 'PressQueue explicit schedule date is invalid.' );
		}

		if ( 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $schedule['local_time'] ) ) {
			throw new ValidationException( 'PressQueue explicit schedule time is invalid.' );
		}

		if ( false === timezone_open( $schedule['timezone'] ) ) {
			throw new ValidationException( 'PressQueue explicit schedule timezone is invalid.' );
		}

		$normalized = array(
			'local_date' => $schedule['local_date'],
			'local_time' => $schedule['local_time'],
			'mode'       => 'explicit_time',
			'timezone'   => $schedule['timezone'],
		);

		if ( isset( $schedule['utc_offset'] ) ) {
			if ( ! is_string( $schedule['utc_offset'] ) || 1 !== preg_match( '/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $schedule['utc_offset'] ) ) {
				throw new ValidationException( 'PressQueue explicit schedule UTC offset is invalid.' );
			}

			$normalized['utc_offset'] = $schedule['utc_offset'];
		}

		return $normalized;
	}

	/**
	 * Normalize media controls.
	 *
	 * @param mixed $media Media control.
	 * @return array<string,mixed>
	 */
	private static function normalize_media( $media ): array {
		if ( ! is_array( $media ) || array_is_list( $media ) ) {
			throw new ValidationException( 'PressQueue media controls must be an object.' );
		}

		if ( ! isset( $media['mode'] ) || ! is_string( $media['mode'] ) ) {
			throw new ValidationException( 'PressQueue media mode is required.' );
		}

		$mode = strtolower( $media['mode'] );
		if ( ! in_array( $mode, self::MEDIA_MODES, true ) ) {
			throw new ValidationException( 'PressQueue media mode is unsupported.' );
		}

		if ( 'post_media' !== $mode ) {
			self::assert_object_keys( $media, array( 'mode' ), 'PressQueue media' );
			return array( 'mode' => $mode );
		}

		self::assert_object_keys( $media, array( 'mode', 'post_media' ), 'PressQueue media' );

		if ( ! isset( $media['post_media'] ) || ! is_array( $media['post_media'] ) || array_is_list( $media['post_media'] ) ) {
			throw new ValidationException( 'PressQueue post media controls must be an object.' );
		}

		self::assert_object_keys( $media['post_media'], array( 'attachment_ids' ), 'PressQueue post media' );

		if ( ! isset( $media['post_media']['attachment_ids'] ) || ! is_array( $media['post_media']['attachment_ids'] ) || ! array_is_list( $media['post_media']['attachment_ids'] ) ) {
			throw new ValidationException( 'PressQueue post media attachments must be a list.' );
		}

		if ( count( $media['post_media']['attachment_ids'] ) > 4 ) {
			throw new ValidationException( 'PressQueue post media supports at most four attachments.' );
		}

		$seen = array();
		$ids  = array();
		foreach ( $media['post_media']['attachment_ids'] as $attachment_id ) {
			if ( is_string( $attachment_id ) && ctype_digit( $attachment_id ) ) {
				$attachment_id = (int) $attachment_id;
			}

			if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
				throw new ValidationException( 'PressQueue media attachment ID is invalid.' );
			}

			if ( isset( $seen[ $attachment_id ] ) ) {
				throw new ValidationException( 'PressQueue media attachment IDs must be unique.' );
			}

			$seen[ $attachment_id ] = true;
			$ids[]                  = $attachment_id;
		}

		return array(
			'mode'       => 'post_media',
			'post_media' => array(
				'attachment_ids' => $ids,
			),
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
	 * Normalize plain text without executing shortcodes.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize_plain_text( string $value ): string {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$value = wp_strip_all_tags( $value, true );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Unit-test fallback.
			$value = strip_tags( $value );
		}

		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		$value = preg_replace( '/\s+/u', ' ', null === $value ? '' : $value );

		return trim( null === $value ? '' : $value );
	}

	/**
	 * Return Unicode character length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private static function length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		preg_match_all( '/./us', $value, $matches );

		return count( $matches[0] );
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
