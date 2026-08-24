# Changelog

## 2026-08-24

### Bootstrap and local quality gate

- Added the guarded `pressqueue` plugin skeleton with PHP, WordPress, and
  network-activation runtime checks.
- Added Composer metadata, PSR-4 autoloading, PHPUnit, PHPCS, PHPStan, Plugin
  Check, dependency audit, runtime smoke, package smoke, and CI configuration.
- Added unit and WordPress integration tests proving lifecycle hooks exist and
  bootstrap makes zero external HTTP requests.
- Added private package smoke tooling that builds a `pressqueue/` rooted zip
  with production Composer autoloading.
- Tightened the bootstrap harness after review so PHP 8.1 dependency installs,
  Plugin Check parsing, HTTP guarding, and network-activation refusal are all
  enforced by tests.
- Moved local smoke-tool caches to neutral temporary directories so CI and
  package tooling stay independent of private planning paths.

### Local policy, storage, and preview control plane

- Added versioned local tables for provider connection metadata, destination
  channels, rules, decisions, and delivery previews.
- Added non-autoloaded dry-run and kill-switch settings, with dry run enabled
  by default and all new connections, channels, and rules disabled by default.
- Added administrator capabilities for settings, rules, and decision viewing.
- Added typed repositories, canonical JSON validation, public IDs, stable event
  keys, decision keys, delivery keys, snapshot hashes, and payload hashes.
- Added deterministic rule evaluation for include and exclude rules, nested
  conditions, taxonomy ancestors, allowlisted metadata, explicit post policy,
  and policy errors that fail closed.
- Added inline template rendering for transport-neutral previews with
  allowlisted tags, safe plain-text normalization, and Unicode-safe truncation.
- Added local planning that writes immutable decision and delivery-preview
  records in dry-run, held, or proposed states without provider requests.
- Added a shared event collector for WordPress publication paths and basic
  server-rendered administration for settings, rules, previews, and recent
  decisions.

### Buffer connection, delivery, and operator controls

- Added encrypted Buffer personal API-key storage with strict credential
  envelopes, redacted failure handling, and disabled-by-default connection
  lifecycle management.
- Added provider-neutral discovery and delivery interfaces with Buffer GraphQL
  account, organization, channel, and text-post adapters behind fakeable HTTP
  transports.
- Added organization-scoped channel synchronization, availability
  normalization, text-only immediate delivery capability documents, explicit
  channel enablement, and fail-closed planning gates for unavailable or
  incompatible destinations.
- Added schema version 3 delivery projection fields, immutable destination
  binding, remote receipt uniqueness, transaction-backed state transitions,
  and an append-only delivery event ledger.
- Added Action Scheduler loading, recurring queue sweeps, worker actions,
  local leases, kill-switch and destination preflight gates, immutable
  payload submission, sanitized receipts, provider result classification, and
  bounded automatic retries.
- Tightened worker credential cleanup so resolved provider credentials are
  erased after delivery preflight and submission paths.
- Added redacted delivery list and detail screens, local cancellation and
  resume controls, manual retry gates, duplicate receipt protection, and
  ambiguity reconciliation that never mutates remote posts.
- No OAuth, media delivery, bulk publishing, evergreen reposting, retention,
  export, compaction, uninstall deletion, public release, live provider
  validation, or live social publishing proof was added.
