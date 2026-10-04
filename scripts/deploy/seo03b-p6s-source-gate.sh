#!/usr/bin/env bash
set -euo pipefail

fail() {
    echo "::error::P6S source-only gate: $*" >&2
    exit 1
}

RELEASE="df2e1fe0cd25188ae642f65c324c97911304f623"

MAP_SHA="ece3d1558b317f2e7517e0ac6397e18f936e55a2ae258c51ffd28ef7db10c196"

MANIFEST_SHA="6a1ca8e5c7fa2df92148490634dabb0d648f6d1647d6cce4a6bc5c3da10e674d"

MAP="docker/nginx/generated/legacy-seo-product-map.conf"

MANIFEST="resources/seo/ortezka/review/seo-03b-p4-20261003/approved-58-manifest.json"

[[ "${GITHUB_EVENT_NAME:-}" == "push" ]] ||
    fail "Only a push event is accepted."

[[ "${GITHUB_REF:-}" == "refs/heads/main" ]] ||
    fail "Only main is accepted."

[[ "${PUSH_BEFORE:-}" == "$RELEASE" ]] ||
    fail "Unexpected previous main commit."

HEAD_SHA="$(git rev-parse HEAD)"

[[ "${GITHUB_SHA:-}" == "$HEAD_SHA" ]] ||
    fail "GitHub SHA does not match checkout."

PARENT_SHA="$(git rev-parse HEAD^)"

[[ "$PARENT_SHA" == "$RELEASE" ]] ||
    fail "P6S transition must be one direct commit after release."

CURRENT_MAP="$(sha256sum "$MAP" | awk '{print $1}')"

CURRENT_MANIFEST="$(sha256sum "$MANIFEST" | awk '{print $1}')"

[[ "$CURRENT_MAP" == "$MAP_SHA" ]] ||
    fail "Frozen 58-rule map changed."

[[ "$CURRENT_MANIFEST" == "$MANIFEST_SHA" ]] ||
    fail "Frozen human approval manifest changed."

EXPECTED_DIFF="$(printf '%s\n' \
    $'M\t.github/workflows/deploy-prod.yml' \
    $'A\tscripts/deploy/seo03b-p6s-source-gate.sh' \
    $'A\ttests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php')"

ACTUAL_DIFF="$(git diff --name-status "$RELEASE" "$HEAD_SHA")"

[[ "$ACTUAL_DIFF" == "$EXPECTED_DIFF" ]] ||
    fail "Unexpected P6S source transition file boundary."

git diff --check "$RELEASE" "$HEAD_SHA" ||
    fail "Git diff contains errors."

[[ -n "${GITHUB_OUTPUT:-}" ]] ||
    fail "GitHub output file is unavailable."

printf 'deploy=false\n' >> "$GITHUB_OUTPUT"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    {
        echo "### SEO-03B P6S source-only transition"
        echo "Source transition accepted."
        echo "AWS and SSM deployment remain disabled."
        echo "Production reconciliation requires separate authorisation."
    } >> "$GITHUB_STEP_SUMMARY"
fi

echo "P6S_SOURCE_ONLY_TRANSITION=PASS"
echo "P6S_DEPLOYMENT=WITHHELD"
