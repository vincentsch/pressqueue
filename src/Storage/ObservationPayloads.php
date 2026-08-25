<?php
/**
 * Redacted observation hook payloads.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\Record\DeliveryEventRecord;

/**
 * Builds versioned, redacted observation payloads.
 */
final class ObservationPayloads {
	/**
	 * Build the decision-recorded hook payload.
	 *
	 * @param DecisionRecord $record Decision record.
	 * @return array<string,mixed>
	 */
	public static function decision( DecisionRecord $record ): array {
		return array(
			'created_at_utc'     => self::rfc3339( $record->created_at ),
			'decision_key'       => $record->decision_key,
			'decision_public_id' => $record->public_id,
			'event_key'          => $record->event_key,
			'event_mode'         => $record->event_mode,
			'post_id'            => $record->post_id,
			'result'             => $record->result,
			'rule_public_id'     => $record->rule_public_id,
			'rule_version'       => $record->rule_version,
			'schema_version'     => 1,
			'scope_public_id'    => $record->scope_public_id,
			'scope_type'         => $record->scope_type,
			'snapshot_hash'      => $record->snapshot_hash,
			'trigger'            => $record->trigger,
		);
	}

	/**
	 * Build the delivery-event-recorded hook payload.
	 *
	 * @param DeliveryEventRecord $record Delivery event record.
	 * @param string|null         $delivery_key Delivery key.
	 * @return array<string,mixed>
	 */
	public static function delivery_event( DeliveryEventRecord $record, ?string $delivery_key ): array {
		return array(
			'action_id'          => $record->action_id,
			'actor_type'         => $record->actor_type,
			'attempt_number'     => $record->attempt_number,
			'classification'     => isset( $record->detail['classification'] ) && is_string( $record->detail['classification'] ) ? $record->detail['classification'] : null,
			'created_at_utc'     => self::rfc3339( $record->created_at ),
			'delivery_key'       => $delivery_key,
			'delivery_public_id' => $record->delivery_public_id,
			'event_public_id'    => $record->public_id,
			'event_type'         => $record->event_type,
			'from_state'         => $record->from_state,
			'provider_result'    => $record->provider_result,
			'retry_safety'       => $record->retry_safety,
			'schema_version'     => 1,
			'to_state'           => $record->to_state,
		);
	}

	/**
	 * Format a timestamp as UTC RFC 3339.
	 *
	 * @param DateTimeImmutable $date_time Date time.
	 * @return string
	 */
	private static function rfc3339( DateTimeImmutable $date_time ): string {
		return $date_time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}
}
