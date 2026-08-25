<?php
/**
 * Provider organization DTO.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Dto;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

/**
 * Represents one remote provider organization.
 */
final class ProviderOrganization {
	public readonly string $provider;
	public readonly string $remote_organization_id;
	public readonly string $display_label;
	public readonly int $channel_count;

	/**
	 * Constructor.
	 *
	 * @param string $provider Provider key.
	 * @param string $remote_organization_id Remote organization ID.
	 * @param string $display_label Display label.
	 * @param int    $channel_count Remote channel count.
	 */
	public function __construct( string $provider, string $remote_organization_id, string $display_label, int $channel_count ) {
		$this->provider               = $provider;
		$this->remote_organization_id = $remote_organization_id;
		$this->display_label          = $display_label;
		$this->channel_count          = $channel_count;
	}

	/**
	 * Return a storage-safe document.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'channel_count'          => $this->channel_count,
			'display_label'          => $this->display_label,
			'provider'               => $this->provider,
			'remote_organization_id' => $this->remote_organization_id,
		);
	}
}
