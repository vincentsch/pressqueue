<?php
/**
 * Optimistic concurrency failure.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Raised when an update uses an old lock version.
 */
final class StaleRecordException extends StorageException {}
