#!/usr/bin/env bash
set -euo pipefail

fail() {
    echo "::error::SEO-06 approved-400 source gate: $*" >&2
    exit 1
}

BASE="bbe0e4e1473219afa04e0e01cc82ba534cf5d71f"

BASE_MAP_SHA="209323a552d3481bf3ca92ed85e8d32912d68bd47d2501ce95b2440a7d3e7b01"
MAP_SHA="52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009"

MANIFEST_SHA="285aaa614de3322d51ae29ffb45b0fdd1f80279decc21774a6a6a508dca9aafa"
BASE_MANIFEST_SHA="cfd55f42623bd1ce22deaac1d82229f82a63b9ee3b3fd354d11db22d0362f9a7"
EXTRA_MAP_SHA="35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802"

WORKFLOW=".github/workflows/deploy-prod.yml"
GATE="scripts/deploy/seo03b-p6s-source-gate.sh"
PREFLIGHT="scripts/deploy/production-release-preflight.sh"

MAP="docker/nginx/generated/legacy-seo-product-map.conf"
EXTRA_MAP="docker/nginx/generated/legacy-seo-staging-extra-map.conf"

MANIFEST="resources/seo/ortezka/review/seo-06c-20261006/approved-400-manifest.json"
BASE_MANIFEST="resources/seo/ortezka/review/seo-05h-20261006/approved-64-manifest.json"

REVIEW="resources/seo/ortezka/review/seo-06b-20261006/semantic-support-approved-221.json"
DECISION="resources/seo/ortezka/review/seo-06b-20261006/owner-decision-221.json"

# Source promotion only. This gate NEVER authorises deployment.
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
    fail "SEO-06 live baseline is not an ancestor of HEAD."

FIRST="$(
    git rev-list \
        --first-parent \
        --reverse \
        "${BASE}..${HEAD}" |
    sed -n '1p'
)"

[[ -n "$FIRST" ]] ||
    fail "Missing approved-400 source transition."

[[ "$(git rev-parse "${FIRST}^1")" == "$BASE" ]] ||
    fail "Approved-400 transition is not directly based on bbe0e4e."

EXPECTED_DIFF="$(printf '%s\n' \
    $'M\t.github/workflows/deploy-prod.yml' \
    $'M\tdocker/nginx/generated/legacy-seo-product-map.conf' \
    $'M\tscripts/deploy/production-release-preflight.sh' \
    $'M\tscripts/deploy/seo03b-p6s-source-gate.sh' \
    $'M\ttests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductRedirectPromotionTest.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductRedirectRuntimeTest.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductRedirectSchemaV4Test.php' \
    $'M\ttests/Feature/Seo/SeoLegacyStagingCandidateRuntimeIsolationTest.php' \
    $'M\ttests/Feature/Seo/SeoProductionReleasePreflightContractTest.php' \
    $'M\ttests/Fixtures/Seo/production-release-preflight-mock.sh')"

ACTUAL_DIFF="$(git diff --name-status "$BASE" "$FIRST")"

[[ "$ACTUAL_DIFF" == "$EXPECTED_DIFF" ]] ||
    fail "Approved-400 first transition changed unexpected files."

git diff --check "$BASE" "$FIRST" ||
    fail "Approved-400 transition contains Git diff errors."

if [[ "$BEFORE" == "$BASE" ]]; then

    [[ "$HEAD" == "$FIRST" ]] ||
        fail "First approved-400 source promotion must contain exactly one mainline commit."

    MODE="FIRST_APPROVED400_SOURCE_PROMOTION"

else

    git merge-base --is-ancestor "$FIRST" "$BEFORE" ||
        fail "Previous commit is outside approved-400 source history."

    git merge-base --is-ancestor "$BEFORE" "$HEAD" ||
        fail "Non-fast-forward push rejected."

    git diff --quiet "$FIRST" "$HEAD" -- \
        "$WORKFLOW" \
        "$GATE" \
        "$PREFLIGHT" \
        "$MAP" \
        "$EXTRA_MAP" \
        "$MANIFEST" \
        "$BASE_MANIFEST" \
        "$REVIEW" \
        "$DECISION" ||
        fail "Protected SEO-06 production-governance file changed."

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
            "$PREFLIGHT" \
            "$MAP" \
            "$EXTRA_MAP" \
            "$MANIFEST" \
            "$BASE_MANIFEST" \
            "$REVIEW" \
            "$DECISION" |
        sed '/^[[:space:]]*$/d'
    )"

    [[ -z "$PROTECTED_TOUCHES" ]] ||
        fail "Protected SEO-06 file was modified after promotion."

    MODE="ONGOING_APPROVED400_CI_ONLY"
fi

[[ "$(sha256sum "$MAP" | awk '{print $1}')" == "$MAP_SHA" ]] ||
    fail "Approved-400 generated map checksum mismatch."

[[ "$(sha256sum "$MANIFEST" | awk '{print $1}')" == "$MANIFEST_SHA" ]] ||
    fail "Approved-400 manifest checksum mismatch."

[[ "$(sha256sum "$BASE_MANIFEST" | awk '{print $1}')" == "$BASE_MANIFEST_SHA" ]] ||
    fail "Approved-64 base manifest changed."

[[ "$(sha256sum "$EXTRA_MAP" | awk '{print $1}')" == "$EXTRA_MAP_SHA" ]] ||
    fail "Frozen staging-extra 336-rule map changed."

[[ "$(grep -cE '^    "[^"]+" "/products/[^"]+";$' "$MAP")" == "400" ]] ||
    fail "Approved-400 production source does not contain exactly 400 rules."

[[ "$(grep -cE '^    "[^"]+" "/products/[^"]+";$' "$EXTRA_MAP")" == "336" ]] ||
    fail "Frozen staging-extra source does not contain exactly 336 rules."

grep -Fq \
    '# Source: resources/seo/ortezka/review/seo-06c-20261006/approved-400-manifest.json' \
    "$MAP" ||
    fail "Approved-400 generated map source header mismatch."

grep -Fq \
    '# Manifest SHA-256: 285aaa614de3322d51ae29ffb45b0fdd1f80279decc21774a6a6a508dca9aafa' \
    "$MAP" ||
    fail "Approved-400 generated map manifest header mismatch."

# Prove that the new production source is exactly:
#
#   live baseline 64 + frozen staging-proven 336 = 400
#
# The baseline map is read from immutable Git history, not from the
# candidate working tree.

export BASE MAP EXTRA_MAP

if ! python3 <<'PY2'
import os
import re
import subprocess
from pathlib import Path

base = os.environ["BASE"]
map_path = os.environ["MAP"]
extra_path = os.environ["EXTRA_MAP"]

pattern = re.compile(
    r'^\s*"([^"]+)"\s+"([^"]+)";\s*$'
)

def parse(text):
    rules = {}

    for line in text.splitlines():
        m = pattern.match(line)

        if not m:
            continue

        source, target = m.groups()

        if source in rules:
            raise SystemExit(
                f"duplicate source: {source}"
            )

        rules[source] = target

    return rules

base_text = subprocess.check_output(
    [
        "git",
        "show",
        f"{base}:{map_path}",
    ],
    text=True,
)

base_rules = parse(base_text)

extra_rules = parse(
    Path(extra_path).read_text(
        encoding="utf-8",
    )
)

candidate_rules = parse(
    Path(map_path).read_text(
        encoding="utf-8",
    )
)

if len(base_rules) != 64:
    raise SystemExit("baseline map is not 64 rules")

if len(extra_rules) != 336:
    raise SystemExit("extra map is not 336 rules")

overlap = set(base_rules) & set(extra_rules)

if overlap:
    raise SystemExit(
        "64/336 source overlap exists"
    )

combined = dict(base_rules)
combined.update(extra_rules)

if combined != candidate_rules:
    raise SystemExit(
        "candidate map is not exact 64+336 union"
    )

if len(candidate_rules) != 400:
    raise SystemExit(
        "candidate map is not 400 rules"
    )

print("SEO06_EXACT_64_PLUS_336_UNION=PASS")
PY2
then
    fail "Approved-400 union proof failed."
fi

BASE_MAP_ACTUAL="$(
    git show "${BASE}:${MAP}" |
    sha256sum |
    awk '{print $1}'
)"

[[ "$BASE_MAP_ACTUAL" == "$BASE_MAP_SHA" ]] ||
    fail "bbe0e4e live-baseline map checksum mismatch."

BASE_RULES="$(
    git show "${BASE}:${MAP}" |
    grep -cE '^    "[^"]+" "/products/[^"]+";$'
)"

[[ "$BASE_RULES" == "64" ]] ||
    fail "bbe0e4e live baseline does not contain 64 rules."

# AWS/SSM production deployment remains independently hard-disabled.
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
        echo "### SEO-06 approved-400 source gate"
        echo "Mode: ${MODE}"
        echo "400-rule source accepted."
        echo "Exact 64 + 336 provenance verified."
        echo "Production deployment remains intentionally disabled."
    } >> "$GITHUB_STEP_SUMMARY"
fi

echo "SEO06_SOURCE_MODE=$MODE"
echo "SEO06_SOURCE_GATE=PASS"
echo "SEO06_SOURCE_RULES=400"
echo "SEO06_SOURCE_MAP_SHA=$MAP_SHA"
echo "SEO06_LIVE_BASELINE_RULES=64"
echo "SEO06_DEPLOYMENT=WITHHELD"
