# Privacy and data

PressQueue stores its settings and delivery history in your WordPress database.
This includes post and user IDs, channel details, rendered messages, image URLs,
and Buffer receipts. These records help explain a delivery and avoid duplicates.

## Credentials and remote requests

Buffer credentials are encrypted using the site's WordPress keys and salts.
They stay on the server and never appear in exports. Changing the keys and salts
makes saved credentials unreadable. Enter the Buffer key again afterward.

Connecting Buffer and refreshing channels contact Buffer. Live delivery sends
message text, schedule details, channel identifiers, and selected image URLs.
Image selection may also contact the image host to check the file. See
[Buffer's terms](https://buffer.com/legal#terms) and
[privacy policy](https://buffer.com/legal#privacy-policy).

PressQueue does not add analytics or tracking requests.

## Export

An administrator with `export_pressqueue_data` can download a JSON file under
**PressQueue > Settings > Data and privacy**. Credentials are always omitted.
Message text and image URLs are left out unless the administrator ticks
**Include message text and image URLs** and confirms it.

Each export file holds at most 10,000 records and 50 MB. For a larger site,
enter the cursor from the previous file under **Very large sites** to continue. Treat exports containing message text as
private site data.

WordPress's personal data export and erasure tools also include PressQueue.
They find records linked to the WordPress user whose email was requested.
Erasure clears optional operator references. It retains audit references such
as post author and batch creator IDs, and reports this in the erasure result.

## Retention

Retention starts disabled. If you enable it, PressQueue removes or redacts old,
resolved work after the selected period, between 30 and 3650 days. Unresolved
work, including shares that still need a check in Buffer, is preserved.
Turning retention on asks you to confirm, because cleanup deletes history.

Accepted deliveries keep their identity, state, receipt identifier, and linked
decision IDs to prevent duplicate sending. Retention clears their copied message,
image snapshot, and detailed receipt. It also clears the linked decision content.
These small identity records remain until you delete plugin data on uninstall.

## Deactivation and uninstall

Deactivation stops new capture and background work and keeps the data.
Uninstall also preserves data by default. PressQueue removes its capabilities
from roles. It unschedules its background actions when Action Scheduler is available.

If you tick **Delete all PressQueue data when the plugin is deleted** (and
confirm it) before removing the plugin, it also
deletes its tables, settings, preview transients, and post metadata, including
runtime fingerprints. Each site in a multisite network uses its own deletion
setting. Removing local data does not delete posts already accepted by Buffer.

On multisite, PressQueue can remove a deleted site's tables only when the plugin
is loaded in the deletion request. Network administrators should check for leftover
`*_pressqueue_*` tables when deleting sites. These tables may contain encrypted
credentials and delivery history.

## Runtime recovery

PressQueue stops delivery if its stored privacy or runtime state is invalid.
The **Data and privacy** tab says whether background sharing is running and,
if not, why and what to do.

For developers, `pressqueue_runtime_disabled` accepts `1` for stopped and `0`
for active. Missing or malformed values stop delivery. Activation opens it after
setup succeeds; deactivation closes it before unscheduling work. Read operations,
exports, local cancellation, and reconciliation remain available while stopped.
