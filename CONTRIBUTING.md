# Contributing to PressQueue

PressQueue is a WordPress plugin that shares posts to social networks through
Buffer, following rules the site owner sets. Buffer is the first provider.
Provider adapters handle requests to the outside service. The shared code owns
rules, "never share" rules, credentials, queue state and retries. Shared code
must not assume that Buffer is the only provider; provider-specific behavior
stays behind the adapter interfaces in `src/Provider/`.

## Code layout

- `src/Planning/` decides which posts qualify and renders messages.
- `src/Delivery/` and `src/Scheduler/` queue and send deliveries in the background.
- `src/Storage/` owns the database tables, records and validation.
- `src/Admin/` renders the WordPress admin screens. `Ui`, `Labels` and
  `assets/admin/admin.css` hold the shared building blocks.
- `assets/editor/` is the block editor sharing panel.
- `src/WpCli/` holds the `wp pressqueue` commands.
- `tests/unit/`, `tests/integration/` and `tests/editor/` hold the tests.

## Local development

You need PHP 8.1 or newer and Composer 2. The test tools need the PHP
extensions `bcmath`, `curl`, `dom`, `intl`, `mbstring`, `mysqli`, `sodium`
and `zip`. Run `composer install`, then the checks listed in
[README.md](README.md). The editor tests need Node.js 20 or newer and no npm
packages.

The integration tests need a throwaway `pressqueue_tests` database. They
create and drop the plugin's tables. The package smoke test needs a throwaway
WordPress site, where it installs and removes the plugin. The Docker matrix
creates its own sites.

## Making a change

Explain the problem and what your change does about it. Add a regression test
for any bug that could affect sending, permissions or stored data. Follow the
WordPress Coding Standards and run the relevant checks before you submit.

These rules hold in every change:

- New connections, channels and rules start switched off.
- Exclusions and policy errors stop delivery.
- Each delivery keeps the same identity across retries.
- An uncertain reply from the provider is never retried automatically.
- Stored data formats, option names, meta keys and REST routes stay backward
  compatible; existing sites upgrade in place.
- Do not copy third-party plugin code, branding, copy or assets.

Use HTTP fakes for provider tests. Never put live API keys, private site
settings, database exports or provider transcripts in code, issues or test
fixtures.

Write documentation in everyday words and keep it factual. Say what the user
needs to do and what happens. Keep internal project names and implementation
history out of setup instructions.

The developer docs cover [provider extensions](docs/developer/provider-extensions.md),
[WP-CLI](docs/developer/wp-cli.md) and [observation hooks](docs/developer/observation-hooks.md).
To report a security issue, read [SECURITY.md](SECURITY.md).
