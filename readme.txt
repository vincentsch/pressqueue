=== PressQueue ===
Contributors: vincentsch
Tags: social media, buffer, auto post, share, scheduling
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/license/mit

Share your posts on LinkedIn, X, Bluesky, Mastodon, Threads and Facebook through Buffer, with rules you control.

== Description ==

PressQueue shares your WordPress posts through your Buffer account. You decide
which posts go where and what the message says. When you publish a post,
PressQueue checks it against your rules and hands it to Buffer in the
background. There it joins your queue, goes out right away or waits as a draft.

Nothing is sent until you say so. PressQueue starts in test mode, shows what
it would share and keeps a record of every share.

**Getting started**

* Paste a Buffer API key and your channels appear. Switch on the ones you want.
* Create a rule with one click: "Share every new post to all my channels".
* Test it against your recent posts, then turn it on and go live.

**Rules in plain words**

* Pick posts by post type, category, tag, author or custom field.
* Write the message with the title, excerpt, author, categories or tags, and
  see a live preview with a character count. The link is added for you.
* Choose the timing: the end of your Buffer queue, the next free slot, right
  away, or a Buffer draft to review first.
* Add the featured image when there is one.

**Decide what goes out**

* "Never share" rules keep a category, tag or author off social media. They
  win over every sharing rule.
* Each post has a "Share this post" switch in the editor, plus options to
  change the message, channels or timing for that post.
* Make sharing opt-in, so new posts start switched off.
* Share an older post from the editor with Share now, after a preview of
  exactly what each channel gets.
* Pause everything with one switch. Waiting shares are held until you resume.

**Share older posts**

Pick older posts, review the list and share them spread across your Buffer
queue. PressQueue never shares the same post to the same channel twice by
accident.

**See what happened**

The Activity page shows what was sent, what is waiting and what needs a look,
with a plain explanation for each share. If Buffer's reply is unclear,
PressQueue stops and asks you to check Buffer instead of risking a double post.
A column in your post list shows where each post went.

**For developers**

PressQueue has WP-CLI commands with JSON output, hooks for observing decisions
and deliveries, and a provider interface for services other than Buffer.

PressQueue has no hosted service and no tracking. It talks to Buffer from
your own server.

PressQueue's code is MIT licensed. The plugin package bundles Action
Scheduler (GPL-3.0-or-later), so the package as a whole is distributed under
GPL-3.0-or-later.

== Installation ==

1. Install PressQueue from Plugins > Add New, or upload the ZIP under
   Plugins > Add New > Upload Plugin, and activate it.
2. Open PressQueue in the admin menu and follow the checklist on the Overview.
3. Under Channels, create an API key in Buffer (Settings > API > New API key),
   paste it and click Connect. Switch on the channels you want to use.
4. Under Rules, create a rule, test it and turn it on.
5. When the test runs in Activity look right, click Go live.

PressQueue needs PHP's Sodium extension, which is standard in PHP 8.1, and
MySQL 5.7.8 or MariaDB 10.2.3 or newer.

== Frequently Asked Questions ==

= Do I need a Buffer account? =

Yes. Free Buffer plans work. Buffer's own plan limits apply, for example how
many posts a free plan can keep in its queue.

= Why an API key instead of logging in with Buffer? =

Buffer's login flow needs every website's address registered with Buffer in
advance. That does not work for a plugin people install on their own sites.
A personal API key is what Buffer recommends for tools you run yourself. It
takes about a minute to create, and PressQueue stores it encrypted.

= Which networks are supported? =

LinkedIn, X, Bluesky, Mastodon, Threads and Facebook, with text, a link and
one image. Instagram, TikTok, YouTube, Pinterest and video posts are not
supported yet.

= Will my old posts suddenly get shared? =

No. Rules share a post when you publish it. They share an update only if you
ask for that. Posts dated more than a day in the past are skipped. To share
older posts, use Share old posts, where you review the list before anything
happens.

= What is the difference between test mode and a Buffer draft? =

Test mode stays inside WordPress and sends nothing to Buffer. A rule with the
Buffer draft timing creates a real draft in Buffer, which you review and
schedule there.

= How do I stop a post from being shared? =

Switch off "Share this post" in the post's Social sharing panel. To keep a
whole category or tag off social media, create a "never share" rule.

= How do I stop everything? =

Choose Paused under PressQueue > Settings. Waiting shares are held until you
resume them. Removing a share from PressQueue does not delete a post Buffer
already published.

= What happens when I uninstall? =

Deactivating keeps your data. Uninstalling keeps it too, unless you switch on
deletion under Settings > Data and privacy first.

== External Services ==

PressQueue connects to Buffer's API at https://api.buffer.com after you add
a Buffer API key. It is needed to list your Buffer channels and to send the
posts your rules allow.

* When you connect or refresh channels, PressQueue sends your API key and
  reads your Buffer account name, organizations and channels.
* When a share goes out (only in Live mode), PressQueue sends the message
  text, the post link, the channel, the timing and, if the rule uses images,
  the image address and alt text.

The API key stays on your server, encrypted with your site's security keys,
and is never included in exports.

When a rule uses images, PressQueue may contact the image's own host to check
the file type, size and dimensions before sending the address to Buffer.

PressQueue sends no analytics or tracking requests.

Buffer terms of service: https://buffer.com/legal#terms
Buffer privacy policy: https://buffer.com/legal#privacy-policy

== Screenshots ==

1. The overview with the setup checklist and recent activity.
2. Connecting Buffer and switching channels on.
3. A rule in the rule editor, with the message preview.
4. The Social sharing panel in the post editor.
5. Activity, with a plain explanation for each share.

== Changelog ==

= 0.1.0 =

First public release.

* Rules with conditions, message templates, timing and images.
* "Never share" rules and a "Share this post" switch for each post.
* One-step Buffer connection and channel switches.
* Test mode, Live and Paused.
* Share old posts with a review step.
* Activity log with plain explanations and safe retries.
* WP-CLI commands, observation hooks and a provider interface.
