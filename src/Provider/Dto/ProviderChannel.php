<?php
/**
 * Provider channel DTO.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Dto;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

/**
 * Represents one remote destination discovered from a provider.
 */
final class ProviderChannel {
	public readonly string $provider;
	public readonly string $remote_organization_id;
	public readonly string $remote_channel_id;
	public readonly string $name;
	public readonly string $display_label;
	public readonly string $service;
	public readonly ?string $external_link;
	/** @var array<string,mixed> */
	public readonly array $raw_status_flags;
	/** @var array<int,string> */
	public readonly array $allowed_actions;
	public readonly string $availability;
	/** @var array<string,mixed> */
	public readonly array $capability_document;
	public readonly ?string $unavailable_reason;

	/**
	 * Constructor.
	 *
	 * @param string              $provider Provider key.
	 * @param string              $remote_organization_id Remote organization ID.
	 * @param string              $remote_channel_id Remote channel ID.
	 * @param string              $name Provider name.
	 * @param string              $display_label Display label.
	 * @param string              $service Service key.
	 * @param string|null         $external_link External link.
	 * @param array<string,mixed> $raw_status_flags Raw status flags.
	 * @param array<int,string>   $allowed_actions Allowed actions.
	 * @param string              $availability Availability.
	 * @param array<string,mixed> $capability_document Capability document.
	 * @param string|null         $unavailable_reason Unavailable reason.
	 */
	public function __construct(
		string $provider,
		string $remote_organization_id,
		string $remote_channel_id,
		string $name,
		string $display_label,
		string $service,
		?string $external_link,
		array $raw_status_flags,
		array $allowed_actions,
		string $availability,
		array $capability_document,
		?string $unavailable_reason
	) {
		$this->provider               = $provider;
		$this->remote_organization_id = $remote_organization_id;
		$this->remote_channel_id      = $remote_channel_id;
		$this->name                   = $name;
		$this->display_label          = $display_label;
		$this->service                = $service;
		$this->external_link          = $external_link;
		$this->raw_status_flags       = $raw_status_flags;
		$this->allowed_actions        = array_values( $allowed_actions );
		$this->availability           = $availability;
		$this->capability_document    = $capability_document;
		$this->unavailable_reason     = $unavailable_reason;
	}

	/**
	 * Return the channel display document.
	 *
	 * @return array<string,mixed>
	 */
	public function display_document(): array {
		return array(
			'display_name'           => $this->display_label,
			'external_link'          => $this->external_link,
			'name'                   => $this->name,
			'raw_status_flags'       => $this->raw_status_flags,
			'remote_channel_id'      => $this->remote_channel_id,
			'remote_organization_id' => $this->remote_organization_id,
			'schema_version'         => 1,
			'service'                => $this->service,
		);
	}
}
