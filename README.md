# PressQueue

PressQueue is an open-source WordPress plugin for conditionally publishing and
scheduling WordPress content through social publishing providers.

Buffer is the first provider. The intended product combines:

- account and channel selection;
- reusable status templates;
- conditions based on post type, taxonomy, author, and post metadata;
- per-post inclusion, exclusion, and scheduling controls;
- media handling;
- idempotent background delivery;
- retryable failures and an auditable delivery log;
- bulk publishing, evergreen reposting, and WP-CLI controls.

## Status

PressQueue has an installable guarded development build with local storage,
capability boundaries, dry-run and kill-switch settings, JSON-based rules,
transport-neutral message previews, immutable decision records, and local
delivery records.

The current build also includes encrypted Buffer personal API-key storage,
fakeable Buffer organization and channel discovery, disabled-by-default channel
enablement, an Action Scheduler backed delivery outbox, immutable text-only
Buffer submission, bounded retry handling, a redacted delivery log, local
cancel and resume controls, manual retry gates, and ambiguity reconciliation.

Default validation uses HTTP fakes and local test doubles. No live Buffer
canary, public release package, WordPress.org publication, OAuth, media
delivery, bulk publishing, evergreen reposting, retention, export, compaction,
or uninstall deletion workflow is part of the current build.

The project will begin privately and be made public when its first release is
tested, documented, license-clean, and useful.

## Product principles

- Publishing must be explicit and observable.
- Exclusion rules fail closed.
- A WordPress save or REST API call must never create duplicate social posts.
- Preview and dry-run behavior come before live delivery.
- Provider credentials stay server-side and are never logged.
- The plugin remains useful without a hosted service.
- Provider-specific behavior stays behind a small adapter boundary.

## Clean-room boundary

PressQueue is an independent implementation inspired by the general workflow
of WordPress social publishing tools. It is not affiliated with Buffer, WP
Zinc, or WordPress.org. No third-party proprietary code, branding, or protected
assets may be copied into this project.

## License

MIT. See [LICENSE](LICENSE).

## Development

Run the default local gate with:

```bash
composer install
composer run ci
```

Prerequisites:

- PHP 8.1 or newer with `bcmath`, `curl`, `dom`, `intl`, `json`, `mbstring`,
  `mysqli`, `sodium`, and `zip`.
- Composer 2.
- MySQL or MariaDB for WordPress integration tests.
- The WordPress core test suite. Install it with
  `bash tools/install-wp-tests.sh pressqueue_tests root '' 127.0.0.1 6.8.8`,
  then set `WP_TESTS_DIR` if you install it somewhere other than `/tmp`.
- A disposable WordPress install for `composer run package:smoke`. Set
  `PACKAGE_SMOKE_WP_PATH` or `WP_PATH` to that WordPress path.
- Plugin Check 2.1.0 for `composer run plugin-check`. Set `WP_PATH` to the
  disposable WordPress path and `PLUGIN_CHECK_PATH` when `plugin-check/cli.php`
  is not under `wp-content/plugins/plugin-check/`.
  Run `composer run package:smoke` first so the private package is installed as
  the `pressqueue` plugin slug.

The package smoke artifact is private local evidence and is not a release
package.
