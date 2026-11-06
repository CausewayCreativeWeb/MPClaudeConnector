#!/usr/bin/env bash
# Release-zip smoke test (docs/13 section 1a): installs the built zip on a fresh WordPress
# with no other plugins and checks activation, deactivation, uninstall and refusal on old WordPress.
#
# Usage: bin/smoke-test.sh [zip]   (default: newest dist/*.zip)
# WordPress versions come from bin/install-wp-tests-sqlite.sh's cache (fetched if missing).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ZIP="${1:-$(ls -t "$ROOT"/dist/causeway-claude-bridge-*.zip 2>/dev/null | head -1)}"
[ -f "$ZIP" ] || { echo "No zip found. Run bin/build-release.sh first." >&2; exit 1; }

SUPPORTED="${SMOKE_WP_VERSIONS:-6.9.11 7.1.4}"
TOO_OLD="${SMOKE_WP_TOO_OLD:-6.8.12}"
BASE="${WP_TESTS_BASE:-$ROOT/.wp-tests}"
SQLITE_VERSION="${SQLITE_VERSION:-v3.1.3}"
SQLITE_DIR="$BASE/sqlite-database-integration-$SQLITE_VERSION/packages/plugin-sqlite-database-integration"

fetch_core() {
	local version="$1"
	if [ ! -f "$BASE/wordpress-$version/wp-settings.php" ]; then
		git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$version" https://github.com/WordPress/WordPress.git "$BASE/wordpress-$version"
		rm -rf "$BASE/wordpress-$version/.git"
	fi
	if [ ! -f "$SQLITE_DIR/load.php" ]; then
		git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$SQLITE_VERSION" https://github.com/WordPress/sqlite-database-integration.git "$BASE/sqlite-database-integration-$SQLITE_VERSION"
	fi
}

# Creates a fresh site from a cached core and unzips the plugin into it. Prints the site path.
make_site() {
	local version="$1" site
	site="$(mktemp -d)/site"
	cp -r "$BASE/wordpress-$version" "$site"
	rm -f "$site/wp-content/db.php"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE_DIR#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$SQLITE_DIR/db.copy" > "$site/wp-content/db.php"
	cat > "$site/wp-config.php" <<PHP
<?php
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_PATH', '$site/.ht.sqlite' );
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', '$site/debug.log' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HOME', 'http://localhost' );
define( 'WP_SITEURL', 'http://localhost' );
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
	unzip -q "$ZIP" -d "$site/wp-content/plugins"
	echo "$site"
}

step() {
	echo "- $2"
	php "$ROOT/tests/smoke/step.php" "$1" "$2"
}

echo "Smoke testing $(basename "$ZIP")"

for version in $SUPPORTED; do
	echo "== WordPress $version, PHP $(php -r 'echo PHP_VERSION;') =="
	fetch_core "$version"
	site="$(make_site "$version")"
	step "$site" install
	step "$site" activate
	step "$site" check-active
	step "$site" add-users
	step "$site" deactivate
	step "$site" check-deactivated
	step "$site" uninstall-keep
	step "$site" activate
	step "$site" check-active
	step "$site" deactivate
	step "$site" uninstall-delete
	if grep -q "causeway-claude-bridge" "$site/debug.log" 2>/dev/null; then
		echo "FAIL: debug.log mentions the plugin:" >&2
		grep "causeway-claude-bridge" "$site/debug.log" >&2
		exit 1
	fi
	rm -rf "$(dirname "$site")"
done

echo "== WordPress $TOO_OLD (below minimum) =="
fetch_core "$TOO_OLD"
site="$(make_site "$TOO_OLD")"
step "$site" install
step "$site" activate-refused
rm -rf "$(dirname "$site")"

echo "Smoke test passed."
