<?php
/**
 * Delivery event storage record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

use DateTimeImmutable;

/**
 * Represents one append-only delivery event.
 */
final class DeliveryEventRecord {
	public int $id;
	public string $public_id;
	public int $delivery_id;
	public string $delivery_public_id;
	public string $event_type;
	public ?string $from_state;
	public ?string $to_state;
	public ?int $attempt_number;
	public ?int $action_id;
	public string $actor_type;
	public ?int $actor_user_id;
	public ?string $provider_result;
	public ?string $retry_safety;
	/** @var array<string,mixed> */
	public array $detail;
	public DateTimeImmutable $created_at;

	/**
	 * Constructor.
	 *
	 * @param int                 $id Internal ID.
	 * @param string              $public_id Public ID.
	 * @param int                 $delivery_id Delivery internal ID.
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param string              $event_type Event type.
	 * @param string|null         $from_state Source state.
	 * @param string|null         $to_state Target state.
	 * @param int|null            $attempt_number Attempt number.
	 * @param int|null            $action_id Action Scheduler action ID.
	 * @param string              $actor_type Actor type.
	 * @param int|null            $actor_user_id Actor user ID.
	 * @param string|null         $provider_result Provider result.
	 * @param string|null         $retry_safety Retry safety.
	 * @param array<string,mixed> $detail Redacted detail document.
	 * @param DateTimeImmutable   $created_at Creation timestamp.
	 */
	public function __construct(
		int $id,
		string $public_id,
		int $delivery_id,
		string $delivery_public_id,
		string $event_type,
		?string $from_state,
		?string $to_state,
		?int $attempt_number,
		?int $action_id,
		string $actor_type,
		?int $actor_user_id,
		?string $provider_result,
		?string $retry_safety,
		array $detail,
		DateTimeImmutable $created_at
	) {
		$this->id                 = $id;
		$this->public_id          = $public_id;
		$this->delivery_id        = $delivery_id;
		$this->delivery_public_id = $delivery_public_id;
		$this->event_type         = $event_type;
		$this->from_state         = $from_state;
		$this->to_state           = $to_state;
		$this->attempt_number     = $attempt_number;
		$this->action_id          = $action_id;
		$this->actor_type         = $actor_type;
		$this->actor_user_id      = $actor_user_id;
		$this->provider_result    = $provider_result;
		$this->retry_safety       = $retry_safety;
		$this->detail             = $detail;
		$this->created_at         = $created_at;
	}
}
