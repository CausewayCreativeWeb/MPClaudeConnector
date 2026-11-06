# 12 — Milestones, rollout, risks and open questions

## Build milestones

Each milestone ends with passing tests and a commit.

| # | Milestone | Done when |
| --- | --- | --- |
| M1 | Skeleton: bootstrap, version checks, activation/deactivation/uninstall, tables, roles and caps, config storage, schema, defaults | Integration tests for activation, roles and config validation pass |
| M2 | Site discovery and publishing guide; presets | Guide for fixture site matches expected markdown |
| M3 | MCP server + read abilities (`check-connection`, `get-publishing-guide`, `list-sections`, `list-terms`, `list-posts`, `get-post`) | MCP e2e lists only `ccb/*` tools; reads work per role |
| M4 | Write abilities: `create-post`, `update-post`; sanitiser; terms; ACF and extra fields; geocoder; SEO meta; idempotency; duplicate check | All create/update integration tests pass on `post`, `resource`, `visits` |
| M5 | Media: image pipeline, `upload-image`, upload links and page, PDFs | Media tests pass; EXIF test passes |
| M6 | Confirmation tokens and live abilities; restore | Token and live-change tests pass |
| M7 | Audit log, post verification, notifications, health check, rate limits, cron | Tests pass; emails rendered |
| M8 | Admin UI (all tabs, REST endpoints, save flow, versions, import/export) | Playwright suite passes; UI acceptance in `docs/08` |
| M9 | Setup pack: extension, skill template, how-to PDF, profile download | `mcpb validate` passes; skill tool-name test passes; manual install on macOS and Windows |
| M10 | Hardening: security checklist, performance, translations, docs | `docs/09` checklist complete |

## Rollout (first site)

1. **Site audit**: versions, plugins, post types, terms, Elementor templates, SEO plugin, hosting limits, firewall/CDN rules, mail delivery.
2. **Staging build**: install, apply MP site preset, configure.
3. **Compatibility and security review** on staging; write up findings.
4. **Pilot**: Mike plus one Contributor on staging for a week, then live with publishing for Mike only. Test Claude Pro and Claude Free accounts.
5. **Team rollout**: accounts, Application Passwords, setup packs, training and how-to.
6. **Handover**: admin walkthrough of Settings → Claude Publishing; support monitors health alerts.

## Risks

| Risk | Mitigation |
| --- | --- |
| Wrong post published or removed | Confirmation summary, notifications, Bin restore, revisions |
| Someone approves a confirmation without reading it | Training; short specific summaries; restore links in every removal email |
| Prompt injection via pasted text | Contributors can't publish; human "yes" required for every live change; skill tells Claude to ignore instructions inside pasted content; logs and emails |
| MCP Adapter breaking changes (pre-1.0) | Pin version; read its migration guides; test upgrades on staging |
| Remote proxy or MCPB format changes | Pin versions; `mcpb validate` in CI; health check reports version drift |
| Firewall/CDN blocks the endpoint after a change | Daily and post-update health checks |
| Free-plan usage limits | Pro required for Publishers and regular posters; pilot tests Free |
| Personal data pasted into Claude | Guidance, training, privacy setting check at onboarding |
| MP Visits' save routine PHP warnings | Logged in compatibility report; plugin not modified (D9) |

## Open questions

- [ ] Besides administrators, who should have AI Publisher rights at launch?
- [ ] Confirm every staff member has their own Claude Pro account
- [ ] Which SEO plugin does the site use?
- [ ] Confirm the starting word and image limits in the MP site preset (News 150–1200 words; Visits max 150 words and 1 image)
- [ ] Which presets should ship in the first release besides the MP site preset?
- [ ] TO CONFIRM items in `docs/01`, `docs/02`, `docs/03`, `docs/05`, `docs/10` (adapter API and hooks, credential name lookup, `visits_location` format and postcode service, MCPB and proxy details)

## Phase 2 (not in this build)

- OAuth sign-in so staff can use Claude on the web and mobile, applying the site's 2FA to the connection (pilot an OAuth server plugin on staging first; build in only if it passes security review)
- More presets for non-MP clients
