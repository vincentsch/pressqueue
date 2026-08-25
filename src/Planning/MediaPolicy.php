<?php
/**
 * Resolved media policy.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

/**
 * Immutable resolved media policy facts.
 */
final class MediaPolicy {
	/**
	 * Media mode.
	 *
	 * @var string
	 */
	public string $mode;

	/**
	 * Ordered attachment IDs.
	 *
	 * @var array<int,int>
	 */
	public array $attachment_ids;

	/**
	 * Constructor.
	 *
	 * @param string         $mode Media mode.
	 * @param array<int,int> $attachment_ids Ordered attachment IDs.
	 */
	public function __construct( string $mode, array $attachment_ids = array() ) {
		$this->mode           = $mode;
		$this->attachment_ids = array_values( $attachment_ids );
	}
}
