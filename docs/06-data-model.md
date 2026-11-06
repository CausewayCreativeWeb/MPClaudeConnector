# 06 — Data model

All tables use `$wpdb->prefix`, InnoDB, `utf8mb4`, created with `dbDelta()` on activation and on version upgrade (schema version in option `ccb_db_version`).

## Tables

### `{prefix}ccb_activity` — audit log

```sql
CREATE TABLE {prefix}ccb_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at_gmt DATETIME NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  app_password_uuid CHAR(36) NULL,
  app_password_name VARCHAR(191) NULL,
  action VARCHAR(40) NOT NULL,
  ability VARCHAR(80) NULL,
  post_id BIGINT UNSIGNED NULL,
  attachment_id BIGINT UNSIGNED NULL,
  section VARCHAR(40) NULL,
  post_title_snapshot VARCHAR(255) NULL,
  status_from VARCHAR(20) NULL,
  status_to VARCHAR(20) NULL,
  result VARCHAR(10) NOT NULL,          -- success | rejected | error
  error_code VARCHAR(60) NULL,
  message VARCHAR(500) NULL,
  config_version INT UNSIGNED NULL,
  ip VARBINARY(16) NULL,
  request_ref CHAR(12) NOT NULL,
  details LONGTEXT NULL,                -- JSON: changed fields, warnings; never credentials or full content
  PRIMARY KEY (id),
  KEY created (created_at_gmt),
  KEY user_created (user_id, created_at_gmt),
  KEY post (post_id),
  KEY action_result (action, result)
);
```

`action` values: `check`, `guide`, `list`, `get`, `create`, `update`, `upload_image`, `upload_link_created`, `upload_link_used`, `prepare`, `publish`, `schedule`, `update_live`, `unpublish`, `trash`, `restore`, `config_save`, `config_restore`, `config_import`, `preset_apply`, `role_change`, `app_password_revoke`, `health_check`.

Read-only actions (`check`, `guide`, `list`, `get`, `prepare`) are logged at summary level and may be excluded from the Activity tab's default filter.

Retention: rows older than `activity.retention_months` deleted daily by `ccb_daily_prune`.

### `{prefix}ccb_config_versions`

```sql
CREATE TABLE {prefix}ccb_config_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version INT UNSIGNED NOT NULL,
  config LONGTEXT NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  note VARCHAR(255) NULL,
  source VARCHAR(20) NOT NULL,          -- ui | import | restore | preset | activation
  created_at_gmt DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY version (version)
);
```

Keep the latest 20 rows.

### `{prefix}ccb_tokens` — confirmation tokens

```sql
CREATE TABLE {prefix}ccb_tokens (
  token_hash CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  post_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(20) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  post_modified_gmt DATETIME NOT NULL,
  expires_at_gmt DATETIME NOT NULL,
  used_at_gmt DATETIME NULL,
  PRIMARY KEY (token_hash),
  KEY expires (expires_at_gmt)
);
```

### `{prefix}ccb_upload_links`

```sql
CREATE TABLE {prefix}ccb_upload_links (
  token_hash CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  post_id BIGINT UNSIGNED NOT NULL,
  purpose VARCHAR(20) NOT NULL,         -- featured_image | content_image | field_file
  field_key VARCHAR(64) NULL,
  expires_at_gmt DATETIME NOT NULL,
  used_at_gmt DATETIME NULL,
  attachment_id BIGINT UNSIGNED NULL,
  created_at_gmt DATETIME NOT NULL,
  PRIMARY KEY (token_hash),
  KEY user_created (user_id, created_at_gmt),
  KEY expires (expires_at_gmt)
);
```

### `{prefix}ccb_idempotency`

```sql
CREATE TABLE {prefix}ccb_idempotency (
  user_id BIGINT UNSIGNED NOT NULL,
  idem_key VARCHAR(64) NOT NULL,
  post_id BIGINT UNSIGNED NOT NULL,
  response LONGTEXT NOT NULL,
  created_at_gmt DATETIME NOT NULL,
  PRIMARY KEY (user_id, idem_key),
  KEY created (created_at_gmt)
);
```

Rows older than 24 hours pruned daily.

## Options

| Option | Autoload | Contents |
| --- | --- | --- |
| `ccb_config` | no | Live config JSON (`docs/04`) |
| `ccb_db_version` | yes | Table schema version |
| `ccb_health` | no | Last health check result: time, status, issues |
| `ccb_debug_until` | no | Timestamp when debug mode switches off |
| `ccb_setup_pack_version` | no | Version of the bundled extension and skill |

## Transients and cache

| Key | TTL | Contents |
| --- | --- | --- |
| `ccb_site_structure` | 5 min | Discovery result |
| `ccb_geo_{postcode}` | 30 days | Postcode lookup |
| `ccb_rl_{user}_{minute}` | 2 min | Request counter (object cache preferred) |
| `ccb_daily_{user}_{date}` | 2 days | Live-change counter |

## Post meta written by the Bridge

| Meta | On | Purpose |
| --- | --- | --- |
| `_ccb_created_via` | posts | `claude`, plus user ID and app password name (JSON) |
| `_ccb_last_change` | posts | Last Bridge action and time |
| `_ccb_source` | attachments | `ccb_upload` or `ccb_upload_link` |

The Bridge also writes, through WordPress APIs: post fields, terms, thumbnails, ACF fields (via `update_field`), config-defined extra meta (e.g. `visits_location`) and SEO plugin meta. Never `_elementor_*`.

## Activation

1. Check WP ≥ 6.9, PHP ≥ 8.1; refuse activation with a clear message otherwise.
2. Create or upgrade tables.
3. Create or update roles and grant admin caps.
4. If `ccb_config` is missing, store an empty default config (no sections enabled) as version 1.
5. Schedule `ccb_daily_health` (03:15 site time) and `ccb_daily_prune` (03:45).
6. Register the upload-link rewrite and flush rewrite rules once.

## Deactivation

Unschedule cron jobs. Keep data and roles. The MCP server stops existing, so Claude loses access immediately.

## Uninstall (`uninstall.php`)

Only if the admin ticked "Delete all data on uninstall" (Advanced tab, default off): drop the five tables, delete `ccb_*` options and transients, remove the two roles (reassign `ccb_contributor` users to WordPress's Contributor role and `ccb_publisher` users to Editor first, so nobody gains rights) and remove `ccb_*` caps from administrators. Post meta prefixed `_ccb_` is deleted. Posts, media and field values are never deleted.
