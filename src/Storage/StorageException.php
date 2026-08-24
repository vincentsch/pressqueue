<?php
/**
 * Generic storage failure.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

use RuntimeException;

/**
 * Raised when persistence cannot complete safely.
 */
class StorageException extends RuntimeException {}
