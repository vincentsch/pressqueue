<?php
/**
 * Buffer capability catalog.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Buffer;

use PressQueue\Storage\StorageValidator;

/**
 * Conservative local catalog for Buffer channel delivery capabilities.
 */
final class BufferCapabilityCatalog {
	public const VERSION = '2026-10-20.1';

	/**
	 * Mastodon limit when the server allows more, Buffer's documented default.
	 */
	public const MASTODON_DEFAULT_LIMIT = 500;

	/**
	 * Post text limits per network, as Buffer documents them for its own checks.
	 *
	 * X is kept at the free plan's 280, which every X account allows.
	 */
	private const TEXT_LIMITS = array(
		'bluesky'  => 300,
		'facebook' => 5000,
		'linkedin' => 3000,
		'mastodon' => self::MASTODON_DEFAULT_LIMIT,
		'threads'  => 500,
		'twitter'  => 280,
	);

	private const SUPPORTED_SERVICES = array(
		'bluesky',
		'facebook',
		'linkedin',
		'mastodon',
		'threads',
		'twitter',
	);

	private const SOURCE_URLS = array(
		'https://developers.buffer.com/examples/create-draft-post.html',
		'https://developers.buffer.com/guides/posts-and-scheduling.html',
		'https://developers.buffer.com/reference.html',
		'https://developers.buffer.com/examples/create-image-post.html',
		'https://developers.buffer.com/types/CreatePostInput.html',
		'https://developers.buffer.com/types/AssetInput.html',
		'https://developers.buffer.com/types/ImageAssetInput.html',
		'https://developers.buffer.com/types/ImageMetadataInput.html',
		'https://developers.buffer.com/types/MastodonMetadata.html',
		'https://developers.buffer.com/guides/character-limits.html',
	);

	/**
	 * Build a schema version 2 capability document.
	 *
	 * @param string              $service Service key.
	 * @param array<string,mixed> $provider_metadata Provider metadata.
	 * @param bool                $available Whether discovery says the channel is otherwise available.
	 * @return array<string,mixed>
	 */
	public function capability_for_service( string $service, array $provider_metadata = array(), bool $available = true ): array {
		StorageValidator::assert_text_key( 'Service', $service );

		if ( ! $available || ! in_array( $service, self::SUPPORTED_SERVICES, true ) ) {
			return $this->unsupported( $service, $available ? 'unsupported_service' : 'unavailable_channel' );
		}

		$text_limit = self::TEXT_LIMITS[ $service ];
		if ( 'mastodon' === $service ) {
			$max_characters = $provider_metadata['maxCharacters'] ?? null;
			if ( ! is_int( $max_characters ) || $max_characters <= 0 ) {
				return $this->unsupported( $service, 'missing_mastodon_text_limit' );
			}

			$text_limit = min( $max_characters, self::MASTODON_DEFAULT_LIMIT );
		}

		return array(
			'catalog_version' => self::VERSION,
			'delivery'        => array(
				'image'               => array(
					'alt_text_required' => false,
					'max_bytes'         => 5 * 1024 * 1024,
					'max_height'        => 4096,
					'max_items'         => 1,
					'max_width'         => 4096,
					'mime_types'        => array( 'image/gif', 'image/jpeg', 'image/png' ),
					'min_height'        => 1,
					'min_width'         => 1,
				),
				'max_text_characters' => $text_limit,
				'schedule_slots'      => array( 'immediate', 'queue_next', 'queue_end', 'explicit_time', 'draft' ),
				'text'                => true,
			),
			'schema_version'  => 2,
			'service'         => $service,
			'sources'         => $this->sources( $service ),
			'supported'       => true,
		);
	}

	/**
	 * Return supported services.
	 *
	 * @return array<int,string>
	 */
	public static function supported_services(): array {
		return self::SUPPORTED_SERVICES;
	}

	/**
	 * Build an unsupported capability document.
	 *
	 * @param string $service Service.
	 * @param string $reason Reason.
	 * @return array<string,mixed>
	 */
	private function unsupported( string $service, string $reason ): array {
		return array(
			'catalog_version'    => self::VERSION,
			'delivery'           => array(
				'image'               => null,
				'max_text_characters' => 0,
				'schedule_slots'      => array(),
				'text'                => false,
			),
			'schema_version'     => 2,
			'service'            => $service,
			'sources'            => $this->sources( $service ),
			'supported'          => false,
			'unsupported_reason' => $reason,
		);
	}

	/**
	 * Return catalog sources.
	 *
	 * @param string $service Service.
	 * @return array<int,array<string,string>>
	 */
	private function sources( string $service ): array {
		$sources = array();

		foreach ( self::SOURCE_URLS as $url ) {
			$sources[] = array(
				'service'         => $service,
				'source_url'      => $url,
				'verified_on_utc' => self::verified_on( $url ),
			);
		}

		return $sources;
	}

	/**
	 * Return the day a source was last checked.
	 *
	 * @param string $url Source URL.
	 * @return string
	 */
	private static function verified_on( string $url ): string {
		if ( str_contains( $url, 'character-limits' ) ) {
			return '2026-10-20';
		}

		return str_contains( $url, 'create-draft-post' ) ? '2026-10-01' : '2026-08-25';
	}
}
