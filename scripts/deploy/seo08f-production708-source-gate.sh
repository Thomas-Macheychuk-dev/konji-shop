#!/usr/bin/env bash

# SEO-08F production-source governance gate.
# Automatic production deployment remains hard-disabled.
# This gate authorizes source state only and always emits deploy=false.

set -uo pipefail

BASE="17bef8f4b0a52057973a775f87ab6fb14a0fb4ca"
MAP="docker/nginx/generated/legacy-seo-product-map.conf"
MANIFEST="resources/seo/ortezka/review/seo-08d-20261008/approved-708-manifest.json"
CANDIDATE_MAP="resources/seo/ortezka/review/seo-08d-20261008/candidate-708-map.conf"
PROOF="resources/seo/ortezka/review/seo-08e-20261008/staging-runtime-proof.json"
AUTH="resources/seo/ortezka/review/seo-08f-20261008/production-promotion-708.json"
WORKFLOW=".github/workflows/deploy-prod.yml"
PREFLIGHT="scripts/deploy/seo08f-production708-preflight.sh"
GATE="scripts/deploy/seo08f-production708-source-gate.sh"
CONTRACT="tests/Feature/Seo/Seo08Production708PromotionContractTest.php"
GOVERNANCE="tests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php"

HISTORICAL_GATE="scripts/deploy/seo07h-production659-source-gate.sh"
HISTORICAL_PREFLIGHT="scripts/deploy/seo07h-production659-preflight.sh"

MAP_SHA="059d34d5e2b49301a4da744d904fd4e43a67c9005a10cb54e6ee90b6e2d4edff"
BASE_MAP_SHA="2db01640afb64d5fecf257c27eb628c4bb1778f75ee47083679e65ceef7e279e"
MANIFEST_SHA="ee80e33e585e29c382c170372cb9de008d81da0438123275f31e6dc1129aa8db"
CANDIDATE_MAP_SHA="8c9d439434855925b44d3f70e9b7cb121d962ff8b6d30c61952c14d2a29bd854"
PROOF_SHA="dd4fb3a95d55fd4540132951790de7b9d7656c0463f6d661f9fc83a8e316baac"
AUTH_SHA="7116b063d95aec383cabaf0a9a50ef2e947ea4623afa389c79c80c6197bd7530"

fail() {
    echo "SEO08F_SOURCE_GATE=FAIL" >&2
    echo "SEO08F_SOURCE_GATE_REASON=$*" >&2
    exit 1
}

[[ "${GITHUB_EVENT_NAME:-push}" == "push" ]] ||
    fail "Manual dispatch and non-push events are prohibited."

HEAD="$(git rev-parse HEAD 2>/dev/null)" ||
    fail "Unable to read HEAD."

[[ "$(git branch --show-current 2>/dev/null)" == "main" ]] ||
    fail "Checkout is not main."

[[ -z "$(git status --porcelain --untracked-files=no 2>/dev/null)" ]] ||
    fail "Tracked source is dirty."

git merge-base --is-ancestor "$BASE" "$HEAD" >/dev/null 2>&1 ||
    fail "SEO-08E proof merge is not an ancestor of HEAD."

PROMOTION="$(git rev-list --first-parent --reverse "${BASE}..${HEAD}" | sed -n '1p')"
[[ -n "$PROMOTION" ]] ||
    fail "Missing SEO-08F promotion transition."

[[ "$(git rev-parse "${PROMOTION}^1")" == "$BASE" ]] ||
    fail "SEO-08F promotion is not directly based on the staging-proof merge."

EXPECTED_DIFF="$(printf '%s\n' \
    $'M\t.github/workflows/deploy-prod.yml' \
    $'M\tdocker/nginx/generated/legacy-seo-product-map.conf' \
    $'A\tresources/seo/ortezka/review/seo-08f-20261008/production-promotion-708.json' \
    $'A\tscripts/deploy/seo08f-production708-preflight.sh' \
    $'A\tscripts/deploy/seo08f-production708-source-gate.sh' \
    $'A\ttests/Feature/Seo/Seo08Production708PromotionContractTest.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductDeploymentGovernanceTest.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductRedirectPromotionTest.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductRedirectRuntimeTest.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductRedirectSchemaV4Test.php' \
    $'M\ttests/Feature/Seo/SeoLegacyProductRedirectSchemaV5Test.php' \
    $'M\ttests/Feature/Seo/SeoLegacyStagingCandidateRuntimeIsolationTest.php')"

ACTUAL_DIFF="$(git diff --name-status "$BASE" "$PROMOTION")"
[[ "$ACTUAL_DIFF" == "$EXPECTED_DIFF" ]] ||
    fail "SEO-08F promotion boundary changed unexpected files."

git diff --check "$BASE" "$PROMOTION" ||
    fail "SEO-08F promotion contains Git diff errors."

BEFORE="${PUSH_BEFORE:-}"
if [[ "$BEFORE" == "$BASE" ]]; then
    [[ "$HEAD" == "$PROMOTION" ]] ||
        fail "First promotion push must contain exactly one mainline transition."
    MODE="FIRST_708_SOURCE_PROMOTION"
else
    git merge-base --is-ancestor "$PROMOTION" "$HEAD" >/dev/null 2>&1 ||
        fail "HEAD does not contain the SEO-08F promotion."

    git diff --quiet "$PROMOTION" "$HEAD" -- \
        "$WORKFLOW" "$MAP" "$MANIFEST" "$CANDIDATE_MAP" "$PROOF" "$AUTH" \
        "$PREFLIGHT" "$GATE" "$CONTRACT" "$GOVERNANCE" \
        "$HISTORICAL_GATE" "$HISTORICAL_PREFLIGHT" ||
        fail "Protected SEO-08F production governance file changed."

    MODE="ONGOING_708_SOURCE_HOLD"
fi

[[ "$(sha256sum "$MAP" | awk '{print $1}')" == "$MAP_SHA" ]] ||
    fail "708 production source map checksum mismatch."
[[ "$(sha256sum "$MANIFEST" | awk '{print $1}')" == "$MANIFEST_SHA" ]] ||
    fail "708 manifest changed."
[[ "$(sha256sum "$CANDIDATE_MAP" | awk '{print $1}')" == "$CANDIDATE_MAP_SHA" ]] ||
    fail "708 candidate evidence map changed."
[[ "$(sha256sum "$PROOF" | awk '{print $1}')" == "$PROOF_SHA" ]] ||
    fail "SEO-08E staging proof changed."
[[ "$(sha256sum "$AUTH" | awk '{print $1}')" == "$AUTH_SHA" ]] ||
    fail "SEO-08F production authorization changed."

[[ "$(grep -cE '^    "[^"]+" "/products/[^"]+";$' "$MAP")" == "708" ]] ||
    fail "Production source does not contain exactly 708 rules."

BASE_MAP_ACTUAL="$(git show "${BASE}:${MAP}" | sha256sum | awk '{print $1}')"
[[ "$BASE_MAP_ACTUAL" == "$BASE_MAP_SHA" ]] ||
    fail "Historical live659 source map changed."
[[ "$(git show "${BASE}:${MAP}" | grep -cE '^    "[^"]+" "/products/[^"]+";$')" == "659" ]] ||
    fail "Historical source baseline is not 659 rules."

export BASE MAP MANIFEST AUTH PROOF
python3 <<'PY' || fail "SEO-08F manifest/map/authorization contract failed."
import json
import os
import re
import subprocess
from pathlib import Path

pattern = re.compile(r'^\s*"([^"]+)"\s+"([^"]+)";\s*$')

def parse(text):
    rules = {}
    for line in text.splitlines():
        m = pattern.match(line)
        if not m:
            continue
        source, target = m.groups()
        if source in rules:
            raise SystemExit(f"duplicate source: {source}")
        rules[source] = target
    return rules

base = parse(subprocess.check_output(
    ["git", "show", f'{os.environ["BASE"]}:{os.environ["MAP"]}'],
    text=True,
))
live = parse(Path(os.environ["MAP"]).read_text(encoding="utf-8"))

if len(base) != 659 or len(live) != 708:
    raise SystemExit("unexpected rule counts")

for source, target in base.items():
    if live.get(source) != target:
        raise SystemExit(f"live659 drift: {source}")

added = {s:t for s,t in live.items() if s not in base}
if len(added) != 49:
    raise SystemExit(f"increment is not 49: {len(added)}")

if set(live) & set(live.values()):
    raise SystemExit("redirect chain/cycle risk")

manifest = json.loads(Path(os.environ["MANIFEST"]).read_text(encoding="utf-8"))
if manifest["schema_version"] != 6:
    raise SystemExit("manifest schema mismatch")
if manifest["product_count"] != 445 or manifest["source_path_count"] != 708:
    raise SystemExit("manifest counts mismatch")

expected = {}
ids = set()
for record in manifest["records"]:
    ids.add(str(record.get("legacy_id") or record.get("legacy_product_id")))
    for source in record["source_paths"]:
        if source in expected:
            raise SystemExit(f"manifest duplicate: {source}")
        expected[source] = record["target_path"]

if expected != live:
    raise SystemExit("production map differs from approved manifest")
if {"3321", "7560"} & ids:
    raise SystemExit("held legacy identities entered approved records")

auth = json.loads(Path(os.environ["AUTH"]).read_text(encoding="utf-8"))
assert auth["decision"] == "AUTHORIZE_PRODUCTION_708"
assert auth["authorization_text"] == "AUTHORIZE SEO-08 PRODUCTION 708"
assert auth["authorization"]["production_source_promotion"] is True
assert auth["authorization"]["production_runtime_activation"] is True
assert auth["authorization"]["production_ordinary_redirect_rule_count"] == 708
assert auth["redirects_installed_at_record_time"] == 0

proof = json.loads(Path(os.environ["PROOF"]).read_text(encoding="utf-8"))
assert proof["result"] == "PASS"
assert proof["runtime_validation"]["approved_source_paths"] == 708
assert proof["runtime_validation"]["redirect_chains"] == 0
assert proof["runtime_validation"]["redirect_loops"] == 0
assert proof["restoration"]["staging_overlay_final_state"] == "OFF"

print("SEO08F_EXACT_659_PLUS_49_UNION=PASS")
print("SEO08F_708_MANIFEST_MAP_CONTRACT=PASS")
print("SEO08F_AUTHORIZATION_CONTRACT=PASS")
PY

[[ "$(grep -Fc '        if: ${{ false }}' "$WORKFLOW")" == "2" ]] ||
    fail "Independent production deployment locks changed."

grep -Fq 'run: bash scripts/deploy/seo08f-production708-source-gate.sh' "$WORKFLOW" ||
    fail "Workflow is not bound to SEO-08F gate."

[[ -n "${GITHUB_OUTPUT:-}" ]] ||
    fail "GitHub output file is unavailable."

printf 'deploy=false\n' >> "$GITHUB_OUTPUT"

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    {
        echo "### SEO-08F authorised 708-source HOLD"
        echo "Mode: ${MODE}"
        echo "Source contains exact 659 + 49 = 708 ordinary redirects."
        echo "445 targets / 708 staging runtime proof pinned."
        echo "Automatic production deployment remains disabled."
    } >> "$GITHUB_STEP_SUMMARY"
fi

echo "SEO08F_SOURCE_MODE=$MODE"
echo "SEO08F_SOURCE_GATE=PASS"
echo "SEO08F_SOURCE_RULES=708"
echo "SEO08F_SOURCE_MAP_SHA=$MAP_SHA"
echo "SEO08F_BASELINE_RULES=659"
echo "SEO08F_INCREMENTAL_RULES=49"
echo "SEO08F_DEPLOYMENT=WITHHELD"
