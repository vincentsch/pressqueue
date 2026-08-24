# Changelog

## 2026-08-24

### Bootstrap and local quality gate

- Added the guarded `pressqueue` plugin skeleton with PHP, WordPress, and
  network-activation runtime checks.
- Added Composer metadata, PSR-4 autoloading, PHPUnit, PHPCS, PHPStan, Plugin
  Check, dependency audit, runtime smoke, package smoke, and CI configuration.
- Added unit and WordPress integration tests proving lifecycle hooks exist and
  bootstrap makes zero external HTTP requests.
- Added private package smoke tooling that builds a `pressqueue/` rooted zip
  with production Composer autoloading.
- Tightened the bootstrap harness after review so PHP 8.1 dependency installs,
  Plugin Check parsing, HTTP guarding, and network-activation refusal are all
  enforced by tests.

### Local planning and Vroni readiness

- Added a root changelog before material repository work.
- Expanded `.vroni/wip/notes/` with agent, ticket, QA, tools, release,
  operations, delivery-governance, provider-contract, state-machine,
  security/privacy, and strategy notes.
- Linked the expanded note map from the architecture note.
- No product code exists yet and no public release, push, provider call, or
  live WordPress installation was performed.
