# Observation hooks

PressQueue fires hooks that let code on the same site watch what it records.
They are best effort. Subscribers run after the outermost database commit has
succeeded. An exception thrown by a subscriber is caught and redacted, and it
does not undo the committed operation.

## Provider registration

`pressqueue_provider_adapters` is a filter that receives an array of
`ProviderDescriptor` instances and returns an array of descriptors.

The built-in `buffer` descriptor is inserted before the filter runs and
cannot be replaced by the filter.

## Decision recorded

`pressqueue_decision_recorded` fires only for a newly inserted decision, after
the transaction commits. It does not fire when deduplication returns an
existing decision row.

Payload keys:

- `schema_version`
- `decision_public_id`
- `decision_key`
- `event_key`
- `post_id`
- `scope_type`
- `scope_public_id`
- `trigger`
- `event_mode`
- `result`
- `rule_public_id`
- `rule_version`
- `snapshot_hash`
- `created_at_utc`

## Delivery event recorded

`pressqueue_delivery_event_recorded` fires once per newly committed delivery
event, after the transaction commits. It does not fire on rollback.

Payload keys:

- `schema_version`
- `event_public_id`
- `delivery_public_id`
- `delivery_key`
- `event_type`
- `from_state`
- `to_state`
- `attempt_number`
- `action_id`
- `actor_type`
- `provider_result`
- `retry_safety`
- `classification`
- `created_at_utc`

Payloads carry identities, classifications, states, hashes and timestamps.
They do not carry snapshot bodies, event detail bodies, rendered content,
media URLs, credentials, encrypted credential envelopes, Authorization
headers, raw HTTP request arguments, raw provider responses, full provider
payloads or actor user IDs.
