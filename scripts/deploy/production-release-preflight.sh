#!/usr/bin/env bash

# SEO-03B: read-only production runtime baseline verification.
# This script does NOT authorise or execute a deployment.

set -uo pipefail

echo "P6S_CB5_DEPLOY_AUTHORIZED=false"

if [[ $# -ne 1 || "${1:-}" != "--current-baseline" ]]; then
    echo "P6S_CB5_ERROR=INVALID_MODE" >&2
    exit 64
fi

EXPECTED_HEAD="6a32ed2a932c0c82af6a176904a6edb5087778ee"

EXPECTED_APP="sha256:f72e5f97d3d544620a20172c731d20b29aca91fe796bfd518911e40e47362092"

EXPECTED_WEB="sha256:80e3ed87af3c8fd63e28dd690268a9d5e657a2b3691dda98c40cff7f7e303504"

EXPECTED_REDIS="sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99"

EXPECTED_ROLLBACK="sha256:769c0d75699d09872f8e779993b05accd64a2cf68e2428ff3badc2a801783ed5"

EXPECTED_MAP="ece3d1558b317f2e7517e0ac6397e18f936e55a2ae258c51ffd28ef7db10c196"

EXPECTED_MANIFEST="6a1ca8e5c7fa2df92148490634dabb0d648f6d1647d6cce4a6bc5c3da10e674d"

COMPOSE="docker-compose.prod.yml"

MAP="docker/nginx/generated/legacy-seo-product-map.conf"

MANIFEST="resources/seo/ortezka/review/seo-03b-p4-20261003/approved-58-manifest.json"

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
    local file="$1"

    sha256sum "$file" 2>/dev/null | awk '{print $1}'
}

image_id() {
    docker image inspect "$1" \
        --format '{{.Id}}' 2>/dev/null
}

echo "=== BASELINE EXECUTION CONTEXT ==="

check_equal \
    "EXECUTION_USER" \
    "$(id -un)" \
    "ubuntu"

check_equal \
    "PRODUCTION_DIRECTORY" \
    "$(pwd -P)" \
    "/var/www/konji-shop"

echo "=== SOURCE INTEGRITY ==="

HEAD="$(git rev-parse HEAD 2>/dev/null)"
HEAD_RC=$?

BRANCH="$(git branch --show-current 2>/dev/null)"

TRACKED="$(git status --porcelain --untracked-files=no 2>/dev/null)"
STATUS_RC=$?

check_equal "GIT_READ" "$HEAD_RC" "0"
check_equal "CHECKOUT_HEAD" "$HEAD" "$EXPECTED_HEAD"
check_equal "CHECKOUT_BRANCH" "$BRANCH" "main"
check_equal "TRACKED_STATUS_READ" "$STATUS_RC" "0"
check_equal "TRACKED_SOURCE_CLEAN" "$TRACKED" ""

check_equal \
    "SOURCE_MAP_SHA" \
    "$(read_sha "$MAP")" \
    "$EXPECTED_MAP"

check_equal \
    "APPROVAL_MANIFEST_SHA" \
    "$(read_sha "$MANIFEST")" \
    "$EXPECTED_MANIFEST"

echo "=== EFFECTIVE COMPOSE IMAGE REFERENCES ==="

IMAGES="$(docker compose -f "$COMPOSE" config --images 2>/dev/null)"
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
    printf '%s\n' "$IMAGES" | LC_ALL=C sort
)"

check_equal "COMPOSE_CONFIG_READ" "$COMPOSE_RC" "0"

check_equal \
    "COMPOSE_IMAGE_REFERENCES" \
    "$ACTUAL_IMAGES" \
    "$EXPECTED_IMAGES"

echo "=== RUNNING SERVICE IMAGE IDENTITIES ==="

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
        docker compose -f "$COMPOSE" ps -q "$SERVICE" 2>/dev/null
    )"

    CONTAINERS["$SERVICE"]="$CID"

    if [[ -z "$CID" ]]; then
        echo "CONTAINER_${SERVICE}=FAIL"
        FAILURES=$((FAILURES + 1))
        continue
    fi

    STATE="$(
        docker inspect "$CID" \
            --format '{{.State.Status}}' 2>/dev/null
    )"

    IMAGE="$(
        docker inspect "$CID" \
            --format '{{.Image}}' 2>/dev/null
    )"

    check_equal "STATE_${SERVICE}" "$STATE" "running"

    check_equal \
        "IMAGE_${SERVICE}" \
        "$IMAGE" \
        "${EXPECTED_SERVICE_IMAGES[$SERVICE]}"
done

echo "=== MUTABLE TAG DRIFT AND ROLLBACK ==="

check_equal \
    "APP_PROD_TAG" \
    "$(image_id 'konji-shop-app:prod')" \
    "$EXPECTED_APP"

check_equal \
    "WEB_PROD_TAG" \
    "$(image_id 'konji-shop-web:prod')" \
    "$EXPECTED_WEB"

check_equal \
    "WEB_ROLLBACK_IMAGE" \
    "$(image_id 'konji-shop-web:seo03b-pre58-7e009b8')" \
    "$EXPECTED_ROLLBACK"

echo "=== ACTIVE SEO CONFIGURATION ==="

WEB_CID="${CONTAINERS[web]:-}"

ACTIVE_MAP=""
FLAG_COUNT="0"
NGINX_RC=1

if [[ -n "$WEB_CID" ]]; then

    FLAG_COUNT="$(
        docker inspect "$WEB_CID" \
            --format '{{range .Config.Env}}{{println .}}{{end}}' \
            2>/dev/null |
        grep -Fxc 'LEGACY_SEO_REDIRECTS_ENABLED=true' || true
    )"

    ACTIVE_MAP="$(
        docker exec "$WEB_CID" sha256sum \
            /etc/nginx/conf.d/00-legacy-seo-product-map.conf \
            2>/dev/null |
        awk '{print $1}'
    )"

    docker exec "$WEB_CID" nginx -t >/dev/null 2>&1
    NGINX_RC=$?
fi

# Do not print the complete production container environment.
check_equal "REDIRECT_FLAG" "$FLAG_COUNT" "1"

check_equal \
    "ACTIVE_WEB_MAP" \
    "$ACTIVE_MAP" \
    "$EXPECTED_MAP"

check_equal "NGINX_CONFIGURATION" "$NGINX_RC" "0"

echo "=== HTTPS READINESS ==="

HEALTH="$(
    curl \
        --silent \
        --show-error \
        --noproxy '*' \
        --resolve ortezka.pl:443:127.0.0.1 \
        --connect-timeout 3 \
        --max-time 12 \
        --output /dev/null \
        --write-out '%{http_code}' \
        https://ortezka.pl/up 2>/dev/null
)"
HEALTH_RC=$?

check_equal "HEALTH_REQUEST" "$HEALTH_RC" "0"
check_equal "HEALTH_HTTP" "$HEALTH" "200"

echo "=== FINAL READ-ONLY BASELINE RESULT ==="

echo "P6S_CB5_FAILURE_COUNT=$FAILURES"

if [[ "$FAILURES" -eq 0 ]]; then
    echo "P6S_CB5_CURRENT_BASELINE=PASS"
    echo "P6S_CB5_DEPLOY_AUTHORIZED=false"
    exit 0
fi

echo "P6S_CB5_CURRENT_BASELINE=FAIL"
echo "P6S_CB5_DEPLOY_AUTHORIZED=false"

exit 1
