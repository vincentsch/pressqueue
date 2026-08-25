<?php
/**
 * Provider conformance base tests.
 *
 * @package PressQueue\Tests
 */

declare(strict_types=1);

namespace PressQueue\Tests\Support;

use PHPUnit\Framework\TestCase;
use PressQueue\Provider\DeliveryProvider;
use PressQueue\Provider\DiscoveryProvider;
use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Provider\Dto\ProviderReceipt;
use PressQueue\Provider\ProviderDescriptor;
use PressQueue\Provider\ProviderException;

/**
 * Reusable adapter conformance checks for fixture and companion providers.
 */
abstract class ProviderConformanceTestCase extends TestCase {
	/**
	 * Return a descriptor for the provider under test.
	 *
	 * @return ProviderDescriptor
	 */
	abstract protected function provider_descriptor(): ProviderDescriptor;

	/**
	 * Return a valid opaque credential.
	 *
	 * @return string
	 */
	protected function valid_credential(): string {
		return 'fixture';
	}

	/**
	 * Return an invalid opaque credential.
	 *
	 * @return string
	 */
	protected function invalid_credential(): string {
		return 'invalid';
	}

	/**
	 * Return a malformed-response trigger credential.
	 *
	 * @return string
	 */
	protected function malformed_credential(): string {
		return 'malformed';
	}

	/**
	 * Return a delivery credential that should classify as invalid.
	 *
	 * @return string
	 */
	protected function invalid_delivery_credential(): string {
		return 'secret invalid delivery credential';
	}

	/**
	 * Return a delivery credential used only for redaction assertions.
	 *
	 * @return string
	 */
	protected function redaction_probe_credential(): string {
		return 'secret accepted delivery credential';
	}

	public function test_descriptor_is_valid_and_lazy_factories_return_adapters(): void {
		$descriptor = $this->provider_descriptor();

		self::assertNotSame( array(), $descriptor->authentication_modes );
		self::assertTrue( $descriptor->supports_discovery() || $descriptor->supports_delivery() );

		if ( $descriptor->supports_discovery() ) {
			self::assertInstanceOf( DiscoveryProvider::class, $descriptor->discovery_provider() );
		}

		if ( $descriptor->supports_delivery() ) {
			self::assertInstanceOf( DeliveryProvider::class, $descriptor->delivery_provider() );
		}
	}

	public function test_discovery_returns_storage_safe_account_and_channels(): void {
		$provider = $this->provider_descriptor()->discovery_provider();
		$account  = $provider->account( $this->valid_credential() );

		self::assertNotSame( '', $account->remote_account_id );
		self::assertTrue( $account->has_organization( $account->organizations[0]->remote_organization_id ) );

		$channels = $provider->channels( $this->valid_credential(), $account->organizations[0]->remote_organization_id );
		self::assertNotSame( array(), $channels );
		self::assertSame( $account->organizations[0]->remote_organization_id, $channels[0]->remote_organization_id );
		self::assertSame( 2, $channels[0]->capability_document['schema_version'] );
	}

	public function test_discovery_failures_are_classified_without_raw_credentials(): void {
		$provider = $this->provider_descriptor()->discovery_provider();

		try {
			$provider->account( $this->invalid_credential() );
			self::fail( 'Invalid credentials must be classified.' );
		} catch ( ProviderException $exception ) {
			self::assertSame( 'invalid_credential', $exception->classification() );
			self::assertStringNotContainsString( $this->invalid_credential(), $exception->getMessage() );
		}

		try {
			$provider->account( $this->malformed_credential() );
			self::fail( 'Malformed responses must be classified.' );
		} catch ( ProviderException $exception ) {
			self::assertSame( 'malformed_response', $exception->classification() );
			self::assertStringNotContainsString( $this->malformed_credential(), $exception->getMessage() );
		}
	}

	public function test_delivery_result_categories_are_normalized(): void {
		$provider = $this->provider_descriptor()->delivery_provider();
		$request  = $this->request();

		$accepted  = $provider->submit( $request, $this->valid_credential() );
		$retryable = $provider->submit( $request, 'retryable' );
		$limited   = $provider->submit( $request, 'rate_limited' );
		$permanent = $provider->submit( $request, 'permanent' );
		$ambiguous = $provider->submit( $request, 'ambiguous' );

		self::assertSame( 'accepted', $accepted->provider_result );
		self::assertNotNull( $accepted->receipt );
		self::assertSame( 'retryable_failure', $retryable->provider_result );
		self::assertSame( 'automatic_allowed', $retryable->retry_safety );
		self::assertSame( 'rate_limited', $limited->provider_result );
		self::assertSame( 'automatic_allowed', $limited->retry_safety );
		self::assertNotNull( $limited->retry_after_utc );
		self::assertSame( 'permanent_failure', $permanent->provider_result );
		self::assertSame( 'ambiguous', $ambiguous->provider_result );
		self::assertSame( 'reconcile_first', $ambiguous->retry_safety );
	}

	public function test_delivery_credential_failure_is_classified_without_raw_credentials(): void {
		$provider = $this->provider_descriptor()->delivery_provider();
		$result   = $provider->submit( $this->request(), $this->invalid_delivery_credential() );
		$json     = json_encode( $result, JSON_THROW_ON_ERROR );

		self::assertSame( 'permanent_failure', $result->provider_result );
		self::assertSame( 'invalid_credential', $result->error_class );
		self::assertIsString( $json );
		self::assertStringNotContainsString( $this->invalid_delivery_credential(), $json );
	}

	public function test_delivery_receipts_and_diagnostics_do_not_contain_credentials(): void {
		$provider = $this->provider_descriptor()->delivery_provider();
		$accepted = $provider->submit( $this->request(), $this->redaction_probe_credential() );
		$failed   = $provider->submit( $this->request(), $this->invalid_delivery_credential() );
		$payload  = json_encode( array( $accepted, $failed ), JSON_THROW_ON_ERROR );

		self::assertIsString( $payload );
		self::assertStringNotContainsString( $this->redaction_probe_credential(), $payload );
		self::assertStringNotContainsString( $this->invalid_delivery_credential(), $payload );
	}

	public function test_dto_boundaries_are_immutable(): void {
		$request = $this->request();
		$receipt = new ProviderReceipt( array( 'schema_version' => 1 ) );

		$this->expectException( \Error::class );
		$request->message = 'changed';

		unset( $receipt );
	}

	/**
	 * Return a valid provider delivery request.
	 *
	 * @return ProviderDeliveryRequest
	 */
	protected function request(): ProviderDeliveryRequest {
		return new ProviderDeliveryRequest(
			$this->provider_descriptor()->provider_key,
			'01K3KGXB71P2MR4K9A7V6Q5C8D',
			str_repeat( 'a', 64 ),
			1,
			'01K3KGXB71P2MR4K9A7V6Q5C8E',
			'acct_fixture',
			'org_fixture',
			'chan_fixture',
			2,
			2,
			'Hello',
			'https://example.test/post'
		);
	}
}
