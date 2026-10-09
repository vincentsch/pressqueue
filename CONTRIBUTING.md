# Contributing to PressQueue

PressQueue is a WordPress plugin for conditional social publishing. Buffer is
the first provider. Provider adapters handle remote requests; the shared code
owns rules, exclusions, credentials, queue state, and retries.

## Local development

Use PHP 8.1 or newer and Composer 2. The test tools need `bcmath`, `curl`, `dom`,
`intl`, `mbstring`, `mysqli`, `sodium`, and `zip`. Install dependencies with
`composer install`, then run the checks described in [README.md](README.md).
Editor tests use Node.js 20 or newer and need no npm dependencies.

Integration tests require a disposable `pressqueue_tests` database. They create
and drop plugin tables. Package smoke requires a disposable WordPress site and
installs and removes the plugin there. The Docker matrix creates its own sites.

## Changes

Explain the problem and the behavior your change produces. Include a regression
test for a bug that could affect delivery, permissions, or stored data. Use
WordPress Coding Standards and run the relevant checks before submitting.

New connections, channels, and rules must start disabled. Exclusions and policy
errors must stop delivery. Keep each delivery's identity stable across retries.
An uncertain provider response must not trigger an automatic retry.

Use HTTP fakes for provider tests. Never include real API keys, private site
settings, database exports, or provider transcripts in code, issues, or test
fixtures. Do not copy proprietary plugin code or assets.

Keep documentation factual and use everyday words. Describe what a user needs
to do and what happens. Avoid internal project names and implementation history
in setup instructions.

See the references for [provider extensions](docs/developer/provider-extensions.md),
[WP-CLI](docs/developer/wp-cli.md), and [observation hooks](docs/developer/observation-hooks.md).
For security reports, read [SECURITY.md](SECURITY.md).
