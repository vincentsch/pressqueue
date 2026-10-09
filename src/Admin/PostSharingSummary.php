<?php
/**
 * Plain-language sharing summaries for the post editor.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Delivery\DeliveryQueryRepository;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Editorial\PostControlPreviewService;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\SettingsRepository;

/**
 * Turns previews, settings and delivery history into ready-made editor strings.
 */
final class PostSharingSummary {
	/**
	 * Reason codes that explain why nothing is shared, strongest first.
	 */
	private const REASON_PRIORITY = array(
		'explicit_post_exclusion',
		'blocked_by_exclusion_error',
		'blocked_by_destination_policy_error',
		'malformed_rule',
		'backdated_publication',
		'no_enabled_rules',
		'no_matching_rules',
		'empty_destination_set',
		'render_policy_error',
		'omitted_by_channel_override',
		'requested_channel_not_selected_by_rules',
		'first_publish_duplicate',
		'disabled_channel',
		'unavailable_channel',
		'missing_channel',
		'unsupported_channel_capability',
		'disabled_connection',
		'missing_connection',
		'unverified_connection',
		'destination_identity_mismatch',
		'duplicate_delivery',
	);

	/**
	 * Build the editor display block for a planning preview.
	 *
	 * @param array<string,mixed> $result PlanningResult::to_array() output.
	 * @return array<string,mixed>
	 */
	public static function from_preview( array $result ): array {
		$summary    = new ActivitySummary();
		$event      = isset( $result['event'] ) && is_array( $result['event'] ) ? $result['event'] : array();
		$trigger    = is_string( $event['trigger'] ?? null ) ? $event['trigger'] : 'first_publish';
		$deliveries = isset( $result['deliveries'] ) && is_array( $result['deliveries'] ) ? $result['deliveries'] : array();
		$decisions  = isset( $result['decisions'] ) && is_array( $result['decisions'] ) ? $result['decisions'] : array();
		$channels   = array();
		$shared_ids = array();
		$message    = array(
			'limit'         => 0,
			'limit_service' => '',
			'rule_message'  => '',
			'url_length'    => 0,
		);
		$rule_id    = '';

		foreach ( $deliveries as $delivery ) {
			if ( ! is_array( $delivery ) || ! isset( $delivery['payload_preview'] ) || ! is_array( $delivery['payload_preview'] ) ) {
				continue;
			}

			$payload      = $delivery['payload_preview'];
			$channel_id   = (string) ( $delivery['channel_public_id'] ?? ( $payload['channel_public_id'] ?? '' ) );
			$channel      = '' === $channel_id ? null : $summary->channel( $channel_id );
			$service      = null === $channel ? (string) ( $payload['service'] ?? '' ) : $channel->service;
			$shared_ids[] = $channel_id;
			$channels[]   = array_merge(
				self::channel_identity( $channel_id, $service, null === $channel ? (string) ( $payload['channel_label'] ?? '' ) : ActivitySummary::channel_display_name( $channel ) ),
				array(
					'image'  => self::image_text( $payload ),
					'timing' => self::timing_text( $payload ),
				)
			);

			if ( '' === $rule_id && is_string( $payload['rule_public_id'] ?? null ) ) {
				$rule_id = $payload['rule_public_id'];
			}

			if ( '' === $message['rule_message'] && is_string( $payload['message'] ?? null ) ) {
				$message['rule_message'] = trim( $payload['message'] );
				$message['url_length']   = self::length( (string) ( $payload['canonical_url'] ?? '' ) );
			}

			$capability = isset( $payload['capability_decision'] ) && is_array( $payload['capability_decision'] ) ? $payload['capability_decision'] : array();
			$limit      = isset( $capability['text_limit'] ) ? (int) $capability['text_limit'] : 0;
			if ( $limit > 0 && ( 0 === $message['limit'] || $limit < $message['limit'] ) ) {
				$message['limit']         = $limit;
				$message['limit_service'] = Labels::service( $service );
			}
		}

		$will_share = array() !== $channels;
		$details    = '';
		if ( $will_share ) {
			$timings = array_unique( array_column( $channels, 'timing' ) );
			$images  = array_unique( array_column( $channels, 'image' ) );
			if ( 1 === count( $timings ) && 1 === count( $images ) ) {
				$details = self::join_details( (string) reset( $timings ), (string) reset( $images ) );
			}
		}

		return array(
			'channels'   => $channels,
			'details'    => $details,
			'heading'    => self::heading( $trigger ),
			'left_out'   => $will_share ? self::left_out_channels( $decisions, $shared_ids, $summary ) : array(),
			'message'    => $message,
			'reason'     => $will_share ? '' : self::not_shared_reason( $decisions, $summary ),
			'rule'       => self::rule_facts( $rule_id ),
			'trigger'    => $trigger,
			'will_share' => $will_share,
		);
	}

	/**
	 * Build a preview display for the saved state of a post.
	 *
	 * @param \WP_Post            $post Post.
	 * @param array<string,mixed> $bundle Stored control bundle.
	 * @return array<string,mixed>|null Null when no preview can be built.
	 */
	public static function saved_post_preview( \WP_Post $post, array $bundle ): ?array {
		if ( ! self::storage_ready() ) {
			return null;
		}

		try {
			$service = new PostControlPreviewService();
			$result  = $service->preview( (int) $post->ID, self::saved_post_snapshot( $post ), $bundle );

			return self::from_preview( $result->to_array() );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return null;
		}
	}

	/**
	 * Build a dirty-editor style snapshot from the saved post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	public static function saved_post_snapshot( \WP_Post $post ): array {
		$published_at = self::gmt_or_now( (string) $post->post_date_gmt );
		$status       = self::preview_status( (string) $post->post_status, $published_at );
		$terms        = array();

		if ( function_exists( 'get_object_taxonomies' ) && function_exists( 'wp_get_object_terms' ) ) {
			$taxonomies = get_object_taxonomies( (string) $post->post_type );
			$found      = array() === $taxonomies ? array() : wp_get_object_terms( (int) $post->ID, $taxonomies );
			if ( is_array( $found ) ) {
				foreach ( $found as $term ) {
					if ( $term instanceof \WP_Term ) {
						$terms[] = array(
							'taxonomy' => (string) $term->taxonomy,
							'term_id'  => (int) $term->term_id,
						);
					}
				}
			}
		}

		$metadata = array( PostControlMetadata::LEGACY_META_KEY => null );
		foreach ( self::rule_metadata_keys() as $key ) {
			if ( PostControlMetadata::is_control_meta_key( $key ) ) {
				continue;
			}
			$value            = get_post_meta( (int) $post->ID, $key, true );
			$metadata[ $key ] = '' === $value ? null : $value;
		}

		$link = function_exists( 'get_permalink' ) ? get_permalink( $post ) : '';

		return array(
			'author_id'        => (int) $post->post_author,
			'canonical_url'    => is_string( $link ) ? $link : '',
			'content'          => (string) $post->post_content,
			'excerpt'          => (string) $post->post_excerpt,
			'metadata'         => $metadata,
			'modified_at_gmt'  => self::gmt_or_zero( (string) $post->post_modified_gmt ),
			'post_status'      => $status,
			'post_type'        => (string) $post->post_type,
			'published_at_gmt' => $published_at,
			'terms'            => $terms,
			'title'            => (string) $post->post_title,
		);
	}

	/**
	 * Return site facts the editor panel needs.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function editor_context( int $post_id ): array {
		$settings  = new SettingsRepository();
		$mode      = 'test';
		$new_posts = 'automatic';

		try {
			$mode      = $settings->sharing_mode();
			$new_posts = $settings->new_post_sharing();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}

		$post   = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		$status = $post instanceof \WP_Post ? (string) $post->post_status : '';

		return array(
			'activity_url'     => self::activity_url( $post_id ),
			'channels'         => self::channels(),
			'mode_note'        => self::mode_note( $mode ),
			'new_post_sharing' => $new_posts,
			'opt_in_hint'      => 'opt_in' === $new_posts ? __( 'On this site, new posts start with sharing off.', 'pressqueue' ) : '',
			'post_status'      => $status,
			'sharing_mode'     => $mode,
			'timezone'         => self::site_timezone(),
			'timezone_label'   => self::site_timezone_label(),
		);
	}

	/**
	 * Return the site time zone as a named zone that PHP and the schedule resolver accept.
	 *
	 * Sites with a manual UTC offset get the matching fixed zone, for example Etc/GMT-2 for +02:00.
	 *
	 * @return string
	 */
	public static function site_timezone(): string {
		$zone = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';

		if ( 1 !== preg_match( '/^([+-])(\d{2}):(\d{2})$/', $zone, $parts ) ) {
			return '' === $zone ? 'UTC' : $zone;
		}

		$hours   = (int) $parts[2];
		$minutes = (int) $parts[3];
		if ( 0 === $hours && 0 === $minutes ) {
			return 'UTC';
		}

		$limit = '+' === $parts[1] ? 14 : 12;
		if ( 0 === $minutes && $hours <= $limit ) {
			return 'Etc/GMT' . ( '+' === $parts[1] ? '-' : '+' ) . $hours;
		}

		return $zone;
	}

	/**
	 * Return the site time zone as people know it from Settings, General.
	 *
	 * @return string
	 */
	public static function site_timezone_label(): string {
		$zone = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';

		return 1 === preg_match( '/^[+-]\d{2}:\d{2}$/', $zone ) ? 'UTC' . $zone : $zone;
	}

	/**
	 * Return the note shown under the switch for the current sharing mode.
	 *
	 * @param string $mode Sharing mode.
	 * @return string
	 */
	public static function mode_note( string $mode ): string {
		if ( 'test' === $mode ) {
			return __( 'Test mode is on. Nothing is sent to Buffer yet.', 'pressqueue' );
		}

		if ( 'paused' === $mode ) {
			return __( 'Sharing is paused. Nothing is sent to Buffer until it is resumed in PressQueue settings.', 'pressqueue' );
		}

		return '';
	}

	/**
	 * Return what happened to a post per channel.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function activity( int $post_id ): array {
		$output = array(
			'items'            => array(),
			'not_shared'       => '',
			'not_shared_title' => '',
			'url'              => self::activity_url( $post_id ),
		);

		if ( ! self::storage_ready() ) {
			return $output;
		}

		try {
			$summary = new ActivitySummary();
			$rows    = ( new DeliveryQueryRepository() )->query( array( 'post_id' => $post_id ), 1, 25 )['rows'];
			$seen    = array();

			foreach ( $rows as $delivery ) {
				if ( isset( $seen[ $delivery->channel_public_id ] ) ) {
					continue;
				}

				$seen[ $delivery->channel_public_id ] = true;
				$channel                              = $summary->channel( $delivery->channel_public_id );
				$state                                = Labels::delivery_state( $delivery->state );
				$time                                 = $delivery->state_changed_at ?? $delivery->updated_at;
				$output['items'][]                    = array_merge(
					self::channel_identity(
						$delivery->channel_public_id,
						null === $channel ? (string) ( $delivery->payload_preview['service'] ?? '' ) : $channel->service,
						null === $channel ? (string) ( $delivery->payload_preview['channel_label'] ?? '' ) : ActivitySummary::channel_display_name( $channel )
					),
					array(
						'help'      => $state['help'],
						'label'     => $state['label'],
						'state'     => $delivery->state,
						'time'      => Ui::relative_time( $time ),
						'time_full' => Ui::local_datetime( $time ),
						'tone'      => $state['tone'],
					)
				);
			}

			$order = array_flip( array_column( self::channels(), 'public_id' ) );
			usort(
				$output['items'],
				static function ( array $left, array $right ) use ( $order ): int {
					return ( $order[ $left['public_id'] ] ?? PHP_INT_MAX ) <=> ( $order[ $right['public_id'] ] ?? PHP_INT_MAX );
				}
			);

			if ( array() === $output['items'] ) {
				$event = self::latest_runtime_event( $post_id );
				if ( null !== $event ) {
					$output['not_shared_title'] = 'update' === $event['trigger']
						? __( 'Not shared when it was last updated.', 'pressqueue' )
						: __( 'Not shared when it was published.', 'pressqueue' );
					$output['not_shared']       = self::not_shared_reason( $event['decisions'], $summary );
				}
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}

		return $output;
	}

	/**
	 * Return the Activity screen URL filtered to one post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function activity_url( int $post_id ): string {
		if ( ! function_exists( 'admin_url' ) || ! function_exists( 'add_query_arg' ) ) {
			return '';
		}

		return Ui::url( Ui::PAGE_ACTIVITY, array( 'post_id' => $post_id ) );
	}

	/**
	 * Return channels people can pick in the editor.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function channels(): array {
		if ( ! self::storage_ready() ) {
			return array();
		}

		try {
			$in_rules = array();
			foreach ( ( new RuleRepository() )->find_enabled() as $rule ) {
				if ( 'include' !== $rule->effect || ! isset( $rule->destination_set['destinations'] ) || ! is_array( $rule->destination_set['destinations'] ) ) {
					continue;
				}

				foreach ( $rule->destination_set['destinations'] as $destination ) {
					if ( is_array( $destination ) && is_string( $destination['channel_public_id'] ?? null ) ) {
						$in_rules[ $destination['channel_public_id'] ] = true;
					}
				}
			}

			$channels = ( new ChannelRepository() )->find_all();
			$order    = array_flip( array_keys( $in_rules ) );
			usort(
				$channels,
				static function ( ChannelRecord $left, ChannelRecord $right ) use ( $order ): int {
					$by_rule = ( $order[ $left->public_id ] ?? PHP_INT_MAX ) <=> ( $order[ $right->public_id ] ?? PHP_INT_MAX );

					return 0 !== $by_rule ? $by_rule : strcmp( Labels::service( $left->service ), Labels::service( $right->service ) );
				}
			);

			return array_map(
				static function ( ChannelRecord $channel ) use ( $in_rules ): array {
					return array_merge(
						self::channel_identity( $channel->public_id, $channel->service, ActivitySummary::channel_display_name( $channel ) ),
						array(
							'in_rules' => isset( $in_rules[ $channel->public_id ] ),
							'usable'   => $channel->enabled && 'available' === $channel->availability,
						)
					);
				},
				$channels
			);
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array();
		}
	}

	/**
	 * Return the heading shown above the channels.
	 *
	 * @param string $trigger Simulated trigger.
	 * @return string
	 */
	public static function heading( string $trigger ): string {
		if ( 'scheduled_publication' === $trigger ) {
			return __( 'When the post goes live as scheduled, PressQueue sends it to:', 'pressqueue' );
		}

		if ( 'update' === $trigger ) {
			return __( 'When you update this post, PressQueue sends it to:', 'pressqueue' );
		}

		return __( 'When you publish, PressQueue sends it to:', 'pressqueue' );
	}

	/**
	 * Pick one plain sentence that explains why nothing is shared.
	 *
	 * @param array<int,mixed>     $decisions Decision arrays from a preview, or stored records.
	 * @param ActivitySummary|null $summary Lookup helper.
	 * @return string
	 */
	public static function not_shared_reason( array $decisions, ?ActivitySummary $summary = null ): string {
		$codes = array();

		foreach ( $decisions as $decision ) {
			if ( $decision instanceof DecisionRecord ) {
				$decision = array(
					'reasons'        => $decision->reasons,
					'result'         => $decision->result,
					'rule_public_id' => $decision->rule_public_id,
				);
			}

			if ( ! is_array( $decision ) ) {
				continue;
			}

			$rule_id = is_string( $decision['rule_public_id'] ?? null ) ? $decision['rule_public_id'] : '';
			if ( 'excluded' === ( $decision['result'] ?? '' ) && '' !== $rule_id ) {
				$name = null === $summary ? '' : $summary->rule_name( $rule_id );
				if ( '' === $name ) {
					return __( 'A never-share rule matches this post.', 'pressqueue' );
				}

				/* translators: %s: rule name. */
				return sprintf( __( 'The never-share rule "%s" matches this post.', 'pressqueue' ), $name );
			}

			$reasons = isset( $decision['reasons'] ) && is_array( $decision['reasons'] ) ? $decision['reasons'] : array();
			foreach ( $reasons as $reason ) {
				if ( is_array( $reason ) && is_string( $reason['code'] ?? null ) && ! isset( $codes[ $reason['code'] ] ) ) {
					$codes[ $reason['code'] ] = is_string( $reason['label'] ?? null ) ? $reason['label'] : '';
				}
			}
		}

		foreach ( self::REASON_PRIORITY as $code ) {
			if ( isset( $codes[ $code ] ) ) {
				return Labels::reason( $code, $codes[ $code ] );
			}
		}

		foreach ( array_keys( $codes ) as $code ) {
			if ( str_starts_with( (string) $code, 'invalid_' ) ) {
				return Labels::reason( (string) $code );
			}
		}

		return Labels::reason( 'no_matching_rules' );
	}

	/**
	 * Return channels that the rules picked but that will not get this post.
	 *
	 * @param array<int,mixed>  $decisions Decisions.
	 * @param array<int,string> $shared_ids Channels that get the post.
	 * @param ActivitySummary   $summary Lookup helper.
	 * @return array<int,array<string,string>>
	 */
	private static function left_out_channels( array $decisions, array $shared_ids, ActivitySummary $summary ): array {
		$output = array();

		foreach ( $decisions as $decision ) {
			if ( ! is_array( $decision ) || 'channel' !== ( $decision['scope_type'] ?? '' ) || 'included' === ( $decision['result'] ?? '' ) ) {
				continue;
			}

			$channel_id = (string) ( $decision['scope_public_id'] ?? '' );
			if ( '' === $channel_id || in_array( $channel_id, $shared_ids, true ) || isset( $output[ $channel_id ] ) ) {
				continue;
			}

			$channel = $summary->channel( $channel_id );
			if ( null === $channel ) {
				continue;
			}

			$output[ $channel_id ] = array_merge(
				self::channel_identity( $channel_id, $channel->service, ActivitySummary::channel_display_name( $channel ) ),
				array( 'reason' => self::not_shared_reason( array( $decision ), $summary ) )
			);
		}

		return array_values( $output );
	}

	/**
	 * Return the rule that produced the shares, described in words.
	 *
	 * @param string $rule_public_id Rule public ID.
	 * @return array<string,string>
	 */
	private static function rule_facts( string $rule_public_id ): array {
		$facts = array(
			'image'  => '',
			'name'   => '',
			'timing' => '',
		);

		if ( '' === $rule_public_id ) {
			return $facts;
		}

		try {
			$rule = ( new RuleRepository() )->find_by_public_id( $rule_public_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return $facts;
		}

		if ( null === $rule ) {
			return $facts;
		}

		$schedules = Labels::schedules();
		$media     = Labels::media_modes();
		$mode      = is_string( $rule->media_policy['mode'] ?? null ) ? $rule->media_policy['mode'] : 'none';

		$facts['name']   = $rule->name;
		$facts['timing'] = $schedules[ $rule->schedule_policy ]['label'] ?? ucfirst( Labels::schedule_phrase( $rule->schedule_policy ) );
		$facts['image']  = $media[ $mode ]['label'] ?? '';

		return $facts;
	}

	/**
	 * Return the newest runtime event recorded for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{trigger:string,decisions:array<int,DecisionRecord>}|null
	 */
	private static function latest_runtime_event( int $post_id ): ?array {
		$database = Database::from_globals();
		$rows     = $database->get_results(
			'SELECT public_id FROM `' . $database->table( Schema::DECISIONS ) . '` WHERE post_id = %d AND event_mode = %s ORDER BY created_at DESC, id DESC LIMIT 50',
			array( $post_id, 'runtime' )
		);

		$repository = new DecisionRepository( $database );
		$events     = array();
		foreach ( $rows as $row ) {
			$record = isset( $row->public_id ) ? $repository->find_by_public_id( (string) $row->public_id ) : null;
			if ( null === $record ) {
				continue;
			}

			if ( ! isset( $events[ $record->event_key ] ) ) {
				$events[ $record->event_key ] = array(
					'decisions' => array(),
					'trigger'   => $record->trigger,
				);
			}

			$events[ $record->event_key ]['decisions'][] = $record;
		}

		foreach ( $events as $event ) {
			if ( in_array( $event['trigger'], array( 'first_publish', 'scheduled_publication' ), true ) ) {
				return $event;
			}
		}

		$first = reset( $events );

		return false === $first ? null : $first;
	}

	/**
	 * Return the shared channel identity fields.
	 *
	 * @param string $public_id Channel public ID.
	 * @param string $service Service slug.
	 * @param string $name Display name.
	 * @return array<string,string>
	 */
	private static function channel_identity( string $public_id, string $service, string $name ): array {
		$service = strtolower( $service );

		return array(
			'monogram'     => Labels::service_monogram( $service ),
			'name'         => '' === $name ? Labels::service( $service ) : $name,
			'public_id'    => $public_id,
			'service'      => $service,
			'service_name' => Labels::service( $service ),
		);
	}

	/**
	 * Return the timing of one share in words.
	 *
	 * @param array<string,mixed> $payload Payload preview.
	 * @return string
	 */
	private static function timing_text( array $payload ): string {
		$slot = (string) ( $payload['schedule_slot'] ?? '' );

		if ( 'explicit_time' === $slot && is_string( $payload['scheduled_for_utc'] ?? null ) && '' !== $payload['scheduled_for_utc'] ) {
			try {
				/* translators: %s: local date and time. */
				return sprintf( __( 'On %s', 'pressqueue' ), Ui::local_datetime( new \DateTimeImmutable( $payload['scheduled_for_utc'], new \DateTimeZone( 'UTC' ) ) ) );
			} catch ( \Exception $exception ) {
				unset( $exception );
			}
		}

		return ucfirst( Labels::schedule_phrase( '' === $slot ? 'immediate' : $slot ) );
	}

	/**
	 * Return the image of one share in words.
	 *
	 * @param array<string,mixed> $payload Payload preview.
	 * @return string
	 */
	private static function image_text( array $payload ): string {
		$media = isset( $payload['media_snapshot'] ) && is_array( $payload['media_snapshot'] ) ? $payload['media_snapshot'] : array();
		$items = isset( $media['items'] ) && is_array( $media['items'] ) ? $media['items'] : array();
		$mode  = is_string( $media['mode'] ?? null ) ? $media['mode'] : 'none';

		if ( array() === $items ) {
			return Labels::media_phrase( 'none' );
		}

		if ( 'post_media' === $mode ) {
			/* translators: %d: number of images. */
			return sprintf( _n( 'with %d chosen image', 'with %d chosen images', count( $items ), 'pressqueue' ), count( $items ) );
		}

		return Labels::media_phrase( 'featured_image' );
	}

	/**
	 * Join timing and image into one line.
	 *
	 * @param string $timing Timing text.
	 * @param string $image Image text.
	 * @return string
	 */
	private static function join_details( string $timing, string $image ): string {
		return '' === $image ? $timing : $timing . ' · ' . $image;
	}

	/**
	 * Return metadata keys that enabled rules read.
	 *
	 * @return array<int,string>
	 */
	private static function rule_metadata_keys(): array {
		if ( ! self::storage_ready() ) {
			return array();
		}

		$keys = array();
		try {
			foreach ( ( new RuleRepository() )->find_enabled() as $rule ) {
				$keys = array_merge( $keys, $rule->custom_field_allowlist );
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array();
		}

		return array_values( array_unique( array_filter( $keys, 'is_string' ) ) );
	}

	/**
	 * Return the status PressQueue sees when it looks at the post: published, scheduled or updated.
	 *
	 * @param string $saved_status Saved post status.
	 * @param string $published_at Publication date in UTC.
	 * @return string
	 */
	private static function preview_status( string $saved_status, string $published_at ): string {
		if ( in_array( $saved_status, array( 'private', 'publish', 'future' ), true ) ) {
			return $saved_status;
		}

		$timestamp = strtotime( $published_at . ' UTC' );

		return false !== $timestamp && $timestamp > time() ? 'future' : 'publish';
	}

	/**
	 * Return a GMT datetime, or now for posts without a fixed date.
	 *
	 * @param string $value GMT datetime.
	 * @return string
	 */
	private static function gmt_or_now( string $value ): string {
		$value = self::gmt_or_zero( $value );

		return '0000-00-00 00:00:00' === $value ? gmdate( 'Y-m-d H:i:s' ) : $value;
	}

	/**
	 * Return a GMT datetime or the WordPress zero date.
	 *
	 * @param string $value GMT datetime.
	 * @return string
	 */
	private static function gmt_or_zero( string $value ): string {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) && ! str_starts_with( $value, '0000' ) ? $value : '0000-00-00 00:00:00';
	}

	/**
	 * Count characters the way people read them.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Check whether storage-backed facts are available.
	 *
	 * @return bool
	 */
	private static function storage_ready(): bool {
		try {
			return Database::has_global_database() && ( new SchemaStatus() )->is_ready_for_planning();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}
}
