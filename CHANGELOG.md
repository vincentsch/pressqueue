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
- No provider connection flow, credential storage, background delivery worker,
  public release, live provider request, or live social publishing was added.
