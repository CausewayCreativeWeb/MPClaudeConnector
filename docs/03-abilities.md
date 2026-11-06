# 03 — Abilities

Every action Claude can take is one of the abilities below, registered with the WordPress Abilities API under the `ccb` namespace and category, and listed explicitly on the dedicated `ccb` MCP server. **Nothing else is exposed.** There is no ability for permanent deletion, emptying the Bin, deleting media, or touching pages, menus, settings, users, plugins or Elementor data.

Ability names follow the Abilities API format (`namespace/kebab-name`). The MCP Adapter derives the tool names from them.

## Conventions

- **Permission**: `use` = `ccb_use_connection`; `publish` = `ccb_use_connection` + `ccb_publish`.
- **Input/output**: JSON Schema (draft 2020-12) in each ability's `input_schema` / `output_schema`. Shapes below are abbreviated; the schema files in `src/Abilities/schemas/` are authoritative once written.
- **Annotations**: set the MCP hints in ability meta — `readonly`, `destructive`, `idempotent` — as listed. **TO CONFIRM** the meta key names for the pinned adapter version.
- **Errors**: return `WP_Error( $code, $message, [ 'status' => int, 'field' => ?string, 'fix' => ?string ] )`. `$message` is plain English the user can read. Codes are listed at the end.
- **Section**: every ability that takes or returns a post resolves it to a configured, enabled section. A post whose type isn't in an enabled section → `ccb_post_type_not_allowed`.
- **Dates**: input accepts ISO 8601 with offset; times without an offset are read as `Europe/London`. Output gives both `*_gmt` and a human `*_display` in the site timezone.
- **Audit**: every call writes one activity row (`docs/06`), including refusals.

## Summary

| Ability | Permission | Live change | Annotations |
| --- | --- | --- | --- |
| `ccb/check-connection` | use | No | readonly |
| `ccb/get-publishing-guide` | use | No | readonly |
| `ccb/list-sections` | use | No | readonly |
| `ccb/list-terms` | use | No | readonly |
| `ccb/list-posts` | use | No | readonly |
| `ccb/get-post` | use | No | readonly |
| `ccb/create-post` | use | No | — |
| `ccb/update-post` | use | No | idempotent |
| `ccb/upload-image` | use | No | — |
| `ccb/get-upload-link` | use | No | — |
| `ccb/prepare-publish` → `ccb/publish-post` | publish | Yes | destructive (action) |
| `ccb/prepare-schedule` → `ccb/schedule-post` | publish | Yes | destructive (action) |
| `ccb/prepare-update-live` → `ccb/update-live-post` | use | Yes | destructive (action) |
| `ccb/prepare-unpublish` → `ccb/unpublish-post` | use | Yes | destructive (action) |
| `ccb/prepare-trash` → `ccb/trash-post` | use | Yes | destructive (action) |
| `ccb/restore-post` | use | No (restores as draft) | — |

`prepare-*` abilities are readonly; they validate and return a summary and token but change nothing.

---

## Read abilities

### `ccb/check-connection`

Run at the start of every session (the skill tells Claude to).

Input: none, or `{ "skill_version": "string" }`.

Output:

```json
{
  "ok": true,
  "user": { "id": 12, "display_name": "Sarah Jones", "role": "ccb_contributor", "can_publish": false },
  "credential": { "name": "Sarah – office laptop" },
  "versions": { "plugin": "1.0.0", "adapter": "0.7.0", "config": 14, "latest_skill": "1.0.0" },
  "sections": [ { "key": "news", "label": "News" } ],
  "issues": [ { "code": "skill_outdated", "severity": "warning", "message": "A newer setup pack is available…", "fix": "Download it from your WordPress profile." } ]
}
```

`ok` is false if any issue has severity `error` (see pre-flight checks in `docs/07`). Claude must stop and show the issues.

### `ccb/get-publishing-guide`

Input: `{ "section": "news" }` (optional; omit for all sections).

Output: `{ "config_version": 14, "general": {…}, "sections": [ {…} ] }` where each section contains:

- `key`, `label`, `post_type`, `post_type_label`
- `guide`: plain-English markdown Claude should follow (generated, see `docs/04`)
- `taxonomies`: `{ "<taxonomy>": { "label", "required": bool, "multiple": bool, "default": [ids], "terms": [ { "id", "slug", "name", "parent" } ] } }`
- `fields`: `[ { "name", "label", "type", "rule": "required|optional|only_if_given", "choices"?, "instructions"? } ]` (ACF fields minus hidden ones, plus extra fields)
- `featured_image`: `{ "required", "ratio", "min_width" }`
- `limits`: `{ "min_words", "max_words", "excerpt_max_chars", "max_images" }`

`general` holds language, allowed headings and HTML, house style notes, words to avoid, sign-off text, image rules and upload limits.

Also exposed as an MCP resource (`ccb://publishing-guide`) if the pinned adapter supports resources (**TO CONFIRM**).

### `ccb/list-sections`

Input: none. Output: `[ { "key", "label", "post_type", "counts": { "draft", "pending", "future", "publish" } } ]`.

### `ccb/list-terms`

Input: `{ "section": "insights", "taxonomy": "resource_category", "search"?: "string" }`.
Output: `[ { "id", "slug", "name", "parent", "count" } ]` (max 200). Taxonomy must belong to the section's post type.

### `ccb/list-posts`

Input:

```json
{ "section": "news", "status": ["pending"], "search": "crossing", "mine": false, "page": 1, "per_page": 10 }
```

`status` values: `draft`, `pending`, `future`, `publish`, `trash`. `per_page` max 20.

Output: `{ "total": 3, "items": [ { "id", "title", "section", "status", "author", "date_gmt", "modified_gmt", "preview_url", "live_url" } ] }`.

### `ccb/get-post`

Input: `{ "post_id": 4182 }`. Output: everything needed to edit:

```json
{
  "id": 4182, "section": "news", "status": "pending", "title": "…", "content": "<p>…</p>", "excerpt": "…",
  "slug": "…", "author": { "id": 12, "name": "Sarah Jones" },
  "date_gmt": "…", "modified_gmt": "…",
  "terms": { "category": [ { "id": 5, "name": "News" } ] },
  "fields": { "authors": "…" },
  "featured_image": { "id": 991, "url": "…", "alt": "…" },
  "attached_images": [ { "id": 992, "url": "…", "alt": "…" } ],
  "seo": { "title": "…", "description": "…" },
  "preview_url": "…", "live_url": "…",
  "elementor_built": false
}
```

`elementor_built` is true if `_elementor_edit_mode` is `builder`. Content edits on such posts are refused (`ccb_elementor_post`), because Elementor stores the layout separately and the editor would overwrite them.

---

## Write abilities (not live)

### `ccb/create-post`

Input:

```json
{
  "section": "visits",
  "title": "Visit to Riverside Primary",
  "content": "<p>…</p>",
  "excerpt": "…",
  "slug": "optional",
  "terms": { "category": [5] },
  "fields": { "visits_location": { "postcode": "WA1 1AA" } },
  "featured_image_id": 991,
  "seo": { "title": "…", "description": "…" },
  "status": "pending",
  "idempotency_key": "uuid-from-claude",
  "confirm_duplicate": false
}
```

Rules:

1. Section must be enabled; post type from config.
2. `status`: Contributors are forced to `pending` whatever is sent. Publishers may choose `draft` or `pending` (default `pending`). `publish`/`future` are never accepted here.
3. Content sanitised (`docs/05`). Disallowed HTML stripped; H1 converted to H2; inline styles removed if configured.
4. Terms: IDs or slugs, must exist and belong to the section's taxonomies; unknown terms → error (never created). Defaults applied when a taxonomy is left empty. Required taxonomies must end up non-empty.
5. Fields: ACF fields written with `update_field( $field_key, … )`; extra fields per config (`docs/05`). Required fields enforced. `only_if_given` fields are written only when provided. Hidden fields are refused.
6. Featured image: must be an attachment the user uploaded through the Bridge or that exists in the Media Library; checked against section rules. If required but missing, the post is still created and verification flags it (so work isn't lost).
7. Limits: word count and image count checked; over the max → error; under the min → warning.
8. Duplicate check: same section + same title in the last 24 hours → `ccb_possible_duplicate` unless `confirm_duplicate` is true.
9. Idempotency: if `idempotency_key` was seen for this user in the last 24 hours, return the original result instead of creating again.
10. Author = current user. Never writes Elementor meta.

Output: `{ "post_id", "status", "preview_url", "edit_url", "verification": { "passed": bool, "checks": [ { "name", "passed", "message", "fix"? } ] }, "warnings": [] }` (see post verification in `docs/07`).

### `ccb/update-post`

Edits a post whose status is `draft` or `pending`. Scheduled (`future`) and published posts must use `ccb/update-live-post`.

Input: `{ "post_id", "expected_modified_gmt"?, …any create-post field except section and idempotency_key }`.

- If `expected_modified_gmt` is sent and differs → `ccb_post_changed`.
- Contributors can't change status; a Contributor editing a `draft` keeps it `draft`.
- Same validation as create. Output as create.

### `ccb/upload-image`

Input:

```json
{ "filename": "visit.jpg", "data_base64": "…", "alt": "Mike with pupils at Riverside Primary", "caption": "optional", "post_id": 4182, "set_featured": true }
```

- Decoded size ≤ the configured max (default 4 MB) → else `ccb_file_too_large` with an upload link offered in `data.fix`.
- Processed by the image pipeline (`docs/05`). `alt` required (≤ 125 chars).
- If `post_id` given: attached to it; `set_featured` sets the thumbnail.

Output: `{ "attachment_id", "url", "width", "height", "bytes", "format": "webp" }`.

### `ccb/get-upload-link`

Input: `{ "post_id": 4182, "purpose": "featured_image" | "content_image" | "field_file", "field": "downloadable_file" }`.
`field` is required when `purpose` is `field_file`, and must be a file-type field on that section.

Output: `{ "url", "expires_at_gmt", "accepts": ["image/jpeg","image/png","image/webp"] | ["application/pdf"], "max_mb": 20 }`.

Claude gives the link to the user, then calls `ccb/get-post` to confirm the file arrived. Details in `docs/05`.

---

## Live-change abilities

Each is a pair. The `prepare-*` ability takes the same input as the action (minus `token`), runs every check the action would run, and returns:

```json
{ "summary": { "action": "Publish now", "title": "…", "section": "News", "from_status": "pending", "to_status": "publish", "url": "…", "changes": [], "notes": ["Publishers will be emailed."] }, "token": "…", "expires_at_gmt": "…" }
```

The action takes the same input plus `token`, verifies and consumes it (`docs/02`), acts, runs post verification and sends notifications.

### `ccb/prepare-publish` → `ccb/publish-post` (publish permission)

- Input: `{ "post_id" }` (+ `token`).
- Allowed from `draft` or `pending`.
- Section requirements must all pass (required fields, terms, featured image); otherwise refused with the list of problems. Publishing never bypasses a rule that create only warned about.

### `ccb/prepare-schedule` → `ccb/schedule-post` (publish permission)

- Input: `{ "post_id", "publish_at": "2026-11-18T09:00:00+00:00" }` (+ `token`).
- Allowed from `draft`, `pending` or `future` (reschedule).
- `publish_at` must be at least 2 minutes in the future → else `ccb_schedule_in_past`. No quiet hours.
- Same section requirements as publish.

### `ccb/prepare-update-live` → `ccb/update-live-post` (use permission)

- Input: `{ "post_id", "changes": { …create-post fields… } }` (+ `token`).
- Allowed on `publish` and `future` posts.
- Summary lists each field that changes, with before → after for short fields and word counts for content.
- A revision is saved before the update (`wp_save_post_revision`). Status and date are unchanged.
- Refused on Elementor-built posts.

### `ccb/prepare-unpublish` → `ccb/unpublish-post` (use permission)

- Input: `{ "post_id" }` (+ `token`). Allowed on `publish` and `future`. Sets status to `draft`.

### `ccb/prepare-trash` → `ccb/trash-post` (use permission)

- Input: `{ "post_id" }` (+ `token`). Any status except `trash`. Uses `wp_trash_post()`.
- Summary says "Can be restored for 30 days" (or the site's `EMPTY_TRASH_DAYS`).

### `ccb/restore-post` (use permission, no token)

- Input: `{ "post_id" }`. Must be in the Bin. Uses `wp_untrash_post()` and always restores to `draft` (force via the `wp_untrash_post_status` filter for this call).
- Publishers are emailed.

---

## Error codes

| Code | HTTP | When |
| --- | --- | --- |
| `ccb_not_authenticated` | 401 | No valid user |
| `ccb_https_required` | 403 | Request not over HTTPS |
| `ccb_forbidden` | 403 | Missing capability (e.g. Contributor publishing) |
| `ccb_rate_limited` | 429 | Over per-minute or daily cap; `data.retry_after` |
| `ccb_invalid_input` | 400 | Schema validation failed; `data.field` |
| `ccb_section_unknown` | 400 | Section key not configured or disabled |
| `ccb_post_not_found` | 404 | No such post |
| `ccb_post_type_not_allowed` | 403 | Post isn't in an enabled section |
| `ccb_wrong_status` | 409 | Action not allowed from the post's current status |
| `ccb_term_unknown` | 400 | Term doesn't exist or isn't in an allowed taxonomy |
| `ccb_required_missing` | 400 | Required term, field or featured image missing (publish/schedule) |
| `ccb_field_invalid` | 400 | Field value fails its type rule |
| `ccb_field_hidden` | 400 | Field is hidden from Claude |
| `ccb_limit_exceeded` | 400 | Words or images over max |
| `ccb_possible_duplicate` | 409 | Same title in the same section within 24 hours |
| `ccb_elementor_post` | 409 | Post is built with Elementor; edit in WP Admin |
| `ccb_post_changed` | 409 | Post modified since it was read or prepared |
| `ccb_token_invalid` | 403 | Token missing, unknown, expired or used |
| `ccb_token_mismatch` | 403 | Payload differs from what was prepared |
| `ccb_schedule_in_past` | 400 | `publish_at` not in the future |
| `ccb_file_too_large` | 413 | Upload over limit; upload link offered |
| `ccb_file_type` | 415 | Not an accepted type (checked by content) |
| `ccb_geocode_failed` | 422 | Postcode not found or outside the UK |
| `ccb_config_invalid` | 500 | Stored config fails validation; admin must fix |
| `ccb_internal` | 500 | Unexpected error; logged with a reference ID shown to the user |

Server errors and timeouts: Claude must not retry create, publish, schedule, update-live, unpublish or trash automatically. The skill tells it to call `ccb/get-post` or `ccb/list-posts` to check what happened and report.
