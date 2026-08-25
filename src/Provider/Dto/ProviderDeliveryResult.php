<?php
/**
 * Provider delivery result DTO.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Dto;

use DateTimeImmutable;
use PressQueue\Storage\StorageEnums;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover immutable DTO fields.

/**
 * Normalized provider delivery result.
 */
final class ProviderDeliveryResult {
	public readonly string $provider;
	public readonly string $connection_public_id;
	public readonly string $provider_result;
	public readonly string $retry_safety;
	public readonly ?string $remote_id;
	public readonly ?ProviderReceipt $receipt;
	public readonly ?DateTimeImmutable $retry_after_utc;
	public readonly ?string $error_class;
	/** @var array<string,mixed>|null */
	public readonly ?array $error_detail;
	public readonly bool $request_started;
	public readonly bool $request_may_have_reached_provider;
	/** @var array<int,string> */
	public readonly array $provider_warnings;
	/** @var array<string,mixed> */
	public readonly array $redacted_detail;

	/**
	 * Constructor.
	 *
	 * @param string                   $provider Provider key.
	 * @param string                   $connection_public_id Connection public ID.
	 * @param string                   $provider_result Provider result.
	 * @param string                   $retry_safety Retry safety.
	 * @param string|null              $remote_id Remote ID.
	 * @param ProviderReceipt|null     $receipt Sanitized receipt.
	 * @param DateTimeImmutable|null   $retry_after_utc Retry-after timestamp.
	 * @param string|null              $error_class Error class.
	 * @param array<string,mixed>|null $error_detail Redacted error detail.
	 * @param bool                     $request_started Whether request work started.
	 * @param bool                     $request_may_have_reached_provider Whether Buffer may have received the request.
	 * @param array<int,string>        $provider_warnings Redacted provider warnings.
	 * @param array<string,mixed>      $redacted_detail Redacted event detail.
	 */
	public function __construct(
		string $provider,
		string $connection_public_id,
		string $provider_result,
		string $retry_safety,
		?string $remote_id,
		?ProviderReceipt $receipt,
		?DateTimeImmutable $retry_after_utc,
		?string $error_class,
		?array $error_detail,
		bool $request_started,
		bool $request_may_have_reached_provider,
		array $provider_warnings = array(),
		array $redacted_detail = array()
	) {
		StorageEnums::assert_provider_result( $provider_result );
		StorageEnums::assert_retry_safety( $retry_safety );

		$this->provider                          = $provider;
		$this->connection_public_id              = $connection_public_id;
		$this->provider_result                   = $provider_result;
		$this->retry_safety                      = $retry_safety;
		$this->remote_id                         = $remote_id;
		$this->receipt                           = $receipt;
		$this->retry_after_utc                   = $retry_after_utc;
		$this->error_class                       = $error_class;
		$this->error_detail                      = $error_detail;
		$this->request_started                   = $request_started;
		$this->request_may_have_reached_provider = $request_may_have_reached_provider;
		$this->provider_warnings                 = $provider_warnings;
		$this->redacted_detail                   = $redacted_detail;
	}

	/**
	 * Build an accepted result.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @param string                  $remote_id Remote ID.
	 * @param ProviderReceipt         $receipt Sanitized receipt.
	 * @param array<int,string>       $warnings Redacted warnings.
	 * @return self
	 */
	public static function accepted( ProviderDeliveryRequest $request, string $remote_id, ProviderReceipt $receipt, array $warnings = array() ): self {
		return new self(
			$request->provider,
			$request->bound_connection_public_id,
			'accepted',
			'not_retryable',
			$remote_id,
			$receipt,
			null,
			null,
			null,
			true,
			true,
			$warnings,
			array(
				'classification' => 'accepted',
				'remote_id_hash' => hash( 'sha256', $remote_id ),
				'warnings'       => $warnings,
			)
		);
	}

	/**
	 * Build a failure or ambiguous result.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @param string                  $provider_result Provider result.
	 * @param string                  $retry_safety Retry safety.
	 * @param string                  $error_class Error class.
	 * @param array<string,mixed>     $error_detail Redacted detail.
	 * @param DateTimeImmutable|null  $retry_after_utc Retry-after timestamp.
	 * @param bool                    $request_started Request started.
	 * @param bool                    $may_have_reached_provider May have reached provider.
	 * @return self
	 */
	public static function failure(
		ProviderDeliveryRequest $request,
		string $provider_result,
		string $retry_safety,
		string $error_class,
		array $error_detail,
		?DateTimeImmutable $retry_after_utc,
		bool $request_started,
		bool $may_have_reached_provider
	): self {
		return new self(
			$request->provider,
			$request->bound_connection_public_id,
			$provider_result,
			$retry_safety,
			null,
			null,
			$retry_after_utc,
			$error_class,
			$error_detail,
			$request_started,
			$may_have_reached_provider,
			array(),
			array(
				'classification'                    => $error_class,
				'provider_result'                   => $provider_result,
				'request_may_have_reached_provider' => $may_have_reached_provider,
			)
		);
	}
}
