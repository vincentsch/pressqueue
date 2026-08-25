<?php
/**
 * Provider delivery capability helpers.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Capability;

use PressQueue\Planning\MediaSnapshot;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * Validates capability documents used for delivery decisions.
 */
final class DeliveryCapability {
	/**
	 * Return a stable capability hash.
	 *
	 * @param array<string,mixed> $capability Capability document.
	 * @return string
	 */
	public static function hash( array $capability ): string {
		return CanonicalJson::hash( 'channel_capability', self::normalized_for_hash( $capability ) );
	}

	/**
	 * Check whether a channel can be enabled.
	 *
	 * @param array<string,mixed> $capability Capability document.
	 * @return bool
	 */
	public static function can_enable( array $capability ): bool {
		return self::supports_slot( $capability, 'immediate' )
			&& self::text_limit( $capability ) > 0;
	}

	/**
	 * Check whether an immutable payload can still be submitted.
	 *
	 * @param array<string,mixed> $capability Current capability document.
	 * @param string              $schedule_slot Requested schedule slot.
	 * @param int                 $final_text_length Final encoded text length.
	 * @param array<string,mixed> $media_snapshot Immutable media snapshot.
	 * @return bool
	 */
	public static function supports_payload( array $capability, string $schedule_slot, int $final_text_length, array $media_snapshot ): bool {
		StorageEnums::assert_schedule_slot( $schedule_slot );

		if ( ! self::supports_slot( $capability, $schedule_slot ) ) {
			return false;
		}

		$text_limit = self::text_limit( $capability );
		if ( $text_limit <= 0 || $final_text_length > $text_limit ) {
			return false;
		}

		try {
			$items = ( new MediaSnapshot( $media_snapshot ) )->items();
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return false;
		}

		if ( array() === $items ) {
			return true;
		}

		$image = self::image_capability( $capability );
		if ( null === $image ) {
			return false;
		}

		$max_items = (int) ( $image['max_items'] ?? 0 );
		if ( $max_items <= 0 || count( $items ) > $max_items ) {
			return false;
		}

		$mime_types   = isset( $image['mime_types'] ) && is_array( $image['mime_types'] ) ? $image['mime_types'] : array();
		$max_bytes    = (int) ( $image['max_bytes'] ?? 0 );
		$min_width    = (int) ( $image['min_width'] ?? 1 );
		$min_height   = (int) ( $image['min_height'] ?? 1 );
		$max_width    = (int) ( $image['max_width'] ?? 0 );
		$max_height   = (int) ( $image['max_height'] ?? 0 );
		$alt_required = true === ( $image['alt_text_required'] ?? false );

		foreach ( $items as $item ) {
			$mime = isset( $item['mime_type'] ) && is_string( $item['mime_type'] ) ? $item['mime_type'] : '';
			if ( ! in_array( $mime, $mime_types, true ) ) {
				return false;
			}

			$bytes  = (int) ( $item['byte_size'] ?? 0 );
			$width  = (int) ( $item['width'] ?? 0 );
			$height = (int) ( $item['height'] ?? 0 );
			$alt    = isset( $item['alt_text'] ) && is_string( $item['alt_text'] ) ? trim( $item['alt_text'] ) : '';

			if ( $bytes <= 0 || $max_bytes <= 0 || $bytes > $max_bytes ) {
				return false;
			}

			if ( $width < $min_width || $height < $min_height || $width > $max_width || $height > $max_height ) {
				return false;
			}

			if ( $alt_required && '' === $alt ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return the maximum text length.
	 *
	 * @param array<string,mixed> $capability Capability document.
	 * @return int
	 */
	public static function text_limit( array $capability ): int {
		$delivery = self::delivery_document( $capability );

		if ( null === $delivery || true !== ( $delivery['text'] ?? false ) ) {
			return 0;
		}

		return max( 0, (int) ( $delivery['max_text_characters'] ?? 0 ) );
	}

	/**
	 * Check whether a schedule slot is supported.
	 *
	 * @param array<string,mixed> $capability Capability document.
	 * @param string              $slot Schedule slot.
	 * @return bool
	 */
	public static function supports_slot( array $capability, string $slot ): bool {
		$delivery = self::delivery_document( $capability );

		if ( null === $delivery || ! isset( $delivery['schedule_slots'] ) || ! is_array( $delivery['schedule_slots'] ) ) {
			return false;
		}

		return in_array( $slot, $delivery['schedule_slots'], true );
	}

	/**
	 * Return a redacted compatibility decision.
	 *
	 * @param array<string,mixed>      $capability Capability document.
	 * @param string                   $schedule_slot Requested schedule slot.
	 * @param int                      $final_text_length Final text length.
	 * @param array<string,mixed>|null $media_snapshot Media snapshot.
	 * @return array<string,mixed>
	 */
	public static function decision( array $capability, string $schedule_slot = 'immediate', int $final_text_length = 0, ?array $media_snapshot = null ): array {
		$media_snapshot = $media_snapshot ?? MediaSnapshot::none()->document();

		return array(
			'capability_hash'   => self::hash( $capability ),
			'catalog_version'   => self::catalog_version( $capability ),
			'compatible'        => self::supports_payload( $capability, $schedule_slot, $final_text_length, $media_snapshot ),
			'final_text_length' => $final_text_length,
			'schedule_slot'     => $schedule_slot,
			'schema_version'    => (int) ( $capability['schema_version'] ?? 0 ),
			'supported_slots'   => self::schedule_slots( $capability ),
			'text_limit'        => self::text_limit( $capability ),
		);
	}

	/**
	 * Return catalog version.
	 *
	 * @param array<string,mixed> $capability Capability document.
	 * @return string|null
	 */
	public static function catalog_version( array $capability ): ?string {
		return isset( $capability['catalog_version'] ) && is_string( $capability['catalog_version'] )
			? $capability['catalog_version']
			: null;
	}

	/**
	 * Return schedule slots.
	 *
	 * @param array<string,mixed> $capability Capability document.
	 * @return array<int,string>
	 */
	private static function schedule_slots( array $capability ): array {
		$delivery = self::delivery_document( $capability );
		$slots    = null !== $delivery && isset( $delivery['schedule_slots'] ) && is_array( $delivery['schedule_slots'] )
			? $delivery['schedule_slots']
			: array();

		return array_values( array_filter( $slots, 'is_string' ) );
	}

	/**
	 * Return delivery document when schema version 2 is present.
	 *
	 * @param array<string,mixed> $capability Capability document.
	 * @return array<string,mixed>|null
	 */
	private static function delivery_document( array $capability ): ?array {
		if ( 2 !== (int) ( $capability['schema_version'] ?? 0 ) ) {
			return null;
		}

		$delivery = $capability['delivery'] ?? null;

		return is_array( $delivery ) && ! array_is_list( $delivery ) ? $delivery : null;
	}

	/**
	 * Return image capability.
	 *
	 * @param array<string,mixed> $capability Capability document.
	 * @return array<string,mixed>|null
	 */
	private static function image_capability( array $capability ): ?array {
		$delivery = self::delivery_document( $capability );
		$image    = null === $delivery ? null : ( $delivery['image'] ?? null );

		return is_array( $image ) && ! array_is_list( $image ) ? $image : null;
	}

	/**
	 * Return only semantic capability fields for hashing.
	 *
	 * @param array<string,mixed> $capability Capability document.
	 * @return array<string,mixed>
	 */
	private static function normalized_for_hash( array $capability ): array {
		unset( $capability['synced_at'], $capability['checked_at'] );

		return $capability;
	}
}
