<?php
/**
 * Provider delivery receipt DTO.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Dto;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Schema;

/**
 * Stores a sanitized provider receipt.
 */
final class ProviderReceipt {
	/**
	 * Receipt document.
	 *
	 * @var array<string,mixed>
	 */
	private array $document;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $document Sanitized receipt document.
	 */
	public function __construct( array $document ) {
		$this->document = $document;
	}

	/**
	 * Return the receipt document.
	 *
	 * @return array<string,mixed>
	 */
	public function document(): array {
		return $this->document;
	}

	/**
	 * Return canonical receipt JSON.
	 *
	 * @return string
	 */
	public function json(): string {
		$json = CanonicalJson::encode_object( $this->document );
		CanonicalJson::assert_maximum_size( $json, Schema::RECEIPT_JSON_MAX_BYTES );

		return $json;
	}
}
