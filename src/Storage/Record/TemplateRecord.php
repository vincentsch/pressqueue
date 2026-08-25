<?php
/**
 * Template storage record.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage\Record;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

use DateTimeImmutable;

/**
 * Represents one reusable template snapshot source.
 */
final class TemplateRecord {
	public int $id;
	public string $public_id;
	public string $name;
	/** @var array<string,mixed> */
	public array $template_set;
	public bool $enabled;
	public int $version;
	public int $lock_version;
	public DateTimeImmutable $created_at;
	public DateTimeImmutable $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int                 $id Internal ID.
	 * @param string              $public_id Public ID.
	 * @param string              $name Display name.
	 * @param array<string,mixed> $template_set Template set document.
	 * @param bool                $enabled Whether selectable.
	 * @param int                 $version Template version.
	 * @param int                 $lock_version Optimistic lock version.
	 * @param DateTimeImmutable   $created_at Creation timestamp.
	 * @param DateTimeImmutable   $updated_at Update timestamp.
	 */
	public function __construct(
		int $id,
		string $public_id,
		string $name,
		array $template_set,
		bool $enabled,
		int $version,
		int $lock_version,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at
	) {
		$this->id           = $id;
		$this->public_id    = $public_id;
		$this->name         = $name;
		$this->template_set = $template_set;
		$this->enabled      = $enabled;
		$this->version      = $version;
		$this->lock_version = $lock_version;
		$this->created_at   = $created_at;
		$this->updated_at   = $updated_at;
	}
}
