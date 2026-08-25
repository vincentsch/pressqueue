# Privacy, Export, Retention, and Uninstall

PressQueue stores local planning, delivery, batch, provider, and operator data
so publishing can be audited without depending on a hosted service. Provider
credentials remain server-side and are never exported.

## Runtime Latch

PressQueue uses the `pressqueue_runtime_disabled` option as a fail-closed
runtime latch.

- `1` means delivery runtime is closed.
- `0` means delivery runtime is open.
- Missing or malformed values are treated as closed.

Activation creates or closes the latch before migrations and opens it only
after storage, capabilities, settings, privacy defaults, and retention defaults
validate successfully. Deactivation closes the latch before unscheduling
delivery work.

When closed, PressQueue denies capture, finalization, durable planning, batch
preview or confirmation, batch recovery, queue sweeps, retry, resume, worker
entry, and the final pre-provider HTTP boundary. Read-only admin screens,
full-site export, personal data export or erasure, local cancellation,
reconciliation, privacy recovery, and uninstall cleanup remain available.

## Full-Site Export

Administrators with `export_pressqueue_data` can download paged JSON exports
from the Privacy screen.

Exports include:

- metadata such as generated time, cursor, record limit, byte limit, and export
  hash;
- privacy and runtime settings snapshots;
- selected fields from PressQueue-owned tables.

Exports do not include credential envelope JSON. Rendered social text and
media URLs are redacted by default. Including rendered payloads requires the
exact confirmation text shown on the Privacy screen.

Each export page is capped at 10,000 records and 50 MB. The returned cursor can
be submitted to continue the export.

## Personal Data Requests

PressQueue registers WordPress personal data exporter and eraser callbacks.
Those callbacks resolve the request email address to a local WordPress user and
only return or erase PressQueue records tied to that user ID.

The eraser does not remove delivery or batch audit rows. It clears local actor
references that directly identify the requesting user while preserving
operational history.

## Retention

Retention is disabled by default. When enabled, the bounded retention runner
deletes or redacts eligible resolved work older than the configured retention
period. The retention period must be between 30 and 3650 days.

Retention preserves unresolved delivery and batch work. It processes old
delivery events, batch items, deliveries, decisions, batches, and provider
diagnostics in bounded chunks with resumable option state.

Enabling retention requires exact confirmation text because it can delete or
redact local audit records.

## Uninstall

Uninstall preserves PressQueue data by default. If uninstall deletion is
enabled, uninstall removes local PressQueue tables, options, transients,
administrator role capabilities, and known PressQueue post metadata for each
site in a multisite network.

Action Scheduler actions owned by PressQueue are unscheduled during uninstall
even when stored data is preserved.

Enabling uninstall deletion requires exact confirmation text because the
cleanup is destructive.
