<?php
/**
 * Fixture delivery provider.
 *
 * @package PressQueue\Tests
 */

declare(strict_types=1);

namespace PressQueue\Tests\Fixtures\Provider;

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Provider\DeliveryProvider;
use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Provider\Dto\ProviderDeliveryResult;
use PressQueue\Provider\Dto\ProviderReceipt;

/**
 * Deterministic delivery adapter for conformance tests.
 */
final class FixtureDeliveryProvider implements DeliveryProvider {
	/**
	 * Submitted delivery requests.
	 *
	 * @var array<int,ProviderDeliveryRequest>
	 */
	public array $requests = array();

	/**
	 * Submit a fixture delivery.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @param string                  $credential Credential.
	 * @return ProviderDeliveryResult
	 */
	public function submit( ProviderDeliveryRequest $request, string $credential ): ProviderDeliveryResult {
		$this->requests[] = $request;

		switch ( $credential ) {
			case 'secret invalid delivery credential':
				return ProviderDeliveryResult::failure( $request, 'permanent_failure', 'not_retryable', 'invalid_credential', array( 'schema_version' => 1 ), null, true, true );

			case 'retryable':
				return ProviderDeliveryResult::failure( $request, 'retryable_failure', 'automatic_allowed', 'fixture_retryable', array( 'schema_version' => 1 ), null, true, true );

			case 'rate_limited':
				return ProviderDeliveryResult::failure( $request, 'rate_limited', 'automatic_allowed', 'fixture_rate_limited', array( 'schema_version' => 1 ), new DateTimeImmutable( '2026-08-25 12:05:00', new DateTimeZone( 'UTC' ) ), true, true );

			case 'permanent':
				return ProviderDeliveryResult::failure( $request, 'permanent_failure', 'not_retryable', 'fixture_permanent', array( 'schema_version' => 1 ), null, true, true );

			case 'ambiguous':
				return ProviderDeliveryResult::failure( $request, 'ambiguous', 'reconcile_first', 'fixture_connection_lost', array( 'schema_version' => 1 ), null, true, true );

			default:
				return ProviderDeliveryResult::accepted(
					$request,
					'remote_fixture_post',
					new ProviderReceipt(
						array(
							'provider'       => 'fixture',
							'remote_id_hash' => hash( 'sha256', 'remote_fixture_post' ),
							'schema_version' => 1,
						)
					)
				);
		}
	}
}
