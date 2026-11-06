# Compatibility report — <site name>

Fill in on the staging copy before go-live (`docs/11` "Compatibility checks"). One report per site. Keep a copy with the client's project notes.

| | |
| --- | --- |
| Site | <domain> (staging: <staging domain>) |
| Checked by / date | <name>, <date> |
| Plugin version | <Claude Publishing Bridge version> (setup pack <version>) |
| Health check | Settings → Claude Publishing → Overview → Run checks now: <Good / Warning / Problem> |

## Environment

| Area | Found | OK? | Notes / action |
| --- | --- | --- | --- |
| WordPress core | <version> (needs 6.9+) | | |
| PHP | <version> (needs 8.1+) | | |
| Image library | <Imagick / GD>, WebP <yes/no> | | Overview shows a warning if WebP is missing |
| Memory / max execution time / upload size | <values> | | Imagick needs memory for large photos |
| Real cron | <yes / WP-Cron only> | | Daily health check and clean-up need cron |
| Mail | <SMTP plugin>, SPF/DKIM <yes/no> | | Send test email from the Notifications tab |

## Plugins and theme

| Area | Found | OK? | Notes / action |
| --- | --- | --- | --- |
| Elementor / Elementor Pro | <versions> | | Single templates and conditions cover each section's post type (Sections tab shows "Shown with …") |
| Elementor template condition meta keys | <keys found> | | Confirms `docs/13` TO CONFIRM item |
| Custom post types | `resource` <yes/no>, `visits` <yes/no> | | Causeway Insights and MP Visits active |
| ACF | <version>, "Resource Details" group <yes/no> | | |
| `visits_location` | Saves from the edit screen <yes/no>; from Claude <yes/no>; on the `[visits_map]` <yes/no> | | Note any PHP warnings from MP Visits' save routine |
| SEO plugin | <SEOPress version> | | Title/description written to `_seopress_titles_title` / `_seopress_titles_desc` (check in the editor) |
| UiPress | <version / not used> | | New roles see a sensible admin; Pending Review view works |
| Security plugin | <Wordfence etc.> | | Application Passwords and `/wp-json/mcp/ccb` not blocked; login limits don't lock out the connection |
| 2FA plugin | <name> | | Application Passwords still work |
| Caching | <plugin / host cache> | | `/wp-json/mcp/` and `/ccb-upload/` excluded; purge after live changes works (checklist after publish shows "The public page is live") |
| CDN / WAF | <Cloudflare etc.> | | MCP endpoint not cached or challenged; upload links not cached |
| Image optimisation plugin | <name / none> | | No double compression or WebP conflicts |

## End-to-end checks on staging

| Check | Result |
| --- | --- |
| Contributor creates a post in each section from Claude; it lands as Pending Review with the right type, terms, image, fields and author | |
| Publisher publishes, schedules, updates live, unpublishes and moves to the Bin, each after a summary | |
| A Bin item is restored as a draft | |
| 12 MB photo refused with an upload link offered; 3 MB photo becomes WebP with no EXIF/GPS | |
| Insights PDF via upload link appears in "Downloadable file" | |
| Visit from a postcode appears in the right place on the map | |
| Revoking one Application Password blocks only that device | |
| Publishers get emails for pending posts and live changes, not for their own | |

## Issues and decisions

| Issue | Decision / fix | Owner |
| --- | --- | --- |
| | | |
