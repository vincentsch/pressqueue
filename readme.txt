=== PressQueue ===
Contributors: webtq
Tags: buffer, linkedin, auto post, social media, scheduling
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.2
License: MIT
License URI: https://opensource.org/license/mit

Auto post from WordPress to LinkedIn, X and Facebook through Buffer. Rules, previews and social images are free. No Pro version.

== Description ==

PressQueue automatically shares WordPress posts to LinkedIn and other social networks through Buffer. Publish a post on your site, and your rules choose which channels receive it and what the message says. Buffer handles the social network connections and publishes from your queue.

Use it to send new articles to LinkedIn, or share selected categories to different networks. PressQueue supports LinkedIn, X (Twitter), Facebook, Bluesky, Mastodon and Threads, with text, a link and one image.

= Free, with no Pro version =

* Rules that choose posts by category, tag, author, post type or custom field.
* Message previews and a social image made from each post's title.
* Queue scheduling, immediate sharing and Buffer drafts to review first.
* A review step for sharing older posts and an Activity log for every share.

There is no Pro version, no license key and no PressQueue account to create. The plugin runs on your server and connects directly to Buffer. You need a Buffer account and API key. Buffer's free plan works, within its channel and queue limits.

PressQueue starts in test mode. It records what it would share and sends no posts to Buffer until you click Go live. You can try your rules against recent posts before sending anything.

[Getting started](https://www.pressqueue.com/docs/getting-started/) | [Documentation](https://www.pressqueue.com/docs/) | [Support forum](https://wordpress.org/support/plugin/pressqueue/)

= Connect WordPress to Buffer =

* Paste a Buffer API key and your channels appear. Switch on the ones you want.
* Create a rule with one click: "Share every new post to all my channels".
* Test it against your recent posts, then turn it on and go live.

= Choose which posts get shared =

* Pick posts by post type, category, tag, author or custom field.
* Write the message with the title, excerpt, author, categories or tags, and see a live preview with a character count. The link is added for you.
* Choose the timing: the end or the top of your Buffer queue, right away, a Buffer draft to review first, or a date and time for one post.

= Create social images from your post titles =

* PressQueue draws an image for each post with its title and your site name, in a style you pick once: 24 backgrounds and 6 fonts, or your own background.
* The social image can also be the link preview of your posts, with Yoast SEO, Rank Math, The SEO Framework, Jetpack or no SEO plugin.

= Control sharing for each post =

* "Never share" rules keep a category, tag or author off social media. They win over every sharing rule.
* Each post has a "Share this post" switch in the editor, plus options to change the message, channels or timing for that post.
* Make sharing opt-in, so new posts start switched off.
* Share now in the editor shares a published post that was never shared. You see what each channel gets before you confirm.
* Pause everything with one switch. Waiting shares are held until you resume.

= Share older posts =

Pick up to 100 older posts, review the list and spread them across your Buffer queue. Channels a post already went to are skipped, unless you pick the post to share again. Buffer's queue limits still apply, so choose a batch that fits your available queue space.

= See what happened =

The Activity page shows what was sent, what is waiting and what needs a look, with a plain explanation for each share. If Buffer's reply is unclear, PressQueue asks you to check Buffer before it tries again, so a post is not sent twice. A column in your post list shows where each post went.

= For developers =

PressQueue has WP-CLI commands with JSON output, hooks for watching decisions and deliveries, and a provider interface for services other than Buffer. The [source code is on GitHub](https://github.com/vincentsch/pressqueue).

PressQueue's own code is MIT licensed. The plugin package bundles Action Scheduler (GPL-3.0-or-later), so the package as a whole is distributed under GPL-3.0-or-later.

== Installation ==

1. Install PressQueue under Plugins > Add Plugin and activate it. If you downloaded pressqueue.zip from pressqueue.com, upload it under Plugins > Add Plugin > Upload Plugin instead.
2. Open PressQueue in the admin menu and follow the checklist on the Overview.
3. Under Channels, open Buffer's API settings, create a key (pick the longest expiry, since the default is 30 days), paste it and click Connect. Switch on the channels you want to use.
4. Under Rules, create a rule, test it and turn it on.
5. When the test runs in Activity look right, click Go live.

PressQueue needs PHP's Sodium extension, which has been part of PHP since 7.2, and MySQL 5.7.8 or MariaDB 10.2.3 or newer. Generating social images also requires PHP's GD extension with FreeType.

== Frequently Asked Questions ==

= Do I need a Buffer account? =

Yes. You need a Buffer account and a personal API key from [Buffer's API settings](https://publish.buffer.com/settings/api). Buffer's free plan works, within its channel and queue limits. PressQueue itself is free.

= Can I automatically post from WordPress to LinkedIn? =

Yes. Connect your LinkedIn channel in Buffer, then connect Buffer to PressQueue. Choose that channel in a sharing rule and test the rule against a recent post. When you go live, new matching posts are sent to Buffer with your message and link. Buffer publishes them on LinkedIn at the timing you chose, or keeps them as drafts if you chose Buffer draft.

= Does PressQueue add social sharing buttons to my site? =

No. PressQueue sends posts to your own social channels through Buffer. It does not add buttons for visitors to share a page, or display a social media feed on your site.

= Is PressQueue an alternative to WordPress to Buffer? =

Yes, if you want conditional rules and queue timing without a paid upgrade. Sharing selected older posts is included too. PressQueue also creates social images from post titles.

Other Buffer plugins offer things PressQueue does not. WP Zinc's free WordPress to Buffer connects with one click instead of an API key and supports Google Business Profile and TikTok. Its Pro version adds Instagram and Pinterest. It also offers multiple images and automatic recurring reposts. PressQueue does not support those networks or workflows.

PressQueue does not import settings from other plugins. Connect Buffer and create your rules here, then turn off the other plugin's automatic sharing before going live to avoid posting twice.

= Why an API key instead of logging in with Buffer? =

PressQueue uses a personal API key so your site connects directly to Buffer. You do not need a PressQueue account or a shared login service. Create a key in Buffer's settings and paste it into PressQueue. The key is stored encrypted with your site's security keys.

= Which networks are supported? =

LinkedIn, X, Bluesky, Mastodon, Threads and Facebook, with text, a link and one image. Instagram, Pinterest, TikTok, YouTube, Google Business and video posts are not supported.

= Will my old posts suddenly get shared? =

No. Rules share a post when you publish it. They share an update only if you ask for that. Posts dated more than a day in the past are skipped. To share older posts, use Share old posts, where you review the list before anything happens.

= What is the difference between test mode and a Buffer draft? =

Test mode stays inside WordPress and sends nothing to Buffer. A rule with the Buffer draft timing creates a draft in Buffer, which you review and schedule there.

= How do I stop a post from being shared? =

Switch off "Share this post" in the post's Social sharing panel. To keep a whole category or tag off social media, create a "never share" rule.

= How do I stop everything? =

Choose Paused under PressQueue > Settings. Waiting shares are held until you resume them. Removing a share from PressQueue does not delete a post Buffer already published.

= What happens when I uninstall? =

Deactivating keeps your data. Uninstalling keeps it too, unless you switch on deletion under Settings > Data and privacy first.

= Where do I get help? =

Read the [documentation](https://www.pressqueue.com/docs/) or ask in the [PressQueue support forum](https://wordpress.org/support/plugin/pressqueue/). You can also [report a bug on GitHub](https://github.com/vincentsch/pressqueue/issues).

== External Services ==

PressQueue connects to Buffer's API at `https://api.buffer.com` after you add a Buffer API key. It is needed to list your Buffer channels and to send the posts your rules allow.

* When you connect or refresh channels, PressQueue sends your API key and reads your Buffer account name, organizations and channels.
* When a share goes out (only in Live mode), PressQueue sends the message text, the post link, the channel, the timing and, if the rule uses images, the image address and alt text.

The API key stays on your server, encrypted with your site's security keys, and is never included in exports.

When a rule uses images, PressQueue may contact the image's own host to check the file type, size and dimensions before sending the address to Buffer.

PressQueue sends no analytics or tracking requests.

[Buffer terms of service](https://buffer.com/legal#terms) [Buffer privacy policy](https://buffer.com/legal#privacy-policy)

The Channels screen links to WordPress's instructions for setting security keys and salts. PressQueue does not call a remote salt generator or send your site's keys to WordPress.org.

== Screenshots ==

1. The Social sharing panel in the post editor shows where a post goes when you publish it.
2. A rule in the rule editor, with a preview of the post on Bluesky.
3. Pick the look of the social image, with a live preview.
4. Connect Buffer once and switch on the channels PressQueue may post to.
5. Activity lists every share and its status.
6. The overview: recent shares and a quick check of what would happen to any post.

== Changelog ==

= 0.1.2 =

* Verify admin form requests before passing their data to input helpers.
* Sanitize Activity filters and reject arrays where a URL parameter needs text.
* Keep the existing activation state when refusing an unsupported network activation request.

= 0.1.1 =

* Updated bundled Action Scheduler to 4.2.0. WordPress 6.9 or newer is now required.
* Hardened admin header HTML and font URL output.
* Clarified the link to WordPress security-key instructions.

= 0.1.0 =

First public release.

* Rules with conditions, message templates, timing and images.
* Social images drawn from the post title, which can also be the link preview of your posts.
* Share now in the editor for a published post that was never shared.
* "Never share" rules and a "Share this post" switch for each post.
* Connect Buffer by pasting an API key, with a switch for each channel.
* Test mode, Live and Paused.
* Share old posts with a review step.
* An Activity page that explains each share and asks you to check Buffer when a reply was unclear.
* WP-CLI commands, observation hooks and a provider interface.
