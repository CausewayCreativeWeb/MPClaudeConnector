# 00 — Overview

## Summary

Staff draft, edit and remove website posts by talking to Claude. Each person works under their own WordPress login and their own credential, so every action is attributable. New posts from Contributors land as **Pending Review**. Only **AI Publishers** (all site administrators plus anyone else an admin selects) can publish or schedule. Any change to the live site needs an explicit confirmation from the person making it. Deleting moves a post to the Bin, where it can be restored for 30 days.

The plugin runs inside WordPress and exposes a fixed, small set of "abilities" to Claude through the official WordPress MCP Adapter. Claude has no other route into the site.

First install: an Elementor-driven MP website (the MP, Mike, is the main approver) with three sections: News (`post`), Insights (`resource`) and Visits (`visits`). The plugin is built to be reused on other client sites with different post types.

## Goals

1. A staff member goes from notes to a correctly formatted draft in WordPress in under five minutes.
2. A Publisher reviews and publishes or schedules a post from Claude in one conversation.
3. Every action through Claude is attributable to a named person in the audit log.
4. Nothing goes live or comes down without an explicit confirmation from the person making the change, and anything removed can be restored.
5. An admin can configure a new site in under 15 minutes from a clean, branded-neutral settings screen, without editing JSON.

## In scope

- Secure, authenticated connection between Claude and WordPress (MCP Adapter + custom abilities)
- Two roles (AI Contributor, AI Publisher), per-person logins and Application Passwords, full audit log
- Create, edit, publish, schedule, update-live, unpublish, trash and restore posts, with confirmation safeguards
- Server-hosted, versioned configuration with live discovery of post types, taxonomies, terms and ACF fields
- Formatting rules, ACF and custom meta fields, featured images
- Image pipeline (validate, resize, WebP, strip EXIF), PDF uploads, one-time upload links
- Pre-flight, post-creation and daily health checks; email notifications
- Admin settings UI (Tailwind CSS, Alpine.js)
- Setup pack: Claude Desktop extension and Claude skill, downloadable from the user's profile
- Compatibility testing and a security review

## Out of scope

- Permanent deletion, emptying the Bin, deleting media
- Bulk actions across many posts in one request
- Editing pages, Elementor layouts or templates, menus, widgets, users, plugins or site settings
- Constituent casework or any personal data handling
- OAuth sign-in and use from claude.ai web or mobile (possible phase 2)
- Changes to the existing MP Visits and Causeway Insights plugins

## Decisions log

| # | Decision | Notes |
| --- | --- | --- |
| D1 | Authentication uses per-user, per-device **Application Passwords** | OAuth deferred to a possible phase 2, piloted on staging first |
| D2 | Launch client is **Claude Desktop** with a Claude Desktop extension | Web and mobile need OAuth (phase 2) |
| D3 | Each person uses their **own Claude Pro** account | Shared Claude accounts not supported. Free works technically but usage limits make it unsuitable for regular posters; test both in pilot |
| D4 | **All administrators are AI Publishers automatically**, plus anyone else an admin selects | Everyone else using the connection is an AI Contributor |
| D5 | **Contributors can edit, unpublish and delete (to Bin) any post**, with confirmation for live changes, logged and emailed | They cannot publish or schedule |
| D6 | **Notifications are email only** | No Slack or Teams |
| D7 | **No quiet hours** for publishing or scheduling | Only past schedule times are refused |
| D8 | **Insights require authors** | Enforced by config even though the ACF field is optional |
| D9 | **Existing plugins are not changed** | MP Visits and Causeway Insights used as they are |
| D10 | **Structure is discovered live**; config holds only allow-lists and rules | Visits location is the one non-discoverable field, defined in config |
| D11 | **Admin UI: neutral branding**, built for reuse across clients | Support contact is a per-site setting |
| D12 | **Only administrators change settings** | Non-admin Publishers see Overview and Activity read-only |
| D13 | Admin UI is **PHP templates + Tailwind CSS v4 + Alpine.js**, compiled at build time | No React, no CDN |
| D14 | PDFs for Insights **always use the one-time upload link** | Too large to send reliably through Claude |

## Glossary

| Term | Meaning |
| --- | --- |
| Ability | A typed, permission-checked action registered with the WordPress Abilities API and exposed to Claude as an MCP tool |
| MCP Adapter | Official WordPress plugin/package that serves abilities over the Model Context Protocol |
| Section | A destination Claude can post to (News, Insights, Visits), mapped to a post type with rules |
| Publishing guide | The merged, plain-English description of a section's rules that Claude reads before writing |
| Live change | Any action that changes what visitors see: publish, schedule, update a published post, unpublish, trash or restore a published post |
| Confirmation token | Single-use token returned by a `prepare-*` ability and required by the matching action |
| Setup pack | Download containing the Claude Desktop extension, Claude skill and a how-to PDF |
| Bridge | This plugin |
