# 08 — Admin UI (Settings → Claude Publishing)

One admin screen where a site administrator configures everything the connection uses, without editing JSON or code. Built with Tailwind CSS v4 (compiled at build time, scoped so it can't restyle the rest of WP Admin) and Alpine.js.

## Decisions

- **Neutral branding.** Header shows "Claude Publishing" and the site name. No agency logo or name anywhere. The support contact comes from config.
- **Built for reuse.** One UI for every client; nothing client-specific in templates. Presets give a starting config.
- **Only administrators change settings.** Users with `ccb_view_activity` but not `manage_options` see Overview and Activity, read-only. Contributors don't see the menu.

## Who sees what

| User | Menu item | Tabs |
| --- | --- | --- |
| Administrator (`manage_options`) | Yes | All, editable |
| Non-admin Publisher (`ccb_view_activity`) | Yes | Overview, Activity (read-only) |
| AI Contributor | No | — |

Register the menu with capability `ccb_view_activity` (admins have it), and check `manage_options` per tab and per REST route.

## App shell

- **Header**: "Claude Publishing" · site name · config version and who saved it · connection status pill (Connected / Warning / Problem) · help link to the support contact.
- **Left nav** (200 px): Overview, Sections, Writing style, Images and files, Access, Notifications, Activity, Setup pack, Advanced. A dot marks tabs with unsaved changes; a red dot marks tabs with validation errors.
- **Content area**: max 1200 px wide.
- **Sticky save bar**: "Unsaved changes in N sections" + Save changes (accent) + Discard. Hidden when nothing has changed.

Layout of the Sections tab: section cards on the left, a "What Claude sees" preview panel (320 px) on the right.

## Tabs

### 1. Overview
- Status cards: MCP endpoint reachable, plugin version, MCP Adapter version, ACF active, config version + who saved it, last daily health check. Each shows good / warning / problem with a one-line reason and a fix link.
- **Run checks now** (admins only): runs the health check and shows results inline.
- **This week**: posts created, published, scheduled and moved to the Bin through Claude; posts waiting in Pending Review (linked to the WP list).
- **Setup progress** (until complete): Sections → Access → Notifications → Setup pack.

### 2. Sections
One card per section; "Add section" for more.

| Control | Type | Source |
| --- | --- | --- |
| Enabled | Toggle | Config |
| Label shown to staff | Text | Config |
| Post type | Select | Live: public post types (already-used ones disabled) |
| Default terms | Searchable multi-select per taxonomy | Live terms |
| Required taxonomies | Checkboxes | Live taxonomies for the post type |
| Featured image | Required toggle, ratio select, minimum width | Config |
| Limits | Min/max words, excerpt length, max images | Config |
| Fields from ACF | Rule table: each field with Required / Optional / Only if given / Hidden | Live ACF fields; unsupported types locked to Hidden with a note |
| Extra fields | Repeater: meta key, label, type, required, choices, instructions | Config |
| Notes for Claude | Textarea | Config |
| Elementor template | Read-only: which single template covers this post type, or a warning | Live |

Collapsed cards show a one-line summary (e.g. "visits · postcode to map") and the toggle. Missing terms or fields referenced by config show an amber warning with a Remove action.

**What Claude sees** panel: the generated guide for the open section, rebuilt from the unsaved config (debounced 400 ms) via `GET /ccb/v1/preview/{section}`.

### 3. Writing style
Language; allowed headings (H2/H3 checkboxes); allowed HTML (checkbox grid); strip inline styles toggle; link rules; excerpt and SEO lengths (SEO plugin detected and shown); house style notes (textarea with markdown preview); words to avoid (tag input); sign-off text.

### 4. Images and files
Accepted image types; max upload size through Claude (MB); max longest edge (px); WebP quality slider (60–95, default 82); strip location data (on, locked, with explanation); PDF max size (MB); upload link expiry (minutes).

### 5. Access
- **Publishers**: list of users with `ccb_publish`. Administrators shown with a lock ("Admin, always a Publisher"). User picker to make someone a Publisher (switches role to `ccb_publisher`); Remove switches them back to `ccb_contributor`.
- **Contributors**: users with `ccb_contributor`; link to WP's Add New User.
- **Application Passwords**: per Publisher/Contributor, device names, created, last used, last IP, Revoke (WordPress core `WP_Application_Passwords`). Confirm dialog.
- **Limits**: requests per minute per user, live changes per user per day.

### 6. Notifications
Recipients (all Publishers toggle + extra addresses); per-event toggles (pending post, live change, removal, health failure); **Send test email**.

### 7. Activity
Table: time, user, device, action, post (linked), section, result. Filters: user, action, section, result, date range; search by post title; 50 per page; Export CSV; retention setting (admins). Read-only for everyone.

### 8. Setup pack
Current extension and skill versions; **Download setup pack** (same as the profile download, for the current user); copyable new-starter instructions.

### 9. Advanced
- Support contact: name, email, URL
- Presets: apply (diff preview first)
- Version history: last 20 saves with user, time, note; side-by-side diff; Restore
- Export / import config JSON (validated and previewed before applying)
- Debug mode (24 hours, then off; never logs credentials)
- Delete all data on uninstall (default off)
- Reset to defaults (typed confirmation)

## Design system

Clean, card-based look: white cards on WP Admin's grey background, generous spacing, one accent colour, status colours only for status. The accent follows the user's WP Admin colour scheme.

### Tokens (Tailwind theme)

| Token | Value | Use |
| --- | --- | --- |
| `accent` | `var(--wp-admin-theme-color, #2271b1)` | Primary buttons, active nav, focus rings, toggles |
| `accent-soft` | accent at 10% | Active nav background, chips |
| `ink` | `#1d2327` | Headings, body |
| `muted` | `#646970` | Help text, labels, metadata |
| `line` | `#dcdcde` | Borders, dividers |
| `surface` | `#ffffff` | Cards, panels |
| `canvas` | `#f0f0f1` | Page background, preview panel |
| `good` / `warn` / `bad` | `#00a32a` / `#dba617` / `#d63638` | Status pills, validation, alerts only |
| Font | WP Admin system stack | All text |
| Type scale | 12 / 13 / 14 / 16 / 20 / 24 px | Help, labels, body, card titles, tab titles, page title |
| Spacing | 4 px base; cards 24 px padding, 16 px gaps | |
| Radius | 8 px cards, 6 px inputs/buttons, full for pills/toggles | |
| Shadow | One soft card shadow only | |

### Components (PHP partials in `templates/admin/components/` + Alpine)

App shell · card · field row (label, control, help, inline error) · text/number/textarea/select · toggle switch · checkbox group · slider with value · tag input · searchable multi-select with chips · user picker · rule table · repeater · status pill · data table (sort, filter bar, pagination, empty state) · preview panel · confirm dialog · toast · diff view.

### Responsive

| Width | Layout |
| --- | --- |
| ≥ 1280 px | Nav, form column, preview 320 px on the right |
| 960–1279 px | Nav, form; preview under the form |
| 782–959 px | Nav becomes a horizontal scrolling tab bar |
| < 782 px | Single column, full-width controls, save bar fixed to bottom |

Light mode only, matching WP Admin.

### Accessibility (WCAG 2.2 AA)

Visible labels tied with `for`/`id`; help and errors via `aria-describedby`; 2 px accent focus ring; full keyboard use (tabs with arrow keys, dialogs trap focus, Escape closes); status never by colour alone; contrast ≥ 4.5:1 for every WP Admin colour scheme's accent; `aria-live` region for save results and validation summaries; `prefers-reduced-motion` respected.

## Front-end build

### Keeping Tailwind in its box

1. **No global Preflight.** Import only Tailwind's theme and utilities layers; apply a small reset scoped to `#ccb-app`.
2. **Prefix** every class with `ccb` (`ccb:flex`).
3. **Scope** all markup inside `<div id="ccb-app">`.
4. **Load only on our screens**: the settings page hook and the upload-link page.

```css
/* assets/src/admin.css */
@layer theme, base, components, utilities;
@import "tailwindcss/theme.css" layer(theme) prefix(ccb);
@import "tailwindcss/utilities.css" layer(utilities) prefix(ccb);

@source "../../templates";
@source "../../assets/src/js";

@theme {
  --color-accent: var(--wp-admin-theme-color, #2271b1);
  --color-ink: #1d2327;
  --color-muted: #646970;
  --color-line: #dcdcde;
  --color-canvas: #f0f0f1;
  --color-good: #00a32a;
  --color-warn: #dba617;
  --color-bad: #d63638;
  --radius-card: 8px;
}

@layer base {
  #ccb-app *, #ccb-app *::before, #ccb-app *::after { box-sizing: border-box; }
  #ccb-app input, #ccb-app select, #ccb-app textarea { box-shadow: none; }
}
```

If WP Admin's `forms.css` element selectors still win over utilities, add scoped component classes (`#ccb-app .ccb-input`) rather than making all of Tailwind `!important`. Verify the `prefix()` syntax against the Tailwind v4 version you pin.

### Interactivity

- Alpine.js 3 bundled locally (no CDN).
- One Alpine store holds the config being edited and the live site structure.
- Unsaved changes: `beforeunload` warning; tab dots.
- URL hash tracks the open tab (`#sections`).
- Initial data passed with `wp_add_inline_script( 'ccb-admin', 'window.ccbBoot = ' . wp_json_encode( … ), 'before' )`.

### Tooling

| Tool | Purpose |
| --- | --- |
| `@tailwindcss/cli` v4 (pinned) | Compile + minify `admin.css` (< 40 KB) |
| esbuild | Bundle Alpine + app JS (< 60 KB) |
| npm scripts | `dev` (watch), `build` (minified, committed to `assets/dist/`) |
| Playwright | Visual, keyboard and axe accessibility tests on every tab, across WP Admin colour schemes |

Asset URLs carry the plugin version.

## REST endpoints (`ccb/v1`)

All send `X-WP-Nonce` (`wp_rest`). All have a `permission_callback`.

| Route | Purpose | Permission |
| --- | --- | --- |
| `GET /config` | Current config + version | `manage_options` |
| `POST /config/validate` | Validate a draft; errors and warnings with field paths | `manage_options` |
| `PUT /config` | Save; body `{ config, base_version, note? }` | `manage_options` |
| `GET /config/versions` | Version list | `manage_options` |
| `GET /config/versions/{id}` | One version | `manage_options` |
| `POST /config/versions/{id}/restore` | Restore as new version | `manage_options` |
| `POST /config/import` | Validate + diff an uploaded JSON (≤ 256 KB) | `manage_options` |
| `GET /presets`, `POST /presets/{name}/preview` | List presets, diff against current | `manage_options` |
| `GET /site-structure` | Discovery result | `manage_options` |
| `POST /preview/{section}` | Guide for a draft section (body: draft config) | `manage_options` |
| `GET /status` | Health status | `ccb_view_activity` |
| `POST /status/run` | Run checks now | `manage_options` |
| `GET /activity` | Paged, filtered log; `?format=csv` | `ccb_view_activity` |
| `GET /users` | Publishers and Contributors | `manage_options` |
| `POST /users/{id}/role` | Switch Contributor ↔ Publisher | `manage_options` |
| `GET /users/{id}/app-passwords`, `DELETE /users/{id}/app-passwords/{uuid}` | List / revoke | `manage_options` |
| `POST /notifications/test` | Send test email | `manage_options` |

## Save flow

1. Edits mark the form dirty; browser validation as you type (required, ranges, formats).
2. **Save** sends the full config with `base_version`. Server validates against schema and live site.
3. Errors block the save; the save bar lists them, each linking to its field; tabs show red dots.
4. Warnings (e.g. a default term deleted in WP) need a confirm.
5. `base_version` older than live → 409; UI shows "Someone else saved changes" with a diff, Reload or Overwrite.
6. Success: version bumped, version row and audit row written, toast shown. Claude uses the new rules on its next request.

## Acceptance (UI)

- [ ] Fresh site configured for News, Insights and Visits in < 15 minutes without JSON
- [ ] Pickers show only what exists; update after a new term or ACF field is added
- [ ] "What Claude sees" updates within 1 s and matches `ccb/get-publishing-guide` after saving
- [ ] Invalid config can't be saved; each error links to its field
- [ ] Concurrent saves → second admin gets the conflict prompt
- [ ] Any of the last 20 versions can be diffed and restored; restore is logged
- [ ] Revoking an Application Password blocks that device's next request
- [ ] Non-admin Publishers see only Overview and Activity; Contributors see no menu
- [ ] Other WP Admin screens unchanged with the plugin active (Playwright screenshot diff)
- [ ] Keyboard-only and screen reader pass on every tab; contrast passes for all WP Admin colour schemes
- [ ] Works at 360, 782, 1024 and 1440 px
- [ ] CSS < 40 KB, JS < 60 KB minified
