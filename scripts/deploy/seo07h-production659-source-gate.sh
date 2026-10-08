#!/usr/bin/env bash
set -euo pipefail

fail() {
    echo "::error::SEO-07H authorised-659 source gate: $*" >&2
    exit 1
}

BASE="8f17139032dc076eb99b895b53c4f6aba8b224fb"

BASE_MAP_SHA="52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009"
MAP_SHA="2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e"

MANIFEST_SHA="dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184"
PROOF_SHA="400a8fb07cca664fcab20be2612e515806d068b5f526e867c8df8f7693678cb1"
AUTH_SHA="f8fd7f0d43ac0a12f80abb4e40ca1a6264f4b93bdf83e7c4ed2ea0b12e6f8bde"
EXTRA_MAP_SHA="35fd51ac823a1a2695265abd87d6dcfc4ad34f12c17c42cf627e9a57444dd802"

WORKFLOW=".github/workflows/deploy-prod.yml"
GATE="scripts/deploy/seo07h-production659-source-gate.sh"
PREFLIGHT="scripts/deploy/seo07h-production659-preflight.sh"

HISTORICAL_GATE="scripts/deploy/seo03b-p6s-source-gate.sh"
HISTORICAL_PREFLIGHT="scripts/deploy/production-release-preflight.sh"

MAP="docker/nginx/generated/legacy-seo-product-map.conf"
EXTRA_MAP="docker/nginx/generated/legacy-seo-staging-extra-map.conf"

MANIFEST="resources/seo/ortezka/review/seo-07f-20261007/approved-659-manifest.json"
PROOF="resources/seo/ortezka/review/seo-07g-20261007/staging-runtime-proof.json"
AUTH="resources/seo/ortezka/review/seo-07h-20261008/production-promotion-659.json"

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
    fail "SEO-07H live-400 source baseline is not an ancestor of HEAD."

FIRST="$(
    git rev-list         --first-parent         --reverse         "${BASE}..${HEAD}" |
    sed -n '1p'
)"

[[ -n "$FIRST" ]] ||
    fail "Missing authorised-659 source transition."

[[ "$(git rev-parse "${FIRST}^1")" == "$BASE" ]] ||
    fail "Authorised-659 transition is not directly based on the SEO-07 baseline."

EXPECTED_PROMOTION_DIFF="$(printf '%s\n' \
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
    $'M\ttests/Feature/Seo/SeoLegacyStagingCandidateRuntimeIsolationTest.php')"

ACTUAL_PROMOTION_DIFF="$(
    git diff --name-status "$BASE" "$FIRST"
)"

[[ "$ACTUAL_PROMOTION_DIFF" == "$EXPECTED_PROMOTION_DIFF" ]] ||
    fail "Authorised-659 promotion boundary changed unexpected files."

git diff --check "$BASE" "$FIRST" ||
    fail "Authorised-659 source transition contains Git diff errors."

# Historical SEO-06 governance remains immutable history.
git diff --quiet "$BASE" "$HEAD" --     "$HISTORICAL_GATE"     "$HISTORICAL_PREFLIGHT" ||
    fail "Historical SEO-06 governance source changed."

if [[ "$BEFORE" == "$BASE" ]]; then

    [[ "$HEAD" == "$FIRST" ]] ||
        fail "First authorised-659 promotion must contain exactly one mainline transition."

    MODE="FIRST_AUTHORIZED659_SOURCE_PROMOTION"

else

    git merge-base --is-ancestor "$FIRST" "$BEFORE" ||
        fail "Previous commit is outside authorised-659 source history."

    git merge-base --is-ancestor "$BEFORE" "$HEAD" ||
        fail "Non-fast-forward push rejected."

    git diff --quiet "$FIRST" "$HEAD" --         "$WORKFLOW"         "$GATE"         "$PREFLIGHT"         "$MAP"         "$EXTRA_MAP"         "$MANIFEST"         "$PROOF"         "$AUTH"         "$HISTORICAL_GATE"         "$HISTORICAL_PREFLIGHT" ||
        fail "Protected SEO-07H production-governance file changed."

    PROTECTED_TOUCHES="$(
        git log             --full-history             -m             --format=             --name-only             "${FIRST}..${HEAD}"             --             "$WORKFLOW"             "$GATE"             "$PREFLIGHT"             "$MAP"             "$EXTRA_MAP"             "$MANIFEST"             "$PROOF"             "$AUTH"             "$HISTORICAL_GATE"             "$HISTORICAL_PREFLIGHT" |
        sed '/^[[:space:]]*$/d'
    )"

    [[ -z "$PROTECTED_TOUCHES" ]] ||
        fail "Protected SEO-07H file was modified after promotion."

    MODE="ONGOING_AUTHORIZED659_CI_ONLY"
fi

[[ "$(sha256sum "$MAP" | awk '{print $1}')" == "$MAP_SHA" ]] ||
    fail "Authorised-659 generated map checksum mismatch."

[[ "$(sha256sum "$MANIFEST" | awk '{print $1}')" == "$MANIFEST_SHA" ]] ||
    fail "Frozen schema-v5 manifest changed."

[[ "$(sha256sum "$PROOF" | awk '{print $1}')" == "$PROOF_SHA" ]] ||
    fail "Frozen SEO-07G staging proof changed."

[[ "$(sha256sum "$AUTH" | awk '{print $1}')" == "$AUTH_SHA" ]] ||
    fail "SEO-07H production authorization evidence changed."

[[ "$(sha256sum "$EXTRA_MAP" | awk '{print $1}')" == "$EXTRA_MAP_SHA" ]] ||
    fail "Historical staging-extra 336-rule map changed."

[[ "$(grep -cE '^    "[^"]+" "/products/[^"]+";$' "$MAP")" == "659" ]] ||
    fail "Authorised production source does not contain exactly 659 rules."

[[ "$(grep -cE '^    "[^"]+" "/products/[^"]+";$' "$EXTRA_MAP")" == "336" ]] ||
    fail "Historical staging-extra source does not contain exactly 336 rules."

grep -Fq     '# Source: resources/seo/ortezka/review/seo-07f-20261007/approved-659-manifest.json'     "$MAP" ||
    fail "Authorised-659 map source header mismatch."

grep -Fq     '# Manifest SHA-256: dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184'     "$MAP" ||
    fail "Authorised-659 map manifest header mismatch."

export AUTH MANIFEST PROOF

if ! python3 <<'PY2'
import json
import os
from pathlib import Path

auth = json.loads(
    Path(os.environ["AUTH"]).read_text(encoding="utf-8")
)

assert auth["schema_version"] == 1
assert auth["phase"] == "SEO-07H"
assert auth["decision"] == "AUTHORIZE_PRODUCTION_659"
assert auth["authorization_text"] == "AUTHORIZE SEO-07 PRODUCTION 659"

assert auth["scope"]["ordinary_redirect_rules_before"] == 400
assert auth["scope"]["ordinary_redirect_rules_after"] == 659
assert auth["scope"]["incremental_redirect_rules"] == 259
assert auth["scope"]["approved_products"] == 415
assert auth["scope"]["q5_placeholder_redirect_separate"] is True
assert auth["scope"]["unrelated_application_changes_authorized"] is False

assert auth["candidate_manifest"]["path"] == os.environ["MANIFEST"]
assert auth["candidate_manifest"]["sha256"] == "dc06ef797feb99073ec895749685d03d5e2bb2f090f8feff033e84eaa8556184"
assert auth["candidate_manifest"]["product_count"] == 415
assert auth["candidate_manifest"]["source_path_count"] == 659

assert auth["staging_runtime_proof"]["path"] == os.environ["PROOF"]
assert auth["staging_runtime_proof"]["sha256"] == "400a8fb07cca664fcab20be2612e515806d068b5f526e867c8df8f7693678cb1"
assert auth["staging_runtime_proof"]["result"] == "PASS"

assert auth["source_baseline"]["main_commit"] == "8f17139032dc076eb99b895b53c4f6aba8b224fb"
assert auth["source_baseline"]["production_map_sha256"] == "52db8dcaf8ca3ecf3cbf0cee1c2444d906ca9df94daae15491f818cfaae56009"
assert auth["source_baseline"]["ordinary_redirect_rules"] == 400

assert auth["authorization"]["production_source_promotion"] is True
assert auth["authorization"]["production_runtime_activation"] is True
assert auth["authorization"]["production_ordinary_redirect_rule_count"] == 659

assert auth["redirects_installed_at_record_time"] == 0

print("SEO07H_AUTHORIZATION_CONTRACT=PASS")
PY2
then
    fail "SEO-07H authorization contract failed."
fi

export BASE MAP

if ! python3 <<'PY3'
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
        f'{os.environ["BASE"]}:{os.environ["MAP"]}',
    ],
    text=True,
)

base = parse(base_text)

candidate = parse(
    Path(os.environ["MAP"]).read_text(
        encoding="utf-8",
    )
)

if len(base) != 400:
    raise SystemExit(
        f"base map is not 400 rules: {len(base)}"
    )

if len(candidate) != 659:
    raise SystemExit(
        f"candidate map is not 659 rules: {len(candidate)}"
    )

drift = {
    source: (target, candidate.get(source))
    for source, target in base.items()
    if candidate.get(source) != target
}

if drift:
    raise SystemExit(
        f"existing 400-rule drift: {len(drift)}"
    )

added = {
    source: target
    for source, target in candidate.items()
    if source not in base
}

if len(added) != 259:
    raise SystemExit(
        f"incremental rule count is not 259: {len(added)}"
    )

intersection = set(candidate) & set(candidate.values())

if intersection:
    raise SystemExit(
        f"redirect chain/cycle risk: {len(intersection)}"
    )

print("SEO07H_EXACT_400_PLUS_259_UNION=PASS")
PY3
then
    fail "Authorised-659 additive union proof failed."
fi

BASE_MAP_ACTUAL="$(
    git show "${BASE}:${MAP}" |
    sha256sum |
    awk '{print $1}'
)"

[[ "$BASE_MAP_ACTUAL" == "$BASE_MAP_SHA" ]] ||
    fail "SEO-07H base 400 map checksum mismatch."

[[ "$(
    git show "${BASE}:${MAP}" |
    grep -cE '^    "[^"]+" "/products/[^"]+";$'
)" == "400" ]] ||
    fail "SEO-07H base does not contain exactly 400 rules."

[[ "$(grep -Fc '        if: ${{ false }}' "$WORKFLOW")" == "2" ]] ||
    fail "Independent production deployment locks changed."

[[ "$(
    grep -F -A1         '      - name: Configure AWS credentials'         "$WORKFLOW" |
    grep -Fc '        if: ${{ false }}'
)" == "1" ]] ||
    fail "AWS credentials deployment lock is missing."

[[ "$(
    grep -F -A1         '      - name: Deploy over SSM'         "$WORKFLOW" |
    grep -Fc '        if: ${{ false }}'
)" == "1" ]] ||
    fail "SSM deployment lock is missing."

grep -Fq     'run: bash scripts/deploy/seo07h-production659-source-gate.sh'     "$WORKFLOW" ||
    fail "Workflow is not bound to the SEO-07H source gate."

[[ -n "${GITHUB_OUTPUT:-}" ]] ||
    fail "GitHub output file is unavailable."

printf 'deploy=false\n' >> "$GITHUB_OUTPUT"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    {
        echo "### SEO-07H authorised-659 production source gate"
        echo "Mode: ${MODE}"
        echo "659-rule source accepted."
        echo "Exact 400 + 259 provenance verified."
        echo "SEO-07G staging proof and SEO-07H authorization verified."
        echo "Automatic production deployment remains intentionally disabled."
    } >> "$GITHUB_STEP_SUMMARY"
fi

echo "SEO07H_SOURCE_MODE=$MODE"
echo "SEO07H_SOURCE_GATE=PASS"
echo "SEO07H_SOURCE_RULES=659"
echo "SEO07H_SOURCE_MAP_SHA=$MAP_SHA"
echo "SEO07H_LIVE_BASELINE_RULES=400"
echo "SEO07H_DEPLOYMENT=WITHHELD"
