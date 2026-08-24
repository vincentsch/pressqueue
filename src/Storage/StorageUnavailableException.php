<?php
/**
 * Storage readiness failure.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Raised when runtime planning must fail closed.
 */
final class StorageUnavailableException extends StorageException {}
