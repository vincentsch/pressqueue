# PressQueue

PressQueue sends WordPress posts to Buffer using rules you set. You can choose
which posts qualify, preview the message, and save it as a Buffer draft or
send it to a social channel. The delivery log records what happened.

This repository is private. The local release candidate is `0.1.0`.

## What it does

- Match posts by type, author, category, tag, or allowed custom fields.
- Reuse message templates and set different messages for different services.
- Exclude a post, restrict its channels, or change its message in the editor.
- Save Buffer drafts, publish immediately, use the Buffer queue, or set a time.
- Preview and confirm batches of existing posts or selected reposts.

Deliveries run in the background through bundled Action Scheduler. Repeated
WordPress hooks reuse the same local delivery identity. If a response is
uncertain, PressQueue stops and asks an operator to check Buffer before retrying.
An accepted delivery means Buffer accepted the request. It does not prove that
the social network has published it.

## Requirements

WordPress 6.8 or newer, PHP 8.1 or newer, and MySQL 5.7.8 or MariaDB 10.2.3 or newer, with InnoDB support.
Buffer connections need a personal Buffer API key and PHP's Sodium extension.
Image URLs must be public HTTPS URLs. OAuth, videos, network activation, and
automatic recurring reposts are not supported.

The Buffer adapter supports Bluesky, Facebook, LinkedIn, Mastodon, Threads,
and X. It uses conservative limits of 280 characters including the post URL,
one image, 5 MB per image, and 4096 pixels per side. A lower Mastodon server
limit takes precedence. Other channels stay unavailable for delivery.

## Installation and setup

A packaged plugin includes its dependencies. Upload the ZIP through
**Plugins > Add New > Upload Plugin** and activate it. When working from source,
run `composer install` before activation.

New connections, channels, and rules start disabled. Dry run starts enabled.
See [Getting started](docs/getting-started.md) for setup instructions, a first
rule, draft delivery, and exclusions.

The block editor has a separate **Save Controls** button in its PressQueue
panel. Save those controls before publishing the WordPress post. The Classic
Editor saves the controls with the post.

Publishing and updating are blocked while the panel has unsaved control changes.
If a control save fails, fix the reported problem and save again.

## Data and privacy

Provider credentials are encrypted using your WordPress keys and salts. If
those keys change, enter the Buffer credential again. Store backups securely.

Deactivation stops PressQueue work and preserves its data. Uninstall also
preserves data unless you enable deletion on the Privacy screen. Exports omit
credentials and hide message text and media URLs by default. See
[Privacy and data](docs/privacy.md).

## Development

```bash
composer install
composer run test:unit
node --test tests/editor/*.test.cjs
composer run lint:phpcs
composer run analyse
composer run audit:dependencies
composer run smoke:runtime
```

Integration tests need the WordPress test suite and a disposable database named
`pressqueue_tests`. The tests create and drop tables. Never use a production
database.

```bash
bash tools/install-wp-tests.sh pressqueue_tests root '' 127.0.0.1 7.1.3
WP_TESTS_DIR=/tmp/wordpress-tests-lib-7.1.3 composer run test:integration
```

The full gate, `composer run ci`, also needs a disposable WordPress installation.
Set `WP_PATH` and `PACKAGE_SMOKE_WP_PATH` to its directory. The Plugin Check
tool installs its pinned version there if it is missing. Package smoke installs,
activates, deactivates, and uninstalls PressQueue on that site.
Set `PLUGIN_CHECK_TARGET=/tmp/pressqueue-package-smoke/pressqueue.zip` to check
the package; the checker reinstalls it on the disposable site without activating it.

`composer run matrix:local` runs the PHP 8.1 through 8.4 matrix against
WordPress 6.8.11 and 7.1.3 in disposable Docker containers. Use `-- --dry-run`
to inspect its commands. See [Contributing](CONTRIBUTING.md) and
[local package checks](docs/private-release.md).

Developer references cover [WP-CLI](docs/developer/wp-cli.md),
[observation hooks](docs/developer/observation-hooks.md), and
[provider adapters](docs/developer/provider-extensions.md).

## License and release

PressQueue source uses the [MIT license](LICENSE). Bundled Action Scheduler
uses GPL-3.0-or-later. Its license is included in the package. See
[third-party notices](THIRD_PARTY_NOTICES.md) and the
[release checklist](docs/public-release-checklist.md).

PressQueue is an independent implementation. It is not affiliated with Buffer
or other social publishing plugins. See [clean-room notes](docs/clean-room.md).
