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
	public ?string $intent_occurrence_key;
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
	public ?DateTimeImmutable $scheduled_for_utc;
	public ?string $schedule_timezone;
	/** @var array<string,mixed> */
	public array $payload_preview;
	public string $payload_hash;
	public DateTimeImmutable $created_at;
	public DateTimeImmutable $updated_at;
	public ?string $bound_provider;
	public ?string $bound_connection_public_id;
	public ?string $bound_channel_public_id;
	public ?string $bound_remote_account_id;
	public ?string $bound_remote_organization_id;
	public ?string $bound_remote_channel_id;
	public ?int $bound_capability_schema_version;
	public ?string $bound_capability_hash;
	public ?string $bound_capability_catalog_version;
	/** @var array<string,mixed>|null */
	public ?array $media_snapshot;
	public ?string $media_identity_hash;
	public ?DateTimeImmutable $queued_at;
	public ?DateTimeImmutable $last_attempt_at;
	public int $attempt_count;
	public int $automatic_attempt_count;
	public ?DateTimeImmutable $next_attempt_at;
	public ?string $lease_owner;
	public ?DateTimeImmutable $lease_expires_at;
	public ?int $action_id;
	public ?string $provider_result;
	public string $retry_safety;
	/** @var array<string,mixed>|null */
	public ?array $receipt;
	public ?string $remote_id;
	public ?string $remote_receipt_key;
	public ?string $error_class;
	/** @var array<string,mixed>|null */
	public ?array $error_detail;
	public string $reconciliation_status;
	public ?DateTimeImmutable $reconciled_at;
	public ?int $reconciled_by;
	public ?string $reconciliation_note;
	public int $payload_schema_version;
	public int $adapter_encoding_version;
	public ?DateTimeImmutable $state_changed_at;

	/**
	 * Constructor.
	 *
	 * @param int                      $id Internal ID.
	 * @param string                   $public_id Public ID.
	 * @param int                      $decision_id Decision internal ID.
	 * @param string                   $decision_public_id Decision public ID.
	 * @param string                   $event_key Event key.
	 * @param string                   $delivery_key Delivery key.
	 * @param string|null              $intent_occurrence_key Manual batch occurrence key.
	 * @param string                   $delivery_mode Delivery mode.
	 * @param string                   $provider Provider key.
	 * @param int                      $connection_id Connection internal ID.
	 * @param string                   $connection_public_id Connection public ID.
	 * @param int                      $channel_id Channel internal ID.
	 * @param string                   $channel_public_id Channel public ID.
	 * @param string                   $remote_channel_id Remote channel ID.
	 * @param string                   $rule_public_id Rule public ID.
	 * @param int                      $rule_version Rule version.
	 * @param string                   $state Delivery state.
	 * @param string                   $schedule_slot Schedule slot.
	 * @param DateTimeImmutable|null   $scheduled_for_utc Scheduled UTC time.
	 * @param string|null              $schedule_timezone Schedule source timezone.
	 * @param array<string,mixed>      $payload_preview Payload preview document.
	 * @param string                   $payload_hash Payload hash.
	 * @param DateTimeImmutable        $created_at Creation timestamp.
	 * @param DateTimeImmutable        $updated_at Update timestamp.
	 * @param string|null              $bound_provider Bound provider.
	 * @param string|null              $bound_connection_public_id Bound connection public ID.
	 * @param string|null              $bound_channel_public_id Bound channel public ID.
	 * @param string|null              $bound_remote_account_id Bound remote account ID.
	 * @param string|null              $bound_remote_organization_id Bound remote organization ID.
	 * @param string|null              $bound_remote_channel_id Bound remote channel ID.
	 * @param int|null                 $bound_capability_schema_version Bound capability schema version.
	 * @param string|null              $bound_capability_hash Bound capability hash.
	 * @param string|null              $bound_capability_catalog_version Bound capability catalog version.
	 * @param array<string,mixed>|null $media_snapshot Media snapshot.
	 * @param string|null              $media_identity_hash Media identity hash.
	 * @param DateTimeImmutable|null   $queued_at Queue timestamp.
	 * @param DateTimeImmutable|null   $last_attempt_at Last attempt timestamp.
	 * @param int                      $attempt_count Total provider submission attempts.
	 * @param int                      $automatic_attempt_count Automatic provider submission attempts.
	 * @param DateTimeImmutable|null   $next_attempt_at Next retry timestamp.
	 * @param string|null              $lease_owner Lease owner token.
	 * @param DateTimeImmutable|null   $lease_expires_at Lease expiry.
	 * @param int|null                 $action_id Action Scheduler action ID.
	 * @param string|null              $provider_result Last provider result.
	 * @param string                   $retry_safety Retry safety.
	 * @param array<string,mixed>|null $receipt Sanitized receipt.
	 * @param string|null              $remote_id Remote provider ID.
	 * @param string|null              $remote_receipt_key Local remote receipt uniqueness key.
	 * @param string|null              $error_class Error class.
	 * @param array<string,mixed>|null $error_detail Redacted error detail.
	 * @param string                   $reconciliation_status Reconciliation status.
	 * @param DateTimeImmutable|null   $reconciled_at Reconciliation timestamp.
	 * @param int|null                 $reconciled_by Reconciliation user ID.
	 * @param string|null              $reconciliation_note Reconciliation note.
	 * @param int                      $payload_schema_version Payload schema version.
	 * @param int                      $adapter_encoding_version Adapter encoding version.
	 * @param DateTimeImmutable|null   $state_changed_at State change timestamp.
	 */
	public function __construct(
		int $id,
		string $public_id,
		int $decision_id,
		string $decision_public_id,
		string $event_key,
		string $delivery_key,
		?string $intent_occurrence_key,
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
		?DateTimeImmutable $scheduled_for_utc,
		?string $schedule_timezone,
		array $payload_preview,
		string $payload_hash,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at,
		?string $bound_provider = null,
		?string $bound_connection_public_id = null,
		?string $bound_channel_public_id = null,
		?string $bound_remote_account_id = null,
		?string $bound_remote_organization_id = null,
		?string $bound_remote_channel_id = null,
		?int $bound_capability_schema_version = null,
		?string $bound_capability_hash = null,
		?string $bound_capability_catalog_version = null,
		?array $media_snapshot = null,
		?string $media_identity_hash = null,
		?DateTimeImmutable $queued_at = null,
		?DateTimeImmutable $last_attempt_at = null,
		int $attempt_count = 0,
		int $automatic_attempt_count = 0,
		?DateTimeImmutable $next_attempt_at = null,
		?string $lease_owner = null,
		?DateTimeImmutable $lease_expires_at = null,
		?int $action_id = null,
		?string $provider_result = null,
		string $retry_safety = 'not_retryable',
		?array $receipt = null,
		?string $remote_id = null,
		?string $remote_receipt_key = null,
		?string $error_class = null,
		?array $error_detail = null,
		string $reconciliation_status = 'none',
		?DateTimeImmutable $reconciled_at = null,
		?int $reconciled_by = null,
		?string $reconciliation_note = null,
		int $payload_schema_version = 1,
		int $adapter_encoding_version = 1,
		?DateTimeImmutable $state_changed_at = null
	) {
		$this->id                               = $id;
		$this->public_id                        = $public_id;
		$this->decision_id                      = $decision_id;
		$this->decision_public_id               = $decision_public_id;
		$this->event_key                        = $event_key;
		$this->delivery_key                     = $delivery_key;
		$this->intent_occurrence_key            = $intent_occurrence_key;
		$this->delivery_mode                    = $delivery_mode;
		$this->provider                         = $provider;
		$this->connection_id                    = $connection_id;
		$this->connection_public_id             = $connection_public_id;
		$this->channel_id                       = $channel_id;
		$this->channel_public_id                = $channel_public_id;
		$this->remote_channel_id                = $remote_channel_id;
		$this->rule_public_id                   = $rule_public_id;
		$this->rule_version                     = $rule_version;
		$this->state                            = $state;
		$this->schedule_slot                    = $schedule_slot;
		$this->scheduled_for_utc                = $scheduled_for_utc;
		$this->schedule_timezone                = $schedule_timezone;
		$this->payload_preview                  = $payload_preview;
		$this->payload_hash                     = $payload_hash;
		$this->created_at                       = $created_at;
		$this->updated_at                       = $updated_at;
		$this->bound_provider                   = $bound_provider;
		$this->bound_connection_public_id       = $bound_connection_public_id;
		$this->bound_channel_public_id          = $bound_channel_public_id;
		$this->bound_remote_account_id          = $bound_remote_account_id;
		$this->bound_remote_organization_id     = $bound_remote_organization_id;
		$this->bound_remote_channel_id          = $bound_remote_channel_id;
		$this->bound_capability_schema_version  = $bound_capability_schema_version;
		$this->bound_capability_hash            = $bound_capability_hash;
		$this->bound_capability_catalog_version = $bound_capability_catalog_version;
		$this->media_snapshot                   = $media_snapshot;
		$this->media_identity_hash              = $media_identity_hash;
		$this->queued_at                        = $queued_at;
		$this->last_attempt_at                  = $last_attempt_at;
		$this->attempt_count                    = $attempt_count;
		$this->automatic_attempt_count          = $automatic_attempt_count;
		$this->next_attempt_at                  = $next_attempt_at;
		$this->lease_owner                      = $lease_owner;
		$this->lease_expires_at                 = $lease_expires_at;
		$this->action_id                        = $action_id;
		$this->provider_result                  = $provider_result;
		$this->retry_safety                     = $retry_safety;
		$this->receipt                          = $receipt;
		$this->remote_id                        = $remote_id;
		$this->remote_receipt_key               = $remote_receipt_key;
		$this->error_class                      = $error_class;
		$this->error_detail                     = $error_detail;
		$this->reconciliation_status            = $reconciliation_status;
		$this->reconciled_at                    = $reconciled_at;
		$this->reconciled_by                    = $reconciled_by;
		$this->reconciliation_note              = $reconciliation_note;
		$this->payload_schema_version           = $payload_schema_version;
		$this->adapter_encoding_version         = $adapter_encoding_version;
		$this->state_changed_at                 = $state_changed_at;
	}
}
