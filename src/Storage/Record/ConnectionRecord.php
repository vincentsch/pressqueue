<?php
/**
 * Connection storage record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

use DateTimeImmutable;

/**
 * Represents one provider connection.
 */
final class ConnectionRecord {
	public int $id;
	public string $public_id;
	public string $provider;
	public string $label;
	/** @var array<string,mixed> */
	public array $account;
	public bool $has_credential;
	public ?string $credential_envelope_hash;
	public ?string $remote_account_id;
	public ?string $remote_organization_id;
	public ?string $verified_identity_hash;
	public string $connection_status;
	public ?string $sync_lease_owner;
	public ?DateTimeImmutable $sync_lease_expires_at;
	public ?DateTimeImmutable $credential_verified_at;
	public ?DateTimeImmutable $credential_rotated_at;
	public ?DateTimeImmutable $last_sync_started_at;
	public ?DateTimeImmutable $last_sync_completed_at;
	/** @var array<string,mixed>|null */
	public ?array $last_error;
	public bool $enabled;
	public DateTimeImmutable $created_at;
	public DateTimeImmutable $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int                      $id Internal ID.
	 * @param string                   $public_id Public ID.
	 * @param string                   $provider Provider key.
	 * @param string                   $label Display label.
	 * @param array<string,mixed>      $account Account metadata.
	 * @param bool                     $has_credential Whether an encrypted credential envelope exists.
	 * @param string|null              $credential_envelope_hash Encrypted envelope hash.
	 * @param string|null              $remote_account_id Remote provider account ID.
	 * @param string|null              $remote_organization_id Selected remote organization ID.
	 * @param string|null              $verified_identity_hash Verified identity hash.
	 * @param string                   $connection_status Connection lifecycle status.
	 * @param string|null              $sync_lease_owner Sync lease owner.
	 * @param DateTimeImmutable|null   $sync_lease_expires_at Sync lease expiry.
	 * @param DateTimeImmutable|null   $credential_verified_at Credential verification timestamp.
	 * @param DateTimeImmutable|null   $credential_rotated_at Credential rotation timestamp.
	 * @param DateTimeImmutable|null   $last_sync_started_at Last sync start timestamp.
	 * @param DateTimeImmutable|null   $last_sync_completed_at Last sync completion timestamp.
	 * @param array<string,mixed>|null $last_error Redacted last error document.
	 * @param bool                     $enabled Whether the connection is enabled.
	 * @param DateTimeImmutable        $created_at Creation timestamp.
	 * @param DateTimeImmutable        $updated_at Update timestamp.
	 */
	public function __construct(
		int $id,
		string $public_id,
		string $provider,
		string $label,
		array $account,
		bool $has_credential,
		?string $credential_envelope_hash,
		?string $remote_account_id,
		?string $remote_organization_id,
		?string $verified_identity_hash,
		string $connection_status,
		?string $sync_lease_owner,
		?DateTimeImmutable $sync_lease_expires_at,
		?DateTimeImmutable $credential_verified_at,
		?DateTimeImmutable $credential_rotated_at,
		?DateTimeImmutable $last_sync_started_at,
		?DateTimeImmutable $last_sync_completed_at,
		?array $last_error,
		bool $enabled,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at
	) {
		$this->id                       = $id;
		$this->public_id                = $public_id;
		$this->provider                 = $provider;
		$this->label                    = $label;
		$this->account                  = $account;
		$this->has_credential           = $has_credential;
		$this->credential_envelope_hash = $credential_envelope_hash;
		$this->remote_account_id        = $remote_account_id;
		$this->remote_organization_id   = $remote_organization_id;
		$this->verified_identity_hash   = $verified_identity_hash;
		$this->connection_status        = $connection_status;
		$this->sync_lease_owner         = $sync_lease_owner;
		$this->sync_lease_expires_at    = $sync_lease_expires_at;
		$this->credential_verified_at   = $credential_verified_at;
		$this->credential_rotated_at    = $credential_rotated_at;
		$this->last_sync_started_at     = $last_sync_started_at;
		$this->last_sync_completed_at   = $last_sync_completed_at;
		$this->last_error               = $last_error;
		$this->enabled                  = $enabled;
		$this->created_at               = $created_at;
		$this->updated_at               = $updated_at;
	}
}
