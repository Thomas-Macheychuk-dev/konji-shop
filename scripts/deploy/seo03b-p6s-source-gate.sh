#!/usr/bin/env bash
set -euo pipefail

fail() {
    echo "::error::SEO-03B production HOLD: $*" >&2
    exit 1
}

BASE="6a32ed2a932c0c82af6a176904a6edb5087778ee"

MAP_SHA="ece3d1558b317f2e7517e0ac6397e18f936e55a2ae258c51ffd28ef7db10c196"
MANIFEST_SHA="6a1ca8e5c7fa2df92148490634dabb0d648f6d1647d6cce4a6bc5c3da10e674d"

WORKFLOW=".github/workflows/deploy-prod.yml"
GATE="scripts/deploy/seo03b-p6s-source-gate.sh"
TEST="tests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php"

MAP="docker/nginx/generated/legacy-seo-product-map.conf"
MANIFEST="resources/seo/ortezka/review/seo-03b-p4-20261003/approved-58-manifest.json"

# This policy accepts CI-only pushes. It never authorises deployment.
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

[[ "$HEAD" != "$BASE" ]] ||
    fail "No P6S-C transition exists."

git merge-base --is-ancestor "$BASE" "$HEAD" ||
    fail "Unexpected commit ancestry."

# All accepted histories must begin with the exact P6S-C HOLD commit.
FIRST="$(
    git rev-list --first-parent --reverse "${BASE}..${HEAD}" |
    sed -n '1p'
)"

[[ -n "$FIRST" ]] ||
    fail "Missing first transition commit."

[[ "$(git rev-parse "${FIRST}^")" == "$BASE" ]] ||
    fail "First transition is not directly based on P6S-A."

EXPECTED_DIFF="$(printf '%s\n' \
    $'M\t.github/workflows/deploy-prod.yml' \
    $'M\tscripts/deploy/seo03b-p6s-source-gate.sh' \
    $'M\ttests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php')"

ACTUAL_DIFF="$(git diff --name-status "$BASE" "$FIRST")"

[[ "$ACTUAL_DIFF" == "$EXPECTED_DIFF" ]] ||
    fail "First P6S-C transition changed unexpected files."

git diff --check "$BASE" "$FIRST" ||
    fail "First transition contains Git diff errors."

if [[ "$BEFORE" == "$BASE" ]]; then

    # First promotion must contain precisely one direct commit.
    [[ "$HEAD" == "$FIRST" ]] ||
        fail "First promotion must contain exactly one commit."

    MODE="FIRST_SOURCE_ONLY_PROMOTION"

else

    # Subsequent pushes may run CI but must retain the HOLD boundary.
    git merge-base --is-ancestor "$FIRST" "$BEFORE" ||
        fail "Previous commit is outside the accepted HOLD history."

    git merge-base --is-ancestor "$BEFORE" "$HEAD" ||
        fail "Non-fast-forward push rejected."

    git diff --quiet "$FIRST" "$HEAD" -- \
        "$WORKFLOW" "$GATE" "$MAP" "$MANIFEST" ||
        fail "A protected production-governance file changed."

    # Examine actual commit history, not just the final tree.
    # A protected-file change followed by a revert is also prohibited.
    PROTECTED_TOUCHES="$(
        git log --full-history -m --format= --name-only "${FIRST}..${HEAD}" -- \
            "$WORKFLOW" "$GATE" "$MAP" "$MANIFEST" |
            sed '/^[[:space:]]*$/d'
    )"

    [[ -z "$PROTECTED_TOUCHES" ]] ||
        fail "Protected file modified in ongoing CI-only commit history."

    MODE="ONGOING_CI_ONLY"
fi

# Verify immutable SEO release content at the checked-out commit.
[[ "$(sha256sum "$MAP" | awk '{print $1}')" == "$MAP_SHA" ]] ||
    fail "Frozen production redirect map changed."

[[ "$(sha256sum "$MANIFEST" | awk '{print $1}')" == "$MANIFEST_SHA" ]] ||
    fail "Frozen human approval manifest changed."

# Independently verify the two workflow deployment locks.
if ! python3 - "$WORKFLOW" <<'PY'
from pathlib import Path
import re
import sys

workflow = Path(sys.argv[1]).read_text()

checks = []

for name in ("Configure AWS credentials", "Deploy over SSM"):
    pattern = (
        r"(?m)^      - name: "
        + re.escape(name)
        + r"\n        if: \$\{\{ false \}\}(?:\n|$)"
    )

    checks.append(bool(re.search(pattern, workflow)))

checks.append(workflow.count('if: ${{ false }}') == 2)

sys.exit(0 if all(checks) else 1)
PY
then
    fail "Independent AWS/SSM deployment locks are missing."
fi

[[ -n "${GITHUB_OUTPUT:-}" ]] ||
    fail "GitHub output file is unavailable."

printf 'deploy=false\n' >> "$GITHUB_OUTPUT"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    {
        echo "### SEO-03B production HOLD"
        echo "Mode: ${MODE}"
        echo "CI accepted; production deployment intentionally disabled."
    } >> "$GITHUB_STEP_SUMMARY"
fi

echo "P6S_C_MODE=$MODE"
echo "P6S_C_PRODUCTION_HOLD=PASS"
echo "P6S_C_DEPLOYMENT=WITHHELD"
