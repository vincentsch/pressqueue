<?php
/**
 * Fixture provider conformance tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PressQueue\Provider\ProviderDescriptor;
use PressQueue\Tests\Fixtures\Provider\FixtureDeliveryProvider;
use PressQueue\Tests\Fixtures\Provider\FixtureDiscoveryProvider;
use PressQueue\Tests\Support\ProviderConformanceTestCase;

/**
 * @covers \PressQueue\Provider\ProviderDescriptor
 * @covers \PressQueue\Provider\Dto\ProviderAccount
 * @covers \PressQueue\Provider\Dto\ProviderChannel
 * @covers \PressQueue\Provider\Dto\ProviderDeliveryRequest
 * @covers \PressQueue\Provider\Dto\ProviderDeliveryResult
 * @covers \PressQueue\Provider\Dto\ProviderOrganization
 * @covers \PressQueue\Provider\Dto\ProviderReceipt
 * @covers \PressQueue\Tests\Fixtures\Provider\FixtureDiscoveryProvider
 * @covers \PressQueue\Tests\Fixtures\Provider\FixtureDeliveryProvider
 */
final class FixtureProviderConformanceTest extends ProviderConformanceTestCase {
	/**
	 * Return the fixture descriptor.
	 *
	 * @return ProviderDescriptor
	 */
	protected function provider_descriptor(): ProviderDescriptor {
		return new ProviderDescriptor(
			'fixture',
			array( 'fixture_key' ),
			static function (): FixtureDiscoveryProvider {
				return new FixtureDiscoveryProvider();
			},
			static function (): FixtureDeliveryProvider {
				return new FixtureDeliveryProvider();
			}
		);
	}
}
