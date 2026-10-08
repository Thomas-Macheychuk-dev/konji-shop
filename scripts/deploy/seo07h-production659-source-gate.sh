#!/usr/bin/env bash
set -euo pipefail

fail() {
    echo "::error::SEO-07I live-659 governance gate: $*" >&2
    exit 1
}

PRE_PROMOTION="8f17139032dc076eb99b895b53c4f6aba8b224fb"
PROMOTION="56bf4c205667517430cbaa2f1ce1212aba3723db"
PROMOTION_SOURCE="dff528e44c6d27ebb55b963ddbc8fb7fca62d065"

BASE_MAP_SHA="52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009"
MAP_SHA="2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e"

MANIFEST_SHA="dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184"
PROOF_SHA="400a8fb07cca664fcab20be2612e515806d068b5f526e867c8df8f7693678cb1"
AUTH_SHA="f8fd7f0d43ac0a12f80abb4e40ca1a6264f4b93bdf83e7c4ed2ea0b12e6f8bde"
LIVE659_EVIDENCE_SHA="93d694d9e89c419963ab3d48a7f1dea1bc3a6f0ef211ec1f4597c62a10179d6b"
EXTRA_MAP_SHA="35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802"

LIVE659_WEB_IMAGE="sha256:a16c4f9c7459f7715925becc5fb414ec11a5cffa56e251b6ef3cd6ca8b8a70e6"
LIVE400_ROLLBACK_IMAGE="sha256:1cb8e6078b624388354f7351bd72bac95fe2c64abeded5d8563e1e52183e9854"

TARGET_REPORT_SHA="85723cda4b943bfd7b72ec24845365bc037b85e847d64a2191726ec1ae6dca52"
RUNTIME_REPORT_SHA="4616f00e7f6a0456fe6910e46d716b06a9dc498163f06e88bcd9c7b1bd624ab9"

WORKFLOW=".github/workflows/deploy-prod.yml"

GATE="scripts/deploy/seo07h-production659-source-gate.sh"
PREDEPLOY="scripts/deploy/seo07h-production659-preflight.sh"
LIVE_BASELINE="scripts/deploy/seo07i-live659-baseline.sh"

HISTORICAL_GATE="scripts/deploy/seo03b-p6s-source-gate.sh"
HISTORICAL_PREFLIGHT="scripts/deploy/production-release-preflight.sh"

MAP="docker/nginx/generated/legacy-seo-product-map.conf"
EXTRA_MAP="docker/nginx/generated/legacy-seo-staging-extra-map.conf"

MANIFEST="resources/seo/ortezka/review/seo-07f-20261007/approved-659-manifest.json"
PROOF="resources/seo/ortezka/review/seo-07g-20261007/staging-runtime-proof.json"
AUTH="resources/seo/ortezka/review/seo-07h-20261008/production-promotion-659.json"
LIVE659_EVIDENCE="resources/seo/ortezka/review/seo-07i-20261008/live-659-baseline.json"

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


git merge-base \
    --is-ancestor \
    "$PROMOTION" \
    "$HEAD" ||
    fail "SEO-07H promotion merge is not an ancestor of HEAD."

[[ "$(git rev-parse "${PROMOTION}^1")" == "$PRE_PROMOTION" ]] ||
    fail "SEO-07H promotion first parent changed."

[[ "$(git rev-parse "${PROMOTION}^2")" == "$PROMOTION_SOURCE" ]] ||
    fail "SEO-07H promotion source commit changed."


EXPECTED_PROMOTION_DIFF="$(
    printf '%s\n' \
        $'M\t.github/workflows/deploy-prod.yml' \
        $'M\tdocker/nginx/generated/legacy-seo-product-map.conf' \
        $'A\tresources/seo/ortezka/review/seo-07h-20261008/production-promotion-659.json' \
        $'A\tscripts/deploy/seo07h-production659-preflight.sh' \
        $'A\tscripts/deploy/seo07h-production659-source-gate.sh' \
        $'A\ttests/Feature/Seo/Seo07Production659PreflightContractTest.php' \
        $'M\ttests/Feature/Seo/SeoLegacyExactNameOnlyDecisionLedgerTest.php' \
        $'M\ttests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php' \
        $'M\ttests/Feature/Seo/SeoLegacyProductRedirectPromotionTest.php' \
        $'M\ttests/Feature/Seo/SeoLegacyProductRedirectRuntimeTest.php' \
        $'M\ttests/Feature/Seo/SeoLegacyProductRedirectSchemaV4Test.php' \
        $'M\ttests/Feature/Seo/SeoLegacyProductRedirectSchemaV5Test.php' \
        $'M\ttests/Feature/Seo/SeoLegacyStagingCandidateRuntimeIsolationTest.php'
)"

ACTUAL_PROMOTION_DIFF="$(
    git diff \
        --name-status \
        "$PRE_PROMOTION" \
        "$PROMOTION"
)"

[[ "$ACTUAL_PROMOTION_DIFF" == "$EXPECTED_PROMOTION_DIFF" ]] ||
    fail "Historical SEO-07H promotion boundary changed."

git diff \
    --check \
    "$PRE_PROMOTION" \
    "$PROMOTION" ||
    fail "Historical SEO-07H promotion contains Git diff errors."


CLOSURE="$(
    git rev-list \
        --first-parent \
        --reverse \
        "${PROMOTION}..${HEAD}" |
    sed -n '1p'
)"

[[ -n "$CLOSURE" ]] ||
    fail "Missing live-659 baseline closure transition."

[[ "$(git rev-parse "${CLOSURE}^1")" == "$PROMOTION" ]] ||
    fail "Live-659 closure is not directly based on the SEO-07H promotion merge."


EXPECTED_CLOSURE_DIFF="$(
    printf '%s\n' \
        $'A\tresources/seo/ortezka/review/seo-07i-20261008/live-659-baseline.json' \
        $'M\tscripts/deploy/seo07h-production659-source-gate.sh' \
        $'A\tscripts/deploy/seo07i-live659-baseline.sh' \
        $'A\ttests/Feature/Seo/Seo07Live659BaselineContractTest.php' \
        $'M\ttests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php' \
        $'A\ttests/Fixtures/Seo/seo07i-live659-baseline-mock.sh'
)"

ACTUAL_CLOSURE_DIFF="$(
    git diff \
        --name-status \
        "$PROMOTION" \
        "$CLOSURE"
)"

[[ "$ACTUAL_CLOSURE_DIFF" == "$EXPECTED_CLOSURE_DIFF" ]] ||
    fail "Live-659 closure changed unexpected files."

git diff \
    --check \
    "$PROMOTION" \
    "$CLOSURE" ||
    fail "Live-659 closure contains Git diff errors."


git diff \
    --quiet \
    "$PROMOTION" \
    "$HEAD" \
    -- "$PREDEPLOY" ||
    fail "Historical SEO-07H predeployment evidence changed."

git diff \
    --quiet \
    "$PRE_PROMOTION" \
    "$HEAD" \
    -- \
    "$HISTORICAL_GATE" \
    "$HISTORICAL_PREFLIGHT" ||
    fail "Historical SEO-06 governance changed."


if [[ "$BEFORE" == "$PROMOTION" ]]; then

    [[ "$HEAD" == "$CLOSURE" ]] ||
        fail "First live-659 closure push must contain exactly one mainline transition."

    MODE="FIRST_LIVE659_BASELINE_CLOSURE"

else

    git merge-base \
        --is-ancestor \
        "$CLOSURE" \
        "$BEFORE" ||
        fail "Previous commit is outside the live-659 baseline history."

    git merge-base \
        --is-ancestor \
        "$BEFORE" \
        "$HEAD" ||
        fail "Non-fast-forward push rejected."

    git diff \
        --quiet \
        "$CLOSURE" \
        "$HEAD" \
        -- \
        "$WORKFLOW" \
        "$GATE" \
        "$PREDEPLOY" \
        "$LIVE_BASELINE" \
        "$MAP" \
        "$EXTRA_MAP" \
        "$MANIFEST" \
        "$PROOF" \
        "$AUTH" \
        "$LIVE659_EVIDENCE" \
        "$HISTORICAL_GATE" \
        "$HISTORICAL_PREFLIGHT" ||
        fail "Protected SEO-07I live-659 governance file changed."

    PROTECTED_TOUCHES="$(
        git log \
            --full-history \
            -m \
            --format= \
            --name-only \
            "${CLOSURE}..${HEAD}" \
            -- \
            "$WORKFLOW" \
            "$GATE" \
            "$PREDEPLOY" \
            "$LIVE_BASELINE" \
            "$MAP" \
            "$EXTRA_MAP" \
            "$MANIFEST" \
            "$PROOF" \
            "$AUTH" \
            "$LIVE659_EVIDENCE" \
            "$HISTORICAL_GATE" \
            "$HISTORICAL_PREFLIGHT" |
        sed '/^[[:space:]]*$/d'
    )"

    [[ -z "$PROTECTED_TOUCHES" ]] ||
        fail "Protected SEO-07I file was modified after live-659 closure."

    MODE="ONGOING_LIVE659_BASELINE_CI_ONLY"
fi


[[ "$(sha256sum "$MAP" | awk '{print $1}')" == "$MAP_SHA" ]] ||
    fail "Live-659 production map checksum mismatch."

[[ "$(sha256sum "$MANIFEST" | awk '{print $1}')" == "$MANIFEST_SHA" ]] ||
    fail "Frozen schema-v5 manifest changed."

[[ "$(sha256sum "$PROOF" | awk '{print $1}')" == "$PROOF_SHA" ]] ||
    fail "Frozen SEO-07G staging proof changed."

[[ "$(sha256sum "$AUTH" | awk '{print $1}')" == "$AUTH_SHA" ]] ||
    fail "Frozen SEO-07H production authorization changed."

[[ "$(sha256sum "$LIVE659_EVIDENCE" | awk '{print $1}')" == "$LIVE659_EVIDENCE_SHA" ]] ||
    fail "SEO-07I live-659 closure evidence changed."

[[ "$(sha256sum "$EXTRA_MAP" | awk '{print $1}')" == "$EXTRA_MAP_SHA" ]] ||
    fail "Historical staging-extra map changed."

[[ "$(
    grep -cE \
        '^    "[^"]+" "/products/[^"]+";$' \
        "$MAP"
)" == "659" ]] ||
    fail "Production source does not contain exactly 659 rules."

[[ "$(
    grep -cE \
        '^    "[^"]+" "/products/[^"]+";$' \
        "$EXTRA_MAP"
)" == "336" ]] ||
    fail "Historical staging-extra source does not contain exactly 336 rules."


export \
    AUTH \
    MANIFEST \
    PROOF \
    LIVE659_EVIDENCE \
    TARGET_REPORT_SHA \
    RUNTIME_REPORT_SHA \
    LIVE659_WEB_IMAGE \
    LIVE400_ROLLBACK_IMAGE

if ! python3 <<'PY'
import json
import os
from pathlib import Path

evidence = json.loads(
    Path(os.environ["LIVE659_EVIDENCE"]).read_text(
        encoding="utf-8",
    )
)

assert evidence["schema_version"] == 1
assert evidence["phase"] == "SEO-07I"
assert evidence["decision"] == "VERIFY_LIVE_PRODUCTION_659"

assert evidence["source"]["main_commit"] == (
    "56bf4c205667517430cbaa2f1ce1212aba3723db"
)

assert evidence["source"]["promotion_source_commit"] == (
    "dff528e44c6d27ebb55b963ddbc8fb7fca62d065"
)

assert evidence["source"]["production_map_sha256"] == (
    "2db01640afb64d5fecf257c27eb628c4"
    "bb1778f75ee47083679e65ceef7e279e"
)

assert evidence["source"]["ordinary_redirect_rules"] == 659

assert evidence["runtime"]["web_image"] == (
    os.environ["LIVE659_WEB_IMAGE"]
)

assert evidence["runtime"]["immutable_live_tag"] == (
    "konji-shop-web:seo07h-live659-56bf4c2"
)

assert evidence["runtime"]["pre659_rollback_tag"] == (
    "konji-shop-web:seo07h-pre659-1cb8e60"
)

assert evidence["runtime"]["pre659_rollback_image"] == (
    os.environ["LIVE400_ROLLBACK_IMAGE"]
)

assert evidence["runtime"]["redirects_enabled"] is True
assert evidence["runtime"]["staging_candidate_enabled"] is False

assert evidence["runtime"]["production_health_http"] == 200
assert evidence["runtime"]["staging_health_http"] == 200

assert evidence["runtime"]["q5_placeholder_redirect_separate"] is True
assert evidence["runtime"]["q5_result"] == "PASS"

validation = evidence["validation"]

assert validation["target_report_sha256"] == (
    os.environ["TARGET_REPORT_SHA"]
)
assert validation["target_products"] == 415
assert validation["target_result"] == "PASS"

assert validation["runtime_report_sha256"] == (
    os.environ["RUNTIME_REPORT_SHA"]
)
assert validation["source_paths"] == 659
assert validation["runtime_result"] == "PASS"
assert validation["redirect_chains"] == 0
assert validation["redirect_loops"] == 0

services = evidence["service_isolation"]

assert services["app_image"] == (
    "sha256:d15817d4739e9cacf44daec8680f4370"
    "daf6be9c2bc6effcd37c8052ee378cb1"
)

assert services["queue_image"] == services["app_image"]
assert services["scheduler_image"] == services["app_image"]

assert services["redis_image"] == (
    "sha256:6ab0b6e7381779332f97b8ca76193e45"
    "b0756f38d4c0dcda72dbb3c32061ab99"
)

assert services["app_recreated"] is False
assert services["queue_recreated"] is False
assert services["scheduler_recreated"] is False
assert services["redis_recreated"] is False
assert services["database_writes"] == 0
assert services["migrations"] == 0

governance = evidence["governance"]

assert governance["authorization_text"] == (
    "AUTHORIZE SEO-07 PRODUCTION 659"
)

assert governance["authorization_path"] == (
    os.environ["AUTH"]
)

assert governance["authorization_sha256"] == (
    "f8fd7f0d43ac0a12f80abb4e40ca1a62"
    "64f4b93bdf83e7c4ed2ea0b12e6f8bde"
)

assert (
    governance["automatic_production_deployment_disabled"]
    is True
)

assert (
    governance["runtime_mutated_by_closure_capture"]
    is False
)

print("SEO07I_LIVE659_EVIDENCE_CONTRACT=PASS")
PY
then
    fail "SEO-07I live-659 evidence contract failed."
fi


export PRE_PROMOTION MAP

if ! python3 <<'PY'
import os
import re
import subprocess
from pathlib import Path

pattern = re.compile(
    r'^\s*"([^"]+)"\s+"([^"]+)";\s*$'
)

def parse(text):
    rules = {}

    for line in text.splitlines():
        match = pattern.match(line)

        if not match:
            continue

        source, target = match.groups()

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
        f'{os.environ["PRE_PROMOTION"]}:{os.environ["MAP"]}',
    ],
    text=True,
)

base = parse(base_text)

live = parse(
    Path(os.environ["MAP"]).read_text(
        encoding="utf-8",
    )
)

if len(base) != 400:
    raise SystemExit(
        f"historical base is not 400 rules: {len(base)}"
    )

if len(live) != 659:
    raise SystemExit(
        f"live source is not 659 rules: {len(live)}"
    )

drift = {
    source: (target, live.get(source))
    for source, target in base.items()
    if live.get(source) != target
}

if drift:
    raise SystemExit(
        f"historical 400-rule drift: {len(drift)}"
    )

added = {
    source: target
    for source, target in live.items()
    if source not in base
}

if len(added) != 259:
    raise SystemExit(
        f"incremental rule count is not 259: {len(added)}"
    )

intersection = set(live) & set(live.values())

if intersection:
    raise SystemExit(
        f"redirect chain/cycle risk: {len(intersection)}"
    )

print("SEO07I_EXACT_400_PLUS_259_UNION=PASS")
PY
then
    fail "Live-659 additive-union proof failed."
fi


BASE_MAP_ACTUAL="$(
    git show "${PRE_PROMOTION}:${MAP}" |
    sha256sum |
    awk '{print $1}'
)"

[[ "$BASE_MAP_ACTUAL" == "$BASE_MAP_SHA" ]] ||
    fail "Historical 400-rule map checksum mismatch."

[[ "$(
    git show "${PRE_PROMOTION}:${MAP}" |
    grep -cE '^    "[^"]+" "/products/[^"]+";$'
)" == "400" ]] ||
    fail "Historical production baseline does not contain 400 rules."


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

grep -Fq \
    'run: bash scripts/deploy/seo07h-production659-source-gate.sh' \
    "$WORKFLOW" ||
    fail "Workflow is not bound to the governed SEO-07 source gate."

[[ -n "${GITHUB_OUTPUT:-}" ]] ||
    fail "GitHub output file is unavailable."

printf 'deploy=false\n' >> "$GITHUB_OUTPUT"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    {
        echo "### SEO-07I verified live-659 governance gate"
        echo "Mode: ${MODE}"
        echo "Verified production baseline: 659 ordinary redirects."
        echo "415 target products and 659 redirect runtime evidence pinned."
        echo "Pre-659 rollback image preserved."
        echo "Automatic production deployment remains disabled."
    } >> "$GITHUB_STEP_SUMMARY"
fi

echo "SEO07I_SOURCE_MODE=$MODE"
echo "SEO07I_SOURCE_GATE=PASS"
echo "SEO07I_SOURCE_RULES=659"
echo "SEO07I_SOURCE_MAP_SHA=$MAP_SHA"
echo "SEO07I_LIVE_BASELINE_RULES=659"
echo "SEO07I_LIVE_BASELINE_WEB_IMAGE=$LIVE659_WEB_IMAGE"
echo "SEO07I_DEPLOYMENT=WITHHELD"
