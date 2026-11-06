# Reference — MP Visits 1.3 source findings

Resolves the **TO CONFIRM** items about Visits in `docs/05` and `docs/reference/existing-plugins.md`. Based on the plugin source supplied by the client on 2026-11-06 (`mp-visits.php`, `visits-admin-map.js`, `visits-map.js`, `visits-settings-map.js`, `mapmarker.svg`). The plugin is still not modified (D9).

## `visits_location` format: confirmed

`visits-admin-map.js` writes the hidden `visits_location` input as:

```js
JSON.stringify({ lat: position.lat, lng: position.lng })
```

- Keys are exactly `lat` and `lng`, in that order.
- Values are JSON **numbers**, not strings, at full JavaScript float precision (e.g. `{"lat":53.390012,"lng":-2.597034}`).
- `save_visits_meta()` stores the posted string unchanged with `update_post_meta()`.
- Readers decode with `json_decode( $meta, true )` (meta box and `[visits_map]` shortcode), and `visits-map.js` uses `location.location.lat` / `.lng`.

**Bridge behaviour (matches `docs/05`):** store `wp_json_encode( [ 'lat' => (float) $lat, 'lng' => (float) $lng ] )`, rounded to 6 decimals. Rounding is safe because the readers only need numbers. Integration test: decode the stored meta and assert it is an array with exactly the float keys `lat` and `lng`, and that the post appears in the `[visits_map]` output's `visitsLocations`.

## Postcode service: confirmed

The admin "Look up on map" button calls:

```
GET https://api.postcodes.io/postcodes/{compact}
```

- `{compact}` is the postcode normalised to uppercase with single spaces, then **all spaces removed** and `encodeURIComponent`-encoded (e.g. `WA11AA`).
- Coordinates are taken from `result.latitude` and `result.longitude`.
- The client-side plausibility check is loose: 5–7 alphanumeric characters once spaces are removed, with at least one digit. postcodes.io does the real validation.

**Bridge behaviour:** same service and fields as `docs/05`. To match the plugin exactly, request the compact form (no space) and key the `ccb_geo_{postcode}` cache on the compact form. The Bridge's stricter UK postcode pattern and UK bounding-box check stay as specified. This is a detail within `docs/05`, not a change to it.

## Other facts relevant to the Bridge

| Finding | Source | Implication |
| --- | --- | --- |
| `[visits_map]` queries `visits` with `posts_per_page => -1` and no `post_status`, so on the front end only **published** Visits (plus private ones for permitted viewers) appear | `visits_shortcode()` | Drafts and Pending Review Visits from Claude never show on the map until published. Post verification can only check map inclusion for published Visits |
| Visits without `visits_location` are skipped by the map | `visits_shortcode()` | Confirms config makes the location required (preset already does) |
| Map pop-up uses the title and the featured image at size `array( 250, 140 )` (matches `map-thumb`). Content is passed to JS but not shown in the pop-up | `visits_shortcode()`, `visits-map.js` | Featured image matters more than body text for the map; keep Visits short as planned |
| The pop-up builds HTML by string concatenation with `location.title` **unescaped**, including inside an `alt` attribute | `visits-map.js` | The Bridge must store titles as plain text (strip all tags, e.g. `sanitize_text_field`) for every section. Already implied by the `docs/09` XSS requirement; add an integration test with a title containing `<img onerror>` and `"` characters |
| `save_visits_meta()` reads `$_POST['visits_meta_box_nonce']` and `$_POST['post_type']` without `isset`, returns early without a valid nonce, then requires `edit_post` **and** `edit_page` | `mp-visits.php` | As already noted: PHP 8 warnings on programmatic saves; write `visits_location` after `wp_insert_post()` / `wp_update_post()`; Bridge roles can't change the location from the WP edit screen |
| If a Visit has no location, the meta box falls back to `wac_visits_map_center` | `show_visits_meta_box()` | No effect on the Bridge |
| The plugin loads Leaflet and OpenStreetMap tiles from external CDNs, and boundary data from ArcGIS | all JS | The plugin's own behaviour, not the Bridge's. Non-negotiable 10 applies only to Bridge assets |
| Updates via `plugin-update-checker` v5 from `repo.causewaycreative.co.uk` | `mp-visits.php` | Confirms the update mechanism the Bridge reuses |

## Test fixture

`tests/fixtures/mu-plugins/` should replicate: the `visits` CPT args, the `map-thumb` size, `save_visits_meta()`, including its missing-`isset` reads so the PHP warnings show up in tests, and `visits_shortcode()` output of `visitsLocations`. The fixture is a test double; the real plugin is never changed.
