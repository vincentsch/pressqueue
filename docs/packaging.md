# Building and checking a package

The installable ZIP contains the plugin, its production Composer
dependencies and their licenses. It leaves out tests, tools, CI files and
anything in `.gitignore`.

## Everyday checks

```bash
composer install
composer run test:unit
node --test tests/editor/*.test.cjs
composer run lint:phpcs
composer run analyse
composer run audit:dependencies
composer run smoke:runtime
```

## Package checks

These need a throwaway WordPress site. They install, activate, deactivate and
uninstall PressQueue there, so never point them at a real site.

```bash
PACKAGE_SMOKE_WP_PATH=/path/to/wordpress composer run package:smoke
WP_PATH=/path/to/wordpress PLUGIN_CHECK_TARGET=/tmp/pressqueue-package-smoke/pressqueue.zip composer run plugin-check
```

## Compatibility matrix

The matrix runs every PHP and WordPress version PressQueue supports in
Docker containers, each with its own database and site.

```bash
composer run matrix:local -- --dry-run
composer run matrix:local
```

Use `PRESSQUEUE_MATRIX_PHP` and `PRESSQUEUE_MATRIX_WORDPRESS` to pick
versions. Results go to `build/qa/` unless you set another path. That folder
is ignored by Git and left out of the ZIP.
