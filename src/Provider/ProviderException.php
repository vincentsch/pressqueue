<?php
/**
 * Provider adapter failure.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider;

use RuntimeException;

/**
 * Raised with a redacted provider failure classification.
 */
final class ProviderException extends RuntimeException {
	/**
	 * Normalized provider failure class.
	 *
	 * @var string
	 */
	private string $classification;

	/**
	 * Constructor.
	 *
	 * @param string $classification Failure class.
	 * @param string $message Redacted message.
	 */
	public function __construct( string $classification, string $message ) {
		$this->classification = $classification;

		parent::__construct( $message );
	}

	/**
	 * Return the normalized discovery failure class.
	 *
	 * @return string
	 */
	public function classification(): string {
		return $this->classification;
	}

	/**
	 * Return a redacted storage-safe document.
	 *
	 * @return array<string,mixed>
	 */
	public function to_redacted_document(): array {
		return array(
			'classification' => $this->classification,
			'message'        => $this->getMessage(),
			'schema_version' => 1,
		);
	}
}
