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
	public bool $enabled;
	public DateTimeImmutable $created_at;
	public DateTimeImmutable $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int                 $id Internal ID.
	 * @param string              $public_id Public ID.
	 * @param string              $provider Provider key.
	 * @param string              $label Display label.
	 * @param array<string,mixed> $account Account metadata.
	 * @param bool                $enabled Whether the connection is enabled.
	 * @param DateTimeImmutable   $created_at Creation timestamp.
	 * @param DateTimeImmutable   $updated_at Update timestamp.
	 */
	public function __construct(
		int $id,
		string $public_id,
		string $provider,
		string $label,
		array $account,
		bool $enabled,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at
	) {
		$this->id         = $id;
		$this->public_id  = $public_id;
		$this->provider   = $provider;
		$this->label      = $label;
		$this->account    = $account;
		$this->enabled    = $enabled;
		$this->created_at = $created_at;
		$this->updated_at = $updated_at;
	}
}
