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
bootstrap, local storage, safety settings, rule evaluation, message previews,
encrypted Buffer credential storage, fakeable Buffer channel discovery,
immutable local delivery records, an Action Scheduler delivery outbox,
text-only Buffer worker submission, bounded retries, and a redacted operator
delivery log with local retry, cancel, resume, and reconciliation controls.

New connections, channels, and rules start disabled, and the kill switch can
hold live-mode delivery rows locally. Default validation uses HTTP fakes and no
live Buffer canary has been run. OAuth, media delivery, bulk publishing,
retention, export, compaction, uninstall deletion, and public release packaging
are not part of this development package.

== Changelog ==

= 0.1.0-dev =

* Add guarded bootstrap, local quality gates, storage, safety settings, rule
  evaluation, message previews, encrypted Buffer connection handling, channel
  discovery, immutable local delivery records, background delivery, bounded
  retries, and safe operator delivery controls.
