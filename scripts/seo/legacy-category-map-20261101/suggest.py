#!/usr/bin/env python3
"""Suggest new Konji categories for live legacy Ortezka -cat-N pages. Suggestions only; nothing here approves a redirect."""
import argparse, csv, json, re, sys, unicodedata
from collections import Counter
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).parent
SOURCES = ROOT / 'sources.json'
REVIEW = ROOT.parent.parent.parent / 'resources/seo/ortezka/review/legacy-category-map-20261101/review.csv'
HOME = 'strona glowna'
# Words carrying no identity on their own; they still count inside an exact name match.
STOP = {'i', 'oraz', 'do', 'dla', 'z', 'ze', 'na', 'w', 'po', 'od', 'pozostale', 'inne', 'akcesoria', 'produkty'}
COLUMNS = [
    'priority', 'legacy_id', 'legacy_path', 'legacy_name', 'legacy_parents', 'legacy_products', 'legacy_products_subtree',
    'confidence', 'suggested_slug', 'suggested_path', 'score',
    'alternative_1_slug', 'alternative_1_path', 'alternative_2_slug', 'alternative_2_path',
]


def fold(text):
    text = unicodedata.normalize('NFKD', text.replace('ł', 'l').replace('Ł', 'L'))
    return re.sub(r'[^a-z0-9]+', ' ', ''.join(c for c in text if not unicodedata.combining(c)).lower()).strip()


@lru_cache(maxsize=None)
def stems(text):
    # Polish inflection mostly changes word endings; a five-letter prefix joins "wózki"/"wózków", "ortezy"/"orteza".
    return frozenset(w[:5] for w in fold(text).split() if w not in STOP)


def similarity(a, b):
    a, b = stems(a), stems(b)
    return len(a & b) / len(a | b) if a and b else 0.0


def build_sources(inventory, export):
    rows = list(csv.DictReader(open(inventory, encoding='utf-8')))
    categories = sorted((r for r in rows if r['type'] == 'category' and r['status'] == '200'), key=lambda r: int(r['legacy_id']))
    direct = Counter()
    for r in rows:
        if r['type'] == 'product' and r['status'] == '200' and r['breadcrumbs']:
            direct[fold(r['breadcrumbs'])] += 1
    legacy = []
    for r in categories:
        trail = fold(r['breadcrumbs'] + ' > ' + r['h1'])
        legacy.append({
            'legacy_id': int(r['legacy_id']), 'path': r['path'], 'name': r['h1'].strip(),
            'parents': [p.strip() for p in r['breadcrumbs'].split('>') if fold(p) != HOME],
            'products': direct[trail],
            'products_subtree': sum(n for key, n in direct.items() if key == trail or key.startswith(trail + ' ')),
        })
    new = json.load(open(export, encoding='utf-8'))
    return {
        'legacy_inventory_categories': len(legacy),
        'konji_export': {'exported_at': new['exported_at'], 'active_categories': len(new['categories'])},
        'legacy': legacy,
        'konji': [{k: c[k] for k in ['id', 'parent_id', 'name', 'slug']} for c in sorted(new['categories'], key=lambda c: c['id'])],
    }


def konji_paths(konji):
    by_id = {c['id']: c for c in konji}
    out = []
    for c in konji:
        trail, node, seen = [], c, set()
        while node and node['id'] not in seen:
            seen.add(node['id']); trail.append(node['name']); node = by_id.get(node['parent_id'])
        out.append({**c, 'trail': list(reversed(trail))})
    return out


def score(old, new):
    leaf = similarity(old['name'], new['name'])
    if fold(old['name']) == fold(new['name']):
        leaf = max(leaf, 1.0)
    context = similarity(' '.join(old['parents']), ' '.join(new['trail'][:-1])) if old['parents'] else 0.0
    slug = 1.0 if fold(re.sub(r'-cat-\d+$', '', old['path'])) == fold(new['slug']) else 0.0
    return round(0.65 * leaf + 0.25 * context + 0.10 * slug, 3), leaf, context


def suggest(sources):
    konji = konji_paths(sources['konji'])
    out = []
    for old in sources['legacy']:
        ranked = sorted(((*score(old, new), new) for new in konji), key=lambda r: (-r[0], len(r[3]['trail']), r[3]['id']))
        best_score, leaf, context, best = ranked[0]
        # A generic leaf ("Akcesoria", "Dla dzieci") is only trustworthy with agreeing parents,
        # or, for an old department, when the match is itself a department or its direct child.
        if leaf >= 1.0 and (context >= 0.3 or (not old['parents'] and len(best['trail']) <= 2)):
            confidence = 'high'
        elif best_score >= 0.45 and (context > 0 or not old['parents']):
            confidence = 'medium'
        elif best_score >= 0.25:
            confidence = 'low'
        else:
            confidence = 'none'
        row = {
            'legacy_id': old['legacy_id'], 'legacy_path': old['path'], 'legacy_name': old['name'],
            'legacy_parents': ' > '.join(old['parents']), 'legacy_products': old['products'],
            'legacy_products_subtree': old['products_subtree'], 'confidence': confidence,
            'suggested_slug': best['slug'] if confidence != 'none' else '',
            'suggested_path': ' > '.join(best['trail']) if confidence != 'none' else '', 'score': best_score,
        }
        for i, alt in enumerate(r[3] for r in ranked[1:3]):
            row[f'alternative_{i + 1}_slug'] = alt['slug']
            row[f'alternative_{i + 1}_path'] = ' > '.join(alt['trail'])
        out.append(row)
    # Review the categories that carried the most old products first.
    out.sort(key=lambda r: (-r['legacy_products_subtree'], r['legacy_id']))
    for i, row in enumerate(out, 1):
        row['priority'] = i
    return out


def write_review(rows, path):
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, 'w', encoding='utf-8', newline='') as f:
        w = csv.DictWriter(f, fieldnames=COLUMNS)
        w.writeheader(); w.writerows(rows)


def main():
    a = argparse.ArgumentParser(description=__doc__)
    a.add_argument('--inventory', help='private legacy-inventory.csv; rebuilds sources.json')
    a.add_argument('--export', help='konji-seo-editorial-review.json with the active category tree; rebuilds sources.json')
    args = a.parse_args()
    if bool(args.inventory) != bool(args.export):
        sys.exit('--inventory and --export are used together')
    if args.inventory:
        SOURCES.write_text(json.dumps(build_sources(args.inventory, args.export), ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    rows = suggest(json.loads(SOURCES.read_text(encoding='utf-8')))
    write_review(rows, REVIEW)
    print(json.dumps({'legacy_categories': len(rows), 'confidence': Counter(r['confidence'] for r in rows), 'review': str(REVIEW)}, ensure_ascii=False))


if __name__ == '__main__':
    main()
