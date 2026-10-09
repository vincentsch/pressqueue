# PressQueue Agent Guide

## Scope

PressQueue is a clean-room open-source WordPress plugin for conditional social
publishing. Buffer is the first provider, but the core must not assume that
Buffer is the only possible provider.

## Current state

The repository contains a private development build being prepared for release.
Keep it private until Vincent approves publication. Do not install it on a live
WordPress site, connect a real account, or send social posts without current
approval for that action. Site-specific settings and operating receipts belong
outside this repository.

## Planning

Product and implementation planning lives in `.vroni/wip/`.

- `notes/vision.md`
- `notes/user-stories.md`
- `notes/architecture.md`
- `notes/agents.md`
- `notes/delivery-governance.md`
- `notes/provider-and-delivery-contracts.md`
- `notes/state-machines.md`
- `notes/security-and-privacy.md`
- `notes/qa.md`
- `notes/tools.md`
- `notes/releases.md`
- `notes/operations.md`
- `notes/tickets.md`
- `notes/strategy.md`
- `projects/`
- `tickets/inbox/`

Project and ticket files are implementation handoffs. Keep them concrete,
decided, testable, and free of research-only tasks.

## Safety

- Never log, commit, echo, or expose provider credentials.
- Social delivery is an external side effect and requires explicit approval.
- Default all new rules and destinations to disabled.
- Exclusion and policy failures must stop delivery.
- Every outbound delivery needs a stable idempotency key.
- Do not copy third-party plugin code, branding, copy, or protected assets.
- Keep the viral content system and Vincent's personal-site production
  workflow outside this repository.
- Never use an em dash in generated copy or documentation.

## Repository

- Public-code owner: `vincentsch`
- Repository: `vincentsch/pressqueue`
- The origin remains private until Vincent approves a public release.
- `.vroni/` is excluded from the public-code repository and kept in a separate
  private backup.
