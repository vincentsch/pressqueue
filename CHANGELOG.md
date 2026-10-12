# Changelog

## 0.1.0 (unreleased)

First public release.

### Sharing

- Share posts to LinkedIn, X, Bluesky, Mastodon, Threads and Facebook through
  Buffer, with text, a link and one image.
- Rules pick posts by post type, category, tag, author or custom field, and
  set the channels, message, timing and image.
- "Never share" rules keep posts off social media and win over every sharing
  rule. If one cannot be checked, the post is not shared.
- Messages use placeholders such as the title or excerpt, can differ per
  network, and can start from saved templates.
- Timing can be the end of the Buffer queue, the next free slot, right away,
  a Buffer draft, or a date and time picked for one post.
- Share old posts in reviewed batches, for the first time or again.

### Social images

- PressQueue can draw a social image for each post: the post title on a
  background, in a style you pick once. 24 backgrounds and 6 fonts are
  included, and you can use your own background image.
- A rule or a single post can share with the social image. If the image
  cannot be made, the post is shared with text and link as before.
- The social image can also become the link preview image of your posts.
  PressQueue hands it to The SEO Framework, Yoast SEO, Rank Math or
  Jetpack, or adds the tags itself when no SEO plugin is active.

### Admin

- An overview with a setup checklist, recent activity, a post check and a
  warning when background tasks are not running.
- Connect Buffer by pasting an API key. Channels appear with on/off switches.
- A rule editor with ordinary form fields, a live message preview and a test
  against recent posts before a rule is switched on.
- Test mode, Live and Paused as one switch.
- An Activity page that explains every share, groups what needs attention and
  walks you through checking Buffer when a reply was unclear.
- A "Share this post" switch in the block and classic editors that saves with
  the post, and an optional opt-in mode for new posts.
- A Share now button in both editors for published posts that were never
  shared. It shows what each channel would get before anything is sent.
- Posts kept back by their own switch show "Sharing off" instead of an error
  label.
- A Social sharing column in post lists and a dashboard notice for shares
  that need attention.

### Safety

- New rules and channels start switched off, and PressQueue starts in test mode.
- Every share has a stable identity, so repeated WordPress events never create
  duplicate posts.
- Uncertain Buffer replies are never retried automatically.
- Posts are checked again right before they are sent. Changes to the post,
  rule or settings hold the share.
- The Buffer key is stored encrypted and never exported.

### Data

- Optional automatic cleanup of old history, a JSON export, WordPress personal
  data export and erasure support, and optional data removal on uninstall.

### Developers

- WP-CLI commands with JSON output, observation hooks and a provider interface
  with a shared conformance test suite.
