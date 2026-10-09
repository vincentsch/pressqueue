# Clean-Room Boundary

PressQueue is an independent implementation of conditional social publishing
for WordPress.

Buffer is the first provider integration. PressQueue is not affiliated with
Buffer, WordPress.org, Automattic, WooCommerce, or any third-party social
publishing plugin.

Project rules:

- Do not copy third-party plugin code, branding, copy, UI assets, fixtures, or
  protected media.
- Keep provider-specific behavior behind adapter interfaces.
- Store provider credentials only in encrypted server-side credential
  envelopes.
- Do not include live provider credentials, token-bearing transcripts, or
  secret runtime files in source, package artifacts, changelogs, QA evidence,
  or scratch output.

Bundled open-source dependencies keep their own license notices. See
`THIRD_PARTY_NOTICES.md` for package notices.
