# 01 — Architecture

## Request path

```
Staff member's computer
  Claude Desktop + Claude skill + Claude Desktop extension
  (extension runs the WordPress MCP remote proxy with the user's own Application Password)
        │  HTTPS, Basic auth with Application Password
        ▼
Cloudflare / firewall / cache
  (MCP endpoint never cached, bot rules allow it, rate limited)
        │
        ▼
WordPress site
  ├─ WordPress MCP Adapter  →  dedicated server "ccb" (default server not exposed)
  │       │
  │       ▼
  ├─ Claude Publishing Bridge (this plugin)
  │     abilities with permission + schema checks
  │     confirmation tokens · config · publishing guide
  │     media pipeline · audit log · health checks · notifications
  │       │
  │       ▼
  ├─ WordPress core: posts, terms, media, Bin, revisions
  ├─ ACF (field writes via update_field)
  ├─ Existing CPT plugins (MP Visits, Causeway Insights) — unchanged
  └─ Elementor Theme Builder templates — render posts, never edited
        │
        ▼
Email notifications to Publishers and the support contact
```

## Components

### WordPress MCP Adapter (dependency)

- Official MCP server and transport layer for WordPress; turns abilities into MCP tools.
- Requires WordPress 6.9+ (Abilities API in core).
- Pulled in with Composer and **pinned to an exact version**. It is pre-1.0 (0.7.0 at time of writing) with breaking changes between minors. Upgrades are tested on staging.
- **TO CONFIRM against the pinned version's docs**: how to create a custom server and how to make sure the default server (`mcp-adapter-default-server`, with its `discover-abilities` / `execute-ability` tools) is not exposed. Requirement: only the `ccb/*` abilities are reachable.

### Claude Publishing Bridge (this plugin)

Registers:

- Ability category `ccb` and the abilities in `docs/03-abilities.md` (on the Abilities API init hooks)
- A dedicated MCP server (id `ccb`, route under the adapter's REST namespace) listing those abilities explicitly
- Roles and capabilities (`docs/02`)
- Config storage, schema validation, site discovery and publishing guide (`docs/04`)
- Media pipeline and upload-link page (`docs/05`)
- Tables for audit log, config versions, upload links (`docs/06`)
- Cron jobs for health checks and log pruning (`docs/07`)
- Admin UI and its REST endpoints (`docs/08`)
- Setup pack download on the user profile (`docs/10`)

### Claude client

- Claude Desktop with the Bridge's Claude Desktop extension. The extension wraps the WordPress remote MCP proxy (`@automattic/mcp-wordpress-remote`, **version pinned**) with the site URL built in. It prompts for username and Application Password at install and stores them as sensitive user config.
- The Claude skill tells Claude the workflow: check connection → load publishing guide → draft → verify → confirm live changes.

## Plugin file layout

```
causeway-claude-bridge/
├─ causeway-claude-bridge.php      # header, constants, autoload, bootstrap
├─ uninstall.php
├─ composer.json                   # wordpress/mcp-adapter (pinned), opis/json-schema, phpunit, phpcs, phpstan
├─ package.json                    # tailwind cli, esbuild, alpinejs, playwright
├─ src/
│  ├─ Plugin.php                   # wires services, hooks
│  ├─ Activation.php               # tables, roles, defaults, cron
│  ├─ Roles/RoleManager.php
│  ├─ Config/ConfigRepository.php  # load, save, versions
│  ├─ Config/SchemaValidator.php
│  ├─ Config/SiteStructure.php     # live discovery + cache
│  ├─ Config/PublishingGuide.php
│  ├─ Config/Presets.php
│  ├─ Mcp/ServerRegistrar.php      # dedicated MCP server
│  ├─ Abilities/AbilityRegistrar.php
│  ├─ Abilities/{CheckConnection,GetPublishingGuide,ListSections,ListTerms,ListPosts,GetPost,
│  │             CreatePost,UpdatePost,UploadImage,GetUploadLink,
│  │             Prepare*,PublishPost,SchedulePost,UpdateLivePost,UnpublishPost,TrashPost,RestorePost}.php
│  ├─ Security/ConfirmationTokens.php
│  ├─ Security/RateLimiter.php
│  ├─ Content/ContentSanitiser.php
│  ├─ Content/PostWriter.php       # wp_insert_post / wp_update_post wrapper, terms, featured image
│  ├─ Content/FieldWriter.php      # ACF update_field + config extra fields
│  ├─ Content/Geocoder.php         # UK postcode → lat/lng
│  ├─ Media/ImagePipeline.php
│  ├─ Media/UploadLinks.php        # one-time upload page + handler
│  ├─ Audit/ActivityLog.php
│  ├─ Checks/PreFlight.php
│  ├─ Checks/PostVerifier.php
│  ├─ Checks/HealthCheck.php       # cron
│  ├─ Notify/Mailer.php
│  ├─ Admin/AdminPage.php
│  ├─ Admin/RestController.php     # ccb/v1
│  └─ SetupPack/SetupPackBuilder.php
├─ templates/
│  ├─ admin/shell.php, tabs/*.php, components/*.php
│  ├─ upload-link.php
│  └─ emails/*.php
├─ schema/config.schema.json
├─ presets/mp-site.json
├─ assets/src/{admin.css, js/…}
├─ assets/dist/{admin.css, admin.js}   # committed build output
├─ setup-pack/
│  ├─ extension/                   # Claude Desktop extension source (manifest.json, server/)
│  └─ skill/SKILL.md               # template; site values injected at download
├─ languages/
└─ tests/{unit,integration,e2e}/
```

## Bootstrap and hooks

| Hook | Handler |
| --- | --- |
| `plugins_loaded` | Load Composer autoloader, check WP ≥ 6.9 and PHP ≥ 8.1 (admin notice and stop if not), boot `Plugin` |
| Abilities API category init hook | Register category `ccb` |
| Abilities API init hook | Register all `ccb/*` abilities |
| MCP Adapter init hook | Create the `ccb` server with the ability list |
| `admin_menu` | Settings → Claude Publishing |
| `rest_api_init` | `ccb/v1` routes |
| `init` | Upload-link rewrite rule/endpoint |
| `show_user_profile`, `edit_user_profile` | Setup pack download, Publisher toggle (admins only) |
| `created_term`, `edited_term`, `delete_term`, `acf/save_post` on field groups | Clear site-structure cache |
| `ccb_daily_health` (cron) | Health check |
| `ccb_daily_prune` (cron) | Prune audit log, expired tokens, expired upload links |
| `upgrader_process_complete` | Queue a health check after plugin/theme/core updates |

**TO CONFIRM**: exact Abilities API and MCP Adapter hook names for the pinned versions.

## Dependencies

| Dependency | Version | Why |
| --- | --- | --- |
| WordPress | ≥ 6.9 | Abilities API in core |
| PHP | ≥ 8.1, Imagick or GD with WebP | Image pipeline |
| wordpress/mcp-adapter | pinned exact | MCP transport |
| opis/json-schema (or equivalent) | pinned | Config and ability input validation |
| ACF | present on site | Insights fields (`acf_get_field_groups`, `update_field`) |
| Elementor Pro | present on site | Single-post templates (read-only checks) |
| yahnis-elsts/plugin-update-checker | same as other MP plugins | Updates |
| @automattic/mcp-wordpress-remote | pinned | Inside the Claude Desktop extension |

Abilities run as PHP callbacks, so post types and fields don't need `show_in_rest`. That matters here: the `visits` post type isn't in the REST API and the Insights ACF group has `show_in_rest` off.
