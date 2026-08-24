<?php
/**
 * Channel storage record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

use DateTimeImmutable;

/**
 * Represents one local provider channel.
 */
final class ChannelRecord {
	public int $id;
	public string $public_id;
	public int $connection_id;
	public string $connection_public_id;
	public string $provider;
	public string $remote_channel_id;
	public string $service;
	/** @var array<string,mixed> */
	public array $display;
	/** @var array<string,mixed> */
	public array $capabilities;
	public bool $enabled;
	public ?DateTimeImmutable $last_synced_at;
	public DateTimeImmutable $created_at;
	public DateTimeImmutable $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int                    $id Internal ID.
	 * @param string                 $public_id Public ID.
	 * @param int                    $connection_id Connection internal ID.
	 * @param string                 $connection_public_id Connection public ID.
	 * @param string                 $provider Provider key.
	 * @param string                 $remote_channel_id Remote channel ID.
	 * @param string                 $service Service key.
	 * @param array<string,mixed>    $display Display metadata.
	 * @param array<string,mixed>    $capabilities Capability metadata.
	 * @param bool                   $enabled Whether the channel is enabled.
	 * @param DateTimeImmutable|null $last_synced_at Last sync timestamp.
	 * @param DateTimeImmutable      $created_at Creation timestamp.
	 * @param DateTimeImmutable      $updated_at Update timestamp.
	 */
	public function __construct(
		int $id,
		string $public_id,
		int $connection_id,
		string $connection_public_id,
		string $provider,
		string $remote_channel_id,
		string $service,
		array $display,
		array $capabilities,
		bool $enabled,
		?DateTimeImmutable $last_synced_at,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at
	) {
		$this->id                   = $id;
		$this->public_id            = $public_id;
		$this->connection_id        = $connection_id;
		$this->connection_public_id = $connection_public_id;
		$this->provider             = $provider;
		$this->remote_channel_id    = $remote_channel_id;
		$this->service              = $service;
		$this->display              = $display;
		$this->capabilities         = $capabilities;
		$this->enabled              = $enabled;
		$this->last_synced_at       = $last_synced_at;
		$this->created_at           = $created_at;
		$this->updated_at           = $updated_at;
	}
}
