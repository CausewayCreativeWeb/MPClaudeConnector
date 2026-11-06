#!/usr/bin/env bash
# Builds the standalone release zip: dist/causeway-claude-bridge-<version>.zip
# The zip contains everything the plugin needs at runtime, including the Strauss-prefixed
# libraries, and nothing that's only for development (see .distignore).
#
# Distribution is a manual upload of the zip to repo.causewaycreative.co.uk (see docs/13).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SLUG="causeway-claude-bridge"
MAIN="$SLUG.php"

# One version, three places: plugin header, CCB_VERSION constant, readme.txt stable tag.
HEADER_VERSION="$(sed -n 's/^ \* Version: *//p' "$MAIN" | head -1 | tr -d '[:space:]')"
CONST_VERSION="$(sed -n "s/^define( 'CCB_VERSION', '\([^']*\)' );/\1/p" "$MAIN" | head -1)"
README_VERSION="$(sed -n 's/^Stable tag: *//p' readme.txt | head -1 | tr -d '[:space:]')"
if [ -z "$HEADER_VERSION" ] || [ "$HEADER_VERSION" != "$CONST_VERSION" ] || [ "$HEADER_VERSION" != "$README_VERSION" ]; then
	echo "Version mismatch: header '$HEADER_VERSION', CCB_VERSION '$CONST_VERSION', readme stable tag '$README_VERSION'." >&2
	exit 1
fi
VERSION="$HEADER_VERSION"

# Runtime libraries, prefixed by Strauss (runs as a Composer post-install script).
composer install --no-interaction --no-progress >/dev/null
if [ ! -f vendor-prefixed/autoload.php ]; then
	echo "vendor-prefixed/autoload.php is missing; Strauss did not run." >&2
	exit 1
fi

# Compiled admin assets (added in M8) and the bundled Claude Desktop proxy (added in M9).
command -v npm >/dev/null || { echo "Node and npm are needed to build the release (admin assets, setup pack)." >&2; exit 1; }
npm ci --no-audit --no-fund
npm run build
npm run build:extension
if [ ! -f setup-pack/extension/server/index.js ]; then
	echo "setup-pack/extension/server/index.js is missing; the extension build failed." >&2
	exit 1
fi

# Collect files: everything git knows about (tracked or new, not ignored) plus the generated folders.
mapfile -t PATTERNS < <(grep -vE '^\s*(#|$)' .distignore)
is_ignored() {
	local path="$1" pattern
	for pattern in "${PATTERNS[@]}"; do
		# shellcheck disable=SC2053 # glob match is intended
		[[ "$path" == $pattern ]] && return 0
	done
	return 1
}

STAGE="$ROOT/build/$SLUG"
rm -rf "$ROOT/build"
mkdir -p "$STAGE" "$ROOT/dist"

{
	git ls-files --cached --others --exclude-standard
	find vendor-prefixed -type f
	[ -d assets/dist ] && find assets/dist -type f
	echo setup-pack/extension/server/index.js
	echo setup-pack/extension/server/THIRD-PARTY-LICENSES.txt
	true
} | sort -u | while IFS= read -r path; do
	[ -f "$path" ] || continue
	is_ignored "$path" && continue
	mkdir -p "$STAGE/$(dirname "$path")"
	cp -p "$path" "$STAGE/$path"
done

ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(cd "$ROOT/build" && zip -rqX "$ZIP" "$SLUG")

echo "Built $ZIP"
(cd "$ROOT/dist" && sha256sum "$(basename "$ZIP")")
