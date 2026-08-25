<?php
/**
 * WP-CLI exit classes.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

/**
 * Stable command exit codes.
 */
final class ExitCodes {
	public const SUCCESS      = 0;
	public const INVALID      = 2;
	public const UNAUTHORIZED = 3;
	public const REFUSED      = 4;
	public const INTERNAL     = 5;
}
