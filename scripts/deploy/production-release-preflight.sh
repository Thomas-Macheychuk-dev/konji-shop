#!/usr/bin/env bash

# SEO-06: read-only verified live-400 production baseline.
#
# This script does NOT authorise or execute deployment.
#
# Intended execution point:
#   - approved-400 source is present on main;
#   - the exact tested web image is already live;
#   - production exposes 400 ordinary governed redirects;
#   - the staging-only 336 overlay remains disabled;
#   - the frozen pre-400 rollback image remains available.

set -uo pipefail

echo "SEO06_LIVE400_BASELINE_DEPLOY_AUTHORIZED=false"

if [[ $# -ne 1 || "${1:-}" != "--current-live400-baseline" ]]; then
    echo "SEO06_LIVE400_BASELINE_ERROR=INVALID_MODE" >&2
    exit 64
fi

LIVE400_SOURCE_HEAD="96fbe6b2cad2dfccce2c3e4e7564398263fcf5c2"

EXPECTED_SOURCE_MAP="52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009"
EXPECTED_ACTIVE_MAP="52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009"

EXPECTED_MANIFEST="285aaa614de3322d51ae29ffb45b0fdd1f80279decc21774a6a6a508dca9aafa"
EXPECTED_BASE_MANIFEST="cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7"
EXPECTED_EXTRA_MAP="35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802"

EXPECTED_LIVE_EVIDENCE="fc9aa7bbb02d14bfe52acfdd1cb6ba29f325ce032d967bbd838c9fc72984cf0e"

EXPECTED_APP="sha256:d15817d4739e9cacf44daec8680f4370daf6be9c2bc6effcd37c8052ee378cb1"
EXPECTED_WEB="sha256:ea6c62b7a8ce95d2d1728fa84e3fbe754b00b496b6ace4b3ac3a4c9ea684f9a4"
EXPECTED_WEB_ROLLBACK="sha256:e81b7c35e39b2672effb24188d3a25fc430a011b56fcac7fbe1ab8532c225616"
EXPECTED_REDIS="sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99"

APP_ROLLBACK="konji-shop-app:seo06-pre400-bbe0e4e"
WEB_ROLLBACK="konji-shop-web:seo06-pre400-bbe0e4e"
WEB_LIVE400="konji-shop-web:seo06-live400-96fbe6b"

COMPOSE="docker-compose.prod.yml"

SOURCE_MAP="docker/nginx/generated/legacy-seo-product-map.conf"
EXTRA_MAP="docker/nginx/generated/legacy-seo-staging-extra-map.conf"

MANIFEST="resources/seo/ortezka/review/seo-06c-20261006/approved-400-manifest.json"
BASE_MANIFEST="resources/seo/ortezka/review/seo-05h-20261006/approved-64-manifest.json"

LIVE_EVIDENCE="storage/app/private/scrapers/seo/ortezka/audits/seo06-production-live400-20261006/runtime-400.json"

FAILURES=0

check_equal() {
    local key="$1"
    local actual="$2"
    local expected="$3"

    if [[ "$actual" == "$expected" ]]; then
        printf '%s=PASS\n' "$key"
    else
        printf '%s=FAIL\n' "$key"
        FAILURES=$((FAILURES + 1))
    fi
}

read_sha() {
    sha256sum "$1" 2>/dev/null |
        awk '{print $1}'
}

image_id() {
    docker image inspect "$1" \
        --format '{{.Id}}' 2>/dev/null
}

echo "=== EXECUTION CONTEXT ==="

check_equal \
    "EXECUTION_USER" \
    "$(id -un)" \
    "ubuntu"

check_equal \
    "PRODUCTION_DIRECTORY" \
    "$(pwd -P)" \
    "/var/www/konji-shop"

echo "=== LIVE-400 SOURCE STATE ==="

HEAD="$(git rev-parse HEAD 2>/dev/null)"
HEAD_RC=$?

BRANCH="$(git branch --show-current 2>/dev/null)"

TRACKED="$(git status --porcelain --untracked-files=no 2>/dev/null)"
STATUS_RC=$?

git merge-base \
    --is-ancestor \
    "$LIVE400_SOURCE_HEAD" \
    "$HEAD" \
    >/dev/null 2>&1

ANCESTRY_RC=$?

check_equal "GIT_READ" "$HEAD_RC" "0"
check_equal "CHECKOUT_BRANCH" "$BRANCH" "main"
check_equal "TRACKED_STATUS_READ" "$STATUS_RC" "0"
check_equal "TRACKED_SOURCE_CLEAN" "$TRACKED" ""
check_equal "LIVE400_SOURCE_IS_ANCESTOR" "$ANCESTRY_RC" "0"

check_equal \
    "SOURCE_400_MAP_SHA" \
    "$(read_sha "$SOURCE_MAP")" \
    "$EXPECTED_SOURCE_MAP"

check_equal \
    "APPROVED_400_MANIFEST_SHA" \
    "$(read_sha "$MANIFEST")" \
    "$EXPECTED_MANIFEST"

check_equal \
    "APPROVED_64_BASE_MANIFEST_SHA" \
    "$(read_sha "$BASE_MANIFEST")" \
    "$EXPECTED_BASE_MANIFEST"

check_equal \
    "STAGING_EXTRA_336_MAP_SHA" \
    "$(read_sha "$EXTRA_MAP")" \
    "$EXPECTED_EXTRA_MAP"

check_equal \
    "LIVE400_VALIDATION_EVIDENCE_SHA" \
    "$(read_sha "$LIVE_EVIDENCE")" \
    "$EXPECTED_LIVE_EVIDENCE"

SOURCE_RULES="$(
    grep -cE \
        '^    "[^"]+" "/products/[^"]+";$' \
        "$SOURCE_MAP" 2>/dev/null
)"

EXTRA_RULES="$(
    grep -cE \
        '^    "[^"]+" "/products/[^"]+";$' \
        "$EXTRA_MAP" 2>/dev/null
)"

check_equal "SOURCE_400_RULES" "$SOURCE_RULES" "400"
check_equal "STAGING_EXTRA_336_RULES" "$EXTRA_RULES" "336"

echo "=== EFFECTIVE COMPOSE IMAGE REFERENCES ==="

IMAGES="$(
    docker compose \
        -f "$COMPOSE" \
        config --images \
        2>/dev/null
)"
COMPOSE_RC=$?

EXPECTED_IMAGES="$(
    printf '%s\n' \
        'konji-shop-app:prod' \
        'konji-shop-app:prod' \
        'konji-shop-app:prod' \
        'konji-shop-web:prod' \
        'redis:7-alpine' |
    LC_ALL=C sort
)"

ACTUAL_IMAGES="$(
    printf '%s\n' "$IMAGES" |
    LC_ALL=C sort
)"

check_equal "COMPOSE_CONFIG_READ" "$COMPOSE_RC" "0"

check_equal \
    "COMPOSE_IMAGE_REFERENCES" \
    "$ACTUAL_IMAGES" \
    "$EXPECTED_IMAGES"

echo "=== RUNNING VERIFIED LIVE-400 SERVICE IMAGES ==="

declare -A EXPECTED_SERVICE_IMAGES=(
    [redis]="$EXPECTED_REDIS"
    [app]="$EXPECTED_APP"
    [queue]="$EXPECTED_APP"
    [scheduler]="$EXPECTED_APP"
    [web]="$EXPECTED_WEB"
)

declare -A CONTAINERS=()

for SERVICE in redis app queue scheduler web; do

    CID="$(
        docker compose \
            -f "$COMPOSE" \
            ps -q "$SERVICE" \
            2>/dev/null
    )"

    CONTAINERS["$SERVICE"]="$CID"

    if [[ -z "$CID" ]]; then
        echo "CONTAINER_${SERVICE}=FAIL"
        FAILURES=$((FAILURES + 1))
        continue
    fi

    STATE="$(
        docker inspect "$CID" \
            --format '{{.State.Status}}' \
            2>/dev/null
    )"

    IMAGE="$(
        docker inspect "$CID" \
            --format '{{.Image}}' \
            2>/dev/null
    )"

    check_equal "STATE_${SERVICE}" "$STATE" "running"

    check_equal \
        "IMAGE_${SERVICE}" \
        "$IMAGE" \
        "${EXPECTED_SERVICE_IMAGES[$SERVICE]}"
done

echo "=== MUTABLE TAGS AND FROZEN ROLLBACK ==="

check_equal \
    "APP_PROD_TAG" \
    "$(image_id 'konji-shop-app:prod')" \
    "$EXPECTED_APP"

check_equal \
    "WEB_PROD_TAG" \
    "$(image_id 'konji-shop-web:prod')" \
    "$EXPECTED_WEB"

check_equal \
    "REDIS_TAG" \
    "$(image_id 'redis:7-alpine')" \
    "$EXPECTED_REDIS"

check_equal \
    "APP_PRE400_ROLLBACK" \
    "$(image_id "$APP_ROLLBACK")" \
    "$EXPECTED_APP"

check_equal \
    "WEB_PRE400_ROLLBACK" \
    "$(image_id "$WEB_ROLLBACK")" \
    "$EXPECTED_WEB_ROLLBACK"

check_equal \
    "WEB_LIVE400_IMMUTABLE" \
    "$(image_id "$WEB_LIVE400")" \
    "$EXPECTED_WEB"

echo "=== ACTIVE LIVE RUNTIME MUST BE EXACTLY 400 ==="

WEB_CID="${CONTAINERS[web]:-}"

ACTIVE_MAP=""
ACTIVE_RULES=""
REDIRECT_FLAG_COUNT="0"
STAGING_FLAG_COUNT="0"
STAGING_RUNTIME_DISABLED_COUNT="0"
NGINX_RC=1

if [[ -n "$WEB_CID" ]]; then

    ENVIRONMENT="$(
        docker inspect "$WEB_CID" \
            --format '{{range .Config.Env}}{{println .}}{{end}}' \
            2>/dev/null
    )"

    REDIRECT_FLAG_COUNT="$(
        printf '%s\n' "$ENVIRONMENT" |
        grep -Fxc \
            'LEGACY_SEO_REDIRECTS_ENABLED=true' ||
        true
    )"

    STAGING_FLAG_COUNT="$(
        printf '%s\n' "$ENVIRONMENT" |
        grep -Fxc \
            'LEGACY_SEO_STAGING_CANDIDATE_ENABLED=false' ||
        true
    )"

    ACTIVE_MAP="$(
        docker exec "$WEB_CID" \
            sha256sum \
            /etc/nginx/conf.d/00-legacy-seo-product-map.conf \
            2>/dev/null |
        awk '{print $1}'
    )"

    ACTIVE_RULES="$(
        docker exec "$WEB_CID" sh -lc \
            'grep -cE '\''^    "[^"]+" "/products/[^"]+";$'\'' /etc/nginx/conf.d/00-legacy-seo-product-map.conf' \
            2>/dev/null
    )"

    STAGING_RUNTIME_DISABLED_COUNT="$(
        docker exec "$WEB_CID" \
            grep -Fxc \
            '# Legacy SEO staging candidate redirects disabled.' \
            /etc/nginx/legacy-seo/runtime/20-staging-candidate-redirects.conf \
            2>/dev/null ||
        true
    )"

    docker exec "$WEB_CID" nginx -t \
        >/dev/null 2>&1

    NGINX_RC=$?
fi

check_equal \
    "REDIRECT_FLAG" \
    "$REDIRECT_FLAG_COUNT" \
    "1"

check_equal \
    "STAGING_CANDIDATE_FLAG_OFF" \
    "$STAGING_FLAG_COUNT" \
    "1"

check_equal \
    "ACTIVE_LIVE400_MAP_SHA" \
    "$ACTIVE_MAP" \
    "$EXPECTED_ACTIVE_MAP"

check_equal \
    "ACTIVE_LIVE400_RULES" \
    "$ACTIVE_RULES" \
    "400"

check_equal \
    "STAGING_RUNTIME_OVERLAY_OFF" \
    "$STAGING_RUNTIME_DISABLED_COUNT" \
    "1"

check_equal \
    "NGINX_CONFIGURATION" \
    "$NGINX_RC" \
    "0"

echo "=== HTTPS READINESS ==="

PROD_HEALTH="$(
    curl \
        --silent \
        --show-error \
        --noproxy '*' \
        --resolve ortezka.pl:443:127.0.0.1 \
        --connect-timeout 3 \
        --max-time 12 \
        --output /dev/null \
        --write-out '%{http_code}' \
        https://ortezka.pl/up \
        2>/dev/null
)"
PROD_HEALTH_RC=$?

STAGING_HEALTH="$(
    curl \
        --silent \
        --show-error \
        --noproxy '*' \
        --resolve staging.ortezka.pl:443:127.0.0.1 \
        --connect-timeout 3 \
        --max-time 12 \
        --output /dev/null \
        --write-out '%{http_code}' \
        https://staging.ortezka.pl/up \
        2>/dev/null
)"
STAGING_HEALTH_RC=$?

check_equal \
    "PRODUCTION_HEALTH_REQUEST" \
    "$PROD_HEALTH_RC" \
    "0"

check_equal \
    "PRODUCTION_HEALTH_HTTP" \
    "$PROD_HEALTH" \
    "200"

check_equal \
    "STAGING_HEALTH_REQUEST" \
    "$STAGING_HEALTH_RC" \
    "0"

check_equal \
    "STAGING_HEALTH_HTTP" \
    "$STAGING_HEALTH" \
    "200"

echo "=== FINAL READ-ONLY LIVE-400 BASELINE RESULT ==="

echo "SEO06_LIVE400_BASELINE_FAILURE_COUNT=$FAILURES"

if [[ "$FAILURES" -eq 0 ]]; then
    echo "SEO06_SOURCE_RULES=400"
    echo "SEO06_ACTIVE_PRODUCTION_RULES=400"
    echo "SEO06_LIVE400_BASELINE=PASS"
    echo "SEO06_LIVE400_BASELINE_DEPLOY_AUTHORIZED=false"
    exit 0
fi

echo "SEO06_LIVE400_BASELINE=FAIL"
echo "SEO06_LIVE400_BASELINE_DEPLOY_AUTHORIZED=false"

exit 1
