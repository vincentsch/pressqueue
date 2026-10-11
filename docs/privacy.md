# Privacy and data

PressQueue keeps its settings and sharing history in your WordPress database.
The history includes post and user IDs, channel details, the message text it
prepared, image URLs and Buffer's receipts. These records explain what
happened to a share and stop it from being shared twice.

## Your Buffer key

PressQueue encrypts the Buffer key with your site's WordPress keys and salts.
The key stays on your server and never appears in an export. If you change
the keys and salts, the saved key becomes unreadable. Paste it again
afterwards.

## What leaves your server

PressQueue contacts Buffer when you connect it and when you refresh channels.
In Live mode, each share sends Buffer the message text, the timing, the
channel IDs and the URLs of any images you chose. Choosing an image may also
contact the image's host to check the file. See
[Buffer's terms](https://buffer.com/legal#terms) and
[privacy policy](https://buffer.com/legal#privacy-policy).

PressQueue sends no analytics or tracking requests.

## Export

An administrator with the `export_pressqueue_data` capability can download a
JSON file under **PressQueue > Settings > Data and privacy**. The Buffer key is
never included. Message text and image URLs are left out unless you tick
**Include message text and image URLs** and confirm it.

One file holds at most 10,000 records and 50 MB. On a larger site, enter the
cursor from the previous file under **Very large sites** to continue. Treat an
export that contains message text as private site data.

WordPress's own personal data export and erasure tools cover PressQueue too.
They find records linked to the WordPress user with the requested email
address. Erasure clears the optional fields that say which user carried out
an action. It keeps references the history needs, such as post author and
batch creator IDs, and says so in the erasure result.

## Removing old history

Automatic cleanup starts switched off. Switched on, it removes or blanks
finished records older than the period you choose, between 30 and 3650 days.
Unfinished work stays, including shares that still need a check in Buffer.
Switching cleanup on asks you to confirm, because it deletes history.

A share that Buffer accepted keeps a small record: its identity, state,
Buffer's receipt ID and the linked decision IDs. That record stops the same
post from being sent twice. Cleanup clears the copied message, the image
snapshot, the detailed receipt and the linked decision content. The small
records stay until you delete the plugin's data on uninstall.

## Deactivating and uninstalling

Deactivating stops PressQueue from picking up new posts and from running
background work. Your data stays. Uninstalling keeps the data too, by default.
It removes PressQueue's capabilities from user roles and, when Action
Scheduler is available, cancels its scheduled background actions.

If you tick **Delete all PressQueue data when the plugin is deleted** and
confirm it before you delete the plugin, uninstalling also drops PressQueue's
tables and deletes its settings, preview transients and post metadata,
including runtime fingerprints. Each site in a multisite network has its own
deletion setting. Deleting local data does not remove posts Buffer has already
accepted.

On multisite, PressQueue can remove a deleted site's tables only if the plugin
is loaded during the deletion request. If you run a network, check for leftover
`*_pressqueue_*` tables after deleting a site. They can contain the encrypted
Buffer key and sharing history.

## When sharing stops itself

PressQueue stops sending if its stored privacy or runtime settings are invalid.
The **Data and privacy** tab shows whether background sharing is running and,
if not, why and what to do.

For developers: the `pressqueue_runtime_disabled` option holds `1` for stopped
and `0` for running. A missing or malformed value also stops sending.
Activation sets it to running once setup has succeeded. Deactivation sets it to
stopped before it cancels scheduled work. Reading, exports, local cancellation
and settling shares that wait on a Buffer check still work while stopped.
