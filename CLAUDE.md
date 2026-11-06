# Claude Publishing Bridge — project guide for Claude Code

This repository builds **Claude Publishing Bridge** (plugin slug `causeway-claude-bridge`, PHP prefix `ccb`), a WordPress plugin that lets named staff draft, edit, publish and remove website posts from Claude, safely.

The first install is an Elementor-based MP website with three sections: **News**, **Insights** and **Visits**. The plugin must stay generic so it can be reused across other client sites with different post types.

Read the specs in `docs/` before writing code. They are the source of truth. If code and spec disagree, stop and ask; don't silently pick one.

## Spec index

| File | What it covers |
| --- | --- |
| `docs/00-overview.md` | Goals, scope, decisions log, glossary |
| `docs/01-architecture.md` | Components, request flow, plugin file layout, dependencies, bootstrap |
| `docs/02-roles-and-permissions.md` | Roles, capabilities, confirmation tokens, limits |
| `docs/03-abilities.md` | Every ability Claude can call: inputs, outputs, rules, error codes |
| `docs/04-configuration.md` | Config JSON shape and schema, live site discovery, publishing guide, presets |
| `docs/05-content-and-media.md` | Content sanitising, Elementor rules, ACF and meta writes, images, PDFs, upload links, Visits geocoding |
| `docs/06-data-model.md` | Tables, options, transients, meta, activation, uninstall |
| `docs/07-checks-errors-notifications.md` | Pre-flight, post verification, daily health check, error handling, emails |
| `docs/08-admin-ui.md` | Settings → Claude Publishing screen: tabs, Tailwind setup, Alpine, REST endpoints |
| `docs/09-security.md` | Security requirements and review checklist |
| `docs/10-setup-pack-and-onboarding.md` | Claude Desktop extension, Claude skill, onboarding and offboarding |
| `docs/11-testing-and-acceptance.md` | Test strategy, compatibility checks, acceptance criteria |
| `docs/12-rollout-and-open-questions.md` | Milestones, rollout, risks, open questions |
| `docs/13-build-plan.md` | Build sequencing, tooling, test environments and per-milestone exit criteria |
| `docs/reference/existing-plugins.md` | The site's existing MP Visits and Causeway Insights plugins (read-only reference) |
| `docs/reference/mp-visits-findings.md` | MP Visits source findings: confirmed `visits_location` format, postcode service, map behaviour |

## Non-negotiables

1. **Claude never gets a route around the plugin.** Only the abilities in `docs/03-abilities.md` are exposed, on a dedicated MCP server. Don't expose the MCP Adapter's default server or any other plugin's abilities.
2. **Server-side enforcement on every ability.** Check capability, validate input against the schema, apply config rules, write an audit row. Never trust what Claude sends.
3. **Live changes need a confirmation token.** Publish, schedule, update a published or scheduled post, unpublish and trash all go `prepare-*` → human yes → token → action. Restore always returns a post as a draft, so it needs no token. Tokens are single use, bound to user + post + action + post state, and expire after 10 minutes.
4. **Contributors can never publish or schedule.** Posts they create are forced to `pending`.
5. **Delete means Bin.** No permanent deletion, no emptying the Bin, no media deletion, no pages, menus, users, settings, plugins or Elementor data.
6. **Never write Elementor layout meta** (`_elementor_data`, `_elementor_edit_mode`, `_elementor_*`).
7. **Never create taxonomy terms.** Claude may only pick existing terms.
8. **No client-specific code.** Everything site-specific lives in the config. Don't hard-code `visits`, `resource` or any slug outside presets and tests.
9. **Neutral branding in the UI.** No agency name or logo in admin screens; the support contact comes from config.
10. **No external assets at runtime.** Tailwind and Alpine are compiled into the plugin. The only outbound HTTP call is the postcode lookup (see `docs/05`).
11. **Don't modify the existing MP Visits or Causeway Insights plugins.** Work with them as they are.

## Stack and conventions

- WordPress **6.9+** (Abilities API in core), PHP **8.1+**
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) as a Composer dependency, **version pinned**. It is pre-1.0 and has had breaking changes (0.6.0, 0.7.0). Check the installed version's `docs/` before using its API, and don't guess method signatures.
- Composer PSR-4 autoload, namespace `Causeway\ClaudeBridge\`, source in `src/`
- Function, option, meta, transient, hook and table prefix: `ccb_`; REST namespace `ccb/v1`; ability namespace `ccb/`
- Text domain `causeway-claude-bridge`. Every UI string is translatable.
- WordPress Coding Standards (PHPCS), PHPStan level 6 with WordPress stubs, strict types in new files
- Escape late (`esc_html`, `esc_attr`, `esc_url`, `wp_json_encode`), sanitise early, `$wpdb->prepare` for every query
- UK English in UI copy and docs
- Updates are delivered with the same self-hosted update server the other MP plugins use (`plugin-update-checker`, metadata at `https://repo.causewaycreative.co.uk/?action=get_metadata&slug=causeway-claude-bridge`)

## Commands

```bash
composer install            # PHP deps incl. MCP Adapter (pinned)
npm install                 # Tailwind CLI, esbuild, Alpine, Playwright
npm run dev                 # watch admin CSS + JS
npm run build               # minified assets to assets/dist (committed)
npx wp-env start            # local WordPress 6.9+ with ACF, Elementor and fixtures
composer test               # PHPUnit (unit + integration via wp-env)
composer lint               # PHPCS + PHPStan
npx playwright test         # admin UI visual, keyboard and a11y tests
```

## Build order

Follow the milestones in `docs/12-rollout-and-open-questions.md`. Summary:

1. Plugin skeleton, activation, roles, tables, config storage and schema
2. Site discovery and publishing guide
3. Read abilities, then create/update abilities, then content, ACF and meta writing
4. Media pipeline, upload links, Visits geocoding
5. Confirmation tokens and the live-change abilities
6. Audit log, notifications, health checks
7. Admin UI
8. Setup pack (Claude Desktop extension and skill)
9. Hardening, tests, compatibility pass

Commit after each milestone with passing tests.

## When unsure

- Ask before changing anything in the Non-negotiables or the decisions log in `docs/00-overview.md`.
- Items marked **TO CONFIRM** in the specs need checking against the real site or library before building on them.
