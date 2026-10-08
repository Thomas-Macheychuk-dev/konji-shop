#!/usr/bin/env bash

# SEO-07H: read-only 659-rule source / 400-rule live-runtime preflight.
#
# This script DOES NOT execute deployment.
# It proves that:
#   - authorised 659-rule source is checked out on main;
#   - the existing 400-rule production runtime is still live;
#   - the tested SEO-07G staging proof and owner authorization are pinned;
#   - rollback to the current live-400 web image remains available;
#   - staging candidate overlay remains disabled.

set -uo pipefail

if [[ $# -ne 1 || "${1:-}" != "--candidate-659-predeploy" ]]; then
    echo "SEO07H_PREDEPLOY_ERROR=INVALID_MODE" >&2
    echo "SEO07H_PREDEPLOY_DEPLOY_AUTHORIZED=false"
    exit 64
fi

SOURCE_BASE="8f17139032dc076eb99b895b53c4f6aba8b224fb"

EXPECTED_SOURCE_MAP="2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e"
EXPECTED_ACTIVE_MAP="52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009"

EXPECTED_MANIFEST="dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184"
EXPECTED_PROOF="400a8fb07cca664fcab20be2612e515806d068b5f526e867c8df8f7693678cb1"
EXPECTED_AUTH="f8fd7f0d43ac0a12f80abb4e40ca1a6264f4b93bdf83e7c4ed2ea0b12e6f8bde"
EXPECTED_EXTRA_MAP="35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802"

EXPECTED_APP="sha256:d15817d4739e9cacf44daec8680f4370daf6be9c2bc6effcd37c8052ee378cb1"
EXPECTED_WEB="sha256:1cb8e6078b624388354f7351bd72bac95fe2c64abeded5d8563e1e52183e9854"
EXPECTED_REDIS="sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99"

WEB_LIVE400_ROLLBACK="konji-shop-web:maphash-live-cb0a34d"

COMPOSE="docker-compose.prod.yml"

SOURCE_MAP="docker/nginx/generated/legacy-seo-product-map.conf"
EXTRA_MAP="docker/nginx/generated/legacy-seo-staging-extra-map.conf"

MANIFEST="resources/seo/ortezka/review/seo-07f-20261007/approved-659-manifest.json"
PROOF="resources/seo/ortezka/review/seo-07g-20261007/staging-runtime-proof.json"
AUTH="resources/seo/ortezka/review/seo-07h-20261008/production-promotion-659.json"

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
    docker image inspect "$1"         --format '{{.Id}}' 2>/dev/null
}

echo "SEO07H_PREDEPLOY_AUTHORIZATION_PRESENT=true"

echo "=== EXECUTION CONTEXT ==="

check_equal     "EXECUTION_USER"     "$(id -un)"     "ubuntu"

check_equal     "PRODUCTION_DIRECTORY"     "$(pwd -P)"     "/var/www/konji-shop"

echo "=== AUTHORISED 659 SOURCE STATE ==="

HEAD="$(git rev-parse HEAD 2>/dev/null)"
HEAD_RC=$?

BRANCH="$(git branch --show-current 2>/dev/null)"

TRACKED="$(git status --porcelain --untracked-files=no 2>/dev/null)"
STATUS_RC=$?

git merge-base     --is-ancestor     "$SOURCE_BASE"     "$HEAD"     >/dev/null 2>&1

ANCESTRY_RC=$?

if [[ "$HEAD" != "$SOURCE_BASE" ]]; then
    ADVANCED="yes"
else
    ADVANCED="no"
fi

check_equal "GIT_READ" "$HEAD_RC" "0"
check_equal "CHECKOUT_BRANCH" "$BRANCH" "main"
check_equal "TRACKED_STATUS_READ" "$STATUS_RC" "0"
check_equal "TRACKED_SOURCE_CLEAN" "$TRACKED" ""
check_equal "SOURCE_BASE_IS_ANCESTOR" "$ANCESTRY_RC" "0"
check_equal "CANDIDATE_CHECKOUT_ADVANCED" "$ADVANCED" "yes"

check_equal     "SOURCE_659_MAP_SHA"     "$(read_sha "$SOURCE_MAP")"     "$EXPECTED_SOURCE_MAP"

check_equal     "APPROVED_659_MANIFEST_SHA"     "$(read_sha "$MANIFEST")"     "$EXPECTED_MANIFEST"

check_equal     "SEO07G_STAGING_PROOF_SHA"     "$(read_sha "$PROOF")"     "$EXPECTED_PROOF"

check_equal     "SEO07H_AUTHORIZATION_SHA"     "$(read_sha "$AUTH")"     "$EXPECTED_AUTH"

check_equal     "HISTORICAL_STAGING_EXTRA_MAP_SHA"     "$(read_sha "$EXTRA_MAP")"     "$EXPECTED_EXTRA_MAP"

SOURCE_RULES="$(
    grep -cE         '^    "[^"]+" "/products/[^"]+";$'         "$SOURCE_MAP" 2>/dev/null
)"

EXTRA_RULES="$(
    grep -cE         '^    "[^"]+" "/products/[^"]+";$'         "$EXTRA_MAP" 2>/dev/null
)"

check_equal "SOURCE_659_RULES" "$SOURCE_RULES" "659"
check_equal "HISTORICAL_STAGING_EXTRA_RULES" "$EXTRA_RULES" "336"

echo "=== EFFECTIVE COMPOSE IMAGE REFERENCES ==="

IMAGES="$(
    docker compose         -f "$COMPOSE"         config --images         2>/dev/null
)"
COMPOSE_RC=$?

EXPECTED_IMAGES="$(
    printf '%s\n'         'konji-shop-app:prod'         'konji-shop-app:prod'         'konji-shop-app:prod'         'konji-shop-web:prod'         'redis:7-alpine' |
    LC_ALL=C sort
)"

ACTUAL_IMAGES="$(
    printf '%s\n' "$IMAGES" |
    LC_ALL=C sort
)"

check_equal "COMPOSE_CONFIG_READ" "$COMPOSE_RC" "0"

check_equal     "COMPOSE_IMAGE_REFERENCES"     "$ACTUAL_IMAGES"     "$EXPECTED_IMAGES"

echo "=== RUNNING LIVE-400 SERVICE IMAGES ==="

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
        docker compose             -f "$COMPOSE"             ps -q "$SERVICE"             2>/dev/null
    )"

    CONTAINERS["$SERVICE"]="$CID"

    if [[ -z "$CID" ]]; then
        echo "CONTAINER_${SERVICE}=FAIL"
        FAILURES=$((FAILURES + 1))
        continue
    fi

    STATE="$(
        docker inspect "$CID"             --format '{{.State.Status}}'             2>/dev/null
    )"

    IMAGE="$(
        docker inspect "$CID"             --format '{{.Image}}'             2>/dev/null
    )"

    check_equal "STATE_${SERVICE}" "$STATE" "running"

    check_equal         "IMAGE_${SERVICE}"         "$IMAGE"         "${EXPECTED_SERVICE_IMAGES[$SERVICE]}"
done

echo "=== MUTABLE TAGS AND LIVE-400 ROLLBACK ==="

check_equal     "APP_PROD_TAG"     "$(image_id 'konji-shop-app:prod')"     "$EXPECTED_APP"

check_equal     "WEB_PROD_TAG"     "$(image_id 'konji-shop-web:prod')"     "$EXPECTED_WEB"

check_equal     "REDIS_TAG"     "$(image_id 'redis:7-alpine')"     "$EXPECTED_REDIS"

check_equal     "WEB_LIVE400_ROLLBACK"     "$(image_id "$WEB_LIVE400_ROLLBACK")"     "$EXPECTED_WEB"

echo "=== ACTIVE RUNTIME MUST STILL BE EXACTLY 400 ==="

WEB_CID="${CONTAINERS[web]:-}"

ACTIVE_MAP=""
ACTIVE_RULES=""
REDIRECT_FLAG_COUNT="0"
STAGING_FLAG_COUNT="0"
STAGING_RUNTIME_DISABLED_COUNT="0"
NGINX_RC=1

if [[ -n "$WEB_CID" ]]; then

    ENVIRONMENT="$(
        docker inspect "$WEB_CID"             --format '{{range .Config.Env}}{{println .}}{{end}}'             2>/dev/null
    )"

    REDIRECT_FLAG_COUNT="$(
        printf '%s\n' "$ENVIRONMENT" |
        grep -Fxc             'LEGACY_SEO_REDIRECTS_ENABLED=true' ||
        true
    )"

    STAGING_FLAG_COUNT="$(
        printf '%s\n' "$ENVIRONMENT" |
        grep -Fxc             'LEGACY_SEO_STAGING_CANDIDATE_ENABLED=false' ||
        true
    )"

    ACTIVE_MAP="$(
        docker exec "$WEB_CID"             sha256sum             /etc/nginx/conf.d/00-legacy-seo-product-map.conf             2>/dev/null |
        awk '{print $1}'
    )"

    ACTIVE_RULES="$(
        docker exec "$WEB_CID" sh -lc             'grep -cE '\''^    "[^"]+" "/products/[^"]+";$'\'' /etc/nginx/conf.d/00-legacy-seo-product-map.conf'             2>/dev/null
    )"

    STAGING_RUNTIME_DISABLED_COUNT="$(
        docker exec "$WEB_CID"             grep -Fxc             '# Legacy SEO staging candidate redirects disabled.'             /etc/nginx/legacy-seo/runtime/20-staging-candidate-redirects.conf             2>/dev/null ||
        true
    )"

    docker exec "$WEB_CID" nginx -t         >/dev/null 2>&1

    NGINX_RC=$?
fi

check_equal     "REDIRECT_FLAG"     "$REDIRECT_FLAG_COUNT"     "1"

check_equal     "STAGING_CANDIDATE_FLAG_OFF"     "$STAGING_FLAG_COUNT"     "1"

check_equal     "ACTIVE_LIVE400_MAP_SHA"     "$ACTIVE_MAP"     "$EXPECTED_ACTIVE_MAP"

check_equal     "ACTIVE_LIVE400_RULES"     "$ACTIVE_RULES"     "400"

check_equal     "STAGING_RUNTIME_OVERLAY_OFF"     "$STAGING_RUNTIME_DISABLED_COUNT"     "1"

check_equal     "NGINX_CONFIGURATION"     "$NGINX_RC"     "0"

echo "=== HTTPS READINESS ==="

PROD_HEALTH="$(
    curl         --silent         --show-error         --noproxy '*'         --resolve ortezka.pl:443:127.0.0.1         --connect-timeout 3         --max-time 12         --output /dev/null         --write-out '%{http_code}'         https://ortezka.pl/up         2>/dev/null
)"
PROD_HEALTH_RC=$?

STAGING_HEALTH="$(
    curl         --silent         --show-error         --noproxy '*'         --resolve staging.ortezka.pl:443:127.0.0.1         --connect-timeout 3         --max-time 12         --output /dev/null         --write-out '%{http_code}'         https://staging.ortezka.pl/up         2>/dev/null
)"
STAGING_HEALTH_RC=$?

check_equal     "PRODUCTION_HEALTH_REQUEST"     "$PROD_HEALTH_RC"     "0"

check_equal     "PRODUCTION_HEALTH_HTTP"     "$PROD_HEALTH"     "200"

check_equal     "STAGING_HEALTH_REQUEST"     "$STAGING_HEALTH_RC"     "0"

check_equal     "STAGING_HEALTH_HTTP"     "$STAGING_HEALTH"     "200"

echo "=== FINAL READ-ONLY SEO-07H PREDEPLOY RESULT ==="

echo "SEO07H_PREDEPLOY_FAILURE_COUNT=$FAILURES"

if [[ "$FAILURES" -eq 0 ]]; then
    echo "SEO07H_SOURCE_RULES=659"
    echo "SEO07H_ACTIVE_PRODUCTION_RULES=400"
    echo "SEO07H_PREDEPLOY_CANDIDATE=PASS"
    echo "SEO07H_PREDEPLOY_DEPLOY_AUTHORIZED=true"
    exit 0
fi

echo "SEO07H_PREDEPLOY_CANDIDATE=FAIL"
echo "SEO07H_PREDEPLOY_DEPLOY_AUTHORIZED=false"

exit 1
