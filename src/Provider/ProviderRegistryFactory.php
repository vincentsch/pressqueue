<?php
/**
 * Default provider registry factory.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider;

use PressQueue\Provider\Buffer\BufferDeliveryProvider;
use PressQueue\Provider\Buffer\BufferDiscoveryProvider;
use PressQueue\Provider\Buffer\BufferGraphqlClient;
use Throwable;

/**
 * Builds the production provider registry.
 */
final class ProviderRegistryFactory {
	/**
	 * Cached registry for this request.
	 *
	 * @var ProviderRegistry|null
	 */
	private static ?ProviderRegistry $registry = null;

	/**
	 * Create the default registry.
	 *
	 * @return ProviderRegistry
	 */
	public static function registry(): ProviderRegistry {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		self::$registry = new ProviderRegistry( self::descriptors() );

		return self::$registry;
	}

	/**
	 * Reset the cached registry for tests.
	 */
	public static function reset_for_tests(): void {
		self::$registry = null;
	}

	/**
	 * Build validated provider descriptors.
	 *
	 * @return array<int,ProviderDescriptor>
	 */
	private static function descriptors(): array {
		$descriptors = array(
			self::buffer_descriptor(),
		);

		try {
			$extensions = function_exists( 'apply_filters' )
				? apply_filters( 'pressqueue_provider_adapters', array() )
				: array();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$extensions = array();
		}

		if ( ! is_array( $extensions ) ) {
			return $descriptors;
		}

		$seen = array( 'buffer' => true );
		foreach ( $extensions as $descriptor ) {
			if ( ! $descriptor instanceof ProviderDescriptor ) {
				continue;
			}

			if ( isset( $seen[ $descriptor->provider_key ] ) ) {
				continue;
			}

			$seen[ $descriptor->provider_key ] = true;
			$descriptors[]                     = $descriptor;
		}

		return $descriptors;
	}

	/**
	 * Return the built-in Buffer descriptor.
	 *
	 * @return ProviderDescriptor
	 */
	private static function buffer_descriptor(): ProviderDescriptor {
		return new ProviderDescriptor(
			'buffer',
			array( 'personal_api_key' ),
			static function (): DiscoveryProvider {
				return new BufferDiscoveryProvider( new BufferGraphqlClient() );
			},
			static function (): DeliveryProvider {
				return new BufferDeliveryProvider();
			}
		);
	}
}
