# Third-party notices

PressQueue source code uses the MIT license in `LICENSE`.
Bundled dependencies keep their own licenses and notices.

The installable ZIP includes:

- WooCommerce Action Scheduler (`woocommerce/action-scheduler`), GPL-3.0-or-later.
  Its license is at `vendor/woocommerce/action-scheduler/license.txt`.
- The Cron Expression library bundled inside Action Scheduler, MIT.
  Its license is at `vendor/woocommerce/action-scheduler/lib/cron-expression/LICENSE`.
- The generated Composer autoloader, MIT. Composer's license is at
  `vendor/composer/LICENSE`.

The ZIP includes each notice. Development tools listed in `require-dev` are
excluded from the installable package.

## License of the installable package

PressQueue's own code is MIT licensed. Because the installable ZIP bundles
Action Scheduler, the ZIP as a whole is distributed under the terms of
GPL-3.0-or-later. The source repository itself does not include Action
Scheduler and is MIT licensed.
