=== PressQueue ===
Contributors: vincentsch
Tags: social publishing, editorial workflow
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0-dev
License: MIT

Conditional social publishing control plane for WordPress.

== Description ==

PressQueue is in private development. The current package provides guarded
bootstrap, local storage, safety settings, rule evaluation, role-protected
editor controls, message previews, reusable template snapshots, encrypted
Buffer credential storage, fakeable Buffer channel discovery, immutable local
delivery records, an Action Scheduler delivery outbox, Buffer worker
submission with text, scheduling, and image payload support, bounded retries,
and a redacted operator delivery log with local retry, cancel, resume, and
reconciliation controls.

New connections, channels, and rules start disabled, and the kill switch can
hold live-mode delivery rows locally. Default validation uses HTTP fakes and no
live Buffer canary has been run. Bounded manual bulk and selected repost
batches are local handoff workflows only.

The package includes a fail-closed runtime latch, privacy settings,
administrator full-site export, WordPress personal data exporter and eraser
callbacks, disabled-by-default retention, and preserve-by-default uninstall
cleanup. Provider credentials are never exported. Rendered social text and
media URLs are excluded from exports unless an administrator gives exact
confirmation.

OAuth, standing evergreen automation, public release packaging, and live social
publishing proof are not part of this development package.

== Remote Services ==

PressQueue can connect to Buffer, a third-party social publishing service,
only after an administrator adds a Buffer personal API key and enables the
local connection, channel, and rule paths. PressQueue contacts Buffer to verify
credentials, discover account, organization, and channel metadata, and submit
or schedule approved social posts.

Data sent to Buffer may include the API key in the Authorization header,
provider account and channel identifiers, rendered social text, schedule
facts, media URLs or media references needed for the provider request, and
request metadata required by Buffer. PressQueue does not contact Buffer during
default local validation, local previews, package smoke, or tests.

Review Buffer's legal terms and privacy information before connecting:
https://buffer.com/legal

== Changelog ==

= 0.1.0-dev =

* Add guarded bootstrap, local quality gates, storage, safety settings, rule
  evaluation, message previews, encrypted Buffer connection handling, channel
  discovery, editor controls, reusable templates, scheduling, image media,
  immutable local delivery records, background delivery, bounded retries,
  manual batch handoff, safe operator delivery controls, privacy export,
  retention controls, and preserve-by-default uninstall policy.
