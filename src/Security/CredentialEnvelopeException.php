<?php
/**
 * Credential envelope failure.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Security;

use RuntimeException;

/**
 * Raised when credential material cannot be safely encrypted or decrypted.
 */
final class CredentialEnvelopeException extends RuntimeException {}
