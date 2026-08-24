<?php
/**
 * Stable storage hash key helpers.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Builds event, decision, delivery, snapshot, and payload hashes.
 */
final class HashKeys {
	/**
	 * Calculate a local event key.
	 *
	 * @param array<string,mixed> $event_fields Canonical event fields.
	 * @return string
	 */
	public static function event_key( array $event_fields ): string {
		return CanonicalJson::hash( 'event_key', $event_fields );
	}

	/**
	 * Calculate a decision key.
	 *
	 * @param array<string,mixed> $event_fields Canonical event fields.
	 * @param string              $event_key Event key.
	 * @param string              $scope_type Decision scope type.
	 * @param string|null         $scope_public_id Scope public ID when scoped.
	 * @param string|null         $rule_public_id Rule public ID for rule-scoped decisions.
	 * @param int|null            $rule_version Rule version for rule-scoped decisions.
	 * @param string              $result Decision result.
	 * @return string
	 */
	public static function decision_key(
		array $event_fields,
		string $event_key,
		string $scope_type,
		?string $scope_public_id,
		?string $rule_public_id,
		?int $rule_version,
		string $result
	): string {
		return CanonicalJson::hash(
			'decision_key',
			array(
				'event_fields'    => $event_fields,
				'event_key'       => $event_key,
				'result'          => $result,
				'rule_public_id'  => $rule_public_id,
				'rule_version'    => $rule_version,
				'scope_public_id' => $scope_public_id,
				'scope_type'      => $scope_type,
			)
		);
	}

	/**
	 * Calculate a delivery key.
	 *
	 * @param string $delivery_mode Delivery mode.
	 * @param string $provider Provider key.
	 * @param string $connection_public_id Connection public ID.
	 * @param string $channel_public_id Channel public ID.
	 * @param string $remote_channel_id Provider channel ID.
	 * @param int    $post_id WordPress post ID.
	 * @param string $revision_fingerprint Revision fingerprint.
	 * @param string $trigger Rule trigger.
	 * @param string $rule_public_id Winning rule public ID.
	 * @param int    $rule_version Winning rule version.
	 * @param string $schedule_slot Schedule slot.
	 * @return string
	 */
	public static function delivery_key(
		string $delivery_mode,
		string $provider,
		string $connection_public_id,
		string $channel_public_id,
		string $remote_channel_id,
		int $post_id,
		string $revision_fingerprint,
		string $trigger,
		string $rule_public_id,
		int $rule_version,
		string $schedule_slot = 'immediate'
	): string {
		return CanonicalJson::hash(
			'delivery_key',
			array(
				'channel_public_id'    => $channel_public_id,
				'connection_public_id' => $connection_public_id,
				'delivery_mode'        => $delivery_mode,
				'post_id'              => $post_id,
				'provider'             => $provider,
				'remote_channel_id'    => $remote_channel_id,
				'revision_fingerprint' => $revision_fingerprint,
				'rule_public_id'       => $rule_public_id,
				'rule_version'         => $rule_version,
				'schedule_slot'        => $schedule_slot,
				'trigger'              => $trigger,
			)
		);
	}

	/**
	 * Calculate a decision snapshot hash.
	 *
	 * @param array<string,mixed> $snapshot Snapshot document.
	 * @return string
	 */
	public static function snapshot_hash( array $snapshot ): string {
		return CanonicalJson::hash( 'decision_snapshot', $snapshot );
	}

	/**
	 * Calculate a rendered payload preview hash.
	 *
	 * @param array<string,mixed> $payload_fields Semantic payload fields.
	 * @return string
	 */
	public static function payload_hash( array $payload_fields ): string {
		return CanonicalJson::hash( 'payload_preview', $payload_fields );
	}
}
