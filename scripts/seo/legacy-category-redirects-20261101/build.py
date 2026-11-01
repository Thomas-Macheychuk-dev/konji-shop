#!/usr/bin/env python3
"""Generate the legacy category and product-fallback nginx map from the reviewed legacy category decisions."""
import argparse, csv, hashlib, importlib.util, json, re, sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).parent
REPO = ROOT.parent.parent.parent
CATEGORY_DIR = ROOT.parent / 'legacy-category-map-20261101'
PRODUCT_SOURCES = ROOT / 'product-sources.json'
RULES = ROOT / 'keyword-rules.json'
MAP = REPO / 'docker/nginx/generated/legacy-seo-category-fallback-map.conf'
REVIEW = REPO / 'resources/seo/ortezka/review/legacy-category-redirects-20261101/fallback-redirects.csv'
UNRESOLVED = REVIEW.parent / 'unresolved-products.csv'
EXACT_MAPS = [REPO / 'docker/nginx/generated/legacy-seo-product-map.conf', REPO / 'docker/nginx/generated/legacy-seo-staging-extra-map.conf']
PLACEHOLDERS = REPO / 'config/legacy-product-placeholders.php'
GONE = '410'
# nginx matches $uri (decoded); anything that could break out of a quoted map key is excluded.
SAFE_KEY = re.compile(r'^/[^"\\\s;{}$]+$')


def _module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


suggest = _module('legacy_category_suggest', CATEGORY_DIR / 'suggest.py')
decisions = _module('legacy_category_decisions', CATEGORY_DIR / 'decisions.py')


def exact_keys():
    keys = set()
    for path in EXACT_MAPS:
        keys |= set(re.findall(r'^\s*"([^"]+)"\s+"[^"]+";$', path.read_text(encoding='utf-8'), re.M))
    return keys


def placeholder_paths():
    return set(re.findall(r"'(/[^']+)'", PLACEHOLDERS.read_text(encoding='utf-8')))


def build_product_sources(inventory):
    rows = [r for r in csv.DictReader(open(inventory, encoding='utf-8')) if r['type'] == 'product']
    exact_ids = {m.group(1) for k in exact_keys() if (m := re.search(r'-id-(\d+)$', k))}
    live = {r['legacy_id'] for r in rows if r['status'] == '200'}
    products = {}
    for r in sorted(rows, key=lambda r: (int(r['legacy_id']), r['path'])):
        lid = r['legacy_id']
        if lid not in live or lid in exact_ids:
            continue
        p = products.setdefault(lid, {'legacy_id': int(lid), 'name': '', 'breadcrumbs': '', 'paths': []})
        p['paths'].append(r['path'])
        if r['status'] == '200':
            p['name'] = p['name'] or r['h1'].strip()
            p['breadcrumbs'] = p['breadcrumbs'] or r['breadcrumbs'].strip()
    return {'legacy_inventory_live_product_ids': len(live), 'products': sorted(products.values(), key=lambda p: p['legacy_id'])}


def category_for_breadcrumbs(breadcrumbs, by_trail):
    parts = [suggest.fold(p) for p in breadcrumbs.split('>')]
    parts = [p for p in parts if p and p != suggest.HOME]
    for i in range(len(parts), 0, -1):
        if tuple(parts[:i]) in by_trail:
            return by_trail[tuple(parts[:i])]
    return None


def rule_for_name(name, rules):
    words = suggest.fold(name).split()
    for rule in rules:
        if any(w.startswith(k) for k in rule['prefixes'] for w in words):
            return rule
    return None


def build():
    sources, rows = decisions.load()
    resolved = decisions.resolve(sources, rows)
    active = {c['slug'] for c in sources['konji']}
    legacy = {c['legacy_id']: c for c in sources['legacy']}
    by_trail = {tuple(suggest.fold(x) for x in c['parents'] + [c['name']]): lid for lid, c in legacy.items()}
    rules = json.loads(RULES.read_text(encoding='utf-8'))['rules']
    for rule in rules:
        if rule['target_slug'] not in active:
            raise ValueError(f'keyword rule target {rule["target_slug"]} is not an active Konji category')
    excluded = exact_keys() | placeholder_paths()

    entries, skipped, unresolved = {}, Counter(), []

    def add(path, value, kind, reason):
        if path in excluded:
            skipped['exact_or_placeholder'] += 1
        elif not SAFE_KEY.match(path):
            skipped['unsafe_path'] += 1
        elif path in entries:
            raise ValueError(f'duplicate legacy path {path}')
        else:
            entries[path] = (value, kind, reason)

    for lid, r in sorted(resolved.items()):
        target = f'/categories/{r["target_slug"]}' if r['target_slug'] else GONE
        add(r['path'], target, 'category', f'legacy category {lid}' + (f' via {r["via"]}' if r['via'] else ''))

    products = json.loads(PRODUCT_SOURCES.read_text(encoding='utf-8'))['products']
    for p in products:
        lid = category_for_breadcrumbs(p['breadcrumbs'], by_trail)
        if lid is not None:
            slug, reason = resolved[lid]['target_slug'], f'breadcrumb category {lid}'
        elif (rule := rule_for_name(p['name'], rules)) is not None:
            slug, reason = rule['target_slug'], f'name rule {rule["id"]}'
        else:
            skipped['unresolved_product_ids'] += 1
            unresolved.append(p)
            continue
        if slug is None:
            # A product never inherits a 410: the product may still exist even when its old category does not.
            skipped['product_in_gone_category'] += 1
            continue
        for path in p['paths']:
            add(path, f'/categories/{slug}', 'product', f'legacy product {p["legacy_id"]} {reason}')

    targets = {v for v, _, _ in entries.values()}
    if targets & set(entries):
        raise ValueError('a fallback target is itself a legacy source')
    return entries, skipped, unresolved


def render_map(entries):
    lines = [
        '# GENERATED FILE. DO NOT EDIT BY HAND.',
        '# Source: scripts/seo/legacy-category-redirects-20261101/build.py',
        '# Legacy -cat-N category pages and unmatched legacy -id-N product pages -> Konji categories.',
        '# Evaluated only after the exact SEO-12 product map. "410" marks a legacy category with no destination.',
        'map $uri $legacy_seo_fallback_target {',
        '    default "";',
    ]
    lines += [f'    "{path}" "{value}";' for path, (value, _, _) in sorted(entries.items())]
    lines.append('}')
    return '\n'.join(lines) + '\n'


def write(entries, unresolved):
    MAP.write_text(render_map(entries), encoding='utf-8')
    REVIEW.parent.mkdir(parents=True, exist_ok=True)
    with open(REVIEW, 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f, lineterminator='\n')
        w.writerow(['legacy_path', 'target', 'kind', 'reason'])
        w.writerows([path, value, kind, reason] for path, (value, kind, reason) in sorted(entries.items()))
    with open(UNRESOLVED, 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f, lineterminator='\n')
        w.writerow(['legacy_id', 'legacy_name', 'legacy_paths'])
        w.writerows([p['legacy_id'], p['name'], ' '.join(p['paths'])] for p in unresolved)


def summary(entries, skipped):
    kinds = Counter((kind, 'gone' if value == GONE else 'redirect') for value, kind, _ in entries.values())
    return {
        'rules': len(entries),
        'category_redirects': kinds[('category', 'redirect')],
        'category_gone': kinds[('category', 'gone')],
        'product_redirects': kinds[('product', 'redirect')],
        'product_ids': len({reason.split()[2] for value, kind, reason in entries.values() if kind == 'product'}),
        'skipped': dict(skipped),
        'map_sha256': hashlib.sha256(render_map(entries).encode('utf-8')).hexdigest(),
    }


def main():
    a = argparse.ArgumentParser(description=__doc__)
    a.add_argument('--inventory', help='private legacy-inventory.csv; rebuilds product-sources.json')
    args = a.parse_args()
    if args.inventory:
        data = build_product_sources(args.inventory)
        PRODUCT_SOURCES.write_text('{"legacy_inventory_live_product_ids": %d, "products": [\n%s\n]}\n' % (
            data['legacy_inventory_live_product_ids'],
            ',\n'.join(json.dumps(p, ensure_ascii=False) for p in data['products'])), encoding='utf-8')
    try:
        entries, skipped, unresolved = build()
    except ValueError as e:
        sys.exit(f'LEGACY_FALLBACK_MAP=FAIL {e}')
    write(entries, unresolved)
    print(json.dumps(summary(entries, skipped), ensure_ascii=False))


if __name__ == '__main__':
    main()
