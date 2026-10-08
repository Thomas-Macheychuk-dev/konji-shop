#!/usr/bin/env bash

# SEO-08F: read-only 708-source / live-659-runtime production preflight.
# This script DOES NOT deploy or mutate runtime.

set -uo pipefail

if [[ $# -ne 1 || "${1:-}" != "--candidate-708-predeploy" ]]; then
    echo "SEO08F_PREDEPLOY_ERROR=INVALID_MODE" >&2
    echo "SEO08F_PREDEPLOY_DEPLOY_AUTHORIZED=false"
    exit 64
fi

SOURCE_BASE="17bef8f4b0a52057973a775f87ab6fb14a0fb4ca"
EXPECTED_SOURCE_MAP="059d34d5e2b49301a4da744d904fd4e43a67c9005a10cb54e6ee90b6e2d4edff"
EXPECTED_ACTIVE_MAP="2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e"
EXPECTED_MANIFEST="ee80e33e585e29c382c170372cb9de008d81da0438123275f31e6dc1129aa8db"
EXPECTED_PROOF="dd4fb3a95d55fd4540132951790de7b9d7656c0463f6d661f9fc83a8e316baac"
EXPECTED_AUTH="7116b063d95aec383cabaf0a9a50ef2e947ea4623afa389c79c80c6197bd7530"
EXPECTED_LIVE_WEB="sha256:a16c4f9c7459f7715925becc5fb414ec11a5cffa56e251b6ef3cd6ca8b8a70e6"

COMPOSE="docker-compose.prod.yml"
MAP="docker/nginx/generated/legacy-seo-product-map.conf"
MANIFEST="resources/seo/ortezka/review/seo-08d-20261008/approved-708-manifest.json"
PROOF="resources/seo/ortezka/review/seo-08e-20261008/staging-runtime-proof.json"
AUTH="resources/seo/ortezka/review/seo-08f-20261008/production-promotion-708.json"

FAILURES=0

check_equal() {
    local key="$1"
    local actual="$2"
    local expected="$3"

    if [[ "$actual" == "$expected" ]]; then
        printf '%s=PASS\n' "$key"
    else
        printf '%s=FAIL expected=%s actual=%s\n' "$key" "$expected" "$actual"
        FAILURES=$((FAILURES + 1))
    fi
}

read_sha() {
    sha256sum "$1" 2>/dev/null | awk '{print $1}'
}

echo "=== SEO-08F PRODUCTION 708 PREDEPLOY ==="
echo "MUTATION=NONE"

check_equal "EXECUTION_USER" "$(id -un)" "ubuntu"
check_equal "PRODUCTION_DIRECTORY" "$(pwd -P)" "/var/www/konji-shop"
check_equal "CHECKOUT_BRANCH" "$(git branch --show-current 2>/dev/null)" "main"
check_equal "TRACKED_SOURCE_CLEAN" "$(git status --porcelain --untracked-files=no 2>/dev/null)" ""

HEAD="$(git rev-parse HEAD 2>/dev/null)"
git merge-base --is-ancestor "$SOURCE_BASE" "$HEAD" >/dev/null 2>&1
check_equal "SOURCE_BASE_IS_ANCESTOR" "$?" "0"

check_equal "SOURCE_708_MAP_SHA" "$(read_sha "$MAP")" "$EXPECTED_SOURCE_MAP"
check_equal "APPROVED_708_MANIFEST_SHA" "$(read_sha "$MANIFEST")" "$EXPECTED_MANIFEST"
check_equal "SEO08E_STAGING_PROOF_SHA" "$(read_sha "$PROOF")" "$EXPECTED_PROOF"
check_equal "SEO08F_AUTHORIZATION_SHA" "$(read_sha "$AUTH")" "$EXPECTED_AUTH"

SOURCE_RULES="$(grep -cE '^    "[^"]+" "/products/[^"]+";$' "$MAP" 2>/dev/null)"
check_equal "SOURCE_708_RULES" "$SOURCE_RULES" "708"

WEB_CID="$(docker compose -f "$COMPOSE" ps -q web 2>/dev/null)"
if [[ -z "$WEB_CID" ]]; then
    echo "WEB_CONTAINER=FAIL"
    FAILURES=$((FAILURES + 1))
else
    ACTIVE_IMAGE="$(docker inspect "$WEB_CID" --format '{{.Image}}' 2>/dev/null)"
    ACTIVE_MAP="$(docker exec "$WEB_CID" sha256sum /etc/nginx/conf.d/00-legacy-seo-product-map.conf 2>/dev/null | awk '{print $1}')"
    ACTIVE_RULES="$(docker exec "$WEB_CID" sh -lc 'grep -cE '\''^    "[^"]+" "/products/[^"]+";$'\'' /etc/nginx/conf.d/00-legacy-seo-product-map.conf' 2>/dev/null)"
    STAGING_OFF="$(docker exec "$WEB_CID" grep -Fxc '# Legacy SEO staging candidate redirects disabled.' /etc/nginx/legacy-seo/runtime/20-staging-candidate-redirects.conf 2>/dev/null || true)"

    check_equal "ACTIVE_LIVE659_WEB_IMAGE" "$ACTIVE_IMAGE" "$EXPECTED_LIVE_WEB"
    check_equal "ACTIVE_LIVE659_MAP_SHA" "$ACTIVE_MAP" "$EXPECTED_ACTIVE_MAP"
    check_equal "ACTIVE_LIVE659_RULES" "$ACTIVE_RULES" "659"
    check_equal "STAGING_RUNTIME_OVERLAY_OFF" "$STAGING_OFF" "1"

    docker exec "$WEB_CID" nginx -t >/dev/null 2>&1
    check_equal "NGINX_CONFIGURATION" "$?" "0"
fi

PROD_ORIGIN="$(curl --silent --show-error --noproxy '*' --resolve ortezka.pl:443:127.0.0.1 --connect-timeout 3 --max-time 12 --output /dev/null --write-out '%{http_code}' https://ortezka.pl/up 2>/dev/null)"
STAGING_ORIGIN="$(curl --silent --show-error --noproxy '*' --resolve staging.ortezka.pl:443:127.0.0.1 --connect-timeout 3 --max-time 12 --output /dev/null --write-out '%{http_code}' https://staging.ortezka.pl/up 2>/dev/null)"
PROD_PUBLIC="$(curl --silent --show-error --max-time 12 --output /dev/null --write-out '%{http_code}' https://ortezka.pl/up 2>/dev/null || true)"

check_equal "PRODUCTION_ORIGIN_HTTP" "$PROD_ORIGIN" "200"
check_equal "STAGING_ORIGIN_HTTP" "$STAGING_ORIGIN" "200"
echo "PRODUCTION_PUBLIC_HTTP=$PROD_PUBLIC"

echo "SEO08F_PREDEPLOY_FAILURE_COUNT=$FAILURES"
if [[ "$FAILURES" -eq 0 ]]; then
    echo "SEO08F_SOURCE_RULES=708"
    echo "SEO08F_ACTIVE_PRODUCTION_RULES=659"
    echo "SEO08F_PREDEPLOY_CANDIDATE=PASS"
    echo "SEO08F_PREDEPLOY_DEPLOY_AUTHORIZED=true"
    exit 0
fi

echo "SEO08F_PREDEPLOY_CANDIDATE=FAIL"
echo "SEO08F_PREDEPLOY_DEPLOY_AUTHORIZED=false"
exit 1
