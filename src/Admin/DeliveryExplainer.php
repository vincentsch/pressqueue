<?php
/**
 * Plain-language explanations for shares (deliveries).
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\Record\DeliveryEventRecord;
use PressQueue\Storage\Record\DeliveryRecord;

/**
 * Turns stored delivery states, error classes and events into sentences.
 *
 * Every method returns fixed, translated copy chosen from a whitelist. Stored
 * free text from providers is never echoed back.
 */
final class DeliveryExplainer {
	/**
	 * Error classes that mean an older plugin version planned the share.
	 */
	private const LEGACY_CLASSES = array( 'immutable_identity_missing', 'legacy_encoder_retired', 'unsupported_payload_version', 'unsupported_encoder_version', 'payload_preview_invalid' );

	/**
	 * Error classes for transport or reply problems.
	 */
	private const REPLY_CLASSES = array( 'transport_indeterminate', 'malformed_response', 'oversized_response', 'incomplete_mutation_receipt', 'missing_mutation_payload', 'unknown_mutation_result', 'unknown_graphql_error', 'unexpected_provider_failure', 'redirect_refused', 'unexpected_http_status' );

	/**
	 * Return the one-line note shown under a post title in the share list.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param string|null    $hold_reason Hold classification from the latest held event.
	 * @return array{text:string,tone:string}
	 */
	public static function row_note( DeliveryRecord $delivery, ?string $hold_reason = null ): array {
		switch ( $delivery->state ) {
			case 'failed':
				return array(
					'text' => self::problem_sentence( $delivery ),
					'tone' => 'bad',
				);

			case 'ambiguous':
				if ( 'confirmed_not_accepted' === $delivery->reconciliation_status ) {
					return array(
						'text' => __( 'You confirmed it is not in Buffer. Retry it or cancel it.', 'pressqueue' ),
						'tone' => 'bad',
					);
				}

				return array(
					'text' => self::problem_sentence( $delivery ),
					'tone' => 'bad',
				);

			case 'retrying':
				return array(
					'text' => self::problem_sentence( $delivery ),
					'tone' => 'warn',
				);

			case 'held':
				return array(
					'text' => self::hold_sentence( $hold_reason ),
					'tone' => 'warn',
				);
		}

		return array(
			'text' => '',
			'tone' => 'idle',
		);
	}

	/**
	 * Explain what went wrong for a failed, retrying or ambiguous share.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return string
	 */
	public static function problem_sentence( DeliveryRecord $delivery ): string {
		$error_class = (string) ( $delivery->error_class ?? '' );

		if ( 'retrying' === $delivery->state ) {
			$sentence = self::retry_reason( $error_class );
			if ( null !== $delivery->next_attempt_at ) {
				/* translators: 1: why the last try failed, 2: time of the next try such as "today, 11:05" or "Oct 12, 11:05". */
				return sprintf( __( '%1$s Next try: %2$s.', 'pressqueue' ), $sentence, self::short_time( $delivery->next_attempt_at ) );
			}

			return $sentence . ' ' . __( 'PressQueue will try again.', 'pressqueue' );
		}

		if ( 'ambiguous' === $delivery->state ) {
			return self::ambiguous_reason( $error_class );
		}

		return self::failure_reason( $error_class, $delivery->provider_result );
	}

	/**
	 * Return what to do about a failed share, as sentences with optional links.
	 *
	 * A step whose page is set has one %s in its text, which stands for a link to
	 * that admin page named by its label. Use steps_html() to show them.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param bool           $can_retry Whether the share can be retried by hand.
	 * @return array<int,array{text:string,page:string,label:string}>
	 */
	public static function failure_steps( DeliveryRecord $delivery, bool $can_retry ): array {
		$steps = array();
		$fix   = self::fix_step( (string) ( $delivery->error_class ?? '' ) );

		if ( null !== $fix ) {
			$steps[] = $fix;
		}

		if ( $can_retry ) {
			if ( null !== $fix ) {
				$steps[] = array(
					'label' => '',
					'page'  => '',
					'text'  => __( 'Then retry this share.', 'pressqueue' ),
				);
			}

			return $steps;
		}

		$steps[] = array(
			'label' => __( 'Share old posts', 'pressqueue' ),
			'page'  => Ui::PAGE_BATCHES,
			/* translators: %s: link to the Share old posts screen. */
			'text'  => __( 'This share cannot be sent again. Cancel it, then share the post from %s.', 'pressqueue' ),
		);

		return $steps;
	}

	/**
	 * Render steps as escaped markup, linking pages the viewer may open.
	 *
	 * @param array<int,array{text:string,page:string,label:string}> $steps Steps.
	 * @return string Escaped markup.
	 */
	public static function steps_html( array $steps ): string {
		$pages = array(
			Ui::PAGE_BATCHES  => Capabilities::OPERATE_BATCHES,
			Ui::PAGE_CHANNELS => Capabilities::MANAGE_CONNECTIONS,
			Ui::PAGE_RULES    => Capabilities::MANAGE_RULES,
		);
		$parts = array();

		foreach ( $steps as $step ) {
			if ( '' === $step['page'] ) {
				$parts[] = esc_html( $step['text'] );
				continue;
			}

			$label = esc_html( $step['label'] );
			if ( isset( $pages[ $step['page'] ] ) && current_user_can( $pages[ $step['page'] ] ) ) {
				$label = '<a href="' . esc_url( Ui::url( $step['page'] ) ) . '">' . $label . '</a>';
			}

			$parts[] = sprintf( esc_html( $step['text'] ), $label );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Return the fix for an error class, or null when there is nothing to fix first.
	 *
	 * @param string $error_class Error class.
	 * @return array{text:string,page:string,label:string}|null
	 */
	private static function fix_step( string $error_class ): ?array {
		if ( 'invalid_credential' === $error_class || 'credential_unavailable' === $error_class ) {
			return array(
				'label' => __( 'Channels', 'pressqueue' ),
				'page'  => Ui::PAGE_CHANNELS,
				/* translators: %s: link to the Channels screen. */
				'text'  => __( 'Add a new Buffer API key under %s.', 'pressqueue' ),
			);
		}

		if ( in_array( $error_class, array( 'forbidden', 'not_found', 'capability_mismatch', 'destination_binding_failed' ), true ) ) {
			return array(
				'label' => __( 'Channels', 'pressqueue' ),
				'page'  => Ui::PAGE_CHANNELS,
				/* translators: %s: link to the Channels screen. */
				'text'  => __( 'Check that the channel is still connected in Buffer, then refresh the channels under %s.', 'pressqueue' ),
			);
		}

		if ( in_array( $error_class, array( 'invalid_input', 'mutation_error' ), true ) ) {
			return array(
				'label' => __( 'Rules', 'pressqueue' ),
				'page'  => Ui::PAGE_RULES,
				/* translators: %s: link to the Rules screen. */
				'text'  => __( 'Check the message and image of the rule under %s. Buffer may not accept this kind of post for this channel.', 'pressqueue' ),
			);
		}

		return null;
	}

	/**
	 * Explain why a share is on hold.
	 *
	 * @param string|null $reason Hold classification. Null means the share was created on hold.
	 * @return string
	 */
	public static function hold_sentence( ?string $reason ): string {
		$sentences = array(
			'kill_switch_enabled'      => __( 'Held because PressQueue was paused.', 'pressqueue' ),
			'runtime_disabled'         => __( 'Held because PressQueue background work was switched off on this site.', 'pressqueue' ),
			'dry_run_enabled'          => __( 'Held because PressQueue was switched to test mode.', 'pressqueue' ),
			'schedule_time_expired'    => __( 'Held because the chosen date and time passed before it could be sent.', 'pressqueue' ),
			'policy_check_failed'      => __( 'Held because PressQueue could not check the rules, so it stopped to be safe.', 'pressqueue' ),
			'delivery_policy_missing'  => __( 'Held because the rule behind this share is missing.', 'pressqueue' ),
			'post_not_public'          => __( 'Held because the post is no longer public.', 'pressqueue' ),
			'rule_changed_or_disabled' => __( 'Held because the rule changed or was switched off.', 'pressqueue' ),
			'post_excluded'            => __( 'Held because sharing was switched off for this post.', 'pressqueue' ),
			'post_destination_removed' => __( 'Held because the post settings no longer include this channel.', 'pressqueue' ),
			'rule_conditions_changed'  => __( 'Held because the post no longer matches the rule.', 'pressqueue' ),
			'current_exclusion_policy' => __( 'Held because a "never share" rule now matches this post.', 'pressqueue' ),
			'credential_unavailable'   => __( 'Held because PressQueue could not read the saved Buffer key.', 'pressqueue' ),
		);

		if ( null === $reason ) {
			return __( 'Held because PressQueue was paused when the post was published.', 'pressqueue' );
		}

		return $sentences[ $reason ] ?? __( 'Held until you resume it.', 'pressqueue' );
	}

	/**
	 * Explain why an action is not possible right now.
	 *
	 * Accepts assessment block codes and refusal classifications, with or without
	 * an action prefix such as resume_ or manual_retry_.
	 *
	 * @param string $code Block or refusal code.
	 * @return string
	 */
	public static function block_sentence( string $code ): string {
		$sentences = array(
			'kill_switch_enabled'             => __( 'PressQueue is paused. Switch sharing back to Live first.', 'pressqueue' ),
			'runtime_disabled'                => __( 'PressQueue background work is switched off on this site.', 'pressqueue' ),
			'dry_run_enabled'                 => __( 'PressQueue is in test mode. Switch to Live to send shares.', 'pressqueue' ),
			'immutable_delivery_incompatible' => __( 'This share was not prepared for sending, so it cannot be sent.', 'pressqueue' ),
			'credential_unavailable'          => __( 'PressQueue cannot read the saved Buffer key. Add the key again under Channels.', 'pressqueue' ),
			'capability_mismatch'             => __( 'The channel changed or was switched off after this share was planned. Check it under Channels.', 'pressqueue' ),
			'not_allowed'                     => __( 'This share is no longer in a state where that is possible.', 'pressqueue' ),
			'state_changed'                   => __( 'The share changed while you were looking at it. Check its current status.', 'pressqueue' ),
			'reconciliation_refused'          => __( 'PressQueue could not save that. Check the Buffer post ID and try again. Each Buffer post can belong to only one share.', 'pressqueue' ),
			'remote_post_already_returned'    => __( 'Buffer already returned a post for this share, so it cannot be marked as not in Buffer.', 'pressqueue' ),
			'not_found'                       => __( 'This share no longer exists.', 'pressqueue' ),
			'policy_check_failed'             => __( 'PressQueue could not check the rules, so it stopped to be safe.', 'pressqueue' ),
			'delivery_policy_missing'         => __( 'The rule behind this share is missing.', 'pressqueue' ),
			'post_not_public'                 => __( 'The post is no longer public.', 'pressqueue' ),
			'rule_changed_or_disabled'        => __( 'The rule changed or was switched off after this share was planned.', 'pressqueue' ),
			'post_excluded'                   => __( 'Sharing is switched off for this post.', 'pressqueue' ),
			'post_destination_removed'        => __( 'The post settings no longer include this channel.', 'pressqueue' ),
			'rule_conditions_changed'         => __( 'The post no longer matches the rule.', 'pressqueue' ),
			'current_exclusion_policy'        => __( 'A "never share" rule now matches this post.', 'pressqueue' ),
		);

		$normalized = self::normalize_block_code( $code );

		if ( in_array( $normalized, self::LEGACY_CLASSES, true ) ) {
			return __( 'An older version of PressQueue planned this share, so it cannot be sent.', 'pressqueue' );
		}

		return $sentences[ $normalized ] ?? __( 'PressQueue did not allow it.', 'pressqueue' );
	}

	/**
	 * Return a whitelisted block key for a code, for use in redirect URLs.
	 *
	 * @param string $code Block or refusal code.
	 * @return string
	 */
	public static function normalize_block_code( string $code ): string {
		$code = strtolower( $code );

		foreach ( array( 'resume_', 'manual_retry_', 'cancel_', 'reconciliation_' ) as $prefix ) {
			if ( str_starts_with( $code, $prefix ) ) {
				$rest = substr( $code, strlen( $prefix ) );
				if ( in_array( $rest, array( 'not_allowed', 'state_changed', 'kill_switch_enabled', 'runtime_disabled', 'immutable_delivery_incompatible', 'credential_unavailable' ), true ) ) {
					return $rest;
				}
			}
		}

		if ( 'reconciliation_refused' === $code ) {
			return $code;
		}

		return 1 === preg_match( '/^[a-z_]{1,64}$/', $code ) ? $code : 'unknown';
	}

	/**
	 * Turn a stored redirect detail into a plain sentence, or an empty string.
	 *
	 * @param string $detail Redirect detail.
	 * @return string
	 */
	public static function notice_detail( string $detail ): string {
		if ( 1 === preg_match( '/^Delivery (?:transition|reconciliation) refused: ([a-z_]{1,64})\.$/', $detail, $matches ) ) {
			return self::block_sentence( $matches[1] );
		}

		if ( 'Delivery does not exist.' === $detail ) {
			return self::block_sentence( 'not_found' );
		}

		if ( 'Remote ID is required for this reconciliation outcome.' === $detail ) {
			return __( 'Enter the Buffer post ID first.', 'pressqueue' );
		}

		if ( 'Delivery action confirmation is required.' === $detail ) {
			return __( 'Tick the box first, then click the button again.', 'pressqueue' );
		}

		return \PressQueue\Security\SafeOperatorError::notice_detail( $detail );
	}

	/**
	 * Describe a Buffer receipt status.
	 *
	 * @param string $status Buffer post status.
	 * @return string
	 */
	public static function receipt_status( string $status ): string {
		$statuses = array(
			'buffer'         => __( 'Waiting in the Buffer queue', 'pressqueue' ),
			'scheduled'      => __( 'Scheduled in Buffer', 'pressqueue' ),
			'pending'        => __( 'Waiting in the Buffer queue', 'pressqueue' ),
			'draft'          => __( 'Saved as a draft in Buffer', 'pressqueue' ),
			'needs_approval' => __( 'Waiting for approval in Buffer', 'pressqueue' ),
			'sending'        => __( 'Buffer is publishing it', 'pressqueue' ),
			'sent'           => __( 'Published by Buffer', 'pressqueue' ),
			'error'          => __( 'Buffer could not publish it', 'pressqueue' ),
		);

		$key = strtolower( $status );

		return $statuses[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
	}

	/**
	 * Build the "What happened" timeline.
	 *
	 * @param DeliveryRecord                 $delivery Delivery.
	 * @param DecisionRecord|null            $decision Decision.
	 * @param array<int,DeliveryEventRecord> $events Events, oldest first.
	 * @return array<int,array{time:\DateTimeInterface,text:string,tone:string}>
	 */
	public static function timeline( DeliveryRecord $delivery, ?DecisionRecord $decision, array $events ): array {
		$entries = array();
		$planned = self::planned_sentence( null === $decision ? '' : $decision->trigger );

		if ( 'dry_run' === $delivery->delivery_mode ) {
			$planned .= ' ' . __( 'Test mode was on, so nothing was sent.', 'pressqueue' );
		}

		$entries[] = array(
			'time' => $delivery->created_at,
			'text' => $planned,
			'tone' => 'dry_run' === $delivery->delivery_mode ? 'idle' : 'info',
		);

		$initial_state = array() === $events ? $delivery->state : (string) ( $events[0]->from_state ?? $delivery->state );
		if ( 'held' === $initial_state ) {
			$entries[] = array(
				'time' => $delivery->created_at,
				'text' => self::hold_sentence( null ),
				'tone' => 'warn',
			);
		}

		$last_retry_index = null;
		foreach ( $events as $index => $event ) {
			if ( 'retry_scheduled' === $event->event_type ) {
				$last_retry_index = $index;
			}
		}

		foreach ( $events as $index => $event ) {
			$entry = self::event_entry( $delivery, $event, $index === $last_retry_index );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Return a display name for the actor of an event.
	 *
	 * @param DeliveryEventRecord $event Event.
	 * @return string
	 */
	public static function actor_name( DeliveryEventRecord $event ): string {
		if ( 'operator' !== $event->actor_type ) {
			return __( 'the background worker', 'pressqueue' );
		}

		return self::user_name( $event->actor_user_id );
	}

	/**
	 * Return a user name that survives deleted users.
	 *
	 * @param int|null $user_id User ID.
	 * @return string
	 */
	public static function user_name( ?int $user_id ): string {
		if ( null === $user_id || $user_id <= 0 ) {
			return __( 'someone', 'pressqueue' );
		}

		$user = get_userdata( $user_id );
		if ( $user instanceof \WP_User && '' !== (string) $user->display_name ) {
			return (string) $user->display_name;
		}

		/* translators: %d: user ID. */
		return sprintf( __( 'User #%d (deleted)', 'pressqueue' ), $user_id );
	}

	/**
	 * Return a short local date and time such as "today, 9:00 am" or "Oct 21, 9:00 am".
	 *
	 * @param \DateTimeInterface $time UTC time.
	 * @return string
	 */
	public static function short_time( \DateTimeInterface $time ): string {
		$timestamp   = $time->getTimestamp();
		$time_format = (string) get_option( 'time_format', 'H:i' );
		$clock       = wp_date( $time_format, $timestamp );
		$clock       = is_string( $clock ) ? $clock : '';

		if ( wp_date( 'Y-m-d' ) === wp_date( 'Y-m-d', $timestamp ) ) {
			/* translators: %s: time of day such as "9:00 am". */
			return sprintf( __( 'today, %s', 'pressqueue' ), $clock );
		}

		/* translators: short date format for dates in the current year, see https://www.php.net/manual/datetime.format.php */
		$date_format = __( 'M j', 'pressqueue' );
		if ( wp_date( 'Y' ) !== wp_date( 'Y', $timestamp ) ) {
			/* translators: short date format with the year, see https://www.php.net/manual/datetime.format.php */
			$date_format = __( 'M j, Y', 'pressqueue' );
		}
		$date = wp_date( $date_format, $timestamp );

		/* translators: 1: short date such as "Oct 21", 2: time of day such as "9:00 am". */
		return sprintf( _x( '%1$s, %2$s', 'short date and time', 'pressqueue' ), is_string( $date ) ? $date : '', $clock );
	}

	/**
	 * Return the first timeline sentence.
	 *
	 * @param string $trigger Decision trigger.
	 * @return string
	 */
	private static function planned_sentence( string $trigger ): string {
		$sentences = array(
			'first_publish'         => __( 'Planned when the post was published.', 'pressqueue' ),
			'scheduled_publication' => __( 'Planned when the scheduled post went live.', 'pressqueue' ),
			'update'                => __( 'Planned when the post was updated.', 'pressqueue' ),
			'bulk'                  => __( 'Planned in Share old posts.', 'pressqueue' ),
			'repost'                => __( 'Planned to share the post again.', 'pressqueue' ),
		);

		return $sentences[ $trigger ] ?? __( 'Planned by PressQueue.', 'pressqueue' );
	}

	/**
	 * Map one stored event to a timeline entry.
	 *
	 * @param DeliveryRecord      $delivery Delivery.
	 * @param DeliveryEventRecord $event Event.
	 * @param bool                $is_last_retry Whether this is the latest retry_scheduled event.
	 * @return array{time:\DateTimeInterface,text:string,tone:string}|null
	 */
	private static function event_entry( DeliveryRecord $delivery, DeliveryEventRecord $event, bool $is_last_retry ): ?array {
		$classification = is_string( $event->detail['classification'] ?? null ) ? (string) $event->detail['classification'] : '';
		$actor          = self::actor_name( $event );
		$text           = '';
		$tone           = 'idle';

		switch ( $event->event_type ) {
			case 'queued':
				if ( in_array( $classification, array( 'retry_due', 'retry_worker_due' ), true ) ) {
					/* translators: %s: who queued it, such as "the background worker". */
					$text = sprintf( __( 'Queued for another try by %s.', 'pressqueue' ), $actor );
				} else {
					/* translators: %s: who queued it, such as "the background worker". */
					$text = sprintf( __( 'Queued by %s.', 'pressqueue' ), $actor );
				}
				$tone = 'info';
				break;

			case 'submission_started':
				$attempt = (int) ( $event->attempt_number ?? 1 );
				$text    = __( 'Sent to Buffer.', 'pressqueue' );
				if ( $attempt > 1 ) {
					/* translators: %d: try number. */
					$text = sprintf( __( 'Sent to Buffer (try %d).', 'pressqueue' ), $attempt );
				}
				$tone = 'info';
				break;

			case 'provider_result':
				if ( 'accepted' === $event->to_state ) {
					$text = __( 'Buffer accepted it.', 'pressqueue' );
					$tone = 'ok';
				} else {
					$text = self::ambiguous_reason( $classification );
					$tone = 'bad';
				}
				break;

			case 'retry_scheduled':
				$text = self::retry_reason( $classification );
				if ( $is_last_retry && 'retrying' === $delivery->state && null !== $delivery->next_attempt_at ) {
					/* translators: 1: why the try failed, 2: time of the next try such as "today, 11:05" or "Oct 12, 11:05". */
					$text = sprintf( __( '%1$s Next try: %2$s.', 'pressqueue' ), $text, self::short_time( $delivery->next_attempt_at ) );
				}
				$tone = 'warn';
				break;

			case 'failed':
				$text = true === ( $event->detail['budget_exhausted'] ?? null )
					? __( 'PressQueue tried several times and stopped trying automatically.', 'pressqueue' )
					: self::failure_reason( $classification, $event->provider_result );
				$tone = 'bad';
				break;

			case 'held':
				$text = self::hold_sentence( $classification );
				$tone = 'warn';
				break;

			case 'cancelled':
				$text = __( 'Cancelled.', 'pressqueue' );
				if ( 'operator' === $event->actor_type ) {
					/* translators: %s: person who cancelled it. */
					$text = sprintf( __( 'Cancelled by %s.', 'pressqueue' ), $actor );
				}
				break;

			case 'resumed':
				/* translators: %s: person who resumed it. */
				$text = sprintf( __( 'Resumed by %s.', 'pressqueue' ), $actor );
				$tone = 'info';
				break;

			case 'manual_retry':
				/* translators: %s: person who asked for the retry. */
				$text = sprintf( __( 'Retry requested by %s.', 'pressqueue' ), $actor );
				$tone = 'info';
				break;

			case 'reconciled':
				if ( 'accepted' === $event->to_state ) {
					/* translators: %s: person who checked Buffer. */
					$text = sprintf( __( '%s confirmed it is in Buffer.', 'pressqueue' ), $actor );
					$tone = 'ok';
				} elseif ( 'cancelled' === $event->to_state ) {
					/* translators: %s: person who checked Buffer. */
					$text = sprintf( __( '%s cancelled it after checking Buffer.', 'pressqueue' ), $actor );
				} else {
					/* translators: %s: person who checked Buffer. */
					$text = sprintf( __( '%s confirmed it is not in Buffer.', 'pressqueue' ), $actor );
					$tone = 'warn';
				}
				break;

			case 'refused_transition':
				/* translators: 1: person who tried the action, 2: why it was not possible. */
				$text = sprintf( __( 'An action by %1$s was not possible. %2$s', 'pressqueue' ), $actor, self::block_sentence( $classification ) );
				$tone = 'warn';
				break;

			case 'scheduler_enqueue_failed':
			case 'scheduler_unavailable':
				$text = __( 'The background worker could not be started. PressQueue will try again.', 'pressqueue' );
				$tone = 'warn';
				break;

			case 'lease_recovered':
				$text = __( 'The background worker stopped before sending. The share was queued again.', 'pressqueue' );
				$tone = 'warn';
				break;

			case 'lease_ambiguous':
				$text = __( 'The background worker stopped while sending, so Buffer may have received it.', 'pressqueue' );
				$tone = 'bad';
				break;
		}

		if ( '' === $text ) {
			return null;
		}

		return array(
			'time' => $event->created_at,
			'text' => self::capitalize( $text ),
			'tone' => $tone,
		);
	}

	/**
	 * Explain why the last try will be repeated.
	 *
	 * @param string $error_class Error class.
	 * @return string
	 */
	private static function retry_reason( string $error_class ): string {
		if ( 'rate_limited' === $error_class ) {
			return __( 'Buffer limits requests per day.', 'pressqueue' );
		}

		if ( 'network_failure' === $error_class ) {
			return __( 'PressQueue could not reach Buffer.', 'pressqueue' );
		}

		if ( 'worker_exception_before_submission_started' === $error_class ) {
			return __( 'Something went wrong before PressQueue could send it.', 'pressqueue' );
		}

		if ( in_array( $error_class, self::REPLY_CLASSES, true ) ) {
			return __( 'Buffer had a temporary problem.', 'pressqueue' );
		}

		return __( 'The last try did not work.', 'pressqueue' );
	}

	/**
	 * Explain why Buffer may have the post although PressQueue could not confirm it.
	 *
	 * @param string $error_class Error class.
	 * @return string
	 */
	private static function ambiguous_reason( string $error_class ): string {
		if ( 'draft_not_confirmed' === $error_class ) {
			return __( 'Buffer returned a post that may be scheduled or published instead of saved as a draft.', 'pressqueue' );
		}

		if ( in_array( $error_class, array( 'expired_lease_after_submission', 'expired_lease_after_submission_started', 'worker_exception_after_submission_started' ), true ) ) {
			return __( 'PressQueue stopped while sending, so Buffer may have received it.', 'pressqueue' );
		}

		if ( 'unexpected_provider_failure' === $error_class ) {
			return __( 'Buffer had a problem while receiving it, so it may have been posted.', 'pressqueue' );
		}

		return __( 'Buffer may have received this, but the reply was cut off.', 'pressqueue' );
	}

	/**
	 * Explain why a share failed.
	 *
	 * @param string      $error_class Error class.
	 * @param string|null $provider_result Provider result.
	 * @return string
	 */
	private static function failure_reason( string $error_class, ?string $provider_result ): string {
		$sentences = array(
			'invalid_credential'         => __( 'Buffer said the API key is no longer valid.', 'pressqueue' ),
			'credential_unavailable'     => __( 'PressQueue could not read the saved Buffer key.', 'pressqueue' ),
			'forbidden'                  => __( 'Buffer refused access to this channel.', 'pressqueue' ),
			'not_found'                  => __( 'Buffer could not find this channel.', 'pressqueue' ),
			'invalid_input'              => __( 'Buffer rejected the post.', 'pressqueue' ),
			'mutation_error'             => __( 'Buffer rejected the post.', 'pressqueue' ),
			'rate_limited'               => __( 'Buffer kept limiting requests, so PressQueue stopped trying automatically.', 'pressqueue' ),
			'redirect_refused'           => __( 'Buffer sent an unexpected reply.', 'pressqueue' ),
			'unexpected_http_status'     => __( 'Buffer sent an unexpected reply.', 'pressqueue' ),
			'capability_mismatch'        => __( 'The channel changed in Buffer or PressQueue after this share was planned.', 'pressqueue' ),
			'destination_binding_failed' => __( 'The channel was not ready when PressQueue tried to send it.', 'pressqueue' ),
			'provider_not_registered'    => __( 'The Buffer connection is not available.', 'pressqueue' ),
			'automatic_budget_exhausted' => __( 'PressQueue tried several times and stopped trying automatically.', 'pressqueue' ),
		);

		if ( isset( $sentences[ $error_class ] ) ) {
			return $sentences[ $error_class ];
		}

		if ( in_array( $error_class, self::LEGACY_CLASSES, true ) ) {
			return __( 'An older version of PressQueue planned this share, so it cannot be sent.', 'pressqueue' );
		}

		if ( in_array( $provider_result, array( 'retryable_failure', 'rate_limited' ), true ) || 'network_failure' === $error_class || in_array( $error_class, self::REPLY_CLASSES, true ) ) {
			return __( 'PressQueue tried several times and stopped trying automatically.', 'pressqueue' );
		}

		return __( 'Buffer did not accept it.', 'pressqueue' );
	}

	/**
	 * Uppercase the first letter of a sentence that starts with a name placeholder.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function capitalize( string $text ): string {
		if ( function_exists( 'mb_strtoupper' ) && function_exists( 'mb_substr' ) ) {
			return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
		}

		return ucfirst( $text );
	}
}
