<?php
/**
 * Provider registry.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider;

use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Resolves provider adapters behind a narrow boundary.
 */
final class ProviderRegistry {
	/**
	 * Registered provider descriptors.
	 *
	 * @var array<string,ProviderDescriptor>
	 */
	private array $descriptors = array();

	/**
	 * Resolved discovery adapters.
	 *
	 * @var array<string,DiscoveryProvider>
	 */
	private array $discovery_providers = array();

	/**
	 * Resolved delivery adapters.
	 *
	 * @var array<string,DeliveryProvider>
	 */
	private array $delivery_providers = array();

	/**
	 * Providers with a failed factory in this request.
	 *
	 * @var array<string,bool>
	 */
	private array $unavailable_providers = array();

	/**
	 * Constructor.
	 *
	 * @param array<string|int,ProviderDescriptor|DiscoveryProvider> $providers Provider descriptors or legacy discovery providers.
	 * @param array<string,DeliveryProvider>                         $delivery_providers Legacy delivery providers.
	 */
	public function __construct( array $providers, array $delivery_providers = array() ) {
		foreach ( $providers as $key => $provider ) {
			if ( $provider instanceof ProviderDescriptor ) {
				$this->descriptors[ $provider->provider_key ] = $provider;
				continue;
			}

			if ( $provider instanceof DiscoveryProvider && is_string( $key ) ) {
				$this->discovery_providers[ $key ] = $provider;
			}
		}

		foreach ( $delivery_providers as $provider => $delivery_provider ) {
			if ( $delivery_provider instanceof DeliveryProvider ) {
				$this->delivery_providers[ $provider ] = $delivery_provider;
			}
		}
	}

	/**
	 * Create the default registry.
	 *
	 * @return self
	 */
	public static function defaults(): self {
		return ProviderRegistryFactory::registry();
	}

	/**
	 * Resolve a discovery provider.
	 *
	 * @param string $provider Provider key.
	 * @return DiscoveryProvider
	 */
	public function discovery_provider( string $provider ): DiscoveryProvider {
		if ( isset( $this->discovery_providers[ $provider ] ) ) {
			return $this->discovery_providers[ $provider ];
		}

		if ( isset( $this->unavailable_providers[ $provider ] ) ) {
			throw new ValidationException( 'Provider adapter is unavailable.' );
		}

		if ( ! isset( $this->descriptors[ $provider ] ) ) {
			throw new ValidationException( 'Provider is not registered for discovery.' );
		}

		if ( ! $this->descriptors[ $provider ]->supports_discovery() ) {
			throw new ValidationException( 'Provider is not registered for discovery.' );
		}

		try {
			$this->discovery_providers[ $provider ] = $this->descriptors[ $provider ]->discovery_provider();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$this->unavailable_providers[ $provider ] = true;
			throw new ValidationException( 'Provider adapter is unavailable.' );
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
		if ( isset( $this->delivery_providers[ $provider ] ) ) {
			return $this->delivery_providers[ $provider ];
		}

		if ( isset( $this->unavailable_providers[ $provider ] ) ) {
			throw new ValidationException( 'Provider adapter is unavailable.' );
		}

		if ( ! isset( $this->descriptors[ $provider ] ) ) {
			throw new ValidationException( 'Provider is not registered for delivery.' );
		}

		if ( ! $this->descriptors[ $provider ]->supports_delivery() ) {
			throw new ValidationException( 'Provider is not registered for delivery.' );
		}

		try {
			$this->delivery_providers[ $provider ] = $this->descriptors[ $provider ]->delivery_provider();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$this->unavailable_providers[ $provider ] = true;
			throw new ValidationException( 'Provider adapter is unavailable.' );
		}

		return $this->delivery_providers[ $provider ];
	}

	/**
	 * Return stable provider summaries.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function summary(): array {
		$summary = array();

		foreach ( $this->descriptors as $provider => $descriptor ) {
			$summary[ $provider ] = $descriptor->summary();
		}

		foreach ( array_keys( $this->discovery_providers ) as $provider ) {
			if ( ! isset( $summary[ $provider ] ) ) {
				$summary[ $provider ] = array(
					'label'              => $provider,
					'provider_key'       => $provider,
					'supports_delivery'  => isset( $this->delivery_providers[ $provider ] ),
					'supports_discovery' => true,
				);
			}
		}

		foreach ( array_keys( $this->delivery_providers ) as $provider ) {
			if ( ! isset( $summary[ $provider ] ) ) {
				$summary[ $provider ] = array(
					'label'              => $provider,
					'provider_key'       => $provider,
					'supports_delivery'  => true,
					'supports_discovery' => isset( $this->discovery_providers[ $provider ] ),
				);
			}
		}

		ksort( $summary, SORT_STRING );

		return $summary;
	}
}
