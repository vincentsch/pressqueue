<?php
/**
 * Provider delivery request DTO.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Dto;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover immutable DTO fields.

/**
 * Immutable provider delivery input.
 */
final class ProviderDeliveryRequest {
	public readonly string $provider;
	public readonly string $delivery_public_id;
	public readonly string $delivery_key;
	public readonly int $attempt_number;
	public readonly string $bound_connection_public_id;
	public readonly string $bound_remote_account_id;
	public readonly string $bound_remote_organization_id;
	public readonly string $bound_remote_channel_id;
	public readonly int $payload_schema_version;
	public readonly int $adapter_encoding_version;
	public readonly string $message;
	public readonly string $canonical_url;
	public readonly string $final_encoded_text;
	public readonly string $schedule_slot;
	public readonly ?string $scheduled_for_utc;
	/** @var array<string,mixed> */
	public readonly array $media_snapshot;
	/** @var array<string,mixed> */
	public readonly array $redaction_context;

	/**
	 * Constructor.
	 *
	 * @param string              $provider Provider key.
	 * @param string              $delivery_public_id Delivery public ID.
	 * @param string              $delivery_key Delivery key.
	 * @param int                 $attempt_number Attempt number.
	 * @param string              $bound_connection_public_id Bound connection public ID.
	 * @param string              $bound_remote_account_id Bound remote account ID.
	 * @param string              $bound_remote_organization_id Bound remote organization ID.
	 * @param string              $bound_remote_channel_id Bound remote channel ID.
	 * @param int                 $payload_schema_version Payload schema version.
	 * @param int                 $adapter_encoding_version Adapter encoding version.
	 * @param string              $message Immutable rendered message.
	 * @param string              $canonical_url Canonical URL.
	 * @param string|null         $final_encoded_text Final encoded provider text.
	 * @param string              $schedule_slot Schedule slot.
	 * @param string|null         $scheduled_for_utc Scheduled UTC time.
	 * @param array<string,mixed> $media_snapshot Media snapshot.
	 * @param array<string,mixed> $redaction_context Redaction context.
	 */
	public function __construct(
		string $provider,
		string $delivery_public_id,
		string $delivery_key,
		int $attempt_number,
		string $bound_connection_public_id,
		string $bound_remote_account_id,
		string $bound_remote_organization_id,
		string $bound_remote_channel_id,
		int $payload_schema_version,
		int $adapter_encoding_version,
		string $message,
		string $canonical_url,
		?string $final_encoded_text = null,
		string $schedule_slot = 'immediate',
		?string $scheduled_for_utc = null,
		array $media_snapshot = array(),
		array $redaction_context = array()
	) {
		$this->provider                     = $provider;
		$this->delivery_public_id           = $delivery_public_id;
		$this->delivery_key                 = $delivery_key;
		$this->attempt_number               = $attempt_number;
		$this->bound_connection_public_id   = $bound_connection_public_id;
		$this->bound_remote_account_id      = $bound_remote_account_id;
		$this->bound_remote_organization_id = $bound_remote_organization_id;
		$this->bound_remote_channel_id      = $bound_remote_channel_id;
		$this->payload_schema_version       = $payload_schema_version;
		$this->adapter_encoding_version     = $adapter_encoding_version;
		$this->message                      = $message;
		$this->canonical_url                = $canonical_url;
		$this->final_encoded_text           = $final_encoded_text ?? trim( $message ) . "\n\n" . $canonical_url;
		$this->schedule_slot                = $schedule_slot;
		$this->scheduled_for_utc            = $scheduled_for_utc;
		$this->media_snapshot               = $media_snapshot;
		$this->redaction_context            = $redaction_context;
	}
}
