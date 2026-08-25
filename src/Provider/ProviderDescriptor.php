<?php
/**
 * Provider registration descriptor.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider;

use Closure;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Describes a provider adapter without constructing it eagerly.
 */
final class ProviderDescriptor {
	/**
	 * Provider key.
	 *
	 * @var string
	 */
	public readonly string $provider_key;

	/**
	 * Supported authentication modes.
	 *
	 * @var array<int,string>
	 */
	public readonly array $authentication_modes;

	/**
	 * Discovery adapter factory.
	 *
	 * @var Closure|null
	 */
	public readonly ?Closure $discovery_factory;

	/**
	 * Delivery adapter factory.
	 *
	 * @var Closure|null
	 */
	public readonly ?Closure $delivery_factory;

	/**
	 * Constructor.
	 *
	 * @param string            $provider_key Provider key.
	 * @param array<int,string> $authentication_modes Supported authentication modes.
	 * @param callable|null     $discovery_factory Discovery provider factory.
	 * @param callable|null     $delivery_factory Delivery provider factory.
	 */
	public function __construct( string $provider_key, array $authentication_modes, ?callable $discovery_factory, ?callable $delivery_factory ) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $provider_key ) ) {
			throw new ValidationException( 'Provider key is invalid.' );
		}

		if ( null === $discovery_factory && null === $delivery_factory ) {
			throw new ValidationException( 'Provider descriptor requires at least one adapter factory.' );
		}

		if ( array() === $authentication_modes || ! array_is_list( $authentication_modes ) ) {
			throw new ValidationException( 'Provider authentication modes must be a non-empty list.' );
		}

		$modes = array();
		foreach ( $authentication_modes as $mode ) {
			if ( ! is_string( $mode ) ) {
				throw new ValidationException( 'Provider authentication mode is invalid.' );
			}
			StorageValidator::assert_text_key( 'Provider authentication mode', $mode );
			$modes[] = $mode;
		}

		$modes = array_values( array_unique( $modes ) );
		sort( $modes, SORT_STRING );

		$this->provider_key         = $provider_key;
		$this->authentication_modes = $modes;
		$this->discovery_factory    = null === $discovery_factory ? null : Closure::fromCallable( $discovery_factory );
		$this->delivery_factory     = null === $delivery_factory ? null : Closure::fromCallable( $delivery_factory );
	}

	/**
	 * Check whether discovery is supported.
	 *
	 * @return bool
	 */
	public function supports_discovery(): bool {
		return null !== $this->discovery_factory;
	}

	/**
	 * Check whether delivery is supported.
	 *
	 * @return bool
	 */
	public function supports_delivery(): bool {
		return null !== $this->delivery_factory;
	}

	/**
	 * Build a discovery adapter.
	 *
	 * @return DiscoveryProvider
	 */
	public function discovery_provider(): DiscoveryProvider {
		if ( null === $this->discovery_factory ) {
			throw new ValidationException( 'Provider is not registered for discovery.' );
		}

		$provider = ( $this->discovery_factory )();
		if ( ! $provider instanceof DiscoveryProvider ) {
			throw new ValidationException( 'Provider discovery factory returned an invalid adapter.' );
		}

		return $provider;
	}

	/**
	 * Build a delivery adapter.
	 *
	 * @return DeliveryProvider
	 */
	public function delivery_provider(): DeliveryProvider {
		if ( null === $this->delivery_factory ) {
			throw new ValidationException( 'Provider is not registered for delivery.' );
		}

		$provider = ( $this->delivery_factory )();
		if ( ! $provider instanceof DeliveryProvider ) {
			throw new ValidationException( 'Provider delivery factory returned an invalid adapter.' );
		}

		return $provider;
	}

	/**
	 * Return a stable public summary.
	 *
	 * @return array<string,mixed>
	 */
	public function summary(): array {
		return array(
			'authentication_modes' => $this->authentication_modes,
			'label'                => $this->label(),
			'provider_key'         => $this->provider_key,
			'supports_delivery'    => $this->supports_delivery(),
			'supports_discovery'   => $this->supports_discovery(),
		);
	}

	/**
	 * Return a derived display label for summaries.
	 *
	 * @return string
	 */
	private function label(): string {
		return ucwords( str_replace( '_', ' ', $this->provider_key ) );
	}
}
