# Provider extensions

Provider adapters are registered through the `pressqueue_provider_adapters`
filter. PressQueue builds the registry the first time a request needs it and
keeps it for the rest of that request. A provider registered after that point
shows up on the next request. The label shown for a provider is derived from
its provider key.

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

At least one factory is required. An invalid descriptor, a duplicate extension
key, or an attempt to replace a reserved built-in key is ignored for that
provider. The built-in `buffer` provider is registered outside the filter and
cannot be replaced.

If a factory throws, only that provider becomes unavailable for the current
request. PressQueue reports a redacted adapter-unavailable error and does not
expose the thrown message.

## Discovery and delivery

Discovery adapters may read account, organization and channel metadata. They
must not create social posts on the provider's side.

Delivery adapters receive `ProviderDeliveryRequest` objects created by the
shared planner and worker gates. They return one normalized result:

- `accepted`
- `retryable_failure`
- `rate_limited`
- `permanent_failure`
- `ambiguous`

Public fields on the provider DTOs are immutable `readonly` properties.

## Connection service

PressQueue has no generic credential screen for extension providers. A
companion plugin collects the credentials and provides its own admin screens.

Use `PressQueue\Provider\ProviderConnectionService` to create and synchronize
local connection state:

- `create_disabled_connection( $provider_key, $label, $credential, $authentication_mode )`
- `fetch_account( $connection, $credential )`
- `verify_account( $connection, $credential )`
- `sync_channels( $connection, $credential, $account, $organization_id, $preserve_enabled )`

New connections and synced channels stay disabled until an operator switches
them on. Changing the selected organization sets the connection back to
unverified and disables the channels that belong to it.

## Conformance tests

Reusable PHPUnit conformance support lives under `tests/Support/`. Companion
providers can extend `ProviderConformanceTestCase` in their own test suites to
check DTO construction, immutable adapter boundaries, discovery success and
failure classification, and normalized delivery results.
