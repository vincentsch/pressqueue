<?php
/**
 * Provider registry.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider;

use PressQueue\Provider\Buffer\BufferDiscoveryProvider;
use PressQueue\Provider\Buffer\BufferDeliveryProvider;
use PressQueue\Provider\Buffer\BufferGraphqlClient;
use PressQueue\Storage\ValidationException;

/**
 * Resolves provider adapters behind a narrow boundary.
 */
final class ProviderRegistry {
	/**
	 * Registered discovery providers.
	 *
	 * @var array<string,DiscoveryProvider>
	 */
	private array $discovery_providers;

	/**
	 * Registered delivery providers.
	 *
	 * @var array<string,DeliveryProvider>
	 */
	private array $delivery_providers;

	/**
	 * Constructor.
	 *
	 * @param array<string,DiscoveryProvider> $discovery_providers Discovery providers.
	 * @param array<string,DeliveryProvider>  $delivery_providers Delivery providers.
	 */
	public function __construct( array $discovery_providers, array $delivery_providers = array() ) {
		$this->discovery_providers = $discovery_providers;
		$this->delivery_providers  = $delivery_providers;
	}

	/**
	 * Create the default registry.
	 *
	 * @return self
	 */
	public static function defaults(): self {
		return new self(
			array(
				'buffer' => new BufferDiscoveryProvider( new BufferGraphqlClient() ),
			),
			array(
				'buffer' => new BufferDeliveryProvider(),
			)
		);
	}

	/**
	 * Resolve a discovery provider.
	 *
	 * @param string $provider Provider key.
	 * @return DiscoveryProvider
	 */
	public function discovery_provider( string $provider ): DiscoveryProvider {
		if ( ! isset( $this->discovery_providers[ $provider ] ) ) {
			throw new ValidationException( 'Provider is not registered for discovery.' );
		}

		return $this->discovery_providers[ $provider ];
	}

	/**
	 * Resolve a delivery provider.
	 *
	 * @param string $provider Provider key.
	 * @return DeliveryProvider
	 */
	public function delivery_provider( string $provider ): DeliveryProvider {
		if ( ! isset( $this->delivery_providers[ $provider ] ) ) {
			throw new ValidationException( 'Provider is not registered for delivery.' );
		}

		return $this->delivery_providers[ $provider ];
	}
}
