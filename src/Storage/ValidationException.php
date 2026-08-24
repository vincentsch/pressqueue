<?php
/**
 * Storage validation failure.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use InvalidArgumentException;

/**
 * Raised when a value cannot be stored safely.
 */
final class ValidationException extends InvalidArgumentException {}
