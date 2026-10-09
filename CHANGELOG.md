# Changelog

## 0.1.0 release candidate

- Added Buffer draft delivery and optional featured images. A Buffer draft is
  a remote draft; local dry run still makes no delivery request.
- Replaced rule destination, schedule, and media JSON fields with checkboxes
  and dropdowns. Advanced conditions and template data remain editable.
- Block publishing and updating from the block editor while PressQueue controls
  have unsaved changes. Prevent duplicate saves and ignore stale API responses.
- Stop processing when database reads fail. Suppress raw SQL diagnostics that
  could expose messages or encrypted credential records in WordPress logs.
- Recheck current post policy, enabled rules, and safety switches before delivery.
  Hold uncertain Buffer results for review and preserve confirmed remote IDs.
- Fixed batch scheduling, lease recovery, scheduled publication, and duplicate
  checks. Automatic publication recognizes WordPress imports and skips backdated archives.
- Fixed text normalization and typed custom fields. Display-only changes do not
  create update deliveries. Explicit times need at least 15 minutes of lead time.
- Keep accepted delivery identities after retention removes message details.
  Repair database drift on administrator visits without slowing public page loads.
- Remove delegated role capabilities on uninstall and, when deletion is enabled,
  remove post controls and runtime fingerprints across all sites.
- Updated compatibility checks for WordPress 6.8.11 and 7.1.3 and PHP 8.1 to 8.4.
  Fixed local matrix networking, PHP 8.0 checks, and package Plugin Check handling.
- Added setup, contribution, and security reporting guides; clarified privacy,
  remote requests, draft behavior, and delivery receipts.

## 2026-08-25

### Editorial controls and preview planning

- Added role-protected post controls for inherited policy, explicit include or
  exclude policy, and restrictive channel overrides.
- Added canonical post-control metadata, authenticated control routes, classic
  editor fallback controls, and nondurable dirty-editor previews.
- Split runtime trigger fingerprints from delivery revision fingerprints so
  control-only saves do not create update events while delivery identities keep
  the final control bundle.
- Added a visual condition builder for the existing rule condition JSON
  contract while keeping the raw JSON value canonical.
- Centralized behavior-change rule disablement in the rule repository.

### Templates, scheduling, and image media

- Added reusable template records, immutable rule template snapshots,
  service-specific messages, template provenance, and explicit template
  adoption.
- Added canonical message, schedule, and image media sections to post controls.
- Added provider-neutral schedule resolution for immediate, next queue slot,
  end of queue, and explicit local times with daylight-saving and horizon
  validation.
- Added image media planning, safe public URL probe contracts, media identity
  snapshots, and provider capability validation before delivery rows are
  created.
- Added payload schema version 2 and Buffer encoder support for schedules and
  ordered image assets.
- Added version-aware delivery compatibility so legacy payloads remain
  displayable but fail closed before queueing, resume, retry, or worker
  submission.

### Manual batch workflows

- Added normalized batch and batch item storage for bounded bulk and selected
  repost previews.
- Added batch operation capability, batch administration screens, exact
  confirmation, preview expiry, duplicate checks, occurrence identity, and
  durable review evidence.
- Added local batch queue handoff through Action Scheduler using the shared
  planner and existing delivery queue.
- Added idempotent batch recovery and local cancellation of unqueued work.
- Hardened batch queue handoff so cancellation cannot leave a cancelled item
  with an unlinked committed delivery.
- Reused reviewed batch schedule overrides during runtime handoff and rejected
  past staggered schedule starts.
- Kept standing evergreen automation, live provider validation, remote post
  mutation, release packaging, retention, export, and uninstall deletion out
  of scope.

### Privacy, security, and private package

- Added a fail-closed runtime latch that gates capture, durable planning,
  batch handoff, queue sweep, retry, resume, worker entry, and the final
  pre-provider HTTP boundary.
- Added capability schema version 5 with `export_pressqueue_data`, while
  keeping `access_pressqueue_admin` virtual.
- Added privacy settings, administrator full-site JSON export, WordPress
  personal data exporter and eraser callbacks, disabled-by-default retention,
  and preserve-by-default uninstall cleanup.
- Redacted credential envelopes, rendered social text, media URLs, provider
  diagnostics, and raw exception text from export and operator flows.
- Added package notices, a translation catalog, pinned external CI action
  references, checksum-pinned WP-CLI tooling, private release docs, and a
  Docker-based local compatibility matrix runner.

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

### Release preparation

- Recheck current post visibility, rules, exclusions, and global delivery controls before sending.
- Fix batch action scheduling, expired lease recovery, and MariaDB schema validation.
- Keep accepted delivery identities during retention while clearing copied content.
- Stop automatic capture during WordPress imports and reject password-protected posts.
- Support featured images in background work and keep image probes outside batch transactions.
- Prevent unsaved editor controls and overlapping saves from changing publication policy.
- Handle incomplete Buffer responses conservatively and preserve returned remote IDs for review.
- Use plain setup documentation, channel checkboxes, and packaged translation strings.
