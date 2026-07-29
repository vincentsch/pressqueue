# PressQueue

PressQueue is a planned open-source WordPress plugin for conditionally
publishing and scheduling WordPress content through social publishing
providers.

The first provider will be Buffer. The intended product combines:

- account and channel selection;
- reusable status templates;
- conditions based on post type, taxonomy, author, and post metadata;
- per-post inclusion, exclusion, and scheduling controls;
- media handling;
- idempotent background delivery;
- retryable failures and an auditable delivery log;
- bulk publishing, evergreen reposting, and WP-CLI controls.

## Status

PressQueue is in planning. This repository does not contain an installable
WordPress plugin yet.

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
