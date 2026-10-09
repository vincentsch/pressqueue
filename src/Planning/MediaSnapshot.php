<?php
/**
 * Media snapshot.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Immutable image media snapshot for a delivery.
 */
final class MediaSnapshot {
	/**
	 * Snapshot document.
	 *
	 * @var array<string,mixed>
	 */
	private array $document;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $document Snapshot document.
	 */
	public function __construct( array $document ) {
		$this->document = self::normalize( $document );
	}

	/**
	 * Return an empty text-only snapshot.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self(
			array(
				'items'          => array(),
				'mode'           => 'none',
				'schema_version' => 1,
			)
		);
	}

	/**
	 * Return document.
	 *
	 * @return array<string,mixed>
	 */
	public function document(): array {
		return $this->document;
	}

	/**
	 * Return media items.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function items(): array {
		return $this->document['items'];
	}

	/**
	 * Return identity hash.
	 *
	 * @return string|null
	 */
	public function identity_hash(): ?string {
		if ( array() === $this->document['items'] ) {
			return null;
		}

		return CanonicalJson::hash(
			'media_identity',
			array(
				'items' => array_map(
					static function ( array $item ): array {
						return array(
							'alt_text'               => $item['alt_text'] ?? '',
							'attachment_id'          => $item['attachment_id'],
							'attachment_modified_at' => $item['attachment_modified_at'],
							'byte_size'              => $item['byte_size'],
							'height'                 => $item['height'],
							'mime_type'              => $item['mime_type'],
							'position'               => $item['position'],
							'url'                    => $item['url'],
							'width'                  => $item['width'],
						);
					},
					$this->document['items']
				),
			)
		);
	}

	/**
	 * Validate and normalize a media snapshot document.
	 *
	 * @param array<string,mixed> $document Snapshot document.
	 * @return array{items:array<int,array<string,mixed>>,mode:string,schema_version:int}
	 */
	private static function normalize( array $document ): array {
		if ( array_is_list( $document ) ) {
			throw new ValidationException( 'Media snapshot must be an object.' );
		}

		if ( 1 !== (int) ( $document['schema_version'] ?? 0 ) ) {
			throw new ValidationException( 'Media snapshot schema version is unsupported.' );
		}

		$mode = isset( $document['mode'] ) && is_string( $document['mode'] ) ? $document['mode'] : '';
		if ( ! in_array( $mode, array( 'none', 'featured_image', 'featured_image_optional', 'post_media' ), true ) ) {
			throw new ValidationException( 'Media snapshot mode is unsupported.' );
		}

		if ( ! isset( $document['items'] ) || ! is_array( $document['items'] ) || ! array_is_list( $document['items'] ) ) {
			throw new ValidationException( 'Media snapshot items must be a list.' );
		}

		$items = array();
		foreach ( $document['items'] as $index => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new ValidationException( 'Media snapshot item must be an object.' );
			}

			$items[] = self::normalize_item( $item, $index );
		}

		if ( 'none' === $mode && array() !== $items ) {
			throw new ValidationException( 'Text-only media snapshot cannot contain items.' );
		}

		if ( 'none' !== $mode && array() === $items ) {
			throw new ValidationException( 'Image media snapshot needs at least one item.' );
		}

		return array(
			'items'          => $items,
			'mode'           => $mode,
			'schema_version' => 1,
		);
	}

	/**
	 * Validate and normalize one media snapshot item.
	 *
	 * @param array<string,mixed> $item Item document.
	 * @param int                 $index Expected position.
	 * @return array<string,mixed>
	 */
	private static function normalize_item( array $item, int $index ): array {
		foreach ( array( 'attachment_id', 'attachment_modified_at', 'byte_size', 'capability_hash', 'height', 'image_kind', 'mime_type', 'position', 'probe_result_hash', 'url', 'width' ) as $key ) {
			if ( ! array_key_exists( $key, $item ) ) {
				throw new ValidationException( 'Media snapshot item is incomplete.' );
			}
		}

		if ( (int) $item['position'] !== $index ) {
			throw new ValidationException( 'Media snapshot item position is inconsistent.' );
		}

		$attachment_id = (int) $item['attachment_id'];
		$byte_size     = (int) $item['byte_size'];
		$height        = (int) $item['height'];
		$width         = (int) $item['width'];

		if ( $attachment_id <= 0 || $byte_size <= 0 || $height <= 0 || $width <= 0 ) {
			throw new ValidationException( 'Media snapshot item numeric facts are invalid.' );
		}

		$url = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';
		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			throw new ValidationException( 'Media snapshot item URL is invalid.' );
		}

		if ( 'image' !== ( $item['image_kind'] ?? null ) ) {
			throw new ValidationException( 'Media snapshot item kind is unsupported.' );
		}

		foreach ( array( 'attachment_modified_at', 'mime_type' ) as $key ) {
			if ( ! isset( $item[ $key ] ) || ! is_string( $item[ $key ] ) || '' === $item[ $key ] ) {
				throw new ValidationException( 'Media snapshot item text facts are invalid.' );
			}
		}

		StorageValidator::assert_utc_timestamp( 'Attachment modified time', $item['attachment_modified_at'] );
		StorageValidator::assert_hash( (string) $item['capability_hash'] );
		StorageValidator::assert_hash( (string) $item['probe_result_hash'] );

		$normalized = array(
			'alt_text'                   => isset( $item['alt_text'] ) && is_string( $item['alt_text'] ) ? $item['alt_text'] : '',
			'attachment_id'              => $attachment_id,
			'attachment_modified_at'     => $item['attachment_modified_at'],
			'byte_size'                  => $byte_size,
			'capability_catalog_version' => isset( $item['capability_catalog_version'] ) && is_string( $item['capability_catalog_version'] ) ? $item['capability_catalog_version'] : null,
			'capability_hash'            => (string) $item['capability_hash'],
			'capability_sources'         => isset( $item['capability_sources'] ) && is_array( $item['capability_sources'] ) ? $item['capability_sources'] : array(),
			'height'                     => $height,
			'image_kind'                 => 'image',
			'mime_type'                  => $item['mime_type'],
			'position'                   => $index,
			'probe_result_hash'          => (string) $item['probe_result_hash'],
			'url'                        => $url,
			'width'                      => $width,
		);

		return $normalized;
	}
}
