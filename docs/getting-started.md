# Getting started

This guide takes you from a fresh install to your first post on social media.
Nothing is sent to Buffer until the last step.

## 1. Connect Buffer

Open **PressQueue > Channels**.

1. Click **Open Buffer API settings**. Buffer opens in a new tab.
2. In Buffer, click **New API key**, name it "PressQueue" and copy the key.
3. Back in WordPress, paste the key and click **Connect**.

PressQueue checks the key, finds your Buffer organization and lists its
channels. If your Buffer account has more than one organization, pick the
one PressQueue should use.

Your channels start switched off. Switch on the ones PressQueue may post to.
A channel that PressQueue cannot post to, such as Instagram, says why.

The key is stored encrypted with your site's security keys. If you ever
change those keys, paste the Buffer key again under **More > Replace API key**.

## 2. Create a rule

Open **PressQueue > Rules**. A rule says which posts to share, where they go,
what the message says and when it goes out.

The quickest start is **Share every new post to all my channels**. It creates
a rule that shares every new post to the channels you switched on, adds it to
the end of your Buffer queue and uses the featured image when there is one.

To build your own, click **Add sharing rule**:

- **When**: usually "A post is published". Scheduled posts count when they go live.
- **Which posts**: leave it as is to share every post, or add conditions such
  as a category, tag, author or post type.
- **Where**: the channels this rule posts to.
- **Message**: the text before the link. Use the buttons to insert the title,
  excerpt, author, categories or tags. The link to the post is added at the
  end automatically. The preview counts characters against the channel's limit.
- **Timing**: add it to the end of the Buffer queue, use the next free queue
  slot, post right away, or save it as a Buffer draft for review.
- **Image**: the featured image when there is one, only posts that have a
  featured image, no image, or images chosen in each post.

Click **Save and test**. New and changed rules start switched off.

## 3. Test it

The test view runs the rule against your most recent posts without sharing
anything. Posts you published earlier are tested as if they were new. Each
post shows the channels it would go to or, if it would not be shared, why.
Open **Show messages** to see the exact text.

When it looks right, click **Turn on this rule**.

PressQueue starts in **test mode**. Test mode checks every post you publish
and records what it would share, but sends nothing to Buffer. Publish a post
or two and look at **PressQueue > Activity** to see the test runs. On the
**Overview**, **Check a post** shows what would happen to any post.

## 4. Go live

When the test runs look right, click **Go live** on the Overview, or choose
**Live** under **Settings > Sharing mode**. From then on, publishing a post
that matches a rule sends it to Buffer. Test runs from before are never sent.

Make sure no other plugin or service already shares the same posts, or they
will appear twice.

## Keeping posts off social media

Each post has a **Social sharing** panel in the editor with a **Share this
post** switch. Switch it off and the post is never shared. The panel saves
with the post. If that save fails, the post is not saved either, so an
unsaved "off" can never be published by accident.

To keep a whole category, tag or author off social media, click
**Add "never share" rule** on the Rules screen. These rules win over every
sharing rule. If one cannot be checked, the post is not shared.

If only some of your posts belong on social media, choose **Only share posts
where "Share this post" is switched on** under **Settings > New posts**. New
posts then start switched off.

## Changing one post

Open **Customize for this post** in the editor panel to change the message,
leave out channels, pick a date and time, or choose images for that post.
A picked time must be at least 15 minutes ahead and no more than a year out.

## Social images

PressQueue can draw an image for each post: the post title on a background,
plus your site name. Posts without a good photo still get a picture in the
feed.

1. Open **PressQueue > Settings > Social images**. Pick a background and a
   font. The preview uses your latest post titles. You can also choose your
   own background from the Media Library.
2. Open a rule and set **Image** to "Social image with the post title". A
   single post can choose it too, under **Customize for this post**.
3. Under **Link previews**, choose "Use the social image for every post" so
   the same picture appears whenever anyone shares the link.

The image is made the first time a post needs it and saved in
`wp-content/uploads/pressqueue/social`. It is made again when the title or
style changes. Generated images do not show up in the Media Library and are
deleted with their post. If the server cannot draw images (PHP needs GD with
FreeType), posts are shared with text and link instead.

Link previews work with The SEO Framework, Yoast SEO, Rank Math and Jetpack.
An image you set for a single post in those plugins still wins. With no SEO
plugin, PressQueue adds the preview tags itself. Other plugins that make
preview images, such as MightyShare, can override PressQueue's image, so
switch off their image output first.

## Sharing older posts

**PressQueue > Share old posts** shares posts that are already published:

1. **Pick posts**: "Never shared before" or "Share again", then narrow it down
   by category, date, tag or title. Up to 100 posts at a time.
2. **Review**: each post shows its channels, or why it will be skipped.
3. **Timing**: spread the shares across your Buffer queue, send one at a time
   from a start time, or save them all as Buffer drafts.

Only rules that include "I pick old posts" (or "I pick shared posts to share
again") take part. Your "never share" rules still apply. With "Never shared
before", PressQueue skips any channel a post was already shared to.

Followers see old posts as new ones, so share a small batch spread across
the queue rather than fifty posts in one morning.

## When something goes wrong

**PressQueue > Activity** lists every share. Anything that needs you shows up
under **Needs attention**, and the WordPress dashboard shows a notice.

- **Failed**: Buffer did not accept the share. The row says why, for example
  an expired key or a message that is too long. Fix the cause and click
  **Retry** where it is offered.
- **Check Buffer**: Buffer may have received the share, but its reply was cut
  off. PressQueue does not retry on its own, because that could post twice.
  Look for the post in Buffer, then open the share and tell PressQueue what
  you found.
- **On hold**: PressQueue was paused, or the post or rule changed before the
  share went out. Resume it from Activity once things look right.

To stop everything at once, choose **Paused** under **Settings > Sharing mode**.
Waiting shares stay on hold until you resume them.

## Background tasks

PressQueue sends shares in the background using WordPress's scheduled tasks,
which run when someone visits your site. On a quiet site, or a server that
blocks these requests, shares can wait. The Overview warns you when that
happens and shows how to add a server cron job.

## Good to know

- PressQueue keeps every message, including its link, within 280 characters
  on every network. A message that is too long is refused rather than cut off.
- Images must be JPEG, PNG or GIF files of up to 5 MB and 4096 pixels per
  side, at a public HTTPS address.
- Posts dated more than a day in the past are not shared automatically when
  published. Use Share old posts for those.
- An update is shared again only when a rule includes "A published post is
  updated" and the post's content changed.
- "Sent to Buffer" means Buffer accepted the share. Buffer then publishes it
  according to the timing you chose.

See [Privacy and data](privacy.md) for what PressQueue stores and how to
remove it.
