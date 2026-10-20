<?php
/**
 * Image media resolution.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\SocialCard\CardStore;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\ValidationException;

/**
 * Resolves image attachment references into immutable delivery snapshots.
 */
final class MediaResolver {
	/**
	 * Safe URL probe.
	 *
	 * @var SafeMediaUrlProbe
	 */
	private SafeMediaUrlProbe $probe;

	/**
	 * Cached successful probes keyed by attachment ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $probe_cache;

	/**
	 * Constructor.
	 *
	 * @param SafeMediaUrlProbe|null         $probe Probe.
	 * @param array<int,array<string,mixed>> $probe_cache Cached probes.
	 */
	public function __construct( ?SafeMediaUrlProbe $probe = null, array $probe_cache = array() ) {
		$this->probe       = $probe ?? new SafeMediaUrlProbe();
		$this->probe_cache = $probe_cache;
	}

	/**
	 * Resolve media for a rule, event, and destination capability.
	 *
	 * @param RuleRecord          $rule Rule.
	 * @param NormalizedEvent     $event Event.
	 * @param array<string,mixed> $capability Channel capability.
	 * @param bool                $allow_probe Whether network probing is allowed.
	 * @return MediaSnapshot
	 */
	public function resolve( RuleRecord $rule, NormalizedEvent $event, array $capability, bool $allow_probe = false ): MediaSnapshot {
		$policy = $this->policy( $rule, $event );
		if ( 'none' === $policy->mode ) {
			return MediaSnapshot::none();
		}

		try {
			$items = array();
			foreach ( $policy->attachment_ids as $index => $attachment_id ) {
				$items[] = $this->attachment_item( $event->post_id, $attachment_id, $index, $capability, $allow_probe, 'runtime' === $event->mode, 'social_card' === $policy->mode );
			}

			$snapshot = new MediaSnapshot(
				array(
					'items'          => $items,
					'mode'           => $policy->mode,
					'schema_version' => 1,
				)
			);

			if ( ! DeliveryCapability::supports_payload( $capability, 'immediate', 0, $snapshot->document() ) ) {
				throw new ValidationException( 'Destination does not support the selected image media.' );
			}
		} catch ( ValidationException $exception ) {
			if ( 'featured_image_optional' === ( $rule->media_policy['mode'] ?? null ) && 'inherit' === ( $event->control_bundle['media']['mode'] ?? 'inherit' ) ) {
				return MediaSnapshot::none();
			}
			if ( 'social_card' === $policy->mode ) {
				// A social image is a nice extra. When it cannot be made or sent, share text and link.
				return MediaSnapshot::none();
			}
			throw $exception;
		}
		return $snapshot;
	}

	/**
	 * Return the image mode that applies to one post: its own choice, else the rule's.
	 *
	 * @param RuleRecord          $rule Rule.
	 * @param array<string,mixed> $control_bundle Post sharing settings.
	 * @return string
	 */
	public static function effective_mode( RuleRecord $rule, array $control_bundle ): string {
		$control = isset( $control_bundle['media'] ) && is_array( $control_bundle['media'] ) ? $control_bundle['media'] : array();
		$mode    = isset( $control['mode'] ) && is_string( $control['mode'] ) ? $control['mode'] : 'inherit';

		return 'inherit' === $mode ? (string) ( $rule->media_policy['mode'] ?? 'none' ) : $mode;
	}

	/**
	 * Resolve media policy.
	 *
	 * @param RuleRecord      $rule Rule.
	 * @param NormalizedEvent $event Event.
	 * @return MediaPolicy
	 */
	private function policy( RuleRecord $rule, NormalizedEvent $event ): MediaPolicy {
		$control = isset( $event->control_bundle['media'] ) && is_array( $event->control_bundle['media'] )
			? $event->control_bundle['media']
			: array( 'mode' => 'inherit' );
		$mode    = self::effective_mode( $rule, $event->control_bundle );

		if ( 'none' === $mode ) {
			return new MediaPolicy( 'none' );
		}

		if ( 'social_card' === $mode ) {
			// CardStore draws cards for published posts only, so an unpublished
			// post previews as text and link until it goes live.
			$card = CardStore::for_post( $event->post_id );

			return null === $card ? new MediaPolicy( 'none' ) : new MediaPolicy( 'social_card', array( $card['attachment_id'] ) );
		}

		if ( 'featured_image' === $mode || 'featured_image_optional' === $mode ) {
			if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
				throw new ValidationException( 'Featured image lookup is unavailable.' );
			}

			$attachment_id = (int) get_post_thumbnail_id( $event->post_id );
			if ( $attachment_id <= 0 ) {
				if ( 'featured_image_optional' === $mode ) {
					return new MediaPolicy( 'none' );
				}

				throw new ValidationException( 'Featured image is missing.' );
			}

			return new MediaPolicy( $mode, array( $attachment_id ) );
		}

		if ( 'post_media' !== $mode ) {
			throw new ValidationException( 'Media mode is unsupported.' );
		}

		$post_media = isset( $control['post_media'] ) && is_array( $control['post_media'] ) ? $control['post_media'] : array();
		$ids        = isset( $post_media['attachment_ids'] ) && is_array( $post_media['attachment_ids'] ) ? $post_media['attachment_ids'] : array();

		if ( array() === $ids ) {
			throw new ValidationException( 'Post media attachment selection is empty.' );
		}

		return new MediaPolicy( 'post_media', array_map( 'intval', $ids ) );
	}

	/**
	 * Build one immutable attachment item.
	 *
	 * @param int                 $post_id Post ID.
	 * @param int                 $attachment_id Attachment ID.
	 * @param int                 $index Position.
	 * @param array<string,mixed> $capability Capability document.
	 * @param bool                $allow_probe Whether probing is allowed.
	 * @param bool                $runtime Whether resolving previously saved selections for runtime work.
	 * @param bool                $own_card Whether the attachment is a social card PressQueue drew for this post.
	 * @return array<string,mixed>
	 */
	private function attachment_item( int $post_id, int $attachment_id, int $index, array $capability, bool $allow_probe, bool $runtime, bool $own_card = false ): array {
		$attachment = function_exists( 'get_post' ) ? get_post( $attachment_id ) : null;
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== (string) $attachment->post_type ) {
			throw new ValidationException( 'Selected media attachment does not exist.' );
		}

		if ( $own_card && ( (int) $attachment->post_parent !== $post_id || ! CardStore::is_card( $attachment_id ) ) ) {
			throw new ValidationException( 'Social image does not belong to this post.' );
		}

		if ( ! $own_card && ! $this->can_use_attachment( $post_id, $attachment, $runtime ) ) {
			throw new ValidationException( 'Selected media attachment is outside the authority boundary.' );
		}

		$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachment_id ) : '';
		if ( ! is_string( $url ) || '' === $url ) {
			throw new ValidationException( 'Selected media attachment has no public URL.' );
		}

		$known_facts     = $this->known_attachment_facts( $attachment_id, $attachment );
		$capability_hash = DeliveryCapability::hash( $capability );
		// PressQueue wrote the card file itself, so its facts are known without fetching it.
		$probe = $own_card ? $this->own_card_probe( $known_facts ) : $this->probe_for_attachment( $attachment_id, $url, (string) $attachment->post_modified_gmt, $capability_hash, $allow_probe, $known_facts );
		$alt   = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : '';

		return array(
			'alt_text'                   => $alt,
			'attachment_id'              => $attachment_id,
			'attachment_modified_at'     => (string) $attachment->post_modified_gmt,
			'byte_size'                  => (int) $probe['byte_size'],
			'capability_catalog_version' => DeliveryCapability::catalog_version( $capability ),
			'capability_hash'            => $capability_hash,
			'capability_sources'         => isset( $capability['sources'] ) && is_array( $capability['sources'] ) ? $capability['sources'] : array(),
			'height'                     => (int) $probe['height'],
			'image_kind'                 => 'image',
			'mime_type'                  => (string) $probe['mime_type'],
			'position'                   => $index,
			'probe_result_hash'          => CanonicalJson::hash(
				'media_probe_result',
				array(
					'byte_size' => (int) $probe['byte_size'],
					'height'    => (int) $probe['height'],
					'mime_type' => (string) $probe['mime_type'],
					'url'       => $url,
					'width'     => (int) $probe['width'],
				)
			),
			'url'                        => $url,
			'width'                      => (int) $probe['width'],
		);
	}

	/**
	 * Return probe facts for a card PressQueue drew.
	 *
	 * @param array<string,mixed> $known_facts Local attachment facts.
	 * @return array<string,mixed>
	 */
	private function own_card_probe( array $known_facts ): array {
		if ( ! $this->probe_has_required_facts( $known_facts ) ) {
			throw new ValidationException( 'Social image file details are missing.' );
		}

		return $known_facts;
	}

	/**
	 * Check attachment authority.
	 *
	 * @param int      $post_id Target post ID.
	 * @param \WP_Post $attachment Attachment.
	 * @param bool     $runtime Whether the selection came from saved runtime controls.
	 * @return bool
	 */
	private function can_use_attachment( int $post_id, \WP_Post $attachment, bool $runtime ): bool {
		if ( $runtime ) {
			// Cron has no logged-in editor. Trust only selections already stored on
			// this post; new editor selections still require the permissions below.
			$thumbnail_id = function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post_id ) : 0;
			$controls     = PostControlMetadata::read_for_post( $post_id );
			$selected_ids = $controls['media']['post_media']['attachment_ids'] ?? array();
			return $thumbnail_id === (int) $attachment->ID
				|| (int) $attachment->post_parent === $post_id
				|| in_array( (int) $attachment->ID, $selected_ids, true );
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		if ( current_user_can( 'edit_post', (int) $attachment->ID ) ) {
			return true;
		}

		return (int) $attachment->post_parent === $post_id;
	}

	/**
	 * Return trusted local attachment facts for the public URL probe.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param \WP_Post $attachment Attachment.
	 * @return array<string,mixed>
	 */
	private function known_attachment_facts( int $attachment_id, \WP_Post $attachment ): array {
		$facts = array();
		$mime  = function_exists( 'get_post_mime_type' ) ? get_post_mime_type( $attachment_id ) : $attachment->post_mime_type;
		if ( is_string( $mime ) && '' !== $mime ) {
			$facts['mime_type'] = $mime;
		}

		$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : array();
		if ( is_array( $metadata ) ) {
			foreach ( array( 'width', 'height', 'filesize' ) as $key ) {
				if ( isset( $metadata[ $key ] ) && is_numeric( $metadata[ $key ] ) ) {
					$facts[ 'filesize' === $key ? 'byte_size' : $key ] = (int) $metadata[ $key ];
				}
			}
		}

		if ( ! isset( $facts['byte_size'] ) && function_exists( 'get_attached_file' ) && function_exists( 'wp_filesize' ) ) {
			$file = get_attached_file( $attachment_id );
			if ( is_string( $file ) && is_file( $file ) ) {
				$facts['byte_size'] = wp_filesize( $file );
			}
		}
		return $facts;
	}

	/**
	 * Return a fresh or cached successful probe.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param string              $url URL.
	 * @param string              $attachment_modified_at Attachment modified timestamp.
	 * @param string              $capability_hash Capability hash.
	 * @param bool                $allow_probe Whether probing is allowed.
	 * @param array<string,mixed> $known_image_facts Trusted local attachment facts.
	 * @return array<string,mixed>
	 */
	private function probe_for_attachment( int $attachment_id, string $url, string $attachment_modified_at, string $capability_hash, bool $allow_probe, array $known_image_facts ): array {
		$cached = $this->probe_cache[ $attachment_id ] ?? null;
		if (
			is_array( $cached )
			&& $this->probe_matches( $cached, $url, $attachment_modified_at, $capability_hash, $known_image_facts )
		) {
			return $cached;
		}

		$cached = $this->read_probe_cache( $attachment_id, $url, $attachment_modified_at, $capability_hash );
		if ( null !== $cached ) {
			$this->probe_cache[ $attachment_id ] = $cached;
			return $cached;
		}

		if ( ! $allow_probe ) {
			throw new ValidationException( 'Media URL probe result is missing or stale.' );
		}

		$probe = array_merge(
			$this->probe->probe( $url, $known_image_facts ),
			array(
				'attachment_modified_at' => $attachment_modified_at,
				'capability_hash'        => $capability_hash,
			)
		);

		if ( ! $this->probe_matches( $probe, $url, $attachment_modified_at, $capability_hash, $known_image_facts ) ) {
			throw new ValidationException( 'Media URL probe facts do not match the attachment.' );
		}

		$this->probe_cache[ $attachment_id ] = $probe;
		$this->write_probe_cache( $attachment_id, $url, $attachment_modified_at, $capability_hash, $probe );

		return $probe;
	}

	/**
	 * Check whether a cached probe matches the current attachment and capability.
	 *
	 * @param array<string,mixed> $probe Probe.
	 * @param string              $url URL.
	 * @param string              $attachment_modified_at Attachment modified timestamp.
	 * @param string              $capability_hash Capability hash.
	 * @param array<string,mixed> $known_image_facts Trusted local attachment facts.
	 * @return bool
	 */
	private function probe_matches( array $probe, string $url, string $attachment_modified_at, string $capability_hash, array $known_image_facts ): bool {
		if (
			( $probe['url'] ?? null ) !== $url
			|| ( $probe['attachment_modified_at'] ?? null ) !== $attachment_modified_at
			|| ( $probe['capability_hash'] ?? null ) !== $capability_hash
			|| ! $this->probe_has_required_facts( $probe )
			|| ! $this->probe_is_fresh( $probe )
		) {
			return false;
		}

		foreach ( array( 'byte_size', 'height', 'mime_type', 'width' ) as $key ) {
			if ( ! array_key_exists( $key, $known_image_facts ) ) {
				return false;
			}

			if ( 'mime_type' === $key ) {
				if ( strtolower( (string) $probe[ $key ] ) !== strtolower( (string) $known_image_facts[ $key ] ) ) {
					return false;
				}

				continue;
			}

			if ( (int) $probe[ $key ] !== (int) $known_image_facts[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read a transient-backed probe cache entry.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url URL.
	 * @param string $attachment_modified_at Attachment modified timestamp.
	 * @param string $capability_hash Capability hash.
	 * @return array<string,mixed>|null
	 */
	private function read_probe_cache( int $attachment_id, string $url, string $attachment_modified_at, string $capability_hash ): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$known_image_facts = $this->known_image_facts_for_cache_probe( $attachment_id );
		$value             = get_transient( $this->probe_cache_key( $attachment_id, $url, $attachment_modified_at, $capability_hash ) );
		if ( ! is_array( $value ) || ! $this->probe_matches( $value, $url, $attachment_modified_at, $capability_hash, $known_image_facts ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Write a transient-backed probe cache entry.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param string              $url URL.
	 * @param string              $attachment_modified_at Attachment modified timestamp.
	 * @param string              $capability_hash Capability hash.
	 * @param array<string,mixed> $probe Probe.
	 */
	private function write_probe_cache( int $attachment_id, string $url, string $attachment_modified_at, string $capability_hash, array $probe ): void {
		$known_image_facts = $this->known_image_facts_for_cache_probe( $attachment_id );
		if ( ! function_exists( 'set_transient' ) || ! $this->probe_matches( $probe, $url, $attachment_modified_at, $capability_hash, $known_image_facts ) ) {
			return;
		}

		set_transient(
			$this->probe_cache_key( $attachment_id, $url, $attachment_modified_at, $capability_hash ),
			$probe,
			(int) ( defined( 'DAY_IN_SECONDS' ) ? constant( 'DAY_IN_SECONDS' ) : 86400 )
		);
	}

	/**
	 * Return a stable transient key for one probe input.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url URL.
	 * @param string $attachment_modified_at Attachment modified timestamp.
	 * @param string $capability_hash Capability hash.
	 * @return string
	 */
	private function probe_cache_key( int $attachment_id, string $url, string $attachment_modified_at, string $capability_hash ): string {
		return 'pressqueue_media_probe_' . hash( 'sha256', $attachment_id . "\n" . $url . "\n" . $attachment_modified_at . "\n" . $capability_hash );
	}

	/**
	 * Return current attachment facts for cache matching.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	private function known_image_facts_for_cache_probe( int $attachment_id ): array {
		$attachment = function_exists( 'get_post' ) ? get_post( $attachment_id ) : null;
		if ( ! $attachment instanceof \WP_Post ) {
			return array();
		}

		return $this->known_attachment_facts( $attachment_id, $attachment );
	}

	/**
	 * Check that a cached probe has all immutable media facts.
	 *
	 * @param array<string,mixed> $probe Probe.
	 * @return bool
	 */
	private function probe_has_required_facts( array $probe ): bool {
		return isset( $probe['byte_size'], $probe['height'], $probe['mime_type'], $probe['width'] )
			&& is_numeric( $probe['byte_size'] )
			&& is_numeric( $probe['height'] )
			&& is_numeric( $probe['width'] )
			&& is_string( $probe['mime_type'] )
			&& '' !== $probe['mime_type'];
	}

	/**
	 * Check cache freshness.
	 *
	 * @param array<string,mixed> $probe Probe.
	 * @return bool
	 */
	private function probe_is_fresh( array $probe ): bool {
		if ( ! isset( $probe['probed_at'] ) || ! is_string( $probe['probed_at'] ) ) {
			return false;
		}

		$timestamp = strtotime( $probe['probed_at'] . ' UTC' );

		return false !== $timestamp && ( time() - $timestamp ) <= 86400;
	}
}
