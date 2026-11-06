# Reference — existing plugins on the first site

Read-only reference. **Don't modify these plugins** (decision D9). The Bridge works with them as they are.

## MP Visits (`mp-visits`, version 1.3, Causeway Creative)

"A plugin for admins to post short updates with title, content, photo, and geographic location."

| Item | Value |
| --- | --- |
| Post type | `visits` |
| Supports | `title`, `editor`, `thumbnail` |
| Public / archive | `public => true`, `has_archive => true` |
| REST | `show_in_rest` not set → **not in the REST API** (and so uses the classic editor) |
| Capability type | `post` |
| Taxonomies | None |
| Location meta | `visits_location` post meta: a JSON string, decoded with `json_decode( $meta, true )` and passed to Leaflet as a marker location. Set from a hidden input written by `visits-admin-map.js` after a "UK postcode → Look up on map" lookup in the "Visit Location" meta box. **Not registered** with `register_post_meta()` |
| Save routine | `save_visits_meta()` on `save_post` for all post types: reads `$_POST['visits_meta_box_nonce']` and `$_POST['post_type']` without `isset` (PHP 8 warnings when saving programmatically), returns early without a valid nonce, then requires both `edit_post` and `edit_page` |
| Image size | `map-thumb` 250×140 (hard crop) — featured image used as map pop-up thumbnail |
| Front end | Shortcode `[visits_map]`: loads all `visits` posts (`posts_per_page => -1`) that have a `visits_location`; outputs title, content, thumbnail and decoded location to Leaflet 1.7.1 (unpkg) |
| Settings | Settings → Map Settings: `wac_visits_map_center` (JSON lat/lng), `wac_visits_constituency` (ONS constituency code) |
| Updates | `plugin-update-checker` v5, metadata `https://repo.causewaycreative.co.uk/?action=get_metadata&slug=mp-visits` |

Implications for the Bridge:

- Write `visits_location` directly with `update_post_meta()` **after** `wp_insert_post()`/`wp_update_post()`.
- Match the exact JSON shape `visits-admin-map.js` writes (**TO CONFIRM**: open the JS; expected `{"lat":<number>,"lng":<number>}`).
- A Visit without a location won't show on the map, so the config makes it required.
- The edit-screen save routine requires `edit_page`. The Bridge roles don't have it (by design), so AI Contributors and non-admin AI Publishers **can't change a Visit's location from the WP Admin edit screen** (the existing value is kept). They set or change it through Claude instead. Administrators are unaffected. Mention this in training and the how-to.
- Keep Visits short (content shows in the map pop-up).

## Causeway Insights (version 1.0.0, Causeway Creative)

"Registers the Resource post type, related taxonomies, and ACF fields for Resource Details." Used for the **Insights** section.

| Item | Value |
| --- | --- |
| Post type | `resource` (labels "Resources") |
| Supports | `title`, `editor`, `thumbnail`, `custom-fields`, `post-formats` |
| REST | `show_in_rest => true` |
| Capability type | default (`post`) |
| Taxonomies | `resource_category` ("Categories", hierarchical, REST), `content-type` ("Types", hierarchical, REST) |
| ACF group | "Resource Details" (`group_655493151878f`), local PHP group, location `post_type == resource`, `show_in_rest => 0` |
| Field `downloadable_file` | key `field_655493153192f`, type `file`, return format array, not required |
| Field `online_reader_url` | key `field_6895f57a6a12e`, type `page_link`, not required, "Only to be used where an online reader is set up" |
| Field `authors` | key `field_68bef927b733c`, type `textarea`, not required |
| `can_export` | false |

Implications for the Bridge:

- Write fields with `update_field( '<field key>', … )`; REST exposure isn't needed.
- Config makes `authors` required (decision D8), `online_reader_url` only-if-given, `downloadable_file` optional via PDF upload link.
- ACF must be active (pre-flight checks).
- Both taxonomies required for Insights in the MP preset.
