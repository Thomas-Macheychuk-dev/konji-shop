#!/usr/bin/env bash
set -euo pipefail

IMAGE="${1:-}"

if [[ -z "$IMAGE" ]]; then
    echo "USAGE: $0 <docker-image>"
    exit 64
fi

EXPECTED_SHA="df806bf23d22c0420fda442811b5bf9137bd5c6ed2e64023898eac496cf58bee"
EXPECTED_RULES=1070

MAP="/etc/nginx/conf.d/00-legacy-seo-product-map.conf"
SOURCE="docker/nginx/generated/legacy-seo-product-map.conf"

echo "=== SEO-12 SOURCE GATE ==="

python3 scripts/seo/verify-seo12-recovery.py \
    --candidate "$SOURCE"

SOURCE_SHA="$(sha256sum "$SOURCE" | awk '{print $1}')"

test "$SOURCE_SHA" = "$EXPECTED_SHA"

echo "SOURCE_MAP_SHA=PASS"

echo
echo "=== SEO-12 IMAGE GATE ==="

ACTUAL_SHA="$(
    docker run --rm --entrypoint sha256sum \
        "$IMAGE" "$MAP" | awk '{print $1}'
)"

ACTUAL_RULES="$(
    docker run --rm --entrypoint cat "$IMAGE" "$MAP" |
    grep -cE '^    "[^"]+" "/products/[^"]+";$'
)"

echo "IMAGE_MAP_SHA=$ACTUAL_SHA"
echo "IMAGE_RULES=$ACTUAL_RULES"

test "$ACTUAL_SHA" = "$EXPECTED_SHA"
test "$ACTUAL_RULES" = "$EXPECTED_RULES"

echo "SEO12_WEB_IMAGE_GATE=PASS"
echo "DEPLOYMENT_AUTHORIZED=NO"
