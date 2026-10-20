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

## Fonts for social images

The fonts in `assets/social/fonts` are used to draw social images. Each one is
licensed under the SIL Open Font License 1.1, and its license, with the
original copyright notice, sits next to it as `OFL-<name>.txt`. PressQueue
ships trimmed static copies: one weight cut from the variable font, with
unused characters removed.

- Inter, copyright 2016 The Inter Project Authors.
- Space Grotesk, copyright 2020 The Space Grotesk Project Authors.
- Bricolage Grotesque, copyright 2022 The Bricolage Grotesque Project Authors.
- JetBrains Mono, copyright 2020 The JetBrains Mono Project Authors.
- "Elegant Serif" (`ElegantSerif-Bold.ttf`) is a modified version of
  Playfair Display, copyright 2017 The Playfair Display Project Authors.
  Playfair Display is a Reserved Font Name, so the modified copy uses
  another name.
- "Sturdy Serif" (`SturdySerif-ExtraBold.ttf`) is a modified version of
  Merriweather, copyright 2024 The Merriweather Project Authors. Merriweather is a
  Reserved Font Name, so the modified copy uses another name.

## Background images for social images

The images in `assets/social/backgrounds` were made for PressQueue with an AI
image generator and contain no third-party artwork. They are distributed with
PressQueue under the same license as the rest of the plugin.

## License of the installable package

PressQueue's own code is MIT licensed. Because the installable ZIP bundles
Action Scheduler, the ZIP as a whole is distributed under the terms of
GPL-3.0-or-later. The source repository itself does not include Action
Scheduler and is MIT licensed.
