# Calico JetEngine MPC CPT Bridge

[Download the latest plugin ZIP](https://github.com/CalicoDesignCo/calico-jetengine-mpc-cpt-bridge/releases/latest/download/calico-jetengine-mpc-cpt-bridge.zip) · [Releases](https://github.com/CalicoDesignCo/calico-jetengine-mpc-cpt-bridge/releases)

A conservative, opt-in WordPress bridge for custom post types and post-meta-backed fields. Administrators choose exactly which content becomes available to MCP clients through the native WordPress Abilities API.

## What it does

- Scans non-core registered custom post types, including JetEngine CPTs.
- Detects registered metadata and stored, non-system post-meta keys.
- Shows post-type visibility, REST status, item count, and inferred field types.
- Requires explicit administrator approval for every post type and field.
- Provides global **Select all detected post types** and per-CPT **Select all custom fields** controls.
- Registers eight stable abilities:
  - `calico-jetengine-mpc/list-types`
  - `calico-jetengine-mpc/list-items`
  - `calico-jetengine-mpc/get-item`
  - `calico-jetengine-mpc/create-item`
  - `calico-jetengine-mpc/update-item`
  - `calico-jetengine-mpc/get-media`
  - `calico-jetengine-mpc/upload-media`
  - `calico-jetengine-mpc/update-media`
- Supports title, content, excerpt, slug, status, featured image, and approved custom fields for creation and updates.
- Applies type-, item-, field-, publishing-, and creation-level WordPress capability checks.
- Returns a `revision` token covering core content, the featured image, and approved fields.
- Detects common JetEngine date storage patterns and publishes both the raw field value and a readable `field_display` value.
- Lets administrators configure each approved field as auto, text, UTC date timestamp, ISO date, or date-time timestamp.
- Does not expose trash or permanent-delete abilities.

## Install and configure

1. Upload and activate the release ZIP in WordPress.
2. Open **Tools → Calico CPT Bridge**.
3. Click **Scan Post Types and Fields**. Scanning is intentionally manual and does not run during activation.
4. Check the post types and fields you want to expose. The bulk controls select only detected, eligible entries.
5. Click **Save Approved MCP Abilities**.
6. In your MCP connector, grant the eight Calico CPT Bridge abilities only to the intended role.
7. Refresh or reconnect the MCP client and discover abilities again.

## JetEngine date-only fields

After upgrading, scan again so the bridge can inspect existing values. For JetEngine Date fields with **Save as timestamp** enabled, choose **Auto-detect** when the suggestion says **Date -> UTC timestamp**, or select that format explicitly. The AI can then send `YYYY-MM-DD`; the bridge stores UTC-midnight Unix seconds, matching JetEngine's calendar-date convention.

`list-types` returns `field_definitions` describing each approved field's effective storage and input format. `get-item`, `create-item`, and `update-item` retain raw values in `fields` and add human-readable values in `field_display`. For example, raw `1791331200` is displayed as `2026-10-07`, regardless of the site's timezone.

Invalid date input is rejected before it can leave an incomplete event. A failed create also rolls back its temporary draft.

JetEngine's own **Save as timestamp** toggle and the bridge field format must agree. If the JetEngine field stores `YYYY-MM-DD`, choose **Date -> YYYY-MM-DD** instead.

Date and date-time values are intentionally different: calendar dates use UTC midnight to avoid timezone drift, while date-times display in the WordPress site timezone.


`create-item` requires an approved post type and a non-empty title. New content starts as a draft while field-level permissions, approved metadata, and featured media are applied. The item moves to the requested status only after those steps succeed. Publishing or making content private requires the post type's native publishing capability.

If a post-creation step fails, the plugin attempts to remove the incomplete draft. If rollback itself fails, the returned error includes `created_item_id` and `rollback_failed` so an administrator can locate it.

## Advanced Date schedules (2.5.0)

JetEngine Advanced Date is distinct from an ordinary date-only field. Approve the base field, FIELD__end_date and FIELD__config. Send the complete JSON schedule in FIELD__config through create-item/update-item, for example:

```json
{"date":"2026-12-04","time":"19:00","is_end_date":"1","end_date":"2026-12-04","end_time":"23:00"}
```

The native JetEngine Advanced Date provider regenerates timestamp rows and clears its pair cache. Its WordPress UTC PHP convention encodes venue wall-clock components without adding a site offset. Ordinary date-only and absolute datetime formats retain their existing behavior.

The bridge validates calendar dates, 24-hour times, end ordering, permissions and saved rows. It rejects conflicting companion values and independent edits to an existing schedule timestamp. Supply the full config for schedule changes, retaining recurrence/manual-date settings. Recurrence generation remains JetEngine's responsibility. Writes require a finite rule: at most 1000 occurrences, or an end date within 1000 days; weekly rules require selected ascending weekdays. Native provider availability is required. No new abilities or permissions are granted automatically.

New items remain temporary drafts until schedule verification succeeds. A failed schedule create removes its incomplete draft. A failed schedule update attempts to restore the affected prior metadata, including repeated row ordering. Status-only publication of a recognized inconsistent schedule is refused. WordPress hooks and other plugins can have side effects outside these metadata writes; this is not a cross-system database transaction.

Protection applies to bridge create/update calls; it is not a global interception of generic CPT tools or third-party writes. Clients must still compare raw timestamps and full config after all writes/publication. Date-only field_display is not proof that a time was saved correctly. Separate display-time and calendar helper fields remain site-specific and are not inferred or overwritten.

## Safe creation

## Flyer-to-event media workflow

1. Ask the AI client to inspect the flyer and extract the event title, dates, venue, description, alt text, and approved custom-field values.
2. Call `upload-media` with either a public HTTPS `source_url` or `data_base64` plus a filename. The call can also save the AI-written title, alt text, caption, and Media Library description.
3. Use the returned attachment `id` as `featured_image_id` in `create-item`, together with the AI-written event content and approved JetEngine fields.
4. Use `get-media` when the AI needs the public source URL, dimensions, sizes, or stored attachment text for an existing WordPress image.

An AI client being able to visually inspect a chat attachment does not guarantee that its connector can forward the attachment bytes to an MCP ability. The client must provide either an accessible HTTPS URL or base64 image data to `upload-media`.
5. Use `update-media` to add or revise the existing attachment's title, alt text, caption, or description. Read the media first and pass its `revision` as `expected_revision` to prevent stale edits.

Media uploads require WordPress's native `upload_files` capability. URL imports allow HTTPS only, use WordPress safe HTTP validation, reject private/local destinations, follow at most three redirects, and are limited to 10 MB. Base64 imports have the same size limit. JPEG, PNG, GIF, WebP, and AVIF are supported; SVG and arbitrary files are rejected.

`get-media` returns the attachment's direct source URL rather than embedding the full image bytes in every response. This keeps MCP responses small and gives vision-capable clients a usable image location.

## Safe updates

Call `get-item` immediately before an update and pass its `revision` value as `expected_revision`. The update is rejected if core content, the featured image, or an approved field changed in the meantime. The older `expected_modified` input remains available for compatibility, but it cannot detect metadata-only changes.

Omitted values remain unchanged. Passing `null` for an approved custom field deletes that field. Registered metadata uses its WordPress sanitization callback; other approved values preserve safe post HTML and structured arrays.

Media metadata follows the same safe pattern: call `get-media`, then pass its `revision` to `update-media`. The update requires native attachment edit access plus `upload_files` and rejects stale revisions.

## Requirements

- WordPress 6.9+
- PHP 7.4+
- An MCP connector that discovers WordPress abilities with `show_in_rest` enabled

## Safety model

The plugin starts with an empty CPT/field allowlist. No custom post type or field is exposed until an administrator approves it. Read queries verify access to every returned item. Creation and updates enforce native `create_posts`, `edit_post`, `edit_post_meta`, and status-specific capabilities. Media abilities separately enforce `upload_files` and attachment access. Underscore-prefixed metadata and common Elementor, WordPress, Rank Math, and Yoast implementation fields are omitted from the approval screen.

The field scanner presents approval candidates, not a guarantee that a field is nonsensitive. Administrators should approve only data intended for the assigned MCP role.

## JetEngine scope

This bridge supports JetEngine fields stored in WordPress post metadata. JetEngine custom database tables and relations are outside its scope. Advanced Date schedules have a dedicated native-provider integration; other specialized field save handlers require their own integration.

WordPress does not provide a transaction spanning post, thumbnail, and metadata writes. The plugin validates inputs before writing and reports individual write failures, but third-party hooks can have side effects that draft rollback does not reverse.

## WordPress updates

Version 2.6.0 adds GitHub-backed updates through WordPress's native Updates screen. Install 2.6.0 manually once if your current version is 2.5.0 or earlier; those older versions cannot discover a new updater by themselves. Subsequent stable releases appear under **Dashboard → Updates → Check again** or **Plugins → Installed Plugins**.

The updater uses the public GitHub Releases API, accepts only normal stable releases with the exact named ZIP, and downloads the package pinned to that release tag. It needs no GitHub account or token. Metadata is cached for six hours; failed requests are cached briefly. WordPress **Check again** clears the metadata cache. Network failures leave the installed plugin usable. Automatic updates remain under the administrator's control.

## Development and verification

Build the production ZIP with scripts/build.ps1 in PowerShell. Install development dependencies with npm install, then run npm test. The production ZIP excludes tests, build scripts and development dependencies.

The suite activates the exact ZIP in disposable WordPress, checks scheduling and ordinary bridge behavior, exercises updater parsing/caching/failure handling, and upgrades an isolated older-version fixture through WordPress's native Dashboard Updates bulk-upgrade API. External release transport and the Advanced Date provider are fixtures in the isolated suite; they do not prove a live production upgrade. Live native JetEngine schedule saving was verified separately before this updater release.

## Changelog

### 2.6.0
- Add public GitHub-backed WordPress updates and plugin details.
- Keep the installed folder, ability names, approved fields and saved settings unchanged.
- Add public license, WordPress readme and update-path verification.


### 2.5.0
- Save recognizable Advanced Date configs through the native JetEngine provider.
- Validate and verify complete schedules, prevent independent timestamp edits, and gate publication on consistency.
- Restore affected metadata after a schedule save failure; retain ordinary date-only behavior.
- Expose bridge version and schedule-writing guidance through tool discovery.
