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

\$table_prefix = 'wp_';
// Behind a TLS-terminating proxy: trust X-Forwarded-Proto (the smoke test's stand-in for HTTPS).
if ( isset( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) { \$_SERVER['HTTPS'] = 'on'; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
	unzip -q "$ZIP" -d "$site/wp-content/plugins"
	echo "$site"
}

# Talks to the plugin's MCP endpoint over real HTTP, authenticating with an Application Password,
# the way the Claude Desktop extension will. PHP's built-in server stands in for the web server;
# X-Forwarded-Proto marks the request as HTTPS (see the wp-config snippet above).
http_checks() {
	local site="$1" port log url credentials
	port=$(( 20000 + RANDOM % 20000 ))
	log="$site/http.log"
	php -S "127.0.0.1:$port" -t "$site" >"$log" 2>&1 &
	local server=$!
	trap 'kill $server 2>/dev/null || true' RETURN
	for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$port/" && break; sleep 0.1; done

	url="http://127.0.0.1:$port/?rest_route=/mcp/ccb"
	credentials="$(cat "$site/.smoke-credentials")"
	local headers=(-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -H 'X-Forwarded-Proto: https')

	local status
	status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${headers[@]}" "$url" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
	[ "$status" = "401" ] || { echo "FAIL: unauthenticated request returned $status, expected 401" >&2; return 1; }
	echo "  ok  Unauthenticated request gets 401"

	local session
	session=$(curl -s -D - -o /dev/null -X POST "${headers[@]}" -u "$credentials" "$url" \
		-d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1.0"}}}' \
		| tr -d '\r' | awk 'tolower($1)=="mcp-session-id:"{print $2}')
	[ -n "$session" ] || { echo "FAIL: initialize returned no session" >&2; return 1; }
	echo "  ok  MCP initialize with an Application Password"
	local mcp=("${headers[@]}" -H "Mcp-Session-Id: $session" -H 'MCP-Protocol-Version: 2025-11-25' -u "$credentials")
	curl -s -o /dev/null -X POST "${mcp[@]}" "$url" -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'

	local tools
	tools=$(curl -s -X POST "${mcp[@]}" "$url" -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
		| php -r '$b=json_decode(stream_get_contents(STDIN),true); $n=array_column($b["result"]["tools"]??[],"name"); sort($n); echo implode(",",$n);')
	[ "$tools" = "ccb-check-connection,ccb-get-post,ccb-get-publishing-guide,ccb-list-posts,ccb-list-sections,ccb-list-terms" ] \
		|| { echo "FAIL: unexpected tools: $tools" >&2; return 1; }
	echo "  ok  tools/list has only the ccb tools: $tools"

	local check
	check=$(curl -s -X POST "${mcp[@]}" "$url" \
		-d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"ccb-check-connection","arguments":{"skill_version":"0.1.0"}}}' \
		| php -r '$b=json_decode(stream_get_contents(STDIN),true); $d=$b["result"]["structuredContent"]??json_decode($b["result"]["content"][0]["text"]??"",true); echo ($d["user"]["display_name"]??"?")."|".($d["credential"]["name"]??"?")."|".implode(",",array_column($d["sections"]??[],"key"));')
	[ "$check" = "Sarah Jones|Sarah - office laptop|news" ] || { echo "FAIL: check-connection returned '$check'" >&2; return 1; }
	echo "  ok  check-connection over HTTP: $check"

	status=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${headers[@]}" -u "sarah:wrong-password" "$url" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
	[ "$status" = "401" ] || { echo "FAIL: wrong password returned $status" >&2; return 1; }
	echo "  ok  Wrong Application Password gets 401"
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
	step "$site" configure
	echo "- http (MCP over HTTP with an Application Password)"
	http_checks "$site"
	step "$site" check-activity
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
