# PressQueue agent guide

Instructions for AI coding agents and human contributors working in this
repository. Read [CONTRIBUTING.md](CONTRIBUTING.md) as well.

## What this is

PressQueue is an open-source WordPress plugin that shares posts to social
networks through Buffer, based on rules the site owner sets. Buffer is the
first provider. Shared code must not assume that Buffer is the only one;
provider-specific behavior stays behind the adapter interfaces in
`src/Provider/`.

## Layout

- `src/Planning/` decides which posts qualify and renders messages.
- `src/Delivery/` and `src/Scheduler/` queue and send deliveries in the background.
- `src/Storage/` owns the database tables, records and validation.
- `src/Admin/` renders the WordPress admin screens. `Ui`, `Labels` and
  `assets/admin/admin.css` hold the shared building blocks.
- `assets/editor/` is the block editor sharing panel.
- `src/WpCli/` holds the `wp pressqueue` commands.
- `tests/unit/`, `tests/integration/` and `tests/editor/` hold the tests.

## Safety rules

- Never log, commit, echo or expose provider credentials.
- Sending to a social network is an external side effect. Tests use HTTP
  fakes; never send a real post from a test or a script without the site
  owner's explicit approval.
- New rules, connections and channels start disabled.
- Exclusions and policy errors must stop delivery. When in doubt, do not send.
- Every outbound delivery needs a stable idempotency key, and an uncertain
  provider response must not be retried automatically.
- Keep stored data formats, option names, meta keys and REST routes backward
  compatible; existing sites upgrade in place.
- Do not copy third-party plugin code, branding, copy or assets.

## Writing

Use plain, everyday words in the admin and in documentation. Name things the
way a site owner would (post, channel, rule, Buffer queue), not by internal
names. Never use an em dash.

## Checks

Run `composer run test:unit`, `node --test tests/editor/*.test.cjs`,
`composer run lint:phpcs` and `composer run analyse` before you finish.
Integration tests need a throwaway database; see the README.

Maintainers may keep private planning notes in `.vroni/`, which Git ignores.
