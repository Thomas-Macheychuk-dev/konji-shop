#!/bin/sh
set -eu

MAP=/etc/nginx/conf.d/00-legacy-seo-product-map.conf
EXPECTED_SHA=df806bf23d22c0420fda442811b5bf9137bd5c6ed2e64023898eac496cf58bee
EXPECTED_RULES=1070

fail() {
    echo "SEO12_RUNTIME_MAP_GATE=FAIL: $1" >&2
    exit 1
}

[ -f "$MAP" ] || fail "MAP_MISSING"

ACTUAL_SHA="$(sha256sum "$MAP" | cut -d ' ' -f 1)"

[ "$ACTUAL_SHA" = "$EXPECTED_SHA" ] ||
    fail "MAP_SHA_MISMATCH"

ACTUAL_RULES="$(
    grep -cE '^    "[^"]+" "/products/[^"]+";$' "$MAP"
)" || fail "INVALID_RULES"

[ "$ACTUAL_RULES" = "$EXPECTED_RULES" ] ||
    fail "RULE_COUNT_MISMATCH"

echo "SEO12_RUNTIME_MAP_GATE=PASS"
