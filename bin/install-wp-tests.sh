#!/usr/bin/env bash
# Sets up WordPress + the WordPress PHPUnit test library for the integration tests.
# Everything comes from GitHub git clones, so it works where wordpress.org is unreachable.
#
# Usage: bin/install-wp-tests.sh [wp-version]   (default 6.9.11)
# Then:  WP_TESTS_DIR=<printed path> composer test:integration
#
# Database (WP_TESTS_DB):
#   sqlite (default)  No server needed; for machines without MySQL or Docker (e.g. cloud sessions).
#   mysql             Uses DB_NAME, DB_USER, DB_PASSWORD, DB_HOST (CI runs this against MySQL 8).
# SQLite runs are a fast local check; CI's MySQL runs are the final word.
set -euo pipefail

WP_VERSION="${1:-${WP_VERSION:-6.9.11}}"
SQLITE_VERSION="${SQLITE_VERSION:-v3.1.3}"
DB_MODE="${WP_TESTS_DB:-sqlite}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="${WP_TESTS_BASE:-$ROOT/.wp-tests}"
CORE_DIR="$BASE/wordpress-$WP_VERSION"
LIB_DIR="$BASE/wordpress-tests-lib-$WP_VERSION"

mkdir -p "$BASE"

if [ ! -f "$CORE_DIR/wp-settings.php" ]; then
	echo "Fetching WordPress $WP_VERSION..."
	rm -rf "$CORE_DIR"
	git clone --quiet --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git "$CORE_DIR"
	rm -rf "$CORE_DIR/.git"
fi

if [ ! -f "$LIB_DIR/includes/bootstrap.php" ]; then
	echo "Fetching the WordPress $WP_VERSION test library..."
	rm -rf "$LIB_DIR" "$BASE/develop-tmp"
	git clone --quiet --depth 1 --filter=blob:none --sparse --branch "$WP_VERSION" \
		https://github.com/WordPress/wordpress-develop.git "$BASE/develop-tmp"
	git -C "$BASE/develop-tmp" sparse-checkout set tests/phpunit/includes tests/phpunit/data
	mkdir -p "$LIB_DIR"
	mv "$BASE/develop-tmp/tests/phpunit/includes" "$BASE/develop-tmp/tests/phpunit/data" "$LIB_DIR/"
	rm -rf "$BASE/develop-tmp"
fi

if [ "$DB_MODE" = "sqlite" ]; then
	# The SQLite integration is a monorepo; the drop-in lives in its plugin package, which links to
	# the sibling mysql-on-sqlite package, so the whole checkout is kept outside wp-content.
	SQLITE_REPO="$BASE/sqlite-database-integration-$SQLITE_VERSION"
	SQLITE_DIR="$SQLITE_REPO/packages/plugin-sqlite-database-integration"
	if [ ! -f "$SQLITE_DIR/load.php" ]; then
		echo "Fetching the SQLite database integration $SQLITE_VERSION..."
		rm -rf "$SQLITE_REPO"
		git clone --quiet --depth 1 --branch "$SQLITE_VERSION" https://github.com/WordPress/sqlite-database-integration.git "$SQLITE_REPO"
	fi

	# Install the db.php drop-in the same way the SQLite plugin does on activation.
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE_DIR#" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$SQLITE_DIR/db.copy" > "$CORE_DIR/wp-content/db.php"
	mkdir -p "$BASE/db"
	DB_CONSTANTS="define( 'DB_ENGINE', 'sqlite' );
define( 'DB_PATH', '$BASE/db/tests-$WP_VERSION.sqlite' );
define( 'DB_NAME', 'wordpress_tests' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );"
elif [ "$DB_MODE" = "mysql" ]; then
	rm -f "$CORE_DIR/wp-content/db.php"
	DB_CONSTANTS="define( 'DB_NAME', '${DB_NAME:-wordpress_tests}' );
define( 'DB_USER', '${DB_USER:-root}' );
define( 'DB_PASSWORD', '${DB_PASSWORD:-}' );
define( 'DB_HOST', '${DB_HOST:-127.0.0.1}' );"
else
	echo "Unknown WP_TESTS_DB '$DB_MODE' (use sqlite or mysql)." >&2
	exit 1
fi

cat > "$LIB_DIR/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '$CORE_DIR/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
$DB_CONSTANTS
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP

echo "Done. Run integration tests with:"
echo "  WP_TESTS_DIR=$LIB_DIR composer test:integration"
