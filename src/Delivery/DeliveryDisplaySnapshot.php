<?php
/**
 * Delivery display snapshot.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\Record\DeliveryEventRecord;
use PressQueue\Storage\Record\DeliveryRecord;

/**
 * Builds redacted operator display values from immutable delivery records.
 */
final class DeliveryDisplaySnapshot {
	private const STALLED_THRESHOLD_SECONDS = ActionSchedulerQueue::SWEEP_INTERVAL_SECONDS * 2;

	/**
	 * Delivery record.
	 *
	 * @var DeliveryRecord
	 */
	public DeliveryRecord $delivery;

	/**
	 * Decision record.
	 *
	 * @var DecisionRecord|null
	 */
	public ?DecisionRecord $decision;

	/**
	 * Channel label.
	 *
	 * @var string
	 */
	public string $channel_label;

	/**
	 * Connection label.
	 *
	 * @var string
	 */
	public string $connection_label;

	/**
	 * Service label.
	 *
	 * @var string
	 */
	public string $service;

	/**
	 * Constructor.
	 *
	 * @param DeliveryRecord      $delivery Delivery.
	 * @param DecisionRecord|null $decision Decision.
	 */
	public function __construct( DeliveryRecord $delivery, ?DecisionRecord $decision ) {
		$this->delivery         = $delivery;
		$this->decision         = $decision;
		$this->channel_label    = $this->snapshot_string( 'channel_label', $delivery->channel_public_id );
		$this->connection_label = $this->snapshot_string( 'connection_label', $delivery->connection_public_id );
		$this->service          = $this->snapshot_string( 'service', '' );
	}

	/**
	 * Return the delivery state with a support-safe canonical value.
	 *
	 * @return string
	 */
	public function state_label(): string {
		return $this->label_with_code( $this->delivery->state );
	}

	/**
	 * Return retry safety with a support-safe canonical value.
	 *
	 * @return string
	 */
	public function retry_safety_label(): string {
		return $this->label_with_code( $this->delivery->retry_safety );
	}

	/**
	 * Return provider result with a support-safe canonical value.
	 *
	 * @return string
	 */
	public function provider_result_label(): string {
		return null === $this->delivery->provider_result ? '' : $this->label_with_code( $this->delivery->provider_result );
	}

	/**
	 * Return local submission compatibility for immutable payload versions.
	 *
	 * @return string
	 */
	public function submission_compatibility_label(): string {
		if ( 1 === $this->delivery->payload_schema_version && 1 === $this->delivery->adapter_encoding_version ) {
			return $this->label_with_code( 'legacy_encoder_retired' );
		}

		if ( 2 !== $this->delivery->payload_schema_version ) {
			return $this->label_with_code( 'unsupported_payload_version' );
		}

		if ( 2 !== $this->delivery->adapter_encoding_version ) {
			return $this->label_with_code( 'unsupported_encoder_version' );
		}

		return $this->label_with_code( 'compatible' );
	}

	/**
	 * Return a human-readable error class while keeping the canonical value.
	 *
	 * @param string|null $error_class Error class.
	 * @return string
	 */
	public function error_class_label( ?string $error_class ): string {
		return null === $error_class || '' === $error_class ? '' : $this->label_with_code( $error_class );
	}

	/**
	 * Return reconciliation status with a support-safe canonical value.
	 *
	 * @return string
	 */
	public function reconciliation_status_label(): string {
		return $this->label_with_code( $this->delivery->reconciliation_status );
	}

	/**
	 * Return a compact post identity from immutable decision facts.
	 *
	 * @return string
	 */
	public function post_identity(): string {
		if ( null === $this->decision ) {
			return 'Post #' . (string) $this->delivery->decision_id;
		}

		return sprintf(
			'Post #%d, %s/%s',
			$this->decision->post_id,
			$this->decision->post_type,
			$this->decision->post_status
		);
	}

	/**
	 * Return a compact rule identity from immutable decision facts.
	 *
	 * @return string
	 */
	public function rule_identity(): string {
		if ( null === $this->decision || null === $this->decision->rule_public_id || null === $this->decision->rule_version ) {
			return $this->delivery->rule_public_id . ' v' . (string) $this->delivery->rule_version;
		}

		return $this->decision->rule_public_id . ' v' . (string) $this->decision->rule_version;
	}

	/**
	 * Return redacted receipt summary.
	 *
	 * @return array<string,mixed>
	 */
	public function receipt_summary(): array {
		if ( null === $this->delivery->receipt ) {
			return array();
		}

		return $this->redacted_document( $this->delivery->receipt );
	}

	/**
	 * Return redacted error detail.
	 *
	 * @return array<string,mixed>
	 */
	public function error_summary(): array {
		if ( null === $this->delivery->error_detail ) {
			return array();
		}

		return $this->redacted_document( $this->delivery->error_detail );
	}

	/**
	 * Return redacted event detail.
	 *
	 * @param DeliveryEventRecord $event Event.
	 * @return array<string,mixed>
	 */
	public function event_detail_summary( DeliveryEventRecord $event ): array {
		return $this->redacted_document( $event->detail );
	}

	/**
	 * Return a lease status without exposing the lease owner token.
	 *
	 * @return string
	 */
	public function lease_status(): string {
		if ( null === $this->delivery->lease_owner || null === $this->delivery->lease_expires_at ) {
			return 'none';
		}

		return $this->delivery->lease_expires_at->getTimestamp() > time() ? 'active' : 'expired';
	}

	/**
	 * Return a stalled-worker signal when the row is old and scheduling is unhealthy.
	 *
	 * @param ActionSchedulerQueue $scheduler Scheduler.
	 * @return string|null
	 */
	public function stalled_worker_signal( ActionSchedulerQueue $scheduler ): ?string {
		if ( ! $this->is_waiting_on_worker() || ! $this->is_older_than_threshold() ) {
			return null;
		}

		if ( ! $scheduler->is_ready() ) {
			return 'Scheduler unavailable';
		}

		if ( ! $scheduler->has_recurring_sweep() ) {
			return 'Recurring sweep missing';
		}

		return null;
	}

	/**
	 * Format a storage timestamp in UTC.
	 *
	 * @param DateTimeImmutable|null $timestamp Timestamp.
	 * @return string
	 */
	public static function format_timestamp( ?DateTimeImmutable $timestamp ): string {
		if ( null === $timestamp ) {
			return '';
		}

		return $timestamp->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) . ' UTC';
	}

	/**
	 * Return a WordPress user label that survives deleted users.
	 *
	 * @param int|null $user_id User ID.
	 * @return string
	 */
	public static function user_label( ?int $user_id ): string {
		if ( null === $user_id ) {
			return '';
		}

		if ( function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User && '' !== (string) $user->display_name ) {
				return (string) $user->display_name . ' (User #' . (string) $user_id . ')';
			}
		}

		return 'User #' . (string) $user_id . ' (deleted)';
	}

	/**
	 * Read a string from immutable payload or decision destination snapshots.
	 *
	 * @param string $key Snapshot key.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function snapshot_string( string $key, string $fallback ): string {
		if ( isset( $this->delivery->payload_preview[ $key ] ) && is_string( $this->delivery->payload_preview[ $key ] ) && '' !== trim( $this->delivery->payload_preview[ $key ] ) ) {
			return $this->delivery->payload_preview[ $key ];
		}

		$destination = null === $this->decision ? null : ( $this->decision->snapshot['destination'] ?? null );
		if ( is_array( $destination ) && isset( $destination[ $key ] ) && is_string( $destination[ $key ] ) && '' !== trim( $destination[ $key ] ) ) {
			return $destination[ $key ];
		}

		return $fallback;
	}

	/**
	 * Build a label while keeping the canonical value visible.
	 *
	 * @param string $value Canonical value.
	 * @return string
	 */
	private function label_with_code( string $value ): string {
		return ucwords( str_replace( '_', ' ', $value ) ) . ' (' . $value . ')';
	}

	/**
	 * Check whether a delivery is waiting on scheduler or worker activity.
	 *
	 * @return bool
	 */
	private function is_waiting_on_worker(): bool {
		if ( 'queued' === $this->delivery->state ) {
			return true;
		}

		return 'retrying' === $this->delivery->state
			&& null !== $this->delivery->next_attempt_at
			&& $this->delivery->next_attempt_at->getTimestamp() <= time();
	}

	/**
	 * Check whether a waiting row is older than the stalled threshold.
	 *
	 * @return bool
	 */
	private function is_older_than_threshold(): bool {
		$timestamp = $this->delivery->state_changed_at ?? $this->delivery->updated_at;

		return ( time() - $timestamp->getTimestamp() ) >= self::STALLED_THRESHOLD_SECONDS;
	}

	/**
	 * Redact unsafe diagnostic keys before rendering.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return array<string,mixed>
	 */
	private function redacted_document( array $document ): array {
		$redacted = array();

		foreach ( $document as $key => $value ) {
			$key = (string) $key;
			if ( $this->is_secret_key( $key ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				if ( array_is_list( $value ) ) {
					$redacted[ $key ] = array_map( array( $this, 'redacted_value' ), $value );
				} else {
					$redacted[ $key ] = $this->redacted_document( $value );
				}
				continue;
			}

			$redacted[ $key ] = $this->redacted_value( $value );
		}

		return $redacted;
	}

	/**
	 * Redact one diagnostic value.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function redacted_value( $value ) {
		if ( is_array( $value ) ) {
			return array_is_list( $value ) ? array_map( array( $this, 'redacted_value' ), $value ) : $this->redacted_document( $value );
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( preg_match( '/bearer\s+[A-Za-z0-9._~+\/=-]+/i', $value ) ) {
			return '[redacted]';
		}

		if ( strlen( $value ) > 1000 ) {
			return substr( $value, 0, 1000 ) . '...';
		}

		return $value;
	}

	/**
	 * Check whether a diagnostic key can contain secret-bearing data.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private function is_secret_key( string $key ): bool {
		return 1 === preg_match( '/authorization|credential|api[_-]?key|token|secret|password|envelope|ciphertext|nonce|request[_ -]?(variables|body)|raw[_ -]?response|provider[_ -]?payload/i', $key );
	}

	/**
	 * Return safe JSON for display.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return string
	 */
	public static function pretty_json( array $document ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback is used only when WordPress is unavailable.
		return (string) json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
