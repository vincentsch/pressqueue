# WP-CLI commands

PressQueue's `wp pressqueue` commands return JSON, for scripts and for people
working on the server. Use `--format=json` or leave `--format` out; other
formats are rejected. Table output is not part of the stable contract.

Every response uses a versioned envelope:

```json
{
  "schema_version": 1,
  "command": "deliveries.list",
  "data": {},
  "pagination": null
}
```

List commands include pagination metadata. Other commands include
`"pagination": null`.

## Capabilities

Commands need a logged-in WordPress user with the same PressQueue capabilities
the admin screens use.

- `manage_pressqueue_settings`: `status`
- `manage_pressqueue_connections`: connections and channels
- `manage_pressqueue_rules`: rules and rule preview
- `view_pressqueue_decisions`: stored post preview and decisions
- `view_pressqueue_deliveries`: deliveries and queue status
- `operate_pressqueue_deliveries`: delivery retry, cancel, resume, reconcile
- `operate_pressqueue_batches`: batch preview, confirm, cancel, list, show

Preview commands also need `current_user_can( 'read_post', $post_id )` after
the post has been loaded.

## Commands

```text
wp pressqueue status
wp pressqueue connections list
wp pressqueue connections show <connection_public_id>
wp pressqueue channels list --connection=<connection_public_id>
wp pressqueue rules list
wp pressqueue rules show <rule_public_id>
wp pressqueue preview post <post_id> [--trigger=<first_publish|update|scheduled_publication>]
wp pressqueue preview rule <rule_public_id> --post-id=<post_id> [--trigger=<first_publish|update|scheduled_publication>]
wp pressqueue decisions list
wp pressqueue decisions show <decision_public_id>
wp pressqueue deliveries list
wp pressqueue deliveries show <delivery_public_id>
wp pressqueue deliveries retry <delivery_public_id> [--apply]
wp pressqueue deliveries cancel <delivery_public_id> [--apply]
wp pressqueue deliveries resume <delivery_public_id> [--apply]
wp pressqueue deliveries reconcile <delivery_public_id> --outcome=<confirmed_remote_id|confirmed_not_accepted|cancelled> [--remote-id=<id>] [--note=<text>] [--apply]
wp pressqueue queue status
wp pressqueue batches preview --type=<bulk|repost> (--post-ids=<comma-list> | --filters-json=<object>) [--limits-json=<object>] [--apply]
wp pressqueue batches confirm <batch_public_id> --preview-snapshot-hash=<64-hex> [--apply]
wp pressqueue batches cancel <batch_public_id> [--apply]
wp pressqueue batches list
wp pressqueue batches show <batch_public_id>
```

## Assessments

Commands that change something run as an assessment by default. Without
`--apply`, they do not schedule actions, create rows, append events or send
posts to providers. Retry and resume assessments decrypt credentials locally
to check whether they are usable. Message and batch previews may check
selected public image URLs and cache what they find. Credentials never appear
in command output.

An assessment that comes out as allowed or blocked exits `0`, because the
assessment itself completed. Applying a mutation that hits a missing record,
stale state, a conflict or a policy refusal exits `4`.

Applied commands repeat the service-level compare-and-set checks after the
assessment, because local state can change between the two.

## Pagination and filters

All list commands accept `--page` and `--per-page`. Defaults are `1` and `50`.
`--per-page` must be between `1` and `100`.

- Connections: `--provider`, `--enabled=<0|1>`, `--status`; order
  `updated_at DESC, public_id ASC`
- Channels: `--connection`, `--enabled=<0|1>`, `--availability`, `--service`;
  order `service ASC, public_id ASC`
- Rules: `--enabled=<0|1>`, `--effect`, `--trigger`; order
  `priority ASC, public_id ASC`
- Decisions: `--post-id`, `--result`, `--trigger`, `--scope-public-id`,
  `--created-from-utc`, `--created-to-utc`; order
  `created_at DESC, public_id DESC`
- Deliveries: `--state`, `--connection`, `--channel`, `--post-id`,
  `--due-retry=<0|1>`, `--changed-from-utc`, `--changed-to-utc`, `--id`;
  order `state_changed_at DESC, public_id DESC`
- Batches: `--type`, `--status`, `--created-by`, `--created-from-utc`,
  `--created-to-utc`; order `created_at DESC, public_id DESC`

Identifier filters are exact matches, trimmed, up to 191 characters, with no
wildcards. UTC range filters accept
`YYYY-MM-DD HH:MM:SS` or `YYYY-MM-DDTHH:MM:SSZ`, include the start and
exclude the end.

## Batch inputs

`--post-ids` and `--filters-json` cannot be combined.

`--post-ids` accepts 1 through 100 unique positive WordPress post IDs. It
builds a filter document with `schema_version: 1`, those IDs, default
`post_types: ["post"]` and default `post_statuses: ["publish"]`.

`--filters-json` accepts one JSON object up to 64 KB. `schema_version` must be
the integer `1`. `post_ids` is rejected inside `--filters-json`.

`--limits-json` accepts one JSON object up to 64 KB. Defaults are
`candidate_limit: 20`, `destination_item_limit: 100` and
`schedule.mode: "inherit"`.

## Exit codes

- `0`: successful read, applied mutation, or permitted assessment
- `2`: invalid arguments, malformed JSON, schema validation failure, duplicate
  IDs, unknown keys, or invalid UTC ranges
- `3`: unauthenticated or unauthorized
- `4`: missing record, stale state, conflict, or policy refusal on apply
- `5`: internal or storage failure

Stable error codes are documented in the JSON schemas under
`docs/developer/schemas/wp-cli/v1/`.

## Redaction

Command output never includes credential envelopes, raw credentials,
Authorization headers, raw HTTP request arguments, raw provider responses,
full provider payloads or PHP traces. The `preview` commands show the message
PressQueue would send, so their output can contain post text, the author's
name and image addresses. Treat it like the post itself.
