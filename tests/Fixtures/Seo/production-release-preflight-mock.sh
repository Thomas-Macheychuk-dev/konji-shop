#!/usr/bin/env bash
set -uo pipefail

SCRIPT="scripts/deploy/production-release-preflight.sh"

SOURCE_BASE="96fbe6b2cad2dfccce2c3e4e7564398263fcf5c2"

APP="sha256:d15817d4739e9cacf44daec8680f4370daf6be9c2bc6effcd37c8052ee378cb1"
WEB="sha256:1cb8e6078b624388354f7351bd72bac95fe2c64abeded5d8563e1e52183e9854"
MOCK_WEB_PRE_HASH_IMAGE="sha256:ea6c62b7a8ce95d2d1728fa84e3fbe754b00b496b6ace4b3ac3a4c9ea684f9a4"
MOCK_WEB_ROLLBACK_IMAGE="sha256:e81b7c35e39b2672effb24188d3a25fc430a011b56fcac7fbe1ab8532c225616"
REDIS="sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99"

MAP="52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009"

MANIFEST400="285aaa614de3322d51ae29ffb45b0fdd1f80279decc21774a6a6a508dca9aafa"
MANIFEST64="cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7"
EXTRA="35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802"
EVIDENCE="fc9aa7bbb02d14bfe52acfdd1cb6ba29f325ce032d967bbd838c9fc72984cf0e"

export \
    SOURCE_BASE APP WEB MOCK_WEB_PRE_HASH_IMAGE MOCK_WEB_ROLLBACK_IMAGE REDIS MAP \
    MANIFEST400 MANIFEST64 EXTRA EVIDENCE

echo "=== PORTABLE LIVE-400 BASELINE PREFLIGHT ==="

if [[ ! -f "$SCRIPT" ]] || ! bash -n "$SCRIPT"; then
    echo "SEO06_LIVE400_SOURCE_SYNTAX=FAIL"
    exit 2
fi

echo "SEO06_LIVE400_SOURCE_SYNTAX=PASS"

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
            printf '%s\n' "${TEST_HEAD:-synthetic-live400-head}"
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

        merge-base:--is-ancestor)
            if [[ "${TEST_ANCESTRY_ERROR:-0}" == 1 ]]; then
                return 1
            fi

            return 0
            ;;

        *)
            return 90
            ;;
    esac
}

sha256sum() {
    case "${1:-}" in
        docker/nginx/generated/legacy-seo-product-map.conf)
            printf '%s  %s\n' \
                "${TEST_SOURCE_MAP:-$MAP}" "$1"
            ;;

        docker/nginx/generated/legacy-seo-staging-extra-map.conf)
            printf '%s  %s\n' \
                "${TEST_EXTRA_MAP:-$EXTRA}" "$1"
            ;;

        resources/seo/ortezka/review/seo-06c-20261006/approved-400-manifest.json)
            printf '%s  %s\n' \
                "${TEST_MANIFEST400:-$MANIFEST400}" "$1"
            ;;

        resources/seo/ortezka/review/seo-05h-20261006/approved-64-manifest.json)
            printf '%s  %s\n' \
                "${TEST_MANIFEST64:-$MANIFEST64}" "$1"
            ;;

        storage/app/private/scrapers/seo/ortezka/audits/seo06-production-live400-20261006/runtime-400.json)
            printf '%s  %s\n' \
                "${TEST_EVIDENCE:-$EVIDENCE}" "$1"
            ;;

        *)
            return 91
            ;;
    esac
}

grep() {
    if [[ "${1:-}" == "-cE" ]]; then
        case "${3:-}" in
            docker/nginx/generated/legacy-seo-product-map.conf)
                printf '%s\n' "${TEST_SOURCE_RULES:-400}"
                return 0
                ;;

            docker/nginx/generated/legacy-seo-staging-extra-map.conf)
                printf '%s\n' "${TEST_EXTRA_RULES:-336}"
                return 0
                ;;
        esac
    fi

    command grep "$@"
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
    local service cid

    case "${1:-}" in
        compose)
            [[ "${2:-}" == "-f" &&
               "${3:-}" == "docker-compose.prod.yml" ]] ||
                return 92

            if [[ "${4:-}" == config &&
                  "${5:-}" == --images ]]; then

                [[ "${TEST_COMPOSE_ERROR:-0}" != 1 ]] ||
                    return 4

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

            if [[ "${4:-}" == ps &&
                  "${5:-}" == -q ]]; then

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
            service="$(mock_service "$cid")" ||
                return 5

            [[ "${3:-}" == --format ]] ||
                return 5

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

                    printf 'LEGACY_SEO_STAGING_CANDIDATE_ENABLED=%s\n' \
                        "${TEST_STAGING_FLAG:-false}"

                    return 0
                    ;;
            esac
            ;;

        image)
            [[ "${2:-}" == inspect ]] ||
                return 6

            case "${3:-}" in
                konji-shop-app:prod)
                    if [[ "${TEST_TAG_DRIFT:-}" == app ]]; then
                        echo sha256:synthetic-wrong-tag
                    else
                        echo "$APP"
                    fi
                    ;;

                konji-shop-web:prod)
                    if [[ "${TEST_TAG_DRIFT:-}" == web ]]; then
                        echo sha256:synthetic-wrong-tag
                    else
                        echo "$WEB"
                    fi
                    ;;

                redis:7-alpine)
                    echo "$REDIS"
                    ;;

                konji-shop-app:seo06-pre400-bbe0e4e)
                    [[ "${TEST_APP_ROLLBACK_MISSING:-0}" != 1 ]] ||
                        return 7
                    echo "$APP"
                    ;;

                konji-shop-web:seo06-pre400-bbe0e4e)
                    [[ "${TEST_WEB_ROLLBACK_MISSING:-0}" != 1 ]] ||
                        return 7
                    echo "$MOCK_WEB_ROLLBACK_IMAGE"
                    ;;

                konji-shop-web:pre-maphash-cb0a34d)
                    [[ "${TEST_PRE_HASH_ROLLBACK_MISSING:-0}" != 1 ]] ||
                        return 7
                    echo "$MOCK_WEB_PRE_HASH_IMAGE"
                    ;;

                konji-shop-web:seo06-live400-96fbe6b)
                    [[ "${TEST_ORIGINAL_LIVE400_TAG_MISSING:-0}" != 1 ]] ||
                        return 7
                    echo "$MOCK_WEB_PRE_HASH_IMAGE"
                    ;;

                konji-shop-web:maphash-live-cb0a34d)
                    [[ "${TEST_LIVE400_TAG_MISSING:-0}" != 1 ]] ||
                        return 7
                    echo "$WEB"
                    ;;

                *)
                    return 7
                    ;;
            esac

            return 0
            ;;

        exec)
            [[ "${2:-}" == mock-web ]] ||
                return 8

            if [[ "${3:-}" == sha256sum ]]; then
                printf '%s  %s\n' \
                    "${TEST_ACTIVE_MAP:-$MAP}" \
                    "${4:-}"

                return 0
            fi

            if [[ "${3:-}" == sh &&
                  "${4:-}" == -lc ]]; then
                printf '%s\n' \
                    "${TEST_ACTIVE_RULES:-400}"

                return 0
            fi

            if [[ "${3:-}" == grep ]]; then
                printf '%s\n' \
                    "${TEST_STAGING_RUNTIME_DISABLED:-1}"

                [[ "${TEST_STAGING_RUNTIME_DISABLED:-1}" == 1 ]]
                return $?
            fi

            if [[ "${3:-}" == nginx &&
                  "${4:-}" == -t ]]; then

                [[ "${TEST_NGINX_ERROR:-0}" != 1 ]]
                return $?
            fi
            ;;
    esac

    return 99
}

curl() {
    local joined="$*"

    if [[ "${TEST_CURL_ERROR:-0}" == 1 ]]; then
        return 7
    fi

    if [[ "$joined" == *"staging.ortezka.pl"* ]]; then
        printf '%s' "${TEST_STAGING_HEALTH:-200}"
    else
        printf '%s' "${TEST_PROD_HEALTH:-200}"
    fi

    return 0
}

export -f \
    id pwd git sha256sum grep docker curl \
    mock_cid mock_service

TEMP_DIR="$(
    mktemp -d -t konji-seo06-live400-XXXXXX
)" || exit 2

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

        bash "$SCRIPT" \
            --current-live400-baseline \
            >"$TEMP_DIR/$name.log" 2>&1

        rc=$?

        grep -Fqx \
            'SEO06_LIVE400_BASELINE_DEPLOY_AUTHORIZED=false' \
            "$TEMP_DIR/$name.log" ||
            exit 1

        if [[ "$outcome" == pass ]]; then

            [[ "$rc" -eq 0 ]] ||
                exit 1

            grep -Fqx \
                'SEO06_LIVE400_BASELINE=PASS' \
                "$TEMP_DIR/$name.log" ||
                exit 1

            grep -Fqx \
                'SEO06_LIVE400_BASELINE_FAILURE_COUNT=0' \
                "$TEMP_DIR/$name.log" ||
                exit 1

        else

            [[ "$rc" -eq 1 ]] ||
                exit 1

            grep -Fqx \
                "$marker=FAIL" \
                "$TEMP_DIR/$name.log" ||
                exit 1

            grep -Fqx \
                'SEO06_LIVE400_BASELINE=FAIL' \
                "$TEMP_DIR/$name.log" ||
                exit 1
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

echo "=== SYNTHETIC SEO-06 LIVE-400 TESTS ==="

run_case valid_live400 pass ""

run_case wrong_user fail EXECUTION_USER \
    TEST_USER ssm-user

run_case wrong_directory fail PRODUCTION_DIRECTORY \
    TEST_DIRECTORY /tmp/wrong

run_case baseline_not_ancestor fail LIVE400_SOURCE_IS_ANCESTOR \
    TEST_ANCESTRY_ERROR 1

run_case wrong_branch fail CHECKOUT_BRANCH \
    TEST_BRANCH detached

run_case dirty_tracked_source fail TRACKED_SOURCE_CLEAN \
    TEST_DIRTY 1

run_case git_status_error fail TRACKED_STATUS_READ \
    TEST_GIT_STATUS_ERROR 1

run_case corrupt_source_map fail SOURCE_400_MAP_SHA \
    TEST_SOURCE_MAP incorrect

run_case corrupt_manifest400 fail APPROVED_400_MANIFEST_SHA \
    TEST_MANIFEST400 incorrect

run_case corrupt_manifest64 fail APPROVED_64_BASE_MANIFEST_SHA \
    TEST_MANIFEST64 incorrect

run_case corrupt_extra_map fail STAGING_EXTRA_336_MAP_SHA \
    TEST_EXTRA_MAP incorrect

run_case corrupt_live_evidence fail LIVE400_VALIDATION_EVIDENCE_SHA \
    TEST_EVIDENCE incorrect

run_case source_rule_drift fail SOURCE_400_RULES \
    TEST_SOURCE_RULES 399

run_case extra_rule_drift fail STAGING_EXTRA_336_RULES \
    TEST_EXTRA_RULES 335

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

run_case missing_app_rollback fail APP_PRE400_ROLLBACK \
    TEST_APP_ROLLBACK_MISSING 1

run_case missing_web_rollback fail WEB_PRE400_ROLLBACK \
    TEST_WEB_ROLLBACK_MISSING 1

run_case missing_pre_hash_rollback fail WEB_PRE_HASH_ROLLBACK \
    TEST_PRE_HASH_ROLLBACK_MISSING 1

run_case missing_original_live400_tag fail WEB_ORIGINAL_LIVE400_IMMUTABLE \
    TEST_ORIGINAL_LIVE400_TAG_MISSING 1

run_case missing_live400_tag fail WEB_LIVE400_IMMUTABLE \
    TEST_LIVE400_TAG_MISSING 1

run_case redirects_disabled fail REDIRECT_FLAG \
    TEST_REDIRECT_FLAG false

run_case staging_candidate_enabled fail STAGING_CANDIDATE_FLAG_OFF \
    TEST_STAGING_FLAG true

run_case active_map_drift fail ACTIVE_LIVE400_MAP_SHA \
    TEST_ACTIVE_MAP incorrect

run_case active_rule_drift fail ACTIVE_LIVE400_RULES \
    TEST_ACTIVE_RULES 399

run_case staging_runtime_active fail STAGING_RUNTIME_OVERLAY_OFF \
    TEST_STAGING_RUNTIME_DISABLED 0

run_case nginx_invalid fail NGINX_CONFIGURATION \
    TEST_NGINX_ERROR 1

run_case prod_http_503 fail PRODUCTION_HEALTH_HTTP \
    TEST_PROD_HEALTH 503

run_case staging_http_503 fail STAGING_HEALTH_HTTP \
    TEST_STAGING_HEALTH 503

run_case curl_failure fail PRODUCTION_HEALTH_REQUEST \
    TEST_CURL_ERROR 1

echo "=== SYNTHETIC ACCEPTANCE ==="

echo "SEO06_LIVE400_CASES_PASSED=$PASSED/$TOTAL"
echo "SEO06_LIVE400_FAILURES=$FAILED"

if [[ "$FAILED" -eq 0 &&
      "$PASSED" -eq 39 ]]; then

    echo "SEO06_LIVE400_SYNTHETIC=PASS"
    exit 0
fi

echo "SEO06_LIVE400_SYNTHETIC=FAIL"
exit 1
