# Local release candidate

The release candidate is a local validation artifact. Keep it private until the
owner approves publication.

## Scope

The package includes:

- guarded bootstrap and activation checks;
- local storage, rules, previews, editor controls, templates, batches, and
  delivery queueing;
- Buffer personal API-key storage and delivery adapter code behind fakeable
  transports;
- runtime latch, privacy settings, full-site export, retention, personal data
  callbacks, and uninstall policy;
- production Composer autoloading and bundled Action Scheduler dependency
  notices.

The package does not prove live provider delivery. Any live social publishing
operation requires explicit approval for that exact action.

## Local Gates

Run focused local checks with:

```bash
composer install
composer run test:unit
composer run lint:phpcs
composer run analyse
composer run audit:dependencies
composer run smoke:runtime
```

With a disposable WordPress install, run:

```bash
PACKAGE_SMOKE_WP_PATH=/path/to/wordpress composer run package:smoke
WP_PATH=/path/to/wordpress PLUGIN_CHECK_TARGET=/tmp/pressqueue-package-smoke/pressqueue.zip composer run plugin-check
```

For local compatibility coverage, run:

```bash
composer run matrix:local -- --dry-run
composer run matrix:local
```

The matrix runner uses Docker and stages disposable source copies. Override
versions with `PRESSQUEUE_MATRIX_PHP` and `PRESSQUEUE_MATRIX_WORDPRESS`.

## Evidence

Raw package smoke artifacts, browser captures, or logs belong in a scratch
directory that is not part of public source. Durable QA evidence should be a
small summary that records commands, versions, results, and limitations.

Local QA evidence is written under `build/qa/` unless you set an evidence path.
This directory is excluded from Git and from the installable ZIP.
