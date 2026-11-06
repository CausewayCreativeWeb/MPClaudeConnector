# 13 — Build plan

How the specs in `docs/00`–`12` turn into code. This adds sequencing, tooling and exit criteria; it changes no decisions. If this file and another spec disagree, the other spec wins.

## Status

| Item | State |
| --- | --- |
| Specs | In repo (`CLAUDE.md`, `docs/`) |
| Code | **M1–M9 done**: all 21 abilities in `docs/03`, live changes with confirmation tokens, emails, health checks, the Settings → Claude Publishing screen and the setup pack. Next: M10 (hardening) |
| Pinned versions (latest at planning, 2026-11-06) | `wordpress/mcp-adapter` **0.7.1**, `@automattic/mcp-wordpress-remote` **0.5.5** |

## 0. Before writing code: resolve TO CONFIRM items

| Item | Spec | Source | How to resolve |
| --- | --- | --- | --- |
| Custom MCP server creation; hiding the default server and its `discover-abilities` / `execute-ability` tools; adapter init hook | `01`, `03` | `wordpress/mcp-adapter` 0.7.1 `docs/` | **Resolved in M3**, see `docs/reference/mcp-adapter-notes.md` |
| Ability meta keys for `readonly` / `destructive` / `idempotent` annotations; MCP resources support (`ccb://publishing-guide`) | `03` | Adapter 0.7.1 | **Resolved in M3** (as above) |
| Application Password name/UUID lookup under the adapter's transport | `02` | Adapter + core | **Resolved in M3**: works; verified over real HTTP in the smoke test |
| Abilities API hook names (expected `wp_abilities_api_categories_init`, `wp_abilities_api_init`) | `01` | WP 6.9 core | **Resolved in M3**: confirmed |
| `visits_location` JSON shape and the postcode service the admin map uses | `05`, reference | `visits-admin-map.js` in MP Visits 1.3 | **Resolved** from client-supplied source: `{"lat":<number>,"lng":<number>}`; postcodes.io with the compact postcode. See `docs/reference/mp-visits-findings.md` |
| Elementor Pro single-template condition meta keys | `04` | Site's Elementor Pro version | Site audit |
| SEO plugin in use | `05` | Client | **Resolved:** SEOPress on all Causeway sites. Writes go to `_seopress_titles_title` and `_seopress_titles_desc` (per `docs/05`); still confirm the keys against the installed SEOPress version during the site audit |
| MCPB `manifest_version` and fields | `10` | `@anthropic-ai/mcpb` | `mcpb init` / `mcpb validate` against the current CLI |
| Proxy env var names; turning off OAuth discovery | `10` | `@automattic/mcp-wordpress-remote` 0.5.5 | Package README and source |
| Tailwind v4 `prefix()` import syntax | `08` | Pinned `@tailwindcss/cli` | Compile a test file |

## 1. Tooling baseline (delivered with M1)

### PHP
- `composer.json`
  - require: `wordpress/mcp-adapter` `0.7.1` (exact), `opis/json-schema` (exact), `yahnis-elsts/plugin-update-checker` v5
  - require-dev: `brianhenryie/strauss` (exact), `phpunit/phpunit`, `yoast/phpunit-polyfills`, `wp-phpunit/wp-phpunit`, `brain/monkey`, `wp-coding-standards/wpcs`, `phpstan/phpstan`, `szepeviktor/phpstan-wordpress`, `php-stubs/acf-pro-stubs`
  - PSR-4 `Causeway\ClaudeBridge\` → `src/`
  - scripts: `test` (unit + integration), `test:unit`, `test:integration`, `lint` (PHPCS + PHPStan), `prefix-namespaces` (Strauss; runs on `post-install-cmd` and `post-update-cmd`)
- `phpcs.xml.dist` (WordPress standard, `ccb` prefixes, text domain), `phpstan.neon.dist` (level 6, WP + ACF stubs)
- Strict types in every new file.

### JS / CSS
- `package.json` with exact versions: `@tailwindcss/cli` v4, `esbuild`, `alpinejs` 3, `@playwright/test`, `@axe-core/playwright`, `@wordpress/env`, `@anthropic-ai/mcpb`
- Scripts: `dev`, `build` (to committed `assets/dist/`)

### Test environments
| Environment | Use |
| --- | --- |
| `wp-env` (`.wp-env.json`) | Developer machines: a browsable local site with the plugin mounted. ACF, Elementor and fixtures are added as later milestones need them |
| `bin/install-wp-tests.sh` | Integration tests everywhere. Clones WordPress core and the matching `wordpress-develop` test library from GitHub (wordpress.org is unreachable from cloud sessions). `WP_TESTS_DB=sqlite` (default) uses the SQLite drop-in, so it needs no database server; `WP_TESTS_DB=mysql` is what CI uses against MySQL 8 |

MySQL-specific behaviour (`dbDelta` index syntax, `VARBINARY` IP column, `LONGTEXT`) is confirmed in CI on MySQL; SQLite runs are a fast local check, not the final word.

### Fixtures (`tests/fixtures/mu-plugins/`)
Replicas, from `docs/reference/existing-plugins.md`, of:
- `visits` CPT (not in REST, `capability_type` post, `map-thumb` size, `save_visits_meta()` and `[visits_map]` replicated as described in `docs/reference/mp-visits-findings.md`)
- `resource` CPT with `resource_category` and `content-type` taxonomies and the local ACF group "Resource Details" (`downloadable_file`, `online_reader_url`, `authors`, same field keys)
- Seed terms (`news` category, Insights categories and types)

These are test doubles only; the real plugins are never modified (D9).

### CI (`.github/workflows/ci.yml`)
PHPCS, PHPStan, PHPUnit unit (PHP 8.1, 8.3), PHPUnit integration on MySQL 8 (WP 6.9.11 on PHP 8.1 and 8.3; WP 7.1.4 on PHP 8.3), Playwright (from M8), `npm run build` with a check that `assets/dist` is unchanged, `mcpb validate`, and the release-zip smoke test below.

## 1a. Packaging and distribution (standalone plugin)

The Bridge must install and run as one self-contained plugin: upload the zip, activate, configure. Nothing else to install on the server, and no Composer, Node or build step on the host.

### Runtime requirements
| Requirement | Kind |
| --- | --- |
| WordPress ≥ 6.9 (Abilities API in core), PHP ≥ 8.1 | Hard. Activation refused with a clear message otherwise |
| Imagick, or GD with WebP | Soft. Pre-flight warning; pipeline keeps JPEG/PNG |
| ACF | Only needed when a section has ACF `field_rules`. Pre-flight error only in that case |
| Elementor / Elementor Pro, SEOPress, MP Visits, Causeway Insights | Optional. Detected live; never required for the plugin to load |

Every integration with another plugin is guarded (`function_exists` / `class_exists` / `is_plugin_active` checks at call time), so deactivating ACF, Elementor or SEOPress never causes a fatal error.

### Bundled dependencies, prefixed with Strauss (required)
Another plugin on a client site may load its own copy of the MCP Adapter, Opis JSON Schema or plugin-update-checker, including the standalone MCP Adapter plugin. Two copies of the same classes cause fatal errors or silently use the wrong version. So:

- All runtime Composer dependencies are copied by Strauss into `vendor-prefixed/` under the namespace `Causeway\ClaudeBridge\Vendor\` (classmap prefix `CCB_Vendor_`, constant prefix `CCB_VENDOR_`). The plugin only loads `vendor-prefixed/autoload.php` and its own PSR-4 autoloader, and never the unprefixed `vendor/autoload.php` at runtime.
- `vendor-prefixed/` is built during release, not committed.
- **TO CONFIRM in M3:** prefixing renames classes, not the adapter's WordPress hook names, REST route namespace or default-server ID. Read the pinned adapter source for global hooks, options and routes it registers, and check that two copies coexisting (ours prefixed, plus the standalone adapter plugin) don't double-register routes or expose the default server through our copy. If they clash, override the hook prefix through the adapter's configuration if it offers one, or patch the prefixed copy at build time with a documented, version-pinned patch.
- If `vendor-prefixed/autoload.php` is missing (e.g. a git checkout without a build), show an admin notice and don't boot, instead of a fatal error.

### Release build (`bin/build-release.sh`, run in CI on tags)
1. `composer install --no-dev --optimize-autoloader`, which also runs Strauss.
2. `npm ci && npm run build` (admin CSS and JS into `assets/dist/`).
3. `npm ci --omit=dev` in `setup-pack/extension/server/` so the pinned `@automattic/mcp-wordpress-remote` is bundled for the Claude Desktop extension (`docs/10`).
4. Copy into `build/causeway-claude-bridge/` using `.distignore`. Excluded: `tests/`, `docs/`, root `node_modules/`, `assets/src/`, `bin/`, dev configs (`phpcs.xml.dist`, `phpstan.neon.dist`, `.wp-env.json`, `package*.json`, `composer.*`), the unprefixed `vendor/`, and `.git*`.
5. Zip as `causeway-claude-bridge-<version>.zip` with the folder `causeway-claude-bridge/` at its root.
6. Update metadata: the update server's `?action=get_metadata&slug=…` URL is the standard WP Update Server API, which builds the metadata itself from the uploaded zip's plugin header and `readme.txt` (requirements, changelog). So the release carries a `readme.txt` and no separate JSON. **Confirm on the first release** that the server behaves this way; if it doesn't, add a metadata step here. Distribution is by **manual upload** to `repo.causewaycreative.co.uk` (confirmed 2026-11-06): CI keeps the zip as a build artifact, and a person uploads it. Release checklist in `RELEASING.md` (M10): bump version, tag, wait for green CI and smoke test, download the artifacts, upload to the update server, then confirm a staging site sees the update.

The version number lives in the plugin header; the build fails if the `CCB_VERSION` constant or the `readme.txt` stable tag disagree (setup pack version added in M9).

### Release-zip smoke test (CI)
On a clean WordPress with **no other plugins**:
- Install the built zip with WP-CLI and activate it, on PHP 8.1 and 8.3. No PHP warnings or notices from the Bridge.
- Tables, roles and default config exist. An unauthenticated request to the `ccb` MCP endpoint returns 401.
- With an Application Password, connect via the bundled proxy: only `ccb/*` tools are listed; `check-connection` returns `ok: false` with a "no section enabled" error (expected on an unconfigured site), not a crash.
- Repeat with the **standalone MCP Adapter plugin** also active: both still load, and only `ccb/*` tools are on our server.
- Repeat with ACF, Elementor and SEOPress active, then deactivated: no fatal errors.
- On WordPress 6.8: activation is refused with the version message.
- Deactivate, then uninstall with "Delete all data" on: tables, options and roles removed; posts untouched.

## 2. Milestones

Same order as `docs/12`. Each ends with passing tests and a commit.

### M1 decisions and findings (2026-11-06)

Gaps the specs don't cover, filled during M1. None changes a decision in `docs/00`.

| Topic | What was done | Why |
| --- | --- | --- |
| Upgrade detection | New option `ccb_installed_version` (autoloaded). Setup re-runs on `init` when it differs from `CCB_VERSION` | `docs/06` says roles are "updated on plugin upgrade", and activation hooks don't fire on updates |
| "Delete all data on uninstall" | Stored as option `ccb_delete_data_on_uninstall` (default off), not in the config | Kept out of exported/imported configs so an import can't switch on data deletion |
| Default support contact | Defaults to the site's admin email; name and URL empty | `support.email` is required, and activation must store a valid default config |
| Numeric ranges not in `docs/04` | `max_edge_px` 800–6000, `min_width` 0–6000, word limits 0–20000, `excerpt_max_chars` 0–1000, `max_images` 0–50, SEO title 10–200 and description 50–400, label/notes/house style lengths as specified | Every number needs a bound; adjust if the client wants others |
| Config map encoding | Empty maps (`sections`, `default_terms`, `field_rules`, `extra_fields`) are stored as JSON objects (`{}`) | PHP can't tell an empty list from an empty map; the schema expects objects |
| Version numbering | The next config version continues from the history table as well as the option | Avoids a unique-key clash if the option is lost while history remains |
| Role names | Stored untranslated ("AI Contributor", "AI Publisher") | Same as core roles; translated when displayed (M8) |
| Bridge-only users | `user_has_cap` strips forbidden capabilities (e.g. `unfiltered_html`, `manage_categories`) from users whose only roles are Bridge roles, even if another plugin adds them | `docs/09`: Bridge roles must never hold them |
| Network activation | Refused with a message; activate per site | Multisite isn't in scope |
| Activation on WordPress < 6.9 | WordPress core refuses first, from the `Requires at least` header; the plugin's own check (WP version, Abilities API, bundled libraries present) is the second line | Verified by the smoke test on 6.8.12 |
| Uninstall role mapping | As `docs/06` says: AI Contributor → Contributor, AI Publisher → **Editor** | Editor has more rights than AI Publisher (e.g. `unfiltered_html`, pages); the client confirmed Editor on 2026-11-06 |
| Bundled update checker | `plugin-update-checker` is copied into `vendor-prefixed/` but not namespace-prefixed | It's built to coexist with other plugins' copies (versioned namespaces), and the other MP plugins bundle it too |
| PHPStan | Kept in `tools/phpstan/` with its own `composer.json` | It ships only as a GitHub zip, which cloud sessions can't download; CI installs it normally |
| `wp-phpunit/wp-phpunit` | Not used; the test library is cloned from `wordpress-develop` at the same tag as core | Lets tests run against any WordPress version (6.9.11 and 7.1.4 locally) |

### M9 decisions and findings (2026-11-06)

| Topic | What was done | Why |
| --- | --- | --- |
| Proxy (TO CONFIRM, `docs/10`) | `@automattic/mcp-wordpress-remote` **0.5.5** reads `WP_API_URL` (full endpoint URL), `WP_API_USERNAME`, `WP_API_PASSWORD`; `OAUTH_ENABLED=false` turns OAuth off (also its default). Confirmed end to end: the proxy lists exactly the 21 `ccb-*` tools from `/wp-json/mcp/ccb` and `ccb-check-connection` succeeds with an Application Password | Package README and a live run (browser test below) |
| Endpoint URL (TO CONFIRM) | `ServerRegistrar::url()`, i.e. `rest_url( 'mcp/ccb' )` (`?rest_route=/mcp/ccb` on sites without pretty permalinks) | Adapter notes, M3 |
| MCPB manifest (TO CONFIRM) | `manifest_version` **0.3**, validated with `@anthropic-ai/mcpb` **2.1.2** (`mcpb validate`; 0.4 only adds the `uv` server type). The schema requires a `description` on every `user_config` field, so the username field has one. Added `compatibility` (macOS, Windows; Node ≥ 18) and `license` | Schema shipped in the CLI |
| Bundling the proxy | Not `server/node_modules` (49 MB with every dependency): `bin/build-extension.mjs` bundles the entry point and the proxy into one file with esbuild (2.9 MB, ~0.9 MB zipped) and writes the licences of all 24 bundled packages to `server/THIRD-PARTY-LICENSES.txt`. Built by `npm run build:extension` and by `bin/build-release.sh`; not committed | Keeps the plugin zip at ~2 MB |
| Node runtime on staff machines (TO CONFIRM) | Still to check in the pilot on macOS and Windows: that Claude Desktop runs the Node extension with its built-in runtime | Can't be tested here |
| Pack contents | `setup-pack-<slug>-<version>.zip` with `<slug>-publishing.mcpb`, `<slug>-publishing-skill.zip` (folder with `SKILL.md`) and `how-to.pdf`. Slug from the site name (`sanitize_title`, 40 chars). No credentials anywhere | `docs/10` layout |
| Skill | Template in `setup-pack/skill/SKILL.md` uses the exact MCP tool names (`ccb-check-connection`…); a test checks every name in the skill exists on the server **and** every server tool is mentioned. YAML `description` is quoted (it contains ": "). Site values are stripped of markup, quotes and newlines | `docs/10` "add a test that every tool name in the skill exists" |
| Versioning | `SetupPackBuilder::VERSION` (0.1.0) is the extension and skill version; bump it when `setup-pack/` or the skill/how-to text changes. Stored in `ccb_setup_pack_version` on install/upgrade; `check-connection` reports `skill_outdated` when Claude passes an older `skill_version` | `docs/10` "New plugin version" row |
| How-to PDF | Generated by a small built-in writer (`SimplePdf`: A4, Helvetica, WinAnsi text, wrapping, multi-page) instead of a PDF library | One page of text doesn't justify a dependency |
| Download | `admin-post.php?action=ccb_setup_pack` with a nonce and `ccb_use_connection`; on the user's own profile page and the Setup pack tab; audit action `setup_pack_download`; marks the setup step done | `docs/10` "Each download is audit-logged" (action name added to the `docs/06` list in code) |
| Tests | PHP: pack contents, manifest values and schema (Opis against the CLI's schema), skill ↔ server tool names, outdated skill, nonce/capability, profile row, PDF encoding and xref. Browser: download from the profile, `mcpb validate`, unzip, run the bundled proxy with the manifest's command and env against the site. Smoke test builds the pack from the release zip | `docs/12` M9 exit criteria (manual install on macOS/Windows still open) |

### M8 decisions and findings (2026-11-06)

| Topic | What was done | Why |
| --- | --- | --- |
| REST | `ccb/v1` as in `docs/08`, plus `GET /config/defaults` (reset), `GET/POST /settings` (debug mode, delete data on uninstall). Every route has a permission callback; cookie requests need the `wp_rest` nonce | `docs/08` table; the extra routes back controls `docs/08` lists under Advanced |
| Saves | Full config + `base_version`; warnings need `accept_warnings`; a stale `base_version` returns 409 with the live config and a diff (Reload or Overwrite). Saves, restores, imports and presets each write an audit row (`config_save`, `config_restore`, `config_import`, `preset_apply`) | `docs/08` "Save flow", `docs/06` actions |
| Missing terms on save | Still an **error** (can't save), as `docs/04` says. The Sections card shows "Out of date" with a Remove action for terms and fields that have gone. `docs/08`'s "a default term deleted in WP" example of a warning is read as the stored-config case | `docs/04` is the specific rule; M2 decision |
| Import | `POST /config/import` only validates and diffs (≤ 256 KB); applying is a normal save with `source: import`. Bookkeeping keys (`version`, `saved_by`…) come from the live config | Nothing changes before the admin has seen the diff |
| Access | Picker offers AI Contributors only; Publisher ↔ Contributor switches only between the two Bridge roles (never demotes an Editor or admin). Revoking uses core `WP_Application_Passwords`; both are audited (`role_change`, `app_password_revoke`) | `docs/02` "Roles"; non-negotiable 5 spirit (no user management beyond this) |
| Profile toggle | "Publisher" checkbox on AI Contributor/Publisher profiles, admins only; applied on `profile_update` after WordPress saves the form, and only when the box was changed, so the Role field can't undo it | `docs/01` hooks, `docs/02` |
| Test email | Sent to the admin who clicks it, not to every recipient; marks the "Notifications" setup step done (option `ccb_onboarding`) | Avoids emailing all Publishers from a settings test |
| Debug mode | Stored as `ccb_debug_until` (24 h). The logging itself lands in M10 (hardening) | `docs/06` option; `docs/09` debug rules |
| Tailwind | v4.3.5, `prefix(ccb)`, no Preflight. Utilities are emitted **unlayered**: WP Admin's CSS is unlayered, and unlayered rules beat every layered rule whatever the specificity, so layered utilities lost to WP's `ol`, `p` and `h1` rules. Element resets use `:where(#ccb-app)` so utilities still win; form controls use `#ccb-app .ccb-*` | `docs/08` allowed scoped component classes; nothing is `!important` |
| Accent colour | `color-mix(var(--wp-admin-theme-color) 78%, black)`: several schemes' accents (e.g. `#007cba`, `#cf4339`) are under 4.5:1 as text or under white text | `docs/08` contrast rule; tested on all 9 schemes |
| Alpine | **3.15.12**, not 3.17.x: 3.17 alone is 46 KB minified, which with the app breaks the 60 KB budget. JS is 59.8 KB (KB = 1024 bytes) and has little headroom; `bin/check-asset-sizes.sh` enforces both budgets in CI | `docs/08` budgets |
| Alpine gotcha | An expression that evaluates to a function is called (`x-show="dialog.onConfirm"` ran the overwrite). Use `!!` when testing for a function | Found by the conflict test |
| Browser tests | `bin/e2e-server.sh` builds a SQLite site (fixtures, ACF, MP preset, test users) and serves it with `php -S`; Playwright 1.63.0 + axe 4.14: axe (WCAG 2.2 AA) on every tab, keyboard tabs, save/conflict/error-link/restore flows, roles and revoke, Publisher/Contributor visibility, assets only on this screen, 360–1440 px, contrast on 9 schemes. CI job "Admin UI" also checks the committed build is current | `docs/08` acceptance; screenshot diffs of other screens replaced by "our CSS/JS isn't loaded there" |
| PHPCS cache | Removed: a stale cache hid findings locally that CI caught on the M7 push | Lint must match CI |

### M7 decisions and findings (2026-11-06)

| Topic | What was done | Why |
| --- | --- | --- |
| Email timing | Emails are queued during the request and sent on `shutdown`; HTML with a plain-text alternative, site name in the subject, support contact in the footer | `docs/07`: an ability's answer isn't held up by mail |
| Recipients | Publishers (and admins where `docs/07` says so) plus the configured extra addresses; the person who made the change is never emailed about it | `docs/07` "Notifications" |
| Bin email | Links to the Bin list for that post type, never a one-click restore or a nonce URL | Email links outlive nonces and shouldn't act on a click |
| Live URL check | After publish, update-live, unpublish and Bin the public URL is fetched once (`ccb_check` cache-buster, no redirects, 10 s). Publish expects 200; removal accepts 404, 410 or a redirect. Failing to reach the site is reported, never an error | `docs/07` post verification. Cache purge for WP Rocket, W3 Total Cache and LiteSpeed, plus `ccb_purge_post_cache` for others |
| Health check | Daily (03:15 site time) and 5 minutes after any plugin/theme/core update: pre-flight checks plus a probe of `/wp-json/mcp/ccb` that must answer 401 JSON (anything else: unreachable, cached, open or blocked by a firewall) | `docs/07` "Daily health check" |
| Health alerts | Support contact and admins, once per problem per 24 hours; `check-connection` warns when the last check failed or is more than 2 days old | Avoids daily repeat emails for one problem |
| Error bursts | 5 or more errors across all users in an hour email the support contact, at most hourly (uses the `health_failure` switch) | `docs/07` |
| Clean-up | Daily (03:45): activity older than the retention period, tokens and upload links expired over a day ago, idempotency keys over a day old, temp files over an hour old | `docs/06` retention |
| Tests | Integration tests answer outbound HTTP with a double (`ccb_check` URLs reflect the post's status), so nothing reaches the network | CI and cloud sessions have no outbound access to the test site |

### M6 decisions and findings (2026-11-06)

| Topic | What was done | Why |
| --- | --- | --- |
| Token order | The action loads the post, then uses up the token (atomically), then re-runs every check against the post as it is now, then acts | `docs/02`: "consumed on the first verify, even if the action then fails validation" |
| Token error codes | Missing, unknown, expired, used, someone else's, other post or other action → `ccb_token_invalid`; post modified since prepare → `ccb_post_changed`; different change sent → `ccb_token_mismatch` | `docs/02` token rules |
| Payload bound to the token | publish/unpublish/trash: nothing extra; schedule: the publish time (as a timestamp, so equivalent formats match); update-live: the `changes` object with keys sorted | `docs/02` "hash of the pending change" |
| Ready to go live | Publish and schedule need: required terms, required fields, featured image (when required) with alt text, and not over the maximum words. Under the minimum stays a warning | `docs/03` "never bypasses a rule that create only warned about"; `docs/05` minimum is a warning |
| Elementor-built posts | `update-live` refuses content changes only; title, terms, fields and image can change | `docs/05` is explicit; `docs/03`'s one-line "refused on Elementor-built posts" read as the same rule as `update-post` |
| Publish date | Publishing sets the date to now | A draft's old date (or a future one) would otherwise be kept |
| Schedule times | Without an offset they're UK time (`Europe/London`), as `docs/03` says, whatever the site's timezone; the summary shows site time | `docs/03` "Dates". Worth revisiting for a non-UK client |
| Bin switched off | If `EMPTY_TRASH_DAYS` is 0, moving to the Bin is refused | WordPress would delete permanently instead (non-negotiable 5) |
| Daily cap | Checked at prepare and at the action; counted when an action succeeds. Restore doesn't count | `docs/02` limits |
| Restore | Needs `delete_post` on the post; the status is forced to draft for that call | `docs/03` |
| Summary | Also includes the author; notes say "Publishers will be emailed" only when notifications are switched on for that event | `docs/02` summary contents; emails come in M7 |
| Hooks for M7 | `ccb_live_change` (action, post, summary) and `ccb_post_restored` | Notifications |

### M5 decisions and findings (2026-11-06)

| Topic | What was done | Why |
| --- | --- | --- |
| Photo orientation | The image is turned upright from its EXIF orientation **before** metadata is stripped | Otherwise portrait phone photos come out sideways once EXIF is gone. Not in `docs/05` |
| Editor choice | GD is preferred (re-encoding always drops metadata). Imagick is used only when GD can't, via a subclass that calls `stripImage()` on the main image | WordPress's Imagick editor strips thumbnails only. The Imagick path isn't exercised here (no extension in this container) |
| Metadata check | After processing, the output is scanned for EXIF, XMP and GPS (JPEG, WebP chunks, PNG chunks); if any remains the upload is refused | `docs/05` step 5 "verify no EXIF/GPS remains", made a hard stop |
| WebP or original | WebP unless it's bigger than the stripped original (flat graphics often are). New filter `ccb_webp_supported` lets a host switch WebP off | `docs/05` step 5 |
| Alt text on upload-link images | The page asks for a short image description (required for images) | Verification fails without alt text; `docs/05` doesn't say how links get it |
| Upload-link page | HTTPS only; `noindex`; `no-store`; `Referrer-Policy: no-referrer` (the token is in the URL); `X-Frame-Options: DENY`; a strict Content Security Policy with per-request nonces for the inline style and script; works without JavaScript | `docs/05`, `docs/09` |
| Who an upload-link upload acts as | The link's owner, for that request only (attachment author and activity row) | The token is the authorisation (`docs/05`) |
| URLs | `/ccb-upload/<token>/` with pretty permalinks, `/?ccb_upload=<token>` otherwise; always https | `docs/05` |
| Admin media functions | `media_handle_sideload()` and friends are loaded explicitly | **Bug caught by the smoke test**: they aren't loaded on REST or front-end requests, so uploads crashed outside the test suite (which loads them) |
| Content images | Uploaded with `upload-image` (no `set_featured`) and attached to the post; Claude puts the returned URL in the content | `docs/03` |
| HEIC | Refused with a hint for iPhone users (export as JPEG or set Camera → Formats → Most Compatible) | `docs/05` refuses HEIC |

### M4 decisions and findings (2026-11-06)

| Topic | What was done | Why |
| --- | --- | --- |
| Required fields at create | Not refused: the post is saved and verification flags what's missing (fields, terms, featured image). Enforced at publish/schedule (M6) | `docs/05` "create allows missing with a failed verification check", and `docs/03` rule 6 "so work isn't lost". `docs/03` rule 5's "required fields enforced" read as at publish |
| `status` input | Schema accepts `draft`, `pending`, `publish`, `future`. AI Contributors always get `pending`, whatever is sent; AI Publishers get `ccb_wrong_status` for `publish`/`future` | `docs/11`: "Contributor create-post with status: publish → stored as pending" (a schema enum would have rejected it instead) |
| Featured image | Missing when required: post saved, verification fails. Present but too small or the wrong shape (±3%): refused with `ccb_field_invalid` | `docs/03` rule 6 and `docs/05` image rules |
| Terms | Accepted as IDs, slugs, or (extra) exact names. Unknown → `ccb_term_unknown`; only integer IDs reach `wp_set_object_terms()`, so nothing is ever created. Update replaces terms only for the taxonomies sent | Non-negotiable 7 |
| Titles | Plain text (tags stripped), up to 200 characters | MP Visits prints titles unescaped (`docs/reference/mp-visits-findings.md`) |
| Unsafe links | `javascript:`, `data:` and similar links are unwrapped (text kept) **before** `wp_kses()`, which would otherwise leave a meaningless relative link | Found by the sanitiser tests |
| Word count | `str_word_count()` of the text without tags, with a space added after block elements first | The literal formula joins words across tags ("words" + "and" → "wordsand") |
| Excerpt, SEO lengths | Too long → shortened at a word boundary with a warning (both) | `docs/05` says so for the excerpt; SEO follows the same rule |
| Duplicate check | Same post type and exact title with `post_date` in the last 24 hours (drafts have no GMT date), any status except Bin | `docs/03` rule 8 |
| MP Visits save routine | Runs on every save (its `$_POST` reads raise PHP warnings on the real site). `visits_location` is written after the save, so the routine never sees or deletes it. The test double counts the warnings instead of raising them | `docs/05`; compatibility report item |
| `page_link` / `post_object` | Stored as a post ID; `page_link` also accepts a URL on this site | `docs/05` table |
| Hooks for later | `ccb_post_created` and `ccb_post_updated` actions fire after successful writes | Notifications (M7) |
| Content images | Must already be in the Media Library; the 800px minimum and the image pipeline come with M5 | `docs/05` |

### M3 decisions and findings (2026-11-06)

Adapter answers are in `docs/reference/mcp-adapter-notes.md`. Other points:

| Topic | What was done | Why |
| --- | --- | --- |
| **Bundled adapter is deprecated upstream** | Kept, prefixed with Strauss; all adapter code isolated in `src/Mcp/` | Standalone plugin requirement. **Needs a decision before 1.0** (see the notes) |
| Error text | Every error message ends `[code: ccb_…; field: …; retry_after: …; ref: …]` | The adapter passes only the message text to Claude, and the skill keys on codes |
| List outputs | `list-sections` returns `{ "sections": […] }` and `list-terms` returns `{ "terms": […] }` rather than a bare list | MCP structured results must be objects. `docs/03` shapes are marked abbreviated |
| Extra output | `check-connection` also returns `support` (the support contact) | The skill must show it when `ok` is false (`docs/07`), and only this tool runs before anything else |
| Where checks happen | Endpoint: logged in and `ccb_use_connection`, else 401/403. Each ability: HTTPS, capability, publish capability, then rate limit and its own rules. Refusals and core input-schema failures are audited | `docs/02` enforcement; the health check expects 401 from the endpoint |
| Draft dates | `date_gmt` for drafts is worked out by WordPress from the local date (drafts store no GMT date) | Otherwise drafts would show no date |
| Pre-flight in M3 | Config validity and staleness, no sections, missing post type, ACF inactive, Elementor template coverage, WebP support, skill version. Health-check age and adapter drift come in M7 | `docs/07` table |
| Adapter diagnostics | Own handler: refusals and errors with a `ccb_*` code are not written to the PHP log (they're in the activity log); everything else is | Avoids filling the PHP log with normal "not allowed" answers |
| `get-post` permission | Also requires `edit_post` on that post | It returns raw content; Bridge roles can edit others' posts anyway |

### M2 decisions and findings (2026-11-06)

| Topic | What was done | Why |
| --- | --- | --- |
| Live checks on save vs stored | `SiteChecks` runs after the schema. On save, a missing post type, term, taxonomy or ACF field is an **error**; for the stored config the same things are **warnings** with code `config_stale`, and the guide leaves them out | `docs/04` validation rules |
| Warnings need confirming | `ConfigRepository::save()` returns `ccb_config_needs_confirm` (409, with the warnings) unless `accept_warnings` is true | `docs/08` save flow step 4 |
| Post types with their own capabilities | Warning, not error (`post_type_capabilities`) | `docs/02`: flag in the admin UI |
| ACF rules | Fields with no rule take `required` if ACF marks them required, otherwise `optional`. A rule other than `hidden` on an unwritable ACF type is an error. An extra field may not reuse an ACF field's name | `docs/04` supported types; avoids two writers for one field |
| Preset file format | `{ "label", "description", "config": { …partial config… } }`; terms written as `"slug:<slug>"` | `docs/04` shows the partial config and `<resolved: slug …>` placeholders, not a file format |
| Preset merge | Preset values win; lists are replaced; settings the preset doesn't mention are kept; sections whose post type is missing are skipped and reported | `docs/04` "only maps post types that exist" |
| Guide tool names | The guide refers to abilities by their ability names (e.g. `ccb/get-upload-link`) until M3 confirms the MCP tool names | `docs/10`: tool names come from the pinned adapter |
| Elementor templates | Read from `elementor_library` posts with `_elementor_template_type` of `single`/`single-post` and conditions in `_elementor_conditions` | **Still TO CONFIRM** against the site's Elementor Pro version at the site audit |
| `multiple` in guide taxonomies | Always `true` | WordPress lets every taxonomy hold several terms; nothing in the config limits it |
| Discovery cache | Cleared on term create/edit/delete, ACF field group save/delete and plugin (de)activation, otherwise 5 minutes. Not on `registered_post_type`, which fires on every request | `docs/04` |

### M1 — Skeleton
Files: `causeway-claude-bridge.php` (with the missing-build guard), `uninstall.php`, Strauss config in `composer.json`, `.distignore`, `bin/build-release.sh`, `src/Plugin.php`, `src/Activation.php`, `src/Roles/RoleManager.php`, `src/Config/ConfigRepository.php`, `src/Config/SchemaValidator.php`, `schema/config.schema.json`, `src/Db/Schema.php` (all five tables via `dbDelta`, `ccb_db_version`).
- Version checks (WP ≥ 6.9, PHP ≥ 8.1) with an admin notice and clean stop.
- Roles `ccb_contributor`, `ccb_publisher` built idempotently; admins get caps on activation and through `user_has_cap`.
- Default empty config stored as version 1; version rows capped at 20.
- Cron scheduled on activation, cleared on deactivation. Uninstall honours "Delete all data" and reassigns roles first.

Tests: release zip builds, and the smoke test activates it on a clean site; schema valid and invalid cases for every rule in `docs/04`; activation creates tables, roles, caps, config, cron; uninstall reassigns `ccb_contributor` → Contributor and `ccb_publisher` → Editor.

### M2 — Site discovery and publishing guide
Files: `src/Config/SiteStructure.php` (+ cache clearing on term and ACF changes), live-site checks in `SchemaValidator`, `src/Config/PublishingGuide.php`, `src/Config/Presets.php`, `presets/mp-site.json`.

Tests: discovery against fixtures; guide markdown snapshot per section; preset apply resolves slugs and reports missing ones; stale-config warnings.

### M3 — MCP server and read abilities
Files: `src/Mcp/ServerRegistrar.php`, `src/Abilities/AbilityRegistrar.php`, `src/Abilities/AbstractAbility.php`, `src/Security/RateLimiter.php`, `src/Audit/ActivityLog.php` (basic rows from day one), and `CheckConnection`, `GetPublishingGuide`, `ListSections`, `ListTerms`, `ListPosts`, `GetPost`.

`AbstractAbility` implements the eight enforcement steps from `docs/02` once (auth, HTTPS, rate limit, schema, section, config rules, token hook, audit row), and every ability extends it. Errors use the `WP_Error` shape and codes from `docs/03`; unexpected exceptions become `ccb_internal` with a 12-character reference.

Tests: each read ability × role (none, Contributor, Publisher, admin); MCP end-to-end lists only `ccb/*` tools and the default adapter server is unreachable, both alone and alongside the standalone MCP Adapter plugin (resolves the Strauss TO CONFIRM in section 1a).

### M4 — Write abilities
Files: `src/Content/ContentSanitiser.php`, `src/Content/PostWriter.php`, `src/Content/FieldWriter.php`, `src/Content/Geocoder.php`, `src/Content/SeoWriter.php` (SEOPress is the first-class path and is tested; Yoast and Rank Math mappings stay for reuse), `src/Checks/PostVerifier.php` (database checks; HTTP checks come in M7), `CreatePost`, `UpdatePost`, idempotency and duplicate check.

Tests: Contributor `status: publish` stored as `pending`; terms never created and unknown terms rejected; defaults applied; ACF written by key on `resource`; `visits_location` stored as `{"lat":<number>,"lng":<number>}` and written after `wp_insert_post`; published Visit appears in `[visits_map]` output; titles stored as plain text (MP Visits' pop-up doesn't escape them); no `_elementor_*` meta written; Elementor-built content edits refused; sanitiser unit tests.

### M5 — Media
Files: `src/Media/ImagePipeline.php`, `src/Media/UploadLinks.php`, `templates/upload-link.php`, `UploadImage`, `GetUploadLink`, PDF handling.

Tests: JPEG with GPS EXIF → WebP without EXIF; PNG; oversize; SVG, HEIC, PHP-as-JPEG and polyglot rejected; PDF without `%PDF-` rejected; PDF via link sets `downloadable_file`; link single use, expiry, wrong type, other user's post. GD path tested locally; Imagick path in CI.

### M6 — Confirmation tokens and live changes
Files: `src/Security/ConfirmationTokens.php`, `Prepare*` + `PublishPost`, `SchedulePost`, `UpdateLivePost`, `UnpublishPost`, `TrashPost`, `RestorePost`.

Tests: the full token matrix (none, expired, reused, other user, other post, payload changed, post modified since prepare); Contributors refused publish and schedule; revision saved before update-live; restore always returns a draft; daily live-change cap.

### M7 — Audit, checks, notifications
Files: full `ActivityLog` details and pruning, HTTP checks and cache purge in `PostVerifier`, `src/Notify/Mailer.php`, `templates/emails/*`, `src/Checks/PreFlight.php`, `src/Checks/HealthCheck.php`, cron handlers, error-burst alert.

Tests: audit row for every call including refusals; mails to the right recipients and never the actor (mail mocked); health check with endpoint blocked and stale config; rate limits.

### M8 — Admin UI
Files: `src/Admin/AdminPage.php`, `src/Admin/RestController.php`, `templates/admin/**`, `assets/src/**`, `assets/dist/**`.

Order inside the milestone: REST endpoints with tests → app shell and design tokens → Sections tab and "What Claude sees" → remaining tabs → save flow (validation, `base_version` conflict) → versions, diff, restore, import/export, presets.

Tests: Playwright per `docs/08` acceptance list (tabs, keyboard, axe, 360/782/1024/1440 px, WP Admin isolation screenshots, all eight colour schemes); CSS < 40 KB, JS < 60 KB.

### M9 — Setup pack
Files: `setup-pack/extension/` (`manifest.json`, `server/index.js` starting the pinned proxy), `setup-pack/skill/SKILL.md`, how-to PDF template, `src/SetupPack/SetupPackBuilder.php`, profile download and Setup pack tab.

Tests: `mcpb validate`; every tool name in the skill exists on the `ccb` server; built pack contains no credentials. Manual install on macOS and Windows.

### M10 — Hardening
Full release-zip smoke test green; `RELEASING.md` written; `docs/09` security checklist worked through and written up; translations (`.pot`); compatibility report template for `docs/11`; performance pass.

## 3. Open questions for the client

From `docs/12`:
- ~~Besides administrators, who has AI Publisher rights at launch?~~ Mike (the MP) is an AI Publisher at launch, made one in the Access tab if he isn't an administrator (confirmed 2026-11-06). This is a site setup step, not code.
- ~~Does every staff member have their own Claude Pro account?~~ Yes (confirmed 2026-11-06).
- ~~Which SEO plugin does the site use?~~ SEOPress on all sites (confirmed 2026-11-06).
- ~~Confirm preset limits~~ Confirmed 2026-11-06: News 150–1200 words; Visits max 150 words and 1 image. Ship as in the `mp-site.json` preset.
- ~~Which presets ship in the first release?~~ Only `mp-site.json` (confirmed 2026-11-06). More presets are phase 2.

Added during planning:
- ~~Please provide the MP Visits plugin source~~ Received 2026-11-06; findings in `docs/reference/mp-visits-findings.md`.
- ~~Should bundled Composer dependencies be namespace-prefixed with Strauss?~~ Yes: required so the plugin runs standalone (decided 2026-11-06; see section 1a).
- ~~How does the update server receive new releases?~~ Manual upload to `repo.causewaycreative.co.uk` (confirmed 2026-11-06; see section 1a).
