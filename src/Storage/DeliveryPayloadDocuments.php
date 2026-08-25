<?php
/**
 * Delivery payload document validation.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use PressQueue\Planning\MediaSnapshot;

/**
 * Validates immutable delivery payload preview documents.
 */
final class DeliveryPayloadDocuments {
	/**
	 * Validate and return schema version 2 submission facts.
	 *
	 * @param array<string,mixed> $payload_preview Payload preview.
	 * @param string              $expected_schedule_slot Expected schedule slot.
	 * @param string|null         $expected_scheduled_for_utc Expected scheduled UTC time.
	 * @param string|null         $expected_schedule_timezone Expected schedule timezone.
	 * @param string|null         $expected_media_identity_hash Expected media identity hash.
	 * @param string|null         $expected_bound_capability_hash Expected bound capability hash.
	 * @return array{final_encoded_text:string,final_encoded_text_length:int,media_identity_hash:string|null,media_snapshot:array<string,mixed>,scheduled_for_utc:string|null,schedule_timezone:string|null}
	 */
	public static function schema_two_facts( array $payload_preview, string $expected_schedule_slot, ?string $expected_scheduled_for_utc = null, ?string $expected_schedule_timezone = null, ?string $expected_media_identity_hash = null, ?string $expected_bound_capability_hash = null ): array {
		if ( array_is_list( $payload_preview ) ) {
			throw new ValidationException( 'Delivery payload preview must be an object.' );
		}

		if ( 2 !== (int) ( $payload_preview['payload_schema_version'] ?? 0 ) ) {
			throw new ValidationException( 'Delivery payload schema version is unsupported.' );
		}

		if ( 2 !== (int) ( $payload_preview['adapter_encoding_version'] ?? 0 ) ) {
			throw new ValidationException( 'Delivery adapter encoding version is unsupported.' );
		}

		foreach ( array( 'message', 'canonical_url', 'final_encoded_text', 'schedule_slot' ) as $key ) {
			if ( ! isset( $payload_preview[ $key ] ) || ! is_string( $payload_preview[ $key ] ) ) {
				throw new ValidationException( 'Delivery payload preview is incomplete.' );
			}
		}

		if ( $payload_preview['schedule_slot'] !== $expected_schedule_slot ) {
			throw new ValidationException( 'Delivery payload schedule slot is inconsistent.' );
		}

		$final_text = trim( $payload_preview['message'] ) . "\n\n" . $payload_preview['canonical_url'];
		if ( $payload_preview['final_encoded_text'] !== $final_text ) {
			throw new ValidationException( 'Delivery payload final text is inconsistent.' );
		}

		if ( ! isset( $payload_preview['final_encoded_text_length'] ) || ! is_int( $payload_preview['final_encoded_text_length'] ) ) {
			throw new ValidationException( 'Delivery payload final text length is required.' );
		}

		$final_text_length = self::length( $final_text );
		if ( $payload_preview['final_encoded_text_length'] !== $final_text_length ) {
			throw new ValidationException( 'Delivery payload final text length is inconsistent.' );
		}

		$scheduled_for_utc = isset( $payload_preview['scheduled_for_utc'] ) && is_string( $payload_preview['scheduled_for_utc'] ) && '' !== $payload_preview['scheduled_for_utc']
			? $payload_preview['scheduled_for_utc']
			: null;
		$schedule_timezone = isset( $payload_preview['schedule_timezone'] ) && is_string( $payload_preview['schedule_timezone'] ) && '' !== $payload_preview['schedule_timezone']
			? $payload_preview['schedule_timezone']
			: null;

		if ( 'explicit_time' === $expected_schedule_slot && ( null === $scheduled_for_utc || null === $schedule_timezone ) ) {
			throw new ValidationException( 'Explicit delivery schedule facts are incomplete.' );
		}

		if ( null !== $scheduled_for_utc ) {
			StorageValidator::assert_utc_timestamp( 'Scheduled UTC time', $scheduled_for_utc );
		}

		if ( $scheduled_for_utc !== $expected_scheduled_for_utc || $schedule_timezone !== $expected_schedule_timezone ) {
			throw new ValidationException( 'Delivery payload schedule facts are inconsistent.' );
		}

		if ( ! isset( $payload_preview['media_snapshot'] ) || ! is_array( $payload_preview['media_snapshot'] ) ) {
			throw new ValidationException( 'Delivery payload media snapshot is required.' );
		}

		$media_snapshot      = new MediaSnapshot( $payload_preview['media_snapshot'] );
		$media_identity_hash = $media_snapshot->identity_hash();
		$preview_media_hash  = isset( $payload_preview['media_identity_hash'] ) && is_string( $payload_preview['media_identity_hash'] ) && '' !== $payload_preview['media_identity_hash']
			? $payload_preview['media_identity_hash']
			: null;

		if ( null !== $preview_media_hash ) {
			StorageValidator::assert_hash( $preview_media_hash );
		}

		if ( $preview_media_hash !== $media_identity_hash || $expected_media_identity_hash !== $media_identity_hash ) {
			throw new ValidationException( 'Delivery payload media identity is inconsistent.' );
		}

		if ( ! isset( $payload_preview['bound_capability_hash'] ) || ! is_string( $payload_preview['bound_capability_hash'] ) ) {
			throw new ValidationException( 'Delivery payload bound capability hash is required.' );
		}

		StorageValidator::assert_hash( $payload_preview['bound_capability_hash'] );

		if ( null !== $expected_bound_capability_hash ) {
			StorageValidator::assert_hash( $expected_bound_capability_hash );

			if ( $payload_preview['bound_capability_hash'] !== $expected_bound_capability_hash ) {
				throw new ValidationException( 'Delivery payload bound capability hash is inconsistent.' );
			}
		}

		if ( 2 !== (int) ( $payload_preview['capability_schema_version'] ?? 0 ) ) {
			throw new ValidationException( 'Delivery payload capability schema version is unsupported.' );
		}

		return array(
			'final_encoded_text'        => $final_text,
			'final_encoded_text_length' => $final_text_length,
			'media_identity_hash'       => $media_identity_hash,
			'media_snapshot'            => $media_snapshot->document(),
			'scheduled_for_utc'         => $scheduled_for_utc,
			'schedule_timezone'         => $schedule_timezone,
		);
	}

	/**
	 * Count Unicode characters.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private static function length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value );
		}

		preg_match_all( '/./us', $value, $matches );

		return count( $matches[0] );
	}
}
