#!/bin/sh
set -eu

MAP=/etc/nginx/conf.d/02-legacy-seo-category-fallback-map.conf
EXPECTED_SHA=ff8b5fae420518047904e2109972bb288a635b3ef634abc9cf2a97eb329bd905
EXPECTED_RULES=14636

fail() {
    echo "LEGACY_FALLBACK_MAP_GATE=FAIL: $1" >&2
    exit 1
}

[ -f "$MAP" ] || fail "MAP_MISSING"

ACTUAL_SHA="$(sha256sum "$MAP" | cut -d ' ' -f 1)"

[ "$ACTUAL_SHA" = "$EXPECTED_SHA" ] ||
    fail "MAP_SHA_MISMATCH"

ACTUAL_RULES="$(
    grep -cE '^    "[^"]+" "(/categories/[^"]+|410)";$' "$MAP"
)" || fail "INVALID_RULES"

[ "$ACTUAL_RULES" = "$EXPECTED_RULES" ] ||
    fail "RULE_COUNT_MISMATCH"

echo "LEGACY_FALLBACK_MAP_GATE=PASS"
