<?php
/**
 * Provider registry.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider;

use PressQueue\Provider\Buffer\BufferDiscoveryProvider;
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
	 * Constructor.
	 *
	 * @param array<string,DiscoveryProvider> $discovery_providers Discovery providers.
	 */
	public function __construct( array $discovery_providers ) {
		$this->discovery_providers = $discovery_providers;
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
}
