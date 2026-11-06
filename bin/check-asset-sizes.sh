#!/usr/bin/env bash
# Admin asset budgets (docs/08 "Tooling"): CSS < 40 KB, JS < 60 KB, minified (KB = 1024 bytes).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail=0
check() {
	local file="$1" limit="$2" size
	size=$(wc -c < "$ROOT/$file")
	if [ "$size" -ge "$limit" ]; then
		echo "FAIL $file is $size bytes (limit $limit)"
		fail=1
	else
		echo "ok   $file is $size bytes (limit $limit)"
	fi
}
check assets/dist/admin.css $(( 40 * 1024 ))
check assets/dist/admin.js $(( 60 * 1024 ))
exit $fail
