#!/usr/bin/env python3
"""Validate decisions.csv and resolve every legacy category to a Konji category slug or 410. Generates no redirect."""
import csv, json, sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).parent
DECISIONS = ROOT / 'decisions.csv'
VALID = {'map', 'parent', 'gone'}


def load():
    sources = json.loads((ROOT / 'sources.json').read_text(encoding='utf-8'))
    rows = list(csv.DictReader(open(DECISIONS, encoding='utf-8')))
    return sources, rows


def resolve(sources, rows):
    """Return {legacy_id: {'path', 'target_slug' or None (410), 'via'}}; raise ValueError on any invalid decision."""
    legacy = {c['legacy_id']: c for c in sources['legacy']}
    slugs = {c['slug'] for c in sources['konji']}
    decisions = {}
    for row in rows:
        lid = int(row['legacy_id'])
        if lid not in legacy or lid in decisions:
            raise ValueError(f'unknown or duplicate legacy category {lid}')
        if legacy[lid]['path'] != row['legacy_path']:
            raise ValueError(f'{lid}: path does not match sources.json')
        if row['decision'] not in VALID:
            raise ValueError(f'{lid}: decision must be one of {sorted(VALID)}')
        if (row['decision'] == 'map') != bool(row['approved_target_slug']):
            raise ValueError(f'{lid}: approved_target_slug is required for map and only for map')
        if row['decision'] == 'map' and row['approved_target_slug'] not in slugs:
            raise ValueError(f'{lid}: {row["approved_target_slug"]} is not an active Konji category')
        decisions[lid] = row
    if set(decisions) != set(legacy):
        raise ValueError(f'undecided legacy categories: {sorted(set(legacy) - set(decisions))}')

    # Old categories are identified by their trail; "parent" means the nearest old ancestor that has its own row.
    by_trail = {tuple(c['parents']) + (c['name'],): lid for lid, c in legacy.items()}
    resolved = {}
    for lid in sorted(legacy):
        current, via = lid, []
        while decisions[current]['decision'] == 'parent':
            parents = legacy[current]['parents']
            ancestor = next((by_trail[tuple(parents[:i])] for i in range(len(parents), 0, -1) if tuple(parents[:i]) in by_trail), None)
            if ancestor is None:
                raise ValueError(f'{current}: decision is parent but no old ancestor category exists')
            via.append(ancestor)
            current = ancestor
        row = decisions[current]
        resolved[lid] = {'path': legacy[lid]['path'], 'target_slug': row['approved_target_slug'] or None, 'via': via}
    return resolved


def main():
    try:
        resolved = resolve(*load())
    except ValueError as e:
        sys.exit(f'LEGACY_CATEGORY_DECISIONS=FAIL {e}')
    print(json.dumps({
        'legacy_categories': len(resolved),
        'redirect': sum(1 for r in resolved.values() if r['target_slug']),
        'gone': sum(1 for r in resolved.values() if not r['target_slug']),
        'distinct_targets': len({r['target_slug'] for r in resolved.values() if r['target_slug']}),
        'decisions': Counter(r['decision'] for r in load()[1]),
    }, ensure_ascii=False))


if __name__ == '__main__':
    main()
