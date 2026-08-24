<?php
/**
 * Delivery detail administration.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Delivery\DeliveryDisplaySnapshot;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\DeliveryEventRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\ValidationException;

/**
 * Renders one delivery and its event history.
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
			__( 'PressQueue Delivery Detail', 'pressqueue' ),
			__( 'Delivery Detail', 'pressqueue' ),
			Capabilities::VIEW_DELIVERIES,
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Render delivery detail.
	 */
	public static function render_page(): void {
		self::require_capability( Capabilities::VIEW_DELIVERIES );

		try {
			$delivery = self::requested_delivery();
		} catch ( ValidationException $exception ) {
			wp_die( esc_html( $exception->getMessage() ) );
		}

		$decision = ( new DecisionRepository() )->find_by_public_id( $delivery->decision_public_id );
		$events   = ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id );
		$snapshot = new DeliveryDisplaySnapshot( $delivery, $decision );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PressQueue Delivery Detail', 'pressqueue' ) . '</h1>';
		self::render_notice();
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . DeliveryListController::SLUG ) ) . '">' . esc_html__( 'Back to Delivery Log', 'pressqueue' ) . '</a></p>';
		self::render_projection( $snapshot );
		self::render_actions( $delivery );
		self::render_payload_summary( $snapshot );
		self::render_decision_summary( $snapshot );
		self::render_event_history( $snapshot, $events );
		echo '</div>';
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
			__( 'Payload hash', 'pressqueue' )             => $delivery->payload_hash,
			__( 'Payload schema version', 'pressqueue' )   => (string) $delivery->payload_schema_version,
			__( 'Adapter encoding version', 'pressqueue' ) => (string) $delivery->adapter_encoding_version,
			__( 'Bound provider identity', 'pressqueue' )  => self::bound_identity( $delivery ),
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

		echo '<h2>' . esc_html__( 'Current Projection', 'pressqueue' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render action forms.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private static function render_actions( DeliveryRecord $delivery ): void {
		if ( ! current_user_can( Capabilities::OPERATE_DELIVERIES ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Operator Actions', 'pressqueue' ) . '</h2>';

		if ( in_array( $delivery->state, array( 'proposed', 'held', 'queued', 'retrying', 'failed' ), true ) ) {
			self::button_form(
				'pressqueue_cancel_delivery',
				'pressqueue_cancel_delivery',
				__( 'Cancel Delivery', 'pressqueue' ),
				$delivery,
				self::cancel_confirmation()
			);
		}

		if ( 'held' === $delivery->state ) {
			self::button_form( 'pressqueue_resume_delivery', 'pressqueue_resume_delivery', __( 'Resume Delivery', 'pressqueue' ), $delivery );
		}

		if ( ( 'failed' === $delivery->state && 'manual_allowed' === $delivery->retry_safety ) || ( 'ambiguous' === $delivery->state && 'confirmed_not_accepted' === $delivery->reconciliation_status && 'manual_allowed' === $delivery->retry_safety ) ) {
			self::button_form( 'pressqueue_retry_delivery', 'pressqueue_retry_delivery', __( 'Retry Delivery', 'pressqueue' ), $delivery );
		}

		if ( 'ambiguous' === $delivery->state ) {
			self::render_reconciliation_forms( $delivery );
		}
	}

	/**
	 * Render payload summary.
	 *
	 * @param DeliveryDisplaySnapshot $snapshot Snapshot.
	 */
	private static function render_payload_summary( DeliveryDisplaySnapshot $snapshot ): void {
		echo '<h2>' . esc_html__( 'Payload Summary', 'pressqueue' ) . '</h2>';
		echo '<pre>' . esc_html( DeliveryDisplaySnapshot::pretty_json( $snapshot->delivery->payload_preview ) ) . '</pre>';
		echo '<h2>' . esc_html__( 'Receipt and Error Summary', 'pressqueue' ) . '</h2>';
		echo '<pre>' . esc_html(
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

		echo '<h2>' . esc_html__( 'Decision Summary', 'pressqueue' ) . '</h2>';

		if ( null === $decision ) {
			echo '<p>' . esc_html__( 'The referenced decision record is unavailable.', 'pressqueue' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Decision key', 'pressqueue' ) . '</th><td><code>' . esc_html( $decision->decision_key ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Result', 'pressqueue' ) . '</th><td><code>' . esc_html( $decision->result ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Trigger', 'pressqueue' ) . '</th><td><code>' . esc_html( $decision->trigger ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Snapshot hash', 'pressqueue' ) . '</th><td><code>' . esc_html( $decision->snapshot_hash ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Reasons', 'pressqueue' ) . '</th><td><pre>' . esc_html( DeliveryDisplaySnapshot::pretty_json( array( 'reasons' => $decision->reasons ) ) ) . '</pre></td></tr>';
		echo '</tbody></table>';

		if ( current_user_can( Capabilities::VIEW_DECISIONS ) ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=pressqueue-decisions' ) ) . '">' . esc_html__( 'Open Decisions', 'pressqueue' ) . '</a></p>';
		}
	}

	/**
	 * Render append-only event history.
	 *
	 * @param DeliveryDisplaySnapshot                                   $snapshot Snapshot.
	 * @param array<int,\PressQueue\Storage\Record\DeliveryEventRecord> $events Events.
	 */
	private static function render_event_history( DeliveryDisplaySnapshot $snapshot, array $events ): void {
		echo '<h2>' . esc_html__( 'Event History', 'pressqueue' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Time', 'pressqueue' ), __( 'Event', 'pressqueue' ), __( 'State', 'pressqueue' ), __( 'Attempt', 'pressqueue' ), __( 'Actor', 'pressqueue' ), __( 'Provider Result', 'pressqueue' ), __( 'Retry Safety', 'pressqueue' ), __( 'Detail', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $events ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No delivery events have been recorded.', 'pressqueue' ) . '</td></tr>';
		}

		foreach ( $events as $event ) {
			echo '<tr>';
			echo '<td>' . esc_html( DeliveryDisplaySnapshot::format_timestamp( $event->created_at ) ) . '</td>';
			echo '<td><code>' . esc_html( $event->event_type ) . '</code></td>';
			echo '<td><code>' . esc_html( ( $event->from_state ?? '' ) . ' -> ' . ( $event->to_state ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( null === $event->attempt_number ? '' : (string) $event->attempt_number ) . '</td>';
			echo '<td>' . esc_html( $event->actor_type ) . '<br />' . esc_html( DeliveryDisplaySnapshot::user_label( $event->actor_user_id ) ) . '</td>';
			echo '<td><code>' . esc_html( $event->provider_result ?? '' ) . '</code></td>';
			echo '<td><code>' . esc_html( $event->retry_safety ?? '' ) . '</code></td>';
			echo '<td><pre>' . esc_html( DeliveryDisplaySnapshot::pretty_json( $snapshot->event_detail_summary( $event ) ) ) . '</pre></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render reconciliation controls for ambiguous deliveries.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private static function render_reconciliation_forms( DeliveryRecord $delivery ): void {
		echo '<h3>' . esc_html__( 'Ambiguity Reconciliation', 'pressqueue' ) . '</h3>';
		self::reconciliation_form( $delivery, 'confirmed_remote_id', __( 'Confirm Remote ID', 'pressqueue' ), true );
		self::reconciliation_form( $delivery, 'confirmed_not_accepted', __( 'Confirm Not Accepted', 'pressqueue' ), false );
		self::reconciliation_form( $delivery, 'cancelled', __( 'Cancel Ambiguous Delivery', 'pressqueue' ), false );
	}

	/**
	 * Render one reconciliation form.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param string         $outcome Outcome.
	 * @param string         $label Button label.
	 * @param bool           $needs_remote_id Whether remote ID is required.
	 */
	private static function reconciliation_form( DeliveryRecord $delivery, string $outcome, string $label, bool $needs_remote_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:.75em 0;">';
		wp_nonce_field( 'pressqueue_reconcile_delivery_' . $outcome );
		echo '<input type="hidden" name="action" value="pressqueue_reconcile_delivery" />';
		echo '<input type="hidden" name="delivery_public_id" value="' . esc_attr( $delivery->public_id ) . '" />';
		echo '<input type="hidden" name="outcome" value="' . esc_attr( $outcome ) . '" />';
		if ( $needs_remote_id ) {
			echo '<p><label>' . esc_html__( 'Buffer remote ID', 'pressqueue' ) . ' <input name="remote_id" type="text" maxlength="191" required /></label></p>';
		}
		echo '<p><label>' . esc_html__( 'Evidence note', 'pressqueue' ) . '<br /><textarea name="note" maxlength="500" rows="3" class="large-text"></textarea></label></p>';
		echo '<p><label><input type="checkbox" name="confirm_local_effect" value="1" required /> ' . esc_html( self::reconciliation_confirmation() ) . '</label></p>';
		submit_button( $label, 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render one button form.
	 *
	 * @param string         $action Admin-post action.
	 * @param string         $nonce Nonce action.
	 * @param string         $label Button label.
	 * @param DeliveryRecord $delivery Delivery.
	 * @param string|null    $confirmation Optional confirmation copy.
	 */
	private static function button_form( string $action, string $nonce, string $label, DeliveryRecord $delivery, ?string $confirmation = null ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:.25em .5em .25em 0;">';
		wp_nonce_field( $nonce );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="delivery_public_id" value="' . esc_attr( $delivery->public_id ) . '" />';
		if ( null !== $confirmation ) {
			echo '<p><label><input type="checkbox" name="confirm_local_effect" value="1" required /> ' . esc_html( $confirmation ) . '</label></p>';
		}
		submit_button( $label, 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Return cancellation confirmation copy.
	 *
	 * @return string
	 */
	private static function cancel_confirmation(): string {
		return __( 'Cancel only this local delivery record. PressQueue will not contact Buffer and will not remove any remote post.', 'pressqueue' );
	}

	/**
	 * Return reconciliation confirmation copy.
	 *
	 * @return string
	 */
	private static function reconciliation_confirmation(): string {
		return __( 'Record local evidence only. PressQueue will not contact Buffer and will not remove any remote post.', 'pressqueue' );
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
	 * Render notice from request.
	 */
	private static function render_notice(): void {
		$message = self::query_text( 'pressqueue_message', 64 );

		$messages = array(
			'delivery_cancelled'  => __( 'Delivery cancelled locally.', 'pressqueue' ),
			'delivery_resumed'    => __( 'Delivery resumed.', 'pressqueue' ),
			'delivery_retried'    => __( 'Delivery queued for manual retry.', 'pressqueue' ),
			'delivery_error'      => __( 'Delivery action failed.', 'pressqueue' ),
			'delivery_reconciled' => __( 'Delivery reconciliation saved.', 'pressqueue' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		$type = str_contains( $message, 'error' ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $messages[ $message ] );

		$detail = self::query_text( 'pressqueue_detail', 191 );
		if ( '' !== $detail ) {
			echo ' ' . esc_html( $detail );
		}

		echo '</p></div>';
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
