#!/usr/bin/env bash
set -uo pipefail

SCRIPT="scripts/deploy/production-release-preflight.sh"

BASE="ba94d18144d53ab2d2c869efca681cd71f63d06a"
BRANCH="chore/seo-03b-p6s-cb-deployment-preflight-20261005"

APP="sha256:f72e5f97d3d544620a20172c731d20b29aca91fe796bfd518911e40e47362092"
WEB="sha256:80e3ed87af3c8fd63e28dd690268a9d5e657a2b3691dda98c40cff7f7e303504"
REDIS="sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99"
ROLLBACK="sha256:769c0d75699d09872f8e779993b05accd64a2cf68e2428ff3badc2a801783ed5"

MAP="ece3d1558b317f2e7517e0ac6397e18f936e55a2ae258c51ffd28ef7db10c196"
MANIFEST="6a1ca8e5c7fa2df92148490634dabb0d648f6d1647d6cce4a6bc5c3da10e674d"

MOCK_MAP_SHA="$MAP"
MOCK_MANIFEST_SHA="$MANIFEST"
export APP WEB REDIS ROLLBACK MOCK_MAP_SHA MOCK_MANIFEST_SHA

echo "=== PORTABLE SOURCE PREFLIGHT ==="

if [[ ! -f "$SCRIPT" ]] || ! bash -n "$SCRIPT"; then
    echo "P6S_CB6_SOURCE_PREFLIGHT=FAIL"
    exit 2
fi

echo "P6S_CB6_SOURCE_PREFLIGHT=PASS"

# All functions below are exported into the CHILD Bash process.
# They replace external dependencies during synthetic testing only.

id() {
    if [[ "${1:-}" == "-un" ]]; then
        printf '%s\n' "${TEST_USER:-ubuntu}"
    else
        return 1
    fi
}

pwd() {
    if [[ "${1:-}" == "-P" ]]; then
        printf '%s\n' "${TEST_DIRECTORY:-/var/www/konji-shop}"
    else
        builtin pwd "$@"
    fi
}

git() {
    case "${1:-}:${2:-}" in
        rev-parse:HEAD)
            printf '%s\n' "${TEST_HEAD:-6a32ed2a932c0c82af6a176904a6edb5087778ee}"
            ;;
        branch:--show-current)
            printf '%s\n' "${TEST_BRANCH:-main}"
            ;;
        status:--porcelain)
            if [[ "${TEST_GIT_STATUS_ERROR:-0}" == 1 ]]; then
                return 3
            fi

            if [[ "${TEST_DIRTY:-0}" == 1 ]]; then
                printf ' M docker-compose.prod.yml\n'
            fi
            ;;
        *)
            return 90
            ;;
    esac
}

sha256sum() {
    case "${1:-}" in
        docker/nginx/generated/legacy-seo-product-map.conf)
            printf '%s  %s\n' "${TEST_SOURCE_MAP:-$MOCK_MAP_SHA}" "$1"
            ;;
        resources/seo/ortezka/review/seo-03b-p4-20261003/approved-58-manifest.json)
            printf '%s  %s\n' "${TEST_MANIFEST:-$MOCK_MANIFEST_SHA}" "$1"
            ;;
        *)
            return 91
            ;;
    esac
}

mock_cid() {
    case "$1" in
        redis) echo mock-redis ;;
        app) echo mock-app ;;
        queue) echo mock-queue ;;
        scheduler) echo mock-scheduler ;;
        web) echo mock-web ;;
        *) return 1 ;;
    esac
}

mock_service() {
    case "$1" in
        mock-redis) echo redis ;;
        mock-app) echo app ;;
        mock-queue) echo queue ;;
        mock-scheduler) echo scheduler ;;
        mock-web) echo web ;;
        *) return 1 ;;
    esac
}

docker() {
    local service value cid

    case "${1:-}" in
        compose)
            [[ "${2:-}" == "-f" &&
               "${3:-}" == "docker-compose.prod.yml" ]] || return 92

            if [[ "${4:-}" == config && "${5:-}" == --images ]]; then

                [[ "${TEST_COMPOSE_ERROR:-0}" != 1 ]] || return 4

                printf '%s\n' \
                    'konji-shop-web:prod' \
                    'konji-shop-app:prod' \
                    'konji-shop-app:prod' \
                    'redis:7-alpine' \
                    'konji-shop-app:prod'

                if [[ "${TEST_COMPOSE_DRIFT:-0}" == 1 ]]; then
                    echo 'unexpected-image:latest'
                fi

                return 0
            fi

            if [[ "${4:-}" == ps && "${5:-}" == -q ]]; then
                service="${6:-}"

                if [[ "${TEST_MISSING_SERVICE:-}" == "$service" ]]; then
                    return 0
                fi

                mock_cid "$service"
                return $?
            fi
            ;;

        inspect)
            cid="${2:-}"
            service=$(mock_service "$cid") || return 5

            [[ "${3:-}" == --format ]] || return 5

            case "${4:-}" in
                '{{.State.Status}}')
                    if [[ "${TEST_STOPPED_SERVICE:-}" == "$service" ]]; then
                        echo exited
                    else
                        echo running
                    fi
                    return 0
                    ;;

                '{{.Image}}')
                    if [[ "${TEST_IMAGE_DRIFT:-}" == "$service" ]]; then
                        echo sha256:synthetic-wrong-image
                        return 0
                    fi

                    case "$service" in
                        redis) echo "$REDIS" ;;
                        web) echo "$WEB" ;;
                        *) echo "$APP" ;;
                    esac
                    return 0
                    ;;

                '{{range .Config.Env}}{{println .}}{{end}}')
                    printf 'LEGACY_SEO_REDIRECTS_ENABLED=%s\n' \
                        "${TEST_REDIRECT_FLAG:-true}"
                    return 0
                    ;;
            esac
            ;;

        image)
            [[ "${2:-}" == inspect ]] || return 6

            case "${3:-}" in
                konji-shop-app:prod)
                    [[ "${TEST_TAG_DRIFT:-}" != app ]] ||
                        { echo sha256:synthetic-wrong-tag; return 0; }
                    echo "$APP"
                    ;;

                konji-shop-web:prod)
                    [[ "${TEST_TAG_DRIFT:-}" != web ]] ||
                        { echo sha256:synthetic-wrong-tag; return 0; }
                    echo "$WEB"
                    ;;

                konji-shop-web:seo03b-pre58-7e009b8)
                    [[ "${TEST_ROLLBACK_MISSING:-0}" != 1 ]] || return 7
                    echo "$ROLLBACK"
                    ;;

                *)
                    return 7
                    ;;
            esac
            return 0
            ;;

        exec)
            [[ "${2:-}" == mock-web ]] || return 8

            if [[ "${3:-}" == sha256sum ]]; then
                printf '%s  %s\n' \
                    "${TEST_ACTIVE_MAP:-$MOCK_MAP_SHA}" "${4:-}"
                return 0
            fi

            if [[ "${3:-}" == nginx && "${4:-}" == -t ]]; then
                [[ "${TEST_NGINX_ERROR:-0}" != 1 ]]
                return $?
            fi
            ;;
    esac

    return 99
}

curl() {
    printf '%s' "${TEST_HEALTH:-200}"
    return "${TEST_CURL_ERROR:-0}"
}

export -f id pwd git sha256sum docker curl mock_cid mock_service

TEMP_DIR=$(mktemp -d -t konji-p6scb6-XXXXXX) || exit 2
trap 'rm -rf -- "$TEMP_DIR"' EXIT

PASSED=0
FAILED=0
TOTAL=0

run_case() {
    local name="$1"
    local outcome="$2"
    local marker="$3"
    local variable="${4:-}"
    local value="${5:-}"

    TOTAL=$((TOTAL + 1))

    (
        if [[ -n "$variable" ]]; then
            printf -v "$variable" '%s' "$value"
            export "$variable"
        fi

        bash "$SCRIPT" --current-baseline \
            >"$TEMP_DIR/$name.log" 2>&1

        rc=$?

        grep -Fqx 'P6S_CB5_DEPLOY_AUTHORIZED=false' \
            "$TEMP_DIR/$name.log" || exit 1

        if [[ "$outcome" == pass ]]; then

            [[ "$rc" -eq 0 ]] || exit 1

            grep -Fqx 'P6S_CB5_CURRENT_BASELINE=PASS' \
                "$TEMP_DIR/$name.log" || exit 1

            grep -Fqx 'P6S_CB5_FAILURE_COUNT=0' \
                "$TEMP_DIR/$name.log" || exit 1

        else

            [[ "$rc" -eq 1 ]] || exit 1

            grep -Fqx "$marker=FAIL" \
                "$TEMP_DIR/$name.log" || exit 1

            grep -Fqx 'P6S_CB5_CURRENT_BASELINE=FAIL' \
                "$TEMP_DIR/$name.log" || exit 1
        fi

        exit 0
    )

    case_rc=$?

    if [[ "$case_rc" -eq 0 ]]; then
        echo "$name=PASS"
        PASSED=$((PASSED + 1))
    else
        echo "$name=FAIL"
        cat "$TEMP_DIR/$name.log"
        FAILED=$((FAILED + 1))
    fi
}

echo "=== SYNTHETIC BASELINE TESTS ==="

run_case valid_baseline pass ""

run_case wrong_user fail EXECUTION_USER \
    TEST_USER ssm-user

run_case wrong_directory fail PRODUCTION_DIRECTORY \
    TEST_DIRECTORY /tmp/incorrect-production-directory

run_case wrong_checkout fail CHECKOUT_HEAD \
    TEST_HEAD incorrect-sha

run_case wrong_branch fail CHECKOUT_BRANCH \
    TEST_BRANCH detached

run_case dirty_tracked_source fail TRACKED_SOURCE_CLEAN \
    TEST_DIRTY 1

run_case git_status_error fail TRACKED_STATUS_READ \
    TEST_GIT_STATUS_ERROR 1

run_case corrupt_source_map fail SOURCE_MAP_SHA \
    TEST_SOURCE_MAP incorrect-sha

run_case corrupt_manifest fail APPROVAL_MANIFEST_SHA \
    TEST_MANIFEST incorrect-sha

run_case compose_image_drift fail COMPOSE_IMAGE_REFERENCES \
    TEST_COMPOSE_DRIFT 1

run_case compose_read_error fail COMPOSE_CONFIG_READ \
    TEST_COMPOSE_ERROR 1

run_case missing_web_container fail CONTAINER_web \
    TEST_MISSING_SERVICE web

run_case stopped_app fail STATE_app \
    TEST_STOPPED_SERVICE app

run_case app_image_drift fail IMAGE_app \
    TEST_IMAGE_DRIFT app

run_case queue_image_drift fail IMAGE_queue \
    TEST_IMAGE_DRIFT queue

run_case scheduler_image_drift fail IMAGE_scheduler \
    TEST_IMAGE_DRIFT scheduler

run_case redis_image_drift fail IMAGE_redis \
    TEST_IMAGE_DRIFT redis

run_case web_image_drift fail IMAGE_web \
    TEST_IMAGE_DRIFT web

run_case app_tag_drift fail APP_PROD_TAG \
    TEST_TAG_DRIFT app

run_case web_tag_drift fail WEB_PROD_TAG \
    TEST_TAG_DRIFT web

run_case missing_rollback fail WEB_ROLLBACK_IMAGE \
    TEST_ROLLBACK_MISSING 1

run_case redirects_disabled fail REDIRECT_FLAG \
    TEST_REDIRECT_FLAG false

run_case active_map_drift fail ACTIVE_WEB_MAP \
    TEST_ACTIVE_MAP incorrect-sha

run_case nginx_invalid fail NGINX_CONFIGURATION \
    TEST_NGINX_ERROR 1

run_case http_503 fail HEALTH_HTTP \
    TEST_HEALTH 503

run_case curl_failure fail HEALTH_REQUEST \
    TEST_CURL_ERROR 7

echo "=== SYNTHETIC ACCEPTANCE ==="

echo "P6S_CB6_CASES_PASSED=$PASSED/$TOTAL"
echo "P6S_CB6_FAILURES=$FAILED"

if [[ "$FAILED" -eq 0 && "$PASSED" -eq 26 ]]; then
    echo "P6S_CB6_SYNTHETIC_BASELINE=PASS"
    exit 0
fi

echo "P6S_CB6_SYNTHETIC_BASELINE=FAIL"
exit 1
