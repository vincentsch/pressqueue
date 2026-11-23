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

## Publishing a release

Work in Git as usual. WordPress.org SVN holds published plugin versions; it is
not a second development repository.

When a version is ready:

1. Update the plugin version and `readme.txt` Stable tag together. Commit the
   changes and wait for the full CI workflow to pass on that commit.
2. Build the installable ZIP with `composer run package:smoke`, then run
   Plugin Check on that exact ZIP. Keep its checksum and test results.
3. Tag the tested commit as `vX.Y.Z`. Create a draft GitHub release with
   `pressqueue.zip` and `pressqueue.zip.sha256` attached. The checksum file
   contains one line: the SHA-256, two spaces, then `pressqueue.zip`.
4. Publish the GitHub release. The **Publish to WordPress.org** workflow checks
   the tag and successful CI run. It verifies the ZIP checksum and compares its
   source files with the tagged commit, then publishes the package to SVN
   `trunk` and `tags/X.Y.Z`. Directory images come from `.wordpress-org/`.
5. Check the workflow result and WordPress.org's listing and download. Directory
   processing may take time after the SVN commit.

The workflow uses the `SVN_PASSWORD` secret in the `wordpress.org` GitHub
Actions environment. This is WordPress.org's separate SVN password. Never put
it in Git or use the account login password. Publishing a GitHub release is
the release action; ordinary commits and tag pushes do not publish to SVN.

If a run stops after attempting its SVN commit, check the remote tag before
retrying. Published code in SVN version tags is never overwritten. The workflow also
supports a manual run for an existing stable GitHub release. Old releases made
before this workflow was added use the local publisher instead.

For a local preview, run `python3 tools/publish-wordpress.py --help`. Without
`--commit`, it stages the ZIP in a new checkout and prints a receipt. Publishing
requires `--commit` and `SVN_PASSWORD` in the process environment. Keep the ZIP
and its recorded hash together; don't rebuild an already tested release.

For a listing-only correction, update `readme.txt` in Git first. WordPress.org
reads the description from the current stable tag, so copy that readme to both
SVN `trunk/readme.txt` and `tags/X.Y.Z/readme.txt` in one commit. Keep the plugin
version and Stable tag unchanged. Check that only those two readme files changed;
never replace published code or recreate the tag. This regenerates the directory
ZIP, so record its new checksum and verify that all other files are unchanged.
The original GitHub release ZIP stays as published. A new code release still
uses the workflow above and a new version number.
