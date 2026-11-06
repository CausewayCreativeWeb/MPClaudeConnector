# 05 — Content, fields and media

## Post content

Claude sends post content as HTML. `ContentSanitiser` cleans it before every write:

1. `wp_kses()` with an allow-list built from `writing.allowed_html` (attributes: `a[href,title,rel,target]`, `img[src,alt,width,height,class]`, `figure[class]`, `blockquote[cite]`; nothing else).
2. Headings outside `writing.headings` are converted to the nearest allowed level (H1 → H2, H4–H6 → H3).
3. Inline `style` attributes, `class` values other than WordPress image classes, empty paragraphs and `&nbsp;` runs are removed when `strip_inline_styles` is on.
4. `<img>` must point at an attachment on this site (`attachment_url_to_postid()` resolves it); anything else is removed and reported as a warning.
5. Links: `href` must be `http`, `https`, `mailto` or a relative path. Apply `link_rules`.
6. Words in `words_to_avoid` produce warnings, not errors.
7. Result stored as classic HTML in `post_content`. If the site uses the block editor for that post type (`use_block_editor_for_post_type()`), wrap paragraphs, headings, lists and images in the matching core block comments so the editor opens them cleanly.

Word count = `str_word_count( wp_strip_all_tags( $content ) )`.

### Excerpt, slug and SEO fields

- Excerpt: plain text, truncated to `excerpt_max_chars` at a word boundary (warning if truncated).
- Slug: `sanitize_title()`; if omitted WordPress generates it.
- SEO fields are written to the detected SEO plugin's meta:

| Plugin | Title meta | Description meta |
| --- | --- | --- |
| Yoast | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` |
| Rank Math | `rank_math_title` | `rank_math_description` |
| SEOPress | `_seopress_titles_title` | `_seopress_titles_desc` |

No SEO plugin → SEO input ignored with a warning. **TO CONFIRM** which SEO plugin the first site uses.

## Elementor

- Claude writes post content only; the site's Elementor Theme Builder single templates render it.
- Never write any `_elementor_*` meta and never touch `elementor_library` posts, pages or kit settings.
- If a post's `_elementor_edit_mode` is `builder`, content edits are refused (`ccb_elementor_post`) because the Elementor layout would override them. Title, terms, fields and featured image may still be changed.
- Pre-flight checks that each section's post type is covered by a single template's display conditions (`docs/07`).

## Fields

### ACF fields

Written with `update_field( $field_key, $value, $post_id )` using the **field key** (not name), after validating by type:

| ACF type | Accepted input | Stored as |
| --- | --- | --- |
| `text`, `email`, `url` | string (validated email/url) | string |
| `textarea` | string, newlines kept | string |
| `wysiwyg` | HTML, sanitised as post content | string |
| `number` | number within min/max | number |
| `select`, `radio`, `checkbox` | value(s) from the field's choices | as ACF expects |
| `true_false` | boolean | 0/1 |
| `date_picker` | `YYYY-MM-DD` | ACF save format |
| `image`, `file` | attachment ID uploaded via the Bridge (or existing in the Media Library) | attachment ID |
| `page_link`, `post_object` | post ID or a URL on this site | post ID / URL |

Field rules from config: `required` must be non-empty before publish/schedule (create allows missing with a failed verification check); `only_if_given` is written only when provided; `hidden` is refused.

Insights on the first site: `authors` required (one name per line), `downloadable_file` optional (PDF through an upload link), `online_reader_url` only if the user gives it.

### Extra fields (config-defined meta)

For meta that isn't ACF. Written with `update_post_meta()` after validation.

| Type | Input | Stored |
| --- | --- | --- |
| `text` | string ≤ 500 | string |
| `textarea` | string ≤ 5000 | string |
| `date` | `YYYY-MM-DD` | string |
| `select` | one of `choices` | string |
| `uk_postcode_to_latlng` | `{ "postcode": "WA1 1AA" }` | JSON string (see below) |

### Visits location (`uk_postcode_to_latlng`)

1. Normalise the postcode (uppercase, single space) and check it against the UK postcode pattern.
2. Look it up with postcodes.io: `GET https://api.postcodes.io/postcodes/{postcode}` (no API key). Timeout 5 s. Cache results for 30 days in a transient keyed by postcode.
3. Take `result.latitude` and `result.longitude`. Refuse if not found or outside the UK bounding box (lat 49.8–60.9, lng −8.7–1.8) → `ccb_geocode_failed`.
4. Store in `visits_location` as `wp_json_encode( [ 'lat' => 53.3900, 'lng' => -2.5970 ] )`, numbers rounded to 6 decimals.

**TO CONFIRM before building:** open `visits-admin-map.js` from the MP Visits plugin and check (a) the exact JSON shape it writes to `visits_location` (key names, number vs string) and (b) which postcode service its "Look up on map" button uses. Match both exactly.

Note: MP Visits hooks `save_post` and reads `$_POST` keys without checking they exist. When the Bridge saves a post, that function returns early (no nonce) and doesn't touch `visits_location`, but PHP 8 will log "undefined array key" warnings. Don't modify that plugin (decision D9); write `visits_location` **after** `wp_insert_post()` / `wp_update_post()` returns, and note the warnings in the compatibility report.

## Terms

- Input accepts term IDs or slugs per taxonomy. Each must exist in a taxonomy registered for the section's post type. Never create terms.
- Empty taxonomy → `default_terms` applied.
- `required_taxonomies` must be non-empty before publish/schedule.
- Written with `wp_set_object_terms()` (replace, not append, on update).

## Image pipeline (`ImagePipeline`)

Applied to every image from `ccb/upload-image` and from upload links.

1. **Decode** base64 strictly (`base64_decode( $s, true )`); reject on failure.
2. **Size**: decoded bytes ≤ `media.max_upload_mb` (upload links: same limit ×3, max 15 MB).
3. **Type by content**: `finfo` MIME + `getimagesize()`; must be in `media.image_types`. HEIC, GIF, SVG and anything else → `ccb_file_type`.
4. **Write** to a private temp dir inside uploads (`ccb-tmp/`, with `index.php` and deny rules), random filename.
5. **Edit** with `wp_get_image_editor()`:
   - Resize so the longest edge ≤ `media.max_edge_px` (never upscale).
   - Strip metadata: Imagick `stripImage()`; GD drops EXIF on re-encode. Verify no EXIF/GPS remains (`exif_read_data` on JPEG output, if available).
   - Save as WebP at `media.webp_quality`. If the WebP is larger than a stripped re-encode in the original format, keep the original format.
6. **Rules**: featured images must meet the section's `min_width` and `ratio` (±3% tolerance; `any` skips ratio). Content images must be ≥ 800 px wide. Too small → error with the reason.
7. **Name** from the post slug: `{slug}-{nn}.webp` (or `upload-{date}-{nn}` with no post).
8. **Import** with `media_handle_sideload()` attached to the post (if any); set `_wp_attachment_image_alt` and caption; delete the temp file.
9. **Featured**: `set_post_thumbnail()` when requested.

Requires Imagick or GD with WebP support; pre-flight reports `image_webp_unsupported` otherwise (pipeline then keeps JPEG/PNG).

## PDFs (Insights downloads)

- Accepted **only** through an upload link with purpose `field_file`.
- Checks: `finfo` = `application/pdf`, file starts with `%PDF-`, size ≤ `media.pdf_max_mb`.
- Stored as uploaded (no processing), named `{slug}.pdf`, attached to the post, then `update_field( <file field key>, $attachment_id, $post_id )`.

## One-time upload links (`UploadLinks`)

For large images, PDFs, or when sending through Claude fails.

- Created by `ccb/get-upload-link`. Stored in `ccb_upload_links` (token hash only). Max 10 per user per hour.
- URL: `https://<site>/ccb-upload/<token>` (rewrite endpoint; `noindex`, no caching headers, excluded from page caches).
- Bound to one user, one post, one purpose (and field). Valid `media.upload_link_minutes` (default 15). **Single use.**
- Works without a browser login so it opens straight from Claude. The secret token is the authorisation; it's single use, short-lived, rate-limited and accepts only the declared file type.
- Page: neutral design using the same compiled Tailwind CSS. Shows the post title and what's expected ("Featured image: landscape, at least 1200px wide" or "PDF up to 20 MB"), a drag-and-drop area, a progress bar, and on success: "Done. Go back to Claude and say it's uploaded." Errors are shown in plain English and leave the link usable until it expires (single use counts only on success).
- Upload handled by a nonce-protected POST to the same URL; file processed by the image pipeline or PDF checks, then attached as the purpose says. Audit-logged as `upload_link_used`.
- Claude confirms by calling `ccb/get-post`.
