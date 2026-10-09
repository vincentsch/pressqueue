<?php
/**
 * Renders planning previews as plain-language results.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

/**
 * Turns a PlanningResult array into "would share to these channels, with this message".
 */
final class PreviewPresenter {
	/**
	 * Summarize a planning result array.
	 *
	 * @param array<string,mixed> $result PlanningResult::to_array() output.
	 * @return array{shares:array<int,array<string,mixed>>,blocked:array<int,array<string,string>>,reason:string,post_id:int}
	 */
	public static function summarize( array $result ): array {
		$summary    = new ActivitySummary();
		$shares     = array();
		$blocked    = array();
		$deliveries = isset( $result['deliveries'] ) && is_array( $result['deliveries'] ) ? $result['deliveries'] : array();
		$decisions  = isset( $result['decisions'] ) && is_array( $result['decisions'] ) ? $result['decisions'] : array();
		$shared_ids = array();

		foreach ( $deliveries as $delivery ) {
			if ( ! is_array( $delivery ) || ! isset( $delivery['payload_preview'] ) || ! is_array( $delivery['payload_preview'] ) ) {
				continue;
			}

			$payload      = $delivery['payload_preview'];
			$channel_id   = (string) ( $delivery['channel_public_id'] ?? '' );
			$channel      = '' === $channel_id ? null : $summary->channel( $channel_id );
			$shared_ids[] = $channel_id;
			$capability   = isset( $payload['capability_decision'] ) && is_array( $payload['capability_decision'] ) ? $payload['capability_decision'] : array();
			$media        = isset( $payload['media_snapshot'] ) && is_array( $payload['media_snapshot'] ) ? $payload['media_snapshot'] : array();
			$media_items  = isset( $media['items'] ) && is_array( $media['items'] ) ? $media['items'] : array();
			$rule_public  = (string) ( $payload['rule_public_id'] ?? '' );
			$text         = (string) ( $payload['final_encoded_text'] ?? trim( (string) ( $payload['message'] ?? '' ) . "\n\n" . (string) ( $payload['canonical_url'] ?? '' ) ) );
			$shares[]     = array(
				'channel_public_id' => $channel_id,
				'service'           => null === $channel ? (string) ( $payload['service'] ?? '' ) : $channel->service,
				'channel_name'      => null === $channel ? (string) ( $payload['channel_label'] ?? $channel_id ) : ActivitySummary::channel_display_name( $channel ),
				'text'              => $text,
				'length'            => (int) ( $payload['final_encoded_text_length'] ?? self::length( $text ) ),
				'limit'             => isset( $capability['text_limit'] ) ? (int) $capability['text_limit'] : 0,
				'schedule_slot'     => (string) ( $payload['schedule_slot'] ?? '' ),
				'scheduled_for_utc' => is_string( $payload['scheduled_for_utc'] ?? null ) ? $payload['scheduled_for_utc'] : '',
				'image_count'       => count( $media_items ),
				'rule_name'         => '' === $rule_public ? '' : $summary->rule_name( $rule_public ),
			);
		}

		foreach ( $decisions as $decision ) {
			if ( ! is_array( $decision ) || 'channel' !== ( $decision['scope_type'] ?? '' ) ) {
				continue;
			}

			$channel_id = (string) ( $decision['scope_public_id'] ?? '' );
			if ( in_array( $channel_id, $shared_ids, true ) || 'included' === ( $decision['result'] ?? '' ) ) {
				continue;
			}

			$channel   = '' === $channel_id ? null : $summary->channel( $channel_id );
			$blocked[] = array(
				'service'      => null === $channel ? '' : $channel->service,
				'channel_name' => null === $channel ? $channel_id : ActivitySummary::channel_display_name( $channel ),
				'reason'       => self::first_reason( isset( $decision['reasons'] ) && is_array( $decision['reasons'] ) ? $decision['reasons'] : array() ),
				'result'       => (string) ( $decision['result'] ?? '' ),
			);
		}

		$reason = '';
		if ( array() === $shares ) {
			$reason = __( 'No sharing rule matched.', 'pressqueue' );
			foreach ( $decisions as $decision ) {
				if ( is_array( $decision ) && in_array( $decision['result'] ?? '', array( 'excluded', 'policy_error', 'skipped' ), true ) ) {
					$reason = self::first_reason( isset( $decision['reasons'] ) && is_array( $decision['reasons'] ) ? $decision['reasons'] : array() );
					if ( 'skipped' !== $decision['result'] ) {
						break;
					}
				}
			}
		}

		$post_id = 0;
		if ( isset( $result['event'] ) && is_array( $result['event'] ) && isset( $result['event']['post_id'] ) ) {
			$post_id = (int) $result['event']['post_id'];
		}

		return array(
			'shares'  => $shares,
			'blocked' => $blocked,
			'reason'  => $reason,
			'post_id' => $post_id,
		);
	}

	/**
	 * Render a compact result for one post.
	 *
	 * @param array<string,mixed> $result PlanningResult::to_array() output.
	 * @param bool                $show_messages Whether to show each rendered message.
	 */
	public static function render( array $result, bool $show_messages = true ): void {
		$summary = self::summarize( $result );
		$shares  = $summary['shares'];

		echo '<div class="pq-preview pq-stack">';

		if ( array() === $shares ) {
			echo '<div class="pq-callout"><p><span class="pq-no" aria-hidden="true">&#x2715;</span> <strong>' . esc_html__( 'Would not be shared.', 'pressqueue' ) . '</strong> ' . esc_html( $summary['reason'] ) . '</p></div>';
		} else {
			echo '<div class="pq-callout pq-callout--ok"><p><span class="pq-yes" aria-hidden="true">&#x2713;</span> <strong>' . esc_html(
				sprintf(
					/* translators: %d: number of channels. */
					_n( 'Would share to %d channel.', 'Would share to %d channels.', count( $shares ), 'pressqueue' ),
					count( $shares )
				)
			) . '</strong></p></div>';

			foreach ( $shares as $share ) {
				self::render_share( $share, $show_messages );
			}
		}

		foreach ( $summary['blocked'] as $blocked ) {
			echo '<div class="pq-row pq-small">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_name returns escaped markup.
			echo '' === $blocked['service'] ? '<span>' . esc_html( $blocked['channel_name'] ) . '</span>' : Ui::channel_name( $blocked['service'], $blocked['channel_name'] );
			echo '<span class="pq-muted">' . esc_html( $blocked['reason'] ) . '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render one share card.
	 *
	 * @param array<string,mixed> $share Share summary.
	 * @param bool                $show_message Whether to show the message.
	 */
	public static function render_share( array $share, bool $show_message = true ): void {
		echo '<div class="pq-share-card">';
		echo '<div class="pq-row pq-row--between">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_name returns escaped markup.
		echo Ui::channel_name( (string) $share['service'], (string) $share['channel_name'] );
		echo '<span class="pq-muted pq-small">' . esc_html( self::timing_text( $share ) ) . '</span>';
		echo '</div>';

		if ( $show_message ) {
			echo '<div class="pq-share-text">' . nl2br( esc_html( (string) $share['text'] ) ) . '</div>';
			$limit = (int) $share['limit'];
			$parts = array();
			if ( $limit > 0 ) {
				/* translators: 1: characters used, 2: character limit. */
				$parts[] = sprintf( __( '%1$d of %2$d characters', 'pressqueue' ), (int) $share['length'], $limit );
			}
			$parts[] = (int) $share['image_count'] > 0 ? __( 'With image', 'pressqueue' ) : __( 'Text and link', 'pressqueue' );
			if ( '' !== $share['rule_name'] ) {
				/* translators: %s: rule name. */
				$parts[] = sprintf( __( 'Rule: %s', 'pressqueue' ), (string) $share['rule_name'] );
			}
			echo '<div class="pq-muted pq-small">' . esc_html( implode( ' · ', $parts ) ) . '</div>';
		}

		echo '</div>';
	}

	/**
	 * Return the timing text for a share.
	 *
	 * @param array<string,mixed> $share Share summary.
	 * @return string
	 */
	private static function timing_text( array $share ): string {
		$slot = (string) $share['schedule_slot'];

		if ( 'explicit_time' === $slot && '' !== $share['scheduled_for_utc'] ) {
			try {
				return Ui::local_datetime( new \DateTimeImmutable( (string) $share['scheduled_for_utc'], new \DateTimeZone( 'UTC' ) ) );
			} catch ( \Exception $exception ) {
				unset( $exception );
			}
		}

		return ucfirst( Labels::schedule_phrase( $slot ) );
	}

	/**
	 * Return the first meaningful reason sentence.
	 *
	 * @param array<int,mixed> $reasons Reasons.
	 * @return string
	 */
	private static function first_reason( array $reasons ): string {
		foreach ( $reasons as $reason ) {
			if ( ! is_array( $reason ) || ! is_string( $reason['code'] ?? null ) ) {
				continue;
			}

			if ( in_array( $reason['code'], array( 'destination_available', 'included_destination' ), true ) ) {
				continue;
			}

			return Labels::reason( $reason['code'], is_string( $reason['label'] ?? null ) ? $reason['label'] : '' );
		}

		return __( 'No sharing rule matched.', 'pressqueue' );
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
}
