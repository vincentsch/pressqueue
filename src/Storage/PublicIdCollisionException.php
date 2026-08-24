<?php
/**
 * Public ID collision failure.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Raised after repeated public ID collisions.
 */
final class PublicIdCollisionException extends StorageException {}
