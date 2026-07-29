# PressQueue Agent Guide

## Scope

PressQueue is a clean-room open-source WordPress plugin for conditional social
publishing. Buffer is the first provider, but the core must not assume that
Buffer is the only possible provider.

## Current state

The repository is planning-only. Do not implement, install, release, publish,
or connect it to a live WordPress or social account unless Vincent explicitly
authorizes that exact action in the current conversation.

## Planning

Product and implementation planning lives in `.vroni/wip/`.

- `notes/vision.md`
- `notes/user-stories.md`
- `notes/architecture.md`
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
- `.vroni/` is excluded from the public-code repository and backed up in its
  own private `vronibot` repository.
