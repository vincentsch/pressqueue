<?php
/**
 * Delivery storage record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

use DateTimeImmutable;

/**
 * Represents one immutable delivery preview row.
 */
final class DeliveryRecord {
	public int $id;
	public string $public_id;
	public int $decision_id;
	public string $decision_public_id;
	public string $event_key;
	public string $delivery_key;
	public string $delivery_mode;
	public string $provider;
	public int $connection_id;
	public string $connection_public_id;
	public int $channel_id;
	public string $channel_public_id;
	public string $remote_channel_id;
	public string $rule_public_id;
	public int $rule_version;
	public string $state;
	public string $schedule_slot;
	/** @var array<string,mixed> */
	public array $payload_preview;
	public string $payload_hash;
	public DateTimeImmutable $created_at;
	public DateTimeImmutable $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int                 $id Internal ID.
	 * @param string              $public_id Public ID.
	 * @param int                 $decision_id Decision internal ID.
	 * @param string              $decision_public_id Decision public ID.
	 * @param string              $event_key Event key.
	 * @param string              $delivery_key Delivery key.
	 * @param string              $delivery_mode Delivery mode.
	 * @param string              $provider Provider key.
	 * @param int                 $connection_id Connection internal ID.
	 * @param string              $connection_public_id Connection public ID.
	 * @param int                 $channel_id Channel internal ID.
	 * @param string              $channel_public_id Channel public ID.
	 * @param string              $remote_channel_id Remote channel ID.
	 * @param string              $rule_public_id Rule public ID.
	 * @param int                 $rule_version Rule version.
	 * @param string              $state Delivery state.
	 * @param string              $schedule_slot Schedule slot.
	 * @param array<string,mixed> $payload_preview Payload preview document.
	 * @param string              $payload_hash Payload hash.
	 * @param DateTimeImmutable   $created_at Creation timestamp.
	 * @param DateTimeImmutable   $updated_at Update timestamp.
	 */
	public function __construct(
		int $id,
		string $public_id,
		int $decision_id,
		string $decision_public_id,
		string $event_key,
		string $delivery_key,
		string $delivery_mode,
		string $provider,
		int $connection_id,
		string $connection_public_id,
		int $channel_id,
		string $channel_public_id,
		string $remote_channel_id,
		string $rule_public_id,
		int $rule_version,
		string $state,
		string $schedule_slot,
		array $payload_preview,
		string $payload_hash,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at
	) {
		$this->id                   = $id;
		$this->public_id            = $public_id;
		$this->decision_id          = $decision_id;
		$this->decision_public_id   = $decision_public_id;
		$this->event_key            = $event_key;
		$this->delivery_key         = $delivery_key;
		$this->delivery_mode        = $delivery_mode;
		$this->provider             = $provider;
		$this->connection_id        = $connection_id;
		$this->connection_public_id = $connection_public_id;
		$this->channel_id           = $channel_id;
		$this->channel_public_id    = $channel_public_id;
		$this->remote_channel_id    = $remote_channel_id;
		$this->rule_public_id       = $rule_public_id;
		$this->rule_version         = $rule_version;
		$this->state                = $state;
		$this->schedule_slot        = $schedule_slot;
		$this->payload_preview      = $payload_preview;
		$this->payload_hash         = $payload_hash;
		$this->created_at           = $created_at;
		$this->updated_at           = $updated_at;
	}
}
