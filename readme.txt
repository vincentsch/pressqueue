=== PressQueue ===
Contributors: vincentsch
Tags: social publishing, buffer, scheduling
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/license/mit

Send WordPress posts to Buffer using rules, previews, and a delivery log.

== Description ==

PressQueue sends WordPress posts to Buffer using rules you set. Choose which
posts qualify, preview their messages, and send them to selected social
channels. You can save unscheduled Buffer drafts for review, publish immediately,
use Buffer's queue, or choose a time in the post editor.

Rules can match post types, authors, categories, tags, and allowed custom
fields. Exclusions take precedence. Reusable templates provide the message,
and editors with permission can change it or exclude an individual post.

The delivery log shows queued work, Buffer receipts, failures, and retries.
If Buffer may have accepted a request but the result is unclear, PressQueue
stops for an operator to check before retrying. A receipt confirms Buffer
accepted the request; it does not confirm publication on the social network.

New connections, channels, and rules start disabled. Dry run is enabled on
installation and records local results without submitting posts to Buffer.
The kill switch holds live deliveries until you resume or cancel them.

Manual batches let you preview and confirm selected existing posts or reposts.
PressQueue does not run recurring repost campaigns. OAuth and video uploads
are not supported. Network activation is refused; activate separately per site.

The Buffer adapter supports Bluesky, Facebook, LinkedIn, Mastodon, Threads,
and X. It uses a conservative 280-character limit, including the WordPress
post URL, and supports one image up to 5 MB and 4096 pixels per side. A lower
Mastodon server limit takes precedence. Other channels cannot deliver.

== Installation ==

1. Upload the packaged PressQueue ZIP in Plugins > Add New > Upload Plugin.
2. Activate PressQueue. The package includes its PHP dependencies.
3. Open PressQueue > Settings and leave Dry run checked while setting up.
4. In Connections, add a Buffer personal API key, test it, select an organization,
   and sync its channels. Enable the connection and your selected channels.
5. Create a rule, preview it against a post, and enable it after checking the result.
6. To send reviewed posts to Buffer, turn off Dry run. Choose the draft schedule
   on the rule if you want unscheduled Buffer drafts.

PHP's Sodium extension is required for credential encryption. The database
must support InnoDB and JSON functions, as provided by MySQL 5.7.8 or
MariaDB 10.2.3 and newer. Image media needs public HTTPS URLs without a query string.

== Frequently Asked Questions ==

= Do I need a Buffer account? =

Yes, for delivery through the included provider. You also need a personal API
key from Buffer. Buffer's plans and API access requirements apply separately.
There is no PressQueue hosted service.

= Does preview create a Buffer post? =

No. Preview evaluates the rules and renders messages locally. Selecting image
media may contact the image host to check its URL and file properties.

= What is the difference between dry run and a Buffer draft? =

Dry run stays in WordPress and never submits a post to Buffer. A draft schedule
creates a real, unscheduled draft in Buffer when live delivery is enabled.

= Can an editor change the destinations? =

Post controls require a separate PressQueue capability, granted to administrators
by default. An authorized editor can restrict the channels chosen by matching
rules. Post controls cannot add a destination outside those rules.

= When are block editor controls saved? =

Use Save Controls in the PressQueue panel before publishing the WordPress post.
The Classic Editor saves PressQueue controls with the post.

= How do I stop sending? =

Enable the kill switch in Settings. It holds pending live work locally. Cancelling
a local delivery cannot remove a post already accepted by Buffer.

= What happens when I uninstall? =

PressQueue preserves settings and history by default. Enable deletion on the
Privacy screen before uninstalling if you want its local data removed.
Deactivation stops background work and preserves data.

== External Services ==

PressQueue uses Buffer's API at https://api.buffer.com after you configure a
connection. Test Credential and Sync Channels make read requests to Buffer.
Background delivery submits the messages allowed by your enabled rules when
dry run is off and the kill switch is off.

Requests send a Buffer API key in the Authorization header, account and channel
identifiers, message text, schedule information, and selected image URLs and
alt text. Credentials stay on your WordPress server and are never exported.
They are encrypted using the site's WordPress keys and salts. Changing those
keys requires entering the Buffer key again.

When you select image media, PressQueue may contact its public HTTPS host to
check file type, size, and dimensions. It sends the image URL to Buffer for
retrieval. PressQueue does not add analytics or tracking requests.

Buffer terms and privacy information: https://buffer.com/legal#terms and https://buffer.com/legal#privacy-policy

== Screenshots ==

1. Dry-run and kill-switch settings.
2. Rule conditions and destination choices.
3. Post controls in the WordPress editor.
4. Export, retention, and uninstall settings.

== Changelog ==

= 0.1.0 =

* Rules, exclusions, message templates, previews, and post editor controls.
* Buffer connections, draft and scheduled delivery, and supported image media.
* Background queue, delivery history, safe retry controls, and manual batches.
* WP-CLI commands, provider extension hooks, privacy export, and optional cleanup.
