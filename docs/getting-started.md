# Getting started

PressQueue sends qualifying WordPress posts to selected Buffer channels. Start
with dry run to check the rules. For a first remote delivery, choose the draft
schedule so the message becomes an unscheduled Buffer draft.

## Connect Buffer

In **PressQueue > Settings**, leave **Dry run** checked. Add a personal Buffer
API key in **Connections**, then use **Test Credential**. Select the organization
shown by that test and click **Sync Channels**.

Enable the connection and the channels you want to use. These settings make
channels available to rules. Dry run still prevents submissions to Buffer.
Your PHP installation needs Sodium, and your WordPress keys and salts must be
configured. If you change those keys, enter the Buffer credential again.

## Create a first rule

Open **PressQueue > Rules** and give the rule a name. Choose **Include** and
**first_publish**. The default condition matches the `post` post type.
Use the condition builder to add author, taxonomy, or custom field conditions.

Check the destination channels and choose a schedule. **Save an unscheduled
Buffer draft** is the draft schedule. It only creates a Buffer draft when dry
run is off. **Publish immediately** can publish to the social channel as soon
as the background worker runs.

The **Template Set JSON** field holds the message. This example uses the post's
title. PressQueue adds the canonical post URL after the message.

```json
{
  "schema_version": 1,
  "default_message": "{{ title }}"
}
```

Leave **Custom Field Allowlist JSON** as `[]` unless your conditions or template
use custom fields. Choose **No image** for the first check. Save the disabled rule,
enter a post ID in its preview form, and click **Preview Rule**. Read the message,
destination, schedule, and reasons before clicking **Enable After Preview**.
Changing the rule's behavior disables it again until a fresh preview.

Rule previews check the selected rule. **PressQueue > Preview** evaluates the
post against the enabled rules together, including exclusions. Use it to check
the full result before turning off dry run.

## Exclude posts

Choose **Exclude** in the post's PressQueue controls to stop that post from
qualifying. In the block editor, click **Save Controls** before publishing.
WordPress publishing and updating stay blocked while these changes are unsaved.
The Classic Editor saves the controls with the post. Controls require
`edit_pressqueue_post_controls` and permission to edit that post. Administrators
have the PressQueue capability by default.

An exclusion rule can cover many posts. Choose **Exclude** as its effect.
It applies to all destinations and publishing triggers. Matching
exclusions take precedence over inclusion. Errors in an exclusion also stop
planning. **Include** in post controls cannot bypass an exclusion.

For example, the following condition tree excludes a category and its children.
Replace `12` with the site's category ID. Exclusion rules also protect manual batches.

```json
{
  "schema_version": 1,
  "type": "group",
  "mode": "ALL",
  "children": [
    {
      "type": "condition",
      "condition": "taxonomy",
      "operator": "has_any",
      "value": {
        "taxonomy": "category",
        "terms": [12],
        "include_ancestors": true
      }
    }
  ]
}
```

## Messages and images

Template tags include `{{ title }}`, `{{ excerpt }}`, `{{ content }}`,
`{{ author }}`, `{{ published_at }}`, and `{{ terms:category }}`.
`{{ excerpt | truncate:120 }}` limits an excerpt to 120 characters. A tag such as
`{{ meta:campaign }}` needs `campaign` in the custom field allowlist.
Messages are converted to plain text. The final message and post URL must fit
the channel's configured limit, which is at most 280 characters in the Buffer
adapter. PressQueue refuses messages that exceed that limit.

A template can give each service its own message:

```json
{
  "schema_version": 1,
  "default_message": "{{ title }}",
  "service_messages": {
    "linkedin": "{{ title }} {{ excerpt | truncate:80 }}"
  }
}
```

Reusable templates are on the Rules screen. Adopting one copies its current
version into the rule. Editing the reusable template does not silently change
saved rules.

The image selector can require a featured image, use one when available, or
use attachments selected in the post controls. Supported images are JPEG, PNG,
or GIF, up to 5 MB and 4096 pixels per side. The Buffer adapter allows one image.
The URL must be public HTTPS without credentials or a query string. Image
checks may contact the image host. A required image that fails its checks stops
delivery. The optional featured-image mode sends text alone when the image is
missing.

## Turn on delivery

Check that other plugins will not send the same posts automatically. Preview
the full result, then turn off **Dry run** in Settings. Only future qualifying
work uses live delivery. Existing dry-run rows cannot be converted into deliveries.

A rule's draft schedule creates an unscheduled Buffer draft. Queue schedules
allow Buffer to choose a posting time. A post or batch can set an explicit time,
using an IANA timezone such as `Europe/Berlin`. Choose a time at least 15 minutes
ahead to leave time for background processing. Ambiguous daylight-saving times
and times more than 365 days ahead are refused. A draft-only rule
refuses overrides that would schedule or publish publicly.

Background work depends on WordPress cron and Action Scheduler. A site with
little traffic may need a server cron job to run WordPress cron. PressQueue's
queue sweep runs every five minutes. Inspect the Delivery Log for the result.

## Retry or stop a delivery

An **accepted** result means Buffer returned a receipt. Check Buffer for the
remote draft or publication status. A rate limit can be retried automatically.
A failed delivery may offer a manual retry depending on the recorded error.

An **ambiguous** result means Buffer may have accepted the request. Check the
remote channel before reconciling it. Attach the confirmed remote ID, confirm
that Buffer did not accept it, or cancel local work. Do not create a new delivery
to work around an uncertain result.

The kill switch holds live work. After turning it off, use the log to resume
selected held deliveries. Cancellation affects local work. It cannot remove a
remote post already accepted by Buffer.

## Existing posts and reposts

Use **Batches** to preview selected existing posts. Rules need the `bulk` trigger
for a bulk batch or the `repost` trigger for a selected repost batch. A repost
requires a previous accepted delivery for that post and channel.

Confirm the preview before local handoff. Changed posts or rules require a new
preview, and duplicate reservations are blocked. These batches are manual;
PressQueue does not start a recurring repost campaign.

See [Privacy and data](privacy.md) for export, retention, and uninstall options.

## Background delivery

Delivery checks the post again before contacting Buffer. It holds work when the
post becomes private, gains a password, or is excluded. Disabling or changing the
rule also holds its older queued work. Review the current rule before resuming.
Turning dry run back on stops existing live work from being sent.

Failed hook planning retries up to five times. A persistent failure appears as an
admin notice. Preview the affected post and check its decision log before making
a manual batch.

Image checks respect WordPress's external HTTP block list. HTTP proxies are not
supported by the image probe. Text delivery can still use WordPress's normal
HTTP configuration. Optional featured images fall back to text when unavailable.

Automatic first publication skips posts dated more than one day in the past.
Use a reviewed manual batch to share backdated posts or imported archives.

Update rules compare saved post facts. Renaming an author or taxonomy term, or
changing the site timezone or domain, does not by itself trigger another share.
