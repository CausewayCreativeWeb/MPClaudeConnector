#!/usr/bin/env bash
# Local WordPress for the admin screen's browser tests (docs/08, docs/13 M8).
#
# Builds a fresh SQLite site from the cached core in .wp-tests (bin/install-wp-tests.sh fetches it),
# links this checkout in as the plugin, adds the Insights/Visits fixtures and ACF, applies the MP preset,
# creates test users, then serves it with PHP's built-in server until stopped.
#
# Usage: bin/e2e-server.sh [port]   (default 8889; WP version from E2E_WP_VERSION, default 7.1.4)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8889}"
VERSION="${E2E_WP_VERSION:-7.1.4}"
BASE="${WP_TESTS_BASE:-$ROOT/.wp-tests}"
SQLITE_VERSION="${SQLITE_VERSION:-v3.1.3}"
SQLITE_DIR="$BASE/sqlite-database-integration-$SQLITE_VERSION/packages/plugin-sqlite-database-integration"
SITE="$BASE/e2e-site"

[ -f "$BASE/wordpress-$VERSION/wp-settings.php" ] || { echo "Run bin/install-wp-tests.sh $VERSION first." >&2; exit 1; }
[ -f "$SQLITE_DIR/load.php" ] || { echo "SQLite integration missing: run bin/install-wp-tests.sh first." >&2; exit 1; }
[ -f "$ROOT/assets/dist/admin.js" ] || { echo "Run npm run build first." >&2; exit 1; }

rm -rf "$SITE"
cp -r "$BASE/wordpress-$VERSION" "$SITE"
rm -f "$SITE/wp-content/db.php"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE_DIR#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	"$SQLITE_DIR/db.copy" > "$SITE/wp-content/db.php"
mkdir -p "$SITE/wp-content/mu-plugins"
cp "$ROOT"/tests/fixtures/mu-plugins/*.php "$SITE/wp-content/mu-plugins/"
ln -s "$ROOT" "$SITE/wp-content/plugins/causeway-claude-bridge"

cat > "$SITE/wp-config.php" <<PHP
<?php
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_PATH', '$SITE/.ht.sqlite' );
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', '$SITE/debug.log' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HOME', 'http://127.0.0.1:$PORT' );
define( 'WP_SITEURL', 'http://127.0.0.1:$PORT' );
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP

php "$ROOT/tests/e2e/setup-site.php" "$SITE" install
php "$ROOT/tests/e2e/setup-site.php" "$SITE" configure
echo "Serving $SITE on http://127.0.0.1:$PORT (admin / e2e-password)"
exec php -S "127.0.0.1:$PORT" -t "$SITE"
