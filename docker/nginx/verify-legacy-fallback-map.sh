#!/bin/sh
set -eu

MAP=/etc/nginx/conf.d/02-legacy-seo-category-fallback-map.conf
EXPECTED_SHA=51ba7619637eb57127dd94767a83a138af10b5228e7d98895fa1d3d6e652c0df
EXPECTED_RULES=14946
EXPECTED_ID_RULES=9933

fail() {
    echo "LEGACY_FALLBACK_MAP_GATE=FAIL: $1" >&2
    exit 1
}

[ -f "$MAP" ] || fail "MAP_MISSING"

ACTUAL_SHA="$(sha256sum "$MAP" | cut -d ' ' -f 1)"

[ "$ACTUAL_SHA" = "$EXPECTED_SHA" ] ||
    fail "MAP_SHA_MISMATCH"

ACTUAL_RULES="$(
    grep -cE '^    "/[^"]+" "(/products/[^"]+|/categories/[^"]+|410)";$' "$MAP"
)" || fail "INVALID_RULES"

[ "$ACTUAL_RULES" = "$EXPECTED_RULES" ] ||
    fail "RULE_COUNT_MISMATCH"

ACTUAL_ID_RULES="$(
    grep -cE '^    "[0-9]+" "(/products/[^"]+|/categories/[^"]+|410)";$' "$MAP"
)" || fail "INVALID_ID_RULES"

[ "$ACTUAL_ID_RULES" = "$EXPECTED_ID_RULES" ] ||
    fail "ID_RULE_COUNT_MISMATCH"

echo "LEGACY_FALLBACK_MAP_GATE=PASS"
