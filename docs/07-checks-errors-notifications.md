# 07 — Checks, errors and notifications

Problems are caught at three points: before Claude starts, right after each change, and daily even when nobody is using the connection. Every failure is shown to the user in plain English and logged; serious ones alert the site's support contact.

## 1. Pre-flight (`ccb/check-connection`)

Run at the start of every Claude session. Each check returns `pass`, `warning` or `error`.

| Check | Error when | Warning when |
| --- | --- | --- |
| HTTPS | Request not over HTTPS | — |
| User | No `ccb_use_connection` | — |
| Config | Fails schema validation (`ccb_config_invalid`) | References deleted terms or fields (`config_stale`) |
| Sections | No section enabled | — |
| Post types | An enabled section's post type no longer exists | — |
| ACF | A section has `field_rules` and ACF is inactive | — |
| Elementor | — | A section's post type isn't covered by a single template's conditions |
| Image support | — | No WebP support in Imagick/GD |
| Versions | — | Skill older than the bundled setup pack (`skill_outdated`); adapter version differs from pinned |
| Health | — | Last daily health check failed or is older than 48 hours |

Any `error` → `ok: false`; the skill tells Claude to stop, show the messages and the support contact, and not attempt any changes.

## 2. Post verification (after every create, update and live change)

`PostVerifier` re-reads the post from the database and returns a checklist Claude shows the user:

- Post exists; status, author, date are as expected
- Post type matches the section; terms are within the allowed taxonomies; required taxonomies set
- Required fields present and valid; `visits_location` (or other extra fields) present and well-formed
- Featured image attached where required; alt text set; files exist on disk
- Content passed sanitisation; word and image counts within limits; headings allowed
- For drafts/pending: preview URL generated
- For live changes: the public URL returns HTTP 200 after publish/update (404 or redirect after unpublish/trash), checked with `wp_remote_get()` (timeout 10 s, cache-busting query, `sslverify` on). If the site uses a page cache (WP Rocket, LiteSpeed, W3TC, Cloudflare APO), purge that URL via the cache plugin's API where available; otherwise warn that cached copies may take a few minutes to update.

Failed checks come back with a `fix` message (e.g. "Featured image missing: ask for an image or use an upload link"). Nothing is deleted on failure, so work isn't lost.

## 3. Daily health check (cron `ccb_daily_health`)

Runs daily at 03:15 site time and after any plugin, theme or core update (`upgrader_process_complete`, queued 5 minutes later).

- Runs all pre-flight checks as a system user context (no user check)
- Calls the plugin's own MCP endpoint over HTTPS from the server (unauthenticated request must return 401, not a firewall challenge page, 403 HTML, or cached response)
- Confirms the cron itself is running (records the last run; Overview tab shows it). Recommend a real server cron on hosting (compatibility check).
- Stores the result in `ccb_health`

On failure: email the support contact and all administrators, once per distinct failure per 24 hours.

## Error handling rules (what Claude and the plugin do)

| Situation | Behaviour |
| --- | --- |
| Credential revoked or wrong | 401; Claude explains the login failed and how to make a new Application Password |
| Action not allowed for the role (e.g. Contributor asks to publish) | `ccb_forbidden`; Claude offers to submit for review instead |
| Rule broken (e.g. term not allowed) | Rejected with the reason; Claude corrects and retries once |
| Confirmation token expired, used or mismatched | Refused; Claude prepares again and shows the new summary |
| Schedule time in the past | Refused; user picks another time |
| Post changed by someone else since it was read or prepared | `ccb_post_changed`; Claude reloads and shows the latest version before asking again |
| Image too large or corrupt | Rejected; Claude offers an upload link |
| Server error or timeout | No automatic retry for create, publish, schedule, update-live, unpublish or trash. Claude checks the post's status with `ccb/get-post` / `ccb/list-posts` and reports what actually happened |
| Duplicate title within 24 hours | `ccb_possible_duplicate`; user confirms before a second draft is created |
| Rate or daily cap hit | Refused with a wait time |
| Unexpected exception | `ccb_internal` with a 12-character reference ID that matches the activity row and the PHP error log entry |

Five or more `error` results across all users within an hour → email the support contact (once per hour).

## Notifications (email only)

Sent with `wp_mail()`, HTML with a plain-text alternative, neutral branding, site name in the subject. Templates in `templates/emails/`. All toggles in config `notifications.events`.

| Event | Recipients | Content |
| --- | --- | --- |
| New pending post | All Publishers (+ extra recipients) | Title, author, section, preview link, link to the WP edit screen |
| Live change (publish, schedule, update live, unpublish) | All Publishers (+ extra) | Who did it, what changed, live URL, time |
| Removal (trash) | All Publishers (+ extra) | Who, title, section, **restore link** to the post type's Bin list in WP Admin (`edit.php?post_status=trash&post_type=…`, login required), where Restore is one click. Don't put a nonce in the email; WordPress nonces expire within a day |
| Restore | All Publishers | Who, title, now a draft |
| Health failure / repeated errors | Support contact + administrators | Failing checks, suggested fixes |
| Test email | The admin who clicked | Confirms delivery |

"All Publishers" = users with `ccb_publish` (administrators + `ccb_publisher`), excluding the person who made the change. Emails are queued and sent at the end of the request (`shutdown`) so the ability response isn't delayed; failures are logged.
