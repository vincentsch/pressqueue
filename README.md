# PressQueue

PressQueue shares your WordPress posts on LinkedIn, X, Bluesky, Mastodon,
Threads and Facebook through your Buffer account. You decide which posts go
where and what the message says. When you publish a post, PressQueue checks
it against your rules. If a rule matches, it hands the post to Buffer in the
background.

Nothing is sent until you say so. PressQueue starts in test mode and shows
what it would share. Every share leaves a record.

![The PressQueue overview in the WordPress admin](docs/images/overview.png)

## How it works

1. **Connect Buffer.** Create an API key in Buffer and paste it into
   PressQueue. Your Buffer channels show up. Switch on the ones you want to
   use.
2. **Make a rule.** A rule says which posts to share, where to send them,
   what the message says and when it goes out. "Share every new post to all
   my channels" is one click.
3. **Test it.** PressQueue starts in test mode. It records what it would
   share and sends nothing, so you can check the results first.
4. **Go live.** From then on, publishing a matching post adds it to your
   Buffer queue, posts it right away or saves it as a Buffer draft, whichever
   the rule says.

## What you get

- **Rules in plain words.** Pick posts by type, category, tag, author or
  custom field. Write the message with placeholders like the title or
  excerpt, and see a live preview with a character count.
- **"Never share" rules.** Keep a category, tag or author off social media
  for good. These rules win over every sharing rule. If one cannot be
  checked, the post is not shared.
- **A switch in the editor.** Each post has a "Share this post" switch and a
  short summary of where it will go. You can change the message, channels or
  timing for one post. You can also make sharing opt-in, so new posts start
  switched off.
- **Share old posts.** Pick older posts, review the list and share them
  spread across your Buffer queue. PressQueue will not share the same post
  to the same channel twice by accident.
- **An activity log.** See what was sent, what is waiting and what needs a
  look. When Buffer's answer is unclear, PressQueue stops and asks you to
  check Buffer instead of risking a double post.
- **Test mode, Live and Paused.** One switch for the whole plugin. Pausing
  holds everything that is waiting until you resume.

PressQueue has no hosted service, account or tracking. It talks to Buffer
from your own server.

## Requirements

- WordPress 6.8 or newer and PHP 8.1 or newer, with the Sodium extension
  (standard in PHP since 7.2).
- MySQL 5.7.8 or MariaDB 10.2.3 or newer.
- A Buffer account. Free plans work. Buffer's own limits apply, for example
  how many posts a free plan can queue.
- Images must be public HTTPS URLs. Video posts, Instagram, TikTok, YouTube
  and Pinterest are not supported yet.

## Install

Download the plugin ZIP from the releases page and upload it under
**Plugins > Add New > Upload Plugin**, then activate it. Open **PressQueue**
in the admin menu and follow the checklist.

To run it from a Git checkout, run `composer install --no-dev` in the plugin
folder before activating.

The [getting started guide](docs/getting-started.md) takes you through the
setup.

## Why an API key and not "Log in with Buffer"?

Buffer's login flow needs every website's address registered with Buffer in
advance. That does not work for a plugin anyone can install on their own
site. A personal API key is what Buffer recommends for tools you run
yourself. It takes about a minute to create, and PressQueue stores it
encrypted with your site's security keys.

## Privacy

PressQueue keeps its rules and history in your WordPress database. It only
talks to Buffer and, when a rule uses images, to the image's host to check
the file. Deactivating keeps your data. Uninstalling keeps it too, unless
you switch on deletion under **Settings > Data and privacy**. See
[Privacy and data](docs/privacy.md) for the details.

## For developers

PressQueue has WP-CLI commands with JSON output, hooks for observing
decisions and deliveries, and a provider interface for adding services
other than Buffer:

- [WP-CLI commands](docs/developer/wp-cli.md)
- [Observation hooks](docs/developer/observation-hooks.md)
- [Provider adapters](docs/developer/provider-extensions.md)

### Running the checks

```bash
composer install
composer run test:unit
node --test tests/editor/*.test.cjs
composer run lint:phpcs
composer run analyse
```

Integration tests need the WordPress test suite and a throwaway database.
They create and drop tables, so never point them at a real database:

```bash
bash tools/install-wp-tests.sh pressqueue_tests root '' 127.0.0.1 7.1.3
WP_TESTS_DIR=/tmp/wordpress-tests-lib-7.1.3 composer run test:integration
```

`composer run matrix:local` runs every supported PHP and WordPress version in
Docker. [Building and checking a package](docs/packaging.md) covers the full
release checks, and [CONTRIBUTING.md](CONTRIBUTING.md) explains how to
contribute.

## License

PressQueue's code is MIT licensed. The installable ZIP bundles Action
Scheduler, which is GPL-3.0-or-later, so the ZIP as a whole is distributed
under GPL-3.0-or-later. See [third-party notices](THIRD_PARTY_NOTICES.md).

PressQueue is an independent project. It is not affiliated with Buffer or
any other social publishing plugin.
