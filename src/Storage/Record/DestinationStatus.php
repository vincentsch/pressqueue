<?php
/**
 * Destination availability record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

/**
 * Exposes channel and connection existence for planning.
 */
final class DestinationStatus {
	public bool $channel_exists;
	public bool $channel_enabled;
	public bool $connection_exists;
	public bool $connection_enabled;
	public ?string $provider;
	public ?string $connection_public_id;
	public ?string $channel_public_id;
	public ?string $remote_channel_id;

	/**
	 * Constructor.
	 *
	 * @param bool        $channel_exists Whether the channel row exists.
	 * @param bool        $channel_enabled Whether the channel is enabled.
	 * @param bool        $connection_exists Whether the connection row exists.
	 * @param bool        $connection_enabled Whether the connection is enabled.
	 * @param string|null $provider Provider key.
	 * @param string|null $connection_public_id Connection public ID.
	 * @param string|null $channel_public_id Channel public ID.
	 * @param string|null $remote_channel_id Remote channel ID.
	 */
	public function __construct(
		bool $channel_exists,
		bool $channel_enabled,
		bool $connection_exists,
		bool $connection_enabled,
		?string $provider,
		?string $connection_public_id,
		?string $channel_public_id,
		?string $remote_channel_id
	) {
		$this->channel_exists       = $channel_exists;
		$this->channel_enabled      = $channel_enabled;
		$this->connection_exists    = $connection_exists;
		$this->connection_enabled   = $connection_enabled;
		$this->provider             = $provider;
		$this->connection_public_id = $connection_public_id;
		$this->channel_public_id    = $channel_public_id;
		$this->remote_channel_id    = $remote_channel_id;
	}
}
