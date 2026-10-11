<?php
/**
 * Share details administration.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Delivery\DeliveryDisplaySnapshot;
use PressQueue\Delivery\OperatorActionAssessmentService;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\DeliveryEventRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\ValidationException;

/**
 * Renders one share: what happened, the message, Buffer's receipt and the actions.
 */
final class DeliveryDetailController {
	public const SLUG = 'pressqueue-delivery';

	/**
	 * Register the detail page.
	 *
	 * @param string $parent_slug Parent slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'Share details', 'pressqueue' ),
			__( 'Share details', 'pressqueue' ),
			Capabilities::VIEW_DELIVERIES,
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Return the details URL for a share.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return string
	 */
	public static function url( string $delivery_public_id ): string {
		return Ui::url( self::SLUG, array( 'delivery_public_id' => $delivery_public_id ) );
	}

	/**
	 * Check whether a manual retry is allowed for the stored state.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return bool
	 */
	public static function can_retry( DeliveryRecord $delivery ): bool {
		return ( 'failed' === $delivery->state && 'manual_allowed' === $delivery->retry_safety )
			|| ( 'ambiguous' === $delivery->state && 'confirmed_not_accepted' === $delivery->reconciliation_status && 'manual_allowed' === $delivery->retry_safety );
	}

	/**
	 * Render share details.
	 */
	public static function render_page(): void {
		self::require_capability( Capabilities::VIEW_DELIVERIES );

		try {
			$delivery = self::requested_delivery();
		} catch ( ValidationException $exception ) {
			wp_die( esc_html( SafeOperatorError::message( $exception, __( 'PressQueue delivery could not be loaded.', 'pressqueue' ) ) ) );
		}

		$decision = ( new DecisionRepository() )->find_by_public_id( $delivery->decision_public_id );
		$events   = ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id );
		$snapshot = new DeliveryDisplaySnapshot( $delivery, $decision );
		$summary  = new ActivitySummary();
		$post_id  = null === $decision ? 0 : $decision->post_id;
		$label    = Labels::delivery_state( $delivery->state );

		ob_start();
		self::render_meta( $delivery, $post_id, $summary );
		$meta = (string) ob_get_clean();

		Ui::page_start(
			0 === $post_id ? __( 'Share details', 'pressqueue' ) : Ui::post_title( $post_id ),
			array(
				'actions'    => $meta,
				'back_url'   => Ui::url( Ui::PAGE_ACTIVITY ),
				'back_label' => __( 'Activity', 'pressqueue' ),
				'badge'      => Ui::pill( $label['tone'], $label['label'], $label['help'] ),
			)
		);
		DeliveryListController::render_notice();

		echo '<div class="pq-cols pq-share-cols">';
		echo '<div class="pq-stack">';
		if ( 'ambiguous' === $delivery->state ) {
			self::render_resolve( $delivery, $summary );
		}
		self::render_timeline( $snapshot, $events );
		self::render_message( $snapshot, $summary );
		echo '</div>';
		echo '<div class="pq-stack">';
		self::render_status( $delivery, $events );
		echo '</div>';
		echo '</div>';

		self::render_technical_details( $snapshot, $events );
		Ui::page_end();
	}

	/**
	 * Render the line under the title: channel, rule and post links.
	 *
	 * @param DeliveryRecord  $delivery Delivery.
	 * @param int             $post_id Post ID.
	 * @param ActivitySummary $summary Summary.
	 */
	private static function render_meta( DeliveryRecord $delivery, int $post_id, ActivitySummary $summary ): void {
		$rule = $summary->rule_name( $delivery->rule_public_id );

		echo '<div class="pq-share-meta pq-row">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_markup returns escaped markup.
		echo DeliveryListController::channel_markup( $delivery, $summary );
		if ( '' !== $rule ) {
			/* translators: %s: rule name. */
			echo '<span class="pq-muted">' . esc_html( sprintf( __( 'Rule: %s', 'pressqueue' ), $rule ) ) . '</span>';
		}

		$edit = $post_id > 0 && current_user_can( 'edit_post', $post_id ) ? get_edit_post_link( $post_id ) : '';
		if ( is_string( $edit ) && '' !== $edit ) {
			echo '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit post', 'pressqueue' ) . '</a>';
		}

		if ( $post_id > 0 && current_user_can( Capabilities::VIEW_DECISIONS ) ) {
			echo '<a href="' . esc_url( Ui::url( Ui::PAGE_ACTIVITY, array( 'tab' => 'decisions' ) ) ) . '">' . esc_html__( 'Why posts were shared or skipped', 'pressqueue' ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * Render the "Check Buffer" card with the three reconciliation choices.
	 *
	 * @param DeliveryRecord  $delivery Delivery.
	 * @param ActivitySummary $summary Summary.
	 */
	private static function render_resolve( DeliveryRecord $delivery, ActivitySummary $summary ): void {
		$channel      = $summary->channel( $delivery->channel_public_id );
		$channel_name = null === $channel ? __( 'this channel', 'pressqueue' ) : ActivitySummary::channel_display_name( $channel );
		$confirmed    = 'confirmed_not_accepted' === $delivery->reconciliation_status;

		echo '<div class="pq-card pq-resolve" id="resolve">';
		echo '<div class="pq-card-head"><div>';
		if ( $confirmed ) {
			echo '<h2>' . esc_html__( 'You confirmed it is not in Buffer', 'pressqueue' ) . '</h2>';
			echo '<p class="pq-sub">' . esc_html__( 'Retry it to send it to Buffer again, or cancel it.', 'pressqueue' ) . '</p>';
		} else {
			echo '<h2>' . esc_html__( 'Check Buffer before you do anything else', 'pressqueue' ) . '</h2>';
			echo '<p class="pq-sub">' . esc_html( DeliveryExplainer::problem_sentence( $delivery ) ) . ' ' . esc_html__( 'If PressQueue sends it again and Buffer already has it, the post appears twice.', 'pressqueue' ) . '</p>';
			/* translators: %s: channel name. */
			echo '<p class="pq-sub">' . esc_html( sprintf( __( 'Open Buffer, look for this post in the queue or sent posts for %s, then tell PressQueue what you found.', 'pressqueue' ), $channel_name ) ) . '</p>';
		}
		echo '</div></div>';

		if ( 'draft_not_confirmed' === $delivery->error_class ) {
			echo '<div class="pq-section"><div class="pq-callout pq-callout--bad"><p><strong>' . esc_html__( 'Sharing was paused to be safe.', 'pressqueue' ) . '</strong> ' . esc_html__( 'Buffer returned a post that was not saved as a draft. Check the returned post in Buffer now: it may be scheduled or published. PressQueue cannot remove it from Buffer.', 'pressqueue' ) . '</p></div></div>';
		}

		if ( ! current_user_can( Capabilities::OPERATE_DELIVERIES ) ) {
			echo '<div class="pq-section"><p class="pq-muted">' . esc_html__( 'Someone who can manage shares needs to resolve this.', 'pressqueue' ) . '</p></div>';
			echo '</div>';
			return;
		}

		if ( $confirmed && self::can_retry( $delivery ) ) {
			echo '<div class="pq-section pq-resolve-retry">';
			self::render_retry_action( $delivery );
			echo '</div>';
		}

		echo '<div class="pq-section"><p class="pq-help">' . esc_html__( 'Your answer is saved in PressQueue only. PressQueue will not contact Buffer and will not remove anything from Buffer.', 'pressqueue' ) . '</p></div>';

		echo '<div class="pq-resolve-options">';
		self::reconciliation_form(
			$delivery,
			'confirmed_remote_id',
			__( 'I found it in Buffer', 'pressqueue' ),
			__( 'PressQueue marks it as sent to Buffer and will not send it again.', 'pressqueue' ),
			__( 'Mark as sent', 'pressqueue' ),
			'button button-primary'
		);

		if ( 'draft_not_confirmed' !== $delivery->error_class && ! $confirmed ) {
			self::reconciliation_form(
				$delivery,
				'confirmed_not_accepted',
				__( 'It is not in Buffer', 'pressqueue' ),
				__( 'PressQueue keeps the share so you can retry it.', 'pressqueue' ),
				__( 'Mark as not in Buffer', 'pressqueue' ),
				'button'
			);
		}

		self::reconciliation_form(
			$delivery,
			'cancelled',
			__( 'Cancel it', 'pressqueue' ),
			__( 'PressQueue stops here and will not send it. Nothing changes in Buffer.', 'pressqueue' ),
			__( 'Cancel share', 'pressqueue' ),
			'button pq-danger'
		);
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render one reconciliation choice.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param string         $outcome Outcome.
	 * @param string         $title Choice title.
	 * @param string         $text Choice explanation.
	 * @param string         $button Button label.
	 * @param string         $button_class Button classes.
	 */
	private static function reconciliation_form( DeliveryRecord $delivery, string $outcome, string $title, string $text, string $button, string $button_class ): void {
		$id = 'pq-resolve-' . str_replace( '_', '-', $outcome );

		echo '<section class="pq-resolve-option" aria-labelledby="' . esc_attr( $id ) . '-title">';
		echo '<h3 id="' . esc_attr( $id ) . '-title">' . esc_html( $title ) . '</h3>';
		echo '<p class="pq-muted">' . esc_html( $text ) . '</p>';
		Ui::form_start(
			'pressqueue_reconcile_delivery',
			'pressqueue_reconcile_delivery_' . $outcome,
			array(
				'delivery_public_id' => $delivery->public_id,
				'outcome'            => $outcome,
			),
			array( 'class' => 'pq-stack pq-resolve-form' )
		);

		if ( 'confirmed_remote_id' === $outcome ) {
			$returned_remote_id = is_string( $delivery->error_detail['remote_id'] ?? null ) ? $delivery->error_detail['remote_id'] : '';
			echo '<div><label class="pq-label" for="' . esc_attr( $id ) . '-remote">' . esc_html__( 'Buffer post ID', 'pressqueue' ) . '</label>';
			echo '<input class="pq-field pq-mono" id="' . esc_attr( $id ) . '-remote" name="remote_id" type="text" maxlength="191" value="' . esc_attr( $returned_remote_id ) . '" required aria-describedby="' . esc_attr( $id ) . '-remote-help" />';
			echo '<p class="pq-help" id="' . esc_attr( $id ) . '-remote-help">' . esc_html__( 'Open the post in Buffer and copy the ID from its address.', 'pressqueue' ) . '</p></div>';
		}

		echo '<details class="pq-more pq-resolve-note"><summary>' . esc_html__( 'Add a note for your team', 'pressqueue' ) . '</summary><div class="pq-more-body">';
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '-note">' . esc_html__( 'Note for your team (optional)', 'pressqueue' ) . '</label>';
		echo '<textarea class="pq-field" id="' . esc_attr( $id ) . '-note" name="note" maxlength="500" rows="2"></textarea></div></details>';
		echo '<label class="pq-confirm-check"><input type="checkbox" name="confirm_local_effect" value="1" required /> ' . esc_html( self::reconciliation_confirmation() ) . '</label>';
		echo '<div><button type="submit" class="' . esc_attr( $button_class ) . '">' . esc_html( $button ) . '</button></div>';
		Ui::form_end();
		echo '</section>';
	}

	/**
	 * Render the "What happened" timeline.
	 *
	 * @param DeliveryDisplaySnapshot                                   $snapshot Snapshot.
	 * @param array<int,\PressQueue\Storage\Record\DeliveryEventRecord> $events Events.
	 */
	private static function render_timeline( DeliveryDisplaySnapshot $snapshot, array $events ): void {
		$entries = DeliveryExplainer::timeline( $snapshot->delivery, $snapshot->decision, $events );

		echo '<div class="pq-card">';
		echo '<div class="pq-card-head"><h2>' . esc_html__( 'What happened', 'pressqueue' ) . '</h2></div>';
		echo '<ol class="pq-timeline">';
		foreach ( $entries as $entry ) {
			echo '<li class="pq-timeline-item pq-timeline-item--' . esc_attr( $entry['tone'] ) . '">';
			echo '<span class="pq-timeline-dot" aria-hidden="true"></span>';
			echo '<div class="pq-timeline-body"><p>' . esc_html( $entry['text'] ) . '</p>';
			DeliveryListController::render_time( $entry['time'] );
			echo '</div></li>';
		}
		echo '</ol>';
		echo '</div>';
	}

	/**
	 * Render the message that was or would be sent.
	 *
	 * @param DeliveryDisplaySnapshot $snapshot Snapshot.
	 * @param ActivitySummary         $summary Summary.
	 */
	private static function render_message( DeliveryDisplaySnapshot $snapshot, ActivitySummary $summary ): void {
		$delivery = $snapshot->delivery;
		$payload  = $delivery->payload_preview;

		if ( 'dry_run' === $delivery->state ) {
			$title = __( 'Message that would have been sent', 'pressqueue' );
		} elseif ( 'accepted' === $delivery->state ) {
			$title = __( 'Message sent to Buffer', 'pressqueue' );
		} elseif ( 'ambiguous' === $delivery->state || ( $delivery->attempt_count > 0 && in_array( $delivery->state, array( 'failed', 'retrying', 'cancelled' ), true ) ) ) {
			$title = __( 'Message PressQueue tried to send', 'pressqueue' );
		} else {
			$title = __( 'Message', 'pressqueue' );
		}

		echo '<div class="pq-card pq-card--pad pq-stack">';
		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( null === $snapshot->decision || ! current_user_can( 'edit_post', $snapshot->decision->post_id ) ) {
			echo '<p class="pq-muted">' . esc_html__( 'Message and media details are hidden because you cannot edit the source post.', 'pressqueue' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( 'retention' === ( $payload['redacted'] ?? null ) || ( ! isset( $payload['final_encoded_text'] ) && ! isset( $payload['message'] ) ) ) {
			echo '<p class="pq-muted">' . esc_html__( 'The message was removed by your data retention settings. The share itself is kept so PressQueue does not send it twice.', 'pressqueue' ) . '</p>';
			echo '</div>';
			return;
		}

		$channel    = $summary->channel( $delivery->channel_public_id );
		$capability = isset( $payload['capability_decision'] ) && is_array( $payload['capability_decision'] ) ? $payload['capability_decision'] : array();
		$media      = isset( $payload['media_snapshot'] ) && is_array( $payload['media_snapshot'] ) ? $payload['media_snapshot'] : array();
		$items      = isset( $media['items'] ) && is_array( $media['items'] ) ? $media['items'] : array();
		$text       = is_string( $payload['final_encoded_text'] ?? null )
			? (string) $payload['final_encoded_text']
			: trim( (string) ( $payload['message'] ?? '' ) . "\n\n" . (string) ( $payload['canonical_url'] ?? '' ) );

		PreviewPresenter::render_share(
			array(
				'channel_name'      => null === $channel ? $snapshot->channel_label : ActivitySummary::channel_display_name( $channel ),
				'image_count'       => count( $items ),
				'length'            => is_int( $payload['final_encoded_text_length'] ?? null ) ? (int) $payload['final_encoded_text_length'] : ( function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text ) : strlen( $text ) ),
				'limit'             => isset( $capability['text_limit'] ) ? (int) $capability['text_limit'] : 0,
				'rule_name'         => $summary->rule_name( $delivery->rule_public_id ),
				'schedule_slot'     => $delivery->schedule_slot,
				'scheduled_for_utc' => null === $delivery->scheduled_for_utc ? '' : $delivery->scheduled_for_utc->format( 'Y-m-d H:i:s' ),
				'service'           => null === $channel ? $snapshot->service : $channel->service,
				'text'              => $text,
			)
		);
		echo '</div>';
	}

	/**
	 * Render the status card with Buffer's receipt and the actions.
	 *
	 * @param DeliveryRecord                                            $delivery Delivery.
	 * @param array<int,\PressQueue\Storage\Record\DeliveryEventRecord> $events Events.
	 */
	private static function render_status( DeliveryRecord $delivery, array $events ): void {
		$label = Labels::delivery_state( $delivery->state );

		echo '<div class="pq-card pq-card--pad pq-stack pq-share-status">';
		echo '<div class="pq-row pq-row--between"><h2>' . esc_html__( 'Status', 'pressqueue' ) . '</h2>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill returns escaped markup.
		echo Ui::pill( $label['tone'], $label['label'] ) . '</div>';

		$sentence = self::status_sentence( $delivery, $events );
		if ( '' !== $sentence ) {
			echo '<p class="pq-status-text">' . esc_html( $sentence ) . '</p>';
		}

		if ( 'failed' === $delivery->state ) {
			$steps = DeliveryExplainer::failure_steps( $delivery, self::can_retry( $delivery ) );
			if ( array() !== $steps ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- steps_html returns escaped markup.
				echo '<p class="pq-next-steps">' . DeliveryExplainer::steps_html( $steps ) . '</p>';
			}
		}

		self::render_facts( $delivery );

		if ( current_user_can( Capabilities::OPERATE_DELIVERIES ) ) {
			self::render_actions( $delivery );
		}

		echo '</div>';
	}

	/**
	 * Return the main status sentence.
	 *
	 * @param DeliveryRecord                                            $delivery Delivery.
	 * @param array<int,\PressQueue\Storage\Record\DeliveryEventRecord> $events Events.
	 * @return string
	 */
	private static function status_sentence( DeliveryRecord $delivery, array $events ): string {
		if ( in_array( $delivery->state, array( 'failed', 'retrying' ), true ) ) {
			return DeliveryExplainer::problem_sentence( $delivery );
		}

		if ( 'ambiguous' === $delivery->state ) {
			return 'confirmed_not_accepted' === $delivery->reconciliation_status ? DeliveryExplainer::row_note( $delivery )['text'] : Labels::delivery_state( 'ambiguous' )['help'];
		}

		if ( 'held' === $delivery->state ) {
			$reason = null;
			foreach ( $events as $event ) {
				if ( 'held' === $event->event_type ) {
					$reason = is_string( $event->detail['classification'] ?? null ) ? (string) $event->detail['classification'] : '';
				}
			}

			return DeliveryExplainer::hold_sentence( $reason ) . ' ' . __( 'Resume it to send it to Buffer.', 'pressqueue' );
		}

		if ( 'cancelled' === $delivery->state ) {
			return __( 'Cancelled in PressQueue. It will not be sent.', 'pressqueue' );
		}

		return Labels::delivery_state( $delivery->state )['help'];
	}

	/**
	 * Render timing and Buffer receipt facts in words.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private static function render_facts( DeliveryRecord $delivery ): void {
		$facts = array();

		if ( 'explicit_time' === $delivery->schedule_slot && null !== $delivery->scheduled_for_utc ) {
			$facts[ __( 'Timing', 'pressqueue' ) ] = Ui::local_datetime( $delivery->scheduled_for_utc );
		} else {
			$facts[ __( 'Timing', 'pressqueue' ) ] = ucfirst( Labels::schedule_phrase( $delivery->schedule_slot ) );
		}

		if ( 'retrying' === $delivery->state && null !== $delivery->next_attempt_at ) {
			$facts[ __( 'Next try', 'pressqueue' ) ] = Ui::local_datetime( $delivery->next_attempt_at ) . ' (' . Ui::relative_time( $delivery->next_attempt_at ) . ')';
		}

		if ( null !== $delivery->last_attempt_at ) {
			$facts[ __( 'Last sent to Buffer', 'pressqueue' ) ] = Ui::local_datetime( $delivery->last_attempt_at );
		}

		$receipt = is_array( $delivery->receipt ) ? $delivery->receipt : array();
		if ( is_string( $receipt['status'] ?? null ) && '' !== $receipt['status'] ) {
			$facts[ __( 'In Buffer', 'pressqueue' ) ] = DeliveryExplainer::receipt_status( (string) $receipt['status'] );
		}

		$due = DeliveryListController::receipt_due_at( $delivery );
		if ( null !== $due ) {
			$when = Ui::local_datetime( $due );
			if ( $due->getTimestamp() > time() ) {
				$when .= ' (' . Ui::relative_time( $due ) . ')';
			}
			$facts[ __( 'Buffer posts it', 'pressqueue' ) ] = $when;
		}

		if ( 'confirmed_remote_id' === $delivery->reconciliation_status && null !== $delivery->reconciled_at ) {
			/* translators: 1: person, 2: date and time. */
			$facts[ __( 'Checked in Buffer', 'pressqueue' ) ] = sprintf( __( '%1$s, %2$s', 'pressqueue' ), DeliveryExplainer::user_name( $delivery->reconciled_by ), Ui::local_datetime( $delivery->reconciled_at ) );
		}

		echo '<dl class="pq-facts">';
		foreach ( $facts as $term => $value ) {
			echo '<dt>' . esc_html( $term ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl>';
	}

	/**
	 * Render Resume, Retry and Cancel with confirmations.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private static function render_actions( DeliveryRecord $delivery ): void {
		$has_action = false;

		if ( 'held' === $delivery->state ) {
			$has_action = true;
			$blocks     = self::assessment_blocks( 'resume', $delivery->public_id );
			echo '<div class="pq-share-action">';
			if ( array() === $blocks ) {
				Ui::button_form(
					'pressqueue_resume_delivery',
					'pressqueue_resume_delivery',
					array( 'delivery_public_id' => $delivery->public_id ),
					__( 'Resume', 'pressqueue' ),
					'button button-primary',
					__( 'Resume this share? PressQueue sends it to Buffer shortly.', 'pressqueue' )
				);
			} else {
				echo '<button type="button" class="button button-primary" disabled="disabled">' . esc_html__( 'Resume', 'pressqueue' ) . '</button>';
				echo '<p class="pq-help">' . esc_html( DeliveryExplainer::block_sentence( $blocks[0] ) ) . '</p>';
			}
			echo '</div>';
		}

		if ( 'failed' === $delivery->state && self::can_retry( $delivery ) ) {
			$has_action = true;
			echo '<div class="pq-share-action">';
			self::render_retry_action( $delivery );
			echo '</div>';
		}

		if ( in_array( $delivery->state, array( 'proposed', 'held', 'queued', 'retrying', 'failed' ), true ) ) {
			$has_action = true;
			echo '<details class="pq-more pq-share-cancel"><summary>' . esc_html__( 'Cancel this share', 'pressqueue' ) . '</summary><div class="pq-more-body">';
			Ui::form_start( 'pressqueue_cancel_delivery', 'pressqueue_cancel_delivery', array( 'delivery_public_id' => $delivery->public_id ), array( 'class' => 'pq-stack' ) );
			echo '<label class="pq-confirm-check"><input type="checkbox" name="confirm_local_effect" value="1" required /> ' . esc_html( self::cancel_confirmation() ) . '</label>';
			echo '<div><button type="submit" class="button pq-danger">' . esc_html__( 'Cancel share', 'pressqueue' ) . '</button></div>';
			Ui::form_end();
			echo '</div></details>';
		}

		if ( 'ambiguous' === $delivery->state ) {
			$has_action = true;
			echo '<p><a class="button button-primary" href="#resolve">' . esc_html__( 'Resolve', 'pressqueue' ) . '</a></p>';
		}

		if ( ! $has_action && 'leased' === $delivery->state ) {
			echo '<p class="pq-help">' . esc_html__( 'PressQueue is sending it right now. Check back in a minute.', 'pressqueue' ) . '</p>';
		}
	}

	/**
	 * Render the retry button, or why a retry is not possible right now.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private static function render_retry_action( DeliveryRecord $delivery ): void {
		$blocks = self::assessment_blocks( 'retry', $delivery->public_id );

		if ( array() === $blocks ) {
			Ui::button_form(
				'pressqueue_retry_delivery',
				'pressqueue_retry_delivery',
				array( 'delivery_public_id' => $delivery->public_id ),
				__( 'Retry', 'pressqueue' ),
				'button button-primary',
				__( 'Send this share to Buffer again?', 'pressqueue' )
			);
			return;
		}

		echo '<button type="button" class="button button-primary" disabled="disabled">' . esc_html__( 'Retry', 'pressqueue' ) . '</button>';
		echo '<p class="pq-help">' . esc_html( DeliveryExplainer::block_sentence( $blocks[0] ) ) . '</p>';
	}

	/**
	 * Return zero-write assessment blocks for an action.
	 *
	 * @param string $action resume or retry.
	 * @param string $delivery_public_id Delivery public ID.
	 * @return array<int,string>
	 */
	private static function assessment_blocks( string $action, string $delivery_public_id ): array {
		try {
			$service    = new OperatorActionAssessmentService();
			$assessment = 'resume' === $action ? $service->assess_resume( $delivery_public_id ) : $service->assess_retry( $delivery_public_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array( 'policy_check_failed' );
		}

		return $assessment->would_mutate ? array() : array_values( array_map( 'strval', $assessment->blocked_reasons ) );
	}

	/**
	 * Render keys, hashes, counters and the raw event ledger behind a disclosure.
	 *
	 * @param DeliveryDisplaySnapshot                                   $snapshot Snapshot.
	 * @param array<int,\PressQueue\Storage\Record\DeliveryEventRecord> $events Events.
	 */
	private static function render_technical_details( DeliveryDisplaySnapshot $snapshot, array $events ): void {
		echo '<details class="pq-more pq-tech"><summary>' . esc_html__( 'Technical details', 'pressqueue' ) . '</summary><div class="pq-more-body pq-stack">';
		echo '<p class="pq-help">' . esc_html__( 'Stored identifiers and the full event record, for support requests.', 'pressqueue' ) . '</p>';
		self::render_projection( $snapshot );
		self::render_payload_summary( $snapshot );
		self::render_decision_summary( $snapshot );
		self::render_event_history( $snapshot, $events );
		echo '</div></details>';
	}

	/**
	 * Render current projection fields.
	 *
	 * @param DeliveryDisplaySnapshot $snapshot Snapshot.
	 */
	private static function render_projection( DeliveryDisplaySnapshot $snapshot ): void {
		$delivery = $snapshot->delivery;
		$rows     = array(
			__( 'Delivery public ID', 'pressqueue' )       => $delivery->public_id,
			__( 'Delivery key', 'pressqueue' )             => $delivery->delivery_key,
			__( 'Decision public ID', 'pressqueue' )       => $delivery->decision_public_id,
			__( 'Event key', 'pressqueue' )                => $delivery->event_key,
			__( 'Post identity', 'pressqueue' )            => $snapshot->post_identity(),
			__( 'Rule identity', 'pressqueue' )            => $snapshot->rule_identity(),
			__( 'Channel label', 'pressqueue' )            => $snapshot->channel_label,
			__( 'Service', 'pressqueue' )                  => $snapshot->service,
			__( 'Connection label', 'pressqueue' )         => $snapshot->connection_label,
			__( 'Remote channel ID', 'pressqueue' )        => $delivery->bound_remote_channel_id ?? $delivery->remote_channel_id ?? '',
			__( 'Delivery mode', 'pressqueue' )            => $delivery->delivery_mode,
			__( 'Schedule slot', 'pressqueue' )            => $delivery->schedule_slot,
			__( 'Scheduled for UTC', 'pressqueue' )        => DeliveryDisplaySnapshot::format_timestamp( $delivery->scheduled_for_utc ),
			__( 'Schedule timezone', 'pressqueue' )        => $delivery->schedule_timezone ?? '',
			__( 'Payload hash', 'pressqueue' )             => $delivery->payload_hash,
			__( 'Payload schema version', 'pressqueue' )   => (string) $delivery->payload_schema_version,
			__( 'Adapter encoding version', 'pressqueue' ) => (string) $delivery->adapter_encoding_version,
			__( 'Submission compatibility', 'pressqueue' ) => $snapshot->submission_compatibility_label(),
			__( 'Bound provider identity', 'pressqueue' )  => self::bound_identity( $delivery ),
			__( 'Bound capability hash', 'pressqueue' )    => $delivery->bound_capability_hash ?? '',
			__( 'Bound capability catalog', 'pressqueue' ) => $delivery->bound_capability_catalog_version ?? '',
			__( 'Media identity hash', 'pressqueue' )      => $delivery->media_identity_hash ?? '',
			__( 'State', 'pressqueue' )                    => $snapshot->state_label(),
			__( 'State changed at', 'pressqueue' )         => DeliveryDisplaySnapshot::format_timestamp( $delivery->state_changed_at ),
			__( 'Attempt count', 'pressqueue' )            => (string) $delivery->attempt_count,
			__( 'Automatic attempt count', 'pressqueue' )  => (string) $delivery->automatic_attempt_count,
			__( 'Queued at', 'pressqueue' )                => DeliveryDisplaySnapshot::format_timestamp( $delivery->queued_at ),
			__( 'Last attempt at', 'pressqueue' )          => DeliveryDisplaySnapshot::format_timestamp( $delivery->last_attempt_at ),
			__( 'Next attempt at', 'pressqueue' )          => DeliveryDisplaySnapshot::format_timestamp( $delivery->next_attempt_at ),
			__( 'Lease status', 'pressqueue' )             => $snapshot->lease_status(),
			__( 'Action Scheduler action ID', 'pressqueue' ) => null === $delivery->action_id ? '' : (string) $delivery->action_id,
			__( 'Provider result', 'pressqueue' )          => $snapshot->provider_result_label(),
			__( 'Retry safety', 'pressqueue' )             => $snapshot->retry_safety_label(),
			__( 'Remote ID', 'pressqueue' )                => $delivery->remote_id ?? '',
			__( 'Remote receipt key', 'pressqueue' )       => $delivery->remote_receipt_key ?? '',
			__( 'Reconciliation status', 'pressqueue' )    => $snapshot->reconciliation_status_label(),
			__( 'Reconciled at', 'pressqueue' )            => DeliveryDisplaySnapshot::format_timestamp( $delivery->reconciled_at ),
			__( 'Reconciled actor', 'pressqueue' )         => DeliveryDisplaySnapshot::user_label( $delivery->reconciled_by ),
			__( 'Evidence note', 'pressqueue' )            => $delivery->reconciliation_note ?? '',
			__( 'Last error class', 'pressqueue' )         => $snapshot->error_class_label( $delivery->error_class ),
		);

		echo '<h3 class="pq-heading">' . esc_html__( 'Current record', 'pressqueue' ) . '</h3>';
		echo '<div class="pq-table-wrap"><table class="pq-table pq-tech-table"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Render the redacted receipt and error documents.
	 *
	 * @param DeliveryDisplaySnapshot $snapshot Snapshot.
	 */
	private static function render_payload_summary( DeliveryDisplaySnapshot $snapshot ): void {
		echo '<h3 class="pq-heading">' . esc_html__( 'Receipt and error', 'pressqueue' ) . '</h3>';
		echo '<pre class="pq-tech-json">' . esc_html(
			DeliveryDisplaySnapshot::pretty_json(
				array(
					'error'   => $snapshot->error_summary(),
					'receipt' => $snapshot->receipt_summary(),
				)
			)
		) . '</pre>';
	}

	/**
	 * Render immutable decision context.
	 *
	 * @param DeliveryDisplaySnapshot $snapshot Snapshot.
	 */
	private static function render_decision_summary( DeliveryDisplaySnapshot $snapshot ): void {
		$decision = $snapshot->decision;

		echo '<h3 class="pq-heading">' . esc_html__( 'Decision', 'pressqueue' ) . '</h3>';

		if ( null === $decision ) {
			echo '<p class="pq-muted">' . esc_html__( 'The referenced decision record is unavailable.', 'pressqueue' ) . '</p>';
			return;
		}

		$reasons = current_user_can( 'edit_post', $decision->post_id ) ? $decision->reasons : array( array( 'code' => 'post_facts_redacted' ) );

		echo '<div class="pq-table-wrap"><table class="pq-table pq-tech-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Decision key', 'pressqueue' ) . '</th><td><code>' . esc_html( $decision->decision_key ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Result', 'pressqueue' ) . '</th><td><code>' . esc_html( $decision->result ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Trigger', 'pressqueue' ) . '</th><td><code>' . esc_html( $decision->trigger ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Snapshot hash', 'pressqueue' ) . '</th><td><code>' . esc_html( $decision->snapshot_hash ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Reasons', 'pressqueue' ) . '</th><td><pre class="pq-tech-json">' . esc_html( DeliveryDisplaySnapshot::pretty_json( array( 'reasons' => $reasons ) ) ) . '</pre></td></tr>';
		echo '</tbody></table></div>';
	}

	/**
	 * Render the append-only event ledger.
	 *
	 * @param DeliveryDisplaySnapshot                                   $snapshot Snapshot.
	 * @param array<int,\PressQueue\Storage\Record\DeliveryEventRecord> $events Events.
	 */
	private static function render_event_history( DeliveryDisplaySnapshot $snapshot, array $events ): void {
		echo '<h3 class="pq-heading">' . esc_html__( 'Event record', 'pressqueue' ) . '</h3>';

		if ( array() === $events ) {
			echo '<p class="pq-muted">' . esc_html__( 'No delivery events have been recorded.', 'pressqueue' ) . '</p>';
			return;
		}

		echo '<div class="pq-table-wrap"><table class="pq-table pq-tech-table"><caption class="screen-reader-text">' . esc_html__( 'PressQueue delivery events', 'pressqueue' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Time', 'pressqueue' ), __( 'Event', 'pressqueue' ), __( 'State', 'pressqueue' ), __( 'Attempt', 'pressqueue' ), __( 'Actor', 'pressqueue' ), __( 'Provider result', 'pressqueue' ), __( 'Retry safety', 'pressqueue' ), __( 'Detail', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			echo '<tr>';
			echo '<td>' . esc_html( DeliveryDisplaySnapshot::format_timestamp( $event->created_at ) ) . '</td>';
			echo '<td><code>' . esc_html( $event->event_type ) . '</code></td>';
			echo '<td><code>' . esc_html( ( $event->from_state ?? '' ) . ' -> ' . ( $event->to_state ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( null === $event->attempt_number ? '' : (string) $event->attempt_number ) . '</td>';
			echo '<td>' . esc_html( $event->actor_type ) . '<br />' . esc_html( DeliveryDisplaySnapshot::user_label( $event->actor_user_id ) ) . '</td>';
			echo '<td><code>' . esc_html( $event->provider_result ?? '' ) . '</code></td>';
			echo '<td><code>' . esc_html( $event->retry_safety ?? '' ) . '</code></td>';
			echo '<td><pre class="pq-tech-json">' . esc_html( DeliveryDisplaySnapshot::pretty_json( $snapshot->event_detail_summary( $event ) ) ) . '</pre></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Return cancellation confirmation copy.
	 *
	 * @return string
	 */
	private static function cancel_confirmation(): string {
		return __( 'Yes, cancel this share. PressQueue will not send it.', 'pressqueue' );
	}

	/**
	 * Return the one acknowledgement each "Check Buffer" choice asks for.
	 *
	 * @return string
	 */
	public static function reconciliation_confirmation(): string {
		return __( 'I looked for this post in Buffer', 'pressqueue' );
	}

	/**
	 * Return immutable bound identity summary.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return string
	 */
	private static function bound_identity( DeliveryRecord $delivery ): string {
		return implode(
			' / ',
			array_filter(
				array(
					$delivery->bound_provider,
					$delivery->bound_connection_public_id,
					$delivery->bound_remote_account_id,
					$delivery->bound_remote_organization_id,
					$delivery->bound_channel_public_id,
					$delivery->bound_remote_channel_id,
					null === $delivery->bound_capability_schema_version ? null : 'capability v' . (string) $delivery->bound_capability_schema_version,
				),
				'is_string'
			)
		);
	}

	/**
	 * Load the requested delivery.
	 *
	 * @return DeliveryRecord
	 */
	private static function requested_delivery(): DeliveryRecord {
		$public_id = self::query_text( 'delivery_public_id', 26 );
		$delivery  = ( new DeliveryRepository() )->find_by_public_id( $public_id );

		if ( null === $delivery ) {
			throw new ValidationException( 'Delivery does not exist.' );
		}

		return $delivery;
	}

	/**
	 * Return bounded query text.
	 *
	 * @param string $key Key.
	 * @param int    $maximum Maximum bytes.
	 * @return string
	 */
	private static function query_text( string $key, int $maximum ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Delivery detail query args are read-only exact values escaped on output.
		$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		return strlen( $value ) > $maximum ? substr( $value, 0, $maximum ) : $value;
	}

	/**
	 * Require an administration capability.
	 *
	 * @param string $capability Capability.
	 */
	private static function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to use this PressQueue screen.', 'pressqueue' ) );
		}
	}
}
