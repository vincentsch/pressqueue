# Public Release Checklist

PressQueue 0.1.0 is prepared as a local release candidate. Publication still
requires the owner's approval. Use the source snapshot when starting public Git
history, so private development history stays private.

## Prepared locally

The local candidate has an installable ZIP, a source snapshot, and four admin
screenshots. Setup and privacy guides, dependency licenses, translation files,
and CI checks are included. QA records the package hashes and compatibility
results separately from public source.

Before submission, confirm the WordPress.org account and plugin slug. Set up a
private security reporting route on the public repository. The candidate remains
private, and none of these steps submits or publishes it.

## Release checks

Before a public release:

- confirm the source tree contains no planning metadata, private scratch
  artifacts, secrets, provider transcripts, or token-bearing logs;
- make the release version change atomically across the plugin header,
  `PRESSQUEUE_VERSION`, `readme.txt` stable tag, `readme.txt` tested-up-to
  value, release notes, package metadata, translation catalog headers, and any
  generated package manifest;
- run the full local gate, package smoke, Plugin Check, and supported PHP and
  WordPress matrix;
- verify GitHub Actions use immutable external action references;
- verify WP-CLI downloads are checksum pinned in local tooling;
- verify Plugin Check is installed or downloaded at the pinned version used by
  the release candidate tooling;
- regenerate the translation catalog from source;
- review `readme.txt`, `docs/releases/`, `LICENSE`, and
  `THIRD_PARTY_NOTICES.md`;
- capture screenshots for key admin screens with disposable fixture data;
- enable private vulnerability reporting or name a private reporting contact;
- check the Git history as well as the current tree for private site details;
- run a clean-room review for names, copy, assets, and bundled dependencies;
- record the package filename, package SHA-256, source commit, supported
  runtime matrix, Plugin Check version, and test result summary.

Each public release action needs explicit approval for that exact action:

- changing repository visibility;
- creating or pushing a public Git tag;
- uploading or submitting anything to WordPress.org;
- publishing a GitHub release;
- distributing a package outside private validation;
- running live provider canary delivery or any other social publishing proof.
