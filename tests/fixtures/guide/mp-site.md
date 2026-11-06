## News (post type: post)
- Choose at least one **Category** (category) from the lists provided. Never invent new ones.
- If no **Category** is chosen, it defaults to News.
- You may choose **Tag** (post_tag) from the list provided. Never invent new ones.
- A featured image is required: landscape 16:9, at least 1200px wide, with alt text.
- Length: 150 to 1,200 words.
- Excerpt: up to 160 characters.
- At most 5 images in the post.
- Headings: H2 and H3 only. UK English.

## Insights (post type: resource)
- Choose at least one **Category** (resource_category) and **Type** (content-type) from the lists provided. Never invent new ones.
- **Downloadable file** (field `downloadable_file`) is optional. Files for it always go through an upload link (`ccb/get-upload-link`, purpose `field_file`).
- Only set **Online reader URL** (field `online_reader_url`, a post ID or a URL on this site) if the user gives one. Only to be used where an online reader is set up.
- **Author(s)** is required (field `authors`, plain text, line breaks kept).
- A featured image is required: landscape 16:9, at least 1200px wide, with alt text.
- Excerpt: up to 160 characters.
- At most 5 images in the post.
- Headings: H2 and H3 only. UK English.

## Visits (post type: visits)
- **Location** is required: send the UK postcode as `{ "postcode": "…" }` in field `visits_location`. The site looks up the map position. Ask for the UK postcode of the place visited.
- A featured image is required: at least 800px wide, with alt text.
- Length: at most 150 words.
- Excerpt: up to 160 characters.
- At most 1 image in the post.
- Headings: H2 and H3 only. UK English.
