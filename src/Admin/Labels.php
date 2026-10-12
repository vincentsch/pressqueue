<?php
/**
 * Human labels for stored PressQueue values.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

/**
 * Translates internal enum values into words people recognize.
 */
final class Labels {
	/**
	 * Sharing modes.
	 *
	 * @return array<string,array{label:string,help:string}>
	 */
	public static function modes(): array {
		return array(
			'test'   => array(
				'label' => __( 'Test mode', 'pressqueue' ),
				'help'  => __( 'Every post is checked and recorded. Nothing is sent to Buffer.', 'pressqueue' ),
			),
			'live'   => array(
				'label' => __( 'Live', 'pressqueue' ),
				'help'  => __( 'Posts that match a rule are sent to Buffer.', 'pressqueue' ),
			),
			'paused' => array(
				'label' => __( 'Paused', 'pressqueue' ),
				'help'  => __( 'Nothing is sent. Shares that are waiting stay on hold until you resume them.', 'pressqueue' ),
			),
		);
	}

	/**
	 * Return a service display name.
	 *
	 * @param string $service Service slug.
	 * @return string
	 */
	public static function service( string $service ): string {
		$names = array(
			'bluesky'        => 'Bluesky',
			'facebook'       => 'Facebook',
			'googlebusiness' => 'Google Business',
			'instagram'      => 'Instagram',
			'linkedin'       => 'LinkedIn',
			'mastodon'       => 'Mastodon',
			'pinterest'      => 'Pinterest',
			'startpage'      => 'Start Page',
			'threads'        => 'Threads',
			'tiktok'         => 'TikTok',
			'twitter'        => 'X',
			'x'              => 'X',
			'youtube'        => 'YouTube',
		);

		$service = strtolower( $service );

		return $names[ $service ] ?? ucfirst( $service );
	}

	/**
	 * Return "Network (account)" so channels with the same name stay distinguishable in text.
	 *
	 * @param string $service Service slug.
	 * @param string $name Channel display name.
	 * @return string
	 */
	public static function channel_label( string $service, string $name ): string {
		$network = self::service( $service );
		$name    = trim( $name );

		if ( '' === $name || 0 === strcasecmp( $name, $network ) ) {
			return $network;
		}

		/* translators: 1: network name such as LinkedIn, 2: account or page name. */
		return sprintf( __( '%1$s (%2$s)', 'pressqueue' ), $network, $name );
	}

	/**
	 * Return the short monogram shown in a service badge.
	 *
	 * @param string $service Service slug.
	 * @return string
	 */
	public static function service_monogram( string $service ): string {
		$monograms = array(
			'bluesky'        => 'B',
			'facebook'       => 'f',
			'googlebusiness' => 'G',
			'instagram'      => 'IG',
			'linkedin'       => 'in',
			'mastodon'       => 'M',
			'pinterest'      => 'P',
			'threads'        => '@',
			'tiktok'         => 'TT',
			'twitter'        => 'X',
			'x'              => 'X',
			'youtube'        => 'YT',
		);

		$service = strtolower( $service );

		return $monograms[ $service ] ?? strtoupper( substr( $service, 0, 1 ) );
	}

	/**
	 * Rule trigger labels.
	 *
	 * @return array<string,string>
	 */
	public static function triggers(): array {
		return array(
			'first_publish'         => __( 'A post is published', 'pressqueue' ),
			'scheduled_publication' => __( 'A scheduled post goes live', 'pressqueue' ),
			'update'                => __( 'A published post is updated', 'pressqueue' ),
			'bulk'                  => __( 'Old posts are picked in Share old posts', 'pressqueue' ),
			'repost'                => __( 'Shared posts are picked to share again', 'pressqueue' ),
		);
	}

	/**
	 * Return a short trigger label.
	 *
	 * @param string $trigger Trigger.
	 * @return string
	 */
	public static function trigger_short( string $trigger ): string {
		$labels = array(
			'first_publish'         => __( 'published', 'pressqueue' ),
			'scheduled_publication' => __( 'published on schedule', 'pressqueue' ),
			'update'                => __( 'updated', 'pressqueue' ),
			'bulk'                  => __( 'picked in Share old posts', 'pressqueue' ),
			'repost'                => __( 'picked to share again', 'pressqueue' ),
		);

		return $labels[ $trigger ] ?? $trigger;
	}

	/**
	 * Rule schedule labels with explanations.
	 *
	 * @return array<string,array{label:string,help:string}>
	 */
	public static function schedules(): array {
		return array(
			'queue_end'  => array(
				'label' => __( 'Add to the end of the Buffer queue', 'pressqueue' ),
				'help'  => __( 'Buffer posts it in your next free time slot after everything already queued.', 'pressqueue' ),
			),
			'queue_next' => array(
				'label' => __( 'Next free queue slot', 'pressqueue' ),
				'help'  => __( 'Goes ahead of posts already waiting in your Buffer queue.', 'pressqueue' ),
			),
			'immediate'  => array(
				'label' => __( 'Post right away', 'pressqueue' ),
				'help'  => __( 'Buffer publishes as soon as PressQueue sends it, usually within a few minutes.', 'pressqueue' ),
			),
			'draft'      => array(
				'label' => __( 'Save as a Buffer draft', 'pressqueue' ),
				'help'  => __( 'Nothing goes public. Review and schedule it in Buffer yourself.', 'pressqueue' ),
			),
		);
	}

	/**
	 * Return a short schedule phrase for sentences.
	 *
	 * @param string $mode Schedule mode.
	 * @return string
	 */
	public static function schedule_phrase( string $mode ): string {
		$phrases = array(
			'queue_end'     => __( 'end of the Buffer queue', 'pressqueue' ),
			'queue_next'    => __( 'next free Buffer queue slot', 'pressqueue' ),
			'immediate'     => __( 'right away', 'pressqueue' ),
			'draft'         => __( 'Buffer draft for review', 'pressqueue' ),
			'explicit_time' => __( 'chosen date and time', 'pressqueue' ),
		);

		return $phrases[ $mode ] ?? $mode;
	}

	/**
	 * Image (media) policy labels.
	 *
	 * @return array<string,array{label:string,help:string}>
	 */
	public static function media_modes(): array {
		return array(
			'featured_image_optional' => array(
				'label' => __( 'Featured image when there is one', 'pressqueue' ),
				'help'  => __( 'Posts without a featured image are shared as text.', 'pressqueue' ),
			),
			'featured_image'          => array(
				'label' => __( 'Only share posts that have a featured image', 'pressqueue' ),
				'help'  => __( 'A post without a usable featured image is held back.', 'pressqueue' ),
			),
			'none'                    => array(
				'label' => __( 'No image', 'pressqueue' ),
				'help'  => __( 'The social network may still show a link preview.', 'pressqueue' ),
			),
			'post_media'              => array(
				'label' => __( 'Images chosen in each post', 'pressqueue' ),
				'help'  => __( 'Writers pick the image in the post editor.', 'pressqueue' ),
			),
		);
	}

	/**
	 * Return a short media phrase for sentences.
	 *
	 * @param string $mode Media mode.
	 * @return string
	 */
	public static function media_phrase( string $mode ): string {
		$phrases = array(
			'featured_image_optional' => __( 'with the featured image when there is one', 'pressqueue' ),
			'featured_image'          => __( 'with the featured image', 'pressqueue' ),
			'none'                    => __( 'without an image', 'pressqueue' ),
			'post_media'              => __( 'with the images chosen in the post', 'pressqueue' ),
		);

		return $phrases[ $mode ] ?? '';
	}

	/**
	 * Map a delivery state to a label, tone and explanation.
	 *
	 * @param string $state Delivery state.
	 * @return array{label:string,tone:string,help:string}
	 */
	public static function delivery_state( string $state ): array {
		$states = array(
			'dry_run'   => array( __( 'Test run', 'pressqueue' ), 'idle', __( 'Recorded in test mode. Nothing was sent.', 'pressqueue' ) ),
			'proposed'  => array( __( 'Waiting', 'pressqueue' ), 'info', __( 'Ready to be sent to Buffer by the background worker.', 'pressqueue' ) ),
			'queued'    => array( __( 'Waiting', 'pressqueue' ), 'info', __( 'Queued for the background worker.', 'pressqueue' ) ),
			'leased'    => array( __( 'Sending', 'pressqueue' ), 'info', __( 'The background worker is sending this to Buffer.', 'pressqueue' ) ),
			'retrying'  => array( __( 'Retrying', 'pressqueue' ), 'warn', __( 'The last try failed for a temporary reason. PressQueue will try again.', 'pressqueue' ) ),
			'held'      => array( __( 'On hold', 'pressqueue' ), 'warn', __( 'Held while PressQueue was paused or a rule changed. Resume it to send.', 'pressqueue' ) ),
			'accepted'  => array( __( 'Sent to Buffer', 'pressqueue' ), 'ok', __( 'Buffer accepted it. Buffer publishes it according to the chosen timing.', 'pressqueue' ) ),
			'failed'    => array( __( 'Failed', 'pressqueue' ), 'bad', __( 'Buffer did not accept it.', 'pressqueue' ) ),
			'ambiguous' => array( __( 'Check Buffer', 'pressqueue' ), 'bad', __( 'Buffer may have received this. Check Buffer before you retry so it is not posted twice.', 'pressqueue' ) ),
			'cancelled' => array( __( 'Cancelled', 'pressqueue' ), 'idle', __( 'Cancelled in PressQueue.', 'pressqueue' ) ),
		);

		$entry = $states[ $state ] ?? array( $state, 'idle', '' );

		return array(
			'label' => $entry[0],
			'tone'  => $entry[1],
			'help'  => $entry[2],
		);
	}

	/**
	 * Map a decision result to a label, tone and explanation.
	 *
	 * Besides the stored results, "switched_off" names an exclusion that comes only from
	 * the post's own "Share this post" switch. It is a choice, not a problem, so it reads
	 * calmly instead of like a "never share" rule.
	 *
	 * @param string $result Decision result, or switched_off.
	 * @return array{label:string,tone:string,help:string}
	 */
	public static function decision_result( string $result ): array {
		$results = array(
			'included'     => array( __( 'Matched', 'pressqueue' ), 'ok', __( 'A sharing rule matched this post.', 'pressqueue' ) ),
			'excluded'     => array( __( 'Never share', 'pressqueue' ), 'bad', __( 'A "never share" rule matches this post.', 'pressqueue' ) ),
			'switched_off' => array( __( 'Sharing off', 'pressqueue' ), 'idle', __( '"Share this post" was switched off for this post.', 'pressqueue' ) ),
			'skipped'      => array( __( 'Not shared', 'pressqueue' ), 'idle', __( 'PressQueue checked this post and did not share it.', 'pressqueue' ) ),
			'policy_error' => array( __( 'Blocked by an error', 'pressqueue' ), 'bad', __( 'A rule has a problem, so the post was stopped to be safe.', 'pressqueue' ) ),
		);

		$entry = $results[ $result ] ?? array( $result, 'idle', '' );

		return array(
			'help'  => $entry[2],
			'label' => $entry[0],
			'tone'  => $entry[1],
		);
	}

	/**
	 * Return a plain sentence for a planning reason code.
	 *
	 * @param string $code Reason code.
	 * @param string $fallback Stored label.
	 * @return string
	 */
	public static function reason( string $code, string $fallback = '' ): string {
		$reasons = array(
			'explicit_post_exclusion'                 => __( 'Sharing is switched off for this post.', 'pressqueue' ),
			'backdated_publication'                   => __( 'The post is dated more than a day in the past, so it is not shared automatically. Share it with Share now in the post editor, or with Share old posts.', 'pressqueue' ),
			'no_enabled_rules'                        => __( 'No rule is switched on for this kind of event.', 'pressqueue' ),
			'no_matching_rules'                       => __( 'No sharing rule matches this post.', 'pressqueue' ),
			'empty_destination_set'                   => __( 'The matching rule has no channels selected.', 'pressqueue' ),
			'omitted_by_channel_override'             => __( 'The post settings leave this channel out.', 'pressqueue' ),
			'requested_channel_not_selected_by_rules' => __( 'The post asked for a channel that no matching rule uses.', 'pressqueue' ),
			'superseded_by_rule'                      => __( 'Another rule with a lower order number also matched and was used.', 'pressqueue' ),
			'included_destination'                    => __( 'Shared by the matching rule.', 'pressqueue' ),
			'first_publish_duplicate'                 => __( 'Already shared to this channel, so it is not shared twice.', 'pressqueue' ),
			'trigger_not_selected'                    => __( 'The rule does not run for this kind of event.', 'pressqueue' ),
			'missing_channel'                         => __( 'The channel no longer exists in Buffer.', 'pressqueue' ),
			'disabled_channel'                        => __( 'The channel is switched off.', 'pressqueue' ),
			'unavailable_channel'                     => __( 'Buffer reports that this channel is not available.', 'pressqueue' ),
			'unsupported_channel_capability'          => __( 'PressQueue cannot post this kind of content to this channel.', 'pressqueue' ),
			'missing_connection'                      => __( 'The Buffer connection was removed.', 'pressqueue' ),
			'disabled_connection'                     => __( 'The Buffer connection is switched off.', 'pressqueue' ),
			'unverified_connection'                   => __( 'The Buffer connection has not been checked yet.', 'pressqueue' ),
			'destination_identity_mismatch'           => __( 'The channel belongs to a different Buffer organization.', 'pressqueue' ),
			'destination_available'                   => __( 'The channel is ready.', 'pressqueue' ),
			'blocked_by_exclusion_error'              => __( 'A "never share" rule could not be checked, so the post was stopped to be safe.', 'pressqueue' ),
			'blocked_by_destination_policy_error'     => __( 'Another rule for this channel had an error, so the post was stopped to be safe.', 'pressqueue' ),
			'render_policy_error'                     => __( 'The message could not be built from the rule.', 'pressqueue' ),
			'malformed_rule'                          => __( 'The rule has an error and needs to be fixed.', 'pressqueue' ),
			'duplicate_delivery'                      => __( 'This share already exists.', 'pressqueue' ),
			'post_facts_redacted'                     => __( 'Details are hidden because you cannot edit this post.', 'pressqueue' ),
		);

		if ( isset( $reasons[ $code ] ) ) {
			return $reasons[ $code ];
		}

		if ( str_starts_with( $code, 'invalid_' ) ) {
			return __( 'A rule condition is invalid, so the post was stopped to be safe.', 'pressqueue' );
		}

		return '' !== $fallback ? $fallback : $code;
	}
}
