# Provider Extension Contract

Provider adapters are registered through the `pressqueue_provider_adapters`
filter. PressQueue builds the registry lazily once per request. Late
registration after the registry is frozen takes effect on the next request.
Provider summaries derive a display label from the provider key.

## Descriptor

Register a `PressQueue\Provider\ProviderDescriptor`:

```php
add_filter(
	'pressqueue_provider_adapters',
	static function ( array $providers ): array {
		$providers[] = new ProviderDescriptor(
			'example_provider',
			array( 'api_key' ),
			static fn(): DiscoveryProvider => new ExampleDiscoveryProvider(),
			static fn(): DeliveryProvider => new ExampleDeliveryProvider()
		);

		return $providers;
	}
);
```

The descriptor fields are:

- `provider_key`: lowercase ASCII identifier matching
  `[a-z][a-z0-9_]{0,63}`
- `authentication_modes`: non-empty list of supported authentication modes
- `discovery_factory`: nullable no-argument callable returning
  `DiscoveryProvider`
- `delivery_factory`: nullable no-argument callable returning
  `DeliveryProvider`

At least one factory is required. Invalid descriptors, duplicate extension
keys, and attempts to replace a reserved built-in key are ignored for that
provider. The built-in `buffer` provider is registered outside the filter and
cannot be replaced.

Factory failures mark only that provider unavailable for the current request.
PressQueue reports a redacted adapter-unavailable error and does not expose
the thrown message.

## Discovery And Delivery

Discovery adapters may read account, organization, and channel metadata. They
must not create provider-side social posts.

Delivery adapters receive `ProviderDeliveryRequest` objects created by the
shared planner and worker gates. They return exactly one normalized result:

- `accepted`
- `retryable_failure`
- `rate_limited`
- `permanent_failure`
- `ambiguous`

Provider DTO public fields are immutable `readonly` properties.

## Connection Service

PressQueue does not provide a generic credential UI for extension providers.
Companion plugins own credential acquisition and provider-specific admin UI.

Use `PressQueue\Provider\ProviderConnectionService` to create and synchronize
local connection state:

- `create_disabled_connection( $provider_key, $label, $credential, $authentication_mode )`
- `fetch_account( $connection, $credential )`
- `verify_account( $connection, $credential )`
- `sync_channels( $connection, $credential, $account, $organization_id, $preserve_enabled )`

New connections and synced channels remain disabled until an operator enables
them explicitly. Changing the selected organization demotes the connection to
unverified and disables related destinations.

## Conformance Tests

Reusable PHPUnit conformance support lives under `tests/Support/`. Companion
providers can extend `ProviderConformanceTestCase` in their own test suites to
verify DTO construction, immutable adapter boundaries, discovery success and
failure classification, and normalized delivery results.
