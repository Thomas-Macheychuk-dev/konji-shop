#!/usr/bin/env bash
set -euo pipefail

fail() {
    echo "::error::SEO-05 approved-64 source gate: $*" >&2
    exit 1
}

BASE="62c22a995aeff956c1f7e10dab94534da222426c"

MAP_SHA="209323a552d3481bf3ca92ed85e8d32912d68bd47d2501ce95b2440a7d3e7b01"
MANIFEST_SHA="cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7"
HISTORICAL_MANIFEST_SHA="6a1ca8e5c7fa2df92148490634dabb0d648f6d1647d6cce4a6bc5c3da10e674d"

WORKFLOW=".github/workflows/deploy-prod.yml"
GATE="scripts/deploy/seo03b-p6s-source-gate.sh"

MAP="docker/nginx/generated/legacy-seo-product-map.conf"

MANIFEST="resources/seo/ortezka/review/seo-05h-20261006/approved-64-manifest.json"

HISTORICAL_MANIFEST="resources/seo/ortezka/review/seo-03b-p4-20261003/approved-58-manifest.json"

# CI source promotion only. This gate NEVER authorises deployment.
[[ "${GITHUB_EVENT_NAME:-}" == "push" ]] ||
    fail "Manual dispatch and non-push events are prohibited."

[[ "${GITHUB_REF:-}" == "refs/heads/main" ]] ||
    fail "Only main is accepted."

HEAD="$(git rev-parse HEAD)"
BEFORE="${PUSH_BEFORE:-}"

[[ "${GITHUB_SHA:-}" == "$HEAD" ]] ||
    fail "GitHub SHA does not match the checked-out commit."

[[ -n "$BEFORE" && "$BEFORE" != "$HEAD" ]] ||
    fail "Invalid previous commit."

git merge-base --is-ancestor "$BASE" "$HEAD" ||
    fail "Unexpected commit ancestry."

FIRST="$(
    git rev-list --first-parent --reverse "${BASE}..${HEAD}" |
    sed -n '1p'
)"

[[ -n "$FIRST" ]] ||
    fail "Missing approved-64 source transition."

[[ "$(git rev-parse "${FIRST}^")" == "$BASE" ]] ||
    fail "Approved-64 transition is not directly based on 62c22a9."

EXPECTED_DIFF="$(printf '%s\n' \
    $'M\tdocker/nginx/generated/legacy-seo-product-map.conf' \
    $'M\tscripts/deploy/seo03b-p6s-source-gate.sh' \
    $'M\ttests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductRedirectPromotionTest.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductRedirectRuntimeTest.php' \
    $'M\ttests/Feature/Seo/SeoProductionReleasePreflightContractTest.php')"

ACTUAL_DIFF="$(git diff --name-status "$BASE" "$FIRST")"

[[ "$ACTUAL_DIFF" == "$EXPECTED_DIFF" ]] ||
    fail "Approved-64 first transition changed unexpected files."

git diff --check "$BASE" "$FIRST" ||
    fail "Approved-64 first transition contains Git diff errors."

if [[ "$BEFORE" == "$BASE" ]]; then

    [[ "$HEAD" == "$FIRST" ]] ||
        fail "First approved-64 source promotion must contain exactly one commit."

    MODE="FIRST_APPROVED64_SOURCE_PROMOTION"

else

    git merge-base --is-ancestor "$FIRST" "$BEFORE" ||
        fail "Previous commit is outside approved-64 source history."

    git merge-base --is-ancestor "$BEFORE" "$HEAD" ||
        fail "Non-fast-forward push rejected."

    git diff --quiet "$FIRST" "$HEAD" -- \
        "$WORKFLOW" \
        "$GATE" \
        "$MAP" \
        "$MANIFEST" \
        "$HISTORICAL_MANIFEST" ||
        fail "Protected approved-64 production-governance file changed."

    PROTECTED_TOUCHES="$(
        git log \
            --full-history \
            -m \
            --format= \
            --name-only \
            "${FIRST}..${HEAD}" \
            -- \
            "$WORKFLOW" \
            "$GATE" \
            "$MAP" \
            "$MANIFEST" \
            "$HISTORICAL_MANIFEST" |
        sed '/^[[:space:]]*$/d'
    )"

    [[ -z "$PROTECTED_TOUCHES" ]] ||
        fail "Protected approved-64 file was modified after promotion."

    MODE="ONGOING_APPROVED64_CI_ONLY"
fi

[[ "$(sha256sum "$MAP" | awk '{print $1}')" == "$MAP_SHA" ]] ||
    fail "Approved-64 generated map checksum mismatch."

[[ "$(sha256sum "$MANIFEST" | awk '{print $1}')" == "$MANIFEST_SHA" ]] ||
    fail "Approved-64 manifest checksum mismatch."

[[ "$(sha256sum "$HISTORICAL_MANIFEST" | awk '{print $1}')" == "$HISTORICAL_MANIFEST_SHA" ]] ||
    fail "Historical approved-58 manifest changed."

[[ "$(grep -cE '^    "[^"]+" "/products/[^"]+";$' "$MAP")" == "64" ]] ||
    fail "Approved-64 generated map does not contain exactly 64 rules."

grep -Fq \
    '# Source: resources/seo/ortezka/review/seo-05h-20261006/approved-64-manifest.json' \
    "$MAP" ||
    fail "Approved-64 generated map source header mismatch."

grep -Fq \
    '# Manifest SHA-256: cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7' \
    "$MAP" ||
    fail "Approved-64 generated map manifest header mismatch."

# AWS/SSM deployment must remain independently disabled.
[[ "$(grep -Fc '        if: ${{ false }}' "$WORKFLOW")" == "2" ]] ||
    fail "Independent production deployment locks changed."

[[ "$(
    grep -F -A1 \
        '      - name: Configure AWS credentials' \
        "$WORKFLOW" |
    grep -Fc '        if: ${{ false }}'
)" == "1" ]] ||
    fail "AWS credentials deployment lock is missing."

[[ "$(
    grep -F -A1 \
        '      - name: Deploy over SSM' \
        "$WORKFLOW" |
    grep -Fc '        if: ${{ false }}'
)" == "1" ]] ||
    fail "SSM deployment lock is missing."

[[ -n "${GITHUB_OUTPUT:-}" ]] ||
    fail "GitHub output file is unavailable."

printf 'deploy=false\n' >> "$GITHUB_OUTPUT"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    {
        echo "### SEO-05 approved-64 source gate"
        echo "Mode: ${MODE}"
        echo "64-rule source accepted."
        echo "Production deployment remains intentionally disabled."
    } >> "$GITHUB_STEP_SUMMARY"
fi

echo "SEO05_SOURCE_MODE=$MODE"
echo "SEO05_SOURCE_GATE=PASS"
echo "SEO05_SOURCE_RULES=64"
echo "SEO05_DEPLOYMENT=WITHHELD"
