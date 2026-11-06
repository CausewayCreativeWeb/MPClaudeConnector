# 04 — Configuration

## Principle

The config holds only what WordPress can't tell the plugin by itself: **which post types Claude may use, and the house rules for each**. Structure (post types, taxonomies, terms, ACF fields) is discovered live, so a new category or ACF field reaches Claude without a config change.

| Read live from WordPress | Set in the config |
| --- | --- |
| Registered public post types and labels | Which post types are enabled as sections, and their labels |
| Taxonomies per post type and their current terms | Default terms and required taxonomies per section |
| ACF field groups and fields per post type (name, key, type, choices, required flag, instructions) | Per-field rule: required / optional / only if given / hidden |
| Featured image support | Image ratio and minimum width |
| Current user's capabilities | Word limits, formatting, house style |
| — | Extra fields that can't be discovered (non-ACF meta such as `visits_location`) |
| — | Notifications, limits, image settings, support contact |

## Storage and versioning

- Live config: option `ccb_config` (JSON, `autoload` off).
- Every save writes a row to `ccb_config_versions` and bumps the integer `version`. Last 20 versions kept.
- Edited only through the admin UI (`docs/08`) or by importing JSON there. Every save is validated against `schema/config.schema.json` **and** against the live site.
- A copy of each client's config is kept in the client repo for review (exported from the Advanced tab).

## Config shape

```json
{
  "schema_version": 1,
  "version": 14,
  "saved_by": 1,
  "saved_at_gmt": "2026-11-12T10:14:03Z",

  "sections": {
    "<key>": {
      "enabled": true,
      "label": "string, 1–40 chars",
      "post_type": "registered public post type",
      "notes": "string ≤ 1000, extra guidance for Claude",
      "default_terms": { "<taxonomy>": [ "<term id>" ] },
      "required_taxonomies": [ "<taxonomy>" ],
      "featured_image": { "required": true, "ratio": "16:9 | 4:3 | 1:1 | any", "min_width": 1200 },
      "limits": { "min_words": 0, "max_words": 0, "excerpt_max_chars": 160, "max_images": 5 },
      "field_rules": { "<acf field name>": "required | optional | only_if_given | hidden" },
      "extra_fields": {
        "<meta_key>": { "label": "string", "type": "text | textarea | date | select | uk_postcode_to_latlng", "required": true, "choices": ["…"], "instructions": "string" }
      },
      "elementor_template_id": 0
    }
  },

  "writing": {
    "language": "en-GB",
    "headings": ["h2", "h3"],
    "allowed_html": ["p","h2","h3","ul","ol","li","a","strong","em","blockquote","figure","figcaption","img","br"],
    "strip_inline_styles": true,
    "link_rules": { "external_new_tab": false, "nofollow_external": false },
    "house_style": "markdown ≤ 4000 chars",
    "words_to_avoid": ["string"],
    "sign_off": "string ≤ 500",
    "seo": { "plugin": "auto | none | yoast | rankmath | seopress", "title_max": 60, "description_max": 155 }
  },

  "media": {
    "image_types": ["image/jpeg","image/png","image/webp"],
    "max_upload_mb": 4,
    "max_edge_px": 2000,
    "webp_quality": 82,
    "strip_metadata": true,
    "pdf_max_mb": 20,
    "upload_link_minutes": 15
  },

  "access": {
    "requests_per_minute": 30,
    "live_changes_per_day": 20
  },

  "notifications": {
    "recipients": { "publishers": true, "extra": ["office@example.org"] },
    "events": { "pending_post": true, "live_change": true, "removal": true, "health_failure": true }
  },

  "support": { "name": "string", "email": "string", "url": "string" },

  "activity": { "retention_months": 12 }
}
```

`strip_metadata` is always true; the UI shows it locked. `0` in limits means "no limit".

## Validation rules (server is authoritative)

- `schema_version` must match the plugin's supported version (migrate older configs on load).
- Each enabled section: `post_type` exists and is public; each `default_terms` ID exists in a taxonomy of that post type; each `required_taxonomies` entry belongs to the post type; each `field_rules` key is an ACF field in a group whose location rules include the post type; `extra_fields` keys match `^[a-z][a-z0-9_]{1,62}$` and don't start with `_` or `_elementor`.
- Two sections may not share the same post type.
- Section keys match `^[a-z][a-z0-9-]{1,30}$`.
- Numbers in range: `webp_quality` 60–95, `max_upload_mb` 1–10, `pdf_max_mb` 1–50, `upload_link_minutes` 5–60, `requests_per_minute` 5–120, `live_changes_per_day` 1–200, `retention_months` 1–36.
- At least one notification recipient (Publishers or an extra address). Emails valid.
- `support.email` required.
- Missing site objects on save → **error**. Objects that disappear *after* save (term deleted in WP) → the section shows a **warning**, the guide omits them, and pre-flight reports `config_stale`.

## Site discovery (`SiteStructure`)

Returns, cached in transient `ccb_site_structure` for 5 minutes (cleared on term and ACF group changes):

```json
{
  "post_types": [ { "name": "resource", "label": "Resources", "supports": ["title","editor","thumbnail"], "show_in_rest": true, "capability_type": "post", "taxonomies": ["resource_category","content-type"] } ],
  "taxonomies": { "resource_category": { "label": "Categories", "hierarchical": true, "terms": [ { "id": 7, "slug": "reports", "name": "Reports", "parent": 0 } ] } },
  "acf": { "available": true, "fields_by_post_type": { "resource": [ { "key": "field_68bef927b733c", "name": "authors", "label": "Author(s)", "type": "textarea", "required": false, "group": "Resource Details" } ] } },
  "elementor": { "active": true, "single_templates": [ { "id": 120, "title": "Single News", "conditions": ["…"] } ] },
  "seo_plugin": "yoast | rankmath | seopress | none"
}
```

- Post types: `get_post_types( [ 'public' => true ], 'objects' )`, excluding `attachment`, `page`, `elementor_library`, `e-landing-page`.
- Terms: `get_terms( [ 'taxonomy' => …, 'hide_empty' => false, 'number' => 500 ] )`.
- ACF: `acf_get_field_groups( [ 'post_type' => … ] )` + `acf_get_fields( $group )`. Local (PHP-registered) groups count. Supported ACF types for writing: `text`, `textarea`, `number`, `email`, `url`, `select`, `radio`, `checkbox`, `true_false`, `date_picker`, `file`, `image`, `page_link`, `post_object`, `wysiwyg`. Other types are listed as `hidden` automatically with a note in the UI.
- Elementor templates: read `elementor_library` posts of type `single`/`single-post` and their conditions (read-only, for the pre-flight check). **TO CONFIRM** meta keys on the site's Elementor Pro version.

## Publishing guide (`PublishingGuide`)

Merges config + site structure into what Claude reads (`ccb/get-publishing-guide`). For each section it builds a short plain-English `guide` in markdown, e.g.:

```markdown
## Insights (post type: resource)
- Choose at least one **Category** (resource_category) and one **Type** (content-type) from the lists provided. Never invent new ones.
- **Authors** are required (field `authors`, plain text, one per line).
- Only set **Online reader URL** if the user gives one.
- A featured image is required: landscape 16:9, at least 1200px wide, with alt text.
- PDFs for the **Downloadable file** field always go through an upload link (`ccb/get-upload-link`, purpose `field_file`).
- Headings: H2 and H3 only. UK English.
- Extra notes: {section.notes}
```

The admin UI's "What Claude sees" panel calls the same builder on the unsaved config (`GET /ccb/v1/preview/{section}`).

## Presets

`presets/*.json` are partial configs an admin can apply from the Advanced tab. Applying a preset shows a diff first and only maps post types that exist on the site. First release ships **`mp-site.json`**:

```json
{
  "sections": {
    "news": {
      "enabled": true, "label": "News", "post_type": "post",
      "default_terms": { "category": ["<resolved: slug news>"] },
      "required_taxonomies": ["category"],
      "featured_image": { "required": true, "ratio": "16:9", "min_width": 1200 },
      "limits": { "min_words": 150, "max_words": 1200, "excerpt_max_chars": 160, "max_images": 5 }
    },
    "insights": {
      "enabled": true, "label": "Insights", "post_type": "resource",
      "required_taxonomies": ["resource_category", "content-type"],
      "featured_image": { "required": true, "ratio": "16:9", "min_width": 1200 },
      "field_rules": { "authors": "required", "downloadable_file": "optional", "online_reader_url": "only_if_given" }
    },
    "visits": {
      "enabled": true, "label": "Visits", "post_type": "visits",
      "featured_image": { "required": true, "ratio": "any", "min_width": 800 },
      "limits": { "max_words": 150, "max_images": 1 },
      "extra_fields": {
        "visits_location": { "label": "Location", "type": "uk_postcode_to_latlng", "required": true, "instructions": "Ask for the UK postcode of the place visited." }
      }
    }
  },
  "writing": { "language": "en-GB", "headings": ["h2", "h3"] }
}
```

Presets reference terms by slug (`<resolved: slug …>`); they're resolved to IDs on apply, and missing slugs are reported.

The word and image limits in the preset are starting values for the client to confirm (see open questions).

## Sections on the first site

| Section | Post type | Taxonomies | Fields | Notes |
| --- | --- | --- | --- | --- |
| News | `post` | `category`, `post_tag` | None | Standard posts |
| Insights | `resource` (Causeway Insights plugin) | `resource_category`, `content-type` (hierarchical, in REST) | ACF group "Resource Details": `downloadable_file` (file, key `field_655493153192f`), `online_reader_url` (page_link, key `field_6895f57a6a12e`), `authors` (textarea, key `field_68bef927b733c`); none required in ACF; group `show_in_rest` off | Authors required by config |
| Visits | `visits` (MP Visits plugin 1.3) | None | `visits_location` post meta (JSON lat/lng), not registered, not ACF | Post type not in REST; only Visits with a location appear on the `[visits_map]` map; featured image is the map pop-up thumbnail (`map-thumb`, 250×140) |

See `docs/reference/existing-plugins.md` for details.
