# 11 — Testing and acceptance

## Environments

| Environment | Purpose |
| --- | --- |
| Local (`wp-env`) | Development and automated tests. WordPress 6.9+, PHP 8.1 and 8.3, ACF, Elementor + Elementor Pro (licensed copy or stubs), MP Visits 1.3 and Causeway Insights 1.0 as fixtures |
| Staging (copy of live) | Compatibility, security review, pilot |
| Live | After pilot sign-off only |

## Automated tests

### Unit (PHPUnit, no WordPress)
- Content sanitiser: allowed/disallowed tags, heading conversion, inline style stripping, image src rules, link rules
- Config schema validation: valid and invalid examples for every rule in `docs/04`
- Publishing guide generation from fixture structure + config
- Token creation, hashing, binding, expiry, single use, mismatch
- Geocoder parsing and UK bounds (HTTP mocked)
- Image rule checks (ratio tolerance, min width)
- CSV escaping

### Integration (PHPUnit with WordPress via `wp-env`)
- Activation: tables, roles, caps, default config, cron
- Each ability × each role (none, Contributor, Publisher, admin) × each relevant post status
- Contributor `create-post` with `status: publish` → stored as `pending`
- Live-change pairs: happy path, no token, expired, reused, other user's, other post, payload changed, post modified since prepare
- Terms never created; unknown term rejected; defaults applied
- ACF fields written by key on `resource`; `authors` required at publish
- `visits_location` written in the exact format the MP Visits map reads; Visit appears in `[visits_map]` output
- No `_elementor_*` meta written by any ability; Elementor-built post content edit refused
- Image pipeline: JPEG with GPS EXIF → WebP without EXIF; PNG; oversized; SVG/HEIC/polyglot rejected
- PDF via upload link sets `downloadable_file`
- Upload link: single use, expiry, wrong type
- Audit rows for every call, including refusals
- Notifications sent to the right recipients (mail mocked), not to the actor
- Restore returns a draft
- Rate limit and daily cap
- Health check: endpoint blocked simulation, stale config

### MCP end-to-end
- Start the `ccb` server in `wp-env`; connect with the pinned `@automattic/mcp-wordpress-remote` using an Application Password; list tools and confirm **only** `ccb/*` abilities are present; run a create → prepare-publish → publish flow.
- Test that every tool name referenced in the skill template exists.

### Admin UI (Playwright)
- Each tab renders; keyboard navigation; axe accessibility scan; screenshots at 360/782/1024/1440 px
- Screenshots of five other WP Admin screens with the plugin on and off are identical (style isolation)
- Save, validation errors, conflict (two contexts), restore, import, preset apply
- All eight WP Admin colour schemes: contrast check on accent elements

### CI
GitHub Actions (or equivalent): PHPCS, PHPStan, PHPUnit unit + integration, Playwright, `npm run build` with a check that `assets/dist` is up to date, `mcpb validate` on the extension.

## Compatibility checks (staging, first site)

| Area | Check |
| --- | --- |
| WordPress core | 6.9+; update path if behind |
| PHP | 8.1+; Imagick or GD with WebP |
| Elementor / Pro | Versions; single templates and conditions cover `post`, `resource`, `visits` |
| Custom post types | `resource` (Causeway Insights) and `visits` (MP Visits) registered; Insights terms exist; ACF active with "Resource Details"; `visits_location` saves correctly from the edit screen and from Claude; note PHP warnings from MP Visits' save routine |
| UiPress | New roles see a sensible admin; Publishers' pending-review view works |
| Security plugin (e.g. Wordfence) | Application Passwords and the MCP endpoint not blocked; login limits don't lock out the connection |
| 2FA plugin | Application Passwords still work |
| SEO plugin | Which plugin; title/description meta writable |
| Caching / CDN / WAF (e.g. Cloudflare) | MCP endpoint and upload links not cached or challenged; cache purge after live changes works |
| Image optimisation plugin | No double compression or conflicts with WebP output |
| Hosting | Upload size, max execution time, memory for Imagick; real cron available |
| Mail | `wp_mail` delivers (SMTP plugin configured; SPF/DKIM) |

## Acceptance criteria (project)

- [ ] A Contributor creates a post in each section; it lands as Pending Review with the correct post type, terms, featured image, fields, formatting and author
- [ ] A Contributor can't publish or schedule, even when asked directly; their edits and deletions of any post need confirmation and appear in the audit log
- [ ] A Publisher can publish, schedule, update live, unpublish and move to the Bin from Claude, each with a confirmation summary first
- [ ] An action without a valid confirmation token is refused
- [ ] A post moved to the Bin through Claude can be restored (as a draft); nothing can be permanently deleted through Claude
- [ ] Every action appears in the audit log with the right user and device name
- [ ] A 12 MB phone photo is refused with a clear message and the upload link works; a 3 MB photo becomes WebP with no EXIF/GPS
- [ ] An Insights PDF uploaded through a link is attached to `downloadable_file`
- [ ] A Visit created from a postcode appears in the right place on the `[visits_map]` map
- [ ] Changing the config in WP Admin is reflected on a second machine without reinstalling anything
- [ ] Revoking one person's Application Password blocks only that device
- [ ] The daily health check emails the support contact when the endpoint is blocked
- [ ] Publishers get an email for every pending post and every live change, and not for their own actions
- [ ] Only `ccb/*` tools are visible to Claude
- [ ] No conflicts with the theme, Elementor or existing plugins on staging and live
- [ ] All admin UI acceptance items in `docs/08` pass
