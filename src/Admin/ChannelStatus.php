<?php
/**
 * Plain-language channel facts for the Channels screen.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Provider\Buffer\BufferCapabilityCatalog;
use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\RuleRecord;

/**
 * Turns stored channel documents into short sentences people understand.
 */
final class ChannelStatus {
	/**
	 * Count enabled sharing rules per channel.
	 *
	 * @param array<int,RuleRecord> $rules Rules.
	 * @return array<string,int> Channel public ID => number of enabled rules that share to it.
	 */
	public static function usage_counts( array $rules ): array {
		$counts = array();

		foreach ( $rules as $rule ) {
			if ( ! $rule->enabled || 'include' !== $rule->effect ) {
				continue;
			}

			$destinations = $rule->destination_set['destinations'] ?? array();
			if ( ! is_array( $destinations ) ) {
				continue;
			}

			$seen = array();
			foreach ( $destinations as $destination ) {
				$id = is_array( $destination ) && isset( $destination['channel_public_id'] ) && is_string( $destination['channel_public_id'] ) ? $destination['channel_public_id'] : '';
				if ( '' === $id || isset( $seen[ $id ] ) ) {
					continue;
				}

				$seen[ $id ]   = true;
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * Check whether a channel can be switched on.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @return bool
	 */
	public static function can_switch_on( ChannelRecord $channel ): bool {
		return 'available' === $channel->availability && DeliveryCapability::can_enable( $channel->capabilities );
	}

	/**
	 * Check whether a channel belongs in the list for its connection.
	 *
	 * Channels left behind by an earlier organization stay out of the main list.
	 *
	 * @param ChannelRecord    $channel Channel.
	 * @param ConnectionRecord $connection Connection.
	 * @return bool
	 */
	public static function is_listed( ChannelRecord $channel, ConnectionRecord $connection ): bool {
		if ( null === $connection->remote_organization_id ) {
			return false;
		}

		return '' === $channel->remote_organization_id || $channel->remote_organization_id === $connection->remote_organization_id;
	}

	/**
	 * Sort channels with usable ones first, then by network and name.
	 *
	 * @param array<int,ChannelRecord> $channels Channels.
	 * @return array<int,ChannelRecord>
	 */
	public static function sort( array $channels ): array {
		usort(
			$channels,
			static function ( ChannelRecord $a, ChannelRecord $b ): int {
				$rank = self::rank( $a ) <=> self::rank( $b );
				if ( 0 !== $rank ) {
					return $rank;
				}

				$service = strcasecmp( Labels::service( $a->service ), Labels::service( $b->service ) );
				if ( 0 !== $service ) {
					return $service;
				}

				return strcasecmp( ActivitySummary::channel_display_name( $a ), ActivitySummary::channel_display_name( $b ) );
			}
		);

		return $channels;
	}

	/**
	 * Return the muted line under a usable channel, for example "LinkedIn · up to 3000 characters with the link · 1 image".
	 *
	 * @param ChannelRecord $channel Channel.
	 * @return string
	 */
	public static function summary( ChannelRecord $channel ): string {
		$parts = array( Labels::service( $channel->service ) );
		$limit = DeliveryCapability::text_limit( $channel->capabilities );

		if ( $limit > 0 ) {
			/* translators: %d: maximum number of characters, including the post link. */
			$parts[] = sprintf( __( 'up to %d characters with the link', 'pressqueue' ), $limit );
		}

		$image = $channel->capabilities['delivery']['image'] ?? null;
		$items = is_array( $image ) ? (int) ( $image['max_items'] ?? 0 ) : 0;
		if ( $items > 0 ) {
			/* translators: %d: number of images. */
			$parts[] = sprintf( _n( '%d image', '%d images', $items, 'pressqueue' ), $items );
		} elseif ( $limit > 0 ) {
			$parts[] = __( 'text only', 'pressqueue' );
		}

		$line = implode( ' · ', $parts );

		if ( 'mastodon' === strtolower( $channel->service ) && $limit > 0 ) {
			/* translators: %d: Buffer's default Mastodon character limit. */
			$line .= '. ' . sprintf( __( 'PressQueue uses the lower of the server limit and %d.', 'pressqueue' ), BufferCapabilityCatalog::MASTODON_DEFAULT_LIMIT );
		}

		return $line;
	}

	/**
	 * Explain why a channel cannot be switched on.
	 *
	 * The reason is rebuilt from what discovery stored: the channel organization, the service,
	 * Buffer's status flags in the display document, and the capability document.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @param string|null   $organization_id Organization the connection uses.
	 * @return array{key:string,label:string,text:string}|null Null when the channel can be switched on.
	 */
	public static function problem( ChannelRecord $channel, ?string $organization_id ): ?array {
		if ( self::can_switch_on( $channel ) ) {
			return null;
		}

		if ( 'missing' === $channel->availability ) {
			return self::reason( 'missing', __( 'Not in Buffer', 'pressqueue' ), __( 'Buffer no longer lists this channel. Add it back in Buffer, then refresh channels.', 'pressqueue' ) );
		}

		if ( 'unverified' === $channel->availability ) {
			return self::reason( 'unchecked', __( 'Not checked yet', 'pressqueue' ), __( 'PressQueue has not checked this channel yet. Refresh channels to check it.', 'pressqueue' ) );
		}

		if ( null !== $organization_id && '' !== $channel->remote_organization_id && $channel->remote_organization_id !== $organization_id ) {
			return self::reason( 'other_organization', __( 'Other organization', 'pressqueue' ), __( 'This channel belongs to another Buffer organization.', 'pressqueue' ) );
		}

		if ( ! in_array( strtolower( $channel->service ), BufferCapabilityCatalog::supported_services(), true ) ) {
			return self::reason( 'unsupported_service', __( 'Not supported', 'pressqueue' ), self::unsupported_service_text( $channel->service ) );
		}

		$flags = $channel->display['raw_status_flags'] ?? null;
		$flags = is_array( $flags ) ? $flags : array();
		foreach ( array( 'isDisconnected', 'isLocked', 'isQueuePaused' ) as $flag ) {
			if ( ! is_bool( $flags[ $flag ] ?? null ) ) {
				return self::reason( 'unknown_status', __( 'Status unknown', 'pressqueue' ), __( 'Buffer did not report whether this channel is ready, so PressQueue keeps it off to be safe. Refresh channels to check again.', 'pressqueue' ) );
			}
		}

		if ( true === $flags['isDisconnected'] ) {
			return self::reason( 'disconnected', __( 'Reconnect in Buffer', 'pressqueue' ), __( 'Buffer lost its connection to this channel. Reconnect it in Buffer, then refresh channels.', 'pressqueue' ) );
		}

		if ( true === $flags['isLocked'] ) {
			return self::reason( 'locked', __( 'Locked in Buffer', 'pressqueue' ), __( 'Buffer has locked this channel, usually because of your Buffer plan. Unlock it in Buffer, then refresh channels.', 'pressqueue' ) );
		}

		if ( true === $flags['isQueuePaused'] ) {
			return self::reason( 'queue_paused', __( 'Queue paused', 'pressqueue' ), __( 'The Buffer queue for this channel is paused. Resume it in Buffer, then refresh channels.', 'pressqueue' ) );
		}

		if ( 'missing_mastodon_text_limit' === ( $channel->capabilities['unsupported_reason'] ?? null ) ) {
			return self::reason( 'unknown_limit', __( 'Limit unknown', 'pressqueue' ), __( 'Buffer did not report this Mastodon server\'s character limit, so PressQueue keeps it off. Refresh channels to try again.', 'pressqueue' ) );
		}

		if ( 'unsupported' === $channel->availability ) {
			return self::reason( 'missing_permission', __( 'No permission', 'pressqueue' ), __( 'Your Buffer key is not allowed to post to this channel. Check your access to it in Buffer, then refresh channels.', 'pressqueue' ) );
		}

		return self::reason( 'unsupported_capability', __( 'Not supported', 'pressqueue' ), __( 'PressQueue cannot post to this channel yet.', 'pressqueue' ) );
	}

	/**
	 * Return the sort rank of a channel.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @return int
	 */
	private static function rank( ChannelRecord $channel ): int {
		if ( self::can_switch_on( $channel ) ) {
			return 0;
		}

		return 'missing' === $channel->availability ? 2 : 1;
	}

	/**
	 * Explain an unsupported network.
	 *
	 * @param string $service Service slug.
	 * @return string
	 */
	private static function unsupported_service_text( string $service ): string {
		$service = strtolower( $service );

		if ( in_array( $service, array( 'instagram', 'pinterest' ), true ) ) {
			return __( 'Needs image-first posts, which PressQueue does not send yet.', 'pressqueue' );
		}

		if ( in_array( $service, array( 'tiktok', 'youtube' ), true ) ) {
			return __( 'Needs video posts, which PressQueue does not send.', 'pressqueue' );
		}

		return __( 'PressQueue does not post to this network yet.', 'pressqueue' );
	}

	/**
	 * Build one reason entry.
	 *
	 * @param string $key Reason key.
	 * @param string $label Short label.
	 * @param string $text Sentence.
	 * @return array{key:string,label:string,text:string}
	 */
	private static function reason( string $key, string $label, string $text ): array {
		return array(
			'key'   => $key,
			'label' => $label,
			'text'  => $text,
		);
	}
}
