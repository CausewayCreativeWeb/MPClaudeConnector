# 02 — Roles and permissions

## Principles

- One WordPress account per person, one Application Password per device. No shared accounts.
- Two roles for people who use Claude. Administrators are Publishers automatically.
- WordPress capabilities decide what a user can do; every ability checks again server-side, so a misconfigured role can't widen Claude's reach.

## Custom capabilities

| Capability | Meaning |
| --- | --- |
| `ccb_use_connection` | May call any `ccb/*` ability |
| `ccb_publish` | May publish and schedule through Claude |
| `ccb_view_activity` | May view the Overview and Activity tabs (read-only) |

## Roles

Created on activation, updated on plugin upgrade (idempotent).

### `ccb_contributor` — "AI Contributor"

Built from the Editor role with publishing removed.

| Keep | Remove |
| --- | --- |
| `read`, `upload_files`, `edit_posts`, `edit_others_posts`, `edit_published_posts`, `edit_private_posts`, `delete_posts`, `delete_others_posts`, `delete_published_posts`, `delete_private_posts`, `read_private_posts`, `ccb_use_connection` | `publish_posts`, `publish_pages`, all page caps, `moderate_comments`, `unfiltered_html`, `manage_categories`, `manage_links` |

`manage_categories` is removed so Contributors can't create terms in WP Admin either.

### `ccb_publisher` — "AI Publisher"

Contributor capabilities **plus** `publish_posts`, `ccb_publish`, `ccb_view_activity`.

### Administrators

On activation and on every request (`user_has_cap` filter) administrators get `ccb_use_connection`, `ccb_publish` and `ccb_view_activity`. They can't be removed from the Publisher list in the admin UI.

### Selecting other Publishers

An admin switches a user between `ccb_contributor` and `ccb_publisher` from the Access tab (user picker) or the user's profile. Each change is audit-logged.

All three sections' post types use `capability_type => post`, so the post caps above apply to them. If a future post type uses its own capability type, `SiteStructure` must report the mapped caps and the role manager must grant the equivalents (**flag in the admin UI** if they're missing).

## What each role can do through Claude

| Action | AI Contributor | AI Publisher (admins + selected) |
| --- | --- | --- |
| Create posts | Yes, forced to `pending` | Yes, `draft` or `pending` |
| Edit any unpublished post | Yes | Yes |
| Upload images and files | Yes (restricted types and size) | Yes (restricted types and size) |
| Publish now | No | Yes, with confirmation |
| Schedule or reschedule | No | Yes, with confirmation |
| Update a published post | Yes, with confirmation | Yes, with confirmation |
| Unpublish (back to draft) | Yes, with confirmation | Yes, with confirmation |
| Move any post to the Bin | Yes, with confirmation | Yes, with confirmation |
| Restore from the Bin | Yes, always restored as a draft | Yes, always restored as a draft |
| Empty Bin, permanent delete, delete media | No (WP Admin only) | No (WP Admin only) |
| Pages, Elementor, menus, settings, plugins, users | No | No |
| Change Bridge settings | No | No (administrators only, in WP Admin) |

Contributors' and Publishers' rights apply in WP Admin too, so manual edits are covered by WordPress revisions and Claude edits by the audit log.

## Server-side enforcement (every ability)

Each ability's `permission_callback` and `execute_callback` must:

1. Require an authenticated user with `ccb_use_connection` (and `ccb_publish` where stated).
2. Require HTTPS (`is_ssl()`); refuse otherwise.
3. Check the rate limit and, for live changes, the daily cap.
4. Validate input against the ability's JSON schema.
5. Check the post (if any) belongs to an **enabled section's post type**. Anything else → `ccb_post_type_not_allowed`.
6. Apply config rules (allowed terms, required fields, formatting) — see `docs/04`, `docs/05`.
7. For live changes, verify and consume the confirmation token.
8. Write an audit row (success, rejected or error).

## Confirmation tokens

Used by every live change: publish, schedule, update a published or scheduled post, unpublish, and move to the Bin. Restore needs no token because a restored post always comes back as a draft (never straight to live).

### Flow

1. Claude calls `ccb/prepare-<action>` with the post ID (and new content, or a schedule time, where relevant).
2. The plugin validates everything the real action would validate and returns a human-readable **summary** plus a **token**.
3. Claude shows the summary and waits for the person to reply with an explicit yes.
4. Claude calls the action with the token. The plugin verifies and consumes the token, then acts.

### Token rules

| Rule | Detail |
| --- | --- |
| Format | 32 random bytes, base64url; only a SHA-256 hash is stored |
| Storage | Table `ccb_tokens` (see `docs/06`) |
| Bound to | user ID, post ID, action, hash of the pending change (content hash or schedule time), and the post's `post_modified_gmt` at prepare time |
| Lifetime | 10 minutes |
| Use | Single use; consumed on the first verify, even if the action then fails validation |
| Staleness | If the post changed since prepare (`post_modified_gmt` differs) → `ccb_post_changed`; Claude must prepare again and show the new summary |
| Payload tamper | If the content or schedule time sent with the action differs from what was prepared → `ccb_token_mismatch` |

### Summary contents

- Action in plain words ("Publish now", "Schedule for Tue 18 Nov 2026, 09:00 UK time", "Move to the Bin")
- Title, section, author, current status → new status
- Live URL (or preview URL)
- For updates to a live post: a short list of what changes (title, excerpt, terms, fields, featured image, body word count before → after)
- For trash: "Can be restored for 30 days"

## Limits

| Limit | Default | Configurable in |
| --- | --- | --- |
| Requests per user per minute | 30 | Access tab |
| Live changes per user per day | 20 | Access tab |
| Upload links per user per hour | 10 | Fixed |
| Posts per action | 1 (no bulk) | Fixed |

Rate limiting uses the object cache when available, otherwise transients, keyed by user ID. Over the limit → `ccb_rate_limited` with `retry_after` seconds.

## Authentication

- WordPress Application Passwords, one per device, named after the device (e.g. "Sarah – office laptop").
- The audit log records the Application Password name and UUID (from `rest_get_authenticated_app_password()` or the equivalent for the adapter's transport; **TO CONFIRM**).
- Revoking one password blocks only that device.
- HTTPS only.
- Browser 2FA stays on for normal logins; Application Passwords must keep working alongside the site's 2FA plugin (compatibility check).
