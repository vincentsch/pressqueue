<?php
/**
 * Provider registry contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Provider\DiscoveryProvider;
use PressQueue\Provider\ProviderDescriptor;
use PressQueue\Provider\ProviderRegistry;
use PressQueue\Provider\ProviderRegistryFactory;
use PressQueue\Storage\ValidationException;
use PressQueue\Tests\Fixtures\Provider\FixtureDeliveryProvider;
use PressQueue\Tests\Fixtures\Provider\FixtureDiscoveryProvider;
use RuntimeException;

use function add_filter;
use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Provider\ProviderDescriptor
 * @covers \PressQueue\Provider\ProviderRegistry
 * @covers \PressQueue\Provider\ProviderRegistryFactory
 */
final class ProviderRegistryContractsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
		ProviderRegistryFactory::reset_for_tests();
	}

	protected function tearDown(): void {
		ProviderRegistryFactory::reset_for_tests();

		parent::tearDown();
	}

	public function test_registry_factory_keeps_buffer_reserved_and_accepts_extension_descriptors(): void {
		add_filter(
			'pressqueue_provider_adapters',
			static function (): array {
				return array(
					new ProviderDescriptor(
						'buffer',
						array( 'api_key' ),
						static function (): FixtureDiscoveryProvider {
							return new FixtureDiscoveryProvider();
						},
						null
					),
					new ProviderDescriptor(
						'fixture_provider',
						array( 'api_key' ),
						static function (): FixtureDiscoveryProvider {
							return new FixtureDiscoveryProvider();
						},
						static function (): FixtureDeliveryProvider {
							return new FixtureDeliveryProvider();
						}
					),
				);
			}
		);

		$summary = ProviderRegistryFactory::registry()->summary();

		self::assertSame( 'Buffer', $summary['buffer']['label'] );
		self::assertSame( array( 'personal_api_key' ), $summary['buffer']['authentication_modes'] );
		self::assertSame( 'Fixture Provider', $summary['fixture_provider']['label'] );
		self::assertTrue( $summary['fixture_provider']['supports_discovery'] );
		self::assertTrue( $summary['fixture_provider']['supports_delivery'] );
	}

	public function test_descriptor_constructor_exposes_exact_adapter_contract(): void {
		$constructor = ( new \ReflectionClass( ProviderDescriptor::class ) )->getConstructor();

		self::assertNotNull( $constructor );
		self::assertSame(
			array(
				'provider_key',
				'authentication_modes',
				'discovery_factory',
				'delivery_factory',
			),
			array_map(
				static function ( \ReflectionParameter $parameter ): string {
					return $parameter->getName();
				},
				$constructor->getParameters()
			)
		);
		self::assertFalse( ( new \ReflectionClass( ProviderDescriptor::class ) )->hasProperty( 'label' ) );
	}

	public function test_registry_factory_ignores_broken_provider_filter_without_leaking_details(): void {
		add_filter(
			'pressqueue_provider_adapters',
			static function (): array {
				throw new RuntimeException( 'token should not be exposed' );
			}
		);

		$summary = ProviderRegistryFactory::registry()->summary();

		self::assertSame( array( 'buffer' ), array_keys( $summary ) );
		self::assertSame( 'Buffer', $summary['buffer']['label'] );
	}

	public function test_descriptor_factories_are_lazy_and_cached_by_registry(): void {
		$builds     = 0;
		$descriptor = new ProviderDescriptor(
			'fixture_lazy',
			array( 'api_key' ),
			static function () use ( &$builds ): FixtureDiscoveryProvider {
				++$builds;
				return new FixtureDiscoveryProvider();
			},
			null
		);
		$registry = new ProviderRegistry( array( $descriptor ) );

		self::assertSame( 0, $builds );
		self::assertSame( 'Fixture Lazy', $registry->summary()['fixture_lazy']['label'] );
		self::assertSame( 0, $builds );

		$first = $registry->discovery_provider( 'fixture_lazy' );
		self::assertInstanceOf( DiscoveryProvider::class, $first );
		self::assertSame( $first, $registry->discovery_provider( 'fixture_lazy' ) );
		self::assertSame( 1, $builds );
	}

	public function test_failed_provider_factory_is_redacted_and_marked_unavailable(): void {
		$descriptor = new ProviderDescriptor(
			'fixture_broken',
			array( 'api_key' ),
			static function (): FixtureDiscoveryProvider {
				throw new RuntimeException( 'secret-token-value' );
			},
			null
		);
		$registry = new ProviderRegistry( array( $descriptor ) );

		try {
			$registry->discovery_provider( 'fixture_broken' );
			self::fail( 'Broken provider factories must not surface to callers.' );
		} catch ( ValidationException $exception ) {
			self::assertSame( 'Provider adapter is unavailable.', $exception->getMessage() );
			self::assertStringNotContainsString( 'secret-token-value', $exception->getMessage() );
		}

		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'Provider adapter is unavailable.' );
		$registry->discovery_provider( 'fixture_broken' );
	}

	public function test_missing_capability_does_not_mark_descriptor_unavailable(): void {
		$descriptor = new ProviderDescriptor(
			'fixture_delivery_only',
			array( 'api_key' ),
			null,
			static function (): FixtureDeliveryProvider {
				return new FixtureDeliveryProvider();
			}
		);
		$registry   = new ProviderRegistry( array( $descriptor ) );

		try {
			$registry->discovery_provider( 'fixture_delivery_only' );
			self::fail( 'Delivery-only providers must not resolve discovery adapters.' );
		} catch ( ValidationException $exception ) {
			self::assertSame( 'Provider is not registered for discovery.', $exception->getMessage() );
		}

		self::assertInstanceOf( FixtureDeliveryProvider::class, $registry->delivery_provider( 'fixture_delivery_only' ) );
	}
}
