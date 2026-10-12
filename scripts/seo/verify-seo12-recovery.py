#!/usr/bin/env python3

import argparse
import hashlib
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

SOURCE = ROOT / (
    "resources/seo/ortezka/recovery/"
    "seo12-20261012/source-1070-map.conf"
)

CANDIDATE = ROOT / (
    "resources/seo/ortezka/recovery/"
    "seo12-20261012/production-1070-candidate.conf"
)

BASE_708 = ROOT / (
    "resources/seo/ortezka/review/"
    "seo-08d-20261008/candidate-708-map.conf"
)

BASE_848 = ROOT / (
    "resources/seo/ortezka/review/"
    "seo-09d-20261008/candidate-848-map.conf"
)

EXPECTED_SOURCE_SHA = (
    "35786007d15345e0c1a484bad28e6bb27334de313fe03c871787bf50388e6c13"
)

EXPECTED_848_SHA = (
    "990daea6d0027ce1fdbddf19015763f5a6655f772de3030592807e002e9e1cc6"
)

RULE = re.compile(
    r'^    "([^"]+)" "([^"]+)";$',
    re.MULTILINE,
)


def load(path, expected_count, expected_sha=None):
    raw = path.read_bytes()
    digest = hashlib.sha256(raw).hexdigest()

    if expected_sha is not None:
        assert digest == expected_sha, (
            f"Unexpected checksum: {path}: {digest}"
        )

    content = raw.decode("utf-8")
    pairs = RULE.findall(content)
    rules = dict(pairs)

    assert len(pairs) == expected_count, (
        f"{path}: expected {expected_count} rules, found {len(pairs)}"
    )
    assert len(rules) == expected_count, (
        f"{path}: duplicate source paths"
    )

    for source, target in rules.items():
        assert source.startswith("/"), f"Invalid source: {source}"
        assert target.startswith("/products/"), (
            f"Invalid target: {target}"
        )

    return rules, content, digest


def check_subset(label, smaller, larger, expected_additions):
    missing = sorted(set(smaller) - set(larger))
    changed = sorted(
        source for source in smaller.keys() & larger.keys()
        if smaller[source] != larger[source]
    )
    additional = sorted(set(larger) - set(smaller))

    print(f"{label}_MISSING={len(missing)}")
    print(f"{label}_CHANGED={len(changed)}")
    print(f"{label}_ADDITIONAL={len(additional)}")

    for source in missing[:5]:
        print(f"MISSING: {source}")

    for source in changed[:5]:
        print(f"CHANGED: {source}")

    assert not missing, f"{label}: missing source mappings"
    assert not changed, f"{label}: changed target mappings"
    assert len(additional) == expected_additions, (
        f"{label}: unexpected additional rule count"
    )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--candidate",
        type=Path,
        default=CANDIDATE,
    )
    args = parser.parse_args()

    source, source_text, source_sha = load(
        SOURCE, 1070, EXPECTED_SOURCE_SHA
    )

    candidate, candidate_text, candidate_sha = load(
        args.candidate, 1070
    )

    base708, _, _ = load(BASE_708, 708)
    base848, _, _ = load(
        BASE_848, 848, EXPECTED_848_SHA
    )

    assert candidate == source, (
        "Recovery candidate does not match the verified source mappings"
    )

    assert (
        "NOT YET AUTHORIZED FOR DEPLOYMENT"
        in candidate_text
    ), "Candidate governance header missing"

    assert (
        "OFFLINE AUDIT ARTIFACT"
        in source_text
    ), "Historical source header changed"

    check_subset("BASE708", base708, candidate, 362)
    check_subset("BASE848", base848, candidate, 222)

    print("SOURCE_RULES=1070")
    print("CANDIDATE_RULES=1070")
    print("SOURCE_SHA256=" + source_sha)
    print("CANDIDATE_SHA256=" + candidate_sha)
    print("SEO12_RECOVERY_MAPPING_GATE=PASS")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, OSError, UnicodeError) as exc:
        print(f"SEO12_RECOVERY_MAPPING_GATE=FAIL: {exc}", file=sys.stderr)
        sys.exit(1)
