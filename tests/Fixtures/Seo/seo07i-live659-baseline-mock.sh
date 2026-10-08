#!/usr/bin/env bash
set -uo pipefail

SCRIPT="scripts/deploy/seo07i-live659-baseline.sh"

PROMOTION="56bf4c205667517430cbaa2f1ce1212aba3723db"

APP="sha256:d15817d4739e9cacf44daec8680f4370daf6be9c2bc6effcd37c8052ee378cb1"
WEB="sha256:a16c4f9c7459f7715925becc5fb414ec11a5cffa56e251b6ef3cd6ca8b8a70e6"
WEB_ROLLBACK="sha256:1cb8e6078b624388354f7351bd72bac95fe2c64abeded5d8563e1e52183e9854"
REDIS="sha256:6ab0b6e7381779332f97b8ca76193e45b0756f38d4c0dcda72dbb3c32061ab99"

MAP="2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e"
EXTRA="35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802"

MOCK_MANIFEST_SHA="dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184"
PROOF="400a8fb07cca664fcab20be2612e515806d068b5f526e867c8df8f7693678cb1"
MOCK_AUTH_SHA="f8fd7f0d43ac0a12f80abb4e40ca1a6264f4b93bdf83e7c4ed2ea0b12e6f8bde"
MOCK_CLOSURE_SHA="93d694d9e89c419963ab3d48a7f1dea1bc3a6f0ef211ec1f4597c62a10179d6b"

MOCK_TARGET_REPORT_SHA="85723cda4b943bfd7b72ec24845365bc037b85e847d64a2191726ec1ae6dca52"
MOCK_RUNTIME_REPORT_SHA="4616f00e7f6a0456fe6910e46d716b06a9dc498163f06e88bcd9c7b1bd624ab9"

export \
    PROMOTION APP WEB WEB_ROLLBACK REDIS \
    MAP EXTRA MOCK_MANIFEST_SHA PROOF MOCK_AUTH_SHA MOCK_CLOSURE_SHA \
    MOCK_TARGET_REPORT_SHA MOCK_RUNTIME_REPORT_SHA

if [[ ! -f "$SCRIPT" ]] ||
   ! bash -n "$SCRIPT"; then

    echo "SEO07I_LIVE659_SOURCE_SYNTAX=FAIL"
    exit 2
fi

echo "SEO07I_LIVE659_SOURCE_SYNTAX=PASS"


id() {
    if [[ "${1:-}" == "-un" ]]; then
        printf '%s\n' "${TEST_USER:-ubuntu}"
    else
        return 1
    fi
}

pwd() {
    if [[ "${1:-}" == "-P" ]]; then
        printf '%s\n' \
            "${TEST_DIRECTORY:-/var/www/konji-shop}"
    else
        builtin pwd "$@"
    fi
}

git() {
    case "${1:-}:${2:-}" in

        rev-parse:HEAD)
            printf '%s\n' \
                "${TEST_HEAD:-synthetic-live659-head}"
            ;;

        branch:--show-current)
            printf '%s\n' \
                "${TEST_BRANCH:-main}"
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
                "${TEST_SOURCE_MAP:-$MAP}" \
                "$1"
            ;;

        docker/nginx/generated/legacy-seo-staging-extra-map.conf)
            printf '%s  %s\n' \
                "${TEST_EXTRA_MAP:-$EXTRA}" \
                "$1"
            ;;

        resources/seo/ortezka/review/seo-07f-20261007/approved-659-manifest.json)
            printf '%s  %s\n' \
                "${TEST_MANIFEST:-$MOCK_MANIFEST_SHA}" \
                "$1"
            ;;

        resources/seo/ortezka/review/seo-07g-20261007/staging-runtime-proof.json)
            printf '%s  %s\n' \
                "${TEST_PROOF:-$PROOF}" \
                "$1"
            ;;

        resources/seo/ortezka/review/seo-07h-20261008/production-promotion-659.json)
            printf '%s  %s\n' \
                "${TEST_AUTH:-$MOCK_AUTH_SHA}" \
                "$1"
            ;;

        resources/seo/ortezka/review/seo-07i-20261008/live-659-baseline.json)
            printf '%s  %s\n' \
                "${TEST_CLOSURE:-$MOCK_CLOSURE_SHA}" \
                "$1"
            ;;

        storage/app/private/scrapers/seo/ortezka/audits/seo07h-production-live659-20261008/targets-415.json)
            printf '%s  %s\n' \
                "${TEST_TARGET_REPORT:-$MOCK_TARGET_REPORT_SHA}" \
                "$1"
            ;;

        storage/app/private/scrapers/seo/ortezka/audits/seo07h-production-live659-20261008/runtime-659.json)
            printf '%s  %s\n' \
                "${TEST_RUNTIME_REPORT:-$MOCK_RUNTIME_REPORT_SHA}" \
                "$1"
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
                printf '%s\n' \
                    "${TEST_SOURCE_RULES:-659}"
                return 0
                ;;

            docker/nginx/generated/legacy-seo-staging-extra-map.conf)
                printf '%s\n' \
                    "${TEST_EXTRA_RULES:-336}"
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

                konji-shop-web:seo07h-pre659-1cb8e60)
                    [[ "${TEST_PRE659_ROLLBACK_MISSING:-0}" != 1 ]] ||
                        return 7

                    echo "$WEB_ROLLBACK"
                    ;;

                konji-shop-web:seo07h-live659-56bf4c2)
                    [[ "${TEST_LIVE659_TAG_MISSING:-0}" != 1 ]] ||
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
                    "${TEST_ACTIVE_RULES:-659}"

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
        printf '%s' \
            "${TEST_STAGING_HEALTH:-200}"
    else
        printf '%s' \
            "${TEST_PROD_HEALTH:-200}"
    fi

    return 0
}

export -f \
    id \
    pwd \
    git \
    sha256sum \
    grep \
    docker \
    curl \
    mock_cid \
    mock_service

TEMP_DIR="$(
    mktemp -d -t konji-seo07i-live659-XXXXXX
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
            --current-live659-baseline \
            >"$TEMP_DIR/$name.log" 2>&1

        rc=$?

        grep -Fqx \
            'SEO07I_LIVE659_BASELINE_DEPLOY_AUTHORIZED=false' \
            "$TEMP_DIR/$name.log" ||
            exit 1

        if [[ "$outcome" == pass ]]; then

            [[ "$rc" -eq 0 ]] ||
                exit 1

            grep -Fqx \
                'SEO07I_LIVE659_BASELINE=PASS' \
                "$TEMP_DIR/$name.log" ||
                exit 1

            grep -Fqx \
                'SEO07I_LIVE659_BASELINE_FAILURE_COUNT=0' \
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
                'SEO07I_LIVE659_BASELINE=FAIL' \
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

echo "=== SYNTHETIC SEO-07I LIVE-659 TESTS ==="

run_case valid_live659 pass ""

run_case wrong_user fail EXECUTION_USER \
    TEST_USER ssm-user

run_case wrong_directory fail PRODUCTION_DIRECTORY \
    TEST_DIRECTORY /tmp/wrong

run_case promotion_not_ancestor fail PROMOTION_IS_ANCESTOR \
    TEST_ANCESTRY_ERROR 1

run_case wrong_branch fail CHECKOUT_BRANCH \
    TEST_BRANCH detached

run_case dirty_tracked_source fail TRACKED_SOURCE_CLEAN \
    TEST_DIRTY 1

run_case git_status_error fail TRACKED_STATUS_READ \
    TEST_GIT_STATUS_ERROR 1

run_case corrupt_source_map fail SOURCE_659_MAP_SHA \
    TEST_SOURCE_MAP incorrect

run_case corrupt_extra_map fail STAGING_EXTRA_MAP_SHA \
    TEST_EXTRA_MAP incorrect

run_case corrupt_manifest fail APPROVED_659_MANIFEST_SHA \
    TEST_MANIFEST incorrect

run_case corrupt_staging_proof fail SEO07G_STAGING_PROOF_SHA \
    TEST_PROOF incorrect

run_case corrupt_authorization fail SEO07H_AUTHORIZATION_SHA \
    TEST_AUTH incorrect

run_case corrupt_closure fail SEO07I_CLOSURE_EVIDENCE_SHA \
    TEST_CLOSURE incorrect

run_case corrupt_target_report fail LIVE659_TARGET_REPORT_SHA \
    TEST_TARGET_REPORT incorrect

run_case corrupt_runtime_report fail LIVE659_RUNTIME_REPORT_SHA \
    TEST_RUNTIME_REPORT incorrect

run_case source_rule_drift fail SOURCE_659_RULES \
    TEST_SOURCE_RULES 658

run_case extra_rule_drift fail HISTORICAL_STAGING_EXTRA_RULES \
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

run_case missing_pre659_rollback fail WEB_PRE659_ROLLBACK \
    TEST_PRE659_ROLLBACK_MISSING 1

run_case missing_live659_tag fail WEB_LIVE659_IMMUTABLE \
    TEST_LIVE659_TAG_MISSING 1

run_case redirects_disabled fail REDIRECT_FLAG \
    TEST_REDIRECT_FLAG false

run_case staging_candidate_enabled fail STAGING_CANDIDATE_FLAG_OFF \
    TEST_STAGING_FLAG true

run_case active_map_drift fail ACTIVE_LIVE659_MAP_SHA \
    TEST_ACTIVE_MAP incorrect

run_case active_rule_drift fail ACTIVE_LIVE659_RULES \
    TEST_ACTIVE_RULES 658

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

echo "SEO07I_LIVE659_CASES_PASSED=$PASSED/$TOTAL"
echo "SEO07I_LIVE659_FAILURES=$FAILED"

if [[ "$FAILED" -eq 0 &&
      "$PASSED" -eq 39 ]]; then

    echo "SEO07I_LIVE659_SYNTHETIC=PASS"
    exit 0
fi

echo "SEO07I_LIVE659_SYNTHETIC=FAIL"
exit 1
