# 13 — Build plan

How the specs in `docs/00`–`12` turn into code. This adds sequencing, tooling and exit criteria; it changes no decisions. If this file and another spec disagree, the other spec wins.

## Status

| Item | State |
| --- | --- |
| Specs | In repo (`CLAUDE.md`, `docs/`) |
| Code | Not started |
| Pinned versions (latest at planning, 2026-11-06) | `wordpress/mcp-adapter` **0.7.1**, `@automattic/mcp-wordpress-remote` **0.5.5** |

## 0. Before writing code: resolve TO CONFIRM items

| Item | Spec | Source | How to resolve |
| --- | --- | --- | --- |
| Custom MCP server creation; hiding the default server and its `discover-abilities` / `execute-ability` tools; adapter init hook | `01`, `03` | `wordpress/mcp-adapter` 0.7.1 `docs/` | Read the pinned docs in `vendor/` after `composer install`. Record answers in `docs/reference/mcp-adapter-notes.md` |
| Ability meta keys for `readonly` / `destructive` / `idempotent` annotations; MCP resources support (`ccb://publishing-guide`) | `03` | Adapter 0.7.1 | As above |
| Application Password name/UUID lookup under the adapter's transport | `02` | Adapter + core | Check `rest_get_authenticated_app_password()` works on the adapter's REST route; integration test |
| Abilities API hook names (expected `wp_abilities_api_categories_init`, `wp_abilities_api_init`) | `01` | WP 6.9 core | Read core source |
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
| `wp-env` (`.wp-env.json`) | Developer machines and CI. WP 6.9, PHP 8.1 and 8.3, ACF, Elementor, fixtures |
| WP + SQLite (`bin/install-wp-tests-sqlite.sh`) | Containers without Docker (e.g. cloud Claude Code sessions). Downloads WP core and the `wordpress-develop` test library, installs the `sqlite-database-integration` drop-in and ACF. Same fixtures. `composer test:integration` picks it when no MySQL is configured |

MySQL-specific behaviour (`dbDelta` index syntax, `VARBINARY` IP column, `LONGTEXT`) is confirmed in CI on `wp-env`; SQLite runs are a fast local check, not the final word.

### Fixtures (`tests/fixtures/mu-plugins/`)
Replicas, from `docs/reference/existing-plugins.md`, of:
- `visits` CPT (not in REST, `capability_type` post, `map-thumb` size, `save_visits_meta()` and `[visits_map]` replicated as described in `docs/reference/mp-visits-findings.md`)
- `resource` CPT with `resource_category` and `content-type` taxonomies and the local ACF group "Resource Details" (`downloadable_file`, `online_reader_url`, `authors`, same field keys)
- Seed terms (`news` category, Insights categories and types)

These are test doubles only; the real plugins are never modified (D9).

### CI (`.github/workflows/ci.yml`)
PHPCS, PHPStan, PHPUnit unit, PHPUnit integration on `wp-env` (PHP 8.1 and 8.3), Playwright, `npm run build` with a check that `assets/dist` is unchanged, `mcpb validate`, and the release-zip smoke test below.

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
6. Generate the `plugin-update-checker` metadata JSON (version, `requires` 6.9, `requires_php` 8.1, download URL, changelog) for the self-hosted update server. Distribution is by **manual upload** to `repo.causewaycreative.co.uk` (confirmed 2026-11-06): CI attaches the zip and metadata JSON to the tagged release as build artifacts, and a person uploads both. Release checklist in `RELEASING.md` (M10): bump version, tag, wait for green CI and smoke test, download the artifacts, upload to the update server, then confirm a staging site sees the update.

The version number lives in one place (plugin header) and is checked against the `CCB_VERSION` constant, setup pack version and update metadata in CI.

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
